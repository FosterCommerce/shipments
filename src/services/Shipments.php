<?php

declare(strict_types=1);

namespace fostercommerce\shipments\services;

use Craft;
use craft\commerce\db\Table as CommerceTable;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use DateTime;
use DateTimeInterface;
use DateTimeZone;
use fostercommerce\shipments\db\Table;
use fostercommerce\shipments\elements\db\ShipmentQuery;
use fostercommerce\shipments\elements\Shipment;
use fostercommerce\shipments\enums\FulfillmentStatus;
use fostercommerce\shipments\enums\ShippingStatus;
use fostercommerce\shipments\enums\StatusAxis;
use fostercommerce\shipments\errors\AllocationMismatchException;
use fostercommerce\shipments\errors\AllocationOverflowException;
use fostercommerce\shipments\errors\DuplicateShipmentReferenceException;
use fostercommerce\shipments\errors\InvalidTransitionException;
use fostercommerce\shipments\errors\OrderNotCompletedException;
use fostercommerce\shipments\events\CreateShipmentsEvent;
use fostercommerce\shipments\events\ShipmentLineItemsChangedEvent;
use fostercommerce\shipments\events\ShipmentStatusChangedEvent;
use fostercommerce\shipments\models\Integration;
use fostercommerce\shipments\models\ShipmentExportQuery;
use fostercommerce\shipments\models\ShipmentExportResult;
use fostercommerce\shipments\models\ShipmentPlan;
use fostercommerce\shipments\models\ShipmentStatusHistoryEntry;
use fostercommerce\shipments\models\ShipmentUpdatePayload;
use fostercommerce\shipments\Plugin;
use fostercommerce\shipments\records\ShipmentLineItem as ShipmentLineItemRecord;
use fostercommerce\shipments\records\ShipmentStatusHistory;
use Throwable;
use yii\base\Component;
use yii\base\Exception;
use yii\base\InvalidArgumentException;

/**
 * Shipment lifecycle service.
 *
 * All status changes route through {@see applyTransition} so invariants, history, and events fire once.
 */
class Shipments extends Component
{
	public const EVENT_BEFORE_CREATE_SHIPMENTS = 'beforeCreateShipments';

	public const EVENT_AFTER_CREATE_SHIPMENTS = 'afterCreateShipments';

	public const EVENT_SHIPMENT_STATUS_CHANGED = 'shipmentStatusChanged';

	public const EVENT_SHIPMENT_LINE_ITEMS_CHANGED = 'shipmentLineItemsChanged';

	/**
	 * Seconds to wait when acquiring the per-shipment transition lock.
	 */
	private const TRANSITION_LOCK_TIMEOUT = 10;

	/**
	 * Seconds to wait when acquiring the per-order staging lock.
	 */
	private const STAGING_LOCK_TIMEOUT = 10;

	/**
	 * Seconds to wait when acquiring the per-order create lock.
	 */
	private const CREATE_LOCK_TIMEOUT = 10;

	public function findById(int $shipmentId, bool $includeTrashed = false): ?Shipment
	{
		/** @var ShipmentQuery $query */
		$query = Shipment::find();
		$query->id($shipmentId);

		if ($includeTrashed) {
			$query->trashed(null);
		}

		/** @var Shipment|null $shipment */
		$shipment = $query->one();
		return $shipment;
	}

	/**
	 * Returns non-trashed shipments for the order, ordered by reference number.
	 *
	 * @return list<Shipment>
	 */
	public function findByOrderId(int $orderId): array
	{
		/** @var ShipmentQuery $query */
		$query = Shipment::find();
		$query->orderId($orderId)->status(null)->orderBy([
			'number' => SORT_ASC,
		]);

		/** @var list<Shipment> $shipments */
		$shipments = $query->all();
		return $shipments;
	}

	/**
	 * Returns trashed shipments for the order, ordered by reference number.
	 *
	 * @return list<Shipment>
	 */
	public function findTrashedByOrderId(int $orderId): array
	{
		/** @var ShipmentQuery $query */
		$query = Shipment::find();
		$query->orderId($orderId)->status(null)->trashed(true)->orderBy([
			'number' => SORT_ASC,
		]);

		/** @var list<Shipment> $shipments */
		$shipments = $query->all();
		return $shipments;
	}

	public function findOneByReference(string $reference, bool $includeTrashed = false): ?Shipment
	{
		/** @var ShipmentQuery $query */
		$query = Shipment::find();
		$query->reference($reference);

		if ($includeTrashed) {
			$query->trashed(null);
		}

		/** @var Shipment|null $shipment */
		$shipment = $query->one();
		return $shipment;
	}

