<?php

declare(strict_types=1);

namespace fostercommerce\shipments\services;

use Craft;
use craft\commerce\base\ShippingMethodInterface;
use craft\commerce\elements\Order;
use craft\commerce\models\ShippingMethod;
use craft\commerce\Plugin as Commerce;
use craft\helpers\Json;
use DateTimeImmutable;
use fostercommerce\shipments\base\ProviderInterface;
use fostercommerce\shipments\base\ShippingRateProviderInterface;
use fostercommerce\shipments\elements\Shipment;
use fostercommerce\shipments\enums\Status;
use fostercommerce\shipments\helpers\MoneyValues;
use fostercommerce\shipments\models\Delivery;
use fostercommerce\shipments\models\ShippingQuote;
use fostercommerce\shipments\Plugin;
use fostercommerce\shipments\records\Shipment as ShipmentRecord;
use Illuminate\Support\Collection;
use Money\Money;
use RuntimeException;
use Throwable;
use verbb\postie\models\ShippingMethod as PostieShippingMethod;
use yii\base\Component;

class Shipping extends Component
{
	/**
	 * Calculate quotes for the shipment’s allocated quantities without saving its order.
	 *
	 * @return list<ShippingQuote>
	 */
	public function calculate(Shipment $shipment): array
	{
		return $this->calculateResult($shipment)['quotes'];
	}

	/**
	 * Restore the saved quotes.
	 *
	 * @return list<ShippingQuote>
	 */
	public function getQuotes(Shipment $shipment): array
	{
		$snapshots = $shipment->shippingSnapshot['quotes'] ?? [];
		if (! is_array($snapshots)) {
			throw new RuntimeException(Craft::t('shipments', 'delivery.errors.savedShipmentQuoteIsInvalid'));
		}

		/** @var list<ShippingQuote> $quotes */
		$quotes = collect($snapshots)->map(static function (mixed $snapshot): ShippingQuote {
			if (! is_array($snapshot)) {
				throw new RuntimeException(Craft::t('shipments', 'delivery.errors.savedShipmentQuoteIsInvalid'));
			}

			/** @var array<string, mixed> $snapshot */
			return ShippingQuote::fromSnapshot($snapshot);
		})->values()->all();
		return $quotes;
	}

	/**
	 * Find rates mapped from the customer's order shipping method.
	 *
	 * @param list<ShippingQuote> $quotes
	 * @return list<ShippingQuote>
	 */
	public function getCustomerChosenQuotes(Shipment $shipment, array $quotes): array
	{
		$order = $shipment->getOrder();
		$handle = $order?->shippingMethodHandle;

		if (! $order instanceof Order || $handle === null || $handle === '') {
			return [];
		}

		$commerceSource = 'commerce:' . $order->getStore()->handle . ':' . $handle;

		return collect($quotes)
			->filter(static function (ShippingQuote $quote) use ($handle, $commerceSource): bool {
				$source = $quote->metadata['mappingSource'] ?? '';

				return $quote->handle === $handle
					|| $source === $commerceSource
					|| (is_string($source) && str_starts_with($source, 'postie:') && ($quote->metadata['mappingSourceService'] ?? null) === $handle);
			})
			->values()
			->all();
	}

