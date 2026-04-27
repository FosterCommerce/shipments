<?php

declare(strict_types=1);

namespace fostercommerce\shipments\events;

use craft\commerce\elements\Order;
use fostercommerce\shipments\base\ProviderInterface;
use fostercommerce\shipments\elements\Shipment;
use yii\base\Event;

/** Before/after a provider `sendShipment()`. Pre-handlers can set `$isValid = false` to skip. After fires only on successful send. */
class SendIntegrationPayloadEvent extends Event
{
	public ProviderInterface $integration;

	public Shipment $shipment;

	public Order $order;

	public bool $isValid = true;
}
