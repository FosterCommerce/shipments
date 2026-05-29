<?php

declare(strict_types=1);

namespace fostercommerce\shipments\errors;

use Craft;
use fostercommerce\shipments\Plugin;
use yii\base\UserException;

/**
 * Thrown when an allocation change (restoring or re-enabling a shipment, or editing its line
 * items in place) would push its order past the ordered quantity for some line item.
 * UserException so Craft surfaces the message to admins in the CP.
 */
class AllocationOverflowException extends UserException
{
	/**
	 * @param array<int, int> $overflowByLineItemId Qty by which each line item would overflow.
	 */
	public function __construct(
		public readonly int $shipmentId,
		public readonly int $orderId,
		public readonly array $overflowByLineItemId,
		string $message = '',
	) {
		if ($message === '') {
			$message = Craft::t(Plugin::HANDLE, 'error.shipmentOverAllocates', [
				'shipmentId' => $shipmentId,
				'orderId' => $orderId,
				'lineItemIds' => implode(', ', array_keys($overflowByLineItemId)),
			]);
		}

		parent::__construct($message);
	}
}
