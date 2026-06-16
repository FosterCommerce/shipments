# Shipments documentation

First-class shipments for Craft Commerce. Every completed order produces one or more shipments, each with its own status, notification emails, CP UI, and integration hooks.

## Where to go

**First time here?** Start with [Getting started](./getting-started.md), a 15-minute end-to-end walkthrough from install to your first saved shipment.

**Running the plugin day-to-day?** See the [user guide](./user-guide/):

- [Creating shipments](./user-guide/creating-shipments.md), auto-creation on order complete and manual staging from the order tab
- [Status transitions](./user-guide/status-transitions.md), the status lifecycle and how to change it
- [Status vocabulary](./user-guide/status-vocabulary.md), what each status means and how you decide
- [Integrations](./user-guide/integrations.md), setting one up, mapping status codes

**Building on top of the plugin?** See the [dev guide](./dev-guide/) + [developer reference](./reference/):

- [Architecture](./dev-guide/architecture.md), write path, services, events
- [Twig queries](./dev-guide/twig-queries.md), reading shipments from front-end templates
- [Custom providers](./dev-guide/custom-providers.md), building an integration
- [Events](./reference/events.md), every event the plugin fires, payloads and listen examples

**Setup details:** [Installation](./installation.md)
