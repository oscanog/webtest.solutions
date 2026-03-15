<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/mail.php';

const BUGCATCHER_PASSWORD_RESET_SESSION_KEY = 'bugcatcher_password_reset';
const BUGCATCHER_PASSWORD_RESET_CSRF_SESSION_KEY = 'bugcatcher_password_reset_csrf';

function bugcatcher_password_reset_ttl_seconds(): int
{
    return (int) bugcatcher_config('PASSWORD_RESET_OTP_TTL_SECONDS', 600);
}

function bugcatcher_password_reset_resend_cooldown_seconds(): int
{
    return (int) bugcatcher_config('PASSWORD_RESET_RESEND_COOLDOWN_SECONDS', 60);
}

function bugcatcher_password_reset_max_verify_attempts(): int
{
    return (int) bugcatcher_config('PASSWORD_RESET_MAX_VERIFY_ATTEMPTS', 5);
}

function bugcatcher_password_reset_max_resends(): int
{
    return (int) bugcatcher_config('PASSWORD_RESET_MAX_RESENDS', 3);
}

function bugcatcher_password_reset_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function bugcatcher_password_reset_normalize_email(string $email): string
{
    return strtolower(trim($email));
}

function bugcatcher_password_reset_mask_email(string $email): string
{
    $email = bugcatcher_password_reset_normalize_email($email);
    $parts = explode('@', $email, 2);
    if (count($parts) !== 2 || $parts[0] === '') {
        return $email;
    }

    $local = $parts[0];
    $visible = substr($local, 0, 2);
    if ($visible === false) {
        $visible = '';
    }

    return $visible . str_repeat('*', max(2, strlen($local) - strlen($visible))) . '@' . $parts[1];
}

function bugcatcher_password_reset_session_state(): array
{
    bugcatcher_start_session();
    $state = $_SESSION[BUGCATCHER_PASSWORD_RESET_SESSION_KEY] ?? [];
    return is_array($state) ? $state : [];
}

function bugcatcher_password_reset_set_state(array $state): void
{
    bugcatcher_start_session();
    $_SESSION[BUGCATCHER_PASSWORD_RESET_SESSION_KEY] = $state;
}

function bugcatcher_password_reset_begin_otp_step(string $email): void
{
    bugcatcher_password_reset_set_state([
        'email' => bugcatcher_password_reset_normalize_email($email),
        'verified_request_id' => 0,
    ]);
}

function bugcatcher_password_reset_mark_verified(string $email, int $requestId): void
{
    bugcatcher_password_reset_set_state([
        'email' => bugcatcher_password_reset_normalize_email($email),
        'verified_request_id' => $requestId,
    ]);
}

function bugcatcher_password_reset_clear_state(): void
{
    bugcatcher_start_session();
    unset($_SESSION[BUGCATCHER_PASSWORD_RESET_SESSION_KEY]);
}

function bugcatcher_password_reset_current_step(): string
{
    $state = bugcatcher_password_reset_session_state();
    $email = (string) ($state['email'] ?? '');
    $verifiedRequestId = (int) ($state['verified_request_id'] ?? 0);

    if ($email === '') {
        return 'request';
    }

    return ($verifiedRequestId > 0) ? 'reset' : 'otp';
}

function bugcatcher_password_reset_session_email(): string
{
    $state = bugcatcher_password_reset_session_state();
    return (string) ($state['email'] ?? '');
}

function bugcatcher_password_reset_verified_request_id(): int
{
    $state = bugcatcher_password_reset_session_state();
    return (int) ($state['verified_request_id'] ?? 0);
}

function bugcatcher_password_reset_csrf_token(): string
{
    bugcatcher_start_session();
    $token = (string) ($_SESSION[BUGCATCHER_PASSWORD_RESET_CSRF_SESSION_KEY] ?? '');
    if ($token === '') {
        $token = bin2hex(random_bytes(32));
        $_SESSION[BUGCATCHER_PASSWORD_RESET_CSRF_SESSION_KEY] = $token;
    }

    return $token;
}

function bugcatcher_password_reset_verify_csrf(string $token): bool
{
    bugcatcher_start_session();
    $expected = (string) ($_SESSION[BUGCATCHER_PASSWORD_RESET_CSRF_SESSION_KEY] ?? '');
    return ($expected !== '' && $token !== '' && hash_equals($expected, $token));
}

function bugcatcher_password_reset_generate_otp(): string
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function bugcatcher_password_reset_hash_otp(string $otp): string
{
    return hash('sha256', $otp);
}

