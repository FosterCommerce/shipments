# REST API reference

> **Status: untested / WIP.** The endpoints below ship in the plugin and are documented here against the source, but they have not been exercised against a live integration. The shape, auth model, and error envelope are likely to change once real integration work begins. Don't build against this until it stabilizes.

The plugin exposes two HTTP entry points:

- `POST /shipments/api/shipments/{id}`, apply a partial update plus an optional status transition to one shipment.
- `/actions/shipments/gateway/handle?integration={integrationHandle}`, public integration gateway. Delegates the request to the integration's provider.

The `api/*` route lives under the CP URL rules. The gateway is a Craft action URL and is anonymous.

## Authentication

The `/shipments/api/...` endpoint is CP-routed and requires a logged-in CP user. Send a session cookie or use Craft's elevated session token. It additionally requires:

- `shipments-editShipments` for any update.
- `shipments-transitionShipments` if the request changes `status`.

CSRF validation is **disabled** on the API controller (`enableCsrfValidation = false`) so a bearer-style integration that posts JSON without a CSRF token still works.

The `/actions/shipments/gateway/handle?integration={integrationHandle}` endpoint is anonymous at the framework level. Signature verification, bearer-token checks, and any other auth are the **provider's** responsibility, executed inside `Provider::handleGatewayRequest($request)`. A provider that doesn't verify signatures is a provider that accepts forged events.

## `POST /shipments/api/shipments/{id}`

Apply a partial update to one shipment. Any combination of fulfillment fields, plus an optional status transition.

### Request body

All fields optional. `null` or omitted means "don't touch."

| Field               | Type                                   | Required | Description                                                                                                |
|---------------------|----------------------------------------|----------|------------------------------------------------------------------------------------------------------------|
| `trackingNumber`    | string (max 255)                       | no       | Carrier tracking number.                                                                                   |
| `trackingUrl`       | string (max 255), URL                  | no       | Public tracking URL. Validated as a URL with `https` default scheme.                                       |
| `carrier`           | string (max 255)                       | no       | Carrier name (free text, e.g. `UPS`).                                                                      |
| `service`           | string (max 255)                       | no       | Service level (free text, e.g. `Ground`).                                                                  |
| `dateScheduledShip` | ISO-8601 string, Unix int, or DateTime | no       | Scheduled ship date. Pre-parsed by the controller before validation.                                      |
| `fulfillmentNotes`  | string                                 | no       | Free-text notes.                                                                                          |
| `shippingNotes`     | string                                 | no       | Free-text notes.                                                                                          |
| `status`            | enum string                            | no       | Target `Status`: `new`, `in_progress`, `on_hold`, `fulfilled`, `shipped`, `cancelled`.                    |
| `statusMessage`     | string                                 | no       | Note recorded on the history row when the status changes.                                                  |
| `fields`            | object (handle -> value)               | no       | Custom field values keyed by field handle. Routed through `Element::setFieldValues`.                      |
| `integrationHandle` | string                                 | no       | Handle of the integration driving this update. Recorded as `sourceIntegration` on any resulting history row. |
| `externalCode`      | string                                 | no       | Raw external status code from the integration. Recorded alongside `sourceIntegration` on any resulting history row. |

### Invariants

- No field is required to reach any status. Tracking, carrier, and service are always optional.
- The shipment is located via `findById($id, includeTrashed: true)`, so updates to soft-deleted shipments succeed.
- Status transitions hold a per-shipment mutex (`shipments:shipment:{id}:transition`); concurrent transitions on the same shipment serialize.
- A transition to the value the shipment already has writes no history row and fires no event.
- Reaching `shipped` advances the shipment's Commerce order to the configured target status (see [status vocabulary](../user-guide/status-vocabulary.md)).

### Responses

**`200 OK`**, successful update.

```json
{
  "success": true,
  "shipment": {
    "id": 1234,
    "reference": "SO-1001-s001",
    "orderId": 5678,
    "status": "in_progress",
    "dateShipped": null,
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
    "targetStatusCode": ["Target Status Code is invalid."]
  }
}
```

**`422 Unprocessable Entity`**, service rejected the update (save error).

```json
{
  "success": false,
  "error": "Couldn't apply transition: ..."
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
    "status": "shipped",
    "integrationHandle": "shipstation",
    "externalCode": "SHIPPED"
  }'
```

## `/actions/shipments/gateway/handle?integration={integrationHandle}`

Public integration gateway. The plugin resolves the integration by the `integration` query param and hands the request to `Provider::handleGatewayRequest($request)`. The provider returns a Craft `Response` directly. **All payload parsing, signature verification, external-to-internal translation, response format, pagination, and filtering are the provider's job.** The plugin never inspects the body itself.

### Behavior

- Looks up the integration by handle.
- Confirms the integration is enabled and has a provider class bound.
- Calls `$provider->handleGatewayRequest($this->request)` and returns its response unchanged on success.
- Catches `IntegrationException` from the provider and converts it to a `400` with the original message; logs the rejection at `error` level.
- Catches any other `Throwable` and converts it to a `400` with a generic `Integration request processing failed.` message; logs the original at `error` level.

### Responses

**`200 OK`**, provider's response, unchanged. Format and body are entirely the provider's concern.

**`404 Not Found`**, `Unknown integration: {handle}`.

**`400 Bad Request`**, integration is disabled, has no provider bound, the provider raised `IntegrationException` (signature mismatch, unparseable payload, dropped event), or the provider threw anything else.

### curl example

```sh
curl -X POST 'https://example.com/actions/shipments/gateway/handle?integration=shipstation' \
  -H 'Content-Type: application/json' \
  -H 'X-ShipStation-Signature: sha256=...' \
  -d '{
    "resource_url": "https://ssapi.shipstation.com/shipments?...",
    "resource_type": "SHIP_NOTIFY"
  }'
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

**Service-level errors** (one message), from caught exceptions in the update endpoint:

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
