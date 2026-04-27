<?php

declare(strict_types=1);

namespace fostercommerce\shipments\rules;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\models\LineItemStatus;
use fostercommerce\shipments\base\ShipmentRuleInterface;
use fostercommerce\shipments\models\Settings;
use fostercommerce\shipments\models\ShipmentPlan;
use fostercommerce\shipments\Plugin;

/**
 * Groups line items into shipments using explicit store-defined groups. Each group pairs a set
 * of Commerce line item status handles with a mode: `together` bundles all matching line items
 * into one shipment; `per-item` emits one shipment per matched line item. Status handles not
 * assigned to any group fall through to the fallback shipment.
 */
class LineItemStatusRule implements ShipmentRuleInterface
{
	public const HANDLE = 'line-item-status';

	public function getHandle(): string
	{
		return self::HANDLE;
	}

	public function getName(): string
	{
		return Craft::t(Plugin::HANDLE, 'Line item status');
	}

	public function getDescription(): string
	{
		return Craft::t(
			Plugin::HANDLE,
			'Groups items into shipments using explicit store-defined groups. Each group declares a name, a mode (ship together or one shipment per line item), and the set of line item statuses whose items belong to it. Line items whose status isn’t assigned to any group fall through to the fallback shipment.',
		);
	}

	public function plan(Order $order, array $remainingQtyByLineItemId): array
	{
		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();
		$groups = $plugin->getSettings()->lineItemStatusGroups;
		if ($groups === []) {
			return [];
		}

		$groupIndexByHandle = [];
		foreach ($groups as $groupIndex => $group) {
			foreach ($group['statusHandles'] as $handle) {
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

			$status = $lineItem->getLineItemStatus();
			if (! $status instanceof LineItemStatus) {
				continue;
			}

			$handle = (string) $status->handle;
			if (! isset($groupIndexByHandle[$handle])) {
				continue;
			}

			$groupIndex = $groupIndexByHandle[$handle];
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
	 * @param array{mode: string, statusHandles: list<string>} $group
	 */
	private function slugFor(array $group): string
	{
		return implode(',', $group['statusHandles']);
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
