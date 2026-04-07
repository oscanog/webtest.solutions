<?php

require_once __DIR__ . '/bootstrap.php';

function webtest_ai_admin_persona_definitions(): array
{
    $assistantName = trim((string) webtest_config('AI_CHAT_DEFAULT_ASSISTANT_NAME', 'WebTest AI'));
    $systemPrompt = trim((string) webtest_config('AI_CHAT_DEFAULT_SYSTEM_PROMPT', ''));

    return [
        'checklist_generator' => [
            'persona_key' => 'checklist_generator',
            'display_name' => 'Checklist Generator',
            'assistant_name' => $assistantName,
            'system_prompt' => $systemPrompt !== ''
                ? $systemPrompt
                : 'You draft practical QA checklist items from the provided product context.',
            'is_enabled' => true,
        ],
        'checklist_reviewer' => [
            'persona_key' => 'checklist_reviewer',
            'display_name' => 'Checklist Reviewer',
            'assistant_name' => trim($assistantName . ' Reviewer'),
            'system_prompt' => 'You review AI-generated checklist drafts, remove duplication, fix weak coverage, and improve clarity for manual QA execution.',
            'is_enabled' => true,
        ],
    ];
}

function webtest_ai_admin_to_bool($value, bool $default = false): bool
{
    if ($value === null) {
        return $default;
    }
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

function webtest_ai_admin_persona_label(string $personaKey): string
{
    $definitions = webtest_ai_admin_persona_definitions();
    $definition = $definitions[$personaKey] ?? null;
    if (is_array($definition) && trim((string) ($definition['display_name'] ?? '')) !== '') {
        return (string) $definition['display_name'];
    }

    return ucwords(str_replace('_', ' ', $personaKey));
}

function webtest_ai_admin_runtime_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS ai_runtime_config (
            id INT(11) NOT NULL AUTO_INCREMENT,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            default_provider_config_id INT(11) DEFAULT NULL,
            default_model_id INT(11) DEFAULT NULL,
            assistant_name VARCHAR(120) DEFAULT NULL,
            system_prompt TEXT DEFAULT NULL,
            created_by INT(11) NOT NULL,
            updated_by INT(11) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_ai_runtime_config_created_by (created_by),
            KEY idx_ai_runtime_config_updated_by (updated_by),
            KEY idx_ai_runtime_config_provider (default_provider_config_id),
            KEY idx_ai_runtime_config_model (default_model_id),
            CONSTRAINT fk_ai_runtime_config_created_by
                FOREIGN KEY (created_by) REFERENCES users(id),
            CONSTRAINT fk_ai_runtime_config_updated_by
                FOREIGN KEY (updated_by) REFERENCES users(id)
                ON DELETE SET NULL,
            CONSTRAINT fk_ai_runtime_config_provider
                FOREIGN KEY (default_provider_config_id) REFERENCES ai_provider_configs(id)
                ON DELETE SET NULL,
            CONSTRAINT fk_ai_runtime_config_model
                FOREIGN KEY (default_model_id) REFERENCES ai_models(id)
                ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS ai_runtime_personas (
            id INT(11) NOT NULL AUTO_INCREMENT,
            persona_key VARCHAR(60) NOT NULL,
            display_name VARCHAR(120) NOT NULL,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            provider_config_id INT(11) DEFAULT NULL,
            model_id INT(11) DEFAULT NULL,
            assistant_name VARCHAR(120) DEFAULT NULL,
            system_prompt TEXT DEFAULT NULL,
            created_by INT(11) NOT NULL,
            updated_by INT(11) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_ai_runtime_personas_key (persona_key),
            KEY idx_ai_runtime_personas_provider (provider_config_id),
            KEY idx_ai_runtime_personas_model (model_id),
            KEY idx_ai_runtime_personas_created_by (created_by),
            KEY idx_ai_runtime_personas_updated_by (updated_by),
            CONSTRAINT fk_ai_runtime_personas_provider
                FOREIGN KEY (provider_config_id) REFERENCES ai_provider_configs(id)
                ON DELETE SET NULL,
            CONSTRAINT fk_ai_runtime_personas_model
                FOREIGN KEY (model_id) REFERENCES ai_models(id)
                ON DELETE SET NULL,
            CONSTRAINT fk_ai_runtime_personas_created_by
                FOREIGN KEY (created_by) REFERENCES users(id),
            CONSTRAINT fk_ai_runtime_personas_updated_by
                FOREIGN KEY (updated_by) REFERENCES users(id)
                ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS ai_chat_draft_persona_runs (
            id INT(11) NOT NULL AUTO_INCREMENT,
            thread_id INT(11) NOT NULL,
            source_user_message_id INT(11) DEFAULT NULL,
            assistant_message_id INT(11) DEFAULT NULL,
            persona_key VARCHAR(60) NOT NULL,
            phase ENUM('generator', 'reviewer') NOT NULL,
            source_mode ENUM('screenshot', 'link') NOT NULL DEFAULT 'screenshot',
            provider_config_id INT(11) DEFAULT NULL,
            model_id INT(11) DEFAULT NULL,
            status ENUM('completed', 'failed', 'skipped') NOT NULL DEFAULT 'completed',
            raw_output LONGTEXT DEFAULT NULL,
            normalized_output LONGTEXT DEFAULT NULL,
            error_message TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_ai_chat_draft_persona_runs_thread (thread_id, id),
            KEY idx_ai_chat_draft_persona_runs_persona (persona_key, phase),
            KEY idx_ai_chat_draft_persona_runs_message (assistant_message_id),
            CONSTRAINT fk_ai_chat_draft_persona_runs_provider
                FOREIGN KEY (provider_config_id) REFERENCES ai_provider_configs(id)
                ON DELETE SET NULL,
            CONSTRAINT fk_ai_chat_draft_persona_runs_model
                FOREIGN KEY (model_id) REFERENCES ai_models(id)
                ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    webtest_ai_admin_backfill_runtime_from_openclaw($conn);

    $done = true;
}

function webtest_ai_admin_backfill_runtime_from_openclaw(mysqli $conn): void
{
    if (!webtest_db_has_table($conn, 'openclaw_runtime_config')) {
        return;
    }

    $result = $conn->query("SELECT id FROM ai_runtime_config ORDER BY id DESC LIMIT 1");
    $existing = $result ? $result->fetch_assoc() : null;
    if ($existing) {
        return;
    }

    $requiredColumns = [
        'ai_chat_enabled',
        'ai_chat_default_provider_config_id',
        'ai_chat_default_model_id',
        'ai_chat_assistant_name',
        'ai_chat_system_prompt',
    ];

    foreach ($requiredColumns as $column) {
        if (!webtest_db_has_column($conn, 'openclaw_runtime_config', $column)) {
            return;
        }
    }

    $sourceResult = $conn->query("
        SELECT ai_chat_enabled,
               ai_chat_default_provider_config_id,
               ai_chat_default_model_id,
               ai_chat_assistant_name,
               ai_chat_system_prompt,
               created_by,
               updated_by,
               created_at,
               updated_at
        FROM openclaw_runtime_config
        ORDER BY id DESC
        LIMIT 1
    ");
    $source = $sourceResult ? $sourceResult->fetch_assoc() : null;
    if (!$source) {
        return;
    }

    $stmt = $conn->prepare("
        INSERT INTO ai_runtime_config
            (
                is_enabled,
                default_provider_config_id,
                default_model_id,
                assistant_name,
                system_prompt,
                created_by,
                updated_by,
                created_at,
                updated_at
            )
        VALUES (?, NULLIF(?, 0), NULLIF(?, 0), NULLIF(?, ''), NULLIF(?, ''), ?, NULLIF(?, 0), ?, ?)
    ");
    $enabled = (int) (!empty($source['ai_chat_enabled']) ? 1 : 0);
    $providerId = (int) ($source['ai_chat_default_provider_config_id'] ?? 0);
    $modelId = (int) ($source['ai_chat_default_model_id'] ?? 0);
    $assistantName = trim((string) ($source['ai_chat_assistant_name'] ?? ''));
    $systemPrompt = trim((string) ($source['ai_chat_system_prompt'] ?? ''));
    $createdBy = max(1, (int) ($source['created_by'] ?? 1));
    $updatedBy = max(0, (int) ($source['updated_by'] ?? 0));
    $createdAt = (string) ($source['created_at'] ?? date('Y-m-d H:i:s'));
    $updatedAt = (string) ($source['updated_at'] ?? $createdAt);
    $stmt->bind_param(
        'iiissiiss',
        $enabled,
        $providerId,
        $modelId,
        $assistantName,
        $systemPrompt,
        $createdBy,
        $updatedBy,
        $createdAt,
        $updatedAt
    );
    $stmt->execute();
    $stmt->close();
}

function webtest_ai_admin_fetch_runtime_config(mysqli $conn): ?array
{
    webtest_ai_admin_runtime_ensure_schema($conn);

    $result = $conn->query("
        SELECT arc.*,
               creator.username AS created_by_name,
               updater.username AS updated_by_name,
               provider.display_name AS default_provider_name,
               model.display_name AS default_model_name
        FROM ai_runtime_config arc
        LEFT JOIN users creator ON creator.id = arc.created_by
        LEFT JOIN users updater ON updater.id = arc.updated_by
        LEFT JOIN ai_provider_configs provider ON provider.id = arc.default_provider_config_id
        LEFT JOIN ai_models model ON model.id = arc.default_model_id
        ORDER BY arc.id DESC
        LIMIT 1
    ");
    $row = $result ? $result->fetch_assoc() : null;

    return $row ?: null;
}

function webtest_ai_admin_validate_runtime_model(mysqli $conn, int $providerId, int $modelId): void
{
    if ($providerId <= 0 || $modelId <= 0) {
        return;
    }

    $stmt = $conn->prepare("
        SELECT provider_config_id
        FROM ai_models
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $modelId);
    $stmt->execute();
    $model = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$model) {
        throw new RuntimeException('The selected AI model does not exist.');
    }

    if ((int) ($model['provider_config_id'] ?? 0) !== $providerId) {
        throw new RuntimeException('The selected AI model does not belong to the chosen provider.');
    }
}

function webtest_ai_admin_fetch_personas(mysqli $conn): array
{
    webtest_ai_admin_runtime_ensure_schema($conn);

    $result = $conn->query("
        SELECT arp.*,
               provider.display_name AS provider_name,
               provider.provider_key AS provider_key,
               model.display_name AS model_name,
               model.model_id AS model_remote_id,
               model.supports_vision AS model_supports_vision,
               model.supports_json_output AS model_supports_json_output
        FROM ai_runtime_personas arp
        LEFT JOIN ai_provider_configs provider ON provider.id = arp.provider_config_id
        LEFT JOIN ai_models model ON model.id = arp.model_id
        ORDER BY arp.id ASC
    ");

    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function webtest_ai_admin_fetch_persona_by_key(mysqli $conn, string $personaKey): ?array
{
    webtest_ai_admin_runtime_ensure_schema($conn);
    $stmt = $conn->prepare("
        SELECT arp.*,
               provider.display_name AS provider_name,
               provider.provider_key AS provider_key,
               model.display_name AS model_name,
               model.model_id AS model_remote_id,
               model.supports_vision AS model_supports_vision,
               model.supports_json_output AS model_supports_json_output
        FROM ai_runtime_personas arp
        LEFT JOIN ai_provider_configs provider ON provider.id = arp.provider_config_id
        LEFT JOIN ai_models model ON model.id = arp.model_id
        WHERE arp.persona_key = ?
        LIMIT 1
    ");
    $stmt->bind_param('s', $personaKey);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function webtest_ai_admin_format_persona(array $persona): array
{
    return [
        'id' => (int) $persona['id'],
        'persona_key' => (string) $persona['persona_key'],
        'display_name' => (string) ($persona['display_name'] ?? webtest_ai_admin_persona_label((string) ($persona['persona_key'] ?? ''))),
        'is_enabled' => (bool) ($persona['is_enabled'] ?? false),
        'provider_config_id' => isset($persona['provider_config_id']) ? (int) $persona['provider_config_id'] : null,
        'provider_name' => (string) ($persona['provider_name'] ?? ''),
        'provider_key' => (string) ($persona['provider_key'] ?? ''),
        'model_id' => isset($persona['model_id']) ? (int) $persona['model_id'] : null,
        'model_name' => (string) ($persona['model_name'] ?? ''),
        'model_remote_id' => (string) ($persona['model_remote_id'] ?? ''),
        'supports_vision' => (bool) ($persona['model_supports_vision'] ?? false),
        'supports_json_output' => (bool) ($persona['model_supports_json_output'] ?? false),
        'assistant_name' => trim((string) ($persona['assistant_name'] ?? '')),
        'system_prompt' => trim((string) ($persona['system_prompt'] ?? '')),
    ];
}

function webtest_ai_admin_personas_for_display(mysqli $conn): array
{
    return array_map('webtest_ai_admin_format_persona', webtest_ai_admin_fetch_personas($conn));
}

function webtest_ai_admin_seed_default_personas(
    mysqli $conn,
    int $actorUserId,
    int $defaultProviderId,
    int $defaultModelId,
    string $assistantName,
    string $systemPrompt
): void {
    webtest_ai_admin_runtime_ensure_schema($conn);
    $definitions = webtest_ai_admin_persona_definitions();

    foreach ($definitions as $personaKey => $definition) {
        $existing = webtest_ai_admin_fetch_persona_by_key($conn, $personaKey);
        if ($existing) {
            continue;
        }

        $seedAssistantName = trim((string) ($definition['assistant_name'] ?? $assistantName));
        if ($personaKey === 'checklist_reviewer' && $seedAssistantName === trim($assistantName)) {
            $seedAssistantName = trim($assistantName . ' Reviewer');
        }
        $seedPrompt = trim((string) ($definition['system_prompt'] ?? $systemPrompt));
        if ($personaKey === 'checklist_generator' && $seedPrompt === '') {
            $seedPrompt = $systemPrompt;
        }

        $stmt = $conn->prepare("
            INSERT INTO ai_runtime_personas
                (persona_key, display_name, is_enabled, provider_config_id, model_id, assistant_name, system_prompt, created_by, updated_by, updated_at)
            VALUES (?, ?, ?, NULLIF(?, 0), NULLIF(?, 0), NULLIF(?, ''), NULLIF(?, ''), ?, ?, NOW())
        ");
        $enabled = !empty($definition['is_enabled']) ? 1 : 0;
        $displayName = (string) ($definition['display_name'] ?? webtest_ai_admin_persona_label($personaKey));
        $stmt->bind_param(
            'ssiiissii',
            $personaKey,
            $displayName,
            $enabled,
            $defaultProviderId,
            $defaultModelId,
            $seedAssistantName,
            $seedPrompt,
            $actorUserId,
            $actorUserId
        );
        $stmt->execute();
        $stmt->close();
    }
}

function webtest_ai_admin_save_personas(
    mysqli $conn,
    int $actorUserId,
    array $personas,
    int $defaultProviderId,
    int $defaultModelId,
    string $assistantName,
    string $systemPrompt
): void {
    webtest_ai_admin_seed_default_personas(
        $conn,
        $actorUserId,
        $defaultProviderId,
        $defaultModelId,
        $assistantName,
        $systemPrompt
    );

    $definitions = webtest_ai_admin_persona_definitions();
    foreach ($definitions as $personaKey => $definition) {
        $input = $personas[$personaKey] ?? null;
        if (!is_array($input)) {
            continue;
        }

        $existing = webtest_ai_admin_fetch_persona_by_key($conn, $personaKey);
        if (!$existing) {
            continue;
        }

        $providerId = isset($input['provider_config_id'])
            ? (int) $input['provider_config_id']
            : (int) ($existing['provider_config_id'] ?? $defaultProviderId);
        $modelId = isset($input['model_id'])
            ? (int) $input['model_id']
            : (int) ($existing['model_id'] ?? $defaultModelId);
        webtest_ai_admin_validate_runtime_model($conn, $providerId, $modelId);

        $personaAssistantName = trim((string) ($input['assistant_name'] ?? $existing['assistant_name'] ?? $definition['assistant_name'] ?? $assistantName));
        if ($personaAssistantName === '') {
            $personaAssistantName = trim((string) ($definition['assistant_name'] ?? $assistantName));
        }

        $personaPrompt = trim((string) ($input['system_prompt'] ?? $existing['system_prompt'] ?? $definition['system_prompt'] ?? $systemPrompt));
        $isEnabled = array_key_exists('is_enabled', $input)
            ? webtest_ai_admin_to_bool($input['is_enabled'], true)
            : (bool) ($existing['is_enabled'] ?? !empty($definition['is_enabled']));

        $stmt = $conn->prepare("
            UPDATE ai_runtime_personas
            SET display_name = ?,
                is_enabled = ?,
                provider_config_id = NULLIF(?, 0),
                model_id = NULLIF(?, 0),
                assistant_name = NULLIF(?, ''),
                system_prompt = NULLIF(?, ''),
                updated_by = ?,
                updated_at = NOW()
            WHERE persona_key = ?
        ");
        $enabled = $isEnabled ? 1 : 0;
        $displayName = (string) ($definition['display_name'] ?? webtest_ai_admin_persona_label($personaKey));
        $stmt->bind_param(
            'siiissis',
            $displayName,
            $enabled,
            $providerId,
            $modelId,
            $personaAssistantName,
            $personaPrompt,
            $actorUserId,
            $personaKey
        );
        $stmt->execute();
        $stmt->close();
    }
}

function webtest_ai_admin_save_runtime_config(
    mysqli $conn,
    int $actorUserId,
    bool $isEnabled,
    int $defaultProviderId,
    int $defaultModelId,
    string $assistantName,
    string $systemPrompt,
    array $personas = []
): void {
    webtest_ai_admin_runtime_ensure_schema($conn);
    webtest_ai_admin_validate_runtime_model($conn, $defaultProviderId, $defaultModelId);

    $assistantName = trim($assistantName);
    if ($assistantName === '') {
        $assistantName = trim((string) webtest_config('AI_CHAT_DEFAULT_ASSISTANT_NAME', 'WebTest AI'));
    }
    $systemPrompt = trim($systemPrompt);

    $existing = webtest_ai_admin_fetch_runtime_config($conn);
    if ($existing) {
        $stmt = $conn->prepare("
            UPDATE ai_runtime_config
            SET is_enabled = ?,
                default_provider_config_id = NULLIF(?, 0),
                default_model_id = NULLIF(?, 0),
                assistant_name = NULLIF(?, ''),
                system_prompt = NULLIF(?, ''),
                updated_by = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $enabled = $isEnabled ? 1 : 0;
        $runtimeId = (int) $existing['id'];
        $stmt->bind_param('iiissii', $enabled, $defaultProviderId, $defaultModelId, $assistantName, $systemPrompt, $actorUserId, $runtimeId);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare("
            INSERT INTO ai_runtime_config
                (
                    is_enabled,
                    default_provider_config_id,
                    default_model_id,
                    assistant_name,
                    system_prompt,
                    created_by,
                    updated_by,
                    updated_at
                )
            VALUES (?, NULLIF(?, 0), NULLIF(?, 0), NULLIF(?, ''), NULLIF(?, ''), ?, ?, NOW())
        ");
        $enabled = $isEnabled ? 1 : 0;
        $stmt->bind_param('iiissii', $enabled, $defaultProviderId, $defaultModelId, $assistantName, $systemPrompt, $actorUserId, $actorUserId);
        $stmt->execute();
        $stmt->close();
    }

    webtest_ai_admin_save_personas(
        $conn,
        $actorUserId,
        $personas,
        $defaultProviderId,
        $defaultModelId,
        $assistantName,
        $systemPrompt
    );
}

function webtest_ai_admin_seed_default_config(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }

    webtest_ai_admin_runtime_ensure_schema($conn);
    $providerKey = trim((string) webtest_config('AI_CHAT_DEMO_PROVIDER_KEY', 'deepseek'));
    $providerName = trim((string) webtest_config('AI_CHAT_DEMO_PROVIDER_NAME', 'DeepSeek'));
    $providerType = trim((string) webtest_config('AI_CHAT_DEMO_PROVIDER_TYPE', 'openai-compatible'));
    $baseUrl = trim((string) webtest_config('AI_CHAT_DEMO_PROVIDER_BASE_URL', 'https://api.deepseek.com'));
    $apiKey = trim((string) webtest_config('AI_CHAT_DEMO_API_KEY', ''));
    $modelId = trim((string) webtest_config('AI_CHAT_DEMO_MODEL_ID', 'deepseek-chat'));
    $modelName = trim((string) webtest_config('AI_CHAT_DEMO_MODEL_NAME', 'DeepSeek Chat'));
    $supportsVision = (bool) webtest_config('AI_CHAT_DEMO_MODEL_SUPPORTS_VISION', false);
    $assistantName = trim((string) webtest_config('AI_CHAT_DEFAULT_ASSISTANT_NAME', 'WebTest AI'));
    $systemPrompt = trim((string) webtest_config('AI_CHAT_DEFAULT_SYSTEM_PROMPT', ''));

    if (
        $providerKey === ''
        || $providerName === ''
        || $providerType === ''
        || $modelId === ''
        || $modelName === ''
    ) {
        $done = true;
        return;
    }

    $actorId = 1;
    $provider = webtest_openclaw_find_provider_by_key($conn, $providerKey);
    if (!$provider) {
        webtest_openclaw_save_provider(
            $conn,
            $actorId,
            0,
            $providerKey,
            $providerName,
            $providerType,
            $baseUrl,
            $apiKey,
            true,
            false
        );
        $provider = webtest_openclaw_find_provider_by_key($conn, $providerKey);
    } elseif (
        trim((string) ($provider['display_name'] ?? '')) !== $providerName
        || trim((string) ($provider['provider_type'] ?? '')) !== $providerType
        || trim((string) ($provider['base_url'] ?? '')) !== $baseUrl
        || !(bool) ($provider['is_enabled'] ?? false)
        || ($apiKey !== '' && webtest_openclaw_decrypt_secret($provider['encrypted_api_key'] ?? '') !== $apiKey)
    ) {
        webtest_openclaw_save_provider(
            $conn,
            $actorId,
            (int) $provider['id'],
            $providerKey,
            $providerName,
            $providerType,
            $baseUrl,
            $apiKey,
            true,
            false
        );
        $provider = webtest_openclaw_find_provider_by_key($conn, $providerKey);
    }

    if (!$provider) {
        $done = true;
        return;
    }

    $model = webtest_openclaw_find_model_by_provider_and_remote_id($conn, (int) $provider['id'], $modelId);
    if (!$model) {
        webtest_openclaw_save_model(
            $conn,
            (int) $provider['id'],
            0,
            $modelId,
            $modelName,
            $supportsVision,
            false,
            true,
            true,
            $actorId
        );
        $model = webtest_openclaw_find_model_by_provider_and_remote_id($conn, (int) $provider['id'], $modelId);
    } elseif (
        trim((string) ($model['display_name'] ?? '')) !== $modelName
        || (bool) ($model['supports_vision'] ?? false) !== $supportsVision
        || !(bool) ($model['is_enabled'] ?? false)
        || !(bool) ($model['is_default'] ?? false)
    ) {
        webtest_openclaw_save_model(
            $conn,
            (int) $provider['id'],
            (int) $model['id'],
            $modelId,
            $modelName,
            $supportsVision,
            (bool) ($model['supports_json_output'] ?? false),
            true,
            true,
            $actorId
        );
        $model = webtest_openclaw_find_model_by_provider_and_remote_id($conn, (int) $provider['id'], $modelId);
    }

    $runtime = webtest_ai_admin_fetch_runtime_config($conn);
    if (!$runtime) {
        webtest_ai_admin_save_runtime_config(
            $conn,
            $actorId,
            (bool) webtest_config('AI_CHAT_DEMO_ENABLED', true),
            (int) $provider['id'],
            (int) ($model['id'] ?? 0),
            $assistantName,
            $systemPrompt
        );
    } elseif (
        (int) ($runtime['default_provider_config_id'] ?? 0) <= 0
        || (int) ($runtime['default_model_id'] ?? 0) <= 0
    ) {
        webtest_ai_admin_save_runtime_config(
            $conn,
            $actorId,
            (bool) ($runtime['is_enabled'] ?? webtest_config('AI_CHAT_DEMO_ENABLED', true)),
            (int) $provider['id'],
            (int) ($model['id'] ?? 0),
            (string) ($runtime['assistant_name'] ?? $assistantName),
            (string) ($runtime['system_prompt'] ?? $systemPrompt)
        );
    }

    $resolvedRuntime = webtest_ai_admin_fetch_runtime_config($conn);
    webtest_ai_admin_seed_default_personas(
        $conn,
        $actorId,
        (int) ($resolvedRuntime['default_provider_config_id'] ?? $provider['id']),
        (int) ($resolvedRuntime['default_model_id'] ?? ($model['id'] ?? 0)),
        (string) ($resolvedRuntime['assistant_name'] ?? $assistantName),
        (string) ($resolvedRuntime['system_prompt'] ?? $systemPrompt)
    );

    $done = true;
}

function webtest_ai_admin_format_provider(array $provider): array
{
    return [
        'id' => (int) $provider['id'],
        'provider_key' => (string) $provider['provider_key'],
        'display_name' => (string) $provider['display_name'],
        'provider_type' => (string) $provider['provider_type'],
        'base_url' => (string) ($provider['base_url'] ?? ''),
        'api_key' => webtest_openclaw_mask_secret($provider['encrypted_api_key'] ?? ''),
        'is_enabled' => (bool) ($provider['is_enabled'] ?? false),
        'supports_model_sync' => (bool) ($provider['supports_model_sync'] ?? false),
    ];
}

function webtest_ai_admin_format_model(array $model): array
{
    return [
        'id' => (int) $model['id'],
        'provider_config_id' => (int) $model['provider_config_id'],
        'provider_name' => (string) ($model['provider_name'] ?? ''),
        'display_name' => (string) $model['display_name'],
        'model_id' => (string) $model['model_id'],
        'supports_vision' => (bool) ($model['supports_vision'] ?? false),
        'supports_json_output' => (bool) ($model['supports_json_output'] ?? false),
        'is_enabled' => (bool) ($model['is_enabled'] ?? false),
        'is_default' => (bool) ($model['is_default'] ?? false),
    ];
}

function webtest_ai_admin_providers_for_display(mysqli $conn): array
{
    return array_map('webtest_ai_admin_format_provider', webtest_openclaw_fetch_providers($conn));
}

function webtest_ai_admin_models_for_display(mysqli $conn): array
{
    return array_map('webtest_ai_admin_format_model', webtest_openclaw_fetch_models($conn));
}

function webtest_ai_admin_runtime_snapshot(mysqli $conn): array
{
    webtest_ai_admin_seed_default_config($conn);
    $runtime = webtest_ai_admin_fetch_runtime_config($conn);
    $personas = webtest_ai_admin_personas_for_display($conn);
    $readiness = webtest_ai_admin_runtime_readiness($conn);

    return [
        'runtime' => [
            'is_enabled' => (bool) ($runtime['is_enabled'] ?? webtest_config('AI_CHAT_DEMO_ENABLED', true)),
            'default_provider_config_id' => isset($runtime['default_provider_config_id']) ? (int) $runtime['default_provider_config_id'] : null,
            'default_model_id' => isset($runtime['default_model_id']) ? (int) $runtime['default_model_id'] : null,
            'assistant_name' => (string) ($runtime['assistant_name'] ?? webtest_config('AI_CHAT_DEFAULT_ASSISTANT_NAME', 'WebTest AI')),
            'system_prompt' => (string) ($runtime['system_prompt'] ?? webtest_config('AI_CHAT_DEFAULT_SYSTEM_PROMPT', '')),
        ],
        'providers' => webtest_ai_admin_providers_for_display($conn),
        'models' => webtest_ai_admin_models_for_display($conn),
        'personas' => $personas,
        'readiness' => $readiness,
    ];
}

function webtest_ai_admin_resolve_runtime(mysqli $conn): array
{
    webtest_ai_admin_seed_default_config($conn);
    $runtime = webtest_ai_admin_fetch_runtime_config($conn);
    if (!$runtime || !(bool) ($runtime['is_enabled'] ?? false)) {
        throw new RuntimeException('AI chat is disabled right now.');
    }

    $providerId = (int) ($runtime['default_provider_config_id'] ?? 0);
    $modelId = (int) ($runtime['default_model_id'] ?? 0);
    if ($providerId <= 0 || $modelId <= 0) {
        throw new RuntimeException('AI chat is not configured correctly. Go to Super Admin > AI.');
    }

    $stmt = $conn->prepare("
        SELECT *
        FROM ai_provider_configs
        WHERE id = ? AND is_enabled = 1
        LIMIT 1
    ");
    $stmt->bind_param('i', $providerId);
    $stmt->execute();
    $provider = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT *
        FROM ai_models
        WHERE id = ? AND is_enabled = 1
        LIMIT 1
    ");
    $stmt->bind_param('i', $modelId);
    $stmt->execute();
    $model = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $apiKey = webtest_openclaw_decrypt_secret($provider['encrypted_api_key'] ?? '');
    if (!$provider || !$model || trim($apiKey) === '') {
        throw new RuntimeException('AI chat is not configured correctly. Go to Super Admin > AI.');
    }

    return [
        'runtime' => $runtime,
        'provider' => $provider,
        'model' => $model,
        'api_key' => $apiKey,
        'assistant_name' => trim((string) ($runtime['assistant_name'] ?? webtest_config('AI_CHAT_DEFAULT_ASSISTANT_NAME', 'WebTest AI'))),
        'system_prompt' => trim((string) ($runtime['system_prompt'] ?? webtest_config('AI_CHAT_DEFAULT_SYSTEM_PROMPT', ''))),
    ];
}

function webtest_ai_admin_resolve_persona_runtime(mysqli $conn, string $personaKey, bool $requireVision = false): array
{
    $runtime = webtest_ai_admin_resolve_runtime($conn);
    $persona = webtest_ai_admin_fetch_persona_by_key($conn, $personaKey);
    if (!$persona) {
        throw new RuntimeException(webtest_ai_admin_persona_label($personaKey) . ' is not configured. Go to Super Admin > AI.');
    }
    if (!(bool) ($persona['is_enabled'] ?? false)) {
        throw new RuntimeException(webtest_ai_admin_persona_label($personaKey) . ' is disabled in Super Admin > AI.');
    }

    $providerId = (int) ($persona['provider_config_id'] ?? $runtime['runtime']['default_provider_config_id'] ?? 0);
    $modelId = (int) ($persona['model_id'] ?? $runtime['runtime']['default_model_id'] ?? 0);
    if ($providerId <= 0 || $modelId <= 0) {
        throw new RuntimeException(webtest_ai_admin_persona_label($personaKey) . ' is missing its provider or model configuration.');
    }

    webtest_ai_admin_validate_runtime_model($conn, $providerId, $modelId);

    $stmt = $conn->prepare("
        SELECT *
        FROM ai_provider_configs
        WHERE id = ? AND is_enabled = 1
        LIMIT 1
    ");
    $stmt->bind_param('i', $providerId);
    $stmt->execute();
    $provider = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT *
        FROM ai_models
        WHERE id = ? AND is_enabled = 1
        LIMIT 1
    ");
    $stmt->bind_param('i', $modelId);
    $stmt->execute();
    $model = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $apiKey = webtest_openclaw_decrypt_secret($provider['encrypted_api_key'] ?? '');
    if (!$provider || !$model || trim($apiKey) === '') {
        throw new RuntimeException(webtest_ai_admin_persona_label($personaKey) . ' is not configured correctly. Go to Super Admin > AI.');
    }

    if ($requireVision && !(bool) ($model['supports_vision'] ?? false)) {
        throw new RuntimeException('Screenshot drafting requires a vision-capable model for Checklist Generator. Configure one in Super Admin > AI.');
    }

    return [
        'runtime' => $runtime['runtime'],
        'persona' => $persona,
        'provider' => $provider,
        'model' => $model,
        'api_key' => $apiKey,
        'assistant_name' => trim((string) ($persona['assistant_name'] ?? $runtime['assistant_name'] ?? webtest_config('AI_CHAT_DEFAULT_ASSISTANT_NAME', 'WebTest AI'))),
        'system_prompt' => trim((string) ($persona['system_prompt'] ?? $runtime['system_prompt'] ?? webtest_config('AI_CHAT_DEFAULT_SYSTEM_PROMPT', ''))),
    ];
}

function webtest_ai_admin_runtime_readiness(mysqli $conn): array
{
    try {
        webtest_ai_admin_resolve_runtime($conn);
    } catch (Throwable $e) {
        $message = $e->getMessage();
        return [
            'link' => [
                'enabled' => false,
                'warning_message' => $message,
            ],
            'screenshot' => [
                'enabled' => false,
                'warning_message' => $message,
            ],
        ];
    }

    $linkWarning = '';
    $linkEnabled = true;
    try {
        webtest_ai_admin_resolve_persona_runtime($conn, 'checklist_generator', false);
    } catch (Throwable $e) {
        $linkEnabled = false;
        $linkWarning = $e->getMessage();
    }

    $screenshotWarning = '';
    $screenshotEnabled = true;
    try {
        webtest_ai_admin_resolve_persona_runtime($conn, 'checklist_generator', true);
    } catch (Throwable $e) {
        $screenshotEnabled = false;
        $screenshotWarning = $e->getMessage();
    }

    return [
        'link' => [
            'enabled' => $linkEnabled,
            'warning_message' => $linkWarning,
        ],
        'screenshot' => [
            'enabled' => $screenshotEnabled,
            'warning_message' => $screenshotWarning,
        ],
    ];
}
