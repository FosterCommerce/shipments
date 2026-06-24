<?php

declare(strict_types=1);

namespace fostercommerce\shipments;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\commerce\elements\Order;
use craft\commerce\events\OrderStatusEvent;
use craft\commerce\Plugin as Commerce;
use craft\commerce\services\OrderHistories;
use craft\console\Application as ConsoleApplication;
use craft\db\Query;
use craft\events\DefineEagerLoadingMapEvent;
use craft\events\RebuildConfigEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterGqlQueriesEvent;
use craft\events\RegisterGqlSchemaComponentsEvent;
use craft\events\RegisterGqlTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\events\TemplateEvent;
use craft\helpers\ElementHelper;
use craft\helpers\UrlHelper;
use craft\services\Elements;
use craft\services\Gql;
use craft\services\ProjectConfig;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use craft\web\View;
use fostercommerce\shipments\db\Table;
use fostercommerce\shipments\elements\Shipment;
use fostercommerce\shipments\enums\Status;
use fostercommerce\shipments\events\ShipmentStatusChangedEvent;
use fostercommerce\shipments\gql\interfaces\elements\Shipment as ShipmentGqlInterface;
use fostercommerce\shipments\gql\queries\Shipment as ShipmentGqlQuery;
use fostercommerce\shipments\models\Settings;
use fostercommerce\shipments\queue\jobs\AdvanceOrderStatusJob;
use fostercommerce\shipments\services\Emails;
use fostercommerce\shipments\services\IntegrationReferences;
use fostercommerce\shipments\services\Integrations;
use fostercommerce\shipments\services\IntegrationStatusMaps;
use fostercommerce\shipments\services\Rules;
use fostercommerce\shipments\services\ShipmentFieldLayouts;
use fostercommerce\shipments\services\ShipmentLineItems;
use fostercommerce\shipments\services\ShipmentReferences;
use fostercommerce\shipments\services\Shipments;
use fostercommerce\shipments\services\TrackedOrders;
use fostercommerce\shipments\services\TransitionEmails;
use fostercommerce\shipments\web\assets\cp\ShipmentsCpAsset;
use Throwable;
use yii\base\Event;

/**
 * @property-read Settings $settings
 * @property-read Rules $rules
 * @property-read Shipments $shipments
 * @property-read ShipmentLineItems $shipmentLineItems
 * @property-read ShipmentReferences $shipmentReferences
 * @property-read Integrations $integrations
 * @property-read IntegrationReferences $integrationReferences
 * @property-read IntegrationStatusMaps $integrationStatusMaps
 * @property-read Emails $emails
 * @property-read TransitionEmails $transitionEmails
 * @property-read ShipmentFieldLayouts $shipmentFieldLayouts
 * @property-read TrackedOrders $trackedOrders
 */
class Plugin extends \craft\base\Plugin
{
	public const HANDLE = 'shipments';

	public const PERMISSION_VIEW = 'shipments-viewShipments';

	public const PERMISSION_EDIT = 'shipments-editShipments';

	public const PERMISSION_DELETE = 'shipments-deleteShipments';

	public const PERMISSION_TRANSITION = 'shipments-transitionShipments';

	public const PERMISSION_PUSH = 'shipments-pushShipments';

	public const PERMISSION_MANAGE_INTEGRATIONS = 'shipments-manageIntegrations';

	public const PERMISSION_MANAGE_EMAILS = 'shipments-manageEmails';

	public const PERMISSION_MANAGE_SETTINGS = 'shipments-manageSettings';

	public bool $hasCpSettings = true;

	public bool $hasCpSection = true;

	public string $schemaVersion = '1.0.1';

