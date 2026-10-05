<?php

declare(strict_types=1);

namespace fostercommerce\shipments\models;

use craft\base\Model;
use Money\Money;

class DeliveryResult extends Model
{
	public string $externalId = '';

	public string $carrier = '';

	public string $service = '';

	public ?string $trackingNumber = null;

	public ?string $trackingUrl = null;

	public ?int $transitDays = null;

	public ?Money $cost = null;

	/**
	 * @var list<DeliveryDocument>
	 */
	public array $documents = [];

	/**
	 * @var list<array{type: string, number: string, url?: string, sequenceNumber?: ?int}>
	 */
	public array $references = [];

	/**
	 * @var array<string, mixed>
	 */
	public array $metadata = [];

	public bool $pending = false;
}
