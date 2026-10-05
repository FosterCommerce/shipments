<?php

declare(strict_types=1);

namespace fostercommerce\shipments\base;

use craft\commerce\elements\Order;
use fostercommerce\shipments\models\ShippingQuote;

interface BatchShippingRateProviderInterface extends ShippingRateProviderInterface
{
	/**
	 * @param list<array{method: ShippingQuote, services: list<string>}> $methods
	 * @param array<string, mixed> $options
	 * @return list<ShippingQuote>
	 */
	public function getShippingQuotesForMethods(Order $order, array $methods, array $options): array;
}
