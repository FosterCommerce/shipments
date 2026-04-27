<?php

declare(strict_types=1);

namespace fostercommerce\shipments\models;

use craft\elements\User;
use DateTime;
use fostercommerce\shipments\enums\FulfillmentStatus;
use fostercommerce\shipments\enums\ShippingStatus;
use fostercommerce\shipments\enums\StatusAxis;

/** Hydrated status-history row, axis-typed. */
final readonly class ShipmentStatusHistoryEntry
{
	public function __construct(
		public StatusAxis $axis,
		public FulfillmentStatus|ShippingStatus|null $fromCode,
		public FulfillmentStatus|ShippingStatus|null $toCode,
		public ?User $user,
		public ?DateTime $date,
		public ?string $message,
		public ?Integration $sourceIntegration,
		public ?string $sourceExternalCode,
	) {
	}
}
