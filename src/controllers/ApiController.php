<?php

declare(strict_types=1);

namespace fostercommerce\shipments\controllers;

use Craft;
use craft\helpers\DateTimeHelper;
use craft\web\Controller;
use DateTimeInterface;
use fostercommerce\shipments\elements\Shipment;
use fostercommerce\shipments\models\Integration;
use fostercommerce\shipments\models\ShipmentUpdatePayload;
use fostercommerce\shipments\Plugin;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/** REST API for shipment status updates. */
class ApiController extends Controller
{
	public $enableCsrfValidation = false;

	protected array|bool|int $allowAnonymous = false;

	/**
	 * PATCH shipment tracking fields + optional status transition.
	 *
	 * @throws BadRequestHttpException
	 * @throws NotFoundHttpException
	 */
	public function actionUpdate(int $id): Response
	{
		$this->requirePostRequest();
		$this->requirePermission(Plugin::PERMISSION_EDIT);

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		$shipment = $plugin->shipments->findById($id, includeTrashed: true);
		if (! $shipment instanceof Shipment) {
			throw new NotFoundHttpException('Shipment not found.');
		}

		$body = $this->request->getBodyParams();

		$payload = new ShipmentUpdatePayload();
		$payload->setAttributes($this->payloadAttributesFromBody($body), true);
		if (! $payload->validate()) {
			return $this->asJson([
				'success' => false,
				'errors' => $payload->getErrors(),
			])->setStatusCode(422);
		}

		if ($payload->targetStatusCode !== null && $payload->targetStatusCode !== $shipment->status) {
			$this->requirePermission(Plugin::PERMISSION_TRANSITION);
		}

		$user = Craft::$app->getUser()->getIdentity();
		$source = $this->resolveSourceIntegration($body['integrationHandle'] ?? null);
		$externalCode = isset($body['externalCode']) && is_string($body['externalCode']) ? $body['externalCode'] : null;

		try {
			$shipment = $plugin->shipments->applyUpdate($shipment, $payload, $user, $source, $externalCode);
		} catch (Throwable $throwable) {
			return $this->asJson([
				'success' => false,
				'error' => $throwable->getMessage(),
			])->setStatusCode(422);
		}

		return $this->asJson([
			'success' => true,
			'shipment' => $this->serializeShipment($shipment),
		]);
	}

	/**
	 * Map the public API body to `ShipmentUpdatePayload` attribute names + pre-parse
	 * `dateScheduledShip` since the DTO property is typed as `?DateTime`.
	 *
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	private function payloadAttributesFromBody(array $body): array
	{
		$mapped = [];
		foreach ($body as $key => $value) {
			$mapped[match ($key) {
				'status' => 'targetStatusCode',
				default => $key,
			}] = $value;
		}

		if (array_key_exists('dateScheduledShip', $mapped)) {
			$raw = $mapped['dateScheduledShip'];
			$mapped['dateScheduledShip'] = is_string($raw) || is_int($raw) || is_array($raw) || $raw instanceof DateTimeInterface
				? DateTimeHelper::toDateTime($raw) ?: null
				: null;
		}

		return $mapped;
	}

	private function resolveSourceIntegration(mixed $handleRaw): ?Integration
	{
		if (! is_string($handleRaw) || $handleRaw === '') {
			return null;
		}

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();
		$integration = $plugin->integrations->getIntegrationByHandle($handleRaw);
		return $integration instanceof Integration ? $integration : null;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function serializeShipment(Shipment $shipment): array
	{
		return [
			'id' => $shipment->id,
			'reference' => $shipment->reference,
			'orderId' => $shipment->orderId,
			'status' => $shipment->status,
			'dateShipped' => $shipment->getDateShipped()?->format('c'),
			'dateScheduledShip' => $shipment->dateScheduledShip?->format('c'),
			'trackingNumber' => $shipment->trackingNumber,
			'trackingUrl' => $shipment->trackingUrl,
			'carrier' => $shipment->carrier,
			'service' => $shipment->service,
			'fulfillmentNotes' => $shipment->fulfillmentNotes,
			'shippingNotes' => $shipment->shippingNotes,
			'enabled' => $shipment->enabled,
			'fields' => $shipment->getSerializedFieldValues(),
		];
	}
}
