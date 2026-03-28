<?php
date_default_timezone_set('Asia/Ho_Chi_Minh');
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

require_once __DIR__ . '/app/common/define.php';
require_once __DIR__ . '/app/common/db.php';
require_once __DIR__ . '/app/models/Admin.php';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login_id = trim($_POST['login_id'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $recaptcha_response = $_POST['g-recaptcha-response'] ?? '';

    if ($login_id === '') {
        $errors['login_id'] = 'Hay nhap login id';
    } elseif (strlen($login_id) < 4) {
        $errors['login_id'] = 'Hay nhap login id toi thieu 4 ky tu';
    }

    if ($password === '') {
        $errors['password'] = 'Hay nhap password';
    } elseif (strlen($password) < 6) {
        $errors['password'] = 'Hay nhap password toi thieu 6 ky tu';
    }

    if (empty($recaptcha_response)) {
        $errors['login'] = 'Vui long xac nhan ban khong phai robot';
    } else {
        $recaptcha_secret = $CONFIG['RECAPTCHA_SECRET_KEY'] ?? '';
        $verify = @file_get_contents(
            "https://www.google.com/recaptcha/api/siteverify?secret=$recaptcha_secret&response=$recaptcha_response"
        );
        $captcha_success = json_decode($verify ?: '{}', true);
        if (empty($captcha_success['success'])) {
            $errors['login'] = 'Xac thuc reCAPTCHA that bai';
        }
    }

    if (empty($errors)) {
        if (!Admin::verifyPassword($login_id, $password)) {
            $errors['login'] = 'Login ID va password khong dung';
        } else {
            $identity = Admin::findIdentityByLoginId($login_id);
            $_SESSION['login_id'] = $identity['login_id'] ?? $login_id;
            $_SESSION['login_time'] = date('Y-m-d H:i');
            $_SESSION['account_type'] = $identity['account_type'] ?? 'student';

            echo json_encode([
                'status' => 'success',
                'login_id' => $_SESSION['login_id'],
                'login_time' => $_SESSION['login_time'],
                'account_type' => $_SESSION['account_type'],
                'display_name' => trim((string)($identity['name'] ?? '')),
            ]);
            exit;
        }
    }

    echo json_encode(['status' => 'error', 'errors' => $errors]);
    exit;
}

http_response_code(405);
echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