function bugcatcher_password_reset_find_user(mysqli $conn, string $email): ?array
{
    $stmt = $conn->prepare("SELECT id, username, email FROM users WHERE email = ? LIMIT 1");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function bugcatcher_password_reset_find_latest_request(mysqli $conn, int $userId): ?array
{
    $stmt = $conn->prepare("
        SELECT
            id,
            user_id,
            otp_hash,
            expires_at,
            verify_attempt_count,
            resend_count,
            last_sent_at,
            verified_at,
            used_at,
            created_at,
            updated_at
        FROM password_reset_requests
        WHERE user_id = ? AND used_at IS NULL
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function bugcatcher_password_reset_request_is_expired(?array $request): bool
{
    if (!$request || empty($request['expires_at'])) {
        return true;
    }

    $expiresAt = strtotime((string) $request['expires_at']);
    if ($expiresAt === false) {
        return true;
    }

    return $expiresAt < time();
}

function bugcatcher_password_reset_cooldown_remaining(?array $request): int
{
    if (!$request || empty($request['last_sent_at'])) {
        return 0;
    }

    $lastSentAt = strtotime((string) $request['last_sent_at']);
    if ($lastSentAt === false) {
        return 0;
    }

    $remaining = ($lastSentAt + bugcatcher_password_reset_resend_cooldown_seconds()) - time();
    return max(0, $remaining);
}

function bugcatcher_password_reset_mark_request_used(mysqli $conn, int $requestId): void
{
    $usedAt = bugcatcher_password_reset_now();
    $stmt = $conn->prepare("
        UPDATE password_reset_requests
        SET used_at = ?, updated_at = ?
        WHERE id = ? AND used_at IS NULL
    ");
    $stmt->bind_param('ssi', $usedAt, $usedAt, $requestId);
    $stmt->execute();
    $stmt->close();
}

function bugcatcher_password_reset_invalidate_unused_requests(mysqli $conn, int $userId): void
{
    $usedAt = bugcatcher_password_reset_now();
    $stmt = $conn->prepare("
        UPDATE password_reset_requests
        SET used_at = ?, updated_at = ?
        WHERE user_id = ? AND used_at IS NULL
    ");
    $stmt->bind_param('ssi', $usedAt, $usedAt, $userId);
    $stmt->execute();
    $stmt->close();
}

function bugcatcher_password_reset_send_email(string $toEmail, string $toName, string $otp): void
{
    $appName = (string) bugcatcher_config('MAIL_FROM_NAME', 'BugCatcher');
    $htmlBody = sprintf(
        '<p>You requested a password reset for %s.</p><p>Your 6-digit reset code is <strong style="font-size:22px; letter-spacing:4px;">%s</strong>.</p><p>This code expires in %d minutes.</p><p>If you did not request this, you can ignore this email.</p>',
        htmlspecialchars($appName, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($otp, ENT_QUOTES, 'UTF-8'),
        (int) ceil(bugcatcher_password_reset_ttl_seconds() / 60)
    );
    $textBody = sprintf(
        "You requested a password reset for %s.\n\nYour 6-digit reset code is: %s\n\nThis code expires in %d minutes.\n\nIf you did not request this, you can ignore this email.",
        $appName,
        $otp,
        (int) ceil(bugcatcher_password_reset_ttl_seconds() / 60)
    );

    bugcatcher_mail_send(
        $toEmail,
        $toName,
        $appName . ' password reset code',
        $htmlBody,
        $textBody
    );
}

function bugcatcher_password_reset_generic_sent_message(bool $resent = false, int $cooldownRemaining = 0): string
{
    if ($cooldownRemaining > 0) {
        return "If an account exists for that email, a reset code is already on the way. Please wait {$cooldownRemaining} seconds before requesting another one.";
    }

    if ($resent) {
        return 'If an account exists for that email, we sent another 6-digit reset code. Enter the latest code below.';
    }

    return 'If an account exists for that email, we sent a 6-digit reset code. Enter it below.';
}

function bugcatcher_password_reset_request_otp(mysqli $conn, string $email): array
{
    $email = bugcatcher_password_reset_normalize_email($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Enter a valid email address.'];
    }

    $mailError = bugcatcher_mail_validate_config();
    if ($mailError !== null) {
        return ['ok' => false, 'error' => 'Password reset email is unavailable right now.'];
    }

    bugcatcher_password_reset_begin_otp_step($email);

    $user = bugcatcher_password_reset_find_user($conn, $email);
    if (!$user) {
        return ['ok' => true, 'message' => bugcatcher_password_reset_generic_sent_message()];
    }

    $latest = bugcatcher_password_reset_find_latest_request($conn, (int) $user['id']);
    $cooldownRemaining = bugcatcher_password_reset_cooldown_remaining($latest);
    if ($cooldownRemaining > 0) {
        return [
            'ok' => true,
            'message' => bugcatcher_password_reset_generic_sent_message(false, $cooldownRemaining),
        ];
    }

    $otp = bugcatcher_password_reset_generate_otp();
    $otpHash = bugcatcher_password_reset_hash_otp($otp);
    $now = bugcatcher_password_reset_now();
    $expiresAt = gmdate('Y-m-d H:i:s', time() + bugcatcher_password_reset_ttl_seconds());

    $conn->begin_transaction();
    try {
        bugcatcher_password_reset_invalidate_unused_requests($conn, (int) $user['id']);

        $stmt = $conn->prepare("
            INSERT INTO password_reset_requests
                (user_id, otp_hash, expires_at, verify_attempt_count, resend_count, last_sent_at, verified_at, used_at, created_at, updated_at)
            VALUES
                (?, ?, ?, 0, 0, ?, NULL, NULL, ?, ?)
        ");
        $userId = (int) $user['id'];
        $stmt->bind_param('isssss', $userId, $otpHash, $expiresAt, $now, $now, $now);
        $stmt->execute();
        $requestId = (int) $stmt->insert_id;
        $stmt->close();

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        return ['ok' => false, 'error' => 'Unable to start the password reset right now.'];
    }

    try {
        bugcatcher_password_reset_send_email((string) $user['email'], (string) $user['username'], $otp);
    } catch (RuntimeException $e) {
        bugcatcher_password_reset_mark_request_used($conn, $requestId);
        bugcatcher_password_reset_clear_state();
        return ['ok' => false, 'error' => 'Password reset email could not be sent right now.'];
    }

    return ['ok' => true, 'message' => bugcatcher_password_reset_generic_sent_message()];
}

function bugcatcher_password_reset_resend_otp(mysqli $conn, string $email): array
{
    $email = bugcatcher_password_reset_normalize_email($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Enter a valid email address.'];
    }

    $mailError = bugcatcher_mail_validate_config();
    if ($mailError !== null) {
        return ['ok' => false, 'error' => 'Password reset email is unavailable right now.'];
    }

    bugcatcher_password_reset_begin_otp_step($email);

    $user = bugcatcher_password_reset_find_user($conn, $email);
    if (!$user) {
        return ['ok' => true, 'message' => bugcatcher_password_reset_generic_sent_message(true)];
    }

    $request = bugcatcher_password_reset_find_latest_request($conn, (int) $user['id']);
    if (!$request || !empty($request['verified_at']) || bugcatcher_password_reset_request_is_expired($request)) {
        return bugcatcher_password_reset_request_otp($conn, $email);
    }

    $cooldownRemaining = bugcatcher_password_reset_cooldown_remaining($request);
    if ($cooldownRemaining > 0) {
        return [
            'ok' => true,
            'message' => bugcatcher_password_reset_generic_sent_message(true, $cooldownRemaining),
        ];
    }

    if ((int) ($request['resend_count'] ?? 0) >= bugcatcher_password_reset_max_resends()) {
        return ['ok' => false, 'error' => 'You have reached the resend limit. Start over to request a new code.'];
    }

    $otp = bugcatcher_password_reset_generate_otp();
    $otpHash = bugcatcher_password_reset_hash_otp($otp);
    $now = bugcatcher_password_reset_now();
    $expiresAt = gmdate('Y-m-d H:i:s', time() + bugcatcher_password_reset_ttl_seconds());

    $stmt = $conn->prepare("
        UPDATE password_reset_requests
        SET
            otp_hash = ?,
            expires_at = ?,
            verify_attempt_count = 0,
            resend_count = resend_count + 1,
            last_sent_at = ?,
            updated_at = ?
        WHERE id = ? AND used_at IS NULL
    ");
    $requestId = (int) $request['id'];
    $stmt->bind_param('ssssi', $otpHash, $expiresAt, $now, $now, $requestId);
    $stmt->execute();
    $stmt->close();

    try {
        bugcatcher_password_reset_send_email((string) $user['email'], (string) $user['username'], $otp);
    } catch (RuntimeException $e) {
        bugcatcher_password_reset_mark_request_used($conn, $requestId);
        bugcatcher_password_reset_clear_state();
        return ['ok' => false, 'error' => 'Password reset email could not be sent right now.'];
    }

    return ['ok' => true, 'message' => bugcatcher_password_reset_generic_sent_message(true)];
}

function bugcatcher_password_reset_verify_otp(mysqli $conn, string $email, string $otp): array
{
    $email = bugcatcher_password_reset_normalize_email($email);
    $otp = trim($otp);

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Start over and request a reset code first.'];
    }

    if ($otp === '' || !preg_match('/^\d{6}$/', $otp)) {
        return ['ok' => false, 'error' => 'Enter the 6-digit code from your email.'];
    }

    $user = bugcatcher_password_reset_find_user($conn, $email);
    if (!$user) {
        return ['ok' => false, 'error' => 'The code is invalid or expired.'];
    }

    $request = bugcatcher_password_reset_find_latest_request($conn, (int) $user['id']);
    if (!$request) {
        return ['ok' => false, 'error' => 'The code is invalid or expired.'];
    }

    if (bugcatcher_password_reset_request_is_expired($request)) {
        bugcatcher_password_reset_mark_request_used($conn, (int) $request['id']);
        return ['ok' => false, 'error' => 'The code is invalid or expired. Request a new one and try again.'];
    }

    if ((int) ($request['verify_attempt_count'] ?? 0) >= bugcatcher_password_reset_max_verify_attempts()) {
        bugcatcher_password_reset_mark_request_used($conn, (int) $request['id']);
        return ['ok' => false, 'error' => 'Too many incorrect codes. Request a new code to continue.'];
    }

    $otpHash = bugcatcher_password_reset_hash_otp($otp);
    if (!hash_equals((string) ($request['otp_hash'] ?? ''), $otpHash)) {
        $newAttemptCount = (int) ($request['verify_attempt_count'] ?? 0) + 1;
        $now = bugcatcher_password_reset_now();
        $stmt = $conn->prepare("
            UPDATE password_reset_requests
            SET verify_attempt_count = ?, updated_at = ?
            WHERE id = ? AND used_at IS NULL
        ");
        $requestId = (int) $request['id'];
        $stmt->bind_param('isi', $newAttemptCount, $now, $requestId);
        $stmt->execute();
        $stmt->close();

        if ($newAttemptCount >= bugcatcher_password_reset_max_verify_attempts()) {
            bugcatcher_password_reset_mark_request_used($conn, $requestId);
            return ['ok' => false, 'error' => 'Too many incorrect codes. Request a new code to continue.'];
        }

        return ['ok' => false, 'error' => 'The code is invalid or expired.'];
    }

    $now = bugcatcher_password_reset_now();
    $stmt = $conn->prepare("
        UPDATE password_reset_requests
        SET verified_at = ?, updated_at = ?
        WHERE id = ? AND used_at IS NULL
    ");
    $requestId = (int) $request['id'];
    $stmt->bind_param('ssi', $now, $now, $requestId);
    $stmt->execute();
    $stmt->close();

    bugcatcher_password_reset_mark_verified($email, $requestId);

    return [
        'ok' => true,
        'message' => 'Code verified. Choose a new password now.',
        'request_id' => $requestId,
    ];
}

function bugcatcher_password_reset_update_password(mysqli $conn, int $requestId, string $email, string $password, string $confirmPassword): array
{
    $email = bugcatcher_password_reset_normalize_email($email);
    if ($requestId <= 0 || $email === '') {
        return ['ok' => false, 'error' => 'Start the reset flow again and request a new code.'];
    }

    if ($password === '' || $confirmPassword === '') {
        return ['ok' => false, 'error' => 'Enter and confirm your new password.'];
    }

    if ($password !== $confirmPassword) {
        return ['ok' => false, 'error' => 'Password does not match.'];
    }

    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare("
            SELECT pr.id, pr.user_id, pr.verified_at, pr.used_at, u.email
            FROM password_reset_requests pr
            JOIN users u ON u.id = pr.user_id
            WHERE pr.id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->bind_param('i', $requestId);
        $stmt->execute();
        $request = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        if (!$request) {
            throw new RuntimeException('Reset request not found.');
        }

        if (bugcatcher_password_reset_normalize_email((string) ($request['email'] ?? '')) !== $email) {
            throw new RuntimeException('Reset request email mismatch.');
        }

        if (empty($request['verified_at']) || !empty($request['used_at'])) {
            throw new RuntimeException('Reset request is not ready.');
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $userId = (int) $request['user_id'];
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->bind_param('si', $passwordHash, $userId);
        $stmt->execute();
        $stmt->close();

        $usedAt = bugcatcher_password_reset_now();
        $stmt = $conn->prepare("
            UPDATE password_reset_requests
            SET used_at = ?, updated_at = ?
            WHERE id = ?
        ");
        $stmt->bind_param('ssi', $usedAt, $usedAt, $requestId);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("
            UPDATE password_reset_requests
            SET used_at = ?, updated_at = ?
            WHERE user_id = ? AND id <> ? AND used_at IS NULL
        ");
        $stmt->bind_param('ssii', $usedAt, $usedAt, $userId, $requestId);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        return ['ok' => false, 'error' => 'Unable to reset the password right now.'];
    }

    bugcatcher_password_reset_clear_state();

    return ['ok' => true];
}
