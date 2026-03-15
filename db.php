<?php
require_once __DIR__ . '/app/bootstrap.php';

bugcatcher_start_session();

try {
    $conn = bugcatcher_db_connection();
} catch (RuntimeException $e) {
    die($e->getMessage());
}

if (!isset($_SESSION['id'])) {
    $loginLocation = bugcatcher_is_known_user_browser()
        ? bugcatcher_path('rainier/login.php?reason=expired')
        : bugcatcher_path('rainier/login.php');
    header("Location: {$loginLocation}");
    exit();
}

$current_user_id = (int) $_SESSION['id'];
$current_username = $_SESSION['username'] ?? 'User';
$current_role = bugcatcher_normalize_system_role($_SESSION['role'] ?? 'user');
