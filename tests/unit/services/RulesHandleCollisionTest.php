<?php

declare(strict_types=1);

namespace fostercommerce\shipments\tests\unit\services;

use craft\commerce\elements\Order;
use fostercommerce\shipments\base\ShipmentRuleInterface;
use fostercommerce\shipments\events\RegisterShipmentRulesEvent;
use fostercommerce\shipments\rules\LineItemStatusRule;
use fostercommerce\shipments\services\Rules;
use PHPUnit\Framework\TestCase;
use yii\base\Event;
use yii\base\InvalidConfigException;

final class RulesHandleCollisionTest extends TestCase
{
	protected function tearDown(): void
	{
		// Detach listeners so one test's registration doesn't bleed into the next.
		Event::off(Rules::class, Rules::EVENT_REGISTER_RULES);
		parent::tearDown();
	}

	public function testDuplicateHandleWithBuiltInThrows(): void
	{
		Event::on(
			Rules::class,
			Rules::EVENT_REGISTER_RULES,
			static function (RegisterShipmentRulesEvent $event): void {
				$event->rules[] = self::makeRule(LineItemStatusRule::HANDLE);
			},
		);

		$rules = new Rules();

		$this->expectException(InvalidConfigException::class);
		$this->expectExceptionMessageMatches('/Duplicate shipment rule handle "line-item-status"/');
		$rules->allRules();
	}

	public function testTwoCustomRulesSharingAHandleThrows(): void
	{
		Event::on(
			Rules::class,
			Rules::EVENT_REGISTER_RULES,
			static function (RegisterShipmentRulesEvent $event): void {
				$event->rules[] = self::makeRule('my-custom-rule');
				$event->rules[] = self::makeRule('my-custom-rule');
			},
		);

		$rules = new Rules();

		$this->expectException(InvalidConfigException::class);
		$this->expectExceptionMessageMatches('/Duplicate shipment rule handle "my-custom-rule"/');
		$rules->allRules();
	}

	public function testOverrideBuiltInByRemovingFirst(): void
	{
		// Documented override pattern: remove the built-in from $event->rules, then
		// append the replacement with the same handle. No collision because the old
		// entry is gone before the append.
		Event::on(
			Rules::class,
			Rules::EVENT_REGISTER_RULES,
			static function (RegisterShipmentRulesEvent $event): void {
				$event->rules = array_values(array_filter(
					$event->rules,
					static fn (ShipmentRuleInterface $rule): bool => $rule::class !== LineItemStatusRule::class,
				));
				$event->rules[] = self::makeRule(LineItemStatusRule::HANDLE);
			},
		);

		$rules = new Rules();

		$resolved = $rules->allRules();
		self::assertArrayHasKey(LineItemStatusRule::HANDLE, $resolved);
		// The replacement is the anonymous class, not the built-in.
		self::assertNotInstanceOf(LineItemStatusRule::class, $resolved[LineItemStatusRule::HANDLE]);
	}

	public function testUniqueHandlesAcrossBuiltInsAndCustomRulesPass(): void
	{
		Event::on(
			Rules::class,
			Rules::EVENT_REGISTER_RULES,
			static function (RegisterShipmentRulesEvent $event): void {
				$event->rules[] = self::makeRule('heavy-items');
				$event->rules[] = self::makeRule('swatch-bundle');
			},
		);

		$rules = new Rules();
		$resolved = $rules->allRules();

		self::assertArrayHasKey('inventory-status', $resolved);
		self::assertArrayHasKey('line-item-status', $resolved);
		self::assertArrayHasKey('shipping-category', $resolved);
		self::assertArrayHasKey('heavy-items', $resolved);
		self::assertArrayHasKey('swatch-bundle', $resolved);
	}

	private static function makeRule(string $handle): ShipmentRuleInterface
	{
		return new class($handle) implements ShipmentRuleInterface {
			public function __construct(private readonly string $handle)
			{
			}

			public function getHandle(): string
			{
				return $this->handle;
			}

			public function getName(): string
			{
				return $this->handle;
			}

			public function getDescription(): string
			{
				return '';
			}

			public function plan(Order $order, array $remainingQtyByLineItemId): array
			{
				return [];
			}
		};
	}
}
