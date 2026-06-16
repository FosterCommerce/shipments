# Shipments Roadmap

Working notes. Not a spec.

## Where we are

Plugin is feature complete for version 1.0.0, but needs more manual testing. Shipments are a Craft element with a single fixed-vocabulary `Status`, per-integration status mappings, a REST API, and a mutex-serialized status-write path (`Shipments::applyTransition`). Integration framework, staging-group CP UI on Commerce Orders, status history with source tracking, export route, project-config everywhere. Ships zero concrete providers; Custom modules can register via `Integrations::EVENT_REGISTER_INTEGRATIONS`.

## Testing needed

Manual, full-feature validation.

1. **Live-test the full flow end to end.** 
   2. Complete an order with mixed line-item statuses, check the rules engine produces the right shipments. 
   3. Transition statuses, confirm emails fire from the queue. 
   4. Create, edit, remove shipments from the order-edit tab. 
   5. Add integrations, verify the driver dropdown populates from a test-module provider. 
   6. Hit the webhook route, hit the export route. 
   7. Status-history rows write correctly on creation + every transition.
2. **Stand up a throwaway test provider** (a site-module class with `push`/`handleWebhook` that just log and return) and wire an integration to it.

## Future TODOs

- [ ] **Tie inventory locations into rules engine.**
- [ ] **Allow creating unique addresses per shipment.**
- [ ] **First-party provider plugins.** Each ships as a separate Composer package (`fostercommerce/shipments-<vendor>`) that registers a `Provider` via `Integrations::EVENT_REGISTER_INTEGRATIONS`:
  - [ ] ShipStation
  - [ ] ShipBob
  - [ ] Shippo
  - [ ] EasyPost
  - [ ] Easyship
  - [ ] Veeqo
  - [ ] Freightview
  - [ ] Warp ([Freight API](https://www.wearewarp.com/freight-api))


## Saying no

1. **No custom shipment statuses.** Status vocabulary is one fixed PHP enum (`Status`), not admin-editable. Admins reconcile vendor vocabulary via a per-integration mapping table that translates the integration's codes into ours.