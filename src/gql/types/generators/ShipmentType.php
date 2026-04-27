<?php

declare(strict_types=1);

namespace fostercommerce\shipments\gql\types\generators;

use craft\gql\base\Generator;
use craft\gql\base\GeneratorInterface;
use craft\gql\base\ObjectType;
use craft\gql\base\SingleGeneratorInterface;
use craft\gql\GqlEntityRegistry;
use fostercommerce\shipments\elements\Shipment as ShipmentElement;
use fostercommerce\shipments\gql\interfaces\elements\Shipment as ShipmentInterface;
use fostercommerce\shipments\gql\types\elements\Shipment as ShipmentGqlType;

/**
 * GraphQL type generator for the {@see ShipmentElement} element.
 */
class ShipmentType extends Generator implements GeneratorInterface, SingleGeneratorInterface
{
	/**
	 * @return list<ObjectType>
	 */
	public static function generateTypes(mixed $context = null): array
	{
		return [static::generateType($context)];
	}

	public static function generateType(mixed $context): ObjectType
	{
		$typeName = ShipmentElement::gqlTypeNameByContext($context);

		/** @var ObjectType $type */
		$type = GqlEntityRegistry::getOrCreate($typeName, fn (): ShipmentGqlType => new ShipmentGqlType([
			'name' => $typeName,
			'fields' => fn (): array => ShipmentInterface::getFieldDefinitions(),
		]));

		return $type;
	}

	public static function getName(mixed $context = null): string
	{
		return ShipmentElement::gqlTypeNameByContext($context);
	}
}
