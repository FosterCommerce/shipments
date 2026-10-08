<?php

declare(strict_types=1);

namespace fostercommerce\shipments\elements;

use Craft;
use craft\base\Element;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\elements\db\ElementQueryInterface;
use craft\elements\User;
use craft\helpers\Cp;
use craft\helpers\DateTimeHelper;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use DateTime;
use fostercommerce\shipments\db\Table;
use fostercommerce\shipments\elements\db\ShipmentQuery;
use fostercommerce\shipments\elements\exporters\Fulfillment as FulfillmentExporter;
use fostercommerce\shipments\enums\Status;
use fostercommerce\shipments\errors\AllocationOverflowException;
use fostercommerce\shipments\errors\DuplicateShipmentReferenceException;
use fostercommerce\shipments\helpers\MoneyValues;
use fostercommerce\shipments\models\Delivery;
use fostercommerce\shipments\models\IntegrationReference;
use fostercommerce\shipments\models\ShipmentLineItem;
use fostercommerce\shipments\Plugin;
use fostercommerce\shipments\records\Shipment as ShipmentRecord;
use fostercommerce\shipments\validators\MoneyAmount;
use Money\Money;
use RuntimeException;
use Throwable;
use yii\base\Exception as YiiBaseException;
use yii\db\IntegrityException;

/**
 * Shipment element: a grouped allocation of order line-item quantities plus fulfillment fields
 * and a single `status` axis.
 *
 * Transition timestamps are not stored as columns; `shipments_status_history` is their single
 * source of truth, derived on demand via `getDateShipped()`.
 */
class Shipment extends Element
{
	public ?int $orderId = null;

	public string $status = Status::New->value;

	public ?DateTime $dateScheduledShip = null;

	public ?string $reference = null;

	public ?int $number = null;

	public ?string $trackingNumber = null;

	public ?string $trackingUrl = null;

	public ?int $transitDays = null;

	public ?string $carrier = null;

	public ?string $service = null;

	public ?string $shippingMethodHandle = null;

	public ?string $shippingMethodName = null;

	public ?string $shippingAmountMinor = null;

	public ?string $shippingCurrency = null;

	/**
	 * @var array<string, mixed>
	 */
	public array $shippingSnapshot = [];

	public ?string $fulfillmentNotes = null;

	public ?string $shippingNotes = null;

	public ?DateTime $dateLastPushAttempt = null;

	public ?string $lastPushAttemptError = null;

	public int $pushAttemptCount = 0;

	private bool $_deliveryWriteLock = false;

	/**
	 * @var list<ShipmentLineItem>|null
	 */
	private ?array $_lineItems = null;

	/**
	 * @var list<IntegrationReference>|null
	 */
	private ?array $_integrationReferences = null;

	private ?Order $_order = null;

	private ?DateTime $_dateShipped = null;

	private bool $_dateShippedLoaded = false;

	/**
	 * @var array<int, bool>
	 */
	private static array $_orderAllocationCache = [];

	public function __toString(): string
	{
		return $this->getUiLabel();
	}

	public function setShippingSnapshotData(mixed $value): void
	{
		$value = is_string($value) ? Json::decode($value) : $value;
		$this->shippingSnapshot = is_array($value) ? $value : [];
	}

	public function getShippingAmount(): ?Money
	{
		return $this->shippingAmountMinor !== null && $this->shippingCurrency !== null
			? MoneyValues::restore([
				'amount' => $this->shippingAmountMinor,
				'currency' => $this->shippingCurrency,
			]) : null;
	}

	public function getCarrierCost(): ?Money
	{
		return $this->getDelivery()?->getCost();
	}

	public function getDelivery(): ?Delivery
	{
		$plugin = Plugin::getInstance();
		return $this->id === null ? null : $plugin->deliveries->getLatestForShipment($this->id);
	}

	/**
	 * @return list<Delivery>
	 */
	public function getDeliveries(): array
	{
		$plugin = Plugin::getInstance();
		return $this->id === null ? [] : $plugin->deliveries->getForShipment($this->id);
	}

	public static function displayName(): string
	{
		return Craft::t(Plugin::HANDLE, 'nav.shipment');
	}

