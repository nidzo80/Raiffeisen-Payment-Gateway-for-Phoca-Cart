<?php
/**
 * @package    Plg_Pcp_RaiAccept
 * @author     Generated for Phoca Cart
 * @license    GNU General Public License version 3 or later
 */

namespace YourVendor\Plugin\Pcp\RaiAccept\Extension;

use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseAwareInterface;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Registry\Registry;
use YourVendor\Plugin\Pcp\RaiAccept\Helper\ShopHelper;
use YourVendor\Plugin\Pcp\RaiAccept\Helper\ApiHelper;

\defined('_JEXEC') or die;

/**
 * RaiAccept Payment Plugin for Phoca Cart.
 *
 * Flow:
 * 1. onPCPbeforeProceedToPayment  - validacija pre plaćanja
 * 2. onPCPbeforeEmptyCartAfterOrder - ne prazni korpu odmah
 * 3. onPCPbeforeSetPaymentForm    - kreira RaiAccept order+sesiju, redirect
 * 4. onPCPafterRecievePayment     - kupac se vratio sa payment forme (success/fail/cancel)
 * 5. onPCPonPaymentWebhook        - RaiAccept šalje webhook notifikaciju
 *
 * @since  1.0.0
 */
final class RaiAccept extends CMSPlugin implements DatabaseAwareInterface
{
    use DatabaseAwareTrait;

    /**
     * Override registerListeners da eksplicitno registrujemo PSR-14 event.
     *
     * @since 1.0.0
     */
    public function registerListeners(): void
    {
        parent::registerListeners();

        // Eksplicitno registrujemo PSR-14 event koji šalje Event objekat
        $this->getDispatcher()->addListener(
            'onPCPgetPaymentBranchInfoAdminList',
            [$this, 'onPCPgetPaymentBranchInfoAdminList']
        );
    }

    /**
     * Registruje event listenere.
     * Potrebno za Joomla 5 PSR-14 evente koji šalju Event objekat.
     *
     * @since 1.0.0
     */
    /**
     * @var bool
     * @since 1.0.0
     */
    protected $autoloadLanguage = true;

    /**
     * @var string
     * @since 1.0.0
     */
    protected string $name = 'raiaccept';

    // -------------------------------------------------------------------------
    // Phoca Cart Event Handlers
    // -------------------------------------------------------------------------

    /**
     * Validacija pre prelaska na plaćanje.
     *
     * @since 1.0.0
     */
    public function onPCPbeforeProceedToPayment(&$proceed, &$message, $eventData): bool
    {
        if (!$this->isMyPlugin($eventData)) {
            return true;
        }

        $proceed = 1;

        $errors = $this->initCheck();

        if (!empty($errors)) {
            $this->getApplication()->enqueueMessage(implode('<br>', $errors), 'error');
            $proceed = 0;
        }

        return true;
    }

    /**
     * Ne prazniti korpu odmah - čekamo potvrdu plaćanja.
     *
     * @since 1.0.0
     */
    public function onPCPbeforeEmptyCartAfterOrder(
        string &$proceed,
        array &$pluginData,
        Registry $componentParams,
        ?Registry $paymentParams,
        object $order,
        array $eventData = []
    ): bool {
        if (!$this->isMyPlugin($eventData)) {
            return false;
        }

        $pluginData['emptycart'] = false;

        return true;
    }

    /**
     * Kreira RaiAccept order i sesiju, redirect kupca na payment formu.
     *
     * @since 1.0.0
     */
    public function onPCPbeforeSetPaymentForm(
        string &$form,
        Registry $paramsC,
        Registry $params,
        array $order,
        array $eventData = []
    ): bool {
        if (!$this->isMyPlugin($eventData)) {
            return false;
        }

        $orderId   = (int) $order['common']->id;
        $paymentId = (isset($order['common']->payment_id) && (int) $order['common']->payment_id > 0)
            ? (int) $order['common']->payment_id
            : 0;

        // Sprečavamo dvostruku naplatu
        if ($this->isOrderCharged($orderId)) {
            ShopHelper::redirectToCart(Text::_('PLG_PCP_RAIACCEPT_ORDER_PROCESSED_ALREADY'), 'info');
            return true;
        }

        $amount   = ShopHelper::getOrderAmount($orderId);
        $orderNr  = \PhocacartOrder::getOrderNumber($orderId);
        $apiHelper = new ApiHelper($this->getCredentials($paymentId));

        try {
            // Korak 1: Autentifikacija
            $token = $apiHelper->authenticate();

            // Korak 2: Kreiranje ordera
            $orderPayload   = $this->buildOrderPayload($order, $orderId, $amount, $orderNr, $amount['rate']);
            $orderResult    = $apiHelper->createOrder($token, $orderPayload);
            $orderIdRai     = $orderResult['orderIdentification'];

            // Korak 3: Kreiranje payment sesije
            $sessionResult  = $apiHelper->createSession($token, $orderIdRai, $orderPayload);
            $redirectUrl    = $sessionResult['paymentRedirectURL'];

            // Čuvamo RaiAccept order ID u bazi
            ShopHelper::saveInternalData($orderId, [
                'rai_order_id' => $orderIdRai,
            ], $this->name);


            // Redirect kupca na RaiAccept payment formu
            $this->getApplication()->redirect($redirectUrl);

        } catch (Exception $e) {
            ShopHelper::addLog(2, 'Payment - RaiAccept - ERROR', $orderId, $e->getMessage());
            ShopHelper::redirectToCart($e->getMessage());
        }

        return true;
    }

