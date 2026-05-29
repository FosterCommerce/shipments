<?php

declare(strict_types=1);

namespace fostercommerce\shipments\errors;

use Craft;
use fostercommerce\shipments\enums\FulfillmentStatus;
use fostercommerce\shipments\enums\ShippingStatus;
use fostercommerce\shipments\enums\StatusAxis;
use fostercommerce\shipments\Plugin;
use yii\base\UserException;

/**
 * Thrown when an axis transition fails an invariant (e.g. transitioning to
 * FulfillmentStatus::Fulfilled without a tracking number).
 *
 * `$reason` should already be translated by the caller; the exception composes
 * it into the full message via Craft::t so operators see a readable surface.
 */
class InvalidTransitionException extends UserException
{
	public function __construct(
		public readonly int $shipmentId,
		public readonly StatusAxis $statusAxis,
		public readonly FulfillmentStatus|ShippingStatus $target,
		string $reason,
		?\Throwable $previous = null,
	) {
		parent::__construct(
			Craft::t(Plugin::HANDLE, 'error.transitionNotAllowed', [
				'shipmentId' => $shipmentId,
				'axis' => $statusAxis->value,
				'target' => $target->value,
				'reason' => $reason,
			]),
			0,
			$previous,
		);
	}
}
