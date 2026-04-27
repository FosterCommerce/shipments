# Permissions

Who can do what. The plugin registers granular permissions under **Users -> Groups -> {group} -> Permissions -> Shipments**. Admins always have everything.

For the full handle list, see the [permissions reference](../reference/permissions.md). This page is for setting up roles.

## The permissions

### Viewing

**View shipments**, required to see the plugin at all (past the nav item). Without it, a user can't open the Shipments index, the Attention page, or any shipment edit page. Grant to anyone who needs to see fulfillment data.

### Editing

**Edit shipments**, create shipments from the order-tab staging form, and edit tracking, carrier, service, and notes on existing ones. Also covers creating shipments through the `rebuild` console command and the REST API update endpoint.

### Transitioning

**Transition shipment statuses**, change fulfillment or shipping status. Split from Edit because status changes drive emails, integration pushes, and history. If your CS team should add tracking numbers but not change fulfillment, keep this permission away from them.

### Deleting

**Delete shipments**, soft-delete. The line items return to the unallocated pool. Restorable from Craft's trash.

### Pushing

**Push shipments to integrations**, use the **Push to {integration}** button in the sidebar of the shipment edit page. Queues a push job for that shipment against the chosen integration.

### Managing integrations

**Manage integrations**, manage integrations and their status mappings. The "integration engineer" role, separate from everyday ops.

### Managing emails

**Manage notification emails**, manage notification emails and wire their transition triggers.

### Managing settings

**Manage plugin settings**, edit the General settings page and the Shipment field layout.

## Recommended role presets

**Customer service agent (read-only).** Looks up shipment status. Doesn't modify.
- View shipments

**Warehouse operator.** Day-to-day picker / packer. Reads incoming orders, adds tracking, moves to fulfilled, pushes to the 3PL.
- View shipments
- Edit shipments
- Transition shipment statuses
- Push shipments to integrations

**Fulfillment lead.** Warehouse operator plus cleanup and customer comms.
- All warehouse-operator permissions
- Delete shipments
- Manage notification emails

**Integration engineer.** Wires up and monitors integrations.
- View shipments
- Manage integrations

**Store admin (non-Craft-admin).** Everything day-to-day.
- All of the above

**Craft admin.** Everything. No permission checks.

## Nesting

Edit, Transition, Delete, and Push all nest under View. You can't grant any of them without also granting View. Craft's permission editor shows this in the UI.

Manage integrations, Manage emails, and Manage settings are independent. You can give someone email-management access without giving them view access to shipments.

## How denial looks

- **Reading a page they can't access** (for example the index): Craft returns its standard 403 page.
- **Trying a sidebar push without the permission**: the button is hidden; the controller also rejects the POST with a 403 if invoked directly.
- **Changing status without the permission** from the CP edit page: the save returns 403 and the change doesn't happen.
- **REST API call without the permission**: 403 JSON response.

## Auditing who did what

Every status change records the user who made it (empty for background jobs and webhook ingestors). Open the shipment's **Status history** tab to see the user, the source integration, and the raw code the integration sent for each change.

For other actions (creating, deleting, editing tracking / carrier / notes), Craft's built-in element change log covers it. Open the shipment edit page and click the **Drafts** / revision history icon in the top bar.
