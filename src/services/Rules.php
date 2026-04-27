<?php

declare(strict_types=1);

namespace fostercommerce\shipments\services;

use craft\commerce\elements\Order;
use fostercommerce\shipments\base\ShipmentRuleInterface;
use fostercommerce\shipments\events\RegisterShipmentRulesEvent;
use fostercommerce\shipments\models\Settings;
use fostercommerce\shipments\models\ShipmentPlan;
use fostercommerce\shipments\Plugin;
use fostercommerce\shipments\rules\InventoryStatusRule;
use fostercommerce\shipments\rules\LineItemStatusRule;
use fostercommerce\shipments\rules\ShippingCategoryRule;
use fostercommerce\shipments\rules\SingleShipmentRule;
use yii\base\Component;
use yii\base\InvalidConfigException;

/** Grouping-source registry + per-order plan resolver. Fallback SingleShipmentRule sweeps any leftover qty. */
class Rules extends Component
{
	public const EVENT_REGISTER_RULES = 'registerRules';

	/**
	 * @var array<string, ShipmentRuleInterface>|null
	 */
	private ?array $rulesByHandle = null;

	/**
	 * @return list<ShipmentPlan>
	 */
	public function planFor(Order $order): array
	{
		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();
		$pool = $plugin->shipmentLineItems->remainingPoolFor($order);
		$plans = [];

		$sourceRule = $this->resolveSourceRule();
		if ($sourceRule instanceof ShipmentRuleInterface) {
			foreach ($sourceRule->plan($order, $pool) as $rulePlan) {
				if ($rulePlan->isEmpty()) {
					continue;
				}

				$this->clampPlanToPool($rulePlan, $pool);
				if ($rulePlan->isEmpty()) {
					continue;
				}

				$this->subtractFromPool($rulePlan, $pool);
				$plans[] = $rulePlan;
			}
		}

		foreach ((new SingleShipmentRule())->plan($order, $pool) as $fallbackPlan) {
			$this->subtractFromPool($fallbackPlan, $pool);
			$plans[] = $fallbackPlan;
		}

		return $plans;
	}

	/**
	 * @return array<string, ShipmentRuleInterface>
	 * @throws InvalidConfigException If a registered rule's handle collides with another rule already in the registry.
	 */
	public function allRules(): array
	{
		if ($this->rulesByHandle === null) {
			$event = new RegisterShipmentRulesEvent();
			$event->rules = [
				new InventoryStatusRule(),
				new LineItemStatusRule(),
				new ShippingCategoryRule(),
			];
			$this->trigger(self::EVENT_REGISTER_RULES, $event);

			$byHandle = [];
			foreach ($event->rules as $rule) {
				$handle = $rule->getHandle();
				if (isset($byHandle[$handle])) {
					// Duplicate handles are rejected rather than letting the later rule
					// silently replace the earlier. A site module that wants to override
					// a built-in should remove it from `$event->rules` in the listener
					// before appending its replacement.
					throw new InvalidConfigException(sprintf(
						'Duplicate shipment rule handle "%s": both %s and %s claim it. Each ShipmentRuleInterface implementation must declare a unique getHandle() value.',
						$handle,
						$byHandle[$handle]::class,
						$rule::class,
					));
				}

				$byHandle[$handle] = $rule;
			}

			$this->rulesByHandle = $byHandle;
		}

		return $this->rulesByHandle;
	}

	private function resolveSourceRule(): ?ShipmentRuleInterface
	{
		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();
		$sourceHandle = $plugin->getSettings()->groupingSource;
		if ($sourceHandle === '' || $sourceHandle === Settings::GROUPING_SOURCE_NONE) {
			return null;
		}

		return $this->allRules()[$sourceHandle] ?? null;
	}

	/**
	 * @param array<int, int> $pool
	 */
	private function clampPlanToPool(ShipmentPlan $plan, array $pool): void
	{
		$clamped = [];
		foreach ($plan->lineItemQtys as $lineItemId => $qty) {
			if (! array_key_exists($lineItemId, $pool)) {
				continue;
			}

			if ($pool[$lineItemId] <= 0) {
				continue;
			}

			if ($qty <= 0) {
				continue;
			}

			$clamped[$lineItemId] = min($qty, $pool[$lineItemId]);
		}

		$plan->lineItemQtys = $clamped;
	}

	/**
	 * @param array<int, int> $pool
	 */
	private function subtractFromPool(ShipmentPlan $plan, array &$pool): void
	{
		foreach ($plan->lineItemQtys as $lineItemId => $qty) {
			if (! array_key_exists($lineItemId, $pool)) {
				continue;
			}

			$pool[$lineItemId] = max(0, $pool[$lineItemId] - $qty);
		}
	}
}
