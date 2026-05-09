<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/app/common/db.php';
require_once __DIR__ . '/app/helpers/response.php';
require_once __DIR__ . '/app/controllers/StudentController.php';

$controller = new StudentController();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $controller->me();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->updateMe();
    exit;
}

jsonResponse(['status' => 'error', 'message' => 'Method not allowed'], 405);