	public static function lowerDisplayName(): string
	{
		return Craft::t(Plugin::HANDLE, 'nav.shipmentLowercase');
	}

	public static function pluralDisplayName(): string
	{
		return Craft::t(Plugin::HANDLE, 'nav.shipments');
	}

	public static function pluralLowerDisplayName(): string
	{
		return Craft::t(Plugin::HANDLE, 'nav.shipmentsLowercase');
	}

	public static function refHandle(): ?string
	{
		return 'shipment';
	}

	public static function hasContent(): bool
	{
		return true;
	}

	public static function hasTitles(): bool
	{
		return false;
	}

	public static function hasUris(): bool
	{
		return false;
	}

	public static function isLocalized(): bool
	{
		return false;
	}

	public static function trackChanges(): bool
	{
		return true;
	}

	public static function find(): ElementQueryInterface
	{
		return Craft::createObject(ShipmentQuery::class, [static::class]);
	}

	/**
	 * @return array<string, array{label: string, color: string}>
	 */
	public static function statuses(): array
	{
		$map = [];
		foreach (Status::cases() as $case) {
			$map[$case->value] = [
				'label' => $case->label(),
				'color' => $case->color(),
			];
		}

		return $map;
	}

	public function getUiLabel(): string
	{
		return $this->reference ?? '';
	}

	public function getStatus(): ?string
	{
		return $this->status;
	}

	public function getStatusEnum(): Status
	{
		return Status::from($this->status);
	}

	/**
	 * First time the shipment reached `shipped`, derived from `shipments_status_history`.
	 * Null when no such transition has ever happened.
	 */
	public function getDateShipped(): ?DateTime
	{
		if (! $this->_dateShippedLoaded) {
			$this->_dateShipped = $this->loadHistoryDate(Status::Shipped->value);
			$this->_dateShippedLoaded = true;
		}

		return $this->_dateShipped;
	}

	public function getCpEditUrl(): ?string
	{
		return $this->id !== null ? UrlHelper::cpUrl('shipments/shipments/' . $this->id) : null;
	}

	public function canView(User $user): bool
	{
		return $user->can(Plugin::PERMISSION_VIEW);
	}

	public function canSave(User $user): bool
	{
		return $user->can(Plugin::PERMISSION_EDIT);
	}

	public function canDelete(User $user): bool
	{
		return $user->can(Plugin::PERMISSION_DELETE);
	}

	public function getFieldLayout(): ?FieldLayout
	{
		$plugin = Plugin::getInstance();
		return $plugin->shipmentFieldLayouts->getFieldLayout();
	}

	public static function gqlTypeNameByContext(mixed $context): string
	{
		return 'Shipment';
	}

	/**
	 * @return list<string>
	 */
	public static function gqlScopesByContext(mixed $context): array
	{
		return ['shipments.read'];
	}

	public function getGqlTypeName(): string
	{
		return self::gqlTypeNameByContext(null);
	}

	public function getOrder(): ?Order
	{
		if ($this->_order instanceof Order) {
			return $this->_order;
		}

		if ($this->orderId === null) {
			return null;
		}

		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();
		$order = $commerce->getOrders()->getOrderById($this->orderId);
		if ($order instanceof Order) {
			$this->_order = $order;
		}

		return $order;
	}

	/**
	 * @return list<ShipmentLineItem>
	 */
	public function getLineItems(): array
	{
		if ($this->_lineItems === null) {
			if ($this->id === null) {
				return $this->_lineItems = [];
			}

			$plugin = Plugin::getInstance();
			$this->_lineItems = $plugin->shipmentLineItems->findForShipmentId($this->id);
		}

		return $this->_lineItems;
	}

	/**
	 * @param list<ShipmentLineItem> $lineItems
	 */
	public function setLineItems(array $lineItems): void
	{
		$this->_lineItems = $lineItems;
	}

	/**
	 * @return list<IntegrationReference>
	 */
	public function getIntegrationReferences(): array
	{
		if ($this->_integrationReferences === null) {
			if ($this->id === null) {
				return $this->_integrationReferences = [];
			}

			$plugin = Plugin::getInstance();
			$this->_integrationReferences = $plugin->integrationReferences->getReferencesForShipmentId($this->id);
		}

		return $this->_integrationReferences;
	}

