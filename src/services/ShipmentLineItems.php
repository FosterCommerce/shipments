<?php

declare(strict_types=1);

namespace fostercommerce\shipments\services;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\models\LineItem;
use craft\commerce\models\LineItemStatus;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\helpers\Typecast;
use fostercommerce\shipments\db\Table;
use fostercommerce\shipments\enums\TrackedOrderShippable;
use fostercommerce\shipments\enums\TrackedOrderState;
use fostercommerce\shipments\enums\TrackedOrderUnderAllocated;
use fostercommerce\shipments\errors\IncompleteCoverageException;
use fostercommerce\shipments\models\ShipmentLineItem;
use fostercommerce\shipments\Plugin;
use yii\base\Component;
use yii\caching\CacheInterface;

/**
 * Quantity math for line items across an order's shipments. Responsible for computing the
 * per-line-item pool of unallocated qty and enforcing the full-coverage invariant.
 */
class ShipmentLineItems extends Component
{
	private const ATTENTION_COUNT_CACHE_KEY = 'shipments.attentionCount';

	private const ATTENTION_COUNT_CACHE_TTL = 300;

	/**
	 * Remaining (unallocated) qty for every non-ignored line item on the order, keyed by
	 * Commerce line item id. Line items whose status handle matches the plugin's
	 * `lineItemStatusesToIgnore` list are omitted entirely.
	 *
	 * @return array<int, int>
	 */
	public function remainingPoolFor(Order $order): array
	{
		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();
		$ignoredStatuses = $plugin->getSettings()->lineItemStatusesToIgnore;

		$pool = [];
		foreach ($order->getLineItems() as $lineItem) {
			if ($this->isIgnored($lineItem, $ignoredStatuses)) {
				continue;
			}

			try {
				if (! $lineItem->getIsShippable()) {
					continue;
				}
			} catch (\Throwable) {
				// Missing purchasable etc. throws from Commerce; skip the line item rather
				// than letting the pool read fail for the whole order.
				continue;
			}

			$lineItemId = $lineItem->id;
			if ($lineItemId === null) {
				continue;
			}

			$pool[$lineItemId] = (int) $lineItem->qty;
		}

		if ($pool === [] || $order->id === null) {
			return $pool;
		}

		$alreadyAllocated = $this->allocatedQtysFor($order->id);
		foreach ($alreadyAllocated as $lineItemId => $allocatedQty) {
			if (! array_key_exists($lineItemId, $pool)) {
				continue;
			}

			$pool[$lineItemId] = max(0, $pool[$lineItemId] - $allocatedQty);
		}

		return $pool;
	}

	/**
	 * Qty already allocated to enabled, non-trashed shipments on the order, keyed by line
	 * item id.
	 *
	 * @return array<int, int>
	 */
	public function allocatedQtysFor(int $orderId): array
	{
		/** @var list<array{lineItemId: int|string, qtyTotal: int|string|null}> $rows */
		$rows = (new Query())
			->select([
				'[[sli.lineItemId]]',
				'qtyTotal' => 'SUM([[sli.qty]])',
			])
			->from([
				'sli' => Table::SHIPMENT_LINE_ITEMS,
			])
			->innerJoin([
				's' => Table::SHIPMENTS,
			], '[[s.id]] = [[sli.shipmentId]]')
			->innerJoin([
				'e' => CraftTable::ELEMENTS,
			], '[[e.id]] = [[s.id]]')
			->where([
				'[[s.orderId]]' => $orderId,
				'[[e.dateDeleted]]' => null,
				'[[e.enabled]]' => true,
			])
			->groupBy(['[[sli.lineItemId]]'])
			->all();

		$allocated = [];
		foreach ($rows as $row) {
			$allocated[(int) $row['lineItemId']] = (int) $row['qtyTotal'];
		}

		return $allocated;
	}

	/**
	 * True when the order's remaining pool has any positive values (completed order
	 * lacking full shipment coverage).
	 */
	public function isOrderUnderAllocated(Order $order): bool
	{
		foreach ($this->remainingPoolFor($order) as $remaining) {
			if ($remaining > 0) {
				return true;
			}
		}

		return false;
	}

