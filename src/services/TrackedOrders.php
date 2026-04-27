<?php

declare(strict_types=1);

namespace fostercommerce\shipments\services;

use Craft;
use craft\commerce\db\Table as CommerceTable;
use craft\commerce\elements\Order;
use craft\commerce\models\OrderStatus;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use DateTime;
use fostercommerce\shipments\db\Table;
use fostercommerce\shipments\enums\TrackedOrderShippable;
use fostercommerce\shipments\enums\TrackedOrderState;
use fostercommerce\shipments\enums\TrackedOrderUnderAllocated;
use fostercommerce\shipments\errors\AllocationOverflowException;
use fostercommerce\shipments\Plugin;
use fostercommerce\shipments\records\TrackedOrder as TrackedOrderRecord;
use Throwable;
use yii\base\Component;
use yii\base\InvalidArgumentException;

/**
 * Manages the `shipments_tracked_orders` table: which completed orders the plugin is
 * actively watching for fulfillment, their cached shippability verdict, and the per-order
 * admin toggle.
 *
 * Two entry points write rows here:
 *   1. `Shipments::createFor` calls `evaluateAndUpsert` before running the rules engine.
 *   2. The "Order requires shipping" lightswitch controller calls `markActive` when an
 *      admin toggles the switch on, routing through `evaluateAndUpsert`.
 *
 * The Attention page joins this table and filters by `state=active AND shippable=yes`.
 * Orders without a row are invisible to Attention, which is how pre-install history stays
 * quiet.
 */
class TrackedOrders extends Component
{
	public function findForOrderId(int $orderId): ?TrackedOrderRecord
	{
		/** @var ?TrackedOrderRecord $record */
		$record = TrackedOrderRecord::findOne([
			'orderId' => $orderId,
		]);
		return $record;
	}

	/**
	 * Intersects Commerce's own `LineItem::getIsShippable()` with the plugin's
	 * `lineItemStatusesToIgnore` setting. If any line item passes both checks, the order
	 * has shipping work to do.
	 */
	public function resolveShippable(Order $order): TrackedOrderShippable
	{
		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();
		$ignoredStatuses = $plugin->getSettings()->lineItemStatusesToIgnore;

		foreach ($order->getLineItems() as $lineItem) {
			try {
				if (! $lineItem->getIsShippable()) {
					continue;
				}
			} catch (Throwable) {
				// A missing purchasable etc. throws from Commerce; treat this line item
				// as non-shippable rather than poisoning the whole verdict.
				continue;
			}

			$status = $lineItem->getLineItemStatus();
			if ($status !== null && $ignoredStatuses !== [] && in_array($status->handle, $ignoredStatuses, true)) {
				continue;
			}

			return TrackedOrderShippable::Yes;
		}

		return TrackedOrderShippable::No;
	}

	public function isOrderStatusIgnored(Order $order): bool
	{
		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();
		$ignoredHandles = $plugin->getSettings()->orderStatusesToIgnore;
		if ($ignoredHandles === []) {
			return false;
		}

		$orderStatus = $order->getOrderStatus();
		if (! $orderStatus instanceof OrderStatus || $orderStatus->handle === null) {
			return false;
		}

		return in_array($orderStatus->handle, $ignoredHandles, true);
	}

	/**
	 * Idempotent upsert. Inserts a row if one doesn't exist; updates `shippable`,
	 * `underAllocated`, and `evaluatedAt` if one does. Never flips `state`, admins own that
	 * via the lightswitch.
	 */
	public function evaluateAndUpsert(Order $order): TrackedOrderRecord
	{
		if ($order->id === null) {
			throw new InvalidArgumentException('Cannot track an order without an id.');
		}

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		$existing = $this->findForOrderId($order->id);
		$shippable = $this->resolveShippable($order);
		$underAllocated = $plugin->shipmentLineItems->isOrderUnderAllocated($order)
			? TrackedOrderUnderAllocated::Yes
			: TrackedOrderUnderAllocated::No;
		$now = new DateTime();

		if ($existing instanceof TrackedOrderRecord) {
			$existing->shippable = $shippable->value;
			$existing->underAllocated = $underAllocated->value;
			$existing->evaluatedAt = $now;
			$existing->save(false);
			return $existing;
		}

		$record = new TrackedOrderRecord();
		$record->orderId = $order->id;
		$record->shippable = $shippable->value;
		$record->underAllocated = $underAllocated->value;
		$record->state = TrackedOrderState::Active->value;
		$record->evaluatedAt = $now;
		$record->trackedAt = $now;
		$record->save(false);

		return $record;
	}

	/**
	 * Recompute and persist the `underAllocated` verdict for the order. Called from
	 * `Shipment::afterSave`, `afterDelete`, and `afterRestore` so the cached column on
	 * `shipments_tracked_orders` stays in sync with the actual allocation pool. No-op if the
	 * order isn't tracked yet; the tracking path will compute the verdict when it inserts.
	 */
	public function recomputeUnderAllocation(Order $order): void
	{
		if ($order->id === null) {
			return;
		}

		$record = $this->findForOrderId($order->id);
		if (! $record instanceof TrackedOrderRecord) {
			return;
		}

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();
		$underAllocated = $plugin->shipmentLineItems->isOrderUnderAllocated($order)
			? TrackedOrderUnderAllocated::Yes
			: TrackedOrderUnderAllocated::No;

		if ($record->underAllocated === $underAllocated->value) {
			return;
		}

		$record->underAllocated = $underAllocated->value;
		$record->save(false);
	}