	/**
	 * @param list<IntegrationReference> $references
	 */
	public function setIntegrationReferences(array $references): void
	{
		$this->_integrationReferences = $references;
	}

	/**
	 * @return list<array{siteId: int, enabledByDefault: bool}>
	 */
	public function getSupportedSites(): array
	{
		$siteId = $this->getOrder()?->siteId ?? Craft::$app->getSites()->getPrimarySite()->id;
		return [
			[
				'siteId' => (int) $siteId,
				'enabledByDefault' => true,
			],
		];
	}

	public function beforeRestore(): bool
	{
		if ($this->id !== null) {
			$this->assertAllocationWouldNotOverflow();
		}

		return parent::beforeRestore();
	}

	public function beforeSave(bool $isNew): bool
	{
		try {
			if (! $isNew && $this->id !== null) {
				$this->acquireDeliveryWriteLock();
				$this->assertDeliveryDetailsUnchanged();
			}

			if ($isNew && ($this->reference === null || $this->reference === '')) {
				$order = $this->getOrder();
				if ($order instanceof Order) {
					$plugin = Plugin::getInstance();
					$this->reference = $plugin->shipmentReferences->allocate($order);
					$separator = strrpos($this->reference, '-s');
					if ($separator !== false) {
						$this->number = (int) substr($this->reference, $separator + 2);
					}
				}
			}

			$save = parent::beforeSave($isNew);
			if (! $save) {
				$this->releaseDeliveryWriteLock();
			}

			return $save;
		} catch (Throwable $throwable) {
			$this->releaseDeliveryWriteLock();
			throw $throwable;
		}
	}

	public function beforeDelete(): bool
	{
		try {
			if ($this->id !== null) {
				$this->acquireDeliveryWriteLock();
				$plugin = Plugin::getInstance();
				$plugin->deliveries->assertShipmentEditable((int) $this->id);
			}

			$delete = parent::beforeDelete();
			if (! $delete) {
				$this->releaseDeliveryWriteLock();
			}

			return $delete;
		} catch (Throwable $throwable) {
			$this->releaseDeliveryWriteLock();
			throw $throwable;
		}
	}

	/**
	 * @param list<Shipment> $sourceElements
	 * @return array<string, mixed>|false|null
	 */
	public static function eagerLoadingMap(array $sourceElements, string $handle): array|false|null
	{
		if ($sourceElements === []) {
			return parent::eagerLoadingMap($sourceElements, $handle);
		}

		$sourceIds = array_values(array_filter(array_map(static fn (Shipment $shipment): ?int => $shipment->id, $sourceElements)));

		if ($handle === 'order') {
			$map = (new Query())
				->select([
					'source' => 'id',
					'target' => 'orderId',
				])
				->from(Table::SHIPMENTS)
				->where([
					'id' => $sourceIds,
				])
				->all();

			return [
				'elementType' => Order::class,
				'map' => array_values($map),
			];
		}

		if ($handle === 'lineItems') {
			$plugin = Plugin::getInstance();
			$rowsByShipmentId = $plugin->shipmentLineItems->findForShipmentIds($sourceIds);
			foreach ($sourceElements as $sourceElement) {
				if ($sourceElement->id === null) {
					continue;
				}

				$sourceElement->setLineItems($rowsByShipmentId[$sourceElement->id] ?? []);
			}

			// Already populated via setLineItems above. Null tells the eager loader
			// there is no element-type map (ShipmentLineItem is not an element).
			return null;
		}

		if ($handle === 'integrationReferences') {
			$plugin = Plugin::getInstance();
			$rowsByShipmentId = $plugin->integrationReferences->getReferencesForShipmentIds($sourceIds);
			foreach ($sourceElements as $sourceElement) {
				if ($sourceElement->id === null) {
					continue;
				}

				$sourceElement->setIntegrationReferences($rowsByShipmentId[$sourceElement->id] ?? []);
			}

			return null;
		}

		return parent::eagerLoadingMap($sourceElements, $handle);
	}

