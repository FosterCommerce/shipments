<?php

declare(strict_types=1);

namespace fostercommerce\shipments\models;

use craft\base\Model;
use craft\helpers\DateTimeHelper;
use craft\web\Request;
use DateTime;
use DateTimeInterface;

/**
 * Query shape for `ShipmentExports::findForExport()`. Populate via `fromRequest()` or set fields directly.
 */
class ShipmentExportQuery extends Model
{
	public const DEFAULT_PAGE_SIZE = 100;

	public const MAX_PAGE_SIZE = 500;

	/**
	 * Inclusive lower bound on `shipments.dateUpdated`.
	 */
	public ?DateTime $startDate = null;

	/**
	 * Inclusive upper bound on `shipments.dateUpdated`.
	 */
	public ?DateTime $endDate = null;

	public int $page = 1;

	/**
	 * Clamped to `MAX_PAGE_SIZE` in the service.
	 */
	public int $pageSize = self::DEFAULT_PAGE_SIZE;

	public ?string $statusHandle = null;

	public ?int $storeId = null;

	/**
	 * Reads `start_date` / `end_date` (snake_case) and `page` / `pageSize` / `status` / `storeId` (camelCase).
	 */
	public static function fromRequest(Request $request): self
	{
		$exportQuery = new self();

		$startDateRaw = $request->getQueryParam('start_date');
		if (is_string($startDateRaw) || is_int($startDateRaw) || is_array($startDateRaw) || $startDateRaw instanceof DateTimeInterface) {
			$exportQuery->startDate = DateTimeHelper::toDateTime($startDateRaw) ?: null;
		}

		$endDateRaw = $request->getQueryParam('end_date');
		if (is_string($endDateRaw) || is_int($endDateRaw) || is_array($endDateRaw) || $endDateRaw instanceof DateTimeInterface) {
			$exportQuery->endDate = DateTimeHelper::toDateTime($endDateRaw) ?: null;
		}

		$pageRaw = $request->getQueryParam('page');
		if (is_numeric($pageRaw) && (int) $pageRaw >= 1) {
			$exportQuery->page = (int) $pageRaw;
		}

		$pageSizeRaw = $request->getQueryParam('pageSize');
		if (is_numeric($pageSizeRaw) && (int) $pageSizeRaw >= 1) {
			$exportQuery->pageSize = (int) $pageSizeRaw;
		}

		$statusHandleRaw = $request->getQueryParam('status');
		if (is_string($statusHandleRaw) && $statusHandleRaw !== '') {
			$exportQuery->statusHandle = $statusHandleRaw;
		}

		$storeIdRaw = $request->getQueryParam('storeId');
		if (is_numeric($storeIdRaw) && (int) $storeIdRaw > 0) {
			$exportQuery->storeId = (int) $storeIdRaw;
		}

		return $exportQuery;
	}

	/**
	 * @return array<array-key, mixed>
	 */
	protected function defineRules(): array
	{
		return [
			[['page', 'pageSize'],
				'integer',
				'min' => 1],
			[['pageSize'],
				'integer',
				'max' => self::MAX_PAGE_SIZE],
			[['startDate', 'endDate', 'statusHandle', 'storeId'], 'safe'],
		];
	}
}
