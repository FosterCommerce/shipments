<?php

declare(strict_types=1);

namespace fostercommerce\shipments\events;

use craft\commerce\elements\Order;
use fostercommerce\shipments\base\ProviderInterface;
use fostercommerce\shipments\elements\Shipment;
use yii\base\Event;

/** Before/after a provider `cancelShipment()`. Pre-handlers can set `$isValid = false` to skip. After fires only on successful cancel. */
class CancelIntegrationPayloadEvent extends Event
{
	public ProviderInterface $integration;

	public Shipment $shipment;

	public Order $order;

	public bool $isValid = true;
}
