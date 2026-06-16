# Status vocabulary

Every shipment carries one status from a fixed list. Audience: store admins + CS leads running day-to-day fulfillment.

Admins do not add their own codes. Integration-specific codes map into this list via [status mappings](./integrations.md).

## The statuses

**Enum:** `fostercommerce\shipments\enums\Status`. **Who sets it:** you (or your 3PL, reporting through an integration), from the shipment edit page, the REST API, or an inbound webhook.

| Value         | Label       | Typical use                                                        |
|---------------|-------------|--------------------------------------------------------------------|
| `new`         | New         | Default state. Created, not yet worked.                            |
| `in_progress` | In progress | Being prepared (picking, packing, labeling).                       |
| `on_hold`     | On hold     | Paused (stock issue, fraud review, address verification).          |
| `fulfilled`   | Fulfilled   | Warehouse work is done.                                            |
| `shipped`     | Shipped     | In the carrier's hands. See the behavior below.                    |
| `cancelled`   | Cancelled   | Won't ship.                                                        |

A status is a label. The plugin does not attach hidden behavior to most of these values: what a status triggers (an email, a push to an integration) is something you configure, not something baked into the code.

## You decide what each status means

The "typical use" column is a suggestion, not a rule. Your store chooses how to use the available statuses to fit your workflow. One store treats `fulfilled` as "packed and ready, not yet shipped"; another treats it as the moment the order is effectively done. Both are correct.

The plugin does not track detailed carrier events (there is no `delivered`, `in_transit`, `out_for_delivery`, and so on). It models the merchant's side of fulfillment, so the last status you set is often the final word on a shipment. If you need carrier-level tracking detail, that is the job of the carrier's own tracking page, not this plugin.

The one fixed behavior is `shipped` advancing the order, described next. Everything else is yours to define.

## The one status with built-in behavior

`shipped` is the single exception. When a shipment reaches `shipped`, the plugin advances its Commerce order to the order status you configure under **Shipments -> Settings** (the auto-advance target). This is one-way: moving the shipment back out of `shipped` does not move the order back. Leave the target empty to disable it.

No status requires any field. You can move a shipment to `shipped` with or without a tracking number; tracking, carrier, and service are always optional.

## Derived ship date

`Shipment::getDateShipped()` reads from the `shipments_status_history` table at read time, returning the first transition into `shipped`. It is not a stored column; the history table is the source of truth, and the getter is a convenience layer. It is instance-cached. Null when the shipment has never reached `shipped`.

## Color palette

`Status::color()` returns a Craft CP status-dot handle, used by `Cp::statusLabelHtml()` to render the pills on the element index and order tab.

| Value         | Color  |
|---------------|--------|
| `new`         | gray   |
| `in_progress` | blue   |
| `on_hold`     | orange |
| `fulfilled`   | teal   |
| `shipped`     | green  |
| `cancelled`   | red    |

## Changing the vocabulary

You can't from the CP. The list is source-code. If a real workflow needs a missing case, open a PR with the enum change plus its translation entry, `color()` entry, and a note in this doc. The intent: a stable, plugin-wide vocabulary lets emails, jobs, dashboards, and integration providers all agree on what each code means without coordination.
