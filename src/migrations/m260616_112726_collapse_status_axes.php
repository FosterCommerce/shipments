<?php

declare(strict_types=1);

namespace fostercommerce\shipments\migrations;

use craft\db\Migration;
use fostercommerce\shipments\db\Table;
use fostercommerce\shipments\enums\Status;

/**
 * Collapses the two status axes (fulfillment + shipping) into one `status` column and removes
 * the carrier-events subsystem. Shipping-axis history, status maps, and email triggers are deleted.
 */
class m260616_112726_collapse_status_axes extends Migration
{
	public function safeUp(): bool
	{
		// shipments: fulfillmentStatus -> status; drop the shipping columns.
		$this->dropIndexIfExists(Table::SHIPMENTS, ['fulfillmentStatus'], false);
		$this->renameColumn(Table::SHIPMENTS, 'fulfillmentStatus', 'status');
		$this->update(Table::SHIPMENTS, [
			'status' => Status::Shipped->value,
		], [
			'status' => 'fulfilled',
		]);
		$this->createIndex(null, Table::SHIPMENTS, ['status'], false);

		$this->dropIndexIfExists(Table::SHIPMENTS, ['shippingStatus'], false);
		$this->dropColumn(Table::SHIPMENTS, 'shippingStatus');
		$this->dropColumn(Table::SHIPMENTS, 'dateShippingStatus');

		// shipment_status_history: keep fulfillment rows (remap fulfilled -> shipped), drop shipping rows.
		$this->update(Table::SHIPMENT_STATUS_HISTORY, [
			'toCode' => Status::Shipped->value,
		], [
			'axis' => 'fulfillment',
			'toCode' => 'fulfilled',
		]);
		$this->update(Table::SHIPMENT_STATUS_HISTORY, [
			'fromCode' => Status::Shipped->value,
		], [
			'axis' => 'fulfillment',
			'fromCode' => 'fulfilled',
		]);
		$this->delete(Table::SHIPMENT_STATUS_HISTORY, [
			'axis' => 'shipping',
		]);
		$this->dropIndexIfExists(Table::SHIPMENT_STATUS_HISTORY, ['shipmentId', 'axis'], false);
		$this->dropColumn(Table::SHIPMENT_STATUS_HISTORY, 'axis');

		// integration_status_maps: drop shipping mappings, remap fulfillment, rebuild keys without axis.
		$this->delete(Table::INTEGRATION_STATUS_MAPS, [
			'axis' => 'shipping',
		]);
		$this->update(Table::INTEGRATION_STATUS_MAPS, [
			'internalCode' => Status::Shipped->value,
		], [
			'axis' => 'fulfillment',
			'internalCode' => 'fulfilled',
		]);
		$this->dropIndexIfExists(Table::INTEGRATION_STATUS_MAPS, ['integrationId', 'axis', 'direction', 'externalCode'], true);
		$this->dropIndexIfExists(Table::INTEGRATION_STATUS_MAPS, ['integrationId', 'axis', 'internalCode'], false);
		$this->dropColumn(Table::INTEGRATION_STATUS_MAPS, 'axis');
		$this->createIndex(null, Table::INTEGRATION_STATUS_MAPS, ['integrationId', 'direction', 'externalCode'], true);
		$this->createIndex(null, Table::INTEGRATION_STATUS_MAPS, ['integrationId', 'internalCode'], false);

		// transition_emails: drop shipping triggers, remap fulfillment, rebuild key without axis.
		$this->delete(Table::TRANSITION_EMAILS, [
			'axis' => 'shipping',
		]);
		$this->update(Table::TRANSITION_EMAILS, [
			'toCode' => Status::Shipped->value,
		], [
			'axis' => 'fulfillment',
			'toCode' => 'fulfilled',
		]);
		$this->dropIndexIfExists(Table::TRANSITION_EMAILS, ['axis', 'toCode', 'emailId'], true);
		$this->dropColumn(Table::TRANSITION_EMAILS, 'axis');
		$this->createIndex(null, Table::TRANSITION_EMAILS, ['toCode', 'emailId'], true);

		// carrier_events: gone with the shipping axis.
		$this->dropTableIfExists(Table::CARRIER_EVENTS);

		return true;
	}
}
