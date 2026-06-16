<?php

declare(strict_types=1);

namespace fostercommerce\shipments\tests\unit\enums;

use fostercommerce\shipments\enums\TrackedOrderShippable;
use fostercommerce\shipments\enums\TrackedOrderState;
use PHPUnit\Framework\TestCase;

final class TrackedOrderEnumsTest extends TestCase
{
	public function testTrackedOrderStateRoundtrip(): void
	{
		self::assertSame(TrackedOrderState::Active, TrackedOrderState::from('active'));
		self::assertSame(TrackedOrderState::Ignored, TrackedOrderState::from('ignored'));
		self::assertNull(TrackedOrderState::tryFrom('nope'));
	}

	public function testTrackedOrderShippableRoundtrip(): void
	{
		self::assertSame(TrackedOrderShippable::Yes, TrackedOrderShippable::from('yes'));
		self::assertSame(TrackedOrderShippable::No, TrackedOrderShippable::from('no'));
		self::assertSame(TrackedOrderShippable::Unknown, TrackedOrderShippable::from('unknown'));
		self::assertNull(TrackedOrderShippable::tryFrom('partial'));
	}
}
