# Provider SPI: inbound, dedupe, and cancel

Working notes. Not a spec.

## Goal

Harden the provider service-provider interface (SPI) so a real integration can push shipments out, receive status updates back, and cancel remotely, without each provider re-solving idempotency and target resolution. The plugin owns the write; the provider owns transport and translation.

Shipments carry a single `Status`. Inbound updates resolve an external code through the integration's status map and apply it via `Shipments::applyTransition`. Status-level only: no per-line-item or per-SKU updates.

## Provider contract

The `Provider` abstract base (`src/base/Provider.php`) defines:

```php
abstract public function sendShipment(Shipment $shipment, Order $order): void;
public function cancelShipment(Shipment $shipment, Order $order): void;     // default throws; override to support cancel
public function handleGatewayRequest(Request $request): Response;             // default throws; override for inbound gateway requests
public function checkConnection(): bool;
public function getSettingsHtml(): ?string;
```

`sendShipmentWithEvents()` / `cancelShipmentWithEvents()` wrap the send/cancel with `EVENT_BEFORE_*` / `EVENT_AFTER_*`. A before-handler can set `$event->isValid = false` to skip.

The gateway controller (`GatewayController::actionHandle`) resolves the `integration` query param, then delegates to `handleGatewayRequest()`. Today the provider parses, verifies the signature, mutates the shipment itself when needed, and returns the response.

## Inbound webhook contract (Craft-defined)

The plugin defines the inbound shape; the remote conforms to it. A provider POSTs to `/actions/shipments/gateway/handle?integration=<integrationHandle>` with:

```json
{
  "orderNumber":    "string, required",
  "shipmentNumber": "string, optional. Required when the order has been split into multiple shipments.",
  "status":         "string, required. An external code mapped via the integration's status map.",
  "occurredAt":     "ISO-8601 UTC, required. The remote's authoritative event time.",
  "deliveryId":     "string, recommended. Unique per webhook delivery; drives dedupe.",
  "trackingNumber": "string, optional",
  "trackingUrl":    "string, optional",
  "carrier":        "string, optional",
  "service":        "string, optional",
  "raw":            "object, optional. Full original event payload for audit storage."
}
```

Target-shipment resolution:

1. If `shipmentNumber` is present, look it up directly.
2. Else look up the order by `orderNumber`. If the order has exactly one shipment, target it. If it has several, reject with 400 (`shipmentNumber` required but absent).

Auth: HMAC-SHA256 with a shared secret in a signature header. The `WebhookSigning` trait already verifies this scheme.

## Proposed additions

### 1. Move the mutation into the controller (optional)

Shift `receiveShipmentUpdate()` to return a `ShipmentUpdatePayload` instead of mutating directly, so the controller owns the write and applies dedupe uniformly. Keep a direct-mutation escape hatch (return null after writing) for non-standard providers. This is the larger change and can land after dedupe and cancel.

### 2. Delivery dedupe

A new `shipments_processed_deliveries` table records `(integrationId, deliveryId)` with a unique index. The controller short-circuits to 200 when the key already exists, and inserts the row after a successful apply. A concurrent insert of the same key fails on the unique constraint, which the controller treats as a duplicate.

| Column          | Type                                              |
|-----------------|---------------------------------------------------|
| `id`            | PK                                                |
| `integrationId` | int, FK to `shipments_integrations.id` (CASCADE)  |
| `deliveryId`    | string(128)                                       |
| `dateProcessed` | datetime                                          |

When a provider doesn't supply `deliveryId`, dedupe is best-effort (the per-shipment transition mutex still prevents double-apply races, but a genuine re-delivery can re-fire).

### 3. Cancel job

`CancelShipmentJob`, a mirror of `PushShipmentJob`, calls `$provider->cancelShipmentWithEvents($shipment, $order)`. Triggered by a listener on the `cancelled` transition and by a new **Cancel on {integration}** sidebar button on the shipment edit page. Both jobs share `recordAttempt()` semantics for `dateLastPushAttempt` / `lastPushAttemptError` / `pushAttemptCount`.

### 4. Payload resolution fields

Add to `ShipmentUpdatePayload`:

```php
public ?string $orderNumber = null;     // resolves to a shipment via its order
public ?string $shipmentNumber = null;  // resolves directly when set
public ?string $deliveryId = null;      // sender-provided dedupe key
public ?array $rawPayload = null;       // full original event for audit
```

`defineRules()` requires either `orderNumber` or `shipmentNumber`. Add `Shipments::findByNumber(string): ?Shipment` (thin element-query wrapper) if not already present.

## CP UI

- Integration edit screen: no structural change. The status-mapping subsection already covers external-to-`Status` translation.
- Shipment edit screen: add the **Cancel on {integration}** sidebar button mirroring **Push to {integration}**.

## Reuse (do not reinvent)

- `IntegrationStatusMaps::resolveInbound` / `resolveOutbound` for code translation.
- `Shipments::applyUpdate()` / `Shipments::applyTransition()` for the write.
- `IntegrationReferences::setIntegrationReference` / `findByIntegrationReference` for remote-id storage.
- `WebhookSigning` trait for HMAC verification.
- `App::parseEnv()` for credentials; `autosuggestField({suggestEnvVars: true})` in settings templates.
- `IntegrationException` (retryable) / `PermanentIntegrationException` (non-retryable) classification.
- `EVENT_SHIPMENT_STATUS_CHANGED` for queueing send/cancel jobs.

## Verification

1. Run migrations; confirm `shipments_processed_deliveries` exists.
2. Stand up a test provider in a site module that implements `sendShipment()`, `cancelShipment()`, and `handleGatewayRequest()` against a mock, and verifies + parses the inbound contract in `handleGatewayRequest()`.
3. Save a test integration; configure status mappings for the codes the provider sends.
4. Place an order; confirm a shipment is created and `PushShipmentJob` runs against the mock.
5. POST a signed inbound body. Confirm 200, the status applied, a history row tagged with the integration + external code, and one `shipments_processed_deliveries` row.
6. POST the same body again. Confirm 200, no new history row, no duplicate dedupe row.
7. Cancel the shipment via the sidebar button. Confirm `CancelShipmentJob` runs and the mock received the cancel.
8. Static analysis clean: PHPStan, ECS, Rector. No baselines.

## Open items

- Delivery dedupe id location (header vs body field) per provider.
- Single-event vs batched-event inbound payloads.
- Whether reverse transitions are ever sent.
- Cancellation from the remote side: dedicated event or just a status.
- Rate limits in both directions.