	/**
	 * Hash shipment inputs and resolved provider configuration for quote validation.
	 */
	public function fingerprint(Shipment $shipment): string
	{
		$order = $shipment->getOrder();
		if (! $order instanceof Order) {
			throw new RuntimeException(Craft::t('shipments', 'delivery.errors.shipmentOrderIsUnavailable'));
		}

		$providers = $this->rateProviders();

		$data = [
			'carrierPricing' => 'mapped_carrier_quote',
			'packing' => 'integration_settings',
			'contacts' => 'saved_details',
			'shipmentReference' => $shipment->reference,
			'orderId' => $order->id,
			'storeId' => $order->storeId,
			'currency' => $order->currency,
			'customerId' => $order->customerId,
			'allocations' => collect($shipment->getLineItems())->map(static fn ($item): array => [$item->lineItemId, $item->qty])->all(),
			'items' => collect($order->getLineItems())->map(static fn ($item): array => $item->toArray())->all(),
			'adjustments' => collect($order->getAdjustments() ?? [])->map(static fn ($item): array => $item->toArray())->all(),
			'fields' => $order->getSerializedFieldValues(),
			'shippingAddress' => [$order->getShippingAddress()?->toArray(), $order->getShippingAddress()?->getSerializedFieldValues()],
			'estimatedShippingAddress' => [$order->getEstimatedShippingAddress()?->toArray(), $order->getEstimatedShippingAddress()?->getSerializedFieldValues()],
			'origin' => [$order->getStore()->getSettings()->getLocationAddress()?->toArray(), $order->getStore()->getSettings()->getLocationAddress()?->getSerializedFieldValues()],
			'purchasables' => collect($order->getLineItems())->map(static fn ($item): mixed => $item->getPurchasable()?->getSerializedFieldValues())->all(),
			'options' => $this->getOptions($shipment),
			'configuration' => Craft::$app->getProjectConfig()->get('commerce'),
			'postie' => Craft::$app->getPlugins()->isPluginEnabled('postie') ? Craft::$app->getProjectConfig()->get('postie') : null,
			'deliveryProviders' => collect($providers)->map(static fn (ShippingRateProviderInterface $provider): array => $provider->getShippingConfiguration())->all(),
		];
		// Only the digest is persisted. Provider configuration can contain credentials.
		return hash('sha256', Json::encode($data));
	}

	/**
	 * Refresh and persist the available shipment quotes.
	 *
	 * @return list<ShippingQuote>
	 * @throws RuntimeException
	 */
	public function refresh(Shipment $shipment): array
	{
		return $this->locked($shipment, fn (Shipment $current): array => $this->refreshUnlocked($current));
	}

	/**
	 * Select an available quote under the shipment lock.
	 *
	 * @throws RuntimeException
	 */
	public function select(Shipment $shipment, string $handle): ShippingQuote
	{
		return $this->locked($shipment, fn (Shipment $current): ShippingQuote => $this->selectUnlocked($current, $handle));
	}

	/**
	 * Validate the selected method and shipment inputs before a carrier purchase.
	 *
	 * @throws RuntimeException
	 */
	public function validateForBooking(Shipment $shipment): ShippingQuote
	{
		$this->assertShipDate($shipment);

		$selected = collect($this->getQuotes($shipment))
			->firstWhere('handle', $shipment->shippingMethodHandle);

		if (! $selected instanceof ShippingQuote) {
			throw new RuntimeException(Craft::t('shipments', 'delivery.errors.selectAShipmentMethodBeforeCreatingADelivery'));
		}

		$this->assertCurrent($shipment, $selected);

		return $selected;
	}

