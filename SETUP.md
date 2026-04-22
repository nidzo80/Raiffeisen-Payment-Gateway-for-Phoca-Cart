# About RaiAccept
[RaiAccept](https://portal.raiaccept.com/) lets you receive card payments and issue refunds for your consumers’ orders they create with your e-commerce platform.

RaiAccept is a safe, secure, easy to use, PCI-DSS and PSD2 compliant payment gateway with responsive UX and advanced Merchant portal with tracking and reporting capabilities.

Whether you are using one of the popular off-the-shelf shop systems or a custom solution, RaiAccept is always simple to integrate.

Start accepting payments and provide your consumers a seamless shopping experience with RaiAccept.
# Supported Banks and Languages

## Supported Acquiring Banks

The table below shows the banks of the Raiffeisen group that RaiAccept is connected with.
If you have a business account in one of the banks below, you can accept e-commerce payments
in the supported countries and currencies.

| Bank | Supported countries | Supported currencies |
|---|---|---|
| Raiffeisen Payment Acceptance Solutions | Austria, Italy, Germany, Poland, Czechia, Slovakia, Slovenia, Hungary, Romania | ALL, AUD, BGN, CAD, CHF, CZK, DKK, EUR, GBP, HUF, ILS, NOK, PLN, RON, RSD, SEK, TRY, USD |
| Raiffeisen Bank Albania | Albania | ALL, EUR |
| Raiffeisen Bank Croatia | Croatia | EUR |
| Raiffeisen Bank Kosovo | Kosovo | EUR |
| Raiffeisen Bank Serbia | Serbia | RSD |

## Supported Languages

The RaiAccept payment form is available in the following languages:

| Code | Language |
|---|---|
| `al` | Albanian |
| `hr` | Croatian |
| `cs` | Czech |
| `en` | English |
| `fr` | French |
| `de` | German |
| `it` | Italian |
| `pl` | Polish |
| `ro` | Romanian |
| `sr` | Serbian |
| `sk` | Slovak |

---

*Source: [docs.raiaccept.com](https://docs.raiaccept.com)*


# RaiAccept — Getting Started

This guide covers everything you need to set up a RaiAccept sandbox account, generate API credentials, and start testing the plugin.

For full official documentation, visit [docs.raiaccept.com](https://docs.raiaccept.com).

---

## 1. Register and log in

Register for a RaiAccept account through your Raiffeisen bank branch. Once registered, log in to the [RaiAccept Merchant Portal](https://portal.raiaccept.com/).

When you first register, you have access to the **Sandbox environment** where you can test payments without processing real money.

---

## 2. Generate API Credentials

1. Log in to the [Merchant Portal](https://portal.raiaccept.com/)
2. Make sure the **Sandbox/Production** switch (bottom left) is set to **Sandbox**
3. Click **API Credentials**
4. Click **New API Credentials**
5. Enter a description (e.g. `Phoca Cart dev`) and click **Create New API Credentials**
6. Copy and save your **Username** and **Password** — they are shown only once
7. Check **I have copied and saved securely username and password** and click **Close**

> ⚠️ Use separate credentials for Sandbox and Production environments.

---

## 3. Configure the Plugin

1. Go to **Phoca Cart → Payment** in your Joomla admin
2. Add a new payment method and select **RaiAccept** as the plugin
3. Enter the **API Username** and **API Password** from step 2
4. Make sure **Sandbox Mode** is set to **Yes**
5. Configure order statuses as needed (see [README.md](README.md))
6. Save and publish the payment method

---

## 4. Test Payments

Use the following test cards in the Sandbox environment. For all cards use:
- **Card holder name:** Any name
- **CVV:** Any 3-digit number
- **Expiration date:** Any valid future date (`MM/YY`)

### Visa

| Card number | Result |
|---|---|
| 4999 9999 9999 0011 | ✅ Payment successful (no 3DS) |
| 4999 9999 9999 0029 | ❌ Payment declined — generic decline |
| 4999 9999 9999 0060 | ❌ Payment declined — expired card |

### Mastercard

| Card number | Result |
|---|---|
| 5559 4900 0000 0007 | ✅ Payment successful (no 3DS) |
| 5559 4900 0000 0114 | ❌ Payment declined — insufficient funds |
| 5559 4900 0000 0239 | ❌ Payment declined — incorrect CVV |

### DinaCard

Only available for clients of Raiffeisen banka Srbije, RSD payments only.

| Card number | Result |
|---|---|
| 9891 0077 0000 0001 | ✅ Payment successful |
| 6573 7177 0000 0022 | ✅ Payment successful |

> ⚠️ Test cards work only in the Sandbox environment.

---

## 5. Go Live

When your account is activated by the bank:

1. Log in to the Merchant Portal and switch to **Production** environment
2. Generate a new set of **Production API credentials**
3. In Phoca Cart → Payment → RaiAccept, enter the Production credentials
4. Set **Sandbox Mode** to **No**
5. Test with a real card for a small amount to verify the integration

---

## Notes for Development Servers

On development servers (e.g. local or staging with a non-public URL), RaiAccept cannot deliver webhooks because it cannot reach your server. In this case:

- Order status will **not** be updated automatically after payment
- Use the included `rai_check.php` diagnostic script to manually save the transaction ID and verify the order
- Upload `rai_check.php` to your site root, open `https://yoursite.com/rai_check.php?order_id=X`, then delete it immediately after use

On a production server with a public URL, webhooks are delivered automatically and no manual intervention is needed.
