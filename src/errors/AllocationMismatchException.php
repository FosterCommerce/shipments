<?php

declare(strict_types=1);

namespace fostercommerce\shipments\errors;

use Craft;
use fostercommerce\shipments\Plugin;
use yii\base\Exception;

/** Submitted allocations don't match the remaining pool. `submittedOutsidePool` = the order changed after render. */
class AllocationMismatchException extends Exception
{
	/**
	 * @param array<int, array{required: int, submitted: int}> $mismatches
	 */
	public function __construct(
		public readonly int $orderId,
		public readonly array $mismatches,
		public readonly bool $submittedOutsidePool,
		string $message = '',
		int $code = 0,
		?\Throwable $previous = null,
	) {
		parent::__construct($message, $code, $previous);
	}

	public function getName(): string
	{
		return Craft::t(Plugin::HANDLE, 'Shipment allocation mismatch');
	}
}
