<?php

declare(strict_types=1);

namespace fostercommerce\shipments\controllers;

use Craft;
use craft\helpers\DateTimeHelper;
use craft\helpers\Json;
use craft\web\Controller;
use DateTimeInterface;
use fostercommerce\shipments\base\ControllerBodyParamsTrait;
use fostercommerce\shipments\elements\Shipment;
use fostercommerce\shipments\enums\Status;
use fostercommerce\shipments\errors\AllocationMismatchException;
use fostercommerce\shipments\errors\AllocationOverflowException;
use fostercommerce\shipments\errors\OrderNotCompletedException;
use fostercommerce\shipments\models\Integration;
use fostercommerce\shipments\Plugin;
use fostercommerce\shipments\queue\jobs\PushShipmentJob;
use fostercommerce\shipments\web\assets\cp\ShipmentsCpAsset;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * CP actions for the shipment edit page + the order-tab create/remove/rebuild flow.
 */
class ShipmentsController extends Controller
{
	use ControllerBodyParamsTrait;

	/**
	 * @throws NotFoundHttpException
	 */
	public function actionEdit(int $id, ?Shipment $shipment = null): Response
	{
		$this->requirePermission(Plugin::PERMISSION_VIEW);

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		if (! $shipment instanceof Shipment) {
			$loaded = $plugin->shipments->findById($id, includeTrashed: true);
			if (! $loaded instanceof Shipment) {
				throw new NotFoundHttpException(Craft::t(Plugin::HANDLE, 'error.shipmentNotFound'));
			}

			$shipment = $loaded;
		}

		$order = $plugin->shipments->loadOrder($shipment->orderId);
		if ($order === null) {
			throw new NotFoundHttpException(Craft::t(Plugin::HANDLE, 'error.orderNotFound'));
		}

		$integrations = $plugin->integrations->getAllIntegrations();
		$pushableIntegrations = [];
		foreach ($integrations as $integration) {
			$provider = $integration->getProvider();
			if ($integration->isEnabled() && $integration->id !== null && $provider?->supportsPush()) {
				$pushableIntegrations[] = $integration;
			}
		}

		$statusHistory = $shipment->id !== null
			? $plugin->shipments->getStatusHistoryForShipmentId($shipment->id)
			: [];

		$this->view->registerAssetBundle(ShipmentsCpAsset::class);

		return $this->renderTemplate(Plugin::HANDLE . '/_cp/shipment/edit', [
			'shipment' => $shipment,
			'order' => $order,
			'statusOptions' => Status::labelMap(),
			'integrations' => $integrations,
			'pushableIntegrations' => $pushableIntegrations,
			'statusHistory' => $statusHistory,
			'unallocatedPool' => $plugin->shipmentLineItems->remainingPoolFor($order),
			'title' => Craft::t(Plugin::HANDLE, 'shipmentEdit.titleWithReference', [
				'reference' => $shipment->reference,
			]),
		]);
	}

