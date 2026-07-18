<?php

declare(strict_types=1);

namespace fostercommerce\shipments\tests\unit\enums;

use fostercommerce\shipments\enums\Status;
use PHPUnit\Framework\TestCase;

final class StatusTest extends TestCase
{
	public function testAdvancesOrderOnlyForShipped(): void
	{
		foreach (Status::cases() as $case) {
			$expected = $case === Status::Shipped;
			self::assertSame($expected, $case->advancesOrder(), "Mismatch for {$case->value}");
		}
	}
}
