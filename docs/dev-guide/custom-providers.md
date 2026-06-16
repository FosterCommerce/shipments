# Custom providers

The plugin ships no concrete providers. To talk to any external fulfillment system, write a `Provider` subclass in a site module (or a dedicated plugin) and register it.

## The contract

Every provider extends `fostercommerce\shipments\base\Provider` and implements:

- `sendShipment(Shipment, Order): void`: create/update the shipment on the remote system. Call `setIntegrationReference()` with the returned id. Required (abstract).
- `cancelShipment(Shipment, Order): void`: cancel the shipment on the remote system. Default throws `IntegrationException('Cancel not implemented…')`; override when the remote supports cancellation.

Inbound webhook updates come as a capability pair:

- `canReceiveUpdates(): bool`: opt-in flag. Default `false`. `WebhooksController` returns `405 Method Not Allowed` when this is false, so a provider that doesn't accept inbound updates needs no further work.
- `receiveShipmentUpdate(Request): ?Shipment`: parse an inbound POST at `shipments/webhooks/<integrationHandle>`. Verify the signature, resolve the local shipment, apply the update. Default throws; override (and have `canReceiveUpdates()` return `true`) when the remote pushes status updates back.

Other optional hooks:

- `pull(): void`: for remotes that won't webhook you. Your module schedules the call.
- `export(Request): Response`: for remotes that pull from you at `shipments/exports/<integrationHandle>`. Pick your format (XML, JSON, whatever). Default throws.

Settings are plain typed properties on the class. The plugin saves/loads them as JSON on the integration row automatically.

## Minimal example

### The provider class

