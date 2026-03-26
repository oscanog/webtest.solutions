<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$root = getenv('BUGCATCHER_ROOT') ?: dirname(__DIR__);
$root = str_replace('\\', '/', $root);
$configPath = getenv('BUGCATCHER_CONFIG_PATH') ?: '';
$sharedPassword = (string) getenv('BUGCATCHER_VALIDATION_SHARED_PASSWORD');
$allowReset = getenv('BUGCATCHER_ALLOW_DESTRUCTIVE_RESET') === '1';

if (!$allowReset) {
    fwrite(STDERR, "BUGCATCHER_ALLOW_DESTRUCTIVE_RESET=1 is required.\n");
    exit(1);
}

if ($sharedPassword === '') {
    fwrite(STDERR, "BUGCATCHER_VALIDATION_SHARED_PASSWORD is required.\n");
    exit(1);
}

if ($configPath === '' || !is_file($configPath)) {
    fwrite(STDERR, "Config file not found: {$configPath}\n");
    exit(1);
}

$config = require $configPath;
if (!is_array($config)) {
    fwrite(STDERR, "Config file must return an array.\n");
    exit(1);
}

$appEnv = strtolower(trim((string) ($config['APP_ENV'] ?? 'development')));
if ($appEnv !== 'production') {
    fwrite(STDERR, "Refusing to reset a non-production app config (APP_ENV={$appEnv}).\n");
    exit(1);
}

$dbHost = (string) ($config['DB_HOST'] ?? '127.0.0.1');
$dbPort = (int) ($config['DB_PORT'] ?? 3306);
$dbName = (string) ($config['DB_NAME'] ?? 'bug_catcher');
$dbUser = (string) ($config['DB_USER'] ?? 'root');
$dbPass = (string) ($config['DB_PASS'] ?? '');

$schemaPath = $root . '/infra/database/schema.sql';
$referenceSeedPath = $root . '/infra/database/seed_reference_data.sql';
if (!is_file($schemaPath) || !is_file($referenceSeedPath)) {
    fwrite(STDERR, "Schema or reference seed file is missing.\n");
    exit(1);
}

$backupRoot = trim((string) getenv('BUGCATCHER_BACKUP_DIR'));
if ($backupRoot === '') {
    $home = trim((string) getenv('HOME'));
    $backupRoot = $home !== '' ? $home . '/bugcatcher-db-backups' : ($root . '/.db-backups');
}

if (!is_dir($backupRoot) && !mkdir($backupRoot, 0775, true) && !is_dir($backupRoot)) {
    fwrite(STDERR, "Unable to create backup directory: {$backupRoot}\n");
    exit(1);
}

$timestamp = gmdate('Ymd-His');
$backupPath = rtrim(str_replace('\\', '/', $backupRoot), '/') . "/bug_catcher-production-{$timestamp}.sql.gz";

