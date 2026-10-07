<?php

declare(strict_types=1);

namespace fostercommerce\shipments\base;

use craft\commerce\elements\Order;
use fostercommerce\shipments\models\ShippingQuote;

/** Optional carrier quotes for an independently configured delivery integration. */
interface ShippingRateProviderInterface
{
	/**
	 * @return non-empty-array<string, string> Service codes mapped to labels.
	 */
	public function getServices(): array;

	/**
	 * @return list<array{source: string, sourceService: string, service: string}>
	 */
	public function getCarrierMappings(): array;

	/**
	 * Quote carrier services without purchasing shipping or saving the order.
	 *
	 * @param list<string> $services Mapped integration services to quote.
	 * @param array<string, mixed> $options
	 * @return list<ShippingQuote>
	 * @throws \Throwable When the carrier cannot return usable rates.
	 */
	public function getShippingQuotes(Order $order, ShippingQuote $method, array $services, array $options): array;

	/**
	 * Get resolved settings for hashing, including credentials that affect rate eligibility.
	 *
	 * Values must never be persisted in quote snapshots or logs.
	 *
	 * @return array<string, mixed>
	 */
	public function getShippingConfiguration(): array;
}
