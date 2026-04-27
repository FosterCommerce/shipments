<?php

declare(strict_types=1);

namespace fostercommerce\shipments\records;

use craft\db\ActiveRecord;
use fostercommerce\shipments\db\Table;

/**
 * @property int $id
 * @property int $shipmentId
 * @property int $integrationId
 * @property string $externalId
 * @property ?string $url
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class IntegrationReference extends ActiveRecord
{
	public static function tableName(): string
	{
		return Table::INTEGRATION_REFERENCES;
	}
}
