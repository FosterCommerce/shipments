<?php

declare(strict_types=1);

namespace fostercommerce\shipments\models;

use craft\base\Model;
use fostercommerce\shipments\elements\Shipment;

/** Page of shipments + pagination metadata from `Shipments::findForExport()`. */
class ShipmentExportResult extends Model
{
	/**
	 * @var list<Shipment>
	 */
	public array $shipments = [];

	public int $page = 1;

	public int $pageCount = 1;

	public int $total = 0;

	public int $pageSize = ShipmentExportQuery::DEFAULT_PAGE_SIZE;
}