	public function loadOrder(?int $orderId): ?Order
	{
		if ($orderId === null) {
			return null;
		}

		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();
		return $commerce->getOrders()->getOrderById($orderId);
	}

	/**
	 * Returns a shipment's status-change history, newest first.
	 *
	 * @return list<ShipmentStatusHistoryEntry>
	 */
	public function getStatusHistoryForShipmentId(int $shipmentId): array
	{
		/** @var list<array<string, mixed>> $rows */
		$rows = (new Query())
			->from(Table::SHIPMENT_STATUS_HISTORY)
			->where([
				'shipmentId' => $shipmentId,
			])
			->orderBy([
				'dateCreated' => SORT_DESC,
				'id' => SORT_DESC,
			])
			->all();

		if ($rows === []) {
			return [];
		}

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		// Batch-fetch users + integrations referenced across all rows so we don't
		// issue one lookup per history entry.
		$userIds = [];
		$integrationIds = [];
		foreach ($rows as $row) {
			if (is_numeric($row['userId'] ?? null)) {
				$userIds[(int) $row['userId']] = true;
			}

			if (is_numeric($row['sourceIntegrationId'] ?? null)) {
				$integrationIds[(int) $row['sourceIntegrationId']] = true;
			}
		}

		$usersById = [];
		if ($userIds !== []) {
			$userIdList = array_keys($userIds);
			$users = User::find()->id($userIdList)->status(null)->all();
			foreach ($users as $user) {
				if ($user->id !== null) {
					$usersById[$user->id] = $user;
				}
			}
		}

		$integrationsById = [];
		foreach (array_keys($integrationIds) as $integrationId) {
			$integration = $plugin->integrations->getIntegrationById($integrationId);
			if ($integration !== null) {
				$integrationsById[$integrationId] = $integration;
			}
		}

		$entries = [];
		foreach ($rows as $row) {
			$axisRaw = $row['axis'] ?? null;
			if (! is_string($axisRaw)) {
				continue;
			}

			$axis = StatusAxis::tryFrom($axisRaw);
			if (! $axis instanceof StatusAxis) {
				continue;
			}

			$toCodeRaw = $row['toCode'] ?? null;
			$toCode = is_string($toCodeRaw) ? $axis->resolveCode($toCodeRaw) : null;

			$fromCodeRaw = $row['fromCode'] ?? null;
			$fromCode = is_string($fromCodeRaw) && $fromCodeRaw !== '' ? $axis->resolveCode($fromCodeRaw) : null;

			$user = null;
			if (is_numeric($row['userId'] ?? null)) {
				$user = $usersById[(int) $row['userId']] ?? null;
			}

			$dateCreatedRaw = $row['dateCreated'] ?? null;
			$dateCreated = null;
			if (is_string($dateCreatedRaw) || is_int($dateCreatedRaw) || is_array($dateCreatedRaw) || $dateCreatedRaw instanceof DateTimeInterface) {
				$dateCreated = DateTimeHelper::toDateTime($dateCreatedRaw) ?: null;
			}

			$messageRaw = $row['message'] ?? null;
			$message = is_scalar($messageRaw) ? (string) $messageRaw : null;

			$sourceIntegration = null;
			if (is_numeric($row['sourceIntegrationId'] ?? null)) {
				$sourceIntegration = $integrationsById[(int) $row['sourceIntegrationId']] ?? null;
			}

			$sourceExternalCodeRaw = $row['sourceExternalCode'] ?? null;
			$sourceExternalCode = is_string($sourceExternalCodeRaw) && $sourceExternalCodeRaw !== '' ? $sourceExternalCodeRaw : null;

			$entries[] = new ShipmentStatusHistoryEntry(
				axis: $axis,
				fromCode: $fromCode,
				toCode: $toCode,
				user: $user,
				date: $dateCreated,
				message: $message,
				sourceIntegration: $sourceIntegration,
				sourceExternalCode: $sourceExternalCode,
			);
		}

		return $entries;
	}