	public function afterSave(bool $isNew): void
	{
		try {
			if (! $this->propagating) {
				if ($isNew) {
					$this->dateScheduledShip ??= $this->dateCreated;
					$record = new ShipmentRecord();
					$record->id = (int) $this->id;
				} else {
					$record = ShipmentRecord::findOne($this->id);
					if (! $record instanceof ShipmentRecord) {
						throw new YiiBaseException('Invalid shipment ID: ' . $this->id);
					}
				}

				$record->orderId = (int) $this->orderId;
				$record->reference = (string) $this->reference;
				$record->number = (int) $this->number;
				$record->status = $this->status;
				$record->dateScheduledShip = $this->dateScheduledShip;
				$record->trackingNumber = $this->trackingNumber;
				$record->trackingUrl = $this->trackingUrl;
				$record->transitDays = $this->transitDays;
				$record->carrier = $this->carrier;
				$record->service = $this->service;
				$record->fulfillmentNotes = $this->fulfillmentNotes;
				$record->shippingNotes = $this->shippingNotes;
				$record->dateLastPushAttempt = $this->dateLastPushAttempt;
				$record->lastPushAttemptError = $this->lastPushAttemptError;
				$record->pushAttemptCount = $this->pushAttemptCount;
				$record->setAttribute('shippingMethodHandle', $this->shippingMethodHandle);
				$record->setAttribute('shippingMethodName', $this->shippingMethodName);
				$record->setAttribute('shippingAmount', $this->shippingAmountMinor);
				$record->setAttribute('shippingCurrency', $this->shippingCurrency);
				$record->setAttribute('shippingSnapshot', $this->shippingSnapshot);

				try {
					$record->save(false);
				} catch (IntegrityException $integrityException) {
					throw new DuplicateShipmentReferenceException((string) $this->reference, previous: $integrityException);
				}
			}

			$this->recomputeTrackedOrderAllocation();

			parent::afterSave($isNew);
		} finally {
			$this->releaseDeliveryWriteLock();
		}
	}

	public function afterDelete(): void
	{
		try {
			$this->recomputeTrackedOrderAllocation();
			parent::afterDelete();
		} finally {
			$this->releaseDeliveryWriteLock();
		}
	}

	public function afterRestore(): void
	{
		$this->recomputeTrackedOrderAllocation();
		parent::afterRestore();
	}

	/**
	 * Emit enum labels + allocation state for CSV export.
	 *
	 * @param array<int, string> $fields
	 * @param array<int, string> $expand
	 * @return array<string, mixed>
	 */
	public function toArray(array $fields = [], array $expand = [], $recursive = true): array
	{
		$serialized = parent::toArray($fields, $expand, $recursive);
		// Internal quote snapshots and merchant money are never part of generic element exports.
		unset($serialized['shippingSnapshot'], $serialized['shippingAmountMinor'], $serialized['shippingCurrency']);

		if ($fields === [] || in_array('status', $fields, true)) {
			$serialized['status'] = $this->getStatusEnum()->label();
		}

		if (in_array('orderAllocation', $fields, true) || $fields === []) {
			$serialized['orderAllocation'] = $this->isOrderUnderAllocatedCached()
				? Craft::t(Plugin::HANDLE, 'orderTab.underAllocated')
				: Craft::t(Plugin::HANDLE, 'orderTab.fullyAllocated');
		}

		return $serialized;
	}

	/**
	 * @return array<string, string>
	 */
	public function attributeLabels(): array
	{
		return [
			...parent::attributeLabels(),
			'transitDays' => Craft::t('shipments', 'shipmentEdit.transitDays'),
			'shippingMethodHandle' => Craft::t('shipments', 'delivery.fields.shippingMethodHandle'),
			'shippingMethodName' => Craft::t('shipments', 'delivery.fields.shippingMethodName'),
			'shippingAmountMinor' => Craft::t('shipments', 'delivery.calculatedShippingAmount'),
			'shippingCurrency' => Craft::t('shipments', 'delivery.fields.shippingCurrency'),
		];
	}

