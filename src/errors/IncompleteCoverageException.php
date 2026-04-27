<?php

declare(strict_types=1);

namespace fostercommerce\shipments\errors;

use Craft;
use fostercommerce\shipments\Plugin;
use yii\base\Exception;

/**
 * Thrown when a save operation would leave one or more non-ignored line item quantities
 * unaccounted for across an order's shipments.
 */
class IncompleteCoverageException extends Exception
{
	/**
	 * @param array<int, int> $missingQtyByLineItemId Remaining unallocated qty keyed by line item id.
	 */
	public function __construct(
		public readonly int $orderId,
		public readonly array $missingQtyByLineItemId,
		string $message = '',
	) {
		if ($message === '') {
			$message = Craft::t(Plugin::HANDLE, 'Order {orderId} has line items without full shipment coverage.', [
				'orderId' => $orderId,
			]);
		}

		parent::__construct($message);
	}
}
