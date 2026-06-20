<?php

declare(strict_types=1);

namespace fostercommerce\shipments\migrations;

use craft\db\Migration;
use fostercommerce\shipments\db\Table;
use fostercommerce\shipments\enums\Status;

/**
 * Renames the `open` status to `new`. The deleted `scheduled` and `incomplete` statuses were
 * never used, so their rows (if any) are left untouched rather than remapped.
 */
class m260616_120500_rename_open_status extends Migration
{
	public function safeUp(): bool
	{
		$this->update(Table::SHIPMENTS, [
			'status' => Status::New->value,
		], [
			'status' => 'open',
		]);
		/** @phpstan-ignore-next-line alterColumn not annotated correctly */
		$this->alterColumn(Table::SHIPMENTS, 'status', $this->string(32)->notNull()->defaultValue(Status::New->value));

		$this->update(Table::SHIPMENT_STATUS_HISTORY, [
			'toCode' => Status::New->value,
		], [
			'toCode' => 'open',
		]);
		$this->update(Table::SHIPMENT_STATUS_HISTORY, [
			'fromCode' => Status::New->value,
		], [
			'fromCode' => 'open',
		]);

		$this->update(Table::INTEGRATION_STATUS_MAPS, [
			'internalCode' => Status::New->value,
		], [
			'internalCode' => 'open',
		]);

		$this->update(Table::TRANSITION_EMAILS, [
			'toCode' => Status::New->value,
		], [
			'toCode' => 'open',
		]);

		return true;
	}
}
