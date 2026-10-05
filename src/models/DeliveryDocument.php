<?php

declare(strict_types=1);

namespace fostercommerce\shipments\models;

use craft\base\Model;
use fostercommerce\shipments\enums\DeliveryDocumentType;

class DeliveryDocument extends Model
{
	public string $filename = '';

	public string $mimeType = 'application/pdf';

	public string $contents = '';

	public ?string $trackingNumber = null;

	public ?int $sequenceNumber = null;

	public DeliveryDocumentType $type = DeliveryDocumentType::OTHER;
}
