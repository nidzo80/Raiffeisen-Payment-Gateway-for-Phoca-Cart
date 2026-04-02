# plg_pcp_raiaccept

**RaiAccept (Raiffeisen Bank Serbia) payment gateway plugin for Phoca Cart (Joomla 6)**

---

## Overview

This plugin integrates RaiAccept — Raiffeisen Bank Serbia's online payment gateway — with the Phoca Cart e-commerce component for Joomla 6. It was built from scratch since Raiffeisen Bank Serbia only provides an official WooCommerce,OpenCart, Magento 2 and PrestaShop plugin.

The plugin handles the full payment lifecycle: checkout redirect, success/fail/cancel return handling, webhook processing, and refund triggering from the Phoca Cart admin orders list.

---

## Architecture

The plugin follows the Phoca Cart 6 payment plugin conventions, using the new Joomla 6 service provider pattern (`services/provider.php`) and PSR-4 autoloading.

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
    └── en-GB/
        ├── plg_pcp_raiaccept.ini
        └── plg_pcp_raiaccept.sys.ini
```

---

## RaiAccept API Flow

Authentication is handled via Amazon Cognito (direct cURL, not SDK):

```
POST https://authenticate.raiaccept.com
Content-Type: application/x-amz-json-1.1
X-Amz-Target: AWSCognitoIdentityProviderService.InitiateAuth
ClientId: kr2gs4117arvbnaperqff5dml
```

Full payment flow:

1. **Authenticate** → obtain `IdToken`
2. **Create order** → `POST https://trapi.raiaccept.com/orders`
3. **Create session** → `POST https://trapi.raiaccept.com/orders/{id}/checkout` → returns `paymentRedirectURL`
4. **Redirect customer** to RaiAccept hosted payment page
5. **Customer returns** via `successUrl` / `failUrl` / `cancelUrl`
6. **Webhook** arrives at `notificationUrl` with transaction result
7. **Refund** → `POST https://trapi.raiaccept.com/orders/{id}/transactions/{txId}/refund`

---

## Phoca Cart Events Implemented

| Event | Purpose |
|---|---|
| `onPCPbeforeProceedToPayment` | Validation before payment |
| `onPCPbeforeEmptyCartAfterOrder` | Prevent cart clearing before payment confirmation |
| `onPCPbeforeSetPaymentForm` | Create RaiAccept order + session, redirect customer |
| `onPCPafterRecievePayment` | Handle customer return (success/fail/cancel) |
| `onPCPonPaymentWebhook` | Process RaiAccept webhook notification |
| `onPCPbeforeCheckPayment` | Alternative webhook handler |
| `onAjaxRaiaccept` | Ajax handler for admin refund |

---

## Key Implementation Details

### Webhook Order Lookup

`PhocacartOrder::getOrderIdByOrderNumber()` is **not available** in the webhook request context in Phoca Cart 6. We use a direct DB query instead:

```php
$db      = Factory::getContainer()->get(DatabaseInterface::class);
$orderId = (int) $db->setQuery(
    $db->getQuery(true)
       ->select('id')
       ->from('#__phocacart_orders')
       ->where('order_number = ' . $db->quote($merchantOrderRef))
)->loadResult();
```

### Payment ID in Webhook URL

Phoca Cart does not pass `pid` (payment method ID) to the webhook handler automatically unless it is embedded in the `notificationUrl`:

```
notificationUrl = site/index.php?option=com_phocacart&view=response
    &task=response.paymentwebhook&type=raiaccept&pid={paymentId}
```

### Cognito Authentication

The RaiAccept authentication endpoint is an Amazon Cognito User Pool. It **must** be called with direct cURL using the exact `Content-Type: application/x-amz-json-1.1` header and `X-Amz-Target` header. Generic HTTP helper classes will not work.

### Transaction ID Storage

The plugin stores `rai_transaction_id` in `params_payment` (JSON field in `#__phocacart_orders`) immediately when the PURCHASE webhook arrives. This ID is required for issuing refunds.

On development servers where RaiAccept cannot reach the webhook URL, `rai_transaction_id` can be recovered manually via the included diagnostic script `rai_check.php`.

### One-Click Checkout