	public function assertEditable(Shipment $shipment): void
	{
		if ($shipment->getStatusEnum() === Status::Cancelled) {
			throw new RuntimeException(Craft::t('shipments', 'delivery.errors.cancelledShipmentCannotChangeShipping'));
		}

		if ($shipment->getDelivery() instanceof Delivery) {
			throw new RuntimeException(Craft::t('shipments', 'delivery.errors.thisShipmentAlreadyHasAnActiveDelivery'));
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getOptions(Shipment $shipment): array
	{
		return [
			'shipDate' => $shipment->dateScheduledShip?->format('Y-m-d') ?? date('Y-m-d'),
		];
	}

	public function isShipDateInPast(Shipment $shipment): bool
	{
		return $this->getOptions($shipment)['shipDate'] < date('Y-m-d');
	}

	/**
	 * @return array{quotes: list<ShippingQuote>, errors: list<array{provider: string, message: string}>}
	 */
	private function calculateResult(Shipment $shipment): array
	{
		$this->assertShipDate($shipment);
		$plugin = Plugin::getInstance();
		$order = $plugin->shipmentCalculation->create($shipment);
		$currency = $order->currency;
		if ($currency === null || $currency === '') {
			throw new RuntimeException(Craft::t('shipments', 'delivery.errors.shipmentOrderHasNoCurrency'));
		}

		$options = $this->getOptions($shipment);
		$methodQuotes = $this->methodQuotes($order, $this->fingerprint($shipment), $currency);
		$quotes = [];
		$errors = [];
		$matchedHandles = [];

		foreach ($this->rateProviders() as $provider) {
			$requests = $this->rateRequests($provider, $methodQuotes);
			if ($requests === []) {
				continue;
			}

			foreach ($requests as $request) {
				$matchedHandles[$request['method']->handle] = true;
			}

			$result = $this->requestRates($provider, $order, $requests, $options);
			$quotes = [...$quotes, ...$result['quotes']];
			$errors = [...$errors, ...$result['errors']];
		}

		$unmatchedQuotes = collect($methodQuotes)
			->filter(static fn (ShippingQuote $method): bool => ! isset($matchedHandles[$method->handle]))
			->all();

		$quotes = [...$quotes, ...$unmatchedQuotes];
		/** @var list<ShippingQuote> $quotes */
		$quotes = collect($quotes)
			->filter(static fn (ShippingQuote $quote): bool => $plugin->deliveries->getAvailableIntegrations($quote) !== [])
			->keyBy('handle')
			->sort(static function (ShippingQuote $first, ShippingQuote $second): int {
				if (! $first->amount instanceof Money || ! $second->amount instanceof Money) {
					return ($first->amount instanceof Money ? 0 : 1) <=> ($second->amount instanceof Money ? 0 : 1);
				}

				return $first->amount->compare($second->amount);
			})
			->values()
			->all();

		return [
			'quotes' => $quotes,
			'errors' => $errors,
		];
	}

	/**
	 * @return Collection<int, ShippingMethodInterface>
	 */
	private function eligibleMethods(Order $order): Collection
	{
		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();
		$mappings = Plugin::getInstance()->carrierMappings;
		/** @var Collection<int, ShippingMethod> $nativeMethods */
		$nativeMethods = $commerce->getShippingMethods()->getAllShippingMethods($order->storeId);
		/** @var Collection<int, ShippingMethodInterface> $methods */
		$methods = $nativeMethods
			->filter(static function (ShippingMethod $method) use ($order): bool {
				$method->clearMatchingShippingRuleCache();

				return $method->getIsEnabled() && $method->matchOrder($order);
			})
			->merge($mappings->getPostieMethods($order));

		return $methods;
	}

	/**
	 * @return list<ShippingQuote>
	 */
	private function methodQuotes(Order $order, string $fingerprint, string $currency): array
	{
		$mappings = Plugin::getInstance()->carrierMappings;

		/** @var list<ShippingQuote> $quotes */
		$quotes = $this->eligibleMethods($order)
			->map(static function (ShippingMethodInterface $method) use ($order, $fingerprint, $currency, $mappings): ShippingQuote {
				$source = $mappings->getSource($method, (string) $order->getStore()->handle);

				return new ShippingQuote([
					'handle' => $method instanceof PostieShippingMethod ? $source['source'] . ':' . $method->getHandle() : $method->getHandle(),
					'name' => $method->getName(),
					'amount' => MoneyValues::fromCommerce($method instanceof PostieShippingMethod ? 0 : $method->getPriceForOrder($order), $currency),
					'fingerprint' => $fingerprint,
					'expiresAt' => new DateTimeImmutable('+15 minutes'),
					'metadata' => [
						'storeId' => $order->storeId,
						'methodId' => $method->getId(),
						'mappingSource' => $source['source'],
						'mappingSourceService' => $source['sourceService'],
					],
				]);
			})
			->values()
			->all();

		return $quotes;
	}

	/**
	 * @param ProviderInterface&ShippingRateProviderInterface $provider
	 * @param list<ShippingQuote> $methods
	 * @return list<array{method: ShippingQuote, services: list<string>}>
	 */
	private function rateRequests(ShippingRateProviderInterface $provider, array $methods): array
	{
		$mappings = Plugin::getInstance()->carrierMappings;
		$requests = [];

		foreach ($methods as $method) {
			/** @var array{source: string, sourceService: string} $source */
			$source = [
				'source' => $method->metadata['mappingSource'],
				'sourceService' => $method->metadata['mappingSourceService'],
			];
			$services = $mappings->getMatchingServices($provider, $source);
			if ($services === []) {
				continue;
			}

			$requests[] = [
				'method' => $method,
				'services' => $services,
			];
		}

		return $requests;
	}

	/**
	 * @param ProviderInterface&ShippingRateProviderInterface $provider
	 * @param list<array{method: ShippingQuote, services: list<string>}> $requests
	 * @param array<string, mixed> $options
	 * @return array{quotes: list<ShippingQuote>, errors: list<array{provider: string, message: string}>}
	 */
	private function requestRates(ShippingRateProviderInterface $provider, Order $order, array $requests, array $options): array
	{
		$quotes = [];
		$errors = [];

		try {
			$quotes = $provider->getShippingQuotesForMethods($order, $requests, $options);
		} catch (Throwable $throwable) {
			foreach ($requests as $request) {
				$quotes = [...$quotes, ...$provider->getUnquotedShippingMethods($request['method'], $request['services'])];
			}

			Craft::error(sprintf('Shipping rates unavailable from %s: %s', $provider::class, $throwable->getMessage()), Plugin::HANDLE);
			$errors[] = [
				'provider' => $provider::displayName(),
				'message' => $throwable->getMessage(),
			];
		}

		return [
			'quotes' => $quotes,
			'errors' => $errors,
		];
	}

	private function assertShipDate(Shipment $shipment): void
	{
		if ($this->isShipDateInPast($shipment)) {
			throw new RuntimeException(Craft::t('shipments', 'delivery.shipDateInPast'));
		}
	}

	/**
	 * @return list<ProviderInterface&ShippingRateProviderInterface>
	 */
	private function rateProviders(): array
	{
		$plugin = Plugin::getInstance();
		$providers = [];
		foreach ($plugin->integrations->getAllIntegrations() as $integration) {
			if ($integration->isEnabled() && ($provider = $integration->getProvider()) instanceof ShippingRateProviderInterface) {
				$providers[] = $provider;
			}
		}

		return $providers;
	}

	private function reload(Shipment $shipment): Shipment
	{
		if ($shipment->id === null) {
			throw new RuntimeException(Craft::t('shipments', 'delivery.errors.saveTheShipmentBeforeCalculatingOrSelectingShipping'));
		}

		$current = Shipment::find()->id($shipment->id)->status(null)->one();
		return $current instanceof Shipment ? $current : throw new RuntimeException(Craft::t('shipments', 'error.shipmentNotFound'));
	}

	private function applyQuote(Shipment $shipment, ?ShippingQuote $quote): void
	{
		$shipment->shippingMethodHandle = $quote?->handle;
		$shipment->shippingMethodName = $quote?->name;
		$shipment->shippingAmountMinor = $quote?->amount?->getAmount();
		$shipment->shippingCurrency = $quote?->amount?->getCurrency()
			->getCode();
	}

	/**
	 * @return list<ShippingQuote>
	 */
	private function refreshUnlocked(Shipment $shipment): array
	{
		$this->assertEditable($shipment);
		$previousQuotes = collect($this->getQuotes($shipment))->keyBy('handle');
		$result = $this->calculateResult($shipment);
		$quotes = $result['quotes'];
		foreach ($quotes as $quote) {
			$previousQuote = $previousQuotes->get($quote->handle);
			if ($previousQuote instanceof ShippingQuote && $previousQuote->carrierCost instanceof Money && $quote->carrierCost instanceof Money && ! $quote->carrierCost->equals($previousQuote->carrierCost)) {
				$quote->previousCarrierCost = $previousQuote->carrierCost;
			}
		}

		$shipment->shippingSnapshot = [
			'fingerprint' => $this->fingerprint($shipment),
			'quotes' => collect($quotes)->map(static fn (ShippingQuote $quote): array => $quote->toSnapshot())->all(),
			'errors' => $result['errors'],
			'calculatedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
		];

		$selected = collect($quotes)
			->firstWhere('handle', $shipment->shippingMethodHandle);

		if (! $selected instanceof ShippingQuote) {
			$selected = $this->getCustomerChosenQuotes($shipment, $quotes)[0] ?? null;
		}

		if (! $selected instanceof ShippingQuote) {
			// Available quotes are sorted by their final shipment price.
			$selected = $quotes[0] ?? null;
		}

		$this->applyQuote($shipment, $selected);
		$this->persist($shipment);
		return $quotes;
	}

	private function selectUnlocked(Shipment $shipment, string $handle): ShippingQuote
	{
		$this->assertEditable($shipment);
		$quote = collect($this->getQuotes($shipment))->firstWhere('handle', $handle);
		if (! $quote instanceof ShippingQuote) {
			throw new RuntimeException(Craft::t('shipments', 'delivery.errors.refreshRatesAndSelectAnAvailableShipmentMethod'));
		}

		$this->assertCurrent($shipment, $quote);
		$this->applyQuote($shipment, $quote);
		$this->persist($shipment);
		return $quote;
	}

	private function persist(Shipment $shipment): void
	{
		if (! $shipment->validate(['shippingMethodHandle', 'shippingMethodName', 'shippingAmountMinor', 'shippingCurrency', 'shippingSnapshot'])) {
			throw new RuntimeException(implode(' ', $shipment->getFirstErrors()));
		}

		$record = ShipmentRecord::findOne($shipment->id) ?? throw new RuntimeException(Craft::t('shipments', 'error.shipmentNotFound'));
		$record->setAttributes([
			'shippingMethodHandle' => $shipment->shippingMethodHandle,
			'shippingMethodName' => $shipment->shippingMethodName,
			'shippingAmount' => $shipment->shippingAmountMinor,
			'shippingCurrency' => $shipment->shippingCurrency,
			'shippingSnapshot' => $shipment->shippingSnapshot,
		], false);
		if (! $record->save(false)) {
			throw new RuntimeException(Craft::t('shipments', 'delivery.errors.unableToSaveShipmentShippingDetails'));
		}
	}

	/**
	 * @template T
	 * @param callable(Shipment): T $operation
	 * @return T
	 */
	private function locked(Shipment $shipment, callable $operation): mixed
	{
		if ($shipment->id === null) {
			throw new RuntimeException(Craft::t('shipments', 'delivery.errors.saveTheShipmentBeforeCalculatingOrSelectingShipping'));
		}

		$mutex = Craft::$app->getMutex();
		$key = 'shipments:delivery:' . $shipment->id;
		if (! $mutex->acquire($key, 0)) {
			throw new RuntimeException(Craft::t('shipments', 'delivery.errors.anotherDeliveryActionIsAlreadyRunningForThisShipment'));
		}

		try {
			return $operation($this->reload($shipment));
		} finally {
			$mutex->release($key);
		}
	}

	private function assertCurrent(Shipment $shipment, ShippingQuote $quote): void
	{
		if (! $quote->expiresAt instanceof DateTimeImmutable || $quote->expiresAt < new DateTimeImmutable() || $quote->fingerprint !== $this->fingerprint($shipment)) {
			throw new RuntimeException(Craft::t('shipments', 'delivery.errors.shipmentDetailsOrConfigurationChangedOrTheQuoteExpired'));
		}
	}
}