    /**
     * Kupac se vratio sa payment forme (success / fail / cancel URL).
     *
     * @since 1.0.0
     */
    public function onPCPafterRecievePayment(int $mid, array &$message, array $eventData): void
    {
        if (!$this->isMyPlugin($eventData)) {
            return;
        }

        $input          = $this->getApplication()->getInput();
        $redirectStatus = $input->get('redirect_status', '');
        $orderId        = (int) $input->get('orderId', 0);
        $paymentId      = (int) $input->get('pid', 0);

        if ($redirectStatus === 'cancel' || $redirectStatus === 'failed') {
            // Postavljamo status ordera na canceled/failed
            if ($orderId > 0 && $paymentId > 0) {
                $statuses = $this->getOrderStatuses($paymentId);
                $statusId = $redirectStatus === 'cancel'
                    ? $statuses['canceled']
                    : $statuses['failed'];
                ShopHelper::setOrderStatus($orderId, $statusId,
                    strtoupper($redirectStatus));
            }

            $this->getApplication()->redirect(
                Uri::root()
                . 'index.php?option=com_phocacart&view=response&task=response.paymentcancel&type=raiaccept&tmpl=component'
            );
            return;
        }

        // SUCCESS: praznimo korpu.
        // Webhook postavlja finalni status - ali ako webhook ne stigne
        // (npr. dev server), postavljamo status i ovde kao backup.
        ShopHelper::emptyCart();

        if ($orderId > 0 && $paymentId > 0) {
            $statuses = $this->getOrderStatuses($paymentId);

            // Verifikujemo status direktno od RaiAccept API-ja
            $paymentData = ShopHelper::getPaymentData($orderId);
            $raiOrderId  = $paymentData[$this->name]['rai_order_id'] ?? null;

            if ($raiOrderId) {
                try {
                    $credentials = $this->getCredentials($paymentId);
                    $apiHelper   = new ApiHelper($credentials);
                    $token       = $apiHelper->authenticate();
                    $orderStatus = $apiHelper->getOrderStatus($token, $raiOrderId);
                    $finalStatus = $orderStatus['status'] ?? '';

                    if ($finalStatus === 'PAID') {
                        // Čuvamo da webhook ne duplikuje
                        ShopHelper::saveInternalData($orderId, [
                            'rai_status_set_on_return' => true,
                        ], $this->name);
                        ShopHelper::setOrderStatus($orderId, $statuses['completed'], 'COMPLETED');
                    }
                } catch (Exception $e) {
                    ShopHelper::addLog(2, 'Payment - RaiAccept - ERROR return verify', $orderId,
                        $e->getMessage());
                }
            }
        }
    }

    /**
     * Webhook notifikacija od RaiAccept.
     *
     * @since 1.0.0
     */
    public function onPCPonPaymentWebhook(int $pid, array $eventData): void
    {
        http_response_code(200);

        // Fallback: ako pluginname nije postavljen (stari URL bez &type=),
        // provjeravamo da li je RaiAccept payload po strukturi
        if (!$this->isMyPlugin($eventData)) {
            $rawInput = @file_get_contents('php://input');
            $rawInput = ($rawInput !== false) ? $rawInput : '';
            $testData = json_decode($rawInput, true);

            // Ako payload ima RaiAccept strukturu (transaction + order), obrađujemo ga
            if (!empty($testData['transaction']['transactionId']) && !empty($testData['order']['orderIdentification'])) {
                $this->processWebhook($pid, $rawInput);
            }
            return;
        }

        // emptyCart u try/catch - webhook nema korisnicku sesiju pa moze baciti exception
        try { ShopHelper::emptyCart(); } catch (\Throwable $e) {}

        $payload = @file_get_contents('php://input');
        $payload  = ($payload !== false) ? $payload : '';

        if (empty($payload)) {
            ShopHelper::addLog(2, 'Payment - RaiAccept - ERROR webhook', 0, 'Empty payload');
            return;
        }

        $this->processWebhook($pid, $payload);
    }

