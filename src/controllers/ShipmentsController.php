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
use fostercommerce\shipments\enums\FulfillmentStatus;
use fostercommerce\shipments\enums\ShippingStatus;
use fostercommerce\shipments\enums\StatusAxis;
use fostercommerce\shipments\errors\AllocationMismatchException;
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
				throw new NotFoundHttpException(Craft::t(Plugin::HANDLE, 'Shipment not found.'));
			}

			$shipment = $loaded;
		}

		$order = $plugin->shipments->loadOrder($shipment->orderId);
		if ($order === null) {
			throw new NotFoundHttpException(Craft::t(Plugin::HANDLE, 'Order not found.'));
		}

		$integrations = $plugin->integrations->getAllIntegrations();
		$statusHistory = $shipment->id !== null
			? $plugin->shipments->getStatusHistoryForShipmentId($shipment->id)
			: [];

		$this->view->registerAssetBundle(ShipmentsCpAsset::class);

		return $this->renderTemplate(Plugin::HANDLE . '/_cp/shipment/edit', [
			'shipment' => $shipment,
			'order' => $order,
			'fulfillmentStatusOptions' => FulfillmentStatus::labelMap(),
			'shippingStatusOptions' => ShippingStatus::labelMap(),
			'integrations' => $integrations,
			'statusHistory' => $statusHistory,
			'title' => Craft::t(Plugin::HANDLE, 'Shipment {reference}', [
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
			throw new BadRequestHttpException(Craft::t(Plugin::HANDLE, 'Invalid shipment id.'));
		}

		$shipmentId = (int) $idInput;
		$shipment = $plugin->shipments->findById($shipmentId, includeTrashed: true);
		if (! $shipment instanceof Shipment) {
			throw new NotFoundHttpException(Craft::t(Plugin::HANDLE, 'Shipment not found.'));
		}

		$order = $plugin->shipments->loadOrder($shipment->orderId);
		if ($order === null) {
			throw new NotFoundHttpException(Craft::t(Plugin::HANDLE, 'Order not found.'));
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
		$outerTransaction = Craft::$app->getDb()->beginTransaction();

		try {
			$saved = $plugin->shipments->saveManual($shipment, $order);
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
		$fulfillmentMessage = $this->bodyString('fulfillmentStatusMessage');
		$shippingMessage = $this->bodyString('shippingStatusMessage');

		$fulfillmentInput = $this->bodyString('fulfillmentStatus') ?? '';
		$shippingInput = $this->bodyString('shippingStatus') ?? '';

		if (
			($fulfillmentInput !== '' && $fulfillmentInput !== $saved->fulfillmentStatus)
			|| ($shippingInput !== '' && $shippingInput !== $saved->shippingStatus)
		) {
			$this->requirePermission(Plugin::PERMISSION_TRANSITION);
		}

		if ($fulfillmentInput !== '' && $fulfillmentInput !== $saved->fulfillmentStatus) {
			$target = FulfillmentStatus::tryFrom($fulfillmentInput);
			if ($target instanceof FulfillmentStatus) {
				try {
					$transitioned = $plugin->shipments->applyTransition($saved, StatusAxis::Fulfillment, $target, $user, $fulfillmentMessage);
					if ($transitioned instanceof Shipment) {
						$saved = $transitioned;
					}
				} catch (Throwable $throwable) {
					Craft::$app->getSession()->setError($throwable->getMessage());
					return null;
				}
			}
		}

		if ($shippingInput !== '' && $shippingInput !== $saved->shippingStatus) {
			$target = ShippingStatus::tryFrom($shippingInput);
			if ($target instanceof ShippingStatus) {
				try {
					$transitioned = $plugin->shipments->applyTransition($saved, StatusAxis::Shipping, $target, $user, $shippingMessage);
					if ($transitioned instanceof Shipment) {
						$saved = $transitioned;
					}
				} catch (Throwable $throwable) {
					Craft::$app->getSession()->setError($throwable->getMessage());
					return null;
				}
			}
		}

		Craft::$app->getSession()->setNotice(Craft::t(Plugin::HANDLE, 'Shipment saved.'));
		return $this->redirectToPostedUrl($saved);
	}

	/**
	 * Soft-deletes a shipment. JSON-accepting callers (the order tab card button) get a
	 * `{success}` payload; form-POST callers (the edit page's gear menu) get a flash
	 * notice + posted-url redirect.
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
			throw new BadRequestHttpException(Craft::t(Plugin::HANDLE, 'Invalid shipment id.'));
		}

		$deleted = $plugin->shipments->softDeleteById((int) $idInput);

		if ($this->request->getAcceptsJson()) {
			if (! $deleted) {
				return $this->asJson([
					'success' => false,
					'error' => Craft::t(Plugin::HANDLE, 'Shipment could not be deleted.'),
				]);
			}

			return $this->asJson([
				'success' => true,
			]);
		}

		if (! $deleted) {
			Craft::$app->getSession()->setError(Craft::t(Plugin::HANDLE, 'Shipment could not be deleted.'));
			return null;
		}

		Craft::$app->getSession()->setNotice(Craft::t(Plugin::HANDLE, 'Shipment deleted.'));
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
			throw new BadRequestHttpException(Craft::t(Plugin::HANDLE, 'Invalid order id.'));
		}

		$orderId = (int) $orderIdInput;
		$order = $plugin->shipments->loadOrder($orderId);
		if ($order === null) {
			throw new NotFoundHttpException(Craft::t(Plugin::HANDLE, 'Order not found.'));
		}

		if (! $order->isCompleted) {
			Craft::$app->getSession()->setError(Craft::t(Plugin::HANDLE, 'Shipments can only be created for completed orders.'));
			return $this->redirectToPostedUrl();
		}

		$plugin->shipments->createFor($order);

		Craft::$app->getSession()->setNotice(Craft::t(Plugin::HANDLE, 'Shipments rebuilt.'));
		return $this->redirectToPostedUrl();
	}

	/**
	 * Queues a {@see PushShipmentJob} for one shipment + one integration. The job runs
	 * the provider's outbound `sendShipmentWithEvents`; success/failure shows up on the shipment's
	 * `dateLastPushAttempt` / `lastPushAttemptError` fields once the queue runs.
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
			throw new BadRequestHttpException(Craft::t(Plugin::HANDLE, 'Invalid shipment id.'));
		}

		$shipment = $plugin->shipments->findById((int) $shipmentIdInput);
		if (! $shipment instanceof Shipment) {
			throw new NotFoundHttpException(Craft::t(Plugin::HANDLE, 'Shipment not found.'));
		}

		$integrationIdInput = $this->request->getRequiredBodyParam('integrationId');
		if (! is_numeric($integrationIdInput)) {
			throw new BadRequestHttpException(Craft::t(Plugin::HANDLE, 'Invalid integration id.'));
		}

		$integration = $plugin->integrations->getIntegrationById((int) $integrationIdInput);
		if (! $integration instanceof Integration) {
			throw new NotFoundHttpException(Craft::t(Plugin::HANDLE, 'Integration not found.'));
		}

		if (! $integration->enabled) {
			Craft::$app->getSession()->setError(Craft::t(Plugin::HANDLE, 'Integration “{name}” is disabled.', [
				'name' => $integration->name ?? '',
			]));
			return $this->redirectToPostedUrl();
		}

		Craft::$app->getQueue()->push(new PushShipmentJob([
			'shipmentId' => $shipment->id,
			'integrationId' => $integration->id,
		]));

		Craft::$app->getSession()->setNotice(Craft::t(Plugin::HANDLE, 'Push to “{name}” queued.', [
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
			throw new BadRequestHttpException(Craft::t(Plugin::HANDLE, 'Invalid order id.'));
		}

		$order = $plugin->shipments->loadOrder((int) $orderIdInput);
		if ($order === null) {
			throw new NotFoundHttpException(Craft::t(Plugin::HANDLE, 'Order not found.'));
		}

		if (! $order->isCompleted) {
			return $this->asFailure(Craft::t(Plugin::HANDLE, 'Shipments can only be created for completed orders.'));
		}

		$postedGroups = $this->request->getBodyParam('groups', []);
		$sanitizedGroups = $this->sanitizeStagingGroups(is_array($postedGroups) ? $postedGroups : []);

		if ($sanitizedGroups === []) {
			return $this->asFailure(Craft::t(Plugin::HANDLE, 'Allocate at least one line item before saving.'));
		}

		try {
			$createdShipments = $plugin->shipments->createFromStagingPost($order, $sanitizedGroups);
		} catch (OrderNotCompletedException) {
			return $this->asFailure(Craft::t(Plugin::HANDLE, 'Shipments can only be created for completed orders.'));
		} catch (AllocationMismatchException $allocationMismatchException) {
			Craft::error(
				'Shipment allocation rejected for order ' . $allocationMismatchException->orderId . '. Pool mismatch: ' . Json::encode($allocationMismatchException->mismatches),
				Plugin::HANDLE,
			);
			$errorMessage = $allocationMismatchException->submittedOutsidePool
				? Craft::t(Plugin::HANDLE, 'Shipment allocation rejected. The order’s line items appear to have changed since this page loaded; please reload and try again.')
				: Craft::t(Plugin::HANDLE, 'Shipment allocations must account for every remaining line item exactly.');
			return $this->asFailure($errorMessage);
		} catch (Throwable $throwable) {
			return $this->asFailure($throwable->getMessage());
		}

		$createdCount = count($createdShipments);
		$message = $createdCount === 1
			? Craft::t(Plugin::HANDLE, 'Shipment created.')
			: Craft::t(Plugin::HANDLE, '{count} shipments created.', [
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
