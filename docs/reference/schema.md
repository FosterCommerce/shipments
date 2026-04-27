# Database schema reference

Every table the plugin creates, keyed. Source of truth: `src/migrations/Install.php`.

The plugin uses **no incremental migrations**, `Install.php` is the single source of truth for the full layout. Schema changes happen by reinstalling (drop + recreate) during plugin build-out. See the `feedback_schema_workflow.md` note.

## `shipments_shipments`

Supplementary table for the `Shipment` element. Keyed to `craft_elements.id`; the element row owns `dateCreated`, `dateUpdated`, `uid`, `dateDeleted`, `enabled`, `archived`.

| Column                    | Type           | Notes                                                                      |
|---------------------------|----------------|----------------------------------------------------------------------------|
| `id`                      | int PK         | -> `craft_elements.id` ON DELETE CASCADE                                    |
| `orderId`                 | int NOT NULL   | -> `commerce_orders.id` ON DELETE CASCADE                                   |
| `reference`               | varchar        | UNIQUE. Format `{orderRef}-sNNN`.                                          |
| `number`                  | int NOT NULL   | Per-order sequence integer. UNIQUE with `orderId`.                         |
| `fulfillmentStatus`       | varchar(32)    | `FulfillmentStatus` value. Default `open`. Indexed.                        |
| `shippingStatus`          | varchar(32)    | `ShippingStatus` value. Nullable. Indexed.                                 |
| `dateShippingStatus`      | datetime       | Timestamp of the latest carrier event.                                     |
| `dateScheduledShip`       | datetime       | Merchant-intended ship date.                                               |
| `trackingNumber`          | varchar        |                                                                            |
| `trackingUrl`             | varchar        |                                                                            |
| `carrier`                 | varchar        | Indexed.                                                                   |
| `service`                 | varchar        |                                                                            |
| `notes`                   | text           | Admin-editable free-text notes.                                            |
| `disableReason`           | varchar(255)   | Set when a system action (cascade from "Order requires shipping" off, order status moved into the ignore list) disables the shipment. Read by the order tab to render the disable label. Manual admin disables leave it null. |
| `dateLastPushAttempt`       | datetime       | Set by `PushShipmentJob` after each attempt.                               |
| `lastPushAttemptError`    | text           | Error message on the last attempt; null on success.                        |
| `pushAttemptCount`        | smallint       | Incremented on every push attempt.                                         |

## `shipments_shipment_line_items`

Per-shipment line-item qty allocations.

| Column          | Type        | Notes                                              |
|-----------------|-------------|----------------------------------------------------|
| `id`            | int PK      |                                                    |
| `shipmentId`    | int NOT NULL| -> `shipments_shipments.id` ON DELETE CASCADE       |
| `lineItemId`    | int NOT NULL| -> `commerce_lineitems.id` ON DELETE CASCADE        |
| `qty`           | int NOT NULL|                                                    |
| `dateCreated`   | datetime    |                                                    |
| `dateUpdated`   | datetime    |                                                    |
| `uid`           | uid         |                                                    |

UNIQUE on `(shipmentId, lineItemId)`. Indexed on `lineItemId`.

## `shipments_shipment_status_history`

Axis-aware audit log. Every `applyTransition` writes one row.

| Column                 | Type           | Notes                                                      |
|------------------------|----------------|------------------------------------------------------------|
| `id`                   | int PK         |                                                            |
| `shipmentId`           | int NOT NULL   | -> `shipments_shipments.id` ON DELETE CASCADE               |
| `axis`                 | varchar(16)    | `fulfillment` or `shipping`.                               |
| `fromCode`             | varchar(32)    | Prior enum value; null for first-transition (creation).    |
| `toCode`               | varchar(32)    | Post-transition enum value.                                |
| `message`              | text           | Optional note from the admin.                              |
| `userId`               | int            | -> `craft_users.id` ON DELETE SET NULL.                     |
| `sourceIntegrationId`  | int            | -> `shipments_integrations.id` ON DELETE SET NULL.          |
| `sourceExternalCode`   | varchar(128)   | Raw external code from the source integration.             |
| `dateCreated`          | datetime       |                                                            |
| `uid`                  | uid            |                                                            |

