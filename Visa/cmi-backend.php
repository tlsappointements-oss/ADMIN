<?php
/**
 * VisaMa — CMI Payment Backend
 * ════════════════════════════════════════════════════════════════════
 *
 * This file handles:
 *   1. Hash generation  (action=hash)   — called by the browser before redirect
 *   2. OK callback      (action=ok)     — CMI redirects user here on success
 *   3. Fail callback    (action=fail)   — CMI redirects user here on failure
 *   4. Server callback  (action=callback) — CMI posts server-to-server confirmation
 *
 * DEPLOY: Place this file at the root of your web server (same domain as visa-portal.html).
 * HTTPS is mandatory — CMI will not accept HTTP endpoints.
 *
 * HOW CMI WORKS:
 *   1. Browser sends all form fields to this file (action=hash)
 *   2. This file computes SHA-512(storekey + field1 + field2 + ... + storekey)
 *      using the EXACT field order defined by CMI (ver3 algorithm)
 *   3. Returns { hash, gatewayUrl } as JSON
 *   4. Browser builds a hidden POST form and submits to CMI gateway
 *   5. User completes 3D-Secure on CMI's hosted page
 *   6. CMI redirects to okUrl or failUrl AND posts to callbackUrl
 *   7. This file handles those callbacks, verifies the hash, updates Firestore
 *
 * ────────────────────────────────────────────────────────────────────
 * CREDENTIALS — fill in your values from CMI back-office:
 * ────────────────────────────────────────────────────────────────────
 */

define('CMI_CLIENT_ID',  'YOUR_CMI_CLIENT_ID');   // Identifiant marchand (CMI back-office)
define('CMI_STORE_KEY',  'YOUR_CMI_STORE_KEY');   // Clé du magasin    (CMI back-office) ← KEEP SECRET
define('CMI_GATEWAY_URL','https://payment.cmi.co.ma/fim/est3Dgate');   // Production
// define('CMI_GATEWAY_URL','https://testpayment.cmi.co.ma/fim/est3Dgate'); // Sandbox/test

// Firebase Admin SDK (for updating Firestore from the callback)
// Install with: composer require kreait/firebase-php
// Or use the REST API below (no composer needed)
define('FIREBASE_PROJECT_ID', 'cars-website-558c0');
define('FIREBASE_API_KEY',    'AIzaSyDnp4fC2_cEw04ydtWOwYgVzRUsqScufFs'); // web API key

// URLs — must match what is registered in CMI back-office
define('OK_URL',       'https://YOUR_DOMAIN/payment-success.html');
define('FAIL_URL',     'https://YOUR_DOMAIN/payment-fail.html');
define('SHOP_URL',     'https://YOUR_DOMAIN');
define('CALLBACK_URL', 'https://YOUR_DOMAIN/cmi-backend.php?action=callback');

// Logging (optional but recommended)
define('LOG_FILE', __DIR__ . '/cmi-log.txt');

// ════════════════════════════════════════════════════════════════════

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

$action = $_GET['action'] ?? 'hash';

if ($action === 'hash') {
    handleHashRequest();
} elseif ($action === 'callback') {
    handleCmiCallback();
} elseif ($action === 'ok') {
    handleOkRedirect();
} elseif ($action === 'fail') {
    handleFailRedirect();
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
}

// ════════════════════════════════════════════════════════════════════
// 1. HASH GENERATION
// ════════════════════════════════════════════════════════════════════

function handleHashRequest(): void
{
    header('Content-Type: application/json');

    // Only allow POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }

    // Read JSON body from browser
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON body']);
        return;
    }

    // Required fields
    $required = ['clientid','oid','amount','okUrl','failUrl','shopurl','CallbackURL'];
    foreach ($required as $f) {
        if (empty($input[$f])) {
            http_response_code(400);
            echo json_encode(['error' => "Missing required field: $f"]);
            return;
        }
    }

    // Force clientid to match server config (prevent spoofing)
    $input['clientid'] = CMI_CLIENT_ID;

    // Generate hash
    $hash = generateCmiHash($input);

    logCmi('HASH_REQUEST', [
        'oid'    => $input['oid'],
        'amount' => $input['amount'],
        'hash'   => substr($hash, 0, 16) . '...',
    ]);

    echo json_encode([
        'hash'       => $hash,
        'gatewayUrl' => CMI_GATEWAY_URL,
    ]);
}

/**
 * CMI ver3 Hash Algorithm — SHA-512
 *
 * The hash is computed as:
 *   sha512( storekey | field1_val | field2_val | ... | fieldN_val | storekey )
 *
 * Fields are sorted alphabetically by key name (CMI ver3 spec).
 * The storekey is prepended AND appended.
 */
function generateCmiHash(array $params): string
{
    $storekey = CMI_STORE_KEY;

    // Remove hash-related fields if present
    unset($params['HASH'], $params['hashAlgorithm'], $params['encoding']);

    // Sort params alphabetically by key (CMI ver3 requirement)
    ksort($params);

    // Build the string: storekey|val1|val2|...|valN|storekey
    $parts = [$storekey];
    foreach ($params as $val) {
        $parts[] = $val;
    }
    $parts[] = $storekey;

    $hashStr = implode('|', $parts);

    return hash('sha512', $hashStr);
}

// ════════════════════════════════════════════════════════════════════
// 2. SERVER-TO-SERVER CALLBACK (CMI POSTs here after payment)
// ════════════════════════════════════════════════════════════════════

