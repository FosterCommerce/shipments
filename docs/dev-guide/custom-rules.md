# Custom rules

Shipments decides how to split one completed order into one or more shipments by running a single "grouping source" rule plus a catch-all. The plugin ships four built-in rules (`SingleShipmentRule`, `InventoryStatusRule`, `LineItemStatusRule`, `ShippingCategoryRule`); your site module can register additional ones. Each registered rule becomes an option in **Shipments -> Settings -> General -> Grouping source**.

## The contract

Implement `fostercommerce\shipments\base\ShipmentRuleInterface`:

```php
<?php

declare(strict_types=1);

namespace fostercommerce\shipments\base;

use craft\commerce\elements\Order;
use fostercommerce\shipments\models\ShipmentPlan;

interface ShipmentRuleInterface
{
    /** Stable internal handle. Used as ShipmentPlan::$ruleHandle and as the Grouping source value. */
    public function getHandle(): string;

    /** Display name for the settings UI. */
    public function getName(): string;

    /** One-sentence description for the settings UI. */
    public function getDescription(): string;

    /**
     * Compute zero or more ShipmentPlan objects from the line item pool.
     *
     * @param array<int, int> $remainingQtyByLineItemId  lineItemId => remaining qty
     * @return list<ShipmentPlan>
     */
    public function plan(Order $order, array $remainingQtyByLineItemId): array;
}
```

## How orchestration works

`Rules::planFor($order)`:

1. Computes the starting pool from the order's non-ignored line items.
2. Resolves the active rule from `settings.groupingSource`. If the admin set it to "One shipment group per order" (`'none'`), no rule runs here.
3. Calls the active rule's `plan($order, $pool)`, clamps each returned plan against the pool (a rule claiming more than's available has the excess silently trimmed), subtracts the clamped qtys from the pool.
4. Runs `SingleShipmentRule` last, which claims whatever remains in a single plan. This guarantees the "≥1 shipment per completed order" invariant and fills in when the admin picked "none" or when the active rule left leftovers.

Only one custom rule is active per order. Custom rules don't compose; the admin picks one via the **Grouping source** dropdown.

## Registering

From your site module's `init()`:

```php
use fostercommerce\shipments\events\RegisterShipmentRulesEvent;
use fostercommerce\shipments\services\Rules;
use modules\mystore\rules\HeavyItemRule;
use yii\base\Event;

Event::on(
    Rules::class,
    Rules::EVENT_REGISTER_RULES,
    static function (RegisterShipmentRulesEvent $event): void {
        $event->rules[] = new HeavyItemRule();
    },
);
```

Your rule now appears in the **Grouping source** dropdown. The plugin prefixes every non-built-in rule's label with `[Custom] ` in the settings UI so admins can tell store-specific rules apart from the four shipped with the plugin. An admin has to select it for it to run.

### Handle collisions

Each rule's `getHandle()` must be unique across every registered rule. If two rules share a handle, the plugin throws `yii\base\InvalidConfigException` the first time `Rules::allRules()` runs (typically on the settings page or during order completion) with a message naming both offending classes. The throw is deliberate: silent replacement of a built-in rule by a module's rule with a clashing handle would be a hard-to-diagnose bug.

To deliberately override a built-in, remove it from `$event->rules` before appending your replacement:

```php
use fostercommerce\shipments\base\ShipmentRuleInterface;
use fostercommerce\shipments\rules\LineItemStatusRule;

Event::on(
    Rules::class,
    Rules::EVENT_REGISTER_RULES,
    static function (RegisterShipmentRulesEvent $event): void {
        $event->rules = array_values(array_filter(
            $event->rules,
            static fn (ShipmentRuleInterface $rule): bool => $rule::class !== LineItemStatusRule::class,
        ));
        $event->rules[] = new MyLineItemStatusRule();
    },
);
```

The replacement keeps the built-in handle but is now the explicit intention of the site module rather than a silent last-write-wins side effect.

## Recipes

### Heavy items

"Items over 10 kg ship separately, one per line item." The simplest possible rule: threshold on a single purchasable field plus one-plan-per-unit fan-out.

```php
<?php

declare(strict_types=1);

namespace modules\mystore\rules;

use craft\commerce\elements\Order;
use fostercommerce\shipments\base\ShipmentRuleInterface;
use fostercommerce\shipments\models\ShipmentPlan;

final class HeavyItemRule implements ShipmentRuleInterface
{
    public function getHandle(): string
    {
        return 'heavy-items';
    }

    public function getName(): string
    {
        return 'Heavy items';
    }

    public function getDescription(): string
    {
        return 'Line items over 10kg get their own shipment, one per line item.';
    }

    public function plan(Order $order, array $pool): array
    {
        $plans = [];
        foreach ($order->getLineItems() as $lineItem) {
            if ($lineItem->id === null) {
                continue;
            }

            $remaining = $pool[$lineItem->id] ?? 0;
            if ($remaining <= 0) {
                continue;
            }

            $purchasable = $lineItem->getPurchasable();
            if ($purchasable === null) {
                continue;
            }

            $weight = $purchasable->weight ?? 0.0;
            if ($weight <= 10.0) {
                continue;
            }

            for ($i = 0; $i < $remaining; $i++) {
                $plan = new ShipmentPlan();
                $plan->ruleHandle = $this->getHandle();
                $plan->lineItemQtys = [$lineItem->id => 1];
                $plans[] = $plan;
            }
        }

        return $plans;
    }
}
```

