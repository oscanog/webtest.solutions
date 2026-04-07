<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/checklist_lib.php';

function webtest_require_super_admin(string $role): void
{
    if (!webtest_is_super_admin_role($role)) {
        http_response_code(403);
        die('Only super admins can access this area.');
    }
}

function webtest_openclaw_json_response(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

function webtest_openclaw_json_request_body(): array
{
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw ?: '', true);
    return is_array($decoded) ? $decoded : [];
}

function webtest_openclaw_authorization_header(): string
{
    return (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
}

function webtest_openclaw_require_internal_request(): void
{
    $expected = webtest_config('OPENCLAW_INTERNAL_SHARED_SECRET', '');
    $header = webtest_openclaw_authorization_header();
    $token = '';
    if (stripos($header, 'Bearer ') === 0) {
        $token = trim(substr($header, 7));
    }

    if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
        webtest_openclaw_json_response(401, ['error' => 'Invalid or missing OpenClaw bearer token.']);
    }
}

function webtest_openclaw_encryption_key(): string
{
    $key = (string) webtest_config('OPENCLAW_ENCRYPTION_KEY', '');
    if ($key === '') {
        throw new RuntimeException('OPENCLAW_ENCRYPTION_KEY is not configured.');
    }

    return hash('sha256', $key, true);
}

function webtest_openclaw_encrypt_secret(string $value): string
{
    if ($value === '') {
        return '';
    }

    $iv = random_bytes(16);
    $ciphertext = openssl_encrypt($value, 'AES-256-CBC', webtest_openclaw_encryption_key(), OPENSSL_RAW_DATA, $iv);
    if ($ciphertext === false) {
        throw new RuntimeException('Failed to encrypt secret.');
    }

    return base64_encode($iv . $ciphertext);
}

function webtest_openclaw_decrypt_secret(?string $value): string
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
    $plaintext = openssl_decrypt($ciphertext, 'AES-256-CBC', webtest_openclaw_encryption_key(), OPENSSL_RAW_DATA, $iv);

    return $plaintext === false ? '' : $plaintext;
}

