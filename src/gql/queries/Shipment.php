<?php

declare(strict_types=1);

namespace fostercommerce\shipments\gql\queries;

use craft\gql\base\Query;
use fostercommerce\shipments\gql\arguments\elements\ShipmentArguments;
use fostercommerce\shipments\gql\interfaces\elements\Shipment as ShipmentInterface;
use fostercommerce\shipments\gql\resolvers\elements\Shipment as ShipmentResolver;
use GraphQL\Type\Definition\Type;

/**
 * Top-level `shipments` / `shipmentCount` / `shipment` GraphQL queries.
 */
class Shipment extends Query
{
	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function getQueries(bool $checkToken = true): array
	{
		return [
			'shipments' => [
				'type' => Type::listOf(ShipmentInterface::getType()),
				'args' => ShipmentArguments::getArguments(),
				'resolve' => ShipmentResolver::class . '::resolve',
				'description' => 'Query for shipments.',
			],
			'shipmentCount' => [
				'type' => Type::nonNull(Type::int()),
				'args' => ShipmentArguments::getArguments(),
				'resolve' => ShipmentResolver::class . '::resolveCount',
				'description' => 'Count shipments.',
			],
			'shipment' => [
				'type' => ShipmentInterface::getType(),
				'args' => ShipmentArguments::getArguments(),
				'resolve' => ShipmentResolver::class . '::resolveOne',
				'description' => 'Query for a single shipment.',
			],
		];
	}
}
