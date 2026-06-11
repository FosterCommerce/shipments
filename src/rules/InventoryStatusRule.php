<?php

declare(strict_types=1);

namespace fostercommerce\shipments\rules;

use Craft;
use craft\commerce\base\PurchasableInterface;
use craft\commerce\elements\Order;
use craft\commerce\enums\LineItemType;
use fostercommerce\shipments\base\ShipmentRuleInterface;
use fostercommerce\shipments\models\Settings;
use fostercommerce\shipments\models\ShipmentPlan;
use fostercommerce\shipments\Plugin;

/** Groups line items by inventory state (in-stock / backordered). Per-bucket grouping mode + `qtySplitMode` control the shape. */
class InventoryStatusRule implements ShipmentRuleInterface
{
	public const HANDLE = 'inventory-status';

	public function getHandle(): string
	{
		return self::HANDLE;
	}

	public function getName(): string
	{
		return Craft::t(Plugin::HANDLE, 'rules.inventory.name');
	}

	public function getDescription(): string
	{
		return Craft::t(
			Plugin::HANDLE,
			'rules.inventory.description',
		);
	}

	public function plan(Order $order, array $remainingQtyByLineItemId): array
	{
		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();
		$settings = $plugin->getSettings();
		$modes = $settings->inventoryGroupingModes;
		$qtySplitMode = $settings->qtySplitMode;

		$inStockMode = $modes[Settings::INVENTORY_BUCKET_IN_STOCK];
		$backorderMode = $modes[Settings::INVENTORY_BUCKET_BACKORDER];

		$inStockGroupedQtys = [];
		$backorderGroupedQtys = [];
		$inStockPerItemPlans = [];
		$backorderPerItemPlans = [];

		foreach ($order->getLineItems() as $lineItem) {
			$lineItemId = $lineItem->id;
			if ($lineItemId === null) {
				continue;
			}

			if (! array_key_exists($lineItemId, $remainingQtyByLineItemId)) {
				continue;
			}

			$remainingQty = $remainingQtyByLineItemId[$lineItemId];
			if ($remainingQty <= 0) {
				continue;
			}

			// Custom line items have no purchasable; getPurchasable() throws on them.
			if ($lineItem->type === LineItemType::Custom) {
				continue;
			}

			$purchasable = $lineItem->getPurchasable();
			if ($purchasable === null) {
				continue;
			}

			[$inStockQty, $backorderQty] = $this->splitQtyByInventory($purchasable, $remainingQty, $qtySplitMode);

			if ($inStockQty > 0) {
				if ($inStockMode === Settings::GROUPING_MODE_PER_ITEM) {
					$inStockPerItemPlans[] = $this->planFor(self::HANDLE . ':in-stock', [
						$lineItemId => $inStockQty,
					]);
				} else {
					$inStockGroupedQtys[$lineItemId] = $inStockQty;
				}
			}

			if ($backorderQty > 0) {
				if ($backorderMode === Settings::GROUPING_MODE_PER_ITEM) {
					$backorderPerItemPlans[] = $this->planFor(self::HANDLE . ':backorder', [
						$lineItemId => $backorderQty,
					]);
				} else {
					$backorderGroupedQtys[$lineItemId] = $backorderQty;
				}
			}
		}

		$plans = [];
		if ($inStockGroupedQtys !== []) {
			$plans[] = $this->planFor(self::HANDLE . ':in-stock', $inStockGroupedQtys);
		}

		foreach ($inStockPerItemPlans as $perItemPlan) {
			$plans[] = $perItemPlan;
		}

		if ($backorderGroupedQtys !== []) {
			$plans[] = $this->planFor(self::HANDLE . ':backorder', $backorderGroupedQtys);
		}

		foreach ($backorderPerItemPlans as $perItemPlan) {
			$plans[] = $perItemPlan;
		}

		return $plans;
	}

	/**
	 * @return array{0: int, 1: int}
	 */
	private function splitQtyByInventory(PurchasableInterface $purchasable, int $remainingQty, string $qtySplitMode): array
	{
		$availableStock = max(0, $purchasable->getStock());

		if ($purchasable->hasStock() && $availableStock >= $remainingQty) {
			return [$remainingQty, 0];
		}

		if ($qtySplitMode === Settings::QTY_SPLIT_MODE_ATOMIC) {
			return [0, $remainingQty];
		}

		$inStockQty = min($availableStock, $remainingQty);
		$backorderQty = $remainingQty - $inStockQty;

		return [$inStockQty, $backorderQty];
	}

	/**
	 * @param array<int, int> $lineItemQtys
	 */
	private function planFor(string $ruleHandle, array $lineItemQtys): ShipmentPlan
	{
		$plan = new ShipmentPlan();
		$plan->ruleHandle = $ruleHandle;
		$plan->lineItemQtys = $lineItemQtys;

		return $plan;
	}
}
