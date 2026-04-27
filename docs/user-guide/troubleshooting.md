# Troubleshooting

Common problems, by symptom. If nothing here fits, check the logs (`storage/logs/`) for the `shipments` category.

## Shipments aren't auto-creating on order complete

Check:

1. **Shipments -> Settings -> General -> Automatic shipment creation** is on.
2. The order is actually *completed*, not a cart. An order is complete when Commerce has flipped it past checkout.
3. The order's status is the Commerce default for its store. Orders that complete into a non-default status (held, fraud review) don't auto-create, on purpose.
4. The order has line items that aren't ignored. If every line item matches **Line item statuses to ignore**, the rules engine has nothing to work with.
5. Nothing in your project broke the creation. Check the Craft log for exceptions in the `shipments` category; a custom extension listening for new shipments can throw and block creation.

Workaround: stage from the order tab by hand, or run `./craft shipments/shipments/rebuild 1234`.

## "This order is not fully allocated" notice won't go away

Every non-ignored line item has to be fully covered by the order's enabled shipments. Check the bullet list under the notice to see which line items still have quantity.

Check:

1. Disabled shipments don't count. If you disabled one, its quantity went back to the pool. Re-enable it (Shipment detail page -> sidebar -> Enabled lightswitch) or stage a new shipment for the remaining quantity.
2. Trashed shipments don't count either. Restore from Craft's trash, or stage a fresh one.
3. If the math looks wrong, check **Shipments -> Settings -> General -> Line item statuses to ignore**. Line items with those statuses are left out of the coverage check.

## Save button stuck disabled on the staging form

The Save button only lights up when every line item's total across all groups equals the remaining quantity exactly. Watch the **Remaining** column per line item; you want it to read `0` for every line item before Save enables.

Common causes:

- Over-allocated one line item (negative Remaining). Drop the quantity in one of the groups.
- Under-allocated another (positive Remaining). Add quantity to a group.
- Added a line item that isn't in the remaining pool. The form shouldn't let you, but stale page state can sneak through; reload.

## Email isn't sending on a status change

Check:

1. The email is **Enabled** (the lightswitch on the email edit page).
2. The email has a **Transition trigger** checked for the axis and code you're changing to. Triggers are at the bottom of the email edit page.
3. The shipment actually changed to a code with a trigger. Editing and saving without changing status doesn't fire anything.
4. Craft's queue is running. `./craft queue/run`. If the queue is stalled, emails pile up there.
5. The HTML template path resolves. A missing template fails the job with a log error.
6. For **Custom** recipients: the To field renders to at least one valid address. Check the log for render errors.
7. For **Customer** recipients: the order has a customer email. Orders without one skip the send with a log warning.

Inspect queued jobs: **Utilities -> Queue Manager**. Look for the email send jobs; failed ones show the error.

## Shipment disappears from the order tab

You either disabled it or deleted it. Disabled still shows on the tab with a "Disabled" pill. Deleted (soft-delete) doesn't; it's in Craft's trash.

Restore:

- Disabled: open the shipment edit page (you can find it if you know the reference, or from the Shipments index with status filter set to include disabled) and flip the Enabled lightswitch back on.
- Trashed: **Shipments** index -> source dropdown -> "Trashed". Select the shipment -> Actions -> Restore. Runs the over-allocation check before restoring.

## Re-enabling a disabled shipment throws "would over-allocate"

While the shipment was disabled, its quantity went back to the pool. Someone used that quantity on a new shipment. Re-enabling would push the total over what was ordered.

Fix: disable or delete the newer shipment first, then re-enable the original. Or keep the newer one and leave the original disabled.

## Integration push fails

Open the shipment's Details tab and look at the last push error. Common messages:

- **Authorization error from the remote**: credentials are wrong. Check the integration's settings and env vars.
- **Signature mismatch on a webhook** (inbound): the webhook secret doesn't match the vendor's configuration.
- **4xx from the remote**: the remote rejected the payload. Usually a mapping or payload format issue; see [custom providers](../dev-guide/custom-providers.md) for your provider.

Retry behavior:
- A normal integration error lets Craft's queue retry on its default schedule.
- A permanent integration error marks the job failed and stops retrying. Find it in the queue, fix the root cause, and requeue.

## Unmapped external status keeps coming back

The inbound webhook keeps sending a code you haven't mapped. Every sighting bumps the occurrence count on the same attention row.

Fix: go to **Shipments -> Attention needed**, click **Map** on the row, add a mapping in the integration's mapping editor, and save. The attention row resolves on save.

If you don't know what the external code means, ask the vendor. Don't map a code you don't understand; you'll misroute shipments.

## Status changes are rejecting with "would violate invariant"

The plugin enforces a few hard rules:

- `fulfillmentStatus -> fulfilled` requires a non-empty `trackingNumber`. Add one, then try again.
- API callers can pass the status they expect as the starting point. If the shipment has moved to something else, the change is rejected. Re-read the shipment, pass the current status, and retry.

## CP actions say "Permission denied"

Your user group doesn't have the permission that action needs. Options:

- Ask an admin to grant the permission. See the [permissions guide](./permissions.md) for the mapping.
- Become an admin. Admins bypass all checks.

## GraphQL returns empty for `shipments`

Check:

1. The GraphQL schema has the `shipments.read` component granted. **GraphQL -> Schemas -> {schema} -> Shipments -> Query shipments**.
2. You're hitting the right site. Shipments aren't per-site; they all live under the primary site, but GraphQL queries still scope by the schema's site.
3. You're querying through the endpoint that uses your schema's token. Anonymous schemas usually don't have `shipments.read`.

## All shipments on an order went disabled at once

Two causes:

1. An admin flipped **Order requires shipping** off on the order's Shipments tab. The confirmation modal warned; the plugin disabled every enabled shipment and returned their line items to the pool. Flip the switch back on to stop the order being ignored, then re-enable the individual shipments as needed.
2. The order's Commerce status just changed into something in the plugin's **Order statuses to ignore** setting (**Shipments -> Settings -> General**). The plugin treats those statuses as "this order doesn't need fulfillment" and auto-disables its shipments. To recover: change the order's status, or remove the handle from the ignored list and then flip the switch back on manually.

The disable label on each shipment card tells you which path drove the disable:

- **"Disabled, order marked as not shipping."**: an admin flipped **Order requires shipping** off on the order's Shipments tab.
- **"Disabled, order status in plugin ignore list."**: the order's Commerce status moved into a handle in **Order statuses to ignore**.
- No label: an admin manually flipped the shipment's own **Enabled** lightswitch. Check Craft's element revision log to see who/when.

## Order's lightswitch is greyed out

The order's current Commerce status is in **Shipments -> Settings -> General -> Order statuses to ignore**. While the order sits in that status, the plugin refuses to track it. Change the order's status, or remove the handle from the setting.

## An inbound webhook says it updated my shipment but nothing changed

If the shipment is disabled (either manually or because its order was untracked), the plugin records the incoming event for the audit trail but doesn't update the shipment's status. Your developer can find the full context in the Craft log under the `shipments` category. To fix:

1. Re-enable the shipment (either flip **Order requires shipping** back on, or re-enable the shipment directly from its edit page).
2. Ask the vendor to re-send the latest state, or apply the current status by hand.

## Nothing here matches

Gather: the Craft log (`storage/logs/web-<date>.log`), the queue state (Utilities -> Queue Manager), the action you took, and the expected versus actual outcome. Attach to an issue.
