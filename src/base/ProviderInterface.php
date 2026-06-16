<?php

declare(strict_types=1);

namespace fostercommerce\shipments\base;

use craft\base\SavableComponentInterface;

/**
 * Marker interface for plugin integrations.
 *
 * The concrete contract lives on the `Provider` abstract base class, which drivers subclass.
 */
interface ProviderInterface extends SavableComponentInterface
{
}
