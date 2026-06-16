# Database schema reference

Every table the plugin creates, keyed. Source of truth: `src/migrations/Install.php`.

`Install.php` holds the full layout. The plugin is in production, so every schema change also ships an incremental `m*` migration that carries existing installs forward. See the `feedback_schema_workflow.md` note.

## `shipments_shipments`

Supplementary table for the `Shipment` element. Keyed to `craft_elements.id`; the element row owns `dateCreated`, `dateUpdated`, `uid`, `dateDeleted`, `enabled`, `archived`.

| Column                | Type        | Notes                                                |
|-----------------------|-------------|------------------------------------------------------|
| `id`                  | int PK      | -> `craft_elements.id` ON DELETE CASCADE             |
| `orderId`             | int NOT NULL| -> `commerce_orders.id` ON DELETE CASCADE            |
| `reference`           | varchar     | UNIQUE. Format `{orderRef}-sNNN`.                    |
| `number`              | int NOT NULL| Per-order sequence integer. UNIQUE with `orderId`.  |
| `status`              | varchar(32) | `Status` value. Default `new`. Indexed.             |
| `dateScheduledShip`   | datetime    | Merchant-intended ship date.                        |
| `trackingNumber`      | varchar     |                                                     |
| `trackingUrl`         | varchar     |                                                     |
| `carrier`             | varchar     | Indexed.                                            |
| `service`             | varchar     |                                                     |
| `fulfillmentNotes`    | text        | Admin-editable free-text notes.                     |
| `shippingNotes`       | text        | Admin-editable free-text notes.                     |
| `dateLastPushAttempt` | datetime    | Set by `PushShipmentJob` after each attempt.        |
| `lastPushAttemptError`| text        | Error message on the last attempt; null on success. |
| `pushAttemptCount`    | smallint    | Incremented on every push attempt. Default 0.       |

## `shipments_shipment_line_items`

Per-shipment line-item qty allocations.

| Column        | Type        | Notes                                         |
|---------------|-------------|-----------------------------------------------|
| `id`          | int PK      |                                               |
| `shipmentId`  | int NOT NULL| -> `shipments_shipments.id` ON DELETE CASCADE  |
| `lineItemId`  | int NOT NULL| -> `commerce_lineitems.id` ON DELETE CASCADE   |
| `qty`         | int NOT NULL|                                               |
| `dateCreated` | datetime    |                                               |
| `dateUpdated` | datetime    |                                               |
| `uid`         | uid         |                                               |

UNIQUE on `(shipmentId, lineItemId)`. Indexed on `lineItemId`.

## `shipments_shipment_status_history`

Audit log. Every `applyTransition` writes one row.

| Column                | Type        | Notes                                                   |
|-----------------------|-------------|---------------------------------------------------------|
| `id`                  | int PK      |                                                         |
| `shipmentId`          | int NOT NULL| -> `shipments_shipments.id` ON DELETE CASCADE            |
| `fromCode`            | varchar(32) | Prior `Status` value; null for the first transition (creation). |
| `toCode`              | varchar(32) | Post-transition `Status` value.                         |
| `message`             | text        | Optional note from the admin.                           |
| `userId`              | int         | -> `craft_users.id` ON DELETE SET NULL.                 |
| `sourceIntegrationId` | int         | -> `shipments_integrations.id` ON DELETE SET NULL.      |
| `sourceExternalCode`  | varchar(128)| Raw external code from the source integration.          |
| `dateCreated`         | datetime    |                                                         |
| `uid`                 | uid         |                                                         |

Indexed on `shipmentId`.

## `shipments_integrations`

Configured external systems.

| Column        | Type            | Notes                                                |
|---------------|-----------------|------------------------------------------------------|
| `id`          | int PK          |                                                      |
| `name`        | varchar NOT NULL| Display name.                                        |
| `handle`      | varchar NOT NULL| UNIQUE. Stable identifier. Used in webhook URLs.     |
| `urlTemplate` | varchar         | Optional URL template for `IntegrationReference::getResolvedUrl()`. |
| `provider`    | varchar         | FQCN of the `Provider` subclass.                     |
| `settings`    | text            | JSON-encoded provider settings.                      |
| `enabled`     | bool            | Default true.                                        |
| `sortOrder`   | int             | Default 0.                                           |
| `dateCreated` | datetime        |                                                      |
| `dateUpdated` | datetime        |                                                      |
| `uid`         | uid             |                                                      |

Project-config-backed. Reads and writes flow through `Integrations::handleChangedIntegration` / `handleDeletedIntegration`.

## `shipments_integration_references`

Per-shipment external IDs in each integration.

| Column          | Type            | Notes                                              |
|-----------------|-----------------|----------------------------------------------------|
| `id`            | int PK          |                                                    |
| `shipmentId`    | int NOT NULL    | -> `shipments_shipments.id` ON DELETE CASCADE       |
| `integrationId` | int NOT NULL    | -> `shipments_integrations.id` ON DELETE CASCADE    |
| `externalId`    | varchar NOT NULL| The external system's ID.                          |
| `url`           | varchar         | Optional override of the integration's URL template.|

