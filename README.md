# plg_pcp_raiaccept

**RaiAccept (Raiffeisen Bank Serbia) payment gateway plugin for Phoca Cart (Joomla 6)**

---

## Overview

This plugin integrates RaiAccept — Raiffeisen Bank Serbia's online payment gateway — with the Phoca Cart e-commerce component for Joomla 6. It was built from scratch since Raiffeisen Bank Serbia only provides an official WooCommerce plugin.

The plugin handles the full payment lifecycle: checkout redirect, success/fail/cancel return handling, webhook processing, and admin refund from the Phoca Cart orders list.

---

## Features

- ✅ Full checkout redirect flow (hosted payment page)
- ✅ Success / fail / cancel return handling with API verification
- ✅ Webhook processing (PURCHASE and REFUND events)
- ✅ One-click checkout for logged-in users
- ✅ Admin refund button directly in the Phoca Cart orders list
- ✅ Partial and full refund support
- ✅ Multi-currency support with exchange rate handling
- ✅ English and Serbian language files
- ✅ Configurable order statuses per payment event

---

## Requirements

- Joomla 6.x
- Phoca Cart 6.0+
- PHP 8.2+

---

## Installation

1. Download the latest ZIP from releases
2. Install via **Joomla Admin → System → Install → Extensions**
3. Go to **Phoca Cart → Payment** and add a new payment method using the RaiAccept plugin
4. Configure credentials and order statuses in the payment method settings

---

## Configuration

All settings are managed in **Phoca Cart → Payment → RaiAccept** (not in Joomla plugin manager).

| Setting | Description | Default |
|---|---|---|
| Sandbox Mode | Use sandbox API for testing | Yes |
| API Username | RaiAccept API username from Merchant Portal | — |
| API Password | RaiAccept API password from Merchant Portal | — |
| Payment Completed | Order status ID for successful payment | 6 |
| Payment Failed | Order status ID for failed payment | 7 |
| Payment Canceled | Order status ID for canceled payment | 3 |
| Payment Refunded | Order status ID for full refund | 5 |
| Payment Partially Refunded | Order status ID for partial refund | 5 |

Default status IDs match the standard Phoca Cart installation:

| ID | Status |
|---|---|
| 1 | Pending |
| 2 | Confirmed |
| 3 | Canceled |
| 4 | Shipped |
| 5 | Refunded |
| 6 | Completed |
| 7 | Failed |

If you add a custom "Partially Refunded" status, update the `Payment Partially Refunded` field accordingly.

---

## Architecture

The plugin uses the Joomla 6 service provider pattern (`services/provider.php`) with PSR-4 autoloading.

```
plg_pcp_raiaccept/
├── raiaccept.xml
├── install.php
├── services/
│   └── provider.php
├── src/
│   ├── Extension/
│   │   └── RaiAccept.php        # Main plugin class
│   └── Helper/
│       ├── ApiHelper.php        # RaiAccept API client
│       └── ShopHelper.php       # Phoca Cart helper utilities
└── language/
    ├── en-GB/
    │   ├── plg_pcp_raiaccept.ini
    │   └── plg_pcp_raiaccept.sys.ini
    └── sr-RS/
        ├── plg_pcp_raiaccept.ini
        └── plg_pcp_raiaccept.sys.ini
```

---

## RaiAccept API Flow

Authentication via Amazon Cognito (direct cURL with exact headers required):

```
POST https://authenticate.raiaccept.com
Content-Type: application/x-amz-json-1.1
X-Amz-Target: AWSCognitoIdentityProviderService.InitiateAuth
```

Full payment flow:

1. **Authenticate** → obtain `IdToken`
2. **Create order** → `POST https://trapi.raiaccept.com/orders`
3. **Create session** → `POST https://trapi.raiaccept.com/orders/{id}/checkout` → returns `paymentRedirectURL`
4. **Redirect customer** to RaiAccept hosted payment page
5. **Customer returns** via `successUrl` / `failUrl` / `cancelUrl`
6. **API verify** order status on return
7. **Webhook** arrives at `notificationUrl` with transaction result
8. **Refund** → `POST https://trapi.raiaccept.com/orders/{id}/transactions/{txId}/refund`

