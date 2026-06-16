<?php

declare(strict_types=1);

namespace fostercommerce\shipments\web\assets\cp;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;
use craft\web\View;
use fostercommerce\shipments\Plugin;

/**
 * Control-panel asset bundle for the Shipments plugin. Ships the CSS + JS used by the order-
 * edit Shipments tab (staging-group creation flow, remove-shipment button) and the shipment
 * edit page (integration-references repeater).
 *
 * Mirrors the `CommerceCpAsset` shape: `sourcePath` under `/dist`, depends on `CpAsset`, all
 * files flat under `css/` and `js/`.
 */
class ShipmentsCpAsset extends AssetBundle
{
	public function init(): void
	{
		$this->sourcePath = __DIR__ . '/dist';

		$this->depends = [
			CpAsset::class,
		];

		$this->css[] = 'css/shipments-cp.css';
		$this->js[] = 'js/shipments-cp.js';

		parent::init();
	}

	public function registerAssetFiles($view): void
	{
		parent::registerAssetFiles($view);

		if ($view instanceof View) {
			$view->registerTranslations(Plugin::HANDLE, [
				'error.couldNotRestoreShipments',
				'error.couldNotSaveLineItems',
				'error.couldNotSaveShipments',
				'error.couldNotUpdateOrder',
				'shipmentEdit.deleteConfirmWithReference',
				'error.deleteFailed',
				'shipmentEdit.lineItems.lineItem',
				'orderTab.staging.qtyInGroup',
				'orderTab.staging.remaining',
				'orderTab.staging.removeGroup',
				'orderTab.requiresShippingOffConfirm',
			]);
		}
	}
}
