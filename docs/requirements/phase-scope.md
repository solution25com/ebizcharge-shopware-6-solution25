# Phase Scope

## Release target

`v0.0.6` is the minimal install-safe hardening release for the Shopware 6.7 plugin.

Primary goals:

- clean Shopware 6.7 install and activation
- one credit-card payment method only
- REST-only provider integration
- support-safe connection diagnostics
- hosted redirect checkout for sandbox validation
- server-side success verification before Shopware success-state changes
- manual Shopware-admin ZIP upload support
- explicit service-graph validation of the plugin container assembly

## Locked scope decisions

- Shopware `6.7.x` only
- plugin delivery model only
- storefront-first architecture with headless-safe service boundaries
- no raw card or CVV handling in the Shopware backend
- credit card only in `v0.0.6`
- redirect-to-hosted-webform flow only in `v0.0.6`
- minimal runtime surface only: no order summary card, no dedicated audit subsystem, no plugin-specific logger channel
- payment method remains inactive and is not after-order enabled
- browser return outcomes are never authoritative without provider verification
- the Shopware order-transaction amount is the only authoritative payment amount

## Deliberately deferred

- callbacks and webhooks
- saved cards and customer vault management
- capture, void, and refund actions
- ACH and deferred terms
- merchant-specific ERP logic
- live certification proof from this repo alone

## Acceptance target carried into this repository

- configuration can be saved safely
- connection test works without creating a charge
- the checkout bootstrap uses `GetEbizWebFormURL`
- success cannot be inferred from browser return alone
- Shopware transaction state is updated through one state-sync service
- runtime services resolve explicitly on a clean Shopware CE install graph
- no secrets, PAN, or CVV are logged or persisted

## Repository delivery requirements

- repo root is the plugin source root
- `docs/` is tracked
- raw inputs are preserved under `docs/input/raw/` but gitignored
- the tagged repo includes the manual-install release ZIP under `release/`
