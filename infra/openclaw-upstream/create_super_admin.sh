#!/usr/bin/env bash
set -euo pipefail

BUGCATCHER_ROOT="${BUGCATCHER_ROOT:-/var/www/bugcatcher}"
CONFIG_PATH="${BUGCATCHER_CONFIG_PATH:-$BUGCATCHER_ROOT/config/local.php}"
LOGIN_PATH="${LOGIN_PATH:-/register-passed-by-maglaque/login.php}"

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
            echo "Value is required." >&2
        fi
    done
    printf '%s' "$value"
}

prompt_password() {
    local password=""
    local confirm=""
    while true; do
        read -r -s -p "Super admin password: " password
        printf '\n' >&2
        read -r -s -p "Confirm password: " confirm
        printf '\n' >&2

        if [[ -z "$password" ]]; then
            echo "Password is required." >&2
            continue
        fi
        if [[ "$password" != "$confirm" ]]; then
            echo "Passwords do not match." >&2
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

echo
echo "Verifying stored password hash..."
env \
    CONFIG_PATH="$CONFIG_PATH" \
    SUPER_ADMIN_EMAIL="$EMAIL" \
    SUPER_ADMIN_PASSWORD="$PASSWORD" \
    php <<'PHP'
<?php
$configPath = getenv('CONFIG_PATH') ?: '';
$email = trim((string) getenv('SUPER_ADMIN_EMAIL'));
$password = (string) getenv('SUPER_ADMIN_PASSWORD');
$config = require $configPath;

$conn = new mysqli(
    (string) ($config['DB_HOST'] ?? '127.0.0.1'),
    (string) ($config['DB_USER'] ?? 'root'),
    (string) ($config['DB_PASS'] ?? ''),
    (string) ($config['DB_NAME'] ?? 'bug_catcher'),
    (int) ($config['DB_PORT'] ?? 3306)
);

if ($conn->connect_error) {
    fwrite(STDERR, "Database connection failed during verification: {$conn->connect_error}\n");
    exit(1);
}

$conn->set_charset('utf8mb4');
$stmt = $conn->prepare("SELECT password FROM users WHERE email = ? LIMIT 1");
$stmt->bind_param('s', $email);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();

if (!$row) {
    fwrite(STDERR, "Verification failed: user row not found.\n");
    exit(1);
}

if (!password_verify($password, (string) $row['password'])) {
    fwrite(STDERR, "Verification failed: stored password hash does not match the entered password.\n");
    exit(1);
}

echo "Password hash verification: OK\n";
PHP

APP_BASE_URL="$(php -r '$cfg = require "'"$CONFIG_PATH"'"; echo $cfg["APP_BASE_URL"] ?? "";')"
if [[ -z "$APP_BASE_URL" ]]; then
    echo "Skipping web login verification because APP_BASE_URL is not configured."
    unset PASSWORD
    exit 0
fi

LOGIN_URL="${APP_BASE_URL%/}${LOGIN_PATH}"
COOKIE_JAR="$(mktemp)"
HEADERS_FILE="$(mktemp)"
BODY_FILE="$(mktemp)"
cleanup_files() {
    rm -f "$COOKIE_JAR" "$HEADERS_FILE" "$BODY_FILE"
}
trap cleanup_files EXIT

echo "Running live login verification against $LOGIN_URL ..."
curl -ksS \
    -c "$COOKIE_JAR" \
    -b "$COOKIE_JAR" \
    -D "$HEADERS_FILE" \
    -o "$BODY_FILE" \
    --data-urlencode "email=$EMAIL" \
    --data-urlencode "password=$PASSWORD" \
    --data-urlencode "login=Login" \
    "$LOGIN_URL" >/dev/null

if grep -qi '^Location: ../dashboard.php' "$HEADERS_FILE"; then
    echo "Web login verification: OK (redirected to ../dashboard.php)"
else
    echo "Web login verification failed." >&2
    echo "Response headers:" >&2
    sed -n '1,20p' "$HEADERS_FILE" >&2
    echo "Response body preview:" >&2
    sed -n '1,40p' "$BODY_FILE" >&2
    exit 1
fi

unset PASSWORD