```php
<?php

declare(strict_types=1);

namespace modules\mystore\providers;

use Craft;
use craft\commerce\elements\Order;
use craft\helpers\App;
use craft\helpers\Json;
use craft\web\Request;
use craft\web\View;
use fostercommerce\shipments\base\Provider;
use fostercommerce\shipments\base\WebhookSigning;
use fostercommerce\shipments\elements\Shipment;
use fostercommerce\shipments\errors\IntegrationException;
use fostercommerce\shipments\errors\PermanentIntegrationException;
use fostercommerce\shipments\Plugin as ShipmentsPlugin;
use Throwable;

class ExampleErpProvider extends Provider
{
    use WebhookSigning;

    public ?string $endpointUrl = null;
    public ?string $bearerToken = null;
    public ?string $webhookSecret = null;

    public static function displayName(): string
    {
        return 'Example ERP';
    }

    public function sendShipment(Shipment $shipment, Order $order): void
    {
        if ($this->endpointUrl === null || $this->bearerToken === null) {
            throw new PermanentIntegrationException('Example ERP is misconfigured.');
        }

        $payload = [
            'orderNumber' => $order->number,
            'shipmentReference' => $shipment->reference,
            'lineItems' => array_map(static fn ($lineItem): array => [
                'id' => $lineItem->lineItemId,
                'qty' => $lineItem->qty,
            ], $shipment->lineItems),
        ];

        try {
            $response = Craft::createGuzzleClient()->post((string) App::parseEnv($this->endpointUrl), [
                'headers' => [
                    'Authorization' => 'Bearer ' . (string) App::parseEnv($this->bearerToken),
                    'Content-Type' => 'application/json',
                ],
                'body' => Json::encode($payload),
                'http_errors' => false,
            ]);
        } catch (Throwable $throwable) {
            throw new IntegrationException('Example ERP send failed: ' . $throwable->getMessage(), 0, $throwable);
        }

        if ($response->getStatusCode() >= 300) {
            throw new IntegrationException('Example ERP rejected the send: ' . (string) $response->getBody());
        }

        $body = Json::decodeIfJson((string) $response->getBody());
        $remoteId = is_array($body) ? ($body['shipmentId'] ?? null) : null;

        if (is_scalar($remoteId) && $this->handle !== null) {
            ShipmentsPlugin::getInstance()
                ->integrationReferences
                ->setIntegrationReference($shipment, $this->handle, (string) $remoteId);
        }
    }

    public function cancelShipment(Shipment $shipment, Order $order): void
    {
        if ($this->endpointUrl === null || $this->bearerToken === null) {
            throw new PermanentIntegrationException('Example ERP is misconfigured.');
        }

        $remoteId = ShipmentsPlugin::getInstance()
            ->integrationReferences
            ->getIntegrationReference($shipment, (string) $this->handle);

        if ($remoteId === null) {
            throw new PermanentIntegrationException('Shipment was never sent to Example ERP.');
        }

        try {
            $response = Craft::createGuzzleClient()->delete((string) App::parseEnv($this->endpointUrl) . '/' . $remoteId, [
                'headers' => [
                    'Authorization' => 'Bearer ' . (string) App::parseEnv($this->bearerToken),
                ],
                'http_errors' => false,
            ]);
        } catch (Throwable $throwable) {
            throw new IntegrationException('Example ERP cancel failed: ' . $throwable->getMessage(), 0, $throwable);
        }

        if ($response->getStatusCode() >= 300) {
            throw new IntegrationException('Example ERP rejected the cancel: ' . (string) $response->getBody());
        }
    }

    public function canReceiveUpdates(): bool
    {
        return true;
    }

    public function receiveShipmentUpdate(Request $request): ?Shipment
    {
        $rawBody = $request->getRawBody();

        if ($this->webhookSecret === null) {
            throw new PermanentIntegrationException('Webhook secret not configured.');
        }

        $provided = (string) $request->getHeaders()->get('X-Example-Signature');
        $secret = (string) App::parseEnv($this->webhookSecret);
        if (! $this->verifyHmacSignature($rawBody, $provided, $secret)) {
            throw new IntegrationException('Signature mismatch.');
        }

        $payload = Json::decodeIfJson($rawBody);
        if (! is_array($payload) || $this->handle === null) {
            return null;
        }

        $plugin = ShipmentsPlugin::getInstance();

        $shipment = $plugin->integrationReferences->findByIntegrationReference(
            $this->handle,
            (string) ($payload['shipmentId'] ?? ''),
        );

        if (! $shipment instanceof Shipment) {
            return null;
        }

        // Translate Example ERP's status via the per-integration mapping table.
        $externalCode = (string) ($payload['status'] ?? '');
        $integration = $plugin->integrations->getIntegrationByHandle($this->handle);
        if ($integration !== null && $integration->id !== null && $externalCode !== '') {
            $internal = $plugin->integrationStatusMaps->resolveInbound(
                $integration->id,
                $externalCode,
            );

            // Unmapped codes resolve to null; leave the shipment untouched.
            if ($internal !== null) {
                $plugin->shipments->applyTransition(
                    $shipment,
                    $internal,
                    null,
                    'Example ERP reported: ' . $externalCode,
                    $integration,
                    $externalCode,
                );
            }
        }

        return $shipment;
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate(
            'mystore/_cp/providers/example-erp/settings',
            ['provider' => $this],
            View::TEMPLATE_MODE_CP,
        );
    }
}
```

### The settings template

`modules/mystore/templates/_cp/providers/example-erp/settings.twig`. Field names go under `settings[...]`; the plugin stashes them as the provider's config blob.

```twig
{% import "_includes/forms" as forms %}

{{ forms.autosuggestField({
    label: 'Endpoint URL'|t('mystore'),
    name: 'settings[endpointUrl]',
    value: provider.endpointUrl,
    class: 'ltr',
    suggestEnvVars: true,
}) }}

{{ forms.autosuggestField({
    label: 'Bearer token'|t('mystore'),
    name: 'settings[bearerToken]',
    value: provider.bearerToken,
    class: 'ltr',
    suggestEnvVars: true,
}) }}

{{ forms.autosuggestField({
    label: 'Webhook secret'|t('mystore'),
    name: 'settings[webhookSecret]',
    value: provider.webhookSecret,
    class: 'ltr',
    suggestEnvVars: true,
}) }}
```

Use `autosuggestField` with `suggestEnvVars: true` on every credential. Never hand-edit secrets into project config.

### Register it

In your module's `init()`:

