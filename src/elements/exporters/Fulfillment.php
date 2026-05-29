<?php

declare(strict_types=1);

namespace fostercommerce\shipments\elements\exporters;

use Craft;
use craft\base\ElementExporter;
use craft\commerce\elements\Order;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\Db;
use craft\helpers\Json;
use fostercommerce\shipments\elements\db\ShipmentQuery;
use fostercommerce\shipments\elements\Shipment;
use fostercommerce\shipments\Plugin;

/**
 * Fulfillment-ops CSV: one row per shipment with the columns warehouse and CS
 * teams ask for (reference, order, status labels, carrier, tracking, ship/deliver
 * dates). Streams via `Db::each()` and eager-loads the order so the per-row
 * order reference doesn't fan out into N queries.
 */
class Fulfillment extends ElementExporter
{
	public static function displayName(): string
	{
		return Craft::t(Plugin::HANDLE, 'emails.fulfillmentDigest');
	}

	/**
	 * @return iterable<int, array<string, mixed>>
	 */
	public function export(ElementQueryInterface $query): iterable
	{
		/** @var ShipmentQuery $query */
		$query->with(['order']);

		foreach (Db::each($query) as $shipment) {
			/** @var Shipment $shipment */
			yield $this->row($shipment);
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function row(Shipment $shipment): array
	{
		$order = $shipment->getOrder();

		$row = [
			'reference' => $shipment->reference,
			'order' => $this->orderLabel($order),
			'orderId' => $shipment->orderId,
			'fulfillmentStatus' => $shipment->getFulfillmentStatusEnum()->label(),
			'shippingStatus' => $shipment->getShippingStatusEnum()?->label(),
			'carrier' => $shipment->carrier,
			'service' => $shipment->service,
			'trackingNumber' => $shipment->trackingNumber,
			'trackingUrl' => $shipment->trackingUrl,
			'dateScheduledShip' => $shipment->dateScheduledShip?->format('c'),
			'dateShipped' => $shipment->getDateShipped()?->format('c'),
			'dateDelivered' => $shipment->getDateDelivered()?->format('c'),
			'dateCreated' => $shipment->dateCreated?->format('c'),
			'enabled' => $shipment->enabled ? 'yes' : 'no',
			'fulfillmentNotes' => $shipment->fulfillmentNotes,
			'shippingNotes' => $shipment->shippingNotes,
		];

		foreach ($shipment->getSerializedFieldValues() as $handle => $value) {
			$row['field_' . $handle] = is_scalar($value) ? $value : Json::encode($value);
		}

		return $row;
	}

	private function orderLabel(?Order $order): ?string
	{
		if (! $order instanceof Order) {
			return null;
		}

		if ($order->reference !== null && $order->reference !== '') {
			return $order->reference;
		}

		return '#' . ($order->number ?? (string) $order->id);
	}
}
