<?php

declare(strict_types=1);

namespace fostercommerce\shipments\models;

use craft\commerce\elements\Order;
use craft\elements\User;
use fostercommerce\shipments\elements\Shipment;
use fostercommerce\shipments\enums\FulfillmentStatus;
use fostercommerce\shipments\enums\ShippingStatus;
use fostercommerce\shipments\enums\StatusAxis;
use fostercommerce\shipments\records\ShipmentStatusHistory;

/** Render-context bundle for a shipment email send. */
final readonly class ShipmentEmailContext
{
	public function __construct(
		public Shipment $shipment,
		public Order $order,
		public ?StatusAxis $axis = null,
		public FulfillmentStatus|ShippingStatus|null $fromCode = null,
		public FulfillmentStatus|ShippingStatus|null $toCode = null,
		public ?ShipmentStatusHistory $history = null,
		public ?User $user = null,
		public ?string $message = null,
	) {
	}
}