`SingleShipmentRule` picks up every line item you didn't claim (the non-heavy ones) and puts them in one combined shipment.

### Group swatches together

A common Craft Commerce pattern: stores selling fabric, wallpaper, flooring, or paint ship **swatches** as a distinct Commerce product type (flat envelope, cheap postage, different packaging than regular goods). An order with three swatches and one bolt of fabric should produce two shipments, one for the swatches and one for the fabric.

This rule groups every line item whose purchasable's product type handle is `swatch` into a single plan. `SingleShipmentRule` catches the rest in one combined shipment.

```php
<?php

declare(strict_types=1);

namespace modules\mystore\rules;

use craft\commerce\elements\Order;
use craft\commerce\elements\Variant;
use fostercommerce\shipments\base\ShipmentRuleInterface;
use fostercommerce\shipments\models\ShipmentPlan;

final class SwatchBundleRule implements ShipmentRuleInterface
{
    private const SWATCH_PRODUCT_TYPE_HANDLE = 'swatch';

    public function getHandle(): string
    {
        return 'swatch-bundle';
    }

    public function getName(): string
    {
        return 'Swatch bundle';
    }

    public function getDescription(): string
    {
        return 'Groups every swatch line item into a single shipment; non-swatch items ship separately.';
    }

    public function plan(Order $order, array $pool): array
    {
        $swatchQtys = [];

        foreach ($order->getLineItems() as $lineItem) {
            $lineItemId = $lineItem->id;
            if ($lineItemId === null) {
                continue;
            }

            $remaining = $pool[$lineItemId] ?? 0;
            if ($remaining <= 0) {
                continue;
            }

            $purchasable = $lineItem->getPurchasable();
            if (! $purchasable instanceof Variant) {
                continue;
            }

            $product = $purchasable->getProduct();
            if ($product === null) {
                continue;
            }

            if ($product->getType()->handle !== self::SWATCH_PRODUCT_TYPE_HANDLE) {
                continue;
            }

            $swatchQtys[$lineItemId] = $remaining;
        }

        if ($swatchQtys === []) {
            return [];
        }

        $plan = new ShipmentPlan();
        $plan->ruleHandle = $this->getHandle();
        $plan->lineItemQtys = $swatchQtys;

        return [$plan];
    }
}
```

Register it the same way as the heavy-items example. An admin selects **Swatch bundle** as the Grouping source under **Shipments -> Settings -> General** and every future order with a mix of swatches and regular products auto-creates two shipments.

If you want the product-type handle to be configurable (so a second store instance can override it), accept it as a constructor argument:

```php
public function __construct(private readonly string $swatchProductTypeHandle = 'swatch')
{
}
```

Then pass the override in the registration listener when instantiating.

Caveats:
- Only inspects Commerce `Variant` purchasables. A custom purchasable from another plugin won't be recognized as a swatch even if it belongs conceptually, because Commerce's product-type system only applies to `Variant`.
- If you sell multi-qty swatch packs and want them split further (one shipment per pack), swap the single-plan accumulator for the `for ($i = 0; $i < $remaining; $i++)` fan-out pattern from the heavy-items example.

### Separate LTL freight items

Stores selling furniture, large appliances, building materials, or anything that can't go via a standard parcel carrier (USPS, UPS Ground, FedEx parcel) put those items in a Commerce **shipping category** like `ltl` or `freight`. LTL (less-than-truckload) freight rides a different carrier network with different paperwork, rates, and packaging (pallets, bills of lading). You don't mix LTL and parcel items in one shipment: the carrier is different.

This rule groups every line item whose purchasable's shipping category handle is `ltl` into a single plan. Non-LTL items fall through to `SingleShipmentRule` and ship via parcel as normal.

