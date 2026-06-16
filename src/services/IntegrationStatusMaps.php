<?php

declare(strict_types=1);

namespace fostercommerce\shipments\services;

use Craft;
use craft\db\Query;
use fostercommerce\shipments\db\Table;
use fostercommerce\shipments\enums\Status;
use fostercommerce\shipments\errors\IntegrationStatusMapException;
use fostercommerce\shipments\Plugin;
use fostercommerce\shipments\records\IntegrationStatusMap;
use Throwable;
use yii\base\Component;
use yii\base\InvalidArgumentException;

/** Per-integration two-way status-code mappings between external integrations and internal Status codes. */
class IntegrationStatusMaps extends Component
{
	public const DIRECTION_INBOUND = 'inbound';

	public const DIRECTION_OUTBOUND = 'outbound';

	public const DIRECTION_BIDIRECTIONAL = 'bidirectional';

	/**
	 * @return list<array<string, mixed>>
	 */
	public function findForIntegration(int $integrationId): array
	{
		/** @var list<array<string, mixed>> $rows */
		$rows = (new Query())
			->from(Table::INTEGRATION_STATUS_MAPS)
			->where([
				'[[integrationId]]' => $integrationId,
			])
			->orderBy([
				'[[externalCode]]' => SORT_ASC,
			])
			->all();
		return $rows;
	}

	/**
	 * Inbound translate: takes an external code and returns the Status case, or null if unmapped.
	 */
	public function resolveInbound(int $integrationId, string $externalCode): ?Status
	{
		$row = (new Query())
			->select(['[[internalCode]]'])
			->from(Table::INTEGRATION_STATUS_MAPS)
			->where([
				'[[integrationId]]' => $integrationId,
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

		return Status::tryFrom($internalCode);
	}

	/**
	 * Outbound translate: takes a Status case and returns the external code, or null if unmapped.
	 */
	public function resolveOutbound(int $integrationId, Status $internal): ?string
	{
		$row = (new Query())
			->select(['[[externalCode]]'])
			->from(Table::INTEGRATION_STATUS_MAPS)
			->where([
				'[[integrationId]]' => $integrationId,
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
		string $direction,
		string $externalCode,
		?string $externalLabel,
		string $internalCode,
	): IntegrationStatusMap {
		if (! in_array($direction, [self::DIRECTION_INBOUND, self::DIRECTION_OUTBOUND, self::DIRECTION_BIDIRECTIONAL], true)) {
			throw new InvalidArgumentException("Invalid direction \"{$direction}\".");
		}

		if (! Status::tryFrom($internalCode) instanceof Status) {
			throw new InvalidArgumentException("Unknown status code \"{$internalCode}\".");
		}

		$record = IntegrationStatusMap::findOne([
			'integrationId' => $integrationId,
			'direction' => $direction,
			'externalCode' => $externalCode,
		]);

		if (! $record instanceof IntegrationStatusMap) {
			$record = new IntegrationStatusMap();
			$record->integrationId = $integrationId;
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
