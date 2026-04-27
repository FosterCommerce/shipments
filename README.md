# Shipments

A Craft CMS plugin that adds first-class **shipments** to Craft Commerce, splitting each completed order into one or more shipments via an extensible rules engine.

## What it does

- Splits completed orders into one or more shipments based on configurable rules
  (e.g., backordered items get their own shipment automatically).
- Tracks each shipment on two axes: fulfillment status (what your warehouse is doing)
  and shipping status (what the carrier reports).
- Validates full coverage: every line item quantity on an order must be accounted for
  across its shipments before a save goes through.
- Sends notification emails when shipments hit specific statuses.
- Connects to fulfillment systems (ShipStation, ERPs, custom) through a provider
  framework with webhook support, status-code mapping, and a REST API.
- Adds a Shipments tab to the order edit page and a standalone Shipments element index
  in the CP.

## Requirements

- Craft CMS `^5.0`
- Craft Commerce `^5.0`
- PHP `^8.2`

## Install

```sh
composer require fostercommerce/shipments
./craft plugin/install shipments
```

See [`docs/installation.md`](./docs/installation.md) for the full installation and configuration guide.

## Rules engine

The plugin decides how to split an order into shipments using a configurable grouping source (single shipment, in-stock vs. backorder, line-item status, or shipping category). A built-in catch-all guarantees every completed order gets at least one shipment.

You can write your own rules. See [`docs/dev-guide/custom-rules.md`](./docs/dev-guide/custom-rules.md).

## Permissions

In addition to `accessPlugin-shipments`:

- `shipments-viewShipments`, see the Shipments index and edit pages.
- `shipments-editShipments`, create and edit shipments (tracking, carrier, notes).
- `shipments-transitionShipments`, change fulfillment or shipping status.
- `shipments-deleteShipments`, delete shipments.
- `shipments-pushShipments`, push shipments to integrations via the per-shipment push button.
- `shipments-manageIntegrations`, manage integrations and status mappings.
- `shipments-manageEmails`, manage notification emails and their triggers.
- `shipments-manageSettings`, edit plugin settings and the shipment field layout.

See [`docs/reference/permissions.md`](./docs/reference/permissions.md) for the full reference.

## Integration framework

The plugin connects to fulfillment systems (ShipStation, Veeqo, ERPs) through a provider framework. Providers handle pushing shipments out, receiving webhook updates back, and translating between the remote system's status codes and the plugin's own status vocabulary. Status codes the plugin doesn't recognize surface on the Attention page for manual mapping.

The plugin ships no providers itself. They come from separate packages or your site module. See [`docs/dev-guide/custom-providers.md`](./docs/dev-guide/custom-providers.md) for how to build one.

## GraphQL

Read-only access to shipments with filters on order, status, tracking, carrier, and integration. Supports eager-loading orders, line items, and integration references.

See [`docs/reference/graphql.md`](./docs/reference/graphql.md) for the schema and query examples.

## License

Proprietary.
