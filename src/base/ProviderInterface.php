<?php

declare(strict_types=1);

namespace fostercommerce\shipments\base;

use craft\base\SavableComponentInterface;

/**
 * Marker interface for plugin integrations. Follows Formie's pattern; the concrete contract
 * lives on the `Provider` abstract base class. Custom drivers typically subclass `Provider`
 * and implement `sendShipment()` / `cancelShipment()`, plus optionally `receiveShipmentUpdate()`
 * and `canReceiveUpdates()` when the remote pushes status updates back.
 */
interface ProviderInterface extends SavableComponentInterface
{
}
