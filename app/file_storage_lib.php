<?php

declare(strict_types=1);

function webtest_file_storage_attachment_targets(): array
{
    return [
        ['table' => 'issue_attachments', 'id_column' => 'id'],
        ['table' => 'checklist_attachments', 'id_column' => 'id'],
        ['table' => 'checklist_batch_attachments', 'id_column' => 'id'],
        ['table' => 'openclaw_request_attachments', 'id_column' => 'id'],
        ['table' => 'ai_chat_message_attachments', 'id_column' => 'id'],
    ];
}

function webtest_file_storage_active_provider(): string
{
    if (webtest_file_storage_is_cloudinary_enabled()) {
        return 'cloudinary';
    }

    return '';
}

function webtest_file_storage_is_remote_enabled(): bool
{
    return webtest_file_storage_active_provider() !== '';
}

function webtest_file_storage_is_cloudinary_enabled(): bool
{
    return webtest_file_storage_cloudinary_cloud_name() !== ''
        && webtest_file_storage_cloudinary_api_key() !== ''
        && webtest_file_storage_cloudinary_api_secret() !== '';
}

function webtest_file_storage_cloudinary_cloud_name(): string
{
    return trim((string) webtest_config('CLOUDINARY_CLOUD_NAME', ''));
}

function webtest_file_storage_cloudinary_api_key(): string
{
    return trim((string) webtest_config('CLOUDINARY_API_KEY', ''));
}

function webtest_file_storage_cloudinary_api_secret(): string
{
    return trim((string) webtest_config('CLOUDINARY_API_SECRET', ''));
}

function webtest_file_storage_cloudinary_base_folder(): string
{
    $folder = trim(str_replace('\\', '/', (string) webtest_config('CLOUDINARY_BASE_FOLDER', 'webtest')), " /");
    return $folder !== '' ? $folder : 'webtest';
}

function webtest_file_storage_cloudinary_api_url(string $resourceType, string $action): string
{
    $resourceType = in_array($resourceType, ['image', 'video', 'raw', 'auto'], true) ? $resourceType : 'auto';
    return 'https://api.cloudinary.com/v1_1/'
        . rawurlencode(webtest_file_storage_cloudinary_cloud_name())
        . '/'
        . $resourceType
        . '/'
        . ltrim($action, '/');
}

function webtest_file_storage_cloudinary_auth_headers(): array
{
    $token = base64_encode(
        webtest_file_storage_cloudinary_api_key() . ':' . webtest_file_storage_cloudinary_api_secret()
    );

    return [
        'Accept: application/json',
        'Authorization: Basic ' . $token,
    ];
}

function webtest_file_storage_uploadthing_token(): string
{
    return trim((string) webtest_config('UPLOADTHING_TOKEN', ''));
}

function webtest_file_storage_uploadthing_api_key(): string
{
    $token = webtest_file_storage_uploadthing_token();
    if ($token === '') {
        return '';
    }

    $decoded = base64_decode($token, true);
    if (!is_string($decoded) || $decoded === '') {
        return '';
    }

    $payload = json_decode($decoded, true);
    if (!is_array($payload)) {
        return '';
    }

    return trim((string) ($payload['apiKey'] ?? ''));
}

function webtest_file_storage_http_request(
    string $method,
    string $url,
    array $headers = [],
    $body = null,
    ?string &$responseBody = null
): int {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is required for remote file storage.');
    }

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Unable to initialize remote file storage request.');
    }

    $curlHeaders = array_merge(['Accept: application/json'], $headers);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $curlHeaders,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $result = curl_exec($ch);
    if (!is_string($result)) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException($error !== '' ? $error : 'Remote file storage request failed.');
    }

    $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $responseBody = $result;

    return $statusCode;
}

