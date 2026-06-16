# Getting started

This walks you from `composer require` to your first saved shipment in about fifteen minutes. By the end you'll know how shipment status works, how the order page's staging form behaves, and where the automatic hooks live.

## 1. Install

```sh
composer require fostercommerce/shipments
./craft plugin/install shipments
```

In the CP you should see a **Shipments** nav item with three subnav entries: **Shipments**, **Attention needed**, and (for admins) **Settings**.

## 2. Complete a test order

Create a test product, add it to a cart on your storefront, and complete the order through checkout. Or mark an existing cart complete from the Commerce CP.

Out of the box **Create shipments automatically on order completion** is off and **Grouping source** is set to "One shipment group per order". So the order completes but no shipment appears yet. You'll stage one by hand next.

## 3. Open the order's Shipments tab

On the order edit page (`/admin/commerce/orders/{id}`) you'll see a **🚚 Shipments** tab. Click it.

Nothing's saved yet, so you'll see:

- A warning notice: *"This order is not fully allocated"*, listing the line items that still need to be placed on a shipment.
- A **Create shipments** section with a pending pill, prefilled with one group claiming every unit.

Leave the defaults and click **Save shipment(s)**. The plugin locks the order so two admins can't save at once, double-checks the quantities match the remaining pool, and creates the shipment.

You now have one shipment.

## 4. Read the saved shipment card

The order tab now shows:

- A **NEW** badge (the shipment status) next to the reference.
- The line items on the shipment, an optional tracking pill, and integration reference pills.
- **Edit details** and **Delete** buttons.

The reference is `{orderReference}-s001`. It's stable, so you can match shipments to outside systems.

## 5. Change a status

Click **Edit details**. The sidebar shows an Enabled lightswitch and creation/update dates. The main area has a single **Status** dropdown (`new -> in_progress -> fulfilled -> shipped`, plus `on_hold` and `cancelled`).

Try this:

1. Enter `TEST123` in **Tracking number**, `UPS` in **Carrier**, `Ground` in **Service**.
2. Change **Status** to **Shipped**.
3. Add a **Status change message** like "Handed off to carrier".
4. Click **Save**.

The plugin records the change in history and queues up any notification emails bound to this transition. All of that saves together, so if anything fails nothing partial sticks. Reaching **Shipped** also advances the Commerce order to your configured target status. The "shipped" timestamp surfaces from the history table whenever you read it.

Open the **Status history** tab. Your change is there with the old status, new status, the user, and the source.

## 6. Map an integration's status codes

Most stores talk to a fulfillment system. The plugin ships no providers, but it ships everything needed to translate their codes into yours.

Go to **Shipments -> Settings -> Integrations -> New**. Pick a Provider from the dropdown (if your site module registered one) or leave it blank for a sandbox integration, fill in a name and handle, and save.

On the integration's edit page, open the status-mapping editor. Each row maps one of the integration's codes to one of the plugin's statuses, with a **direction**:

- **Inbound**, the integration sends us this code; translate *their code to ours*.
- **Outbound**, we send this code to the integration; translate *our code to theirs*.
- **Bidirectional**, both.

An inbound code with no matching mapping is skipped; the shipment's status doesn't change. Add a mapping for the codes you want to act on.

## 7. Attach a notification email

Go to **Shipments -> Settings -> Emails -> New** (admin-only by default). Fill in the usual email fields: subject, template path (autocompletes from your site templates), recipient type, enabled. Save.

Re-open the email. You'll see a **Transition triggers** section: a checkbox per status. Check `shipped` to send this email every time a shipment moves to shipped.

Save. The next matching status change queues the email for sending. The queue push and the status save commit together, so emails can't go missing if the save rolls back.

## 8. Where to go next

You've touched every major piece of the plugin. For deeper reading:

- [Creating shipments](./user-guide/creating-shipments.md), auto-creation, the grouping engine, manual staging.
- [Status transitions](./user-guide/status-transitions.md), the status lifecycle, when to change it, the rules.
- [Integrations](./user-guide/integrations.md), setting up a provider, mapping in depth, the attention workflow.
- [Custom providers](./dev-guide/custom-providers.md), build your own.
