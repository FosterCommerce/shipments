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
				'Couldn’t restore shipments.',
				'Couldn’t save shipments.',
				'Couldn’t update this order.',
				'Delete shipment {reference}? Its line items will go back to the unallocated pool.',
				'Delete failed.',
				'Line item',
				'Qty in group',
				'Remaining',
				'Remove group',
				'Turning this off will trash {count} shipment(s) on this order and stop tracking it for fulfillment. Continue?',
				'Turn off shipping for this order? It will drop off the Attention page.',
			]);
		}
	}
}
