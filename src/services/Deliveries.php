<?php

declare(strict_types=1);

namespace fostercommerce\shipments\services;

use Craft;
use craft\db\Query;
use craft\elements\Asset;
use craft\helpers\Assets as AssetHelper;
use craft\helpers\DateTimeHelper;
use craft\helpers\FileHelper;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\models\VolumeFolder;
use DateTime;
use fostercommerce\shipments\base\DeliveryProviderInterface;
use fostercommerce\shipments\db\Table;
use fostercommerce\shipments\elements\Shipment;
use fostercommerce\shipments\enums\Status;
use fostercommerce\shipments\errors\DeliveryRejectedException;
use fostercommerce\shipments\events\DeliveryEvent;
use fostercommerce\shipments\helpers\DeliveryDiagnostics;
use fostercommerce\shipments\models\Delivery;
use fostercommerce\shipments\models\DeliveryDocument;
use fostercommerce\shipments\models\DeliveryResult;
use fostercommerce\shipments\models\Integration;
use fostercommerce\shipments\models\ShippingQuote;
use fostercommerce\shipments\Plugin;
use fostercommerce\shipments\records\Delivery as DeliveryRecord;
use fostercommerce\shipments\records\Shipment as ShipmentRecord;
use Money\Money;
use RuntimeException;
use Throwable;
use yii\base\Component;

class Deliveries extends Component
{
	/**
	 * Emitted after a successful booking has saved references and protected PDFs.
	 *
	 * The DeliveryEvent contains the current shipment and delivery. Follow-up retries can
	 * emit this event again, so listeners must deduplicate by delivery ID or operation ID.
	 *
	 * ```php
	 * use fostercommerce\shipments\events\DeliveryEvent;
	 * use fostercommerce\shipments\services\Deliveries;
	 * use yii\base\Event;
	 *
	 * Event::on(Deliveries::class, Deliveries::EVENT_AFTER_CREATE, static function (DeliveryEvent $event): void {
	 *     $deliveryId = $event->delivery->id;
	 *     $shipmentId = $event->shipment->id;
	 *     // Use these IDs to enqueue a deduplicated follow-up in your integration.
	 * });
	 * ```
	 *
	 * @event DeliveryEvent
	 */
	public const EVENT_AFTER_CREATE = 'afterCreateDelivery';

	/**
	 * @event DeliveryEvent Fired after the carrier confirms cancellation.
	 */
	public const EVENT_AFTER_CANCEL = 'afterCancelDelivery';

	/**
	 * Get enabled providers that support the selected quote.
	 *
	 * @return list<Integration>
	 */
	public function getAvailableIntegrations(ShippingQuote $quote): array
	{
		/** @var list<Integration> $integrations */
		$integrations = Plugin::getInstance()->integrations->getAllIntegrations()->filter(static function (Integration $integration) use ($quote): bool {
			$provider = $integration->getProvider();
			return $integration->isEnabled() && $provider instanceof DeliveryProviderInterface && $provider->supportsMethod($quote);
		})->values()->all();
		return $integrations;
	}

	/**
	 * Get delivery history with the newest booking first.
	 *
	 * @return list<Delivery>
	 */
	public function getForShipment(int $shipmentId): array
	{
		/** @var list<array<string, mixed>> $rows */
		$rows = (new Query())->from(Table::DELIVERIES)->where([
			'[[shipmentId]]' => $shipmentId,
		])->orderBy([
			'[[id]]' => SORT_DESC,
		])->all();
		/** @var list<Delivery> $deliveries */
		$deliveries = collect($rows)
			->map(fn (array $row): Delivery => $this->hydrate($row))->all();
		return $deliveries;
	}

	/**
	 * Get the newest active delivery.
	 */
	public function getLatestForShipment(int $shipmentId): ?Delivery
	{
		return collect($this->getForShipment($shipmentId))->first(static fn (Delivery $delivery): bool => $delivery->getIsActive());
	}

