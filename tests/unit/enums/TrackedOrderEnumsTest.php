<?php

declare(strict_types=1);

namespace fostercommerce\shipments\tests\unit\enums;

use fostercommerce\shipments\enums\CarrierEventReason;
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

	public function testCarrierEventReasonRoundtrip(): void
	{
		self::assertSame(CarrierEventReason::Projected, CarrierEventReason::from('projected'));
		self::assertSame(CarrierEventReason::SkippedDisabledShipment, CarrierEventReason::from('skipped_disabled_shipment'));
		self::assertSame(CarrierEventReason::SkippedAttentionOff, CarrierEventReason::from('skipped_attention_off'));
		self::assertNull(CarrierEventReason::tryFrom('not_a_reason'));
	}

	public function testExactlyOneProjectedCaseExists(): void
	{
		// Guardrail: `CarrierEvents::ingest` branches on `reason === Projected`. If a
		// future case should also project, update that check deliberately. This test
		// surfaces that decision by pinning the projecting-case count at 1.
		$projectingCases = array_filter(
			CarrierEventReason::cases(),
			static fn (CarrierEventReason $reason): bool => $reason === CarrierEventReason::Projected,
		);
		self::assertCount(1, $projectingCases);
	}
}
