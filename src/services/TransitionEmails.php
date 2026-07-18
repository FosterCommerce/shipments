<?php

declare(strict_types=1);

namespace fostercommerce\shipments\services;

use Craft;
use craft\db\Query;
use fostercommerce\shipments\db\Table;
use fostercommerce\shipments\enums\Status;
use fostercommerce\shipments\events\ShipmentStatusChangedEvent;
use fostercommerce\shipments\models\Email;
use fostercommerce\shipments\Plugin;
use fostercommerce\shipments\queue\jobs\SendShipmentEmailJob;
use fostercommerce\shipments\records\ShipmentTransitionEmail;
use Throwable;
use yii\base\Component;

/**
 * Binds emails to status transitions.
 */
class TransitionEmails extends Component
{
	/**
	 * Returns bound emails for a given target Status value.
	 *
	 * @return list<Email>
	 */
	public function findForTransition(Status $toCode): array
	{
		$ids = (new Query())
			->select(['emailId'])
			->from(Table::TRANSITION_EMAILS)
			->where([
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
	 * Returns an email's bound toCode strings.
	 *
	 * @return list<string>
	 */
	public function findBindingsForEmailId(int $emailId): array
	{
		/** @var list<string> $toCodes */
		$toCodes = (new Query())
			->select(['toCode'])
			->from(Table::TRANSITION_EMAILS)
			->where([
				'emailId' => $emailId,
			])
			->column();

		return $toCodes;
	}

	/**
	 * Replaces all bindings for the given email. Unknown codes are dropped silently.
	 *
	 * @param list<string> $toCodes
	 * @throws Throwable
	 */
	public function saveBindingsForEmailId(int $emailId, array $toCodes): void
	{
		$transaction = Craft::$app->getDb()->beginTransaction();

		try {
			Craft::$app->getDb()->createCommand()
				->delete(Table::TRANSITION_EMAILS, [
					'emailId' => $emailId,
				])
				->execute();

			foreach ($toCodes as $code) {
				if (! Status::tryFrom($code) instanceof Status) {
					continue;
				}

				$record = new ShipmentTransitionEmail();
				$record->emailId = $emailId;
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

		$emails = $this->findForTransition($event->toCode);
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
				'toCode' => $event->toCode->value,
				'historyId' => $event->history->id,
				'userId' => $event->user?->id,
				'message' => $event->message,
			]));
		}
	}
}