	/**
	 * @throws BadRequestHttpException
	 * @throws NotFoundHttpException
	 * @throws Throwable
	 */
	public function actionSave(): ?Response
	{
		$this->requirePostRequest();
		$this->requirePermission(Plugin::PERMISSION_EDIT);

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		$idInput = $this->request->getRequiredBodyParam('id');
		if (! is_numeric($idInput)) {
			throw new BadRequestHttpException(Craft::t(Plugin::HANDLE, 'error.invalidShipmentId'));
		}

		$shipmentId = (int) $idInput;
		$shipment = $plugin->shipments->findById($shipmentId, includeTrashed: true);
		if (! $shipment instanceof Shipment) {
			throw new NotFoundHttpException(Craft::t(Plugin::HANDLE, 'error.shipmentNotFound'));
		}

		$order = $plugin->shipments->loadOrder($shipment->orderId);
		if ($order === null) {
			throw new NotFoundHttpException(Craft::t(Plugin::HANDLE, 'error.orderNotFound'));
		}

		$shipment->trackingNumber = $this->bodyString('trackingNumber');
		$shipment->trackingUrl = $this->bodyString('trackingUrl');
		$shipment->carrier = $this->bodyString('carrier');
		$shipment->service = $this->bodyString('service');
		$shipment->fulfillmentNotes = $this->bodyString('fulfillmentNotes');
		$shipment->shippingNotes = $this->bodyString('shippingNotes');
		$shipment->enabled = (bool) $this->request->getBodyParam('enabled', false);
		$shipment->setFieldValuesFromRequest('fields');

		$dateScheduledShipInput = $this->request->getBodyParam('dateScheduledShip');
		$shipment->dateScheduledShip = is_string($dateScheduledShipInput) || is_int($dateScheduledShipInput) || is_array($dateScheduledShipInput) || $dateScheduledShipInput instanceof DateTimeInterface
			? DateTimeHelper::toDateTime($dateScheduledShipInput) ?: null
			: null;

		$integrationReferences = $this->parseIntegrationReferences();
		$statusInput = $this->bodyString('status') ?? '';
		$target = $statusInput !== '' ? Status::tryFrom($statusInput) : null;
		$willTransition = $target instanceof Status && $statusInput !== $shipment->status;
		$outerTransaction = Craft::$app->getDb()->beginTransaction();

		try {
			$saved = $plugin->shipments->saveManual($shipment, $order, ! $willTransition);
			$plugin->integrationReferences->saveReferencesForShipment($saved, $integrationReferences);
			$outerTransaction->commit();
		} catch (Throwable $throwable) {
			$outerTransaction->rollBack();
			Craft::$app->getSession()->setError($throwable->getMessage());
			Craft::$app->getUrlManager()->setRouteParams([
				'shipment' => $shipment,
				'id' => $shipmentId,
			]);
			return null;
		}

		$user = Craft::$app->getUser()->getIdentity();
		$statusMessage = $this->bodyString('statusMessage');

		if ($target instanceof Status && $statusInput !== $saved->status) {
			$this->requirePermission(Plugin::PERMISSION_TRANSITION);

			try {
				$transitioned = $plugin->shipments->applyTransition($saved, $target, $user, $statusMessage);
				if ($transitioned instanceof Shipment) {
					$saved = $transitioned;
				}
			} catch (Throwable $throwable) {
				Craft::$app->getSession()->setError($throwable->getMessage());
				return null;
			}
		}

		Craft::$app->getSession()->setNotice(Craft::t(Plugin::HANDLE, 'shipmentEdit.saved'));
		return $this->redirectToPostedUrl($saved);
	}

	/**
	 * Replaces a shipment's line-item allocation from `lineItems[<lineItemId>] = qty` POST rows.
	 *
	 * Lowering a quantity frees those units back to the order's unallocated pool; omitting a line item (or qty 0) removes it.
	 *
	 * @throws BadRequestHttpException
	 * @throws NotFoundHttpException
	 * @throws Throwable
	 */
	public function actionSaveLineItems(): ?Response
	{
		$this->requirePostRequest();
		$this->requireAcceptsJson();
		$this->requirePermission(Plugin::PERMISSION_EDIT);

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		$idInput = $this->request->getRequiredBodyParam('id');
		if (! is_numeric($idInput)) {
			throw new BadRequestHttpException(Craft::t(Plugin::HANDLE, 'error.invalidShipmentId'));
		}

		$shipment = $plugin->shipments->findById((int) $idInput, includeTrashed: true);
		if (! $shipment instanceof Shipment) {
			throw new NotFoundHttpException(Craft::t(Plugin::HANDLE, 'error.shipmentNotFound'));
		}

		// A trashed or disabled shipment's line items don't count toward allocation, so editing
		// them has no coherent meaning; the edit UI is read-only in that state.
		if ($shipment->trashed || ! $shipment->enabled) {
			return $this->asFailure(Craft::t(Plugin::HANDLE, 'shipmentEdit.lineItems.enabledOnly'));
		}

		$postedLineItems = $this->request->getBodyParam('lineItems', []);
		$lineItemQtys = $this->sanitizeLineItemQtys(is_array($postedLineItems) ? $postedLineItems : []);

		if ($lineItemQtys === []) {
			return $this->asFailure(Craft::t(Plugin::HANDLE, 'shipmentEdit.lineItems.mustKeepOne'));
		}

		try {
			$plugin->shipments->saveLineItems($shipment, $lineItemQtys, Craft::$app->getUser()->getIdentity());
		} catch (AllocationOverflowException) {
			return $this->asFailure(Craft::t(Plugin::HANDLE, 'error.quantitiesOverAllocate'));
		} catch (Throwable $throwable) {
			return $this->asFailure($throwable->getMessage());
		}

		return $this->asSuccess(Craft::t(Plugin::HANDLE, 'shipmentEdit.lineItems.saved'));
	}

