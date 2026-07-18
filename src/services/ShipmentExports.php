<?php

declare(strict_types=1);

namespace fostercommerce\shipments\services;

use craft\commerce\db\Table as CommerceTable;
use craft\db\Query;
use DateTime;
use DateTimeZone;
use fostercommerce\shipments\elements\db\ShipmentQuery;
use fostercommerce\shipments\elements\Shipment;
use fostercommerce\shipments\models\ShipmentExportQuery;
use fostercommerce\shipments\models\ShipmentExportResult;
use yii\base\Component;

/**
 * Paginated shipment export queries.
 */
class ShipmentExports extends Component
{
	/**
	 * Returns a paginated page of non-trashed shipments within the query's `dateUpdated` range.
	 */
	public function findForExport(ShipmentExportQuery $exportQuery): ShipmentExportResult
	{
		$pageSize = max(1, min($exportQuery->pageSize, ShipmentExportQuery::MAX_PAGE_SIZE));
		$page = max(1, $exportQuery->page);

		/** @var ShipmentQuery $query */
		$query = Shipment::find();
		$query->orderBy([
			'[[elements.dateUpdated]]' => SORT_ASC,
			'[[elements.id]]' => SORT_ASC,
		]);

		// `dateUpdated` is stored UTC; normalize bounds to UTC so a caller passing a non-UTC
		// offset (e.g. `2026-04-25T00:00:00-05:00`) doesn't shift the window by their offset.
		$utc = new DateTimeZone('UTC');

		if ($exportQuery->startDate instanceof DateTime) {
			$startUtc = (clone $exportQuery->startDate)->setTimezone($utc);
			$query->dateUpdated('>=' . $startUtc->format('Y-m-d H:i:s'));
		}

		if ($exportQuery->endDate instanceof DateTime) {
			$endUtc = (clone $exportQuery->endDate)->setTimezone($utc);
			$query->andWhere(['<=', '[[elements.dateUpdated]]', $endUtc->format('Y-m-d H:i:s')]);
		}

		if ($exportQuery->statusHandle !== null && $exportQuery->statusHandle !== '') {
			$query->status($exportQuery->statusHandle);
		}

		if ($exportQuery->storeId !== null) {
			$orderIdsInStore = (new Query())
				->select(['id'])
				->from(CommerceTable::ORDERS)
				->where([
					'storeId' => $exportQuery->storeId,
				]);
			$query->orderId($orderIdsInStore);
		}

		$total = (int) (clone $query)->count();
		$pageCount = $total > 0 ? (int) ceil($total / $pageSize) : 0;

		if ($pageCount > 0) {
			$page = min($page, $pageCount);
		}

		/** @var list<Shipment> $shipments */
		$shipments = (clone $query)
			->limit($pageSize)
			->offset(($page - 1) * $pageSize)
			->all();

		$result = new ShipmentExportResult();
		$result->shipments = $shipments;
		$result->page = $page;
		$result->pageCount = $pageCount;
		$result->total = $total;
		$result->pageSize = $pageSize;
		return $result;
	}
}