	/**
	 * `lineItemId => qty missing` map for a single order, suitable for rendering
	 * the notice on the order's Shipments tab.
	 *
	 * @return array<int, int>
	 */
	public function getMissingCoverageFor(Order $order): array
	{
		$missing = [];
		foreach ($this->remainingPoolFor($order) as $lineItemId => $remaining) {
			if ($remaining > 0) {
				$missing[$lineItemId] = $remaining;
			}
		}

		return $missing;
	}

	/**
	 * Completed, tracked-and-shippable order ids flagged under-allocated. Reads the cached
	 * verdict on `shipments_tracked_orders.underAllocated`, which is kept fresh by
	 * `TrackedOrders::recomputeUnderAllocation` on every shipment save/delete/restore.
	 *
	 * @return list<int>
	 */
	public function findUnderAllocatedOrderIds(): array
	{
		$rawIds = (new Query())
			->select(['orderId'])
			->from(Table::TRACKED_ORDERS)
			->where([
				'state' => TrackedOrderState::Active->value,
				'shippable' => TrackedOrderShippable::Yes->value,
				'underAllocated' => TrackedOrderUnderAllocated::Yes->value,
			])
			->column();

		$orderIds = [];
		foreach ($rawIds as $rawId) {
			if (is_numeric($rawId)) {
				$orderIds[] = (int) $rawId;
			}
		}

		return $orderIds;
	}

	/**
	 * Would counting the given shipment's line-item allocations against the order's pool
	 * push any line item over its ordered quantity? Returns the mismatches keyed by line
	 * item id (`lineItemId => amount-by-which-we-would-overflow`) when yes, empty when no.
	 * Used to gate re-enable and restore transitions.
	 *
	 * @return array<int, int>
	 */
	public function overflowIfCounted(int $shipmentId, Order $order): array
	{
		if ($order->id === null) {
			return [];
		}

		$shipmentLineItems = $this->findForShipmentId($shipmentId);
		if ($shipmentLineItems === []) {
			return [];
		}

		$orderedQtys = [];
		foreach ($order->getLineItems() as $orderLineItem) {
			if ($orderLineItem->id === null) {
				continue;
			}

			$orderedQtys[(int) $orderLineItem->id] = (int) $orderLineItem->qty;
		}

		$otherAllocations = $this->allocatedQtysFor($order->id);

		$overflow = [];
		foreach ($shipmentLineItems as $shipmentLineItem) {
			$lineItemId = (int) $shipmentLineItem->lineItemId;
			$candidateTotal = ($otherAllocations[$lineItemId] ?? 0) + (int) $shipmentLineItem->qty;
			$ordered = $orderedQtys[$lineItemId] ?? 0;
			if ($candidateTotal > $ordered) {
				$overflow[$lineItemId] = $candidateTotal - $ordered;
			}
		}

		return $overflow;
	}

	/**
	 * Would replacing the shipment's current allocation with the proposed quantities push any
	 * line item over its ordered quantity? Returns the mismatches keyed by line item id
	 * (`lineItemId => amount-by-which-we-would-overflow`) when yes, empty when no. Unlike
	 * {@see overflowIfCounted}, which checks the persisted rows, this checks a *proposed*
	 * allocation, so it gates an in-place line-item edit before it is written. The shipment's
	 * own current allocation is excluded from the "already allocated" totals so that keeping or
	 * lowering a quantity never reads as overflow.
	 *
	 * @param array<int, int> $proposedQtys lineItemId => qty
	 * @return array<int, int>
	 */
	public function overflowForProposedAllocation(int $shipmentId, Order $order, array $proposedQtys): array
	{
		if ($order->id === null) {
			return [];
		}

		$orderedQtys = [];
		foreach ($order->getLineItems() as $orderLineItem) {
			if ($orderLineItem->id === null) {
				continue;
			}

			$orderedQtys[(int) $orderLineItem->id] = (int) $orderLineItem->qty;
		}

		$otherAllocations = $this->allocatedQtysFor($order->id);
		foreach ($this->findForShipmentId($shipmentId) as $currentLineItem) {
			$lineItemId = (int) $currentLineItem->lineItemId;
			$otherAllocations[$lineItemId] = ($otherAllocations[$lineItemId] ?? 0) - (int) $currentLineItem->qty;
		}

		$overflow = [];
		foreach ($proposedQtys as $lineItemId => $qty) {
			$lineItemId = (int) $lineItemId;
			$qty = (int) $qty;
			if ($qty <= 0) {
				continue;
			}

			$candidateTotal = max(0, $otherAllocations[$lineItemId] ?? 0) + $qty;
			$ordered = $orderedQtys[$lineItemId] ?? 0;
			if ($candidateTotal > $ordered) {
				$overflow[$lineItemId] = $candidateTotal - $ordered;
			}
		}

		return $overflow;
	}

