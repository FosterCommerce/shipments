# GraphQL reference

Read-only. The plugin exposes shipments through Craft's GraphQL service. No mutations yet; writes go through the CP.

## Schema component

Grant `shipments.read` to any GraphQL schema that should see shipments. Set in **GraphQL -> Schemas -> {schema} -> Shipments**.

## Root queries

Three top-level queries, all accepting the same argument set:

| Query           | Returns                       | Use for                                                                  |
|-----------------|-------------------------------|--------------------------------------------------------------------------|
| `shipments`     | `[Shipment]`                  | List query; pair with `limit` + `offset` + `orderBy` for pagination.     |
| `shipmentCount` | `Int!`                        | Total matching shipments. Pair with `shipments` for paginated UIs.       |
| `shipment`      | `Shipment` (single, nullable) | Single-record lookup. Pass `id`, `uid`, or any unique filter; returns the first match. |

### `shipments`

```graphql
{
  shipments(
    orderId: [1234]
    status: ["fulfilled", "shipped"]
    reference: ["00066503-s001"]
    trackingNumber: null
    carrier: null
    service: null
    integrationId: [5]
    limit: 25
    offset: 0
    orderBy: "dateCreated desc"
  ) {
    id
    reference
    orderId
    orderReference
    status
    dateShipped
    dateScheduledShip
    trackingNumber
    trackingUrl
    carrier
    service
    fulfillmentNotes
    shippingNotes
    dateCreated
    dateUpdated
  }
}
```

The custom filter arguments (`orderId`, `reference`, `trackingNumber`, `carrier`, `service`, `integrationId`) accept arrays (union semantics) or scalars. Status is filtered through Craft's standard element `status` argument, alongside the other built-ins (`id`, `uid`, `limit`, `offset`, `orderBy`, `trashed`, `dateCreated`, `dateUpdated`).

### `shipmentCount`

```graphql
{
  shipmentCount(
    orderId: [1234]
    status: ["new", "in_progress"]
  )
}
```

Returns a non-null `Int`. Accepts the same arguments as `shipments`; `limit` / `offset` / `orderBy` are ignored.

### `shipment`

```graphql
{
  shipment(id: 1234) {
    reference
    status
    trackingNumber
  }
}
```

Returns a single `Shipment` or `null`. Useful with any unique filter (`id`, `uid`, `reference`).

## Type

```graphql
interface ShipmentInterface {
  reference: String
  number: Int
  orderId: Int
  orderReference: String
  status: String
  dateShipped: String
  dateScheduledShip: String
  trackingNumber: String
  trackingUrl: String
  carrier: String
  service: String
  fulfillmentNotes: String
  shippingNotes: String
}

type Shipment implements ShipmentInterface & ElementInterface {
  # ShipmentInterface fields
  # plus all ElementInterface fields (id, uid, dateCreated, dateUpdated, …)
}
```

`status` returns the raw enum value (`"fulfilled"`, `"shipped"`). Translated labels aren't exposed; render them in the client. The [status vocabulary](../user-guide/status-vocabulary.md) doc documents each value.

## Eager loading

Eager loading maps are registered on the `Shipment` element for:

- `order`, the parent Commerce order. `elementType: craft\commerce\elements\Order`.
- `lineItems`, the per-line-item qty allocations. Returns `list<ShipmentLineItem>`. **Not an element**; expose via a custom resolver if you need it in GQL.
- `integrationReferences`, per-integration external IDs. **Not an element**; same caveat.

Reverse eager-loading from `Order` to `Shipment`:

```graphql
{
  order(id: 1234) {
    shipments {
      reference
      status
      trackingNumber
    }
  }
}
```

Wired via `Element::EVENT_DEFINE_EAGER_LOADING_MAP` on `craft\commerce\elements\Order`.

## Status history

Not exposed in GraphQL yet. Read via the service method `Shipments::getStatusHistoryForShipmentId(int)`, which returns `list<ShipmentStatusHistoryEntry>`.

## Mutations

Not yet. Create/transition via the CP. If your headless front end needs GraphQL mutations, the handle to look for is `ShipmentGqlMutation`; open an issue / PR.

## Introspection

Standard; `__schema` and `__type` both work. Every field carries a GraphQL description.

## Examples

### Latest shipments for one order

```graphql
{
  shipments(orderId: [1234], orderBy: "dateCreated desc") {
    reference
    status
    trackingNumber
    trackingUrl
  }
}
```

### Everything currently in `shipped` status

```graphql
{
  shipments(status: ["shipped"]) {
    reference
    dateShipped
    carrier
    service
  }
}
```

`dateShipped` is derived from the status-history table on read. There is no `dateShipped` query argument; if you need a date-bounded shipped set, query by `dateUpdated` (or filter the result in your client).

### All shipments pushed to a specific integration

```graphql
{
  shipments(integrationId: [5]) {
    reference
    status
  }
}
```
