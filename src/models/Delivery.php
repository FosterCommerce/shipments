<?php

declare(strict_types=1);

namespace fostercommerce\shipments\models;

use Craft;
use craft\base\Model;
use craft\elements\Asset;
use craft\helpers\Cp;
use craft\helpers\Json;
use craft\helpers\MoneyHelper;
use DateTime;
use fostercommerce\shipments\enums\DeliveryDocumentType;
use fostercommerce\shipments\helpers\DeliveryResponse;
use fostercommerce\shipments\helpers\MoneyValues;
use fostercommerce\shipments\Plugin;
use fostercommerce\shipments\validators\MoneyAmount;
use Money\Money;

class Delivery extends Model
{
	public ?int $id = null;

	public int $shipmentId = 0;

	public int $integrationId = 0;

	public string $operationId = '';

	public string $status = 'pending';

	public ?string $externalId = null;

	public string $carrier = '';

	public string $service = '';

	public ?string $trackingNumber = null;

	public ?string $trackingUrl = null;

	public ?int $transitDays = null;

	public ?string $costAmount = null;

	public ?string $costCurrency = null;

	/**
	 * @var array<string, mixed>
	 */
	public array $metadata = [];

	/**
	 * @var array<string, mixed>
	 */
	public array $requestData = [];

	/**
	 * @var list<array{type: string, number: string, url?: string, sequenceNumber?: ?int}>
	 */
	public array $references = [];

	/**
	 * @var list<array{assetId: int, filename: string, mimeType: string, trackingNumber: ?string, sequenceNumber?: ?int, type?: string}>
	 */
	public array $documents = [];

	public ?string $lastError = null;

	public ?int $createdBy = null;

	public ?DateTime $dateCreated = null;

	public ?DateTime $dateUpdated = null;

	public ?DateTime $dateNotified = null;

	public ?string $uid = null;

	/**
	 * @return Asset[]
	 */
	public function getDocumentAssets(): array
	{
		$ids = collect($this->documents)->pluck('assetId')->filter()->values()->all();
		return $ids === [] ? [] : Asset::find()->id($ids)->fixedOrder()->status(null)->all();
	}

	/**
	 * @return list<array{trackingNumber: ?string, sequenceNumber: ?int, documents: list<array{asset: Asset, type: ?DeliveryDocumentType}>}>
	 */
	public function getDocumentGroups(): array
	{
		$assets = collect($this->getDocumentAssets())->keyBy('id');
		$groups = [];
		foreach ($this->references as $reference) {
			if ($reference['type'] === 'package') {
				$groups[$reference['number']] = [
					'trackingNumber' => $reference['number'],
					'sequenceNumber' => $reference['sequenceNumber'] ?? null,
					'documents' => [],
				];
			}
		}

		foreach ($this->documents as $document) {
			$key = $document['trackingNumber'] ?? '';
			$groups[$key] ??= [
				'trackingNumber' => $document['trackingNumber'],
				'sequenceNumber' => $document['sequenceNumber'] ?? null,
				'documents' => [],
			];
			$asset = $assets->get($document['assetId']);
			if ($asset instanceof Asset) {
				$groups[$key]['documents'][] = [
					'asset' => $asset,
					'type' => DeliveryDocumentType::tryFrom($document['type'] ?? ''),
				];
			}
		}

		return array_values(collect($groups)->sortBy(static fn (array $group): int => $group['sequenceNumber'] ?? PHP_INT_MAX)->all());
	}

