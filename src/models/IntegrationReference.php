<?php

declare(strict_types=1);

namespace fostercommerce\shipments\models;

use craft\base\Model;
use DateTime;
use fostercommerce\shipments\Plugin;

/**
 * A single shipment's reference into one external fulfillment system. Many-to-one to
 * `Integration` (the configured system) and `Shipment`.
 *
 * @property-read ?Integration $source
 * @property-read ?string $resolvedUrl
 */
class IntegrationReference extends Model
{
	public ?int $id = null;

	public int $shipmentId = 0;

	public int $integrationId = 0;

	public string $externalId = '';

	public ?string $url = null;

	public ?DateTime $dateCreated = null;

	public ?DateTime $dateUpdated = null;

	public ?string $uid = null;

	public function getSource(): ?Integration
	{
		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();
		return $plugin->integrations->getIntegrationById($this->integrationId);
	}

	/**
	 * Per-row `url` override wins; falls back to the source's URL template.
	 */
	public function getResolvedUrl(): ?string
	{
		if ($this->url !== null && $this->url !== '') {
			return $this->url;
		}

		$integration = $this->getSource();
		if (! $integration instanceof Integration) {
			return null;
		}

		return $integration->buildUrl($this->externalId);
	}

	/**
	 * @return array<array-key, mixed>
	 */
	protected function defineRules(): array
	{
		return [
			[['shipmentId', 'integrationId', 'externalId'], 'required'],
			[['shipmentId', 'integrationId'], 'integer'],
			[['externalId', 'url'], 'string'],
		];
	}
}
