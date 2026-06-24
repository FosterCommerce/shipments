<?php

declare(strict_types=1);

namespace fostercommerce\shipments\providers;

use Craft;
use craft\base\MissingComponentInterface;
use craft\base\MissingComponentTrait;
use craft\commerce\elements\Order;
use craft\web\Request;
use craft\web\Response;
use fostercommerce\shipments\base\Provider;
use fostercommerce\shipments\elements\Shipment;
use fostercommerce\shipments\errors\IntegrationException;
use fostercommerce\shipments\Plugin;

/**
 * Placeholder returned by `Integrations::createProvider` when a saved provider class can't be
 * resolved (uninstalled, renamed). Follows Craft's `MissingComponentTrait` so the settings UI
 * can still render the saved row without crashing.
 */
class MissingProvider extends Provider implements MissingComponentInterface
{
	use MissingComponentTrait;

	public static function displayName(): string
	{
		return Craft::t(Plugin::HANDLE, 'error.missingIntegration');
	}

	public static function isSelectable(): bool
	{
		return false;
	}

	public function sendShipment(Shipment $shipment, Order $order): void
	{
		throw new IntegrationException(Craft::t(Plugin::HANDLE, 'error.providerNotAvailable', [
			'type' => $this->expectedType,
		]));
	}

	public function cancelShipment(Shipment $shipment, Order $order): void
	{
		throw new IntegrationException(Craft::t(Plugin::HANDLE, 'error.providerNotAvailable', [
			'type' => $this->expectedType,
		]));
	}

	public function handleGatewayRequest(Request $request): Response
	{
		throw new IntegrationException("The provider \"{$this->expectedType}\" is not available.");
	}
}
