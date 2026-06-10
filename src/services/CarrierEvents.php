<?php

declare(strict_types=1);

namespace fostercommerce\shipments\services;

use Craft;
use craft\helpers\DateTimeHelper;
use craft\helpers\Json;
use DateTime;
use DateTimeInterface;
use DateTimeZone;
use fostercommerce\shipments\elements\Shipment;
use fostercommerce\shipments\enums\CarrierEventReason;
use fostercommerce\shipments\enums\ShippingStatus;
use fostercommerce\shipments\enums\StatusAxis;
use fostercommerce\shipments\enums\TrackedOrderState;
use fostercommerce\shipments\models\Integration;
use fostercommerce\shipments\Plugin;
use fostercommerce\shipments\records\CarrierEvent as CarrierEventRecord;
use Throwable;
use yii\base\Component;
use yii\base\InvalidArgumentException;
use yii\db\IntegrityException;

/** Ingests + dedupes carrier events and drives shipping-axis transitions. */
class CarrierEvents extends Component
{
	/**
	 * @param array<string, mixed> $payload
	 * @return array{deduped: bool, resolved: ?ShippingStatus, shipment: Shipment}
	 * @throws Throwable
	 */
	public function ingest(Shipment $shipment, array $payload, ?Integration $source = null): array
	{
		$codeRaw = $payload['code'] ?? '';
		$code = is_string($codeRaw) ? $codeRaw : '';
		if ($code === '') {
			// REST guard: this surfaces in the carrier-event JSON response, not the CP, so it is
			// not translated (matches ApiController's hardcoded-English messages).
			throw new InvalidArgumentException('`code` is required.');
		}

		$dateOccurredRaw = $payload['dateOccurred'] ?? null;
		$dateOccurred = is_string($dateOccurredRaw) || is_int($dateOccurredRaw) || is_array($dateOccurredRaw) || $dateOccurredRaw instanceof DateTimeInterface
			? DateTimeHelper::toDateTime($dateOccurredRaw)
			: false;
		if (! $dateOccurred instanceof DateTime) {
			throw new InvalidArgumentException('`dateOccurred` must be a valid timestamp.');
		}

		$externalCode = isset($payload['externalCode']) && is_string($payload['externalCode']) && $payload['externalCode'] !== ''
			? $payload['externalCode']
			: null;

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		$resolved = ShippingStatus::tryFrom($code);
		if (! $resolved instanceof ShippingStatus && $source instanceof Integration && $source->id !== null) {
			$mapped = $plugin->integrationStatusMaps->resolveInbound($source->id, StatusAxis::Shipping, $code);
			if ($mapped instanceof ShippingStatus) {
				$resolved = $mapped;
				$externalCode ??= $code;
			} else {
				// No mapping for this carrier code, so the event can't be projected onto a
				// shipping status. Log it for the admin instead of silently dropping it.
				Craft::error(
					"Unmapped inbound shipping status code \"{$code}\" from integration {$source->id}.",
					Plugin::HANDLE,
				);
			}
		}

		$rawPayload = $payload['rawPayload'] ?? null;
		$rawPayloadString = match (true) {
			is_array($rawPayload), is_object($rawPayload) => Json::encode($rawPayload),
			is_string($rawPayload) => $rawPayload,
			default => null,
		};

		// UTC-normalize before hashing so the same instant delivered with different
		// timezone offsets collapses to a single dedupe key.
		$dateOccurredUtc = (clone $dateOccurred)->setTimezone(new DateTimeZone('UTC'));
		$eventHash = hash('sha256', implode('|', [
			(string) $shipment->id,
			$code,
			$dateOccurredUtc->format(DateTime::ATOM),
			$externalCode ?? '',
		]));

		$persistedCode = $resolved instanceof ShippingStatus ? $resolved->value : $code;

		$reason = $this->resolveEventReason($shipment);
		if ($reason !== CarrierEventReason::Projected) {
			$this->logSkippedDisabledTarget($shipment, $reason, $code, $externalCode, $eventHash, $source);
		}

		$record = new CarrierEventRecord();
		$record->shipmentId = (int) $shipment->id;
		$record->integrationId = $source?->id;
		$record->code = $persistedCode;
		$record->description = isset($payload['description']) && is_string($payload['description']) ? $payload['description'] : null;
		$record->dateOccurred = $dateOccurred;
		$record->receivedAt = new DateTime();
		$record->locationCity = isset($payload['locationCity']) && is_string($payload['locationCity']) ? $payload['locationCity'] : null;
		$record->locationRegion = isset($payload['locationRegion']) && is_string($payload['locationRegion']) ? $payload['locationRegion'] : null;
		$record->locationCountry = isset($payload['locationCountry']) && is_string($payload['locationCountry']) ? strtoupper(substr($payload['locationCountry'], 0, 2)) : null;
		$record->rawPayload = $rawPayloadString;
		$record->eventHash = $eventHash;
		$record->reason = $reason->value;

		try {
			$record->save(false);
		} catch (IntegrityException) {
			return [
				'deduped' => true,
				'resolved' => $resolved,
				'shipment' => $shipment,
			];
		}

		if ($reason !== CarrierEventReason::Projected) {
			return [
				'deduped' => false,
				'resolved' => $resolved,
				'shipment' => $shipment,
			];
		}

		if ($resolved instanceof ShippingStatus) {
			$user = Craft::$app->getUser()->getIdentity();
			$transitioned = $plugin->shipments->applyTransition($shipment, StatusAxis::Shipping, $resolved, $user, null, $source, $externalCode);
			if ($transitioned instanceof Shipment) {
				$shipment = $transitioned;
			}
		}

		return [
			'deduped' => false,
			'resolved' => $resolved,
			'shipment' => $shipment,
		];
	}

	/**
	 * Decides whether an inbound event should project onto the shipment's status. `Projected`
	 * is the normal path; `SkippedDisabledShipment` records the event for audit when the
	 * element-level `enabled` flag is off; `SkippedAttentionOff` covers the per-order
	 * "Order requires shipping" lightswitch being off.
	 */
	private function resolveEventReason(Shipment $shipment): CarrierEventReason
	{
		if (! $shipment->enabled) {
			return CarrierEventReason::SkippedDisabledShipment;
		}

		if ($shipment->orderId !== null) {
			/** @var Plugin $plugin */
			$plugin = Plugin::getInstance();
			$tracked = $plugin->trackedOrders->findForOrderId($shipment->orderId);
			if ($tracked !== null && $tracked->state === TrackedOrderState::Ignored->value) {
				return CarrierEventReason::SkippedAttentionOff;
			}
		}

		return CarrierEventReason::Projected;
	}

	private function logSkippedDisabledTarget(
		Shipment $shipment,
		CarrierEventReason $reason,
		string $code,
		?string $externalCode,
		string $eventHash,
		?Integration $source,
	): void {
		$fields = [
			'shipmentId=' . $shipment->id,
			'orderId=' . ($shipment->orderId ?? 'null'),
			'reference=' . ($shipment->reference ?? ''),
			'axis=shipping',
			'code=' . $code,
			'externalCode=' . ($externalCode ?? ''),
			'integrationHandle=' . ($source?->handle ?? ''),
			'reason=' . $reason->value,
			'eventHash=' . $eventHash,
		];

		Craft::warning(
			'Webhook update rejected; target shipment is disabled. ' . implode(' ', $fields),
			Plugin::HANDLE,
		);
	}
}
