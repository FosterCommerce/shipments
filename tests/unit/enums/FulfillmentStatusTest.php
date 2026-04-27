<?php

declare(strict_types=1);

namespace fostercommerce\shipments\tests\unit\enums;

use fostercommerce\shipments\enums\FulfillmentStatus;
use PHPUnit\Framework\TestCase;

final class FulfillmentStatusTest extends TestCase
{
	public function testRequiresTrackingNumberOnlyForFulfilled(): void
	{
		foreach (FulfillmentStatus::cases() as $case) {
			$expected = $case === FulfillmentStatus::Fulfilled;
			self::assertSame($expected, $case->requiresTrackingNumber(), "Mismatch for {$case->value}");
		}
	}

	public function testIsTerminalForFulfilledAndCancelled(): void
	{
		self::assertTrue(FulfillmentStatus::Fulfilled->isTerminal());
		self::assertTrue(FulfillmentStatus::Cancelled->isTerminal());
		self::assertFalse(FulfillmentStatus::Open->isTerminal());
		self::assertFalse(FulfillmentStatus::InProgress->isTerminal());
		self::assertFalse(FulfillmentStatus::Scheduled->isTerminal());
		self::assertFalse(FulfillmentStatus::OnHold->isTerminal());
		self::assertFalse(FulfillmentStatus::Incomplete->isTerminal());
	}

}
