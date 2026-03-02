<?php
require_once __DIR__ . '/app/bootstrap.php';

bugcatcher_start_session();

try {
    $conn = bugcatcher_db_connection();
} catch (RuntimeException $e) {
    die($e->getMessage());
}

if (!isset($_SESSION['id'])) {
    header("Location: register-passed-by-maglaque/login.php");
    exit();
}

$current_user_id = (int) $_SESSION['id'];
$current_username = $_SESSION['username'] ?? 'User';
$current_role = ($_SESSION['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
