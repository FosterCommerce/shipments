<?php

declare(strict_types=1);

namespace fostercommerce\shipments\events;

use yii\base\Event;

/** Handlers push provider FQCNs onto `$types`. */
class RegisterIntegrationsEvent extends Event
{
	/**
	 * @var list<class-string>
	 */
	public array $types = [];
}
