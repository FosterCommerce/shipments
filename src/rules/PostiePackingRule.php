<?php

declare(strict_types=1);

namespace fostercommerce\shipments\rules;

use Craft;
use craft\commerce\elements\Order;
use fostercommerce\shipments\base\ShipmentRuleInterface;
use fostercommerce\shipments\models\ShipmentPlan;
use fostercommerce\shipments\Plugin;

/** Creates one shipment per Postie packed box, with SingleShipmentRule handling the rest. */
class PostiePackingRule implements ShipmentRuleInterface
{
	public const HANDLE = 'postie-packing';

	public function getHandle(): string
	{
		return self::HANDLE;
	}

	public function getName(): string
	{
		return Craft::t(Plugin::HANDLE, 'rules.postiePacking.name');
	}

	public function getDescription(): string
	{
		return Craft::t(Plugin::HANDLE, 'rules.postiePacking.description');
	}

	public function plan(Order $order, array $remainingQtyByLineItemId): array
	{
		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		$boxes = $plugin->postiePacking->getBoxAllocations($order->number);
		$pool = $remainingQtyByLineItemId;
		$plans = [];

		foreach ($boxes as $boxIndex => $lineItemQtys) {
			$allocations = [];

			foreach ($lineItemQtys as $lineItemId => $qty) {
				$remaining = $pool[$lineItemId] ?? 0;

				if ($remaining > 0) {
					$claim = min($qty, $remaining);
					$allocations[$lineItemId] = $claim;
					$pool[$lineItemId] = $remaining - $claim;
				}
			}

			if ($allocations !== []) {
				$plan = new ShipmentPlan();
				$plan->ruleHandle = self::HANDLE . ':box-' . (string) $boxIndex;
				$plan->lineItemQtys = $allocations;
				$plans[] = $plan;
			}
		}

		return $plans;
	}
}
