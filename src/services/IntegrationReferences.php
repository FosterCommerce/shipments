<?php

declare(strict_types=1);

namespace fostercommerce\shipments\services;

use Craft;
use craft\db\Query;
use craft\helpers\Typecast;
use fostercommerce\shipments\db\Table;
use fostercommerce\shipments\elements\Shipment;
use fostercommerce\shipments\models\Integration;
use fostercommerce\shipments\models\IntegrationReference;
use fostercommerce\shipments\Plugin;
use fostercommerce\shipments\records\IntegrationReference as IntegrationReferenceRecord;
use Throwable;
use yii\base\Component;
use yii\base\Exception;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;

/**
 * Per-shipment integration-reference rows. Upsert via `setIntegrationReference`, resolve back
 * from a webhook via `findByIntegrationReference`.
 */
class IntegrationReferences extends Component
{
	/**
	 * Upsert on (shipment, integration). Throws if the handle is unknown or the save fails.
	 *
	 * @throws Throwable
	 */
	public function setIntegrationReference(
		Shipment $shipment,
		string $integrationHandle,
		string $externalId,
		?string $url = null,
	): IntegrationReference {
		if ($shipment->id === null) {
			throw new InvalidArgumentException('Cannot set an integration reference on an unsaved shipment.');
		}

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();
		$integration = $plugin->integrations->getIntegrationByHandle($integrationHandle);
		if (! $integration instanceof Integration || $integration->id === null) {
			throw new InvalidConfigException(Craft::t(Plugin::HANDLE, 'Unknown integration handle: {handle}', [
				'handle' => $integrationHandle,
			]));
		}

		$record = IntegrationReferenceRecord::findOne([
			'shipmentId' => $shipment->id,
			'integrationId' => $integration->id,
		]);

		if (! $record instanceof IntegrationReferenceRecord) {
			$record = new IntegrationReferenceRecord();
			$record->shipmentId = $shipment->id;
			$record->integrationId = $integration->id;
		}

		$record->externalId = $externalId;
		$record->url = $url !== null && $url !== '' ? $url : null;

		if (! $record->save()) {
			$errors = $record->getFirstErrors();
			throw new Exception(Craft::t(Plugin::HANDLE, 'Couldn’t save shipment integration reference: {errors}', [
				'errors' => implode(', ', $errors),
			]));
		}

		return $this->modelFromRecord($record);
	}

	public function findByIntegrationReference(string $integrationHandle, string $externalId): ?Shipment
	{
		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();
		$integration = $plugin->integrations->getIntegrationByHandle($integrationHandle);
		if (! $integration instanceof Integration || $integration->id === null) {
			return null;
		}

		$row = (new Query())
			->select(['shipmentId'])
			->from(Table::INTEGRATION_REFERENCES)
			->where([
				'integrationId' => $integration->id,
				'externalId' => $externalId,
			])
			->one();

		if (! is_array($row) || ! is_numeric($row['shipmentId'] ?? null)) {
			return null;
		}

		return $plugin->shipments->findById((int) $row['shipmentId'], includeTrashed: true);
	}

	/**
	 * @return list<IntegrationReference>
	 */
	public function getReferencesForShipmentId(int $shipmentId): array
	{
		return $this->getReferencesForShipmentIds([$shipmentId])[$shipmentId] ?? [];
	}

	/**
	 * Batch variant keyed by shipmentId, used by eager loading.
	 *
	 * @param list<int> $shipmentIds
	 * @return array<int, list<IntegrationReference>>
	 */
	public function getReferencesForShipmentIds(array $shipmentIds): array
	{
		if ($shipmentIds === []) {
			return [];
		}

		/** @var list<array<string, mixed>> $rows */
		$rows = (new Query())
			->from(Table::INTEGRATION_REFERENCES)
			->where([
				'shipmentId' => $shipmentIds,
			])
			->orderBy([
				'id' => SORT_ASC,
			])
			->all();

		$byShipmentId = array_fill_keys($shipmentIds, []);
		foreach ($rows as $row) {
			$shipmentIdRaw = $row['shipmentId'] ?? null;
			if (! is_numeric($shipmentIdRaw)) {
				continue;
			}

			$byShipmentId[(int) $shipmentIdRaw][] = $this->modelFromRow($row);
		}

		return $byShipmentId;
	}

