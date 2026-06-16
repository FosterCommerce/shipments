<?php

declare(strict_types=1);

namespace fostercommerce\shipments\models;

use craft\elements\User;
use DateTime;
use fostercommerce\shipments\enums\Status;

/** Hydrated status-history row. */
final readonly class ShipmentStatusHistoryEntry
{
	public function __construct(
		public ?Status $fromCode,
		public ?Status $toCode,
		public ?User $user,
		public ?DateTime $date,
		public ?string $message,
		public ?Integration $sourceIntegration,
		public ?string $sourceExternalCode,
	) {
	}
}
