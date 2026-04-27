<?php

declare(strict_types=1);

namespace fostercommerce\shipments\events;

use craft\commerce\elements\Order;
use fostercommerce\shipments\elements\Shipment;
use fostercommerce\shipments\models\ShipmentPlan;
use yii\base\Event;

/**
 * Fired before and after shipment creation. `BEFORE` listeners may mutate `$plans` to
 * rewrite, merge, or append plans before persistence; `AFTER` listeners are observe-only
 * and can read the persisted `Shipment` elements via `$shipments`.
 */
class CreateShipmentsEvent extends Event
{
	public Order $order;

	/**
	 * @var list<ShipmentPlan>
	 */
	public array $plans = [];

	/**
	 * Empty on the BEFORE fire (nothing has been persisted yet); populated with the saved
	 * `Shipment` elements on the AFTER fire, in plan order.
	 *
	 * @var list<Shipment>
	 */
	public array $shipments = [];
}
