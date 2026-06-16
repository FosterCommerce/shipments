<?php

declare(strict_types=1);

namespace fostercommerce\shipments\rules;

use Craft;
use craft\commerce\elements\Order;
use fostercommerce\shipments\base\ShipmentRuleInterface;
use fostercommerce\shipments\models\ShipmentPlan;
use fostercommerce\shipments\Plugin;

/**
 * Sweeps every remaining line-item quantity into a single shipment.
 *
 * Runs last in the orchestrator, ensuring at least one shipment per completed order.
 */
class SingleShipmentRule implements ShipmentRuleInterface
{
	public const HANDLE = 'single-shipment';

	public function getHandle(): string
	{
		return self::HANDLE;
	}

	public function getName(): string
	{
		return Craft::t(Plugin::HANDLE, 'rules.fallback.name');
	}

	public function getDescription(): string
	{
		return Craft::t(
			Plugin::HANDLE,
			'rules.fallback.description',
		);
	}

	public function plan(Order $order, array $remainingQtyByLineItemId): array
	{
		$allocations = [];
		foreach ($remainingQtyByLineItemId as $lineItemId => $qty) {
			if ($qty <= 0) {
				continue;
			}

			$allocations[$lineItemId] = $qty;
		}

		if ($allocations === []) {
			return [];
		}

		$plan = new ShipmentPlan();
		$plan->ruleHandle = self::HANDLE;
		$plan->lineItemQtys = $allocations;

		return [$plan];
	}
}
