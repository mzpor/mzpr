<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth_otp.php';

auth_otp_bootstrap_session();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    auth_otp_json(false, 'Method not allowed', [], 405);
}

auth_otp_logout();
auth_otp_json(true, 'خروج انجام شد.', ['logged_in' => false]);