	/**
	 * Soft-deletes a shipment.
	 *
	 * JSON callers get a `{success}` payload; form-POST callers get a flash notice and posted-url redirect.
	 *
	 * @throws BadRequestHttpException
	 */
	public function actionDelete(): ?Response
	{
		$this->requirePostRequest();
		$this->requirePermission(Plugin::PERMISSION_DELETE);

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		$idInput = $this->request->getRequiredBodyParam('id');
		if (! is_numeric($idInput)) {
			throw new BadRequestHttpException(Craft::t(Plugin::HANDLE, 'error.invalidShipmentId'));
		}

		$deleted = $plugin->shipments->softDeleteById((int) $idInput);

		if ($this->request->getAcceptsJson()) {
			if (! $deleted) {
				return $this->asJson([
					'success' => false,
					'error' => Craft::t(Plugin::HANDLE, 'error.shipmentNotDeleted'),
				]);
			}

			return $this->asJson([
				'success' => true,
			]);
		}

		if (! $deleted) {
			Craft::$app->getSession()->setError(Craft::t(Plugin::HANDLE, 'error.shipmentNotDeleted'));
			return null;
		}

		Craft::$app->getSession()->setNotice(Craft::t(Plugin::HANDLE, 'shipmentEdit.deleted'));
		return $this->redirectToPostedUrl();
	}

	/**
	 * @throws BadRequestHttpException
	 * @throws NotFoundHttpException
	 */
	public function actionRebuild(): Response
	{
		$this->requirePostRequest();
		$this->requirePermission(Plugin::PERMISSION_EDIT);

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		$orderIdInput = $this->request->getRequiredBodyParam('orderId');
		if (! is_numeric($orderIdInput)) {
			throw new BadRequestHttpException(Craft::t(Plugin::HANDLE, 'error.invalidOrderId'));
		}

		$orderId = (int) $orderIdInput;
		$order = $plugin->shipments->loadOrder($orderId);
		if ($order === null) {
			throw new NotFoundHttpException(Craft::t(Plugin::HANDLE, 'error.orderNotFound'));
		}

		if (! $order->isCompleted) {
			Craft::$app->getSession()->setError(Craft::t(Plugin::HANDLE, 'error.shipmentsCompletedOnly'));
			return $this->redirectToPostedUrl();
		}

		$plugin->shipments->createFor($order);

		Craft::$app->getSession()->setNotice(Craft::t(Plugin::HANDLE, 'shipmentEdit.rebuilt'));
		return $this->redirectToPostedUrl();
	}

