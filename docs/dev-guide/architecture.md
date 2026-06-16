# Architecture reference

How the pieces fit together. Audience: developers extending or integrating with the plugin.

## Data model

- **`Shipment`**, a Craft element. Owns a grouped allocation of order line-item quantities plus fulfillment fields (tracking, carrier, service, scheduled ship date, notes, free-text fields via a plugin-wide field layout), and a single `status` column. Disabling is admin-driven only (the **Enabled** lightswitch on the shipment edit page); the plugin never disables shipments on its own, and the audit for a manual disable lives in Craft's element revision log.
- **`shipments_shipment_line_items`**, join rows between a shipment and Commerce line items, carrying the per-line-item quantity the shipment covers.
- **`shipments_shipment_status_history`**, audit log. Every `applyTransition` writes one row with `fromCode`, `toCode`, `userId`, `sourceIntegrationId`, `sourceExternalCode`.
- **`shipments_integrations`**, named external systems (an ERP, a warehouse platform, etc.). Each integration wraps a `Provider` subclass with settings.
- **`shipments_integration_references`**, per-shipment rows mapping `(shipmentId, integrationId) -> externalId + optional url override`.
- **`shipments_integration_status_maps`**, per-integration translation table between the integration's vocabulary and our `Status` enum. Rows have `direction` (inbound/outbound/bidirectional), `externalCode`, `externalLabel`, `internalCode`.
- **`shipments_transition_emails`**, bindings from `toCode` to notification emails.
- **`shipments_emails`**, notification-email definitions (project-config backed).
- **`shipments_tracked_orders`**, which completed orders the plugin is actively watching for fulfillment, plus each order's cached `shippable` verdict, `state` (active or ignored), and `underAllocated` flag. The Attention page joins this table; orders without a row are invisible to it.

## Status model

One fixed-vocabulary status stored as a string code:

| Enum     | Driven by                          | Values                                                              |
|----------|------------------------------------|--------------------------------------------------------------------|
| `Status` | Merchant / 3PL, or an integration  | `new`, `in_progress`, `on_hold`, `fulfilled`, `shipped`, `cancelled` |

The vocabulary is fixed; integration-specific external codes map in via `IntegrationStatusMaps`. A status carries no built-in behavior except `shipped`: reaching it advances the shipment's Commerce order (see below). See [status vocabulary](../user-guide/status-vocabulary.md) for the meaning of each case.

## Write paths, all route through one service

Every shipment status change, CP click, webhook ingestor, REST call, ends up here:

```php
Shipments::applyTransition(
    Shipment $shipment,
    Status $to,
    ?User $user = null,
    ?string $message = null,
    ?Integration $source = null,
    ?string $externalCode = null,
): ?Shipment
```

Inside that method, in order:

1. Acquire a per-shipment mutex: `shipments:shipment:{id}:transition`. Serializes concurrent transitions on the same shipment.
2. Re-read the canonical state under the lock.
3. Open a DB transaction.
4. Write `status` and save via `Craft::$app->getElements()->saveElement($shipment)`. No field is required to reach any status. The `dateShipped` timestamp is derived on read from the history rows (`Shipment::getDateShipped()`), not stored.
5. Insert a `ShipmentStatusHistory` record with the source integration and external code.
6. Fire `EVENT_SHIPMENT_STATUS_CHANGED` **inside** the transaction. Listeners that push queue jobs (`TransitionEmails`, the order-advance hook) ride the same DB connection; Craft's default queue is DB-backed, so the job push is atomic with the status write.
7. Commit. Release the mutex.

Same pattern for `Shipments::createFromStagingPost` (per-order mutex instead; pool validation under the lock).

## Order advance on `shipped`

`Plugin::advanceOrderStatusOnShipped` listens on `EVENT_SHIPMENT_STATUS_CHANGED`. On the edge into `shipped` (`toCode->advancesOrder()` true and `fromCode` not already advancing), it pushes an `AdvanceOrderStatusJob` for the order. The job moves the Commerce order to the handle configured in `Settings::$autoAdvanceOrderStatusHandle`. One-way; empty setting disables it.

## Services

