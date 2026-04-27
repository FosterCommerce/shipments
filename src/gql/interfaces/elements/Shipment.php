<?php

declare(strict_types=1);

namespace fostercommerce\shipments\gql\interfaces\elements;

use Craft;
use craft\gql\GqlEntityRegistry;
use craft\gql\interfaces\Element;
use fostercommerce\shipments\elements\Shipment as ShipmentElement;
use fostercommerce\shipments\gql\types\generators\ShipmentType;
use fostercommerce\shipments\Plugin;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\Type;

/**
 * GraphQL interface for {@see ShipmentElement}.
 */
class Shipment extends Element
{
	public static function getTypeGenerator(): string
	{
		return ShipmentType::class;
	}

	public static function getName(): string
	{
		return 'ShipmentInterface';
	}

	public static function getType(mixed $fields = null): Type
	{
		$existing = GqlEntityRegistry::getEntity(self::getName());
		if ($existing instanceof Type) {
			return $existing;
		}

		$created = GqlEntityRegistry::createEntity(self::getName(), new InterfaceType([
			'name' => static::getName(),
			'fields' => self::class . '::getFieldDefinitions',
			'description' => 'Interface implemented by all Shipment elements.',
			'resolveType' => static fn (ShipmentElement $value): string => $value->getGqlTypeName(),
		]));

		ShipmentType::generateTypes();

		/** @var Type $created */
		return $created;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function getFieldDefinitions(): array
	{
		return Craft::$app->getGql()->prepareFieldDefinitions(array_merge(parent::getFieldDefinitions(), [
			'reference' => [
				'name' => 'reference',
				'type' => Type::string(),
				'description' => 'Shipment reference, e.g. `00066502-s001`.',
			],
			'number' => [
				'name' => 'number',
				'type' => Type::int(),
				'description' => 'Per-order sequence integer.',
			],
			'orderId' => [
				'name' => 'orderId',
				'type' => Type::int(),
				'description' => 'Commerce order id this shipment belongs to.',
			],
			'orderReference' => [
				'name' => 'orderReference',
				'type' => Type::string(),
				'description' => 'Convenience: the parent order’s reference.',
			],
			'fulfillmentStatus' => [
				'name' => 'fulfillmentStatus',
				'type' => Type::string(),
				'description' => 'Merchant/3PL fulfillment status code.',
			],
			'shippingStatus' => [
				'name' => 'shippingStatus',
				'type' => Type::string(),
				'description' => 'Carrier shipping status code, null if no carrier event observed yet.',
			],
			'dateShippingStatus' => [
				'name' => 'dateShippingStatus',
				'type' => Type::string(),
				'description' => 'Timestamp of the latest carrier event.',
			],
			'dateShipped' => [
				'name' => 'dateShipped',
				'type' => Type::string(),
				'description' => 'First time the carrier reported in-transit; derived from the status-history table.',
			],
			'dateDelivered' => [
				'name' => 'dateDelivered',
				'type' => Type::string(),
				'description' => 'First time the carrier reported delivered; derived from the status-history table.',
			],
			'dateScheduledShip' => [
				'name' => 'dateScheduledShip',
				'type' => Type::string(),
				'description' => 'Target date the merchant plans to ship by. Informational only.',
			],
			'trackingNumber' => [
				'name' => 'trackingNumber',
				'type' => Type::string(),
				'description' => 'Carrier tracking number.',
			],
			'trackingUrl' => [
				'name' => 'trackingUrl',
				'type' => Type::string(),
				'description' => 'Carrier tracking URL.',
			],
			'carrier' => [
				'name' => 'carrier',
				'type' => Type::string(),
				'description' => 'Carrier identifier (admin-entered).',
			],
			'service' => [
				'name' => 'service',
				'type' => Type::string(),
				'description' => 'Service level (admin-entered).',
			],
			'fulfillmentNotes' => [
				'name' => 'fulfillmentNotes',
				'type' => Type::string(),
				'description' => 'Admin notes scoped to the fulfillment axis.',
			],
			'shippingNotes' => [
				'name' => 'shippingNotes',
				'type' => Type::string(),
				'description' => 'Admin notes scoped to the shipping axis.',
			],
		], self::getCustomFieldDefinitions()), self::getName());
	}

	/**
	 * Surfaces custom-field GraphQL types from the shipment field layout. Shipments use a
	 * single project-wide layout; custom fields appear under their handle alongside the
	 * built-in attributes.
	 *
	 * @return array<string, Type|array<string, mixed>>
	 */
	private static function getCustomFieldDefinitions(): array
	{
		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();
		$fieldLayout = $plugin->shipmentFieldLayouts->getFieldLayout();

		$definitions = [];
		foreach ($fieldLayout->getCustomFields() as $field) {
			$definitions[$field->handle] = $field->getContentGqlType();
		}

		return $definitions;
	}
}
