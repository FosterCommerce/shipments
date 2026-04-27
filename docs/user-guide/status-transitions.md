# Status transitions

Every shipment has two status columns. One you control, one the carrier does. This page explains which to use when.

## Quick recap

- **Fulfillment status**, *your* side. You and your 3PL drive it. Values: `open -> in_progress -> (scheduled, on_hold) -> fulfilled`, plus `cancelled` or `incomplete` at any point.
- **Shipping status**, *the carrier's* side. Usually driven by carrier webhooks; editable by hand for corrections. Values: `pending -> pre_transit -> in_transit -> out_for_delivery -> delivered`, plus `attempted_delivery`, `available_for_pickup`, `exception`, `returned`, `failure`.

Both lists are fixed. See the [status vocabulary](./status-vocabulary.md) for what each one means.

## When to change which

| Event                                                       | Which one? | Code                                   |
|-------------------------------------------------------------|------------|----------------------------------------|
| Shipment created                                            | fulfillment | `open` (automatic)                     |
| Started picking and packing                                 | fulfillment | `in_progress`                          |
| Waiting for stock                                           | fulfillment | `on_hold`                              |
| Waiting for a future release date                           | fulfillment | `scheduled`                            |
| Label printed, handed off to carrier                        | fulfillment | `fulfilled` (requires a tracking number) |
| Order won't ship                                            | fulfillment | `cancelled`                            |
| Something went wrong and it needs attention                 | fulfillment | `incomplete`                           |
| Carrier generated tracking but hasn't scanned yet           | shipping    | `pre_transit`                          |
| Carrier scanned and is moving the package                   | shipping    | `in_transit`                           |
| Out for delivery today                                      | shipping    | `out_for_delivery`                     |
| Delivery attempted, missed the recipient                    | shipping    | `attempted_delivery`                   |
| At a pickup location                                        | shipping    | `available_for_pickup`                 |
| Successfully delivered                                      | shipping    | `delivered`                            |
| Delay, damage, lost                                         | shipping    | `exception`                            |
| Package came back                                           | shipping    | `returned`                             |
| Undeliverable                                               | shipping    | `failure`                              |

The rule of thumb: if it's a merchant action, it's fulfillment. If it's a carrier event, it's shipping.

## How to change it

**From the shipment edit page** (`/admin/shipments/shipments/<id>`):

Pick a new value in **Fulfillment status** or **Shipping status**. Add an optional **Status change message** (saved on the history row). Save. You can change both dropdowns in the same save; the plugin processes them one at a time.

**From the order's Shipments tab:**

Click **Edit details** on the shipment card.

**Many shipments at once:**

The Shipments index has no bulk-transition action. Open each shipment by clicking its row (Craft opens it in a slideout) and change the status there. The slideout pattern is the fastest way to move through several shipments without leaving the index.

**Via an integration webhook:**

The inbound webhook translates the integration's code through the mapping table, then updates the matching status. See [integrations](./integrations.md).

## The rules

The plugin checks a few hard rules on every status change, whether it comes from the CP, the API, or a webhook. If one fails, the change is rejected.

1. **`fulfillmentStatus -> fulfilled` requires a tracking number.** "Fulfilled" means the package left your warehouse attached to a trackable label. Transitioning without tracking is almost always a mistake. Inbound webhooks that arrive without tracking land on the attention page instead of transitioning the shipment.
2. **The caller can pass the status it expects as the starting point.** If the shipment has since moved to a different status, the change is rejected. This catches stale API clients.
3. **Two changes to the same shipment can't run at once.** The plugin queues them up so they each see the real current state.

## Dates derived from the history

The plugin doesn't store a separate "shipped" or "delivered" timestamp. It reads them from the status history:

| What you read              | Comes from                                                                       |
|----------------------------|----------------------------------------------------------------------------------|
| `dateShipped`              | First time the shipping status changed to `in_transit`.                          |
| `dateDelivered`            | First time the shipping status changed to `delivered`.                           |
| `dateShippingStatus`       | When the most recent shipping-status change happened.                            |
| `dateScheduledShip`        | Merchant-intended ship date (set on the shipment edit page; not transition-driven). |

The history table is the source of truth. If you backdate or correct a transition, the dates above follow automatically.

## History

Every status change is recorded. Open the **Status history** tab on the shipment edit page to see them, newest first. Each entry shows:

- Which status changed (fulfillment or shipping).
- What it was before and what it is now.
- Who made the change (blank for webhooks and background jobs).
- Which integration drove it, if any (blank for manual changes from the CP).
- The raw code the integration sent, if any.
- The optional note.
- The timestamp.

## What the customer sees

The plugin doesn't store a "what the customer sees" value. If you want one string for the customer ("Your order has shipped"), derive it from the two statuses. See the [status vocabulary](./status-vocabulary.md#customer-facing-derivation) for a starter snippet.

## Emails on transition

Admins attach any email to any status-change trigger under **Shipments -> Settings -> Emails -> {email} -> Transition triggers**. Multiple emails can share a trigger; every match queues up. See the [emails guide](./emails.md) for templates, recipients, and the send flow.

## Cancelled vs disabled vs deleted

- **`cancelled` fulfillment status**: the shipment exists and is visible, but won't ship. Its quantity stays allocated (doesn't go back to the pool).
- **Disabled**: the shipment is paused. Quantity returns to the pool. Re-enabling checks the math.
- **Deleted / trashed**: hidden from normal views. Quantity returns to the pool. Restore checks the math.

Pick by intent: cancel means "this won't ship and I want the record", disable means "pause this for now", delete means "this was a mistake".
