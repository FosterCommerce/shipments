<?php

declare(strict_types=1);

namespace fostercommerce\shipments\events;

use fostercommerce\shipments\base\ProviderInterface;
use yii\base\Event;

/** Before/after a provider `checkConnection()`. Pre-handlers can set `$isValid = false` to cancel the probe. */
class IntegrationConnectionEvent extends Event
{
	public ProviderInterface $integration;

	public bool $success = false;

	public bool $isValid = true;
}
