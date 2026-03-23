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
        'username' => 'm_viner001',
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
        'username' => 'gendejesus_52310085',
        'email' => '52310085@gendejesus.edu.ph',
        'system_role' => 'user',
        'org_role' => 'QA Tester',
    ],
    [
        'env_prefix' => 'SENIOR_QA',
        'username' => 'gendejesus_52310225',
        'email' => '52310225@gendejesus.edu.ph',
        'system_role' => 'user',
        'org_role' => 'Senior QA',
    ],
    [
        'env_prefix' => 'PM',
        'username' => 'gendejesus_52310851',
        'email' => '52310851@gendejesus.edu.ph',
        'system_role' => 'user',
        'org_role' => 'Project Manager',
    ],
    [
        'env_prefix' => 'SENIOR_DEV',
        'username' => 'gendejesus_52310826',
        'email' => '52310826@gendejesus.edu.ph',
        'system_role' => 'user',
        'org_role' => 'Senior Developer',
    ],
    [
        'env_prefix' => 'JUNIOR_DEV',
        'username' => 'oscar_nogoy08',
        'email' => 'oscar.nogoy08@gmail.com',
        'system_role' => 'user',
        'org_role' => 'Junior Developer',
    ],
];

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

$conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
$conn->set_charset('utf8mb4');

$userStmt = $conn->prepare("
    INSERT INTO users (username, email, password, role, last_active_org_id)
    VALUES (?, ?, ?, ?, NULL)
");

foreach ($accounts as $index => $account) {
    $passwordHash = password_hash($sharedPassword, PASSWORD_DEFAULT);
    if (!is_string($passwordHash) || $passwordHash === '') {
        throw new RuntimeException('Unable to hash the shared validation password.');
    }

    $username = $account['username'];
    $email = $account['email'];
    $systemRole = $account['system_role'];
    $userStmt->bind_param('ssss', $username, $email, $passwordHash, $systemRole);
    $userStmt->execute();
    $accounts[$index]['id'] = (int) $conn->insert_id;
}
$userStmt->close();

$orgName = 'BugCatcher Production Validation';
$orgOwnerId = (int) $accounts[0]['id'];
$orgStmt = $conn->prepare("
    INSERT INTO organizations (name, owner_id)
    VALUES (?, ?)
");
$orgStmt->bind_param('si', $orgName, $orgOwnerId);
$orgStmt->execute();
$orgId = (int) $conn->insert_id;
$orgStmt->close();

$membershipStmt = $conn->prepare("
    INSERT INTO org_members (org_id, user_id, role)
    VALUES (?, ?, ?)
");
foreach ($accounts as $account) {
    $userId = (int) $account['id'];
    $orgRole = $account['org_role'];
    $membershipStmt->bind_param('iis', $orgId, $userId, $orgRole);
    $membershipStmt->execute();
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

$projectName = 'Production Validation Project';
$projectCode = 'BC-PROD-VALIDATION';
$projectDescription = 'Clean production validation project for Playwright role and notification coverage.';
$projectStatus = 'active';
$projectCreatorId = (int) $accounts[0]['id'];
$projectStmt = $conn->prepare("
    INSERT INTO projects (org_id, name, code, description, status, created_by, updated_by)
    VALUES (?, ?, ?, ?, ?, ?, NULL)
");
$projectStmt->bind_param('issssi', $orgId, $projectName, $projectCode, $projectDescription, $projectStatus, $projectCreatorId);
$projectStmt->execute();
$projectId = (int) $conn->insert_id;
$projectStmt->close();

$labelId = 1;

echo "BACKUP_FILE={$backupPath}\n";
echo "ORG_ID={$orgId}\n";
echo "PROJECT_ID={$projectId}\n";
echo "LABEL_ID={$labelId}\n";
echo "SHARED_PASSWORD_SET=1\n";

foreach ($accounts as $account) {
    $prefix = $account['env_prefix'];
    $userId = (int) $account['id'];
    echo "{$prefix}_USER_ID={$userId}\n";
    echo "E2E_{$prefix}_EMAIL={$account['email']}\n";
    echo "E2E_{$prefix}_ID={$userId}\n";
}