	/**
	 * Returns a paginated page of non-trashed shipments within the query's `dateUpdated` range.
	 */
	public function findForExport(ShipmentExportQuery $exportQuery): ShipmentExportResult
	{
		$pageSize = max(1, min($exportQuery->pageSize, ShipmentExportQuery::MAX_PAGE_SIZE));
		$page = max(1, $exportQuery->page);

		/** @var ShipmentQuery $query */
		$query = Shipment::find();
		$query->orderBy([
			'[[elements.dateUpdated]]' => SORT_ASC,
			'[[elements.id]]' => SORT_ASC,
		]);

		// `dateUpdated` is stored UTC; normalize bounds to UTC so a caller passing a non-UTC
		// offset (e.g. `2026-04-25T00:00:00-05:00`) doesn't shift the window by their offset.
		$utc = new DateTimeZone('UTC');

		if ($exportQuery->startDate instanceof DateTime) {
			$startUtc = (clone $exportQuery->startDate)->setTimezone($utc);
			$query->dateUpdated('>=' . $startUtc->format('Y-m-d H:i:s'));
		}

		if ($exportQuery->endDate instanceof DateTime) {
			$endUtc = (clone $exportQuery->endDate)->setTimezone($utc);
			$query->andWhere(['<=', '[[elements.dateUpdated]]', $endUtc->format('Y-m-d H:i:s')]);
		}

		if ($exportQuery->statusHandle !== null && $exportQuery->statusHandle !== '') {
			$query->fulfillmentStatus($exportQuery->statusHandle);
		}

		if ($exportQuery->storeId !== null) {
			$orderIdsInStore = (new Query())
				->select(['id'])
				->from(CommerceTable::ORDERS)
				->where([
					'storeId' => $exportQuery->storeId,
				]);
			$query->orderId($orderIdsInStore);
		}

		$total = (int) (clone $query)->count();
		$pageCount = $total > 0 ? (int) ceil($total / $pageSize) : 0;

		if ($pageCount > 0) {
			$page = min($page, $pageCount);
		}

		/** @var list<Shipment> $shipments */
		$shipments = (clone $query)
			->limit($pageSize)
			->offset(($page - 1) * $pageSize)
			->all();

		$result = new ShipmentExportResult();
		$result->shipments = $shipments;
		$result->page = $page;
		$result->pageCount = $pageCount;
		$result->total = $total;
		$result->pageSize = $pageSize;
		return $result;
	}

	/**
	 * Creates shipments for a completed order from its shipment plan.
	 *
	 * Idempotent and serialized per-order: skips silently if the order already has non-trashed shipments.
	 *
	 * @return list<Shipment>
	 * @throws Throwable
	 */
	public function createFor(Order $order): array
	{
		if ($order->id === null) {
			return [];
		}

		if (! $order->isCompleted) {
			Craft::warning(
				"Shipments::createFor skipped for order {$order->id}: order is not completed.",
				Plugin::HANDLE,
			);
			return [];
		}

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		if ($plugin->getTrackedOrders()->isOrderStatusIgnored($order)) {
			Craft::info(
				"Shipments::createFor skipped for order {$order->id}: order status is in the plugin's ignore list.",
				Plugin::HANDLE,
			);
			return [];
		}

		// Per-order lock so concurrent completion fires on the same order don't both
		// pass the existence check and both proceed to create.
		$mutex = Craft::$app->getMutex();
		$lockName = 'shipments:order:' . $order->id . ':create';
		if (! $mutex->acquire($lockName, self::CREATE_LOCK_TIMEOUT)) {
			throw new Exception("Could not acquire create lock for order {$order->id}.");
		}

		try {
			$existing = $this->findByOrderId($order->id);
			if ($existing !== []) {
				return $existing;
			}

			$plugin->getTrackedOrders()->evaluateAndUpsert($order);

			$plans = $plugin->rules->planFor($order);

			$beforeEvent = new CreateShipmentsEvent();
			$beforeEvent->order = $order;
			$beforeEvent->plans = $plans;
			$this->trigger(self::EVENT_BEFORE_CREATE_SHIPMENTS, $beforeEvent);

			$finalPlans = array_values($beforeEvent->plans);
			if ($finalPlans === []) {
				return [];
			}

			$saved = $this->persistPlans($order, $finalPlans);
			if ($saved !== []) {
				$plugin->getTrackedOrders()->markActive($order);
			}

			$afterEvent = new CreateShipmentsEvent();
			$afterEvent->order = $order;
			$afterEvent->plans = $finalPlans;
			$afterEvent->shipments = $saved;
			$this->trigger(self::EVENT_AFTER_CREATE_SHIPMENTS, $afterEvent);

			return $saved;
		} finally {
			$mutex->release($lockName);
		}
	}

