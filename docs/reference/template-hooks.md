# Template hooks

Hooks on the shipment edit page's **Delivery** tab, for adding markup from a plugin or module.

Each hook receives the template's variables as `$context`, including `shipment` and `order`.

| Hook | Renders | Extra context |
|------|---------|---------------|
| `cp.shipments.delivery.shipping-method` | Under the **Shipping method** heading, when a delivery provider is enabled | |
| `cp.shipments.delivery` | At the end of each entry in the delivery history | `delivery` |
| `shipments.delivery.document-actions` | Beside each delivery document, when the user has `shipments-downloadDocuments`. Returned markup replaces **Download** and **Print**; an empty string keeps them | `delivery`, `document` |

## Example

```php
use Craft;
use craft\helpers\Html;
use fostercommerce\shipments\elements\Shipment;

Craft::$app->getView()->hook(
    'cp.shipments.delivery.shipping-method',
    static function (array $context): string {
        /** @var Shipment $shipment */
        $shipment = $context['shipment'];

        return Html::tag('p', $shipment->reference, ['class' => 'light']);
    },
);
```
