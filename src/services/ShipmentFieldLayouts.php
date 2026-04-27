<?php

declare(strict_types=1);

namespace fostercommerce\shipments\services;

use Craft;
use craft\events\ConfigEvent;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use craft\helpers\StringHelper;
use craft\models\FieldLayout;
use fostercommerce\shipments\elements\Shipment;
use Throwable;
use yii\base\Component;

/**
 * Owns the single shared `Shipment` field layout; persisted via project config so admins
 * can add custom fields from Shipments > Settings > Shipment Fields.
 */
class ShipmentFieldLayouts extends Component
{
	public const CONFIG_FIELD_LAYOUT_KEY = 'shipments.shipmentFieldLayout';

	public function getFieldLayout(): FieldLayout
	{
		$layout = Craft::$app->getFields()->getLayoutByType(Shipment::class);
		if ($layout === null) {
			$layout = new FieldLayout();
		}

		$layout->type = Shipment::class;
		return $layout;
	}

	/**
	 * @throws Throwable
	 */
	public function saveFieldLayout(FieldLayout $layout): bool
	{
		$layout->type = Shipment::class;
		if (! $layout->validate()) {
			return false;
		}

		$uid = $layout->uid ?? StringHelper::UUID();
		$layout->uid = $uid;

		$projectConfig = Craft::$app->getProjectConfig();
		$projectConfig->set(self::CONFIG_FIELD_LAYOUT_KEY, [
			$uid => $layout->getConfig() ?? [],
		]);

		return true;
	}

	/**
	 * @throws Throwable
	 */
	public function handleChangedFieldLayout(ConfigEvent $event): void
	{
		/** @var array<string, array<string, mixed>>|null $data */
		$data = $event->newValue;

		ProjectConfigHelper::ensureAllFieldsProcessed();
		$fieldsService = Craft::$app->getFields();

		if ($data === null || $data === [] || reset($data) === []) {
			$fieldsService->deleteLayoutsByType(Shipment::class);
			return;
		}

		$config = reset($data);
		$uid = (string) key($data);

		$layout = FieldLayout::createFromConfig($config);
		$existing = $fieldsService->getLayoutByType(Shipment::class);
		if ($existing !== null) {
			$layout->id = $existing->id;
		}

		$layout->type = Shipment::class;
		$layout->uid = $uid;

		$fieldsService->saveLayout($layout, false);
	}

	public function handleDeletedFieldLayout(): void
	{
		Craft::$app->getFields()->deleteLayoutsByType(Shipment::class);
	}
}
