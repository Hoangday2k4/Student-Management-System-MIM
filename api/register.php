<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

require_once __DIR__ . '/app/common/define.php';
require_once __DIR__ . '/app/common/db.php';
require_once __DIR__ . '/app/models/Admin.php';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Admin::ensureSchema();

    $pdo = get_db_connection();
    $login_id = trim($_POST['login_id'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');

    if ($login_id === '') {
        $errors['login_id'] = 'Hay nhap login id';
    } elseif (strlen($login_id) < 4) {
        $errors['login_id'] = 'Login id toi thieu 4 ky tu';
    }

    if ($password === '') {
        $errors['password'] = 'Hay nhap password';
    } elseif (strlen($password) < 6) {
        $errors['password'] = 'Password toi thieu 6 ky tu';
    }

    if ($confirm_password === '') {
        $errors['confirm_password'] = 'Hay nhap lai password';
    } elseif ($password !== $confirm_password) {
        $errors['confirm_password'] = 'Password nhap lai khong khop';
    }

    if (empty($errors)) {
        if (Admin::findByLoginId($login_id)) {
            $errors['login_id'] = 'Login id da ton tai';
        }
    }

    if (empty($errors)) {
        Admin::createAccountWithPdo($pdo, $login_id, $password, $login_id, 'staff');
        echo json_encode(['status' => 'success']);
        exit;
    }

    echo json_encode(['status' => 'error', 'errors' => $errors]);
    exit;
}

http_response_code(405);
echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
