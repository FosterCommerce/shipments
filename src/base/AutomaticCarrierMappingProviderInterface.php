<?php

declare(strict_types=1);

namespace fostercommerce\shipments\base;

interface AutomaticCarrierMappingProviderInterface extends ShippingRateProviderInterface
{
	/**
	 * The Postie provider class name; Postie remains an optional dependency.
	 */
	public function getAutomaticMappingProviderType(): string;

	/**
	 * @return list<string>
	 */
	public function getAutoMappedServices(string $sourceService): array;
}
