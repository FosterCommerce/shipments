<?php

declare(strict_types=1);

namespace fostercommerce\shipments\base;

use craft\commerce\elements\Order;
use fostercommerce\shipments\models\ShipmentPlan;

/**
 * Contract for rules that split a Commerce order into one or more shipments.
 *
 * Rules must not mutate the remaining-qty pool; the orchestrator reconciles allocations after each rule runs.
 */
interface ShipmentRuleInterface
{
	/**
	 * Unique, stable handle for this rule. Must remain stable: it tags plans and toggles the rule in settings.
	 */
	public function getHandle(): string;

	/**
	 * Human-readable, translated name for display in the CP.
	 */
	public function getName(): string;

	/**
	 * Translated explanation of what the rule does, rendered as instructional copy on the settings page.
	 */
	public function getDescription(): string;

	/**
	 * Build shipment plans for the given order, honoring the remaining-qty pool.
	 *
	 * @param array<int, int> $remainingQtyByLineItemId remaining qty available to allocate, keyed by line item id
	 * @return list<ShipmentPlan>
	 */
	public function plan(Order $order, array $remainingQtyByLineItemId): array;
}
