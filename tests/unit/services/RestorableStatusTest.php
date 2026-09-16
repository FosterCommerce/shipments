<?php

declare(strict_types=1);

namespace fostercommerce\shipments\tests\unit\services;

use fostercommerce\shipments\enums\Status;
use fostercommerce\shipments\services\Shipments;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class RestorableStatusTest extends TestCase
{
	public function testRestoresTheStatusHeldBeforeTheOrderCancellation(): void
	{
		self::assertSame(Status::InProgress, $this->restorableStatus(
			$this->historyRow(fromCode: Status::InProgress->value),
		));
	}

	public function testShipmentCreatedCancelledHasNothingToRestore(): void
	{
		self::assertNull($this->restorableStatus(
			$this->historyRow(fromCode: null),
		));
	}

	public function testStatusNoLongerInTheEnumIsLeftAlone(): void
	{
		self::assertNull($this->restorableStatus(
			$this->historyRow(fromCode: 'awaiting_pick'),
		));
	}

	public function testManualCancellationIsLeftAlone(): void
	{
		self::assertNull($this->restorableStatus(
			$this->historyRow(sourceExternalCode: null),
		));
	}

	public function testCancellationFromElsewhereIsLeftAlone(): void
	{
		self::assertNull($this->restorableStatus(
			$this->historyRow(sourceExternalCode: 'cancel'),
		));
	}

	public function testCancellationFromAnIntegrationIsLeftAlone(): void
	{
		self::assertNull($this->restorableStatus(
			$this->historyRow(sourceIntegrationId: 4),
		));
	}

	public function testLaterTransitionOutOfCancelledIsLeftAlone(): void
	{
		self::assertNull($this->restorableStatus(
			$this->historyRow(toCode: Status::New->value),
		));
	}

	/**
	 * @param array{fromCode: string|null, toCode: string, sourceExternalCode: string|null, sourceIntegrationId: int|string|null} $historyRow
	 */
	private function restorableStatus(array $historyRow): ?Status
	{
		$method = new ReflectionMethod(Shipments::class, 'restorableStatus');
		$method->setAccessible(true);

		return $method->invoke(new Shipments(), $historyRow);
	}

	/**
	 * @return array{fromCode: string|null, toCode: string, sourceExternalCode: string|null, sourceIntegrationId: int|string|null}
	 */
	private function historyRow(
		?string $fromCode = 'new',
		string $toCode = 'cancelled',
		?string $sourceExternalCode = Shipments::ORDER_CANCELLED_CODE,
		int|string|null $sourceIntegrationId = null,
	): array {
		return [
			'fromCode' => $fromCode,
			'toCode' => $toCode,
			'sourceExternalCode' => $sourceExternalCode,
			'sourceIntegrationId' => $sourceIntegrationId,
		];
	}
}
