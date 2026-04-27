# Integrations

An **integration** is a saved connection to an outside fulfillment system (ShipStation, your ERP, a custom REST endpoint). The plugin ships no providers out of the box. Your developers or a separate plugin register provider classes, and you configure them here.

Audience: store admins connecting, mapping, and monitoring integrations.

## Setting one up

**Shipments -> Settings -> Integrations -> New.**

1. **Name**, shown in CP dropdowns, email render context, and bulk-action menus.
2. **Handle**, a stable identifier used in webhook URLs and on history rows. Never change it after you've set it.
3. **Provider**, the dropdown lists every provider class a site module or plugin has registered. If it's empty, no providers are installed yet; ask your developer.
4. **URL template**, optional. Builds clickable links from external IDs on shipment cards. Supports `{externalId}` and `{shipment.reference}` placeholders.
5. **Enabled**, a disabled integration rejects inbound webhooks (with a 400) and hides from the push bulk-action.
6. **Provider settings**, whatever fields the provider needs (credentials, webhook secret, endpoint URL).

Save the integration. Then click through to the **Status mappings** editor to wire up code translation.

## Status mappings

Every integration speaks its own status vocabulary. ShipStation says `label_purchased`. ShipStation2 says `awaiting_shipment`. Your ERP says `SHIPPED_TO_CARRIER`. The plugin uses one fixed vocabulary per axis (see [status vocabulary](./status-vocabulary.md)). Mappings translate between the two.

The editor has two tables:

- **Fulfillment mappings**, translate warehouse codes.
- **Shipping mappings**, translate carrier codes.

Each row has four fields:

1. **External code**, the integration's string. Match exactly what they send.
2. **External label**, optional human description; CP only.
3. **Direction**, one of:
   - **Inbound**, the integration sends this code to us; translate to our value.
   - **Outbound**, we send this code to the integration; translate our value to theirs.
   - **Bidirectional**, both.
4. **Maps to (fulfillment|shipping)**, the plugin's internal status value.

### Example: generic ERP

Imagine an ERP that uses `RELEASED`, `PRODUCTION_STAGED`, `SHIPPED_TO_CARRIER`, `CLOSED`, `CANCELLED` in its fulfillment lifecycle.

| External code        | Direction      | Maps to             |
|----------------------|----------------|---------------------|
| `RELEASED`           | bidirectional  | `open`              |
| `PRODUCTION_STAGED`  | bidirectional  | `in_progress`       |
| `SHIPPED_TO_CARRIER` | inbound        | `fulfilled`         |
| `CLOSED`             | inbound        | `fulfilled`         |
| `CANCELLED`          | bidirectional  | `cancelled`         |

When the ERP sends a webhook with `SHIPPED_TO_CARRIER`, the inbound handler finds this row, sees `fulfilled`, and changes the shipment. The original code is saved on the history row for audit.

When you click **Push to {integration name}** on a shipment, the outbound pusher looks up the reverse direction and sends the external code that matches your internal one.

`CLOSED` has no outbound mapping; the ERP only uses it inbound, which is fine.

## The Attention page

**Shipments -> Attention needed.** Two sections:

### Under-allocated orders

Completed orders whose enabled shipments don't cover every non-ignored line item. Usually happens when an admin disables a shipment without making a replacement. Click **Fix** to open the order's Shipments tab; the notice at the top tells you which line items need quantity, and the form lets you stage the missing shipment.

Filter by order date using the controls at the top.

### Unmapped integration statuses

Every external code the plugin got from an inbound webhook but didn't recognize. Each row shows the integration, axis, external code, how many times we've seen it, and when we last saw it.

Click **Map** to jump to that integration's mapping editor. Add a row for the external code and save. The plugin marks the attention row resolved on save and it disappears. If the same code arrives again before you map it, the row's counter goes up; no duplicate rows.

**This is your signal that the integration sent something the plugin didn't know about.** Either map it, or contact the vendor if the code looks wrong. Don't ignore it: the webhook that delivered the code didn't update the shipment, so real-world state and plugin state drift further apart each time the code arrives.

## Testing an integration

The usual flow:

1. Configure the integration with test credentials.
2. Map a handful of the vendor's most common codes.
3. Create a test order and stage a shipment.
4. Use the vendor's webhook tester (ShipStation has one; most do) to send a signed sample webhook to `https://your-site.test/shipments/webhooks/{your-integration-handle}`.
5. Check that the shipment changed. Check that the **Status history** tab shows the integration as the source and the original external code it sent.
6. Check **Shipments -> Attention needed** for any codes you still need to map.
7. For outbound: open a shipment, click **Push to {name}** in the sidebar, and confirm the remote got your payload. Watch the last-push-attempt timestamp and any error message populate on the shipment as the queue runs.

## Permissions

- `shipments-manageIntegrations`, required to manage integrations and mappings.
- `shipments-pushShipments`, required to use **Push to {name}**.

The split lets you give CS agents `viewShipments` only, warehouse leads `editShipments` + `transitionShipments` + `pushShipments`, and the integration engineer `manageIntegrations`.

## What breaks if...

- **You rename an integration handle**: webhook URLs and the source labels on history rows break. Treat handles as permanent. If you must rename, change the vendor's webhook config first, then here, and expect a brief outage.
- **You delete an integration**: the database cascades. Mappings, carrier events, and reference rows on shipments all delete with it. Don't delete to "reset." Disable instead.
- **You change a mapping's internal code**: future webhooks translate to the new code. Past history rows keep their original values. Nothing retroactively changes.
- **Two integrations' external codes collide**: no problem. Each integration's mappings are kept separate, so two integrations can have `SHIPPED` mean different things.
