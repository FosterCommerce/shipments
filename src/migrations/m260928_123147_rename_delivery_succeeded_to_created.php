<?php

declare(strict_types=1);

namespace fostercommerce\shipments\migrations;

use craft\db\Migration;
use fostercommerce\shipments\db\Table;

class m260928_123147_rename_delivery_succeeded_to_created extends Migration
{
	public function safeUp(): bool
	{
		$this->update(Table::DELIVERIES, [
			'status' => 'created',
		], [
			'status' => 'succeeded',
		]);
		return true;
	}
}
