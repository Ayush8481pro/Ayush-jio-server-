<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Content-Type: text/plain");
header("Access-Control-Allow-Origin: *");

$DEBUG = isset($_REQUEST['debug']) && $_REQUEST['debug'] == '1';

// ---------------------------------------------------------------------
// 1. Read and sanitize inputs
// ---------------------------------------------------------------------
$id      = trim($_REQUEST['id']      ?? '');
$ck      = trim($_REQUEST['ck']      ?? '');
$jiocred = trim($_REQUEST['jiocred'] ?? '');
$credkey = trim($_REQUEST['credkey'] ?? '');
$chid    = trim($_REQUEST['chid']    ?? '');   // optional channel ID for credential fallback

if (empty($id)) {
    http_response_code(400);
    exit("Missing required parameter: id");
}

// ---------------------------------------------------------------------
// 2. Determine the initial cookie
// ---------------------------------------------------------------------
$initialCookie = '';

// Option A: Use provided ck (hex or plain)
if (!empty($ck)) {
    if (ctype_xdigit($ck) && strlen($ck) % 2 === 0) {
        $decoded = hex2bin($ck);
        if ($decoded !== false) {
            $initialCookie = $decoded;
        }
    }
    if ($initialCookie === '') {
        $initialCookie = $ck; // plain cookie string
    }
}

// Option B: Generate from jiocred + credkey
if (empty($initialCookie)) {
    if (empty($jiocred) || empty($credkey)) {
        http_response_code(400);
        exit("Either 'ck' or both 'jiocred' and 'credkey' must be provided.");
    }
    if (empty($chid)) {
        http_response_code(400);
        exit("Missing 'chid' parameter: actual channel ID is required when using jiocred/credkey.");
    }
    try {
        $initialCookie = generate_initial_cookie_from_credentials($jiocred, $credkey, $chid, $DEBUG);
    } catch (Exception $e) {
        http_response_code(500);
        exit("Failed to generate cookie from credentials: " . $e->getMessage());
    }
}

// ---------------------------------------------------------------------
// 3. Prepare CDN URL and headers
// ---------------------------------------------------------------------
$headers = [
    'Cookie: ' . $initialCookie,
    'Content-Type: application/x-www-form-urlencoded',
    'User-Agent: plaYtv/7.1.3 (Linux;Android 14) ExoPlayerLib/2.11.7'
];

$chs = explode('-', $id);
if (count($chs) < 2) {
    http_response_code(400);
    exit("Invalid id parameter format. Expected: ChannelName-...");
}
$url = sprintf("https://jiotvmblive.cdn.jio.com/bpk-tv/%s/Fallback/%s", $chs[0], $id);

// ---------------------------------------------------------------------
// 4. Step 1: Initial request (may set/refresh CDN cookies)
// ---------------------------------------------------------------------
if ($DEBUG) {
    echo "=== Step 1: Initial Request ===\n";
    echo "URL: $url\n";
    echo "Headers: " . json_encode($headers) . "\n\n";
}
$response1 = cUrlGetDataWithDetails($url, $headers, null, $DEBUG);
if ($DEBUG) {
    echo "Step 1 Response:\n";
    echo "Status: " . $response1['http_code'] . "\n";
    echo "Headers:\n" . $response1['headers'] . "\n";
    echo "Body:\n" . $response1['body'] . "\n\n";
}

// ---------------------------------------------------------------------
// 5. Step 2: Fetch fresh __hdnea__ cookie
// ---------------------------------------------------------------------
if ($DEBUG) {
    echo "=== Step 2: Fetch Fresh Cookie ===\n";
}
try {
    $freshCookieHex = get_and_refresh_cookie($url, $headers, $DEBUG);
    if ($DEBUG) {
        echo "Fresh cookie hex: $freshCookieHex\n";
    } else {
        echo $freshCookieHex;
    }
} catch (Exception $e) {
    http_response_code(500);
    if ($DEBUG) {
        echo "Error: " . $e->getMessage() . "\n";
    } else {
        echo "Error: " . $e->getMessage();
    }
}

// =====================================================================
// Helper functions (with optional debug)
// =====================================================================

/**
 * Perform a cURL request and return an array containing body, status code, and headers.
 */
function cUrlGetDataWithDetails($url, $headers = null, $post_fields = null, $debug = false)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HEADER, 1); // include headers in output
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    if (!empty($post_fields)) {
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
    }

    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception("cURL Error: $err");
    }

    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $response_headers = substr($response, 0, $header_size);
    $response_body = substr($response, $header_size);
    curl_close($ch);

    return [
        'http_code' => $http_code,
        'headers'   => $response_headers,
        'body'      => $response_body,
    ];
}

