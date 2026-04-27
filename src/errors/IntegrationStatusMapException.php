<?php

declare(strict_types=1);

namespace fostercommerce\shipments\errors;

use yii\base\UserException;

/** Thrown when an integration status mapping row can't be persisted. */
class IntegrationStatusMapException extends UserException
{
}