	public function init(): void
	{
		parent::init();

		$this->setComponents([
			'rules' => Rules::class,
			'shipments' => Shipments::class,
			'shipmentLineItems' => ShipmentLineItems::class,
			'shipmentReferences' => ShipmentReferences::class,
			'integrations' => Integrations::class,
			'integrationReferences' => IntegrationReferences::class,
			'integrationStatusMaps' => IntegrationStatusMaps::class,
			'emails' => Emails::class,
			'transitionEmails' => TransitionEmails::class,
			'shipmentFieldLayouts' => ShipmentFieldLayouts::class,
			'trackedOrders' => TrackedOrders::class,
		]);

		if (Craft::$app instanceof ConsoleApplication) {
			$this->controllerNamespace = 'fostercommerce\\shipments\\console\\controllers';
		}

		$this->registerProjectConfigHandlers();
		$this->registerCpUrlRules();
		$this->registerOrderEditTemplateHook();
		$this->registerElementType();
		$this->registerOrderEagerLoadingMap();
		$this->registerGraphQl();
		$this->registerPermissions();

		Event::on(
			Order::class,
			Order::EVENT_AFTER_COMPLETE_ORDER,
			$this->createShipmentsOnOrderComplete(...),
		);

		Event::on(
			Order::class,
			Order::EVENT_AFTER_SAVE,
			$this->recomputeUnderAllocationOnOrderSave(...),
		);

		Event::on(
			Shipments::class,
			Shipments::EVENT_SHIPMENT_STATUS_CHANGED,
			$this->transitionEmails->onShipmentStatusChanged(...),
		);

		Event::on(
			Shipments::class,
			Shipments::EVENT_SHIPMENT_STATUS_CHANGED,
			$this->advanceOrderStatusOnShipped(...),
		);

		Event::on(
			OrderHistories::class,
			OrderHistories::EVENT_ORDER_STATUS_CHANGE,
			$this->onOrderStatusChange(...),
		);
	}

	public function getSettings(): Settings
	{
		/** @var Settings $settings */
		$settings = parent::getSettings();
		return $settings;
	}

	public function getRules(): Rules
	{
		/** @var Rules $rules */
		$rules = $this->get('rules');
		return $rules;
	}

	public function getShipments(): Shipments
	{
		/** @var Shipments $shipments */
		$shipments = $this->get('shipments');
		return $shipments;
	}

	public function getShipmentLineItems(): ShipmentLineItems
	{
		/** @var ShipmentLineItems $shipmentLineItems */
		$shipmentLineItems = $this->get('shipmentLineItems');
		return $shipmentLineItems;
	}

	public function getShipmentReferences(): ShipmentReferences
	{
		/** @var ShipmentReferences $shipmentReferences */
		$shipmentReferences = $this->get('shipmentReferences');
		return $shipmentReferences;
	}

	public function getIntegrations(): Integrations
	{
		/** @var Integrations $integrations */
		$integrations = $this->get('integrations');
		return $integrations;
	}

	public function getIntegrationReferences(): IntegrationReferences
	{
		/** @var IntegrationReferences $integrationReferences */
		$integrationReferences = $this->get('integrationReferences');
		return $integrationReferences;
	}

	public function getIntegrationStatusMaps(): IntegrationStatusMaps
	{
		/** @var IntegrationStatusMaps $integrationStatusMaps */
		$integrationStatusMaps = $this->get('integrationStatusMaps');
		return $integrationStatusMaps;
	}

	public function getEmails(): Emails
	{
		/** @var Emails $emails */
		$emails = $this->get('emails');
		return $emails;
	}

	public function getTransitionEmails(): TransitionEmails
	{
		/** @var TransitionEmails $transitionEmails */
		$transitionEmails = $this->get('transitionEmails');
		return $transitionEmails;
	}

	public function getShipmentFieldLayouts(): ShipmentFieldLayouts
	{
		/** @var ShipmentFieldLayouts $shipmentFieldLayouts */
		$shipmentFieldLayouts = $this->get('shipmentFieldLayouts');
		return $shipmentFieldLayouts;
	}

	public function getTrackedOrders(): TrackedOrders
	{
		/** @var TrackedOrders $trackedOrders */
		$trackedOrders = $this->get('trackedOrders');
		return $trackedOrders;
	}

