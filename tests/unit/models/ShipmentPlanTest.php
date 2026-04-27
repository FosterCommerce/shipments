<?php

declare(strict_types=1);

namespace fostercommerce\shipments\tests\unit\models;

use fostercommerce\shipments\models\ShipmentPlan;
use PHPUnit\Framework\TestCase;

final class ShipmentPlanTest extends TestCase
{
	public function testIsEmptyWhenNoQtys(): void
	{
		$plan = new ShipmentPlan();

		self::assertTrue($plan->isEmpty());
	}

	public function testIsEmptyWhenAllQtysZero(): void
	{
		$plan = new ShipmentPlan();
		$plan->lineItemQtys = [1 => 0, 2 => 0];

		self::assertTrue($plan->isEmpty());
	}

	public function testIsNotEmptyWhenAtLeastOnePositiveQty(): void
	{
		$plan = new ShipmentPlan();
		$plan->lineItemQtys = [1 => 0, 2 => 3];

		self::assertFalse($plan->isEmpty());
	}

	public function testTotalQtySumsEveryEntry(): void
	{
		$plan = new ShipmentPlan();
		$plan->lineItemQtys = [10 => 2, 11 => 5, 12 => 1];

		self::assertSame(8, $plan->totalQty());
	}

	public function testTotalQtyZeroOnEmptyPlan(): void
	{
		$plan = new ShipmentPlan();

		self::assertSame(0, $plan->totalQty());
	}
}
