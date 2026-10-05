# Events

Every event the plugin exposes, what fires it, what's on the payload, and how to listen.

## `Shipments::EVENT_BEFORE_CREATE_SHIPMENTS`

**Fires:** inside `Shipments::createFor()`, after the rules engine produced plans, before the plugin persists them. Useful for mutating the plan list (append a plan, drop a plan, redistribute qty).

**Payload:** `CreateShipmentsEvent`

| Property | Type                  | Notes                                                 |
|----------|-----------------------|-------------------------------------------------------|
| `order`  | `Order`               | The Commerce order that just completed.               |
| `plans`  | `list<ShipmentPlan>`  | The rules engine's output. Mutate to change what gets persisted. |

**Listen:**

```php
Event::on(
    Shipments::class,
    Shipments::EVENT_BEFORE_CREATE_SHIPMENTS,
    static function (CreateShipmentsEvent $event): void {
        // Drop any plan that's empty.
        $event->plans = array_values(array_filter(
            $event->plans,
            static fn (ShipmentPlan $plan): bool => $plan->lineItemQtys !== [],
        ));
    },
);
```

**Common use:** mutate the plan list before persistence (append, drop, or redistribute quantities).

## `Shipments::EVENT_AFTER_CREATE_SHIPMENTS`

**Fires:** inside `Shipments::createFor()`, after the persistence transaction commits. The saved `Shipment` elements are exposed on `$event->shipments`, in plan order.

**Payload:** `CreateShipmentsEvent`. Same as the BEFORE fire plus a populated `shipments` list:

| Property    | Type                  | Notes                                                            |
|-------------|-----------------------|------------------------------------------------------------------|
| `order`     | `Order`               | The Commerce order that just completed.                          |
| `plans`     | `list<ShipmentPlan>`  | The plans that were persisted (post-BEFORE mutation).            |
| `shipments` | `list<Shipment>`      | The saved `Shipment` elements, in plan order. Empty on BEFORE.   |

**Listen:**

```php
Event::on(
    Shipments::class,
    Shipments::EVENT_AFTER_CREATE_SHIPMENTS,
    static function (CreateShipmentsEvent $event) use ($integrationId): void {
        foreach ($event->shipments as $shipment) {
            Craft::$app->getQueue()->push(new PushShipmentJob([
                'shipmentId' => $shipment->id,
                'integrationId' => $integrationId,
            ]));
        }
    },
);
```

**Common use:** queue integration pushes, send internal notifications.

## `Shipments::EVENT_SHIPMENT_STATUS_CHANGED`

**Fires:** inside `applyTransition` (every transition) and on initial creation (with `fromCode = null`). Fires **inside** the DB transaction, Craft's default queue backend is DB-backed, so queue pushes from listeners ride the same transaction.

**Payload:** `ShipmentStatusChangedEvent`

| Property              | Type            | Notes                                                       |
|-----------------------|-----------------|-------------------------------------------------------------|
| `shipment`            | `Shipment`      | The post-transition state.                                  |
| `fromCode`            | `?Status`       | null on creation.                                           |
| `toCode`              | `Status`        | What it just transitioned to.                               |
| `history`             | `ShipmentStatusHistory` | The just-saved history row.                         |
| `user`                | `?User`         | Who initiated (null for queue / webhook).                   |
| `message`             | `?string`       | Optional note on the history row.                           |
| `sourceIntegration`   | `?Integration`  | Which integration drove the change (null for CP actions).   |
| `sourceExternalCode`  | `?string`       | The raw external code from the integration.                 |

**Common use:** queue a `PushShipmentJob` when specific transitions happen.

```php
Event::on(
    Shipments::class,
    Shipments::EVENT_SHIPMENT_STATUS_CHANGED,
    static function (ShipmentStatusChangedEvent $event) use ($integrationId): void {
        if ($event->toCode !== Status::Shipped) {
            return;
        }

        // Don't re-push if the change came *from* this integration.
        if ($event->sourceIntegration?->id === $integrationId) {
            return;
        }

        Craft::$app->getQueue()->push(new PushShipmentJob([
            'shipmentId' => $event->shipment->id,
            'integrationId' => $integrationId,
        ]));
    },
);
```

