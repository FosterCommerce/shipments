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

Pick a new value in **Status**. Add an optional **Status change message** (saved on the history row). Save.

**From the order's Shipments tab:**

Click **Edit details** on the shipment card.

**Many shipments at once:**

The Shipments index has no bulk-transition action. Click a row to open the shipment in a slideout and change the status there. The slideout is the fastest way to move through several shipments without leaving the index.

**Via an integration webhook:**

The inbound webhook translates the integration's code through the mapping table, then applies the status. See [integrations](./integrations.md).

## The rules

There is one guardrail: **two changes to the same shipment can't run at once.** The plugin serializes them with a per-shipment lock so each sees the real current state, whether the change comes from the CP, the API, or a webhook.

No field is ever required to reach a status. A shipment can become `shipped` with or without a tracking number. Any status can follow any other.

## What `shipped` does to the order

Reaching `shipped` advances the shipment's Commerce order to the order status configured under **Shipments -> Settings**. One-way: moving back out of `shipped` does not move the order back. No other status touches the order. See the [status vocabulary](./status-vocabulary.md#the-one-status-with-built-in-behavior).

## Derived ship date

The plugin doesn't store a "shipped" timestamp. `dateShipped` is read from the status history: the first time the shipment reached `shipped`. `dateScheduledShip` is separate, a merchant-intended ship date you set on the edit page; it is not transition-driven. If you backdate or correct a transition, `dateShipped` follows automatically.

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

## Cancelled vs disabled vs deleted

- **`cancelled` status**: the shipment exists and is visible, but won't ship. Its quantity stays allocated (doesn't return to the pool).
- **Disabled**: the shipment is paused. Quantity returns to the pool. Re-enabling checks the math.
- **Deleted / trashed**: hidden from normal views. Quantity returns to the pool. Restore checks the math.

Pick by intent: cancel means "this won't ship and I want the record," disable means "pause this for now," delete means "this was a mistake."
