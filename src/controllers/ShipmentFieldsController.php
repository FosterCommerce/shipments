<?php

declare(strict_types=1);

namespace fostercommerce\shipments\controllers;

use Craft;
use craft\web\Controller;
use fostercommerce\shipments\elements\Shipment;
use fostercommerce\shipments\Plugin;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Shipment field layout designer. Edits the single shared `Shipment` field layout.
 */
class ShipmentFieldsController extends Controller
{
	/**
	 * @throws Throwable
	 */
	public function actionEdit(): Response
	{
		$this->requirePermission(Plugin::PERMISSION_MANAGE_SETTINGS);

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		return $this->renderTemplate(Plugin::HANDLE . '/_cp/settings/shipment-fields/index', [
			'title' => Craft::t(Plugin::HANDLE, 'nav.shipmentFields'),
			'fieldLayout' => $plugin->shipmentFieldLayouts->getFieldLayout(),
		]);
	}

	/**
	 * @throws BadRequestHttpException
	 * @throws Throwable
	 */
	public function actionSave(): ?Response
	{
		$this->requirePostRequest();
		$this->requirePermission(Plugin::PERMISSION_MANAGE_SETTINGS);

		if (! Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
			throw new ForbiddenHttpException(Craft::t(Plugin::HANDLE, 'error.adminChangesDisallowed'));
		}

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		$layout = Craft::$app->getFields()->assembleLayoutFromPost();
		$layout->type = Shipment::class;

		if (! $plugin->shipmentFieldLayouts->saveFieldLayout($layout)) {
			Craft::$app->getSession()->setError(Craft::t(Plugin::HANDLE, 'error.couldNotSaveShipmentFields'));
			Craft::$app->getUrlManager()->setRouteParams([
				'fieldLayout' => $layout,
			]);
			return null;
		}

		Craft::$app->getSession()->setNotice(Craft::t(Plugin::HANDLE, 'settings.fields.saved'));
		return $this->redirectToPostedUrl();
	}
}
