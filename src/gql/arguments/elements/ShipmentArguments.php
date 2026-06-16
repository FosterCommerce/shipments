<?php

declare(strict_types=1);

namespace fostercommerce\shipments\gql\arguments\elements;

use craft\gql\base\ElementArguments;
use GraphQL\Type\Definition\Type;

/**
 * GraphQL arguments for the `shipments` query.
 */
class ShipmentArguments extends ElementArguments
{
	/**
	 * @return array<string, mixed>
	 */
	public static function getArguments(): array
	{
		return array_merge(parent::getArguments(), [
			'orderId' => [
				'name' => 'orderId',
				'type' => Type::listOf(Type::int()),
				'description' => 'Narrow by order id(s).',
			],
			'reference' => [
				'name' => 'reference',
				'type' => Type::listOf(Type::string()),
				'description' => 'Narrow by shipment reference(s).',
			],
			'trackingNumber' => [
				'name' => 'trackingNumber',
				'type' => Type::listOf(Type::string()),
				'description' => 'Narrow by tracking number(s).',
			],
			'carrier' => [
				'name' => 'carrier',
				'type' => Type::listOf(Type::string()),
				'description' => 'Narrow by carrier.',
			],
			'service' => [
				'name' => 'service',
				'type' => Type::listOf(Type::string()),
				'description' => 'Narrow by service.',
			],
			'integrationId' => [
				'name' => 'integrationId',
				'type' => Type::listOf(Type::int()),
				'description' => 'Narrow to shipments referenced by the given integration(s).',
			],
		]);
	}
}
