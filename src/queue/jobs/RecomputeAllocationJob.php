<?php

declare(strict_types=1);

namespace fostercommerce\shipments\queue\jobs;

use Craft;
use craft\queue\BaseJob;
use fostercommerce\shipments\Plugin;

/** Re-derives the cached under-allocated verdict for orders the Attention page read as stale. */
class RecomputeAllocationJob extends BaseJob
{
	/**
	 * @var list<int>
	 */
	public array $orderIds = [];

	public function execute($queue): void
	{
		if ($this->orderIds === []) {
			return;
		}

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();
		foreach ($this->orderIds as $orderId) {
			$order = $plugin->shipments->loadOrder($orderId);
			if ($order === null) {
				continue;
			}

			$plugin->trackedOrders->recomputeUnderAllocation($order);
		}
	}

	protected function defaultDescription(): ?string
	{
		return Craft::t(Plugin::HANDLE, 'queue.recomputingAllocation');
	}
}
