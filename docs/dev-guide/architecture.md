# Architecture reference

How the pieces fit together. Audience: developers extending or integrating with the plugin.

## Data model

- **`Shipment`**, a Craft element. Owns a grouped allocation of order line-item quantities plus fulfillment fields (tracking, carrier, service, scheduled ship date, notes, free-text fields via a plugin-wide field layout), and two status columns: `fulfillmentStatus` + `shippingStatus`. Disabling is admin-driven only (the **Enabled** lightswitch on the shipment edit page); the plugin never disables shipments on its own, and the audit for a manual disable lives in Craft's element revision log.
- **`shipments_shipment_line_items`**, join rows between a shipment and Commerce line items, carrying the per-line-item quantity the shipment covers.
- **`shipments_shipment_status_history`**, axis-aware audit log. Every `applyTransition` writes one row with `axis`, `fromCode`, `toCode`, `userId`, `sourceIntegrationId`, `sourceExternalCode`.
- **`shipments_integrations`**, named external systems (ShipStation, a custom ERP, etc.). Each integration wraps a `Provider` subclass with settings.
- **`shipments_integration_references`**, per-shipment rows mapping `(shipmentId, integrationId) -> externalId + optional url override`.
- **`shipments_integration_status_maps`**, per-integration translation table between the integration's vocabulary and our `FulfillmentStatus` / `ShippingStatus` enums. Rows have `axis`, `direction` (inbound/outbound/bidirectional), `externalCode`, `externalLabel`, `internalCode`.
- **`shipments_unmapped_external_statuses`**, attention-needed rows when an inbound webhook delivers a code with no mapping.
- **`shipments_carrier_events`**, one row per carrier event ingested. SHA-256 `eventHash` dedupes `(shipmentId, code, dateOccurred, externalCode)`.
- **`shipments_transition_emails`**, bindings from `(axis, toCode)` to notification emails.
- **`shipments_emails`**, notification-email definitions (project-config backed).
- **`shipments_tracked_orders`**, which completed orders the plugin is actively watching for fulfillment, plus each order's cached `shippable` verdict, `state` (active or ignored), and `underAllocated` flag. The Attention page joins this table; orders without a row are invisible to it.

## Status model

Two independent axes:

| Axis           | Enum                 | Driven by            | Values                                                                                                                                                           |
|----------------|----------------------|----------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `fulfillment`  | `FulfillmentStatus`  | Merchant / 3PL       | `open`, `in_progress`, `scheduled`, `on_hold`, `fulfilled`, `cancelled`, `incomplete`                                                                            |
| `shipping`     | `ShippingStatus`     | Carrier events       | `pending`, `pre_transit`, `in_transit`, `out_for_delivery`, `attempted_delivery`, `available_for_pickup`, `delivered`, `exception`, `returned`, `failure`        |

Both vocabularies are fixed. Integration-specific external codes map in via `IntegrationStatusMaps`. See [status vocabulary](../user-guide/status-vocabulary.md) for the semantics of each case.

## Write paths, all route through one service

Every shipment status change, CP click, webhook ingestor, REST call, ends up here:

```php
Shipments::applyTransition(
    Shipment $shipment,
    StatusAxis $axis,
    FulfillmentStatus|ShippingStatus $to,
    ?User $user = null,
    ?string $message = null,
    ?Integration $source = null,
    ?string $externalCode = null,
    FulfillmentStatus|ShippingStatus|null $expectedFromCode = null,
): ?Shipment
```

Inside that method, in order:

