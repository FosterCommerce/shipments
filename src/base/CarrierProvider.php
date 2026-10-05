<?php

declare(strict_types=1);

namespace fostercommerce\shipments\base;

use Craft;
use craft\helpers\App;
use craft\helpers\Json;
use fostercommerce\shipments\errors\DeliveryRejectedException;
use fostercommerce\shipments\models\Delivery;
use fostercommerce\shipments\models\ShippingQuote;
use fostercommerce\shipments\Plugin;
use Money\Money;

/**
 * @property list<array<string, mixed>> $boxes
 * @property list<array{source: string, sourceService: string, service: string}> $carrierMappings
 */
abstract class CarrierProvider extends Provider implements ShippingRateProviderInterface, DeliveryProviderInterface
{
	public bool|string $production = false;

	public string $senderName = '';

	public string $senderPhone = '';

	public string $senderEmail = '';

	public string $weightUnit = 'lb';

	public string $dimensionUnit = 'in';

	/**
	 * @var list<array{source: string, sourceService: string, service: string}>
	 */
	private array $mappings = [];

	/**
	 * @var list<array<string, mixed>>
	 */
	private array $packageBoxes = [];

	/**
	 * Get the configured box or pallet definitions.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function getBoxes(): array
	{
		return $this->packageBoxes;
	}

	/**
	 * Populate box definitions from saved settings or the editable table.
	 *
	 * @param array<array-key, array<string, mixed>>|string $boxes
	 */
	public function setBoxes(array|string $boxes): void
	{
		/** @var list<array<string, mixed>> $values */
		$values = is_array($boxes) ? collect($boxes)->map(static fn (array $box): array => array_key_exists('packageLength', $box)
			? collect($box)->except('packageLength')->put('length', $box['packageLength'])->all()
			: $box)->values()->all() : [];
		$this->packageBoxes = $values;
	}

	public function getCarrierMappings(): array
	{
		return $this->mappings;
	}

	/**
	 * @param array<array-key, array{source: string, sourceService: string, service: string}>|string $mappings
	 */
	public function setCarrierMappings(array|string $mappings): void
	{
		$this->mappings = is_array($mappings) ? array_values($mappings) : [];
	}

	public function getIsProduction(): bool
	{
		return App::parseBooleanEnv($this->production) ?? throw new DeliveryRejectedException(Craft::t('shipments', 'delivery.errors.invalidProductionEnvironment'));
	}

	public function supportsMethod(ShippingQuote $quote): bool
	{
		/** @var array{source: string, sourceService: string} $source */
		$source = [
			'source' => $quote->metadata['mappingSource'] ?? '',
			'sourceService' => $quote->metadata['mappingSourceService'] ?? '',
		];
		return $this->uid !== null && $quote->providerUid === $this->uid
			&& ($quote->metadata['integrationProvider'] ?? null) === static::class
			&& in_array($quote->metadata['integrationService'] ?? null, Plugin::getInstance()->carrierMappings->getMatchingServices($this, $source), true)
			&& $quote->serviceCode !== null;
	}

	/**
	 * @return list<string>
	 */
	public function getAutoMappedServices(string $sourceService): array
	{
		/** @var list<string> $services */
		$services = collect($this->getServices())->keys()
			->filter(static fn (string $code): bool => $code === $sourceService)
			->values()->all();
		return $services;
	}

	/**
	 * @return array<string, string>
	 */
	public function attributeLabels(): array
	{
		return [
			...parent::attributeLabels(),
			'carrierMappings' => Craft::t('shipments', 'settings.integrations.carrierMapping'),
		];
	}

	/**
	 * @param array<string, mixed> $metadata
	 */
	protected function carrierQuote(ShippingQuote $method, string $serviceCode, string $name, Money $cost, array $metadata = [], ?string $integrationService = null, ?int $transitDays = null): ShippingQuote
	{
		$quote = clone $method;
		$quote->handle = 'delivery_' . $this->uid . '_' . substr(hash('sha256', Json::encode([$method->metadata['mappingSource'], $method->metadata['mappingSourceService'], $integrationService ?? $serviceCode, $serviceCode])), 0, 24);
		$quote->name = $name;
		$quote->providerUid = $this->uid;
		$quote->providerHandle = $this->handle;
		$quote->serviceCode = $serviceCode;
		$quote->transitDays = $transitDays;
		$quote->amount = $cost;
		$quote->carrierCost = $cost;
		$quote->metadata = $metadata + $method->metadata + [
			'integrationProvider' => static::class,
			'integrationService' => $integrationService ?? $serviceCode,
			'commerceMethodHandle' => $method->handle,
		];
		return $quote;
	}

	protected function env(string $value): string
	{
		return trim((string) App::parseEnv($value));
	}

	protected function assertDeliveryEnvironment(Delivery $delivery): void
	{
		if (isset($delivery->metadata['production']) && $delivery->metadata['production'] !== $this->getIsProduction()) {
			throw new DeliveryRejectedException(Craft::t('shipments', 'delivery.errors.deliveryEnvironmentChanged'));
		}
	}

	protected function assertSettings(): void
	{
		if (! $this->validate()) {
			throw new DeliveryRejectedException(implode(' ', $this->getFirstErrors()));
		}
	}

	/**
	 * @return array<array-key, mixed>
	 */
	protected function defineRules(): array
	{
		return [
			...parent::defineRules(),
			[['weightUnit', 'dimensionUnit'], 'required'],
			[
				'weightUnit',
				'in',
				'range' => ['g', 'kg', 'lb'],
			],
			[
				'dimensionUnit',
				'in',
				'range' => ['mm', 'cm', 'm', 'ft', 'in'],
			],
			['carrierMappings', function (string $attribute): void {
				foreach ($this->mappings as $mapping) {
					$autoMap = $mapping['service'] === 'auto' && str_starts_with($mapping['source'], 'postie:') && $this instanceof AutomaticCarrierMappingProviderInterface;
					if ($mapping['source'] === '' || (! $autoMap && ! array_key_exists($mapping['service'], $this->getServices()))) {
						$this->addError($attribute, Craft::t('shipments', 'settings.integrations.invalidCarrierMapping'));
					}
				}
			}],
			[
				'production',
				function (string $attribute): void {
					if (App::parseBooleanEnv($this->production) === null) {
						$this->addError($attribute, Craft::t('shipments', 'delivery.errors.invalidProductionEnvironment'));
					}
				},
				'skipOnEmpty' => false,
			],
		];
	}
}