```php
<?php

declare(strict_types=1);

namespace modules\mystore\rules;

use craft\commerce\elements\Order;
use fostercommerce\shipments\base\ShipmentRuleInterface;
use fostercommerce\shipments\models\ShipmentPlan;
use Throwable;

final class LtlFreightRule implements ShipmentRuleInterface
{
    private const LTL_SHIPPING_CATEGORY_HANDLE = 'ltl';

    public function getHandle(): string
    {
        return 'ltl-freight';
    }

    public function getName(): string
    {
        return 'LTL freight';
    }

    public function getDescription(): string
    {
        return 'Groups every LTL-category line item into a single freight shipment; parcel items ship separately.';
    }

    public function plan(Order $order, array $pool): array
    {
        $freightQtys = [];

        foreach ($order->getLineItems() as $lineItem) {
            $lineItemId = $lineItem->id;
            if ($lineItemId === null) {
                continue;
            }

            $remaining = $pool[$lineItemId] ?? 0;
            if ($remaining <= 0) {
                continue;
            }

            $purchasable = $lineItem->getPurchasable();
            if ($purchasable === null) {
                continue;
            }

            try {
                $shippingCategoryHandle = $purchasable->getShippingCategory()->handle;
            } catch (Throwable) {
                continue;
            }

            if ($shippingCategoryHandle !== self::LTL_SHIPPING_CATEGORY_HANDLE) {
                continue;
            }

            $freightQtys[$lineItemId] = $remaining;
        }

        if ($freightQtys === []) {
            return [];
        }

        $plan = new ShipmentPlan();
        $plan->ruleHandle = $this->getHandle();
        $plan->lineItemQtys = $freightQtys;

        return [$plan];
    }
}
```

Register it the same way as the other recipes. An admin picks **LTL freight** as the Grouping source and every order containing LTL-category items produces a dedicated freight shipment alongside the parcel shipment.

Variations:
- If each LTL line item needs its own bill of lading (one pallet = one shipment), swap the accumulator for the `for ($i = 0; $i < $remaining; $i++)` fan-out pattern from the heavy-items example.
- For multi-store setups where each store uses a different shipping-category handle, accept the handle as a constructor argument (same pattern as the swatch example).

> **Note:** The plugin ships a built-in `ShippingCategoryRule` that already handles the common "group by shipping category" case with a store-defined group editor in settings. Prefer the built-in unless your splitting logic diverges from "one group per category handle." See the grouping-source dropdown under **Shipments -> Settings -> General**.

### Honor Verbb Postie's box-packing result

Stores using Verbb's Postie plugin with a multi-box packing method (UPS, FedEx, USPS, etc.) let Postie compute how many physical boxes an order needs and which line items go in which box. That box breakdown already exists at checkout; Postie's BoxPacker-based packer produced it to quote rates. This recipe honors that breakdown: one `Shipment` element per packed box, with the exact line-item split Postie computed.

The shape has two moving parts because Postie's pack result is ephemeral:

- **A listener** captures Postie's `Provider::EVENT_AFTER_PACK_ORDER` during checkout and caches a `lineItemId` to `boxIndex` map keyed by the cart number.
- **A custom rule** reads the cache at order-complete time and produces one `ShipmentPlan` per box.

**Listener, capture the pack result.**

```php
<?php

declare(strict_types=1);

namespace modules\mystore;

use Craft;
use DVDoug\BoxPacker\PackedBox;
use DVDoug\BoxPacker\PackedItem;
use verbb\postie\base\Provider;
use verbb\postie\events\PackOrderEvent;
use verbb\postie\models\PackedBoxes;
use yii\base\Event;

Event::on(
    Provider::class,
    Provider::EVENT_AFTER_PACK_ORDER,
    static function (PackOrderEvent $event): void {
        if ($event->order === null || $event->order->number === null) {
            return;
        }

        if (! $event->packedBoxes instanceof PackedBoxes) {
            return;
        }

        $byLineItemId = [];
        $boxIndex = 0;
        foreach ($event->packedBoxes->getPackedBoxList() as $packedBox) {
            if (! $packedBox instanceof PackedBox) {
                continue;
            }

            foreach ($packedBox->getItems() as $packedItem) {
                if (! $packedItem instanceof PackedItem) {
                    continue;
                }

                $description = $packedItem->getItem()->getDescription();
                if (preg_match('/^Item (\d+)$/', $description, $matches) !== 1) {
                    continue;
                }

                $lineItemId = (int) $matches[1];
                $byLineItemId[$lineItemId] = $boxIndex;
            }

            $boxIndex++;
        }

        Craft::$app->getCache()->set(
            'postie-pack:' . $event->order->number,
            $byLineItemId,
            3600,
        );
    },
);
```

Postie's default `Provider::getBoxItemFromLineItem()` writes each BoxPacker item's description as `"Item {$lineItem->id}"`, which is what the regex above extracts. If your site ships a custom Postie provider that overrides this method with a different description format, match whatever your override writes instead.

**Rule, consume the cached breakdown.**

