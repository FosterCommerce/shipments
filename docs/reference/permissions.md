# Permissions reference

Exhaustive permission handle list. Granted per user group under **Users -> Groups -> {group} -> Permissions -> Shipments**. Admins always have everything.

## Handles

| Handle                              | Grants                                                                                                            | Nested under                  |
|-------------------------------------|-------------------------------------------------------------------------------------------------------------------|-------------------------------|
| `accessPlugin-shipments`            | Outer gate: see the Shipments nav item at all. Craft built-in; automatic for any plugin with `hasCpSection=true`. | |
| `shipments-viewShipments`           | See the shipments element index, attention-needed page, and shipment edit page (read).                            | |
| `shipments-editShipments`           | Create shipments via staging + edit fulfillment fields (tracking, carrier, service, notes).                       | `shipments-viewShipments`     |
| `shipments-transitionShipments`     | Change a shipment's status.                                                                                       | `shipments-viewShipments`     |
| `shipments-deleteShipments`         | Soft-delete shipments.                                                                                            | `shipments-viewShipments`     |
| `shipments-pushShipments`           | Use the **Push to {integration}** button in the sidebar of the shipment edit page.                                | `shipments-viewShipments`     |
| `shipments-manageIntegrations`      | CRUD integrations + status mappings.                                                                              | |
| `shipments-manageEmails`            | CRUD notification emails + bind transition triggers.                                                              | |
| `shipments-manageSettings`          | Edit plugin settings + the shipment field layout.                                                                 | |

`shipments-viewShipments` is required to grant any of its nested children in the CP UI (Craft convention).

## Which permission gates which action

### Controllers

| Action                                                  | Permission                          |
|---------------------------------------------------------|-------------------------------------|
| `shipments/shipments/edit` (load edit page)             | `shipments-viewShipments`           |
| `shipments/shipments/save` (save fulfillment fields)    | `shipments-editShipments`           |
| &nbsp; + status change included                         | `shipments-transitionShipments`     |
| `shipments/shipments/delete`                            | `shipments-deleteShipments`         |
| `shipments/shipments/rebuild`                           | `shipments-editShipments`           |
| `shipments/shipments/create-shipment` (staging submit)  | `shipments-editShipments`           |
| `shipments/shipments/push` (per-shipment push button)   | `shipments-pushShipments`           |
| `shipments/attention/index`                             | `shipments-viewShipments`           |
| `shipments/integrations/*`                              | `shipments-manageIntegrations`      |
| `shipments/emails/*`                                    | `shipments-manageEmails`            |
| `shipments/settings/*`                                  | `shipments-manageSettings`          |
| `shipments/shipment-fields/*`                           | `shipments-manageSettings`          |

## Recommended role presets

**CS agent (read-only):**
- `shipments-viewShipments`

**Warehouse operator:**
- `shipments-viewShipments`
- `shipments-editShipments`
- `shipments-transitionShipments`
- `shipments-pushShipments`

**Fulfillment lead:**
- Warehouse operator +
- `shipments-deleteShipments`
- `shipments-manageEmails`

**Integration engineer:**
- `shipments-viewShipments`
- `shipments-manageIntegrations`

**Store admin:**
- All of the above (except admin-only field layout).

## Registration

Permissions register via `UserPermissions::EVENT_REGISTER_PERMISSIONS` in `Plugin::registerPermissions()`. Constants on `Plugin`:

```php
Plugin::PERMISSION_VIEW
Plugin::PERMISSION_EDIT
Plugin::PERMISSION_TRANSITION
Plugin::PERMISSION_DELETE
Plugin::PERMISSION_PUSH
Plugin::PERMISSION_MANAGE_INTEGRATIONS
Plugin::PERMISSION_MANAGE_EMAILS
Plugin::PERMISSION_MANAGE_SETTINGS
```

Use the constants in custom integrations that check + gate plugin features.
