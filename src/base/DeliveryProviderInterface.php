<?php

declare(strict_types=1);

namespace fostercommerce\shipments\base;

use craft\commerce\elements\Order;
use fostercommerce\shipments\elements\Shipment;
use fostercommerce\shipments\errors\DeliveryRejectedException;
use fostercommerce\shipments\models\Delivery;
use fostercommerce\shipments\models\DeliveryResult;
use fostercommerce\shipments\models\ShippingQuote;
use Throwable;

/** Optional capability for integrations which purchase shipping explicitly. */
interface DeliveryProviderInterface
{
	/**
	 * Check whether this integration can book the selected carrier service.
	 */
	public function supportsMethod(ShippingQuote $quote): bool;

	/**
	 * Purchase shipping once, returning any accepted reference before document retrieval.
	 *
	 * @param array<string, mixed> $options
	 * @param string $operationId Stable local UUID for carrier references and reconciliation.
	 * @throws DeliveryRejectedException Only when no carrier purchase could have occurred.
	 * @throws Throwable When the outcome may be uncertain; core must not repeat the purchase.
	 */
	public function createDelivery(Shipment $shipment, Order $order, ShippingQuote $quote, array $options, string $operationId): DeliveryResult;

	/**
	 * Retrieve an accepted delivery; this must never purchase shipping.
	 *
	 * @throws Throwable When retrieval fails; core retains the reference for another retrieval.
	 */
	public function fetchDelivery(Shipment $shipment, Delivery $delivery): DeliveryResult;

	/**
	 * Cancel the saved booking, returning only after the carrier confirms cancellation.
	 *
	 * @throws DeliveryRejectedException When cancellation was rejected and the prior state still applies.
	 * @throws Throwable When cancellation may have occurred but could not be confirmed.
	 */
	public function cancelDelivery(Shipment $shipment, Delivery $delivery): void;
}
