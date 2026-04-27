# REST API reference

> **Status: untested / WIP.** The endpoints below ship in the plugin and are documented here against the source, but they have not been exercised against a live integration. The shape, auth model, and error envelope are likely to change once real integration work begins. Don't build against this until it stabilizes.

The plugin exposes four HTTP entry points:

- `POST /shipments/api/shipments/{id}`, apply a partial update plus optional axis transitions to one shipment.
- `POST /shipments/api/shipments/{id}/carrier-events`, ingest a single carrier event.
- `POST /shipments/webhooks/{integrationHandle}`, public webhook receiver. Delegates body parsing and signature checks to the integration's provider.
- `GET /shipments/exports/{integrationHandle}`, public export endpoint. Delegates response shape and auth to the integration's provider.

`api/*` routes live under the CP URL rules. `webhooks/*` and `exports/*` live under the site URL rules and are anonymous.

## Authentication

The two `/shipments/api/...` endpoints are CP-routed and require a logged-in CP user. Send a session cookie or use Craft's elevated session token. They additionally require:

- `shipments-editShipments` for any update.
- `shipments-transitionShipments` if the request changes `fulfillmentStatus` or `shippingStatus`.

CSRF validation is **disabled** on the API controller (`enableCsrfValidation = false`) so a bearer-style integration that posts JSON without a CSRF token still works.

The `/shipments/webhooks/{integrationHandle}` and `/shipments/exports/{integrationHandle}` endpoints are anonymous at the framework level. Signature verification, bearer-token checks, and any other auth are the **provider's** responsibility, executed inside `Provider::receiveShipmentUpdate($request)` / `Provider::export($request)`. A provider that doesn't verify signatures is a provider that accepts forged events.

## `POST /shipments/api/shipments/{id}`

Apply a partial update to one shipment. Any combination of fulfillment fields, plus an optional fulfillment-axis or shipping-axis transition.

### Request body

All fields optional. `null` or omitted means "don't touch."

| Field                       | Type                                     | Required | Description                                                                                                                          |
|-----------------------------|------------------------------------------|----------|--------------------------------------------------------------------------------------------------------------------------------------|
| `trackingNumber`            | string (max 255)                         | no       | Carrier tracking number. Required by invariant before the shipment can transition to fulfillment status `fulfilled`.                 |
| `trackingUrl`               | string (max 255), URL                    | no       | Public tracking URL. Validated as a URL with `https` default scheme.                                                                 |
| `carrier`                   | string (max 255)                         | no       | Carrier name (free text, e.g. `UPS`).                                                                                                |
| `service`                   | string (max 255)                         | no       | Service level (free text, e.g. `Ground`).                                                                                            |
| `dateScheduledShip`         | ISO-8601 string, Unix int, or DateTime   | no       | Scheduled ship date. Pre-parsed by the controller before validation.                                                                 |
| `fulfillmentNotes`          | string                                   | no       | Internal warehouse notes.                                                                                                            |
| `shippingNotes`             | string                                   | no       | Customer-facing notes.                                                                                                               |
| `fulfillmentStatus`         | enum string                              | no       | Target `FulfillmentStatus`: `open`, `in_progress`, `scheduled`, `on_hold`, `fulfilled`, `cancelled`, `incomplete`.                   |
| `shippingStatus`            | enum string                              | no       | Target `ShippingStatus`: `pending`, `pre_transit`, `in_transit`, `out_for_delivery`, `attempted_delivery`, `available_for_pickup`, `delivered`, `exception`, `returned`, `failure`. |
| `fulfillmentStatusMessage`  | string                                   | no       | Note recorded on the fulfillment-axis history row when the fulfillment status changes.                                               |
| `shippingStatusMessage`     | string                                   | no       | Note recorded on the shipping-axis history row when the shipping status changes.                                                     |
| `fields`                    | object (handle -> value)                 | no       | Custom field values keyed by field handle. Routed through `Element::setFieldValues`.                                                 |
| `integrationHandle`         | string                                   | no       | Handle of the integration driving this update. Recorded as `sourceIntegration` on any resulting history row.                         |
| `externalCode`              | string                                   | no       | Raw external status code from the integration. Recorded alongside `sourceIntegration` on any resulting history row.                  |

### Invariants

- A transition to `fulfillmentStatus = fulfilled` requires a non-empty `trackingNumber` on the shipment (either pre-existing or set in this same request).
- The shipment is located via `findById($id, includeTrashed: true)`, so updates to soft-deleted shipments succeed.
- Status transitions hold a per-shipment mutex (`shipments:shipment:{id}:transition`); concurrent transitions on the same shipment serialize.
- A transition that doesn't actually change the value is a no-op (no history row written, no event fired).

### Responses

**`200 OK`**, successful update.

