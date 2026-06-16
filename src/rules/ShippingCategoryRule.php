<?php

declare(strict_types=1);

namespace fostercommerce\shipments\rules;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\enums\LineItemType;
use fostercommerce\shipments\base\ShipmentRuleInterface;
use fostercommerce\shipments\models\Settings;
use fostercommerce\shipments\models\ShipmentPlan;
use fostercommerce\shipments\Plugin;
use Throwable;

/**
 * Groups line items into shipments by Commerce shipping category, per store-defined groups.
 *
 * Each group's mode is `together` (one shipment for all matches) or `per-item` (one per match);
 * line items whose category is unassigned fall through to the fallback shipment.
 */
class ShippingCategoryRule implements ShipmentRuleInterface
{
	public const HANDLE = 'shipping-category';

	public function getHandle(): string
	{
		return self::HANDLE;
	}

	public function getName(): string
	{
		return Craft::t(Plugin::HANDLE, 'rules.shippingCategory.name');
	}

	public function getDescription(): string
	{
		return Craft::t(
			Plugin::HANDLE,
			'rules.shippingCategory.description',
		);
	}

	public function plan(Order $order, array $remainingQtyByLineItemId): array
	{
		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();
		$groups = $plugin->getSettings()->shippingCategoryGroups;
		if ($groups === []) {
			return [];
		}

		$groupIndexByHandle = [];
		foreach ($groups as $groupIndex => $group) {
			foreach ($group['categoryHandles'] as $handle) {
				$groupIndexByHandle[$handle] = $groupIndex;
			}
		}

		/** @var array<int, array<int, int>> $bundledByGroup */
		$bundledByGroup = [];
		$plans = [];

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

			try {
				$shippingCategoryHandle = $purchasable->getShippingCategory()->handle;
			} catch (Throwable) {
				continue;
			}

			if ($shippingCategoryHandle === null) {
				continue;
			}

			if (! isset($groupIndexByHandle[$shippingCategoryHandle])) {
				continue;
			}

			$groupIndex = $groupIndexByHandle[$shippingCategoryHandle];
			$group = $groups[$groupIndex];

			if ($group['mode'] === Settings::GROUPING_MODE_PER_ITEM) {
				$plans[] = $this->planFor(self::HANDLE . ':' . $this->slugFor($group), [
					$lineItemId => $remainingQty,
				]);
				continue;
			}

			$bundledByGroup[$groupIndex][$lineItemId] = $remainingQty;
		}

		foreach ($bundledByGroup as $groupIndex => $lineItemQtys) {
			$plans[] = $this->planFor(self::HANDLE . ':' . $this->slugFor($groups[$groupIndex]), $lineItemQtys);
		}

		return $plans;
	}

	/**
	 * @param array{mode: string, categoryHandles: list<string>} $group
	 */
	private function slugFor(array $group): string
	{
		return implode(',', $group['categoryHandles']);
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