/**
 * Simple cURL GET (legacy) – kept for compatibility, but not used in debug mode.
 */
function cUrlGetData($url, $headers = null, $post_fields = null)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    if (!empty($post_fields)) {
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
    }

    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $data = curl_exec($ch);
    if (curl_errno($ch)) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception("cURL Error: $err");
    }
    curl_close($ch);
    return $data;
}

/**
 * Extract cookies from raw HTTP response headers.
 */
function extractCookies($header)
{
    $cookies = [];
    foreach (explode("\r\n", $header) as $line) {
        if (preg_match('/^Set-Cookie:\s*([^;]*)/mi', $line, $matches)) {
            parse_str($matches[1], $cookie);
            $cookies = array_merge($cookies, $cookie);
        }
    }
    return $cookies;
}

/**
 * Get cookies from a URL (using cUrlGetDataWithDetails to capture status/headers).
 */
function getCookiesFromUrl($url, $headers = [], $post_fields = null, $debug = false)
{
    $response = cUrlGetDataWithDetails($url, $headers, $post_fields, $debug);
    return extractCookies($response['headers']);
}

/**
 * Get a fresh __hdnea__ cookie from the CDN and return it hex‑encoded.
 */
function get_and_refresh_cookie($url, $headers, $debug = false)
{
    if ($debug) {
        echo "Making second request to: $url\n";
    }
    $cookies = getCookiesFromUrl($url, $headers, null, $debug);
    if ($debug) {
        echo "Cookies found: " . json_encode($cookies) . "\n";
    }
    if (isset($cookies['__hdnea__'])) {
        return bin2hex('__hdnea__=' . $cookies['__hdnea__']);
    }
    throw new Exception("Cookie '__hdnea__' not found in response.");
}

/**
 * Generate initial cookie from Jio credentials (with debug).
 */
function generate_initial_cookie_from_credentials($jiocred, $credkey, $channelId, $debug = false)
{
    $decoded = base64_decode(strtr($jiocred, '-_', '+/'));
    if ($decoded === false) {
        throw new Exception("Invalid jiocred: not valid base64.");
    }
    $cred = json_decode($decoded, true);
    if (!is_array($cred)) {
        throw new Exception("Invalid jiocred: JSON decode failed.");
    }

    $authToken    = $cred['authToken'] ?? '';
    $ssoToken     = $cred['ssoToken'] ?? '';
    $deviceId     = $cred['deviceId'] ?? '';
    $sessionAttr  = $cred['sessionAttributes']['user'] ?? [];
    $crm          = $sessionAttr['subscriberId'] ?? '';
    $uniqueId     = $sessionAttr['unique'] ?? '';

    if (empty($authToken) || empty($crm) || empty($uniqueId) || empty($deviceId)) {
        throw new Exception("Missing required fields in jiocred.");
    }

    $post_data = http_build_query([
        'stream_type' => 'Seek',
        'channel_id'  => $channelId,
    ]);

    $api_headers = [
        "Host: jiotvapi.media.jio.com",
        "Content-Type: application/x-www-form-urlencoded",
        "appkey: NzNiMDhlYzQyNjJm",
        "channel_id: $channelId",
        "userid: $crm",
        "crmid: $crm",
        "deviceId: $deviceId",
        "devicetype: phone",
        "isott: true",
        "languageId: 6",
        "lbcookie: 1",
        "os: android",
        "osversion: 14",
        "srno: 250918144000",
        "accesstoken: $authToken",
        "ssotoken: $ssoToken",
        "subscriberid: $crm",
        "uniqueId: $uniqueId",
        "content-length: " . strlen($post_data),
        "usergroup: tvYR7NSNn7rymo3F",
        "User-Agent: okhttp/4.12.13",
        "versionCode: 452",
    ];

    $apiUrl = "https://jiotvapi.media.jio.com/playback/apis/v1/geturl?langId=6";

    if ($debug) {
        echo "=== Jio API Request (Credential Fallback) ===\n";
        echo "URL: $apiUrl\n";
        echo "POST Data: $post_data\n";
        echo "Headers: " . json_encode($api_headers) . "\n\n";
    }

    $apiCookies = getCookiesFromUrl($apiUrl, $api_headers, $post_data, $debug);

    if ($debug) {
        echo "Jio API Cookies: " . json_encode($apiCookies) . "\n";
    }

    if (isset($apiCookies['__hdnea__'])) {
        return '__hdnea__=' . $apiCookies['__hdnea__'];
    }

    throw new Exception("Could not obtain '__hdnea__' cookie from Jio API.");
}
?>