Indexed on `shipmentId` and `(shipmentId, axis)`.

## `shipments_integrations`

Configured external systems.

| Column        | Type           | Notes                                                |
|---------------|----------------|------------------------------------------------------|
| `id`          | int PK         |                                                      |
| `name`        | varchar NOT NULL| Display name.                                       |
| `handle`      | varchar NOT NULL| UNIQUE. Stable identifier. Used in webhook URLs.    |
| `urlTemplate` | varchar        | Optional URL template for `IntegrationReference::getResolvedUrl()`. |
| `provider`    | varchar        | FQCN of the `Provider` subclass.                     |
| `settings`    | text           | JSON-encoded provider settings.                      |
| `enabled`     | bool           | Default true.                                        |
| `sortOrder`   | int            |                                                      |
| `dateCreated` | datetime       |                                                      |
| `dateUpdated` | datetime       |                                                      |
| `uid`         | uid            |                                                      |

Project-config-backed. Reads and writes flow through `Integrations::handleChangedIntegration` / `handleDeletedIntegration`.

## `shipments_integration_references`

Per-shipment external IDs in each integration.

| Column          | Type           | Notes                                             |
|-----------------|----------------|---------------------------------------------------|
| `id`            | int PK         |                                                   |
| `shipmentId`    | int NOT NULL   | -> `shipments_shipments.id` ON DELETE CASCADE      |
| `integrationId` | int NOT NULL   | -> `shipments_integrations.id` ON DELETE CASCADE   |
| `externalId`    | varchar NOT NULL| The external system's ID.                        |
| `url`           | varchar        | Optional override of the integration's URL template. |

UNIQUE on `(shipmentId, integrationId)` and on `(integrationId, externalId)`.

## `shipments_integration_status_maps`

Per-integration vocabulary translation.

| Column            | Type          | Notes                                                         |
|-------------------|---------------|---------------------------------------------------------------|
| `id`              | int PK        |                                                               |
| `integrationId`   | int NOT NULL  | -> `shipments_integrations.id` ON DELETE CASCADE               |
| `axis`            | varchar(16)   | `fulfillment` or `shipping`.                                  |
| `direction`       | varchar(16)   | `inbound`, `outbound`, or `bidirectional`. Default `inbound`. |
| `externalCode`    | varchar(128)  | Integration's code.                                           |
| `externalLabel`   | varchar       | Optional human description.                                   |
| `internalCode`    | varchar(32)   | Our enum value.                                               |

UNIQUE on `(integrationId, axis, direction, externalCode)`.

## `shipments_carrier_events`

Raw carrier events, deduped by hash.

| Column            | Type          | Notes                                                               |
|-------------------|---------------|---------------------------------------------------------------------|
| `id`              | int PK        |                                                                     |
| `shipmentId`     | int NOT NULL  | -> `shipments_shipments.id` ON DELETE CASCADE                        |
| `integrationId`   | int           | -> `shipments_integrations.id` ON DELETE SET NULL                    |
| `code`            | varchar(64)   | Normalized `ShippingStatus` value OR raw external code if unmapped. |
| `description`     | varchar(512)  | Carrier-supplied human description.                                 |
| `dateOccurred`      | datetime      | Carrier's timestamp.                                                |
| `receivedAt`      | datetime      | When we ingested it.                                                |
| `locationCity`    | varchar(128)  |                                                                     |
| `locationRegion`  | varchar(64)   |                                                                     |
| `locationCountry` | char(2)       | Uppercase ISO-3166.                                                 |
| `rawPayload`      | text          | Provider payload, unmodified. JSON-encoded if we received an object.|
| `eventHash`       | char(64) NOT NULL | SHA-256 of `(shipmentId + code + dateOccurred + externalCode)`. **UNIQUE**, dedupes re-deliveries. |
| `reason`          | varchar(40)   | `CarrierEventReason` value. `projected` (normal), `skipped_disabled_shipment`, or `skipped_attention_off`. |

Indexed on `(shipmentId, dateOccurred)`.

