<?php

// * Copyright 2021-2025 SnehTV, Inc.
// * Licensed under MIT (https://github.com/mitthu786/TS-JioTV/blob/main/LICENSE)
// * Created By : TechieSneh

error_reporting(0);
include "cpfunctions.php";

// Server configuration
$protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://';
$local_ip  = getHostByName(php_uname('n'));
$host_jio  = (!in_array($_SERVER['SERVER_ADDR'], ['127.0.0.1', 'localhost']))
    ? $_SERVER['HTTP_HOST']
    : $local_ip;
$host_jio .= (strpos($host_jio, $_SERVER['SERVER_PORT']) === false) ? ':' . $_SERVER['SERVER_PORT'] : '';

$jio_path = sprintf(
    "%s%s%s",
    $protocol,
    $host_jio,
    str_replace(basename($_SERVER['PHP_SELF']), '', str_replace(" ", "%20", $_SERVER['PHP_SELF']))
);

// Common headers
header("Access-Control-Allow-Origin: *");
header("Access-Control-Expose-Headers: Content-Length,Content-Range");
header("Access-Control-Allow-Headers: Range");
header("Accept-Ranges: bytes");

// Get credentials
$cred        = getCRED();
$jio_cred    = json_decode($cred, true);
$ssoToken    = $jio_cred['ssoToken'] ?? '';
$accessToken = $jio_cred['authToken'] ?? '';
$crm         = $jio_cred['sessionAttributes']['user']['subscriberId'] ?? '';
$uniqueId    = $jio_cred['sessionAttributes']['user']['unique'] ?? '';
$device_id   = $jio_cred['deviceId'] ?? '';

// Request parameters
$srno  = $_REQUEST['srno'] ?? '';
$id    = $_REQUEST['id'] ?? '';
$begin = $_REQUEST['begin'] ?? '';
$end   = $_REQUEST['end'] ?? '';

// API request setup
$post_data = http_build_query([
    'stream_type' => 'Catchup',
    'channel_id'  => $id,
    'programId'   => $srno,
    'showtime'    => '000000',
    'srno'        => $srno,
    'begin'       => $begin,
    'end'         => $end
]);

$headers_api = [
    "Host: jiotvapi.media.jio.com",
    "Content-Type: application/x-www-form-urlencoded",
    "appkey: NzNiMDhlYzQyNjJm",
    "channel_id: $id",
    "userid: $crm",
    "crmid: $crm",
    "deviceId: $device_id",
    "devicetype: phone",
    "isott: true",
    "languageId: 6",
    "lbcookie: 1",
    "os: android",
    "dm: Xiaomi 22101316UP",
    "osversion: 14",
    "srno: $srno",
    "accesstoken: $accessToken",
    "subscriberid: $crm",
    "uniqueId: $uniqueId",
    "content-length: " . strlen($post_data),
    "usergroup: tvYR7NSNn7rymo3F",
    "User-Agent: okhttp/4.12.13",
    "versionCode: 452",
];

// API call
$response = cUrlGetData("https://jiotvapi.media.jio.com/playback/apis/v1/geturl?langId=6", $headers_api, $post_data);
$haystack = json_decode($response);

if (($haystack->code ?? 0) !== 200) {
    refresh_token();
    header("Location: {$_SERVER['REQUEST_URI']}");
    exit;
}

// ========== MODIFICATION: OUTPUT RAW URL AS TEXT ==========
header("Content-Type: text/plain; charset=utf-8");
echo $haystack->result;
exit;
// ==========================================================