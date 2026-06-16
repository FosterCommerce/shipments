<?php

declare(strict_types=1);

namespace fostercommerce\shipments\errors;

use Craft;
use fostercommerce\shipments\Plugin;
use yii\base\UserException;

/**
 * Thrown when an allocation change would push an order past the ordered quantity for some line item.
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
