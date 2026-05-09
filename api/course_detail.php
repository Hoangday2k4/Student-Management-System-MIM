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
require_once __DIR__ . '/app/controllers/CourseController.php';

$controller = new CourseController();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = strtolower(trim((string)($_GET['action'] ?? '')));
    if ($action === 'attendance') {
        $controller->getAttendance();
    } else {
        $controller->detail();
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = strtolower(trim((string)($_GET['action'] ?? '')));
    if ($action === 'submit-attendance') {
        $controller->submitAttendance();
    } elseif ($action === 'create-lesson') {
        $controller->createLesson();
    } elseif ($action === 'delete-lesson') {
        $controller->deleteLesson();
    } elseif ($action === 'submit-score') {
        $controller->submitScore();
    } else {
        $controller->update();
    }
    exit;
}

jsonResponse(['status' => 'error', 'message' => 'Method not allowed'], 405);

