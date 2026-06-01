# Plugin Architecture

## v0.0.6 recovery shape

`v0.0.6` keeps the intentionally small `v0.0.4` runtime surface. The hardening focus is still payment correctness over scope expansion, with the additional pipeline-remediation changes layered on top: validator metadata and the plugin icon are present, password-only credential changes invalidate connection health, unauthorized-like statuses are no longer approved, and the storage/state-sync paths are hardened without widening the payment model.

## High-level structure

- `src/EbizChargeShopware.php`
  Thin plugin lifecycle entry point with a lifecycle-safe fallback installer path.
- `src/Installer/`
  Payment-method installation and deactivate-only lifecycle behavior.
- `src/Checkout/Payment/Handler/`
  Shopware 6.7 `AbstractPaymentHandler` entry point.
- `src/Provider/`
  REST contract, transport, request builders, and response normalization.
- `src/Service/Checkout/`
  Hosted checkout bootstrap and order-transaction loading.
- `src/Service/Finalize/`
  Browser return parsing and provider verification orchestration.
- `src/Service/StateSync/`
  Single authority for Shopware transaction-state transitions.
- `src/Service/Connection/`
  Diagnostics and connection-health persistence.
- `src/Storage/Dbal/`
  Transaction metadata persistence only.
- `src/Controller/Api/`
  Admin API diagnostics route with ACL.
- `src/Command/`
  CLI diagnostics.
- `src/Migration/`
  Plugin table for transaction metadata.

## Runtime rules

### Lifecycle

- The plugin base class may use the container only for lifecycle concerns.
- `PaymentMethodInstaller` remains explicitly public because lifecycle access bypasses constructor injection.
- `src/EbizChargeShopware.php` still falls back to a repository-backed installer instance when plugin services are unavailable during `install()`.
- Activation does not auto-enable the payment method.

### Service graph

- Runtime services are wired explicitly in segmented XML.
- `v0.0.6` does not use runtime `<prototype ...>` discovery.
- `ProviderClientInterface`, `ProviderTransportInterface`, and `TransactionRecordStoreInterface` are resolved through explicit aliases.
- Controllers do not depend on `service_container` or `setContainer`.

### Payment authority

- `CreditCardPaymentHandler` stays thin.
- `HostedCheckoutService` creates the redirect session and records support-safe transaction metadata.
- The Shopware order-transaction amount is the only authoritative amount in checkout and finalize.
- `FinalizationService` treats all browser return outcomes as hints and performs provider verification before writing success, failure, or cancellation states.
- `TransactionStateSyncService` is the only place that mutates Shopware order-transaction states.
- `PaymentMethodInstaller` keeps the method inactive and not after-order enabled.

### Supportability

- Connection-test results persist a credential fingerprint per sales channel.
- Stored transaction metadata is normalized and support-safe.
- Runtime logging uses the default Shopware logger service.
- No raw provider payloads, secrets, PAN, or CVV are persisted.

### Admin surface

- System config remains the main admin surface.
- A small custom config component runs the connection test.
- The order metadata panel and separate audit timeline were removed from the minimal recovery slice.
- No secrets are exposed to administration JavaScript.
