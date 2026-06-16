# Installation

A Craft CMS plugin that adds first-class **shipments** to Craft Commerce, splitting each completed order into one or more shipments via an extensible rules engine.

## Requirements

- Craft CMS `^5.0`
- Craft Commerce `^5.0`
- PHP `^8.2`
- MySQL 8 / MariaDB 10.4+ or PostgreSQL 13+

## Install

```sh
composer require fostercommerce/shipments
./craft plugin/install shipments
```

## Configure

**Shipments -> Settings -> General**.

- **Create shipments automatically on order completion**: runs the rules engine every time an order completes. Off by default; turn on when you're ready for shipments to appear without clicking anything.
- **Grouping source**: how to split one order into shipments. Choices: one shipment per order, by Commerce inventory state, by Commerce line-item status, or by Commerce shipping category.
- **Enforce full coverage**: blocks shipment saves until every line item quantity is accounted for. Leave on unless your store ships partial orders and never reconciles.
- **Line item statuses to ignore**: line items in these statuses are skipped (refunded, cancelled, and so on). Default: empty.
- **Order statuses to ignore**: orders in these Commerce statuses are marked as not requiring shipping and drop off the Attention page. Any shipments they already have stay in place. You can't add new shipments to an order while it's in one of these statuses. Adding a status here runs a one-time pass over orders already in that status. Default: empty.
- **Inventory grouping modes** (Grouping source = Craft Commerce inventory state): per-bucket choice between **ship together** (one shipment for the whole bucket) and **one shipment per line item**. Configured separately for in-stock items and backordered items. Default: ship together for both buckets.
- **Quantity split mode** (Grouping source = Craft Commerce inventory state): how to handle line items that are partly in stock. `split` lets the line item appear in both buckets with partial quantities; `atomic` keeps the whole line item in the backorder bucket if any of it is. Default: `split`.
- **Line item status groups** (Grouping source = Commerce line-item status): admin-defined groups, each with a mode (ship together or one per line item) and a list of Commerce line-item status handles. Line items whose status isn't in any group fall through to the single-shipment fallback. Default: empty.
- **Shipping category groups** (Grouping source = Commerce shipping category): admin-defined groups, each with a mode and a list of Commerce shipping-category handles. Use for physical-shipping splits like LTL, hazmat, or oversized. Line items whose category isn't in any group fall through to the single-shipment fallback. Default: empty.

## Emails

**Shipments -> Settings -> Emails.**

Emails send when a shipment transitions to a status you've attached them to. Each email has a subject, a recipient (the customer or a custom address), a Twig template, and an optional language override. The template renders with `shipment` and `order` available.

Pick the statuses that trigger each email at the bottom of the email edit page.

## Integrations

**Shipments -> Settings -> Integrations.**

An integration is a saved connection to a remote system (ShipStation, Veeqo, your ERP). It has a handle, an optional URL template for deep links, and the settings the provider needs (credentials, endpoint, webhook secret). Providers come from a separate plugin or your site module; the Shipments plugin ships no providers itself. See [custom providers](./dev-guide/custom-providers.md) for building one.

Each integration works in two directions. Outbound, the plugin pushes shipments to the remote, queued from a status change, the per-shipment push button, or a custom trigger. Inbound, the remote pushes updates to the plugin via webhook, an export URL, or a poll run by your site module. See [integrations](./user-guide/integrations.md) for the admin-side walkthrough.

Disabling an integration stops outbound pushes and rejects inbound webhooks. Existing reference rows on shipments stay visible so nothing goes missing.

## Using shipments

On a completed order, a **Shipments** tab shows the shipments (once any exist) and a form for creating new ones from unallocated line items. Each shipment has its own edit page: tracking number, carrier, service, ship date, notes, integration references, and a status-history log.

Shipments soft-delete. Removing a shipment from an order returns its line items to the unallocated pool.

## Console commands

```sh
./craft shipments/shipments/rebuild <orderId>
```

Rebuilds shipments for one order from the rules engine. Use after changing grouping settings.
