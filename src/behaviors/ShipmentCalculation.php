<?php

declare(strict_types=1);

namespace fostercommerce\shipments\behaviors;

use craft\commerce\elements\Order;
use RuntimeException;
use yii\base\Behavior;

/**
 * @extends Behavior<Order>
 */
class ShipmentCalculation extends Behavior
{
	public const BEHAVIOR = 'shipmentCalculation';

	/**
	 * @return array<string, string>
	 */
	public function events(): array
	{
		return [
			Order::EVENT_BEFORE_SAVE => 'preventSave',
		];
	}

	public function preventSave(): never
	{
		throw new RuntimeException('A shipment calculation context cannot be saved as an order.');
	}
}
