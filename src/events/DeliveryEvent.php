<?php

declare(strict_types=1);

namespace fostercommerce\shipments\events;

use fostercommerce\shipments\elements\Shipment;
use fostercommerce\shipments\models\Delivery;
use yii\base\Event;

/**
 * Saved carrier delivery and its current shipment for follow-up listeners.
 */
class DeliveryEvent extends Event
{
	public Shipment $shipment;

	public Delivery $delivery;
}
