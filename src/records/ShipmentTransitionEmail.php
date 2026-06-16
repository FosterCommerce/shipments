<?php

declare(strict_types=1);

namespace fostercommerce\shipments\records;

use craft\db\ActiveRecord;
use fostercommerce\shipments\db\Table;
use yii\db\ActiveQueryInterface;

/**
 * @property int $id
 * @property string $toCode       Status value the transition must land on to trigger
 * @property int $emailId
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 * @property ?Email $email
 */
class ShipmentTransitionEmail extends ActiveRecord
{
	public static function tableName(): string
	{
		return Table::TRANSITION_EMAILS;
	}

	public function getEmail(): ActiveQueryInterface
	{
		return $this->hasOne(Email::class, [
			'id' => 'emailId',
		]);
	}
}
