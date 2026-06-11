<?php

declare(strict_types=1);

namespace fostercommerce\shipments\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\web\Controller;
use fostercommerce\shipments\db\Table;
use fostercommerce\shipments\Plugin;
use fostercommerce\shipments\queue\jobs\RecomputeAllocationJob;
use yii\web\Response;

/**
 * Lists completed orders whose enabled shipments don't cover the full line-item pool, paged so a
 * large backlog doesn't hydrate every Commerce order at once.
 */
class AttentionController extends Controller
{
	public function actionIndex(): Response
	{
		$this->requirePermission(Plugin::PERMISSION_VIEW);

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		$allOrderIds = $plugin->shipmentLineItems->findUnderAllocatedOrderIds();

		// Hydrating every flagged order at once blows out memory once the list grows. Page the id
		// list and only load full Commerce elements for the current page.
		$pageSize = 50;
		$totalOrders = count($allOrderIds);
		$totalPages = max(1, (int) ceil($totalOrders / $pageSize));
		$pageParam = $this->request->getParam('page', 1);
		$requestedPage = is_numeric($pageParam) ? (int) $pageParam : 1;
		$currentPage = min($totalPages, max(1, $requestedPage));
		$orderIds = array_slice($allOrderIds, ($currentPage - 1) * $pageSize, $pageSize);

		$orders = [];
		$staleOrderIds = [];
		if ($orderIds !== []) {
			/** @var list<Order> $loadedOrders */
			$loadedOrders = Order::find()->id($orderIds)->status(null)->all();

			$ordersById = [];
			foreach ($loadedOrders as $loadedOrder) {
				if ($loadedOrder->id !== null) {
					$ordersById[$loadedOrder->id] = $loadedOrder;
				}
			}

			foreach ($orderIds as $orderId) {
				$order = $ordersById[$orderId] ?? null;
				if (! $order instanceof Order) {
					continue;
				}

				$missing = $plugin->shipmentLineItems->getMissingCoverageFor($order);
				if ($missing === []) {
					$staleOrderIds[] = $orderId;
					continue;
				}

				$orders[] = [
					'order' => $order,
					'missing' => $missing,
				];
			}

			if ($staleOrderIds !== []) {
				Craft::$app->getQueue()->push(new RecomputeAllocationJob([
					'orderIds' => $staleOrderIds,
				]));
			}
		}

		$trackedOrderCount = (int) (new Query())
			->from(Table::TRACKED_ORDERS)
			->count();

		return $this->renderTemplate(Plugin::HANDLE . '/_cp/attention/index', [
			'title' => Craft::t(Plugin::HANDLE, 'orderTab.attentionNeeded'),
			'orders' => $orders,
			'trackedOrderCount' => $trackedOrderCount,
			'totalOrders' => $totalOrders,
			'currentPage' => $currentPage,
			'totalPages' => $totalPages,
		]);
	}
}