```json
{
  "success": true,
  "shipment": {
    "id": 1234,
    "reference": "SO-1001-s001",
    "orderId": 5678,
    "fulfillmentStatus": "in_progress",
    "shippingStatus": "pre_transit",
    "dateShippingStatus": "2026-04-26T10:15:00+00:00",
    "dateShipped": null,
    "dateDelivered": null,
    "dateScheduledShip": "2026-04-28T00:00:00+00:00",
    "trackingNumber": "1Z999AA10123456784",
    "trackingUrl": "https://www.ups.com/track?tracknum=1Z999AA10123456784",
    "carrier": "UPS",
    "service": "Ground",
    "fulfillmentNotes": null,
    "shippingNotes": null,
    "enabled": true,
    "fields": {}
  }
}
```

**`422 Unprocessable Entity`**, DTO validation failed.

```json
{
  "success": false,
  "errors": {
    "trackingUrl": ["Tracking Url is not a valid URL."],
    "targetFulfillmentCode": ["Target Fulfillment Code is invalid."]
  }
}
```

**`422 Unprocessable Entity`**, service rejected the update (invariant fail, optimistic-lock fail, save error).

```json
{
  "success": false,
  "error": "Tracking number is required to mark a shipment fulfilled."
}
```

**`401 Unauthorized`**, no logged-in user.

**`403 Forbidden`**, user lacks `shipments-editShipments` or (when transitioning) `shipments-transitionShipments`.

**`404 Not Found`**, no shipment with that id.

### curl example

```sh
curl -X POST 'https://example.com/shipments/api/shipments/1234' \
  -H 'Content-Type: application/json' \
  -H 'Cookie: CraftSessionId=...' \
  -d '{
    "trackingNumber": "1Z999AA10123456784",
    "carrier": "UPS",
    "service": "Ground",
    "fulfillmentStatus": "fulfilled",
    "shippingStatus": "pre_transit",
    "integrationHandle": "shipstation",
    "externalCode": "SHIPPED"
  }'
```

## `POST /shipments/api/shipments/{id}/carrier-events`

Ingest a single carrier event (scan, status change, exception). The event is hashed for dedupe and, if its code resolves to a `ShippingStatus`, drives a shipping-axis transition.

### Request body

| Field             | Type                                     | Required | Description                                                                                                          |
|-------------------|------------------------------------------|----------|----------------------------------------------------------------------------------------------------------------------|
| `code`            | string                                   | yes      | The event code. Either a `ShippingStatus` enum value (`in_transit`, `delivered`, ...) or an integration-specific external code that maps to one. |
| `dateOccurred`    | ISO-8601 string, Unix int, or array form | yes      | When the event happened at the carrier. UTC-normalized before hashing for dedupe.                                    |
| `description`     | string                                   | no       | Free-text event description (e.g., `Package departed facility`).                                                     |
| `externalCode`    | string                                   | no       | Original external code, recorded for audit when `code` was already an internal value.                                |
| `locationCity`    | string                                   | no       | City the event occurred in.                                                                                          |
| `locationRegion`  | string                                   | no       | State or region.                                                                                                     |
| `locationCountry` | string                                   | no       | ISO country code. Truncated to first 2 chars and uppercased on persist.                                              |
| `rawPayload`      | object, array, or string                 | no       | The full vendor payload for this event, stored verbatim. Arrays/objects are JSON-encoded.                            |
| `integrationHandle` | string                                 | no       | Handle of the integration that delivered this event. Required to resolve external codes via integration mappings.    |

### Invariants

- `code` and `dateOccurred` are required; missing or invalid values throw `400 Bad Request`.
- Dedupe key is SHA-256 of `(shipmentId, code, dateOccurredUtc, externalCode)`. Re-delivering the same event returns `deduped=true` without re-applying the transition.
- If `code` doesn't match a `ShippingStatus` and no integration mapping resolves it, the event still persists, gets recorded as an unmapped external status (Attention page), and `resolved` returns `null`.
- Events on disabled shipments persist but do not project (no transition fired). The `reason` column on the persisted event records why.
- Events on orders whose "Order requires shipping" lightswitch is off persist but do not project.

### Responses

**`200 OK`**, event accepted.

```json
{
  "success": true,
  "deduped": false,
  "resolved": "in_transit",
  "shipment": {
    "id": 1234,
    "reference": "SO-1001-s001",
    "shippingStatus": "in_transit",
    "dateShippingStatus": "2026-04-26T10:15:00+00:00"
  }
}
```

`resolved` is `null` if the code couldn't be mapped to a `ShippingStatus`. `deduped` is `true` when the SHA-256 key matched an existing row.

**`400 Bad Request`**, missing or unparseable `code` or `dateOccurred`.

```json
{
  "name": "Bad Request",
  "message": "`dateOccurred` must be a valid timestamp.",
  "code": 0,
  "status": 400
}
```

