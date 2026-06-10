<?php

declare(strict_types=1);

namespace fostercommerce\shipments\services;

use Craft;
use craft\db\Query;
use fostercommerce\shipments\db\Table;
use fostercommerce\shipments\enums\FulfillmentStatus;
use fostercommerce\shipments\enums\ShippingStatus;
use fostercommerce\shipments\enums\StatusAxis;
use fostercommerce\shipments\errors\IntegrationStatusMapException;
use fostercommerce\shipments\Plugin;
use fostercommerce\shipments\records\IntegrationStatusMap;
use Throwable;
use yii\base\Component;
use yii\base\InvalidArgumentException;

/** Per-integration two-way status-code mappings between external integrations and internal enums (fulfillment + shipping axes). */
class IntegrationStatusMaps extends Component
{
	public const DIRECTION_INBOUND = 'inbound';

	public const DIRECTION_OUTBOUND = 'outbound';

	public const DIRECTION_BIDIRECTIONAL = 'bidirectional';

	/**
	 * @return list<array<string, mixed>>
	 */
	public function findForIntegration(int $integrationId, ?StatusAxis $axis = null): array
	{
		$query = (new Query())
			->from(Table::INTEGRATION_STATUS_MAPS)
			->where([
				'[[integrationId]]' => $integrationId,
			])
			->orderBy([
				'[[axis]]' => SORT_ASC,
				'[[externalCode]]' => SORT_ASC,
			]);

		if ($axis instanceof StatusAxis) {
			$query->andWhere([
				'[[axis]]' => $axis->value,
			]);
		}

		/** @var list<array<string, mixed>> $rows */
		$rows = $query->all();
		return $rows;
	}

	/**
	 * Inbound translate: takes an external code and returns the internal enum case, or null if unmapped.
	 */
	public function resolveInbound(int $integrationId, StatusAxis $axis, string $externalCode): FulfillmentStatus|ShippingStatus|null
	{
		$row = (new Query())
			->select(['[[internalCode]]'])
			->from(Table::INTEGRATION_STATUS_MAPS)
			->where([
				'[[integrationId]]' => $integrationId,
				'[[axis]]' => $axis->value,
				'[[externalCode]]' => $externalCode,
			])
			->andWhere([
				'or',
				[
					'[[direction]]' => self::DIRECTION_INBOUND,
				],
				[
					'[[direction]]' => self::DIRECTION_BIDIRECTIONAL,
				],
			])
			->one();

		if (! is_array($row)) {
			return null;
		}

		$internalCode = $row['internalCode'] ?? null;
		if (! is_string($internalCode)) {
			return null;
		}

		return $axis->resolveCode($internalCode);
	}

	/**
	 * Outbound translate: takes an internal enum case and returns the external code, or null if unmapped.
	 */
	public function resolveOutbound(int $integrationId, StatusAxis $axis, FulfillmentStatus|ShippingStatus $internal): ?string
	{
		$row = (new Query())
			->select(['[[externalCode]]'])
			->from(Table::INTEGRATION_STATUS_MAPS)
			->where([
				'[[integrationId]]' => $integrationId,
				'[[axis]]' => $axis->value,
				'[[internalCode]]' => $internal->value,
			])
			->andWhere([
				'or',
				[
					'[[direction]]' => self::DIRECTION_OUTBOUND,
				],
				[
					'[[direction]]' => self::DIRECTION_BIDIRECTIONAL,
				],
			])
			->one();

		if (! is_array($row)) {
			return null;
		}

		$externalCode = $row['externalCode'] ?? null;
		return is_string($externalCode) ? $externalCode : null;
	}

	/**
	 * Upsert a single mapping row.
	 *
	 * @throws Throwable
	 */
	public function saveMap(
		int $integrationId,
		StatusAxis $axis,
		string $direction,
		string $externalCode,
		?string $externalLabel,
		string $internalCode,
	): IntegrationStatusMap {
		if (! in_array($direction, [self::DIRECTION_INBOUND, self::DIRECTION_OUTBOUND, self::DIRECTION_BIDIRECTIONAL], true)) {
			throw new InvalidArgumentException("Invalid direction \"{$direction}\".");
		}

		if ($axis->resolveCode($internalCode) === null) {
			throw new InvalidArgumentException("Unknown {$axis->value} code \"{$internalCode}\".");
		}

		$record = IntegrationStatusMap::findOne([
			'integrationId' => $integrationId,
			'axis' => $axis->value,
			'direction' => $direction,
			'externalCode' => $externalCode,
		]);

		if (! $record instanceof IntegrationStatusMap) {
			$record = new IntegrationStatusMap();
			$record->integrationId = $integrationId;
			$record->axis = $axis->value;
			$record->direction = $direction;
			$record->externalCode = $externalCode;
		}

		$record->externalLabel = $externalLabel;
		$record->internalCode = $internalCode;

		if (! $record->save()) {
			$errors = $record->getFirstErrors();
			throw new IntegrationStatusMapException(Craft::t(Plugin::HANDLE, 'error.couldNotSaveIntegrationStatusMap', [
				'errors' => implode(', ', $errors),
			]));
		}

		return $record;
	}

	public function deleteMapById(int $id): bool
	{
		$record = IntegrationStatusMap::findOne([
			'id' => $id,
		]);

		if (! $record instanceof IntegrationStatusMap) {
			return false;
		}

		return $record->delete() !== false;
	}

	public function deleteAllForIntegration(int $integrationId): int
	{
		$deletedCount = 0;
		foreach (IntegrationStatusMap::findAll([
			'integrationId' => $integrationId,
		]) as $record) {
			if ($record->delete() !== false) {
				$deletedCount++;
			}
		}

		return $deletedCount;
	}
}
