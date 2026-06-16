<?php

declare(strict_types=1);

namespace fostercommerce\shipments\errors;

/**
 * An integration failure that must not be retried by the queue (bad config, unrecoverable 4xx, malformed payload).
 */
class PermanentIntegrationException extends IntegrationException
{
}
