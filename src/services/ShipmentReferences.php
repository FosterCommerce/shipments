<?php

declare(strict_types=1);

namespace fostercommerce\shipments\services;

use craft\commerce\elements\Order;
use craft\db\Query;
use fostercommerce\shipments\db\Table;
use yii\base\Component;
use yii\base\InvalidArgumentException;

/**
 * Allocates the next `-sNNN` shipment reference for an order. Reference base is
 * `$order->reference` when present, or `$order->number` as a fallback (which Commerce always populates
 * with a long hashed value). Final shape: `{base}-s001`, `{base}-s002`, …
 */
class ShipmentReferences extends Component
{
	public const SEPARATOR = '-s';

	/**
	 * Compute the next sequence number for the given order and return the full reference string.
	 * Call this inside the same transaction that persists the shipment to minimize the race
	 * window; the `(orderId, number)` and `reference` unique indexes are the ultimate guards.
	 */
	public function allocate(Order $order): string
	{
		if ($order->id === null) {
			throw new InvalidArgumentException('Cannot allocate a shipment reference for an unsaved order.');
		}

		$nextNumber = $this->nextNumberFor($order->id);
		$base = $this->baseFor($order);

		return $base . self::SEPARATOR . str_pad((string) $nextNumber, 3, '0', STR_PAD_LEFT);
	}

	public function nextNumberFor(int $orderId): int
	{
		$maxNumber = (new Query())
			->select([
				'maxNumber' => 'MAX([[number]])',
			])
			->from(Table::SHIPMENTS)
			->where([
				'orderId' => $orderId,
			])
			->scalar();

		return ((int) $maxNumber) + 1;
	}

	private function baseFor(Order $order): string
	{
		$reference = $order->reference;
		if ($reference !== null && $reference !== '') {
			return $reference;
		}

		$number = $order->number;
		if ($number !== null && $number !== '') {
			return $number;
		}

		return 'order-' . $order->id;
	}
}