	/**
	 * Creates shipments from staging-group POST rows.
	 *
	 * Submitted totals must exactly match the order's remaining pool.
	 *
	 * @param list<array<int, int>> $postedAllocations
	 * @return list<Shipment>
	 * @throws AllocationMismatchException
	 * @throws OrderNotCompletedException
	 * @throws Throwable
	 */
	public function createFromStagingPost(Order $order, array $postedAllocations): array
	{
		if ($order->id === null) {
			return [];
		}

		if (! $order->isCompleted) {
			throw new OrderNotCompletedException($order->id);
		}

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		$submittedTotals = [];
		$sanitizedAllocations = [];
		foreach ($postedAllocations as $groupAllocation) {
			$cleanGroup = [];
			foreach ($groupAllocation as $lineItemId => $qty) {
				$qty = (int) $qty;
				if ($qty <= 0) {
					continue;
				}

				$lineItemId = (int) $lineItemId;
				$cleanGroup[$lineItemId] = ($cleanGroup[$lineItemId] ?? 0) + $qty;
				$submittedTotals[$lineItemId] = ($submittedTotals[$lineItemId] ?? 0) + $qty;
			}

			if ($cleanGroup !== []) {
				$sanitizedAllocations[] = $cleanGroup;
			}
		}

		// Per-order lock so concurrent staging submits serialize.
		$mutex = Craft::$app->getMutex();
		$lockName = 'shipments:order:' . $order->id . ':staging';
		if (! $mutex->acquire($lockName, self::STAGING_LOCK_TIMEOUT)) {
			throw new Exception(Craft::t(Plugin::HANDLE, 'error.shipmentSaveInProgress', [
				'orderId' => $order->id,
			]));
		}

		try {
			$pool = $plugin->shipmentLineItems->remainingPoolFor($order);

			$mismatches = [];
			$submittedOutsidePool = false;
			foreach ($pool as $lineItemId => $requiredQty) {
				$submittedQty = $submittedTotals[$lineItemId] ?? 0;
				if ($submittedQty !== $requiredQty) {
					$mismatches[$lineItemId] = [
						'required' => $requiredQty,
						'submitted' => $submittedQty,
					];
				}
			}

			foreach ($submittedTotals as $lineItemId => $submittedQty) {
				if (! array_key_exists($lineItemId, $pool)) {
					$mismatches[$lineItemId] = [
						'required' => 0,
						'submitted' => $submittedQty,
					];
					$submittedOutsidePool = true;
				}
			}

			if ($mismatches !== []) {
				throw new AllocationMismatchException($order->id, $mismatches, $submittedOutsidePool);
			}

			$plugin->getTrackedOrders()->evaluateAndUpsert($order);

			return $this->createFromAllocations($order, $sanitizedAllocations);
		} finally {
			$mutex->release($lockName);
		}
	}

	/**
	 * Creates shipments directly from allocations, skipping the pool check.
	 *
	 * CP/API callers should use {@see createFromStagingPost} instead.
	 *
	 * @param list<array<int, int>> $allocations
	 * @return list<Shipment>
	 * @throws Throwable
	 */
	public function createFromAllocations(Order $order, array $allocations): array
	{
		$plans = [];
		foreach ($allocations as $lineItemQtys) {
			$filtered = [];
			foreach ($lineItemQtys as $lineItemId => $qty) {
				$qty = (int) $qty;
				if ($qty <= 0) {
					continue;
				}

				$filtered[(int) $lineItemId] = $qty;
			}

			if ($filtered === []) {
				continue;
			}

			$plan = new ShipmentPlan();
			$plan->ruleHandle = 'manual';
			$plan->lineItemQtys = $filtered;
			$plans[] = $plan;
		}

		if ($plans === []) {
			return [];
		}

		return $this->persistPlans($order, $plans);
	}

	/**
	 * Saves fulfillment fields (tracking, carrier, etc.) on a shipment.
	 *
	 * Status changes go through {@see applyTransition}, not this.
	 *
	 * @throws Throwable
	 */
	public function saveManual(Shipment $shipment, Order $order): Shipment
	{
		if ($shipment->id === null) {
			throw new InvalidArgumentException('Cannot save a shipment without an id.');
		}

		if (! Craft::$app->getElements()->saveElement($shipment)) {
			$errors = $shipment->getFirstErrors();
			throw new Exception(Craft::t(Plugin::HANDLE, 'error.couldNotSaveShipment', [
				'errors' => implode(', ', $errors),
			]));
		}

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		if ($plugin->getSettings()->enforceCoverage) {
			$plugin->shipmentLineItems->assertFullCoverage($order);
		}

		$updated = $this->findById($shipment->id, includeTrashed: true);
		if (! $updated instanceof Shipment) {
			throw new Exception('Lost track of shipment after save.');
		}

		return $updated;
	}

