<?php
/**
 * RaiAccept Diagnostic Tool
 *
 * Use this script on development servers where RaiAccept cannot deliver webhooks.
 * It allows you to verify a transaction and manually save the transaction ID to the database.
 *
 * USAGE:
 * 1. Enter your RaiAccept API credentials below
 * 2. Upload this file to your Joomla site root
 * 3. Open https://yoursite.com/rai_check.php?order_id=X
 * 4. DELETE THIS FILE IMMEDIATELY after use
 *
 * WARNING: Never leave this file on a production server.
 */

// ============================================================
// CONFIGURE YOUR CREDENTIALS HERE
// ============================================================
define('RAI_USERNAME', '');   // Your RaiAccept API username
define('RAI_PASSWORD', '');   // Your RaiAccept API password
define('RAI_SANDBOX',  true); // true = sandbox, false = production
// ============================================================

// RaiAccept Cognito Client ID (public, same for all merchants)
define('RAI_COGNITO_CLIENT_ID', 'kr2gs4117arvbnaperqff5dml');

define('RAI_AUTH_URL', 'https://authenticate.raiaccept.com');
define('RAI_API_URL',  RAI_SANDBOX ? 'https://trapi.raiaccept.com' : 'https://api.raiaccept.com');

if (!defined('_JEXEC')) {
    define('_JEXEC', 1);
}

if (!file_exists(__DIR__ . '/includes/defines.php')) {
    die('Error: Joomla not found. Make sure this file is in your Joomla root directory.');
}

define('JPATH_BASE', __DIR__);
require_once __DIR__ . '/includes/defines.php';
require_once __DIR__ . '/includes/framework.php';

$app = \Joomla\CMS\Factory::getApplication('site');

// ============================================================

function rai_authenticate(): string
{
    if (empty(RAI_USERNAME) || empty(RAI_PASSWORD)) {
        return '';
    }

    $payload = json_encode([
        'AuthFlow'       => 'USER_PASSWORD_AUTH',
        'ClientId'       => RAI_COGNITO_CLIENT_ID,
        'AuthParameters' => [
            'USERNAME' => RAI_USERNAME,
            'PASSWORD' => RAI_PASSWORD,
        ],
    ]);

    $ch = curl_init(RAI_AUTH_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-amz-json-1.1',
            'X-Amz-Target: AWSCognitoIdentityProviderService.InitiateAuth',
        ],
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    return $data['AuthenticationResult']['IdToken'] ?? '';
}

function rai_api_get(string $token, string $endpoint): array
{
    $ch = curl_init(RAI_API_URL . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true) ?? [];
}

function rai_save_transaction_id(int $orderId, string $transactionId): bool
{
    $db = \Joomla\CMS\Factory::getDbo();

    $current = $db->setQuery(
        $db->getQuery(true)
           ->select('params_payment')
           ->from('#__phocacart_orders')
           ->where('id = ' . $orderId)
    )->loadResult();

    $params = !empty($current) ? json_decode($current, true) : [];
    $params['raiaccept']['rai_transaction_id'] = $transactionId;

    $obj                 = new stdClass;
    $obj->id             = $orderId;
    $obj->params_payment = json_encode($params);

    return $db->updateObject('#__phocacart_orders', $obj, 'id');
}

// ============================================================

