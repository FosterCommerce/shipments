<?php

declare(strict_types=1);

namespace fostercommerce\shipments\enums;

use Craft;
use fostercommerce\shipments\Plugin;

/**
 * The two status axes a shipment carries. Used to disambiguate history rows,
 * email transition triggers, and integration status mappings.
 */
enum StatusAxis: string
{
	case Fulfillment = 'fulfillment';

	case Shipping = 'shipping';

	public function label(): string
	{
		return match ($this) {
			self::Fulfillment => Craft::t(Plugin::HANDLE, 'shipmentEdit.fulfillmentTab'),
			self::Shipping => Craft::t(Plugin::HANDLE, 'shipmentEdit.shippingTab'),
		};
	}

	/**
	 * Resolve a code string for this axis into its typed enum case, or null if
	 * the code is not valid for this axis.
	 */
	public function resolveCode(string $code): FulfillmentStatus|ShippingStatus|null
	{
		return match ($this) {
			self::Fulfillment => FulfillmentStatus::tryFrom($code),
			self::Shipping => ShippingStatus::tryFrom($code),
		};
	}

	/**
	 * @return array<string, string>
	 */
	public function labelMap(): array
	{
		return match ($this) {
			self::Fulfillment => FulfillmentStatus::labelMap(),
			self::Shipping => ShippingStatus::labelMap(),
		};
	}
}