1. Acquire a per-shipment mutex: `shipments:shipment:{id}:transition`. Serializes concurrent transitions on the same shipment.
2. Re-read the canonical state under the lock.
3. Optimistic-lock check: if `$expectedFromCode` was supplied and doesn't match current, throw `InvalidTransitionException`.
4. Run axis-specific invariants. `FulfillmentStatus::Fulfilled` requires a non-empty `trackingNumber`.
5. Open a DB transaction.
6. Mutate status columns + `dateShippingStatus`. Shipped/delivered timestamps are derived on read from the history rows themselves (`Shipment::getDateShipped()` / `getDateDelivered()`).
7. Save via `Craft::$app->getElements()->saveElement($shipment)`.
8. Insert a `ShipmentStatusHistory` record with axis + source integration / external code.
9. Fire `EVENT_SHIPMENT_STATUS_CHANGED` **inside** the transaction. Listeners that push queue jobs (`TransitionEmails`) ride the same DB connection, Craft's default queue is DB-backed, so the job push is atomic with the status write.
10. Commit. Release the mutex.

Same pattern for `Shipments::createFromStagingPost` (per-order mutex instead; pool validation under the lock) and `CarrierEvents::ingest` (dedupe-insert, then `applyTransition` for resolvable codes).

## Services

| Service                      | Responsibility                                                                                                              |
|------------------------------|-----------------------------------------------------------------------------------------------------------------------------|
| `Shipments`                  | Lifecycle orchestrator. `applyTransition`, `createFromStagingPost`, `saveManual`, `applyUpdate`, history hydration, export. |
| `ShipmentLineItems`          | Allocation math. `remainingPoolFor`, `overflowIfCounted`, `isOrderUnderAllocated`, `findUnderAllocatedOrderIds`.             |
| `ShipmentReferences`         | `{orderRef}-sNNN` allocation with collision retry.                                                                           |
| `Rules`                      | Rules-engine registry + `planFor` orchestration.                                                                             |
| `CarrierEvents`              | Ingest carrier events. Normalize, dedupe via `eventHash`, resolve via mappings, drive shipping-axis transitions.             |
| `IntegrationStatusMaps`      | CRUD for mappings. `resolveInbound` / `resolveOutbound`. Records + resolves unmapped codes.                                  |
| `Integrations`               | Integration CRUD (project-config backed) + provider type registry.                                                           |
| `IntegrationReferences`      | Per-shipment external-id tracking.                                                                                           |
| `Emails`                     | Email CRUD + render + send.                                                                                                  |
| `TransitionEmails`           | `(axis, toCode) -> email[]` bindings. Event listener for `EVENT_SHIPMENT_STATUS_CHANGED`.                                     |
| `ShipmentFieldLayouts`       | Project-config-backed field layout for `Shipment`.                                                                           |
| `TrackedOrders`              | Owns `shipments_tracked_orders`. `evaluateAndUpsert`, `markActive`, `markIgnored`, `recomputeUnderAllocation`, `sweepForNewlyIgnoredStatuses`. Drives the Attention page filter. When an order's status moves into `orderStatusesToIgnore` it flips the row to `ignored` (off the Attention page); shipments are left intact. |

## Events

| Event                                            | Class                           | Fired                                                           |
|--------------------------------------------------|---------------------------------|-----------------------------------------------------------------|
| `Shipments::EVENT_BEFORE_CREATE_SHIPMENTS`       | `CreateShipmentsEvent`          | Before rules-engine shipments persist.                          |
| `Shipments::EVENT_AFTER_CREATE_SHIPMENTS`        | `CreateShipmentsEvent`          | After rules-engine shipments commit.                            |
| `Shipments::EVENT_SHIPMENT_STATUS_CHANGED`       | `ShipmentStatusChangedEvent`    | Creation (`fromCode = null`) + every transition, in transaction. |
| `Integrations::EVENT_REGISTER_INTEGRATIONS`      | `RegisterIntegrationsEvent`     | Module classes register provider types.                         |
| `Rules::EVENT_REGISTER_RULES`                    | `RegisterShipmentRulesEvent`    | Module classes register shipment rules.                         |

## Order completion hook

Auto-creation is wired in `Plugin::init()` via `Event::on(Order::class, Order::EVENT_AFTER_COMPLETE_ORDER, ...)`. There's no plugin-level event for "order completed", you listen directly to Commerce's order event if you need a peer hook.

