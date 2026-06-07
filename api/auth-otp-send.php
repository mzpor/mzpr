<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth_otp.php';

auth_otp_bootstrap_session();
auth_otp_load_safir();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    auth_otp_json(false, 'Method not allowed', [], 405);
}

$data = auth_otp_read_json_body();
$phone11 = auth_otp_normalize_phone_11((string) ($data['phone'] ?? ''));
if ($phone11 === null) {
    auth_otp_json(false, 'شماره موبایل نامعتبر است.', [], 422);
}

if (!auth_otp_bale_configured()) {
    auth_otp_json(false, 'کلید OTP بله تنظیم نشده. فایل includes/.env را بررسی کنید.', [], 503);
}

$existing = auth_otp_store_get($phone11);
if ($existing !== null) {
    $lastSent = (int) ($existing['last_sent_at'] ?? 0);
    $wait = AUTH_OTP_RESEND_COOLDOWN_SEC - (time() - $lastSent);
    if ($wait > 0) {
        auth_otp_json(false, 'لطفاً ' . $wait . ' ثانیه دیگر برای ارسال مجدد صبر کنید.', ['retry_after' => $wait], 429);
    }
}

$code = (string) random_int(1000, 9999);
$phone98 = auth_otp_phone_11_to_98($phone11);
[$ok, $err] = otpphp_send_via_safir($phone98, (int) $code);
if (!$ok) {
    auth_otp_json(false, $err ?? 'ارسال کد ناموفق بود.', [], 500);
}

auth_otp_store_set($phone11, $code, time() + AUTH_OTP_TTL_SEC, 0, time());

if (function_exists('otpphp_is_dev') && otpphp_is_dev()) {
    error_log('[mzpr auth-otp dev] OTP for ' . $phone11 . ': ' . $code);
}

auth_otp_json(true, 'کد تأیید ارسال شد.', [
    'sent' => true,
    'ttl_seconds' => AUTH_OTP_TTL_SEC,
]);
