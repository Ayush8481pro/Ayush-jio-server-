<?php
// Copyright 2021-2025 SnehTV, Inc.
// Licensed under MIT (https://github.com/mitthu786/TS-JioTV/blob/main/LICENSE)
// Created By: TechieSneh

error_reporting(0);
include "functions.php";

// Set response header to JSON
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

// Request variables
$id = htmlspecialchars($_REQUEST['id'] ?? '');
$case = htmlspecialchars($_REQUEST['case'] ?? ''); // Fetch specific case if passed
$haystack = getJioTvData($id);

// Refresh token if response is invalid
if (empty($haystack->code) || $haystack->code !== 200) {
    refresh_token();
    header("Location: {$_SERVER['REQUEST_URI']}");
    exit;
}

// Parse response
[$baseUrl, $query] = array_pad(explode('?', $haystack->result, 2), 2, '');
$cookies_y = str_contains($query, "minrate=") ? explode("&", $query)[2] : $query;
$cook = bin2hex($cookies_y);
$chs = explode('/', $baseUrl);

// Playback headers
$headers_1 = ["User-Agent: plaYtv/7.1.3 (Linux;Android 14) ExoPlayerLib/2.11.7"];

// Initialize JSON response array
$response = [
    "id" => $id,
    "original_url" => $haystack->result,
    "user_agent" => "plaYtv/7.1.3 (Linux;Android 14) ExoPlayerLib/2.11.7"
];

if (!empty($case)) {
    $response["case_requested"] = $case;
}

// Determine logic based on provided case, otherwise fallback to URL query matching
$is_case_1 = ($case == '1' || (empty($case) && str_contains($query, "bpk-tv")));
$is_case_2 = ($case == '2' || (empty($case) && str_contains($query, "/HLS/")));

if ($is_case_1) {
    // Case 1: bpk-tv streams
    $playlist = cUrlGetData($haystack->result, $headers_1);
    
    $response["stream_type"] = "bpk-tv";
    $response["cookie"] = $cookies_y;
    $response["cookie_hex"] = $cook;
    $response["raw_playlist"] = $playlist; // Returns original, unmodified M3U8 text

} elseif ($is_case_2) {
    // Case 2: HLS streams
    $playlist = cUrlGetData($haystack->result, $headers_1);

    // Extract HLS Cookie safely
    $cook_decoded = hex2bin($cook);
    if (str_contains($cook_decoded, "__hdnea")) {
        $cook_final = "__hdnea" . explode("__hdnea", $cook_decoded)[1];
    } else {
        $cook_final = $cook_decoded;
    }

    $response["stream_type"] = "HLS";
    $response["cookie"] = $cook_final;
    $response["cookie_hex"] = bin2hex($cook_final);
    $response["raw_playlist"] = $playlist; // Returns original, unmodified M3U8 text

} else {
    // Case 3: fallback stream
    $fallback_url = "https://snehtv.pages.dev/video/tsjiotv.m3u8";
    $playlist = cUrlGetData($fallback_url, $headers_1);
    
    $response["stream_type"] = "fallback";
    $response["original_url"] = $fallback_url;
    $response["raw_playlist"] = $playlist;
}

// Echo structured JSON response
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit;