| Service                      | Responsibility                                                                                                              |
|------------------------------|-----------------------------------------------------------------------------------------------------------------------------|
| `Shipments`                  | Lifecycle orchestrator. `applyTransition`, `createFromStagingPost`, `saveManual`, `applyUpdate`, history hydration, export. |
| `ShipmentLineItems`          | Allocation math. `remainingPoolFor`, `overflowIfCounted`, `isOrderUnderAllocated`, `findUnderAllocatedOrderIds`.             |
| `ShipmentReferences`         | `{orderRef}-sNNN` allocation with collision retry.                                                                           |
| `Rules`                      | Rules-engine registry + `planFor` orchestration.                                                                            |
| `IntegrationStatusMaps`      | CRUD for mappings. `resolveInbound` / `resolveOutbound`.                                                                     |
| `Integrations`               | Integration CRUD (project-config backed) + provider type registry.                                                          |
| `IntegrationReferences`      | Per-shipment external-id tracking.                                                                                          |
| `Emails`                     | Email CRUD + render + send.                                                                                                 |
| `TransitionEmails`           | `toCode -> email[]` bindings. Event listener for `EVENT_SHIPMENT_STATUS_CHANGED`.                                            |
| `ShipmentFieldLayouts`       | Project-config-backed field layout for `Shipment`.                                                                          |
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

Auto-creation is wired in `Plugin::init()` via `Event::on(Order::class, Order::EVENT_AFTER_COMPLETE_ORDER, ...)`. There's no plugin-level event for "order completed"; listen directly to Commerce's order event if you need a peer hook.

## Queue jobs

| Job                        | Queued by                                                     | Does                                                                       |
|----------------------------|---------------------------------------------------------------|----------------------------------------------------------------------------|
| `CreateShipmentsJob`       | Nothing in `src/`; available for callers that want to defer creation | Wraps `Shipments::createFor` for queue execution. The plugin itself runs `createFor` synchronously on `Order::EVENT_AFTER_COMPLETE_ORDER`, so admins see new shipments immediately after checkout. Use this job from a custom listener to push creation off the request path. |
| `AdvanceOrderStatusJob`    | The order-advance hook on `EVENT_SHIPMENT_STATUS_CHANGED`      | Advances a shipment's Commerce order to the configured target status after the shipment reaches `shipped`. |
| `PushShipmentJob`          | Per-shipment push button; custom listeners                    | Calls `$provider->sendShipmentWithEvents($shipment, $order)`. Permanent-vs-retryable. |
| `RecomputeAllocationJob`   | The Attention page, after an order's shipments change          | Recomputes the cached `underAllocated` verdict for the given orders.       |
| `SendShipmentEmailJob`     | `TransitionEmails::onShipmentStatusChanged` (via event)       | Renders + sends one email.                                                 |

## Exception hierarchy

```text
yii\base\UserException
├── AllocationMismatchException         (staging totals vs pool diverge)
├── AllocationOverflowException         (re-enable/restore would over-allocate)
├── DuplicateShipmentReferenceException (reference collision; retriable once)
├── IncompleteCoverageException         (saveManual with enforceCoverage=true)
├── IntegrationStatusMapException       (map row save failed)
└── OrderNotCompletedException          (shipment creation on non-completed order)

yii\base\Exception
└── IntegrationException                (retryable integration error)
    └── PermanentIntegrationException   (non-retryable; PushShipmentJob stops)
```

## Concurrency model

- **Staging submit lock:** `Craft::$app->getMutex()->acquire("shipments:order:{$orderId}:staging", 10)`. Two concurrent Save clicks on the same order serialize.
- **Transition lock:** `shipments:shipment:{$shipmentId}:transition`. Two concurrent status changes on the same shipment serialize.
- **Reference retry:** on `DuplicateShipmentReferenceException`, `persistSinglePlanWithReferenceRetry` retries up to 3 times.

## Elements, queries, GraphQL

`Shipment` implements the full Craft element contract: index, sources (grouped by status), sort options, table attributes, searchable attributes, field layouts, eager-loading maps (`order`, `lineItems`, `integrationReferences`), GraphQL interface + type + arguments. `hasTitles() = false`; the title column renders `getUiLabel()` which returns the reference. The element index has no bulk actions; multi-shipment editing happens via Craft's slideout-from-row pattern.

`Shipment::toArray()` overrides the `status` key to return its translated label (e.g. `"Fulfilled"`) instead of the raw enum value. This is what CSV export and any other `toArray()` consumer sees. GraphQL bypasses `toArray()` via field resolvers, so its responses still emit the raw enum value (`"fulfilled"`).

## Permissions

On top of Craft's built-in `accessPlugin-shipments`:

- `shipments-viewShipments`, see index, attention, edit pages.
- `shipments-editShipments`, create/edit shipments.
- `shipments-transitionShipments`, change status.
- `shipments-deleteShipments`, soft-delete.
- `shipments-pushShipments`, queue integration push.
- `shipments-manageIntegrations`, CRUD integrations + mappings.
- `shipments-manageEmails`, CRUD emails + transition bindings.
- `shipments-manageSettings`, edit plugin settings + field layout.

Registered via `UserPermissions::EVENT_REGISTER_PERMISSIONS`. Controllers + element actions each `requirePermission` the appropriate handle.
