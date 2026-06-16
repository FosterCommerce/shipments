<?php

declare(strict_types=1);

namespace fostercommerce\shipments\enums;

/**
 * Why an ingested carrier event ended up in its current state, recorded on
 * `shipments_carrier_events.reason`.
 */
enum CarrierEventReason: string
{
	/**
	 * Deduped, resolved, and projected onto the target shipment's status.
	 */
	case Projected = 'projected';

	/**
	 * Target shipment's `enabled` flag is off; stored for audit, not projected.
	 */
	case SkippedDisabledShipment = 'skipped_disabled_shipment';

	/**
	 * Target order's tracked-order state is `Ignored`; stored for audit, not projected.
	 */
	case SkippedAttentionOff = 'skipped_attention_off';
}
