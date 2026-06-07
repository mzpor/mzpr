<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth_otp.php';

auth_otp_bootstrap_session();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    auth_otp_json(false, 'Method not allowed', [], 405);
}

$data = auth_otp_read_json_body();
$phone11 = auth_otp_normalize_phone_11((string) ($data['phone'] ?? ''));
$firstName = trim((string) ($data['first_name'] ?? ''));
$lastName = trim((string) ($data['last_name'] ?? ''));
$token = trim((string) ($data['registration_token'] ?? ''));
$skipProfile = !empty($data['skip_profile']);

if ($phone11 === null) {
    auth_otp_json(false, 'شماره موبایل نامعتبر است.', [], 422);
}
if ($token === '') {
    auth_otp_json(false, 'نشست ثبت‌نام معتبر نیست.', [], 400);
}

$tokenData = auth_otp_get_registration_token($token);
if ($tokenData === null || ($tokenData['phone'] ?? '') !== $phone11) {
    auth_otp_json(false, 'نشست ثبت‌نام پیامکی معتبر نیست یا منقضی شده است.', [], 400);
}

if ($skipProfile) {
    $byPhone = auth_otp_find_user_by_phone($phone11);
    if ($byPhone === null) {
        auth_otp_json(false, 'کاربری با این شماره ثبت نشده است. لطفاً نام خود را وارد کنید.', [], 404);
    }
    $matchedUser = auth_otp_mark_phone_verified((string) ($byPhone['username'] ?? ''));
    if ($matchedUser === null) {
        auth_otp_json(false, 'خطا در ذخیره اطلاعات کاربر.', [], 500);
    }
    auth_otp_delete_registration_token($token);
    auth_otp_establish_login_session($matchedUser);
    auth_otp_json(true, 'ورود انجام شد.', [
        'skipped_profile' => true,
        'user' => auth_otp_user_payload($matchedUser),
        'redirect' => auth_otp_redirect_after_login($matchedUser),
    ]);
}

if ($firstName === '') {
    auth_otp_json(false, 'نام الزامی است.', [], 422);
}

$users = auth_otp_read_users();
$byPhone = auth_otp_find_user_by_phone($phone11);

$now = date('H:i:s');
$today = auth_otp_get_persian_date();
$verifiedAt = date('Y-m-d H:i:s');
$created = false;
$matchedUser = null;

if ($byPhone !== null) {
    foreach ($users as &$row) {
        if (($row['username'] ?? '') === ($byPhone['username'] ?? '')) {
            $row['phone'] = $phone11;
            $row['name'] = $firstName;
            $row['lastname'] = $lastName;
            $row['phone_verified'] = '1';
            $row['phone_verified_at'] = $verifiedAt;
            $row['time'] = $now;
            $row['date'] = $today;
            $matchedUser = $row;
            break;
        }
    }
    unset($row);
} else {
    if (auth_otp_username_exists($phone11)) {
        auth_otp_json(false, 'این شماره قبلاً ثبت شده است.', [], 409);
    }
    $nextId = empty($users) ? 1 : (max(array_map('intval', array_column($users, 'id'))) + 1);
    $matchedUser = auth_otp_normalize_user_row([
        'id' => (string) $nextId,
        'time' => $now,
        'username' => $phone11,
        'password' => substr(bin2hex(random_bytes(8)), 0, 12),
        'date' => $today,
        'name' => $firstName,
        'lastname' => $lastName,
        'phone' => $phone11,
        'national_id' => '',
        'avatar' => '',
        'role' => 'customer',
        'phone_verified' => '1',
        'phone_verified_at' => $verifiedAt,
        'bale_id' => '',
        'province' => '',
        'city' => '',
        'address' => '',
    ], 'customer');
    $users[] = $matchedUser;
    $created = true;
}

if ($matchedUser === null) {
    auth_otp_json(false, 'خطا در ذخیره اطلاعات کاربر.', [], 500);
}

if (!auth_otp_write_users($users)) {
    auth_otp_json(false, 'خطا در ذخیره اطلاعات.', [], 500);
}

auth_otp_delete_registration_token($token);
auth_otp_establish_login_session($matchedUser);

auth_otp_json(true, $created ? 'ثبت‌نام و ورود انجام شد.' : 'اطلاعات تکمیل و ورود انجام شد.', [
    'created' => $created,
    'user' => auth_otp_user_payload($matchedUser),
    'redirect' => auth_otp_redirect_after_login($matchedUser),
], $created ? 201 : 200);
