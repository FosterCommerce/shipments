<?php

declare(strict_types=1);

namespace fostercommerce\shipments\services;

use Craft;
use verbb\postie\base\Provider;
use verbb\postie\events\PackOrderEvent;
use verbb\postie\models\PackedBoxes;
use yii\base\Component;
use yii\base\Event;

/** Stores Postie pack results for PostiePackingRule. */
class PostiePacking extends Component
{
	public const CACHE_KEY_PREFIX = 'shipments:postie-pack:';

	public const CACHE_DURATION = 86400;

	public function isAvailable(): bool
	{
		return class_exists(Provider::class);
	}

	public function registerListeners(): void
	{
		if (! $this->isAvailable()) {
			return;
		}

		Event::on(
			Provider::class,
			Provider::EVENT_AFTER_PACK_ORDER,
			[$this, 'handleAfterPackOrder'],
		);
	}

	public function handleAfterPackOrder(PackOrderEvent $event): void
	{
		$this->storeBoxAllocations(
			$event->order->number,
			$this->parsePackedBoxes($event->packedBoxes),
		);
	}

	/**
	 * @return list<array<int, int>>
	 */
	public function getBoxAllocations(string $orderNumber): array
	{
		$cached = Craft::$app->getCache()->get($this->cacheKey($orderNumber));
		if (! is_array($cached)) {
			return [];
		}

		/** @var list<array<int, int>> $cached */
		return $cached;
	}

	/**
	 * @param list<array<int, int>> $boxes
	 */
	public function storeBoxAllocations(string $orderNumber, array $boxes): void
	{
		Craft::$app->getCache()->set(
			$this->cacheKey($orderNumber),
			$boxes,
			self::CACHE_DURATION,
		);
	}

	/**
	 * @return list<array<int, int>>
	 */
	public function parsePackedBoxes(PackedBoxes $packedBoxes): array
	{
		$boxes = [];
		foreach ($packedBoxes->getPackedBoxList() as $packedBox) {
			$lineItemQtys = [];
			foreach ($packedBox->getItems() as $packedItem) {
				if (preg_match('/^Item (\d+)$/', $packedItem->getItem()->getDescription(), $matches) !== 1) {
					continue;
				}

				$lineItemId = (int) $matches[1];
				$lineItemQtys[$lineItemId] = ($lineItemQtys[$lineItemId] ?? 0) + 1;
			}

			if ($lineItemQtys !== []) {
				$boxes[] = $lineItemQtys;
			}
		}

		return $boxes;
	}

	public function cacheKey(string $orderNumber): string
	{
		return self::CACHE_KEY_PREFIX . $orderNumber;
	}
}
