<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/checklist_lib.php';

const BUGCATCHER_OPENCLAW_DOCS_DIR = __DIR__ . '/../docs/openclaw';
const BUGCATCHER_OPENCLAW_DOC_ORDER = [
    'README.md',
    'architecture.md',
    'self-setup-runbook.md',
    'discord-setup.md',
    'provider-setup.md',
    'server-setup.md',
    'super-admin-account.md',
    'user-guide.md',
    'admin-guide.md',
    'api.md',
    'implementation-handoff.md',
];

function bugcatcher_require_super_admin(string $role): void
{
    if (!bugcatcher_is_super_admin_role($role)) {
        http_response_code(403);
        die('Only super admins can access this area.');
    }
}

function bugcatcher_openclaw_json_response(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

function bugcatcher_openclaw_json_request_body(): array
{
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw ?: '', true);
    return is_array($decoded) ? $decoded : [];
}

function bugcatcher_openclaw_authorization_header(): string
{
    return (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
}

function bugcatcher_openclaw_require_internal_request(): void
{
    $expected = bugcatcher_config('OPENCLAW_INTERNAL_SHARED_SECRET', '');
    $header = bugcatcher_openclaw_authorization_header();
    $token = '';
    if (stripos($header, 'Bearer ') === 0) {
        $token = trim(substr($header, 7));
    }

    if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
        bugcatcher_openclaw_json_response(401, ['error' => 'Invalid or missing OpenClaw bearer token.']);
    }
}

function bugcatcher_openclaw_hash_code(string $code): string
{
    return hash('sha256', $code);
}

function bugcatcher_openclaw_generate_link_code(): string
{
    return strtoupper(substr(bin2hex(random_bytes(6)), 0, 12));
}

function bugcatcher_openclaw_encryption_key(): string
{
    $key = (string) bugcatcher_config('OPENCLAW_ENCRYPTION_KEY', '');
    if ($key === '') {
        throw new RuntimeException('OPENCLAW_ENCRYPTION_KEY is not configured.');
    }

    return hash('sha256', $key, true);
}

function bugcatcher_openclaw_encrypt_secret(string $value): string
{
    if ($value === '') {
        return '';
    }

    $iv = random_bytes(16);
    $ciphertext = openssl_encrypt($value, 'AES-256-CBC', bugcatcher_openclaw_encryption_key(), OPENSSL_RAW_DATA, $iv);
    if ($ciphertext === false) {
        throw new RuntimeException('Failed to encrypt secret.');
    }

    return base64_encode($iv . $ciphertext);
}

function bugcatcher_openclaw_decrypt_secret(?string $value): string
{
    if (!is_string($value) || $value === '') {
        return '';
    }

    $decoded = base64_decode($value, true);
    if ($decoded === false || strlen($decoded) < 17) {
        return '';
    }

    $iv = substr($decoded, 0, 16);
    $ciphertext = substr($decoded, 16);
    $plaintext = openssl_decrypt($ciphertext, 'AES-256-CBC', bugcatcher_openclaw_encryption_key(), OPENSSL_RAW_DATA, $iv);

    return $plaintext === false ? '' : $plaintext;
}

function bugcatcher_openclaw_mask_secret(?string $value): string
{
    try {
        $value = bugcatcher_openclaw_decrypt_secret($value);
    } catch (Throwable $e) {
        return 'Configured (encryption key unavailable)';
    }
    if ($value === '') {
        return 'Not set';
    }
    if (strlen($value) <= 6) {
        return str_repeat('*', strlen($value));
    }

    return substr($value, 0, 3) . str_repeat('*', max(4, strlen($value) - 6)) . substr($value, -3);
}

function bugcatcher_openclaw_mask_plain_secret(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return 'Not set';
    }
    if (strlen($value) <= 6) {
        return str_repeat('*', strlen($value));
    }

    return substr($value, 0, 3) . str_repeat('*', max(4, strlen($value) - 6)) . substr($value, -3);
}