    /**
     * Webhook handler - alternativni event koji Phoca Cart okida za notifikacije.
     *
     * @since 1.0.0
     */
    public function onPCPbeforeCheckPayment(int $pid, array $eventData): void
    {

        if (!$this->isMyPlugin($eventData)) {
            return;
        }

        http_response_code(200);

        ShopHelper::emptyCart();

        $payload = @file_get_contents('php://input');

        if (empty($payload)) {
            ShopHelper::addLog(2, 'Payment - RaiAccept - ERROR check payment', 0, 'Empty payload');
            return;
        }

        $this->processWebhook($pid, $payload);
    }

    // -------------------------------------------------------------------------
    // Private methods
    // -------------------------------------------------------------------------

    /**
     * Procesira webhook payload od RaiAccept.
     *
     * @since 1.0.0
     */
    private function processWebhook(int $pid, string $payload): void
    {
        $data = json_decode($payload, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            ShopHelper::addLog(2, 'Payment - RaiAccept - ERROR webhook', 0, 'Invalid JSON payload');
            return;
        }

        $merchantOrderRef  = $data['order']['invoice']['merchantOrderReference'] ?? null;
        $transactionStatus = $data['transaction']['status'] ?? null;
        $transactionType   = $data['transaction']['transactionType'] ?? null;
        $transactionId     = $data['transaction']['transactionId'] ?? null;
        $statusCode        = $data['transaction']['statusCode'] ?? null;

        if (!$merchantOrderRef) {
            ShopHelper::addLog(2, 'Payment - RaiAccept - ERROR webhook', 0,
                'merchantOrderReference missing');
            return;
        }

        // Pronalazimo order ID direktnim DB upitom
        // (PhocacartOrder::getOrderIdByOrderNumber nije dostupan u webhook kontekstu)
        try {
            $db      = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
            $orderId = (int) $db->setQuery(
                $db->getQuery(true)
                   ->select('id')
                   ->from('#__phocacart_orders')
                   ->where('order_number = ' . $db->quote($merchantOrderRef))
            )->loadResult();
        } catch (\Throwable $e) {
            ShopHelper::addLog(2, 'Payment - RaiAccept - ERROR webhook', 0, 'DB error: ' . $e->getMessage());
            return;
        }

        if (!$orderId) {
            ShopHelper::addLog(2, 'Payment - RaiAccept - ERROR webhook', 0,
                'Order not found for ref: ' . $merchantOrderRef);
            return;
        }

        // Odmah čuvamo transaction ID u bazi čim stigne - bez obzira na finalStatus
        // Ovo je važno jer getOrderStatus može vratiti FULLY_REFUNDED pa ne ulazimo u PURCHASE case
        if (!empty($transactionId) && $transactionType === 'PURCHASE') {
            ShopHelper::saveInternalData($orderId, [
                'rai_transaction_id' => $transactionId,
                'rai_status_code'    => $statusCode,
            ], $this->name);
        }

        // Ako Phoca Cart nije proslijedio pid (pid=0), dohvatamo ga iz baze
        if ($pid < 1) {
            $db     = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
            $pid    = (int) $db->setQuery(
                $db->getQuery(true)
                   ->select('payment_id')
                   ->from('#__phocacart_orders')
                   ->where('id = ' . (int) $orderId)
            )->loadResult();
        }
        try {
            $statuses = $this->getOrderStatuses($pid);
        } catch (\Throwable $e) {
            ShopHelper::addLog(2, 'Payment - RaiAccept - ERROR getOrderStatuses', $orderId, $e->getMessage() . ' ' . $e->getFile() . ':' . $e->getLine());
            // Koristimo default statuse
            $statuses = ['completed' => 6, 'failed' => 7, 'canceled' => 3, 'refunded' => 5, 'part_refunded' => 5];
        }

        // Verifikujemo finalni status direktno od API-ja
        $paymentData = ShopHelper::getPaymentData($orderId);
        $raiOrderId  = $paymentData[$this->name]['rai_order_id'] ?? null;

        if ($raiOrderId) {
            try {
                $credentials = $this->getCredentials($pid);
                $apiHelper   = new ApiHelper($credentials);
                $token       = $apiHelper->authenticate();
                $orderStatus = $apiHelper->getOrderStatus($token, $raiOrderId);
                $finalStatus = $orderStatus['status'] ?? $transactionStatus;
            } catch (Exception $e) {
                ShopHelper::addLog(2, 'Payment - RaiAccept - ERROR webhook verify', $orderId,
                    $e->getMessage());
                $finalStatus = $transactionStatus;
            }
        } else {
            $finalStatus = $transactionStatus;
        }

        if ($transactionType === 'REFUND') {
            // Za refund koristimo transaction status (SUCCESS/FAILED)
            // i order status (PARTIALLY_REFUNDED/FULLY_REFUNDED) odvojeno
            $orderFinalStatus = isset($orderStatus) ? ($orderStatus['status'] ?? '') : '';

            // Ako rai_transaction_id nije sačuvan (stari order), pokušavamo dohvatiti iz transakcija
            $pd = ShopHelper::getPaymentData($orderId);
            if (empty($pd[$this->name]['rai_transaction_id']) && !empty($raiOrderId)) {
                try {
                    $txList = $apiHelper->getTransactions($token, $raiOrderId);
                    // Tražimo PURCHASE transakciju sa SUCCESS statusom
                    foreach ((array) $txList as $tx) {
                        $tx = (array) $tx;
                        if (($tx['transactionType'] ?? '') === 'PURCHASE' && ($tx['status'] ?? '') === 'SUCCESS') {
                            ShopHelper::saveInternalData($orderId, [
                                'rai_transaction_id' => $tx['transactionId'],
                            ], $this->name);
                            break;
                        }
                    }
                } catch (\Exception $e) {
                    ShopHelper::addLog(2, 'Payment - RaiAccept - ERROR refund tx recovery', $orderId, $e->getMessage());
                }
            }

            $this->processRefundWebhook($orderId, $transactionStatus, $orderFinalStatus, $statuses, $transactionId);
            return;
        }

        // Procesiranje kupovine
        switch ($finalStatus) {
            case 'PAID':
            case 'SUCCESS':
                // Čuvamo transaction ID
                ShopHelper::saveInternalData($orderId, [
                    'rai_transaction_id' => $transactionId,
                    'rai_status_code'    => $statusCode,
                ], $this->name);

                // Postavljamo status samo ako nije već postavljen na success returnu
                $pd = ShopHelper::getPaymentData($orderId);
                if (empty($pd[$this->name]['rai_status_set_on_return'])) {
                    ShopHelper::setOrderStatus($orderId, $statuses['completed'], 'COMPLETED');
                }
                ShopHelper::addLog(1, 'Payment - RaiAccept - SUCCESS', $orderId,
                    'Payment completed. Transaction: ' . $transactionId);
                break;

            case 'FAILED':
                ShopHelper::setOrderStatus($orderId, $statuses['failed'], 'FAILED');
                ShopHelper::addLog(1, 'Payment - RaiAccept - FAILED', $orderId,
                    'Payment failed. Status code: ' . $statusCode);
                break;

            case 'CANCELED':
            case 'ABANDONED':
                ShopHelper::setOrderStatus($orderId, $statuses['canceled'], 'CANCELED');
                ShopHelper::addLog(1, 'Payment - RaiAccept - CANCELED', $orderId,
                    'Payment canceled.');
                break;

            default:
        }
    }

