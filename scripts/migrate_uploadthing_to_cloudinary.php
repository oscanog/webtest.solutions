<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

function webtest_upload_migration_usage(): void
{
    $script = basename(__FILE__);
    fwrite(STDOUT, <<<TXT
Usage:
  php scripts/{$script} [--execute] [--table=<table>] [--limit=<count>] [--delete-uploadthing-source]

Options:
  --execute                    Perform the migration. Without this flag the script runs in dry-run mode.
  --table=<table>              Limit work to one or more attachment tables.
  --limit=<count>              Stop after migrating this many rows in total.
  --delete-uploadthing-source  Best-effort delete of old UploadThing files after each successful row update.

TXT);
}

function webtest_upload_migration_targets(): array
{
    return [
        'issue_attachments' => ['scope' => 'issues'],
        'checklist_attachments' => ['scope' => 'checklist-item'],
        'checklist_batch_attachments' => ['scope' => 'checklist-batch'],
        'openclaw_request_attachments' => ['scope' => 'openclaw-request'],
        'ai_chat_message_attachments' => ['scope' => 'ai-chat'],
    ];
}

function webtest_upload_migration_tables($rawTables): array
{
    $targets = webtest_upload_migration_targets();
    if ($rawTables === false || $rawTables === null) {
        return array_keys($targets);
    }

    $requested = is_array($rawTables) ? $rawTables : [$rawTables];
    $requested = array_values(array_unique(array_filter(array_map('trim', $requested))));
    foreach ($requested as $table) {
        if (!isset($targets[$table])) {
            throw new InvalidArgumentException("Unsupported table: {$table}");
        }
    }

    return $requested;
}

