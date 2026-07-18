<?php

declare(strict_types=1);

namespace fostercommerce\shipments\console\controllers;

use craft\commerce\Plugin as Commerce;
use craft\console\Controller;
use fostercommerce\shipments\Plugin;
use yii\console\ExitCode;

/**
 * Console commands for building and rebuilding shipments.
 */
class ShipmentsController extends Controller
{
	/**
	 * Rebuild shipments for a single order. Skips if the order already has shipments.
	 */
	public function actionRebuild(int $orderId): int
	{
		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();
		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		$order = $commerce->getOrders()->getOrderById($orderId);
		if ($order === null) {
			$this->stderr("Order {$orderId} not found.\n");
			return ExitCode::UNSPECIFIED_ERROR;
		}

		$existing = $plugin->shipments->findByOrderId($orderId);
		if ($existing !== []) {
			$this->stdout("Order {$orderId} already has " . count($existing) . " shipment(s); skipping.\n");
			return ExitCode::OK;
		}

		$created = $plugin->shipments->createFor($order);
		$this->stdout('Created ' . count($created) . " shipment(s) for order {$orderId}.\n");

		return ExitCode::OK;
	}
}
