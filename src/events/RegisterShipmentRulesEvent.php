<?php

declare(strict_types=1);

namespace fostercommerce\shipments\events;

use fostercommerce\shipments\base\ShipmentRuleInterface;
use yii\base\Event;

/**
 * Fired by the `Rules` service during initialization so that integrators can register additional
 * shipment rule implementations. Listeners should push instances implementing
 * `ShipmentRuleInterface` onto `$rules`.
 */
class RegisterShipmentRulesEvent extends Event
{
	/**
	 * @var list<ShipmentRuleInterface>
	 */
	public array $rules = [];
}
