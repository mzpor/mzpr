<?php

declare(strict_types=1);

/**
 * ورود OTP با پیام‌رسان بله (Safir) — پروژه mzpr (صنایع چوبی تهران).
 * کاربران در فایل mobl.csv ذخیره می‌شوند.
 */

mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Tehran');

const AUTH_OTP_TTL_SEC = 300;
const AUTH_OTP_MAX_VERIFY_ATTEMPTS = 5;
const AUTH_OTP_REGISTRATION_TTL_SEC = 600;
const AUTH_OTP_RESEND_COOLDOWN_SEC = 60;
const AUTH_OTP_SESSION_CODES = 'auth_otp_codes';
const AUTH_OTP_SESSION_REGISTRATION = 'auth_otp_registration';

function auth_otp_bootstrap_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function auth_otp_json(bool $ok, string $message = '', array $data = [], int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    $payload = $ok
        ? array_merge(['success' => true, 'message' => $message], $data !== [] ? ['data' => $data] : [])
        : array_merge(['success' => false, 'message' => $message], $data);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function auth_otp_read_json_body(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw !== false ? $raw : '', true);
    return is_array($data) ? $data : [];
}

function auth_otp_to_en_digits(string $s): string
{
    $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $arabic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    $en = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    return str_replace(array_merge($persian, $arabic), array_merge($en, $en), $s);
}

function auth_otp_normalize_phone_11(string $input): ?string
{
    $d = preg_replace('/\D+/', '', auth_otp_to_en_digits(trim($input)));
    if ($d === null || $d === '') {
        return null;
    }
    if (strncmp($d, '0098', 4) === 0) {
        $d = substr($d, 4);
    } elseif (strncmp($d, '98', 2) === 0 && strlen($d) >= 12) {
        $d = substr($d, 2);
    }
    if (strlen($d) === 10 && $d[0] === '9') {
        $d = '0' . $d;
    }
    return preg_match('/^09\d{9}$/', $d) ? $d : null;
}

function auth_otp_phone_11_to_98(string $phone11): string
{
    return '98' . substr($phone11, 1);
}

function auth_otp_is_valid_national_id(string $input): bool
{
    $normalized = preg_replace('/\D+/', '', auth_otp_to_en_digits($input)) ?? '';
    if (!preg_match('/^\d{10}$/', $normalized)) {
        return false;
    }
    if (preg_match('/^(\d)\1{9}$/', $normalized)) {
        return false;
    }
    $check = (int) $normalized[9];
    $sum = 0;
    for ($i = 0; $i < 9; $i++) {
        $sum += (int) $normalized[$i] * (10 - $i);
    }
    $remainder = $sum % 11;
    return ($remainder < 2 && $check === $remainder) || ($remainder >= 2 && $check === 11 - $remainder);
}

function auth_otp_env_file(): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . '.env';
}

function auth_otp_load_env(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $path = auth_otp_env_file();
    if (!is_readable($path)) {
        $loaded = true;
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        $loaded = true;
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || (isset($line[0]) && $line[0] === '#')) {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value, " \t\"'");
        if ($name === '') {
            continue;
        }
        if (getenv($name) === false) {
            putenv("{$name}={$value}");
        }
        $_ENV[$name] = $value;
    }
    $loaded = true;
}

function auth_otp_load_safir(): bool
{
    static $loaded = false;
    if ($loaded) {
        return function_exists('otpphp_send_via_safir');
    }
    auth_otp_load_env();
    $safir = __DIR__ . DIRECTORY_SEPARATOR . 'bale_safir.php';
    if (!is_file($safir)) {
        return false;
    }
    require_once $safir;
    $loaded = function_exists('otpphp_send_via_safir');
    return $loaded;
}

function auth_otp_bale_configured(): bool
{
    auth_otp_load_env();
    if (!function_exists('otpphp_bale_otp_configured')) {
        auth_otp_load_safir();
    }
    return function_exists('otpphp_bale_otp_configured') && otpphp_bale_otp_configured();
}

function auth_otp_store_set(string $phone11, string $code, int $expiresAt, int $attempts = 0, ?int $resendAt = null): void
{
    if (!isset($_SESSION[AUTH_OTP_SESSION_CODES]) || !is_array($_SESSION[AUTH_OTP_SESSION_CODES])) {
        $_SESSION[AUTH_OTP_SESSION_CODES] = [];
    }
    $_SESSION[AUTH_OTP_SESSION_CODES][$phone11] = [
        'code' => $code,
        'expires_at' => $expiresAt,
        'attempts' => $attempts,
        'last_sent_at' => $resendAt ?? time(),
    ];
}

/** @return null|array{code: string, expires_at: int, attempts: int, last_sent_at: int} */
function auth_otp_store_get(string $phone11): ?array
{
    $all = $_SESSION[AUTH_OTP_SESSION_CODES] ?? [];
    if (!is_array($all)) {
        return null;
    }
    return $all[$phone11] ?? null;
}

