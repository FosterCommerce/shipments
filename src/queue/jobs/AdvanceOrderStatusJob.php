<?php

declare(strict_types=1);

namespace fostercommerce\shipments\queue\jobs;

use Craft;
use craft\commerce\elements\Order;
use craft\queue\BaseJob;
use fostercommerce\shipments\Plugin;

/**
 * Advances an order to the configured status once every enabled shipment is shipped. Queued
 * from the shipment status-change event so the order save runs after the shipment write
 * commits, in its own transaction, and an order failure can never roll back the shipment.
 */
class AdvanceOrderStatusJob extends BaseJob
{
	public ?int $orderId = null;

	public function execute($queue): void
	{
		if ($this->orderId === null) {
			return;
		}

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		$order = $plugin->shipments->loadOrder($this->orderId);
		if (! $order instanceof Order) {
			return;
		}

		$plugin->trackedOrders->advanceOrderStatusIfAllShipped($order);
	}

	protected function defaultDescription(): ?string
	{
		return Craft::t(Plugin::HANDLE, 'queue.advancingOrderStatus', [
			'orderId' => $this->orderId,
		]);
	}
}
