<?php

declare(strict_types=1);

namespace fostercommerce\shipments\records;

use craft\commerce\records\Order;
use craft\db\ActiveRecord;
use craft\records\Element;
use DateTime;
use fostercommerce\shipments\db\Table;
use yii\db\ActiveQueryInterface;

/**
 * @property int $id
 * @property int $orderId
 * @property string $reference
 * @property int $number
 * @property string $fulfillmentStatus
 * @property ?string $shippingStatus
 * @property ?DateTime $dateShippingStatus
 * @property ?DateTime $dateScheduledShip
 * @property ?string $trackingNumber
 * @property ?string $trackingUrl
 * @property ?string $carrier
 * @property ?string $service
 * @property ?string $fulfillmentNotes
 * @property ?string $shippingNotes
 * @property ?DateTime $dateLastPushAttempt
 * @property ?string $lastPushAttemptError
 * @property int $pushAttemptCount
 * @property ActiveQueryInterface $element
 * @property ActiveQueryInterface $order
 */
class Shipment extends ActiveRecord
{
	public static function tableName(): string
	{
		return Table::SHIPMENTS;
	}

	public function getElement(): ActiveQueryInterface
	{
		return $this->hasOne(Element::class, [
			'id' => 'id',
		]);
	}

	public function getOrder(): ActiveQueryInterface
	{
		return $this->hasOne(Order::class, [
			'id' => 'orderId',
		]);
	}
}