    /**
     * Procesira refund webhook.
     *
     * @since 1.0.0
     */
    private function processRefundWebhook(
        int $orderId,
        string $transactionStatus,
        string $orderStatus,
        array $statuses,
        ?string $transactionId
    ): void {


        if ($transactionStatus !== 'SUCCESS') {
            ShopHelper::addLog(1, 'Payment - RaiAccept - REFUND FAILED', $orderId,
                'Refund transaction status: ' . $transactionStatus);
            return;
        }

        // Biramo status na osnovu toga da li je parcijalni ili potpuni refund
        $pcpStatusId = match($orderStatus) {
            'PARTIALLY_REFUNDED' => $statuses['part_refunded'],
            'FULLY_REFUNDED'     => $statuses['refunded'],
            default              => $statuses['refunded'], // ako ne znamo, stavljamo refunded
        };

        $statusLabel = $orderStatus === 'PARTIALLY_REFUNDED' ? 'PARTIALLY REFUNDED' : 'REFUNDED';

        ShopHelper::setOrderStatus($orderId, $pcpStatusId, $statusLabel);
        ShopHelper::addLog(1, 'Payment - RaiAccept - REFUNDED', $orderId,
            $statusLabel . '. Transaction: ' . $transactionId);
    }

    /**
     * Gradi payload za RaiAccept order API.
     *
     * @since 1.0.0
     */
    private function buildOrderPayload(array $order, int $orderId, array $amount, string $orderNr, float $rate = 1.0): array
    {
        $baseUrl = Uri::root();

        $paymentId  = (int) ($order['common']->payment_id ?? 0);
        $successUrl = $baseUrl . 'index.php?option=com_phocacart&view=response&task=response.paymentrecieve&type=raiaccept&tmpl=component&orderId=' . $orderId . '&pid=' . $paymentId . '&redirect_status=success';
        $failUrl    = $baseUrl . 'index.php?option=com_phocacart&view=response&task=response.paymentrecieve&type=raiaccept&tmpl=component&orderId=' . $orderId . '&pid=' . $paymentId . '&redirect_status=failed';
        $cancelUrl  = $baseUrl . 'index.php?option=com_phocacart&view=response&task=response.paymentrecieve&type=raiaccept&tmpl=component&orderId=' . $orderId . '&pid=' . $paymentId . '&redirect_status=cancel';
        $webhookUrl = $baseUrl . 'index.php?option=com_phocacart&view=response&task=response.paymentwebhook&type=raiaccept&pid=' . $paymentId;

        // Debug: logujemo $order strukturu da vidimo tačna polja

        $b = $order['bas']['b'] ?? [];
        $s = $order['bas']['s'] ?? $b;

        // Phoca Cart Form Fields koristi name_first / name_last
        // address_1, phone_1 itd. - sa fallback na stare kljuceve
        $billingFirst   = trim($b['name_first'] ?? $b['firstname'] ?? '');
        $billingLast    = trim($b['name_last']  ?? $b['lastname']  ?? '');
        $billingStreet  = trim(($b['address_1'] ?? $b['address'] ?? '') . ' ' . ($b['address_2'] ?? $b['address2'] ?? ''));
        $billingCity    = trim($b['city']    ?? '');
        $billingZip     = trim($b['zip']     ?? $b['postal_code'] ?? '');
        $billingCountry = $this->getCountryIso3(trim($b['country_code_2'] ?? $b['country_code'] ?? ''));
        $email          = trim($b['email']   ?? '');
        $phone          = trim($b['phone_1'] ?? $b['phone'] ?? '');

        // Ako shipping nema ime (guest bez posebne shipping adrese), kopiramo iz billing-a
        $shippingFirst   = trim($s['name_first'] ?? $s['firstname'] ?? '') ?: $billingFirst;
        $shippingLast    = trim($s['name_last']  ?? $s['lastname']  ?? '') ?: $billingLast;
        $shippingStreet  = trim(($s['address_1'] ?? $s['address'] ?? '') . ' ' . ($s['address_2'] ?? $s['address2'] ?? '')) ?: $billingStreet;
        $shippingCity    = trim($s['city']    ?? '') ?: $billingCity;
        $shippingZip     = trim($s['zip']     ?? $s['postal_code'] ?? '') ?: $billingZip;
        $shippingCountry = $this->getCountryIso3(trim($s['country_code_2'] ?? $s['country_code'] ?? '')) ?: $billingCountry;

        // Formatiranje telefona - RaiAccept prihvata +381 ili 00381 format, bez razmaka
        if (!empty($phone) && !str_starts_with($phone, '+') && !str_starts_with($phone, '00')) {
            $phone = '+381' . ltrim($phone, '0');
        }

        // Stavke ordera
        $items = [];
        if (!empty($order['products'])) {
            foreach ($order['products'] as $product) {
                // Phoca Cart: brutto/dbrutto su u default valuti shopa
                // Mnozimo sa exchange rate da dobijemo cenu u valuti transakcije
                $dbrutto = (float) ($product->dbrutto ?? 0);
                $brutto  = (float) ($product->brutto  ?? 0);
                $price   = round(($dbrutto > 0 ? $dbrutto : $brutto) * $rate, 2);
                $qty   = (int) ($product->quantity ?? $product->qty ?? 1);
                $title = (string) ($product->title ?? $product->name ?? 'Product');

                $items[] = [
                    'description'   => $title,
                    'numberOfItems' => $qty,
                    'price'         => $price,
                ];
            }
        }

        if (empty($items)) {
            $items[] = [
                'description'   => 'Order ' . $orderNr,
                'numberOfItems' => 1,
                'price'         => (float) $amount['total'],
            ];
        }

        // Gradimo consumer - samo neprazna polja
        $consumer = ['firstName' => $billingFirst, 'lastName' => $billingLast];
        if (!empty($email)) {
            $consumer['email'] = $email;
        }
        if (!empty($phone)) {
            $consumer['mobilePhone'] = $phone;
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!empty($ip)) {
            $consumer['ipAddress'] = $ip;
        }

        // Gradimo billing adresu - samo neprazna polja
        $billing = [];
        if (!empty($billingFirst))   { $billing['firstName']      = $billingFirst; }
        if (!empty($billingLast))    { $billing['lastName']       = $billingLast; }
        if (!empty($billingStreet))  { $billing['addressStreet1'] = $billingStreet; }
        if (!empty($billingCity))    { $billing['city']           = $billingCity; }
        if (!empty($billingZip))     { $billing['postalCode']     = $billingZip; }
        if (!empty($billingCountry)) { $billing['country']        = $billingCountry; }

        // Gradimo shipping adresu - samo neprazna polja
        $shipping = [];
        if (!empty($shippingFirst))   { $shipping['firstName']      = $shippingFirst; }
        if (!empty($shippingLast))    { $shipping['lastName']       = $shippingLast; }
        if (!empty($shippingStreet))  { $shipping['addressStreet1'] = $shippingStreet; }
        if (!empty($shippingCity))    { $shipping['city']           = $shippingCity; }
        if (!empty($shippingZip))     { $shipping['postalCode']     = $shippingZip; }
        if (!empty($shippingCountry)) { $shipping['country']        = $shippingCountry; }

        $payload = [
            'consumer'                => $consumer,
            'invoice' => [
                'amount'                 => (float) $amount['total'],
                'currency'               => strtoupper($amount['currency']),
                'description'            => 'Phoca Cart order: ' . $orderNr,
                'merchantOrderReference' => (string) $orderNr,
                'items'                  => $items,
            ],
            'paymentMethodPreference' => 'CARD',
            'urls' => [
                'successUrl'      => $successUrl,
                'failUrl'         => $failUrl,
                'cancelUrl'       => $cancelUrl,
                'notificationUrl' => $webhookUrl,
            ],
        ];

        if (!empty($billing))  { $payload['billingAddress']  = $billing; }
        if (!empty($shipping)) { $payload['shippingAddress'] = $shipping; }

        // One-click checkout: samo za ulogovane korisnike (ne guest)
        $userId = (int) ($order['common']->user_id ?? 0);
        if ($userId > 0) {
            $payload['recurring'] = [
                'customerReference' => 'joomla-user-' . $userId,
                'recurringModel'    => 'ONE_CLICK_CHECKOUT',
            ];
        }

        return $payload;
    }

