<?php

declare(strict_types=1);

namespace fostercommerce\shipments\records;

use craft\db\ActiveRecord;
use fostercommerce\shipments\db\Table;

/**
 * Carrier delivery record.
 *
 * @property int $id
 * @property int $shipmentId
 * @property int $integrationId
 * @property string $operationId
 * @property string $status
 * @property ?string $externalId
 * @property string $carrier
 * @property string $service
 * @property ?string $trackingNumber
 * @property ?string $trackingUrl
 * @property ?int $transitDays
 * @property ?string $costAmount
 * @property ?string $costCurrency
 * @property ?string $metadata
 * @property ?string $requestData
 * @property ?string $references
 * @property ?string $documents
 * @property ?string $lastError
 * @property ?int $createdBy
 * @property ?string $dateNotified
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class Delivery extends ActiveRecord
{
	public static function tableName(): string
	{
		return Table::DELIVERIES;
	}
}
