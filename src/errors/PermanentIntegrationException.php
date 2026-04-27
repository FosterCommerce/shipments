<?php

declare(strict_types=1);

namespace fostercommerce\shipments\errors;

/**
 * Marker subclass: a hard failure (bad config, unrecoverable 4xx, malformed remote payload)
 * that shouldn't be retried by the queue. `PushShipmentJob` catches this and marks the job
 * permanently failed; plain `IntegrationException` lets Craft retry.
 */
class PermanentIntegrationException extends IntegrationException
{
}
