<?php

declare(strict_types=1);

namespace fostercommerce\shipments\services;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\models\LineItem;
use craft\commerce\models\OrderAdjustment;
use craft\elements\Address;
use craft\helpers\StringHelper;
use fostercommerce\shipments\behaviors\ShipmentCalculation as ShipmentCalculationBehavior;
use fostercommerce\shipments\elements\Shipment;
use fostercommerce\shipments\helpers\MoneyValues;
use Money\Money;
use RuntimeException;
use yii\base\Component;

class ShipmentCalculation extends Component
{
	public function create(Shipment $shipment): Order
	{
		$source = $shipment->getOrder();
		if (! $source instanceof Order) {
			throw new RuntimeException(Craft::t('shipments', 'delivery.errors.shipmentOrderIsUnavailable'));
		}

		$currency = $source->currency;
		if ($currency === null || $currency === '') {
			throw new RuntimeException(Craft::t('shipments', 'delivery.errors.shipmentOrderHasNoCurrency'));
		}

		$order = new Order([
			'id' => $source->id,
			'storeId' => $source->storeId,
			'siteId' => $source->siteId,
			'orderSiteId' => $source->orderSiteId,
			'currency' => $currency,
			'customerId' => $source->customerId,
			'couponCode' => $source->couponCode,
		]);
		$order->attachBehavior(ShipmentCalculationBehavior::BEHAVIOR, new ShipmentCalculationBehavior());
		$order->setFieldValues($source->getSerializedFieldValues());
		// Commerce and extensions memoize matching rules by order number.
		$order->number = 'shipment-' . $shipment->id . '-' . StringHelper::UUID();
		$order->setRecalculationMode(Order::RECALCULATION_MODE_NONE);
		$order->setShippingAddress($this->cloneAddress($source->getShippingAddress(), $order));
		$order->setEstimatedShippingAddress($this->cloneAddress($source->getEstimatedShippingAddress(), $order));
		$order->setBillingAddress($this->cloneAddress($source->getBillingAddress(), $order));

		$sourceItems = collect($source->getLineItems())->keyBy('id');
		$allocations = collect($shipment->getLineItems())->keyBy('lineItemId');
		$items = $allocations->map(function ($allocation) use ($sourceItems): LineItem {
			$item = $sourceItems->get($allocation->lineItemId);
			if (! $item instanceof LineItem || $allocation->qty < 1 || $allocation->qty > $item->qty) {
				throw new RuntimeException(Craft::t('shipments', 'delivery.errors.shipmentContainsAnInvalidLineItemAllocation'));
			}

			$copy = clone $item;
			$copy->qty = $allocation->qty;
			return $copy;
		})->values()->all();
		if ($items === []) {
			throw new RuntimeException(Craft::t('shipments', 'delivery.errors.allocateItemsBeforeCalculatingShipmentShipping'));
		}

		$order->setLineItems($items);
		$subtotal = MoneyValues::fromCommerce($source->getItemSubtotal(), $currency);
		$allocatedSubtotal = MoneyValues::fromCommerce($order->getItemSubtotal(), $currency);
		/** @var array<int, OrderAdjustment> $adjustments */
		$adjustments = collect($source->getAdjustments() ?? [])->filter(static fn (OrderAdjustment $adjustment): bool => $adjustment->type !== 'shipping')
			->map(function (OrderAdjustment $adjustment) use ($allocations, $sourceItems, $order, $subtotal, $allocatedSubtotal, $currency): ?OrderAdjustment {
				$copy = clone $adjustment;
				$amount = MoneyValues::fromCommerce($adjustment->amount, $currency);
				if ($adjustment->lineItemId !== null) {
					$allocation = $allocations->get($adjustment->lineItemId);
					$item = $sourceItems->get($adjustment->lineItemId);
					if ($allocation === null || ! $item instanceof LineItem) {
						return null;
					}

					$amount = $amount->multiply($allocation->qty)->divide($item->qty, Money::ROUND_HALF_UP);
					foreach ($order->getLineItems() as $lineItem) {
						if ($lineItem->id === $adjustment->lineItemId) {
							$copy->setLineItem($lineItem);
						}
					}
				} elseif (! $subtotal->isZero()) {
					// Shipping thresholds see a proportional share of order-level adjustments.
					$amount = $amount->multiply($allocatedSubtotal->getAmount())->divide($subtotal->getAmount(), Money::ROUND_HALF_UP);
				} else {
					$amount = $amount->multiply(0);
				}

				$copy->amount = (float) MoneyValues::decimal($amount);
				return $copy;
			})->filter()->values()->all();
		$order->setAdjustments($adjustments);
		$order->storedTotalPrice = $order->getTotalPrice();
		$order->storedTotal = $order->getTotal();
		$order->storedItemSubtotal = $order->getItemSubtotal();
		$order->storedItemTotal = $order->getItemTotal();
		$order->storedTotalQty = $order->getTotalQty();
		$order->storedTotalShippingCost = 0;
		$order->storedTotalDiscount = $order->getTotalDiscount();
		$order->storedTotalTax = $order->getTotalTax();
		$order->storedTotalTaxIncluded = $order->getTotalTaxIncluded();
		return $order;
	}

	private function cloneAddress(?Address $address, Order $order): ?Address
	{
		if (! $address instanceof Address) {
			return null;
		}

		$copy = clone $address;
		$copy->setPrimaryOwner($order);
		$copy->setFieldValues($address->getSerializedFieldValues());
		return $copy;
	}
}
