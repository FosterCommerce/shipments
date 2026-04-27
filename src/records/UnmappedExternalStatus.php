<?php

declare(strict_types=1);

namespace fostercommerce\shipments\records;

use craft\db\ActiveRecord;
use DateTime;
use fostercommerce\shipments\db\Table;
use yii\db\ActiveQueryInterface;

/**
 * @property int $id
 * @property int $integrationId
 * @property string $axis            'fulfillment' | 'shipping'
 * @property string $externalCode    code seen on the wire that has no mapping
 * @property int $occurrenceCount    bumps each time the same code arrives
 * @property DateTime|string $dateFirstSeen
 * @property DateTime|string $dateLastSeen
 * @property DateTime|string|null $resolvedAt cleared on next sighting; set when admin adds a mapping
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 * @property ?Integration $integration
 */
class UnmappedExternalStatus extends ActiveRecord
{
	public static function tableName(): string
	{
		return Table::UNMAPPED_EXTERNAL_STATUSES;
	}

	public function getIntegration(): ActiveQueryInterface
	{
		return $this->hasOne(Integration::class, [
			'id' => 'integrationId',
		]);
	}
}