	/**
	 * Get a delivery by its ID.
	 */
	public function getById(int $id): ?Delivery
	{
		/** @var array<string, mixed>|false $row */
		$row = (new Query())->from(Table::DELIVERIES)->where([
			'[[id]]' => $id,
		])->one();
		return is_array($row) ? $this->hydrate($row) : null;
	}

	/**
	 * Create a carrier delivery under the shipment lock.
	 *
	 * @throws RuntimeException
	 */
	public function create(Shipment $shipment): Delivery
	{
		return $this->locked((int) $shipment->id, function () use ($shipment): Delivery {
			$shipment = $this->shipment((int) $shipment->id);
			$this->assertShipmentEditable((int) $shipment->id);
			$this->documentFolder();
			if ($shipment->status === Status::Cancelled->value) {
				throw new RuntimeException(Craft::t('shipments', 'delivery.errors.cancelledShipmentCannotCreateADelivery'));
			}

			if (! $shipment->enabled) {
				throw new RuntimeException(Craft::t('shipments', 'delivery.errors.disabledShipmentCannotCreateADelivery'));
			}

			$quote = Plugin::getInstance()->shipping->validateForBooking($shipment);
			$integration = collect($this->getAvailableIntegrations($quote))->firstWhere('uid', $quote->providerUid);
			if (! $integration instanceof Integration || ! $integration->getProvider() instanceof DeliveryProviderInterface) {
				throw new RuntimeException(Craft::t('shipments', 'delivery.errors.selectAnEnabledDeliveryProviderForThisShippingMethod'));
			}

			/** @var array<string, mixed> $options */
			$options = Plugin::getInstance()->shipping->getOptions($shipment);
			$order = Plugin::getInstance()->shipmentCalculation->create($shipment);
			$delivery = new Delivery([
				'shipmentId' => $shipment->id,
				'integrationId' => $integration->id,
				'operationId' => StringHelper::UUID(),
				'status' => 'processing',
				'transitDays' => $quote->transitDays,
				'createdBy' => Craft::$app->getUser()->getId(),
				'requestData' => [
					'options' => $options,
					'quote' => $quote->toSnapshot(),
				],
			]);
			$this->saveOrFail($delivery);
			try {
				try {
					$result = $integration->getProvider()->createDelivery($shipment, $order, $quote, $options, $delivery->operationId);
				} catch (DeliveryRejectedException $exception) {
					DeliveryDiagnostics::log($exception, 'createRejected', $delivery->operationId);
					$delivery->status = 'failed';
					$delivery->lastError = $exception->getMessage();
					$this->saveOrFail($delivery);

					return $delivery;
				}

				$this->recordResult($shipment, $delivery, $result);
			} catch (Throwable $throwable) {
				DeliveryDiagnostics::log($throwable, 'create', $delivery->operationId);
				// Never submit again after an ambiguous response or local persistence failure.
				$delivery->status = $delivery->externalId === null ? 'uncertain' : 'pending';
				$delivery->lastError = Craft::t('shipments', 'delivery.errors.carrierResultCouldNotBeCompleted');
				$this->saveOrFail($delivery);
			}

			if ($delivery->status === 'pending' && $delivery->externalId !== null) {
				$this->retrieve($shipment, $delivery);
			}

			return $delivery;
		});
	}

	/**
	 * Retrieve documents for an unfinished delivery without purchasing shipping.
	 *
	 * @throws RuntimeException
	 */
	public function refresh(Delivery $delivery): Delivery
	{
		return $this->locked($delivery->shipmentId, function () use ($delivery): Delivery {
			$delivery = $this->getById((int) $delivery->id) ?? throw new RuntimeException(Craft::t('shipments', 'delivery.errors.deliveryNotFound'));
			if (! in_array($delivery->status, ['pending', 'uncertain', 'processing'], true)) {
				throw new RuntimeException(Craft::t('shipments', 'delivery.errors.thisDeliveryCannotRetrieveDocumentsInItsCurrentState'));
			}

			$this->retrieve($this->shipment($delivery->shipmentId), $delivery);

			return $delivery;
		});
	}