function webtest_file_storage_parse_json_response(int $statusCode, string $responseBody, string $fallback): array
{
    $trimmed = trim($responseBody);
    if ($trimmed === '') {
        if ($statusCode >= 200 && $statusCode < 300) {
            return [];
        }

        throw new RuntimeException($fallback);
    }

    $decoded = json_decode($trimmed, true);
    if (!is_array($decoded)) {
        throw new RuntimeException($fallback);
    }

    if ($statusCode < 200 || $statusCode >= 300) {
        $message = trim((string) (($decoded['error']['message'] ?? '') ?: ($decoded['message'] ?? '') ?: $fallback));
        throw new RuntimeException($message !== '' ? $message : $fallback);
    }

    return $decoded;
}

function webtest_file_storage_normalize_provider(?string $storageProvider): string
{
    $provider = strtolower(trim((string) $storageProvider));
    if (in_array($provider, ['cloudinary', 'uploadthing'], true)) {
        return $provider;
    }

    return '';
}

function webtest_file_storage_detect_provider(
    ?string $storageProvider,
    ?string $storageKey,
    ?string $filePath
): string {
    $normalized = webtest_file_storage_normalize_provider($storageProvider);
    if ($normalized !== '') {
        return $normalized;
    }

    $storageKey = trim((string) $storageKey);
    if ($storageKey === '') {
        return '';
    }

    $path = strtolower(trim((string) $filePath));
    if ($path !== '') {
        if (strpos($path, 'res.cloudinary.com/') !== false || strpos($path, 'cloudinary.com/') !== false) {
            return 'cloudinary';
        }
        if (
            strpos($path, 'utfs.io/') !== false
            || strpos($path, 'ufs.sh/') !== false
            || strpos($path, 'uploadthing') !== false
        ) {
            return 'uploadthing';
        }
    }

    return $path === '' ? webtest_file_storage_active_provider() : '';
}

function webtest_file_storage_provider_from_row(array $row): string
{
    return webtest_file_storage_detect_provider(
        (string) ($row['storage_provider'] ?? ''),
        (string) ($row['storage_key'] ?? ''),
        (string) ($row['file_path'] ?? '')
    );
}

function webtest_file_storage_normalize_scope(string $scope): string
{
    $scope = strtolower(trim($scope));
    if ($scope === '') {
        return 'misc';
    }

    $scope = preg_replace('/[^a-z0-9._-]+/', '-', $scope);
    $scope = trim((string) $scope, '-');
    return $scope !== '' ? substr($scope, 0, 60) : 'misc';
}

function webtest_file_storage_generate_public_id(string $originalName): string
{
    $baseName = pathinfo($originalName, PATHINFO_FILENAME);
    $baseName = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $baseName);
    $baseName = trim((string) $baseName, '-_.');
    if ($baseName === '') {
        $baseName = 'file';
    }

    $baseName = substr($baseName, 0, 80);
    return $baseName . '-' . bin2hex(random_bytes(6));
}

function webtest_file_storage_local_root(string $scope): array
{
    $normalizedScope = webtest_file_storage_normalize_scope($scope);
    if (strpos($normalizedScope, 'checklist') === 0) {
        return [
            'dir' => webtest_checklist_uploads_dir(),
            'url_prefix' => webtest_checklist_upload_path_prefix(),
        ];
    }

    return [
        'dir' => webtest_uploads_dir(),
        'url_prefix' => webtest_upload_path_prefix(),
    ];
}

function webtest_file_storage_local_move_file(string $sourcePath, string $destinationPath): bool
{
    if (is_uploaded_file($sourcePath) && @move_uploaded_file($sourcePath, $destinationPath)) {
        @chmod($destinationPath, 0664);
        return true;
    }

    if (@rename($sourcePath, $destinationPath)) {
        @chmod($destinationPath, 0664);
        return true;
    }

    if (!@copy($sourcePath, $destinationPath)) {
        return false;
    }

    if (!@unlink($sourcePath)) {
        @unlink($destinationPath);
        return false;
    }

    @chmod($destinationPath, 0664);
    return true;
}

