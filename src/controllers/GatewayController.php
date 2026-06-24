<?php

declare(strict_types=1);

namespace fostercommerce\shipments\controllers;

use Craft;
use craft\web\Controller;
use craft\web\Response;
use fostercommerce\shipments\errors\IntegrationException;
use fostercommerce\shipments\errors\PermanentIntegrationException;
use fostercommerce\shipments\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;

class GatewayController extends Controller
{
	public $enableCsrfValidation = false;

	protected array|bool|int $allowAnonymous = ['handle'];

	/**
	 * @throws NotFoundHttpException
	 * @throws BadRequestHttpException
	 */
	public function actionHandle(?string $integration = null): Response
	{
		if (! is_string($integration) || $integration === '') {
			throw new BadRequestHttpException('Integration must be set.');
		}

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		try {
			$provider = $plugin->integrations->resolveEnabledProvider($integration);
		} catch (PermanentIntegrationException $permanentIntegrationException) {
			Craft::error(sprintf('[%s] cannot handle integration request: %s', $integration, $permanentIntegrationException->getMessage()), Plugin::HANDLE);
			$message = "{$permanentIntegrationException->getMessage()} Cannot handle integration request.";
			if ($permanentIntegrationException->getCode() === 404) {
				throw new NotFoundHttpException($message, 0, $permanentIntegrationException);
			}

			throw new BadRequestHttpException($message, 0, $permanentIntegrationException);
		}

		try {
			return $provider->handleGatewayRequest($this->request);
		} catch (IntegrationException $integrationException) {
			Craft::error(sprintf('[%s] integration request rejected: %s', $integration, $integrationException->getMessage()), Plugin::HANDLE);
			throw new BadRequestHttpException($integrationException->getMessage(), 0, $integrationException);
		}
	}
}
