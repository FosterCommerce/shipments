<?php

declare(strict_types=1);

namespace fostercommerce\shipments\queue\jobs;

use Craft;
use craft\elements\User;
use craft\queue\BaseJob;
use fostercommerce\shipments\enums\Status;
use fostercommerce\shipments\models\ShipmentEmailContext;
use fostercommerce\shipments\Plugin;
use fostercommerce\shipments\records\ShipmentStatusHistory;
use yii\base\Exception;

/**
 * Sends one shipment notification email for a given status transition. Carries
 * the history row id for narrative metadata and the message that triggered it.
 */
class SendShipmentEmailJob extends BaseJob
{
	public ?int $shipmentId = null;

	public ?int $emailId = null;

	public ?int $historyId = null;

	public ?string $toCode = null;

	public ?int $userId = null;

	public ?string $message = null;

	public function execute($queue): void
	{
		if ($this->shipmentId === null || $this->emailId === null) {
			return;
		}

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		$shipment = $plugin->shipments->findById($this->shipmentId);
		if ($shipment === null) {
			return;
		}

		$email = $plugin->emails->getEmailById($this->emailId);
		if ($email === null) {
			return;
		}

		$order = $plugin->shipments->loadOrder($shipment->orderId);
		if ($order === null) {
			return;
		}

		$history = $this->historyId !== null
			? ShipmentStatusHistory::findOne([
				'id' => $this->historyId,
			])
			: null;

		if ($this->historyId !== null && ! $history instanceof ShipmentStatusHistory) {
			Craft::warning(
				"Shipment status history record {$this->historyId} not found for email job; sending without history context.",
				Plugin::HANDLE,
			);
		}

		$toCode = $this->toCode !== null ? Status::tryFrom($this->toCode) : null;
		$fromCode = $history instanceof ShipmentStatusHistory && $history->fromCode !== null && $history->fromCode !== ''
			? Status::tryFrom($history->fromCode)
			: null;

		$user = null;
		if ($this->userId !== null) {
			$userElement = Craft::$app->getUsers()->getUserById($this->userId);
			if ($userElement instanceof User) {
				$user = $userElement;
			}
		}

		$context = new ShipmentEmailContext(
			shipment: $shipment,
			order: $order,
			fromCode: $fromCode,
			toCode: $toCode,
			history: $history instanceof ShipmentStatusHistory ? $history : null,
			user: $user,
			message: $this->message,
		);

		$error = '';
		if (! $plugin->emails->sendForShipment($email, $context, $error)) {
			throw new Exception($error !== '' ? $error : 'Shipment email failed to send.');
		}
	}

	protected function defaultDescription(): ?string
	{
		return Craft::t(Plugin::HANDLE, 'queue.sendingEmail', [
			'emailId' => $this->emailId,
			'shipmentId' => $this->shipmentId,
		]);
	}
}
