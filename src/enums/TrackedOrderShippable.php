<?php

declare(strict_types=1);

namespace fostercommerce\shipments\enums;

/**
 * Cached verdict on `shipments_tracked_orders.shippable`. Computed from
 * `LineItem::getIsShippable()` intersected with the plugin's `lineItemStatusesToIgnore`
 * setting. `Unknown` is reserved for states we couldn't evaluate (purchasable deleted,
 * order in an unexpected shape) and never participates in the Attention filter.
 */
enum TrackedOrderShippable: string
{
	case Yes = 'yes';

	case No = 'no';

	case Unknown = 'unknown';
}