	public function getResponseJson(): string
	{
		$response = $this->metadata['response'] ?? [];
		return Json::encode(DeliveryResponse::withoutPdfContents(is_array($response) ? $response : []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	}

	/**
	 * Get the recorded carrier cost, or null while confirmation is pending.
	 */
	public function getCost(): ?Money
	{
		return $this->costAmount !== null && $this->costCurrency !== null
			? MoneyValues::restore([
				'amount' => $this->costAmount,
				'currency' => $this->costCurrency,
			])
			: null;
	}

	/**
	 * Format the recorded carrier cost using the control-panel locale.
	 */
	public function getFormattedCost(): ?string
	{
		$cost = $this->getCost();
		return $cost instanceof Money ? (string) MoneyHelper::toString($cost) : null;
	}

	/**
	 * Check whether this booking prevents another carrier purchase.
	 */
	public function getIsActive(): bool
	{
		return ! in_array($this->status, ['failed', 'voided'], true);
	}

	/**
	 * Get the translated booking state for the control panel.
	 */
	public function getStatusHtml(): string
	{
		return Cp::statusLabelHtml([
			'label' => $this->getStatusLabel(),
			'color' => match ($this->status) {
				'created' => 'green',
				'failed',
				'voided' => 'red',
				'processing',
				'voiding' => 'blue',
				'pending',
				'uncertain',
				'void_uncertain' => 'orange',
				default => 'gray',
			},
		]) ?? '';
	}

	public function getStatusLabel(): string
	{
		$key = match ($this->status) {
			'pending' => 'delivery.states.pending',
			'processing' => 'delivery.states.processing',
			'created' => 'delivery.states.created',
			'failed' => 'delivery.states.failed',
			'uncertain' => 'delivery.states.uncertain',
			'voiding' => 'delivery.states.voiding',
			'void_uncertain' => 'delivery.states.voidUncertain',
			'voided' => 'delivery.states.voided',
			default => 'delivery.states.unknown',
		};
		return Craft::t(Plugin::HANDLE, $key);
	}

	/**
	 * @return array<string, string>
	 */
	public function attributeLabels(): array
	{
		return [
			...parent::attributeLabels(),
			'shipmentId' => Craft::t('shipments', 'nav.shipment'),
			'integrationId' => Craft::t('shipments', 'delivery.fields.deliveryProvider'),
			'operationId' => Craft::t('shipments', 'delivery.fields.operationID'),
			'status' => Craft::t('shipments', 'delivery.fields.deliveryState'),
			'externalId' => Craft::t('shipments', 'delivery.carrierReference'),
			'carrier' => Craft::t('shipments', 'shipmentEdit.tracking.carrier'),
			'service' => Craft::t('shipments', 'shipmentEdit.tracking.service'),
			'trackingNumber' => Craft::t('shipments', 'shipmentEdit.tracking.number'),
			'trackingUrl' => Craft::t('shipments', 'shipmentEdit.tracking.url'),
			'transitDays' => Craft::t('shipments', 'shipmentEdit.transitDays'),
			'costAmount' => Craft::t('shipments', 'delivery.recordedCarrierCost'),
			'costCurrency' => Craft::t('shipments', 'delivery.fields.carrierCostCurrency'),
			'createdBy' => Craft::t('shipments', 'delivery.fields.createdBy'),
			'lastError' => Craft::t('shipments', 'delivery.fields.lastError'),
		];
	}

	/**
	 * @return array<array-key, mixed>
	 */
	protected function defineRules(): array
	{
		return [
			...parent::defineRules(),
			[['shipmentId', 'integrationId', 'operationId', 'status'], 'required'],
			[['shipmentId', 'integrationId', 'createdBy'],
				'integer',
				'min' => 1],
			[['transitDays'],
				'integer',
				'min' => 0],
			[['operationId'],
				'match',
				'pattern' => '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD'],
			[['status'],
				'in',
				'range' => ['pending', 'processing', 'created', 'failed', 'uncertain', 'voiding', 'void_uncertain', 'voided']],
			[['externalId', 'carrier', 'service', 'trackingNumber'],
				'string',
				'max' => 255],
			[['trackingUrl'],
				'url',
				'validSchemes' => ['http', 'https']],
			[['costAmount'],
				'string',
				'max' => 64],
			[['costAmount'],
				MoneyAmount::class,
				'currencyAttribute' => 'costCurrency'],
			[['lastError'], 'string'],
		];
	}
}
