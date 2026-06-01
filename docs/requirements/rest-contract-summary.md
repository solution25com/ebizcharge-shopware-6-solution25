# REST Contract Summary

## Required operations in v0.0.6

- `GetMerchantIntegrationSettings`
- `GetEbizWebFormURL`
- `GetTransactionDetails`
- `SearchEbizWebFormReceivedPayments`

## Transport rules

- JSON request and response payloads only
- `SecurityToken` in the request body
- `EBizSubscription-Key` in the HTTP headers
- TLS-only transport
- configurable base URL per environment
- configurable timeout and bounded retry count

## Checkout request mapping

The hosted checkout request builder sends:

- `formType = Webform`
- `processingCommand = Sale | AuthOnly`
- `payByType = CC`
- `customerId`
- `custFullName`
- `orderId`
- `invoiceNumber`
- `amountDue`
- `totalAmount`
- `tipAmount`
- `shippingAmount`
- `dutyAmount`
- `taxAmount`
- `description`
- `lineItems`
- `billingAddress`
- `shippingAddress` when available
- `approvedURL`
- `declinedURL`
- `errorURL`
- `transactionLookupKey`
- `shipFromZip`

Fields intentionally omitted because the attached requirements warn against them for this slice:

- `Currency`
- `AllowPartialAuth`
- `IfAuthExpired`
- `IsRecurring`

## Verification rules

Provider success is accepted only after server-side verification confirms:

- provider reference exists
- provider status resolves to an approved outcome
- amount matches the Shopware transaction
- currency matches the Shopware transaction
- lookup key and/or order identity correlate to the Shopware order transaction

`amountDue` is the authoritative payment amount. `totalAmount` is passed only as descriptive order context and is not used as a success fallback.

## Connection-test rules

- Success requires the expected `GetMerchantIntegrationSettings` response envelope.
- The result node must be present and non-empty.
- Unrelated non-empty JSON payloads are treated as failure.

If verification is incomplete or inconclusive, the plugin keeps the transaction in `in_progress`.

## REST-only guardrails

- no `SoapClient`
- no WSDL references
- no SOAP endpoints
- no `ext-soap` dependency
