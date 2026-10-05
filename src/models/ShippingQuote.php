<?php

declare(strict_types=1);

namespace fostercommerce\shipments\models;

use Craft;
use craft\base\Model;
use craft\helpers\MoneyHelper;
use DateTimeImmutable;
use fostercommerce\shipments\helpers\MoneyValues;
use InvalidArgumentException;
use Money\Money;

class ShippingQuote extends Model
{
	public string $handle = '';

	public string $name = '';

	public ?string $providerUid = null;

	public ?string $providerHandle = null;

	public ?string $serviceCode = null;

	public ?int $transitDays = null;

	public Money $amount;

	public ?Money $carrierCost = null;

	public ?Money $previousCarrierCost = null;

	/**
	 * @var array<string, mixed>
	 */
	public array $metadata = [];

	public string $fingerprint = '';

	public ?DateTimeImmutable $expiresAt = null;

	/**
	 * Serialize the quote without converting its money amounts to floats.
	 *
	 * @return array<string, mixed>
	 */
	public function toSnapshot(): array
	{
		return [
			'handle' => $this->handle,
			'name' => $this->name,
			'providerUid' => $this->providerUid,
			'providerHandle' => $this->providerHandle,
			'serviceCode' => $this->serviceCode,
			'transitDays' => $this->transitDays,
			'amount' => MoneyValues::serialize($this->amount),
			'carrierCost' => $this->carrierCost instanceof Money ? MoneyValues::serialize($this->carrierCost) : null,
			'previousCarrierCost' => $this->previousCarrierCost instanceof Money ? MoneyValues::serialize($this->previousCarrierCost) : null,
			'metadata' => $this->metadata,
			'fingerprint' => $this->fingerprint,
			'expiresAt' => $this->expiresAt?->format(DATE_ATOM),
		];
	}

	/**
	 * Restore a saved quote and its exact monetary values.
	 *
	 * @param array<string, mixed> $snapshot
	 * @throws InvalidArgumentException When the saved quote is invalid.
	 */
	public static function fromSnapshot(array $snapshot): self
	{
		$amount = $snapshot['amount'] ?? null;
		$cost = $snapshot['carrierCost'] ?? null;
		$previousCost = $snapshot['previousCarrierCost'] ?? null;
		$expiresAt = $snapshot['expiresAt'] ?? null;
		if (! is_array($amount) || ($cost !== null && ! is_array($cost)) || ($previousCost !== null && ! is_array($previousCost)) || ($expiresAt !== null && ! is_string($expiresAt))) {
			throw new InvalidArgumentException(Craft::t('shipments', 'delivery.errors.savedShipmentQuoteIsInvalidDetails'));
		}

		$snapshot['amount'] = MoneyValues::restore($amount);
		$snapshot['carrierCost'] = $cost === null ? null : MoneyValues::restore($cost);
		$snapshot['previousCarrierCost'] = $previousCost === null ? null : MoneyValues::restore($previousCost);
		$snapshot['expiresAt'] = $expiresAt !== null && $expiresAt !== '' ? new DateTimeImmutable($expiresAt) : null;
		return new self($snapshot);
	}

	/**
	 * Format the calculated shipping amount using the control-panel locale.
	 */
	public function getFormattedAmount(): string
	{
		return (string) MoneyHelper::toString($this->amount);
	}

	public function getFormattedPreviousCarrierCost(): ?string
	{
		return $this->previousCarrierCost instanceof Money ? (string) MoneyHelper::toString($this->previousCarrierCost) : null;
	}
}