	/**
	 * @return array<array-key, mixed>
	 */
	protected function defineRules(): array
	{
		return array_merge(parent::defineRules(), [
			[['orderId', 'status'], 'required'],
			[['status'],
				'in',
				'range' => array_map(static fn (Status $case): string => $case->value, Status::cases())],
			[['reference'],
				'string',
				'max' => 255],
			[['trackingNumber', 'trackingUrl', 'carrier', 'service'],
				'string',
				'max' => 255],
			[['trackingUrl'],
				'url',
				'defaultScheme' => 'https'],
			[['fulfillmentNotes', 'shippingNotes', 'lastPushAttemptError'], 'string'],
			[['number', 'pushAttemptCount'], 'integer'],
			[['transitDays'],
				'integer',
				'min' => 0],
			[['dateScheduledShip', 'dateLastPushAttempt'], 'safe'],
			[['shippingMethodHandle', 'shippingMethodName'],
				'string',
				'max' => 255],
			[['shippingAmountMinor'],
				'string',
				'max' => 64],
			[['shippingAmountMinor'],
				MoneyAmount::class,
				'currencyAttribute' => 'shippingCurrency'],
			[['shippingSnapshot'], 'safe'],
		]);
	}

	/**
	 * @return array<array-key, mixed>
	 */
	protected static function defineSources(?string $context = null): array
	{
		$sources = [
			[
				'key' => '*',
				'label' => Craft::t(Plugin::HANDLE, 'nav.allShipments'),
				'defaultSort' => ['dateCreated', 'desc'],
			],
			[
				'heading' => Craft::t(Plugin::HANDLE, 'shipmentEdit.statusLabel'),
			],
		];

		foreach (Status::cases() as $case) {
			$sources[] = [
				'key' => 'status:' . $case->value,
				'label' => $case->label(),
				'criteria' => [
					'status' => $case->value,
				],
				'defaultSort' => ['dateCreated', 'desc'],
			];
		}

		return $sources;
	}

	/**
	 * @return array<string, array{label: string}>
	 */
	protected static function defineTableAttributes(): array
	{
		$attributes = [
			'order' => [
				'label' => Craft::t(Plugin::HANDLE, 'shipmentEdit.order'),
			],
			'status' => [
				'label' => Craft::t(Plugin::HANDLE, 'shipmentEdit.statusLabel'),
			],
			'carrier' => [
				'label' => Craft::t(Plugin::HANDLE, 'shipmentEdit.tracking.carrier'),
			],
			'service' => [
				'label' => Craft::t(Plugin::HANDLE, 'shipmentEdit.tracking.service'),
			],
			'trackingNumber' => [
				'label' => Craft::t(Plugin::HANDLE, 'shipmentEdit.tracking.heading'),
			],
			'orderAllocation' => [
				'label' => Craft::t(Plugin::HANDLE, 'orderTab.allocationHeading'),
			],
			'dateUpdated' => [
				'label' => Craft::t(Plugin::HANDLE, 'index.column.dateUpdated'),
			],
			'dateCreated' => [
				'label' => Craft::t(Plugin::HANDLE, 'index.column.dateCreated'),
			],
		];

		$plugin = Plugin::getInstance();
		foreach ($plugin->shipmentFieldLayouts->getFieldLayout()->getCustomFields() as $field) {
			$attributes['field:' . $field->uid] = [
				'label' => Craft::t('site', $field->name ?? $field->handle ?? ''),
			];
		}

		return $attributes;
	}

	/**
	 * @return array<int, string>
	 */
	protected static function defineExporters(string $source): array
	{
		return array_merge(parent::defineExporters($source), [
			FulfillmentExporter::class,
		]);
	}

	/**
	 * @return list<string>
	 */
	protected static function defineDefaultTableAttributes(string $source): array
	{
		return [
			'order',
			'status',
			'carrier',
			'trackingNumber',
			'orderAllocation',
			'dateUpdated',
		];
	}

