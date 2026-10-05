<?php

declare(strict_types=1);

namespace fostercommerce\shipments\helpers;

final class DeliveryResponse
{
	/**
	 * @param array<array-key, mixed> $response
	 * @return array<array-key, mixed>
	 */
	public static function withoutPdfContents(array $response): array
	{
		return collect($response)->except('encodedLabel')->map(static fn (mixed $value): mixed => is_array($value) ? self::withoutPdfContents($value) : $value)->all();
	}
}
