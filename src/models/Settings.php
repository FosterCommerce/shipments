<?php

declare(strict_types=1);

namespace fostercommerce\shipments\models;

use Craft;
use craft\base\Model;
use fostercommerce\shipments\Plugin;

class Settings extends Model
{
	public const QTY_SPLIT_MODE_SPLIT = 'split';

	public const QTY_SPLIT_MODE_ATOMIC = 'atomic';

	public const GROUPING_SOURCE_NONE = 'none';

	public const GROUPING_MODE_TOGETHER = 'together';

	public const GROUPING_MODE_PER_ITEM = 'per-item';

	public const INVENTORY_BUCKET_IN_STOCK = 'inStock';

	public const INVENTORY_BUCKET_BACKORDER = 'backorder';

	public bool $autoCreateOnComplete = false;

	/**
	 * When true, shipment saves reject unless every non-ignored line item qty is accounted for.
	 */
	public bool $enforceCoverage = true;

	/**
	 * Commerce line-item status handles that the plugin skips entirely: they're excluded
	 * from the rules engine and don't count toward the coverage check.
	 *
	 * @var list<string>
	 */
	public array $lineItemStatusesToIgnore = [];

	/**
	 * Commerce order-status handles that mean "this order doesn't need fulfillment"
	 * (for example, `cancelled`, `refunded`). When an order's status transitions into
	 * any of these, the plugin flips the per-order "Order requires shipping" switch off
	 * and cascade-trashes its shipments. Orders currently in one of these statuses also
	 * can't have the switch flipped on.
	 *
	 * @var list<string>
	 */
	public array $orderStatusesToIgnore = [];

	/**
	 * Registered rule handle, or `'none'` for single-shipment-per-order.
	 */
	public string $groupingSource = self::GROUPING_SOURCE_NONE;

	/**
	 * @var array{inStock: string, backorder: string}
	 */
	public array $inventoryGroupingModes = [
		self::INVENTORY_BUCKET_IN_STOCK => self::GROUPING_MODE_TOGETHER,
		self::INVENTORY_BUCKET_BACKORDER => self::GROUPING_MODE_TOGETHER,
	];

	/**
	 * @var list<array{mode: string, statusHandles: list<string>}>
	 */
	public array $lineItemStatusGroups = [];

	/**
	 * Groups used by `ShippingCategoryRule`. Each group bundles Commerce shipping-category
	 * handles (e.g. `ltl`, `hazmat`, `oversized`) and a mode.
	 *
	 * @var list<array{mode: string, categoryHandles: list<string>}>
	 */
	public array $shippingCategoryGroups = [];

	/**
	 * `split` = partial-qty line items appear in both buckets; `atomic` = whole line item lands in backorder if any of it is.
	 */
	public string $qtySplitMode = self::QTY_SPLIT_MODE_SPLIT;