function webtest_file_storage_local_upload_file(
    string $tmpPath,
    string $originalName,
    string $mimeType,
    int $size,
    string $scope
): array {
    $root = webtest_file_storage_local_root($scope);
    $baseDir = rtrim((string) ($root['dir'] ?? ''), "\\/");
    $urlPrefix = trim((string) ($root['url_prefix'] ?? ''), '/');
    if ($baseDir === '' || $urlPrefix === '') {
        throw new RuntimeException('Local file storage path is not configured.');
    }

    $scopeDirName = webtest_file_storage_normalize_scope($scope);
    $targetDir = $baseDir . DIRECTORY_SEPARATOR . $scopeDirName;
    if (!is_dir($targetDir) && !@mkdir($targetDir, 02775, true) && !is_dir($targetDir)) {
        throw new RuntimeException('Unable to create local upload directory.');
    }

    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($extension === '') {
        $guessedExtension = strtolower((string) pathinfo(parse_url($mimeType, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
        $extension = $guessedExtension !== '' ? $guessedExtension : 'bin';
    }

    $generatedName = webtest_file_storage_generate_public_id($originalName);
    $targetName = $generatedName . ($extension !== '' ? '.' . $extension : '');
    $targetPath = $targetDir . DIRECTORY_SEPARATOR . $targetName;
    if (!webtest_file_storage_local_move_file($tmpPath, $targetPath)) {
        throw new RuntimeException('Unable to store uploaded file locally.');
    }

    return [
        'file_path' => $urlPrefix . '/' . $scopeDirName . '/' . $targetName,
        'storage_key' => '',
        'storage_provider' => '',
        'original_name' => $originalName,
        'mime_type' => $mimeType !== '' ? $mimeType : 'application/octet-stream',
        'file_size' => $size,
    ];
}

function webtest_file_storage_cloudinary_upload_file(
    string $tmpPath,
    string $originalName,
    string $mimeType,
    int $size,
    string $scope
): array {
    if (!webtest_file_storage_is_cloudinary_enabled()) {
        throw new RuntimeException('Cloudinary storage is not configured.');
    }

    $folder = webtest_file_storage_cloudinary_base_folder() . '/' . webtest_file_storage_normalize_scope($scope);
    $responseBody = '';
    $statusCode = webtest_file_storage_http_request(
        'POST',
        webtest_file_storage_cloudinary_api_url('auto', 'upload'),
        webtest_file_storage_cloudinary_auth_headers(),
        [
            'file' => curl_file_create($tmpPath, $mimeType, $originalName),
            'folder' => $folder,
            'public_id' => webtest_file_storage_generate_public_id($originalName),
            'overwrite' => 'false',
            'unique_filename' => 'false',
            'use_filename' => 'false',
        ],
        $responseBody
    );

    $data = webtest_file_storage_parse_json_response($statusCode, $responseBody, 'Cloudinary upload failed.');
    $filePath = trim((string) ($data['secure_url'] ?? $data['url'] ?? ''));
    $storageKey = trim((string) ($data['public_id'] ?? ''));
    if ($filePath === '' || $storageKey === '') {
        throw new RuntimeException('Cloudinary upload did not return file metadata.');
    }

    return [
        'file_path' => $filePath,
        'storage_key' => $storageKey,
        'storage_provider' => 'cloudinary',
        'original_name' => $originalName,
        'mime_type' => trim((string) ($data['resource_type'] ?? '')) === 'raw'
            ? ($mimeType !== '' ? $mimeType : 'application/octet-stream')
            : ($mimeType !== '' ? $mimeType : (string) ($data['format'] ?? 'application/octet-stream')),
        'file_size' => isset($data['bytes']) ? (int) $data['bytes'] : $size,
    ];
}

function webtest_file_storage_cloudinary_detect_resource_type(?string $filePath, ?string $mimeType = null): string
{
    $path = strtolower(trim((string) $filePath));
    if (strpos($path, '/image/upload/') !== false) {
        return 'image';
    }
    if (strpos($path, '/video/upload/') !== false) {
        return 'video';
    }
    if (strpos($path, '/raw/upload/') !== false) {
        return 'raw';
    }

    $mimeType = strtolower(trim((string) $mimeType));
    if (strpos($mimeType, 'image/') === 0) {
        return 'image';
    }
    if (strpos($mimeType, 'video/') === 0) {
        return 'video';
    }

    return 'raw';
}

function webtest_file_storage_cloudinary_candidate_resource_types(?string $filePath, ?string $mimeType = null): array
{
    $detected = webtest_file_storage_cloudinary_detect_resource_type($filePath, $mimeType);
    return array_values(array_unique(array_filter([$detected, 'image', 'video', 'raw'])));
}

function webtest_file_storage_cloudinary_delete(
    string $storageKey,
    ?string $filePath = null,
    ?string $mimeType = null
): void {
    if (!webtest_file_storage_is_cloudinary_enabled()) {
        return;
    }

    $lastError = null;
    foreach (webtest_file_storage_cloudinary_candidate_resource_types($filePath, $mimeType) as $resourceType) {
        $responseBody = '';
        try {
            $statusCode = webtest_file_storage_http_request(
                'POST',
                webtest_file_storage_cloudinary_api_url($resourceType, 'destroy'),
                webtest_file_storage_cloudinary_auth_headers(),
                [
                    'public_id' => $storageKey,
                    'invalidate' => 'true',
                ],
                $responseBody
            );
            $data = webtest_file_storage_parse_json_response($statusCode, $responseBody, 'Cloudinary delete failed.');
            $result = strtolower(trim((string) ($data['result'] ?? '')));
            if ($result === 'ok') {
                return;
            }
            if ($result === 'not found') {
                $lastError = null;
                continue;
            }

            return;
        } catch (Throwable $e) {
            $lastError = $e;
        }
    }

    if ($lastError !== null) {
        throw $lastError;
    }
}

function webtest_file_storage_uploadthing_delete(string $storageKey): void
{
    $apiKey = webtest_file_storage_uploadthing_api_key();
    if ($apiKey === '') {
        return;
    }

    $payload = json_encode([
        'fileKeys' => [$storageKey],
    ]);
    if (!is_string($payload)) {
        throw new RuntimeException('Failed to encode UploadThing delete request.');
    }

    $responseBody = '';
    $statusCode = webtest_file_storage_http_request(
        'POST',
        'https://api.uploadthing.com/v6/deleteFiles',
        [
            'Content-Type: application/json',
            'x-uploadthing-api-key: ' . $apiKey,
        ],
        $payload,
        $responseBody
    );

    if ($statusCode >= 200 && $statusCode < 300) {
        return;
    }

    $decoded = json_decode($responseBody, true);
    $message = is_array($decoded)
        ? trim((string) (($decoded['error']['message'] ?? '') ?: ($decoded['message'] ?? '')))
        : '';

    throw new RuntimeException($message !== '' ? $message : 'UploadThing delete failed.');
}

function webtest_file_storage_upload_file(
    string $tmpPath,
    string $originalName,
    string $mimeType,
    int $size,
    string $scope
): array {
    if (!is_file($tmpPath)) {
        throw new RuntimeException('Upload source file was not found.');
    }

    if (!webtest_file_storage_is_remote_enabled()) {
        return webtest_file_storage_local_upload_file($tmpPath, $originalName, $mimeType, $size, $scope);
    }

    return webtest_file_storage_cloudinary_upload_file($tmpPath, $originalName, $mimeType, $size, $scope);
}

function webtest_file_storage_delete(
    ?string $storageKey,
    ?string $filePath = null,
    ?string $storageProvider = null,
    ?string $mimeType = null
): void {
    $storageKey = trim((string) $storageKey);
    if ($storageKey === '') {
        return;
    }

    $provider = webtest_file_storage_detect_provider($storageProvider, $storageKey, $filePath);
    if ($provider === 'cloudinary') {
        webtest_file_storage_cloudinary_delete($storageKey, $filePath, $mimeType);
        return;
    }
    if ($provider === 'uploadthing') {
        webtest_file_storage_uploadthing_delete($storageKey);
    }
}

function webtest_file_storage_stmt_bind_params(mysqli_stmt $stmt, string $types, array $params): void
{
    $refs = [];
    foreach ($params as $key => $value) {
        $refs[$key] = &$params[$key];
    }

    array_unshift($refs, $types);
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function webtest_file_storage_has_reference(
    mysqli $conn,
    string $storageKey,
    ?string $ignoreTable = null,
    ?int $ignoreId = null,
    ?string $storageProvider = null
): bool {
    $storageKey = trim($storageKey);
    if ($storageKey === '') {
        return false;
    }

    $normalizedProvider = webtest_file_storage_normalize_provider($storageProvider);
    foreach (webtest_file_storage_attachment_targets() as $target) {
        if (!webtest_db_has_table($conn, $target['table'])) {
            continue;
        }

        if (!webtest_db_has_column($conn, $target['table'], 'storage_key')) {
            continue;
        }

        $hasProviderColumn = webtest_db_has_column($conn, $target['table'], 'storage_provider');
        $sql = "
            SELECT 1
            FROM {$target['table']}
            WHERE storage_key = ?
        ";
        $types = 's';
        $params = [$storageKey];

        if ($normalizedProvider !== '' && $hasProviderColumn) {
            $sql .= " AND (storage_provider = ? OR storage_provider IS NULL OR storage_provider = '')";
            $types .= 's';
            $params[] = $normalizedProvider;
        }

        if ($ignoreTable === $target['table'] && $ignoreId !== null && $ignoreId > 0) {
            $sql .= " AND {$target['id_column']} <> ?";
            $types .= 'i';
            $params[] = $ignoreId;
        }

        $sql .= " LIMIT 1";
        $stmt = $conn->prepare($sql);
        webtest_file_storage_stmt_bind_params($stmt, $types, $params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            return true;
        }
    }

    return false;
}

function webtest_file_storage_delete_if_unreferenced(
    mysqli $conn,
    ?string $storageKey,
    ?string $ignoreTable = null,
    ?int $ignoreId = null,
    ?string $filePath = null,
    ?string $storageProvider = null,
    ?string $mimeType = null
): void {
    $storageKey = trim((string) $storageKey);
    if ($storageKey === '') {
        return;
    }

    $provider = webtest_file_storage_detect_provider($storageProvider, $storageKey, $filePath);
    if ($provider === '') {
        return;
    }

    if (webtest_file_storage_has_reference($conn, $storageKey, $ignoreTable, $ignoreId, $provider)) {
        return;
    }

    webtest_file_storage_delete($storageKey, $filePath, $provider, $mimeType);
}

function webtest_file_storage_delete_legacy_local(?string $absolutePath): void
{
    if ($absolutePath !== null && is_file($absolutePath)) {
        @unlink($absolutePath);
    }
}

function webtest_db_has_column(mysqli $conn, string $table, string $column): bool
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

function webtest_db_has_table(mysqli $conn, string $table): bool
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

function webtest_file_storage_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }

    foreach (webtest_file_storage_attachment_targets() as $target) {
        $table = $target['table'];
        if (!webtest_db_has_table($conn, $table)) {
            continue;
        }
        if (!webtest_db_has_column($conn, $table, 'storage_key')) {
            $conn->query("ALTER TABLE {$table} ADD COLUMN storage_key VARCHAR(255) DEFAULT NULL AFTER file_path");
        }
        if (!webtest_db_has_column($conn, $table, 'storage_provider')) {
            $conn->query("ALTER TABLE {$table} ADD COLUMN storage_provider VARCHAR(32) DEFAULT NULL AFTER storage_key");
        }
    }

    $done = true;
}
