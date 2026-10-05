<?php

declare(strict_types=1);

namespace fostercommerce\shipments\helpers;

use Craft;
use craft\helpers\Json;
use GuzzleHttp\Exception\RequestException;
use Throwable;

/**
 * Log carrier failures without request bodies, customer details, or credentials.
 */
final class DeliveryDiagnostics
{
	/**
	 * Record exception origins and transport codes for a delivery operation.
	 */
	public static function log(Throwable $exception, string $stage, string $operationId): void
	{
		$causes = [];
		do {
			// Exception messages and trace arguments can contain authenticated URLs or payloads.
			$causes[] = [
				'type' => $exception::class,
				'code' => $exception->getCode(),
				'file' => $exception->getFile(),
				'line' => $exception->getLine(),
				'httpStatus' => $exception instanceof RequestException ? $exception->getResponse()?->getStatusCode() : null,
				'transportCode' => preg_match('/(?:SQLSTATE\[[A-Z0-9]+\]|cURL error \d+)/', $exception->getMessage(), $matches) ? $matches[0] : null,
				'trace' => collect($exception->getTrace())->map(static fn (array $frame): array => collect($frame)->only(['file', 'line', 'class', 'function'])->all())->all(),
			];
			$exception = $exception->getPrevious();
		} while ($exception instanceof Throwable);

		Craft::error(Json::encode([
			'operationId' => $operationId,
			'stage' => $stage,
			'causes' => $causes,
		]), 'shipments.deliveries');
	}
}
