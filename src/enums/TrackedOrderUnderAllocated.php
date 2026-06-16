<?php

declare(strict_types=1);

namespace fostercommerce\shipments\enums;

/**
 * Cached verdict on `shipments_tracked_orders.underAllocated`: whether enabled shipments
 * fail to fully cover the order's non-ignored line items.
 */
enum TrackedOrderUnderAllocated: string
{
	case Yes = 'yes';

	case No = 'no';
}