---

## Phoca Cart Events

| Event | Purpose |
|---|---|
| `onPCPbeforeProceedToPayment` | Validation before payment |
| `onPCPbeforeEmptyCartAfterOrder` | Prevent cart clearing before payment confirmation |
| `onPCPbeforeSetPaymentForm` | Create RaiAccept order + session, redirect customer |
| `onPCPafterRecievePayment` | Handle customer return (success/fail/cancel) |
| `onPCPonPaymentWebhook` | Process RaiAccept webhook notification |
| `onPCPbeforeCheckPayment` | Alternative webhook handler |
| `onPCPgetPaymentBranchInfoAdminList` | Refund panel in admin orders list |
| `onAjaxRaiaccept` | Ajax handler for admin refund |

### Note on `onPCPgetPaymentBranchInfoAdminList`

This event uses the Joomla 6 PSR-14 dispatcher (sends an `Event` object), unlike other Phoca Cart payment events which use the legacy direct-parameter style. The plugin registers this listener explicitly in the constructor:

```php
$subject->addListener(
    'onPCPgetPaymentBranchInfoAdminList',
    [$this, 'onPCPgetPaymentBranchInfoAdminList']
);
```

The refund panel appears in the orders list only when filtering by payment method (**Filter Options → Payment: Raiffeisen**).

---

## Admin Refund

The refund button appears in **Phoca Cart → Orders** when filtering by the Raiffeisen payment method. It shows:

- **Refund input + button** — when `rai_transaction_id` is present and available amount > 0
- **"Transaction ID missing"** — when transaction ID is not saved (use `rai_check.php` on dev servers)
- **"Fully Refunded"** — when the full amount has already been refunded

Refund is processed via `com_ajax` → `onAjaxRaiaccept()`, which:
1. Reads credentials from `#__phocacart_payment_methods`
2. Calls RaiAccept refund API
3. Updates `rai_refunded_amount` in `params_payment`
4. Sets order status to Refunded or Partially Refunded

---

## Key Implementation Notes

### Webhook Order Lookup

`PhocacartOrder::getOrderIdByOrderNumber()` is not available in webhook context. Direct DB query used instead:

```php
$orderId = (int) $db->setQuery(
    $db->getQuery(true)
       ->select('id')
       ->from('#__phocacart_orders')
       ->where('order_number = ' . $db->quote($merchantOrderRef))
)->loadResult();
```

### Payment ID in Webhook URL

Must be embedded in `notificationUrl`:

```
.../index.php?option=com_phocacart&view=response
    &task=response.paymentwebhook&type=raiaccept&pid={paymentId}
```

### Cognito Authentication

Must use direct cURL with `Content-Type: application/x-amz-json-1.1` and `X-Amz-Target` headers.

### Phoca Cart Bootstrap in Ajax Context

Phoca Cart classes are not auto-loaded in `com_ajax` requests:

```php
require_once JPATH_ADMINISTRATOR . '/components/com_phocacart/libraries/bootstrap.php';
```

### Order Total Amount

Stored in `#__phocacart_order_total`, not in `#__phocacart_orders`:

```php
$db->getQuery(true)
   ->select('t.amount_currency')
   ->from('#__phocacart_order_total AS t')
   ->where('t.order_id = ' . $orderId)
   ->where('t.type = ' . $db->quote('brutto'))
```

### Admin Credentials in Ajax Context

Payment credentials are in `#__phocacart_payment_methods`, not `#__extensions`.

---

## Diagnostic Tool (`rai_check.php`)

For development servers where RaiAccept cannot reach the webhook URL.

**Upload to site root, use, then delete immediately.**

Usage: `https://yoursite.com/rai_check.php?order_id=X`

- Shows `rai_order_id` and `rai_transaction_id`
- Fetches transaction list from RaiAccept API
- Allows manually saving `rai_transaction_id` to the database

Not needed on production — webhooks arrive automatically.

---

## Namespace

Currently uses `YourVendor\Plugin\Pcp\RaiAccept` — replace before production deployment.

---

## License

GNU General Public License version 3 or later