function webtest_openclaw_mask_secret(?string $value): string
{
    try {
        $value = webtest_openclaw_decrypt_secret($value);
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

function webtest_openclaw_mask_plain_secret(?string $value): string
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

function webtest_openclaw_config_version_now(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

function webtest_openclaw_control_plane_ensure(mysqli $conn): void
{
    $version = webtest_openclaw_config_version_now();
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

function webtest_openclaw_queue_reload_request(mysqli $conn, ?int $actorUserId, string $reason): int
{
    webtest_openclaw_control_plane_ensure($conn);
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

function webtest_openclaw_mark_config_changed(mysqli $conn, ?int $actorUserId, string $reason): int
{
    webtest_openclaw_control_plane_ensure($conn);
    $version = webtest_openclaw_config_version_now();
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

    return webtest_openclaw_queue_reload_request($conn, $actorUserId, $reason);
}

function webtest_openclaw_temp_dir_ensure(): string
{
    $dir = webtest_openclaw_temp_dir();
    if ($dir === '') {
        throw new RuntimeException('OPENCLAW_TEMP_UPLOAD_DIR is not configured.');
    }
    if (!is_dir($dir)) {
        mkdir($dir, 02775, true);
    }
    return $dir;
}

function webtest_openclaw_resolve_temp_file(string $token): ?string
{
    $token = preg_replace('/[^a-zA-Z0-9._-]/', '', $token);
    if ($token === '') {
        return null;
    }

    $candidates = [
        webtest_openclaw_temp_dir() . DIRECTORY_SEPARATOR . $token,
        webtest_checklist_uploads_dir() . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . $token,
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function webtest_openclaw_fetch_runtime_config(mysqli $conn): ?array
{
    webtest_openclaw_runtime_config_ensure_schema($conn);
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

function webtest_openclaw_validate_provider_input(
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

function webtest_openclaw_save_runtime_config(
    mysqli $conn,
    int $actorUserId,
    bool $isEnabled,
    int $defaultProviderId,
    int $defaultModelId,
    string $notes,
    bool $aiChatEnabled = true,
    int $aiChatDefaultProviderId = 0,
    int $aiChatDefaultModelId = 0,
    string $aiChatAssistantName = '',
    string $aiChatSystemPrompt = ''
): void {
    webtest_openclaw_runtime_config_ensure_schema($conn);
    $existing = webtest_openclaw_fetch_runtime_config($conn);

    if ($existing) {
        $stmt = $conn->prepare("
            UPDATE openclaw_runtime_config
            SET is_enabled = ?,
                default_provider_config_id = NULLIF(?, 0),
                default_model_id = NULLIF(?, 0),
                notes = NULLIF(?, ''),
                updated_by = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $runtimeId = (int) $existing['id'];
        $enabled = $isEnabled ? 1 : 0;
        $stmt->bind_param('iiisii', $enabled, $defaultProviderId, $defaultModelId, $notes, $actorUserId, $runtimeId);
        $stmt->execute();
        $stmt->close();
        webtest_openclaw_mark_config_changed($conn, $actorUserId, 'runtime_config_saved');
        return;
    }

    $stmt = $conn->prepare("
        INSERT INTO openclaw_runtime_config
            (
                is_enabled,
                default_provider_config_id,
                default_model_id,
                notes,
                created_by,
                updated_by,
                updated_at
            )
        VALUES (?, NULLIF(?, 0), NULLIF(?, 0), NULLIF(?, ''), ?, ?, NOW())
    ");
    $enabled = $isEnabled ? 1 : 0;
    $stmt->bind_param('iiisii', $enabled, $defaultProviderId, $defaultModelId, $notes, $actorUserId, $actorUserId);
    $stmt->execute();
    $stmt->close();
    webtest_openclaw_mark_config_changed($conn, $actorUserId, 'runtime_config_created');
}

function webtest_openclaw_fetch_providers(mysqli $conn): array
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

function webtest_openclaw_save_provider(
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
    webtest_openclaw_validate_provider_input($conn, $providerId, $providerKey, $displayName, $providerType, $baseUrl);

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
        $encryptedKey = webtest_openclaw_encrypt_secret($apiKey);
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
        webtest_openclaw_mark_config_changed($conn, $actorUserId, 'provider_saved');
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
    webtest_openclaw_mark_config_changed($conn, $actorUserId, 'provider_created');
}

function webtest_openclaw_delete_provider(mysqli $conn, int $providerId, ?int $actorUserId = null): void
{
    $stmt = $conn->prepare("DELETE FROM ai_provider_configs WHERE id = ?");
    $stmt->bind_param('i', $providerId);
    $stmt->execute();
    $stmt->close();
    webtest_openclaw_mark_config_changed($conn, $actorUserId, 'provider_deleted');
}

function webtest_openclaw_fetch_models(mysqli $conn): array
{
    $result = $conn->query("
        SELECT am.*, apc.display_name AS provider_name
        FROM ai_models am
        JOIN ai_provider_configs apc ON apc.id = am.provider_config_id
        ORDER BY apc.display_name ASC, am.display_name ASC
    ");
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function webtest_openclaw_save_model(
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
        webtest_openclaw_mark_config_changed($conn, $actorUserId, 'model_saved');
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
    webtest_openclaw_mark_config_changed($conn, $actorUserId, 'model_created');
}

function webtest_openclaw_delete_model(mysqli $conn, int $modelId, ?int $actorUserId = null): void
{
    $stmt = $conn->prepare("DELETE FROM ai_models WHERE id = ?");
    $stmt->bind_param('i', $modelId);
    $stmt->execute();
    $stmt->close();
    webtest_openclaw_mark_config_changed($conn, $actorUserId, 'model_deleted');
}

function webtest_openclaw_runtime_config_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $done = true;
}

function webtest_openclaw_find_provider_by_key(mysqli $conn, string $providerKey): ?array
{
    $stmt = $conn->prepare("SELECT * FROM ai_provider_configs WHERE provider_key = ? LIMIT 1");
    $stmt->bind_param('s', $providerKey);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function webtest_openclaw_find_model_by_provider_and_remote_id(mysqli $conn, int $providerId, string $remoteModelId): ?array
{
    $stmt = $conn->prepare("SELECT * FROM ai_models WHERE provider_config_id = ? AND model_id = ? LIMIT 1");
    $stmt->bind_param('is', $providerId, $remoteModelId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function webtest_openclaw_fetch_batch_attachments(mysqli $conn, int $batchId): array
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

function webtest_openclaw_store_batch_attachment(
    mysqli $conn,
    int $batchId,
    string $tmpPath,
    string $originalName,
    int $size,
    ?int $uploadedBy,
    string $sourceType = 'bot'
): bool {
    webtest_file_storage_ensure_schema($conn);
    $allowed = webtest_checklist_allowed_mime_map();
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

    $safeOrig = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
    try {
        $stored = webtest_file_storage_upload_file($tmpPath, $safeOrig, $mime, $size, 'openclaw-batch');
    } catch (Throwable $e) {
        return false;
    }
    $filePath = (string) $stored['file_path'];
    $storageKey = (string) ($stored['storage_key'] ?? '');
    $storageProvider = (string) ($stored['storage_provider'] ?? '');
    $storedName = (string) ($stored['original_name'] ?? $safeOrig);
    $storedMime = (string) ($stored['mime_type'] ?? $mime);
    $storedSize = (int) ($stored['file_size'] ?? $size);

    $stmt = $conn->prepare("
        INSERT INTO checklist_batch_attachments
            (checklist_batch_id, file_path, storage_key, storage_provider, original_name, mime_type, file_size, uploaded_by, source_type)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param('isssssiis', $batchId, $filePath, $storageKey, $storageProvider, $storedName, $storedMime, $storedSize, $uploadedBy, $sourceType);
    $stmt->execute();
    $stmt->close();

    return true;
}

function webtest_openclaw_parse_source_reference(?string $value): array
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

function webtest_openclaw_normalize_duplicate_key(string $moduleName, string $submoduleName, string $title): string
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

function webtest_openclaw_find_duplicates(mysqli $conn, int $projectId, array $candidateItems): array
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
        $key = webtest_openclaw_normalize_duplicate_key(
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
        $key = webtest_openclaw_normalize_duplicate_key($moduleName, $submoduleName, $title);
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

function webtest_openclaw_create_batch_from_payload(mysqli $conn, array $payload): array
{
    $orgId = ctype_digit((string) ($payload['org_id'] ?? '')) ? (int) $payload['org_id'] : 0;
    $projectId = ctype_digit((string) ($payload['project_id'] ?? '')) ? (int) $payload['project_id'] : 0;
    $requestedByUserId = ctype_digit((string) ($payload['requested_by_user_id'] ?? '')) ? (int) $payload['requested_by_user_id'] : 0;
    $requestId = ctype_digit((string) ($payload['openclaw_request_id'] ?? '')) ? (int) $payload['openclaw_request_id'] : 0;
    $batchPayload = is_array($payload['batch'] ?? null) ? $payload['batch'] : [];
    $itemsPayload = is_array($payload['items'] ?? null) ? $payload['items'] : [];
    $attachmentsPayload = is_array($payload['batch_attachments'] ?? null) ? $payload['batch_attachments'] : [];

    if ($orgId <= 0 || $projectId <= 0 || $requestedByUserId <= 0 || !$batchPayload || !$itemsPayload) {
        throw new RuntimeException('org_id, project_id, requested_by_user_id, batch, and items are required.');
    }
    if (count($itemsPayload) > 200) {
        throw new RuntimeException('Max batch size is 200 items.');
    }
    if (count($attachmentsPayload) < 1) {
        throw new RuntimeException('At least one image attachment is required.');
    }

    $project = webtest_checklist_fetch_project($conn, $orgId, $projectId);
    if (!$project) {
        throw new RuntimeException('Project not found in the provided organization.');
    }
    if (webtest_checklist_fetch_member_role($conn, $orgId, $requestedByUserId) === null) {
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
    if ($assignedQaLeadId > 0 && !webtest_checklist_member_has_role($conn, $orgId, $assignedQaLeadId, ['QA Lead'])) {
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
            VALUES (?, ?, ?, ?, NULLIF(?, ''), 'bot', 'api', NULLIF(?, ''), 'open', ?, ?, NULLIF(?, 0), NULLIF(?, ''))
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
            $requiredRole = webtest_checklist_normalize_enum((string) ($itemPayload['required_role'] ?? 'QA Tester'), WEBTEST_CHECKLIST_ALLOWED_REQUIRED_ROLES, 'QA Tester');
            $priority = webtest_checklist_normalize_enum((string) ($itemPayload['priority'] ?? 'medium'), WEBTEST_CHECKLIST_PRIORITIES, 'medium');
            $assignedToUserId = ctype_digit((string) ($itemPayload['assigned_to_user_id'] ?? '')) ? (int) $itemPayload['assigned_to_user_id'] : 0;

            if ($itemTitle === '' || $itemModuleName === '') {
                throw new RuntimeException('Each item requires title and module_name.');
            }
            if ($assignedToUserId > 0 && webtest_checklist_fetch_member_role($conn, $orgId, $assignedToUserId) === null) {
                throw new RuntimeException("assigned_to_user_id {$assignedToUserId} is not a member of org {$orgId}.");
            }

            $fullTitle = webtest_checklist_full_title($itemModuleName, $itemSubmodule, $itemTitle);
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
            $path = webtest_openclaw_resolve_temp_file($token);
            if (!$path) {
                throw new RuntimeException("Attachment token {$token} was not found on the server.");
            }
            $size = (int) filesize($path);
            if (!webtest_openclaw_store_batch_attachment($conn, $batchId, $path, $originalName, $size, $requestedByUserId, 'bot')) {
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
