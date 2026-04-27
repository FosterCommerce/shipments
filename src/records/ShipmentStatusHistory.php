<?php

declare(strict_types=1);

namespace fostercommerce\shipments\records;

use craft\db\ActiveRecord;
use craft\records\User;
use fostercommerce\shipments\db\Table;
use yii\db\ActiveQueryInterface;

/**
 * @property int $id
 * @property int $shipmentId
 * @property string $axis              'fulfillment' | 'shipping'
 * @property ?string $fromCode         enum value of the axis, or null on first entry
 * @property string $toCode            enum value of the axis
 * @property ?string $message
 * @property ?int $userId
 * @property ?int $sourceIntegrationId
 * @property ?string $sourceExternalCode
 * @property string $dateCreated
 * @property string $uid
 * @property ?User $user
 * @property ?Integration $sourceIntegration
 */
class ShipmentStatusHistory extends ActiveRecord
{
	public static function tableName(): string
	{
		return Table::SHIPMENT_STATUS_HISTORY;
	}

	public function getUser(): ActiveQueryInterface
	{
		return $this->hasOne(User::class, [
			'id' => 'userId',
		]);
	}

	public function getSourceIntegration(): ActiveQueryInterface
	{
		return $this->hasOne(Integration::class, [
			'id' => 'sourceIntegrationId',
		]);
	}
}
