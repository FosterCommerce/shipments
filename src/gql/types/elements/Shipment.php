<?php

declare(strict_types=1);

namespace fostercommerce\shipments\gql\types\elements;

use craft\gql\types\elements\Element as ElementType;
use fostercommerce\shipments\elements\Shipment as ShipmentElement;
use fostercommerce\shipments\gql\interfaces\elements\Shipment as ShipmentInterface;
use GraphQL\Type\Definition\ResolveInfo;

/**
 * GraphQL object type for the {@see ShipmentElement} element.
 */
class Shipment extends ElementType
{
	/**
	 * @param array<string, mixed> $config
	 */
	public function __construct(array $config)
	{
		$config['interfaces'] = [
			ShipmentInterface::getType(),
		];

		parent::__construct($config);
	}

	/**
	 * @param array<string, mixed> $arguments
	 */
	protected function resolve(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): mixed
	{
		/** @var ShipmentElement $source */
		$fieldName = $resolveInfo->fieldName;

		return match ($fieldName) {
			'orderReference' => $source->getOrder()?->reference,
			default => parent::resolve($source, $arguments, $context, $resolveInfo),
		};
	}
}
