<?php

declare(strict_types=1);

namespace fostercommerce\shipments\enums;

/**
 * Cached verdict on `shipments_tracked_orders.underAllocated`. `Yes` means the order's enabled
 * shipments don't fully cover its non-ignored line items; `No` means they do. Recomputed on
 * every shipment save/delete/restore by `TrackedOrders::recomputeUnderAllocation`; read by the
 * Attention page query and the element-index `orderAllocation` sort.
 */
enum TrackedOrderUnderAllocated: string
{
	case Yes = 'yes';

	case No = 'no';
}