```php
use fostercommerce\shipments\events\RegisterIntegrationsEvent;
use fostercommerce\shipments\services\Integrations;
use modules\mystore\providers\ExampleErpProvider;
use yii\base\Event;

Event::on(
    Integrations::class,
    Integrations::EVENT_REGISTER_INTEGRATIONS,
    static function (RegisterIntegrationsEvent $event): void {
        $event->types[] = ExampleErpProvider::class;
    },
);
```

Your provider now appears in the Provider dropdown on the Integration edit page.

## Applying updates

Every write path, CP edits, webhooks, REST API, ends in the same service:

- `Shipments::applyTransition(Shipment, Status, ?User, ?message, ?sourceIntegration, ?externalCode)`, single canonical status-write. Writes a history row tagged with the source integration and the external code it sent, and fires `EVENT_SHIPMENT_STATUS_CHANGED` inside the transaction. No field is required to reach any status. The `dateShipped` timestamp is derived from history rows on read (`Shipment::getDateShipped()`), not stored as a column.
- `Shipments::applyUpdate(Shipment, ShipmentUpdatePayload, ?User $user = null, ?Integration $source = null, ?string $externalCode = null)`, convenience wrapper that writes fulfillment fields (tracking/carrier/etc.) plus an optional status transition in one call. Idempotent; null fields are skipped. Pass `$source` and `$externalCode` so any transition inside this call lands on the history row tagged with your integration and the original code it sent.

Per-shipment mutex serialization is automatic.

### Permanent vs retryable failures

`IntegrationException` lets Craft's queue retry (network blip, 5xx). `PermanentIntegrationException` marks the job failed without retry (bad config, 4xx, malformed remote payload). `PushShipmentJob` records `lastPushAttemptError` + bumps `pushAttemptCount` in either case.

## Triggering pushes

`Shipments::EVENT_SHIPMENT_STATUS_CHANGED` fires on initial creation (`fromCode = null`) and on every subsequent transition, inside the write transaction. Queue a push from wherever makes sense:

```php
use fostercommerce\shipments\enums\Status;

Event::on(
    Shipments::class,
    Shipments::EVENT_SHIPMENT_STATUS_CHANGED,
    static function (ShipmentStatusChangedEvent $event) use ($integrationId): void {
        if ($event->toCode !== Status::Shipped) {
            return;
        }

        Craft::$app->getQueue()->push(new PushShipmentJob([
            'shipmentId' => $event->shipment->id,
            'integrationId' => $integrationId,
        ]));
    },
);
```

For a CP-initiated push, the **Push to {integration}** button in the sidebar of each shipment's edit page queues the same job for that shipment + integration.

## Polling

For remotes that won't webhook you, override `pull()` and trigger it from a console command you control:

```php
// modules/mystore/console/controllers/SyncController.php
public function actionPull(): int
{
    foreach (ShipmentsPlugin::getInstance()->integrations->getAllIntegrations() as $integration) {
        if (! $integration->enabled) {
            continue;
        }

        $provider = $integration->getProvider();
        if ($provider instanceof ExampleErpProvider) {
            $provider->pull();
        }
    }

    return ExitCode::OK;
}
```

Point cron at `./craft mystore/sync/pull` on whatever interval you need.

## Exports

When a remote pulls from us on a schedule, override `export(Request): Response`. The plugin provides the route (`shipments/exports/<integrationHandle>`) and the canonical shipment query; your provider picks the format.

```php
use fostercommerce\shipments\enums\Status;
use fostercommerce\shipments\models\ShipmentExportQuery;

public function export(Request $request): Response
{
    $this->assertAuthorized($request);

    $query = ShipmentExportQuery::fromRequest($request);
    $query->statusHandle = Status::Shipped->value;

    $result = ShipmentsPlugin::getInstance()->shipments->findForExport($query);

    $body = $this->renderAsXml($result->shipments, $result->pageCount);  // your format

    $response = Craft::$app->getResponse();
    $response->format = Response::FORMAT_RAW;
    $response->content = $body;
    $response->headers->set('Content-Type', 'text/xml');
    return $response;
}
```

`ShipmentExportQuery::fromRequest()` parses the common `start_date` / `end_date` / `page` / `pageSize` convention. Build the DTO manually if your remote uses different names. Results order by `dateUpdated asc` and `pageSize` caps at 500.

