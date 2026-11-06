<?php

declare(strict_types=1);

namespace fostercommerce\shipments\events;

use fostercommerce\shipments\elements\Shipment;
use fostercommerce\shipments\models\ShippingQuote;
use Money\Money;
use yii\base\Event;

/**
 * Lets a listener change the price the Delivery tab lists for a quote.
 */
class DefineQuotePriceEvent extends Event
{
	public Shipment $shipment;

	public ShippingQuote $quote;

	public ?Money $price = null;
}
