<?php
/**
 * StockEx — SMS Gateway
 *
 * Sends transactional SMS through the messaging JSON API:
 *
 *   POST {SMS_API_URL}  (sendsms_api_json.aspx)
 *   payload: [{"user","pwd","number","msg","sender","language"}]
 *   response: [{"msg_id","number","response":"send success"}]
 *
 * Numbers are sent in E.164 country-code format (e.g. 2557XXXXXXXX).
 * Asian/Unicode text requires hex; we use ASCII English messages so the
 * default `language=English` path works without any hex encoding.
 *
 * Configuration (env): SMS_API_URL, SMS_USER, SMS_PWD, SMS_SENDER,
 * SMS_LANGUAGE, SMS_MODE (defaults: log).
 *
 * SMS_MODE=log prints the message to the error log instead of dialing out —
 * used for local development where no gateway credentials exist.
 */

require_once __DIR__ . '/../config/env_loader.php';

if (!defined('SMS_API_URL')) {
    define('SMS_API_URL', env('SMS_API_URL', ''));
}
if (!defined('SMS_USER')) {
    define('SMS_USER', env('SMS_USER', ''));
}
if (!defined('SMS_PWD')) {
    define('SMS_PWD', env('SMS_PWD', ''));
}
if (!defined('SMS_SENDER')) {
    define('SMS_SENDER', env('SMS_SENDER', 'StockEx'));
}
if (!defined('SMS_LANGUAGE')) {
    define('SMS_LANGUAGE', env('SMS_LANGUAGE', 'English'));
}
if (!defined('SMS_MODE')) {
    define('SMS_MODE', env('SMS_MODE', 'log'));
}

/**
 * Send a single SMS message.
 *
 * @param string $number  A Tanzanian phone (07XXXXXXXX, +2557XXXXXXXX, 2557...).
 * @param string $message Free text (ASCII for the English/Unicode param).
 * @return array{success:bool, msg_id?:string|null, number?:string, mode?:string, error?:string}
 */
function sms_send(string $number, string $message): array
{
    $number = sms_normalize_number($number);
    if ($number === null) {
        return ['success' => false, 'error' => 'Invalid phone number.'];
    }

    if (defined('SMS_MODE') && SMS_MODE === 'log') {
        error_log('[SMS:log] to ' . $number . ' :: ' . $message);
        return ['success' => true, 'mode' => 'log', 'number' => $number];
    }

    if (SMS_API_URL === '' || SMS_USER === '' || SMS_PWD === '') {
        error_log('[SMS] Gateway is not configured (SMS_API_URL/SMS_USER/SMS_PWD).');
        return ['success' => false, 'error' => 'SMS gateway is not configured.'];
    }

    $payload = [[
        'user'     => SMS_USER,
        'pwd'      => SMS_PWD,
        'number'   => $number,
        'msg'      => $message,
        'sender'   => SMS_SENDER,
        'language' => SMS_LANGUAGE,
    ]];

    $ch = curl_init(SMS_API_URL);
    if ($ch === false) {
        error_log('[SMS] curl_init failed; php-curl may be missing.');
        return ['success' => false, 'error' => 'SMS delivery failed (curl unavailable).'];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        error_log("[SMS] curl error ($errno): $error");
        return ['success' => false, 'error' => 'SMS delivery failed (network error).'];
    }

    $response = json_decode((string)$raw, true);
    if (!is_array($response)) {
        error_log('[SMS] Unexpected gateway response (http ' . (int)$status . '): ' . substr((string)$raw, 0, 500));
        return ['success' => false, 'error' => 'SMS delivery failed (unexpected response).'];
    }

    foreach ($response as $item) {
        if (is_array($item) && isset($item['response'])) {
            if (is_string($item['response']) && stripos($item['response'], 'send success') !== false) {
                return [
                    'success' => true,
                    'msg_id'  => $item['msg_id'] ?? null,
                    'number'  => $item['number'] ?? $number,
                ];
            }
            return [
                'success' => false,
                'error'   => 'SMS gateway rejected: ' . substr((string)$item['response'], 0, 140),
            ];
        }
    }

    error_log('[SMS] Unrecognized gateway response: ' . substr(json_encode($response), 0, 500));
    return ['success' => false, 'error' => 'SMS delivery failed (unrecognized response).'];
}

/**
 * Normalize a phone number to E.164 format (country code included).
 * Accepts 07XXXXXXXX, +2557XXXXXXXX, 2557XXXXXXXX, bare digits.
 */
function sms_normalize_number(string $phone): ?string
{
    $phone = trim((string)$phone);
    $phone = ltrim($phone, '+');
    $phone = preg_replace('/[\s\-\(\)]/', '', $phone) ?? '';
    $phone = ltrim($phone, '0');

    if (preg_match('/^(255|25)?([0-9]{9})$/', $phone, $m)) {
        return '255' . $m[2];
    }
    if (preg_match('/^[0-9]{10,15}$/', $phone)) {
        // Already an international-ish number; pass through.
        return $phone;
    }
    return null;
}