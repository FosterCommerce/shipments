<?php

declare(strict_types=1);

namespace fostercommerce\shipments\services;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\commerce\elements\Order;
use craft\commerce\models\LineItem;
use craft\commerce\Plugin as Commerce;
use craft\elements\Address;
use DVDoug\BoxPacker\NoBoxesAvailableException;
use DVDoug\BoxPacker\Packer;
use fostercommerce\shipments\errors\DeliveryRejectedException;
use fostercommerce\shipments\errors\PackingException;
use fostercommerce\shipments\models\PackageBox;
use PhpUnitsOfMeasure\PhysicalQuantity\Length;
use PhpUnitsOfMeasure\PhysicalQuantity\Mass;
use verbb\shippy\models\Address as ShippyAddress;
use verbb\shippy\models\Package;
use verbb\shippy\models\PackageItem;
use verbb\shippy\models\Shipment;
use yii\base\Component;

/**
 * @phpstan-type PackedParcel array{length: float, width: float, height: float, weight: float, items: list<LineItem>, type?: string}
 */
class DeliveryPreparation extends Component
{
	/**
	 * Prepare carrier addresses and contacts from an isolated shipment order.
	 *
	 * @param array<string, mixed> $defaults
	 * @throws DeliveryRejectedException
	 */
	public function shipment(Order $order, array $defaults): Shipment
	{
		$origin = $order->getStore()->getSettings()->getLocationAddress();
		$destination = $order->getShippingAddress() ?? $order->getEstimatedShippingAddress();
		if (! $origin instanceof Address || ! $destination instanceof Address) {
			throw new DeliveryRejectedException(Craft::t('shipments', 'delivery.errors.storeAndShipmentShippingAddressesAreRequired'));
		}

		$addresses = collect([
			'from' => [$origin, 'sender'],
			'to' => [$destination, 'recipient'],
		])->map(function (array $source) use ($order, $defaults): ShippyAddress {
			[$address, $prefix] = $source;
			/** @var Address $address */
			$contact = static function (string $suffix, string $fallback) use ($defaults, $prefix): string {
				$value = $defaults[$prefix . $suffix] ?? '';
				return $prefix === 'sender' ? (is_string($value) ? trim($value) : '') : $fallback;
			};
			$residential = $this->field($address, 'isResidential');
			$fullName = trim((string) $address->fullName);
			return new ShippyAddress([
				'firstName' => $contact('Name', $fullName === '' || $fullName === '0' ? (string) $address->title : $fullName),
				'lastName' => '',
				'companyName' => (string) $address->organization,
				'street1' => (string) $address->addressLine1,
				'street2' => (string) $address->addressLine2,
				'city' => (string) $address->locality,
				'stateProvince' => (string) $address->administrativeArea,
				'postalCode' => (string) $address->postalCode,
				'countryCode' => $address->countryCode,
				'phone' => $contact('Phone', $prefix === 'recipient' ? ($this->field($address, 'phone') ?? '') : ''),
				'email' => $contact('Email', $prefix === 'recipient' ? ($this->field($address, 'email') ?? (string) $order->email) : ''),
				'isResidential' => $residential === null ? trim((string) $address->organization) === '' : (bool) $residential,
			]);
		})->all();
		return new Shipment($addresses + [
			'currency' => $order->currency,
		]);
	}

	/**
	 * Get allocated physical items, including items with free shipping.
	 *
	 * @return list<LineItem>
	 */
	public function physicalItems(Order $order): array
	{
		/** @var list<LineItem> $items */
		$items = collect($order->getLineItems())->filter(static fn (LineItem $item): bool => $item->qty > 0 && $item->getIsShippable())->values()->all();
		return $items;
	}

	/**
	 * Read a scalar custom-field value for carrier preparation.
	 */
	public function field(?ElementInterface $element, string $handle): ?string
	{
		if ($handle === '' || ! $element?->getFieldLayout()?->getFieldByHandle($handle) instanceof FieldInterface) {
			return null;
		}

		$value = $element->getFieldValue($handle);
		if (is_bool($value)) {
			return $value ? '1' : '0';
		}

		return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
	}

	/**
	 * Offer allocated physical items to the callback, then fit them into boxes if unhandled.
	 *
	 * @param list<array<string, mixed>> $boxes
	 * @param (\Closure(list<LineItem>): (list<PackedParcel>|false))|null $callback Return false to use box packing.
	 * @return list<PackedParcel>
	 * @throws DeliveryRejectedException
	 */
	public function pack(Order $order, array $boxes, ?\Closure $callback = null, string $weightUnit = 'lb', string $dimensionUnit = 'in'): array
	{
		$items = $this->physicalItems($order);
		if ($items === []) {
			throw new DeliveryRejectedException(Craft::t('shipments', 'delivery.errors.thereAreNoPhysicalItemsAllocatedToThisShipment'));
		}

		if ($callback instanceof \Closure) {
			$parcels = $callback($items);
			if ($parcels !== false) {
				return $parcels;
			}
		}

		if ($boxes === []) {
			throw new DeliveryRejectedException(Craft::t('shipments', 'delivery.errors.configurePackageOrPalletSizesInThisShipmentsIntegration'));
		}

		$definitions = collect($boxes)->map(fn (array $box): PackageBox => $this->box($box, $weightUnit, $dimensionUnit));

		return $this->packInBoxes($items, $definitions->all());
	}

