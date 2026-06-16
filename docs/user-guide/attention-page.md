# Attention needed

**Shipments -> Attention needed.** The one place admins check to see "what's wrong right now."

## What shows up here

Only orders the plugin is actively watching. An order enters that group the first time the plugin runs its rules for it (on order complete when auto-creation is on, or when an admin runs `rebuild`), or when an admin flips **Order requires shipping** on from the order's Shipments tab. Orders that have never been touched by the plugin stay invisible here, which is why historical pre-install orders don't flood the page.

If you install the plugin on a store with existing orders, the page is empty at first. Orders appear as they complete under the plugin, or as you explicitly turn the switch on.

## Under-allocated orders

Completed tracked orders whose enabled shipments don't cover every shippable line item.

Shows up when:

- An admin disabled a shipment without creating a replacement.
- An admin trashed a shipment and didn't re-stage the quantity.
- Auto-creation was off when the order completed and nobody staged it by hand.
- A rules-engine run produced partial coverage (rare; the fallback rule claims whatever's left, but a custom rule could miss).

Each row shows:

- **Order**, links to the Commerce order edit page.
- **Date**, the order date.
- **Customer**, links to the customer's Craft user record.
- **Missing**, the number of line items that still need quantity.
- **Fix** button, links straight to the order's Shipments tab so you can stage the missing shipment.

**Clearing a row:** stage shipments that cover the missing quantity. The row drops off on the next page load.

When an inbound integration webhook sends a status code that isn't mapped, the shipment is left untouched and nothing is recorded. Add a mapping for any code you want to act on; see [integrations](./integrations.md).

## The subnav badge

The Shipments subnav shows a count badge on **Attention needed** equal to the number of under-allocated orders. The badge refreshes on every page load.

## Who sees it

Any user with `shipments-viewShipments` can open the page and the **Fix** buttons. Staging the missing shipment from the order's Shipments tab needs `shipments-editShipments`.

## Limits

- No email alerts when rows appear. If you want push alerts, have your developer build a listener on the plugin's status-change event (see the [events reference](../reference/events.md)).
- No auto-resolve for under-allocated orders. You stage the missing shipment by hand.
