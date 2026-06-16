<?php

declare(strict_types=1);

namespace fostercommerce\shipments\enums;

use Craft;
use fostercommerce\shipments\Plugin;

/**
 * Merchant / 3PL fulfillment lifecycle.
 */
enum FulfillmentStatus: string
{
	case Open = 'open';

	case InProgress = 'in_progress';

	case Scheduled = 'scheduled';

	case OnHold = 'on_hold';

	case Fulfilled = 'fulfilled';

	case Cancelled = 'cancelled';

	case Incomplete = 'incomplete';

	public function label(): string
	{
		return match ($this) {
			self::Open => Craft::t(Plugin::HANDLE, 'status.fulfillment.open'),
			self::InProgress => Craft::t(Plugin::HANDLE, 'status.fulfillment.inProgress'),
			self::Scheduled => Craft::t(Plugin::HANDLE, 'status.fulfillment.scheduled'),
			self::OnHold => Craft::t(Plugin::HANDLE, 'status.fulfillment.onHold'),
			self::Fulfilled => Craft::t(Plugin::HANDLE, 'status.fulfillment.fulfilled'),
			self::Cancelled => Craft::t(Plugin::HANDLE, 'status.fulfillment.cancelled'),
			self::Incomplete => Craft::t(Plugin::HANDLE, 'status.fulfillment.incomplete'),
		};
	}

	/**
	 * Craft CP status color handle. See `craft\helpers\Cp::statusLabelHtml`.
	 */
	public function color(): string
	{
		return match ($this) {
			self::Open => 'gray',
			self::InProgress => 'blue',
			self::Scheduled => 'purple',
			self::OnHold => 'orange',
			self::Fulfilled => 'green',
			self::Cancelled => 'red',
			self::Incomplete => 'red',
		};
	}

	public function isTerminal(): bool
	{
		return match ($this) {
			self::Fulfilled, self::Cancelled => true,
			default => false,
		};
	}

	/**
	 * Whether reaching this status requires a tracking number on the shipment.
	 */
	public function requiresTrackingNumber(): bool
	{
		return $this === self::Fulfilled;
	}

	/**
	 * @return array<string, string>
	 */
	public static function labelMap(): array
	{
		$map = [];
		foreach (self::cases() as $case) {
			$map[$case->value] = $case->label();
		}

		return $map;
	}
}