## `ShipmentLineItems::EVENT_RESOLVE_SHIPPABLE_UNITS`

**Fires:** inside `ShipmentLineItems::shippableUnitsFor()`, after the map is seeded with each line item's cart qty, before pool and overflow math read it. Lets you report a different shippable unit count for a line whose cart qty does not equal its physical units, such as a single summary or kit line that stands for many units. All coverage math reads the resulting map, so the reported count stays consistent everywhere.

**Payload:** `ResolveShippableUnitsEvent`

| Property         | Type               | Notes                                                                          |
|------------------|--------------------|--------------------------------------------------------------------------------|
| `order`          | `Order`            | The order whose line items are being resolved.                                 |
| `shippableUnits` | `array<int, int>`  | Shippable units keyed by Commerce line item id, seeded with cart qty. Overwrite entries to change the count. |

**Listen:**

```php
Event::on(
    ShipmentLineItems::class,
    ShipmentLineItems::EVENT_RESOLVE_SHIPPABLE_UNITS,
    static function (ResolveShippableUnitsEvent $event): void {
        // A summary line (cart qty 1) that ships as 30 physical units.
        foreach ($event->order->getLineItems() as $lineItem) {
            if ($lineItem->id !== null && $lineItem->sku === 'KIT-30') {
                $event->shippableUnits[$lineItem->id] = 30;
            }
        }
    },
);
```

**Common use:** override shippable units for kit or summary lines whose cart qty differs from the physical unit count.

Listeners overwrite existing entries; do not unset a key. A missing entry reads as zero shippable units, which silently drops the line from coverage. The event fires once per order per request; the resolved map is cached, so a listener that does database work runs only once per order.

## `Integrations::EVENT_REGISTER_INTEGRATIONS`

