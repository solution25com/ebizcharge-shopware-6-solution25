# Final Audit

## Review baseline

Working tree review after the `v0.0.6` pipeline-remediation pass on 2026-04-13.

## Resolved P0/P1 issues

### Hidden monolog channel dependency

- Resolved the confirmed install blocker where `CreditCardPaymentHandler` depended on `monolog.logger.ebizcharge_payment`.
- `v0.0.6` continues to use the default Shopware logger service only and does not depend on a plugin-specific logger-channel contract.
- `src/Resources/config/packages/monolog.xml` is removed from the runtime slice.

### Implicit or fragile service assembly

- Replaced the previous discovery-heavy assembly with explicit XML wiring for runtime services, controllers, commands, and interface aliases.
- `ProviderClientInterface`, `ProviderTransportInterface`, and `TransactionRecordStoreInterface` now resolve through explicit aliases in `src/Resources/config/services/core.xml`.
- Controllers no longer depend on `service_container` or `setContainer`.

### Lifecycle install failure

- `PaymentMethodInstaller` remains explicitly public for lifecycle use.
- `src/EbizChargeShopware.php` still falls back to a repository-backed installer instance when plugin services are unavailable during `install()`.

### Excess install surface

- Removed the separate audit subsystem and the order-detail summary/admin extension from the minimal recovery slice.
- Migration scope is reduced to the transaction metadata table only.
- `debugLogging` and the dedicated plugin log-channel contract are no longer part of the config/runtime model.

### Success inferred from browser return

- Browser-reported `approved`, `declined`, and `cancelled` outcomes are now hints only.
- `FinalizationService` attempts provider verification for every browser outcome before it writes `paid`, `authorized`, `failed`, or `cancelled`.
- When provider verification is unavailable or inconclusive, the plugin keeps the transaction `in_progress` and interrupts finalize instead of trusting the browser.

### False-positive connection tests

- `ResponseNormalizer::connectionTestSucceeded()` now requires the expected `GetMerchantIntegrationSettings` envelope and a non-empty result payload.
- Unrelated non-empty JSON no longer marks credentials as verified.

### Password-insensitive connection fingerprints

- `PluginConfig::credentialFingerprint()` now includes the configured password.
- Password-only credential rotations therefore invalidate the stored connection-test gate instead of silently reusing stale success state.

### Wrong amount authority and after-order exposure

- `OrderTransactionLoader` now sources `amountDue` from the Shopware order transaction price instead of the full order total.
- Provider verification compares against the transaction amount only.
- `PaymentMethodInstaller` now keeps `afterOrderEnabled = false`, and plugin `update()` rewrites the payment method for upgrades from `v0.0.4`.

### Pipeline validation blockers

- `composer.json` now contains the Shopware extension metadata required by the validator: `authors`, localized descriptions, localized manufacturer links, localized support links, and the explicit plugin icon reference.
- The release now ships a neutral icon at `src/Resources/config/plugin.png`.
- `CreditCardPaymentHandler`, `OrderTransactionLoader`, and `TransactionStateSyncService` were adjusted to match Shopware 6.7 PHPStan expectations without widening the runtime scope.

### Provider-status approval ambiguity

- `ResponseNormalizer::resolveProviderOutcome()` no longer treats any broad `auth` substring as approved.
- `unauthorized` and `unauthenticated` are classified as declines before any approved-auth branch, while only explicit auth-like values such as `authorized`, `authonly`, `auth_only`, and `auth-only` remain approved.

### Transition-state divergence

- `TransactionStateSyncService` no longer swallows all transition exceptions.
- Illegal Shopware state transitions now persist the actual current Shopware state, while unexpected transition failures bubble up instead of silently diverging from the platform state.

### Non-atomic transaction metadata writes

- `DbalTransactionRecordStore::upsert()` now uses one atomic MySQL/MariaDB upsert statement.
- The table schema stays unchanged in this pass.

## Validation executed in this workspace

- `/opt/homebrew/bin/php tools/guardrail-check.php`
- `/opt/homebrew/bin/php tools/service-graph-check.php`
- `/opt/homebrew/bin/php tools/self-test.php`
- `./tools/package-release.sh`
- `/opt/homebrew/bin/php tools/release-smoke.php`
- `./tools/validate-all.sh`
- `xmllint --noout` for the config and service XML files

The strongest local gate in this pass is the explicit service-graph validation. It parses the plugin XML import chain, resolves aliases, reflects constructor dependencies, and checks the same graph again against the packaged ZIP artifact.

## Residual risks and verification gaps

- No real Shopware kernel boot or container compile has been executed in this workspace. The release is validated through the service-graph check and plain-PHP self-tests, not through a live shop.
- A real Shopware 6.7 install and config-page load were confirmed outside this workspace, but no real eBizCharge sandbox request was made here.
- Node.js is not available in this workspace, so administration-bundle validation is limited to source/bundle review rather than executable `node --check`.
- Local Bitbucket pipeline re-execution is not available from this workspace, so the external branch pipeline remains the next authoritative green gate for store-validator and PHPStan confirmation.
- `cancelledURL` remains intentionally deferred because the attached REST examples do not yet prove provider support for that return URL contract.
- A real Shopware CE smoke pass is still required for the final external gate:
  `plugin:refresh`, `plugin:install --activate`, config-page load, admin connection test, `plugin:deactivate`, `plugin:uninstall --keep-user-data`, reinstall.

## Release assessment

The repository is prepared as a `v0.0.6` install-safe hardening candidate for Shopware 6.7 with a manual-upload ZIP workflow. It is not declared runtime-proven until a real sandbox checkout/finalize pass succeeds outside this workspace.
