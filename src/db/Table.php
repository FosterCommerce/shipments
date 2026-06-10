<?php

declare(strict_types=1);

namespace fostercommerce\shipments\db;

/**
 * Table name constants for the Shipments plugin.
 */
final class Table
{
	public const SHIPMENTS = '{{%shipments_shipments}}';

	public const SHIPMENT_LINE_ITEMS = '{{%shipments_shipment_line_items}}';

	public const SHIPMENT_STATUS_HISTORY = '{{%shipments_shipment_status_history}}';

	public const TRANSITION_EMAILS = '{{%shipments_transition_emails}}';

	public const EMAILS = '{{%shipments_emails}}';

	public const INTEGRATIONS = '{{%shipments_integrations}}';

	public const INTEGRATION_REFERENCES = '{{%shipments_integration_references}}';

	public const INTEGRATION_STATUS_MAPS = '{{%shipments_integration_status_maps}}';

	public const CARRIER_EVENTS = '{{%shipments_carrier_events}}';

	public const TRACKED_ORDERS = '{{%shipments_tracked_orders}}';
}
