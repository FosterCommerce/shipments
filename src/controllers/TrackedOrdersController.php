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
 * Backs the per-order "Order requires shipping" lightswitch and the restore button:
 *   - set-active: track the order (admin owns fulfillment for it).
 *   - set-ignored: drop the order off Attention; its shipments stay put.
 *   - restore-shipments: pull trashed shipments back into the order's allocation pool.
 *
 * set-active is refused while the order's Commerce status is in `orderStatusesToIgnore`:
 * that setting wins over the switch.
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
				'orderTab.statusIgnoredNotice',
			));
		}

		$plugin->trackedOrders->markActive($order);
		return $this->asSuccess(Craft::t(
			Plugin::HANDLE,
			'orderTab.orderTracked',
		));
	}

	public function actionSetIgnored(): ?Response
	{
		$this->requirePostRequest();
		$this->requirePermission(Plugin::PERMISSION_EDIT);

		$order = $this->requireOrder();
		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		$plugin->trackedOrders->markIgnored($order);

		return $this->asSuccess(Craft::t(
			Plugin::HANDLE,
			'orderTab.orderUntracked',
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
				'error.noShipmentsRestored',
			));
		}

		if ($result['skipped'] > 0) {
			return $this->asSuccess(Craft::t(
				Plugin::HANDLE,
				'orderTab.shipmentsRestoredWithSkipped',
				$result,
			));
		}

		return $this->asSuccess(Craft::t(
			Plugin::HANDLE,
			'orderTab.shipmentsRestoredCount',
			$result,
		));
	}

	private function requireOrder(): Order
	{
		$orderIdRaw = $this->request->getRequiredBodyParam('orderId');
		if (! is_numeric($orderIdRaw)) {
			throw new BadRequestHttpException(Craft::t(Plugin::HANDLE, 'error.orderIdMustBeNumber'));
		}

		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();
		$order = $commerce->getOrders()->getOrderById((int) $orderIdRaw);
		if (! $order instanceof Order) {
			throw new NotFoundHttpException(Craft::t(Plugin::HANDLE, 'error.orderNotFound'));
		}

		return $order;
	}
}
