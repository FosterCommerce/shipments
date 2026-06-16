<?php

declare(strict_types=1);

namespace fostercommerce\shipments\events;

use craft\commerce\elements\Order;
use craft\elements\User;
use fostercommerce\shipments\elements\Shipment;
use yii\base\Event;

/**
 * Fired inside the write transaction whenever an existing shipment's line-item allocation is
 * edited in place via {@see \fostercommerce\shipments\services\Shipments::saveLineItems}.
 * Providers listen to push the revised shipment to a remote system as an update.
 *
 * `previousQtys` and `newQtys` are `lineItemId => qty` maps. A line item present in
 * `previousQtys` but absent from `newQtys` was removed; one present only in `newQtys` was
 * added.
 *
 * ```php
 * use fostercommerce\shipments\events\ShipmentLineItemsChangedEvent;
 * use fostercommerce\shipments\services\Shipments;
 * use yii\base\Event;
 *
 * Event::on(Shipments::class, Shipments::EVENT_SHIPMENT_LINE_ITEMS_CHANGED, function (ShipmentLineItemsChangedEvent $event) {
 *     // $event->shipment->getLineItems() already reflects the new allocation.
 * });
 * ```
 */
class ShipmentLineItemsChangedEvent extends Event
{
	public Shipment $shipment;

	public Order $order;

	/**
	 * @var array<int, int>
	 */
	public array $previousQtys = [];

	/**
	 * @var array<int, int>
	 */
	public array $newQtys = [];

	public ?User $user = null;
}
