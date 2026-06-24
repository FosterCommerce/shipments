<?php

declare(strict_types=1);

namespace fostercommerce\shipments\controllers;

use Craft;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\web\assets\admintable\AdminTableAsset;
use craft\web\Controller;
use fostercommerce\shipments\base\ControllerBodyParamsTrait;
use fostercommerce\shipments\base\ProviderInterface;
use fostercommerce\shipments\enums\Status;
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
		$integrations = $plugin->integrations->getAllIntegrations();

		$this->view->registerAssetBundle(AdminTableAsset::class);

		$integrationTableData = [];
		foreach ($integrations as $integration) {
			$typeLabel = Craft::t(Plugin::HANDLE, 'settings.integrations.providerNone');

			try {
				$provider = $integration->getProvider();
				if ($provider !== null) {
					$typeLabel = $provider::displayName();
				}
			} catch (Throwable) {
				$typeLabel = $integration->provider;
			}

			$integrationTableData[] = [
				'id' => $integration->id,
				'title' => (string) $integration,
				'url' => $integration->getCpEditUrl(),
				'type' => $typeLabel,
				'enabled' => [
					'status' => $integration->isEnabled(),
					'label' => $integration->isEnabled()
						? Craft::t(Plugin::HANDLE, 'settings.integrations.enabled')
						: Craft::t(plugin::HANDLE, 'settings.integrations.disabled'),
				],
			];
		}

		return $this->renderTemplate(Plugin::HANDLE . '/settings/integrations/index', [
			'integrations' => $integrations,
			'integrationTableData' => $integrationTableData,
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
					throw new NotFoundHttpException(Craft::t(Plugin::HANDLE, 'error.integrationNotFound'));
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
				'enabled' => $integration->isEnabled(),
				'settings' => $integration->provider === $providerClass ? $integration->settings : [],
				'uid' => $integration->uid,
			]);
			$providerSettings[$providerClass] = $providerInstance->getSettingsHtml() ?? '';
		}

		$this->view->registerAssetBundle(ShipmentsCpAsset::class);
		$actionTrigger = Craft::$app->getConfig()->getGeneral()->actionTrigger;
		$gatewayEndpointUrl = $integration->handle !== null && $integration->handle !== ''
			? UrlHelper::siteUrl("{$actionTrigger}/shipments/gateway/handle", [
				'integration' => $integration->handle,
			])
			: null;

		return $this->renderTemplate(Plugin::HANDLE . '/settings/integrations/_edit', [
			'integration' => $integration,
			'gatewayEndpointUrl' => $gatewayEndpointUrl,
			'providerOptions' => $providerOptions,
			'providerSettings' => $providerSettings,
			'title' => $integration->id === null
				? Craft::t(Plugin::HANDLE, 'integrations.createNew')
				: (string) $integration,
		]);
	}

	/**
	 * @throws BadRequestHttpException
	 */
	public function actionSave(): ?Response
	{
		$this->requirePostRequest();
		$this->requireAdmin();

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
		$integration->enabled = $this->normalizeEnabledConfig($this->request->getBodyParam('enabled', $integration->enabled));

		$postedSettings = $this->request->getBodyParam('settings');
		$integration->settings = is_array($postedSettings) ? $postedSettings : [];

		if (! $plugin->integrations->saveIntegration($integration)) {
			Craft::$app->getSession()->setError(Craft::t(Plugin::HANDLE, 'error.couldNotSaveIntegration'));
			Craft::$app->getUrlManager()->setRouteParams([
				'integration' => $integration,
			]);
			return null;
		}

		Craft::$app->getSession()->setNotice(Craft::t(Plugin::HANDLE, 'integrations.saved'));
		return $this->redirectToPostedUrl($integration);
	}

	/**
	 * @throws BadRequestHttpException
	 */
	public function actionDelete(): Response
	{
		$this->requirePostRequest();
		$this->requireAcceptsJson();
		$this->requireAdmin();

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		$idInput = $this->request->getRequiredBodyParam('id');
		if (! is_numeric($idInput)) {
			throw new BadRequestHttpException(Craft::t(Plugin::HANDLE, 'error.invalidIntegrationId'));
		}

		if (! $plugin->integrations->deleteIntegrationById((int) $idInput)) {
			return $this->asJson([
				'success' => false,
				'error' => Craft::t(Plugin::HANDLE, 'error.integrationNotDeleted'),
			]);
		}

		return $this->asJson([
			'success' => true,
		]);
	}

	/**
	 * Renders + saves the per-integration status mapping editor that drives
	 * `shipments_integration_status_maps`.
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
			throw new NotFoundHttpException(Craft::t(Plugin::HANDLE, 'error.integrationNotFound'));
		}

		if ($this->request->getIsPost()) {
			$this->requireAdmin();

			$transaction = Craft::$app->getDb()->beginTransaction();

			try {
				$plugin->integrationStatusMaps->deleteAllForIntegration($id);

				$this->persistMapRows($id, $this->request->getBodyParam('statusMaps', []));

				$transaction->commit();
			} catch (Throwable $throwable) {
				$transaction->rollBack();
				Craft::$app->getSession()->setError(Craft::t(Plugin::HANDLE, 'error.couldNotSaveStatusMappings', [
					'message' => $throwable->getMessage(),
				]));
				return $this->redirectToPostedUrl();
			}

			Craft::$app->getSession()->setNotice(Craft::t(Plugin::HANDLE, 'settings.integrations.statusMappingsSaved'));
			return $this->redirectToPostedUrl();
		}

		return $this->renderTemplate(Plugin::HANDLE . '/settings/integrations/_status-maps', [
			'integration' => $integration,
			'maps' => $plugin->integrationStatusMaps->findForIntegration($id),
			'statusOptions' => Status::labelMap(),
			'directionOptions' => [
				IntegrationStatusMaps::DIRECTION_INBOUND => Craft::t(Plugin::HANDLE, 'settings.integrations.directionInbound'),
				IntegrationStatusMaps::DIRECTION_OUTBOUND => Craft::t(Plugin::HANDLE, 'settings.integrations.directionOutbound'),
				IntegrationStatusMaps::DIRECTION_BIDIRECTIONAL => Craft::t(Plugin::HANDLE, 'settings.integrations.directionBidirectional'),
			],
			'title' => Craft::t(Plugin::HANDLE, 'settings.integrations.statusMappingsFor', [
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
		$this->requireAdmin();

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
	private function persistMapRows(int $integrationId, mixed $rawRows): void
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
			if (! Status::tryFrom($internalCode) instanceof Status) {
				continue;
			}

			$directionRaw = $row['direction'] ?? IntegrationStatusMaps::DIRECTION_INBOUND;
			$direction = is_string($directionRaw) && $directionRaw !== '' ? $directionRaw : IntegrationStatusMaps::DIRECTION_INBOUND;

			$externalLabelRaw = $row['externalLabel'] ?? null;
			$externalLabel = is_string($externalLabelRaw) && trim($externalLabelRaw) !== '' ? trim($externalLabelRaw) : null;

			$plugin->integrationStatusMaps->saveMap(
				$integrationId,
				$direction,
				$externalCode,
				$externalLabel,
				$internalCode,
			);
		}
	}

	private function normalizeEnabledConfig(mixed $value): bool|string
	{
		if (is_string($value) && str_starts_with($value, '$')) {
			return $value;
		}

		return (bool) $value;
	}
}
