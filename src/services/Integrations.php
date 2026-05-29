<?php

declare(strict_types=1);

namespace fostercommerce\shipments\services;

use Craft;
use craft\db\Query;
use craft\errors\MissingComponentException;
use craft\events\ConfigEvent;
use craft\helpers\Component as ComponentHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\helpers\Typecast;
use fostercommerce\shipments\base\Provider;
use fostercommerce\shipments\base\ProviderInterface;
use fostercommerce\shipments\db\Table;
use fostercommerce\shipments\errors\PermanentIntegrationException;
use fostercommerce\shipments\events\RegisterIntegrationsEvent;
use fostercommerce\shipments\models\Integration;
use fostercommerce\shipments\Plugin;
use fostercommerce\shipments\providers\MissingProvider;
use fostercommerce\shipments\records\Integration as IntegrationRecord;
use Illuminate\Support\Collection;
use Throwable;
use yii\base\Component;
use yii\base\Exception;

/**
 * Provider-type registry + integration-row CRUD. Writes go through project config.
 */
class Integrations extends Component
{
	public const EVENT_REGISTER_INTEGRATIONS = 'registerShipmentsIntegrations';

	public const CONFIG_INTEGRATIONS_KEY = 'shipments.integrations';

	/**
	 * @var list<class-string>|null
	 */
	private ?array $allProviderTypes = null;

	/**
	 * @var Collection<int, Integration>|null
	 */
	private ?Collection $allIntegrations = null;

	// Provider-type registry

	/**
	 * Every registered provider class. Fires `EVENT_REGISTER_INTEGRATIONS` on first call.
	 *
	 * @return list<class-string>
	 */
	public function getAllProviderTypes(): array
	{
		if ($this->allProviderTypes !== null) {
			return $this->allProviderTypes;
		}

		$event = new RegisterIntegrationsEvent();
		$this->trigger(self::EVENT_REGISTER_INTEGRATIONS, $event);

		$types = [];
		foreach ($event->types as $type) {
			if (! is_string($type)) {
				continue;
			}

			if (! class_exists($type)) {
				continue;
			}

			if (! is_subclass_of($type, ProviderInterface::class)) {
				continue;
			}

			$types[] = $type;
		}

		$this->allProviderTypes = $types;
		return $types;
	}

	/**
	 * Selectable subset of `getAllProviderTypes()`; providers can opt out via
	 * `isSelectable()`.
	 *
	 * @return list<class-string>
	 */
	public function getSelectableProviderTypes(): array
	{
		$selectable = [];
		foreach ($this->getAllProviderTypes() as $type) {
			/** @var class-string $type */
			if (! method_exists($type, 'isSelectable') || $type::isSelectable()) {
				$selectable[] = $type;
			}
		}

		return $selectable;
	}

	/**
	 * Build a provider from a saved config. Returns `MissingProvider` if the class can't
	 * resolve (Craft's `MissingComponentTrait` pattern).
	 *
	 * @param array<string, mixed>|string $config
	 * @throws Throwable
	 */
	public function createProvider(array|string $config): ProviderInterface
	{
		if (is_string($config)) {
			$config = [
				'type' => $config,
			];
		}

		// Spread `settings` into top-level so typed properties hydrate via the constructor.
		$settings = $config['settings'] ?? [];
		unset($config['settings']);
		if (is_array($settings)) {
			$config += $settings;
		}

		$type = $config['type'] ?? null;
		if (! is_string($type) || ! is_subclass_of($type, ProviderInterface::class)) {
			$config['errorMessage'] = is_string($type)
				? Craft::t(Plugin::HANDLE, 'error.noProviderForType', [
					'type' => $type,
				])
				: Craft::t(Plugin::HANDLE, 'error.integrationMissingProviderType');
			$config['expectedType'] = is_string($type) ? $type : '';
			unset($config['type']);
			return new MissingProvider($config);
		}

		try {
			/** @var array{type: class-string<ProviderInterface>, __class?: string} $config */
			$component = ComponentHelper::createComponent($config, ProviderInterface::class);
		} catch (MissingComponentException $missingComponentException) {
			$config['errorMessage'] = $missingComponentException->getMessage();
			$config['expectedType'] = $config['type'];
			unset($config['type']);

			return new MissingProvider($config);
		}

		/** @var ProviderInterface $component */
		return $component;
	}

	// Integration CRUD

	/**
	 * @return Collection<int, Integration>
	 */
	public function getAllIntegrations(): Collection
	{
		if (! $this->allIntegrations instanceof Collection) {
			$this->allIntegrations = collect();

			/** @var list<array<string, mixed>> $rows */
			$rows = (new Query())
				->select([
					'id',
					'name',
					'handle',
					'urlTemplate',
					'provider',
					'settings',
					'enabled',
					'sortOrder',
					'uid',
				])
				->from(Table::INTEGRATIONS)
				->orderBy([
					'sortOrder' => SORT_ASC,
				])
				->all();

			foreach ($rows as $row) {
				$this->allIntegrations->push($this->modelFromRow($row));
			}
		}

		return $this->allIntegrations;
	}

	public function getIntegrationById(int $id): ?Integration
	{
		return $this->getAllIntegrations()->firstWhere('id', $id);
	}

	public function getIntegrationByHandle(string $handle): ?Integration
	{
		return $this->getAllIntegrations()->firstWhere('handle', $handle);
	}

	public function getIntegrationByUid(string $uid): ?Integration
	{
		return $this->getAllIntegrations()->firstWhere('uid', $uid);
	}

