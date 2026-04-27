<?php

declare(strict_types=1);

namespace fostercommerce\shipments\gql\resolvers\elements;

use craft\elements\db\ElementQuery;
use craft\gql\base\ElementResolver;
use fostercommerce\shipments\elements\Shipment as ShipmentElement;

/**
 * GraphQL resolver for the `shipments` root query.
 */
class Shipment extends ElementResolver
{
	/**
	 * @param array<string, mixed> $arguments
	 */
	protected static function prepareQuery(mixed $source, array $arguments, ?string $fieldName = null): mixed
	{
		$query = $source === null
			? ShipmentElement::find()
			: $source->{$fieldName};

		if (! $query instanceof ElementQuery) {
			return $query;
		}

		foreach ($arguments as $key => $value) {
			if (method_exists($query, $key)) {
				$query->{$key}($value);
			} elseif (property_exists($query, $key)) {
				$query->{$key} = $value;
			}
		}

		return $query;
	}
}