    /**
     * Konvertuje ISO2 kod zemlje u ISO3 (RaiAccept zahteva ISO 3166-1 alpha-3).
     *
     * @since 1.0.0
     */
    private function getCountryIso3(string $iso2): string
    {
        $map = [
            'RS' => 'SRB', 'DE' => 'DEU', 'AT' => 'AUT', 'CH' => 'CHE',
            'HR' => 'HRV', 'BA' => 'BIH', 'SI' => 'SVN', 'SK' => 'SVK',
            'HU' => 'HUN', 'RO' => 'ROU', 'BG' => 'BGR', 'MK' => 'MKD',
            'ME' => 'MNE', 'AL' => 'ALB', 'XK' => 'XKX', 'PL' => 'POL',
            'CZ' => 'CZE', 'FR' => 'FRA', 'IT' => 'ITA', 'ES' => 'ESP',
            'PT' => 'PRT', 'NL' => 'NLD', 'BE' => 'BEL', 'SE' => 'SWE',
            'NO' => 'NOR', 'DK' => 'DNK', 'FI' => 'FIN', 'GB' => 'GBR',
            'US' => 'USA', 'CA' => 'CAN', 'AU' => 'AUS', 'TR' => 'TUR',
            'GR' => 'GRC', 'CY' => 'CYP', 'LU' => 'LUX', 'IE' => 'IRL',
            'UA' => 'UKR', 'RU' => 'RUS',
        ];

        return $map[strtoupper($iso2)] ?? strtoupper($iso2);
    }

