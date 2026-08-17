<img width="1600" height="400" alt="EBizCharge" src="https://github.com/user-attachments/assets/5b0875e6-5573-41c2-841d-c7f49f4ceb08" />

# EBizCharge Payment for Shopware 6.7

Version `1.0.5`

## Introduction

The **EBizCharge Payment Plugin** enables secure payments for Shopware 6.7 stores through the EBizCharge REST hosted webform and customer-vault flows. For new payment entry, customers are redirected to EBizCharge, keeping raw payment data outside the Shopware backend.

REST only integration using the EBizCharge hosted webform and REST verification APIs.

The plugin supports hosted card and ACH checkout, saved-payment-method account management for registered customers, saved-payment-method checkout, admin Pay by Link workflows, webhook status updates, and provider-backed transaction synchronization for capture, void, and refund events.

---

## Key Features

### Secure Hosted Payment Processing

- Accept hosted card and ACH payments through the EBizCharge secure checkout.
- Keep sensitive payment entry on the provider-hosted form.
- Use server-side provider verification before Shopware transaction states are applied.
- Mark approved hosted webform payments as applied in EBizCharge after Shopware state sync.

### Pay by Link

- Create `EBizCharge Pay by Link` as a Shopware payment method for admin/order workflows.
- Automatically generate and email hosted payment links for Pay by Link order transactions.
- Resend payment links from the Shopware Administration order view.
- Handle payment-link return, success, and failure pages in the storefront.

### Saved Payment Methods

- Let registered customers manage saved EBizCharge payment methods from their account area.
- Add a saved card or ACH bank account through a hosted EBizCharge form.
- Pay during checkout with a selected saved card or saved bank account.
- Delete saved payment methods.
- Set a default saved payment method.

### Admin Panel Integration

- Configure EBizCharge credentials and checkout behavior from Shopware Administration.
- Run a connection test from the plugin configuration page.
- Resend Pay by Link emails from admin order details.

### Webhook Status Updates

- Receive EBizCharge webhook events at `/ebizcharge/webhook`.
- Protect webhook requests with Basic Auth and HMAC signature validation.
- Sync captured, voided, and refunded sale events into Shopware transaction states.

### Flexible Configuration

- Choose between sandbox and production EBizCharge environments.
- Configure REST base URLs and credentials per environment.
- Select `Sale` or `AuthOnly` processing.
- Configure transaction description, AVS behavior, verification lookback, timeout, retries, and webhook security.

### Shopware 6.7 Compatibility

- Supports Shopware `>=6.7.0.0 <6.8.0.0`.
- Uses Shopware payment handlers, DAL repositories, storefront controllers, and administration extensions.

---

## Compatibility

- Shopware `>=6.7.0.0 <6.8.0.0`
- PHP `>=8.2`

---

## Get Started

### Installation & Activation

Manual upload in Shopware Admin is supported with the packaged release ZIP.

#### GitHub

1. Clone the plugin into your Shopware custom plugins directory:

```bash
cd custom/plugins
git clone https://github.com/solution25com/ebizcharge-shopware-6-solution25.git
```

2. Log in to the Shopware Administration.

3. Navigate to:

```text
Extensions > My Extensions
```

4. Locate the EBizCharge plugin and click **Install**.

5. Install and activate the plugin.

---

## Plugin Configuration

After installing the plugin, configure your **EBizCharge** credentials and checkout behavior through the Shopware Administration panel.

### Accessing the Configuration

1. Go to **Extensions > My extensions**
2. Open the EBizCharge plugin configuration
3. Select the sales channel you want to configure
4. Save credentials and run the connection test

<img width="1308" height="1296" alt="image" src="https://github.com/user-attachments/assets/65d5126b-8e12-4751-a61d-168fdaaf6b75" />


### Environment And Credentials

Configure the active environment:

- Sandbox
- Production

For each environment, configure:

- Base URL
- Security ID
- User ID
- Password
- `EBizSubscription-Key`

### Checkout Behavior

Configure:

- Payment command: `Sale` or `AuthOnly`
- Ship-from ZIP
- Transaction description template
- AVS enforcement
- Verification lookback days
- Connection timeout
- Retry count

<img width="1308" height="1096" alt="image" src="https://github.com/user-attachments/assets/864f8a5a-4e38-4403-91d0-3c9e36db410b" />


