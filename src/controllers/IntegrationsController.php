<?php

declare(strict_types=1);

namespace fostercommerce\shipments\controllers;

use Craft;
use craft\helpers\Json;
use craft\web\Controller;
use fostercommerce\shipments\base\ControllerBodyParamsTrait;
use fostercommerce\shipments\base\ProviderInterface;
use fostercommerce\shipments\enums\FulfillmentStatus;
use fostercommerce\shipments\enums\ShippingStatus;
use fostercommerce\shipments\enums\StatusAxis;
use fostercommerce\shipments\models\Integration;
use fostercommerce\shipments\Plugin;
use fostercommerce\shipments\services\IntegrationStatusMaps;
use fostercommerce\shipments\web\assets\cp\ShipmentsCpAsset;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * CP CRUD for integrations. Writes flow through project config.
 */
class IntegrationsController extends Controller
{
	use ControllerBodyParamsTrait;

	public function actionIndex(): Response
	{
		$this->requirePermission(Plugin::PERMISSION_MANAGE_INTEGRATIONS);

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		return $this->renderTemplate(Plugin::HANDLE . '/settings/integrations/index', [
			'integrations' => $plugin->integrations->getAllIntegrations(),
		]);
	}

	/**
	 * @throws NotFoundHttpException
	 */
	public function actionEdit(?int $id = null, ?Integration $integration = null): Response
	{
		$this->requirePermission(Plugin::PERMISSION_MANAGE_INTEGRATIONS);

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		if (! $integration instanceof Integration) {
			if ($id !== null) {
				$loaded = $plugin->integrations->getIntegrationById($id);
				if (! $loaded instanceof Integration) {
					throw new NotFoundHttpException(Craft::t(Plugin::HANDLE, 'Integration not found.'));
				}

				$integration = $loaded;
			} else {
				$integration = new Integration();
			}
		}

		$providerOptions = [];
		$providerSettings = [];
		foreach ($plugin->integrations->getSelectableProviderTypes() as $providerClass) {
			/** @var class-string<ProviderInterface> $providerClass */
			$providerOptions[] = [
				'label' => $providerClass::displayName(),
				'value' => $providerClass,
			];

			$providerInstance = $plugin->integrations->createProvider([
				'type' => $providerClass,
				'name' => $integration->name,
				'handle' => $integration->handle,
				'enabled' => $integration->enabled,
				'settings' => $integration->provider === $providerClass ? $integration->settings : [],
				'uid' => $integration->uid,
			]);
			$providerSettings[$providerClass] = $providerInstance->getSettingsHtml() ?? '';
		}

		$this->view->registerAssetBundle(ShipmentsCpAsset::class);

		return $this->renderTemplate(Plugin::HANDLE . '/settings/integrations/_edit', [
			'integration' => $integration,
			'providerOptions' => $providerOptions,
			'providerSettings' => $providerSettings,
			'title' => $integration->id === null
				? Craft::t(Plugin::HANDLE, 'Create a new integration')
				: (string) $integration,
		]);
	}

	/**
	 * @throws BadRequestHttpException
	 */
	public function actionSave(): ?Response
	{
		$this->requirePostRequest();
		$this->requirePermission(Plugin::PERMISSION_MANAGE_INTEGRATIONS);

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		$idInput = $this->request->getBodyParam('id');
		$existingId = is_numeric($idInput) ? (int) $idInput : null;

		$integration = null;
		if ($existingId !== null) {
			$existing = $plugin->integrations->getIntegrationById($existingId);
			if ($existing instanceof Integration) {
				$integration = $existing;
			}
		}

		if (! $integration instanceof Integration) {
			$integration = new Integration();
		}

		$integration->name = $this->bodyString('name') ?? (string) $integration->name;
		$integration->handle = $this->bodyString('handle') ?? (string) $integration->handle;
		$integration->urlTemplate = $this->bodyString('urlTemplate');
		$integration->provider = $this->bodyString('provider');
		$integration->enabled = (bool) $this->request->getBodyParam('enabled', $integration->enabled);

		$postedSettings = $this->request->getBodyParam('settings');
		$integration->settings = is_array($postedSettings) ? $postedSettings : [];

		if (! $plugin->integrations->saveIntegration($integration)) {
			Craft::$app->getSession()->setError(Craft::t(Plugin::HANDLE, 'Couldn’t save integration.'));
			Craft::$app->getUrlManager()->setRouteParams([
				'integration' => $integration,
			]);
			return null;
		}

		Craft::$app->getSession()->setNotice(Craft::t(Plugin::HANDLE, 'Integration saved.'));
		return $this->redirectToPostedUrl($integration);
	}

	/**
	 * @throws BadRequestHttpException
	 */
	public function actionDelete(): Response
	{
		$this->requirePostRequest();
		$this->requireAcceptsJson();
		$this->requirePermission(Plugin::PERMISSION_MANAGE_INTEGRATIONS);

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		$idInput = $this->request->getRequiredBodyParam('id');
		if (! is_numeric($idInput)) {
			throw new BadRequestHttpException(Craft::t(Plugin::HANDLE, 'Invalid integration id.'));
		}

		if (! $plugin->integrations->deleteIntegrationById((int) $idInput)) {
			return $this->asJson([
				'success' => false,
				'error' => Craft::t(Plugin::HANDLE, 'The integration could not be deleted.'),
			]);
		}

		return $this->asJson([
			'success' => true,
		]);
	}

