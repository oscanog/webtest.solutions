<?php

declare(strict_types=1);

function bc_v1_ai_chat_allowed(array $orgContext): bool
{
    if (bugcatcher_is_system_admin_role((string) ($orgContext['system_role'] ?? 'user'))) {
        return true;
    }

    return (string) ($orgContext['org_role'] ?? '') === 'QA Lead';
}

function bc_v1_ai_chat_context(mysqli $conn): array
{
    $actor = bc_v1_actor($conn, true);
    $org = bc_v1_org_context($conn, $actor, bc_v1_get_int($_GET + $_POST, 'org_id', 0));
    if (!bc_v1_ai_chat_allowed($org)) {
        bc_v1_json_error(403, 'forbidden', 'You do not have access to AI chat.');
    }

    bugcatcher_ai_chat_ensure_schema($conn);

    return [$actor, $org];
}

function bugcatcher_ai_chat_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }

    bugcatcher_file_storage_ensure_schema($conn);
    bugcatcher_openclaw_seed_demo_ai_config($conn);

    $conn->query("
        CREATE TABLE IF NOT EXISTS ai_chat_threads (
            id INT(11) NOT NULL AUTO_INCREMENT,
            org_id INT(11) NOT NULL,
            user_id INT(11) NOT NULL,
            title VARCHAR(160) NOT NULL DEFAULT 'New chat',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL,
            last_message_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_ai_chat_threads_owner (user_id, org_id, updated_at),
            CONSTRAINT fk_ai_chat_threads_org FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE CASCADE,
            CONSTRAINT fk_ai_chat_threads_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $threadColumns = [
        'checklist_project_id' => "ALTER TABLE ai_chat_threads ADD COLUMN checklist_project_id INT(11) DEFAULT NULL AFTER user_id",
        'checklist_target_mode' => "ALTER TABLE ai_chat_threads ADD COLUMN checklist_target_mode ENUM('new', 'existing') DEFAULT NULL AFTER checklist_project_id",
        'checklist_existing_batch_id' => "ALTER TABLE ai_chat_threads ADD COLUMN checklist_existing_batch_id INT(11) DEFAULT NULL AFTER checklist_target_mode",
        'checklist_batch_title' => "ALTER TABLE ai_chat_threads ADD COLUMN checklist_batch_title VARCHAR(160) DEFAULT NULL AFTER checklist_existing_batch_id",
        'checklist_module_name' => "ALTER TABLE ai_chat_threads ADD COLUMN checklist_module_name VARCHAR(160) DEFAULT NULL AFTER checklist_batch_title",
        'checklist_submodule_name' => "ALTER TABLE ai_chat_threads ADD COLUMN checklist_submodule_name VARCHAR(160) DEFAULT NULL AFTER checklist_module_name",
        'checklist_resolved_batch_id' => "ALTER TABLE ai_chat_threads ADD COLUMN checklist_resolved_batch_id INT(11) DEFAULT NULL AFTER checklist_submodule_name",
    ];

    foreach ($threadColumns as $column => $sql) {
        if (!bugcatcher_db_has_column($conn, 'ai_chat_threads', $column)) {
            $conn->query($sql);
        }
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS ai_chat_messages (
            id INT(11) NOT NULL AUTO_INCREMENT,
            thread_id INT(11) NOT NULL,
            role ENUM('user', 'assistant', 'system') NOT NULL,
            content LONGTEXT DEFAULT NULL,
            status ENUM('pending', 'streaming', 'completed', 'failed') NOT NULL DEFAULT 'completed',
            error_message TEXT DEFAULT NULL,
            provider_config_id INT(11) DEFAULT NULL,
            model_id INT(11) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_ai_chat_messages_thread (thread_id, id),
            CONSTRAINT fk_ai_chat_messages_thread FOREIGN KEY (thread_id) REFERENCES ai_chat_threads(id) ON DELETE CASCADE,
            CONSTRAINT fk_ai_chat_messages_provider FOREIGN KEY (provider_config_id) REFERENCES ai_provider_configs(id) ON DELETE SET NULL,
            CONSTRAINT fk_ai_chat_messages_model FOREIGN KEY (model_id) REFERENCES ai_models(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS ai_chat_message_attachments (
            id INT(11) NOT NULL AUTO_INCREMENT,
            message_id INT(11) NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            storage_key VARCHAR(255) DEFAULT NULL,
            original_name VARCHAR(255) NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            file_size INT(11) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_ai_chat_message_attachments_message (message_id),
            CONSTRAINT fk_ai_chat_message_attachments_message FOREIGN KEY (message_id) REFERENCES ai_chat_messages(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS ai_chat_generated_checklist_items (
            id INT(11) NOT NULL AUTO_INCREMENT,
            assistant_message_id INT(11) NOT NULL,
            thread_id INT(11) NOT NULL,
            org_id INT(11) NOT NULL,
            project_id INT(11) NOT NULL,
            target_mode ENUM('new', 'existing') NOT NULL DEFAULT 'new',
            target_batch_id INT(11) DEFAULT NULL,
            batch_title VARCHAR(160) NOT NULL,
            module_name VARCHAR(160) NOT NULL,
            submodule_name VARCHAR(160) DEFAULT NULL,
            sequence_no INT(11) NOT NULL DEFAULT 1,
            title VARCHAR(255) NOT NULL,
            description TEXT DEFAULT NULL,
            priority VARCHAR(32) NOT NULL DEFAULT 'medium',
            required_role VARCHAR(64) NOT NULL DEFAULT 'QA Tester',
            review_status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
            duplicate_status VARCHAR(32) NOT NULL DEFAULT 'unique',
            duplicate_summary TEXT DEFAULT NULL,
            duplicate_matches LONGTEXT DEFAULT NULL,
            approved_batch_id INT(11) DEFAULT NULL,
            approved_item_id INT(11) DEFAULT NULL,
            approved_by INT(11) DEFAULT NULL,
            approved_at DATETIME DEFAULT NULL,
            rejected_by INT(11) DEFAULT NULL,
            rejected_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_ai_chat_generated_items_thread (thread_id, id),
            KEY idx_ai_chat_generated_items_assistant (assistant_message_id),
            CONSTRAINT fk_ai_chat_generated_items_message FOREIGN KEY (assistant_message_id) REFERENCES ai_chat_messages(id) ON DELETE CASCADE,
            CONSTRAINT fk_ai_chat_generated_items_thread FOREIGN KEY (thread_id) REFERENCES ai_chat_threads(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $done = true;
}

function bugcatcher_ai_chat_fetch_thread(mysqli $conn, int $threadId, int $orgId, int $userId): ?array
{
    $stmt = $conn->prepare("
        SELECT *
        FROM ai_chat_threads
        WHERE id = ? AND org_id = ? AND user_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('iii', $threadId, $orgId, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function bugcatcher_ai_chat_fetch_message_attachments(mysqli $conn, array $messageIds): array
{
    if (!$messageIds) {
        return [];
    }

    $messageIds = array_values(array_unique(array_map('intval', $messageIds)));
    $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
    $types = str_repeat('i', count($messageIds));
    $stmt = $conn->prepare("
        SELECT id, message_id, file_path, storage_key, original_name, mime_type, file_size, created_at
        FROM ai_chat_message_attachments
        WHERE message_id IN ({$placeholders})
        ORDER BY id ASC
    ");
    bc_v1_stmt_bind($stmt, $types, $messageIds);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $map = [];
    foreach ($rows as $row) {
        $messageId = (int) $row['message_id'];
        $map[$messageId][] = [
            'id' => (int) $row['id'],
            'file_path' => (string) $row['file_path'],
            'storage_key' => (string) ($row['storage_key'] ?? ''),
            'original_name' => (string) $row['original_name'],
            'mime_type' => (string) $row['mime_type'],
            'file_size' => (int) $row['file_size'],
            'created_at' => (string) $row['created_at'],
        ];
    }

    return $map;
}

function bugcatcher_ai_chat_fetch_generated_items(mysqli $conn, array $assistantMessageIds): array
{
    if (!$assistantMessageIds) {
        return [];
    }

    $assistantMessageIds = array_values(array_unique(array_map('intval', $assistantMessageIds)));
    $placeholders = implode(',', array_fill(0, count($assistantMessageIds), '?'));
    $types = str_repeat('i', count($assistantMessageIds));
    $stmt = $conn->prepare("
        SELECT *
        FROM ai_chat_generated_checklist_items
        WHERE assistant_message_id IN ({$placeholders})
        ORDER BY assistant_message_id ASC, sequence_no ASC, id ASC
    ");
    bc_v1_stmt_bind($stmt, $types, $assistantMessageIds);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $map = [];
    foreach ($rows as $row) {
        $assistantMessageId = (int) $row['assistant_message_id'];
        $duplicateMatches = json_decode((string) ($row['duplicate_matches'] ?? '[]'), true);
        if (!is_array($duplicateMatches)) {
            $duplicateMatches = [];
        }

        $map[$assistantMessageId][] = [
            'id' => (int) $row['id'],
            'project_id' => (int) $row['project_id'],
            'target_mode' => (string) $row['target_mode'],
            'target_batch_id' => isset($row['target_batch_id']) ? (int) $row['target_batch_id'] : null,
            'batch_title' => (string) $row['batch_title'],
            'module_name' => (string) $row['module_name'],
            'submodule_name' => (string) ($row['submodule_name'] ?? ''),
            'sequence_no' => (int) $row['sequence_no'],
            'title' => (string) $row['title'],
            'description' => (string) ($row['description'] ?? ''),
            'priority' => (string) ($row['priority'] ?? 'medium'),
            'required_role' => (string) ($row['required_role'] ?? 'QA Tester'),
            'review_status' => (string) ($row['review_status'] ?? 'pending'),
            'duplicate_status' => (string) ($row['duplicate_status'] ?? 'unique'),
            'duplicate_summary' => (string) ($row['duplicate_summary'] ?? ''),
            'duplicate_matches' => $duplicateMatches,
            'approved_batch_id' => isset($row['approved_batch_id']) ? (int) $row['approved_batch_id'] : null,
            'approved_item_id' => isset($row['approved_item_id']) ? (int) $row['approved_item_id'] : null,
            'approved_at' => (string) ($row['approved_at'] ?? ''),
            'rejected_at' => (string) ($row['rejected_at'] ?? ''),
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    return $map;
}

function bugcatcher_ai_chat_thread_context_shape(mysqli $conn, array $thread): array
{
    $projectId = isset($thread['checklist_project_id']) ? (int) $thread['checklist_project_id'] : 0;
    $existingBatchId = isset($thread['checklist_existing_batch_id']) ? (int) $thread['checklist_existing_batch_id'] : 0;
    $resolvedBatchId = isset($thread['checklist_resolved_batch_id']) ? (int) $thread['checklist_resolved_batch_id'] : 0;
    $project = $projectId > 0 ? bugcatcher_checklist_fetch_project($conn, (int) $thread['org_id'], $projectId) : null;
    $existingBatch = $existingBatchId > 0 ? bugcatcher_checklist_fetch_batch($conn, (int) $thread['org_id'], $existingBatchId) : null;
    $resolvedBatch = $resolvedBatchId > 0 ? bugcatcher_checklist_fetch_batch($conn, (int) $thread['org_id'], $resolvedBatchId) : null;

    return [
        'project_id' => $projectId,
        'project_name' => (string) ($project['name'] ?? ''),
        'target_mode' => (string) ($thread['checklist_target_mode'] ?? ''),
        'existing_batch_id' => $existingBatchId > 0 ? $existingBatchId : null,
        'existing_batch_title' => (string) ($existingBatch['title'] ?? ''),
        'resolved_batch_id' => $resolvedBatchId > 0 ? $resolvedBatchId : null,
        'resolved_batch_title' => (string) ($resolvedBatch['title'] ?? ''),
        'batch_title' => (string) ($thread['checklist_batch_title'] ?? ''),
        'module_name' => (string) ($thread['checklist_module_name'] ?? ''),
        'submodule_name' => (string) ($thread['checklist_submodule_name'] ?? ''),
        'is_ready' => $projectId > 0 && trim((string) ($thread['checklist_target_mode'] ?? '')) !== '',
        'is_locked' => $resolvedBatchId > 0,
    ];
}

function bugcatcher_ai_chat_fetch_messages(mysqli $conn, int $threadId): array
{
    $stmt = $conn->prepare("
        SELECT *
        FROM ai_chat_messages
        WHERE thread_id = ?
        ORDER BY id ASC
    ");
    $stmt->bind_param('i', $threadId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $messageIds = array_map(static fn(array $row): int => (int) $row['id'], $rows);
    $assistantMessageIds = array_values(array_filter(array_map(
        static fn(array $row): int => (string) ($row['role'] ?? '') === 'assistant' ? (int) $row['id'] : 0,
        $rows
    )));
    $attachmentMap = bugcatcher_ai_chat_fetch_message_attachments($conn, $messageIds);
    $generatedItemMap = bugcatcher_ai_chat_fetch_generated_items($conn, $assistantMessageIds);

    return array_map(static function (array $row) use ($attachmentMap, $generatedItemMap): array {
        $messageId = (int) $row['id'];
        return [
            'id' => $messageId,
            'role' => (string) $row['role'],
            'content' => (string) ($row['content'] ?? ''),
            'status' => (string) ($row['status'] ?? 'completed'),
            'error_message' => (string) ($row['error_message'] ?? ''),
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'attachments' => $attachmentMap[$messageId] ?? [],
            'generated_checklist_items' => $generatedItemMap[$messageId] ?? [],
        ];
    }, $rows);
}

function bugcatcher_ai_chat_thread_shape(mysqli $conn, array $thread): array
{
    return [
        'id' => (int) $thread['id'],
        'org_id' => (int) $thread['org_id'],
        'user_id' => (int) $thread['user_id'],
        'title' => (string) $thread['title'],
        'created_at' => (string) $thread['created_at'],
        'updated_at' => (string) ($thread['updated_at'] ?? ''),
        'last_message_at' => (string) ($thread['last_message_at'] ?? ''),
        'draft_context' => bugcatcher_ai_chat_thread_context_shape($conn, $thread),
        'messages' => bugcatcher_ai_chat_fetch_messages($conn, (int) $thread['id']),
    ];
}

function bugcatcher_ai_chat_touch_thread(mysqli $conn, int $threadId): void
{
    $stmt = $conn->prepare("
        UPDATE ai_chat_threads
        SET updated_at = NOW(),
            last_message_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param('i', $threadId);
    $stmt->execute();
    $stmt->close();
}

function bugcatcher_ai_chat_summarize_title(string $message): string
{
    $normalized = preg_replace('/\s+/', ' ', trim($message));
    if ($normalized === '') {
        return 'New chat';
    }

    return function_exists('mb_substr')
        ? mb_substr($normalized, 0, 60)
        : substr($normalized, 0, 60);
}

function bugcatcher_ai_chat_update_thread_title_if_placeholder(mysqli $conn, int $threadId, string $message): void
{
    $title = bugcatcher_ai_chat_summarize_title($message);
    $stmt = $conn->prepare("
        UPDATE ai_chat_threads
        SET title = CASE
            WHEN title = 'New chat' OR title = '' THEN ?
            ELSE title
        END,
        updated_at = NOW(),
        last_message_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param('si', $title, $threadId);
    $stmt->execute();
    $stmt->close();
}

function bugcatcher_ai_chat_update_thread_title_from_context(mysqli $conn, int $threadId, string $batchTitle): void
{
    $title = trim($batchTitle);
    if ($title === '') {
        return;
    }

    $stmt = $conn->prepare("
        UPDATE ai_chat_threads
        SET title = CASE
            WHEN title = 'New chat' OR title = '' THEN ?
            ELSE title
        END,
        updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param('si', $title, $threadId);
    $stmt->execute();
    $stmt->close();
}

function bugcatcher_ai_chat_context_from_thread(array $thread): array
{
    return [
        'project_id' => (int) ($thread['checklist_project_id'] ?? 0),
        'target_mode' => (string) ($thread['checklist_target_mode'] ?? ''),
        'existing_batch_id' => (int) ($thread['checklist_existing_batch_id'] ?? 0),
        'batch_title' => trim((string) ($thread['checklist_batch_title'] ?? '')),
        'module_name' => trim((string) ($thread['checklist_module_name'] ?? '')),
        'submodule_name' => trim((string) ($thread['checklist_submodule_name'] ?? '')),
    ];
}

function bugcatcher_ai_chat_thread_has_ready_context(array $thread): bool
{
    $context = bugcatcher_ai_chat_context_from_thread($thread);
    if ($context['project_id'] <= 0 || !in_array($context['target_mode'], ['new', 'existing'], true)) {
        return false;
    }

    if ($context['target_mode'] === 'existing') {
        return $context['existing_batch_id'] > 0;
    }

    return $context['batch_title'] !== '' && $context['module_name'] !== '';
}

function bugcatcher_ai_chat_validate_draft_context(mysqli $conn, int $orgId, array $payload): array
{
    $projectId = bc_v1_get_int($payload, 'project_id', 0);
    $project = $projectId > 0 ? bugcatcher_checklist_fetch_project($conn, $orgId, $projectId) : null;
    if (!$project) {
        throw new RuntimeException('Select a valid project in the active organization.');
    }

    $targetMode = trim((string) ($payload['target_mode'] ?? ''));
    if (!in_array($targetMode, ['new', 'existing'], true)) {
        throw new RuntimeException('Select whether the draft should save to a new or existing checklist batch.');
    }

    if ($targetMode === 'existing') {
        $existingBatchId = bc_v1_get_int($payload, 'existing_batch_id', 0);
        $batch = $existingBatchId > 0 ? bugcatcher_checklist_fetch_batch($conn, $orgId, $existingBatchId) : null;
        if (!$batch || (int) ($batch['project_id'] ?? 0) !== $projectId) {
            throw new RuntimeException('Select a valid existing checklist batch from the chosen project.');
        }

        return [
            'project_id' => $projectId,
            'target_mode' => 'existing',
            'existing_batch_id' => (int) $batch['id'],
            'batch_title' => trim((string) ($batch['title'] ?? '')),
            'module_name' => trim((string) ($batch['module_name'] ?? '')),
            'submodule_name' => trim((string) ($batch['submodule_name'] ?? '')),
        ];
    }

    $batchTitle = trim((string) ($payload['batch_title'] ?? ''));
    $moduleName = trim((string) ($payload['module_name'] ?? ''));
    $submoduleName = trim((string) ($payload['submodule_name'] ?? ''));
    if ($batchTitle === '' || $moduleName === '') {
        throw new RuntimeException('Batch title and module name are required for a new checklist batch target.');
    }

    return [
        'project_id' => $projectId,
        'target_mode' => 'new',
        'existing_batch_id' => 0,
        'batch_title' => $batchTitle,
        'module_name' => $moduleName,
        'submodule_name' => $submoduleName,
    ];
}

function bugcatcher_ai_chat_thread_context_matches(array $thread, array $context): bool
{
    return (int) ($thread['checklist_project_id'] ?? 0) === (int) $context['project_id']
        && (string) ($thread['checklist_target_mode'] ?? '') === (string) $context['target_mode']
        && (int) ($thread['checklist_existing_batch_id'] ?? 0) === (int) ($context['existing_batch_id'] ?? 0)
        && trim((string) ($thread['checklist_batch_title'] ?? '')) === trim((string) ($context['batch_title'] ?? ''))
        && trim((string) ($thread['checklist_module_name'] ?? '')) === trim((string) ($context['module_name'] ?? ''))
        && trim((string) ($thread['checklist_submodule_name'] ?? '')) === trim((string) ($context['submodule_name'] ?? ''));
}

function bugcatcher_ai_chat_upsert_thread_context(mysqli $conn, int $threadId, array $context): void
{
    $existingBatchId = (int) ($context['existing_batch_id'] ?? 0);
    $stmt = $conn->prepare("
        UPDATE ai_chat_threads
        SET checklist_project_id = ?,
            checklist_target_mode = ?,
            checklist_existing_batch_id = NULLIF(?, 0),
            checklist_batch_title = ?,
            checklist_module_name = ?,
            checklist_submodule_name = NULLIF(?, ''),
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param(
        'isisssi',
        $context['project_id'],
        $context['target_mode'],
        $existingBatchId,
        $context['batch_title'],
        $context['module_name'],
        $context['submodule_name'],
        $threadId
    );
    $stmt->execute();
    $stmt->close();
}

function bugcatcher_ai_chat_resolve_runtime(mysqli $conn): array
{
    $runtime = bugcatcher_openclaw_fetch_runtime_config($conn);
    if (!$runtime || !(bool) ($runtime['ai_chat_enabled'] ?? false)) {
        throw new RuntimeException('AI chat is disabled right now.');
    }

    $providerId = (int) ($runtime['ai_chat_default_provider_config_id'] ?? 0);
    $modelId = (int) ($runtime['ai_chat_default_model_id'] ?? 0);
    if ($providerId <= 0 || $modelId <= 0) {
        throw new RuntimeException('AI chat is not configured correctly. Go to Super Admin > OpenClaw > AI Chat settings.');
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

    $apiKey = bugcatcher_openclaw_decrypt_secret($provider['encrypted_api_key'] ?? '');
    if (!$provider || !$model || trim($apiKey) === '') {
        throw new RuntimeException('AI chat is not configured correctly. Go to Super Admin > OpenClaw > AI Chat settings.');
    }
    if (!(bool) ($model['supports_vision'] ?? false)) {
        throw new RuntimeException('The configured AI model must support image analysis for checklist drafting.');
    }

    return [
        'runtime' => $runtime,
        'provider' => $provider,
        'model' => $model,
        'api_key' => $apiKey,
        'assistant_name' => trim((string) ($runtime['ai_chat_assistant_name'] ?? bugcatcher_config('AI_CHAT_DEFAULT_ASSISTANT_NAME', 'BugCatcher AI'))),
        'system_prompt' => trim((string) ($runtime['ai_chat_system_prompt'] ?? bugcatcher_config('AI_CHAT_DEFAULT_SYSTEM_PROMPT', ''))),
    ];
}

function bugcatcher_ai_chat_has_image_context(mysqli $conn, int $threadId): bool
{
    $stmt = $conn->prepare("
        SELECT 1
        FROM ai_chat_message_attachments a
        JOIN ai_chat_messages m ON m.id = a.message_id
        WHERE m.thread_id = ?
          AND a.mime_type LIKE 'image/%'
        LIMIT 1
    ");
    $stmt->bind_param('i', $threadId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (bool) $row;
}

function bugcatcher_ai_chat_build_checklist_system_prompt(array $runtime, array $thread): string
{
    $parts = [];
    $basePrompt = trim((string) ($runtime['system_prompt'] ?? ''));
    if ($basePrompt !== '') {
        $parts[] = $basePrompt;
    }

    $parts[] = implode("\n", [
        'You are BugCatcher AI drafting checklist items for admins and QA Leads.',
        'This chat is only for creating checklist batch items from screenshots or module images.',
        'Use the configured batch target exactly as the default scope for every item.',
        'Return valid JSON only. Do not wrap it in markdown fences.',
        'JSON schema:',
        '{',
        '  "assistant_reply": "short explanation for the user",',
        '  "items": [',
        '    {',
        '      "title": "short checklist title",',
        '      "description": "include action, expected result, and verification hints",',
        '      "module_name": "module name",',
        '      "submodule_name": "optional submodule name",',
        '      "priority": "low|medium|high",',
        '      "required_role": "QA Lead|Senior QA|QA Tester|Project Manager|Senior Developer|Junior Developer|member|owner"',
        '    }',
        '  ]',
        '}',
        'If the screenshot evidence is incomplete, set "items" to an empty array and explain what is missing in "assistant_reply".',
        'Create focused, non-duplicative checklist items. Prefer 3 to 8 items unless the user asks otherwise.',
        'Keep titles concise and make descriptions practical for manual QA execution.',
        'Target context:',
        '- Project ID: ' . (int) ($thread['checklist_project_id'] ?? 0),
        '- Batch title: ' . trim((string) ($thread['checklist_batch_title'] ?? '')),
        '- Module: ' . trim((string) ($thread['checklist_module_name'] ?? '')),
        '- Submodule: ' . trim((string) ($thread['checklist_submodule_name'] ?? '')),
        '- Target mode: ' . trim((string) ($thread['checklist_target_mode'] ?? 'new')),
    ]);

    return implode("\n\n", array_filter($parts));
}

function bugcatcher_ai_chat_build_provider_messages(mysqli $conn, int $threadId, array $runtime, array $thread): array
{
    $messages = bugcatcher_ai_chat_fetch_messages($conn, $threadId);
    $payload = [];

    $payload[] = [
        'role' => 'system',
        'content' => bugcatcher_ai_chat_build_checklist_system_prompt($runtime, $thread),
    ];

    $supportsVision = (bool) ($runtime['model']['supports_vision'] ?? false);
    $providerKey = strtolower(trim((string) ($runtime['provider']['provider_key'] ?? '')));
    foreach ($messages as $message) {
        $content = trim((string) ($message['content'] ?? ''));
        $attachments = $message['attachments'] ?? [];
        if ($message['role'] === 'assistant' && (string) ($message['status'] ?? '') === 'failed' && $content === '') {
            continue;
        }
        if ($message['role'] === 'assistant' && $content === '' && empty($message['generated_checklist_items'])) {
            continue;
        }

        if ($message['role'] === 'user' && $supportsVision && $attachments) {
            if ($providerKey === 'deepseek') {
                $parts = [];
                if ($content !== '') {
                    $parts[] = $content;
                }
                $parts[] = 'Review the attached screenshot URLs below as image inputs.';
                foreach ($attachments as $index => $attachment) {
                    $originalName = trim((string) ($attachment['original_name'] ?? ('image-' . ($index + 1))));
                    $imageUrl = trim((string) ($attachment['file_path'] ?? ''));
                    if ($imageUrl === '') {
                        continue;
                    }
                    $parts[] = sprintf(
                        'Image %d (%s): ![%s](%s)',
                        $index + 1,
                        $originalName,
                        $originalName,
                        $imageUrl
                    );
                }
                if (!$parts) {
                    $parts[] = 'Please review the attached images.';
                }
                $payload[] = [
                    'role' => 'user',
                    'content' => implode("\n\n", $parts),
                ];
                continue;
            }

            $parts = [];
            if ($content !== '') {
                $parts[] = ['type' => 'text', 'text' => $content];
            }
            foreach ($attachments as $attachment) {
                $parts[] = [
                    'type' => 'image_url',
                    'image_url' => ['url' => (string) $attachment['file_path']],
                ];
            }
            if (!$parts) {
                $parts[] = ['type' => 'text', 'text' => 'Please review the attached images.'];
            }
            $payload[] = [
                'role' => 'user',
                'content' => $parts,
            ];
            continue;
        }

        if ($content === '' && !$attachments) {
            continue;
        }

        if ($message['role'] === 'user' && $attachments && !$supportsVision) {
            $content = trim($content . "\n\nAttached file(s):\n" . implode("\n", array_map(
                static fn(array $attachment): string => '- ' . (string) ($attachment['original_name'] ?? 'Attachment'),
                $attachments
            )));
        }

        $payload[] = [
            'role' => (string) $message['role'],
            'content' => $content,
        ];
    }

    return $payload;
}

function bugcatcher_ai_chat_stream_event(string $event, array $payload): void
{
    echo "event: {$event}\n";
    echo 'data: ' . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
    if (function_exists('ob_flush')) {
        @ob_flush();
    }
    flush();
}

function bugcatcher_ai_chat_start_stream_response(): void
{
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-store');
    header('X-Accel-Buffering: no');
    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', '0');
    ignore_user_abort(true);
    set_time_limit(0);
}

function bugcatcher_ai_chat_friendly_provider_error(int $statusCode, string $responseBody): string
{
    $decoded = json_decode(trim($responseBody), true);
    $message = '';
    if (is_array($decoded)) {
        $message = trim((string) ($decoded['error']['message'] ?? $decoded['message'] ?? ''));
    }

    if ($statusCode === 401 || $statusCode === 403 || stripos($message, 'api key') !== false || stripos($message, 'authentication') !== false) {
        return 'AI chat is not configured correctly. Go to Super Admin > OpenClaw > AI Chat settings.';
    }

    return $message !== '' ? $message : 'AI chat could not complete the reply. Please try again.';
}

function bugcatcher_ai_chat_stream_provider_reply(array $providerRuntime, array $messages, callable $onDelta): string
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is required for AI chat streaming.');
    }

    $baseUrl = rtrim((string) ($providerRuntime['provider']['base_url'] ?? ''), '/');
    if ($baseUrl === '') {
        $baseUrl = 'https://api.deepseek.com';
    }
    $url = $baseUrl . '/chat/completions';
    $payload = json_encode([
        'model' => (string) ($providerRuntime['model']['model_id'] ?? 'deepseek-chat'),
        'messages' => $messages,
        'stream' => true,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $buffer = '';
    $rawResponse = '';
    $assistantText = '';
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: text/event-stream',
            'Authorization: Bearer ' . (string) $providerRuntime['api_key'],
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$buffer, &$rawResponse, &$assistantText, $onDelta): int {
            $rawResponse .= $chunk;
            $buffer .= $chunk;

            while (($newlinePos = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $newlinePos), "\r");
                $buffer = (string) substr($buffer, $newlinePos + 1);

                if ($line === '' || strpos($line, 'data: ') !== 0) {
                    continue;
                }

                $data = substr($line, 6);
                if ($data === '[DONE]') {
                    continue;
                }

                $decoded = json_decode($data, true);
                if (!is_array($decoded)) {
                    continue;
                }

                $delta = $decoded['choices'][0]['delta']['content'] ?? '';
                if (is_string($delta) && $delta !== '') {
                    $assistantText .= $delta;
                    $onDelta($delta);
                }
            }

            return strlen($chunk);
        },
    ]);

    $result = curl_exec($curl);
    $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    if ($result === false) {
        $error = curl_error($curl);
        curl_close($curl);
        throw new RuntimeException($error !== '' ? $error : 'AI provider request failed.');
    }

    curl_close($curl);

    if ($statusCode < 200 || $statusCode >= 300) {
        throw new RuntimeException(bugcatcher_ai_chat_friendly_provider_error($statusCode, $rawResponse));
    }

    if (trim($assistantText) === '') {
        throw new RuntimeException('AI chat returned an empty reply. Please try again.');
    }

    return $assistantText;
}

function bugcatcher_ai_chat_extract_json_payload(string $rawContent): string
{
    $trimmed = trim($rawContent);
    if ($trimmed === '') {
        return '';
    }

    if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/is', $trimmed, $matches)) {
        return trim((string) ($matches[1] ?? ''));
    }
    if ($trimmed[0] === '{') {
        return $trimmed;
    }

    $firstBrace = strpos($trimmed, '{');
    $lastBrace = strrpos($trimmed, '}');
    if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
        return trim(substr($trimmed, $firstBrace, $lastBrace - $firstBrace + 1));
    }

    return '';
}

function bugcatcher_ai_chat_normalize_draft_item(array $payload, array $thread, int $sequenceNo): ?array
{
    $title = trim((string) ($payload['title'] ?? ''));
    if ($title === '') {
        return null;
    }

    $moduleName = trim((string) ($payload['module_name'] ?? $thread['checklist_module_name'] ?? ''));
    if ($moduleName === '') {
        return null;
    }

    $submoduleName = trim((string) ($payload['submodule_name'] ?? $thread['checklist_submodule_name'] ?? ''));
    $description = trim((string) ($payload['description'] ?? ''));
    $priority = bugcatcher_checklist_normalize_enum(
        (string) ($payload['priority'] ?? 'medium'),
        BUGCATCHER_CHECKLIST_PRIORITIES,
        'medium'
    );
    $requiredRole = bugcatcher_checklist_normalize_enum(
        (string) ($payload['required_role'] ?? 'QA Tester'),
        BUGCATCHER_CHECKLIST_ALLOWED_REQUIRED_ROLES,
        'QA Tester'
    );

    return [
        'sequence_no' => $sequenceNo,
        'title' => $title,
        'description' => $description,
        'module_name' => $moduleName,
        'submodule_name' => $submoduleName,
        'priority' => $priority,
        'required_role' => $requiredRole,
    ];
}

function bugcatcher_ai_chat_parse_draft_response(string $rawContent, array $thread): array
{
    $jsonPayload = bugcatcher_ai_chat_extract_json_payload($rawContent);
    if ($jsonPayload === '') {
        return [
            'assistant_reply' => trim($rawContent),
            'items' => [],
            'parsed' => false,
        ];
    }

    $decoded = json_decode($jsonPayload, true);
    if (!is_array($decoded)) {
        return [
            'assistant_reply' => trim($rawContent),
            'items' => [],
            'parsed' => false,
        ];
    }

    $assistantReply = trim((string) ($decoded['assistant_reply'] ?? $decoded['reply'] ?? ''));
    $itemsPayload = is_array($decoded['items'] ?? null) ? $decoded['items'] : [];
    $items = [];
    foreach ($itemsPayload as $index => $itemPayload) {
        if (!is_array($itemPayload)) {
            continue;
        }

        $normalized = bugcatcher_ai_chat_normalize_draft_item($itemPayload, $thread, $index + 1);
        if ($normalized !== null) {
            $items[] = $normalized;
        }
    }

    if ($assistantReply === '' && $items) {
        $assistantReply = sprintf('I drafted %d checklist item%s for review.', count($items), count($items) === 1 ? '' : 's');
    }
    if ($assistantReply === '') {
        $assistantReply = trim($rawContent);
    }

    return [
        'assistant_reply' => $assistantReply,
        'items' => $items,
        'parsed' => true,
    ];
}

function bugcatcher_ai_chat_store_uploaded_attachments(mysqli $conn, int $messageId, array &$uploadedKeys): void
{
    if (empty($_FILES['attachments']) || !is_array($_FILES['attachments']['name'] ?? null)) {
        return;
    }

    $allowed = bugcatcher_checklist_allowed_mime_map();
    $count = count($_FILES['attachments']['name']);
    $stmtAttachment = $conn->prepare("
        INSERT INTO ai_chat_message_attachments
            (message_id, file_path, storage_key, original_name, mime_type, file_size)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    for ($index = 0; $index < $count; $index++) {
        $err = (int) ($_FILES['attachments']['error'][$index] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE || $err !== UPLOAD_ERR_OK) {
            continue;
        }

        $tmpPath = (string) ($_FILES['attachments']['tmp_name'][$index] ?? '');
        $size = (int) ($_FILES['attachments']['size'][$index] ?? 0);
        if ($tmpPath === '' || !is_uploaded_file($tmpPath) || $size <= 0) {
            continue;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = is_resource($finfo) ? (string) finfo_file($finfo, $tmpPath) : '';
        if (is_resource($finfo)) {
            finfo_close($finfo);
        }
        if (!isset($allowed[$mime]) || strpos($mime, 'image/') !== 0 || $size > $allowed[$mime]['max']) {
            continue;
        }

        $safeOrig = preg_replace('/[^a-zA-Z0-9._-]/', '_', (string) ($_FILES['attachments']['name'][$index] ?? 'image'));
        $stored = bugcatcher_file_storage_upload_file($tmpPath, $safeOrig, $mime, $size, 'ai-chat');
        $filePath = (string) $stored['file_path'];
        $storageKey = (string) ($stored['storage_key'] ?? '');
        if ($storageKey !== '') {
            $uploadedKeys[] = $storageKey;
        }

        $storedName = (string) ($stored['original_name'] ?? $safeOrig);
        $storedMime = (string) ($stored['mime_type'] ?? $mime);
        $storedSize = (int) ($stored['file_size'] ?? $size);
        $stmtAttachment->bind_param('issssi', $messageId, $filePath, $storageKey, $storedName, $storedMime, $storedSize);
        $stmtAttachment->execute();
    }

    $stmtAttachment->close();
}

function bugcatcher_ai_chat_insert_generated_items(mysqli $conn, int $assistantMessageId, array $thread, array $items): void
{
    if (!$items) {
        return;
    }

    $duplicates = bugcatcher_openclaw_find_duplicates($conn, (int) $thread['checklist_project_id'], $items);
    $stmt = $conn->prepare("
        INSERT INTO ai_chat_generated_checklist_items
            (assistant_message_id, thread_id, org_id, project_id, target_mode, target_batch_id, batch_title, module_name,
             submodule_name, sequence_no, title, description, priority, required_role, review_status, duplicate_status,
             duplicate_summary, duplicate_matches, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, NULLIF(?, 0), ?, ?, NULLIF(?, ''), ?, ?, NULLIF(?, ''), ?, ?, 'pending', ?, ?, ?, NOW(), NOW())
    ");

    foreach ($items as $index => $item) {
        $duplicateMeta = is_array($duplicates[$index] ?? null) ? $duplicates[$index] : [];
        $targetBatchId = (string) ($thread['checklist_target_mode'] ?? '') === 'existing'
            ? (int) ($thread['checklist_existing_batch_id'] ?? 0)
            : 0;
        $duplicateStatus = (string) ($duplicateMeta['duplicate_status'] ?? 'unique');
        $duplicateSummary = (string) ($duplicateMeta['duplicate_summary'] ?? '');
        $duplicateMatches = json_encode($duplicateMeta['matches'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $stmt->bind_param(
            'iiiisisssisssssss',
            $assistantMessageId,
            $thread['id'],
            $thread['org_id'],
            $thread['checklist_project_id'],
            $thread['checklist_target_mode'],
            $targetBatchId,
            $thread['checklist_batch_title'],
            $item['module_name'],
            $item['submodule_name'],
            $item['sequence_no'],
            $item['title'],
            $item['description'],
            $item['priority'],
            $item['required_role'],
            $duplicateStatus,
            $duplicateSummary,
            $duplicateMatches
        );
        $stmt->execute();
    }

    $stmt->close();
}

function bugcatcher_ai_chat_generate_checklist_draft(
    mysqli $conn,
    array $thread,
    array $runtime,
    string $messageText,
    bool $hasAttachments
): array {
    if (!bugcatcher_ai_chat_thread_has_ready_context($thread)) {
        throw new RuntimeException('Select a project and checklist batch target before generating draft items.');
    }
    if (!$hasAttachments && !bugcatcher_ai_chat_has_image_context($conn, (int) $thread['id'])) {
        throw new RuntimeException('Upload at least one image before generating checklist draft items.');
    }
    if ($messageText === '' && !$hasAttachments) {
        throw new RuntimeException('Add a message or image before drafting checklist items.');
    }

    $uploadedKeys = [];
    $userMessageId = 0;
    $assistantMessageId = 0;
    $assistantReply = '';
    $rawAssistantContent = '';
    $items = [];
    $threadId = (int) $thread['id'];

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("
            INSERT INTO ai_chat_messages (thread_id, role, content, status, updated_at)
            VALUES (?, 'user', ?, 'completed', NOW())
        ");
        $stmt->bind_param('is', $threadId, $messageText);
        $stmt->execute();
        $userMessageId = (int) $stmt->insert_id;
        $stmt->close();

        bugcatcher_ai_chat_store_uploaded_attachments($conn, $userMessageId, $uploadedKeys);

        $stmt = $conn->prepare("
            INSERT INTO ai_chat_messages (thread_id, role, content, status, provider_config_id, model_id, updated_at)
            VALUES (?, 'assistant', '', 'streaming', ?, ?, NOW())
        ");
        $providerId = (int) ($runtime['provider']['id'] ?? 0);
        $modelId = (int) ($runtime['model']['id'] ?? 0);
        $stmt->bind_param('iii', $threadId, $providerId, $modelId);
        $stmt->execute();
        $assistantMessageId = (int) $stmt->insert_id;
        $stmt->close();

        bugcatcher_ai_chat_touch_thread($conn, $threadId);
        if (trim((string) ($thread['checklist_batch_title'] ?? '')) !== '') {
            bugcatcher_ai_chat_update_thread_title_from_context($conn, $threadId, (string) $thread['checklist_batch_title']);
        } else {
            bugcatcher_ai_chat_update_thread_title_if_placeholder($conn, $threadId, $messageText);
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        foreach ($uploadedKeys as $uploadedKey) {
            try {
                bugcatcher_file_storage_delete($uploadedKey);
            } catch (Throwable $deleteError) {
                // Ignore rollback cleanup failures.
            }
        }
        throw new RuntimeException('Unable to save the draft request.');
    }

    try {
        $providerMessages = bugcatcher_ai_chat_build_provider_messages($conn, $threadId, $runtime, $thread);
        $rawAssistantContent = bugcatcher_ai_chat_stream_provider_reply($runtime, $providerMessages, static function (string $delta): void {
            // Streaming deltas are not surfaced in the checklist-draft JSON flow.
        });
        $parsed = bugcatcher_ai_chat_parse_draft_response($rawAssistantContent, $thread);
        $assistantReply = trim((string) ($parsed['assistant_reply'] ?? ''));
        if ($assistantReply === '') {
            $assistantReply = trim($rawAssistantContent);
        }
        $items = is_array($parsed['items'] ?? null) ? $parsed['items'] : [];

        $conn->begin_transaction();
        $stmt = $conn->prepare("
            UPDATE ai_chat_messages
            SET content = ?, status = 'completed', error_message = NULL, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param('si', $assistantReply, $assistantMessageId);
        $stmt->execute();
        $stmt->close();

        bugcatcher_ai_chat_insert_generated_items($conn, $assistantMessageId, $thread, $items);
        bugcatcher_ai_chat_touch_thread($conn, $threadId);
        $conn->commit();
    } catch (Throwable $e) {
        $friendly = $e->getMessage() !== '' ? $e->getMessage() : 'AI chat could not complete the reply. Please try again.';
        $stmt = $conn->prepare("
            UPDATE ai_chat_messages
            SET content = ?, status = 'failed', error_message = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $fallbackContent = $assistantReply !== '' ? $assistantReply : trim($rawAssistantContent);
        $stmt->bind_param('ssi', $fallbackContent, $friendly, $assistantMessageId);
        $stmt->execute();
        $stmt->close();
        bugcatcher_ai_chat_touch_thread($conn, $threadId);
    }

    $freshThread = bugcatcher_ai_chat_fetch_thread($conn, $threadId, (int) $thread['org_id'], (int) $thread['user_id']);
    if (!$freshThread) {
        throw new RuntimeException('AI chat thread not found after saving the checklist draft.');
    }

    return [
        'thread' => bugcatcher_ai_chat_thread_shape($conn, $freshThread),
        'user_message_id' => $userMessageId,
        'assistant_message_id' => $assistantMessageId,
    ];
}

function bugcatcher_ai_chat_fetch_generated_item_for_actor(mysqli $conn, int $generatedItemId, int $orgId, int $userId): ?array
{
    $stmt = $conn->prepare("
        SELECT gi.*, t.checklist_resolved_batch_id
        FROM ai_chat_generated_checklist_items gi
        JOIN ai_chat_threads t ON t.id = gi.thread_id
        WHERE gi.id = ? AND t.org_id = ? AND t.user_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('iii', $generatedItemId, $orgId, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function bugcatcher_ai_chat_fetch_generated_item_shape(mysqli $conn, int $generatedItemId, int $orgId, int $userId): array
{
    $row = bugcatcher_ai_chat_fetch_generated_item_for_actor($conn, $generatedItemId, $orgId, $userId);
    if (!$row) {
        throw new RuntimeException('Generated checklist item not found.');
    }

    $duplicateMatches = json_decode((string) ($row['duplicate_matches'] ?? '[]'), true);
    if (!is_array($duplicateMatches)) {
        $duplicateMatches = [];
    }

    return [
        'id' => (int) $row['id'],
        'project_id' => (int) $row['project_id'],
        'target_mode' => (string) $row['target_mode'],
        'target_batch_id' => isset($row['target_batch_id']) ? (int) $row['target_batch_id'] : null,
        'batch_title' => (string) $row['batch_title'],
        'module_name' => (string) $row['module_name'],
        'submodule_name' => (string) ($row['submodule_name'] ?? ''),
        'sequence_no' => (int) $row['sequence_no'],
        'title' => (string) $row['title'],
        'description' => (string) ($row['description'] ?? ''),
        'priority' => (string) ($row['priority'] ?? 'medium'),
        'required_role' => (string) ($row['required_role'] ?? 'QA Tester'),
        'review_status' => (string) ($row['review_status'] ?? 'pending'),
        'duplicate_status' => (string) ($row['duplicate_status'] ?? 'unique'),
        'duplicate_summary' => (string) ($row['duplicate_summary'] ?? ''),
        'duplicate_matches' => $duplicateMatches,
        'approved_batch_id' => isset($row['approved_batch_id']) ? (int) $row['approved_batch_id'] : null,
        'approved_item_id' => isset($row['approved_item_id']) ? (int) $row['approved_item_id'] : null,
        'approved_at' => (string) ($row['approved_at'] ?? ''),
        'rejected_at' => (string) ($row['rejected_at'] ?? ''),
        'created_at' => (string) $row['created_at'],
        'updated_at' => (string) ($row['updated_at'] ?? ''),
    ];
}

function bugcatcher_ai_chat_resolve_generated_item_batch(mysqli $conn, array $item, int $actorUserId): array
{
    $project = bugcatcher_checklist_fetch_project($conn, (int) $item['org_id'], (int) $item['project_id']);
    if (!$project) {
        throw new RuntimeException('The selected project is no longer available. Please reselect the checklist target.');
    }

    $resolvedBatchId = (int) ($item['checklist_resolved_batch_id'] ?? 0);
    if ($resolvedBatchId > 0) {
        $resolvedBatch = bugcatcher_checklist_fetch_batch($conn, (int) $item['org_id'], $resolvedBatchId);
        if (!$resolvedBatch) {
            throw new RuntimeException('The resolved checklist batch is no longer available. Please reselect the checklist target.');
        }
        return $resolvedBatch;
    }

    if ((string) ($item['target_mode'] ?? '') === 'existing') {
        $targetBatchId = (int) ($item['target_batch_id'] ?? 0);
        $batch = $targetBatchId > 0 ? bugcatcher_checklist_fetch_batch($conn, (int) $item['org_id'], $targetBatchId) : null;
        if (!$batch || (int) ($batch['project_id'] ?? 0) !== (int) $item['project_id']) {
            throw new RuntimeException('The selected checklist batch is no longer available. Please reselect the checklist target.');
        }
        return $batch;
    }

    $existing = bugcatcher_checklist_find_batch_by_exact_target(
        $conn,
        (int) $item['org_id'],
        (int) $item['project_id'],
        (string) $item['batch_title'],
        (string) $item['module_name'],
        (string) ($item['submodule_name'] ?? '')
    );
    if ($existing) {
        return $existing;
    }

    $stmt = $conn->prepare("
        INSERT INTO checklist_batches
            (org_id, project_id, title, module_name, submodule_name, status, created_by, updated_by)
        VALUES (?, ?, ?, ?, NULLIF(?, ''), 'open', ?, ?)
    ");
    $submoduleName = trim((string) ($item['submodule_name'] ?? ''));
    $stmt->bind_param(
        'iisssii',
        $item['org_id'],
        $item['project_id'],
        $item['batch_title'],
        $item['module_name'],
        $submoduleName,
        $actorUserId,
        $actorUserId
    );
    $stmt->execute();
    $batchId = (int) $stmt->insert_id;
    $stmt->close();

    $batch = bugcatcher_checklist_fetch_batch($conn, (int) $item['org_id'], $batchId);
    if (!$batch) {
        throw new RuntimeException('Checklist batch was created but could not be loaded.');
    }

    return $batch;
}

function bugcatcher_ai_chat_create_item_from_generated_item(mysqli $conn, array $generatedItem, array $batch, int $actorUserId): int
{
    $sequenceNo = bugcatcher_checklist_next_sequence($conn, (int) $batch['id']);
    $assignedToUserId = 0;
    $submoduleName = trim((string) ($generatedItem['submodule_name'] ?? ''));
    $description = trim((string) ($generatedItem['description'] ?? ''));
    $fullTitle = bugcatcher_checklist_full_title((string) $generatedItem['module_name'], $submoduleName, (string) $generatedItem['title']);
    $stmt = $conn->prepare("
        INSERT INTO checklist_items
            (batch_id, org_id, project_id, sequence_no, title, module_name, submodule_name, full_title, description,
             status, priority, required_role, assigned_to_user_id, created_by, updated_by)
        VALUES (?, ?, ?, ?, ?, ?, NULLIF(?, ''), ?, NULLIF(?, ''), 'open', ?, ?, NULLIF(?, 0), ?, ?)
    ");
    $stmt->bind_param(
        'iiiisssssssiii',
        $batch['id'],
        $generatedItem['org_id'],
        $generatedItem['project_id'],
        $sequenceNo,
        $generatedItem['title'],
        $generatedItem['module_name'],
        $submoduleName,
        $fullTitle,
        $description,
        $generatedItem['priority'],
        $generatedItem['required_role'],
        $assignedToUserId,
        $actorUserId,
        $actorUserId
    );
    $stmt->execute();
    $itemId = (int) $stmt->insert_id;
    $stmt->close();

    return $itemId;
}

function bc_v1_ai_chat_bootstrap_get(mysqli $conn, array $params): void
{
    bc_v1_require_method(['GET']);
    [, $org] = bc_v1_ai_chat_context($conn);

    try {
        $runtime = bugcatcher_ai_chat_resolve_runtime($conn);
        bc_v1_json_success([
            'enabled' => true,
            'assistant_name' => $runtime['assistant_name'],
            'provider' => [
                'id' => (int) $runtime['provider']['id'],
                'display_name' => (string) $runtime['provider']['display_name'],
            ],
            'model' => [
                'id' => (int) $runtime['model']['id'],
                'display_name' => (string) $runtime['model']['display_name'],
                'model_id' => (string) $runtime['model']['model_id'],
                'supports_vision' => (bool) ($runtime['model']['supports_vision'] ?? false),
            ],
            'org_id' => (int) $org['org_id'],
        ]);
    } catch (Throwable $e) {
        bc_v1_json_success([
            'enabled' => false,
            'assistant_name' => (string) bugcatcher_config('AI_CHAT_DEFAULT_ASSISTANT_NAME', 'BugCatcher AI'),
            'error_message' => $e->getMessage(),
            'org_id' => (int) $org['org_id'],
        ]);
    }
}

function bc_v1_ai_chat_threads_get(mysqli $conn, array $params): void
{
    bc_v1_require_method(['GET']);
    [$actor, $org] = bc_v1_ai_chat_context($conn);

    $stmt = $conn->prepare("
        SELECT *
        FROM ai_chat_threads
        WHERE org_id = ? AND user_id = ?
        ORDER BY COALESCE(updated_at, created_at) DESC, id DESC
    ");
    $userId = (int) $actor['user']['id'];
    $orgId = (int) $org['org_id'];
    $stmt->bind_param('ii', $orgId, $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    bc_v1_json_success([
        'threads' => array_map(static function (array $row) use ($conn): array {
            return [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'created_at' => (string) $row['created_at'],
                'updated_at' => (string) ($row['updated_at'] ?? ''),
                'last_message_at' => (string) ($row['last_message_at'] ?? ''),
                'draft_context' => bugcatcher_ai_chat_thread_context_shape($conn, $row),
            ];
        }, $rows),
    ]);
}

function bc_v1_ai_chat_threads_post(mysqli $conn, array $params): void
{
    bc_v1_require_method(['POST']);
    [$actor, $org] = bc_v1_ai_chat_context($conn);
    $payload = bc_v1_request_data();
    $title = trim((string) ($payload['title'] ?? ''));
    if ($title === '') {
        $title = 'New chat';
    }

    $stmt = $conn->prepare("
        INSERT INTO ai_chat_threads (org_id, user_id, title, updated_at, last_message_at)
        VALUES (?, ?, ?, NOW(), NOW())
    ");
    $orgId = (int) $org['org_id'];
    $userId = (int) $actor['user']['id'];
    $stmt->bind_param('iis', $orgId, $userId, $title);
    $stmt->execute();
    $threadId = (int) $stmt->insert_id;
    $stmt->close();

    $thread = bugcatcher_ai_chat_fetch_thread($conn, $threadId, $orgId, $userId);
    bc_v1_json_success([
        'thread' => bugcatcher_ai_chat_thread_shape($conn, $thread ?: [
            'id' => $threadId,
            'org_id' => $orgId,
            'user_id' => $userId,
            'title' => $title,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'last_message_at' => date('Y-m-d H:i:s'),
        ]),
    ], 201);
}

function bc_v1_ai_chat_threads_id_get(mysqli $conn, array $params): void
{
    bc_v1_require_method(['GET']);
    [$actor, $org] = bc_v1_ai_chat_context($conn);
    $threadId = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
    if ($threadId <= 0) {
        bc_v1_json_error(422, 'invalid_thread', 'Thread id is invalid.');
    }

    $thread = bugcatcher_ai_chat_fetch_thread($conn, $threadId, (int) $org['org_id'], (int) $actor['user']['id']);
    if (!$thread) {
        bc_v1_json_error(404, 'thread_not_found', 'AI chat thread not found.');
    }

    bc_v1_json_success([
        'thread' => bugcatcher_ai_chat_thread_shape($conn, $thread),
    ]);
}

function bc_v1_ai_chat_threads_id_delete(mysqli $conn, array $params): void
{
    bc_v1_require_method(['DELETE']);
    [$actor, $org] = bc_v1_ai_chat_context($conn);
    $threadId = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
    if ($threadId <= 0) {
        bc_v1_json_error(422, 'invalid_thread', 'Thread id is invalid.');
    }

    $thread = bugcatcher_ai_chat_fetch_thread($conn, $threadId, (int) $org['org_id'], (int) $actor['user']['id']);
    if (!$thread) {
        bc_v1_json_error(404, 'thread_not_found', 'AI chat thread not found.');
    }

    $stmt = $conn->prepare("
        SELECT a.storage_key
        FROM ai_chat_message_attachments a
        JOIN ai_chat_messages m ON m.id = a.message_id
        WHERE m.thread_id = ?
    ");
    $stmt->bind_param('i', $threadId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as $row) {
        $storageKey = (string) ($row['storage_key'] ?? '');
        if ($storageKey !== '') {
            try {
                bugcatcher_file_storage_delete($storageKey);
            } catch (Throwable $deleteError) {
                // Ignore remote cleanup failures during thread deletion.
            }
        }
    }

    $stmt = $conn->prepare("DELETE FROM ai_chat_threads WHERE id = ? AND user_id = ? AND org_id = ?");
    $orgId = (int) $org['org_id'];
    $userId = (int) $actor['user']['id'];
    $stmt->bind_param('iii', $threadId, $userId, $orgId);
    $stmt->execute();
    $stmt->close();

    bc_v1_json_success([
        'deleted' => true,
        'thread_id' => $threadId,
    ]);
}

function bc_v1_ai_chat_threads_id_draft_context_patch(mysqli $conn, array $params): void
{
    bc_v1_require_method(['PATCH']);
    [$actor, $org] = bc_v1_ai_chat_context($conn);
    $threadId = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
    if ($threadId <= 0) {
        bc_v1_json_error(422, 'invalid_thread', 'Thread id is invalid.');
    }

    $thread = bugcatcher_ai_chat_fetch_thread($conn, $threadId, (int) $org['org_id'], (int) $actor['user']['id']);
    if (!$thread) {
        bc_v1_json_error(404, 'thread_not_found', 'AI chat thread not found.');
    }

    try {
        $context = bugcatcher_ai_chat_validate_draft_context($conn, (int) $org['org_id'], bc_v1_request_data());
    } catch (Throwable $e) {
        bc_v1_json_error(422, 'invalid_draft_context', $e->getMessage());
    }

    if ((int) ($thread['checklist_resolved_batch_id'] ?? 0) > 0 && !bugcatcher_ai_chat_thread_context_matches($thread, $context)) {
        bc_v1_json_error(409, 'draft_context_locked', 'This chat already saved approved checklist items. Start a new chat to change the checklist target.');
    }

    bugcatcher_ai_chat_upsert_thread_context($conn, $threadId, $context);
    bugcatcher_ai_chat_update_thread_title_from_context($conn, $threadId, (string) $context['batch_title']);

    $freshThread = bugcatcher_ai_chat_fetch_thread($conn, $threadId, (int) $org['org_id'], (int) $actor['user']['id']);
    if (!$freshThread) {
        bc_v1_json_error(500, 'thread_reload_failed', 'Unable to reload the AI chat thread.');
    }

    bc_v1_json_success([
        'thread' => bugcatcher_ai_chat_thread_shape($conn, $freshThread),
    ]);
}

function bc_v1_ai_chat_threads_id_checklist_drafts_post(mysqli $conn, array $params): void
{
    bc_v1_require_method(['POST']);
    [$actor, $org] = bc_v1_ai_chat_context($conn);
    $threadId = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
    if ($threadId <= 0) {
        bc_v1_json_error(422, 'invalid_thread', 'Thread id is invalid.');
    }

    $thread = bugcatcher_ai_chat_fetch_thread($conn, $threadId, (int) $org['org_id'], (int) $actor['user']['id']);
    if (!$thread) {
        bc_v1_json_error(404, 'thread_not_found', 'AI chat thread not found.');
    }

    $payload = bc_v1_request_data();
    $messageText = trim((string) ($payload['message'] ?? ''));
    $hasAttachments = !empty($_FILES['attachments']) && is_array($_FILES['attachments']['name'] ?? null);

    try {
        $runtime = bugcatcher_ai_chat_resolve_runtime($conn);
        $result = bugcatcher_ai_chat_generate_checklist_draft($conn, $thread, $runtime, $messageText, $hasAttachments);
    } catch (Throwable $e) {
        bc_v1_json_error(422, 'draft_generation_failed', $e->getMessage());
    }

    bc_v1_json_success($result, 201);
}

function bc_v1_ai_chat_generated_items_id_approve_post(mysqli $conn, array $params): void
{
    bc_v1_require_method(['POST']);
    [$actor, $org] = bc_v1_ai_chat_context($conn);
    $generatedItemId = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
    if ($generatedItemId <= 0) {
        bc_v1_json_error(422, 'invalid_generated_item', 'Generated checklist item id is invalid.');
    }

    $generatedItem = bugcatcher_ai_chat_fetch_generated_item_for_actor($conn, $generatedItemId, (int) $org['org_id'], (int) $actor['user']['id']);
    if (!$generatedItem) {
        bc_v1_json_error(404, 'generated_item_not_found', 'Generated checklist item not found.');
    }
    if ((string) ($generatedItem['review_status'] ?? '') === 'approved') {
        bc_v1_json_success([
            'generated_item' => bugcatcher_ai_chat_fetch_generated_item_shape($conn, $generatedItemId, (int) $org['org_id'], (int) $actor['user']['id']),
        ]);
    }
    if ((string) ($generatedItem['review_status'] ?? '') === 'rejected') {
        bc_v1_json_error(409, 'generated_item_rejected', 'Rejected checklist items cannot be approved. Ask AI to draft a new item instead.');
    }

    $actorUserId = (int) $actor['user']['id'];

    try {
        $conn->begin_transaction();
        $batch = bugcatcher_ai_chat_resolve_generated_item_batch($conn, $generatedItem, $actorUserId);
        $itemId = bugcatcher_ai_chat_create_item_from_generated_item($conn, $generatedItem, $batch, $actorUserId);

        $stmt = $conn->prepare("
            UPDATE ai_chat_generated_checklist_items
            SET review_status = 'approved',
                approved_batch_id = ?,
                approved_item_id = ?,
                approved_by = ?,
                approved_at = NOW(),
                updated_at = NOW()
            WHERE id = ? AND review_status = 'pending'
        ");
        $batchId = (int) $batch['id'];
        $stmt->bind_param('iiii', $batchId, $itemId, $actorUserId, $generatedItemId);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("
            UPDATE ai_chat_threads
            SET checklist_resolved_batch_id = NULLIF(?, 0),
                updated_at = NOW(),
                last_message_at = NOW()
            WHERE id = ?
        ");
        $threadId = (int) $generatedItem['thread_id'];
        $stmt->bind_param('ii', $batchId, $threadId);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        bc_v1_json_error(422, 'approve_failed', $e->getMessage());
    }

    bc_v1_json_success([
        'generated_item' => bugcatcher_ai_chat_fetch_generated_item_shape($conn, $generatedItemId, (int) $org['org_id'], (int) $actor['user']['id']),
    ]);
}

function bc_v1_ai_chat_generated_items_id_reject_post(mysqli $conn, array $params): void
{
    bc_v1_require_method(['POST']);
    [$actor, $org] = bc_v1_ai_chat_context($conn);
    $generatedItemId = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
    if ($generatedItemId <= 0) {
        bc_v1_json_error(422, 'invalid_generated_item', 'Generated checklist item id is invalid.');
    }

    $generatedItem = bugcatcher_ai_chat_fetch_generated_item_for_actor($conn, $generatedItemId, (int) $org['org_id'], (int) $actor['user']['id']);
    if (!$generatedItem) {
        bc_v1_json_error(404, 'generated_item_not_found', 'Generated checklist item not found.');
    }
    if ((string) ($generatedItem['review_status'] ?? '') === 'approved') {
        bc_v1_json_error(409, 'generated_item_approved', 'Approved checklist items cannot be rejected.');
    }

    $actorUserId = (int) $actor['user']['id'];
    $stmt = $conn->prepare("
        UPDATE ai_chat_generated_checklist_items
        SET review_status = 'rejected',
            rejected_by = ?,
            rejected_at = NOW(),
            updated_at = NOW()
        WHERE id = ? AND review_status = 'pending'
    ");
    $stmt->bind_param('ii', $actorUserId, $generatedItemId);
    $stmt->execute();
    $stmt->close();

    bc_v1_json_success([
        'generated_item' => bugcatcher_ai_chat_fetch_generated_item_shape($conn, $generatedItemId, (int) $org['org_id'], (int) $actor['user']['id']),
    ]);
}

function bc_v1_ai_chat_threads_messages_stream_post(mysqli $conn, array $params): void
{
    bc_v1_require_method(['POST']);
    [$actor, $org] = bc_v1_ai_chat_context($conn);
    $threadId = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
    if ($threadId <= 0) {
        bc_v1_json_error(422, 'invalid_thread', 'Thread id is invalid.');
    }

    $thread = bugcatcher_ai_chat_fetch_thread($conn, $threadId, (int) $org['org_id'], (int) $actor['user']['id']);
    if (!$thread) {
        bc_v1_json_error(404, 'thread_not_found', 'AI chat thread not found.');
    }

    $payload = bc_v1_request_data();
    $messageText = trim((string) ($payload['message'] ?? ''));
    $hasAttachments = !empty($_FILES['attachments']) && is_array($_FILES['attachments']['name'] ?? null);

    try {
        $runtime = bugcatcher_ai_chat_resolve_runtime($conn);
        $result = bugcatcher_ai_chat_generate_checklist_draft($conn, $thread, $runtime, $messageText, $hasAttachments);
    } catch (Throwable $e) {
        bc_v1_json_error(422, 'draft_generation_failed', $e->getMessage());
    }

    bugcatcher_ai_chat_start_stream_response();
    bugcatcher_ai_chat_stream_event('start', [
        'thread_id' => $threadId,
        'user_message_id' => (int) ($result['user_message_id'] ?? 0),
        'assistant_message_id' => (int) ($result['assistant_message_id'] ?? 0),
        'assistant_name' => (string) $runtime['assistant_name'],
    ]);

    $assistantContent = '';
    foreach (($result['thread']['messages'] ?? []) as $message) {
        if ((int) ($message['id'] ?? 0) === (int) ($result['assistant_message_id'] ?? 0)) {
            $assistantContent = (string) ($message['content'] ?? '');
            break;
        }
    }
    bugcatcher_ai_chat_stream_event('done', [
        'assistant_message_id' => (int) ($result['assistant_message_id'] ?? 0),
        'content' => $assistantContent,
    ]);

    exit;
}
