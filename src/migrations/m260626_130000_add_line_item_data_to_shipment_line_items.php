<?php

declare(strict_types=1);

namespace fostercommerce\shipments\migrations;

use craft\db\Migration;
use fostercommerce\shipments\db\Table;

class m260626_130000_add_line_item_data_to_shipment_line_items extends Migration
{
	public function safeUp(): bool
	{
		// @phpstan-ignore-next-line The addColumn() annotation is wrong; addColumn accepts ColumnSchemaBuilder instances.
		$this->addColumn(Table::SHIPMENT_LINE_ITEMS, 'lineItemData', $this->json()->after('qty'));

		return true;
	}
}
