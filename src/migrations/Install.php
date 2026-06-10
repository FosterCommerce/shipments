<?php

declare(strict_types=1);

namespace fostercommerce\shipments\migrations;

use craft\commerce\db\Table as CommerceTable;
use craft\db\Migration;
use craft\db\Table as CraftTable;
use fostercommerce\shipments\db\Table;
use fostercommerce\shipments\enums\FulfillmentStatus;

/**
 * Cumulative install; reflects the full plugin schema. No incremental migrations;
 * this file is the single source of truth for the DB layout.
 *
 * Status model: two fixed-vocabulary axes (FulfillmentStatus, ShippingStatus) stored
 * as string codes on the shipment element's supplementary row. No user-editable
 * statuses table. Integration-specific external codes map into our vocabularies via
 * `shipments_integration_status_maps`.
 */
class Install extends Migration
{
	public function safeUp(): bool
	{
		$this->archiveTableIfExists(Table::TRACKED_ORDERS);
		$this->archiveTableIfExists(Table::CARRIER_EVENTS);
		$this->archiveTableIfExists(Table::INTEGRATION_STATUS_MAPS);
		$this->archiveTableIfExists(Table::INTEGRATION_REFERENCES);
		$this->archiveTableIfExists(Table::INTEGRATIONS);
		$this->archiveTableIfExists(Table::SHIPMENT_STATUS_HISTORY);
		$this->archiveTableIfExists(Table::TRANSITION_EMAILS);
		$this->archiveTableIfExists(Table::EMAILS);
		$this->archiveTableIfExists(Table::SHIPMENT_LINE_ITEMS);
		$this->archiveTableIfExists(Table::SHIPMENTS);

		$this->createTable(Table::EMAILS, [
			'id' => $this->primaryKey(),
			'name' => $this->string()->notNull(),
			'subject' => $this->string()->notNull(),
			'recipientType' => $this->string(20)->notNull()->defaultValue('customer'),
			'to' => $this->string(),
			'bcc' => $this->string(),
			'cc' => $this->string(),
			'replyTo' => $this->string(),
			'enabled' => $this->boolean()->notNull()->defaultValue(true),
			'templatePath' => $this->string()->notNull(),
			'plainTextTemplatePath' => $this->string(),
			'language' => $this->string(50)->notNull()->defaultValue('orderLanguage'),
			'dateCreated' => $this->dateTime()->notNull(),
			'dateUpdated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
		]);

		// Supplementary table keyed to the Craft `elements` row (id is the element id).
		// The element row owns dateCreated/dateUpdated/uid/dateDeleted/enabled/archived.
		$this->createTable(Table::SHIPMENTS, [
			'id' => $this->integer()->notNull(),
			'orderId' => $this->integer()->notNull(),
			'reference' => $this->string()->notNull(),
			'number' => $this->integer()->notNull(),
			'fulfillmentStatus' => $this->string(32)->notNull()->defaultValue(FulfillmentStatus::Open->value),
			'shippingStatus' => $this->string(32),
			'dateShippingStatus' => $this->dateTime(),
			'dateScheduledShip' => $this->dateTime(),
			'trackingNumber' => $this->string(),
			'trackingUrl' => $this->string(),
			'carrier' => $this->string(),
			'service' => $this->string(),
			'fulfillmentNotes' => $this->text(),
			'shippingNotes' => $this->text(),
			'dateLastPushAttempt' => $this->dateTime(),
			'lastPushAttemptError' => $this->text(),
			'pushAttemptCount' => $this->smallInteger()->notNull()->defaultValue(0),
			'PRIMARY KEY([[id]])',
		]);

		$this->createTable(Table::SHIPMENT_LINE_ITEMS, [
			'id' => $this->primaryKey(),
			'shipmentId' => $this->integer()->notNull(),
			'lineItemId' => $this->integer()->notNull(),
			'qty' => $this->integer()->notNull(),
			'dateCreated' => $this->dateTime()->notNull(),
			'dateUpdated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
		]);

		$this->createTable(Table::TRANSITION_EMAILS, [
			'id' => $this->primaryKey(),
			'axis' => $this->string(16)->notNull(),
			'toCode' => $this->string(32)->notNull(),
			'emailId' => $this->integer()->notNull(),
			'dateCreated' => $this->dateTime()->notNull(),
			'dateUpdated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
		]);

		$this->createTable(Table::INTEGRATIONS, [
			'id' => $this->primaryKey(),
			'name' => $this->string()->notNull(),
			'handle' => $this->string()->notNull(),
			'urlTemplate' => $this->string(),
			'provider' => $this->string(),
			'settings' => $this->text(),
			'enabled' => $this->boolean()->notNull()->defaultValue(true),
			'sortOrder' => $this->integer()->notNull()->defaultValue(0),
			'dateCreated' => $this->dateTime()->notNull(),
			'dateUpdated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
		]);

		$this->createTable(Table::INTEGRATION_REFERENCES, [
			'id' => $this->primaryKey(),
			'shipmentId' => $this->integer()->notNull(),
			'integrationId' => $this->integer()->notNull(),
			'externalId' => $this->string()->notNull(),
			'url' => $this->string(),
			'dateCreated' => $this->dateTime()->notNull(),
			'dateUpdated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
		]);

		$this->createTable(Table::INTEGRATION_STATUS_MAPS, [
			'id' => $this->primaryKey(),
			'integrationId' => $this->integer()->notNull(),
			'axis' => $this->string(16)->notNull(),
			'direction' => $this->string(16)->notNull()->defaultValue('inbound'),
			'externalCode' => $this->string(128)->notNull(),
			'externalLabel' => $this->string(255),
			'internalCode' => $this->string(32)->notNull(),
			'dateCreated' => $this->dateTime()->notNull(),
			'dateUpdated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
		]);

		$this->createTable(Table::SHIPMENT_STATUS_HISTORY, [
			'id' => $this->primaryKey(),
			'shipmentId' => $this->integer()->notNull(),
			'axis' => $this->string(16)->notNull(),
			'fromCode' => $this->string(32),
			'toCode' => $this->string(32)->notNull(),
			'message' => $this->text(),
			'userId' => $this->integer(),
			'sourceIntegrationId' => $this->integer(),
			'sourceExternalCode' => $this->string(128),
			'dateCreated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
		]);

		$this->createTable(Table::CARRIER_EVENTS, [
			'id' => $this->primaryKey(),
			'shipmentId' => $this->integer()->notNull(),
			'integrationId' => $this->integer(),
			'code' => $this->string(64)->notNull(),
			'description' => $this->string(512),
			'dateOccurred' => $this->dateTime()->notNull(),
			'receivedAt' => $this->dateTime()->notNull(),
			'locationCity' => $this->string(128),
			'locationRegion' => $this->string(64),
			'locationCountry' => $this->char(2),
			'rawPayload' => $this->text(),
			'eventHash' => $this->char(64)->notNull(),
			'reason' => $this->string(40)->notNull()->defaultValue('projected'),
			'dateCreated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
		]);

		$this->createIndex(null, Table::SHIPMENTS, ['reference'], true);
		$this->createIndex(null, Table::SHIPMENTS, ['orderId', 'number'], true);
		$this->createIndex(null, Table::SHIPMENTS, ['orderId'], false);
		$this->createIndex(null, Table::SHIPMENTS, ['fulfillmentStatus'], false);
		$this->createIndex(null, Table::SHIPMENTS, ['shippingStatus'], false);

		$this->createIndex(null, Table::INTEGRATIONS, ['handle'], true);

		$this->createIndex(null, Table::INTEGRATION_REFERENCES, ['shipmentId', 'integrationId'], true);
		$this->createIndex(null, Table::INTEGRATION_REFERENCES, ['integrationId', 'externalId'], true);
		$this->createIndex(null, Table::INTEGRATION_REFERENCES, ['shipmentId'], false);

		$this->createIndex(null, Table::INTEGRATION_STATUS_MAPS, ['integrationId', 'axis', 'direction', 'externalCode'], true);
		$this->createIndex(null, Table::INTEGRATION_STATUS_MAPS, ['integrationId', 'axis', 'internalCode'], false);

		$this->createIndex(null, Table::SHIPMENT_LINE_ITEMS, ['shipmentId', 'lineItemId'], true);
		$this->createIndex(null, Table::SHIPMENT_LINE_ITEMS, ['lineItemId'], false);

		$this->createIndex(null, Table::TRANSITION_EMAILS, ['axis', 'toCode', 'emailId'], true);
		$this->createIndex(null, Table::TRANSITION_EMAILS, ['emailId'], false);

		$this->createIndex(null, Table::SHIPMENT_STATUS_HISTORY, ['shipmentId'], false);
		$this->createIndex(null, Table::SHIPMENT_STATUS_HISTORY, ['shipmentId', 'axis'], false);

		$this->createIndex(null, Table::CARRIER_EVENTS, ['shipmentId', 'dateOccurred'], false);
		$this->createIndex(null, Table::CARRIER_EVENTS, ['eventHash'], true);

		// Which completed orders the plugin is actively watching. An order gets a row here
		// when the rules engine runs for it, or when an admin flips the "Order requires
		// shipping" switch on. Orders without a row are invisible to the Attention page,
		// which is how historical (pre-install) orders stop flooding it.
		$this->createTable(Table::TRACKED_ORDERS, [
			'id' => $this->primaryKey(),
			'orderId' => $this->integer()->notNull(),
			'shippable' => $this->string(16)->notNull()->defaultValue('unknown'),
			'state' => $this->string(16)->notNull()->defaultValue('active'),
			'underAllocated' => $this->string(16)->notNull()->defaultValue('no'),
			'evaluatedAt' => $this->dateTime(),
			'trackedAt' => $this->dateTime()->notNull(),
			'dateCreated' => $this->dateTime()->notNull(),
			'dateUpdated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
		]);

		$this->createIndex(null, Table::TRACKED_ORDERS, ['orderId'], true);
		$this->createIndex(null, Table::TRACKED_ORDERS, ['state', 'shippable', 'underAllocated'], false);

		$this->addForeignKey(null, Table::SHIPMENTS, ['id'], CraftTable::ELEMENTS, ['id'], 'CASCADE');
		$this->addForeignKey(null, Table::SHIPMENTS, ['orderId'], CommerceTable::ORDERS, ['id'], 'CASCADE');
		$this->addForeignKey(null, Table::SHIPMENT_LINE_ITEMS, ['shipmentId'], Table::SHIPMENTS, ['id'], 'CASCADE');
		$this->addForeignKey(null, Table::SHIPMENT_LINE_ITEMS, ['lineItemId'], CommerceTable::LINEITEMS, ['id'], 'CASCADE');
		$this->addForeignKey(null, Table::TRANSITION_EMAILS, ['emailId'], Table::EMAILS, ['id'], 'CASCADE');
		$this->addForeignKey(null, Table::SHIPMENT_STATUS_HISTORY, ['shipmentId'], Table::SHIPMENTS, ['id'], 'CASCADE');
		$this->addForeignKey(null, Table::SHIPMENT_STATUS_HISTORY, ['userId'], '{{%users}}', ['id'], 'SET NULL');
		$this->addForeignKey(null, Table::SHIPMENT_STATUS_HISTORY, ['sourceIntegrationId'], Table::INTEGRATIONS, ['id'], 'SET NULL');
		$this->addForeignKey(null, Table::INTEGRATION_REFERENCES, ['shipmentId'], Table::SHIPMENTS, ['id'], 'CASCADE');
		$this->addForeignKey(null, Table::INTEGRATION_REFERENCES, ['integrationId'], Table::INTEGRATIONS, ['id'], 'CASCADE');
		$this->addForeignKey(null, Table::INTEGRATION_STATUS_MAPS, ['integrationId'], Table::INTEGRATIONS, ['id'], 'CASCADE');
		$this->addForeignKey(null, Table::CARRIER_EVENTS, ['shipmentId'], Table::SHIPMENTS, ['id'], 'CASCADE');
		$this->addForeignKey(null, Table::CARRIER_EVENTS, ['integrationId'], Table::INTEGRATIONS, ['id'], 'SET NULL');
		$this->addForeignKey(null, Table::TRACKED_ORDERS, ['orderId'], CommerceTable::ORDERS, ['id'], 'CASCADE');

		return true;
	}

	public function safeDown(): bool
	{
		// Drop in reverse FK-dependency order: any table with inbound foreign keys must be
		// dropped after every table that points at it.
		$this->dropTableIfExists(Table::TRACKED_ORDERS);
		$this->dropTableIfExists(Table::CARRIER_EVENTS);
		$this->dropTableIfExists(Table::INTEGRATION_STATUS_MAPS);
		$this->dropTableIfExists(Table::INTEGRATION_REFERENCES);
		$this->dropTableIfExists(Table::SHIPMENT_STATUS_HISTORY);
		$this->dropTableIfExists(Table::TRANSITION_EMAILS);
		$this->dropTableIfExists(Table::SHIPMENT_LINE_ITEMS);
		$this->dropTableIfExists(Table::INTEGRATIONS);
		$this->dropTableIfExists(Table::SHIPMENTS);
		$this->dropTableIfExists(Table::EMAILS);

		return true;
	}
}