## Queue jobs

| Job                        | Queued by                                                     | Does                                                                       |
|----------------------------|---------------------------------------------------------------|----------------------------------------------------------------------------|
| `CreateShipmentsJob`       | Nothing in `src/`; available for callers that want to defer creation | Wraps `Shipments::createFor` for queue execution. The plugin itself runs `createFor` synchronously from `Plugin::createShipmentsOnOrderComplete` on `Order::EVENT_AFTER_COMPLETE_ORDER`, so admins see new shipments immediately after checkout. Use this job from a custom listener if you want to push creation off the request path. |
| `PushShipmentJob`          | Per-shipment push button; custom listeners                    | Calls `$provider->sendShipmentWithEvents($shipment, $order)`. Permanent-vs-retryable. |
| `SendShipmentEmailJob`     | `TransitionEmails::onShipmentStatusChanged` (via event)       | Renders + sends one email.                                                 |

## Exception hierarchy

```text
yii\base\UserException
├── AllocationMismatchException         (staging totals vs pool diverge)
├── AllocationOverflowException         (re-enable/restore would over-allocate)
├── DuplicateShipmentReferenceException (reference collision; retriable once)
├── IncompleteCoverageException         (saveManual with enforceCoverage=true)
├── IntegrationStatusMapException       (map row save failed)
├── InvalidTransitionException          (invariant or optimistic-lock fail)
└── OrderNotCompletedException          (shipment creation on non-completed order)

yii\base\Exception
└── IntegrationException                (retryable integration error)
    └── PermanentIntegrationException   (non-retryable; PushShipmentJob stops)
```

## Concurrency model

- **Staging submit lock:** `Craft::$app->getMutex()->acquire("shipments:order:{$orderId}:staging", 10)`. Two concurrent Save clicks on the same order serialize.
- **Transition lock:** `shipments:shipment:{$shipmentId}:transition`. Two concurrent status changes on the same shipment serialize.
- **Optimistic lock:** pass `expectedFromCode` to `applyTransition`. Caller's view of pre-state is verified under the lock.
- **Reference retry:** on `DuplicateShipmentReferenceException`, `persistSinglePlanWithReferenceRetry` retries up to 3 times.
- **Carrier event dedupe:** SHA-256 of `(shipmentId, code, dateOccurred, externalCode)` is a unique constraint. Second delivery returns `deduped=true`.

## Elements, queries, GraphQL

`Shipment` implements the full Craft element contract: index, sources (grouped by axis), sort options, table attributes, searchable attributes, field layouts, eager-loading maps (`order`, `lineItems`, `integrationReferences`), GraphQL interface + type + arguments. `hasTitles() = false`; the title column renders `getUiLabel()` which returns the reference. The element index has no bulk actions; multi-shipment editing happens via Craft's slideout-from-row pattern.

`Shipment::toArray()` overrides the `fulfillmentStatus` and `shippingStatus` keys to return their translated label (e.g. `"Fulfilled"`, `"In transit"`) instead of the raw enum value. This is what CSV export and any other `toArray()` consumer sees. GraphQL bypasses `toArray()` via field resolvers, so its responses still emit raw enum values (`"fulfilled"`, `"in_transit"`).

## Permissions

On top of Craft's built-in `accessPlugin-shipments`:

- `shipments-viewShipments`, see index, attention, edit pages.
- `shipments-editShipments`, create/edit shipments.
- `shipments-transitionShipments`, change fulfillment/shipping status.
- `shipments-deleteShipments`, soft-delete.
- `shipments-pushShipments`, queue integration push.
- `shipments-manageIntegrations`, CRUD integrations + mappings.
- `shipments-manageEmails`, CRUD emails + transition bindings.
- `shipments-manageSettings`, edit plugin settings + field layout.

Registered via `UserPermissions::EVENT_REGISTER_PERMISSIONS`. Controllers + element actions each `requirePermission` the appropriate handle.
