# Email templates

Author and customize the Twig templates that render shipment emails. Audience: developers building or editing the templates referenced from **Shipments -> Settings -> Emails**. Prerequisite: a configured email (see the [emails user guide](../user-guide/emails.md)).

## Render context

Templates get these variables:

| Variable        | Type                       | What it is                                                                           |
|-----------------|----------------------------|--------------------------------------------------------------------------------------|
| `shipment`      | `Shipment` element         | The shipment after the change.                                                       |
| `order`         | `Order` element            | The Commerce order.                                                                  |
| `axis`          | `StatusAxis`               | Which status changed, `Fulfillment` or `Shipping`.                                   |
| `fromCode`      | enum value, nullable       | The status before the change (null if this is a new shipment).                       |
| `toCode`        | enum value                 | The status after the change.                                                         |
| `statusHistory` | `ShipmentStatusHistory`    | The new history row; pull the note or source integration from it.                    |
| `user`          | `?User`                    | Who made the change (null for webhooks and background jobs).                         |
| `message`       | `?string`                  | The optional note the admin left on the change.                                      |

## Starter template

A starter HTML template is at `src/templates/emails/shipment.twig` in the plugin. Copy it into your site templates directory and point the email's HTML template path at the new location.

```twig
<h1>{{ 'Shipment {reference}'|t('shipments', { reference: shipment.reference }) }}</h1>

<p>
    {{ 'Order'|t('shipments') }}:
    <strong>{{ order.reference ?: order.number }}</strong>
</p>

{% if axis and toCode %}
    <p>
        {{ axis.label() }}:
        <strong>{{ toCode.label() }}</strong>
    </p>
{% endif %}

{% if shipment.trackingNumber %}
    <p>
        {{ 'Tracking number'|t('shipments') }}:
        {% if shipment.trackingUrl %}
            <a href="{{ shipment.trackingUrl }}">{{ shipment.trackingNumber }}</a>
        {% else %}
            {{ shipment.trackingNumber }}
        {% endif %}
    </p>
{% endif %}

{% if message %}
    <blockquote>{{ message }}</blockquote>
{% endif %}
```

## Plain-text alternates

If you fill in the email's plain-text template path, the same render context is available there. The plain-text version is sent as the email's plain-text alternate; clients that prefer plain text (or accessibility tools) read it instead of the HTML.

## Language

The plugin switches the active language to the email's configured language before rendering, the same way Commerce does for its own emails. Strings wrapped in `|t('shipments')` (or any other category) translate per the active locale. If your template includes copy that needs translating, register it in your site's translation source files.

## Errors at render time

If the template throws (missing variable, undefined filter, etc.), the send fails, the queue logs the error, and the email is marked failed per the retry policy. The error message is recorded on the queue job for inspection in **Utilities -> Queue Manager**.