    /**
     * Proverava da li je order već naplaćen.
     *
     * @since 1.0.0
     */
    private function isOrderCharged(int $orderId): bool
    {
        $paymentData = ShopHelper::getPaymentData($orderId);
        $internalData = $paymentData[$this->name] ?? null;

        return !empty($internalData['rai_transaction_id']);
    }

    /**
     * Dohvata credentials iz plugin parametara.
     *
     * @since 1.0.0
     */
    private function getCredentials(int $pid): array
    {
        $params  = ShopHelper::getPaymentMethod($pid)->params;
        $sandbox = (bool) $params->get('sandbox', 0);

        return [
            'sandbox'  => $sandbox,
            'username' => trim($params->get('api_username', '')),
            'password' => trim($params->get('api_password', '')),
        ];
    }

    /**
     * Dohvata statuse ordera iz plugin parametara.
     *
     * @since 1.0.0
     */
    private function getOrderStatuses(int $pid): array
    {
        $params = ShopHelper::getPaymentMethod($pid)->params;

        return [
            'completed'     => (int) $params->get('status_completed', 6),
            'failed'        => (int) $params->get('status_failed', 7),
            'canceled'      => (int) $params->get('status_canceled', 3),
            'refunded'      => (int) $params->get('status_refunded', 5),
            'part_refunded' => (int) $params->get('status_part_refunded', 5),
        ];
    }