	/**
	 * Replaces a shipment's line-item allocation in place (omitting a line item drops it).
	 *
	 * Gates on {@see ShipmentLineItems::overflowForProposedAllocation} so the order can never be
	 * over-allocated. Does not assert full coverage: a split leaves the order transiently
	 * under-allocated until the replacement shipment is created.
	 *
	 * @param array<int, int> $lineItemQtys lineItemId => qty
	 * @throws AllocationOverflowException
	 * @throws Throwable
	 */
	public function saveLineItems(Shipment $shipment, array $lineItemQtys, ?User $user = null): Shipment
	{
		if ($shipment->id === null) {
			throw new InvalidArgumentException('Cannot save line items for an unsaved shipment.');
		}

		$shipmentId = $shipment->id;

		$order = $this->loadOrder($shipment->orderId);
		if (! $order instanceof Order || $order->id === null) {
			throw new Exception("Order {$shipment->orderId} for shipment {$shipmentId} not found.");
		}

		$desired = [];
		foreach ($lineItemQtys as $lineItemId => $qty) {
			$lineItemId = (int) $lineItemId;
			$qty = (int) $qty;
			if ($qty <= 0) {
				continue;
			}

			$desired[$lineItemId] = ($desired[$lineItemId] ?? 0) + $qty;
		}

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		// Share the per-order allocation lock with createFromStagingPost: an edit and a staging
		// create both move the same order's pool, so a per-shipment lock would let them pass
		// their overflow checks concurrently and over-allocate the order.
		$mutex = Craft::$app->getMutex();
		$lockName = 'shipments:order:' . $order->id . ':staging';
		if (! $mutex->acquire($lockName, self::STAGING_LOCK_TIMEOUT)) {
			throw new Exception(Craft::t(Plugin::HANDLE, 'error.shipmentUpdateInProgress', [
				'shipmentId' => $shipmentId,
			]));
		}

		try {
			$overflow = $plugin->shipmentLineItems->overflowForProposedAllocation($shipmentId, $order, $desired);
			if ($overflow !== []) {
				throw new AllocationOverflowException($shipmentId, $order->id, $overflow);
			}

			$previousQtys = [];
			foreach ($plugin->shipmentLineItems->findForShipmentId($shipmentId) as $existingLineItem) {
				$previousQtys[(int) $existingLineItem->lineItemId] = (int) $existingLineItem->qty;
			}

			$transaction = Craft::$app->getDb()->beginTransaction();

			try {
				$existingRecords = ShipmentLineItemRecord::findAll([
					'shipmentId' => $shipmentId,
				]);
				$existingByLineItemId = [];
				foreach ($existingRecords as $existingRecord) {
					$existingByLineItemId[(int) $existingRecord->lineItemId] = $existingRecord;
				}

				foreach ($desired as $lineItemId => $qty) {
					$record = $existingByLineItemId[$lineItemId] ?? null;
					if (! $record instanceof ShipmentLineItemRecord) {
						$record = new ShipmentLineItemRecord();
						$record->shipmentId = $shipmentId;
						$record->lineItemId = $lineItemId;
					}

					$record->qty = $qty;
					if (! $record->save()) {
						$errors = $record->getFirstErrors();
						throw new Exception(Craft::t(Plugin::HANDLE, 'error.couldNotSaveShipmentLineItem', [
							'errors' => implode(', ', $errors),
						]));
					}
				}

				foreach ($existingByLineItemId as $lineItemId => $existingRecord) {
					if (! array_key_exists($lineItemId, $desired)) {
						$existingRecord->delete();
					}
				}

				// Refresh the element's cached line items (the same DB connection sees the
				// uncommitted writes) so listeners reading `$event->shipment->getLineItems()`
				// observe the new allocation.
				$shipment->setLineItems($plugin->shipmentLineItems->findForShipmentId($shipmentId));

				$event = new ShipmentLineItemsChangedEvent();
				$event->shipment = $shipment;
				$event->order = $order;
				$event->previousQtys = $previousQtys;
				$event->newQtys = $desired;
				$event->user = $user;
				$this->trigger(self::EVENT_SHIPMENT_LINE_ITEMS_CHANGED, $event);

				// Line items are written directly (not through the element's save cycle), so the
				// tracked-order allocation projection that `Shipment::afterSave` normally refreshes
				// has to be recomputed here, inside the transaction, so it commits atomically.
				$plugin->getTrackedOrders()->recomputeUnderAllocation($order);

				$transaction->commit();
			} catch (Throwable $throwable) {
				$transaction->rollBack();
				throw $throwable;
			}
		} finally {
			$mutex->release($lockName);
		}

		$updated = $this->findById($shipmentId, includeTrashed: true);
		if (! $updated instanceof Shipment) {
			throw new Exception('Lost track of shipment after saving line items.');
		}

		return $updated;
	}

