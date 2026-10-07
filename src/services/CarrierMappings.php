<?php

declare(strict_types=1);

namespace fostercommerce\shipments\services;

use Craft;
use craft\commerce\base\ShippingMethodInterface;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use fostercommerce\shipments\base\AutomaticCarrierMappingProviderInterface;
use fostercommerce\shipments\base\ShippingRateProviderInterface;
use verbb\postie\base\Provider as PostieProvider;
use verbb\postie\events\ModifyShippingMethodsEvent;
use verbb\postie\models\ShippingMethod as PostieShippingMethod;
use verbb\postie\Postie;
use verbb\postie\services\Service as PostieService;
use yii\base\Component;

class CarrierMappings extends Component
{
	/**
	 * @return list<array{value: string, label: string, group: string, services: array<string, string>}>
	 */
	public function getSources(): array
	{
		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();
		$sources = [];
		$stores = $commerce->getStores()->getAllStores();
		foreach ($stores as $store) {
			foreach ($commerce->getShippingMethods()->getAllShippingMethods($store->id) as $method) {
				if ($method->getId() === null) {
					continue;
				}

				$sources[] = [
					'value' => 'commerce:' . $store->handle . ':' . $method->getHandle(),
					'label' => $method->getName() . ($stores->count() > 1 ? ' (' . $store->getName() . ')' : ''),
					'group' => Craft::t('shipments', 'settings.integrations.commerceMethods'),
					'services' => [],
				];
			}
		}

		if (Craft::$app->getPlugins()->isPluginEnabled('postie')) {
			/** @var Postie $postie */
			$postie = Postie::getInstance();
			foreach ($postie->getProviders()->getAllProviders() as $provider) {
				$sources[] = [
					'value' => 'postie:' . $provider->uid,
					'label' => (string) $provider->name,
					'group' => 'Postie',
					'services' => [
						'*' => Craft::t('shipments', 'settings.integrations.allServices'),
					] + $this->postieServices($provider),
				];
			}
		}

		return $sources;
	}

	/**
	 * @return list<array{source: string, sourceService: string, service: string}>
	 */
	public function getAutomaticMappings(AutomaticCarrierMappingProviderInterface $provider): array
	{
		if (! Craft::$app->getPlugins()->isPluginEnabled('postie')) {
			return [];
		}

		/** @var Postie $postie */
		$postie = Postie::getInstance();
		$providerType = $provider->getAutomaticMappingProviderType();
		/** @var list<array{source: string, sourceService: string, service: string}> $mappings */
		$mappings = collect($postie->getProviders()->getAllProviders())
			->filter(static fn (PostieProvider $source): bool => $source->getEnabled() && $source instanceof $providerType)
			->map(static fn (PostieProvider $source): array => [
				'source' => 'postie:' . $source->uid,
				'sourceService' => '*',
				'service' => 'auto',
			])
			->values()->all();
		return $mappings;
	}

	/**
	 * @return list<PostieShippingMethod>
	 */
	public function getPostieMethods(Order $order): array
	{
		if (! Craft::$app->getPlugins()->isPluginEnabled('postie')) {
			return [];
		}

		/** @var Postie $postie */
		$postie = Postie::getInstance();
		/** @var list<PostieShippingMethod> $methods */
		$methods = collect($postie->getProviders()->getAllEnabledProviders())
			->flatMap(function (PostieProvider $provider) use ($postie, $order): array {
				$services = $this->postieServices($provider);
				if ($provider::getServiceList() === [] && $provider->services === []) {
					$services = [
						'*' => (string) $provider->name,
					];
				}

				return collect($services)
					->map(function (string $name, string $code) use ($postie, $provider, $order): PostieShippingMethod {
						// Postie's rule evaluation clears provider settings, so use a separate instance.
						$method = $postie->getProviders()->getShippingMethodForService(clone $provider, $code);
						$method->name = $name;
						$method->storeId = $order->storeId;
						$method->rate = 0;
						$method->rateOptions = [];
						return $method;
					})
					->filter(static fn (PostieShippingMethod $method): bool => $method->getIsEnabled() && $method->matchOrder($order))
					->values()
					->all();
			})
			->values()
			->all();

		$modifyShippingMethodsEvent = new ModifyShippingMethodsEvent([
			'order' => $order,
			'shippingMethods' => $methods,
		]);

		$postie->getService()->trigger(PostieService::EVENT_BEFORE_REGISTER_SHIPPING_METHODS, $modifyShippingMethodsEvent);
		/** @var list<PostieShippingMethod> $methods */
		$methods = collect($modifyShippingMethodsEvent->shippingMethods)->values()->all();
		return $methods;
	}

	/**
	 * @return array{source: string, sourceService: string}
	 */
	public function getSource(ShippingMethodInterface $method, string $storeHandle): array
	{
		if (Craft::$app->getPlugins()->isPluginEnabled('postie') && $method instanceof PostieShippingMethod) {
			/** @var PostieProvider $provider */
			$provider = $method->provider;
			return [
				'source' => 'postie:' . $provider->uid,
				'sourceService' => $method->getHandle(),
			];
		}

		return [
			'source' => 'commerce:' . $storeHandle . ':' . $method->getHandle(),
			'sourceService' => '',
		];
	}

	/**
	 * @param array{source: string, sourceService: string} $source
	 * @return list<string>
	 */
	public function getMatchingServices(ShippingRateProviderInterface $provider, array $source): array
	{
		/** @var list<string> $services */
		$services = collect($provider->getCarrierMappings())
			->filter(static fn (array $mapping): bool => $mapping['source'] === $source['source']
				&& ($mapping['sourceService'] === '*' || $mapping['sourceService'] === $source['sourceService']))
			->flatMap(static fn (array $mapping): array => $mapping['service'] === 'auto' && str_starts_with($source['source'], 'postie:') && $provider instanceof AutomaticCarrierMappingProviderInterface
				? $provider->getAutoMappedServices($source['sourceService'])
				: (array_key_exists($mapping['service'], $provider->getServices()) ? [$mapping['service']] : []))
			->unique()->values()->all();
		return $services;
	}

	/**
	 * @return array<string, string>
	 */
	private function postieServices(PostieProvider $provider): array
	{
		$labels = $this->serviceLabels($provider::getServiceList());
		/** @var array<string, array{enabled?: bool|string, name?: string}> $configuredServices */
		$configuredServices = $provider->services;
		$restrictServices = (bool) $provider->getRestrictServices();
		$codes = collect($configuredServices)->keys();
		if (! $restrictServices) {
			$codes = collect($labels)->keys()->merge($codes)->unique();
		}

		return $codes->filter(static fn (string $code): bool => (bool) ($configuredServices[$code]['enabled'] ?? ! $restrictServices))
			->mapWithKeys(static fn (string $code): array => [
				$code => $configuredServices[$code]['name'] ?? $labels[$code] ?? $code,
			])->all();
	}

	/**
	 * @param array<array-key, mixed> $services
	 * @return array<string, string>
	 */
	private function serviceLabels(array $services): array
	{
		return collect($services)->flatMap(fn (mixed $label, int|string $code): array => is_array($label)
			? $this->serviceLabels($label)
			: [
				(string) $code => is_string($label) ? $label : (string) $code,
			])->all();
	}
}