$orderId    = (int) ($_GET['order_id'] ?? 0);
$doSave     = isset($_GET['save']) && $_GET['save'] === '1';
$txIdToSave = trim($_GET['tx_id'] ?? '');

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>RaiAccept Diagnostic</title>
<style>
body { font-family: monospace; padding: 2rem; background: #f5f5f5; }
.box { background: #fff; border: 1px solid #ddd; padding: 1.5rem; margin-bottom: 1rem; border-radius: 4px; }
.ok   { color: green;  font-weight: bold; }
.err  { color: red;    font-weight: bold; }
.warn { color: orange; font-weight: bold; }
pre   { background: #f0f0f0; padding: 1rem; overflow-x: auto; border-radius: 4px; }
a     { color: #0066cc; }
input[type=text] { padding: 0.4rem; width: 300px; font-family: monospace; }
button { padding: 0.4rem 1rem; cursor: pointer; }
</style>
</head>
<body>

<div class="box">
    <h2>RaiAccept Diagnostic Tool</h2>
    <p class="warn">⚠️ DELETE THIS FILE after use. Never leave it on a production server.</p>
    <p>Environment: <strong><?= RAI_SANDBOX ? 'SANDBOX' : 'PRODUCTION' ?></strong></p>
    <?php if (empty(RAI_USERNAME)): ?>
    <p class="err">⚠️ Credentials not configured. Open this file and fill in RAI_USERNAME and RAI_PASSWORD.</p>
    <?php endif; ?>
</div>

<?php if ($orderId < 1): ?>
<div class="box">
    <h3>Enter Order ID</h3>
    <form method="get">
        <label>Phoca Cart Order ID (numeric ID, not order number):</label><br><br>
        <input type="text" name="order_id" placeholder="e.g. 42" autofocus>
        <button type="submit">Check</button>
    </form>
</div>

<?php else: ?>
<div class="box">
    <h3>Order #<?= $orderId ?></h3>
    <?php
    $db  = \Joomla\CMS\Factory::getDbo();
    $row = $db->setQuery(
        $db->getQuery(true)
           ->select('id, order_number, status_id, params_payment')
           ->from('#__phocacart_orders')
           ->where('id = ' . $orderId)
    )->loadObject();

    if (!$row) {
        echo '<p class="err">Order not found.</p>';
    } else {
        $params     = !empty($row->params_payment) ? json_decode($row->params_payment, true) : [];
        $raiData    = $params['raiaccept'] ?? [];
        $raiOrderId = $raiData['rai_order_id']       ?? null;
        $raiTransId = $raiData['rai_transaction_id'] ?? null;

        echo '<p><strong>Order number:</strong> ' . htmlspecialchars($row->order_number) . '</p>';
        echo '<p><strong>Status ID:</strong> ' . (int)$row->status_id . '</p>';
        echo '<p><strong>rai_order_id:</strong> ' . ($raiOrderId
            ? htmlspecialchars($raiOrderId)
            : '<span class="err">N/A</span>') . '</p>';
        echo '<p><strong>rai_transaction_id:</strong> ' . ($raiTransId
            ? '<span class="ok">' . htmlspecialchars($raiTransId) . '</span>'
            : '<span class="err">N/A</span>') . '</p>';

        $token = '';
        if (!empty(RAI_USERNAME) && !empty(RAI_PASSWORD)) {
            $token = rai_authenticate();
            echo '<p><strong>API Auth:</strong> ' . ($token
                ? '<span class="ok">OK</span>'
                : '<span class="err">Failed — check credentials</span>') . '</p>';
        }

        if ($token && $raiOrderId) {
            $transactions = rai_api_get($token, '/orders/' . $raiOrderId . '/transactions');

            echo '<h4>Transactions (raw):</h4>';
            echo '<pre>' . htmlspecialchars(json_encode($transactions, JSON_PRETTY_PRINT)) . '</pre>';

            echo '<h4>Transactions:</h4>';
            $purchaseTx = null;
            foreach ((array)$transactions as $tx) {
                $tx = (array)$tx;
                echo '<p>ID: <code>' . htmlspecialchars($tx['transactionId'] ?? '') . '</code>'
                    . ' | Type: '   . htmlspecialchars($tx['transactionType'] ?? '')
                    . ' | Status: ' . htmlspecialchars($tx['status'] ?? '')
                    . ' | Amount: ' . htmlspecialchars($tx['transactionAmount'] ?? '')
                    . ' '           . htmlspecialchars($tx['transactionCurrency'] ?? '')
                    . '</p>';
                if (($tx['transactionType'] ?? '') === 'PURCHASE' && ($tx['status'] ?? '') === 'SUCCESS') {
                    $purchaseTx = $tx;
                }
            }

            if ($purchaseTx) {
                $foundTxId = $purchaseTx['transactionId'];
                echo '<p class="ok">Purchase Transaction ID found: <code>' . htmlspecialchars($foundTxId) . '</code></p>';

                if (!$raiTransId) {
                    echo '<p><a href="?order_id=' . $orderId . '&save=1&tx_id=' . urlencode($foundTxId) . '">💾 Save transaction ID to database</a></p>';
                } else {
                    echo '<p class="ok">Transaction ID already saved in database.</p>';
                }
            } else {
                echo '<p class="warn">No successful PURCHASE transaction found.</p>';
            }
        } elseif (!$raiOrderId) {
            echo '<p class="warn">No rai_order_id saved for this order.</p>';
        }

        if ($doSave && $txIdToSave) {
            $saved = rai_save_transaction_id($orderId, $txIdToSave);
            echo $saved
                ? '<p class="ok">✅ SAVED! Transaction ID saved: <code>' . htmlspecialchars($txIdToSave) . '</code></p>'
                : '<p class="err">❌ Failed to save transaction ID.</p>';
        }
    }
    ?>
    <br><a href="?">← Check another order</a>
</div>
<?php endif; ?>

</body>
</html>
