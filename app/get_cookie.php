<?php
// index.php - Generate fresh universal __hdnea__ from existing __hdnea__ cookie
// Self-contained: no external files required.
error_reporting(0);

// Helper: hex to string
function hex2str($hex) {
    $s = hex2bin($hex);
    if ($s === false) { http_response_code(400); exit("Invalid hex string"); }
    return $s;
}

// Helper: extract cookies from HTTP headers
function extractCookiesFromHeader($headerText) {
    $cookies = [];
    foreach (explode("\r\n", $headerText) as $line) {
        if (preg_match('/^Set-Cookie:\s*([^;]*)/i', $line, $m)) {
            parse_str($m[1], $cookie);
            $cookies = array_merge($cookies, $cookie);
        }
    }
    return $cookies;
}

// Helper: decrypt credentials (if jiocred/credkey provided)
function decrypt_data($e_data, $key) {
    $key = (int) $key;
    $encrypted = base64_decode($e_data);
    $decrypted = array_map(fn($char) => chr(ord($char) - $key), str_split($encrypted));
    return implode('', $decrypted);
}

// Get parameters
$ck = $_REQUEST['ck'] ?? '';
$id = $_REQUEST['id'] ?? '';
$jiocred_hex = $_REQUEST['jiocred'] ?? '';
$credkey_hex = $_REQUEST['credkey'] ?? '';

if (empty($ck)) {
    http_response_code(400);
    exit("Missing ck parameter");
}

// Decode the provided __hdnea__ cookie
$cookie = hex2str($ck);

// Base headers (User-Agent is mandatory)
$headers = [
    "Cookie: $cookie",
    "User-Agent: plaYtv/7.1.3 (Linux;Android 14) ExoPlayerLib/2.11.7"
];

// Optionally add additional JioTV headers from encrypted credentials
if (!empty($jiocred_hex) && !empty($credkey_hex)) {
    $jiocred = hex2str($jiocred_hex);
    $credkey = hex2str($credkey_hex);
    $cred = json_decode(decrypt_data($jiocred, $credkey), true);
    if ($cred) {
        $access_token = $cred['authToken'] ?? '';
        $crm = $cred['sessionAttributes']['user']['subscriberId'] ?? '';
        $device_id = $cred['deviceId'] ?? '';
        $unique_id = $cred['sessionAttributes']['user']['unique'] ?? '';
        $sso_token = $cred['sessionAttributes']['user']['ssoToken'] ?? '';

        $headers = array_merge($headers, [
            "accesstoken: $access_token",
            "appkey: NzNiMDhlYcQyNjJm",
            "crmid: $crm",
            "deviceId: $device_id",
            "devicetype: phone",
            "isott: true",
            "languageId: 6",
            "lbcookie: 1",
            "os: android",
            "osVersion: 14",
            "srno: 250918144000",
            "ssotoken: $sso_token",
            "subscriberId: $crm",
            "uniqueId: $unique_id",
            "usergroup: tvYR7NSNn7rymo3F",
            "versionCode: 452",
            "Origin: https://www.jiocinema.com",
            "Referer: https://www.jiocinema.com/",
        ]);
    }
}

// Determine URL to request.
// If id is provided, use the channel fallback URL (like your stream.php did).
// Otherwise, try the CDN root or a generic path which often issues universal tokens.
if (!empty($id)) {
    $parts = explode('-', $id, 2);
    $channel = $parts[0];
    $url = "https://jiotvmblive.cdn.jio.com/bpk-tv/{$channel}/Fallback/{$id}";
} else {
    // Try common endpoints that give universal cookies
    $url = "https://jiotvmblive.cdn.jio.com/";
}

// Make two requests to ensure cookie refresh
$allCookies = [];
for ($i = 0; $i < 2; $i++) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 10
    ]);
    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headerText = substr($response, 0, $headerSize);
    curl_close($ch);
    $cookies = extractCookiesFromHeader($headerText);
    $allCookies = array_merge($allCookies, $cookies);
}

if (isset($allCookies['__hdnea__'])) {
    $hdnea = '__hdnea__=' . $allCookies['__hdnea__'];
    echo bin2hex($hdnea);
} else {
    http_response_code(500);
    echo "Error: __hdnea__ not found. Try adding jiocred/credkey or change the URL.";
}
?>
