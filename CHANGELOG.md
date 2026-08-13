# Changelog

> **WIP.** This file is in flux until the first tagged release. Skip it in code reviews.

## Unreleased

### Added
- Added a `productTypesToIgnore` setting, so line items of product types that never ship (services, downloads) are left out of shipments and the coverage check.
- First-class `Shipment` element with its own status sources, element index, bulk actions, field layouts, and GraphQL type.
- Single fixed-vocabulary `Status` enum (`new`, `in_progress`, `on_hold`, `fulfilled`, `shipped`, `cancelled`), with a per-integration mapping table translating external codes into it. Statuses carry no built-in behavior except `shipped`, which advances the order.
- `Shipments::applyTransition()` as the single canonical status-write path; CP edits, bulk actions, REST API, and webhooks all route through it.
- Order auto-advance: reaching `shipped` advances the shipment's Commerce order to the handle configured in `autoAdvanceOrderStatusHandle`, via `AdvanceOrderStatusJob`.
- Granular permissions: `shipments-viewShipments`, `shipments-editShipments`, `shipments-transitionShipments`, `shipments-deleteShipments`, `shipments-pushShipments`, `shipments-manageIntegrations`, `shipments-manageEmails`, `shipments-manageSettings`.
- Attention page listing under-allocated completed orders, with date-range filtering.
- Transition emails: bind any email to any `toCode` transition. Enqueues inside the status-change transaction so notifications are durable with the write.
- `Shipments::EVENT_BEFORE_CREATE_SHIPMENTS`, `EVENT_AFTER_CREATE_SHIPMENTS`, and `EVENT_SHIPMENT_STATUS_CHANGED` events for extending the write path.
- `WebhookSigning` trait with hex and base64 HMAC helpers using constant-time compare.
- `PermanentIntegrationException` to mark a push job non-retryable; plain `IntegrationException` lets Craft's queue retry.
- REST API: `POST /shipments/api/shipments/{id}` for status / tracking updates. Accepts `integrationHandle` and `externalCode` so history rows record where the change came from.
- Read-only GraphQL: `shipments(...)` query with eager-loadable `order`, `lineItems`, `integrationReferences`.
- CSV export via `Shipments::findForExport(ShipmentExportQuery)`.
- Tracked orders: a `shipments_tracked_orders` table records which orders the plugin is actively watching for fulfillment. Orders without a row are invisible to the Attention page, so historical pre-install orders no longer flood it.
- Per-order **Order requires shipping** lightswitch on the order's Shipments tab. Turning it off cascade-disables every enabled shipment on the order and drops it off the Attention page.
- `orderStatusesToIgnore` plugin setting (UI: **Order statuses to ignore**). Orders whose Commerce status is in this list are auto-untracked on status change, and their shipments are cascade-disabled. Adding a handle to the setting runs a one-time retroactive sweep of orders currently in that status.

### Changed
- The “Order requires shipping” switch is locked off on an order whose line items are all skipped, and turning it on is rejected.
- Replaced the user-editable `ShipmentStatus` model with a fixed enum.
- Plugin settings moved off `settingsHtml()` to a custom CP route (the delta wrapper on the default form silently strips POST).
- Staging submissions serialize via `Craft::$app->getMutex()` per-order, and `applyTransition` serializes per-shipment while re-reading canonical state under the lock.
- Shipment reference allocation (`{orderRef}-sNNN`) retries once on `DuplicateShipmentReferenceException` for concurrent creates on the same order.
- Transition emails are enqueued via a DB-backed queue event fired inside the DB transaction, making the push atomic with the status write.
- Status history records the source integration handle and the external code it sent on every transition, with batched user/integration lookups to avoid N+1s.

### Fixed
- Fixed a bug where canceling the “Order requires shipping” confirmation reprompted without end.
- Fixed concurrent staging saves being able to double-allocate line-item quantities; the pool is now validated under a per-order mutex.
- Fixed an issue where a failed push could leave no reason on the shipment.
