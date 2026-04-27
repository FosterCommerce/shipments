<?php

declare(strict_types=1);

namespace fostercommerce\shipments\tests\unit\enums;

use fostercommerce\shipments\enums\ShippingStatus;
use PHPUnit\Framework\TestCase;

final class ShippingStatusTest extends TestCase
{
	public function testTerminalStatuses(): void
	{
		self::assertTrue(ShippingStatus::Delivered->isTerminal());
		self::assertTrue(ShippingStatus::Returned->isTerminal());
		self::assertTrue(ShippingStatus::Failure->isTerminal());

		self::assertFalse(ShippingStatus::Pending->isTerminal());
		self::assertFalse(ShippingStatus::PreTransit->isTerminal());
		self::assertFalse(ShippingStatus::InTransit->isTerminal());
		self::assertFalse(ShippingStatus::OutForDelivery->isTerminal());
		self::assertFalse(ShippingStatus::AttemptedDelivery->isTerminal());
		self::assertFalse(ShippingStatus::AvailableForPickup->isTerminal());
		self::assertFalse(ShippingStatus::Exception->isTerminal());
	}

	public function testTryFromAcceptsKnownCodes(): void
	{
		self::assertSame(ShippingStatus::InTransit, ShippingStatus::tryFrom('in_transit'));
		self::assertSame(ShippingStatus::Delivered, ShippingStatus::tryFrom('delivered'));
	}

	public function testTryFromReturnsNullForUnknown(): void
	{
		self::assertNull(ShippingStatus::tryFrom('not_a_real_code'));
	}
}
