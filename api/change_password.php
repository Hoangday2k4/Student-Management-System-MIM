<?php
require_once __DIR__ . '/app/common/define.php';
require_once __DIR__ . '/app/common/db.php';
require_once __DIR__ . '/app/helpers/response.php';
require_once __DIR__ . '/app/models/Admin.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['login_id'])) {
    jsonResponse(['error' => 'Unauthorized'], 401);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$loginId = trim((string)$_SESSION['login_id']);
$oldPassword = trim((string)($input['old_password'] ?? ''));
$newPassword = trim((string)($input['new_password'] ?? ''));
$confirmPassword = trim((string)($input['confirm_password'] ?? ''));
$errors = [];

if ($oldPassword === '') {
    $errors['old_password'] = 'Hay nhap mat khau cu.';
}
if ($newPassword === '') {
    $errors['new_password'] = 'Hay nhap mat khau moi.';
} elseif (strlen($newPassword) < 6) {
    $errors['new_password'] = 'Mat khau moi toi thieu 6 ky tu.';
}
if ($confirmPassword === '') {
    $errors['confirm_password'] = 'Hay nhap lai mat khau moi.';
} elseif ($newPassword !== $confirmPassword) {
    $errors['confirm_password'] = 'Mat khau nhap lai khong khop.';
}
if ($oldPassword !== '' && $newPassword !== '' && $oldPassword === $newPassword) {
    $errors['new_password'] = 'Mat khau moi phai khac mat khau cu.';
}

if (!empty($errors)) {
    jsonResponse(['error' => 'Validation failed', 'fields' => $errors], 422);
    exit;
}

if (!Admin::verifyPassword($loginId, $oldPassword)) {
    jsonResponse([
        'error' => 'Validation failed',
        'fields' => ['old_password' => 'Mat khau cu khong dung.'],
    ], 422);
    exit;
}

$ok = Admin::updatePasswordByLoginId($loginId, $newPassword);
if (!$ok) {
    jsonResponse(['error' => 'Khong the cap nhat mat khau (khong tim thay tai khoan).'], 500);
    exit;
}

jsonResponse(['success' => true]);
