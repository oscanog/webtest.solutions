<?php
// Use bug_catcher database (single database for everything)
$conn = new mysqli("localhost", "root", "", "bug_catcher");

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Auto-fix old users table schema (simple migration for thesis setup)
$columns = [];
$res = $conn->query("SHOW COLUMNS FROM users");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $columns[] = $row['Field'];
    }
}

if (!in_array('email', $columns, true)) {
    $conn->query("ALTER TABLE users ADD COLUMN email VARCHAR(255) UNIQUE AFTER username");
}
if (!in_array('password', $columns, true)) {
    $conn->query("ALTER TABLE users ADD COLUMN password VARCHAR(255) AFTER email");
}
if (!in_array('role', $columns, true)) {
    $conn->query("ALTER TABLE users ADD COLUMN role ENUM('admin','user') DEFAULT 'user' AFTER password");
}

// Ensure default login accounts exist
function ensure_default_user($conn, $username, $email, $plainPassword, $role)
{
    $check = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
    if (!$check) {
        return;
    }
    $check->bind_param("ss", $username, $email);
    $check->execute();
    $res = $check->get_result();

    $hash = password_hash($plainPassword, PASSWORD_DEFAULT);

    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $id = (int) $row['id'];

        $update = $conn->prepare("UPDATE users SET email = ?, password = ?, role = ? WHERE id = ?");
        if (!$update) {
            return;
        }
        $update->bind_param("sssi", $email, $hash, $role, $id);
        $update->execute();
        return;
    }

    $insert = $conn->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)");
    if (!$insert) {
        return;
    }
    $insert->bind_param("ssss", $username, $email, $hash, $role);
    $insert->execute();
}

ensure_default_user($conn, 'admin', 'admin@bugcatcher.com', 'password', 'admin');
ensure_default_user($conn, 'user', 'user@bugcatcher.com', 'password', 'user');
