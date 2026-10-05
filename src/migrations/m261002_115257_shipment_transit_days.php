<?php

declare(strict_types=1);

namespace fostercommerce\shipments\migrations;

use craft\db\Migration;
use fostercommerce\shipments\db\Table;

class m261002_115257_shipment_transit_days extends Migration
{
	public function safeUp(): bool
	{
		$this->addColumn(Table::SHIPMENTS, 'transitDays', (string) $this->integer());
		$this->addColumn(Table::DELIVERIES, 'transitDays', (string) $this->integer());
		return true;
	}
}
