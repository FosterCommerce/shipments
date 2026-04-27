<?php

declare(strict_types=1);

namespace fostercommerce\shipments\base;

use craft\commerce\elements\Order;
use fostercommerce\shipments\models\ShipmentPlan;

/**
 * Contract for rules that split a Commerce order into one or more shipments. Rules receive the
 * order and a remaining-qty pool keyed by line item id; they should return `ShipmentPlan`
 * instances that claim the line-item quantities they want to move into their own shipments.
 *
 * Rules must not mutate the pool directly; the orchestrator reconciles allocations after each
 * rule runs. A rule that produces no plans should return an empty array.
 */
interface ShipmentRuleInterface
{
	/**
	 * Unique, stable handle for this rule. Used in settings to enable/disable rules and to tag
	 * plans with the rule that produced them.
	 */
	public function getHandle(): string;

	/**
	 * Human-readable, translated name for display in the CP.
	 */
	public function getName(): string;

	/**
	 * Translated, one-to-three-sentence explanation of what the rule does and when to enable it.
	 * Rendered as the instructional copy on the settings page so users can make an informed
	 * enable/disable decision.
	 */
	public function getDescription(): string;

	/**
	 * Build shipment plans for the given order, honoring the remaining-qty pool. Keys are
	 * Commerce line item ids; values are remaining qty available to allocate.
	 *
	 * @param array<int, int> $remainingQtyByLineItemId
	 * @return list<ShipmentPlan>
	 */
	public function plan(Order $order, array $remainingQtyByLineItemId): array;
}
