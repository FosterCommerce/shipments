<?php

declare(strict_types=1);

namespace fostercommerce\shipments\enums;

/**
 * Per-order admin toggle recorded on `shipments_tracked_orders.state`. `Active` means the
 * order is in scope for the Attention page; `Ignored` means the admin (or the ignored-order-
 * statuses event listener) has suppressed it.
 */
enum TrackedOrderState: string
{
	case Active = 'active';

	case Ignored = 'ignored';
}
