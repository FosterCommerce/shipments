<?php

declare(strict_types=1);

namespace fostercommerce\shipments\errors;

use Craft;
use fostercommerce\shipments\Plugin;
use Throwable;
use yii\base\Exception;

/**
 * Thrown when a new shipment would collide with an existing shipment's reference. Usually
 * indicates a race condition in reference allocation; the caller should retry.
 */
class DuplicateShipmentReferenceException extends Exception
{
	public function __construct(
		public readonly string $reference,
		string $message = '',
		?Throwable $previous = null,
	) {
		if ($message === '') {
			$message = Craft::t(Plugin::HANDLE, 'A shipment with reference “{reference}” already exists.', [
				'reference' => $reference,
			]);
		}

		parent::__construct($message, 0, $previous);
	}
}