	/**
	 * Applies a parsed remote update: fulfillment fields plus optional axis transitions.
	 *
	 * Idempotent; runs in a single transaction.
	 *
	 * @throws Throwable
	 */
	public function applyUpdate(
		Shipment $shipment,
		ShipmentUpdatePayload $payload,
		?User $user = null,
		?Integration $source = null,
		?string $externalCode = null,
	): Shipment {
		if ($shipment->id === null) {
			throw new InvalidArgumentException('Cannot apply an update to an unsaved shipment.');
		}

		$order = $this->loadOrder($shipment->orderId);
		if (! $order instanceof Order) {
			throw new Exception("Order {$shipment->orderId} for shipment {$shipment->id} not found.");
		}

		$fulfillmentDirty = false;

		if ($payload->trackingNumber !== null && $shipment->trackingNumber !== $payload->trackingNumber) {
			$shipment->trackingNumber = $payload->trackingNumber;
			$fulfillmentDirty = true;
		}

		if ($payload->trackingUrl !== null && $shipment->trackingUrl !== $payload->trackingUrl) {
			$shipment->trackingUrl = $payload->trackingUrl;
			$fulfillmentDirty = true;
		}

		if ($payload->carrier !== null && $shipment->carrier !== $payload->carrier) {
			$shipment->carrier = $payload->carrier;
			$fulfillmentDirty = true;
		}

		if ($payload->service !== null && $shipment->service !== $payload->service) {
			$shipment->service = $payload->service;
			$fulfillmentDirty = true;
		}

		if ($payload->fulfillmentNotes !== null && $shipment->fulfillmentNotes !== $payload->fulfillmentNotes) {
			$shipment->fulfillmentNotes = $payload->fulfillmentNotes;
			$fulfillmentDirty = true;
		}

		if ($payload->shippingNotes !== null && $shipment->shippingNotes !== $payload->shippingNotes) {
			$shipment->shippingNotes = $payload->shippingNotes;
			$fulfillmentDirty = true;
		}

		if (is_array($payload->fields) && $payload->fields !== []) {
			$shipment->setFieldValues($payload->fields);
			$fulfillmentDirty = true;
		}

		if ($payload->dateScheduledShip instanceof DateTime) {
			$existingTimestamp = $shipment->dateScheduledShip instanceof DateTime ? $shipment->dateScheduledShip->getTimestamp() : null;
			$incomingTimestamp = $payload->dateScheduledShip->getTimestamp();
			if ($existingTimestamp !== $incomingTimestamp) {
				$shipment->dateScheduledShip = $payload->dateScheduledShip;
				$fulfillmentDirty = true;
			}
		}

		$targetFulfillment = null;
		if ($payload->targetFulfillmentCode !== null && $payload->targetFulfillmentCode !== '') {
			$targetFulfillment = FulfillmentStatus::tryFrom($payload->targetFulfillmentCode);
			if (! $targetFulfillment instanceof FulfillmentStatus) {
				Craft::warning(
					"Shipments::applyUpdate: unknown fulfillment code “{$payload->targetFulfillmentCode}”; ignored.",
					Plugin::HANDLE,
				);
			}
		}

		$targetShipping = null;
		if ($payload->targetShippingCode !== null && $payload->targetShippingCode !== '') {
			$targetShipping = ShippingStatus::tryFrom($payload->targetShippingCode);
			if (! $targetShipping instanceof ShippingStatus) {
				Craft::warning(
					"Shipments::applyUpdate: unknown shipping code “{$payload->targetShippingCode}”; ignored.",
					Plugin::HANDLE,
				);
			}
		}

		$outerTransaction = Craft::$app->getDb()->beginTransaction();

		try {
			if ($fulfillmentDirty) {
				$shipment = $this->saveManual($shipment, $order);
			}

			if ($targetFulfillment instanceof FulfillmentStatus && $shipment->fulfillmentStatus !== $targetFulfillment->value) {
				$transitioned = $this->applyTransition($shipment, StatusAxis::Fulfillment, $targetFulfillment, $user, $payload->fulfillmentStatusMessage, $source, $externalCode);
				if ($transitioned instanceof Shipment) {
					$shipment = $transitioned;
				}
			}

			if ($targetShipping instanceof ShippingStatus && $shipment->shippingStatus !== $targetShipping->value) {
				$transitioned = $this->applyTransition($shipment, StatusAxis::Shipping, $targetShipping, $user, $payload->shippingStatusMessage, $source, $externalCode);
				if ($transitioned instanceof Shipment) {
					$shipment = $transitioned;
				}
			}

			$outerTransaction->commit();
		} catch (Throwable $throwable) {
			$outerTransaction->rollBack();
			throw $throwable;
		}

		return $shipment;
	}

