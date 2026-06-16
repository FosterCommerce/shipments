<?php

declare(strict_types=1);

namespace fostercommerce\shipments\enums;

/**
 * Cached verdict on `shipments_tracked_orders.shippable`.
 */
enum TrackedOrderShippable: string
{
	case Yes = 'yes';

	case No = 'no';

	/**
	 * Could not be evaluated (e.g. purchasable deleted); excluded from the Attention filter.
	 */
	case Unknown = 'unknown';
}
