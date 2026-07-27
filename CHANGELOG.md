# Changelog

## [1.0.3] - 2026-07-27
### Changed
- Redesigned saved payment method UI at checkout and in the account section with card and ACH styling, brand logos, last 4 digits display, and method name labels
- Separated credit card and ACH payment methods into distinct sections
- Improved default payment method selection handling
- Removed saved payment dropdown when no saved cards or ACH accounts are available
- Improved delete confirmation flow with loading feedback for account redirects
- Removed inline style overrides for cleaner stylesheet inheritance

## [1.0.2] - 2026-07-10

### Added

- Added checkout selection for saved EBizCharge payment methods, including saved cards and saved ACH bank accounts.
- Added direct saved-payment-method charging for registered customers through EBizCharge customer transactions.
- Added ACH bank-account support to the hosted saved-payment-method flow.
- Added provider-side applied-status updates after approved hosted webform payments are synced in Shopware.

### Changed

- EBizCharge hosted webform payloads now include required company, country, currency, and date fields for provider compatibility.
- Saved-payment-method storefront views now group bank accounts separately from cards and request a card security code only when the saved card requires it.
- EBizCharge naming, snippets, payment method labels, plugin icon, and admin/storefront UX copy were aligned with the current brand spelling.

## [1.0.1] - 2026-06-12

### Changed

- Updated saved-card storefront styling.

## [1.0.0] - 2026-06-11

### Fixed

- Fixed Shopware extension verifier issues and aligned release package metadata.

## [0.0.9] - 2026-06-11

### Fixed

- Added purchase order number to hosted checkout payloads.
- Corrected Shopware tax, SKU, discount, unit-of-measure, and line-item tax mapping in hosted checkout data.
- Improved provider verification parsing for eBizCharge return/reference casing variants.
- Kept saved-card state short codes compatible with eBizCharge hosted forms.
- Aligned validation guardrails, self-tests, and release packaging.

## [0.0.8] - 2026-06-02

- Bug fixing and improvements

## [0.0.7] - 2026-06-01

### Added

- Added Pay by Link support with automatic email generation, admin resend action, storefront return pages, persistence, and mail-template setup.
- Added saved-card account management for registered customers, including hosted add-card flow, delete/default actions, and account-area views.
- Added webhook handling with Basic Auth and HMAC signature validation for captured, voided, and refunded events.
- Added provider-side capture, void, and refund synchronization when Shopware transaction states enter `paid`, `cancelled`, or `refunded`.

### Changed

- Credit-card checkout can show saved payment methods for registered customers while raw card entry remains hosted by eBizCharge.
- Transaction records now include provider payment type, provider payment method, support message, and last-sync timestamp fields.
- Plugin configuration now includes webhook security credentials and AVS enforcement.

### Fixed

- Added Shopware login required defaults to custom account routes.

## [0.0.6] - 2026-04-13

### Added

- Shopware validator metadata in `composer.json`: `authors`, localized descriptions, localized manufacturer links, localized support links, and explicit `plugin-icon` registration.
- A neutral Shopware extension icon at `src/Resources/config/plugin.png`.
- Self-test coverage for password-only fingerprint invalidation, unauthorized-like provider statuses, illegal transition handling, unexpected transition exception bubbling, and atomic transaction-record upserts.
- PHPUnit coverage for password-sensitive credential fingerprints and the safer approved-vs-unauthorized status normalization rules.

### Changed

- Narrowed `CreditCardPaymentHandler::validate()` to return `Struct` and `pay()` to return `RedirectResponse`, matching the actual Shopware 6.7 behavior without widening the payment scope.
- Cleaned `OrderTransactionLoader` to align with Shopware 6.7 entity typing while preserving the current authority model: order-transaction amount remains authoritative and order total remains descriptive only.
- `TransactionStateSyncService` now persists the actual current Shopware state after illegal transitions instead of silently writing the rejected target state.
- `DalTransactionRecordStore::upsert()` preserves existing transaction metadata while merging later provider verification fields.

### Fixed

- `ResponseNormalizer::resolveProviderOutcome()` no longer misclassifies `unauthorized` or similar statuses as approved through a broad `auth` substring match.
- `PluginConfig::credentialFingerprint()` now includes the password, so password-only credential rotations invalidate prior successful connection tests.
- `TransactionStateSyncService::currentState()` now type-narrows the repository result before reading `getStateMachineState()`, matching the PHPStan expectation for Shopware 6.7 entities.
- Packaging and release smoke validation now require the plugin icon and compare it between source and the packaged ZIP artifact.

### Validation

- `/opt/homebrew/bin/php -l` across `src`, `tests`, and `tools`
- `/opt/homebrew/bin/php tools/guardrail-check.php`
- `/opt/homebrew/bin/php tools/service-graph-check.php`
- `/opt/homebrew/bin/php tools/self-test.php`
- `xmllint --noout` for `config.xml` and the segmented service XML files
- `./tools/package-release.sh`
- `/opt/homebrew/bin/php tools/release-smoke.php`
- `./tools/validate-all.sh`

