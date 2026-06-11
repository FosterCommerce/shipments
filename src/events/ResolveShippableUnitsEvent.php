<?php

declare(strict_types=1);

namespace fostercommerce\shipments\events;

use craft\commerce\elements\Order;
use yii\base\Event;

/**
 * Fired whenever the plugin resolves how many units of each line item can be shipped. `$shippableUnits`
 * is keyed by Commerce line item id and seeded with the cart qty. Listeners may override entries for
 * orders whose shippable unit count differs from cart qty, e.g. a single summary/kit line that stands
 * for many physical units. All pool and overflow math reads the resulting map, so coverage stays
 * consistent with whatever count is reported.
 */
class ResolveShippableUnitsEvent extends Event
{
	public Order $order;

	/**
	 * @var array<int, int>
	 */
	public array $shippableUnits = [];
}
