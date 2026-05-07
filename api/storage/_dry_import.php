<?php
session_start();
$_SESSION['login_id'] = 'admin';
require __DIR__ . '/../public/student_import.php';
?>
