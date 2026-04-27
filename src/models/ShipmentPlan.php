<?php

declare(strict_types=1);

namespace fostercommerce\shipments\models;

use craft\base\Model;

/**
 * DTO emitted by shipment rules describing a proposed shipment: which line items and in what
 * quantities. The orchestrator in `Rules::planFor()` assembles an ordered array of these and
 * the `Shipments` service persists them.
 */
class ShipmentPlan extends Model
{
	/**
	 * Handle of the rule that produced this plan. Rules with sub-variants (e.g.
	 * `LineItemStatusRule` emits one plan per group) may append a colon suffix,
	 * e.g. `line-item-status:backorder`. Consumers should treat everything up to
	 * the first colon as the rule identifier.
	 */
	public string $ruleHandle = '';

	/**
	 * Map of Commerce line item id to qty to allocate to the shipment.
	 *
	 * @var array<int, int>
	 */
	public array $lineItemQtys = [];

	/**
	 * Optional shipment status handle suggested by the rule. Null defers to the plugin default.
	 */
	public ?string $suggestedStatusHandle = null;

	public function isEmpty(): bool
	{
		foreach ($this->lineItemQtys as $lineItemQty) {
			if ($lineItemQty > 0) {
				return false;
			}
		}

		return true;
	}

	public function totalQty(): int
	{
		return array_sum($this->lineItemQtys);
	}
}