	/**
	 * Build a parcel from its validated measurements.
	 *
	 * @param array<array-key, mixed> $row
	 * @throws DeliveryRejectedException
	 */
	public function package(array $row): Package
	{
		foreach (['length', 'width', 'height', 'weight'] as $key) {
			if (! isset($row[$key]) || ! is_numeric($row[$key]) || (float) $row[$key] <= 0) {
				throw new DeliveryRejectedException(Craft::t('shipments', 'delivery.errors.everyPackageNeedsPositiveDimensionsAndWeight'));
			}
		}

		if (! in_array($row['dimensionUnit'] ?? '', ['in', 'cm', 'mm'], true) || ! in_array($row['weightUnit'] ?? '', ['lb', 'kg', 'g', 'oz'], true)) {
			throw new DeliveryRejectedException(Craft::t('shipments', 'delivery.errors.chooseValidPackageMeasurementUnits'));
		}

		return new Package(collect($row)->only(['length', 'width', 'height', 'weight', 'dimensionUnit', 'weightUnit', 'reference'])->all());
	}

	/**
	 * Pack each unit in its own parcel using the item's dimensions and weight.
	 * Returns dimensions in inches and weights in pounds.
	 *
	 * @param list<LineItem> $items
	 * @return list<PackedParcel>
	 * @throws DeliveryRejectedException When an item has nonpositive dimensions or weight.
	 */
	public function packIndividually(array $items): array
	{
		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();
		$settings = $commerce->getSettings();
		/** @var list<PackedParcel> $parcels */
		$parcels = collect($items)->flatMap(static function (LineItem $item) use ($settings): array {
			if ($item->weight <= 0 || min($item->length, $item->width, $item->height) <= 0) {
				throw new DeliveryRejectedException(Craft::t('shipments', 'delivery.errors.everyAllocatedItemNeedsAPositiveWeightAndFor'));
			}

			return collect(range(1, $item->qty))->map(static fn (): array => [
				'length' => (new Length($item->length, $settings->dimensionUnits))->toUnit('in'),
				'width' => (new Length($item->width, $settings->dimensionUnits))->toUnit('in'),
				'height' => (new Length($item->height, $settings->dimensionUnits))->toUnit('in'),
				'weight' => (new Mass($item->weight, $settings->weightUnits))->toUnit('lb'),
				'items' => [$item],
			])->all();
		})->values()->all();
		return $parcels;
	}

	/**
	 * Stack all units into one parcel, summing their heights and weights.
	 * Uses the largest item length and width, with dimensions in inches and weight in pounds.
	 *
	 * @param list<LineItem> $items
	 * @return list<PackedParcel>
	 * @throws DeliveryRejectedException When an item has nonpositive dimensions or weight.
	 */
	public function packStacked(array $items): array
	{
		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();
		$settings = $commerce->getSettings();
		foreach ($items as $item) {
			$this->item($item, true);
		}

		$lines = collect($items);
		/** @var list<LineItem> $contents */
		$contents = $lines
			->flatMap(static fn (LineItem $item): array => collect(range(1, $item->qty))
				->map(static fn (): LineItem => $item)
				->all())
			->values()
			->all();

		$length = $lines->reduce(static fn (float $max, LineItem $item): float => max($max, $item->length), 0.0);
		$width = $lines->reduce(static fn (float $max, LineItem $item): float => max($max, $item->width), 0.0);
		$height = $lines->reduce(static fn (float $height, LineItem $item): float => $height + $item->qty * $item->height, 0.0);
		$weight = $lines->reduce(static fn (float $weight, LineItem $item): float => $weight + $item->qty * $item->weight, 0.0);

		return [[
			'length' => (new Length($length, $settings->dimensionUnits))->toUnit('in'),
			'width' => (new Length($width, $settings->dimensionUnits))->toUnit('in'),
			'height' => (new Length($height, $settings->dimensionUnits))->toUnit('in'),
			'weight' => (new Mass($weight, $settings->weightUnits))->toUnit('lb'),
			'items' => $contents,
		]];
	}

