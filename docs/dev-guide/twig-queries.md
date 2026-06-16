# Querying shipments from Twig

How to read shipments from front-end templates (and any CP template). Audience: developers wiring shipment data into customer-facing pages, account dashboards, or order summaries.

`Shipment` is a Craft element, so it ships with the standard `craft.shipments` element query and the standard `with(['shipments'])` eager-loading entry point on the `Order` element. There is no plugin-specific Twig tag, function, or filter; everything below is the built-in element-query API specialized to this plugin's filters.

## Basic query

```twig
{% set shipments = craft.shipments
    .orderId(order.id)
    .all() %}

{% for shipment in shipments %}
    <p>{{ shipment.reference }}: {{ shipment.statusEnum.label() }}</p>
{% endfor %}
```

`craft.shipments` returns a `ShipmentQuery`. Chain filters, then `.all()`, `.one()`, `.nth(n)`, `.count()`, or `.exists()` to execute. By default disabled and trashed shipments are excluded; pass `.status(null)` to include disabled, `.trashed(true)` to include soft-deleted.

## Filters

| Method                | Accepts                              | Notes                                                                               |
|-----------------------|--------------------------------------|-------------------------------------------------------------------------------------|
| `orderId(value)`      | `int`, `string`, `list<int\|string>` | Commerce order id. Pass a list for multiple orders.                                 |
| `status(value)`       | `string`, `list<string>`             | One of the `Status` enum values (`new`, `in_progress`, `on_hold`, `fulfilled`, `shipped`, `cancelled`). |
| `reference(value)`    | `string`, `list<string>`             | Exact reference match. Supports Craft's `'not foo'` / `['or', 'a', 'b']` forms.    |
| `trackingNumber(value)`| `string`, `list<string>`            | Exact tracking number.                                                              |
| `carrier(value)`      | `string`, `list<string>`             | Carrier name as stored on the shipment (e.g. `UPS`, `USPS`).                        |
| `service(value)`      | `string`, `list<string>`             | Carrier service code as stored on the shipment.                                     |
| `integrationId(value)`| `int`, `string`, `list<int\|string>` | Filter to shipments that have an integration reference for the given integration id. |

`status(value)` is Craft's standard element-status filter, specialized to also accept the `Status` enum values. Passing `null` includes disabled shipments.

Standard `ElementQuery` filters work too: `.id(...)`, `.dateCreated(...)`, `.dateUpdated(...)`, `.trashed(...)`, `.limit(...)`, `.offset(...)`, `.orderBy(...)`, `.search(...)`, `.with(...)`.

Filtering on the shipped date directly is not supported on the query (the value is derived from `shipments_status_history`, not stored as a column). Use `.dateCreated()` for created shipments, or read `shipment.dateShipped` per result and filter in Twig.

## Eager-loading shipments from an order

The plugin registers a `shipments` eager-loading map on `Order`, so a single query can hydrate shipments for every order on the page:

```twig
{% set orders = craft.orders
    .customer(currentUser)
    .with(['shipments'])
    .all() %}

{% for order in orders %}
    <h2>Order #{{ order.reference }}</h2>
    {% for shipment in order.shipments %}
        <p>{{ shipment.reference }}: {{ shipment.statusEnum.label() }}</p>
    {% endfor %}
{% endfor %}
```

After `with(['shipments'])`, `order.shipments` returns the eager-loaded list without re-querying. Without eager-loading, fall back to a one-off query: `craft.shipments.orderId(order.id).all()`.

## What's on a shipment

Direct properties read straight from the row:

- `id`, `enabled`, `dateCreated`, `dateUpdated` (standard element properties)
- `orderId`, `reference`, `number`
- `status` (string code)
- `trackingNumber`, `trackingUrl`, `carrier`, `service`, `fulfillmentNotes`, `shippingNotes`
- `dateScheduledShip` (`DateTime` or `null`)
- `dateShipped` (`DateTime` or `null`; derived from `shipments_status_history`, instance-cached on first read)

Methods worth knowing:

- `shipment.statusEnum` returns a `Status` enum case with `.label()`, `.color()`, and `.value`.
- `shipment.lineItems` returns the list of `ShipmentLineItem` models (each has `lineItemId` and `qty`).
- `shipment.integrationReferences` returns the list of `IntegrationReference` models for deep-linking to remote systems.
- `shipment.order` returns the parent `Order` element (or `null` if it was deleted).

For the full status vocabulary and what each code means, see [status vocabulary](../user-guide/status-vocabulary.md).

## Recipe: order-detail page with shipments and their items

