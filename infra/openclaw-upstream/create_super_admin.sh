#!/usr/bin/env bash
set -euo pipefail

BUGCATCHER_ROOT="${BUGCATCHER_ROOT:-/var/www/bugcatcher}"
CONFIG_PATH="${BUGCATCHER_CONFIG_PATH:-$BUGCATCHER_ROOT/config/local.php}"

if [[ ! -f "$CONFIG_PATH" ]]; then
    echo "Config file not found: $CONFIG_PATH" >&2
    exit 1
fi

prompt_nonempty() {
    local label="$1"
    local value=""
    while [[ -z "$value" ]]; do
        read -r -p "$label: " value
        value="${value#"${value%%[![:space:]]*}"}"
        value="${value%"${value##*[![:space:]]}"}"
        if [[ -z "$value" ]]; then
            echo "Value is required."
        fi
    done
    printf '%s' "$value"
}

prompt_password() {
    local password=""
    local confirm=""
    while true; do
        read -r -s -p "Super admin password: " password
        echo
        read -r -s -p "Confirm password: " confirm
        echo

        if [[ -z "$password" ]]; then
            echo "Password is required."
            continue
        fi
        if [[ "$password" != "$confirm" ]]; then
            echo "Passwords do not match."
            continue
        fi
        printf '%s' "$password"
        return
    done
}

EMAIL="$(prompt_nonempty 'What is your super_admin account email address')"
USERNAME="$(prompt_nonempty 'What is your super_admin account username')"
PASSWORD="$(prompt_password)"

env \
    CONFIG_PATH="$CONFIG_PATH" \
    SUPER_ADMIN_EMAIL="$EMAIL" \
    SUPER_ADMIN_USERNAME="$USERNAME" \
    SUPER_ADMIN_PASSWORD="$PASSWORD" \
    php <<'PHP'
<?php
$configPath = getenv('CONFIG_PATH') ?: '';
$email = trim((string) getenv('SUPER_ADMIN_EMAIL'));
$username = trim((string) getenv('SUPER_ADMIN_USERNAME'));
$password = (string) getenv('SUPER_ADMIN_PASSWORD');

if ($configPath === '' || !is_file($configPath)) {
    fwrite(STDERR, "Config file not found.\n");
    exit(1);
}
if ($email === '' || $username === '' || $password === '') {
    fwrite(STDERR, "Email, username, and password are required.\n");
    exit(1);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Email address is invalid.\n");
    exit(1);
}

$config = require $configPath;
if (!is_array($config)) {
    fwrite(STDERR, "Config file must return an array.\n");
    exit(1);
}

$conn = new mysqli(
    (string) ($config['DB_HOST'] ?? '127.0.0.1'),
    (string) ($config['DB_USER'] ?? 'root'),
    (string) ($config['DB_PASS'] ?? ''),
    (string) ($config['DB_NAME'] ?? 'bug_catcher'),
    (int) ($config['DB_PORT'] ?? 3306)
);

if ($conn->connect_error) {
    fwrite(STDERR, "Database connection failed: {$conn->connect_error}\n");
    exit(1);
}

$conn->set_charset('utf8mb4');
$passwordHash = password_hash($password, PASSWORD_DEFAULT);

$conflictStmt = $conn->prepare("
    SELECT id, username, email, role
    FROM users
    WHERE (email = ? OR username = ?)
    ORDER BY CASE WHEN email = ? THEN 0 ELSE 1 END, id ASC
    LIMIT 1
");
$conflictStmt->bind_param('sss', $email, $username, $email);
$conflictStmt->execute();
$existing = $conflictStmt->get_result()->fetch_assoc() ?: null;
$conflictStmt->close();

if ($existing) {
    $userId = (int) $existing['id'];
    $updateStmt = $conn->prepare("
        UPDATE users
        SET username = ?,
            email = ?,
            password = ?,
            role = 'super_admin'
        WHERE id = ?
    ");
    $updateStmt->bind_param('sssi', $username, $email, $passwordHash, $userId);
    $updateStmt->execute();
    $updateStmt->close();

    echo "Updated existing user to super_admin.\n";
    echo "id={$userId}\n";
    echo "username={$username}\n";
    echo "email={$email}\n";
    echo "role=super_admin\n";
    exit(0);
}

$insertStmt = $conn->prepare("
    INSERT INTO users (username, email, password, role)
    VALUES (?, ?, ?, 'super_admin')
");
$insertStmt->bind_param('sss', $username, $email, $passwordHash);
$insertStmt->execute();
$userId = (int) $insertStmt->insert_id;
$insertStmt->close();

echo "Created new super_admin user.\n";
echo "id={$userId}\n";
echo "username={$username}\n";
echo "email={$email}\n";
echo "role=super_admin\n";
PHP

unset PASSWORD