    /**
     * Proverava minimalne uslove za rad plugina.
     *
     * @since 1.0.0
     */
    private function initCheck(): array
    {
        return [];
    }


    /**
     * Prikazuje RaiAccept refund panel u admin listi orderova.
     *
     * @since 1.0.0
     */
    public function onPCPgetPaymentBranchInfoAdminList(\Joomla\Event\Event $event): void
    {
        $context     = $event->getArgument('context');
        $item        = $event->getArgument('order');
        $paymentInfo = $event->getArgument('paymentMethod');
        $eventData   = $event->getArgument('eventData', []);

        \PhocacartLog::add(1, 'RAI BRANCH', 0,
            'context=' . $context
            . ' pluginname=' . ($eventData['pluginname'] ?? 'none')
            . ' myname=' . $this->name
            . ' match=' . ($this->isMyPlugin($eventData) ? 'YES' : 'NO'));

        if (!$this->isMyPlugin($eventData)) {
            return;
        }

        $paymentData = !empty($item->params_payment)
            ? json_decode($item->params_payment, true)
            : [];

        $raiData    = $paymentData['raiaccept'] ?? [];
        $raiTransId = $raiData['rai_transaction_id'] ?? '';
        $raiOrderId = $raiData['rai_order_id']       ?? '';

        \PhocacartLog::add(1, 'RAI BRANCH 2', 0,
            'raiTransId=' . $raiTransId . ' raiOrderId=' . $raiOrderId);

        if (empty($raiTransId) || empty($raiOrderId)) {
            return;
        }

        $currency        = $item->currency_code ?? 'RSD';
        $rate            = (float) ($item->currency_exchange_rate ?? 1);
        $totalAmount     = (float) ($item->total_amount_currency ?? ($item->total_amount ?? 0) * $rate);
        $alreadyRefunded = (float) ($raiData['rai_refunded_amount'] ?? 0);
        $availableAmount = round($totalAmount - $alreadyRefunded, 2);

        if ($availableAmount <= 0) {
            $event->addResult(['content' => '<div class="badge text-bg-success mt-1">Fully Refunded</div>']);
        return;
        }

        $orderId   = (int) $item->id;
        $paymentId = (int) ($item->payment_id ?? 0);
        $ajaxUrl   = Uri::root() . 'administrator/index.php?option=com_ajax&plugin=raiaccept&group=pcp&format=json&task=refund';
        $csrfToken = \Joomla\CMS\Session\Session::getFormToken();

        $content = '<div class="mt-1" id="rai-panel-' . $orderId . '">'
            . '<div class="input-group input-group-sm">'
            . '<input type="number" class="form-control form-control-sm" id="rai-amount-' . $orderId . '"'
            . ' value="' . $availableAmount . '" min="0.01" max="' . $availableAmount . '" step="0.01" style="max-width:90px">'
            . '<span class="input-group-text">' . htmlspecialchars($currency) . '</span>'
            . '<button type="button" class="btn btn-warning btn-sm" onclick="raiRefund_' . $orderId . '(event)">'
            . '&#8617; Refund</button>'
            . '</div>'
            . '<div id="rai-msg-' . $orderId . '" class="small mt-1"></div>'
            . '</div>'
            . '<script>'
            . 'function raiRefund_' . $orderId . '(e){'
            . 'e.preventDefault();e.stopPropagation();'
            . 'var a=parseFloat(document.getElementById("rai-amount-' . $orderId . '").value);'
            . 'if(isNaN(a)||a<=0||a>' . $availableAmount . '+0.001){'
            . 'document.getElementById("rai-msg-' . $orderId . '").innerHTML="<span class=\'text-danger\'>Invalid amount</span>";return false;}'
            . 'if(!window.confirm("Refund "+a.toFixed(2)+" ' . $currency . ' for order #' . $orderId . '?"))return false;'
            . 'var btn=e.target;btn.disabled=true;btn.innerHTML="...";'
            . 'window.fetch("' . $ajaxUrl . '",{'
            . 'method:"POST",'
            . 'headers:{"Content-Type":"application/json","X-Requested-With":"XMLHttpRequest"},'
            . 'body:JSON.stringify({'
            . 'orderId:' . $orderId . ','
            . 'paymentId:' . $paymentId . ','
            . 'raiOrderId:"' . $raiOrderId . '",'
            . 'raiTransId:"' . $raiTransId . '",'
            . 'amount:a,'
            . 'currency:"' . $currency . '",'
            . '"' . $csrfToken . '":1'
            . '})})'
            . '.then(function(r){return r.json();})'
            . '.then(function(d){'
            . 'btn.disabled=false;btn.innerHTML="&#8617; Refund";'
            . 'var msg=document.getElementById("rai-msg-' . $orderId . '");'
            . 'if(d.success){msg.innerHTML="<span class=\'text-success\'>"+d.message+"</span>";'
            . 'document.getElementById("rai-panel-' . $orderId . '").innerHTML="<span class=\'badge text-bg-warning\'>Refunded</span>";}'
            . 'else{msg.innerHTML="<span class=\'text-danger\'>"+(d.message||"Error")+"</span>";}'
            . '})'
            . '.catch(function(){'
            . 'btn.disabled=false;btn.innerHTML="&#8617; Refund";'
            . 'document.getElementById("rai-msg-' . $orderId . '").innerHTML="<span class=\'text-danger\'>Network error</span>";'
            . '});return false;}'
            . '</script>';

        $event->addResult(['content' => $content]);
    }