function webtest_upload_migration_download(string $url): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is required to migrate UploadThing assets.');
    }

    $tempPath = tempnam(sys_get_temp_dir(), 'bc-cld-');
    if ($tempPath === false) {
        throw new RuntimeException('Failed to create a temporary download file.');
    }

    $handle = fopen($tempPath, 'wb');
    if ($handle === false) {
        @unlink($tempPath);
        throw new RuntimeException('Failed to open a temporary download file.');
    }

    $ch = curl_init($url);
    if ($ch === false) {
        fclose($handle);
        @unlink($tempPath);
        throw new RuntimeException('Failed to initialize an UploadThing download request.');
    }

    curl_setopt_array($ch, [
        CURLOPT_FILE => $handle,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_USERAGENT => 'WebTest Upload Migration',
    ]);

    $ok = curl_exec($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    fclose($handle);

    if ($ok !== true || $statusCode < 200 || $statusCode >= 300) {
        @unlink($tempPath);
        $message = $error !== '' ? $error : "Download failed with HTTP {$statusCode}.";
        throw new RuntimeException($message);
    }

    $size = filesize($tempPath);
    if ($size === false || $size <= 0) {
        @unlink($tempPath);
        throw new RuntimeException('Downloaded file is empty.');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = is_resource($finfo) ? (string) finfo_file($finfo, $tempPath) : '';
    if (is_resource($finfo)) {
        finfo_close($finfo);
    }

    return [
        'path' => $tempPath,
        'size' => (int) $size,
        'mime_type' => $mimeType !== '' ? $mimeType : 'application/octet-stream',
    ];
}

function webtest_upload_migration_fetch_rows(mysqli $conn, string $table): array
{
    $sql = "
        SELECT id, file_path, storage_key, storage_provider, original_name, mime_type, file_size
        FROM {$table}
        WHERE storage_key IS NOT NULL
          AND TRIM(storage_key) <> ''
        ORDER BY id ASC
    ";
    $result = $conn->query($sql);
    if ($result === false) {
        throw new RuntimeException("Failed to read rows from {$table}: " . $conn->error);
    }

    return $result->fetch_all(MYSQLI_ASSOC);
}

function webtest_upload_migration_update_row(mysqli $conn, string $table, int $id, array $stored): void
{
    $stmt = $conn->prepare("
        UPDATE {$table}
        SET file_path = ?, storage_key = ?, storage_provider = ?
        WHERE id = ?
    ");
    if ($stmt === false) {
        throw new RuntimeException("Failed to prepare update for {$table}: " . $conn->error);
    }

    $filePath = (string) ($stored['file_path'] ?? '');
    $storageKey = (string) ($stored['storage_key'] ?? '');
    $storageProvider = (string) ($stored['storage_provider'] ?? 'cloudinary');
    $stmt->bind_param('sssi', $filePath, $storageKey, $storageProvider, $id);
    $stmt->execute();
    $stmt->close();
}

$options = getopt('', [
    'execute',
    'table:',
    'limit:',
    'delete-uploadthing-source',
    'help',
]);

if (isset($options['help'])) {
    webtest_upload_migration_usage();
    exit(0);
}

$execute = isset($options['execute']);
$limit = isset($options['limit']) ? max(0, (int) $options['limit']) : 0;
$deleteUploadThingSource = isset($options['delete-uploadthing-source']);

try {
    $tables = webtest_upload_migration_tables($options['table'] ?? false);
    $conn = webtest_db_connection();
    webtest_file_storage_ensure_schema($conn);

    if ($execute && !webtest_file_storage_is_cloudinary_enabled()) {
        throw new RuntimeException('Cloudinary credentials are required before running with --execute.');
    }

    fwrite(STDOUT, sprintf(
        "[upload-migration] mode=%s tables=%s limit=%s delete_uploadthing_source=%s\n",
        $execute ? 'execute' : 'dry-run',
        implode(',', $tables),
        $limit > 0 ? (string) $limit : 'none',
        $deleteUploadThingSource ? 'true' : 'false'
    ));

    $processed = 0;
    $planned = 0;
    $migrated = 0;
    $skipped = 0;
    $failed = 0;

    foreach ($tables as $table) {
        $target = webtest_upload_migration_targets()[$table];
        $rows = webtest_upload_migration_fetch_rows($conn, $table);

        foreach ($rows as $row) {
            if ($limit > 0 && $processed >= $limit) {
                break 2;
            }

            $processed++;
            $provider = webtest_file_storage_provider_from_row($row);
            if ($provider !== 'uploadthing') {
                $skipped++;
                fwrite(STDOUT, "[upload-migration] skip table={$table} id={$row['id']} provider=" . ($provider !== '' ? $provider : 'unknown') . "\n");
                continue;
            }

            $originalKey = (string) ($row['storage_key'] ?? '');
            $originalPath = (string) ($row['file_path'] ?? '');
            $originalName = trim((string) ($row['original_name'] ?? 'attachment'));
            $mimeType = trim((string) ($row['mime_type'] ?? 'application/octet-stream'));

            if (!$execute) {
                $planned++;
                fwrite(STDOUT, "[upload-migration] dry-run table={$table} id={$row['id']} key={$originalKey}\n");
                continue;
            }

            $download = null;
            $stored = null;
            try {
                $download = webtest_upload_migration_download($originalPath);
                $stored = webtest_file_storage_upload_file(
                    (string) $download['path'],
                    $originalName !== '' ? $originalName : 'attachment',
                    $mimeType !== '' ? $mimeType : (string) $download['mime_type'],
                    (int) $download['size'],
                    (string) $target['scope']
                );

                webtest_upload_migration_update_row($conn, $table, (int) $row['id'], $stored);

                if ($deleteUploadThingSource && $originalKey !== '') {
                    try {
                        webtest_file_storage_delete($originalKey, $originalPath, 'uploadthing', $mimeType);
                    } catch (Throwable $deleteError) {
                        fwrite(STDOUT, "[upload-migration] warn table={$table} id={$row['id']} uploadthing_delete=" . $deleteError->getMessage() . "\n");
                    }
                }

                $migrated++;
                fwrite(STDOUT, "[upload-migration] migrated table={$table} id={$row['id']} old_key={$originalKey} new_key=" . (string) ($stored['storage_key'] ?? '') . "\n");
            } catch (Throwable $e) {
                $failed++;
                if (is_array($stored) && trim((string) ($stored['storage_key'] ?? '')) !== '') {
                    try {
                        webtest_file_storage_delete(
                            (string) $stored['storage_key'],
                            (string) ($stored['file_path'] ?? ''),
                            (string) ($stored['storage_provider'] ?? 'cloudinary'),
                            (string) ($stored['mime_type'] ?? $mimeType)
                        );
                    } catch (Throwable $cleanupError) {
                        fwrite(STDOUT, "[upload-migration] warn table={$table} id={$row['id']} cleanup=" . $cleanupError->getMessage() . "\n");
                    }
                }

                fwrite(STDOUT, "[upload-migration] fail table={$table} id={$row['id']} error=" . $e->getMessage() . "\n");
            } finally {
                if (is_array($download) && isset($download['path']) && is_string($download['path']) && is_file($download['path'])) {
                    @unlink($download['path']);
                }
            }
        }
    }

    fwrite(STDOUT, sprintf(
        "[upload-migration] summary processed=%d planned=%d migrated=%d skipped=%d failed=%d\n",
        $processed,
        $planned,
        $migrated,
        $skipped,
        $failed
    ));

    exit($failed > 0 ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, "[upload-migration] fatal " . $e->getMessage() . PHP_EOL);
    exit(1);
}
