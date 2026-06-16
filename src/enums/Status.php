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
	case New = 'new';

	case InProgress = 'in_progress';

	case OnHold = 'on_hold';

	case Fulfilled = 'fulfilled';

	case Shipped = 'shipped';

	case Cancelled = 'cancelled';

	public function label(): string
	{
		return match ($this) {
			self::New => Craft::t(Plugin::HANDLE, 'status.new'),
			self::InProgress => Craft::t(Plugin::HANDLE, 'status.inProgress'),
			self::OnHold => Craft::t(Plugin::HANDLE, 'status.onHold'),
			self::Fulfilled => Craft::t(Plugin::HANDLE, 'status.fulfilled'),
			self::Shipped => Craft::t(Plugin::HANDLE, 'status.shipped'),
			self::Cancelled => Craft::t(Plugin::HANDLE, 'status.cancelled'),
		};
	}

	/**
	 * Craft CP status color handle. See `craft\helpers\Cp::statusLabelHtml`.
	 */
	public function color(): string
	{
		return match ($this) {
			self::New => 'gray',
			self::InProgress => 'blue',
			self::OnHold => 'orange',
			self::Fulfilled => 'teal',
			self::Shipped => 'green',
			self::Cancelled => 'red',
		};
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