	public function deleteReferenceById(int $id): bool
	{
		$record = IntegrationReferenceRecord::findOne([
			'id' => $id,
		]);
		if (! $record instanceof IntegrationReferenceRecord) {
			return false;
		}

		return (bool) $record->delete();
	}

	/**
	 * Diff-and-apply the CP-posted references for a shipment. Unknown integrations and stale
	 * ids throw; malformed rows are logged and skipped. Caller owns the transaction.
	 *
	 * @param list<array{id?: ?int, integrationId: int, externalId: string, url: ?string}> $rows
	 * @throws Throwable
	 */
	public function saveReferencesForShipment(Shipment $shipment, array $rows): void
	{
		if ($shipment->id === null) {
			throw new InvalidArgumentException('Cannot save integration references for an unsaved shipment.');
		}

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();
		$knownSourceIds = [];
		foreach ($plugin->integrations->getAllIntegrations() as $knownSource) {
			if ($knownSource->id !== null) {
				$knownSourceIds[(int) $knownSource->id] = true;
			}
		}

		$existingRecords = IntegrationReferenceRecord::findAll([
			'shipmentId' => $shipment->id,
		]);
		$existingById = [];
		foreach ($existingRecords as $existingRecord) {
			if ($existingRecord->id !== null) {
				$existingById[(int) $existingRecord->id] = $existingRecord;
			}
		}

		$keptIds = [];
		foreach ($rows as $row) {
			$integrationId = $row['integrationId'];
			$externalId = trim($row['externalId']);
			if ($integrationId <= 0) {
				Craft::warning("Skipping integration-reference row for shipment {$shipment->id}: non-positive integrationId.", Plugin::HANDLE);
				continue;
			}

			if ($externalId === '') {
				Craft::warning("Skipping integration-reference row for shipment {$shipment->id}: blank externalId.", Plugin::HANDLE);
				continue;
			}

			if (! isset($knownSourceIds[$integrationId])) {
				throw new InvalidConfigException(Craft::t(Plugin::HANDLE, 'Unknown integration id: {id}', [
					'id' => $integrationId,
				]));
			}

			$postedId = $row['id'] ?? null;
			if ($postedId !== null && ! isset($existingById[$postedId])) {
				throw new Exception(Craft::t(Plugin::HANDLE, 'Integration reference {id} no longer exists. Please reload the page and retry.', [
					'id' => $postedId,
				]));
			}

			$record = $postedId !== null
				? $existingById[$postedId]
				: new IntegrationReferenceRecord();

			$record->shipmentId = $shipment->id;
			$record->integrationId = $integrationId;
			$record->externalId = $externalId;
			$record->url = $row['url'] !== null && $row['url'] !== '' ? $row['url'] : null;

			if (! $record->save()) {
				$errors = $record->getFirstErrors();
				throw new Exception(Craft::t(Plugin::HANDLE, 'Couldn’t save shipment integration reference: {errors}', [
					'errors' => implode(', ', $errors),
				]));
			}

			$keptIds[(int) $record->id] = true;
		}

		foreach ($existingById as $existingId => $existingRecord) {
			if (! isset($keptIds[$existingId])) {
				$existingRecord->delete();
			}
		}
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function modelFromRow(array $row): IntegrationReference
	{
		Typecast::properties(IntegrationReference::class, $row);
		return new IntegrationReference($row);
	}

	private function modelFromRecord(IntegrationReferenceRecord $record): IntegrationReference
	{
		/** @var array<string, mixed> $attributes */
		$attributes = $record->getAttributes();
		return $this->modelFromRow($attributes);
	}
}
