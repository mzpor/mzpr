<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth_otp.php';

auth_otp_bootstrap_session();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    auth_otp_json(false, 'Method not allowed', [], 405);
}

$data = auth_otp_read_json_body();
$phone11 = auth_otp_normalize_phone_11((string) ($data['phone'] ?? ''));
$otp = trim(auth_otp_to_en_digits((string) ($data['otp'] ?? '')));

if ($phone11 === null) {
    auth_otp_json(false, 'شماره موبایل نامعتبر است.', [], 422);
}
if (!preg_match('/^\d{4}$/', $otp)) {
    auth_otp_json(false, 'کد باید ۴ رقم باشد.', [], 422);
}

$entry = auth_otp_store_get($phone11);
if ($entry === null) {
    auth_otp_json(false, 'کدی برای این شماره ثبت نشده یا منقضی شده است.', [], 400);
}

$attempts = (int) ($entry['attempts'] ?? 0);
if ($attempts >= AUTH_OTP_MAX_VERIFY_ATTEMPTS) {
    auth_otp_store_delete($phone11);
    auth_otp_json(false, 'تعداد تلاش بیش از حد مجاز است.', [], 400);
}

if (time() > (int) ($entry['expires_at'] ?? 0)) {
    auth_otp_store_delete($phone11);
    auth_otp_json(false, 'کد تأیید منقضی شده است.', [], 400);
}

if (($entry['code'] ?? '') !== $otp) {
    $attempts++;
    if ($attempts >= AUTH_OTP_MAX_VERIFY_ATTEMPTS) {
        auth_otp_store_delete($phone11);
        auth_otp_json(false, 'تعداد تلاش بیش از حد مجاز است.', [], 400);
    }
    auth_otp_store_set($phone11, (string) $entry['code'], (int) $entry['expires_at'], $attempts, (int) ($entry['last_sent_at'] ?? time()));
    auth_otp_json(false, 'کد تأیید نادرست است.', [], 400);
}

auth_otp_store_delete($phone11);

$user = auth_otp_find_user_by_phone($phone11);

if (
    $user !== null
    && !auth_otp_needs_profile_completion($user)
    && auth_otp_phone_already_verified($user)
) {
    $matched = auth_otp_touch_user_login((string) ($user['username'] ?? ''));
    if ($matched === null) {
        auth_otp_json(false, 'خطا در ذخیره زمان ورود.', [], 500);
    }
    auth_otp_establish_login_session($matched);
    auth_otp_json(true, 'ورود موفق', [
        'requires_registration' => false,
        'user' => auth_otp_user_payload($matched),
        'redirect' => auth_otp_redirect_after_login($matched),
    ]);
}

$token = bin2hex(random_bytes(32));
auth_otp_set_registration_token($phone11, $token);

$profile = auth_otp_profile_prefill($user);

auth_otp_json(true, 'کد تأیید شد؛ تکمیل اطلاعات لازم است.', [
    'requires_registration' => true,
    'registration_token' => $token,
    'registration_ttl_seconds' => AUTH_OTP_REGISTRATION_TTL_SEC,
    'known_user' => $user !== null,
    'profile' => $profile,
]);