	/**
	 * @return array<int|string, array<string, mixed>|string>
	 */
	protected static function defineSortOptions(): array
	{
		return [
			[
				'label' => Craft::t(Plugin::HANDLE, 'index.column.reference'),
				'orderBy' => '[[shipments_shipments.reference]]',
				'attribute' => 'reference',
			],
			[
				'label' => Craft::t(Plugin::HANDLE, 'shipmentEdit.statusLabel'),
				'orderBy' => '[[shipments_shipments.status]]',
				'attribute' => 'status',
			],
			[
				'label' => Craft::t(Plugin::HANDLE, 'shipmentEdit.tracking.carrier'),
				'orderBy' => '[[shipments_shipments.carrier]]',
				'attribute' => 'carrier',
			],
			[
				'label' => Craft::t(Plugin::HANDLE, 'orderTab.allocationHeading'),
				'orderBy' => '[[tracked.underAllocated]]',
				'attribute' => 'orderAllocation',
			],
			[
				'label' => Craft::t(Plugin::HANDLE, 'index.column.dateUpdated'),
				'orderBy' => '[[elements.dateUpdated]]',
				'attribute' => 'dateUpdated',
			],
			[
				'label' => Craft::t(Plugin::HANDLE, 'index.column.dateCreated'),
				'orderBy' => '[[elements.dateCreated]]',
				'attribute' => 'dateCreated',
			],
		];
	}

	/**
	 * @return list<string>
	 */
	protected static function defineSearchableAttributes(): array
	{
		return ['reference', 'trackingNumber', 'carrier', 'service', 'fulfillmentNotes', 'shippingNotes'];
	}

	protected function attributeHtml(string $attribute): string
	{
		return match ($attribute) {
			'order' => $this->orderAttributeHtml(),
			'status' => $this->statusAttributeHtml(),
			'trackingNumber' => $this->trackingAttributeHtml(),
			'orderAllocation' => $this->orderAllocationAttributeHtml(),
			default => parent::attributeHtml($attribute),
		};
	}

	private function assertDeliveryDetailsUnchanged(): void
	{
		if (! $this->getDelivery() instanceof Delivery) {
			return;
		}

		if ($this->status === Status::Cancelled->value) {
			throw new RuntimeException(Craft::t('shipments', 'delivery.errors.voidBeforeCancellingShipment'));
		}

		$saved = (new Query())->from(Table::SHIPMENTS)->where([
			'[[id]]' => $this->id,
		])->one();
		if (! is_array($saved)) {
			throw new RuntimeException(Craft::t('shipments', 'delivery.errors.bookedShipmentCouldNotBeLoaded'));
		}

		$savedShipDate = DateTimeHelper::toDateTime($saved['dateScheduledShip']);
		$savedShipTimestamp = $savedShipDate instanceof DateTime ? $savedShipDate->getTimestamp() : null;

		if ($this->dateScheduledShip?->getTimestamp() !== $savedShipTimestamp) {
			throw new RuntimeException(Craft::t('shipments', 'shipmentEdit.shipDateActiveDelivery'));
		}

		$attributes = [
			'orderId' => 'orderId',
			'trackingNumber' => 'trackingNumber',
			'trackingUrl' => 'trackingUrl',
			'carrier' => 'carrier',
			'service' => 'service',
			'shippingMethodHandle' => 'shippingMethodHandle',
			'shippingMethodName' => 'shippingMethodName',
			'shippingAmountMinor' => 'shippingAmount',
			'shippingCurrency' => 'shippingCurrency',
		];
		foreach ($attributes as $attribute => $column) {
			if ($this->{$attribute} !== $saved[$column]) {
				throw new RuntimeException(Craft::t('shipments', 'delivery.errors.carrierDeliveryDetailsAreLocked'));
			}
		}

		$snapshot = is_string($saved['shippingSnapshot']) ? Json::decode($saved['shippingSnapshot']) : $saved['shippingSnapshot'];
		$enabled = (new Query())->select('[[enabled]]')->from(CraftTable::ELEMENTS)->where([
			'[[id]]' => $this->id,
		])->scalar();
		if ($this->shippingSnapshot !== $snapshot || $this->enabled !== (bool) $enabled) {
			throw new RuntimeException(Craft::t('shipments', 'delivery.errors.bookedShipmentCannotChangeShippingDetailsOrBe'));
		}
	}

	private function acquireDeliveryWriteLock(): void
	{
		$mutex = Craft::$app->getMutex();
		$key = 'shipments:delivery:' . $this->id;
		if ($mutex->isAcquired($key)) {
			return;
		}

		if (! $mutex->acquire($key, 0)) {
			throw new RuntimeException(Craft::t('shipments', 'delivery.errors.carrierDeliveryActionIsInProgress'));
		}

		$this->_deliveryWriteLock = true;
	}

