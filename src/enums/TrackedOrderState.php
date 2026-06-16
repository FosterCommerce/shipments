<?php

declare(strict_types=1);

namespace fostercommerce\shipments\enums;

/**
 * Per-order admin toggle recorded on `shipments_tracked_orders.state`.
 */
enum TrackedOrderState: string
{
	case Active = 'active';

	/**
	 * Suppressed from the Attention page by an admin or the ignored-order-statuses listener.
	 */
	case Ignored = 'ignored';
}