	/**
	 * Applies an axis transition: writes the new code, records history, and fires
	 * {@see EVENT_SHIPMENT_STATUS_CHANGED}.
	 *
	 * Single code path for CP edits, REST API calls, and webhook ingestors.
	 *
	 * @throws InvalidTransitionException
	 * @throws Throwable
	 */
	public function applyTransition(
		Shipment $shipment,
		StatusAxis $axis,
		FulfillmentStatus|ShippingStatus $to,
		?User $user = null,
		?string $message = null,
		?Integration $source = null,
		?string $externalCode = null,
		FulfillmentStatus|ShippingStatus|null $expectedFromCode = null,
	): ?Shipment {
		if ($shipment->id === null) {
			return null;
		}

		if ($axis === StatusAxis::Fulfillment && ! $to instanceof FulfillmentStatus) {
			throw new InvalidArgumentException('Fulfillment axis requires a FulfillmentStatus case.');
		}

		if ($axis === StatusAxis::Shipping && ! $to instanceof ShippingStatus) {
			throw new InvalidArgumentException('Shipping axis requires a ShippingStatus case.');
		}

		// Per-shipment lock so concurrent transitions can't race the same row.
		$mutex = Craft::$app->getMutex();
		$lockName = 'shipments:shipment:' . $shipment->id . ':transition';
		if (! $mutex->acquire($lockName, self::TRANSITION_LOCK_TIMEOUT)) {
			throw new Exception(Craft::t(Plugin::HANDLE, 'error.shipmentUpdateInProgress', [
				'shipmentId' => $shipment->id,
			]));
		}

		try {
			// Re-read under the lock so optimistic checks use the canonical state.
			$current = $this->findById($shipment->id, includeTrashed: true);
			if (! $current instanceof Shipment || $current->id === null) {
				return null;
			}

			$shipment = $current;
			$shipmentId = $current->id;

			$fromCode = match ($axis) {
				StatusAxis::Fulfillment => FulfillmentStatus::tryFrom($shipment->fulfillmentStatus),
				StatusAxis::Shipping => $shipment->shippingStatus !== null ? ShippingStatus::tryFrom($shipment->shippingStatus) : null,
			};

			if ($expectedFromCode !== null && $fromCode?->value !== $expectedFromCode->value) {
				throw new InvalidTransitionException(
					$shipmentId,
					$axis,
					$to,
					Craft::t(Plugin::HANDLE, 'error.expectedAxisMismatch', [
						'axis' => $axis->value,
						'expected' => $expectedFromCode->value,
						'actual' => $fromCode?->value ?? 'null',
					]),
				);
			}

			$transaction = Craft::$app->getDb()->beginTransaction();

			try {
				if ($axis === StatusAxis::Fulfillment && $to instanceof FulfillmentStatus) {
					$shipment->fulfillmentStatus = $to->value;
				}

				if ($axis === StatusAxis::Shipping && $to instanceof ShippingStatus) {
					$shipment->shippingStatus = $to->value;
					$shipment->dateShippingStatus = new DateTime();
				}

				if (! Craft::$app->getElements()->saveElement($shipment)) {
					$errors = $shipment->getFirstErrors();
					throw new Exception(Craft::t(Plugin::HANDLE, 'error.couldNotApplyTransition', [
						'errors' => implode(', ', $errors),
					]));
				}

				$history = new ShipmentStatusHistory();
				$history->shipmentId = $shipmentId;
				$history->axis = $axis->value;
				$history->fromCode = $fromCode?->value;
				$history->toCode = $to->value;
				$history->message = $message;
				$history->userId = $user?->id;
				$history->sourceIntegrationId = $source?->id;
				$history->sourceExternalCode = $externalCode;
				if (! $history->save()) {
					$errors = $history->getFirstErrors();
					throw new Exception(Craft::t(Plugin::HANDLE, 'error.couldNotSaveShipmentStatusHistory', [
						'errors' => implode(', ', $errors),
					]));
				}

				// Fire inside the transaction so listener queue pushes commit atomically
				// with the status write.
				$event = new ShipmentStatusChangedEvent();
				$event->shipment = $shipment;
				$event->axis = $axis;
				$event->fromCode = $fromCode;
				$event->toCode = $to;
				$event->history = $history;
				$event->user = $user;
				$event->message = $message;
				$event->sourceIntegration = $source;
				$event->sourceExternalCode = $externalCode;
				$this->trigger(self::EVENT_SHIPMENT_STATUS_CHANGED, $event);

				$transaction->commit();
			} catch (Throwable $throwable) {
				$transaction->rollBack();
				throw $throwable;
			}

			return $this->findById($shipmentId, includeTrashed: true);
		} finally {
			$mutex->release($lockName);
		}
	}

