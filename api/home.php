<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/app/common/define.php';
require_once __DIR__ . '/app/common/db.php';
require_once __DIR__ . '/app/models/Admin.php';
require_once __DIR__ . '/app/models/Course.php';

if (!isset($_SESSION['login_id'])) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'Chua dang nhap'
    ]);
    exit;
}

$loginId = (string)$_SESSION['login_id'];
$loginTime = $_SESSION['login_time'] ?? '';

// Khởi tạo schema môn học/lớp học sẵn để tránh lỗi khi gọi chức năng mới lần đầu
Course::ensureSchema();

$identity = Admin::findIdentityByLoginId($loginId);
$accountType = $identity['account_type'] ?? 'student';
$displayName = trim((string)($identity['name'] ?? ''));

echo json_encode([
    'login_id' => $loginId,
    'login_time' => $loginTime,
    'account_type' => $accountType,
    'display_name' => $displayName,
]);