For logged-in Joomla users, the plugin automatically enables RaiAccept one-click checkout by including:

```php
'recurring' => [
    'customerReference' => 'joomla-user-' . $userId,
    'recurringModel'    => 'ONE_CLICK_CHECKOUT',
]
```

### Currency

All amounts are sent in the shop's active currency. The `currency_exchange_rate` from the order is used to convert product prices from the default currency.

### Country Codes

RaiAccept requires ISO 3166-1 alpha-3 country codes (`SRB`, `DEU`, etc.). The plugin includes a conversion map from ISO 2-letter codes.

---

## Admin Refund (Work in Progress)

### Goal

Add a refund button to the Phoca Cart admin orders list that triggers a refund via the RaiAccept API without leaving the page.

### Approach

Per advice from the Phoca Cart developer (Jan Pavelka), the correct approach is:

1. Display the refund button via the `GetPaymentBranchInfoAdminList` Phoca Cart event, which fires for each order row in the admin orders list (`com_phocacart`, `view=phocacartorders`)
2. Trigger the refund via Joomla's standard `com_ajax` feature, handled by `onAjaxRaiaccept()` in the plugin

### Current Status — `GetPaymentBranchInfoAdminList` Not Firing

The plugin implements `onPCPgetPaymentBranchInfoAdminList()` but the method is **never called**. The Phoca Cart `Dispatcher` dispatches the event correctly:

```php
// From: administrator/components/com_phocacart/tmpl/phocacartorders/default.php
$results = Dispatcher::dispatch(new Event\Payment\GetPaymentBranchInfoAdminList(
    'com_phocacart.phocacartorders', $item, $paymentInfo, [
        'pluginname' => $paramsPayment['method'],
    ]
));
```

The event class:

```php
// Phoca\PhocaCart\Event\Payment\GetPaymentBranchInfoAdminList
parent::__construct('pcp', 'onPCPgetPaymentBranchInfoAdminList', [
    'context'       => $context,
    'order'         => $order,
    'paymentMethod' => $paymentMethod,
    'eventData'     => $eventData,
]);
```

This is a **Joomla 6 PSR-14 event** (uses `ResultAware` + `ResultTypeArrayAware` traits). The method must receive a `\Joomla\Event\Event` object and return results via `$event->addResult()`.

We have tried:
- Method signature `onPCPgetPaymentBranchInfoAdminList(\Joomla\Event\Event $event): void` with `$event->addResult([...])`
- Implementing `SubscriberInterface` + `getSubscribedEvents()`
- Overriding `registerListeners()` with explicit `addListener()`
- Explicit `addListener()` in `services/provider.php`

None of these caused the method to be called.

**Open question for the Phoca Cart developer:** How should a `pcp` payment plugin register a listener for `GetPaymentBranchInfoAdminList` in Joomla 6? Which registration mechanism does Phoca Cart expect?

---

## Plugin Parameters

| Parameter | Description | Default |
|---|---|---|
| `sandbox` | Use sandbox API endpoints | Yes |
| `api_username` | RaiAccept API username | — |
| `api_password` | RaiAccept API password | — |
| `status_completed` | Order status ID for completed payment | 6 |
| `status_failed` | Order status ID for failed payment | 7 |
| `status_canceled` | Order status ID for canceled payment | 3 |
| `status_refunded` | Order status ID for full refund | 5 |
| `status_part_refunded` | Order status ID for partial refund | 5 |

---

## Diagnostic Tool

`rai_check.php` is a standalone script (upload to site root, then delete after use) that:

- Displays `rai_order_id` and `rai_transaction_id` for a given Phoca Cart order
- Fetches the transaction list from the RaiAccept API
- Allows manually saving `rai_transaction_id` to the database (needed on dev servers where webhooks cannot reach)

Usage: `https://yoursite.com/rai_check.php?order_id=X`

---

## Environment

- **Joomla**: 6.x
- **Phoca Cart**: 6.0.0
- **PHP**: 8.2+
- **Tested on**: Serbian market (RSD currency, Serbian billing addresses)

---

## Namespace

Currently uses `YourVendor\Plugin\Pcp\RaiAccept` — replace with your own vendor namespace before production use.

---

## License

GNU General Public License version 3 or later
