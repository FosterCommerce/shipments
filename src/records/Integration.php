<?php

declare(strict_types=1);

namespace fostercommerce\shipments\records;

use craft\db\ActiveRecord;
use fostercommerce\shipments\db\Table;

/**
 * @property int $id
 * @property string $name
 * @property string $handle
 * @property ?string $urlTemplate
 * @property ?string $provider
 * @property ?string $settings
 * @property bool $enabled
 * @property int $sortOrder
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class Integration extends ActiveRecord
{
	public static function tableName(): string
	{
		return Table::INTEGRATIONS;
	}
}
