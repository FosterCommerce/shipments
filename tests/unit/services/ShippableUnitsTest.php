<?php

declare(strict_types=1);

namespace fostercommerce\shipments\tests\unit\services;

use craft\commerce\elements\Order;
use fostercommerce\shipments\events\ResolveShippableUnitsEvent;
use fostercommerce\shipments\services\ShipmentLineItems;
use PHPUnit\Framework\TestCase;
use yii\base\Event;

final class ShippableUnitsTest extends TestCase
{
	protected function tearDown(): void
	{
		Event::off(ShipmentLineItems::class, ShipmentLineItems::EVENT_RESOLVE_SHIPPABLE_UNITS);
		parent::tearDown();
	}

	public function testDefaultsToCartQty(): void
	{
		$order = $this->orderWithLineItems([[10, 1], [11, 3]]);

		self::assertSame([
			10 => 1,
			11 => 3,
		], (new ShipmentLineItems())->shippableUnitsFor($order));
	}

	public function testListenerOverridesUnits(): void
	{
		// A summary line (cart qty 1) standing for 30 physical units.
		Event::on(
			ShipmentLineItems::class,
			ShipmentLineItems::EVENT_RESOLVE_SHIPPABLE_UNITS,
			static function (ResolveShippableUnitsEvent $event): void {
				$event->shippableUnits[10] = 30;
			},
		);

		$order = $this->orderWithLineItems([[10, 1], [11, 3]]);

		self::assertSame([
			10 => 30,
			11 => 3,
		], (new ShipmentLineItems())->shippableUnitsFor($order));
	}

	public function testSkipsLineItemsWithoutId(): void
	{
		$order = $this->orderWithLineItems([[10, 1], [null, 5]]);

		self::assertSame([
			10 => 1,
		], (new ShipmentLineItems())->shippableUnitsFor($order));
	}

	/**
	 * @param list<array{0: ?int, 1: int}> $idQtyPairs
	 */
	private function orderWithLineItems(array $idQtyPairs): Order
	{
		$lineItems = [];
		foreach ($idQtyPairs as [$lineItemId, $qty]) {
			$lineItems[] = new class($lineItemId, $qty) {
				public function __construct(
					public readonly ?int $id,
					public readonly int $qty,
				) {
				}
			};
		}

		$order = $this->createMock(Order::class);
		$order->method('getLineItems')->willReturn($lineItems);

		return $order;
	}
}
