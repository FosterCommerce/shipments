<?php

declare(strict_types=1);

namespace fostercommerce\shipments\base;

use craft\base\SavableComponentInterface;
use craft\web\Request;
use craft\web\Response;

/**
 * Contract for fulfillment integration providers.
 */
interface ProviderInterface extends SavableComponentInterface
{
	public function supportsPush(): bool;

	public function handleGatewayRequest(Request $request): Response;
}
