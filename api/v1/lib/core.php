<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/password_reset.php';
require_once dirname(__DIR__, 3) . '/app/checklist_lib.php';
require_once dirname(__DIR__, 3) . '/app/openclaw_lib.php';

const BC_V1_ORG_ROLES = [
    'owner',
    'member',
    'Project Manager',
    'QA Lead',
    'Senior Developer',
    'Senior QA',
    'Junior Developer',
    'QA Tester',
];

function bc_v1_json_success(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode(['ok' => true, 'data' => $data]);
    exit;
}

function bc_v1_json_error(int $statusCode, string $code, string $message, $details = null): void
{
    $error = ['code' => $code, 'message' => $message];
    if ($details !== null) {
        $error['details'] = $details;
    }

    http_response_code($statusCode);
    echo json_encode(['ok' => false, 'error' => $error]);
    exit;
}

function bc_v1_method(): string
{
    return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
}

function bc_v1_require_method(array $allowed): string
{
    $method = bc_v1_method();
    $normalized = array_map('strtoupper', $allowed);
    if (!in_array($method, $normalized, true)) {
        bc_v1_json_error(405, 'method_not_allowed', 'Method not allowed.', ['allowed' => $normalized]);
    }
    return $method;
}

function bc_v1_json_body(bool $required = false): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        if ($required) {
            bc_v1_json_error(400, 'invalid_json', 'Request body must be valid JSON.');
        }
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        bc_v1_json_error(400, 'invalid_json', 'Request body must be valid JSON.');
    }

    return $decoded;
}

function bc_v1_request_data(): array
{
    if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false) {
        return bc_v1_json_body(false);
    }

    if (!empty($_POST)) {
        return $_POST;
    }

    if (in_array(bc_v1_method(), ['PUT', 'PATCH', 'DELETE'], true)) {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        parse_str($raw, $parsed);
        return is_array($parsed) ? $parsed : [];
    }

    return [];
}

function bc_v1_get_int(array $source, string $key, int $default = 0): int
{
    $value = $source[$key] ?? null;
    if (is_int($value)) {
        return $value;
    }
    if (!is_numeric($value)) {
        return $default;
    }
    $text = (string) $value;
    return ctype_digit($text) ? (int) $text : $default;
}

function bc_v1_get_bool(array $source, string $key, bool $default = false): bool
{
    if (!array_key_exists($key, $source)) {
        return $default;
    }

    $value = $source[$key];
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return ((int) $value) !== 0;
    }

    $normalized = strtolower(trim((string) $value));
    if ($normalized === '') {
        return $default;
    }

    return in_array($normalized, ['1', 'true', 'yes', 'on', 'enabled'], true);
}

function bc_v1_authorization_header(): string
{
    return (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
}

function bc_v1_bearer_token(): string
{
    $header = bc_v1_authorization_header();
    if (stripos($header, 'Bearer ') !== 0) {
        return '';
    }
    return trim(substr($header, 7));
}

function bc_v1_base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function bc_v1_base64url_decode(string $data): string
{
    $padding = strlen($data) % 4;
    if ($padding > 0) {
        $data .= str_repeat('=', 4 - $padding);
    }
    $decoded = base64_decode(strtr($data, '-_', '+/'), true);
    return is_string($decoded) ? $decoded : '';
}

function bc_v1_token_secret(): string
{
    $secret = (string) bugcatcher_config('OPENCLAW_ENCRYPTION_KEY', '');
    if ($secret === '' || $secret === 'replace-with-32-byte-secret') {
        $secret = (string) bugcatcher_config('OPENCLAW_INTERNAL_SHARED_SECRET', '');
    }
    if ($secret === '' || $secret === 'replace-me-too') {
        $secret = 'bugcatcher-v1-dev-secret';
    }
    return hash('sha256', 'bugcatcher-v1|' . $secret, true);
}

function bc_v1_sign_token(array $payload): string
{
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $head = bc_v1_base64url_encode(json_encode($header));
    $body = bc_v1_base64url_encode(json_encode($payload));
    $sig = hash_hmac('sha256', $head . '.' . $body, bc_v1_token_secret(), true);
    return $head . '.' . $body . '.' . bc_v1_base64url_encode($sig);
}

function bc_v1_verify_token(string $token): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }
    [$head, $body, $sig] = $parts;
    $expected = hash_hmac('sha256', $head . '.' . $body, bc_v1_token_secret(), true);
    $actual = bc_v1_base64url_decode($sig);
    if ($actual === '' || !hash_equals($expected, $actual)) {
        return null;
    }

    $decoded = bc_v1_base64url_decode($body);
    if ($decoded === '') {
        return null;
    }
    $payload = json_decode($decoded, true);
    if (!is_array($payload)) {
        return null;
    }
    if ((int) ($payload['exp'] ?? 0) <= time()) {
        return null;
    }
    return $payload;
}

