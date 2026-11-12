# Status transitions

Every shipment has one status. This page covers how it changes and what gets recorded. Audience: store admins + CS leads.

## The statuses

`new -> in_progress -> fulfilled -> shipped`, with `on_hold` and `cancelled` reachable at any point. See the [status vocabulary](./status-vocabulary.md) for what each means. The list is fixed; admins don't add their own.

## When to change it

A typical flow, not a required one. Your store decides what each status means and which ones it uses; see [you decide what each status means](./status-vocabulary.md#you-decide-what-each-status-means).

| Event                                       | Status                  |
|---------------------------------------------|-------------------------|
| Shipment created                            | `new` (automatic)       |
| Started picking and packing                 | `in_progress`           |
| Waiting for stock or a review               | `on_hold`               |
| Packed and ready in the warehouse           | `fulfilled`             |
| Handed off to the carrier                   | `shipped`               |
| Order won't ship                            | `cancelled`             |

## How to change it

**From the shipment edit page** (`/admin/shipments/shipments/<id>`):

Pick a new value in **Status**. Add an optional **Status change note** (saved on the history row). Save.

**From the order's Shipments tab:**

Click **Manage shipment** on the shipment card.

**Many shipments at once:**

Select shipments on the Shipments index and choose **Set status**. The action skips canceled shipments and counts them as failed, because reopening one counts its units as allocated again.

**From a carrier delivery:**

When **Set shipments to Shipped when a carrier delivery is created** is on (the default), creating a label or bill of lading sets the shipment to `shipped`. Voiding the last active delivery sets a `shipped` shipment back to its previous status. With the setting off, neither action changes the status.

**Via an integration webhook:**

The inbound webhook translates the integration's code through the mapping table, then applies the status. See [integrations](./integrations.md).

## The rules

There is one guardrail: **two changes to the same shipment can't run at once.** The plugin serializes them with a per-shipment lock so each sees the real current state, whether the change comes from the CP, the API, or a webhook.

No field is ever required to reach a status. A shipment can become `shipped` with or without a tracking number. Any status can follow any other.

## What `shipped` does to the order

Reaching `shipped` advances the shipment's Commerce order to the order status configured under **Shipments -> Settings**. One-way: moving back out of `shipped` does not move the order back. See the [status vocabulary](./status-vocabulary.md#the-statuses-with-built-in-behavior).

## What a locking status does to the order

While a shipment on a completed order has a locking status, the order's line items and total can't change. Choose the locking statuses under **Shipments -> Settings -> General -> Shipment statuses that lock orders**. To turn the lock off, turn off **Lock orders in fulfillment**. The save fails if it adds, removes or swaps a line item, or changes a line item's quantity, price or options. It also fails if it changes the order total. The error names the shipment's status: **The order’s line items and total can’t change while a shipment on it is Fulfilled.** Other fields, such as notes and the order status, still save.

`new` and `cancelled` never lock an order. See [Configure](../installation.md#configure) for the default.

## What the order's status does to shipments

List Commerce order statuses under **Shipments -> Settings -> General -> Order statuses that cancel shipments**, and an order moving into one of them cancels every shipment on it that isn't `shipped` and has no active carrier delivery. To cancel a shipment that has one, void the delivery, then set the status to `cancelled`. Each cancellation records the order as its cause in the shipment's history.

Moving the order back out of those statuses restores each of those shipments to the status it held immediately before. A shipment canceled any other way, by an admin or by an integration, stays canceled. A shipment already at `shipped` is left alone in both directions.

Emails bound to the `cancelled` transition send when this runs, and emails bound to the restored status send when the order moves back out. Check your triggers under **Shipments -> Settings -> Emails** before adding a status here.

## Derived ship date

The plugin doesn't store a "shipped" timestamp. `dateShipped` is read from the status history: the latest time the shipment was set to `shipped`, and only while it is Shipped. `dateScheduledShip` is separate, the **Pickup Date** you set on the shipment's **Delivery** tab when a delivery provider is enabled; it is not transition-driven.

## History

Every status change is recorded. Open the **Status history** tab on the shipment edit page to see them, newest first. Each entry shows:

- What the status was before and what it is now.
- Who made the change (blank for webhooks and background jobs).
- Which integration drove it, if any (blank for manual CP changes).
- The raw code the integration sent, if any.
- The optional note.
- The timestamp.

## Emails on transition

Admins attach any email to any status-change trigger under **Shipments -> Settings -> Emails -> {email} -> Transition triggers**. Multiple emails can share a trigger; every match queues up. See the [emails guide](./emails.md).

## Canceled vs disabled vs deleted

- **`cancelled` status**: the shipment exists and is visible, but won't ship. Its quantity returns to the pool, so another shipment can claim the same line items.
- **Disabled**: the shipment is paused. Quantity returns to the pool. Re-enabling checks the math.
- **Deleted / trashed**: hidden from normal views. Quantity returns to the pool. Restore checks the math.

Pick by intent: cancel means "this won't ship and I want the record," disable means "pause this for now," delete means "this was a mistake."
