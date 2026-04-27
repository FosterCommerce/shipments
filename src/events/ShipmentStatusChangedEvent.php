<?php

declare(strict_types=1);

namespace fostercommerce\shipments\events;

use craft\elements\User;
use fostercommerce\shipments\elements\Shipment;
use fostercommerce\shipments\enums\FulfillmentStatus;
use fostercommerce\shipments\enums\ShippingStatus;
use fostercommerce\shipments\enums\StatusAxis;
use fostercommerce\shipments\models\Integration;
use fostercommerce\shipments\records\ShipmentStatusHistory;
use yii\base\Event;

/**
 * Fired post-commit on create (fromCode = null) and on every transition.
 * `axis` disambiguates whether this is a fulfillment or shipping transition.
 */
class ShipmentStatusChangedEvent extends Event
{
	public Shipment $shipment;

	public StatusAxis $axis;

	public FulfillmentStatus|ShippingStatus|null $fromCode = null;

	public FulfillmentStatus|ShippingStatus $toCode;

	public ShipmentStatusHistory $history;

	public ?User $user = null;

	public ?string $message = null;

	public ?Integration $sourceIntegration = null;

	public ?string $sourceExternalCode = null;
}