```php
<?php

declare(strict_types=1);

namespace modules\mystore\rules;

use Craft;
use craft\commerce\elements\Order;
use fostercommerce\shipments\base\ShipmentRuleInterface;
use fostercommerce\shipments\models\ShipmentPlan;

final class PostiePackingRule implements ShipmentRuleInterface
{
    public function getHandle(): string
    {
        return 'postie-packing';
    }

    public function getName(): string
    {
        return 'Postie box packing';
    }

    public function getDescription(): string
    {
        return 'Honors the box breakdown Postie computed at checkout. One shipment per packed box.';
    }

    public function plan(Order $order, array $pool): array
    {
        $cartNumber = $order->number;
        if ($cartNumber === null) {
            return [];
        }

        $byLineItemId = Craft::$app->getCache()->get('postie-pack:' . $cartNumber);
        if (! is_array($byLineItemId) || $byLineItemId === []) {
            return [];
        }

        $groupedByBox = [];
        foreach ($byLineItemId as $lineItemId => $boxIndex) {
            $remaining = $pool[$lineItemId] ?? 0;
            if ($remaining <= 0) {
                continue;
            }

            $groupedByBox[$boxIndex][$lineItemId] = $remaining;
        }

        $plans = [];
        foreach ($groupedByBox as $boxIndex => $lineItemQtys) {
            $plan = new ShipmentPlan();
            $plan->ruleHandle = $this->getHandle() . ':box-' . (string) $boxIndex;
            $plan->lineItemQtys = $lineItemQtys;
            $plans[] = $plan;
        }

        return $plans;
    }
}
```

Register the rule the same way as the other recipes, select **Postie box packing** as the grouping source, and every order that had Postie rate-quoted at checkout produces one shipment per box.

Caveats:
- **The cache depends on the order flowing through checkout.** Orders created programmatically (via the console `rebuild` command, a custom import) never hit Postie's rate flow and won't have a cached breakdown. The rule returns `[]` for those, and `SingleShipmentRule` catches them with a single combined shipment.
- **Cache TTL.** One hour in the example. If checkout is abandoned and resumed after the TTL, the rule falls back to single-shipment. Increase if needed.
- **Multi-provider sites.** The event fires for every provider Postie tries (UPS + FedEx + USPS during a rate quote). The last provider to run wins the cache key. That's usually fine (all providers pack identically under the same BoxPacker config), but if your providers pack differently, key the cache by provider handle and have the rule pick which one to honor.
- **BoxPacker `Item` description.** The listener parses Postie's default `"Item {lineItemId}"` format. A site module that overrides `Provider::getBoxItemFromLineItem()` (to add dimensions, packaging hints, etc.) needs to keep an identifiable line-item id in the description so the regex still matches.

## Anti-patterns

- **Don't query the order's line items directly for quantities**, use the pool parameter. The pool reflects what's actually available to claim.
- **Don't mutate the pool yourself**, return plans and let the orchestrator subtract. The orchestrator's arithmetic is the coverage invariant.
- **Don't throw on "no plans to make"**, return `[]`. The orchestrator handles a rule that has nothing to contribute for a given order.
- **Don't persist anything from `plan`**, it's a pure function over `(order, pool) -> list<ShipmentPlan>`. Persistence is `Shipments::persistPlans`'s job.
- **Don't reach back into Commerce for line-item status** if there's a simpler signal on the pool or the order itself. Remember that `lineItemStatusesToIgnore` already filtered the pool.

## Testing

Write a pure unit test, no Craft bootstrap required if you mock `Order::getLineItems()`:

```php
public function testHeavyItemRulePlansOnePerUnit(): void
{
    $rule = new HeavyItemRule();
    $order = $this->mockOrderWithHeavyItem(lineItemId: 42, qty: 3, weight: 15.0);
    $pool = [42 => 3];

    $plans = $rule->plan($order, $pool);

    self::assertCount(3, $plans);
    foreach ($plans as $plan) {
        self::assertSame('heavy-items', $plan->ruleHandle);
        self::assertSame([42 => 1], $plan->lineItemQtys);
    }
}
```

## Reference: built-in rules

- `SingleShipmentRule`, claims everything remaining into one plan. Runs last, always. Cannot be disabled.
- `InventoryStatusRule`, splits in-stock vs backordered buckets via Commerce's `Purchasable::hasStock()` / `getStock()`. Per-bucket "ship together" vs "one per line item". Quantity split mode controls whether partially-stocked line items split or stay atomic.
- `LineItemStatusRule`, explicit admin-defined groups keyed by Commerce line-item status handle. Each group ships together or one per line item. Unassigned statuses fall through to `SingleShipmentRule`.
- `ShippingCategoryRule`, explicit admin-defined groups keyed by Commerce shipping-category handle (LTL, hazmat, oversized, etc.). Same group-editor UI as `LineItemStatusRule`; unassigned categories fall through to `SingleShipmentRule`.

Read their source in `src/rules/` for patterns.