function auth_otp_store_delete(string $phone11): void
{
    if (isset($_SESSION[AUTH_OTP_SESSION_CODES][$phone11])) {
        unset($_SESSION[AUTH_OTP_SESSION_CODES][$phone11]);
    }
}

function auth_otp_set_registration_token(string $phone11, string $token): void
{
    if (!isset($_SESSION[AUTH_OTP_SESSION_REGISTRATION]) || !is_array($_SESSION[AUTH_OTP_SESSION_REGISTRATION])) {
        $_SESSION[AUTH_OTP_SESSION_REGISTRATION] = [];
    }
    $_SESSION[AUTH_OTP_SESSION_REGISTRATION][$token] = [
        'phone' => $phone11,
        'expires_at' => time() + AUTH_OTP_REGISTRATION_TTL_SEC,
    ];
}

/** @return null|array{phone: string, expires_at: int} */
function auth_otp_get_registration_token(string $token): ?array
{
    $entry = $_SESSION[AUTH_OTP_SESSION_REGISTRATION][$token] ?? null;
    if (!is_array($entry)) {
        return null;
    }
    if (time() > (int) ($entry['expires_at'] ?? 0)) {
        unset($_SESSION[AUTH_OTP_SESSION_REGISTRATION][$token]);
        return null;
    }
    return $entry;
}

function auth_otp_delete_registration_token(string $token): void
{
    if (isset($_SESSION[AUTH_OTP_SESSION_REGISTRATION][$token])) {
        unset($_SESSION[AUTH_OTP_SESSION_REGISTRATION][$token]);
    }
}

function auth_otp_mobl_csv_path(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'mobl.csv';
}

function auth_otp_user_columns(): array
{
    return ['id', 'time', 'username', 'password', 'date', 'name', 'lastname', 'phone', 'national_id', 'avatar', 'role', 'phone_verified', 'phone_verified_at', 'bale_id', 'province', 'city', 'address'];
}

function auth_otp_normalize_user_row(array $row, string $defaultRole = 'customer'): array
{
    foreach (auth_otp_user_columns() as $column) {
        if (!array_key_exists($column, $row) || $row[$column] === null) {
            $row[$column] = '';
        }
    }
    if ($row['role'] === '') {
        $row['role'] = $defaultRole;
    }
    if ($row['phone_verified'] === '') {
        $row['phone_verified'] = '0';
    }
    return $row;
}

function auth_otp_read_users(): array
{
    $path = auth_otp_mobl_csv_path();
    if (!is_file($path)) {
        return [];
    }
    $users = [];
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return [];
    }
    $header = fgetcsv($handle) ?: [];
    $header = array_map(static function ($h) {
        $h = trim((string) $h);
        return preg_replace('/^["\']|["\']$/', '', $h);
    }, $header);
    while (($row = fgetcsv($handle)) !== false) {
        $assoc = array_combine($header, array_pad($row, count($header), ''));
        if ($assoc === false) {
            continue;
        }
        foreach ($assoc as $key => $value) {
            $value = preg_replace('/^["\']+|["\']+$/', '', (string) $value);
            $assoc[$key] = trim($value);
        }
        $users[] = auth_otp_normalize_user_row($assoc, 'customer');
    }
    fclose($handle);
    return $users;
}

function auth_otp_write_users(array $rows): bool
{
    $path = auth_otp_mobl_csv_path();
    $userCsvColumns = auth_otp_user_columns();
    $existingHeader = [];
    if (is_file($path)) {
        $h = fopen($path, 'rb');
        if ($h !== false) {
            $existingHeader = fgetcsv($h) ?: [];
            $existingHeader = array_map(static function ($col) {
                $col = trim((string) $col);
                return preg_replace('/^["\']|["\']$/', '', $col);
            }, $existingHeader);
            fclose($h);
        }
    }
    $allColumns = $existingHeader;
    foreach ($userCsvColumns as $col) {
        if (!in_array($col, $allColumns, true)) {
            $allColumns[] = $col;
        }
    }
    $finalColumns = [];
    foreach ($userCsvColumns as $col) {
        if (in_array($col, $allColumns, true)) {
            $finalColumns[] = $col;
        }
    }
    foreach (array_diff($allColumns, $finalColumns) as $col) {
        $finalColumns[] = $col;
    }
    $handle = fopen($path, 'wb');
    if ($handle === false) {
        return false;
    }
    fputcsv($handle, $finalColumns);
    foreach ($rows as $row) {
        $row = auth_otp_normalize_user_row($row, 'customer');
        $line = [];
        foreach ($finalColumns as $column) {
            $line[] = $row[$column] ?? '';
        }
        fputcsv($handle, $line);
    }
    fclose($handle);
    return true;
}