	/**
	 * Moves an order to `state=active`. If the order hasn't been tracked yet, inserts a new
	 * row via `evaluateAndUpsert`. If the order's current status is in `orderStatusesToIgnore`,
	 * the caller should have stopped earlier; this method trusts the caller to have enforced
	 * that.
	 */
	public function markActive(Order $order): TrackedOrderRecord
	{
		$record = $this->evaluateAndUpsert($order);
		if ($record->state === TrackedOrderState::Active->value) {
			return $record;
		}

		$record->state = TrackedOrderState::Active->value;
		$record->save(false);
		return $record;
	}

	/**
	 * Moves an order to `state=ignored` and trashes every non-trashed shipment on it. Used
	 * both by the "off" lightswitch flip (admin choice) and by the ignored-order-status
	 * event listener. Trashed shipments retain their full history; admins can restore them
	 * via the order tab when toggling shipping back on.
	 *
	 * @return int Number of shipments that were trashed as a result.
	 */
	public function markIgnored(Order $order): int
	{
		if ($order->id === null) {
			throw new InvalidArgumentException('Cannot ignore an order without an id.');
		}

		$existing = $this->findForOrderId($order->id);
		if (! $existing instanceof TrackedOrderRecord) {
			$existing = $this->evaluateAndUpsert($order);
		}

		$existing->state = TrackedOrderState::Ignored->value;
		$existing->save(false);

		return $this->cascadeTrashShipments($order);
	}

	/**
	 * Retroactive sweep: flips every currently-tracked-active order whose current status
	 * is in `$newlyIgnoredStatusHandles` into `state=ignored`, cascade-trashing their
	 * shipments. Called by the settings save handler when the admin adds handles to
	 * `orderStatusesToIgnore`.
	 *
	 * @param list<string> $newlyIgnoredStatusHandles
	 * @return array{ordersAffected: int, shipmentsTrashed: int}
	 */
	public function sweepForNewlyIgnoredStatuses(array $newlyIgnoredStatusHandles): array
	{
		if ($newlyIgnoredStatusHandles === []) {
			return [
				'ordersAffected' => 0,
				'shipmentsTrashed' => 0,
			];
		}

		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();
		$statusIds = [];
		foreach ($commerce->getOrderStatuses()->getAllOrderStatuses() as $orderStatus) {
			if ($orderStatus->handle !== null && in_array($orderStatus->handle, $newlyIgnoredStatusHandles, true)) {
				$statusIds[] = $orderStatus->id;
			}
		}

		$statusIds = array_values(array_filter($statusIds, static fn (mixed $value): bool => is_int($value)));
		if ($statusIds === []) {
			return [
				'ordersAffected' => 0,
				'shipmentsTrashed' => 0,
			];
		}

		/** @var list<int> $orderIds */
		$orderIds = (new Query())
			->select(['[[t.orderId]]'])
			->from([
				't' => Table::TRACKED_ORDERS,
			])
			->innerJoin([
				'o' => CommerceTable::ORDERS,
			], '[[o.id]] = [[t.orderId]]')
			->where([
				'[[t.state]]' => TrackedOrderState::Active->value,
				'[[o.orderStatusId]]' => $statusIds,
			])
			->column();

		$ordersAffected = 0;
		$shipmentsTrashed = 0;

		foreach ($orderIds as $orderId) {
			$order = $commerce->getOrders()->getOrderById((int) $orderId);
			if (! $order instanceof Order) {
				continue;
			}

			$shipmentsTrashed += $this->markIgnored($order);
			$ordersAffected++;
		}

		return [
			'ordersAffected' => $ordersAffected,
			'shipmentsTrashed' => $shipmentsTrashed,
		];
	}

	/**
	 * Restores trashed shipments belonging to the order, in reference order. Each restore
	 * runs the element's allocation guard via `beforeRestore`, so any shipment that would
	 * over-allocate the order's pool is left trashed.
	 *
	 * @return array{restored: int, skipped: int}
	 */
	public function restoreTrashedShipments(Order $order): array
	{
		if ($order->id === null) {
			return [
				'restored' => 0,
				'skipped' => 0,
			];
		}

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();
		$trashed = $plugin->getShipments()->findTrashedByOrderId($order->id);

		$restored = 0;
		$skipped = 0;
		foreach ($trashed as $shipment) {
			try {
				if (Craft::$app->getElements()->restoreElement($shipment)) {
					$restored++;
				} else {
					$skipped++;
				}
			} catch (AllocationOverflowException) {
				$skipped++;
			}
		}

		return [
			'restored' => $restored,
			'skipped' => $skipped,
		];
	}

	/**
	 * Soft-deletes every non-trashed shipment on the order. Trashed elements retain their
	 * row, line items, and history; admins can restore them later. `Shipment::afterDelete`
	 * recomputes the order's allocation so the unallocated pool reflects the change.
	 */
	private function cascadeTrashShipments(Order $order): int
	{
		if ($order->id === null) {
			return 0;
		}

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();
		$shipments = $plugin->getShipments()->findByOrderId($order->id);

		$trashedCount = 0;
		foreach ($shipments as $shipment) {
			if (! Craft::$app->getElements()->deleteElement($shipment)) {
				Craft::warning(
					'Failed to cascade-trash shipment ' . $shipment->id,
					Plugin::HANDLE,
				);
				continue;
			}

			$trashedCount++;
		}

		return $trashedCount;
	}
}