**`422 Unprocessable Entity`**, event accepted, but a downstream save or transition failed.

```json
{
  "success": false,
  "error": "Couldn't apply transition: ..."
}
```

**`401 Unauthorized`, `403 Forbidden`, `404 Not Found`**, same as the update endpoint.

### curl example

```sh
curl -X POST 'https://example.com/shipments/api/shipments/1234/carrier-events' \
  -H 'Content-Type: application/json' \
  -H 'Cookie: CraftSessionId=...' \
  -d '{
    "code": "in_transit",
    "externalCode": "I_TRANSIT",
    "dateOccurred": "2026-04-26T10:15:00+00:00",
    "description": "Package departed origin facility",
    "locationCity": "Atlanta",
    "locationRegion": "GA",
    "locationCountry": "US",
    "integrationHandle": "shipstation",
    "rawPayload": {
      "trackingNumber": "1Z999AA10123456784",
      "scanType": "DEPARTURE"
    }
  }'
```

## `POST /shipments/webhooks/{integrationHandle}`

Public webhook receiver. The plugin resolves the integration by handle, gates routing on the provider's `canReceiveUpdates()` capability flag, hands the request to `Provider::receiveShipmentUpdate($request)`, and returns a thin envelope. **All payload parsing, signature verification, and external-to-internal translation is the provider's job.** The plugin never inspects the body itself.

### Behavior

- Looks up the integration by handle.
- Confirms the integration is enabled and has a provider class bound.
- Confirms the provider's `canReceiveUpdates()` returns `true`. Returns `405 Method Not Allowed` if not.
- Calls `$provider->receiveShipmentUpdate($this->request)`. The provider returns either a `Shipment` (the one it touched) or `null` (event acknowledged but didn't map to a single shipment).
- Catches `IntegrationException` from the provider and converts it to a `400` with the original message; logs the rejection at `error` level.
- Catches any other `Throwable` and converts it to a `400` with a generic `Webhook processing failed.` message; logs the original at `error` level.

### Responses

**`200 OK`**, provider handled the webhook.

```json
{
  "success": true,
  "shipmentId": 1234
}
```

`shipmentId` is `null` when the provider acknowledges an event that doesn't correspond to a single shipment (heartbeat, batch ack, multi-shipment notification).

**`404 Not Found`**, `Unknown integration: {handle}`.

**`405 Method Not Allowed`**, the provider's `canReceiveUpdates()` returned `false`. The integration's provider class doesn't accept inbound webhooks.

**`400 Bad Request`**, integration is disabled, has no provider bound, the provider raised `IntegrationException` (signature mismatch, unparseable payload, dropped event), or the provider threw anything else.

### curl example

```sh
curl -X POST 'https://example.com/shipments/webhooks/shipstation' \
  -H 'Content-Type: application/json' \
  -H 'X-ShipStation-Signature: sha256=...' \
  -d '{
    "resource_url": "https://ssapi.shipstation.com/shipments?...",
    "resource_type": "SHIP_NOTIFY"
  }'
```

## `GET /shipments/exports/{integrationHandle}`

Public export endpoint. The plugin resolves the integration by handle and delegates to `Provider::export($request)`. The provider returns a Craft `Response` directly; the plugin doesn't interpose. **Auth, format (CSV / JSON / XML), pagination, and any filtering are the provider's job.**

### Behavior

- Looks up the integration by handle.
- Confirms the integration is enabled and has a provider class bound.
- Calls `$provider->export($this->request)`; returns its response unchanged on success.
- Catches `IntegrationException` and converts it to a `400`.
- Catches any other `Throwable` and converts it to a `400` with `Export processing failed.`; logs the original.

### Responses

**`200 OK`**, provider's response, unchanged. Format and body are entirely the provider's concern.

**`404 Not Found`**, `Unknown integration: {handle}`.

**`400 Bad Request`**, integration disabled, no provider bound, or provider raised an exception.

### curl example

```sh
curl 'https://example.com/shipments/exports/erp?since=2026-04-25T00:00:00Z' \
  -H 'Authorization: Bearer ...'
```

## Error envelope

The plugin uses two response shapes for failures.

**DTO validation errors** (per-field), from the update endpoint after `setAttributes` + `validate`:

```json
{
  "success": false,
  "errors": {
    "fieldName": ["Field-level message."]
  }
}
```

**Service-level errors** (one message), from caught exceptions in the update and carrier-events endpoints:

```json
{
  "success": false,
  "error": "Top-level message."
}
```

**Yii framework errors** (4xx other than the 422s above), from `throw new BadRequestHttpException(...)` / `NotFoundHttpException(...)`:

```json
{
  "name": "Bad Request",
  "message": "...",
  "code": 0,
  "status": 400
}
```

This is Craft's default JSON error renderer; the plugin doesn't customize it. Future stabilization of this API should consolidate on a single envelope.