	/**
	 * @param list<LineItem> $items
	 * @param list<PackageBox> $boxes
	 * @return list<PackedParcel>
	 */
	private function packInBoxes(array $items, array $boxes): array
	{
		$packer = new Packer();
		collect($boxes)->each(static fn (PackageBox $box) => $packer->addBox($box));
		$byId = collect($items)->keyBy('id');
		foreach ($items as $item) {
			$packer->addItem($this->item($item, true), $item->qty);
		}

		try {
			$packedBoxes = $packer->pack();
		} catch (NoBoxesAvailableException $noBoxesAvailableException) {
			$item = $byId->get((int) $noBoxesAvailableException->getItem()->getDescription());
			if (! $item instanceof LineItem) {
				throw $noBoxesAvailableException;
			}

			throw new PackingException(Craft::t('shipments', 'delivery.errors.noBoxesCouldBeFoundForItem', [
				'item' => $item->getDescription(),
				'sku' => $item->getSku(),
			]), 0, $noBoxesAvailableException);
		}

		$parcels = [];
		foreach ($packedBoxes as $packed) {
			$contents = [];
			foreach ($packed->getItems() as $packedItem) {
				$item = $byId->get((int) $packedItem->getItem()->getDescription());
				if (! $item instanceof LineItem) {
					throw new DeliveryRejectedException(Craft::t('shipments', 'delivery.errors.packingReturnedAnUnknownShipmentItem'));
				}

				$contents[] = $item;
			}

			/** @var PackageBox $box */
			$box = $packed->getBox();
			$parcels[] = $this->parcel($box, $packed->getWeight(), $contents);
		}

		if (collect($parcels)->sum(static fn (array $parcel): int => count($parcel['items'])) !== collect($items)->sum('qty')) {
			throw new DeliveryRejectedException(Craft::t('shipments', 'delivery.errors.packingDidNotIncludeEveryAllocatedItem'));
		}

		return $parcels;
	}

	private function item(LineItem $item, bool $dimensions): PackageItem
	{
		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();
		$settings = $commerce->getSettings();
		if ($item->weight <= 0 || ($dimensions && min($item->length, $item->width, $item->height) <= 0)) {
			throw new DeliveryRejectedException(Craft::t('shipments', 'delivery.errors.everyAllocatedItemNeedsAPositiveWeightAndFor'));
		}

		$package = new PackageItem();
		$package->setDimensions(
			(string) $item->id,
			$this->packingDimension($item->width, $settings->dimensionUnits),
			$this->packingDimension($item->length, $settings->dimensionUnits),
			$this->packingDimension($item->height, $settings->dimensionUnits),
			(int) ceil((new Mass($item->weight, $settings->weightUnits))->toUnit('g'))
		);
		return $package;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function box(array $row, string $weightUnit, string $dimensionUnit): PackageBox
	{
		$measurements = collect(['length', 'width', 'height', 'maxWeight'])->mapWithKeys(static function (string $key) use ($row): array {
			$value = $row[$key] ?? null;
			if (! is_numeric($value) || (float) $value <= 0) {
				throw new DeliveryRejectedException(Craft::t('shipments', 'delivery.errors.everyConfiguredBoxOrPalletNeedsPositiveDimensionsAnd'));
			}

			return [
				$key => (float) $value,
			];
		})->all();
		$tare = $row['tareWeight'] ?? 0;
		if (! is_numeric($tare) || (float) $tare < 0 || (float) $tare >= $measurements['maxWeight']) {
			throw new DeliveryRejectedException(Craft::t('shipments', 'delivery.errors.emptyPackageWeightMustBeNonnegativeAndLessThan'));
		}

		$box = new PackageBox();
		$box->lengthInches = (new Length($measurements['length'], $dimensionUnit))->toUnit('in');
		$box->widthInches = (new Length($measurements['width'], $dimensionUnit))->toUnit('in');
		$box->heightInches = (new Length($measurements['height'], $dimensionUnit))->toUnit('in');
		$box->setDimensions(
			'',
			$this->packingDimension($measurements['width'], $dimensionUnit),
			$this->packingDimension($measurements['length'], $dimensionUnit),
			$this->packingDimension($measurements['height'], $dimensionUnit),
			(int) floor((new Mass($measurements['maxWeight'], $weightUnit))->toUnit('g')),
		);
		$box->setEmptyWeight((int) ceil((new Mass((float) $tare, $weightUnit))->toUnit('g')));
		$box->setType(is_string($row['boxType'] ?? null) ? $row['boxType'] : '');
		return $box;
	}

	private function packingDimension(float $value, string $unit): int
	{
		// Use hundredths of an inch consistently for all packer dimensions.
		// This turns 17.5in into 1750, 25mm into 98 (equivalent to 0.98in), etc.
		return (int) round((new Length($value, $unit))->toUnit('in') * 100);
	}

	/** @param list<LineItem> $items
	 * @return PackedParcel
	 */
	private function parcel(PackageBox $box, int $weight, array $items): array
	{
		return [
			'length' => $box->lengthInches,
			'width' => $box->widthInches,
			'height' => $box->heightInches,
			'weight' => $weight / 453.59237,
			'items' => $items,
			'type' => $box->getType(),
		];
	}
}
