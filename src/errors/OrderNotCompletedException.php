<?php

declare(strict_types=1);

namespace fostercommerce\shipments\errors;

use Craft;
use fostercommerce\shipments\Plugin;
use yii\base\Exception;

/**
 * Thrown when a caller tries to build or create shipments for an order that hasn't been
 * completed yet. Shipments only exist for completed orders.
 */
class OrderNotCompletedException extends Exception
{
	public function __construct(
		public readonly int $orderId,
		string $message = '',
	) {
		if ($message === '') {
			$message = Craft::t(Plugin::HANDLE, 'error.orderNotCompleted', [
				'orderId' => $orderId,
			]);
		}

		parent::__construct($message);
	}
}