**Fires:** during `Integrations::getSelectableProviderTypes()` (called when rendering the integration edit page's provider dropdown).

**Payload:** `RegisterIntegrationsEvent`

| Property | Type                                          | Notes                                                                 |
|----------|-----------------------------------------------|-----------------------------------------------------------------------|
| `types`  | `list<class-string<ProviderInterface>>`       | Push your provider FQCN onto this list to make it selectable.         |

**Listen:**

```php
Event::on(
    Integrations::class,
    Integrations::EVENT_REGISTER_INTEGRATIONS,
    static function (RegisterIntegrationsEvent $event): void {
        $event->types[] = ExampleErpProvider::class;
    },
);
```

**Common use:** register your custom provider class from a site module's `init()`.

## `Rules::EVENT_REGISTER_RULES`

**Fires:** during `Rules::allRules()` (called when rendering the grouping source settings + running `planFor`).

**Payload:** `RegisterShipmentRulesEvent`

| Property | Type                              | Notes                                                                 |
|----------|-----------------------------------|-----------------------------------------------------------------------|
| `rules`  | `list<ShipmentRuleInterface>`     | Push instances implementing `ShipmentRuleInterface` onto this list.   |

**Listen:**

```php
Event::on(
    Rules::class,
    Rules::EVENT_REGISTER_RULES,
    static function (RegisterShipmentRulesEvent $event): void {
        $event->rules[] = new MyCustomRule();
    },
);
```

**Common use:** register a custom rule implementation. See [custom rules](../dev-guide/custom-rules.md).

## `Provider::EVENT_BEFORE_SEND`

**Fires:** inside `Provider::sendShipmentWithEvents()`, before the provider's `sendShipment()` runs. Set `$event->isValid = false` to skip the send.

**Payload:** `SendIntegrationPayloadEvent`

| Property      | Type                  | Notes                                              |
|---------------|-----------------------|----------------------------------------------------|
| `integration` | `ProviderInterface`   | The provider about to run.                         |
| `shipment`    | `Shipment`            | The shipment being sent.                           |
| `order`       | `Order`               | The Commerce order the shipment belongs to.        |
| `isValid`     | `bool`                | Set to `false` to skip the send. Default `true`.   |

**Listen:**

```php
Event::on(
    Provider::class,
    Provider::EVENT_BEFORE_SEND,
    static function (SendIntegrationPayloadEvent $event): void {
        // inspect $event->shipment / $event->order; set $event->isValid = false to skip.
    },
);
```

**Common use:** veto a send based on shipment/order state, log outbound attempts, mutate provider state before send.

## `Provider::EVENT_AFTER_SEND`

**Fires:** inside `Provider::sendShipmentWithEvents()`, after `sendShipment()` completes successfully. Does **not** fire if `EVENT_BEFORE_SEND` set `isValid = false`, or if `sendShipment()` threw.

**Payload:** `SendIntegrationPayloadEvent`. Same shape as the BEFORE fire; `isValid` is unused on AFTER.

**Common use:** record a successful integration push, fan-out notifications, snapshot the remote reference.

## `Provider::EVENT_BEFORE_CANCEL`

**Fires:** inside `Provider::cancelShipmentWithEvents()`, before the provider's `cancelShipment()` runs. Set `$event->isValid = false` to skip the cancel.

**Payload:** `CancelIntegrationPayloadEvent`

| Property      | Type                  | Notes                                              |
|---------------|-----------------------|----------------------------------------------------|
| `integration` | `ProviderInterface`   | The provider about to run.                         |
| `shipment`    | `Shipment`            | The shipment being cancelled.                      |
| `order`       | `Order`               | The Commerce order the shipment belongs to.        |
| `isValid`     | `bool`                | Set to `false` to skip the cancel. Default `true`. |

**Listen:**

```php
Event::on(
    Provider::class,
    Provider::EVENT_BEFORE_CANCEL,
    static function (CancelIntegrationPayloadEvent $event): void {
        // inspect $event->shipment / $event->order; set $event->isValid = false to skip.
    },
);
```

**Common use:** veto a cancel when the remote already shipped, log cancel attempts.

## `Provider::EVENT_AFTER_CANCEL`

**Fires:** inside `Provider::cancelShipmentWithEvents()`, after `cancelShipment()` completes successfully. Does **not** fire if `EVENT_BEFORE_CANCEL` set `isValid = false`, or if `cancelShipment()` threw.

**Payload:** `CancelIntegrationPayloadEvent`. Same shape as the BEFORE fire; `isValid` is unused on AFTER.

**Common use:** record a successful integration cancel, notify downstream systems.

## Debugging

Set your site's log level to `info` and all status transitions log their source + target to the `shipments` log category. Increase to `debug` for rules-engine trace output.

## `Deliveries::EVENT_AFTER_CREATE`

**Fires:** in `Deliveries::publishSafely()` after a successful booking has saved its references and protected PDFs and transitioned the shipment to Shipped.

**Payload:** `fostercommerce\shipments\events\DeliveryEvent`

| Property | Type | Notes |
|---|---|---|
| `shipment` | `Shipment` | Current shipment element. |
| `delivery` | `Delivery` | Saved booking, references, documents, cost, and operation ID. |

**Listen:**

```php
<?php

declare(strict_types=1);

namespace modules\deliverylog;

use Craft;
use fostercommerce\shipments\events\DeliveryEvent;
use fostercommerce\shipments\services\Deliveries;
use yii\base\Event;
use yii\base\Module;

class DeliveryLog extends Module
{
    public function init(): void
    {
        parent::init();
        Event::on(Deliveries::class, Deliveries::EVENT_AFTER_CREATE, static function (DeliveryEvent $event): void {
            Craft::info(
                'Delivery ready: ' . $event->delivery->operationId . ', shipment ' . $event->shipment->id,
                'delivery-ready',
            );
        });
    }
}
```

**Common use:** log completed carrier bookings or enqueue integration follow-up work.

**Publication:** emitted once when the delivery first succeeds with tracking and documents saved. Refreshing or restoring documents does not emit it again. Configured emails use the Shipped status transition, which commits with delivery completion before this event fires.

## `Deliveries::EVENT_AFTER_CANCEL`

Fires after the carrier confirms cancellation and the delivery is saved as `voided`. Uses the same `DeliveryEvent` payload as creation. Failed or uncertain cancellation requests do not emit it. This event does not send configured emails.

This event is emitted on confirmed cancellation and cannot be manually republished. Cancelling a delivery does not imply a refund or change the shipment's fulfillment status.
