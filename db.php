<?php
require_once __DIR__ . '/app/bootstrap.php';

webtest_start_session();

try {
    $conn = webtest_db_connection();
} catch (RuntimeException $e) {
    die($e->getMessage());
}

if (!isset($_SESSION['id'])) {
    $loginLocation = webtest_is_known_user_browser()
        ? webtest_path('rainier/login.php?reason=expired')
        : webtest_path('rainier/login.php');
    header("Location: {$loginLocation}");
    exit();
}

$current_user_id = (int) $_SESSION['id'];
$current_username = $_SESSION['username'] ?? 'User';
$current_role = webtest_normalize_system_role($_SESSION['role'] ?? 'user');
