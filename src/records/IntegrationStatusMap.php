<?php

declare(strict_types=1);

namespace fostercommerce\shipments\records;

use craft\db\ActiveRecord;
use fostercommerce\shipments\db\Table;
use yii\db\ActiveQueryInterface;

/**
 * @property int $id
 * @property int $integrationId
 * @property string $direction      'inbound' | 'outbound' | 'bidirectional'
 * @property string $externalCode   code from the integration's vocabulary
 * @property ?string $externalLabel optional human label for CP mapping UI
 * @property string $internalCode   Status value in our vocabulary
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 * @property ?Integration $integration
 */
class IntegrationStatusMap extends ActiveRecord
{
	public static function tableName(): string
	{
		return Table::INTEGRATION_STATUS_MAPS;
	}

	public function getIntegration(): ActiveQueryInterface
	{
		return $this->hasOne(Integration::class, [
			'id' => 'integrationId',
		]);
	}
}
