# Testing your integration

How to verify a custom `Provider` subclass works end-to-end before handing it to QA.

## The plan

1. **Unit-test the provider in isolation.** Pure PHP. No Craft bootstrap.
2. **Smoke-test the plugin's integration with it.** Stand up a local Craft install, wire in your site module, hit the CP and webhooks manually.
3. **Integration-test the webhook path.** Post signed payloads, confirm the shipment transitions.
4. **Integration-test the push path.** Queue a push, run the queue, confirm the remote received the expected payload.
5. **Fuzz the mapping layer.** Send codes that aren't mapped, confirm the attention-needed page fills.

## 1. Unit tests

Your `sendShipment(Shipment, Order): void`, `cancelShipment(Shipment, Order): void`, and `receiveShipmentUpdate(Request): ?Shipment` are pure-ish given mocked dependencies. Don't bootstrap Craft, mock what you need.

Test cases:

- **Signature verification rejects tampered body.** Use `WebhookSigning::verifyHmacSignature` / `verifyHmacSignatureBase64` directly. See the existing test at `tests/unit/base/WebhookSigningTest.php`.
- **Send serializes the expected payload shape.** Use Guzzle's `MockHandler` to capture the outbound request; assert on the body.
- **Send throws `PermanentIntegrationException` on misconfiguration** (missing credentials, missing endpoint URL). Don't let a bad config cause retries.
- **Send throws `IntegrationException` on a 5xx or network error** (retryable).
- **Cancel hits the expected endpoint and surfaces remote 4xx as `PermanentIntegrationException`.**
- **`canReceiveUpdates()` returns `true`** if the provider is meant to accept inbound webhooks (otherwise the webhook controller 405s).
- **Webhook rejects missing signature header.**
- **Webhook rejects malformed JSON body.**

Example:

```php
use fostercommerce\shipments\errors\PermanentIntegrationException;
use PHPUnit\Framework\TestCase;

final class ExampleErpProviderTest extends TestCase
{
    public function testSendThrowsPermanentWhenMisconfigured(): void
    {
        $provider = new ExampleErpProvider();
        $provider->endpointUrl = null;

        $this->expectException(PermanentIntegrationException::class);
        $provider->sendShipment($this->stubShipment(), $this->stubOrder());
    }
}
```

## 2. Smoke test, local Craft

```sh
ddev start   # or your local Craft setup
./craft plugin/install shipments
```

With your site module registered in `config/app.php`, hit **Shipments -> Settings -> Integrations -> New**, pick your provider from the dropdown. If it's missing, your `EVENT_REGISTER_INTEGRATIONS` listener isn't firing.

Save the integration. Open the status-mapping editor. Add a handful of inbound mappings for your vendor's common codes. Save.

Stage a shipment on a test order, confirm the shipment card shows up.

## 3. Webhook path

Your webhook endpoint is at `https://your-site.test/shipments/webhooks/{handle}`. It's public, unauthenticated at the Craft layer, your provider is responsible for signature verification.

**Generate a signed test request:**

```sh
BODY='{"event":"shipped","shipmentId":"EXT-123","status":"SHIPPED_TO_CARRIER"}'
SECRET='your-webhook-secret'
SIG=$(echo -n "$BODY" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $2}')

curl -X POST https://your-site.test/shipments/webhooks/example-erp \
     -H "Content-Type: application/json" \
     -H "X-Example-Signature: $SIG" \
     -d "$BODY"
```

**Expect:**

- HTTP 200 with `{success: true, shipmentId: <id>}` if the webhook resolved to a known shipment and applied an update.
- HTTP 400 if signature verification fails. Check the log for `shipments` category entries.

Check the shipment's Status history tab: there should be a row with `sourceIntegration` = your integration and `sourceExternalCode` = `SHIPPED_TO_CARRIER`.

**If the transition didn't happen but the webhook returned 200:** the external code wasn't mapped. Check **Shipments -> Attention needed -> Unmapped integration statuses**, your code should appear there with a Map button.

## 4. Push path

From the shipments element index, select your test shipment -> **Actions** -> **Push to {your integration name}**. A `PushShipmentJob` queues.

```sh
./craft queue/run
```

Check the log for `shipments` category entries. Check the target system received the payload.

**Programmatic push for CI:**

```php
Craft::$app->getQueue()->push(new PushShipmentJob([
    'shipmentId' => $shipment->id,
    'integrationId' => $integration->id,
]));

Craft::$app->getQueue()->run();  // process synchronously
```

## 5. Mapping layer fuzz

Send a handful of deliberately-unmapped codes:

```sh
for code in BLAZE STORM OPERATION_SUNSET; do
    # construct signed body with status=$code, POST to webhook
done
```

After all three, check **Shipments -> Attention needed**:

- Three separate rows in **Unmapped integration statuses**.
- `occurrenceCount = 1` on each. Send one twice, confirm it increments to 2.
- Each has a **Map** button linking to the mapping editor.

Add a mapping for one, save, reload the attention page, that row should be gone.

## Common bugs surfaced by testing

- **Signature verification passes locally but fails in staging.** Usually a trailing newline or BOM in the env var. `App::parseEnv` doesn't trim; the vendor's secret usually has no surrounding whitespace.
- **Shipment resolves but transition silently doesn't happen.** The external code isn't mapped, check attention-needed. Or the transition would violate an invariant (e.g. `fulfilled` without tracking); check the log.
- **Push fails once then retries forever.** The provider is throwing `IntegrationException` when it should throw `PermanentIntegrationException`. Review the error taxonomy in [custom-providers.md](./custom-providers.md).
- **Queue job dies with a lock error.** Two pushes on the same shipment are queuing at the same time. The per-shipment mutex on `applyTransition` serializes transitions, not pushes, the push itself doesn't need a lock, but your provider shouldn't assume the shipment state stays frozen during the request.

## Staging

Before production:

1. Point the integration at the vendor's **staging** environment, not production. Vendors usually expose separate endpoint URLs + separate credentials.
2. Use a sandboxed webhook delivery tool (ngrok, webhook.site, Hookdeck) so you can inspect what the vendor sent and replay it.
3. Run through every status transition the vendor can send. Each unmapped code surfaces to attention-needed, map them before production.
4. Push a test shipment end-to-end, confirm the vendor sees it in their UI, confirm the callback webhook transitions it back.

## What to automate in CI

Minimum viable CI for an integration:

- `composer phpstan`
- `composer ecs:check`
- `composer test` (unit tests)
- `./vendor/bin/phpunit --testsuite=integration` (once the full Craft test harness is wired; see `tests/integration/README.md`)

Before each release of the integration plugin, manually run the smoke + webhook + push paths against the vendor's sandbox.