	public function getSettingsResponse(): mixed
	{
		$response = Craft::$app->getResponse();
		/** @var \craft\web\Response $response */
		return $response->redirect(UrlHelper::cpUrl('shipments/settings/general'));
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function getCpNavItem(): ?array
	{
		$navItem = parent::getCpNavItem();
		if (! is_array($navItem)) {
			return null;
		}

		$navItem['label'] = Craft::t(self::HANDLE, 'nav.shipments');
		// Bare handle so any subpath under /admin/shipments/* keeps the section highlighted
		// (Craft's nav-selection uses str_starts_with on this URL).
		$navItem['url'] = self::HANDLE;

		$userService = Craft::$app->getUser();
		if ($userService->checkPermission(self::PERMISSION_VIEW)) {
			$navItem['subnav']['shipments'] = [
				'label' => Craft::t(self::HANDLE, 'nav.shipments'),
				'url' => 'shipments/shipments',
			];

			$attentionCount = $this->shipmentLineItems->getCachedAttentionCount();
			$navItem['subnav']['attention'] = [
				'label' => Craft::t(self::HANDLE, 'orderTab.attentionNeeded'),
				'url' => 'shipments/attention-needed',
				'badgeCount' => $attentionCount,
			];
		}

		if ($userService->checkPermission(self::PERMISSION_MANAGE_SETTINGS)) {
			$navItem['subnav']['settings'] = [
				'label' => Craft::t(self::HANDLE, 'nav.settings'),
				'url' => 'shipments/settings/general',
			];
		}

		return $navItem;
	}

	protected function createSettingsModel(): ?Model
	{
		return new Settings();
	}

	/**
	 * Recompute the denormalized `underAllocated` column on order save, since the shipment-side
	 * recompute only fires on shipment writes and order-side edits can leave it stale.
	 * Only completed orders are tracked, so carts bail before any DB lookup.
	 */
	private function recomputeUnderAllocationOnOrderSave(Event $event): void
	{
		$order = $event->sender;
		if (! $order instanceof Order || $order->id === null || ! $order->isCompleted) {
			return;
		}

		if (ElementHelper::isDraftOrRevision($order)) {
			return;
		}

		$this->trackedOrders->recomputeUnderAllocation($order);
	}

	/**
	 * Runs sync rather than queued so admins see shipments immediately after completing an
	 * order. Exceptions are swallowed: a create failure must not roll back order completion.
	 */
	private function createShipmentsOnOrderComplete(Event $event): void
	{
		$order = $event->sender;
		if (! $order instanceof Order || $order->id === null) {
			return;
		}

		if (ElementHelper::isDraftOrRevision($order)) {
			return;
		}

		if (! $this->getSettings()->autoCreateOnComplete) {
			return;
		}

		try {
			$this->shipments->createFor($order);
		} catch (Throwable $throwable) {
			Craft::error(
				"Shipments auto-create failed for order {$order->id}: " . $throwable->getMessage(),
				self::HANDLE,
			);
		}
	}

	private function advanceOrderStatusOnShipped(ShipmentStatusChangedEvent $event): void
	{
		// Only the edge into the shipped state can newly complete an order.
		$enteredShippedState = $event->toCode->advancesOrder()
			&& ! ($event->fromCode?->advancesOrder() ?? false);
		if (! $enteredShippedState) {
			return;
		}

		$orderId = $event->shipment->orderId;
		if ($orderId === null) {
			return;
		}

		// Queue so the order save runs after the shipment write commits, in its own transaction.
		Craft::$app->getQueue()->push(new AdvanceOrderStatusJob([
			'orderId' => $orderId,
		]));
	}

	/**
	 * On a Commerce order-status change into `orderStatusesToIgnore`, take the order out of the
	 * plugin's active fulfillment scope. Failures here are logged, not thrown: tracking must
	 * never roll back the admin's status change.
	 */
	private function onOrderStatusChange(OrderStatusEvent $event): void
	{
		$order = $event->order;
		if ($order->id === null) {
			return;
		}

		try {
			if (! $this->trackedOrders->isOrderStatusIgnored($order)) {
				return;
			}

			$trackedOrder = $this->trackedOrders->findForOrderId($order->id);
			if ($trackedOrder === null) {
				return;
			}

			$this->trackedOrders->markIgnored($order);
		} catch (Throwable $throwable) {
			Craft::error(
				"Tracked-order status-change handler failed for order {$order->id}: " . $throwable->getMessage(),
				self::HANDLE,
			);
		}
	}

	private function registerPermissions(): void
	{
		Event::on(
			UserPermissions::class,
			UserPermissions::EVENT_REGISTER_PERMISSIONS,
			static function (RegisterUserPermissionsEvent $event): void {
				$event->permissions[] = [
					'heading' => Craft::t(self::HANDLE, 'nav.shipments'),
					'permissions' => [
						self::PERMISSION_VIEW => [
							'label' => Craft::t(self::HANDLE, 'permission.viewShipments'),
							'nested' => [
								self::PERMISSION_EDIT => [
									'label' => Craft::t(self::HANDLE, 'permission.editShipments'),
								],
								self::PERMISSION_TRANSITION => [
									'label' => Craft::t(self::HANDLE, 'permission.transitionStatuses'),
								],
								self::PERMISSION_DELETE => [
									'label' => Craft::t(self::HANDLE, 'permission.deleteShipments'),
								],
								self::PERMISSION_PUSH => [
									'label' => Craft::t(self::HANDLE, 'permission.pushToIntegrations'),
								],
							],
						],
						self::PERMISSION_MANAGE_INTEGRATIONS => [
							'label' => Craft::t(self::HANDLE, 'permission.manageIntegrations'),
						],
						self::PERMISSION_MANAGE_EMAILS => [
							'label' => Craft::t(self::HANDLE, 'permission.manageEmails'),
						],
						self::PERMISSION_MANAGE_SETTINGS => [
							'label' => Craft::t(self::HANDLE, 'permission.manageSettings'),
						],
					],
				];
			},
		);
	}

	private function registerElementType(): void
	{
		Event::on(
			Elements::class,
			Elements::EVENT_REGISTER_ELEMENT_TYPES,
			static function (RegisterComponentTypesEvent $event): void {
				$event->types[] = Shipment::class;
			},
		);
	}

	/**
	 * Registers the Shipment GraphQL interface + root queries so headless front-ends can
	 * read shipments via `{shipments { ... }}`.
	 */
	private function registerGraphQl(): void
	{
		Event::on(
			Gql::class,
			Gql::EVENT_REGISTER_GQL_TYPES,
			static function (RegisterGqlTypesEvent $event): void {
				$event->types[] = ShipmentGqlInterface::class;
			},
		);

		Event::on(
			Gql::class,
			Gql::EVENT_REGISTER_GQL_QUERIES,
			static function (RegisterGqlQueriesEvent $event): void {
				$event->queries = array_merge(
					$event->queries,
					ShipmentGqlQuery::getQueries(),
				);
			},
		);

		Event::on(
			Gql::class,
			Gql::EVENT_REGISTER_GQL_SCHEMA_COMPONENTS,
			static function (RegisterGqlSchemaComponentsEvent $event): void {
				$event->queries[Craft::t(self::HANDLE, 'nav.shipments')] = [
					'shipments.read' => [
						'label' => Craft::t(self::HANDLE, 'gql.queryShipments'),
					],
				];
			},
		);
	}

	/**
	 * Wire up `Order::find()->with(['shipments'])` eager-loading. Craft doesn't know Order can
	 * have shipments; we teach it via `Element::EVENT_REGISTER_EAGER_LOADING_MAP`.
	 */
	private function registerOrderEagerLoadingMap(): void
	{
		Event::on(
			Order::class,
			Element::EVENT_DEFINE_EAGER_LOADING_MAP,
			static function (DefineEagerLoadingMapEvent $event): void {
				if ($event->handle !== 'shipments') {
					return;
				}

				$orderIds = array_values(array_filter(array_map(
					static fn (mixed $sourceElement): ?int => $sourceElement instanceof Order ? $sourceElement->id : null,
					$event->sourceElements,
				)));

				if ($orderIds === []) {
					$event->elementType = Shipment::class;
					$event->map = [];
					$event->handled = true;
					return;
				}

				/** @var list<array{source: int, target: int}> $map */
				$map = (new Query())
					->select([
						'source' => 'orderId',
						'target' => 'id',
					])
					->from(Table::SHIPMENTS)
					->where([
						'orderId' => $orderIds,
					])
					->all();

				$event->elementType = Shipment::class;
				$event->map = $map;
				$event->handled = true;
			},
		);
	}

	private function registerProjectConfigHandlers(): void
	{
		Craft::$app->getProjectConfig()
			->onAdd(Emails::CONFIG_EMAILS_KEY . '.{uid}', $this->emails->handleChangedEmail(...))
			->onUpdate(Emails::CONFIG_EMAILS_KEY . '.{uid}', $this->emails->handleChangedEmail(...))
			->onRemove(Emails::CONFIG_EMAILS_KEY . '.{uid}', $this->emails->handleDeletedEmail(...))
			->onAdd(Integrations::CONFIG_INTEGRATIONS_KEY . '.{uid}', $this->integrations->handleChangedIntegration(...))
			->onUpdate(Integrations::CONFIG_INTEGRATIONS_KEY . '.{uid}', $this->integrations->handleChangedIntegration(...))
			->onRemove(Integrations::CONFIG_INTEGRATIONS_KEY . '.{uid}', $this->integrations->handleDeletedIntegration(...))
			->onAdd(ShipmentFieldLayouts::CONFIG_FIELD_LAYOUT_KEY, $this->shipmentFieldLayouts->handleChangedFieldLayout(...))
			->onUpdate(ShipmentFieldLayouts::CONFIG_FIELD_LAYOUT_KEY, $this->shipmentFieldLayouts->handleChangedFieldLayout(...))
			->onRemove(ShipmentFieldLayouts::CONFIG_FIELD_LAYOUT_KEY, $this->shipmentFieldLayouts->handleDeletedFieldLayout(...));

		Event::on(
			ProjectConfig::class,
			ProjectConfig::EVENT_REBUILD,
			$this->onRebuildProjectConfig(...),
		);
	}

	private function onRebuildProjectConfig(RebuildConfigEvent $event): void
	{
		$emailsConfig = [];
		foreach ($this->emails->getAllEmails() as $email) {
			if ($email->uid === null) {
				continue;
			}

			$emailsConfig[$email->uid] = $email->getConfig();
		}

		$integrationsConfig = [];
		foreach ($this->integrations->getAllIntegrations() as $integration) {
			if ($integration->uid === null) {
				continue;
			}

			$integrationsConfig[$integration->uid] = $integration->getConfig();
		}

		$event->config['shipments']['emails'] = $emailsConfig;
		$event->config['shipments']['integrations'] = $integrationsConfig;

		$layout = $this->shipmentFieldLayouts->getFieldLayout();
		if ($layout->uid !== null) {
			$event->config['shipments']['shipmentFieldLayout'] = [
				$layout->uid => $layout->getConfig() ?? [],
			];
		}
	}

	private function registerCpUrlRules(): void
	{
		Event::on(
			UrlManager::class,
			UrlManager::EVENT_REGISTER_CP_URL_RULES,
			$this->registerCpUrlRulesHandler(...),
		);
	}

	private function registerCpUrlRulesHandler(RegisterUrlRulesEvent $event): void
	{
		$event->rules['shipments/settings/general'] = self::HANDLE . '/settings/edit';
		$event->rules['shipments/settings/shipment-fields'] = self::HANDLE . '/shipment-fields/edit';
		$event->rules['shipments/settings/emails'] = self::HANDLE . '/emails/index';
		$event->rules['shipments/settings/emails/new'] = self::HANDLE . '/emails/edit';
		$event->rules['shipments/settings/emails/<id:\d+>'] = self::HANDLE . '/emails/edit';
		$event->rules['shipments/settings/integrations'] = self::HANDLE . '/integrations/index';
		$event->rules['shipments/settings/integrations/new'] = self::HANDLE . '/integrations/edit';
		$event->rules['shipments/settings/integrations/<id:\d+>'] = self::HANDLE . '/integrations/edit';
		$event->rules['shipments/settings/integrations/<id:\d+>/status-maps'] = self::HANDLE . '/integrations/status-maps';
		$event->rules['shipments'] = [
			'template' => self::HANDLE . '/_cp/shipment/_index',
		];
		$event->rules['shipments/shipments'] = [
			'template' => self::HANDLE . '/_cp/shipment/_index',
		];
		$event->rules['shipments/shipments/<id:\d+>'] = self::HANDLE . '/shipments/edit';
		$event->rules['shipments/shipments/create-shipment'] = self::HANDLE . '/shipments/create-shipment';
		$event->rules['shipments/attention-needed'] = self::HANDLE . '/attention/index';

		$event->rules['POST shipments/api/shipments/<id:\d+>'] = self::HANDLE . '/api/update';
	}

	private function registerOrderEditTemplateHook(): void
	{
		Event::on(
			View::class,
			View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE,
			$this->registerShipmentsTab(...),
		);

		Craft::$app->getView()->hook('cp.commerce.order.content', $this->renderShipmentsTabContent(...));
	}

	private function registerShipmentsTab(TemplateEvent $event): void
	{
		// Scope to the Commerce order edit template; otherwise any page that injects `tabs`
		// and has an `order` in scope would also pick up our shipments tab.
		if ($event->template !== 'commerce/orders/_edit') {
			return;
		}

		if (! Craft::$app->getUser()->checkPermission(self::PERMISSION_VIEW)) {
			return;
		}

		if (! array_key_exists('order', $event->variables) || ! array_key_exists('tabs', $event->variables)) {
			return;
		}

		$order = $event->variables['order'];
		if (! $order instanceof Order || $order->id === null) {
			return;
		}

		$count = count($this->shipments->findByOrderId($order->id));
		$label = $count > 0
			? Craft::t(self::HANDLE, 'orderTab.tabLabelWithCount', [
				'count' => $count,
			])
			: Craft::t(self::HANDLE, 'orderTab.tabLabel');

		$tabs = $event->variables['tabs'];
		if (! is_array($tabs)) {
			return;
		}

		$tabs['shipments-shipments'] = [
			'label' => $label,
			'url' => '#orderShipmentsTab',
			'class' => null,
		];
		$event->variables['tabs'] = $tabs;
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private function renderShipmentsTabContent(array &$context): string
	{
		if (! Craft::$app->getUser()->checkPermission(self::PERMISSION_VIEW)) {
			return '';
		}

		$order = $context['order'] ?? null;
		if (! $order instanceof Order || $order->id === null) {
			return '';
		}

		$shipments = $this->shipments->findByOrderId($order->id);
		$trashedShipmentsCount = count($this->shipments->findTrashedByOrderId($order->id));
		$unallocatedPool = $this->shipmentLineItems->remainingPoolFor($order);
		$suggestedGroups = $this->suggestedStagingGroups($order, $shipments, $unallocatedPool);

		$trackedOrderRecord = $this->trackedOrders->findForOrderId($order->id);
		// Self-heal: if shipments exist but no tracked-orders row does, the order was tracked
		// at some point and the row got out of sync. Insert one now (state='active'), so the
		// switch reflects the obvious reality rather than reading the absent row as 'ignored'.
		if ($trackedOrderRecord === null && $shipments !== []) {
			$trackedOrderRecord = $this->trackedOrders->evaluateAndUpsert($order);
		}

		$isTracked = $trackedOrderRecord !== null;
		$isOrderStatusIgnored = $this->trackedOrders->isOrderStatusIgnored($order);
		$trackedState = $trackedOrderRecord?->state ?? 'ignored';
		$trackedShippable = $trackedOrderRecord?->shippable ?? $this->trackedOrders->resolveShippable($order)->value;

		$view = Craft::$app->getView();
		$view->registerAssetBundle(ShipmentsCpAsset::class);

		return $view->renderTemplate(self::HANDLE . '/_cp/order/shipments-tab', [
			'order' => $order,
			'shipments' => $shipments,
			'trashedShipmentsCount' => $trashedShipmentsCount,
			'unallocatedPool' => $unallocatedPool,
			'suggestedGroups' => $suggestedGroups,
			'trackedState' => $trackedState,
			'trackedShippable' => $trackedShippable,
			'isOrderStatusIgnored' => $isOrderStatusIgnored,
			'isTracked' => $isTracked,
		], View::TEMPLATE_MODE_CP);
	}

	/**
	 * @param list<\fostercommerce\shipments\elements\Shipment> $existingShipments
	 * @param array<int, int> $unallocatedPool
	 * @return list<array<int, int>>
	 */
	private function suggestedStagingGroups(Order $order, array $existingShipments, array $unallocatedPool): array
	{
		if ($existingShipments !== []) {
			return [];
		}

		if (! $order->isCompleted) {
			return [];
		}

		if ($unallocatedPool === []) {
			return [];
		}

		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();
		$defaultOrderStatusId = $commerce->getOrderStatuses()->getDefaultOrderStatusId($order->storeId);
		if ($defaultOrderStatusId === null || $order->orderStatusId !== $defaultOrderStatusId) {
			return [];
		}

		$groups = [];
		foreach ($this->rules->planFor($order) as $plan) {
			$groupRow = [];
			foreach ($plan->lineItemQtys as $lineItemId => $qty) {
				if ($qty <= 0) {
					continue;
				}

				$groupRow[$lineItemId] = $qty;
			}

			if ($groupRow !== []) {
				$groups[] = $groupRow;
			}
		}

		return $groups;
	}
}