function bc_v1_fetch_user_by_id(mysqli $conn, int $userId): ?array
{
    $stmt = $conn->prepare("SELECT id, username, email, role, last_active_org_id FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return null;
    }
    $row['id'] = (int) $row['id'];
    $row['role'] = bugcatcher_normalize_system_role((string) ($row['role'] ?? 'user'));
    $row['last_active_org_id'] = (int) ($row['last_active_org_id'] ?? 0);
    return $row;
}

function bc_v1_has_membership(mysqli $conn, int $orgId, int $userId): bool
{
    $stmt = $conn->prepare("SELECT 1 FROM org_members WHERE org_id = ? AND user_id = ? LIMIT 1");
    $stmt->bind_param('ii', $orgId, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (bool) $row;
}

function bc_v1_first_org_id(mysqli $conn, int $userId): int
{
    $stmt = $conn->prepare("SELECT org_id FROM org_members WHERE user_id = ? ORDER BY org_id ASC LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row['org_id'] ?? 0);
}

function bc_v1_set_active_org(mysqli $conn, int $userId, int $orgId): void
{
    $stmt = $conn->prepare("UPDATE users SET last_active_org_id = NULLIF(?, 0) WHERE id = ?");
    $stmt->bind_param('ii', $orgId, $userId);
    $stmt->execute();
    $stmt->close();

    if ($orgId > 0) {
        $_SESSION['active_org_id'] = $orgId;
    } else {
        unset($_SESSION['active_org_id']);
    }
}

function bc_v1_issue_token_pair(array $user, int $activeOrgId): array
{
    $now = time();
    $base = [
        'iss' => 'bugcatcher',
        'sub' => (int) $user['id'],
        'username' => (string) $user['username'],
        'role' => (string) $user['role'],
        'ao' => $activeOrgId,
        'iat' => $now,
    ];

    $access = bc_v1_sign_token(array_merge($base, ['type' => 'access', 'exp' => $now + 900]));
    $refresh = bc_v1_sign_token(array_merge($base, ['type' => 'refresh', 'exp' => $now + (30 * 24 * 60 * 60)]));

    return [
        'token_type' => 'Bearer',
        'access_token' => $access,
        'access_expires_in' => 900,
        'refresh_token' => $refresh,
        'refresh_expires_in' => 30 * 24 * 60 * 60,
    ];
}

function bc_v1_actor(mysqli $conn, bool $required = true): ?array
{
    $token = bc_v1_bearer_token();
    if ($token !== '') {
        $payload = bc_v1_verify_token($token);
        if (!$payload || ($payload['type'] ?? '') !== 'access') {
            bc_v1_json_error(401, 'invalid_token', 'Invalid or expired access token.');
        }
        $user = bc_v1_fetch_user_by_id($conn, (int) ($payload['sub'] ?? 0));
        if (!$user) {
            bc_v1_json_error(401, 'user_not_found', 'Token user no longer exists.');
        }
        $activeOrgId = (int) ($payload['ao'] ?? 0);
        if ($activeOrgId <= 0) {
            $activeOrgId = (int) ($user['last_active_org_id'] ?? 0);
        }
        return [
            'auth_type' => 'token',
            'user' => $user,
            'active_org_id' => $activeOrgId,
            'token_payload' => $payload,
        ];
    }

    $sessionUserId = (int) ($_SESSION['id'] ?? 0);
    if ($sessionUserId > 0) {
        $user = bc_v1_fetch_user_by_id($conn, $sessionUserId);
        if (!$user) {
            bc_v1_json_error(401, 'session_user_not_found', 'Session user no longer exists.');
        }
        $activeOrgId = (int) ($_SESSION['active_org_id'] ?? 0);
        if ($activeOrgId <= 0) {
            $activeOrgId = (int) ($user['last_active_org_id'] ?? 0);
        }
        return [
            'auth_type' => 'session',
            'user' => $user,
            'active_org_id' => $activeOrgId,
            'token_payload' => null,
        ];
    }

    if ($required) {
        bc_v1_json_error(401, 'unauthorized', 'Authentication required.');
    }
    return null;
}

function bc_v1_bridge_session_auth(mysqli $conn, bool $required = true): ?array
{
    $actor = bc_v1_actor($conn, $required);
    if (!$actor) {
        return null;
    }

    $user = $actor['user'];
    $_SESSION['id'] = (int) $user['id'];
    $_SESSION['username'] = (string) $user['username'];
    $_SESSION['role'] = (string) $user['role'];

    $activeOrgId = (int) ($actor['active_org_id'] ?? 0);
    if ($activeOrgId > 0 && !bc_v1_has_membership($conn, $activeOrgId, (int) $user['id'])) {
        $activeOrgId = 0;
    }
    if ($activeOrgId <= 0) {
        $activeOrgId = bc_v1_first_org_id($conn, (int) $user['id']);
    }

    if ($activeOrgId > 0) {
        $_SESSION['active_org_id'] = $activeOrgId;
    } else {
        unset($_SESSION['active_org_id']);
    }

    return $actor;
}

function bc_v1_org_context(mysqli $conn, array $actor, int $orgId = 0): array
{
    if ($orgId <= 0) {
        $orgId = (int) ($actor['active_org_id'] ?? 0);
    }
    if ($orgId <= 0) {
        bc_v1_json_error(403, 'org_context_required', 'Active organization is required.');
    }

    $userId = (int) ($actor['user']['id'] ?? 0);
    $stmt = $conn->prepare("
        SELECT om.role, o.name AS org_name, o.owner_id
        FROM org_members om
        JOIN organizations o ON o.id = om.org_id
        WHERE om.org_id = ? AND om.user_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('ii', $orgId, $userId);
    $stmt->execute();
    $membership = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$membership) {
        bc_v1_json_error(403, 'org_membership_required', 'You are not a member of the organization.');
    }

    return [
        'org_id' => $orgId,
        'org_name' => (string) ($membership['org_name'] ?? ''),
        'org_role' => (string) ($membership['role'] ?? ''),
        'org_owner_id' => (int) ($membership['owner_id'] ?? 0),
        'is_org_owner' => (int) ($membership['owner_id'] ?? 0) === $userId,
        'user_id' => $userId,
        'system_role' => (string) ($actor['user']['role'] ?? 'user'),
    ];
}

function bc_v1_require_super_admin(array $actor): void
{
    if (!bugcatcher_is_super_admin_role((string) ($actor['user']['role'] ?? 'user'))) {
        bc_v1_json_error(403, 'forbidden', 'Only super admins can access this endpoint.');
    }
}

function bc_v1_require_manager_role(array $orgContext): void
{
    if (!bugcatcher_checklist_is_manager_role((string) $orgContext['org_role'])) {
        bc_v1_json_error(403, 'forbidden', 'Only checklist managers can perform this action.');
    }
}

function bc_v1_stmt_bind(mysqli_stmt $stmt, string $types, array $params): void
{
    if ($types === '' || !$params) {
        return;
    }
    $refs = [];
    foreach ($params as $key => $value) {
        $refs[$key] = &$params[$key];
    }
    array_unshift($refs, $types);
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function bc_v1_route_path(): string
{
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        return '/';
    }
    $scriptDir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/api/v1/index.php')));
    if ($scriptDir !== '/' && $scriptDir !== '' && strpos($path, $scriptDir) === 0) {
        $path = substr($path, strlen($scriptDir));
        if ($path === '' || $path === false) {
            $path = '/';
        }
    }
    $normalized = '/' . trim($path, '/');
    return $normalized === '//' ? '/' : $normalized;
}

function bc_v1_match_pattern(string $pattern, string $path): ?array
{
    $normalizedPattern = '/' . trim($pattern, '/');
    $normalizedPath = '/' . trim($path, '/');

    if ($normalizedPattern === '/' && $normalizedPath === '/') {
        return [];
    }

    $patternParts = array_values(array_filter(explode('/', trim($normalizedPattern, '/')), 'strlen'));
    $pathParts = array_values(array_filter(explode('/', trim($normalizedPath, '/')), 'strlen'));
    if (count($patternParts) !== count($pathParts)) {
        return null;
    }

    $params = [];
    foreach ($patternParts as $index => $part) {
        $value = $pathParts[$index];
        if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)\}$/', $part, $matches)) {
            $params[$matches[1]] = urldecode($value);
            continue;
        }
        if ($part !== $value) {
            return null;
        }
    }

    return $params;
}

function bc_v1_dispatch(string $method, string $path, array $routes, mysqli $conn): bool
{
    foreach ($routes as $route) {
        $routeMethod = strtoupper((string) ($route['method'] ?? 'GET'));
        if ($routeMethod !== 'ANY' && $routeMethod !== $method) {
            continue;
        }
        $params = bc_v1_match_pattern((string) $route['pattern'], $path);
        if ($params === null) {
            continue;
        }
        $handler = $route['handler'] ?? null;
        if (!is_callable($handler)) {
            bc_v1_json_error(500, 'route_handler_invalid', 'Route handler is invalid.');
        }
        $handler($conn, $params);
        return true;
    }
    return false;
}

function bc_v1_include_legacy(string $relativePath, array $query = []): void
{
    foreach ($query as $key => $value) {
        $_GET[$key] = (string) $value;
    }
    require dirname(__DIR__, 3) . '/' . ltrim($relativePath, '/');
    exit;
}