    /**
     * Ajax handler za refund iz admin liste orderova.
     * URL: administrator/index.php?option=com_ajax&plugin=raiaccept&group=pcp&format=json&task=refund
     *
     * @since 1.0.0
     */
    public function onAjaxRaiaccept(): array
    {
        $app  = $this->getApplication();
        $user = $app->getIdentity();

        if (!$user->authorise('core.edit', 'com_phocacart')) {
            return ['success' => false, 'message' => 'Unauthorized'];
        }

        $rawBody    = @file_get_contents('php://input');
        $body       = json_decode($rawBody ?: '', true) ?? [];
        $csrfToken  = \Joomla\CMS\Session\Session::getFormToken();

        if (empty($body[$csrfToken])) {
            return ['success' => false, 'message' => 'Invalid token'];
        }

        $orderId    = (int)   ($body['orderId']    ?? 0);
        $paymentId  = (int)   ($body['paymentId']  ?? 0);
        $raiOrderId = trim(   ($body['raiOrderId'] ?? ''));
        $raiTransId = trim(   ($body['raiTransId'] ?? ''));
        $amount     = (float) ($body['amount']     ?? 0);
        $currency   = strtoupper(trim($body['currency'] ?? ''));

        if ($orderId < 1 || empty($raiOrderId) || empty($raiTransId) || $amount <= 0 || empty($currency)) {
            return ['success' => false, 'message' => 'Invalid parameters'];
        }

        try {
            $credentials = $this->getCredentials($paymentId);
            $apiHelper   = new ApiHelper($credentials);
            $authToken   = $apiHelper->authenticate();
            $result      = $apiHelper->refund($authToken, $raiOrderId, $raiTransId, $amount, $currency);
            $refundTxId  = $result['transactionId'] ?? '';

            // Ažuriramo params_payment
            $paymentData = ShopHelper::getPaymentData($orderId);
            $raiData     = $paymentData[$this->name] ?? [];
            $newRefunded = round((float)($raiData['rai_refunded_amount'] ?? 0) + $amount, 2);

            ShopHelper::saveInternalData($orderId, [
                'rai_refunded_amount' => $newRefunded,
                'rai_refund_trans_id' => $refundTxId,
            ], $this->name);

            // Dohvatamo ukupan iznos za određivanje parcijalni/potpuni refund
            $db          = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
            $totalAmount = (float) $db->setQuery(
                $db->getQuery(true)
                   ->select('total_amount_currency')
                   ->from('#__phocacart_orders')
                   ->where('id = ' . $orderId)
            )->loadResult();

            $statuses = $this->getOrderStatuses($paymentId);
            $statusId = ($newRefunded < $totalAmount)
                ? $statuses['part_refunded']
                : $statuses['refunded'];
            $statusLabel = ($newRefunded < $totalAmount) ? 'PARTIALLY REFUNDED' : 'REFUNDED';

            ShopHelper::setOrderStatus($orderId, $statusId, $statusLabel);

            ShopHelper::addLog(1, 'Payment - RaiAccept - REFUND', $orderId,
                'Admin refund: ' . $amount . ' ' . $currency . ' | TX: ' . $refundTxId);

            return [
                'success' => true,
                'message' => 'Refund of ' . number_format($amount, 2) . ' ' . $currency . ' successful.',
            ];

        } catch (Exception $e) {
            ShopHelper::addLog(2, 'Payment - RaiAccept - REFUND ERROR', $orderId, $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }


    /**
     * Provera da li je event za ovaj plugin.
     *
     * @since 1.0.0
     */
    private function isMyPlugin(array $eventData): bool
    {
        // Koristimo != umesto !== zbog mogucih type razlika (string vs string)
        // Isti pristup kao PayPal plugin
        if (!isset($eventData['pluginname']) || $eventData['pluginname'] != $this->name) {
            return false;
        }
        return true;
    }
}