## `shipments_unmapped_external_statuses`

Attention-needed row when an inbound webhook delivered an external code with no mapping.

| Column            | Type          | Notes                                                 |
|-------------------|---------------|-------------------------------------------------------|
| `id`              | int PK        |                                                       |
| `integrationId`   | int NOT NULL  | -> `shipments_integrations.id` ON DELETE CASCADE       |
| `axis`            | varchar(16)   |                                                       |
| `externalCode`    | varchar(128)  |                                                       |
| `occurrenceCount` | int NOT NULL  | Bumped on every re-sighting.                          |
| `dateFirstSeen`     | datetime      |                                                       |
| `dateLastSeen`      | datetime      |                                                       |
| `resolvedAt`      | datetime      | Set when the admin adds a matching mapping.           |

UNIQUE on `(integrationId, axis, externalCode)`.

## `shipments_emails`

Notification-email definitions.

| Column                  | Type                | Notes                                                         |
|-------------------------|---------------------|---------------------------------------------------------------|
| `id`                    | int PK              |                                                               |
| `name`                  | varchar NOT NULL    |                                                               |
| `subject`               | varchar NOT NULL    | Twig-rendered against the email context.                      |
| `recipientType`         | varchar(20)         | `customer` or `custom`.                                       |
| `to`                    | varchar             | Twig-rendered when `recipientType='custom'`.                  |
| `bcc`                   | varchar             | Twig-rendered.                                                |
| `cc`                    | varchar             | Twig-rendered.                                                |
| `replyTo`               | varchar             | Twig-rendered.                                                |
| `enabled`               | bool                | Default true.                                                 |
| `templatePath`          | varchar NOT NULL    | HTML template path.                                           |
| `plainTextTemplatePath` | varchar             | Optional.                                                     |
| `language`              | varchar(50)         | `orderLanguage` or a specific site language code.             |

Project-config-backed.

## `shipments_transition_emails`

Join: bind an email to a transition `(axis, toCode)`.

| Column        | Type          | Notes                                                          |
|---------------|---------------|----------------------------------------------------------------|
| `id`          | int PK        |                                                                |
| `axis`        | varchar(16)   | `fulfillment` or `shipping`.                                   |
| `toCode`      | varchar(32)   | Enum value the transition must land on to trigger the email.   |
| `emailId`     | int NOT NULL  | -> `shipments_emails.id` ON DELETE CASCADE                      |

UNIQUE on `(axis, toCode, emailId)`. Indexed on `emailId`.

## `shipments_tracked_orders`

Which completed orders the plugin is actively watching for fulfillment, plus each order's cached shippability verdict and the admin's "Order requires shipping" toggle.

| Column           | Type           | Notes                                                                                         |
|------------------|----------------|-----------------------------------------------------------------------------------------------|
| `id`             | int PK         |                                                                                               |
| `orderId`        | int NOT NULL   | -> `commerce_orders.id` ON DELETE CASCADE. **UNIQUE**.                                         |
| `shippable`      | varchar(16)    | `TrackedOrderShippable` value: `yes`, `no`, or `unknown`. Cached from `LineItem::getIsShippable()` filtered by `lineItemStatusesToIgnore`. |
| `state`          | varchar(16)    | `TrackedOrderState` value: `active` or `ignored`.                                              |
| `underAllocated` | varchar(16)    | `TrackedOrderUnderAllocated` value: `yes` or `no`. Cached result of `ShipmentLineItems::isOrderUnderAllocated`, recomputed by `TrackedOrders::recomputeUnderAllocation` on every shipment save/delete/restore. Drives the Attention-page filter + the element-index `orderAllocation` sort. |
| `evaluatedAt`    | datetime       | When `shippable` was last computed.                                                            |
| `trackedAt`      | datetime       | When the row was first inserted. Never overwritten.                                            |

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
- `shipments_integrations` -> mappings, references, unmapped rows cascade delete; carrier events + history set source to null.
- `craft_elements` (shipment row) -> supplementary + line items + history + references + events all cascade.

If ops needs permanent history retention beyond shipment delete, move to soft-delete-only on the element + null-safe FK on history.
