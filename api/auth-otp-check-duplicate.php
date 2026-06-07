<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth_otp.php';

auth_otp_bootstrap_session();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    auth_otp_json(false, 'Method not allowed', [], 405);
}

$field = trim((string) ($_GET['field'] ?? ''));
$value = auth_otp_to_en_digits(trim((string) ($_GET['value'] ?? '')));
$exists = false;

if ($field === 'phone') {
    $phone11 = auth_otp_normalize_phone_11($value);
    $exists = $phone11 !== null && auth_otp_find_user_by_phone($phone11) !== null;
} elseif ($field === 'national_id') {
    $nid = preg_replace('/\D+/', '', $value) ?? '';
    $exists = $nid !== '' && auth_otp_find_user_by_national_id($nid) !== null;
} else {
    auth_otp_json(false, 'فیلد نامعتبر است.', [], 422);
}

auth_otp_json(true, 'duplicate check', ['exists' => $exists]);
