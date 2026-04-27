<?php

declare(strict_types=1);

namespace fostercommerce\shipments\models;

use Craft;
use craft\base\Model;
use craft\helpers\UrlHelper;
use craft\validators\HandleValidator;
use craft\validators\UniqueValidator;
use fostercommerce\shipments\base\ProviderInterface;
use fostercommerce\shipments\Plugin;
use fostercommerce\shipments\records\Integration as IntegrationRecord;
use Throwable;

/**
 * A configured integration row. Active when `provider` is set; reference-only if not.
 *
 * @property-read string $cpEditUrl
 */
class Integration extends Model implements \Stringable
{
	public ?int $id = null;

	public ?string $name = null;

	public ?string $handle = null;

	public ?string $urlTemplate = null;

	/**
	 * FQCN of a `base\Provider` subclass. Null = reference-only.
	 */
	public ?string $provider = null;

	/**
	 * @var array<string, mixed>
	 */
	public array $settings = [];

	public bool $enabled = true;

	public ?int $sortOrder = null;

	public ?string $uid = null;

	private ?ProviderInterface $resolvedProvider = null;

	public function __toString(): string
	{
		if ($this->name !== null && $this->name !== '') {
			return $this->name;
		}

		if ($this->handle !== null && $this->handle !== '') {
			return $this->handle;
		}

		return (string) Craft::t(Plugin::HANDLE, '(unnamed integration)');
	}

	public function getCpEditUrl(): string
	{
		return UrlHelper::cpUrl('shipments/settings/integrations/' . ($this->id ?? ''));
	}

	/**
	 * Substitute `{externalId}` into the URL template, or null if no template is set.
	 */
	public function buildUrl(string $externalId): ?string
	{
		if ($this->urlTemplate === null || $this->urlTemplate === '') {
			return null;
		}

		return strtr($this->urlTemplate, [
			'{externalId}' => $externalId,
		]);
	}

	/**
	 * Resolve the bound provider, or null when `provider` is unset. Unknown classes return
	 * a `MissingProvider` placeholder.
	 *
	 * @throws Throwable
	 */
	public function getProvider(): ?ProviderInterface
	{
		if ($this->provider === null || $this->provider === '') {
			return null;
		}

		if ($this->resolvedProvider instanceof ProviderInterface) {
			return $this->resolvedProvider;
		}

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		$this->resolvedProvider = $plugin->integrations->createProvider([
			'type' => $this->provider,
			'name' => $this->name,
			'handle' => $this->handle,
			'enabled' => $this->enabled,
			'settings' => $this->settings,
			'uid' => $this->uid,
		]);

		return $this->resolvedProvider;
	}

	/**
	 * Returns the project config payload for this integration.
	 *
	 * @return array<string, mixed>
	 */
	public function getConfig(): array
	{
		return [
			'name' => $this->name,
			'handle' => $this->handle,
			'urlTemplate' => $this->urlTemplate,
			'provider' => $this->provider,
			'settings' => $this->settings,
			'enabled' => $this->enabled,
			'sortOrder' => $this->sortOrder ?? 99,
		];
	}

	/**
	 * @return array<array-key, mixed>
	 */
	protected function defineRules(): array
	{
		return [
			[['name', 'handle'], 'required'],
			[['handle'],
				UniqueValidator::class,
				'targetClass' => IntegrationRecord::class,
				'targetAttribute' => ['handle'],
				'message' => Craft::t(Plugin::HANDLE, '{attribute} “{value}” has already been taken.'),
			],
			[['handle'],
				HandleValidator::class,
				'reservedWords' => ['id', 'dateCreated', 'dateUpdated', 'uid', 'title'],
			],
			[['id', 'urlTemplate', 'provider', 'settings', 'enabled', 'sortOrder', 'uid'], 'safe'],
		];
	}
}