	/**
	 * Queues a {@see PushShipmentJob} for one shipment and one integration.
	 *
	 * Push outcome lands on the shipment's `dateLastPushAttempt` / `lastPushAttemptError` once the queue runs.
	 *
	 * @throws BadRequestHttpException
	 * @throws NotFoundHttpException
	 */
	public function actionPush(): ?Response
	{
		$this->requirePostRequest();
		$this->requirePermission(Plugin::PERMISSION_PUSH);

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		$shipmentIdInput = $this->request->getRequiredBodyParam('id');
		if (! is_numeric($shipmentIdInput)) {
			throw new BadRequestHttpException(Craft::t(Plugin::HANDLE, 'error.invalidShipmentId'));
		}

		$shipment = $plugin->shipments->findById((int) $shipmentIdInput);
		if (! $shipment instanceof Shipment) {
			throw new NotFoundHttpException(Craft::t(Plugin::HANDLE, 'error.shipmentNotFound'));
		}

		$integrationIdInput = $this->request->getRequiredBodyParam('integrationId');
		if (! is_numeric($integrationIdInput)) {
			throw new BadRequestHttpException(Craft::t(Plugin::HANDLE, 'error.invalidIntegrationId'));
		}

		$integration = $plugin->integrations->getIntegrationById((int) $integrationIdInput);
		if (! $integration instanceof Integration) {
			throw new NotFoundHttpException(Craft::t(Plugin::HANDLE, 'error.integrationNotFound'));
		}

		if (! $integration->isEnabled()) {
			Craft::$app->getSession()->setError(Craft::t(Plugin::HANDLE, 'error.integrationDisabled', [
				'name' => $integration->name ?? '',
			]));
			return $this->redirectToPostedUrl();
		}

		$provider = $integration->getProvider();
		if ($provider?->supportsPush() !== true) {
			Craft::$app->getSession()->setError(Craft::t(Plugin::HANDLE, 'error.integrationPushUnsupported', [
				'name' => $integration->name ?? '',
			]));
			return $this->redirectToPostedUrl();
		}

		Craft::$app->getQueue()->push(new PushShipmentJob([
			'shipmentId' => $shipment->id,
			'integrationId' => $integration->id,
		]));

		Craft::$app->getSession()->setNotice(Craft::t(Plugin::HANDLE, 'shipmentEdit.pushToQueued', [
			'name' => $integration->name ?? '',
		]));
		return $this->redirectToPostedUrl();
	}

	/**
	 * Creates shipments for an order from staging-group POST rows:
	 * `groups[<groupId>][<lineItemId>] = qty`.
	 *
	 * @throws BadRequestHttpException
	 * @throws NotFoundHttpException
	 * @throws Throwable
	 */
	public function actionCreateShipment(): ?Response
	{
		$this->requirePostRequest();
		$this->requirePermission(Plugin::PERMISSION_EDIT);

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		$orderIdInput = $this->request->getRequiredBodyParam('orderId');
		if (! is_numeric($orderIdInput)) {
			throw new BadRequestHttpException(Craft::t(Plugin::HANDLE, 'error.invalidOrderId'));
		}

		$order = $plugin->shipments->loadOrder((int) $orderIdInput);
		if ($order === null) {
			throw new NotFoundHttpException(Craft::t(Plugin::HANDLE, 'error.orderNotFound'));
		}

		if (! $order->isCompleted) {
			return $this->asFailure(Craft::t(Plugin::HANDLE, 'error.shipmentsCompletedOnly'));
		}

		$postedGroups = $this->request->getBodyParam('groups', []);
		$sanitizedGroups = $this->sanitizeStagingGroups(is_array($postedGroups) ? $postedGroups : []);

		if ($sanitizedGroups === []) {
			return $this->asFailure(Craft::t(Plugin::HANDLE, 'error.allocateAtLeastOneLineItem'));
		}

		try {
			$createdShipments = $plugin->shipments->createFromStagingPost($order, $sanitizedGroups);
		} catch (OrderNotCompletedException) {
			return $this->asFailure(Craft::t(Plugin::HANDLE, 'error.shipmentsCompletedOnly'));
		} catch (AllocationMismatchException $allocationMismatchException) {
			Craft::error(
				'Shipment allocation rejected for order ' . $allocationMismatchException->orderId . '. Pool mismatch: ' . Json::encode($allocationMismatchException->mismatches),
				Plugin::HANDLE,
			);
			$errorMessage = $allocationMismatchException->submittedOutsidePool
				? Craft::t(Plugin::HANDLE, 'error.shipmentAllocationStale')
				: Craft::t(Plugin::HANDLE, 'error.shipmentAllocationsExact');
			return $this->asFailure($errorMessage);
		} catch (Throwable $throwable) {
			return $this->asFailure($throwable->getMessage());
		}

		$createdCount = count($createdShipments);
		$message = $createdCount === 1
			? Craft::t(Plugin::HANDLE, 'shipmentEdit.created')
			: Craft::t(Plugin::HANDLE, 'shipmentEdit.shipmentsCreatedCount', [
				'count' => $createdCount,
			]);
		return $this->asSuccess($message);
	}

