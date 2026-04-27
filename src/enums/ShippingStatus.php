<?php

declare(strict_types=1);

namespace fostercommerce\shipments\enums;

use Craft;
use fostercommerce\shipments\Plugin;

/**
 * Carrier-driven shipping lifecycle. Normalized across providers so downstream
 * logic doesn't vary by carrier. Null on a shipment means no carrier event has
 * been observed yet.
 */
enum ShippingStatus: string
{
	case Pending = 'pending';

	case PreTransit = 'pre_transit';

	case InTransit = 'in_transit';

	case OutForDelivery = 'out_for_delivery';

	case AttemptedDelivery = 'attempted_delivery';

	case AvailableForPickup = 'available_for_pickup';

	case Delivered = 'delivered';

	case Exception = 'exception';

	case Returned = 'returned';

	case Failure = 'failure';

	public function label(): string
	{
		return match ($this) {
			self::Pending => Craft::t(Plugin::HANDLE, 'Pending'),
			self::PreTransit => Craft::t(Plugin::HANDLE, 'Pre-transit'),
			self::InTransit => Craft::t(Plugin::HANDLE, 'In transit'),
			self::OutForDelivery => Craft::t(Plugin::HANDLE, 'Out for delivery'),
			self::AttemptedDelivery => Craft::t(Plugin::HANDLE, 'Attempted delivery'),
			self::AvailableForPickup => Craft::t(Plugin::HANDLE, 'Available for pickup'),
			self::Delivered => Craft::t(Plugin::HANDLE, 'Delivered'),
			self::Exception => Craft::t(Plugin::HANDLE, 'Exception'),
			self::Returned => Craft::t(Plugin::HANDLE, 'Returned'),
			self::Failure => Craft::t(Plugin::HANDLE, 'Failure'),
		};
	}

	public function color(): string
	{
		return match ($this) {
			self::Pending => 'gray',
			self::PreTransit => 'gray',
			self::InTransit => 'blue',
			self::OutForDelivery => 'blue',
			self::AttemptedDelivery => 'orange',
			self::AvailableForPickup => 'purple',
			self::Delivered => 'green',
			self::Exception => 'orange',
			self::Returned => 'red',
			self::Failure => 'red',
		};
	}

	public function isTerminal(): bool
	{
		return match ($this) {
			self::Delivered, self::Returned, self::Failure => true,
			default => false,
		};
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
