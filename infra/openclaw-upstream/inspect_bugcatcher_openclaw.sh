#!/usr/bin/env bash
set -euo pipefail

php <<'PHP'
<?php
require '/var/www/bugcatcher/app/bootstrap.php';

$cfg = bugcatcher_load_config();
echo 'APP_BASE_URL=' . ($cfg['APP_BASE_URL'] ?? '') . PHP_EOL;
echo 'OPENCLAW_TEMP_UPLOAD_DIR=' . ($cfg['OPENCLAW_TEMP_UPLOAD_DIR'] ?? '') . PHP_EOL;
echo 'OPENCLAW_INTERNAL_SHARED_SECRET=' . (
    !empty($cfg['OPENCLAW_INTERNAL_SHARED_SECRET']) &&
    $cfg['OPENCLAW_INTERNAL_SHARED_SECRET'] !== 'replace-with-a-second-long-random-secret'
        ? 'set'
        : 'missing'
) . PHP_EOL;
echo 'OPENCLAW_ENCRYPTION_KEY=' . (
    !empty($cfg['OPENCLAW_ENCRYPTION_KEY']) &&
    $cfg['OPENCLAW_ENCRYPTION_KEY'] !== 'replace-with-a-32-byte-secret'
        ? 'set'
        : 'missing'
) . PHP_EOL;
PHP

echo "-- database --"
mysql -Nse "
USE bug_catcher;
SHOW TABLES LIKE 'discord_user_links';
SHOW TABLES LIKE 'openclaw_runtime_config';
SHOW TABLES LIKE 'openclaw_requests';
SHOW TABLES LIKE 'checklist_batch_attachments';
SHOW COLUMNS FROM users LIKE 'role';
"
