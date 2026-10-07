<?php

declare(strict_types=1);

namespace fostercommerce\shipments\migrations;

use craft\db\Migration;
use fostercommerce\shipments\db\Table;

/**
 * Shipment methods and carrier deliveries.
 */
class m260925_125612_shipment_deliveries extends Migration
{
	public function safeUp(): bool
	{
		collect([
			'shippingMethodHandle' => $this->string(),
			'shippingMethodName' => $this->string(),
			'shippingAmount' => $this->string(64),
			'shippingCurrency' => $this->string(3),
			'shippingSnapshot' => $this->json(),
		])->each(function ($type, string $column): void {
			if (! $this->db->columnExists(Table::SHIPMENTS, $column)) {
				$this->addColumn(Table::SHIPMENTS, $column, (string) $type);
			}
		});
		if (! $this->db->tableExists(Table::DELIVERIES)) {
			$this->createTable(Table::DELIVERIES, [
				'id' => $this->primaryKey(),
				'shipmentId' => $this->integer()->notNull(),
				'integrationId' => $this->integer()->notNull(),
				'operationId' => $this->string(36)->notNull(),
				'status' => $this->string(32)->notNull(),
				'externalId' => $this->string(),
				'carrier' => $this->string()->notNull()->defaultValue(''),
				'service' => $this->string()->notNull()->defaultValue(''),
				'trackingNumber' => $this->string(),
				'trackingUrl' => $this->text(),
				'costAmount' => $this->string(64),
				'costCurrency' => $this->string(3),
				'metadata' => $this->json(),
				'requestData' => $this->json(),
				'references' => $this->json(),
				'documents' => $this->json(),
				'lastError' => $this->text(),
				'createdBy' => $this->integer(),
				'dateNotified' => $this->dateTime(),
				'dateCreated' => $this->dateTime()->notNull(),
				'dateUpdated' => $this->dateTime()->notNull(),
				'uid' => $this->uid(),
			]);
			$this->createIndex(null, Table::DELIVERIES, ['operationId'], true);
			$this->createIndex(null, Table::DELIVERIES, ['shipmentId', 'status']);
			$this->addForeignKey(null, Table::DELIVERIES, ['shipmentId'], Table::SHIPMENTS, ['id'], 'CASCADE');
			$this->addForeignKey(null, Table::DELIVERIES, ['integrationId'], Table::INTEGRATIONS, ['id'], 'RESTRICT');
		}

		return true;
	}
}
