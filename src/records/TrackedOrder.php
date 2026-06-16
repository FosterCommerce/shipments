<?php

declare(strict_types=1);

namespace fostercommerce\shipments\records;

use craft\commerce\records\Order;
use craft\db\ActiveRecord;
use DateTime;
use fostercommerce\shipments\db\Table;
use yii\db\ActiveQueryInterface;

/**
 * Per-order tracking row for the Attention page. Enum columns store string case values.
 *
 * @property int $id
 * @property int $orderId
 * @property string $shippable
 * @property string $state
 * @property string $underAllocated
 * @property ?DateTime $evaluatedAt
 * @property ?DateTime $orderStatusAdvancedAt
 * @property DateTime $trackedAt
 * @property ActiveQueryInterface $order
 */
class TrackedOrder extends ActiveRecord
{
	public static function tableName(): string
	{
		return Table::TRACKED_ORDERS;
	}

	public function getOrder(): ActiveQueryInterface
	{
		return $this->hasOne(Order::class, [
			'id' => 'orderId',
		]);
	}
}
