<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$host = "localhost";
$user = "root";
$pass = "";
$db = "bug_catcher";

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if (!isset($_SESSION['id'])) {
    header("Location: register-passed-by-maglaque/login.php");
    exit();
}

$current_user_id = (int) $_SESSION['id'];
$current_username = $_SESSION['username'] ?? 'User';
$current_role = ($_SESSION['role'] ?? 'user') === 'admin' ? 'admin' : 'user';