	/**
	 * Resolve an enabled integration handle to its bound provider. Callers needing a specific
	 * provider type narrow with `instanceof` on the return value.
	 *
	 * Throws `PermanentIntegrationException` so callers can map to HTTP status without coupling
	 * the service to `yii\web`. Exception code carries the intended HTTP status: 404 for an
	 * unknown handle, 400 for disabled / unbound rows.
	 *
	 * @throws PermanentIntegrationException
	 */
	public function resolveEnabledProvider(string $handle): Provider
	{
		$integration = $this->getIntegrationByHandle($handle);
		if (! $integration instanceof Integration) {
			throw new PermanentIntegrationException("Unknown integration: {$handle}", 404);
		}

		if (! $integration->enabled) {
			throw new PermanentIntegrationException("Integration {$handle} is disabled.", 400);
		}

		$provider = $integration->getProvider();
		if (! $provider instanceof Provider) {
			throw new PermanentIntegrationException("Integration {$handle} has no provider bound.", 400);
		}

		return $provider;
	}

	/**
	 * @throws Exception
	 */
	public function saveIntegration(Integration $integration, bool $runValidation = true): bool
	{
		$isNew = $integration->id === null;

		if ($runValidation && ! $integration->validate()) {
			Craft::warning(
				'Integration not saved due to validation errors: ' . Json::encode($integration->getErrors()),
				Plugin::HANDLE,
			);
			return false;
		}

		$integrationUid = $isNew
			? StringHelper::UUID()
			: Db::uidById(Table::INTEGRATIONS, (int) $integration->id);

		if ($integrationUid === null) {
			throw new Exception("No integration exists with id {$integration->id}.");
		}

		Craft::$app->getProjectConfig()->set(
			self::CONFIG_INTEGRATIONS_KEY . '.' . $integrationUid,
			$integration->getConfig(),
		);

		if ($isNew) {
			$integration->id = Db::idByUid(Table::INTEGRATIONS, $integrationUid);
			$integration->uid = $integrationUid;
		}

		$this->allIntegrations = null;

		return true;
	}

	/**
	 * @throws Throwable
	 */
	public function handleChangedIntegration(ConfigEvent $event): void
	{
		$integrationUid = (string) ($event->tokenMatches[0] ?? '');
		if ($integrationUid === '') {
			return;
		}

		$data = $event->newValue;

		if (! is_array($data)) {
			return;
		}

		$transaction = Craft::$app->getDb()->beginTransaction();

		try {
			$integrationRecord = $this->getIntegrationRecord($integrationUid);

			$integrationRecord->name = (string) ($data['name'] ?? '');
			$integrationRecord->handle = (string) ($data['handle'] ?? '');
			$urlTemplate = $data['urlTemplate'] ?? null;
			$integrationRecord->urlTemplate = is_string($urlTemplate) && $urlTemplate !== '' ? $urlTemplate : null;

			$provider = $data['provider'] ?? null;
			$integrationRecord->provider = is_string($provider) && $provider !== '' ? $provider : null;

			$settings = $data['settings'] ?? null;
			$integrationRecord->settings = is_array($settings) && $settings !== [] ? Json::encode($settings) : null;

			$integrationRecord->enabled = (bool) ($data['enabled'] ?? true);
			$integrationRecord->sortOrder = (int) ($data['sortOrder'] ?? 99);
			$integrationRecord->uid = $integrationUid;

			$integrationRecord->save(false);

			$transaction->commit();
		} catch (Throwable $throwable) {
			$transaction->rollBack();
			throw $throwable;
		}

		$this->allIntegrations = null;
	}

	public function deleteIntegrationById(int $id): bool
	{
		$integration = $this->getIntegrationById($id);
		if (! $integration instanceof Integration || $integration->uid === null) {
			return false;
		}

		Craft::$app->getProjectConfig()->remove(self::CONFIG_INTEGRATIONS_KEY . '.' . $integration->uid);
		return true;
	}

	/**
	 * @throws Throwable
	 */
	public function handleDeletedIntegration(ConfigEvent $event): void
	{
		$integrationUid = (string) ($event->tokenMatches[0] ?? '');
		if ($integrationUid === '') {
			return;
		}

		$transaction = Craft::$app->getDb()->beginTransaction();

		try {
			$integrationRecord = $this->getIntegrationRecord($integrationUid);
			if ($integrationRecord->id === null) {
				$transaction->commit();
				return;
			}

			$integrationRecord->delete();
			$transaction->commit();
		} catch (Throwable $throwable) {
			$transaction->rollBack();
			throw $throwable;
		}

		$this->allIntegrations = null;
	}

	/**
	 * @param list<int> $ids
	 */
	public function reorderIntegrations(array $ids): bool
	{
		$projectConfig = Craft::$app->getProjectConfig();
		$uidsByIds = Db::uidsByIds(Table::INTEGRATIONS, $ids);

		foreach ($ids as $sortOrder => $integrationId) {
			if (! isset($uidsByIds[$integrationId])) {
				continue;
			}

			$projectConfig->set(
				self::CONFIG_INTEGRATIONS_KEY . '.' . $uidsByIds[$integrationId] . '.sortOrder',
				$sortOrder + 1,
			);
		}

		return true;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function modelFromRow(array $row): Integration
	{
		$settingsRaw = $row['settings'] ?? null;
		if (is_string($settingsRaw) && $settingsRaw !== '') {
			$decoded = Json::decodeIfJson($settingsRaw);
			$row['settings'] = is_array($decoded) ? $decoded : [];
		} else {
			$row['settings'] = [];
		}

		Typecast::properties(Integration::class, $row);
		return new Integration($row);
	}

	private function getIntegrationRecord(string $uid): IntegrationRecord
	{
		/** @var IntegrationRecord|null $integrationRecord */
		$integrationRecord = IntegrationRecord::find()
			->where([
				'uid' => $uid,
			])
			->one();

		return $integrationRecord ?? new IntegrationRecord();
	}
}
