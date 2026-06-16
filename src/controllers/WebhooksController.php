<?php

declare(strict_types=1);

namespace fostercommerce\shipments\controllers;

use Craft;
use craft\web\Controller;
use fostercommerce\shipments\errors\IntegrationException;
use fostercommerce\shipments\errors\PermanentIntegrationException;
use fostercommerce\shipments\Plugin;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Public entry point at `shipments/webhooks/<integrationHandle>`, delegating to the provider's `receiveShipmentUpdate()`.
 *
 * Signature verification is the provider's job. Returns 405 when `canReceiveUpdates()` is false.
 */
class WebhooksController extends Controller
{
	public $enableCsrfValidation = false;

	protected array|bool|int $allowAnonymous = ['handle'];

	/**
	 * @throws NotFoundHttpException
	 * @throws BadRequestHttpException
	 * @throws MethodNotAllowedHttpException
	 */
	public function actionHandle(string $integrationHandle): Response
	{
		$this->requirePostRequest();

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		try {
			$provider = $plugin->integrations->resolveEnabledProvider($integrationHandle);
		} catch (PermanentIntegrationException $permanentIntegrationException) {
			Craft::error(sprintf('[%s] cannot accept webhooks: %s', $integrationHandle, $permanentIntegrationException->getMessage()), Plugin::HANDLE);
			$message = "{$permanentIntegrationException->getMessage()} Cannot accept webhooks.";
			if ($permanentIntegrationException->getCode() === 404) {
				throw new NotFoundHttpException($message, 0, $permanentIntegrationException);
			}

			throw new BadRequestHttpException($message, 0, $permanentIntegrationException);
		}

		if (! $provider->canReceiveUpdates()) {
			throw new MethodNotAllowedHttpException("Integration {$integrationHandle} does not accept inbound webhooks.");
		}

		try {
			$shipment = $provider->receiveShipmentUpdate($this->request);
		} catch (IntegrationException $integrationException) {
			Craft::error(sprintf('[%s] webhook rejected: %s', $integrationHandle, $integrationException->getMessage()), Plugin::HANDLE);
			throw new BadRequestHttpException($integrationException->getMessage(), 0, $integrationException);
		} catch (Throwable $throwable) {
			Craft::error(sprintf('[%s] unexpected webhook error: %s', $integrationHandle, $throwable->getMessage()), Plugin::HANDLE);
			throw new BadRequestHttpException('Webhook processing failed.', 0, $throwable);
		}

		return $this->asJson([
			'success' => true,
			'shipmentId' => $shipment?->id,
		]);
	}
}
