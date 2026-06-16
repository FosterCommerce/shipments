# Integration tests

These tests require a full Craft CMS bootstrap (`craftcms/phpunit` + Codeception, or a custom bootstrap that instantiates `\craft\console\Application` against a fixture database). They aren't wired up yet; the skeletons below document the cases the service layer needs to cover.

## Cases to implement

**`Shipments::applyTransition`**
- History row is written with fromCode + toCode + userId + sourceIntegrationId + sourceExternalCode.
- `EVENT_SHIPMENT_STATUS_CHANGED` fires with the correct payload inside the transaction.
- Concurrent `applyTransition` calls on the same shipment serialize via the mutex.

**`Shipment::getDateShipped`**
- Returns null when no matching history row exists.
- Returns the earliest `dateCreated` for `toCode = shipped`.
- A re-transition does not change the value; first matching row wins.
- Result is instance-cached; second call doesn't re-query.

**`Shipments::createFromStagingPost`**
- Rejects allocations that don't sum to the remaining pool exactly.
- Rejects allocations for line items not in the pool (`submittedOutsidePool=true`).
- Concurrent submissions on the same order serialize; the second fails pool validation.
- `DuplicateShipmentReferenceException` retries once then surfaces.

**`ShipmentLineItems::overflowIfCounted`**
- Returns empty when re-enabling wouldn't exceed ordered qty.
- Returns per-line-item overflow map when it would.

**`IntegrationStatusMaps::resolveInbound` / `resolveOutbound`**
- Inbound resolves direction=inbound + bidirectional, ignores direction=outbound.
- Outbound resolves direction=outbound + bidirectional, ignores direction=inbound.
- Unmapped codes return null without side effects.

**`TransitionEmails::onShipmentStatusChanged`**
- Queues `SendShipmentEmailJob` for every enabled binding matching `toCode`.
- Skips disabled emails.
- No queue push when `$event->shipment->id` or `$event->history->id` is null.
- Runs inside the status-change transaction (so email enqueue rolls back if transition fails).

**`ShipmentReferences::allocate`**
- First allocation on an order returns `{orderReference}-s001`.
- N-th allocation increments past existing shipment `number` values on the same order.
- Falls back to `order.number` when `order.reference` is null, and to `order-{id}` when both are null.
- Concurrent allocations on the same order surface via the `(orderId, number)` unique index; caller retries.

**`Settings` validators (require `Craft::t()` so need bootstrap)**
- `validateLineItemStatusGroups` rejects non-array, missing mode, empty `statusHandles`, duplicate handle across groups.
- `validateShippingCategoryGroups` rejects non-array, missing mode, empty `categoryHandles`, duplicate handle across groups.
- `validateInventoryGroupingModes` rejects non-array and unknown mode per bucket.
- `setAttributes` normalizes incoming rows: drops non-array rows, non-string handles, blank handles; preserves `mode`.

**`SettingsController::actionSaveSettings`**
- Clears `lineItemStatusGroups` when `groupingSource` is anything other than `line-item-status`.
- Clears `shippingCategoryGroups` when `groupingSource` is anything other than `shipping-category`.
- Triggers `TrackedOrders::sweepForNewlyIgnoredStatuses` only for handles newly added to `orderStatusesToIgnore`.
- Invalidates the Attention-count cache on save (so the badge refreshes).

## Running

Once `craftcms/phpunit` is wired up with `codeception.yml` and a test DB:

```sh
composer test:all
```

Unit tests (`tests/unit/`) run standalone and don't require the Craft bootstrap:

```sh
composer test
```