function auth_otp_find_user_by_phone(string $phone11): ?array
{
    foreach (auth_otp_read_users() as $user) {
        $na = auth_otp_normalize_phone_11((string) ($user['phone'] ?? ''));
        if ($na !== null && $na === $phone11) {
            return $user;
        }
    }
    return null;
}

function auth_otp_find_user_by_national_id(string $nationalId): ?array
{
    $nationalId = preg_replace('/\D+/', '', auth_otp_to_en_digits($nationalId)) ?? '';
    foreach (auth_otp_read_users() as $user) {
        $nid = preg_replace('/\D+/', '', auth_otp_to_en_digits((string) ($user['national_id'] ?? ''))) ?? '';
        if ($nid !== '' && $nid === $nationalId) {
            return $user;
        }
    }
    return null;
}

function auth_otp_username_exists(string $username): bool
{
    foreach (auth_otp_read_users() as $user) {
        if (($user['username'] ?? '') === $username) {
            return true;
        }
    }
    return false;
}

function auth_otp_phones_match(?string $a, ?string $b): bool
{
    $na = auth_otp_normalize_phone_11((string) $a);
    $nb = auth_otp_normalize_phone_11((string) $b);
    return $na !== null && $nb !== null && $na === $nb;
}

function auth_otp_touch_user_login(string $username): ?array
{
    $users = auth_otp_read_users();
    $now = date('H:i:s');
    $today = auth_otp_get_persian_date();
    $matched = null;
    foreach ($users as &$row) {
        if (($row['username'] ?? '') === $username) {
            $row['time'] = $now;
            $row['date'] = $today;
            $matched = $row;
            break;
        }
    }
    unset($row);
    if ($matched === null || !auth_otp_write_users($users)) {
        return null;
    }
    return $matched;
}

function auth_otp_mark_phone_verified(string $username): ?array
{
    $users = auth_otp_read_users();
    $verifiedAt = date('Y-m-d H:i:s');
    $now = date('H:i:s');
    $today = auth_otp_get_persian_date();
    $matched = null;
    foreach ($users as &$row) {
        if (($row['username'] ?? '') === $username) {
            $row['phone_verified'] = '1';
            if (trim((string) ($row['phone_verified_at'] ?? '')) === '') {
                $row['phone_verified_at'] = $verifiedAt;
            }
            $row['time'] = $now;
            $row['date'] = $today;
            $matched = $row;
            break;
        }
    }
    unset($row);
    if ($matched === null || !auth_otp_write_users($users)) {
        return null;
    }
    return $matched;
}

function auth_otp_needs_profile_completion(array $user): bool
{
    return trim((string) ($user['name'] ?? '')) === '';
}

function auth_otp_phone_already_verified(array $user): bool
{
    return (string) ($user['phone_verified'] ?? '0') === '1';
}

function auth_otp_profile_prefill(?array $user): array
{
    if ($user === null) {
        return [
            'known_user' => false,
            'national_id' => '',
            'first_name' => '',
            'last_name' => '',
            'username' => '',
            'role' => '',
            'display_name' => '',
        ];
    }
    $firstName = trim((string) ($user['name'] ?? ''));
    $lastName = trim((string) ($user['lastname'] ?? ''));
    $nationalId = preg_replace('/\D+/', '', auth_otp_to_en_digits((string) ($user['national_id'] ?? ''))) ?? '';

    return [
        'known_user' => true,
        'national_id' => $nationalId,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'username' => (string) ($user['username'] ?? ''),
        'role' => (string) ($user['role'] ?? 'customer'),
        'display_name' => trim($firstName . ' ' . $lastName),
    ];
}

function auth_otp_get_persian_date(): string
{
    return date('Y/m/d');
}

function auth_otp_establish_login_session(array $matchedUser): void
{
    $matchedUser = auth_otp_normalize_user_row($matchedUser);
    $_SESSION['user'] = [
        'username' => $matchedUser['username'],
        'name' => $matchedUser['name'],
        'lastname' => $matchedUser['lastname'],
        'phone' => $matchedUser['phone'],
        'national_id' => $matchedUser['national_id'],
        'avatar' => $matchedUser['avatar'],
        'role' => $matchedUser['role'] ?: 'customer',
        'bale_id' => $matchedUser['bale_id'] ?? '',
        'phone_verified' => $matchedUser['phone_verified'],
        'phone_verified_at' => $matchedUser['phone_verified_at'],
        'province' => $matchedUser['province'] ?? '',
        'city' => $matchedUser['city'] ?? '',
        'address' => $matchedUser['address'] ?? '',
    ];
}

function auth_otp_user_payload(array $user): array
{
    return [
        'username' => $user['username'] ?? '',
        'name' => trim(($user['name'] ?? '') . ' ' . ($user['lastname'] ?? '')),
        'role' => $user['role'] ?: 'customer',
    ];
}

function auth_otp_redirect_after_login(array $user): string
{
    if (($user['role'] ?? '') === 'admin') {
        return 'index.html';
    }
    return 'index.html';
}
