<?php

declare(strict_types=1);

namespace fostercommerce\shipments\errors;

use Craft;
use fostercommerce\shipments\Plugin;
use yii\base\Exception;

/**
 * Thrown by integration drivers for any domain error (failed push, failed webhook auth,
 * remote API error, bad configuration). Queue jobs catch this to mark themselves retryable or
 * permanently failed; webhook controllers catch it to return a 400.
 */
class IntegrationException extends Exception
{
	public function getName(): string
	{
		return Craft::t(Plugin::HANDLE, 'error.integrationError');
	}
}
