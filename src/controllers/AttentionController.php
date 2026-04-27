<?php

declare(strict_types=1);

namespace fostercommerce\shipments\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\web\Controller;
use fostercommerce\shipments\db\Table;
use fostercommerce\shipments\enums\StatusAxis;
use fostercommerce\shipments\models\Integration;
use fostercommerce\shipments\Plugin;
use yii\web\Response;

/**
 * Aggregates attention-needed signals:
 *   1. Completed orders whose enabled shipments don't cover the full line-item pool.
 *   2. External status codes ingested from integrations that have no mapping to our
 *      fulfillment/shipping vocabularies.
 */
class AttentionController extends Controller
{
	public function actionIndex(): Response
	{
		$this->requirePermission(Plugin::PERMISSION_VIEW);

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		$orderIds = $plugin->shipmentLineItems->findUnderAllocatedOrderIds();

		$orders = [];
		$staleVerdictHealed = false;
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
				// The cached `underAllocated` flag on `shipments_tracked_orders` can drift if
				// the order's line items changed after the cache was last refreshed (qty
				// edits, line item removals, status flips into the ignored list). Recompute
				// here when the live pool says there's nothing missing, drop the order from
				// the page, and let the badge count rebuild from the corrected verdict.
				if ($missing === []) {
					$plugin->trackedOrders->recomputeUnderAllocation($order);
					$staleVerdictHealed = true;
					continue;
				}

				$orders[] = [
					'order' => $order,
					'missing' => $missing,
				];
			}

			if ($staleVerdictHealed) {
				$plugin->shipmentLineItems->invalidateAttentionCount();
			}
		}

		$unmappedRows = $plugin->integrationStatusMaps->findUnresolvedUnmappedCodes();
		$unmapped = [];
		foreach ($unmappedRows as $row) {
			$integrationIdRaw = $row['integrationId'] ?? null;
			if (! is_numeric($integrationIdRaw)) {
				continue;
			}

			$integration = $plugin->integrations->getIntegrationById((int) $integrationIdRaw);
			if (! $integration instanceof Integration) {
				continue;
			}

			$axisRaw = $row['axis'] ?? null;
			$axis = is_string($axisRaw) ? StatusAxis::tryFrom($axisRaw) : null;
			if (! $axis instanceof StatusAxis) {
				continue;
			}

			$externalCodeRaw = $row['externalCode'] ?? '';
			$occurrenceCountRaw = $row['occurrenceCount'] ?? 0;
			$unmapped[] = [
				'integration' => $integration,
				'axis' => $axis,
				'externalCode' => is_scalar($externalCodeRaw) ? (string) $externalCodeRaw : '',
				'occurrenceCount' => is_numeric($occurrenceCountRaw) ? (int) $occurrenceCountRaw : 0,
				'dateFirstSeen' => $row['dateFirstSeen'] ?? null,
				'dateLastSeen' => $row['dateLastSeen'] ?? null,
			];
		}

		$trackedOrderCount = (int) (new Query())
			->from(Table::TRACKED_ORDERS)
			->count();

		return $this->renderTemplate(Plugin::HANDLE . '/_cp/attention/index', [
			'title' => Craft::t(Plugin::HANDLE, 'Attention needed'),
			'orders' => $orders,
			'unmapped' => $unmapped,
			'trackedOrderCount' => $trackedOrderCount,
		]);
	}
}
