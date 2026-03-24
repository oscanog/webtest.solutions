<?php

declare(strict_types=1);

function bugcatcher_file_storage_is_remote_enabled(): bool
{
    return (bool) bugcatcher_config('UPLOADTHING_ENABLED', false)
        && trim((string) bugcatcher_config('UPLOADTHING_TOKEN', '')) !== '';
}

function bugcatcher_file_storage_bridge_base_url(): string
{
    $host = (string) bugcatcher_config('UPLOADTHING_BRIDGE_HOST', '127.0.0.1');
    $port = (int) bugcatcher_config('UPLOADTHING_BRIDGE_PORT', 8091);
    return 'http://' . $host . ':' . $port;
}

function bugcatcher_file_storage_bridge_secret(): string
{
    return trim((string) bugcatcher_config('UPLOADTHING_BRIDGE_INTERNAL_SHARED_SECRET', ''));
}

function bugcatcher_file_storage_request(
    string $method,
    string $path,
    array $headers = [],
    $body = null,
    ?string &$responseBody = null
): int {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is required for UploadThing storage.');
    }

    $url = rtrim(bugcatcher_file_storage_bridge_base_url(), '/') . '/' . ltrim($path, '/');
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Unable to initialize UploadThing bridge request.');
    }

    $secret = bugcatcher_file_storage_bridge_secret();
    if ($secret === '') {
        throw new RuntimeException('UploadThing bridge secret is not configured.');
    }

    $curlHeaders = array_merge([
        'Accept: application/json',
        'Authorization: Bearer ' . $secret,
    ], $headers);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $curlHeaders,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 120,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $result = curl_exec($ch);
    if (!is_string($result)) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException($error !== '' ? $error : 'UploadThing bridge request failed.');
    }

    $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $responseBody = $result;

    return $statusCode;
}

function bugcatcher_file_storage_parse_json_response(int $statusCode, string $responseBody): array
{
    $decoded = json_decode($responseBody, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('UploadThing bridge returned an invalid response.');
    }

    if ($statusCode < 200 || $statusCode >= 300 || !($decoded['ok'] ?? false)) {
        $message = trim((string) (($decoded['error']['message'] ?? '') ?: 'UploadThing bridge request failed.'));
        throw new RuntimeException($message);
    }

    return $decoded['data'] ?? [];
}

function bugcatcher_file_storage_upload_file(
    string $tmpPath,
    string $originalName,
    string $mimeType,
    int $size,
    string $scope
): array {
    if (!bugcatcher_file_storage_is_remote_enabled()) {
        throw new RuntimeException('UploadThing storage is not configured.');
    }
    if (!is_file($tmpPath)) {
        throw new RuntimeException('Upload source file was not found.');
    }

    $responseBody = '';
    $statusCode = bugcatcher_file_storage_request(
        'POST',
        '/internal/upload',
        [],
        [
            'scope' => $scope,
            'files[]' => curl_file_create($tmpPath, $mimeType, $originalName),
        ],
        $responseBody
    );
    $data = bugcatcher_file_storage_parse_json_response($statusCode, $responseBody);
    $file = $data['files'][0] ?? null;
    if (!is_array($file) || trim((string) ($file['url'] ?? '')) === '' || trim((string) ($file['key'] ?? '')) === '') {
        throw new RuntimeException('UploadThing bridge did not return file metadata.');
    }

    return [
        'file_path' => (string) $file['url'],
        'storage_key' => (string) $file['key'],
        'original_name' => (string) ($file['name'] ?? $originalName),
        'mime_type' => (string) ($file['type'] ?? $mimeType),
        'file_size' => isset($file['size']) ? (int) $file['size'] : $size,
    ];
}

function bugcatcher_file_storage_delete(?string $storageKey): void
{
    $storageKey = trim((string) $storageKey);
    if ($storageKey === '' || !bugcatcher_file_storage_is_remote_enabled()) {
        return;
    }

    $responseBody = '';
    $statusCode = bugcatcher_file_storage_request(
        'DELETE',
        '/internal/files',
        ['Content-Type: application/json'],
        json_encode(['keys' => [$storageKey]]),
        $responseBody
    );
    bugcatcher_file_storage_parse_json_response($statusCode, $responseBody);
}

function bugcatcher_file_storage_has_reference(mysqli $conn, string $storageKey, ?string $ignoreTable = null, ?int $ignoreId = null): bool
{
    $storageKey = trim($storageKey);
    if ($storageKey === '') {
        return false;
    }

    $targets = [
        ['table' => 'issue_attachments', 'id_column' => 'id'],
        ['table' => 'checklist_attachments', 'id_column' => 'id'],
        ['table' => 'checklist_batch_attachments', 'id_column' => 'id'],
        ['table' => 'openclaw_request_attachments', 'id_column' => 'id'],
        ['table' => 'ai_chat_message_attachments', 'id_column' => 'id'],
    ];

    foreach ($targets as $target) {
        if (!bugcatcher_db_has_table($conn, $target['table'])) {
            continue;
        }

        if (!bugcatcher_db_has_column($conn, $target['table'], 'storage_key')) {
            continue;
        }

        if ($ignoreTable === $target['table'] && $ignoreId !== null && $ignoreId > 0) {
            $stmt = $conn->prepare("
                SELECT 1
                FROM {$target['table']}
                WHERE storage_key = ?
                  AND {$target['id_column']} <> ?
                LIMIT 1
            ");
            $stmt->bind_param('si', $storageKey, $ignoreId);
        } else {
            $stmt = $conn->prepare("
                SELECT 1
                FROM {$target['table']}
                WHERE storage_key = ?
                LIMIT 1
            ");
            $stmt->bind_param('s', $storageKey);
        }

        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            return true;
        }
    }

    return false;
}

function bugcatcher_file_storage_delete_if_unreferenced(
    mysqli $conn,
    ?string $storageKey,
    ?string $ignoreTable = null,
    ?int $ignoreId = null
): void {
    $storageKey = trim((string) $storageKey);
    if ($storageKey === '' || !bugcatcher_file_storage_is_remote_enabled()) {
        return;
    }

    if (bugcatcher_file_storage_has_reference($conn, $storageKey, $ignoreTable, $ignoreId)) {
        return;
    }

    bugcatcher_file_storage_delete($storageKey);
}

function bugcatcher_file_storage_delete_legacy_local(?string $absolutePath): void
{
    if ($absolutePath !== null && is_file($absolutePath)) {
        @unlink($absolutePath);
    }
}

function bugcatcher_db_has_column(mysqli $conn, string $table, string $column): bool
{
    $sql = "
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (bool) $row;
}

function bugcatcher_db_has_table(mysqli $conn, string $table): bool
{
    $sql = "
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (bool) $row;
}

function bugcatcher_file_storage_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $targets = [
        'issue_attachments',
        'checklist_attachments',
        'checklist_batch_attachments',
        'openclaw_request_attachments',
        'ai_chat_message_attachments',
    ];

    foreach ($targets as $table) {
        if (!bugcatcher_db_has_table($conn, $table)) {
            continue;
        }
        if (!bugcatcher_db_has_column($conn, $table, 'storage_key')) {
            $conn->query("ALTER TABLE {$table} ADD COLUMN storage_key VARCHAR(255) DEFAULT NULL AFTER file_path");
        }
    }

    $done = true;
}
