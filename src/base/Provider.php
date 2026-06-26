<?php

declare(strict_types=1);

namespace fostercommerce\shipments\base;

use Craft;
use craft\base\SavableComponent;
use craft\commerce\elements\Order;
use craft\web\Request;
use craft\web\Response;
use fostercommerce\shipments\elements\Shipment;
use fostercommerce\shipments\errors\IntegrationException;
use fostercommerce\shipments\events\CancelIntegrationPayloadEvent;
use fostercommerce\shipments\events\IntegrationConnectionEvent;
use fostercommerce\shipments\events\SendIntegrationPayloadEvent;
use fostercommerce\shipments\models\Integration;
use fostercommerce\shipments\Plugin;
use Throwable;

/**
 * Abstract base for fulfillment-integration providers.
 *
 * Providers send shipments and may handle remote-initiated requests through `handleGatewayRequest()`.
 */
abstract class Provider extends SavableComponent implements ProviderInterface
{
	/**
	 * @event SendIntegrationPayloadEvent The event fired before `sendShipment()`. Set `$event->isValid = false` to skip.
	 */
	public const EVENT_BEFORE_SEND = 'beforeSend';

	/**
	 * @event SendIntegrationPayloadEvent The event fired after a successful `sendShipment()`.
	 */
	public const EVENT_AFTER_SEND = 'afterSend';

	/**
	 * @event CancelIntegrationPayloadEvent The event fired before `cancelShipment()`. Set `$event->isValid = false` to skip.
	 */
	public const EVENT_BEFORE_CANCEL = 'beforeCancel';

	/**
	 * @event CancelIntegrationPayloadEvent The event fired after a successful `cancelShipment()`.
	 */
	public const EVENT_AFTER_CANCEL = 'afterCancel';

	public const EVENT_BEFORE_CHECK_CONNECTION = 'beforeCheckConnection';

	public const EVENT_AFTER_CHECK_CONNECTION = 'afterCheckConnection';

	public const CONNECT_SUCCESS = 'success';

	public const CONNECT_FAIL = 'fail';

	// Populated from the owning Integration row by Integrations::createProvider.
	public ?string $name = null;

	public ?string $handle = null;

	public ?string $uid = null;

	/**
	 * @var array<string, mixed>
	 */
	public array $settings = [];

	public bool $enabled = true;

	private ?Integration $sourceIntegration = null;

	/**
	 * Create or update the shipment on the remote system.
	 *
	 * @throws IntegrationException
	 */
	abstract public function sendShipment(Shipment $shipment, Order $order): void;

	public function supportsPush(): bool
	{
		return false;
	}

	/**
	 * Cancel the shipment on the remote system. Override to handle; the default throws.
	 *
	 * @throws IntegrationException
	 */
	public function cancelShipment(Shipment $shipment, Order $order): void
	{
		throw new IntegrationException(Craft::t(Plugin::HANDLE, 'error.cancelNotImplemented'));
	}

	public function handleGatewayRequest(Request $request): Response
	{
		throw new IntegrationException('Requests are not implemented by this provider.');
	}

	public function getSourceIntegration(): ?Integration
	{
		if ($this->sourceIntegration instanceof Integration) {
			return $this->sourceIntegration;
		}

		if ($this->handle === null || $this->handle === '') {
			return null;
		}

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();
		$this->sourceIntegration = $plugin->integrations->getIntegrationByHandle($this->handle);

		return $this->sourceIntegration;
	}

	public function checkConnection(): bool
	{
		$beforeEvent = new IntegrationConnectionEvent();
		$beforeEvent->integration = $this;
		$this->trigger(self::EVENT_BEFORE_CHECK_CONNECTION, $beforeEvent);

		if (! $beforeEvent->isValid) {
			return false;
		}

		try {
			$success = $this->fetchConnection();
		} catch (Throwable $throwable) {
			self::error($this, 'Connection check failed: ' . $throwable->getMessage());
			$success = false;
		}

		$afterEvent = new IntegrationConnectionEvent();
		$afterEvent->integration = $this;
		$afterEvent->success = $success;
		$this->trigger(self::EVENT_AFTER_CHECK_CONNECTION, $afterEvent);

		return $afterEvent->success;
	}

	/**
	 * Event-wrapped send. Providers override `sendShipment()`, not this.
	 *
	 * @throws IntegrationException
	 */
	public function sendShipmentWithEvents(Shipment $shipment, Order $order): void
	{
		$beforeEvent = new SendIntegrationPayloadEvent();
		$beforeEvent->integration = $this;
		$beforeEvent->shipment = $shipment;
		$beforeEvent->order = $order;
		$this->trigger(self::EVENT_BEFORE_SEND, $beforeEvent);

		if (! $beforeEvent->isValid) {
			self::info($this, 'Send skipped by event hook.');
			return;
		}

		$this->sendShipment($shipment, $order);

		$afterEvent = new SendIntegrationPayloadEvent();
		$afterEvent->integration = $this;
		$afterEvent->shipment = $shipment;
		$afterEvent->order = $order;
		$this->trigger(self::EVENT_AFTER_SEND, $afterEvent);
	}

	/**
	 * Event-wrapped cancel. Providers override `cancelShipment()`, not this.
	 *
	 * @throws IntegrationException
	 */
	public function cancelShipmentWithEvents(Shipment $shipment, Order $order): void
	{
		$beforeEvent = new CancelIntegrationPayloadEvent();
		$beforeEvent->integration = $this;
		$beforeEvent->shipment = $shipment;
		$beforeEvent->order = $order;
		$this->trigger(self::EVENT_BEFORE_CANCEL, $beforeEvent);

		if (! $beforeEvent->isValid) {
			self::info($this, 'Cancel skipped by event hook.');
			return;
		}

		$this->cancelShipment($shipment, $order);

		$afterEvent = new CancelIntegrationPayloadEvent();
		$afterEvent->integration = $this;
		$afterEvent->shipment = $shipment;
		$afterEvent->order = $order;
		$this->trigger(self::EVENT_AFTER_CANCEL, $afterEvent);
	}

	public static function type(): string
	{
		return 'shipment';
	}

	public static function isSelectable(): bool
	{
		return true;
	}

	public function getSettingsUtilityHtml(): ?string
	{
		return null;
	}

	public static function info(ProviderInterface $integration, string $message): void
	{
		Craft::info(sprintf('[%s] %s', $integration->handle ?? 'integration', $message), Plugin::HANDLE);
	}

	public static function error(ProviderInterface $integration, string $message): void
	{
		Craft::error(sprintf('[%s] %s', $integration->handle ?? 'integration', $message), Plugin::HANDLE);
	}

	/**
	 * Probe the remote system. Override in providers that can; default assumes connectivity.
	 * `checkConnection()` wraps this with events.
	 */
	protected function fetchConnection(): bool
	{
		return true;
	}

	/**
	 * @return array<array-key, mixed>
	 */
	protected function defineRules(): array
	{
		return [
			[['enabled', 'settings'], 'safe'],
		];
	}
}