### Webhook Security

Configure:

- Webhook Basic Auth username
- Webhook Basic Auth password
- Webhook signature key

<img width="1308" height="532" alt="image" src="https://github.com/user-attachments/assets/3fe1ba0c-1047-48f0-b513-2664f0dcab09" />


### Connection Test

The configuration page includes a connection test button backed by:

```text
POST /api/_action/ebizcharge/test-connection
```

The plugin also provides a CLI command:

```bash
bin/console ebizcharge:test-connection
```

Connection health is tracked per sales channel and credential fingerprint. password-only credential changes invalidate prior health state and require a fresh successful test.

---

## Checkout Experience

The plugin integrates into Shopware checkout through the `EBizCharge Hosted Payment` payment method.

### Hosted Payment

1. Customer selects **EBizCharge Hosted Payment** in checkout.
2. Shopware creates the order and order transaction.
3. The plugin validates configuration and connection health.
4. The plugin creates an EBizCharge hosted webform URL.
5. Customer is redirected to the hosted EBizCharge payment form.
6. EBizCharge redirects the customer back to Shopware.
7. Shopware finalization verifies the payment with provider data before updating the transaction state.

<img width="1002" height="1542" alt="image" src="https://github.com/user-attachments/assets/43c4e993-3d34-48c2-8901-88e9a9cde9bf" />


### Saved Payment Methods During Checkout

For registered customers, checkout displays saved EBizCharge payment methods when customer vault data exists.

- Customers can choose a saved card or saved bank account from the checkout payment section.
- Saved cards and saved bank accounts are grouped separately.
- Saved cards can request the card security code when EBizCharge marks it as required.
- Saved payment methods are selected in Shopware checkout, not inside the hosted EBizCharge form.
- The hosted EBizCharge form is used when the customer chooses to pay with a new payment method.

---

## Pay by Link

The plugin supports admin-managed Pay by Link payments.

### How It Works

1. An order transaction is created with the `EBizCharge Pay by Link` payment method.
2. The plugin creates a hosted EBizCharge email-form URL.
3. The plugin sends the payment link using the `ebizcharge.admin.payment_link` mail template.
4. Customer opens the link and completes payment on the hosted EBizCharge form.
5. The return endpoint finalizes the payment and redirects to success or failure.

### Admin Resend

Admins can resend the payment link from the Shopware order view. The resend action calls:

```text
POST /api/_action/ebizcharge/payment-link/re-send
```

### Storefront Return Pages

Payment-link return routes:

```text
/ebizcharge-payment-link-return
/ebizcharge-payment-link-success
/ebizcharge-payment-link-fail
```

---

## Saved Payment Methods

Registered customers can manage their saved EBizCharge payment methods from the account dashboard.

<img width="2304" height="1016" alt="image" src="https://github.com/user-attachments/assets/38f1c122-7a7a-4446-9a3a-cf00a1d794ca" />


### Accessing Saved Payment Methods

Customers can open:

```text
/account/ebizcharge/saved-cards
```

or use the **EBizCharge Saved Payment Methods** account menu entry.


### Available Actions

- View saved payment methods
- Add a card or bank account through a hosted EBizCharge form
- Delete a saved payment method
- Set a default saved payment method

Saved-payment-method records are stored in:

```text
ebizcharge_vaulted_customer
```

---

## Webhooks

The plugin exposes:

```text
POST /ebizcharge/webhook
```

Supported webhook events:

- `transaction.sale.captured`
- `transaction.sale.voided`
- `transaction.sale.refunded`

Webhook requests are validated with configured Basic Auth credentials and an HMAC signature key.

---

## Transaction Data

The plugin stores EBizCharge transaction metadata in:

```text
ebizcharge_payment_transaction
```

Stored metadata includes:

- Shopware order transaction ID
- Shopware order ID and order number
- provider reference number
- authorization code
- provider payment type
- provider payment method
- normalized transaction state
- amount and currency
- last sync timestamp

This data is used for finalization, webhook processing, and provider capture/void/refund calls.

---

## Validation

Run the repository validation script:

```bash
./tools/validate-all.sh
```

The project also includes targeted tools for guardrails, service graph checks, self-tests, packaging, and release smoke validation.
