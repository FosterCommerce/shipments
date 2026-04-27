<?php

declare(strict_types=1);

namespace fostercommerce\shipments\records;

use craft\db\ActiveRecord;
use DateTime;
use fostercommerce\shipments\db\Table;
use yii\db\ActiveQueryInterface;

/**
 * One row per carrier-reported event. Deduped by `eventHash` (unique). Drives the
 * `shippingStatus` projection on the shipment element.
 *
 * @property int $id
 * @property int $shipmentId
 * @property ?int $integrationId
 * @property string $code             normalized ShippingStatus enum value
 * @property ?string $description
 * @property DateTime|string $dateOccurred
 * @property DateTime|string $receivedAt
 * @property ?string $locationCity
 * @property ?string $locationRegion
 * @property ?string $locationCountry
 * @property ?string $rawPayload
 * @property string $eventHash
 * @property string $reason             CarrierEventReason value (projected | skipped_disabled_shipment)
 * @property string $dateCreated
 * @property string $uid
 * @property ?Integration $integration
 */
class CarrierEvent extends ActiveRecord
{
	public static function tableName(): string
	{
		return Table::CARRIER_EVENTS;
	}

	public function getIntegration(): ActiveQueryInterface
	{
		return $this->hasOne(Integration::class, [
			'id' => 'integrationId',
		]);
	}
}