	/**
	 * Request cancellation and retain the booking history.
	 *
	 * @throws RuntimeException
	 */
	public function void(Delivery $delivery): void
	{
		$this->locked($delivery->shipmentId, function () use ($delivery): Delivery {
			$delivery = $this->getById((int) $delivery->id) ?? throw new RuntimeException(Craft::t('shipments', 'delivery.errors.deliveryNotFound'));
			if (! in_array($delivery->status, ['pending', 'created', 'voiding', 'void_uncertain'], true) || $delivery->externalId === null) {
				throw new RuntimeException(Craft::t('shipments', 'delivery.errors.thisDeliveryHasNoConfirmedReferenceThatCanBe'));
			}

			$previousStatus = $delivery->status;
			$delivery->status = 'voiding';
			$this->saveOrFail($delivery);
			try {
				$this->provider($delivery)->cancelDelivery($this->shipment($delivery->shipmentId), $delivery);
			} catch (DeliveryRejectedException $exception) {
				DeliveryDiagnostics::log($exception, 'voidRejected', $delivery->operationId);
				$delivery->status = $previousStatus;
				$delivery->lastError = $exception->getMessage();
				$this->saveOrFail($delivery);
				return $delivery;
			} catch (Throwable $exception) {
				DeliveryDiagnostics::log($exception, 'void', $delivery->operationId);
				$delivery->status = 'void_uncertain';
				$delivery->lastError = Craft::t('shipments', 'delivery.errors.cancellationCouldNotBeConfirmed');
				$this->saveOrFail($delivery);
				return $delivery;
			}

			$delivery->status = 'voided';
			$delivery->lastError = null;
			$delivery->metadata['voidedAt'] = (new DateTime())->format(DATE_ATOM);
			$delivery->metadata['voidedBy'] = Craft::$app->getUser()->getId();
			$transaction = Craft::$app->getDb()->beginTransaction();
			try {
				$this->saveOrFail($delivery);
				// Retain all booking costs/history; cancellation is not proof of a refund.
				$record = ShipmentRecord::findOne([
					'id' => $delivery->shipmentId,
					'trackingNumber' => $delivery->trackingNumber,
				]);
				if ($record !== null) {
					$record->trackingNumber = null;
					$record->trackingUrl = null;
					$record->transitDays = null;
					$record->carrier = null;
					$record->service = null;
					if (! $record->save(false)) {
						throw new RuntimeException(Craft::t('shipments', 'delivery.errors.unableToSaveShipmentTracking'));
					}
				}

				$transaction->commit();
			} catch (Throwable $throwable) {
				$transaction->rollBack();
				throw $throwable;
			}

			$this->publishSafely($delivery);
			return $delivery;
		});
	}

	/**
	 * Record an operator’s confirmation that an unresolved request was not booked.
	 */
	public function acknowledgeFailed(Delivery $delivery, string $note): void
	{
		$this->locked($delivery->shipmentId, function () use ($delivery, $note): Delivery {
			$delivery = $this->getById((int) $delivery->id) ?? throw new RuntimeException(Craft::t('shipments', 'delivery.errors.deliveryNotFound'));
			if (! in_array($delivery->status, ['processing', 'pending', 'uncertain'], true) || $delivery->trackingNumber !== null || $delivery->references !== [] || $delivery->documents !== [] || trim($note) === '') {
				throw new RuntimeException(Craft::t('shipments', 'delivery.errors.onlyAnUnresolvedRequestWithoutTrackingOrDocumentsCan'));
			}

			$delivery->metadata['reconciliation'] = [
				'note' => trim($note),
				'userId' => Craft::$app->getUser()->getId(),
				'date' => (new DateTime())->format(DATE_ATOM),
			];
			$delivery->status = 'failed';
			$delivery->lastError = Craft::t('shipments', 'delivery.errors.operatorConfirmedThatTheCarrierDidNotCreateThis');
			$this->saveOrFail($delivery);
			return $delivery;
		});
	}

