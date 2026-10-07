<?php

declare(strict_types=1);

namespace fostercommerce\shipments\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use fostercommerce\shipments\base\ControllerBodyParamsTrait;
use fostercommerce\shipments\elements\Shipment;
use fostercommerce\shipments\models\Delivery;
use fostercommerce\shipments\Plugin;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Explicit delivery actions remain separate from shipment fulfillment status.
 */
class DeliveriesController extends Controller
{
	use ControllerBodyParamsTrait;

	public function actionRefreshRates(): Response
	{
		$this->requirePostRequest();
		$this->requirePermission(Plugin::PERMISSION_EDIT);
		$this->requirePermission(Plugin::PERMISSION_VIEW);
		$shipment = $this->shipment($this->bodyId('id'));
		return $this->finish($shipment->id, function () use ($shipment): string {
			$quotes = Plugin::getInstance()->shipping->refresh($shipment);
			return $quotes === [] ? Craft::t('shipments', 'delivery.notices.noShippingMethodsMatchThisShipment') : Craft::t('shipments', 'delivery.notices.shipmentRatesRefreshed');
		});
	}

	public function actionSelectMethod(): Response
	{
		$this->requirePostRequest();
		$this->requirePermission(Plugin::PERMISSION_EDIT);
		$this->requirePermission(Plugin::PERMISSION_VIEW);
		$shipment = $this->shipment($this->bodyId('id'));
		try {
			$quote = Plugin::getInstance()->shipping->select($shipment, $this->bodyString('method') ?? '');
			return $this->asJson([
				'html' => $this->getView()->renderTemplate('shipments/_cp/shipment/_prepared', [
					'selectedQuote' => $quote,
				]),
			]);
		} catch (Throwable $throwable) {
			throw new BadRequestHttpException($throwable->getMessage(), 0, $throwable);
		}
	}

	public function actionCreate(): Response
	{
		$this->requirePostRequest();
		$this->requirePermission(Plugin::PERMISSION_CREATE_DELIVERY);
		$this->requirePermission(Plugin::PERMISSION_VIEW);
		$shipment = $this->shipment($this->bodyId('id'));
		return $this->finish($shipment->id, function () use ($shipment): string {
			$delivery = Plugin::getInstance()->deliveries->create($shipment);
			return $delivery->status === 'created' ? Craft::t('shipments', 'delivery.notices.deliveryCreated') : Craft::t('shipments', 'delivery.notices.deliverySaved');
		});
	}

	public function actionRefreshDelivery(): Response
	{
		$this->requirePostRequest();
		$this->requirePermission(Plugin::PERMISSION_CREATE_DELIVERY);
		$this->requirePermission(Plugin::PERMISSION_VIEW);
		$delivery = $this->delivery($this->bodyId('deliveryId'));
		return $this->finish($delivery->shipmentId, function () use ($delivery): string {
			Plugin::getInstance()->deliveries->refresh($delivery);
			return Craft::t('shipments', 'delivery.notices.deliveryResultRefreshed');
		});
	}

	public function actionVoid(): Response
	{
		$this->requirePostRequest();
		$this->requirePermission(Plugin::PERMISSION_VOID_DELIVERY);
		$this->requirePermission(Plugin::PERMISSION_VIEW);
		$delivery = $this->delivery($this->bodyId('deliveryId'));
		return $this->finish($delivery->shipmentId, function () use ($delivery): string {
			Plugin::getInstance()->deliveries->void($delivery);
			$current = $this->delivery((int) $delivery->id);
			if ($current->status !== 'voided') {
				throw new BadRequestHttpException($current->lastError ?? Craft::t('shipments', 'delivery.errors.carrierCancellationHasNotBeenConfirmed'));
			}

			return Craft::t('shipments', 'delivery.notices.carrierCancellationConfirmed');
		});
	}

	public function actionAcknowledgeFailed(): Response
	{
		$this->requirePostRequest();
		$this->requirePermission(Plugin::PERMISSION_CREATE_DELIVERY);
		$this->requirePermission(Plugin::PERMISSION_VIEW);
		$delivery = $this->delivery($this->bodyId('deliveryId'));
		return $this->finish($delivery->shipmentId, function () use ($delivery): string {
			$note = $this->bodyString('note');
			if ($this->bodyString('confirmNoDelivery') !== '1' || $note === null) {
				throw new BadRequestHttpException(Craft::t('shipments', 'delivery.errors.confirmThatTheCarrierHasNoDeliveryAndRecord'));
			}

			Plugin::getInstance()->deliveries->acknowledgeFailed($delivery, $note);
			return Craft::t('shipments', 'delivery.notices.carrierReviewRecorded');
		});
	}

	private function bodyId(string $name): int
	{
		$value = $this->request->getRequiredBodyParam($name);
		if (! is_scalar($value) || ! ctype_digit((string) $value) || (int) $value < 1) {
			throw new BadRequestHttpException(Craft::t('shipments', 'delivery.errors.validRecordIDIsRequired'));
		}

		return (int) $value;
	}

	private function shipment(int $id): Shipment
	{
		$shipment = Plugin::getInstance()->shipments->findById($id);
		if (! $shipment instanceof Shipment) {
			throw new NotFoundHttpException(Craft::t('shipments', 'delivery.errors.shipmentDoesNotExist'));
		}

		return $shipment;
	}

	private function delivery(int $id): Delivery
	{
		return Plugin::getInstance()->deliveries->getById($id) ?? throw new NotFoundHttpException(Craft::t('shipments', 'delivery.notices.deliveryDoesNotExist'));
	}

	/**
	 * @param callable(): string $action
	 */
	private function finish(?int $shipmentId, callable $action): Response
	{
		try {
			Craft::$app->getSession()->setNotice($action());
		} catch (Throwable $throwable) {
			Craft::$app->getSession()->setError($throwable->getMessage());
		}

		return $this->redirect(UrlHelper::cpUrl('shipments/shipments/' . $shipmentId) . '#delivery');
	}
}
