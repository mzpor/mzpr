<?php

declare(strict_types=1);

/**
 * ارسال OTP از طریق Safir بله — پروژه mzpr (صنایع چوبی تهران).
 * متغیرها از includes/.env خوانده می‌شوند (قبل از include، auth_otp_load_env).
 */

const OTPPHP_TOKEN_URL = 'https://safir.bale.ai/api/v2/auth/token';
const OTPPHP_SEND_URL = 'https://safir.bale.ai/api/v2/send_otp';
const OTPPHP_SEND_MESSAGE_V3_URL = 'https://safir.bale.ai/api/v3/send_message';

function otpphp_env(string $name, string $default = ''): string
{
    $v = getenv($name);
    if ($v !== false && $v !== '') {
        return (string) $v;
    }
    if (isset($_ENV[$name]) && (string) $_ENV[$name] !== '') {
        return (string) $_ENV[$name];
    }

    return $default;
}

function otpphp_storage_dir(): string
{
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'storage';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

function otpphp_token_cache_path(): string
{
    return otpphp_storage_dir() . DIRECTORY_SEPARATOR . 'bale_token_cache.json';
}

/**
 * @return array{0: string, 1: ?string}
 */
function otpphp_get_bale_access_token(): array
{
    $clientId = otpphp_env('BALE_CLIENT_ID');
    $clientSecret = otpphp_env('BALE_CLIENT_SECRET');
    if ($clientId === '' || $clientSecret === '') {
        return ['', 'BALE_CLIENT_ID یا BALE_CLIENT_SECRET در includes/.env تنظیم نشده است.'];
    }

    $path = otpphp_token_cache_path();
    $now = time();
    if (is_readable($path)) {
        $raw = file_get_contents($path);
        if ($raw !== false) {
            $data = json_decode($raw, true);
            if (is_array($data) && isset($data['token'], $data['expires_at']) && (int) $data['expires_at'] > $now + 60) {
                return [(string) $data['token'], null];
            }
        }
    }

    $body = http_build_query([
        'grant_type' => 'client_credentials',
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'scope' => 'read',
    ], '', '&', PHP_QUERY_RFC3986);

    $ch = curl_init(OTPPHP_TOKEN_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['', 'خطای شبکه در دریافت توکن: ' . $err];
    }
    $json = json_decode($response, true);
    if ($code < 200 || $code >= 300 || !is_array($json) || empty($json['access_token'])) {
        return ['', 'خطای توکن Safir: ' . $code . ' ' . substr((string) $response, 0, 500)];
    }

    $expiresIn = isset($json['expires_in']) ? (int) $json['expires_in'] : 43200;
    $expiresAt = $now + $expiresIn - 60;

    @file_put_contents(
        $path,
        json_encode(['token' => $json['access_token'], 'expires_at' => $expiresAt], JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );

    return [(string) $json['access_token'], null];
}

/**
 * @return array{0: bool, 1: ?string}
 */
function otpphp_send_via_safir_v3(string $phone98, int $otpCode, string $apiAccessKey, int $botId): array
{
    if ($botId <= 0) {
        return [false, 'BALE_SAFIR_BOT_ID در includes/.env تنظیم نشده است.'];
    }

    $otpStr = str_pad((string) $otpCode, 4, '0', STR_PAD_LEFT);
    $payload = json_encode([
        'request_id' => 'mz-' . $phone98 . '-' . (string) (int) (microtime(true) * 1000),
        'bot_id' => $botId,
        'phone_number' => $phone98,
        'message_data' => [
            'otp_message' => ['otp' => $otpStr],
        ],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init(OTPPHP_SEND_MESSAGE_V3_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'api-access-key: ' . $apiAccessKey,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return [false, 'خطای شبکه در ارسال OTP (Safir v3): ' . $cerr];
    }
    if ($code < 200 || $code >= 300) {
        return [false, 'خطای ارسال OTP (Safir v3): ' . $code . ' ' . substr((string) $response, 0, 500)];
    }

    $json = json_decode((string) $response, true);
    if (is_array($json) && !empty($json['error_data']) && is_array($json['error_data'])) {
        $first = $json['error_data'][0] ?? [];
        $desc = (string) ($first['description'] ?? $first['message'] ?? 'خطای Safir');

        return [false, $desc];
    }

    return [true, null];
}

/**
 * @return array{0: bool, 1: ?string}
 */
function otpphp_send_via_safir_v2(string $phone98, int $otpCode): array
{
    [$token, $err] = otpphp_get_bale_access_token();
    if ($err !== null) {
        return [false, $err];
    }

    $payload = json_encode(['phone' => $phone98, 'otp' => $otpCode], JSON_UNESCAPED_UNICODE);
    $ch = curl_init(OTPPHP_SEND_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return [false, 'خطای شبکه در ارسال OTP: ' . $cerr];
    }
    if ($code < 200 || $code >= 300) {
        return [false, 'خطای ارسال OTP: ' . $code . ' ' . substr((string) $response, 0, 500)];
    }

    return [true, null];
}

/**
 * @return array{0: bool, 1: ?string}
 */
function otpphp_send_via_safir(string $phone98, int $otpCode): array
{
    $apiKey = otpphp_env('BALE_API_ACCESS_KEY');
    if ($apiKey === '') {
        $apiKey = otpphp_env('SAFIR_API_ACCESS_KEY');
    }
    $botId = (int) otpphp_env('BALE_SAFIR_BOT_ID');
    if ($botId <= 0) {
        $botId = (int) otpphp_env('SAFIR_BOT_ID');
    }
    if ($apiKey !== '' && $botId > 0) {
        return otpphp_send_via_safir_v3($phone98, $otpCode, $apiKey, $botId);
    }

    return otpphp_send_via_safir_v2($phone98, $otpCode);
}

function otpphp_bale_otp_configured(): bool
{
    $apiKey = otpphp_env('BALE_API_ACCESS_KEY') ?: otpphp_env('SAFIR_API_ACCESS_KEY');
    $botId = (int) otpphp_env('BALE_SAFIR_BOT_ID');
    if ($botId <= 0) {
        $botId = (int) otpphp_env('SAFIR_BOT_ID');
    }
    if ($apiKey !== '' && $botId > 0) {
        return true;
    }

    return otpphp_env('BALE_CLIENT_ID') !== '' && otpphp_env('BALE_CLIENT_SECRET') !== '';
}

function otpphp_is_dev(): bool
{
    $app = strtolower((string) (otpphp_env('APP_ENV')));
    $node = strtolower((string) (otpphp_env('NODE_ENV')));
    return in_array($app, ['development', 'dev', 'local'], true)
        || in_array($node, ['development', 'dev'], true);
}
