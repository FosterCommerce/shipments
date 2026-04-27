<?php

declare(strict_types=1);

namespace fostercommerce\shipments\tests\unit\enums;

use fostercommerce\shipments\enums\FulfillmentStatus;
use fostercommerce\shipments\enums\ShippingStatus;
use fostercommerce\shipments\enums\StatusAxis;
use PHPUnit\Framework\TestCase;

final class StatusAxisTest extends TestCase
{
	public function testFulfillmentAxisResolvesFulfillmentCodes(): void
	{
		self::assertSame(FulfillmentStatus::Open, StatusAxis::Fulfillment->resolveCode('open'));
		self::assertSame(FulfillmentStatus::Fulfilled, StatusAxis::Fulfillment->resolveCode('fulfilled'));
	}

	public function testFulfillmentAxisReturnsNullForUnknownCode(): void
	{
		self::assertNull(StatusAxis::Fulfillment->resolveCode('not_a_fulfillment_code'));
	}

	public function testFulfillmentAxisReturnsNullForShippingCode(): void
	{
		// Shipping's in_transit is not a fulfillment code.
		self::assertNull(StatusAxis::Fulfillment->resolveCode('in_transit'));
	}

	public function testShippingAxisResolvesShippingCodes(): void
	{
		self::assertSame(ShippingStatus::InTransit, StatusAxis::Shipping->resolveCode('in_transit'));
		self::assertSame(ShippingStatus::Delivered, StatusAxis::Shipping->resolveCode('delivered'));
	}

	public function testShippingAxisReturnsNullForFulfillmentCode(): void
	{
		self::assertNull(StatusAxis::Shipping->resolveCode('fulfilled'));
	}

}