	public function validateInventoryGroupingModes(string $attribute): void
	{
		$value = $this->{$attribute};
		if (! is_array($value)) {
			$this->addError($attribute, Craft::t(Plugin::HANDLE, 'Inventory grouping modes must be a keyed array.'));
			return;
		}

		$validModes = [self::GROUPING_MODE_TOGETHER, self::GROUPING_MODE_PER_ITEM];
		$bucketLabels = [
			self::INVENTORY_BUCKET_IN_STOCK => Craft::t(Plugin::HANDLE, 'In-stock items'),
			self::INVENTORY_BUCKET_BACKORDER => Craft::t(Plugin::HANDLE, 'Backordered items'),
		];

		foreach ($bucketLabels as $bucket => $bucketLabel) {
			$mode = $value[$bucket] ?? null;
			if (! is_string($mode) || ! in_array($mode, $validModes, true)) {
				$this->addError($attribute, Craft::t(Plugin::HANDLE, '{bucketLabel}: grouping mode must be one of {modes}.', [
					'bucketLabel' => $bucketLabel,
					'modes' => implode(', ', $validModes),
				]));
				return;
			}
		}
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public function setAttributes($values, $safeOnly = true): void
	{
		if (isset($values['lineItemStatusGroups']) && is_array($values['lineItemStatusGroups'])) {
			$values['lineItemStatusGroups'] = $this->normalizeRuleRows($values['lineItemStatusGroups'], 'statusHandles');
		}

		if (isset($values['shippingCategoryGroups']) && is_array($values['shippingCategoryGroups'])) {
			$values['shippingCategoryGroups'] = $this->normalizeRuleRows($values['shippingCategoryGroups'], 'categoryHandles');
		}

		parent::setAttributes($values, $safeOnly);
	}

	public function validateShippingCategoryGroups(string $attribute): void
	{
		$value = $this->{$attribute};
		if (! is_array($value)) {
			$this->addError($attribute, Craft::t(Plugin::HANDLE, 'Shipping-category groups must be a list of groups.'));
			return;
		}

		$validModes = [self::GROUPING_MODE_TOGETHER, self::GROUPING_MODE_PER_ITEM];
		$seenHandles = [];

		foreach ($value as $index => $group) {
			$groupLabel = Craft::t(Plugin::HANDLE, 'Group {number}', [
				'number' => $index + 1,
			]);

			if (! is_array($group)) {
				$this->addError($attribute, Craft::t(Plugin::HANDLE, '{groupLabel}: must be an array.', [
					'groupLabel' => $groupLabel,
				]));
				return;
			}

			$mode = $group['mode'] ?? '';
			if (! is_string($mode) || ! in_array($mode, $validModes, true)) {
				$this->addError($attribute, Craft::t(Plugin::HANDLE, '{groupLabel}: mode must be one of {modes}.', [
					'groupLabel' => $groupLabel,
					'modes' => implode(', ', $validModes),
				]));
				return;
			}

			$categoryHandles = $group['categoryHandles'] ?? [];
			if (! is_array($categoryHandles) || $categoryHandles === []) {
				$this->addError($attribute, Craft::t(Plugin::HANDLE, '{groupLabel}: assign at least one shipping category.', [
					'groupLabel' => $groupLabel,
				]));
				return;
			}

			foreach ($categoryHandles as $handle) {
				if (! is_string($handle) || $handle === '') {
					$this->addError($attribute, Craft::t(Plugin::HANDLE, '{groupLabel}: category handles must be non-empty strings.', [
						'groupLabel' => $groupLabel,
					]));
					return;
				}

				if (isset($seenHandles[$handle])) {
					$this->addError($attribute, Craft::t(Plugin::HANDLE, 'Shipping category “{handle}” is assigned to both {previousGroup} and {groupLabel}. Each category may belong to only one group.', [
						'handle' => $handle,
						'previousGroup' => $seenHandles[$handle],
						'groupLabel' => $groupLabel,
					]));
					return;
				}

				$seenHandles[$handle] = $groupLabel;
			}
		}
	}

	public function validateLineItemStatusGroups(string $attribute): void
	{
		$value = $this->{$attribute};
		if (! is_array($value)) {
			$this->addError($attribute, Craft::t(Plugin::HANDLE, 'Line item status groups must be a list of groups.'));
			return;
		}

		$validModes = [self::GROUPING_MODE_TOGETHER, self::GROUPING_MODE_PER_ITEM];
		$seenHandles = [];

		foreach ($value as $index => $group) {
			$groupLabel = Craft::t(Plugin::HANDLE, 'Group {number}', [
				'number' => $index + 1,
			]);

			if (! is_array($group)) {
				$this->addError($attribute, Craft::t(Plugin::HANDLE, '{groupLabel}: must be an array.', [
					'groupLabel' => $groupLabel,
				]));
				return;
			}

			$mode = $group['mode'] ?? '';
			if (! is_string($mode) || ! in_array($mode, $validModes, true)) {
				$this->addError($attribute, Craft::t(Plugin::HANDLE, '{groupLabel}: mode must be one of {modes}.', [
					'groupLabel' => $groupLabel,
					'modes' => implode(', ', $validModes),
				]));
				return;
			}

			$statusHandles = $group['statusHandles'] ?? [];
			if (! is_array($statusHandles) || $statusHandles === []) {
				$this->addError($attribute, Craft::t(Plugin::HANDLE, '{groupLabel}: assign at least one line item status.', [
					'groupLabel' => $groupLabel,
				]));
				return;
			}

			foreach ($statusHandles as $handle) {
				if (! is_string($handle) || $handle === '') {
					$this->addError($attribute, Craft::t(Plugin::HANDLE, '{groupLabel}: status handles must be non-empty strings.', [
						'groupLabel' => $groupLabel,
					]));
					return;
				}

				if (isset($seenHandles[$handle])) {
					$this->addError($attribute, Craft::t(Plugin::HANDLE, 'Status “{handle}” is assigned to both {previousGroup} and {groupLabel}. Each status may belong to only one group.', [
						'handle' => $handle,
						'previousGroup' => $seenHandles[$handle],
						'groupLabel' => $groupLabel,
					]));
					return;
				}

				$seenHandles[$handle] = $groupLabel;
			}
		}
	}

	/**
	 * @return array<array-key, mixed>
	 */
	protected function defineRules(): array
	{
		return [
			[['autoCreateOnComplete', 'enforceCoverage'], 'boolean'],
			[['lineItemStatusesToIgnore'],
				'each',
				'rule' => ['string']],
			[['orderStatusesToIgnore'],
				'each',
				'rule' => ['string']],
			[['qtySplitMode'],
				'in',
				'range' => [self::QTY_SPLIT_MODE_SPLIT, self::QTY_SPLIT_MODE_ATOMIC]],
			[['groupingSource'], 'string'],
			[['inventoryGroupingModes'], 'validateInventoryGroupingModes'],
			[['lineItemStatusGroups'], 'validateLineItemStatusGroups'],
			[['shippingCategoryGroups'], 'validateShippingCategoryGroups'],
			[[
				'autoCreateOnComplete',
				'enforceCoverage',
				'lineItemStatusesToIgnore',
				'orderStatusesToIgnore',
				'qtySplitMode',
				'groupingSource',
				'inventoryGroupingModes',
				'lineItemStatusGroups',
				'shippingCategoryGroups',
			], 'safe'],
		];
	}

	/**
	 * @param array<array-key, mixed> $raw
	 * @return list<array<string, mixed>>
	 */
	private function normalizeRuleRows(array $raw, string $handlesKey): array
	{
		$normalized = [];
		foreach ($raw as $row) {
			if (! is_array($row)) {
				continue;
			}

			$mode = is_string($row['mode'] ?? null) ? $row['mode'] : self::GROUPING_MODE_TOGETHER;
			$handlesRaw = $row[$handlesKey] ?? [];
			$handles = [];
			if (is_array($handlesRaw)) {
				foreach ($handlesRaw as $handle) {
					if (is_string($handle) && $handle !== '') {
						$handles[] = $handle;
					}
				}
			}

			if ($handles === []) {
				continue;
			}

			$normalized[] = [
				'mode' => $mode,
				$handlesKey => $handles,
			];
		}

		return $normalized;
	}
}