	/**
	 * @return list<ShipmentLineItem>
	 */
	public function findForShipmentId(int $shipmentId): array
	{
		return $this->findForShipmentIds([$shipmentId])[$shipmentId] ?? [];
	}

	/**
	 * Batch-fetch line items for many shipments in a single query, keyed by shipmentId.
	 *
	 * @param list<int> $shipmentIds
	 * @return array<int, list<ShipmentLineItem>>
	 */
	public function findForShipmentIds(array $shipmentIds): array
	{
		if ($shipmentIds === []) {
			return [];
		}

		/** @var list<array<string, mixed>> $rows */
		$rows = (new Query())
			->from(Table::SHIPMENT_LINE_ITEMS)
			->where([
				'shipmentId' => $shipmentIds,
			])
			->orderBy([
				'id' => SORT_ASC,
			])
			->all();

		$byShipmentId = array_fill_keys($shipmentIds, []);
		foreach ($rows as $row) {
			$shipmentIdRaw = $row['shipmentId'] ?? null;
			if (! is_numeric($shipmentIdRaw)) {
				continue;
			}

			Typecast::properties(ShipmentLineItem::class, $row);
			$byShipmentId[(int) $shipmentIdRaw][] = new ShipmentLineItem($row);
		}

		return $byShipmentId;
	}

	/**
	 * Throws if any non-ignored line item still has unallocated qty on the order.
	 *
	 * @throws IncompleteCoverageException
	 */
	public function assertFullCoverage(Order $order): void
	{
		if ($order->id === null) {
			return;
		}

		$remaining = $this->remainingPoolFor($order);
		$missing = array_filter($remaining, static fn (int $qty): bool => $qty > 0);

		if ($missing !== []) {
			throw new IncompleteCoverageException($order->id, $missing);
		}
	}

	/**
	 * Badge count for the Attention-needed subnav: under-allocated tracked orders. Cached so
	 * every CP page render doesn't run the aggregate query.
	 */
	public function getCachedAttentionCount(): int
	{
		$cache = Craft::$app->getCache();
		/** @var CacheInterface $cache */
		$cached = $cache->get(self::ATTENTION_COUNT_CACHE_KEY);
		if (is_int($cached)) {
			return $cached;
		}

		$total = count($this->findUnderAllocatedOrderIds());
		$cache->set(self::ATTENTION_COUNT_CACHE_KEY, $total, self::ATTENTION_COUNT_CACHE_TTL);

		return $total;
	}

	public function invalidateAttentionCount(): void
	{
		$cache = Craft::$app->getCache();
		/** @var CacheInterface $cache */
		$cache->delete(self::ATTENTION_COUNT_CACHE_KEY);
	}

	/**
	 * @param list<string> $ignoredStatuses
	 */
	private function isIgnored(LineItem $lineItem, array $ignoredStatuses): bool
	{
		if ($ignoredStatuses === []) {
			return false;
		}

		$status = $lineItem->getLineItemStatus();
		if (! $status instanceof LineItemStatus) {
			return false;
		}

		return in_array($status->handle, $ignoredStatuses, true);
	}
}
