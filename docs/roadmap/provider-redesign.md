# Provider interface redesign + Style3D integration scope

## Context

Plugin currently exposes a `Provider` abstract base with two required methods (`push`, `handleWebhook`) and webhook routing at `shipments/webhooks/<integrationHandle>`. The first real integration target is Style3D for the Aretyn client.

Style3D's confirmed scope (from Marco Costa, 2026-05-01):

- Customer places an order in Craft.
- Craft sends the order to Style3D.
- Style3D provides status updates **at the SO (shipment-order) level** to Craft. No line-item updates.
- When Craft splits an order into multiple shipments, Craft sends each split's shipment number to Style3D, and Style3D returns updates keyed by that shipment number.
- Order number is the primary reference identifier.

Goals:

1. Tighten the provider contract: rename push/handleWebhook to `sendShipment` / `cancelShipment` / `receiveShipmentUpdate`, add a `canReceiveUpdates()` capability flag, and shift `receiveShipmentUpdate` to return a `ShipmentUpdatePayload` DTO instead of mutating directly.
2. Define a Craft-controlled inbound webhook contract. Style3D adapts to Craft's shape, not the reverse. Plugin documents the contract; Style3D's payload conforms.
3. Add a cancellation outbound path (`cancelShipment` + `CancelShipmentJob`) for the case where a shipment is cancelled in Craft and the remote needs to know.

Decisions captured from clarifications:

1. **Bidirectional contract**: `sendShipment`, `cancelShipment` (new), `receiveShipmentUpdate`. Plugin pushes orders and cancellations to Style3D; receives status updates back.
2. **Shipment-level only**. No per-SKU statuses, no slowest-SKU rollup, no admin-managed line-item registry. Line-item scope is **deferred**; revisit only if Style3D (or a future provider) emits per-SKU events.
3. **Craft defines the inbound contract**. Style3D conforms.
4. **No external operator field**. The "Operator" labels visible in Style3D's UI are not stored or rendered in Craft.

## Inbound webhook contract (Craft-defined)

Style3D POSTs to `https://<craft-host>/shipments/webhooks/style3d` (or whatever integration handle is configured). Body shape:

```json
{
  "orderNumber":      "string, required",
  "shipmentNumber":   "string, optional. Required when Craft has split the order.",
  "status":           "string, required. Matches an external code mapped via the integration's status map.",
  "occurredAt":       "ISO-8601 UTC, required. Style3D's authoritative event time.",
  "deliveryId":       "string, recommended. Unique per webhook delivery; drives dedupe.",
  "trackingNumber":   "string, optional",
  "trackingUrl":      "string, optional",
  "carrier":          "string, optional",
  "service":          "string, optional",
  "raw":              "object, optional. Full Style3D event payload for audit storage."
}
```

Resolution order for the target shipment:

1. If `shipmentNumber` present, look up via `Shipments::findByNumber()`.
2. Else look up the order by `orderNumber`. If the order has exactly one shipment, target that shipment. If multiple, reject with 400 (`shipmentNumber` was required but absent).

Auth scheme: HMAC-SHA256 with shared secret. Header: `X-Style3D-Signature`. **Confirm with Ty before sharing with Style3D.** Plugin already ships a `WebhookSigning` trait that verifies this scheme.

## Provider contract changes

### `src/base/Provider.php`

Rename + extend the abstract base:

```php
abstract public function sendShipment(Shipment $shipment, Order $order): void;   // was push()
abstract public function cancelShipment(Shipment $shipment, Order $order): void; // new

public function receiveShipmentUpdate(Request $request): ?ShipmentUpdatePayload  // was handleWebhook()
{
    return null;
}

public function canReceiveUpdates(): bool
{
    return false;
}
```

Differences from current contract:

- `push()` becomes `sendShipment()`. Same signature, same semantics (create-or-update on the remote).
- `cancelShipment()` is new. Default abstract; provider must implement.
- `handleWebhook()` becomes `receiveShipmentUpdate()` with **return type changed from `?Shipment` to `?ShipmentUpdatePayload`**. Provider parses + verifies signature + returns the DTO. Plugin owns the actual mutation. Default no-op so providers that don't accept inbound webhooks don't need to implement it.
- `canReceiveUpdates()` capability flag, defaults `false`. `WebhooksController` returns 405 when false.
- `pull()`, `export()`, `checkConnection()`, `getSettingsHtml()` unchanged.

`sendPayload()` event-wrapper renamed to `sendShipmentWithEvents()`. Event constants stay (`EVENT_BEFORE_PUSH`, `EVENT_AFTER_PUSH`) to avoid disrupting listener code; semantically wrap the send. Add `EVENT_BEFORE_CANCEL` / `EVENT_AFTER_CANCEL` mirroring the push pair.

### Breaking change scope

Breaking change for any external `Provider` subclass. No first-party providers ship with this plugin yet (`src/providers/MissingProvider.php` is the only existing subclass and stays). Bump major version in `composer.json` (`2.x` to `3.0.0`).

## DTO changes

### `src/models/ShipmentUpdatePayload.php` (existing model, extended)

Add fields used by the controller to resolve the target shipment and dedupe:

