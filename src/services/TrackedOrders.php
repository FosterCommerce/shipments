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
use fostercommerce\shipments\elements\Shipment;
use fostercommerce\shipments\enums\ShippingStatus;
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
	 * `Shipment::afterSave`, `afterDelete`, and `afterRestore`, and from `Order::afterSave`, so
	 * the cached column on `shipments_tracked_orders` stays in sync with the actual allocation
	 * pool. Skips silently if the order isn't tracked yet; the tracking path computes the verdict
	 * when it inserts. Invalidates the Attention-needed badge cache whenever the verdict flips.
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

		// The verdict flipped, so the cached badge total is now wrong. Every caller of this
		// method (shipment save/delete/restore, order save) funnels through here, so a single
		// invalidation point keeps the Attention-needed badge in sync.
		$plugin->shipmentLineItems->invalidateAttentionCount();
	}

	/**
	 * Once every enabled shipment is shipped, move the order to the configured status. One-way:
	 * `orderStatusAdvancedAt` stamps it so manual changes afterwards are left alone.
	 */
	public function advanceOrderStatusIfAllShipped(Order $order): void
	{
		if ($order->id === null) {
			return;
		}

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();
		$targetHandle = $plugin->getSettings()->autoAdvanceOrderStatusHandle;
		if ($targetHandle === null || $targetHandle === '') {
			return;
		}

		$record = $this->findForOrderId($order->id);
		if (! $record instanceof TrackedOrderRecord || $record->orderStatusAdvancedAt !== null) {
			return;
		}

		$enabledShipments = array_filter(
			$plugin->getShipments()->findByOrderId($order->id),
			static fn (Shipment $shipment): bool => $shipment->enabled,
		);
		if ($enabledShipments === []) {
			return;
		}

		foreach ($enabledShipments as $shipment) {
			$shippingStatus = $shipment->getShippingStatusEnum();
			if (! $shippingStatus instanceof ShippingStatus || ! $shippingStatus->advancesOrder()) {
				return;
			}
		}

		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();
		$targetStatus = $commerce->getOrderStatuses()->getOrderStatusByHandle($targetHandle);
		if (! $targetStatus instanceof OrderStatus) {
			Craft::warning(
				"Auto order-status target “{$targetHandle}” no longer exists; skipping advance for order {$order->id}.",
				Plugin::HANDLE,
			);
			return;
		}

		// Already there (admin set it manually, or advanced before the stamp existed); just stamp.
		if ($order->orderStatusId === $targetStatus->id) {
			$record->orderStatusAdvancedAt = new DateTime();
			$record->save(false);
			return;
		}

		$order->orderStatusId = $targetStatus->id;

		// Swallow a bad save so it logs without failing the job; a validation error won't pass on retry.
		try {
			if (! Craft::$app->getElements()->saveElement($order)) {
				Craft::warning(
					"Auto-advance for order {$order->id} to “{$targetHandle}” failed validation: " . implode(', ', $order->getFirstErrors()),
					Plugin::HANDLE,
				);
				return;
			}

			$record->orderStatusAdvancedAt = new DateTime();
			$record->save(false);
		} catch (Throwable $throwable) {
			Craft::warning(
				"Auto-advance for order {$order->id} to “{$targetHandle}” errored: " . $throwable->getMessage(),
				Plugin::HANDLE,
			);
		}
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
	 * Takes the order out of the plugin's active fulfillment scope.
	 */
	public function markIgnored(Order $order): void
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
	}

	/**
	 * Catches up orders already sitting in a status the admin just added to the ignore list,
	 * so they leave fulfillment scope now instead of waiting for their next status change.
	 *
	 * @param list<string> $newlyIgnoredStatusHandles
	 * @return int Number of orders moved to ignored.
	 */
	public function sweepForNewlyIgnoredStatuses(array $newlyIgnoredStatusHandles): int
	{
		if ($newlyIgnoredStatusHandles === []) {
			return 0;
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
			return 0;
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

		foreach ($orderIds as $orderId) {
			$order = $commerce->getOrders()->getOrderById((int) $orderId);
			if (! $order instanceof Order) {
				continue;
			}

			$this->markIgnored($order);
			$ordersAffected++;
		}

		return $ordersAffected;
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
}
