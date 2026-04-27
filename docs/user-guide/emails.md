# Emails

Send a notification email every time a shipment changes to a given status. The plugin ships no default emails; you build each one.

Audience: store admins setting up customer and internal notifications.

## Where to configure

**Shipments -> Settings -> Emails.** Admin-only by default (grant `shipments-manageEmails` to give others access).

Per email, you configure:

1. **Basic fields**: name, subject, recipient type, To / BCC / CC / Reply-To, HTML template path, optional plain-text template path, language, enabled toggle.
2. **Transition triggers**: which status changes send this email.

The template path field autocompletes against your site templates.

## Recipients

- **Customer**, sends to the order's customer email. Renders in the order's language (or the site default).
- **Custom**, sends to whatever addresses you put in **To**. The field is Twig-rendered, so you can do dynamic addressing:
  ```twig
  {% if shipment.carrier == 'UPS' %}ups-ops@your-store.example{% else %}fedex-ops@your-store.example{% endif %}
  ```
  Separate multiple addresses with commas, semicolons, or whitespace.

## Templates

Templates render with `shipment` and `order` available, plus the axis, the to/from codes, the user who made the change, and any note the admin left. Authoring or customizing templates is developer work; see the [email templates dev guide](../dev-guide/email-templates.md) for the full variable list and a starter template.

## Transition triggers

This controls "when does this send." Scroll to the bottom of the email edit page:

- **Fulfillment status triggers**, check one or more fulfillment status values. The email sends when a shipment changes into *any* checked value.
- **Shipping status triggers**, the same, for shipping status values.

Uncheck and save to remove a trigger. Multiple emails can share a trigger; every match queues on every change.

**You have to save the email before you can check triggers.** The UI shows a "Save the email first" note when you're creating a new email.

## How the send works

1. An admin (or webhook, or API) changes a shipment's status.
2. The plugin writes the new status and a history row in a single save.
3. For every enabled email whose triggers match the new status, the plugin queues a send job.
4. The queue push happens in the same save, so an email can't queue if the status change rolls back. No orphan emails, no lost sends.
5. The queued job runs in the background, renders the template, and sends through Craft's mailer.

## Disabled emails

Flipping Enabled off stops all future sends. Jobs already in the queue still run. There's no per-axis pause; it's all-or-nothing per email. Clone the email if you want one version live and another paused.

## Language

The email renders in the order's language by default, or in a specific site language if you set the email's `language` field. The plugin switches the active language before rendering, the same way Commerce does for its own emails.

## Common patterns

**One customer-facing email per major milestone:**

- "Your order has shipped", bound to `fulfillment: fulfilled`.
- "Your package is out for delivery", bound to `shipping: out_for_delivery`.
- "Your package has been delivered", bound to `shipping: delivered`.

**Internal alerts for exceptions:**

- "Shipment on hold", custom recipient `warehouse-lead@your-store.example`, bound to `fulfillment: on_hold`.
- "Delivery problem", custom recipient `cs@your-store.example`, bound to `shipping: exception`, `shipping: failure`, `shipping: returned`.

**A "please ship this" email for 3PLs:**

- Custom recipient for the 3PL dispatch inbox, bound to `fulfillment: in_progress`.

## Testing

There's no "test email" button yet. To check an email:

1. Create a test order and stage a shipment.
2. Change the shipment to the status you've bound the email to.
3. Check Craft's queue (**Utilities -> Queue Manager**) for the send job.
4. Check the recipient inbox (or `settings.toAddress` in dev to catch all local mail).

## What breaks if...

- **You delete an email.** All its transition bindings delete with it. Future matching changes no longer fire it. History and already-sent emails are unaffected.
- **You point the HTML template at a path that doesn't exist.** The next send fails, logs the error, and the queue marks the job failed per its retry policy. Fix the path; jobs don't retry on their own after the queue gave up, so requeue them.
- **A Custom recipient doesn't render to a valid address.** That portion of the send is skipped; the rest proceeds. Failures are logged.
