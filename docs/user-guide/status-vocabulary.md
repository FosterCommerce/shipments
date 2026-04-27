# Status vocabulary

The plugin tracks every shipment against two independent status axes. Both vocabularies are fixed; admins do not add their own codes. Integration-specific codes map into these via [status mappings](./integrations.md).

## Axis 1: Fulfillment status

**Enum:** `fostercommerce\shipments\enums\FulfillmentStatus`. **Modeled on:** Shopify's FulfillmentOrder lifecycle. **Who sets it:** the merchant (or their 3PL, reporting through an integration).

| Value           | Label           | Semantics                                                                                           | Terminal? |
|-----------------|-----------------|-----------------------------------------------------------------------------------------------------|-----------|
| `open`          | Open            | Default state. Created, not worked.                                                                 | no        |
| `in_progress`   | In progress     | Actively being prepared (picking, packing, labeling).                                               | no        |
| `scheduled`     | Scheduled       | Awaiting a future action (scheduled pickup, later release).                                         | no        |
| `on_hold`       | On hold         | Paused (stock issue, fraud review, address verification).                                           | no        |
| `fulfilled`     | Fulfilled       | Merchant considers this done. **Requires a tracking number** to transition into.                    | yes       |
| `cancelled`     | Cancelled       | Won't ship.                                                                                         | yes       |
| `incomplete`    | Incomplete      | Attempt errored mid-way; needs attention.                                                           | no        |

**Invariants the plugin enforces:**

- Transition **into** `fulfilled` requires a non-empty `trackingNumber`. Violations throw `InvalidTransitionException`.

Transitioning out of `fulfilled` (e.g. back to `in_progress` after a correction) is allowed and has no automatic side effects.

## Axis 2: Shipping status

**Enum:** `fostercommerce\shipments\enums\ShippingStatus`. **Modeled on:** common carrier webhook vocabularies (USPS, UPS, FedEx event codes). **Who sets it:** the carrier, via integration webhooks or the REST carrier-events endpoint. Admins can override manually from the edit page.

| Value                 | Label                | Semantics                                                                | Terminal? |
|-----------------------|----------------------|--------------------------------------------------------------------------|-----------|
| `pending`             | Pending              | First observed state; carrier acknowledged but no physical movement.     | no        |
| `pre_transit`         | Pre-transit          | Label generated; not yet scanned into the carrier's network.             | no        |
| `in_transit`          | In transit           | First "in-motion" scan.                                                  | no        |
| `out_for_delivery`    | Out for delivery     | On the last-mile vehicle.                                                | no        |
| `attempted_delivery`  | Attempted delivery   | Delivery attempt failed; carrier retries.                                | no        |
| `available_for_pickup`| Available for pickup | At a pickup point.                                                       | no        |
| `delivered`           | Delivered            | Successfully delivered.                                                  | yes       |
| `exception`           | Exception            | Problem that doesn't fit another status (delay, damage, lost).           | no        |
| `returned`            | Returned             | Shipped back to origin.                                                  | yes       |
| `failure`             | Failure              | Undeliverable; won't be retried.                                         | yes       |

**Null is a valid value.** A shipment with no observed carrier activity has `shippingStatus = null`. Subsequent movements update `dateShippingStatus`.

## Derived ship/delivery dates

`Shipment::getDateShipped()` and `Shipment::getDateDelivered()` derive their values from the `shipments_status_history` table at read time, returning the earliest matching row:

- `getDateShipped()`: first transition to `(axis = shipping, toCode = in_transit)`.
- `getDateDelivered()`: first transition to `(axis = shipping, toCode = delivered)`.

These are not stored as columns. The history table is the single source of truth; the getters are a convenience layer. Both are instance-cached.

## Color palette

Both enums expose `color()` returning a Craft CP status-dot handle (`gray`, `blue`, `green`, `orange`, `purple`, `red`). Used by `Cp::statusLabelHtml()` to render the pills on the element index and order tab.

## Customer-facing derivation

Not a stored column. Derived at display time when you need a single human-readable phrase:

```php
match (true) {
    in_array($shipment->fulfillmentStatus, ['cancelled', 'on_hold', 'incomplete'], true) => $shipment->getFulfillmentStatusEnum()->label(),
    $shipment->getDateDelivered() !== null => 'Delivered',
    $shipment->shippingStatus === ShippingStatus::OutForDelivery->value => 'Out for delivery',
    $shipment->shippingStatus === ShippingStatus::InTransit->value => 'In transit',
    $shipment->fulfillmentStatus === FulfillmentStatus::Fulfilled->value => 'Shipped',
    default => $shipment->getFulfillmentStatusEnum()->label(),
};
```

## Why two axes?

Shopify, ShipEngine, EasyPost, and ShipStation all separate merchant intent from carrier observation. Collapsing them into a single column is the anti-pattern: it forces you to pick whether "shipped" means "the merchant pressed the button" or "the carrier scanned it," and both answers are wrong for different workflows. The merchant axis drives emails ("your order is on its way"), the carrier axis drives tracking-page updates. Keep them separate, derive display state from both when you need one string.

## Changing the vocabulary

You can't. Both enums are source-code. If a real workflow needs a missing case (e.g. `partially_delivered`), open a PR with the enum change + translations + `color()` entry + semantic notes in this doc. The intent: plugin-wide stability lets emails, jobs, dashboards, and integration providers all agree on what codes mean without coordination.