	/**
	 * Renders + saves the per-integration status mapping editor. Two tables
	 * (fulfillment + shipping) drive `shipments_integration_status_maps`.
	 *
	 * @throws NotFoundHttpException
	 */
	public function actionStatusMaps(int $id): Response
	{
		$this->requirePermission(Plugin::PERMISSION_MANAGE_INTEGRATIONS);

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		$integration = $plugin->integrations->getIntegrationById($id);
		if (! $integration instanceof Integration) {
			throw new NotFoundHttpException(Craft::t(Plugin::HANDLE, 'Integration not found.'));
		}

		if ($this->request->getIsPost()) {
			$transaction = Craft::$app->getDb()->beginTransaction();

			try {
				$plugin->integrationStatusMaps->deleteAllForIntegration($id);

				$this->persistMapRows($id, StatusAxis::Fulfillment, $this->request->getBodyParam('fulfillmentMaps', []));
				$this->persistMapRows($id, StatusAxis::Shipping, $this->request->getBodyParam('shippingMaps', []));

				$transaction->commit();
			} catch (Throwable $throwable) {
				$transaction->rollBack();
				Craft::$app->getSession()->setError(Craft::t(Plugin::HANDLE, 'Couldn’t save status mappings: {message}', [
					'message' => $throwable->getMessage(),
				]));
				return $this->redirectToPostedUrl();
			}

			Craft::$app->getSession()->setNotice(Craft::t(Plugin::HANDLE, 'Status mappings saved.'));
			return $this->redirectToPostedUrl();
		}

		return $this->renderTemplate(Plugin::HANDLE . '/settings/integrations/_status-maps', [
			'integration' => $integration,
			'maps' => $plugin->integrationStatusMaps->findForIntegration($id),
			'fulfillmentOptions' => FulfillmentStatus::labelMap(),
			'shippingOptions' => ShippingStatus::labelMap(),
			'directionOptions' => [
				IntegrationStatusMaps::DIRECTION_INBOUND => Craft::t(Plugin::HANDLE, 'Inbound'),
				IntegrationStatusMaps::DIRECTION_OUTBOUND => Craft::t(Plugin::HANDLE, 'Outbound'),
				IntegrationStatusMaps::DIRECTION_BIDIRECTIONAL => Craft::t(Plugin::HANDLE, 'Bidirectional'),
			],
			'title' => Craft::t(Plugin::HANDLE, 'Status mappings for {name}', [
				'name' => $integration->name ?? '',
			]),
		]);
	}

	/**
	 * @throws BadRequestHttpException
	 */
	public function actionReorder(): Response
	{
		$this->requirePostRequest();
		$this->requireAcceptsJson();
		$this->requirePermission(Plugin::PERMISSION_MANAGE_INTEGRATIONS);

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		$idsInput = $this->request->getRequiredBodyParam('ids');
		if (is_string($idsInput)) {
			$decoded = Json::decodeIfJson($idsInput);
			$idsInput = is_array($decoded) ? $decoded : [];
		}

		$plugin->integrations->reorderIntegrations($this->normalizeIntList($idsInput));

		return $this->asJson([
			'success' => true,
		]);
	}

	/**
	 * @param mixed $rawRows raw `editableTableField` POST
	 */
	private function persistMapRows(int $integrationId, StatusAxis $axis, mixed $rawRows): void
	{
		if (! is_array($rawRows)) {
			return;
		}

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		foreach ($rawRows as $row) {
			if (! is_array($row)) {
				continue;
			}

			$externalCodeRaw = $row['externalCode'] ?? '';
			$externalCode = is_string($externalCodeRaw) ? trim($externalCodeRaw) : '';
			if ($externalCode === '') {
				continue;
			}

			$internalCodeRaw = $row['internalCode'] ?? '';
			$internalCode = is_string($internalCodeRaw) ? $internalCodeRaw : '';
			if ($axis->resolveCode($internalCode) === null) {
				continue;
			}

			$directionRaw = $row['direction'] ?? IntegrationStatusMaps::DIRECTION_INBOUND;
			$direction = is_string($directionRaw) && $directionRaw !== '' ? $directionRaw : IntegrationStatusMaps::DIRECTION_INBOUND;

			$externalLabelRaw = $row['externalLabel'] ?? null;
			$externalLabel = is_string($externalLabelRaw) && trim($externalLabelRaw) !== '' ? trim($externalLabelRaw) : null;

			$plugin->integrationStatusMaps->saveMap(
				$integrationId,
				$axis,
				$direction,
				$externalCode,
				$externalLabel,
				$internalCode,
			);

			$plugin->integrationStatusMaps->resolveUnmappedCode($integrationId, $axis, $externalCode);
		}
	}
}
