<?php

declare(strict_types=1);

namespace fostercommerce\shipments\tests\unit\enums;

use fostercommerce\shipments\enums\Status;
use PHPUnit\Framework\TestCase;

final class StatusTest extends TestCase
{
	public function testRequiresTrackingNumberOnlyForShipped(): void
	{
		foreach (Status::cases() as $case) {
			$expected = $case === Status::Shipped;
			self::assertSame($expected, $case->requiresTrackingNumber(), "Mismatch for {$case->value}");
		}
	}

	public function testAdvancesOrderOnlyForShipped(): void
	{
		foreach (Status::cases() as $case) {
			$expected = $case === Status::Shipped;
			self::assertSame($expected, $case->advancesOrder(), "Mismatch for {$case->value}");
		}
	}

	public function testIsTerminalForShippedAndCancelled(): void
	{
		self::assertTrue(Status::Shipped->isTerminal());
		self::assertTrue(Status::Cancelled->isTerminal());
		self::assertFalse(Status::Open->isTerminal());
		self::assertFalse(Status::InProgress->isTerminal());
		self::assertFalse(Status::Scheduled->isTerminal());
		self::assertFalse(Status::OnHold->isTerminal());
		self::assertFalse(Status::Incomplete->isTerminal());
	}
}
