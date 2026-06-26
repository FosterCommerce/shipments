<?php

declare(strict_types=1);

namespace fostercommerce\shipments\records;

use craft\db\ActiveRecord;
use fostercommerce\shipments\db\Table;

/**
 * @property int $id
 * @property int $shipmentId
 * @property int $lineItemId
 * @property int $qty
 * @property ?array $lineItemData
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class ShipmentLineItem extends ActiveRecord
{
	public static function tableName(): string
	{
		return Table::SHIPMENT_LINE_ITEMS;
	}
}