	/**
	 * Structural narrowing of the raw `groups[...]` POST. Policy lives in the service.
	 *
	 * @param array<mixed, mixed> $postedGroups
	 * @return list<array<int, int>>
	 */
	private function sanitizeStagingGroups(array $postedGroups): array
	{
		$sanitized = [];
		foreach ($postedGroups as $postedGroup) {
			if (! is_array($postedGroup)) {
				continue;
			}

			$cleanGroup = [];
			foreach ($postedGroup as $lineItemId => $qty) {
				if (! is_numeric($lineItemId)) {
					continue;
				}

				if (! is_numeric($qty)) {
					continue;
				}

				$lineItemId = (int) $lineItemId;
				$qty = (int) $qty;
				if ($qty <= 0) {
					continue;
				}

				$cleanGroup[$lineItemId] = ($cleanGroup[$lineItemId] ?? 0) + $qty;
			}

			if ($cleanGroup !== []) {
				$sanitized[] = $cleanGroup;
			}
		}

		return $sanitized;
	}

	/**
	 * Structural narrowing of the raw `lineItems[<lineItemId>] = qty` POST.
	 *
	 * A qty of 0 or less is dropped, which the service reads as "remove this line item".
	 *
	 * @param array<mixed, mixed> $postedLineItems
	 * @return array<int, int>
	 */
	private function sanitizeLineItemQtys(array $postedLineItems): array
	{
		$sanitized = [];
		foreach ($postedLineItems as $lineItemId => $qty) {
			if (! is_numeric($lineItemId)) {
				continue;
			}

			if (! is_numeric($qty)) {
				continue;
			}

			$lineItemId = (int) $lineItemId;
			$qty = (int) $qty;
			if ($lineItemId <= 0) {
				continue;
			}

			if ($qty <= 0) {
				continue;
			}

			$sanitized[$lineItemId] = ($sanitized[$lineItemId] ?? 0) + $qty;
		}

		return $sanitized;
	}

	/**
	 * Narrow `integrationReferences[]` POST rows. Blank rows are dropped.
	 *
	 * @return list<array{id: ?int, integrationId: int, externalId: string, url: ?string}>
	 */
	private function parseIntegrationReferences(): array
	{
		$rawRows = $this->request->getBodyParam('integrationReferences', []);
		if (! is_array($rawRows)) {
			return [];
		}

		$rows = [];
		foreach ($rawRows as $rawRow) {
			if (! is_array($rawRow)) {
				continue;
			}

			$integrationIdRaw = $rawRow['integrationId'] ?? null;
			$externalIdRaw = $rawRow['externalId'] ?? null;
			if (! is_numeric($integrationIdRaw)) {
				continue;
			}

			if (! is_scalar($externalIdRaw)) {
				continue;
			}

			$integrationId = (int) $integrationIdRaw;
			$externalId = trim((string) $externalIdRaw);
			if ($integrationId <= 0) {
				continue;
			}

			if ($externalId === '') {
				continue;
			}

			$idRaw = $rawRow['id'] ?? null;
			$urlRaw = $rawRow['url'] ?? null;
			$url = is_scalar($urlRaw) && trim((string) $urlRaw) !== '' ? trim((string) $urlRaw) : null;

			$rows[] = [
				'id' => is_numeric($idRaw) ? (int) $idRaw : null,
				'integrationId' => $integrationId,
				'externalId' => $externalId,
				'url' => $url,
			];
		}

		return $rows;
	}
}