$accounts = [
    [
        'env_prefix' => 'SUPER_ADMIN',
        'username' => 'm.viner001',
        'email' => 'm.viner001@gmail.com',
        'system_role' => 'super_admin',
        'org_role' => 'owner',
    ],
    [
        'env_prefix' => 'ADMIN',
        'username' => 'mackrafanan9247',
        'email' => 'mackrafanan9247@gmail.com',
        'system_role' => 'admin',
        'org_role' => 'member',
    ],
    [
        'env_prefix' => 'QA_LEAD',
        'username' => 'emmanuelmagnosulit',
        'email' => 'emmanuelmagnosulit@gmail.com',
        'system_role' => 'user',
        'org_role' => 'QA Lead',
    ],
    [
        'env_prefix' => 'QA_TESTER',
        'username' => '52310085',
        'email' => '52310085@gendejesus.edu.ph',
        'system_role' => 'user',
        'org_role' => 'QA Tester',
    ],
    [
        'env_prefix' => 'QA_TESTER_2',
        'username' => '52311077',
        'email' => '52311077@gendejesus.edu.ph',
        'system_role' => 'user',
        'org_role' => 'QA Tester',
    ],
    [
        'env_prefix' => 'QA_TESTER_3',
        'username' => '32212218',
        'email' => '32212218@gendejesus.edu.ph',
        'system_role' => 'user',
        'org_role' => 'QA Tester',
    ],
    [
        'env_prefix' => 'QA_TESTER_4',
        'username' => '52310668',
        'email' => '52310668@gendejesus.edu.ph',
        'system_role' => 'user',
        'org_role' => 'QA Tester',
    ],
    [
        'env_prefix' => 'SENIOR_QA',
        'username' => '52310225',
        'email' => '52310225@gendejesus.edu.ph',
        'system_role' => 'user',
        'org_role' => 'Senior QA',
    ],
    [
        'env_prefix' => 'PM',
        'username' => '52310851',
        'email' => '52310851@gendejesus.edu.ph',
        'system_role' => 'user',
        'org_role' => 'Project Manager',
    ],
    [
        'env_prefix' => 'SENIOR_DEV',
        'username' => '52310826',
        'email' => '52310826@gendejesus.edu.ph',
        'system_role' => 'user',
        'org_role' => 'Senior Developer',
    ],
    [
        'env_prefix' => 'SENIOR_DEV_2',
        'username' => 'gemmueldelacruz',
        'email' => 'gemmueldelacruz@gmail.com',
        'system_role' => 'user',
        'org_role' => 'Senior Developer',
    ],
    [
        'env_prefix' => 'JUNIOR_DEV',
        'username' => 'oscar.nogoy08',
        'email' => 'oscar.nogoy08@gmail.com',
        'system_role' => 'user',
        'org_role' => 'Junior Developer',
    ],
    [
        'env_prefix' => 'JUNIOR_DEV_2',
        'username' => 'bulletlangto',
        'email' => 'bulletlangto@gmail.com',
        'system_role' => 'user',
        'org_role' => 'Junior Developer',
    ],
];

$extraOrgNames = ['Google', 'Microsoft', 'Tesla'];

function runCommand(array $command, array $env = [], ?string $stdin = null): array
{
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptorSpec, $pipes, null, $env ?: null);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start process: ' . implode(' ', $command));
    }

    if ($stdin !== null) {
        fwrite($pipes[0], $stdin);
    }
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    return [
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
        'exit_code' => $exitCode,
    ];
}

function backupDatabase(string $backupPath, string $dbHost, int $dbPort, string $dbUser, string $dbPass, string $dbName): void
{
    $command = [
        'mysqldump',
        '--single-transaction',
        '--routines',
        '--events',
        '--triggers',
        '--default-character-set=utf8mb4',
        "--host={$dbHost}",
        "--port={$dbPort}",
        "--user={$dbUser}",
        '--databases',
        $dbName,
    ];

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptorSpec, $pipes, null, ['MYSQL_PWD' => $dbPass] + $_ENV);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start mysqldump.');
    }

    fclose($pipes[0]);
    $gzip = gzopen($backupPath, 'wb9');
    if ($gzip === false) {
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        throw new RuntimeException("Unable to open backup file for writing: {$backupPath}");
    }

    while (!feof($pipes[1])) {
        $chunk = fread($pipes[1], 65536);
        if ($chunk === false) {
            break;
        }
        if ($chunk !== '') {
            gzwrite($gzip, $chunk);
        }
    }

    gzclose($gzip);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException("mysqldump failed: {$stderr}");
    }
}

function mysqlExecute(string $dbHost, int $dbPort, string $dbUser, string $dbPass, string $sql): void
{
    $result = runCommand(
        [
            'mysql',
            '--default-character-set=utf8mb4',
            "--host={$dbHost}",
            "--port={$dbPort}",
            "--user={$dbUser}",
        ],
        ['MYSQL_PWD' => $dbPass] + $_ENV,
        $sql
    );

    if ($result['exit_code'] !== 0) {
        throw new RuntimeException("mysql execution failed: {$result['stderr']}");
    }
}

