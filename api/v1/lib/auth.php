<?php

declare(strict_types=1);

function bc_v1_auth_login(mysqli $conn, array $params): void
{
    bc_v1_require_method(['POST']);
    $payload = bc_v1_request_data();
    $email = trim((string) ($payload['email'] ?? ''));
    $password = (string) ($payload['password'] ?? '');
    $requestedOrgId = bc_v1_get_int($payload, 'active_org_id', 0);

    if ($email === '' && $password === '') {
        bc_v1_json_error(422, 'validation_error', 'Email and password are required.');
    }
    if ($email === '') {
        bc_v1_json_error(422, 'email_required', 'Email is required.');
    }
    if ($password === '') {
        bc_v1_json_error(422, 'password_required', 'Password is required.');
    }

    $stmt = $conn->prepare("SELECT id, username, email, password, role, last_active_org_id FROM users WHERE email = ? LIMIT 1");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        bc_v1_json_error(401, 'email_not_found', 'No account found for that email address.');
    }
    if (!password_verify($password, (string) $user['password'])) {
        bc_v1_json_error(401, 'wrong_password', 'Incorrect password.');
    }

    $userId = (int) $user['id'];
    $role = bugcatcher_normalize_system_role((string) ($user['role'] ?? 'user'));
    $activeOrgId = 0;
    if ($requestedOrgId > 0 && bc_v1_has_membership($conn, $requestedOrgId, $userId)) {
        $activeOrgId = $requestedOrgId;
    } elseif ((int) ($user['last_active_org_id'] ?? 0) > 0 && bc_v1_has_membership($conn, (int) $user['last_active_org_id'], $userId)) {
        $activeOrgId = (int) $user['last_active_org_id'];
    } else {
        $activeOrgId = bc_v1_first_org_id($conn, $userId);
    }

    $_SESSION['id'] = $userId;
    $_SESSION['username'] = (string) $user['username'];
    $_SESSION['role'] = $role;
    bugcatcher_mark_known_user_browser();
    bc_v1_set_active_org($conn, $userId, $activeOrgId);

    $normalizedUser = bc_v1_fetch_user_by_id($conn, $userId);
    if (!$normalizedUser) {
        bc_v1_json_error(500, 'login_failed', 'Login succeeded but user record could not be loaded.');
    }
    $tokens = bc_v1_issue_token_pair($normalizedUser, $activeOrgId);

    bc_v1_json_success([
        'user' => [
            'id' => $normalizedUser['id'],
            'username' => $normalizedUser['username'],
            'email' => $normalizedUser['email'],
            'role' => $normalizedUser['role'],
        ],
        'active_org_id' => $activeOrgId,
        'tokens' => $tokens,
    ]);
}

function bc_v1_auth_signup(mysqli $conn, array $params): void
{
    bc_v1_require_method(['POST']);
    $payload = bc_v1_request_data();
    $username = trim((string) ($payload['username'] ?? ''));
    $email = trim((string) ($payload['email'] ?? ''));
    $password = (string) ($payload['password'] ?? '');
    $confirm = (string) ($payload['confirm_password'] ?? $payload['cpass'] ?? '');

    if ($username === '' || $email === '' || $password === '' || $confirm === '') {
        bc_v1_json_error(422, 'validation_error', 'username, email, password, and confirm_password are required.');
    }
    if ($password !== $confirm) {
        bc_v1_json_error(422, 'password_mismatch', 'Password does not match.');
    }

    $check = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $check->bind_param('s', $email);
    $check->execute();
    $exists = $check->get_result()->fetch_assoc();
    $check->close();
    if ($exists) {
        bc_v1_json_error(409, 'email_exists', 'This email is already used. Try another one.');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $insert = $conn->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, 'user')");
    $insert->bind_param('sss', $username, $email, $hash);
    $insert->execute();
    $userId = (int) $conn->insert_id;
    $insert->close();

    bc_v1_json_success([
        'created' => true,
        'user_id' => $userId,
        'message' => 'You are registered successfully. You can now login.',
    ], 201);
}

function bc_v1_auth_refresh(mysqli $conn, array $params): void
{
    bc_v1_require_method(['POST']);
    $payload = bc_v1_request_data();
    $refreshToken = trim((string) ($payload['refresh_token'] ?? ''));
    if ($refreshToken === '') {
        $refreshToken = bc_v1_bearer_token();
    }
    if ($refreshToken === '') {
        bc_v1_json_error(422, 'validation_error', 'refresh_token is required.');
    }

    $tokenPayload = bc_v1_verify_token($refreshToken);
    if (!$tokenPayload || ($tokenPayload['type'] ?? '') !== 'refresh') {
        bc_v1_json_error(401, 'invalid_refresh_token', 'Invalid or expired refresh token.');
    }

    $userId = (int) ($tokenPayload['sub'] ?? 0);
    $user = bc_v1_fetch_user_by_id($conn, $userId);
    if (!$user) {
        bc_v1_json_error(401, 'user_not_found', 'Refresh token user no longer exists.');
    }

    $activeOrgId = (int) ($tokenPayload['ao'] ?? 0);
    if ($activeOrgId > 0 && !bc_v1_has_membership($conn, $activeOrgId, $userId)) {
        $activeOrgId = 0;
    }
    if ($activeOrgId <= 0) {
        $activeOrgId = (int) ($user['last_active_org_id'] ?? 0);
    }
    if ($activeOrgId > 0 && !bc_v1_has_membership($conn, $activeOrgId, $userId)) {
        $activeOrgId = bc_v1_first_org_id($conn, $userId);
    }

    bc_v1_json_success([
        'tokens' => bc_v1_issue_token_pair($user, $activeOrgId),
        'active_org_id' => $activeOrgId,
    ]);
}