	/**
	 * Soft-deletes a shipment. Returns false if the shipment doesn't exist.
	 */
	public function softDeleteById(int $shipmentId): bool
	{
		$shipment = $this->findById($shipmentId);
		if (! $shipment instanceof Shipment) {
			return false;
		}

		return Craft::$app->getElements()->deleteElement($shipment);
	}

	/**
	 * @param list<ShipmentPlan> $plans
	 * @return list<Shipment>
	 * @throws Throwable
	 */
	private function persistPlans(Order $order, array $plans): array
	{
		$orderId = $order->id;
		if ($orderId === null) {
			return [];
		}

		$currentUser = Craft::$app->getUser()->getIdentity();
		$transaction = Craft::$app->getDb()->beginTransaction();

		try {
			$saved = [];
			foreach ($plans as $plan) {
				$fulfillmentStatus = $plan->suggestedStatusHandle !== null
					? FulfillmentStatus::from($plan->suggestedStatusHandle)
					: FulfillmentStatus::Open;

				$shipment = $this->persistSinglePlanWithReferenceRetry($orderId, $fulfillmentStatus);

				$creationHistory = new ShipmentStatusHistory();
				$creationHistory->shipmentId = (int) $shipment->id;
				$creationHistory->axis = StatusAxis::Fulfillment->value;
				$creationHistory->fromCode = null;
				$creationHistory->toCode = $fulfillmentStatus->value;
				$creationHistory->userId = $currentUser?->id;
				if (! $creationHistory->save()) {
					$errors = $creationHistory->getFirstErrors();
					throw new Exception(Craft::t(Plugin::HANDLE, 'error.couldNotSaveShipmentCreationHistory', [
						'errors' => implode(', ', $errors),
					]));
				}

				foreach ($plan->lineItemQtys as $lineItemId => $qty) {
					if ($qty <= 0) {
						continue;
					}

					$lineItemRecord = new ShipmentLineItemRecord();
					$lineItemRecord->shipmentId = (int) $shipment->id;
					$lineItemRecord->lineItemId = $lineItemId;
					$lineItemRecord->qty = $qty;
					if (! $lineItemRecord->save()) {
						$errors = $lineItemRecord->getFirstErrors();
						throw new Exception(Craft::t(Plugin::HANDLE, 'error.couldNotSaveShipmentLineItem', [
							'errors' => implode(', ', $errors),
						]));
					}
				}

				$statusChangedEvent = new ShipmentStatusChangedEvent();
				$statusChangedEvent->shipment = $shipment;
				$statusChangedEvent->axis = StatusAxis::Fulfillment;
				$statusChangedEvent->fromCode = null;
				$statusChangedEvent->toCode = $fulfillmentStatus;
				$statusChangedEvent->history = $creationHistory;
				$statusChangedEvent->user = $currentUser;
				$this->trigger(self::EVENT_SHIPMENT_STATUS_CHANGED, $statusChangedEvent);

				$saved[] = $shipment;
			}

			$transaction->commit();
		} catch (Throwable $throwable) {
			$transaction->rollBack();
			throw $throwable;
		}

		return $saved;
	}

	/**
	 * Saves one shipment, retrying reference allocation on collision.
	 *
	 * @throws DuplicateShipmentReferenceException if the retries also collide
	 */
	private function persistSinglePlanWithReferenceRetry(int $orderId, FulfillmentStatus $fulfillmentStatus): Shipment
	{
		$attempts = 0;
		while (true) {
			$attempts++;
			$shipment = new Shipment();
			$shipment->orderId = $orderId;
			$shipment->fulfillmentStatus = $fulfillmentStatus->value;

			try {
				if (! Craft::$app->getElements()->saveElement($shipment)) {
					$errors = $shipment->getFirstErrors();
					throw new Exception(Craft::t(Plugin::HANDLE, 'error.couldNotSaveShipment', [
						'errors' => implode(', ', $errors),
					]));
				}

				return $shipment;
			} catch (DuplicateShipmentReferenceException $duplicate) {
				if ($attempts >= 3) {
					throw $duplicate;
				}
			}
		}
	}
}
