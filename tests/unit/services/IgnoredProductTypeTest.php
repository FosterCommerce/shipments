<?php

declare(strict_types=1);

namespace fostercommerce\shipments\tests\unit\services;

use craft\commerce\elements\Variant;
use craft\commerce\enums\LineItemType;
use craft\commerce\models\LineItem;
use fostercommerce\shipments\services\ShipmentLineItems;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class IgnoredProductTypeTest extends TestCase
{
	public function testIgnoresAVariantOfAListedType(): void
	{
		self::assertTrue($this->isIgnored($this->lineItemForProductType('serviceProducts'), ['serviceProducts']));
	}

	public function testKeepsAVariantOfAnUnlistedType(): void
	{
		self::assertFalse($this->isIgnored($this->lineItemForProductType('physicalProducts'), ['serviceProducts']));
	}

	public function testKeepsEverythingWhenNothingIsListed(): void
	{
		self::assertFalse($this->isIgnored($this->lineItemForProductType('serviceProducts'), []));
	}

	/**
	 * `LineItem::getPurchasable()` throws on a custom line item rather than returning null.
	 */
	public function testKeepsACustomLineItemWithoutReadingItsPurchasable(): void
	{
		$lineItem = $this->createMock(LineItem::class);
		$lineItem->type = LineItemType::Custom;
		$lineItem->expects(self::never())->method('getPurchasable');

		self::assertFalse($this->isIgnored($lineItem, ['serviceProducts']));
	}

	/**
	 * Commerce purchasables from other plugins have no product type, so they can never match.
	 */
	public function testKeepsAPurchasableThatIsNotAVariant(): void
	{
		$lineItem = $this->createMock(LineItem::class);
		$lineItem->method('getPurchasable')->willReturn(null);

		self::assertFalse($this->isIgnored($lineItem, ['serviceProducts']));
	}

	private function lineItemForProductType(string $productTypeHandle): LineItem
	{
		$variant = $this->createMock(Variant::class);
		$variant->method('getProductTypeHandle')->willReturn($productTypeHandle);

		$lineItem = $this->createMock(LineItem::class);
		$lineItem->method('getPurchasable')->willReturn($variant);

		return $lineItem;
	}

	/**
	 * @param list<string> $ignoredProductTypes
	 */
	private function isIgnored(LineItem $lineItem, array $ignoredProductTypes): bool
	{
		$method = new ReflectionMethod(ShipmentLineItems::class, 'isIgnoredProductType');
		$method->setAccessible(true);

		return $method->invoke(new ShipmentLineItems(), $lineItem, $ignoredProductTypes);
	}
}
