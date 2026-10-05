<?php

declare(strict_types=1);

namespace fostercommerce\shipments\errors;

use RuntimeException;

/**
 * The provider confirmed that no delivery was purchased.
 */
class DeliveryRejectedException extends RuntimeException
{
}