UNIQUE on `(shipmentId, integrationId)` and on `(integrationId, externalId)`. Indexed on `shipmentId`.

## `shipments_integration_status_maps`

Per-integration vocabulary translation.

| Column          | Type        | Notes                                                         |
|-----------------|-------------|---------------------------------------------------------------|
| `id`            | int PK      |                                                               |
| `integrationId` | int NOT NULL| -> `shipments_integrations.id` ON DELETE CASCADE               |
| `direction`     | varchar(16) | `inbound`, `outbound`, or `bidirectional`. Default `inbound`. |
| `externalCode`  | varchar(128)| Integration's code.                                           |
| `externalLabel` | varchar(255)| Optional human description.                                   |
| `internalCode`  | varchar(32) | Our `Status` value.                                           |

UNIQUE on `(integrationId, direction, externalCode)`. Indexed on `(integrationId, internalCode)`.

## `shipments_emails`

Notification-email definitions.

| Column                  | Type             | Notes                                                |
|-------------------------|------------------|------------------------------------------------------|
| `id`                    | int PK           |                                                      |
| `name`                  | varchar NOT NULL |                                                      |
| `subject`               | varchar NOT NULL | Twig-rendered against the email context.             |
| `recipientType`         | varchar(20)      | `customer` or `custom`. Default `customer`.          |
| `to`                    | varchar          | Twig-rendered when `recipientType='custom'`.         |
| `bcc`                   | varchar          | Twig-rendered.                                       |
| `cc`                    | varchar          | Twig-rendered.                                       |
| `replyTo`               | varchar          | Twig-rendered.                                       |
| `enabled`               | bool             | Default true.                                        |
| `templatePath`          | varchar NOT NULL | HTML template path.                                  |
| `plainTextTemplatePath` | varchar          | Optional.                                            |
| `language`              | varchar(50)      | `orderLanguage` or a specific site language code.    |

Project-config-backed.

## `shipments_transition_emails`

Join: bind an email to a transition `toCode`.

| Column    | Type        | Notes                                                        |
|-----------|-------------|--------------------------------------------------------------|
| `id`      | int PK      |                                                              |
| `toCode`  | varchar(32) | `Status` value the transition must land on to trigger the email. |
| `emailId` | int NOT NULL| -> `shipments_emails.id` ON DELETE CASCADE                    |

UNIQUE on `(toCode, emailId)`. Indexed on `emailId`.

## `shipments_tracked_orders`

Which completed orders the plugin is actively watching for fulfillment, plus each order's cached shippability verdict and the admin's "Order requires shipping" toggle.

| Column                  | Type        | Notes                                                                                        |
|-------------------------|-------------|----------------------------------------------------------------------------------------------|
| `id`                    | int PK      |                                                                                              |
| `orderId`               | int NOT NULL| -> `commerce_orders.id` ON DELETE CASCADE. **UNIQUE**.                                        |
| `shippable`             | varchar(16) | `TrackedOrderShippable` value: `yes`, `no`, or `unknown`. Default `unknown`.                 |
| `state`                 | varchar(16) | `TrackedOrderState` value: `active` or `ignored`. Default `active`.                          |
| `underAllocated`        | varchar(16) | `TrackedOrderUnderAllocated` value: `yes` or `no`. Default `no`. Cached result of `ShipmentLineItems::isOrderUnderAllocated`, recomputed on every shipment save/delete/restore. Drives the Attention-page filter + the element-index `orderAllocation` sort. |
| `evaluatedAt`           | datetime    | When `shippable` was last computed.                                                          |
| `orderStatusAdvancedAt` | datetime    | When the order was auto-advanced after a shipment reached `shipped`.                         |
| `trackedAt`             | datetime    | When the row was first inserted. Never overwritten.                                          |
| `dateCreated`           | datetime    |                                                                                              |
| `dateUpdated`           | datetime    |                                                                                              |
| `uid`                   | uid         |                                                                                              |

Indexed on `(state, shippable, underAllocated)` for the Attention page filter.

## Craft core dependencies

The plugin references these Craft/Commerce tables by FK:

- `craft_elements`, the `Shipment` element's base row.
- `craft_users`, for history rows.
- `commerce_orders`, every shipment belongs to one.
- `commerce_lineitems`, qty allocations reference these.
- `commerce_lineitemstatuses`, for the `Commerce line-item status` grouping source.

All FKs cascade appropriately on delete:
- `commerce_orders` -> shipments cascade delete.
- `commerce_lineitems` -> line-item allocation rows cascade delete.
- `shipments_integrations` -> mappings + references cascade delete; history sets source to null.
- `craft_elements` (shipment row) -> supplementary + line items + history + references all cascade.
