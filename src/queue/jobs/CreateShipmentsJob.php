<?php

declare(strict_types=1);

namespace fostercommerce\shipments\queue\jobs;

use Craft;
use craft\queue\BaseJob;
use fostercommerce\shipments\Plugin;

/** Run the rules engine against the given order. Idempotent; skips silently once shipments exist. */
class CreateShipmentsJob extends BaseJob
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
		if ($order === null) {
			Craft::warning("Skipping CreateShipmentsJob: order {$this->orderId} not found.", Plugin::HANDLE);
			return;
		}

		$plugin->shipments->createFor($order);
	}

	protected function defaultDescription(): ?string
	{
		return Craft::t(Plugin::HANDLE, 'queue.creatingShipments', [
			'id' => $this->orderId ?? 0,
		]);
	}
}
