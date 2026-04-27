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

## Unmapped integration statuses

Every external code an integration sent (via inbound webhook or the carrier-events endpoint) that the plugin didn't recognize.

Shows up when:

- A new integration sent a code you haven't mapped.
- An integration started using a code they hadn't before (carrier rollout, platform upgrade).
- The vendor has a typo or misconfiguration.

Each row shows:

- **Integration**, the integration's name.
- **Axis**, fulfillment or shipping.
- **External code**, the raw string the integration sent.
- **Occurrences**, how many times we've seen this exact row. Goes up on every re-sighting without duplicating the row.
- **Last seen**, the most recent delivery timestamp.
- **Map** button, links straight to the integration's status-mapping editor.

**This matters.** The webhook that delivered the unmapped code did NOT update the shipment. The real world and the plugin's view of it have drifted. Either:

1. Add a mapping. The plugin resolves the attention row as soon as you save, and future deliveries of that code update shipments correctly. You may need to fix any shipments that got stuck.
2. Contact the vendor if the code looks wrong. Don't map a code you don't understand.

## The subnav badge

The Shipments subnav shows a count badge on **Attention needed** equal to the number of under-allocated orders. Unmapped statuses are *not* in the badge (they're a softer signal). The badge refreshes on every page load.

## Who sees it

Any user with `shipments-viewShipments` can open the page. The **Map** button on an unmapped row goes to the mapping editor, which needs `shipments-manageIntegrations`. Viewers without that permission can see the row but can't fix it.

## Limits

- No email alerts when rows appear. If you want push alerts, have your developer build a listener on the plugin's status-change event (see the [events reference](../reference/events.md)).
- No auto-resolve for under-allocated orders. You stage the missing shipment by hand.
- The unmapped list isn't filterable by integration. Scan by eye; most stores only have a few.