```php
public ?string $orderNumber = null;     // resolves to shipment via order
public ?string $shipmentNumber = null;  // resolves directly when set
public ?string $deliveryId = null;      // sender-provided dedupe key
public ?array $rawPayload = null;       // full original event for audit
```

Existing fulfillment / shipping fields (tracking number, status transitions, etc.) unchanged.

`defineRules()` requires either `orderNumber` or `shipmentNumber`, plus the existing required fields.

## Service layer

### `src/services/Shipments.php` (existing)

`applyUpdate()` unchanged in shape. Plugin already routes shipment-level updates through it. The plugin's webhook controller calls `applyUpdate()` after resolving the target via the new identifier fields on `ShipmentUpdatePayload`.

Add `findByNumber(string $shipmentNumber): ?Shipment` if not already present (verify in code; if missing, add it as a thin Active Query wrapper).

Existing services unchanged: `IntegrationStatusMaps`, `IntegrationReferences`, `CarrierEvents`, `ShipmentLineItems`.

## Webhook controller

`src/controllers/WebhooksController.php`:

- `actionHandle()` consults `$provider->canReceiveUpdates()` first. Returns `405 Method Not Allowed` if false.
- Otherwise delegates to `$provider->receiveShipmentUpdate($this->request)`.
- On non-null `ShipmentUpdatePayload` return:
  1. Resolve target shipment via `shipmentNumber` (preferred) or `orderNumber`.
  2. Dedupe: if `deliveryId` is set and a row exists in a new `shipments_processed_deliveries` table for `(integrationId, deliveryId)`, return 200 silently.
  3. Otherwise call `Shipments::applyUpdate()` and insert the dedupe row.
- Provider may still mutate directly and return null (escape hatch for non-standard providers). Document both paths.

### `shipments_processed_deliveries` (new table)

| Column | Type | Notes |
|---|---|---|
| `id` | PK | |
| `integrationId` | int, FK to `shipments_integrations.id` `CASCADE` | |
| `deliveryId` | string(128) | |
| `dateProcessed` | datetime | |

Unique index `(integrationId, deliveryId)`. Concurrent insert of same key fails on the unique constraint, which the controller treats as a duplicate and short-circuits to 200.

If a provider doesn't supply `deliveryId`, fall back to `CarrierEvents::ingest` SHA-256 hashing already in place at the carrier-event level. Dedupe is best-effort when `deliveryId` is absent.

## Push and cancel triggering

- Existing `EVENT_SHIPMENT_STATUS_CHANGED` listener pattern stays. Consumers queue `PushShipmentJob` (keep class name; internal terminology only).
- New `CancelShipmentJob` (`src/queue/jobs/CancelShipmentJob.php`): mirror of `PushShipmentJob`, calls `$provider->cancelShipment($shipment, $order)`. Triggered by listeners on `FulfillmentStatus::Cancelled` transition AND by a new "Cancel on {integration}" CP sidebar button.
- Both jobs share `recordAttempt()` semantics for `dateLastPushAttempt` / `lastPushAttemptError` / `pushAttemptCount`.

## CP UI

### Integration edit screen (`src/templates/settings/integrations/_edit.twig`)

No structural changes. Provider settings (endpoint URL, bearer token, webhook secret) render through the provider's `getSettingsHtml()`. Existing **Status mapping** subsection (powered by `IntegrationStatusMaps`) covers external code to internal `FulfillmentStatus` / `ShippingStatus` translation.

### Shipment edit screen

- New **Cancel on {integration}** sidebar button. Mirrors existing **Push to {integration}** button. Queues `CancelShipmentJob`.
- No other changes.

## Documentation updates

Per Foster Commerce documentation house rules.

- `docs/dev-guide/custom-providers.md`: rewrite contract section. New method names, `canReceiveUpdates()` flag, DTO return shape, inbound webhook contract spec, cancel example.
- `docs/reference/events.md`: add `EVENT_BEFORE_CANCEL` / `EVENT_AFTER_CANCEL`.
- `docs/reference/schema.md`: document `shipments_processed_deliveries` table.
- `docs/user-guide/integrations.md`: add a paragraph noting the Cancel sidebar button and what it triggers.
- `CHANGELOG.md`: `## 3.0.0 - <release date>` with **Added** (`cancelShipment`, `canReceiveUpdates`, `CancelShipmentJob`, processed-deliveries table), **Changed** (renamed methods, `receiveShipmentUpdate` returns DTO), **Removed** (none).

## Critical files to modify

Provider + transport:

- `plugins/shipments/src/base/Provider.php`: rename + new methods + capability flag.
- `plugins/shipments/src/base/ProviderInterface.php`: comment refresh.
- `plugins/shipments/src/controllers/WebhooksController.php`: capability gate + DTO consumption + delivery-id dedupe.
- `plugins/shipments/src/queue/jobs/PushShipmentJob.php`: call `sendShipment()` instead of `push()`.
- `plugins/shipments/src/queue/jobs/CancelShipmentJob.php`: new.

Schema:

- `plugins/shipments/src/migrations/Install.php`: fresh install includes new dedupe table.
- `plugins/shipments/src/migrations/m{stamp}_processed_deliveries.php`: new (generated via `ddev craft migrate/create`).
- `plugins/shipments/src/db/Table.php`: add `PROCESSED_DELIVERIES` constant.

Records + models:

- `plugins/shipments/src/records/ProcessedDelivery.php`: new ActiveRecord (thin).
- `plugins/shipments/src/models/ShipmentUpdatePayload.php`: add `$orderNumber`, `$shipmentNumber`, `$deliveryId`, `$rawPayload`.

Services:

- `plugins/shipments/src/services/Shipments.php`: ensure `findByNumber()` exists; webhook controller uses it.
- `plugins/shipments/src/Plugin.php`: register processed-deliveries service if a typed getter is needed; otherwise direct DB access from controller.

Translations:

- `plugins/shipments/src/translations/en/shipments.php`: new strings ("Cancel on {name}", cancel job description).

## Reuse (do not reinvent)

- `IntegrationStatusMaps::resolveInbound / resolveOutbound` for code translation.
- `Shipments::applyUpdate()` and `Shipments::applyTransition()` for the actual write.
- `IntegrationReferences::setIntegrationReference / findByIntegrationReference` for remote-id storage.
- `CarrierEvents::ingest()` for raw event audit (called by the controller in addition to `applyUpdate` so the event is preserved verbatim).
- `WebhookSigning` trait for HMAC verification.
- `App::parseEnv()` for credentials. `autosuggestField({suggestEnvVars: true})` in templates.
- `IntegrationException` (retryable) / `PermanentIntegrationException` (terminal) error classification.
- `EVENT_SHIPMENT_STATUS_CHANGED` for queueing send/cancel jobs.

## Verification

End-to-end test path:

1. Run migrations: `ddev craft migrate/all`. Confirm `shipments_processed_deliveries` exists.
2. Create a Style3D test provider in a site module that:
   - Returns `true` from `canReceiveUpdates()`.
   - Implements `sendShipment()` and `cancelShipment()` against a mock endpoint.
   - In `receiveShipmentUpdate()`, verifies HMAC signature, parses the inbound contract above, and returns a populated `ShipmentUpdatePayload`.
3. Boot plugin in CP. Navigate **Shipments -> Settings -> Integrations -> New**. Save the test integration.
4. Configure status mapping for at least the terminal Style3D codes (`Shipped`, etc.).
5. Place a Commerce order. Confirm a shipment is created and `PushShipmentJob` runs against the mock.
6. POST a signed inbound body matching the contract to `shipments/webhooks/<style3d-handle>`. Confirm:
   - HTTP 200 with `{success: true, shipmentId: ...}`.
   - Status applied via `applyUpdate`.
   - Status history row tagged with the integration + external code.
   - One row in `shipments_processed_deliveries`.
7. POST the same body again. Confirm:
   - HTTP 200.
   - No new history row.
   - No duplicate row in `shipments_processed_deliveries` (unique constraint short-circuits).
8. Cancel the shipment in the CP via the **Cancel on {integration}** sidebar button. Confirm:
   - `CancelShipmentJob` queued and runs.
   - Mock endpoint received the cancel request.
   - `lastPushAttemptError` cleared on success.
9. Disable `canReceiveUpdates()` on a different test provider. POST to its webhook URL. Confirm 405.
10. Static analysis: `ddev composer test:phpstan`, `ddev composer test:ecs`, `ddev composer test:rector`. Pass clean. No baselines.

## Out of scope (deferred)

- **Per-line-item statuses and slowest-SKU rollup**. Marco confirmed Style3D will not emit line-item updates. Revisit only if a future provider requires it. Avoids the schema, service, registry CRUD, and CP UI work originally scoped.
- **Admin-managed line item status registry** (`shipments_line_item_statuses` table, controller, templates). Deferred.
- **Order modification outbound** (Craft updates an existing order on Style3D after line item cancel). Pending Marco's answer; if needed, treat as either an enhanced `sendShipment` (provider compares delta) or a new `updateRemoteOrder` method. Current plan assumes initial-send-only.
- **Operator field** storage and rendering. Skipped.
- **GraphQL surface** for shipment update payloads. Existing GraphQL shipment interface unchanged.
- **REST API endpoints** for inbound updates. Webhook path covers Style3D.
- **Bulk recompute migration**. No historical data to backfill.

## Open items pending answers

From Marco:

- Delivery dedupe ID per webhook (header or body field)?
- Style3D retry policy on Craft 5xx?
- Single-event vs batched-event payload?
- Reverse transitions ever sent?
- Style3D-side splits (do they create their own splits and notify, or only act on Craft splits)?
- Order rejection payload?
- Order modification accepted after initial send (line item cancel from Craft)?
- Cancellation from Style3D side: dedicated event or just a status?
- Sandbox URL + test data?
- Rate limits both directions?
- Full enumerated status list?
- Terminal statuses?

From Ty (internal):

- Confirm HMAC-SHA256 + `X-Style3D-Signature` header convention.

From us to Style3D (we provide):

- Webhook URL once integration handle is locked.
- Inbound payload contract spec (this document's "Inbound webhook contract" section).
