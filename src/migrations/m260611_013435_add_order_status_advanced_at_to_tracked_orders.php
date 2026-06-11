<?php

declare(strict_types=1);

namespace fostercommerce\shipments\migrations;

use craft\db\Migration;
use fostercommerce\shipments\db\Table;
use yii\db\Schema;

/**
 * Install.php carries this column for fresh installs; this brings existing installs forward.
 */
class m260611_013435_add_order_status_advanced_at_to_tracked_orders extends Migration
{
	public function safeUp(): bool
	{
		$this->addColumn(
			Table::TRACKED_ORDERS,
			'orderStatusAdvancedAt',
			Schema::TYPE_DATETIME,
		);

		return true;
	}
}
