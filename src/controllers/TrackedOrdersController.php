<?php

declare(strict_types=1);

namespace fostercommerce\shipments\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\web\Controller;
use fostercommerce\shipments\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Handles the per-order "Order requires shipping" lightswitch and the related restore-
 * shipments flow:
 *   - POST /shipments/tracked-orders/set-active, flip on (track the order; admin now
 *     owns fulfillment for it).
 *   - POST /shipments/tracked-orders/set-ignored, flip off (cascade-trash all shipments
 *     on the order; the order drops off Attention).
 *   - POST /shipments/tracked-orders/restore-shipments, restore previously-trashed
 *     shipments back into the order's allocation pool.
 *
 * The setActive path is rejected if the order's current Commerce status is in the
 * plugin's `orderStatusesToIgnore` setting; the setting is authoritative.
 */
class TrackedOrdersController extends Controller
{
	public function actionSetActive(): ?Response
	{
		$this->requirePostRequest();
		$this->requirePermission(Plugin::PERMISSION_EDIT);

		$order = $this->requireOrder();
		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		if ($plugin->trackedOrders->isOrderStatusIgnored($order)) {
			return $this->asFailure(Craft::t(
				Plugin::HANDLE,
				'This order’s status is in the plugin’s ignore list. Remove the status from Settings or change the order’s status first.',
			));
		}

		$plugin->trackedOrders->markActive($order);
		return $this->asSuccess(Craft::t(
			Plugin::HANDLE,
			'Order is now tracked for fulfillment.',
		));
	}

	public function actionSetIgnored(): ?Response
	{
		$this->requirePostRequest();
		$this->requirePermission(Plugin::PERMISSION_EDIT);

		$order = $this->requireOrder();
		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		$trashed = $plugin->trackedOrders->markIgnored($order);

		if ($trashed > 0) {
			return $this->asSuccess(Craft::t(
				Plugin::HANDLE,
				'Order is no longer tracked. {count} shipment(s) were trashed.',
				[
					'count' => $trashed,
				],
			));
		}

		return $this->asSuccess(Craft::t(
			Plugin::HANDLE,
			'Order is no longer tracked.',
		));
	}

	public function actionRestoreShipments(): ?Response
	{
		$this->requirePostRequest();
		$this->requirePermission(Plugin::PERMISSION_EDIT);

		$order = $this->requireOrder();
		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		$result = $plugin->trackedOrders->restoreTrashedShipments($order);

		if ($result['skipped'] > 0 && $result['restored'] === 0) {
			return $this->asFailure(Craft::t(
				Plugin::HANDLE,
				'No shipments could be restored. They may over-allocate the order’s line items.',
			));
		}

		if ($result['skipped'] > 0) {
			return $this->asSuccess(Craft::t(
				Plugin::HANDLE,
				'{restored} shipment(s) restored. {skipped} couldn’t be restored without over-allocating the order.',
				$result,
			));
		}

		return $this->asSuccess(Craft::t(
			Plugin::HANDLE,
			'{restored} shipment(s) restored.',
			$result,
		));
	}

	private function requireOrder(): Order
	{
		$orderIdRaw = $this->request->getRequiredBodyParam('orderId');
		if (! is_numeric($orderIdRaw)) {
			throw new BadRequestHttpException(Craft::t(Plugin::HANDLE, 'orderId must be a number.'));
		}

		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();
		$order = $commerce->getOrders()->getOrderById((int) $orderIdRaw);
		if (! $order instanceof Order) {
			throw new NotFoundHttpException(Craft::t(Plugin::HANDLE, 'Order not found.'));
		}

		return $order;
	}
}