## [0.0.5] - 2026-04-13

### Changed

- Hardened the minimal Shopware 6.7 runtime from `0.0.4` without widening scope beyond the hosted REST card flow.
- Made the Shopware order-transaction amount the single authoritative amount through checkout bootstrap, stored metadata, and provider verification.
- Disabled `afterOrderEnabled` on the payment method and added plugin `update()` handling so upgrades rewrite the existing method to the safer default.

### Fixed

- `ResponseNormalizer::connectionTestSucceeded()` no longer accepts arbitrary non-empty JSON; it now requires the expected `GetMerchantTransactionData` response envelope and result payload.
- `FinalizationService` no longer treats browser-reported `declined` or `cancelled` outcomes as authoritative. All outcomes now attempt provider verification first.
- Provider verification now rejects amount mismatches against the Shopware order transaction instead of allowing fallback comparison against the full order total.
- `CreditCardPaymentHandler::pay()` now validates only the authoritative transaction amount, not the descriptive order total.

### Added

- Self-tests for provider-verified cancel, browser-decline without verification, unexpected connection-test payloads, amount-mismatch rejection, and disabled after-order installation/update behavior.
- PHPUnit coverage for the payment-method installer update path and the stricter connection-test response handling.

### Validation

- `/opt/homebrew/bin/php -l` across `src`, `tests`, and `tools`
- `/opt/homebrew/bin/php tools/guardrail-check.php`
- `/opt/homebrew/bin/php tools/service-graph-check.php`
- `/opt/homebrew/bin/php tools/self-test.php`
- `xmllint --noout` for `config.xml` and the segmented service XML files
- `./tools/package-release.sh`
- `/opt/homebrew/bin/php tools/release-smoke.php`

## [0.0.4] - 2026-04-13

### Changed

- Reduced the runtime surface to the minimal install-safe Shopware 6.7 core: payment method installer, connection diagnostics, hosted redirect checkout, server-side finalize verification, transaction-state sync, and one transaction metadata store.
- Replaced prototype-style or assumption-based service assembly with explicit XML wiring for runtime services, controllers, commands, and provider/store aliases.
- Removed the plugin-specific monolog channel contract and reverted runtime logging to the default Shopware logger service.
- Removed the order-summary admin extension and the separate audit store from the release slice.
- Advanced the plugin version to `0.0.4`.

### Fixed

- `CreditCardPaymentHandler` and related services no longer depend on `monolog.logger.ebizcharge_payment`, which was not guaranteed to exist on a clean Shopware CE install.
- `ProviderClientInterface`, `ProviderTransportInterface`, and `TransactionRecordStoreInterface` now have explicit aliases in the runtime service graph.
- Controller wiring no longer depends on `service_container` or `setContainer`.
- Migration scope is reduced to the transaction metadata table only; the unused audit table is gone.
- Validation no longer checks for the old broken architecture and now fails on hidden package config, unresolved service ids, stale order-summary surfaces, and logger-channel assumptions.

### Added

- `tools/service-graph-check.php` to parse the plugin XML import chain, resolve aliases, reflect constructor dependencies, and fail on unresolved runtime references.
- `tools/release-smoke.php` to unpack the release archive and rerun the same graph validation against the packaged artifact.
- Plain-PHP self-tests covering payment-method installation payloads, connection-test gating, hosted redirect creation, verified finalize flow, and command/controller instantiation.

### Validation

- `/opt/homebrew/bin/php -l` across `src`, `tests`, and `tools`
- `/opt/homebrew/bin/php tools/guardrail-check.php`
- `/opt/homebrew/bin/php tools/service-graph-check.php`
- `/opt/homebrew/bin/php tools/self-test.php`
- `xmllint --noout` for `config.xml` and the segmented service XML files
- `./tools/package-release.sh`
- `/opt/homebrew/bin/php tools/release-smoke.php`

## [0.0.3] - 2026-04-11

### Fixed

- Replaced the invalid Symfony XML service discovery entry in `src/Resources/config/services/core.xml` with valid XML so Shopware could parse the plugin container during install.
- Kept controller classes out of the runtime service import path so explicit controller registrations remained authoritative.

### Changed

- Plugin version advanced to `0.0.3`.
- Release tooling began deriving the archive version from `composer.json`.

## [0.0.2] - 2026-04-11

### Fixed

- Plugin lifecycle stopped requiring the installer service to exist during `install()`.
- The plugin added a repository-backed fallback installer path in the lifecycle entry point.

## [0.0.1] - 2026-04-11

### Added

- Initial Shopware `6.7.x`-only plugin metadata and REST-only eBizCharge integration boundaries.
- Hosted redirect checkout through `GetEbizWebFormURL`.
- Server-side finalize verification using `GetTransactionDetails` and `SearchEbizWebFormReceivedPayments`.
- Support-safe admin connection test route and CLI command.
- Packaging and validation scripts for release ZIP production.
