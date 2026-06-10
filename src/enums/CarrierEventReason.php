<?php

declare(strict_types=1);

namespace fostercommerce\shipments\enums;

/**
 * Why an ingested carrier event ended up in its current state. Recorded on
 * `shipments_carrier_events.reason` so ops can see inbound updates that arrived for
 * shipments the plugin chose not to project onto.
 */
enum CarrierEventReason: string
{
	/**
	 * Normal ingest: event was deduped, resolved (or logged when no mapping exists), and
	 * projected onto the target shipment's status.
	 */
	case Projected = 'projected';

	/**
	 * Target shipment isn't projection-eligible (typically the element-level `enabled` flag
	 * has been flipped off via Craft's edit UI). Event is stored for audit, not projected.
	 */
	case SkippedDisabledShipment = 'skipped_disabled_shipment';

	/**
	 * Target shipment's order has the "Order requires shipping" lightswitch flipped off
	 * (tracked-order state is `Ignored`). Event is stored for audit, not projected.
	 */
	case SkippedAttentionOff = 'skipped_attention_off';
}
