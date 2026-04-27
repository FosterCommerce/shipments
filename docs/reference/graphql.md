# GraphQL reference

Read-only. The plugin exposes shipments through Craft's GraphQL service. No mutations yet, writes go through the CP.

## Schema component

Grant `shipments.read` to any GraphQL schema that should see shipments. Set in **GraphQL -> Schemas -> {schema} -> Shipments**.

## Root queries

Three top-level queries, all accepting the same argument set:

| Query           | Returns                                  | Use for                                                                  |
|-----------------|------------------------------------------|--------------------------------------------------------------------------|
| `shipments`     | `[Shipment]`                             | List query; pair with `limit` + `offset` + `orderBy` for pagination.     |
| `shipmentCount` | `Int!`                                   | Total matching shipments. Pair with `shipments` for paginated UIs.       |
| `shipment`      | `Shipment` (single, nullable)            | Single-record lookup. Pass `id`, `uid`, or any unique filter; returns the first match. |

### `shipments`

```graphql
{
  shipments(
    orderId: [1234]
    fulfillmentStatus: ["fulfilled"]
    shippingStatus: ["in_transit", "delivered"]
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
    fulfillmentStatus
    shippingStatus
    dateShippingStatus
    dateShipped
    dateDelivered
    dateScheduledShip
    trackingNumber
    trackingUrl
    carrier
    service
    notes
    dateCreated
    dateUpdated
  }
}
```

All filter arguments accept arrays (union semantics) or scalars. Standard Craft element arguments (`id`, `uid`, `limit`, `offset`, `orderBy`, `status`, `trashed`, `dateCreated`, `dateUpdated`) also work.

### `shipmentCount`

```graphql
{
  shipmentCount(
    orderId: [1234]
    fulfillmentStatus: ["open", "in_progress"]
  )
}
```

Returns a non-null `Int`. Accepts the same arguments as `shipments`; `limit` / `offset` / `orderBy` are ignored.

### `shipment`

```graphql
{
  shipment(id: 1234) {
    reference
    fulfillmentStatus
    shippingStatus
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
  fulfillmentStatus: String
  shippingStatus: String
  dateShippingStatus: String
  dateShipped: String
  dateDelivered: String
  dateScheduledShip: String
  trackingNumber: String
  trackingUrl: String
  carrier: String
  service: String
  notes: String
}

type Shipment implements ShipmentInterface & ElementInterface {
  # ShipmentInterface fields
  # plus all ElementInterface fields (id, uid, dateCreated, dateUpdated, …)
}
```

Status fields return raw enum values (`"fulfilled"`, `"in_transit"`). Translated labels aren't exposed, render in the client. The [status vocabulary](./status-vocabulary.md) doc documents each value.

## Eager loading

Eager loading maps are registered on the `Shipment` element for:

- `order`, the parent Commerce order. `elementType: craft\commerce\elements\Order`.
- `lineItems`, the per-line-item qty allocations. Returns `list<ShipmentLineItem>`. **Not an element**, expose via custom resolver if you need it in GQL.
- `integrationReferences`, per-integration external IDs. **Not an element**, same caveat.

Reverse eager-loading from `Order` to `Shipment`:

```graphql
{
  order(id: 1234) {
    shipments {
      reference
      fulfillmentStatus
      shippingStatus
      trackingNumber
    }
  }
}
```

Wired via `Element::EVENT_DEFINE_EAGER_LOADING_MAP` on `craft\commerce\elements\Order`.

## Status history

Not exposed in GraphQL yet. Read via the service method `Shipments::getStatusHistoryForShipmentId(int)`, which returns `list<ShipmentStatusHistoryEntry>`.

## Mutations

Not yet. Create/transition via the CP. If your headless front end needs GraphQL mutations, the handle to look for is `ShipmentGqlMutation`, open an issue / PR.

## Introspection

Standard, `__schema` and `__type` both work. Every field carries a GraphQL description.

## Examples

### Latest shipments for one order

```graphql
{
  shipments(orderId: [1234], orderBy: "dateCreated desc") {
    reference
    fulfillmentStatus
    shippingStatus
    trackingNumber
    trackingUrl
  }
}
```

### Everything currently in `delivered` status

```graphql
{
  shipments(
    shippingStatus: ["delivered"]
  ) {
    reference
    dateDelivered
    carrier
    service
  }
}
```

`dateDelivered` is derived from the status-history table on read. There is no `dateDelivered` query argument; if you need a date-bounded delivered set, query by `dateUpdated` (or filter the result in your client).

### All shipments pushed to a specific integration

```graphql
{
  shipments(integrationId: [5]) {
    reference
    fulfillmentStatus
  }
}
```
