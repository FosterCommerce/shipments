<?php

declare(strict_types=1);

namespace fostercommerce\shipments\elements\db;

use craft\db\Query;
use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use DateTimeInterface;
use fostercommerce\shipments\db\Table;
use fostercommerce\shipments\elements\Shipment;

/**
 * Element query for {@see Shipment} elements.
 *
 * @method Shipment[]|array all($db = null)
 * @method Shipment|array|null one($db = null)
 * @method Shipment|array|null nth(int $n, $db = null)
 *
 * @extends ElementQuery<int, Shipment>
 */
class ShipmentQuery extends ElementQuery
{
	public mixed $orderId = null;

	public mixed $fulfillmentStatus = null;

	public mixed $shippingStatus = null;

	public mixed $reference = null;

	public mixed $trackingNumber = null;

	public mixed $carrier = null;

	public mixed $service = null;

	public mixed $integrationId = null;

	public mixed $dateShippingStatus = null;

	public function orderId(mixed $value): static
	{
		$this->orderId = $value;
		return $this;
	}

	public function fulfillmentStatus(mixed $value): static
	{
		$this->fulfillmentStatus = $value;
		return $this;
	}

	public function shippingStatus(mixed $value): static
	{
		$this->shippingStatus = $value;
		return $this;
	}

	public function reference(mixed $value): static
	{
		$this->reference = $value;
		return $this;
	}

	public function trackingNumber(mixed $value): static
	{
		$this->trackingNumber = $value;
		return $this;
	}

	public function carrier(mixed $value): static
	{
		$this->carrier = $value;
		return $this;
	}

	public function service(mixed $value): static
	{
		$this->service = $value;
		return $this;
	}

	public function dateShippingStatus(mixed $value): static
	{
		$this->dateShippingStatus = $value;
		return $this;
	}

	/**
	 * Filter to shipments that have at least one integration reference for the given
	 * integration id. Useful for "show me shipments pushed to ShipStation."
	 */
	public function integrationId(mixed $value): static
	{
		$this->integrationId = $value;
		return $this;
	}

	protected function beforePrepare(): bool
	{
		$this->joinElementTable(Table::SHIPMENTS);

		$query = $this->query;
		$subQuery = $this->subQuery;
		if (! $query instanceof Query || ! $subQuery instanceof Query) {
			return parent::beforePrepare();
		}

		// Join the tracked-orders table so the `orderAllocation` sort can read the cached
		// verdict on `shipments_tracked_orders.underAllocated` without a correlated subquery.
		// Craft pushes ORDER BY into the inner subquery (for LIMIT/OFFSET) and re-applies it
		// on the outer hydration query, so the join has to exist on both.
		$query->leftJoin(
			[
				'tracked' => Table::TRACKED_ORDERS,
			],
			'[[tracked.orderId]] = [[shipments_shipments.orderId]]',
		);
		$subQuery->leftJoin(
			[
				'tracked' => Table::TRACKED_ORDERS,
			],
			'[[tracked.orderId]] = [[shipments_shipments.orderId]]',
		);

		$query->addSelect([
			'[[shipments_shipments.orderId]]',
			'[[shipments_shipments.fulfillmentStatus]]',
			'[[shipments_shipments.shippingStatus]]',
			'[[shipments_shipments.dateShippingStatus]]',
			'[[shipments_shipments.dateScheduledShip]]',
			'[[shipments_shipments.reference]]',
			'[[shipments_shipments.number]]',
			'[[shipments_shipments.trackingNumber]]',
			'[[shipments_shipments.trackingUrl]]',
			'[[shipments_shipments.carrier]]',
			'[[shipments_shipments.service]]',
			'[[shipments_shipments.fulfillmentNotes]]',
			'[[shipments_shipments.shippingNotes]]',
			'[[shipments_shipments.dateLastPushAttempt]]',
			'[[shipments_shipments.lastPushAttemptError]]',
			'[[shipments_shipments.pushAttemptCount]]',
		]);

		$this->applyNumericFilter('[[shipments_shipments.orderId]]', $this->orderId);
		$this->applyStringFilter('[[shipments_shipments.fulfillmentStatus]]', $this->fulfillmentStatus);
		$this->applyStringFilter('[[shipments_shipments.shippingStatus]]', $this->shippingStatus);
		$this->applyStringFilter('[[shipments_shipments.reference]]', $this->reference);
		$this->applyStringFilter('[[shipments_shipments.trackingNumber]]', $this->trackingNumber);
		$this->applyStringFilter('[[shipments_shipments.carrier]]', $this->carrier);
		$this->applyStringFilter('[[shipments_shipments.service]]', $this->service);
		$this->applyDateFilter('[[shipments_shipments.dateShippingStatus]]', $this->dateShippingStatus);

		$integrationIdFilter = $this->coerceNumericFilter($this->integrationId);
		if ($integrationIdFilter !== null) {
			$parsed = Db::parseNumericParam('integrationId', $integrationIdFilter);
			if ($parsed !== null) {
				$referencedIds = (new Query())
					->select(['shipmentId'])
					->from(Table::INTEGRATION_REFERENCES)
					->where($parsed);
				$subQuery->andWhere([
					'[[elements.id]]' => $referencedIds,
				]);
			}
		}

		return parent::beforePrepare();
	}

	private function applyNumericFilter(string $column, mixed $rawValue): void
	{
		$subQuery = $this->subQuery;
		if (! $subQuery instanceof Query) {
			return;
		}

		$value = $this->coerceNumericFilter($rawValue);
		if ($value === null) {
			return;
		}

		$condition = Db::parseNumericParam($column, $value);
		if ($condition === null) {
			return;
		}

		$subQuery->andWhere($condition);
	}

	private function applyStringFilter(string $column, mixed $rawValue): void
	{
		$subQuery = $this->subQuery;
		if (! $subQuery instanceof Query) {
			return;
		}

		$value = $this->coerceStringFilter($rawValue);
		if ($value === null) {
			return;
		}

		$condition = Db::parseParam($column, $value);
		if ($condition === null) {
			return;
		}

		$subQuery->andWhere($condition);
	}

	private function applyDateFilter(string $column, mixed $rawValue): void
	{
		$subQuery = $this->subQuery;
		if (! $subQuery instanceof Query || $rawValue === null) {
			return;
		}

		if (! is_string($rawValue) && ! is_array($rawValue) && ! $rawValue instanceof DateTimeInterface) {
			return;
		}

		$condition = Db::parseDateParam($column, $rawValue);
		if ($condition === null) {
			return;
		}

		$subQuery->andWhere($condition);
	}

	/**
	 * @return array<string>|string|null
	 */
	private function coerceNumericFilter(mixed $rawValue): array|string|null
	{
		if ($rawValue === null) {
			return null;
		}

		if (is_array($rawValue)) {
			$out = [];
			foreach ($rawValue as $item) {
				if (is_int($item) || is_string($item)) {
					$out[] = (string) $item;
				}
			}

			return $out === [] ? null : $out;
		}

		if (is_int($rawValue) || is_string($rawValue)) {
			return (string) $rawValue;
		}

		return null;
	}

	/**
	 * @return array<string>|string|null
	 */
	private function coerceStringFilter(mixed $rawValue): array|string|null
	{
		if ($rawValue === null) {
			return null;
		}

		if (is_array($rawValue)) {
			$out = [];
			foreach ($rawValue as $item) {
				if (is_int($item) || is_string($item)) {
					$out[] = (string) $item;
				}
			}

			return $out === [] ? null : $out;
		}

		if (is_int($rawValue) || is_string($rawValue)) {
			return (string) $rawValue;
		}

		return null;
	}
}
