<?php

declare(strict_types=1);

namespace fostercommerce\shipments\events;

use fostercommerce\shipments\elements\Shipment;
use yii\base\Event;

/**
 * Lets a listener change the data hashed to detect a stale quote.
 */
class DefineQuoteFingerprintEvent extends Event
{
	public Shipment $shipment;

	/**
	 * @var array<string, mixed>
	 */
	public array $fingerprintData = [];
}
