<?php

declare(strict_types=1);

namespace fostercommerce\shipments\migrations;

use craft\db\Migration;
use fostercommerce\shipments\db\Table;

class m261002_115257_shipment_transit_days extends Migration
{
	public function safeUp(): bool
	{
		// A database restored without its migration rows can already have the columns
		foreach ([Table::SHIPMENTS, Table::DELIVERIES] as $table) {
			if (! $this->db->columnExists($table, 'transitDays')) {
				$this->addColumn($table, 'transitDays', (string) $this->integer());
			}
		}

		return true;
	}
}