function bugcatcher_openclaw_config_version_now(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

function bugcatcher_openclaw_optional_datetime(?string $value): ?string
{
    if (!is_string($value) || trim($value) === '') {
        return null;
    }

    return date('Y-m-d H:i:s', strtotime($value));
}

function bugcatcher_openclaw_control_plane_ensure(mysqli $conn): void
{
    $version = bugcatcher_openclaw_config_version_now();
    $stmt = $conn->prepare("
        INSERT INTO openclaw_control_plane_state
            (id, config_version, updated_at)
        VALUES (1, ?, NOW())
        ON DUPLICATE KEY UPDATE
            config_version = COALESCE(NULLIF(config_version, ''), VALUES(config_version))
    ");
    $stmt->bind_param('s', $version);
    $stmt->execute();
    $stmt->close();
}

function bugcatcher_openclaw_runtime_status_ensure(mysqli $conn): void
{
    $stmt = $conn->prepare("
        INSERT INTO openclaw_runtime_status
            (id, gateway_state, discord_state, updated_at)
        VALUES (1, 'unknown', 'unknown', NOW())
        ON DUPLICATE KEY UPDATE
            id = id
    ");
    $stmt->execute();
    $stmt->close();
}

function bugcatcher_openclaw_fetch_control_plane_state(mysqli $conn): array
{
    bugcatcher_openclaw_control_plane_ensure($conn);
    $result = $conn->query("
        SELECT cps.*,
               u.username AS last_runtime_reload_requested_by_name
        FROM openclaw_control_plane_state cps
        LEFT JOIN users u ON u.id = cps.last_runtime_reload_requested_by
        WHERE cps.id = 1
        LIMIT 1
    ");
    $row = $result ? $result->fetch_assoc() : null;

    return $row ?: [
        'id' => 1,
        'config_version' => bugcatcher_openclaw_config_version_now(),
    ];
}

function bugcatcher_openclaw_fetch_runtime_status(mysqli $conn): array
{
    bugcatcher_openclaw_runtime_status_ensure($conn);
    $result = $conn->query("
        SELECT *
        FROM openclaw_runtime_status
        WHERE id = 1
        LIMIT 1
    ");
    $row = $result ? $result->fetch_assoc() : null;

    return $row ?: ['id' => 1];
}

function bugcatcher_openclaw_fetch_pending_reload_request(mysqli $conn): ?array
{
    $result = $conn->query("
        SELECT orr.*,
               u.username AS requested_by_username
        FROM openclaw_reload_requests orr
        LEFT JOIN users u ON u.id = orr.requested_by_user_id
        WHERE orr.status IN ('pending', 'processing')
        ORDER BY orr.id ASC
        LIMIT 1
    ");
    $row = $result ? $result->fetch_assoc() : null;

    return $row ?: null;
}

function bugcatcher_openclaw_queue_reload_request(mysqli $conn, ?int $actorUserId, string $reason): int
{
    bugcatcher_openclaw_control_plane_ensure($conn);
    $reason = substr(trim($reason), 0, 120);
    $requestedAt = date('Y-m-d H:i:s');
    $requestedBy = max(0, (int) ($actorUserId ?? 0));

    $stmt = $conn->prepare("
        INSERT INTO openclaw_reload_requests
            (requested_by_user_id, reason, status, requested_at)
        VALUES (NULLIF(?, 0), NULLIF(?, ''), 'pending', ?)
    ");
    $stmt->bind_param('iss', $requestedBy, $reason, $requestedAt);
    $stmt->execute();
    $reloadRequestId = (int) $stmt->insert_id;
    $stmt->close();

    $stmt = $conn->prepare("
        UPDATE openclaw_control_plane_state
        SET last_runtime_reload_requested_at = ?,
            last_runtime_reload_requested_by = NULLIF(?, 0),
            last_runtime_reload_reason = NULLIF(?, ''),
            updated_at = NOW()
        WHERE id = 1
    ");
    $stmt->bind_param('sis', $requestedAt, $requestedBy, $reason);
    $stmt->execute();
    $stmt->close();

    return $reloadRequestId;
}

function bugcatcher_openclaw_mark_config_changed(mysqli $conn, ?int $actorUserId, string $reason): int
{
    bugcatcher_openclaw_control_plane_ensure($conn);
    $version = bugcatcher_openclaw_config_version_now();
    $requestedAt = date('Y-m-d H:i:s');
    $requestedBy = max(0, (int) ($actorUserId ?? 0));
    $reason = substr(trim($reason), 0, 120);

    $stmt = $conn->prepare("
        UPDATE openclaw_control_plane_state
        SET config_version = ?,
            last_runtime_reload_requested_at = ?,
            last_runtime_reload_requested_by = NULLIF(?, 0),
            last_runtime_reload_reason = NULLIF(?, ''),
            updated_at = NOW()
        WHERE id = 1
    ");
    $stmt->bind_param('ssis', $version, $requestedAt, $requestedBy, $reason);
    $stmt->execute();
    $stmt->close();

    return bugcatcher_openclaw_queue_reload_request($conn, $actorUserId, $reason);
}

function bugcatcher_openclaw_update_reload_request_status(
    mysqli $conn,
    int $reloadRequestId,
    string $status,
    ?string $errorMessage = null
): void {
    if ($reloadRequestId <= 0 || !in_array($status, ['pending', 'processing', 'completed', 'failed'], true)) {
        return;
    }

    $processedAt = in_array($status, ['completed', 'failed'], true) ? date('Y-m-d H:i:s') : null;
    $stmt = $conn->prepare("
        UPDATE openclaw_reload_requests
        SET status = ?,
            processed_at = NULLIF(?, ''),
            error_message = NULLIF(?, '')
        WHERE id = ?
    ");
    $stmt->bind_param('sssi', $status, $processedAt, $errorMessage, $reloadRequestId);
    $stmt->execute();
    $stmt->close();
}

function bugcatcher_openclaw_temp_dir_ensure(): string
{
    $dir = bugcatcher_openclaw_temp_dir();
    if ($dir === '') {
        throw new RuntimeException('OPENCLAW_TEMP_UPLOAD_DIR is not configured.');
    }
    if (!is_dir($dir)) {
        mkdir($dir, 02775, true);
    }
    return $dir;
}

function bugcatcher_openclaw_resolve_temp_file(string $token): ?string
{
    $token = preg_replace('/[^a-zA-Z0-9._-]/', '', $token);
    if ($token === '') {
        return null;
    }

    $candidates = [
        bugcatcher_openclaw_temp_dir() . DIRECTORY_SEPARATOR . $token,
        bugcatcher_checklist_uploads_dir() . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . $token,
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function bugcatcher_openclaw_store_link_code(mysqli $conn, int $userId, string $code): void
{
    $hash = bugcatcher_openclaw_hash_code($code);
    $expiresAt = date('Y-m-d H:i:s', time() + 600);
    $stmt = $conn->prepare("
        INSERT INTO discord_user_links
            (user_id, link_code_hash, link_code_expires_at, is_active)
        VALUES (?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE
            link_code_hash = VALUES(link_code_hash),
            link_code_expires_at = VALUES(link_code_expires_at),
            is_active = 1
    ");
    $stmt->bind_param('iss', $userId, $hash, $expiresAt);
    $stmt->execute();
    $stmt->close();
}

function bugcatcher_openclaw_fetch_user_link_by_user(mysqli $conn, int $userId): ?array
{
    $stmt = $conn->prepare("
        SELECT dul.*, u.username, u.email
        FROM discord_user_links dul
        JOIN users u ON u.id = dul.user_id
        WHERE dul.user_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function bugcatcher_openclaw_fetch_user_link_by_discord_user(mysqli $conn, string $discordUserId): ?array
{
    $stmt = $conn->prepare("
        SELECT dul.*, u.username, u.email
        FROM discord_user_links dul
        JOIN users u ON u.id = dul.user_id
        WHERE dul.discord_user_id = ? AND dul.is_active = 1
        LIMIT 1
    ");
    $stmt->bind_param('s', $discordUserId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function bugcatcher_openclaw_fetch_user_link_by_valid_code(mysqli $conn, string $code): ?array
{
    $hash = bugcatcher_openclaw_hash_code($code);
    $now = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("
        SELECT dul.*, u.username, u.email
        FROM discord_user_links dul
        JOIN users u ON u.id = dul.user_id
        WHERE dul.link_code_hash = ? AND dul.link_code_expires_at IS NOT NULL AND dul.link_code_expires_at >= ?
        LIMIT 1
    ");
    $stmt->bind_param('ss', $hash, $now);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function bugcatcher_openclaw_confirm_link(
    mysqli $conn,
    int $userId,
    string $discordUserId,
    string $discordUsername,
    string $discordGlobalName
): void {
    $linkedAt = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("
        INSERT INTO discord_user_links
            (user_id, discord_user_id, discord_username, discord_global_name, link_code_hash, link_code_expires_at, linked_at, last_seen_at, is_active)
        VALUES (?, ?, NULLIF(?, ''), NULLIF(?, ''), NULL, NULL, ?, ?, 1)
        ON DUPLICATE KEY UPDATE
            discord_user_id = VALUES(discord_user_id),
            discord_username = VALUES(discord_username),
            discord_global_name = VALUES(discord_global_name),
            link_code_hash = NULL,
            link_code_expires_at = NULL,
            linked_at = VALUES(linked_at),
            last_seen_at = VALUES(last_seen_at),
            is_active = 1
    ");
    $stmt->bind_param('isssss', $userId, $discordUserId, $discordUsername, $discordGlobalName, $linkedAt, $linkedAt);
    $stmt->execute();
    $stmt->close();
}

function bugcatcher_openclaw_unlink_user(mysqli $conn, int $userId): void
{
    $stmt = $conn->prepare("
        UPDATE discord_user_links
        SET discord_user_id = NULL,
            discord_username = NULL,
            discord_global_name = NULL,
            link_code_hash = NULL,
            link_code_expires_at = NULL,
            linked_at = NULL,
            is_active = 0
        WHERE user_id = ?
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
}

function bugcatcher_openclaw_touch_user_link(mysqli $conn, int $linkId): void
{
    $now = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("UPDATE discord_user_links SET last_seen_at = ? WHERE id = ?");
    $stmt->bind_param('si', $now, $linkId);
    $stmt->execute();
    $stmt->close();
}

function bugcatcher_openclaw_fetch_user_memberships(mysqli $conn, int $userId): array
{
    $stmt = $conn->prepare("
        SELECT o.id, o.name, om.role
        FROM org_members om
        JOIN organizations o ON o.id = om.org_id
        WHERE om.user_id = ?
        ORDER BY o.name ASC
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows ?: [];
}

function bugcatcher_openclaw_context_for_discord_user(mysqli $conn, string $discordUserId): ?array
{
    $link = bugcatcher_openclaw_fetch_user_link_by_discord_user($conn, $discordUserId);
    if (!$link) {
        return null;
    }

    bugcatcher_openclaw_touch_user_link($conn, (int) $link['id']);
    $memberships = bugcatcher_openclaw_fetch_user_memberships($conn, (int) $link['user_id']);
    $organizations = [];
    foreach ($memberships as $membership) {
        $organizations[] = [
            'id' => (int) $membership['id'],
            'name' => $membership['name'],
            'role' => $membership['role'],
            'projects' => bugcatcher_checklist_fetch_projects($conn, (int) $membership['id'], false),
        ];
    }

    return [
        'user' => [
            'id' => (int) $link['user_id'],
            'username' => $link['username'],
            'email' => $link['email'],
        ],
        'link' => [
            'discord_user_id' => $link['discord_user_id'],
            'discord_username' => $link['discord_username'],
            'discord_global_name' => $link['discord_global_name'],
            'linked_at' => $link['linked_at'],
        ],
        'organizations' => $organizations,
    ];
}

function bugcatcher_openclaw_fetch_runtime_config(mysqli $conn): ?array
{
    $result = $conn->query("
        SELECT orc.*,
               creator.username AS created_by_name,
               updater.username AS updated_by_name,
               provider.display_name AS default_provider_name,
               model.display_name AS default_model_name
        FROM openclaw_runtime_config orc
        LEFT JOIN users creator ON creator.id = orc.created_by
        LEFT JOIN users updater ON updater.id = orc.updated_by
        LEFT JOIN ai_provider_configs provider ON provider.id = orc.default_provider_config_id
        LEFT JOIN ai_models model ON model.id = orc.default_model_id
        ORDER BY orc.id DESC
        LIMIT 1
    ");
    $row = $result ? $result->fetch_assoc() : null;
    return $row ?: null;
}

function bugcatcher_openclaw_validate_provider_input(
    mysqli $conn,
    int $providerId,
    string $providerKey,
    string $displayName,
    string $providerType,
    string $baseUrl
): void {
    $providerKey = trim($providerKey);
    $displayName = trim($displayName);
    $providerType = trim($providerType);
    $baseUrl = trim($baseUrl);

    if ($providerKey === '' || !preg_match('/^[a-z0-9][a-z0-9._-]*$/i', $providerKey)) {
        throw new RuntimeException('Provider key must use letters, numbers, dots, underscores, or dashes.');
    }
    if ($displayName === '') {
        throw new RuntimeException('Display name is required.');
    }
    if ($providerType === '') {
        throw new RuntimeException('Provider type is required.');
    }
    if ($baseUrl !== '' && filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
        throw new RuntimeException('Base URL must be a valid URL.');
    }

    $stmt = $conn->prepare("
        SELECT id
        FROM ai_provider_configs
        WHERE provider_key = ?
          AND id <> ?
        LIMIT 1
    ");
    $stmt->bind_param('si', $providerKey, $providerId);
    $stmt->execute();
    $duplicate = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($duplicate) {
        throw new RuntimeException('Provider key must be unique.');
    }
}

function bugcatcher_openclaw_save_runtime_config(
    mysqli $conn,
    int $actorUserId,
    bool $isEnabled,
    string $discordBotToken,
    int $defaultProviderId,
    int $defaultModelId,
    string $notes
): void {
    $existing = bugcatcher_openclaw_fetch_runtime_config($conn);
    $encryptedToken = $existing['encrypted_discord_bot_token'] ?? null;
    if ($discordBotToken !== '') {
        $encryptedToken = bugcatcher_openclaw_encrypt_secret($discordBotToken);
    }

    if ($existing) {
        $stmt = $conn->prepare("
            UPDATE openclaw_runtime_config
            SET is_enabled = ?,
                encrypted_discord_bot_token = ?,
                default_provider_config_id = NULLIF(?, 0),
                default_model_id = NULLIF(?, 0),
                notes = NULLIF(?, ''),
                updated_by = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $runtimeId = (int) $existing['id'];
        $enabled = $isEnabled ? 1 : 0;
        $stmt->bind_param('isiisii', $enabled, $encryptedToken, $defaultProviderId, $defaultModelId, $notes, $actorUserId, $runtimeId);
        $stmt->execute();
        $stmt->close();
        bugcatcher_openclaw_mark_config_changed($conn, $actorUserId, 'runtime_config_saved');
        return;
    }

    $stmt = $conn->prepare("
        INSERT INTO openclaw_runtime_config
            (is_enabled, encrypted_discord_bot_token, default_provider_config_id, default_model_id, notes, created_by, updated_by, updated_at)
        VALUES (?, ?, NULLIF(?, 0), NULLIF(?, 0), NULLIF(?, ''), ?, ?, NOW())
    ");
    $enabled = $isEnabled ? 1 : 0;
    $stmt->bind_param('isiisii', $enabled, $encryptedToken, $defaultProviderId, $defaultModelId, $notes, $actorUserId, $actorUserId);
    $stmt->execute();
    $stmt->close();
    bugcatcher_openclaw_mark_config_changed($conn, $actorUserId, 'runtime_config_created');
}

function bugcatcher_openclaw_fetch_providers(mysqli $conn): array
{
    $result = $conn->query("
        SELECT apc.*,
               creator.username AS created_by_name,
               updater.username AS updated_by_name
        FROM ai_provider_configs apc
        LEFT JOIN users creator ON creator.id = apc.created_by
        LEFT JOIN users updater ON updater.id = apc.updated_by
        ORDER BY apc.display_name ASC, apc.id ASC
    ");
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function bugcatcher_openclaw_save_provider(
    mysqli $conn,
    int $actorUserId,
    int $providerId,
    string $providerKey,
    string $displayName,
    string $providerType,
    string $baseUrl,
    string $apiKey,
    bool $isEnabled,
    bool $supportsModelSync
): void {
    bugcatcher_openclaw_validate_provider_input($conn, $providerId, $providerKey, $displayName, $providerType, $baseUrl);

    $existing = null;
    if ($providerId > 0) {
        $stmt = $conn->prepare("SELECT * FROM ai_provider_configs WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $providerId);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
    }

    $encryptedKey = $existing['encrypted_api_key'] ?? null;
    if ($apiKey !== '') {
        $encryptedKey = bugcatcher_openclaw_encrypt_secret($apiKey);
    }

    if ($existing) {
        $stmt = $conn->prepare("
            UPDATE ai_provider_configs
            SET provider_key = ?,
                display_name = ?,
                provider_type = ?,
                base_url = NULLIF(?, ''),
                encrypted_api_key = ?,
                is_enabled = ?,
                supports_model_sync = ?,
                updated_by = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $enabled = $isEnabled ? 1 : 0;
        $sync = $supportsModelSync ? 1 : 0;
        $stmt->bind_param('sssssiiii', $providerKey, $displayName, $providerType, $baseUrl, $encryptedKey, $enabled, $sync, $actorUserId, $providerId);
        $stmt->execute();
        $stmt->close();
        bugcatcher_openclaw_mark_config_changed($conn, $actorUserId, 'provider_saved');
        return;
    }

    $stmt = $conn->prepare("
        INSERT INTO ai_provider_configs
            (provider_key, display_name, provider_type, base_url, encrypted_api_key, is_enabled, supports_model_sync, created_by, updated_by, updated_at)
        VALUES (?, ?, ?, NULLIF(?, ''), ?, ?, ?, ?, ?, NOW())
    ");
    $enabled = $isEnabled ? 1 : 0;
    $sync = $supportsModelSync ? 1 : 0;
    $stmt->bind_param('sssssiiii', $providerKey, $displayName, $providerType, $baseUrl, $encryptedKey, $enabled, $sync, $actorUserId, $actorUserId);
    $stmt->execute();
    $stmt->close();
    bugcatcher_openclaw_mark_config_changed($conn, $actorUserId, 'provider_created');
}

function bugcatcher_openclaw_delete_provider(mysqli $conn, int $providerId, ?int $actorUserId = null): void
{
    $stmt = $conn->prepare("DELETE FROM ai_provider_configs WHERE id = ?");
    $stmt->bind_param('i', $providerId);
    $stmt->execute();
    $stmt->close();
    bugcatcher_openclaw_mark_config_changed($conn, $actorUserId, 'provider_deleted');
}

function bugcatcher_openclaw_fetch_models(mysqli $conn): array
{
    $result = $conn->query("
        SELECT am.*, apc.display_name AS provider_name
        FROM ai_models am
        JOIN ai_provider_configs apc ON apc.id = am.provider_config_id
        ORDER BY apc.display_name ASC, am.display_name ASC
    ");
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function bugcatcher_openclaw_save_model(
    mysqli $conn,
    int $providerConfigId,
    int $modelId,
    string $remoteModelId,
    string $displayName,
    bool $supportsVision,
    bool $supportsJsonOutput,
    bool $isEnabled,
    bool $isDefault,
    ?int $actorUserId = null
): void {
    if ($providerConfigId <= 0) {
        throw new RuntimeException('A provider is required for the model.');
    }
    if (trim($remoteModelId) === '' || trim($displayName) === '') {
        throw new RuntimeException('Model id and display name are required.');
    }

    if ($isDefault) {
        $stmt = $conn->prepare("UPDATE ai_models SET is_default = 0 WHERE provider_config_id = ?");
        $stmt->bind_param('i', $providerConfigId);
        $stmt->execute();
        $stmt->close();
    }

    if ($modelId > 0) {
        $stmt = $conn->prepare("
            UPDATE ai_models
            SET model_id = ?,
                display_name = ?,
                supports_vision = ?,
                supports_json_output = ?,
                is_enabled = ?,
                is_default = ?,
                last_synced_at = NOW()
            WHERE id = ?
        ");
        $vision = $supportsVision ? 1 : 0;
        $json = $supportsJsonOutput ? 1 : 0;
        $enabled = $isEnabled ? 1 : 0;
        $default = $isDefault ? 1 : 0;
        $stmt->bind_param('ssiiiii', $remoteModelId, $displayName, $vision, $json, $enabled, $default, $modelId);
        $stmt->execute();
        $stmt->close();
        bugcatcher_openclaw_mark_config_changed($conn, $actorUserId, 'model_saved');
        return;
    }

    $stmt = $conn->prepare("
        INSERT INTO ai_models
            (provider_config_id, model_id, display_name, supports_vision, supports_json_output, is_enabled, is_default, last_synced_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $vision = $supportsVision ? 1 : 0;
    $json = $supportsJsonOutput ? 1 : 0;
    $enabled = $isEnabled ? 1 : 0;
    $default = $isDefault ? 1 : 0;
    $stmt->bind_param('issiiii', $providerConfigId, $remoteModelId, $displayName, $vision, $json, $enabled, $default);
    $stmt->execute();
    $stmt->close();
    bugcatcher_openclaw_mark_config_changed($conn, $actorUserId, 'model_created');
}

function bugcatcher_openclaw_delete_model(mysqli $conn, int $modelId, ?int $actorUserId = null): void
{
    $stmt = $conn->prepare("DELETE FROM ai_models WHERE id = ?");
    $stmt->bind_param('i', $modelId);
    $stmt->execute();
    $stmt->close();
    bugcatcher_openclaw_mark_config_changed($conn, $actorUserId, 'model_deleted');
}

function bugcatcher_openclaw_fetch_channel_bindings(mysqli $conn): array
{
    $result = $conn->query("
        SELECT dcb.*,
               creator.username AS created_by_name,
               updater.username AS updated_by_name
        FROM discord_channel_bindings dcb
        LEFT JOIN users creator ON creator.id = dcb.created_by
        LEFT JOIN users updater ON updater.id = dcb.updated_by
        ORDER BY dcb.guild_name ASC, dcb.channel_name ASC
    ");
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function bugcatcher_openclaw_save_channel_binding(
    mysqli $conn,
    int $actorUserId,
    int $bindingId,
    string $guildId,
    string $guildName,
    string $channelId,
    string $channelName,
    bool $isEnabled,
    bool $allowDmFollowup
): void {
    if (trim($guildId) === '' || trim($channelId) === '') {
        throw new RuntimeException('Guild ID and channel ID are required.');
    }

    if ($bindingId > 0) {
        $stmt = $conn->prepare("
            UPDATE discord_channel_bindings
            SET guild_id = ?,
                guild_name = NULLIF(?, ''),
                channel_id = ?,
                channel_name = NULLIF(?, ''),
                is_enabled = ?,
                allow_dm_followup = ?,
                updated_by = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $enabled = $isEnabled ? 1 : 0;
        $allowDm = $allowDmFollowup ? 1 : 0;
        $stmt->bind_param('ssssiiii', $guildId, $guildName, $channelId, $channelName, $enabled, $allowDm, $actorUserId, $bindingId);
        $stmt->execute();
        $stmt->close();
        bugcatcher_openclaw_mark_config_changed($conn, $actorUserId, 'channel_binding_saved');
        return;
    }

    $stmt = $conn->prepare("
        INSERT INTO discord_channel_bindings
            (guild_id, guild_name, channel_id, channel_name, is_enabled, allow_dm_followup, created_by, updated_by, updated_at)
        VALUES (?, NULLIF(?, ''), ?, NULLIF(?, ''), ?, ?, ?, ?, NOW())
    ");
    $enabled = $isEnabled ? 1 : 0;
    $allowDm = $allowDmFollowup ? 1 : 0;
    $stmt->bind_param('ssssiiii', $guildId, $guildName, $channelId, $channelName, $enabled, $allowDm, $actorUserId, $actorUserId);
    $stmt->execute();
    $stmt->close();
    bugcatcher_openclaw_mark_config_changed($conn, $actorUserId, 'channel_binding_created');
}

function bugcatcher_openclaw_delete_channel_binding(mysqli $conn, int $bindingId, ?int $actorUserId = null): void
{
    $stmt = $conn->prepare("DELETE FROM discord_channel_bindings WHERE id = ?");
    $stmt->bind_param('i', $bindingId);
    $stmt->execute();
    $stmt->close();
    bugcatcher_openclaw_mark_config_changed($conn, $actorUserId, 'channel_binding_deleted');
}

function bugcatcher_openclaw_effective_runtime_config(mysqli $conn): array
{
    $runtime = bugcatcher_openclaw_fetch_runtime_config($conn) ?: [];
    $providers = array_values(array_filter(
        bugcatcher_openclaw_fetch_providers($conn),
        static function (array $provider): bool {
            return (int) ($provider['is_enabled'] ?? 0) === 1;
        }
    ));
    $models = array_values(array_filter(
        bugcatcher_openclaw_fetch_models($conn),
        static function (array $model): bool {
            return (int) ($model['is_enabled'] ?? 0) === 1;
        }
    ));
    $channels = array_values(array_filter(
        bugcatcher_openclaw_fetch_channel_bindings($conn),
        static function (array $channel): bool {
            return (int) ($channel['is_enabled'] ?? 0) === 1;
        }
    ));
    $control = bugcatcher_openclaw_fetch_control_plane_state($conn);
    $pendingReload = bugcatcher_openclaw_fetch_pending_reload_request($conn);
    $runtimeStatus = bugcatcher_openclaw_fetch_runtime_status($conn);

    $serializedProviders = array_map(static function (array $provider): array {
        return [
            'id' => (int) $provider['id'],
            'provider_key' => (string) $provider['provider_key'],
            'display_name' => (string) $provider['display_name'],
            'provider_type' => (string) $provider['provider_type'],
            'base_url' => (string) ($provider['base_url'] ?? ''),
            'api_key' => bugcatcher_openclaw_decrypt_secret($provider['encrypted_api_key'] ?? ''),
            'is_enabled' => true,
            'supports_model_sync' => (bool) ($provider['supports_model_sync'] ?? false),
        ];
    }, $providers);

    $serializedModels = array_map(static function (array $model): array {
        return [
            'id' => (int) $model['id'],
            'provider_config_id' => (int) $model['provider_config_id'],
            'model_id' => (string) $model['model_id'],
            'display_name' => (string) $model['display_name'],
            'supports_vision' => (bool) ($model['supports_vision'] ?? false),
            'supports_json_output' => (bool) ($model['supports_json_output'] ?? false),
            'is_enabled' => true,
            'is_default' => (bool) ($model['is_default'] ?? false),
        ];
    }, $models);

    $serializedChannels = array_map(static function (array $channel): array {
        return [
            'id' => (int) $channel['id'],
            'guild_id' => (string) $channel['guild_id'],
            'guild_name' => (string) ($channel['guild_name'] ?? ''),
            'channel_id' => (string) $channel['channel_id'],
            'channel_name' => (string) ($channel['channel_name'] ?? ''),
            'is_enabled' => true,
            'allow_dm_followup' => (bool) ($channel['allow_dm_followup'] ?? false),
        ];
    }, $channels);

    return [
        'config_version' => (string) ($control['config_version'] ?? bugcatcher_openclaw_config_version_now()),
        'runtime' => [
            'is_enabled' => (bool) ($runtime['is_enabled'] ?? false),
            'default_provider_config_id' => isset($runtime['default_provider_config_id']) ? (int) $runtime['default_provider_config_id'] : null,
            'default_model_id' => isset($runtime['default_model_id']) ? (int) $runtime['default_model_id'] : null,
            'notes' => (string) ($runtime['notes'] ?? ''),
            'discord_bot_token' => bugcatcher_openclaw_decrypt_secret($runtime['encrypted_discord_bot_token'] ?? ''),
        ],
        'providers' => $serializedProviders,
        'models' => $serializedModels,
        'channels' => $serializedChannels,
        'pending_reload_request' => $pendingReload ? [
            'id' => (int) $pendingReload['id'],
            'reason' => (string) ($pendingReload['reason'] ?? ''),
            'status' => (string) ($pendingReload['status'] ?? 'pending'),
            'requested_at' => $pendingReload['requested_at'],
            'requested_by_user_id' => isset($pendingReload['requested_by_user_id']) ? (int) $pendingReload['requested_by_user_id'] : null,
            'requested_by_username' => (string) ($pendingReload['requested_by_username'] ?? ''),
        ] : null,
        'runtime_status' => [
            'config_version_applied' => $runtimeStatus['config_version_applied'] ?? null,
            'gateway_state' => $runtimeStatus['gateway_state'] ?? null,
            'discord_state' => $runtimeStatus['discord_state'] ?? null,
            'discord_application_id' => $runtimeStatus['discord_application_id'] ?? null,
            'last_heartbeat_at' => $runtimeStatus['last_heartbeat_at'] ?? null,
            'last_reload_at' => $runtimeStatus['last_reload_at'] ?? null,
        ],
    ];
}

function bugcatcher_openclaw_runtime_config_for_display(mysqli $conn): array
{
    $snapshot = bugcatcher_openclaw_effective_runtime_config($conn);
    $snapshot['runtime']['discord_bot_token'] = bugcatcher_openclaw_mask_plain_secret($snapshot['runtime']['discord_bot_token'] ?? '');

    foreach ($snapshot['providers'] as &$provider) {
        $provider['api_key'] = bugcatcher_openclaw_mask_plain_secret($provider['api_key'] ?? '');
    }
    unset($provider);

    return $snapshot;
}

function bugcatcher_openclaw_record_runtime_status(mysqli $conn, array $payload): array
{
    bugcatcher_openclaw_runtime_status_ensure($conn);

    $heartbeatAt = bugcatcher_openclaw_optional_datetime($payload['heartbeat_at'] ?? null) ?? date('Y-m-d H:i:s');
    $lastReloadAt = bugcatcher_openclaw_optional_datetime($payload['last_reload_at'] ?? null);
    $configVersionApplied = trim((string) ($payload['config_version_applied'] ?? ''));
    $gatewayState = substr(trim((string) ($payload['gateway_state'] ?? 'unknown')), 0, 30);
    $discordState = substr(trim((string) ($payload['discord_state'] ?? 'unknown')), 0, 30);
    $discordApplicationId = substr(trim((string) ($payload['discord_application_id'] ?? '')), 0, 64);
    $lastProviderError = trim((string) ($payload['last_provider_error'] ?? ''));
    $lastDiscordError = trim((string) ($payload['last_discord_error'] ?? ''));

    $stmt = $conn->prepare("
        UPDATE openclaw_runtime_status
        SET config_version_applied = NULLIF(?, ''),
            gateway_state = NULLIF(?, ''),
            discord_state = NULLIF(?, ''),
            discord_application_id = NULLIF(?, ''),
            last_heartbeat_at = ?,
            last_reload_at = NULLIF(?, ''),
            last_provider_error = NULLIF(?, ''),
            last_discord_error = NULLIF(?, ''),
            updated_at = NOW()
        WHERE id = 1
    ");
    $stmt->bind_param(
        'ssssssss',
        $configVersionApplied,
        $gatewayState,
        $discordState,
        $discordApplicationId,
        $heartbeatAt,
        $lastReloadAt,
        $lastProviderError,
        $lastDiscordError
    );
    $stmt->execute();
    $stmt->close();

    $reloadRequestId = isset($payload['reload_request_id']) ? (int) $payload['reload_request_id'] : 0;
    $reloadRequestStatus = trim((string) ($payload['reload_request_status'] ?? ''));
    if ($reloadRequestId > 0 && $reloadRequestStatus !== '') {
        bugcatcher_openclaw_update_reload_request_status(
            $conn,
            $reloadRequestId,
            $reloadRequestStatus,
            trim((string) ($payload['reload_request_error'] ?? '')) ?: null
        );
    }

    return bugcatcher_openclaw_fetch_runtime_status($conn);
}

function bugcatcher_openclaw_fetch_recent_requests(mysqli $conn, int $limit = 25): array
{
    $limit = max(1, min(100, $limit));
    $result = $conn->query("
        SELECT `or`.*,
               u.username AS requested_by_name,
               p.name AS project_name,
               o.name AS org_name,
               cb.title AS submitted_batch_title,
               dul.discord_username,
               dul.discord_global_name
        FROM openclaw_requests `or`
        LEFT JOIN users u ON u.id = `or`.requested_by_user_id
        LEFT JOIN projects p ON p.id = `or`.selected_project_id
        LEFT JOIN organizations o ON o.id = `or`.selected_org_id
        LEFT JOIN checklist_batches cb ON cb.id = `or`.submitted_batch_id
        LEFT JOIN discord_user_links dul ON dul.id = `or`.discord_user_link_id
        ORDER BY `or`.created_at DESC, `or`.id DESC
        LIMIT {$limit}
    ");
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function bugcatcher_openclaw_fetch_linked_users(mysqli $conn, int $limit = 100): array
{
    $limit = max(1, min(250, $limit));
    $result = $conn->query("
        SELECT dul.*, u.username, u.email
        FROM discord_user_links dul
        JOIN users u ON u.id = dul.user_id
        ORDER BY dul.linked_at DESC, dul.id DESC
        LIMIT {$limit}
    ");
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function bugcatcher_openclaw_fetch_batch_attachments(mysqli $conn, int $batchId): array
{
    $stmt = $conn->prepare("
        SELECT cba.*, u.username AS uploaded_by_name
        FROM checklist_batch_attachments cba
        LEFT JOIN users u ON u.id = cba.uploaded_by
        WHERE cba.checklist_batch_id = ?
        ORDER BY cba.created_at DESC, cba.id DESC
    ");
    $stmt->bind_param('i', $batchId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows ?: [];
}

function bugcatcher_openclaw_store_batch_attachment(
    mysqli $conn,
    int $batchId,
    string $tmpPath,
    string $originalName,
    int $size,
    ?int $uploadedBy,
    string $sourceType = 'bot'
): bool {
    $allowed = bugcatcher_checklist_allowed_mime_map();
    if ($size <= 0 || !is_file($tmpPath)) {
        return false;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $tmpPath);
    finfo_close($finfo);

    if (!isset($allowed[$mime])) {
        return false;
    }
    if (strpos($mime, 'image/') !== 0) {
        return false;
    }
    if ($size > $allowed[$mime]['max']) {
        return false;
    }

    $uploadDir = bugcatcher_checklist_ensure_upload_dir();
    $safeOrig = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
    $newName = "batch_{$batchId}_" . bin2hex(random_bytes(8)) . '.' . $allowed[$mime]['ext'];
    $destAbs = $uploadDir . DIRECTORY_SEPARATOR . $newName;
    $destRel = bugcatcher_checklist_upload_relative_path($newName);

    if (!bugcatcher_checklist_move_server_file($tmpPath, $destAbs)) {
        return false;
    }

    $stmt = $conn->prepare("
        INSERT INTO checklist_batch_attachments
            (checklist_batch_id, file_path, original_name, mime_type, file_size, uploaded_by, source_type)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param('isssiis', $batchId, $destRel, $safeOrig, $mime, $size, $uploadedBy, $sourceType);
    $stmt->execute();
    $stmt->close();

    return true;
}

function bugcatcher_openclaw_parse_source_reference(?string $value): array
{
    if (!is_string($value) || trim($value) === '') {
        return [];
    }

    $decoded = json_decode($value, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    return ['raw' => $value];
}

function bugcatcher_openclaw_normalize_duplicate_key(string $moduleName, string $submoduleName, string $title): string
{
    $parts = [
        strtolower(trim($moduleName)),
        strtolower(trim($submoduleName)),
        strtolower(trim($title)),
    ];
    $parts = array_map(static function ($value) {
        $value = preg_replace('/\s+/', ' ', $value);
        return preg_replace('/[^a-z0-9| ]+/', '', $value);
    }, $parts);

    return implode('|', $parts);
}

function bugcatcher_openclaw_find_duplicates(mysqli $conn, int $projectId, array $candidateItems): array
{
    $stmt = $conn->prepare("
        SELECT id, title, module_name, submodule_name, full_title, description
        FROM checklist_items
        WHERE project_id = ?
        ORDER BY id DESC
    ");
    $stmt->bind_param('i', $projectId);
    $stmt->execute();
    $existingRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $indexed = [];
    foreach ($existingRows as $row) {
        $key = bugcatcher_openclaw_normalize_duplicate_key(
            (string) ($row['module_name'] ?? ''),
            (string) ($row['submodule_name'] ?? ''),
            (string) ($row['title'] ?? '')
        );
        $indexed[$key][] = $row;
    }

    $results = [];
    foreach ($candidateItems as $index => $item) {
        $moduleName = trim((string) ($item['module_name'] ?? ''));
        $submoduleName = trim((string) ($item['submodule_name'] ?? ''));
        $title = trim((string) ($item['title'] ?? ''));
        $description = trim((string) ($item['description'] ?? ''));
        $key = bugcatcher_openclaw_normalize_duplicate_key($moduleName, $submoduleName, $title);
        $matches = $indexed[$key] ?? [];
        $status = 'unique';

        if ($matches) {
            $status = 'confirmed_duplicate';
        } else {
            foreach ($existingRows as $row) {
                $candidateText = strtolower($moduleName . ' ' . $submoduleName . ' ' . $title . ' ' . $description);
                $existingText = strtolower(
                    (string) ($row['module_name'] ?? '') . ' ' .
                    (string) ($row['submodule_name'] ?? '') . ' ' .
                    (string) ($row['title'] ?? '') . ' ' .
                    (string) ($row['description'] ?? '')
                );
                similar_text($candidateText, $existingText, $percent);
                if ($percent >= 74.0) {
                    $status = 'possible_duplicate';
                    $matches[] = $row;
                }
            }
        }

        $results[$index] = [
            'duplicate_status' => $status,
            'duplicate_summary' => $matches
                ? 'Potential overlap with existing project checklist items was found.'
                : 'No project-scoped duplicates were found.',
            'matches' => array_map(static function ($row) {
                return [
                    'id' => (int) $row['id'],
                    'title' => $row['title'],
                    'full_title' => $row['full_title'],
                ];
            }, array_slice($matches, 0, 10)),
        ];
    }

    return $results;
}

function bugcatcher_openclaw_create_batch_from_payload(mysqli $conn, array $payload): array
{
    $orgId = ctype_digit((string) ($payload['org_id'] ?? '')) ? (int) $payload['org_id'] : 0;
    $projectId = ctype_digit((string) ($payload['project_id'] ?? '')) ? (int) $payload['project_id'] : 0;
    $requestedByUserId = ctype_digit((string) ($payload['requested_by_user_id'] ?? '')) ? (int) $payload['requested_by_user_id'] : 0;
    $discordUserId = trim((string) ($payload['discord_user_id'] ?? ''));
    $requestId = ctype_digit((string) ($payload['openclaw_request_id'] ?? '')) ? (int) $payload['openclaw_request_id'] : 0;
    $batchPayload = is_array($payload['batch'] ?? null) ? $payload['batch'] : [];
    $itemsPayload = is_array($payload['items'] ?? null) ? $payload['items'] : [];
    $attachmentsPayload = is_array($payload['batch_attachments'] ?? null) ? $payload['batch_attachments'] : [];

    if ($orgId <= 0 || $projectId <= 0 || $requestedByUserId <= 0 || $discordUserId === '' || !$batchPayload || !$itemsPayload) {
        throw new RuntimeException('org_id, project_id, requested_by_user_id, discord_user_id, batch, and items are required.');
    }
    if (count($itemsPayload) > 200) {
        throw new RuntimeException('Max batch size is 200 items.');
    }
    if (count($attachmentsPayload) < 1) {
        throw new RuntimeException('At least one image attachment is required.');
    }

    $link = bugcatcher_openclaw_fetch_user_link_by_discord_user($conn, $discordUserId);
    if (!$link || (int) $link['user_id'] !== $requestedByUserId) {
        throw new RuntimeException('The Discord user is not linked to the requested BugCatcher account.');
    }

    $project = bugcatcher_checklist_fetch_project($conn, $orgId, $projectId);
    if (!$project) {
        throw new RuntimeException('Project not found in the provided organization.');
    }
    if (bugcatcher_checklist_fetch_member_role($conn, $orgId, $requestedByUserId) === null) {
        throw new RuntimeException('Requested user is not a member of the selected organization.');
    }

    $title = trim((string) ($batchPayload['title'] ?? ''));
    $moduleName = trim((string) ($batchPayload['module_name'] ?? ''));
    $submoduleName = trim((string) ($batchPayload['submodule_name'] ?? ''));
    $notes = trim((string) ($batchPayload['notes'] ?? ''));
    $assignedQaLeadId = ctype_digit((string) ($batchPayload['assigned_qa_lead_id'] ?? '')) ? (int) $batchPayload['assigned_qa_lead_id'] : 0;
    $sourceReferenceValue = $batchPayload['source_reference'] ?? '';
    $sourceReference = is_array($sourceReferenceValue)
        ? json_encode($sourceReferenceValue)
        : trim((string) $sourceReferenceValue);

    if ($title === '' || $moduleName === '') {
        throw new RuntimeException('Batch title and module_name are required.');
    }
    if ($assignedQaLeadId > 0 && !bugcatcher_checklist_member_has_role($conn, $orgId, $assignedQaLeadId, ['QA Lead'])) {
        throw new RuntimeException('assigned_qa_lead_id must belong to a QA Lead in this organization.');
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $itemIds = [];

    try {
        $conn->begin_transaction();

        $stmt = $conn->prepare("
            INSERT INTO checklist_batches
                (org_id, project_id, title, module_name, submodule_name, source_type, source_channel, source_reference,
                 status, created_by, updated_by, assigned_qa_lead_id, notes)
            VALUES (?, ?, ?, ?, NULLIF(?, ''), 'bot', 'discord', NULLIF(?, ''), 'open', ?, ?, NULLIF(?, 0), NULLIF(?, ''))
        ");
        $stmt->bind_param(
            'iissssiiis',
            $orgId,
            $projectId,
            $title,
            $moduleName,
            $submoduleName,
            $sourceReference,
            $requestedByUserId,
            $requestedByUserId,
            $assignedQaLeadId,
            $notes
        );
        $stmt->execute();
        $batchId = (int) $conn->insert_id;
        $stmt->close();

        foreach ($itemsPayload as $index => $itemPayload) {
            if (!is_array($itemPayload)) {
                throw new RuntimeException('Each item must be an object.');
            }
            if (array_key_exists('final_include', $itemPayload) && !(bool) $itemPayload['final_include']) {
                continue;
            }

            $sequenceNo = ctype_digit((string) ($itemPayload['sequence_no'] ?? '')) ? (int) $itemPayload['sequence_no'] : ($index + 1);
            $itemTitle = trim((string) ($itemPayload['title'] ?? ''));
            $itemModuleName = trim((string) ($itemPayload['module_name'] ?? $moduleName));
            $itemSubmodule = trim((string) ($itemPayload['submodule_name'] ?? $submoduleName));
            $description = trim((string) ($itemPayload['description'] ?? ''));
            $requiredRole = bugcatcher_checklist_normalize_enum((string) ($itemPayload['required_role'] ?? 'QA Tester'), BUGCATCHER_CHECKLIST_ALLOWED_REQUIRED_ROLES, 'QA Tester');
            $priority = bugcatcher_checklist_normalize_enum((string) ($itemPayload['priority'] ?? 'medium'), BUGCATCHER_CHECKLIST_PRIORITIES, 'medium');
            $assignedToUserId = ctype_digit((string) ($itemPayload['assigned_to_user_id'] ?? '')) ? (int) $itemPayload['assigned_to_user_id'] : 0;

            if ($itemTitle === '' || $itemModuleName === '') {
                throw new RuntimeException('Each item requires title and module_name.');
            }
            if ($assignedToUserId > 0 && bugcatcher_checklist_fetch_member_role($conn, $orgId, $assignedToUserId) === null) {
                throw new RuntimeException("assigned_to_user_id {$assignedToUserId} is not a member of org {$orgId}.");
            }

            $fullTitle = bugcatcher_checklist_full_title($itemModuleName, $itemSubmodule, $itemTitle);
            $stmt = $conn->prepare("
                INSERT INTO checklist_items
                    (batch_id, org_id, project_id, sequence_no, title, module_name, submodule_name, full_title, description,
                     status, priority, required_role, assigned_to_user_id, created_by, updated_by)
                VALUES (?, ?, ?, ?, ?, ?, NULLIF(?, ''), ?, NULLIF(?, ''), 'open', ?, ?, NULLIF(?, 0), ?, ?)
            ");
            $stmt->bind_param(
                'iiiisssssssiii',
                $batchId,
                $orgId,
                $projectId,
                $sequenceNo,
                $itemTitle,
                $itemModuleName,
                $itemSubmodule,
                $fullTitle,
                $description,
                $priority,
                $requiredRole,
                $assignedToUserId,
                $requestedByUserId,
                $requestedByUserId
            );
            $stmt->execute();
            $itemIds[] = (int) $conn->insert_id;
            $stmt->close();
        }

        if (!$itemIds) {
            throw new RuntimeException('No checklist items remained for submission.');
        }

        foreach ($attachmentsPayload as $attachment) {
            if (!is_array($attachment)) {
                throw new RuntimeException('Each batch attachment must be an object.');
            }
            $token = trim((string) ($attachment['temp_file_token'] ?? ''));
            $originalName = trim((string) ($attachment['original_name'] ?? 'attachment'));
            $path = bugcatcher_openclaw_resolve_temp_file($token);
            if (!$path) {
                throw new RuntimeException("Attachment token {$token} was not found on the server.");
            }
            $size = (int) filesize($path);
            if (!bugcatcher_openclaw_store_batch_attachment($conn, $batchId, $path, $originalName, $size, $requestedByUserId, 'bot')) {
                throw new RuntimeException("Attachment {$originalName} could not be stored.");
            }
        }

        if ($requestId > 0) {
            $stmt = $conn->prepare("
                UPDATE openclaw_requests
                SET status = 'submitted',
                    current_step = 'submitted',
                    submitted_batch_id = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->bind_param('ii', $batchId, $requestId);
            $stmt->execute();
            $stmt->close();
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    return [
        'batch_id' => $batchId,
        'item_ids' => $itemIds,
        'item_count' => count($itemIds),
    ];
}

function bugcatcher_openclaw_health_snapshot(mysqli $conn): array
{
    $runtime = bugcatcher_openclaw_fetch_runtime_config($conn);
    $providers = bugcatcher_openclaw_fetch_providers($conn);
    $channels = bugcatcher_openclaw_fetch_channel_bindings($conn);
    $requests = bugcatcher_openclaw_fetch_recent_requests($conn, 1);
    $control = bugcatcher_openclaw_fetch_control_plane_state($conn);
    $status = bugcatcher_openclaw_fetch_runtime_status($conn);
    $pendingReload = bugcatcher_openclaw_fetch_pending_reload_request($conn);

    return [
        'runtime_configured' => $runtime !== null,
        'runtime_enabled' => (bool) ($runtime['is_enabled'] ?? false),
        'provider_count' => count($providers),
        'enabled_provider_count' => count(array_filter($providers, static function ($provider) {
            return (int) $provider['is_enabled'] === 1;
        })),
        'channel_count' => count($channels),
        'enabled_channel_count' => count(array_filter($channels, static function ($channel) {
            return (int) $channel['is_enabled'] === 1;
        })),
        'last_successful_request_at' => $requests ? ($requests[0]['updated_at'] ?: $requests[0]['created_at']) : null,
        'last_request_status' => $requests[0]['status'] ?? null,
        'config_version' => $control['config_version'] ?? null,
        'config_version_applied' => $status['config_version_applied'] ?? null,
        'gateway_state' => $status['gateway_state'] ?? null,
        'discord_state' => $status['discord_state'] ?? null,
        'discord_application_id' => $status['discord_application_id'] ?? null,
        'last_heartbeat_at' => $status['last_heartbeat_at'] ?? null,
        'last_reload_at' => $status['last_reload_at'] ?? null,
        'last_provider_error' => $status['last_provider_error'] ?? null,
        'last_discord_error' => $status['last_discord_error'] ?? null,
        'pending_reload_request_id' => $pendingReload['id'] ?? null,
    ];
}

function bugcatcher_openclaw_docs_files(): array
{
    $docs = [];
    foreach (BUGCATCHER_OPENCLAW_DOC_ORDER as $file) {
        $path = BUGCATCHER_OPENCLAW_DOCS_DIR . DIRECTORY_SEPARATOR . $file;
        if (is_file($path)) {
            $docs[$file] = $path;
        }
    }
    return $docs;
}

function bugcatcher_openclaw_doc_title(string $fileName): string
{
    $titles = [
        'README.md' => 'Overview',
        'architecture.md' => 'Architecture',
        'self-setup-runbook.md' => 'Self Setup Runbook',
        'discord-setup.md' => 'Discord Setup',
        'provider-setup.md' => 'Provider Setup',
        'server-setup.md' => 'Server Setup',
        'super-admin-account.md' => 'Super Admin Account',
        'user-guide.md' => 'User Guide',
        'admin-guide.md' => 'Admin Guide',
        'api.md' => 'API',
        'implementation-handoff.md' => 'Implementation Handoff',
    ];
    return $titles[$fileName] ?? ucwords(str_replace(['-', '.md'], [' ', ''], strtolower($fileName)));
}

function bugcatcher_openclaw_render_markdown(string $markdown): string
{
    $lines = preg_split("/\\r\\n|\\r|\\n/", $markdown);
    $html = [];
    $inList = false;
    $inCode = false;
    $codeBuffer = [];

    $flushList = static function () use (&$html, &$inList): void {
        if ($inList) {
            $html[] = '</ul>';
            $inList = false;
        }
    };

    foreach ($lines as $line) {
        if (preg_match('/^```/', $line)) {
            $flushList();
            if ($inCode) {
                $html[] = '<pre><code>' . htmlspecialchars(implode("\n", $codeBuffer), ENT_QUOTES, 'UTF-8') . '</code></pre>';
                $codeBuffer = [];
                $inCode = false;
            } else {
                $inCode = true;
            }
            continue;
        }

        if ($inCode) {
            $codeBuffer[] = $line;
            continue;
        }

        if (preg_match('/^\s*[-*]\s+(.+)$/', $line, $matches)) {
            if (!$inList) {
                $html[] = '<ul>';
                $inList = true;
            }
            $html[] = '<li>' . bugcatcher_openclaw_render_markdown_inline($matches[1]) . '</li>';
            continue;
        }

        $flushList();

        if (preg_match('/^(#{1,4})\s+(.+)$/', $line, $matches)) {
            $level = strlen($matches[1]);
            $html[] = sprintf('<h%d>%s</h%d>', $level, bugcatcher_openclaw_render_markdown_inline($matches[2]), $level);
            continue;
        }

        if (trim($line) === '') {
            continue;
        }

        $html[] = '<p>' . bugcatcher_openclaw_render_markdown_inline($line) . '</p>';
    }

    if ($inList) {
        $html[] = '</ul>';
    }
    if ($inCode) {
        $html[] = '<pre><code>' . htmlspecialchars(implode("\n", $codeBuffer), ENT_QUOTES, 'UTF-8') . '</code></pre>';
    }

    return implode("\n", $html);
}

function bugcatcher_openclaw_render_markdown_inline(string $text): string
{
    $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $escaped = preg_replace('/`([^`]+)`/', '<code>$1</code>', $escaped);
    $escaped = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $escaped);
    $escaped = preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $escaped);
    $escaped = preg_replace('/\[(.+?)\]\((.+?)\)/', '<a href="$2" target="_blank" rel="noopener">$1</a>', $escaped);
    return $escaped;
}