	/**
	 * Validate and persist a delivery, returning false on validation or save failure.
	 */
	public function save(Delivery $delivery): bool
	{
		if (! $delivery->validate()) {
			return false;
		}

		$record = $delivery->id === null ? new DeliveryRecord() : DeliveryRecord::findOne($delivery->id);
		if (! $record instanceof DeliveryRecord) {
			return false;
		}

		$data = $delivery->getAttributes();
		unset($data['id'], $data['dateCreated'], $data['dateUpdated'], $data['uid']);
		$record->setAttributes($data, false);
		if (! $record->save(false)) {
			return false;
		}

		/** @var int $id */
		$id = $record->getAttribute('id');
		$delivery->id = $id;
		return true;
	}

	/**
	 * Reject changes while a carrier delivery remains active.
	 *
	 * @throws RuntimeException
	 */
	public function assertShipmentEditable(int $shipmentId): void
	{
		foreach ($this->getForShipment($shipmentId) as $delivery) {
			if ($delivery->getIsActive()) {
				throw new RuntimeException(Craft::t('shipments', 'delivery.errors.thisShipmentHasAnActiveCarrierDelivery'));
			}
		}
	}

	private function documentFolder(): VolumeFolder
	{
		$uid = Plugin::getInstance()->getSettings()->deliveryDocumentVolumeUid;
		$volume = $uid === '' ? null : Craft::$app->getVolumes()->getVolumeByUid($uid);
		$folder = $volume === null ? null : Craft::$app->getAssets()->getRootFolderByVolumeId((int) $volume->id);
		return $folder ?? throw new DeliveryRejectedException(Craft::t('shipments', 'delivery.errors.configureDocumentVolume'));
	}

	private function provider(Delivery $delivery): DeliveryProviderInterface
	{
		$provider = Plugin::getInstance()->integrations->getIntegrationById($delivery->integrationId)?->getProvider();
		if (! $provider instanceof DeliveryProviderInterface) {
			throw new RuntimeException(Craft::t('shipments', 'delivery.errors.deliveryProviderIsUnavailable'));
		}

		return $provider;
	}