function handleCmiCallback(): void
{
    // CMI sends POST with payment result
    $postData = $_POST;
    logCmi('CALLBACK_RECEIVED', $postData);

    if (empty($postData)) {
        http_response_code(400);
        echo 'APPROVED'; // Always respond with APPROVED to stop CMI retrying
        return;
    }

    $oid    = $postData['oid']          ?? '';
    $resp   = $postData['Response']     ?? '';
    $amount = $postData['amount']       ?? '';
    $hash   = $postData['HASH']         ?? '';

    // Verify the hash from CMI to confirm authenticity
    $verifyParams = $postData;
    unset($verifyParams['HASH']);
    $expectedHash = generateCmiHash($verifyParams);

    if (!hash_equals($expectedHash, strtolower($hash))) {
        logCmi('CALLBACK_HASH_MISMATCH', ['oid' => $oid, 'received' => $hash, 'expected' => $expectedHash]);
        http_response_code(200);
        echo 'APPROVED'; // Must always respond APPROVED
        return;
    }

    // Update Firestore via REST API
    $approved = ($resp === 'Approved');
    updateFirestoreApplication($oid, [
        'paymentStatus'  => $approved ? 'paid' : 'failed',
        'status'         => $approved ? 'pending' : 'rejected',
        'cmiResponse'    => $resp,
        'cmiAuthCode'    => $postData['AuthCode']      ?? '',
        'cmiProcReturnCode' => $postData['ProcReturnCode'] ?? '',
        'cmiTranDate'    => $postData['EXTRA_TRXDATE']  ?? '',
        'paymentConfirmedAt' => date('c'),
    ]);

    logCmi('CALLBACK_PROCESSED', ['oid' => $oid, 'status' => $approved ? 'APPROVED' : 'FAILED']);

    // CMI requires this exact response
    echo 'APPROVED';
}

// ════════════════════════════════════════════════════════════════════
// 3. USER REDIRECT — OK (payment succeeded)
// ════════════════════════════════════════════════════════════════════

function handleOkRedirect(): void
{
    $oid   = $_GET['ref']   ?? ($_POST['oid']   ?? '');
    $docId = $_GET['docId'] ?? '';

    logCmi('OK_REDIRECT', ['oid' => $oid]);

    // Redirect to success page
    header('Location: ' . OK_URL . '?ref=' . urlencode($oid) . '&docId=' . urlencode($docId));
    exit;
}

// ════════════════════════════════════════════════════════════════════
// 4. USER REDIRECT — FAIL (payment failed or cancelled)
// ════════════════════════════════════════════════════════════════════

function handleFailRedirect(): void
{
    $oid   = $_GET['ref']   ?? ($_POST['oid']   ?? '');
    $docId = $_GET['docId'] ?? '';
    $resp  = $_POST['Response'] ?? 'Failed';

    logCmi('FAIL_REDIRECT', ['oid' => $oid, 'response' => $resp]);

    // Update Firestore
    if ($oid) {
        updateFirestoreApplication($oid, [
            'paymentStatus' => 'failed',
            'cmiResponse'   => $resp,
        ]);
    }

    header('Location: ' . FAIL_URL . '?ref=' . urlencode($oid) . '&reason=' . urlencode($resp));
    exit;
}

// ════════════════════════════════════════════════════════════════════
// FIRESTORE REST UPDATE (no SDK needed)
// ════════════════════════════════════════════════════════════════════

/**
 * Finds the application document by cmiOrderId == $oid and patches it.
 * Uses Firestore REST API with the project's web API key (public key — safe to use server-side).
 */
function updateFirestoreApplication(string $oid, array $fields): void
{
    if (empty($oid)) return;

    $projectId = FIREBASE_PROJECT_ID;
    $apiKey    = FIREBASE_API_KEY;

    // 1. Query for the document by cmiOrderId
    $queryUrl = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents:runQuery?key={$apiKey}";
    $query = [
        'structuredQuery' => [
            'from'  => [['collectionId' => 'applications']],
            'where' => [
                'fieldFilter' => [
                    'field'    => ['fieldPath' => 'cmiOrderId'],
                    'op'       => 'EQUAL',
                    'value'    => ['stringValue' => $oid],
                ],
            ],
            'limit' => 1,
        ],
    ];

    $ch = curl_init($queryUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($query),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    $results = json_decode($resp, true);
    if (empty($results[0]['document']['name'])) {
        logCmi('FIRESTORE_DOC_NOT_FOUND', ['oid' => $oid]);
        return;
    }

    $docName = $results[0]['document']['name'];

    // 2. Build the update mask and fields
    $updateFields = [];
    foreach ($fields as $key => $val) {
        $updateFields[$key] = ['stringValue' => (string)$val];
    }
    $updateMask = implode(',', array_keys($fields));

    $patchUrl = "https://firestore.googleapis.com/v1/{$docName}?key={$apiKey}&updateMask.fieldPaths=" . implode('&updateMask.fieldPaths=', array_keys($fields));

    $ch2 = curl_init($patchUrl);
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'PATCH',
        CURLOPT_POSTFIELDS     => json_encode(['fields' => $updateFields]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $patchResp = curl_exec($ch2);
    curl_close($ch2);

    logCmi('FIRESTORE_UPDATED', ['oid' => $oid, 'fields' => array_keys($fields)]);
}

// ════════════════════════════════════════════════════════════════════
// LOGGING
// ════════════════════════════════════════════════════════════════════

function logCmi(string $event, array $data): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $event . ' ' . json_encode($data) . PHP_EOL;
    @file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}