function mysqlImportFile(string $dbHost, int $dbPort, string $dbUser, string $dbPass, string $database, string $path): void
{
    $sql = file_get_contents($path);
    if (!is_string($sql)) {
        throw new RuntimeException("Unable to read SQL file: {$path}");
    }

    $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql) ?? $sql;
    $result = runCommand(
        [
            'mysql',
            '--default-character-set=utf8mb4',
            "--host={$dbHost}",
            "--port={$dbPort}",
            "--user={$dbUser}",
            $database,
        ],
        ['MYSQL_PWD' => $dbPass] + $_ENV,
        $sql
    );

    if ($result['exit_code'] !== 0) {
        throw new RuntimeException("Import failed for {$path}: {$result['stderr']}");
    }
}

function quotedIdentifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function dbConnection(string $dbHost, int $dbPort, string $dbUser, string $dbPass, string $dbName): mysqli
{
    $conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
    $conn->set_charset('utf8mb4');
    return $conn;
}

function tableExists(mysqli $conn, string $tableName): bool
{
    $stmt = $conn->prepare("
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = ?
        LIMIT 1
    ");
    $stmt->bind_param('s', $tableName);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

function fetchAllAssoc(mysqli $conn, string $sql): array
{
    $result = $conn->query($sql);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function fetchOneAssoc(mysqli $conn, string $sql): ?array
{
    $result = $conn->query($sql);
    $row = $result ? $result->fetch_assoc() : null;
    return $row ?: null;
}

function captureAiConfiguration(mysqli $conn): array
{
    $preserved = [
        'providers' => [],
        'models' => [],
        'ai_runtime' => null,
        'openclaw_runtime' => null,
    ];

    if (tableExists($conn, 'ai_provider_configs')) {
        $preserved['providers'] = fetchAllAssoc($conn, "
            SELECT provider_key,
                   display_name,
                   provider_type,
                   base_url,
                   encrypted_api_key,
                   is_enabled,
                   supports_model_sync
            FROM ai_provider_configs
            ORDER BY id ASC
        ");
    }

    if (tableExists($conn, 'ai_models') && tableExists($conn, 'ai_provider_configs')) {
        $preserved['models'] = fetchAllAssoc($conn, "
            SELECT provider.provider_key,
                   model.model_id,
                   model.display_name,
                   model.supports_vision,
                   model.supports_json_output,
                   model.is_enabled,
                   model.is_default,
                   model.last_synced_at
            FROM ai_models model
            INNER JOIN ai_provider_configs provider ON provider.id = model.provider_config_id
            ORDER BY model.id ASC
        ");
    }

    if (tableExists($conn, 'ai_runtime_config') && tableExists($conn, 'ai_provider_configs') && tableExists($conn, 'ai_models')) {
        $preserved['ai_runtime'] = fetchOneAssoc($conn, "
            SELECT runtime.is_enabled,
                   runtime.assistant_name,
                   runtime.system_prompt,
                   provider.provider_key AS default_provider_key,
                   model.model_id AS default_model_key
            FROM ai_runtime_config runtime
            LEFT JOIN ai_provider_configs provider ON provider.id = runtime.default_provider_config_id
            LEFT JOIN ai_models model ON model.id = runtime.default_model_id
            ORDER BY runtime.id DESC
            LIMIT 1
        ");
    }

    if (tableExists($conn, 'openclaw_runtime_config') && tableExists($conn, 'ai_provider_configs') && tableExists($conn, 'ai_models')) {
        $preserved['openclaw_runtime'] = fetchOneAssoc($conn, "
            SELECT runtime.is_enabled,
                   runtime.notes,
                   provider.provider_key AS default_provider_key,
                   model.model_id AS default_model_key
            FROM openclaw_runtime_config runtime
            LEFT JOIN ai_provider_configs provider ON provider.id = runtime.default_provider_config_id
            LEFT JOIN ai_models model ON model.id = runtime.default_model_id
            ORDER BY runtime.id DESC
            LIMIT 1
        ");
    }

    return $preserved;
}

function restoreAiConfiguration(mysqli $conn, array $preserved, int $superAdminId): void
{
    $providerIdsByKey = [];
    $modelIdsByComposite = [];

    if (!empty($preserved['providers'])) {
        $providerStmt = $conn->prepare("
            INSERT INTO ai_provider_configs
                (
                    provider_key,
                    display_name,
                    provider_type,
                    base_url,
                    encrypted_api_key,
                    is_enabled,
                    supports_model_sync,
                    created_by,
                    updated_by
                )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($preserved['providers'] as $provider) {
            $providerKey = (string) ($provider['provider_key'] ?? '');
            $displayName = (string) ($provider['display_name'] ?? '');
            $providerType = (string) ($provider['provider_type'] ?? '');
            $baseUrl = isset($provider['base_url']) ? (string) $provider['base_url'] : null;
            $encryptedApiKey = isset($provider['encrypted_api_key']) ? (string) $provider['encrypted_api_key'] : null;
            $isEnabled = (int) ($provider['is_enabled'] ?? 1);
            $supportsModelSync = (int) ($provider['supports_model_sync'] ?? 0);
            $updatedBy = $superAdminId;

            $providerStmt->bind_param(
                'sssssiiii',
                $providerKey,
                $displayName,
                $providerType,
                $baseUrl,
                $encryptedApiKey,
                $isEnabled,
                $supportsModelSync,
                $superAdminId,
                $updatedBy
            );
            $providerStmt->execute();
            $providerIdsByKey[$providerKey] = (int) $conn->insert_id;
        }

        $providerStmt->close();
    }

    if (!empty($preserved['models'])) {
        $modelStmt = $conn->prepare("
            INSERT INTO ai_models
                (
                    provider_config_id,
                    model_id,
                    display_name,
                    supports_vision,
                    supports_json_output,
                    is_enabled,
                    is_default,
                    last_synced_at
                )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($preserved['models'] as $model) {
            $providerKey = (string) ($model['provider_key'] ?? '');
            $providerId = (int) ($providerIdsByKey[$providerKey] ?? 0);
            if ($providerId <= 0) {
                continue;
            }

            $modelKey = (string) ($model['model_id'] ?? '');
            $displayName = (string) ($model['display_name'] ?? '');
            $supportsVision = (int) ($model['supports_vision'] ?? 0);
            $supportsJsonOutput = (int) ($model['supports_json_output'] ?? 1);
            $isEnabled = (int) ($model['is_enabled'] ?? 1);
            $isDefault = (int) ($model['is_default'] ?? 0);
            $lastSyncedAt = isset($model['last_synced_at']) ? (string) $model['last_synced_at'] : null;

            $modelStmt->bind_param(
                'issiiiis',
                $providerId,
                $modelKey,
                $displayName,
                $supportsVision,
                $supportsJsonOutput,
                $isEnabled,
                $isDefault,
                $lastSyncedAt
            );
            $modelStmt->execute();
            $modelIdsByComposite[$providerKey . '|' . $modelKey] = (int) $conn->insert_id;
        }

        $modelStmt->close();
    }

    if (is_array($preserved['ai_runtime'])) {
        $runtime = $preserved['ai_runtime'];
        $defaultProviderKey = (string) ($runtime['default_provider_key'] ?? '');
        $defaultModelKey = (string) ($runtime['default_model_key'] ?? '');
        $defaultProviderId = (int) ($providerIdsByKey[$defaultProviderKey] ?? 0);
        $defaultModelId = (int) ($modelIdsByComposite[$defaultProviderKey . '|' . $defaultModelKey] ?? 0);
        $assistantName = isset($runtime['assistant_name']) ? (string) $runtime['assistant_name'] : null;
        $systemPrompt = isset($runtime['system_prompt']) ? (string) $runtime['system_prompt'] : null;
        $isEnabled = (int) ($runtime['is_enabled'] ?? 1);
        $updatedBy = $superAdminId;

        $runtimeStmt = $conn->prepare("
            INSERT INTO ai_runtime_config
                (
                    is_enabled,
                    default_provider_config_id,
                    default_model_id,
                    assistant_name,
                    system_prompt,
                    created_by,
                    updated_by
                )
            VALUES (?, NULLIF(?, 0), NULLIF(?, 0), ?, ?, ?, ?)
        ");
        $runtimeStmt->bind_param(
            'iiissii',
            $isEnabled,
            $defaultProviderId,
            $defaultModelId,
            $assistantName,
            $systemPrompt,
            $superAdminId,
            $updatedBy
        );
        $runtimeStmt->execute();
        $runtimeStmt->close();
    }

    if (is_array($preserved['openclaw_runtime'])) {
        $runtime = $preserved['openclaw_runtime'];
        $defaultProviderKey = (string) ($runtime['default_provider_key'] ?? '');
        $defaultModelKey = (string) ($runtime['default_model_key'] ?? '');
        $defaultProviderId = (int) ($providerIdsByKey[$defaultProviderKey] ?? 0);
        $defaultModelId = (int) ($modelIdsByComposite[$defaultProviderKey . '|' . $defaultModelKey] ?? 0);
        $notes = isset($runtime['notes']) ? (string) $runtime['notes'] : null;
        $isEnabled = (int) ($runtime['is_enabled'] ?? 0);
        $updatedBy = $superAdminId;

        $runtimeStmt = $conn->prepare("
            INSERT INTO openclaw_runtime_config
                (
                    is_enabled,
                    default_provider_config_id,
                    default_model_id,
                    notes,
                    created_by,
                    updated_by
                )
            VALUES (?, NULLIF(?, 0), NULLIF(?, 0), ?, ?, ?)
        ");
        $runtimeStmt->bind_param(
            'iiisii',
            $isEnabled,
            $defaultProviderId,
            $defaultModelId,
            $notes,
            $superAdminId,
            $updatedBy
        );
        $runtimeStmt->execute();
        $runtimeStmt->close();
    }
}

function firstLabelId(mysqli $conn): int
{
    $row = fetchOneAssoc($conn, "SELECT id FROM labels ORDER BY id ASC LIMIT 1");
    if (!$row) {
        throw new RuntimeException('No labels were seeded from infra/database/seed_reference_data.sql.');
    }

    return (int) ($row['id'] ?? 0);
}

$preResetConn = dbConnection($dbHost, $dbPort, $dbUser, $dbPass, $dbName);
$preservedAiConfig = captureAiConfiguration($preResetConn);
$preResetConn->close();

backupDatabase($backupPath, $dbHost, $dbPort, $dbUser, $dbPass, $dbName);

mysqlExecute(
    $dbHost,
    $dbPort,
    $dbUser,
    $dbPass,
    "DROP DATABASE IF EXISTS " . quotedIdentifier($dbName) . ";\n" .
    "CREATE DATABASE " . quotedIdentifier($dbName) . " CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;\n"
);
mysqlImportFile($dbHost, $dbPort, $dbUser, $dbPass, $dbName, $schemaPath);
mysqlImportFile($dbHost, $dbPort, $dbUser, $dbPass, $dbName, $referenceSeedPath);

$conn = dbConnection($dbHost, $dbPort, $dbUser, $dbPass, $dbName);

$userStmt = $conn->prepare("
    INSERT INTO users (username, email, password, role, last_active_org_id)
    VALUES (?, ?, ?, ?, NULL)
");

foreach ($accounts as $index => $account) {
    $passwordHash = password_hash($sharedPassword, PASSWORD_DEFAULT);
    if (!is_string($passwordHash) || $passwordHash === '') {
        throw new RuntimeException('Unable to hash the shared validation password.');
    }

    $username = (string) $account['username'];
    $email = (string) $account['email'];
    $systemRole = (string) $account['system_role'];
    $userStmt->bind_param('ssss', $username, $email, $passwordHash, $systemRole);
    $userStmt->execute();
    $accounts[$index]['id'] = (int) $conn->insert_id;
}
$userStmt->close();

$superAdminId = (int) $accounts[0]['id'];
$adminId = (int) $accounts[1]['id'];

$orgName = 'GJC_team';
$orgStmt = $conn->prepare("
    INSERT INTO organizations (name, owner_id)
    VALUES (?, ?)
");
$orgStmt->bind_param('si', $orgName, $superAdminId);
$orgStmt->execute();
$orgId = (int) $conn->insert_id;
$orgStmt->close();

$membershipStmt = $conn->prepare("
    INSERT INTO org_members (org_id, user_id, role)
    VALUES (?, ?, ?)
");
foreach ($accounts as $account) {
    $userId = (int) $account['id'];
    $orgRole = (string) $account['org_role'];
    $membershipStmt->bind_param('iis', $orgId, $userId, $orgRole);
    $membershipStmt->execute();
}

$extraOrgIds = [];
foreach ($extraOrgNames as $extraOrgName) {
    $extraOrgStmt = $conn->prepare("
        INSERT INTO organizations (name, owner_id)
        VALUES (?, ?)
    ");
    $extraOrgStmt->bind_param('si', $extraOrgName, $superAdminId);
    $extraOrgStmt->execute();
    $extraOrgId = (int) $conn->insert_id;
    $extraOrgStmt->close();

    $ownerRole = 'owner';
    $memberRole = 'member';
    $membershipStmt->bind_param('iis', $extraOrgId, $superAdminId, $ownerRole);
    $membershipStmt->execute();
    $membershipStmt->bind_param('iis', $extraOrgId, $adminId, $memberRole);
    $membershipStmt->execute();

    $extraOrgIds[$extraOrgName] = $extraOrgId;
}
$membershipStmt->close();

$updateActiveOrgStmt = $conn->prepare("
    UPDATE users
    SET last_active_org_id = ?
    WHERE id = ?
");
foreach ($accounts as $account) {
    $userId = (int) $account['id'];
    $updateActiveOrgStmt->bind_param('ii', $orgId, $userId);
    $updateActiveOrgStmt->execute();
}
$updateActiveOrgStmt->close();

$projectName = 'GJC Main Project';
$projectCode = 'GJC-MAIN';
$projectDescription = 'Baseline production parity seed project for the GJC_team organization.';
$projectStatus = 'active';
$projectStmt = $conn->prepare("
    INSERT INTO projects (org_id, name, code, description, status, created_by, updated_by)
    VALUES (?, ?, ?, ?, ?, ?, NULL)
");
$projectStmt->bind_param('issssi', $orgId, $projectName, $projectCode, $projectDescription, $projectStatus, $superAdminId);
$projectStmt->execute();
$projectId = (int) $conn->insert_id;
$projectStmt->close();

restoreAiConfiguration($conn, $preservedAiConfig, $superAdminId);

$labelId = firstLabelId($conn);
$conn->close();

echo "BACKUP_FILE={$backupPath}\n";
echo "ORG_ID={$orgId}\n";
echo "ORG_NAME={$orgName}\n";
echo "PROJECT_ID={$projectId}\n";
echo "PROJECT_NAME={$projectName}\n";
echo "LABEL_ID={$labelId}\n";
echo "SHARED_PASSWORD_SET=1\n";

foreach ($extraOrgNames as $extraOrgName) {
    $extraOrgId = (int) ($extraOrgIds[$extraOrgName] ?? 0);
    $extraOrgKey = strtoupper(str_replace(' ', '_', $extraOrgName));
    echo "{$extraOrgKey}_ORG_ID={$extraOrgId}\n";
}

foreach ($accounts as $account) {
    $prefix = (string) $account['env_prefix'];
    $userId = (int) $account['id'];
    $email = (string) $account['email'];
    echo "{$prefix}_USER_ID={$userId}\n";
    echo "{$prefix}_EMAIL={$email}\n";
    echo "E2E_{$prefix}_EMAIL={$email}\n";
    echo "E2E_{$prefix}_ID={$userId}\n";
}