	private function shipment(int $id): Shipment
	{
		$shipment = Shipment::find()->id($id)->status(null)->one();
		return $shipment instanceof Shipment ? $shipment : throw new RuntimeException(Craft::t('shipments', 'error.shipmentNotFound'));
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate(array $row): Delivery
	{
		foreach (['metadata', 'requestData', 'references', 'documents'] as $key) {
			$row[$key] = is_string($row[$key] ?? null) ? Json::decode($row[$key]) : ($row[$key] ?? []);
		}

		foreach (['dateCreated', 'dateUpdated', 'dateNotified'] as $key) {
			$value = $row[$key] ?? null;
			$row[$key] = is_string($value) && $value !== '' ? DateTimeHelper::toDateTime($value) : null;
		}

		return new Delivery($row);
	}

	private function retrieve(Shipment $shipment, Delivery $delivery): void
	{
		try {
			$result = $this->provider($delivery)->fetchDelivery($shipment, $delivery);
			$this->recordResult($shipment, $delivery, $result);
		} catch (Throwable $throwable) {
			DeliveryDiagnostics::log($throwable, 'retrieve', $delivery->operationId);
			$delivery->status = $delivery->externalId === null ? 'uncertain' : 'pending';
			$delivery->lastError = $throwable instanceof DeliveryRejectedException ? $throwable->getMessage() : Craft::t('shipments', 'delivery.errors.documentRetrievalIsIncomplete');
			$this->saveOrFail($delivery);
		}
	}

	private function recordResult(Shipment $shipment, Delivery $delivery, DeliveryResult $result): void
	{
		$this->mergeResult($delivery, $result);

		// Persist the remote reference before document I/O or any follow-up work.
		$this->saveOrFail($delivery);
		$this->storeResultDocuments($delivery, $result);

		if (
			! $result->pending
			&& $delivery->externalId !== null
			&& $delivery->documents !== []
			&& ($delivery->trackingNumber !== null || $delivery->references !== [])
		) {
			$delivery->status = 'created';
		}

		$this->completeDelivery($shipment, $delivery);
	}

	private function mergeResult(Delivery $delivery, DeliveryResult $result): void
	{
		$delivery->externalId = $result->externalId !== '' ? $result->externalId : $delivery->externalId;
		$delivery->carrier = $result->carrier !== '' ? $result->carrier : $delivery->carrier;
		$delivery->service = $result->service !== '' ? $result->service : $delivery->service;
		$delivery->trackingNumber = $result->trackingNumber ?? $delivery->trackingNumber;
		$delivery->trackingUrl = $result->trackingUrl ?? $delivery->trackingUrl;
		$delivery->transitDays = $result->transitDays ?? $delivery->transitDays;

		/** @var list<array{type: string, number: string, url?: string, sequenceNumber?: ?int}> $references */
		$references = collect($result->references)
			->merge($delivery->references)
			->unique(static fn (array $reference): string => $reference['type'] . ':' . $reference['number'])
			->values()
			->all();
		$delivery->references = $references;

		$delivery->metadata = collect($delivery->metadata)->replace($result->metadata)->all();
		if ($result->cost instanceof Money && ! $result->cost->isNegative()) {
			$delivery->costAmount = $result->cost->getAmount();
			$delivery->costCurrency = $result->cost->getCurrency()->getCode();
		}

		$delivery->status = 'pending';
		$delivery->lastError = null;
		if ($result->pending && is_string($result->metadata['documentError'] ?? null)) {
			$delivery->lastError = mb_substr($result->metadata['documentError'], 0, 1000);
		} elseif ($result->pending && ! empty($result->metadata['executionErrors'])) {
			$delivery->lastError = Craft::t('shipments', 'delivery.errors.carrierAcceptedAReferenceButHasNotConfirmed');
		}
	}

	private function storeResultDocuments(Delivery $delivery, DeliveryResult $result): void
	{
		if ($result->documents !== []) {
			/** @var list<array{assetId: int, filename: string, mimeType: string, trackingNumber: ?string, sequenceNumber?: ?int, type?: string}> $documents */
			$documents = collect($result->documents)
				->map(fn (DeliveryDocument $document): array => $this->storeDocument($delivery, $document))
				->values()
				->all();
			$delivery->documents = $documents;
		}
	}

	private function completeDelivery(Shipment $shipment, Delivery $delivery): void
	{
		$firstSuccess = $delivery->status === 'created' && ! $delivery->dateNotified instanceof DateTime;
		if ($firstSuccess) {
			$delivery->dateNotified = new DateTime();
		}

		$transaction = Craft::$app->getDb()->beginTransaction();
		try {
			$this->saveOrFail($delivery);
			if ($delivery->status === 'created') {
				$this->updateShipmentFromDelivery($shipment, $delivery, $firstSuccess);
			}

			$transaction->commit();
		} catch (Throwable $throwable) {
			$transaction->rollBack();
			if ($firstSuccess) {
				$delivery->dateNotified = null;
			}

			throw $throwable;
		}

		if ($firstSuccess) {
			$this->publishSafely($delivery);
		}
	}

	private function updateShipmentFromDelivery(Shipment $shipment, Delivery $delivery, bool $firstSuccess): void
	{
		$record = ShipmentRecord::findOne($shipment->id) ?? throw new RuntimeException(Craft::t('shipments', 'error.shipmentNotFound'));
		$record->setAttributes([
			'trackingNumber' => $delivery->trackingNumber,
			'trackingUrl' => $delivery->trackingUrl,
			'transitDays' => $delivery->transitDays,
			'carrier' => $delivery->carrier,
			'service' => $delivery->service,
		], false);
		if (! $record->save(false)) {
			throw new RuntimeException(Craft::t('shipments', 'delivery.errors.unableToSaveShipmentTracking'));
		}

		if ($firstSuccess) {
			Plugin::getInstance()->shipments->applyTransition(
				$shipment,
				Status::Shipped,
				Craft::$app->getUser()->getIdentity(),
				queueAutoPushes: false,
			);
		}
	}

	/**
	 * @return array{assetId: int, filename: string, mimeType: string, trackingNumber: ?string, sequenceNumber?: ?int, type?: string}
	 */
	private function storeDocument(Delivery $delivery, DeliveryDocument $document): array
	{
		if ($document->mimeType !== 'application/pdf' || ! str_starts_with($document->contents, '%PDF-') || strlen($document->contents) > 25 * 1024 * 1024) {
			throw new RuntimeException(Craft::t('shipments', 'delivery.errors.carrierDocumentIsNotAValidPDFOrExceeds'));
		}

		$folder = $this->documentFolder();
		$filename = $delivery->operationId . '-' . hash('sha256', $document->contents) . '.pdf';

		$asset = Asset::find()->folderId($folder->id)->filename($filename)->status(null)->one();
		if (! $asset instanceof Asset || ! $asset->getVolume()->fileExists($asset->getPath())) {
			$isNew = ! $asset instanceof Asset;
			if (! $asset instanceof Asset) {
				$asset = new Asset([
					'volumeId' => $folder->volumeId,
					'newFolderId' => $folder->id,
					'filename' => $filename,
					'title' => pathinfo($document->filename, PATHINFO_FILENAME),
				]);
			}

			$temporary = AssetHelper::tempFilePath($filename);
			try {
				if (file_put_contents($temporary, $document->contents) !== strlen($document->contents)) {
					throw new RuntimeException(Craft::t('shipments', 'delivery.errors.unableToStoreTheCarrierPDF'));
				}

				$asset->tempFilePath = $temporary;
				$asset->setScenario($isNew ? Asset::SCENARIO_CREATE : Asset::SCENARIO_REPLACE);
				if (! Craft::$app->getElements()->saveElement($asset)) {
					throw new RuntimeException(Craft::t('shipments', 'delivery.errors.unableToStoreCarrierAsset', [
						'errors' => implode(' ', $asset->getFirstErrors()),
					]));
				}
			} finally {
				FileHelper::unlink($temporary);
			}
		}

		return [
			'assetId' => (int) $asset->id,
			'filename' => basename($document->filename === '' || $document->filename === '0' ? 'delivery.pdf' : $document->filename),
			'mimeType' => 'application/pdf',
			'trackingNumber' => $document->trackingNumber,
			'sequenceNumber' => $document->sequenceNumber,
			'type' => $document->type->value,
		];
	}

	private function saveOrFail(Delivery $delivery): void
	{
		if (! $this->save($delivery)) {
			throw new RuntimeException(Craft::t('shipments', 'delivery.errors.unableToPersistTheDeliveryOperation', [
				'errors' => implode(' ', $delivery->getFirstErrors()),
			]));
		}
	}

	private function publishSafely(Delivery $delivery): void
	{
		try {
			$this->trigger($delivery->status === 'voided' ? self::EVENT_AFTER_CANCEL : self::EVENT_AFTER_CREATE, new DeliveryEvent([
				'shipment' => $this->shipment($delivery->shipmentId),
				'delivery' => $delivery,
			]));
		} catch (Throwable $throwable) {
			DeliveryDiagnostics::log($throwable, 'publish', $delivery->operationId);
		}
	}

	/**
	 * @param callable(): Delivery $callback
	 */
	private function locked(int $shipmentId, callable $callback): Delivery
	{
		$mutex = Craft::$app->getMutex();
		$key = 'shipments:delivery:' . $shipmentId;
		if (! $mutex->acquire($key, 0)) {
			throw new RuntimeException(Craft::t('shipments', 'delivery.errors.anotherDeliveryActionIsAlreadyRunningForThisShipment'));
		}

		try {
			return $callback();
		} finally {
			$mutex->release($key);
		}
	}
}
