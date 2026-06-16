<?php

declare(strict_types=1);

namespace fostercommerce\shipments\models;

use Craft;
use craft\base\Model;
use craft\helpers\DateTimeHelper;
use DateTime;
use DateTimeInterface;
use fostercommerce\shipments\enums\Status;
use fostercommerce\shipments\Plugin;

/**
 * Parsed update for `Shipments::applyUpdate()`. All fields optional; null = don't touch.
 * `targetStatusCode` drives an `applyTransition` call.
 */
class ShipmentUpdatePayload extends Model
{
	public ?string $trackingNumber = null;

	public ?string $trackingUrl = null;

	public ?string $carrier = null;

	public ?string $service = null;

	/**
	 * Accepts a `DateTime`, ISO-8601 string, Unix timestamp, or Craft date-array form.
	 * The validator normalizes it to `DateTime|null` so consumers can rely on `instanceof DateTime`.
	 *
	 * @var DateTime|string|int|array<string, mixed>|null
	 */
	public DateTime|string|int|array|null $dateScheduledShip = null;

	public ?string $fulfillmentNotes = null;

	public ?string $shippingNotes = null;

	/**
	 * Target Status enum value. Unknown values log + skip, they don't throw.
	 */
	public ?string $targetStatusCode = null;

	/**
	 * Stored on the `ShipmentStatusHistory` row when the status transitions.
	 */
	public ?string $statusMessage = null;

	/**
	 * Custom-field values keyed by field handle. Routed through `Element::setFieldValues`.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $fields = null;

	public function normalizeDateScheduledShip(string $attribute): void
	{
		$value = $this->{$attribute};
		if ($value === null || $value instanceof DateTime) {
			return;
		}

		if (is_string($value) || is_int($value) || is_array($value) || $value instanceof DateTimeInterface) {
			$parsed = DateTimeHelper::toDateTime($value);
			if ($parsed instanceof DateTime) {
				$this->{$attribute} = $parsed;
				return;
			}
		}

		$this->addError($attribute, Craft::t(Plugin::HANDLE, 'error.valueNotValidDate', [
			'value' => is_scalar($value) ? (string) $value : 'value',
		]));
		$this->{$attribute} = null;
	}

	/**
	 * @return array<array-key, mixed>
	 */
	protected function defineRules(): array
	{
		return [
			[['trackingNumber', 'trackingUrl', 'carrier', 'service'],
				'string',
				'max' => 255],
			[['trackingUrl'],
				'url',
				'defaultScheme' => 'https'],
			[['fulfillmentNotes', 'shippingNotes', 'statusMessage'], 'string'],
			[['targetStatusCode'],
				'in',
				'range' => array_map(static fn (Status $case): string => $case->value, Status::cases())],
			[['dateScheduledShip'], 'normalizeDateScheduledShip'],
			[['fields'], 'safe'],
		];
	}
}
