# Integration

Adapters for WooCommerce internals and third-party plugins/services live here (and ONLY here).
Empty in stage 1.

Rules:
- Anything not verifiable from the stubs (WooCommerce hook names, Mercado Pago behavior,
  WAHA endpoints, Action Scheduler functions) is wrapped in a small class in this folder.
- Each unverified item gets a row in `docs/VERIFY.md` (status `unverified`, where used, how to verify).
- Modules depend on the adapter, never on the raw hook/endpoint, so a wrong assumption is fixed in one place.
- Adapters must be lazy: no hooks registered unless the calling module's `should_load()` approved the context.
