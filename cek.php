<?php

header("Content-Type: application/json");
error_reporting(0);
set_time_limit(15);

/*
|--------------------------------------------------------------------------
| CONFIG
|--------------------------------------------------------------------------
*/

$mxTimeout = 6;
$smtpTimeout = 6;

$mxHosts = [
    "mx1.mxge.comcast.net",
    "mx2.mxge.comcast.net",
    "mx1a1.comcast.net",
    "mx2a1.comcast.net",
];

$mailFrom = "customer@laacademy.id";

/*
|--------------------------------------------------------------------------
| INPUT
|--------------------------------------------------------------------------
*/

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

if (!is_array($data) || empty($data["email"])) {

    http_response_code(400);

    echo json_encode([
        "status" => "error",
        "message" => "Invalid request (email required)"
    ]);

    exit;
}

$email = trim($data["email"]);

/*
|--------------------------------------------------------------------------
| SAFE SMTP READ
|--------------------------------------------------------------------------
*/

function readSmtp($socket)
{
    if (!$socket) return "";

    $data = "";

    while (!feof($socket)) {

        $line = @fgets($socket, 1024);

        if (!$line) break;

        $data .= $line;

        if (preg_match('/^\d{3}\s/', $line)) {
            break;
        }
    }

    return trim($data);
}

/*
|--------------------------------------------------------------------------
| CHECK SINGLE MX
|--------------------------------------------------------------------------
*/

function checkMx($host, $email, $mailFrom, $smtpTimeout)
{
    $log = [];

    $ip = @gethostbyname($host);

    if (!$ip || $ip === $host) {
        return [
            "status" => "dns_failed",
            "log" => ["DNS failed"]
        ];
    }

    $log[] = "MX: $host -> $ip";

    $socket = @fsockopen($ip, 25, $errno, $errstr, $smtpTimeout);

    if (!$socket) {
        return [
            "status" => "connect_failed",
            "log" => array_merge($log, ["CONNECT FAIL: $errstr"])
        ];
    }

    stream_set_timeout($socket, $smtpTimeout);

    $banner = readSmtp($socket);
    $log[] = "BANNER: $banner";

    fwrite($socket, "EHLO laacademy.id\r\n");
    $log[] = "EHLO: " . readSmtp($socket);

    fwrite($socket, "MAIL FROM:<{$mailFrom}>\r\n");
    $log[] = "MAIL FROM: " . readSmtp($socket);

    fwrite($socket, "RCPT TO:<{$email}>\r\n");
    $rcpt = readSmtp($socket);

    $log[] = "RCPT TO: $rcpt";

    fwrite($socket, "QUIT\r\n");
    fclose($socket);

    if (strpos($rcpt, "250") !== false) {
        return [
            "status" => "valid",
            "log" => $log
        ];
    }

    if (
        strpos($rcpt, "550") !== false ||
        strpos($rcpt, "5.1.1") !== false
    ) {
        return [
            "status" => "invalid",
            "log" => $log
        ];
    }

    return [
        "status" => "unknown",
        "log" => $log
    ];
}

/*
|--------------------------------------------------------------------------
| MAIN ENGINE (MX FALLBACK)
|--------------------------------------------------------------------------
*/

$result = [
    "email" => $email,
    "status" => "not_checked",
    "mx_used" => null,
    "steps" => []
];

foreach ($mxHosts as $mx) {

    $check = checkMx($mx, $email, $mailFrom, $smtpTimeout);

    $result["steps"][] = [
        "mx" => $mx,
        "result" => $check
    ];

    $result["mx_used"] = $mx;

    // stop jika sudah jelas hasilnya
    if ($check["status"] === "valid" || $check["status"] === "invalid") {
        $result["status"] = $check["status"];
        break;
    }
}

/*
|--------------------------------------------------------------------------
| RESPONSE
|--------------------------------------------------------------------------
*/

echo json_encode($result, JSON_PRETTY_PRINT);
