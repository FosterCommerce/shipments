<?php

declare(strict_types=1);

namespace fostercommerce\shipments\errors;

use RuntimeException;

/**
 * The shipment items could not be packed into the configured boxes.
 */
class PackingException extends RuntimeException
{
}