Show a customer the shipments on one of their orders, the status of each, and the line items contained in each shipment. The order is loaded in the controlling template (e.g. from `craft.orders.number(...)` or via the order edit URL).

```twig
{% set shipments = craft.shipments
    .orderId(order.id)
    .orderBy('number ASC')
    .all() %}

{% set lineItemsById = {} %}
{% for orderLineItem in order.lineItems %}
    {% set lineItemsById = lineItemsById|merge({ (orderLineItem.id): orderLineItem }) %}
{% endfor %}

{% if shipments|length %}
    <section class="shipments">
        <h2>{{ 'Shipments'|t }}</h2>

        {% for shipment in shipments %}
            <article class="shipment">
                <header>
                    <h3>{{ shipment.reference }}</h3>

                    <p class="status">
                        {{ 'Status'|t }}:
                        <strong>{{ shipment.statusEnum.label() }}</strong>
                        {% if shipment.dateShipped %}
                            <time datetime="{{ shipment.dateShipped|atom }}">
                                {{ shipment.dateShipped|date('medium') }}
                            </time>
                        {% endif %}
                    </p>

                    {% if shipment.trackingNumber %}
                        <p class="tracking">
                            {{ 'Tracking'|t }}:
                            {% if shipment.trackingUrl %}
                                <a href="{{ shipment.trackingUrl }}" rel="noopener" target="_blank">{{ shipment.trackingNumber }}</a>
                            {% else %}
                                <span>{{ shipment.trackingNumber }}</span>
                            {% endif %}
                            {% if shipment.carrier %}({{ shipment.carrier }}{% if shipment.service %}, {{ shipment.service }}{% endif %}){% endif %}
                        </p>
                    {% endif %}
                </header>

                {% if shipment.lineItems|length %}
                    <ul class="shipment-line-items">
                        {% for shipmentLineItem in shipment.lineItems %}
                            {% set orderLineItem = lineItemsById[shipmentLineItem.lineItemId] ?? null %}
                            <li>
                                <span class="qty">{{ shipmentLineItem.qty }} &times;</span>
                                {% if orderLineItem %}
                                    <span class="description">{{ orderLineItem.description }}</span>
                                    {% if orderLineItem.sku %}
                                        <span class="sku">{{ orderLineItem.sku }}</span>
                                    {% endif %}
                                {% else %}
                                    <span class="description">{{ 'Line item no longer on order'|t }}</span>
                                {% endif %}
                            </li>
                        {% endfor %}
                    </ul>
                {% endif %}
            </article>
        {% endfor %}
    </section>
{% endif %}
```

A few things to note:

- `lineItemsById` is built once per render so the inner loop resolves each `shipmentLineItem.lineItemId` to its Commerce `LineItem` in O(1). For orders with many line items this is meaningfully faster than calling `order.lineItemById(id)` inside the loop.
- A line item that's missing from the order map means it was removed from the order after the shipment was saved. Decide whether to render a placeholder, hide the row, or surface a warning to staff.
- If you list shipments across many orders on the same page (e.g. account dashboard), eager-load via `craft.orders.with(['shipments']).all()` and iterate `order.shipments` instead of running one query per order.

## Customer-facing template

A typical "shipments on the order confirmation page" snippet:

```twig
{% set shipments = craft.shipments
    .orderId(order.id)
    .orderBy('number ASC')
    .all() %}

{% if shipments|length %}
    <h2>{{ 'Shipments'|t }}</h2>
    {% for shipment in shipments %}
        <article>
            <h3>{{ shipment.reference }}</h3>
            <p>{{ shipment.statusEnum.label() }}</p>
            {% if shipment.trackingNumber %}
                {% if shipment.trackingUrl %}
                    <a href="{{ shipment.trackingUrl }}">{{ shipment.trackingNumber }}</a>
                {% else %}
                    {{ shipment.trackingNumber }}
                {% endif %}
            {% endif %}
            {% for lineItem in shipment.lineItems %}
                <p>{{ lineItem.qty }} x line item {{ lineItem.lineItemId }}</p>
            {% endfor %}
        </article>
    {% endfor %}
{% endif %}
```

Resolve the original Commerce line item from `lineItem.lineItemId` against `order.lineItems` if you need the purchasable, price, or options.

## Permissions and visibility

Element queries on the front-end are not gated by CP user permissions. Any template that has access to an order can read its shipments. Scope your queries by `order.customerId` (or `order.email`) if you're rendering an account dashboard, the same way you would for orders.

For headless front-ends, prefer GraphQL: see [graphql](../reference/graphql.md). The `shipments.read` schema component is required on the token's schema.