function bc_v1_auth_logout(mysqli $conn, array $params): void
{
    bc_v1_require_method(['POST']);
    bugcatcher_clear_known_user_browser();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $cookie = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $cookie['path'], $cookie['domain'], $cookie['secure'], $cookie['httponly']);
    }
    session_destroy();
    bc_v1_json_success([
        'logged_out' => true,
        'note' => 'Tokens are stateless. Delete them on the client after logout.',
    ]);
}

function bc_v1_auth_me(mysqli $conn, array $params): void
{
    bc_v1_require_method(['GET']);
    $actor = bc_v1_actor($conn, true);
    $user = $actor['user'];

    $memberships = [];
    $stmt = $conn->prepare("
        SELECT om.org_id, om.role, o.name AS org_name, o.owner_id
        FROM org_members om
        JOIN organizations o ON o.id = om.org_id
        WHERE om.user_id = ?
        ORDER BY o.name ASC
    ");
    $stmt->bind_param('i', $user['id']);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as $row) {
        $memberships[] = [
            'org_id' => (int) $row['org_id'],
            'org_name' => (string) $row['org_name'],
            'role' => (string) $row['role'],
            'is_owner' => (int) $row['owner_id'] === (int) $user['id'],
        ];
    }

    bc_v1_json_success([
        'user' => [
            'id' => (int) $user['id'],
            'username' => (string) $user['username'],
            'email' => (string) $user['email'],
            'role' => (string) $user['role'],
        ],
        'auth_type' => (string) $actor['auth_type'],
        'active_org_id' => (int) ($actor['active_org_id'] ?? 0),
        'memberships' => $memberships,
    ]);
}

function bc_v1_session_active_org_put(mysqli $conn, array $params): void
{
    bc_v1_require_method(['PUT']);
    $actor = bc_v1_actor($conn, true);
    $payload = bc_v1_request_data();
    $orgId = bc_v1_get_int($payload, 'org_id', 0);
    if ($orgId <= 0) {
        bc_v1_json_error(422, 'invalid_org', 'org_id must be a positive integer.');
    }
    $userId = (int) $actor['user']['id'];
    if (!bc_v1_has_membership($conn, $orgId, $userId)) {
        bc_v1_json_error(403, 'org_membership_required', 'You are not a member of the selected organization.');
    }

    bc_v1_set_active_org($conn, $userId, $orgId);
    bc_v1_json_success([
        'active_org_id' => $orgId,
        'tokens' => bc_v1_issue_token_pair($actor['user'], $orgId),
    ]);
}

function bc_v1_auth_forgot_request_otp(mysqli $conn, array $params): void
{
    bc_v1_require_method(['POST']);
    $payload = bc_v1_request_data();
    $result = bugcatcher_password_reset_request_otp($conn, trim((string) ($payload['email'] ?? '')));
    if (!$result['ok']) {
        bc_v1_json_error(422, 'reset_request_failed', (string) ($result['error'] ?? 'Unable to start password reset.'));
    }
    bc_v1_json_success(['step' => bugcatcher_password_reset_current_step(), 'message' => (string) ($result['message'] ?? '')]);
}

function bc_v1_auth_forgot_resend_otp(mysqli $conn, array $params): void
{
    bc_v1_require_method(['POST']);
    $payload = bc_v1_request_data();
    $email = trim((string) ($payload['email'] ?? bugcatcher_password_reset_session_email()));
    $result = bugcatcher_password_reset_resend_otp($conn, $email);
    if (!$result['ok']) {
        bc_v1_json_error(422, 'reset_resend_failed', (string) ($result['error'] ?? 'Unable to resend reset code.'));
    }
    bc_v1_json_success(['step' => bugcatcher_password_reset_current_step(), 'message' => (string) ($result['message'] ?? '')]);
}

function bc_v1_auth_forgot_verify_otp(mysqli $conn, array $params): void
{
    bc_v1_require_method(['POST']);
    $payload = bc_v1_request_data();
    $email = trim((string) ($payload['email'] ?? bugcatcher_password_reset_session_email()));
    $otp = trim((string) ($payload['otp'] ?? ''));
    if ($otp === '') {
        bc_v1_json_error(422, 'validation_error', 'otp is required.');
    }
    $result = bugcatcher_password_reset_verify_otp($conn, $email, $otp);
    if (!$result['ok']) {
        bc_v1_json_error(422, 'reset_verify_failed', (string) ($result['error'] ?? 'Unable to verify OTP.'));
    }
    bc_v1_json_success([
        'step' => bugcatcher_password_reset_current_step(),
        'verified_request_id' => bugcatcher_password_reset_verified_request_id(),
        'message' => (string) ($result['message'] ?? ''),
    ]);
}

function bc_v1_auth_forgot_reset_password(mysqli $conn, array $params): void
{
    bc_v1_require_method(['POST']);
    $payload = bc_v1_request_data();
    $email = trim((string) ($payload['email'] ?? bugcatcher_password_reset_session_email()));
    $password = (string) ($payload['password'] ?? '');
    $confirm = (string) ($payload['confirm_password'] ?? $payload['cpass'] ?? '');
    $result = bugcatcher_password_reset_update_password(
        $conn,
        bugcatcher_password_reset_verified_request_id(),
        $email,
        $password,
        $confirm
    );
    if (!$result['ok']) {
        bc_v1_json_error(422, 'reset_password_failed', (string) ($result['error'] ?? 'Unable to reset password.'));
    }
    bc_v1_json_success(['reset' => true, 'message' => (string) ($result['message'] ?? 'Password has been reset.')]);
}
