<?php

declare(strict_types=1);

namespace fostercommerce\shipments\services;

use Craft;
use craft\db\Query;
use fostercommerce\shipments\db\Table;
use fostercommerce\shipments\enums\FulfillmentStatus;
use fostercommerce\shipments\enums\ShippingStatus;
use fostercommerce\shipments\enums\StatusAxis;
use fostercommerce\shipments\events\ShipmentStatusChangedEvent;
use fostercommerce\shipments\models\Email;
use fostercommerce\shipments\Plugin;
use fostercommerce\shipments\queue\jobs\SendShipmentEmailJob;
use fostercommerce\shipments\records\ShipmentTransitionEmail;
use Throwable;
use yii\base\Component;

/**
 * Binds emails to axis transitions. When a shipment transitions into a given
 * `(axis, toCode)`, every bound email is queued via `SendShipmentEmailJob`.
 */
class TransitionEmails extends Component
{
	/**
	 * Bindings for a given axis + target enum value. Returns full `Email` models
	 * (not just ids) so callers can filter by enabled / skip the broken ones.
	 *
	 * @return list<Email>
	 */
	public function findForTransition(StatusAxis $axis, FulfillmentStatus|ShippingStatus $toCode): array
	{
		$ids = (new Query())
			->select(['emailId'])
			->from(Table::TRANSITION_EMAILS)
			->where([
				'axis' => $axis->value,
				'toCode' => $toCode->value,
			])
			->column();

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		$emails = [];
		foreach ($ids as $id) {
			$email = $plugin->emails->getEmailById((int) $id);
			if ($email instanceof Email) {
				$emails[] = $email;
			}
		}

		return $emails;
	}

	/**
	 * Bindings for an individual email, keyed by axis, valued by a list of toCode strings.
	 *
	 * @return array<string, list<string>>
	 */
	public function findBindingsForEmailId(int $emailId): array
	{
		/** @var list<array{axis: string, toCode: string}> $rows */
		$rows = (new Query())
			->select(['axis', 'toCode'])
			->from(Table::TRANSITION_EMAILS)
			->where([
				'emailId' => $emailId,
			])
			->all();

		$bindings = [
			StatusAxis::Fulfillment->value => [],
			StatusAxis::Shipping->value => [],
		];
		foreach ($rows as $row) {
			$bindings[$row['axis']][] = $row['toCode'];
		}

		return $bindings;
	}

	/**
	 * Replaces all bindings for the given email. Accepts two lists of enum-value
	 * strings (one per axis); unknown codes are dropped silently.
	 *
	 * @param list<string> $fulfillmentToCodes
	 * @param list<string> $shippingToCodes
	 * @throws Throwable
	 */
	public function saveBindingsForEmailId(int $emailId, array $fulfillmentToCodes, array $shippingToCodes): void
	{
		$transaction = Craft::$app->getDb()->beginTransaction();

		try {
			Craft::$app->getDb()->createCommand()
				->delete(Table::TRANSITION_EMAILS, [
					'emailId' => $emailId,
				])
				->execute();

			foreach ($fulfillmentToCodes as $code) {
				if (! FulfillmentStatus::tryFrom($code) instanceof FulfillmentStatus) {
					continue;
				}

				$record = new ShipmentTransitionEmail();
				$record->emailId = $emailId;
				$record->axis = StatusAxis::Fulfillment->value;
				$record->toCode = $code;
				$record->save(false);
			}

			foreach ($shippingToCodes as $code) {
				if (! ShippingStatus::tryFrom($code) instanceof ShippingStatus) {
					continue;
				}

				$record = new ShipmentTransitionEmail();
				$record->emailId = $emailId;
				$record->axis = StatusAxis::Shipping->value;
				$record->toCode = $code;
				$record->save(false);
			}

			$transaction->commit();
		} catch (Throwable $throwable) {
			$transaction->rollBack();
			throw $throwable;
		}
	}

	/**
	 * Deletes every binding for the given email id. Called when the email itself is
	 * removed so stale rows don't linger.
	 */
	public function pruneForEmailId(int $emailId): void
	{
		Craft::$app->getDb()->createCommand()
			->delete(Table::TRANSITION_EMAILS, [
				'emailId' => $emailId,
			])
			->execute();
	}

	/**
	 * Queues one `SendShipmentEmailJob` per bound email when a shipment transitions.
	 */
	public function onShipmentStatusChanged(ShipmentStatusChangedEvent $event): void
	{
		if ($event->shipment->id === null || $event->history->id === null) {
			return;
		}

		$emails = $this->findForTransition($event->axis, $event->toCode);
		if ($emails === []) {
			return;
		}

		$queue = Craft::$app->getQueue();
		foreach ($emails as $email) {
			if (! $email->enabled) {
				continue;
			}

			if ($email->id === null) {
				continue;
			}

			$queue->push(new SendShipmentEmailJob([
				'shipmentId' => $event->shipment->id,
				'emailId' => $email->id,
				'axis' => $event->axis->value,
				'toCode' => $event->toCode->value,
				'historyId' => $event->history->id,
				'userId' => $event->user?->id,
				'message' => $event->message,
			]));
		}
	}
}
