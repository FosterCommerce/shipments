# Creating shipments

How shipments come into existence. Audience: store admins and CS leads running day-to-day fulfillment.

## Three ways

1. **Automatically, when the order completes.** The rules engine runs every time a Commerce order completes and creates one or more shipments. No clicking.
2. **Manually, from the order's Shipments tab.** An admin opens the order, picks how many of each line item go on each shipment, and saves. Use this when auto-creation is off, when an order completed before you turned auto-creation on, or when you want finer control.
3. **Programmatically.** A console command, queue job, or REST API call creates the shipments. Your developers set this up.

## Automatic creation

Turn it on at **Shipments -> Settings -> General -> Automatic shipment creation**. When on, the plugin reacts to the order-complete event and:

1. Checks if the order already has any non-trashed shipments. If yes, stops. Safe to run twice.
2. Runs the rules from the **Grouping source** setting and produces a list of planned shipments.
3. Saves each one in `new` status.
4. Adds a "shipment created" row to history.
5. Fires the status-change event so emails and integration pushes can react.

The default grouping is **One shipment group per order**: every order gets one shipment covering every line item. Change the setting if your store needs splits.

### Grouping source: Craft Commerce inventory state

Splits the shipment into two buckets: items that are in stock at order time, and items that are backordered. For each bucket you choose **ship together** (one shipment for the whole bucket) or **one shipment per line item**. A line item that's partly in stock follows the **Quantity split mode** setting: `split` lets it appear in both buckets with partial quantities, `atomic` keeps the whole line item in the backorder bucket.

### Grouping source: Commerce line-item status

Define shipment groups in settings. Each group has a name, a mode (ship together or one per line item), and a list of Commerce line-item status handles. Line items whose status isn't in any group fall through to the single-shipment rule.

### Grouping source: Commerce shipping category

Define shipment groups in settings, keyed by Commerce **Shipping category**. Each group has a mode (ship together or one per line item) and a list of shipping-category handles. Use this when physical shipping constraints drive the split, for example LTL freight items that can't mix with parcel, hazmat items that need a dedicated carrier, or oversized goods that go via a different service. Line items whose category isn't in any group fall through to the single-shipment rule.

### Guardrails

- Line items matching **Line item statuses to ignore** are skipped by every rule and left out of the coverage check.
- **Enforce full coverage** (on by default) blocks saves until every non-ignored line item is fully accounted for across the order's shipments.
- Auto-creation runs on every completed order while **Create shipments automatically on order completion** is on. To stop auto-creation for orders in a hold or fraud-review status, add those statuses to **Order statuses to ignore**. Matching orders get no new shipments, and any shipments they already have stay as they are.

## Manual staging

Open any completed order's **🚚 Shipments** tab. If the order has line items that aren't on a shipment yet, you see a **Create shipments** section with:

- A pre-filled group claiming every remaining unit, or the split the rules engine suggested.
- A **Qty in group** input per line item that still has quantity left.
- A running **Remaining** counter per line item.

Totals across all groups must match the remaining pool exactly. Over-allocate one line item or under-allocate another, and the Save button stays disabled.

To split across multiple shipments, click **Add another shipment group** and spread the quantities. Remove a group with **Remove group**.

### Concurrency

Two admins staging the same order at the same time queue up behind a lock. The second save reads the pool *after* the first one commits, so it can't double-allocate. The second admin sees a "Staging totals don't match remaining quantity yet" error. Reload the page and the first admin's shipments are already there.

### Reference collisions

References look like `{orderReference}-sNNN`. Two admins creating shipments on the same order at the same moment can race; the plugin retries up to three times. You'll see the created shipment normally on success. If the three retries all lose (very rare), you get a duplicate-reference error; click Save again.

## Console

One command ships out of the box:

```sh
./craft shipments/shipments/rebuild 1234
```

Runs the rules engine for one order. Same safe-to-run-twice rule: does nothing if shipments already exist.

## Order requires shipping

At the top of the order's Shipments tab there's a **Order requires shipping** lightswitch. It answers one question: does this order have anything to ship?

- **On**: the plugin treats the order as in scope. Staging form is visible, auto-creation runs when the order completes, the order can appear on the Attention page if its shipments don't cover everything.
- **Off**: the plugin leaves the order alone. The staging form is hidden and the order drops off the Attention page. Any shipments it already has stay as they are.

The switch flips on automatically the first time the plugin creates shipments for the order, so you rarely need to touch it. Flip it manually when:

- You want the plugin to manage an older order that predates install, or an order that completed before you turned auto-creation on.
- You want to tell the plugin "this order doesn't need shipping" (a custom one-off, a digital bundle, a mis-ordered test).

Flipping the switch off asks you to confirm first. It only changes whether the plugin tracks the order; the order's shipments are left untouched either way.

If the order's Commerce status is in the plugin's **Order statuses to ignore** setting, the switch is locked off. Change the order's status or remove the handle from the setting to re-enable fulfillment for this order.

### Disabling a shipment

Turning **Order requires shipping** off, or an order moving into an ignored status, never disables or deletes shipments. A shipment only goes disabled when someone turns off its own **Enabled** switch on the shipment page. Craft's revision history records who did that and when.

## Disabled vs trashed

- **Disabled** (the Enabled lightswitch on the shipment edit page): the quantity returns to the unallocated pool, and the shipment still shows on the tab with a "Disabled" pill. Re-enabling checks the math first. If re-enabling would go over the ordered amount (because other shipments took that quantity in the meantime), it throws.
- **Deleted / Trashed** (the Delete button or gear menu): soft-deleted. Disappears from the tab. Quantity returns to the pool. Restoring from Craft's trash runs the same over-allocation check.

Both preserve the shipment's history and integration references. Neither is reversible in bulk; do it on purpose.
