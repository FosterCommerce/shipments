# Integrations

An **integration** is a saved connection to an outside fulfillment system (an ERP, a warehouse platform, a custom REST endpoint). The plugin ships no providers out of the box. Your developers or a separate plugin register provider classes, and you configure them here.

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

Every integration speaks its own status vocabulary. One system says `label_purchased`, another says `awaiting_shipment`, your ERP says `SHIPPED_TO_CARRIER`. The plugin uses one fixed vocabulary (see [status vocabulary](./status-vocabulary.md)). Mappings translate between the two.

Each mapping row has four fields:

1. **External code**, the integration's string. Match exactly what they send.
2. **External label**, optional human description; CP only.
3. **Direction**, one of:
   - **Inbound**, the integration sends this code to us; translate to our value.
   - **Outbound**, we send this code to the integration; translate our value to theirs.
   - **Bidirectional**, both.
4. **Maps to**, the plugin's internal status value.

### Example: generic ERP

Imagine an ERP that uses `RELEASED`, `PRODUCTION_STAGED`, `SHIPPED_TO_CARRIER`, `CLOSED`, `CANCELLED` in its lifecycle. Map each to whichever plugin status fits your workflow.

| External code        | Direction      | Maps to             |
|----------------------|----------------|---------------------|
| `RELEASED`           | bidirectional  | `new`               |
| `PRODUCTION_STAGED`  | bidirectional  | `in_progress`       |
| `SHIPPED_TO_CARRIER` | inbound        | `shipped`           |
| `CLOSED`             | inbound        | `shipped`           |
| `CANCELLED`          | bidirectional  | `cancelled`         |

When the ERP sends a webhook with `SHIPPED_TO_CARRIER`, the inbound handler finds this row, sees `shipped`, and changes the shipment. The original code is saved on the history row for audit.

When you click **Push to {integration name}** on a shipment, the outbound pusher looks up the reverse direction and sends the external code that matches your internal one.

`CLOSED` has no outbound mapping; the ERP only uses it inbound, which is fine.

## The Attention page

**Shipments -> Attention needed** lists completed orders whose enabled shipments don't cover every non-ignored line item. Usually happens when an admin disables a shipment without making a replacement. Click **Fix** to open the order's Shipments tab; the notice at the top tells you which line items need quantity, and the form lets you stage the missing shipment. Filter by order date using the controls at the top.

An inbound webhook code with no matching mapping is skipped: the shipment's status doesn't change, and nothing is recorded. If an integration sends a code you care about, add a mapping for it.

## Testing an integration

The usual flow:

1. Configure the integration with test credentials.
2. Map a handful of the vendor's most common codes.
3. Create a test order and stage a shipment.
4. Use the vendor's webhook tester (most platforms have one) to send a signed sample webhook to `https://your-site.test/actions/shipments/gateway/handle?integration={your-integration-handle}`.
5. Check that the shipment changed. Check that the **Status history** tab shows the integration as the source and the original external code it sent.
6. If nothing changed, the code probably isn't mapped. Add a mapping for it and resend.
7. For outbound: open a shipment, click **Push to {name}** in the sidebar, and confirm the remote got your payload. Watch the last-push-attempt timestamp and any error message populate on the shipment as the queue runs.

## Permissions

- `shipments-manageIntegrations`, required to manage integrations and mappings.
- `shipments-pushShipments`, required to use **Push to {name}**.

The split lets you give CS agents `viewShipments` only, warehouse leads `editShipments` + `transitionShipments` + `pushShipments`, and the integration engineer `manageIntegrations`.

## What breaks if...

- **You rename an integration handle**: webhook URLs and the source labels on history rows break. Treat handles as permanent. If you must rename, change the vendor's webhook config first, then here, and expect a brief outage.
- **You delete an integration**: the database cascades. Mappings and reference rows on shipments delete with it; history rows keep their values but lose the source link. Don't delete to "reset." Disable instead.
- **You change a mapping's internal code**: future webhooks translate to the new code. Past history rows keep their original values. Nothing retroactively changes.
- **Two integrations' external codes collide**: no problem. Each integration's mappings are kept separate, so two integrations can have `SHIPPED` mean different things.