## Env vars

Every credential must be env-var-aware.

1. In the settings template, `autosuggestField({..., suggestEnvVars: true})`.
2. In the provider, `App::parseEnv($this->bearerToken)` before handing to HTTP clients. Never log the raw setting.

## Errors

Throw `IntegrationException` for retryable domain errors (network blip, 5xx from the remote). Throw `PermanentIntegrationException` for hard failures that shouldn't be retried (bad config, 4xx, malformed payload, signature mismatch). The webhook and export controllers convert both to 400 with a log entry. `PushShipmentJob` classifies retries accordingly and records `dateLastPushAttempt` / `lastPushAttemptError` / `pushAttemptCount` on the shipment either way.

## Webhook idempotency

Webhook senders retry. The same delivery can arrive twice (sender-side retry, network re-delivery, or two parallel workers picking up the same payload). Your handler has to be safe under duplicate and concurrent delivery, otherwise you'll get double history rows, doubled emails, or lost-update races.

### What's already covered

If your `receiveShipmentUpdate()` routes the actual mutation through one of these, you get idempotency for free:

- **`Shipments::applyTransition`** short-circuits same-to-same transitions (skips silently when the status already equals the target) and serializes concurrent calls per shipment via `Craft::$app->getMutex()`. The mutex holds across read-current-state + write, so two parallel deliveries can't both decide to transition off the same pre-state.
- **`Shipments::applyUpdate`** wraps `applyTransition` and applies fulfillment fields with null-skip semantics, so re-delivering the same payload settles to the same state.

If your handler resolves the shipment and immediately calls one of these, you do not need to add your own dedupe.

### When you need your own protection

You need explicit idempotency when your handler does meaningful work *outside* those service calls before mutating state. Examples: fetching extra data from the remote, writing to your own tables, queuing side-effect jobs, or making a decision based on a pre-state you read yourself. Two patterns:

**1. Dedupe on the sender's delivery id.** Most webhook senders attach a unique id per delivery (a dedicated header, an `id` on the event body, or a resource path plus delivery timestamp). Record processed ids in your own table and short-circuit on hit:

```php
$deliveryId = (string) $request->getHeaders()->get('X-Example-Delivery-Id');

if ($deliveryId !== '' && $this->alreadyProcessed($deliveryId)) {
    return null;
}

// ...verify signature, parse, mutate...

$this->markProcessed($deliveryId);
```

Pick a column with a unique index so a concurrent insert of the same id fails loudly rather than racing.

**2. Application mutex around resolve+mutate.** If you can't dedupe by delivery id (sender doesn't expose one, or you have to derive state mid-handler), wrap the resolve-then-mutate sequence in an application-level mutex so a parallel delivery waits, re-reads, and sees the change is already applied:

```php
$mutex = Craft::$app->getMutex();
$lockName = 'shipments-handler:' . $shipment->id;

if (! $mutex->acquire($lockName, 10)) {
    throw new \RuntimeException('Could not acquire handler lock for shipment ' . $shipment->id);
}

try {
    // ...re-read current state, decide whether the change still applies, then mutate...
} finally {
    $mutex->release($lockName);
}
```

Going through `applyTransition` already gives you per-shipment serialization via the mutex; this lock is for work *around* it that you can't push into the service call. The mutex is portable across DB drivers and avoids the pitfalls of DB-specific row locks.

Either pattern is fine; pick the one your sender supports. If you're unsure, dedupe on delivery id is the simpler default.

## Testing

1. Install the plugin, boot your module.
2. **Shipments -> Settings -> Integrations -> New**: confirm your provider appears in the Provider dropdown.
3. Save an integration pointing at a mock endpoint (e.g. webhook.site).
4. Queue a push and run the queue, then check the request hit the mock.
5. Craft a signed inbound body, POST it to `shipments/webhooks/<handle>`, confirm the target shipment updated.

## Reference implementations

First-party provider plugins ship as separate Composer packages (`fostercommerce/shipments-<vendor>`) as they're funded. Until then, this doc is the contract.