	private function releaseDeliveryWriteLock(): void
	{
		if ($this->_deliveryWriteLock) {
			Craft::$app->getMutex()->release('shipments:delivery:' . $this->id);
			$this->_deliveryWriteLock = false;
		}
	}

	/**
	 * @throws AllocationOverflowException
	 */
	private function assertAllocationWouldNotOverflow(): void
	{
		if ($this->id === null) {
			return;
		}

		$order = $this->getOrder();
		if (! $order instanceof Order || $order->id === null) {
			return;
		}

		$plugin = Plugin::getInstance();
		$overflow = $plugin->shipmentLineItems->overflowIfCounted($this->id, $order);
		if ($overflow !== []) {
			throw new AllocationOverflowException($this->id, $order->id, $overflow);
		}
	}

	private function recomputeTrackedOrderAllocation(): void
	{
		$order = $this->getOrder();
		if (! $order instanceof Order) {
			return;
		}

		unset(self::$_orderAllocationCache[(int) $order->id]);

		$plugin = Plugin::getInstance();
		$plugin->getTrackedOrders()->recomputeUnderAllocation($order);
	}

	private function orderAttributeHtml(): string
	{
		$order = $this->getOrder();
		if (! $order instanceof Order) {
			return '';
		}

		$label = $order->reference !== null && $order->reference !== ''
			? $order->reference
			: '#' . ($order->number ?? (string) $order->id);

		return Html::a(
			Html::encode($label),
			UrlHelper::cpUrl('commerce/orders/' . $order->id),
		);
	}

	private function statusAttributeHtml(): string
	{
		$case = $this->getStatusEnum();
		return Cp::statusLabelHtml([
			'label' => $case->label(),
			'color' => $case->color(),
		]) ?? '';
	}

	private function orderAllocationAttributeHtml(): string
	{
		if ($this->orderId === null) {
			return '';
		}

		if ($this->isOrderUnderAllocatedCached()) {
			return Cp::statusLabelHtml([
				'label' => Craft::t(Plugin::HANDLE, 'orderTab.underAllocated'),
				'color' => 'red',
			]) ?? '';
		}

		return Cp::statusLabelHtml([
			'label' => Craft::t(Plugin::HANDLE, 'orderTab.fullyAllocated'),
			'color' => 'green',
		]) ?? '';
	}

	private function isOrderUnderAllocatedCached(): bool
	{
		if ($this->orderId === null) {
			return false;
		}

		// static cache: index rows sharing an order don't each recompute the pool
		if (! array_key_exists($this->orderId, self::$_orderAllocationCache)) {
			$order = $this->getOrder();
			if (! $order instanceof Order) {
				self::$_orderAllocationCache[$this->orderId] = false;
			} else {
				$plugin = Plugin::getInstance();
				self::$_orderAllocationCache[$this->orderId] = $plugin->shipmentLineItems->isOrderUnderAllocated($order);
			}
		}

		return self::$_orderAllocationCache[$this->orderId];
	}

	private function trackingAttributeHtml(): string
	{
		$trackingNumber = $this->trackingNumber ?? '';
		if ($trackingNumber === '') {
			return '';
		}

		if ($this->trackingUrl !== null && $this->trackingUrl !== '') {
			return Html::a(
				Html::tag('code', Html::encode($trackingNumber)),
				$this->trackingUrl,
				[
					'target' => '_blank',
					'rel' => 'noreferrer',
				],
			);
		}

		return Html::tag('code', Html::encode($trackingNumber));
	}

	private function loadHistoryDate(string $toCode): ?DateTime
	{
		if ($this->id === null) {
			return null;
		}

		$value = (new Query())
			->select(['[[history.dateCreated]]'])
			->from([
				'history' => Table::SHIPMENT_STATUS_HISTORY,
			])
			->where([
				'[[history.shipmentId]]' => $this->id,
				'[[history.toCode]]' => $toCode,
			])
			->orderBy([
				'[[history.dateCreated]]' => SORT_ASC,
				'[[history.id]]' => SORT_ASC,
			])
			->limit(1)
			->scalar();

		if (! is_string($value) || $value === '') {
			return null;
		}

		return DateTimeHelper::toDateTime($value) ?: null;
	}
}
