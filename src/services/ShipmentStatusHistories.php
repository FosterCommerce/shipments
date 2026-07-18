<?php

declare(strict_types=1);

namespace fostercommerce\shipments\services;

use craft\db\Query;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use DateTimeInterface;
use fostercommerce\shipments\db\Table;
use fostercommerce\shipments\enums\Status;
use fostercommerce\shipments\models\ShipmentStatusHistoryEntry;
use fostercommerce\shipments\Plugin;
use yii\base\Component;

/**
 * Shipment status-history reads.
 */
class ShipmentStatusHistories extends Component
{
	/**
	 * @return list<ShipmentStatusHistoryEntry>
	 */
	public function getForShipmentId(int $shipmentId): array
	{
		/** @var list<array<string, mixed>> $rows */
		$rows = (new Query())
			->from(Table::SHIPMENT_STATUS_HISTORY)
			->where([
				'shipmentId' => $shipmentId,
			])
			->orderBy([
				'dateCreated' => SORT_DESC,
				'id' => SORT_DESC,
			])
			->all();

		if ($rows === []) {
			return [];
		}

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		// Batch-fetch users + integrations referenced across all rows so we don't
		// issue one lookup per history entry.
		$userIds = [];
		$integrationIds = [];
		foreach ($rows as $row) {
			if (is_numeric($row['userId'] ?? null)) {
				$userIds[(int) $row['userId']] = true;
			}

			if (is_numeric($row['sourceIntegrationId'] ?? null)) {
				$integrationIds[(int) $row['sourceIntegrationId']] = true;
			}
		}

		$usersById = [];
		if ($userIds !== []) {
			$userIdList = array_keys($userIds);
			$users = User::find()->id($userIdList)->status(null)->all();
			foreach ($users as $user) {
				if ($user->id !== null) {
					$usersById[$user->id] = $user;
				}
			}
		}

		$integrationsById = [];
		foreach (array_keys($integrationIds) as $integrationId) {
			$integration = $plugin->integrations->getIntegrationById($integrationId);
			if ($integration !== null) {
				$integrationsById[$integrationId] = $integration;
			}
		}

		$entries = [];
		foreach ($rows as $row) {
			$toCodeRaw = $row['toCode'] ?? null;
			$toCode = is_string($toCodeRaw) ? Status::tryFrom($toCodeRaw) : null;

			$fromCodeRaw = $row['fromCode'] ?? null;
			$fromCode = is_string($fromCodeRaw) && $fromCodeRaw !== '' ? Status::tryFrom($fromCodeRaw) : null;

			$user = null;
			if (is_numeric($row['userId'] ?? null)) {
				$user = $usersById[(int) $row['userId']] ?? null;
			}

			$dateCreatedRaw = $row['dateCreated'] ?? null;
			$dateCreated = null;
			if (is_string($dateCreatedRaw) || is_int($dateCreatedRaw) || is_array($dateCreatedRaw) || $dateCreatedRaw instanceof DateTimeInterface) {
				$dateCreated = DateTimeHelper::toDateTime($dateCreatedRaw) ?: null;
			}

			$messageRaw = $row['message'] ?? null;
			$message = is_scalar($messageRaw) ? (string) $messageRaw : null;

			$sourceIntegration = null;
			if (is_numeric($row['sourceIntegrationId'] ?? null)) {
				$sourceIntegration = $integrationsById[(int) $row['sourceIntegrationId']] ?? null;
			}

			$sourceExternalCodeRaw = $row['sourceExternalCode'] ?? null;
			$sourceExternalCode = is_string($sourceExternalCodeRaw) && $sourceExternalCodeRaw !== '' ? $sourceExternalCodeRaw : null;

			$entries[] = new ShipmentStatusHistoryEntry(
				fromCode: $fromCode,
				toCode: $toCode,
				user: $user,
				date: $dateCreated,
				message: $message,
				sourceIntegration: $sourceIntegration,
				sourceExternalCode: $sourceExternalCode,
			);
		}

		return $entries;
	}
}
