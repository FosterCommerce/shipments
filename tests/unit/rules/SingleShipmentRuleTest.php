<?php

declare(strict_types=1);

namespace fostercommerce\shipments\tests\unit\rules;

use craft\commerce\elements\Order;
use fostercommerce\shipments\rules\SingleShipmentRule;
use PHPUnit\Framework\TestCase;

/**
 * `SingleShipmentRule::plan` reads only the pool argument, never the order, so it's unit
 * testable without a Craft bootstrap. A stub Order is enough to satisfy the signature.
 */
final class SingleShipmentRuleTest extends TestCase
{
	public function testReturnsEmptyWhenPoolIsEmpty(): void
	{
		$rule = new SingleShipmentRule();

		$plans = $rule->plan($this->stubOrder(), []);

		self::assertSame([], $plans);
	}

	public function testReturnsEmptyWhenAllQtysAreZeroOrNegative(): void
	{
		$rule = new SingleShipmentRule();

		$plans = $rule->plan($this->stubOrder(), [1 => 0, 2 => -3]);

		self::assertSame([], $plans);
	}

	public function testCollectsPositiveQtysIntoOnePlan(): void
	{
		$rule = new SingleShipmentRule();

		$plans = $rule->plan($this->stubOrder(), [1 => 2, 2 => 5, 3 => 0]);

		self::assertCount(1, $plans);
		self::assertSame(SingleShipmentRule::HANDLE, $plans[0]->ruleHandle);
		self::assertSame([1 => 2, 2 => 5], $plans[0]->lineItemQtys);
	}

	public function testHandleIsStable(): void
	{
		$rule = new SingleShipmentRule();

		self::assertSame('single-shipment', $rule->getHandle());
		self::assertSame('single-shipment', SingleShipmentRule::HANDLE);
	}

	private function stubOrder(): Order
	{
		// The rule ignores the order entirely; any Order instance works.
		return $this->createStub(Order::class);
	}
}
