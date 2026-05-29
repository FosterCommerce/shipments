<?php

declare(strict_types=1);

namespace fostercommerce\shipments\controllers;

use Craft;
use craft\web\Controller;
use craft\web\Response;
use fostercommerce\shipments\errors\IntegrationException;
use fostercommerce\shipments\errors\PermanentIntegrationException;
use fostercommerce\shipments\Plugin;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;

/**
 * Public entry point at `shipments/exports/<integrationHandle>`. Delegates to the
 * provider's `export()`; auth + format are the provider's job.
 */
class ExportsController extends Controller
{
	public $enableCsrfValidation = false;

	protected array|bool|int $allowAnonymous = ['handle'];

	/**
	 * @throws NotFoundHttpException
	 * @throws BadRequestHttpException
	 */
	public function actionHandle(string $integrationHandle): Response
	{
		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		try {
			$provider = $plugin->integrations->resolveEnabledProvider($integrationHandle);
		} catch (PermanentIntegrationException $permanentIntegrationException) {
			Craft::error(sprintf('[%s] cannot serve exports: %s', $integrationHandle, $permanentIntegrationException->getMessage()), Plugin::HANDLE);
			$message = "{$permanentIntegrationException->getMessage()} Cannot serve exports.";
			if ($permanentIntegrationException->getCode() === 404) {
				throw new NotFoundHttpException($message, 0, $permanentIntegrationException);
			}

			throw new BadRequestHttpException($message, 0, $permanentIntegrationException);
		}

		try {
			return $provider->export($this->request);
		} catch (IntegrationException $integrationException) {
			Craft::error(sprintf('[%s] export rejected: %s', $integrationHandle, $integrationException->getMessage()), Plugin::HANDLE);
			throw new BadRequestHttpException($integrationException->getMessage(), 0, $integrationException);
		} catch (Throwable $throwable) {
			Craft::error(sprintf('[%s] unexpected export error: %s', $integrationHandle, $throwable->getMessage()), Plugin::HANDLE);
			throw new BadRequestHttpException('Export processing failed.', 0, $throwable);
		}
	}
}
