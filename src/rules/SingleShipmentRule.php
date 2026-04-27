<?php

declare(strict_types=1);

namespace fostercommerce\shipments\rules;

use Craft;
use craft\commerce\elements\Order;
use fostercommerce\shipments\base\ShipmentRuleInterface;
use fostercommerce\shipments\models\ShipmentPlan;
use fostercommerce\shipments\Plugin;

/**
 * Sweeps every remaining line-item quantity into a single shipment. Always runs last in the
 * orchestrator so that any quantities untouched by earlier rules end up grouped here; this is
 * what guarantees the "≥ 1 shipment per completed order" invariant.
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
		return Craft::t(Plugin::HANDLE, 'Single shipment (fallback)');
	}

	public function getDescription(): string
	{
		return Craft::t(
			Plugin::HANDLE,
			'Always runs last. Collects everything no other rule claimed into one shipment. This is what guarantees every order has at least one shipment; it cannot be disabled.',
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
