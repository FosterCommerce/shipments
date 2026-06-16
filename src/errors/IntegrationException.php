<?php

declare(strict_types=1);

namespace fostercommerce\shipments\errors;

use Craft;
use fostercommerce\shipments\Plugin;
use yii\base\Exception;

/**
 * Thrown by integration drivers for any domain error (failed push, webhook auth, remote API, bad config).
 */
class IntegrationException extends Exception
{
	public function getName(): string
	{
		return Craft::t(Plugin::HANDLE, 'error.integrationError');
	}
}
