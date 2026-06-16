<?php

declare(strict_types=1);

namespace fostercommerce\shipments\enums;

use Craft;
use fostercommerce\shipments\Plugin;

/**
 * Shipment status lifecycle.
 */
enum Status: string
{
	case Open = 'open';

	case InProgress = 'in_progress';

	case Scheduled = 'scheduled';

	case Shipped = 'shipped';

	case OnHold = 'on_hold';

	case Cancelled = 'cancelled';

	case Incomplete = 'incomplete';

	public function label(): string
	{
		return match ($this) {
			self::Open => Craft::t(Plugin::HANDLE, 'status.open'),
			self::InProgress => Craft::t(Plugin::HANDLE, 'status.inProgress'),
			self::Scheduled => Craft::t(Plugin::HANDLE, 'status.scheduled'),
			self::Shipped => Craft::t(Plugin::HANDLE, 'status.shipped'),
			self::OnHold => Craft::t(Plugin::HANDLE, 'status.onHold'),
			self::Cancelled => Craft::t(Plugin::HANDLE, 'status.cancelled'),
			self::Incomplete => Craft::t(Plugin::HANDLE, 'status.incomplete'),
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
			self::Shipped => 'green',
			self::OnHold => 'orange',
			self::Cancelled => 'red',
			self::Incomplete => 'red',
		};
	}

	public function isTerminal(): bool
	{
		return match ($this) {
			self::Shipped, self::Cancelled => true,
			default => false,
		};
	}

	/**
	 * Whether reaching this status requires a tracking number on the shipment.
	 */
	public function requiresTrackingNumber(): bool
	{
		return $this === self::Shipped;
	}

	/**
	 * Whether reaching this status advances the Commerce order to the configured target.
	 */
	public function advancesOrder(): bool
	{
		return $this === self::Shipped;
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
