<?php

declare(strict_types=1);

namespace fostercommerce\shipments\models;

use craft\base\Model;
use DateTime;

class ShipmentLineItem extends Model
{
	public ?int $id = null;

	public int $shipmentId = 0;

	public int $lineItemId;

	public int $qty;

	public ?DateTime $dateCreated = null;

	public ?DateTime $dateUpdated = null;

	public ?string $uid = null;

	/**
	 * @return array<array-key, mixed>
	 */
	protected function defineRules(): array
	{
		return [
			[['lineItemId', 'qty'], 'required'],
			[['shipmentId', 'lineItemId'], 'integer'],
			[['qty'],
				'integer',
				'min' => 1],
		];
	}
}
