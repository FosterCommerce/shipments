<?php

declare(strict_types=1);

namespace fostercommerce\shipments\queue\jobs;

use Craft;
use craft\helpers\Db;
use craft\queue\BaseJob;
use DateTime;
use fostercommerce\shipments\base\Provider;
use fostercommerce\shipments\db\Table;
use fostercommerce\shipments\elements\Shipment;
use fostercommerce\shipments\errors\IntegrationException;
use fostercommerce\shipments\errors\PermanentIntegrationException;
use fostercommerce\shipments\models\Integration;
use fostercommerce\shipments\Plugin;
use RuntimeException;
use yii\db\Expression;

/**
 * Outbound push for one (shipment, integration) pair. `PermanentIntegrationException`
 * marks the job failed without retry; plain `IntegrationException` lets Craft retry.
 */
class PushShipmentJob extends BaseJob
{
	public ?int $shipmentId = null;

	public ?int $integrationId = null;

	public function execute($queue): void
	{
		if ($this->shipmentId === null || $this->integrationId === null) {
			return;
		}

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		$shipment = $plugin->shipments->findById($this->shipmentId, includeTrashed: false);
		if (! $shipment instanceof Shipment) {
			Craft::warning("PushShipmentJob: shipment {$this->shipmentId} not found.", Plugin::HANDLE);
			return;
		}

		$integration = $plugin->integrations->getIntegrationById($this->integrationId);
		if (! $integration instanceof Integration || ! $integration->isEnabled()) {
			Craft::warning("PushShipmentJob: integration {$this->integrationId} missing or disabled.", Plugin::HANDLE);
			return;
		}

		$provider = $integration->getProvider();
		if (! $provider instanceof Provider) {
			Craft::warning("PushShipmentJob: integration {$integration->handle} has no provider bound.", Plugin::HANDLE);
			return;
		}

		$order = $plugin->shipments->loadOrder($shipment->orderId);
		if ($order === null) {
			Craft::warning("PushShipmentJob: order {$shipment->orderId} for shipment {$shipment->id} not found.", Plugin::HANDLE);
			return;
		}

		try {
			$provider->sendShipmentWithEvents($shipment, $order);
			$this->recordAttempt($shipment, null);
		} catch (PermanentIntegrationException $permanentIntegrationException) {
			$this->recordAttempt($shipment, $permanentIntegrationException->getMessage());
			// Re-throw as a plain RuntimeException so Craft's queue treats this as a
			// non-retryable terminal failure. A retryable IntegrationException would
			// let the queue retry on its own schedule.
			throw new RuntimeException($permanentIntegrationException->getMessage(), 0, $permanentIntegrationException);
		} catch (IntegrationException $integrationException) {
			$this->recordAttempt($shipment, $integrationException->getMessage());
			throw $integrationException;
		}
	}

	protected function defaultDescription(): ?string
	{
		return Craft::t(Plugin::HANDLE, 'queue.pushingShipment', [
			'id' => (string) ($this->shipmentId ?? '?'),
		]);
	}

	/**
	 * Persist attempt metadata without going through afterSave. Uses a SQL `pushAttemptCount + 1`
	 * expression so concurrent pushes on the same shipment each increment rather than racing on a stale read.
	 */
	private function recordAttempt(Shipment $shipment, ?string $error): void
	{
		if ($shipment->id === null) {
			return;
		}

		Db::update(Table::SHIPMENTS, [
			'dateLastPushAttempt' => Db::prepareDateForDb(new DateTime()),
			'lastPushAttemptError' => $error,
			'pushAttemptCount' => new Expression('[[pushAttemptCount]] + 1'),
		], [
			'id' => $shipment->id,
		], [], false);
	}
}
