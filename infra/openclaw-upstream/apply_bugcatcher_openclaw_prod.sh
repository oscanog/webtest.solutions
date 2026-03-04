#!/usr/bin/env bash
set -euo pipefail

BUGCATCHER_ROOT="${BUGCATCHER_ROOT:-/var/www/bugcatcher}"
CONFIG_PATH="${CONFIG_PATH:-$BUGCATCHER_ROOT/config/local.php}"
TEMP_DIR="${TEMP_DIR:-$BUGCATCHER_ROOT/uploads/openclaw-tmp}"
LOG_LEVEL="${LOG_LEVEL:-info}"
MIGRATION_PATH="${MIGRATION_PATH:-$BUGCATCHER_ROOT/infra/database/migrations/20260304_openclaw_rollout.sql}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/bugcatcher}"
PROMOTE_USER_ID="${PROMOTE_USER_ID:-}"

if [[ -z "$PROMOTE_USER_ID" ]]; then
    echo "PROMOTE_USER_ID is required." >&2
    exit 1
fi

if [[ ! -f "$CONFIG_PATH" ]]; then
    echo "Config file not found: $CONFIG_PATH" >&2
    exit 1
fi

if [[ ! -f "$MIGRATION_PATH" ]]; then
    echo "Migration file not found: $MIGRATION_PATH" >&2
    exit 1
fi

install -d -m 0750 "$BACKUP_DIR"
BACKUP_PATH="$BACKUP_DIR/bug_catcher_pre_openclaw_$(date +%Y%m%d%H%M%S).sql"
mysqldump --single-transaction --routines --triggers bug_catcher > "$BACKUP_PATH"

OPENCLAW_INTERNAL_SHARED_SECRET="${OPENCLAW_INTERNAL_SHARED_SECRET:-$(openssl rand -hex 32)}"
OPENCLAW_ENCRYPTION_KEY="${OPENCLAW_ENCRYPTION_KEY:-$(openssl rand -base64 32 | tr -d '\n')}"

env \
    CONFIG_PATH="$CONFIG_PATH" \
    TEMP_DIR="$TEMP_DIR" \
    LOG_LEVEL="$LOG_LEVEL" \
    OPENCLAW_INTERNAL_SHARED_SECRET="$OPENCLAW_INTERNAL_SHARED_SECRET" \
    OPENCLAW_ENCRYPTION_KEY="$OPENCLAW_ENCRYPTION_KEY" \
    php <<'PHP'
<?php
$configPath = getenv('CONFIG_PATH');
$tempDir = getenv('TEMP_DIR');
$logLevel = getenv('LOG_LEVEL');
$sharedSecret = getenv('OPENCLAW_INTERNAL_SHARED_SECRET');
$encryptionKey = getenv('OPENCLAW_ENCRYPTION_KEY');

$current = require $configPath;
if (!is_array($current)) {
    fwrite(STDERR, "Config file did not return an array.\n");
    exit(1);
}

$current['OPENCLAW_INTERNAL_SHARED_SECRET'] = $sharedSecret;
$current['OPENCLAW_ENCRYPTION_KEY'] = $encryptionKey;
$current['OPENCLAW_TEMP_UPLOAD_DIR'] = $tempDir;
$current['OPENCLAW_LOG_LEVEL'] = $logLevel;

$export = "<?php\n\nreturn " . var_export($current, true) . ";\n";
if (file_put_contents($configPath, $export) === false) {
    fwrite(STDERR, "Failed to write updated config.\n");
    exit(1);
}
PHP

install -d -m 2770 "$TEMP_DIR"
if getent group www-data >/dev/null 2>&1; then
    chgrp www-data "$TEMP_DIR"
fi
if id -u openclaw >/dev/null 2>&1 && getent group www-data >/dev/null 2>&1; then
    usermod -a -G www-data openclaw
fi

mysql bug_catcher < "$MIGRATION_PATH"

mysql -Nse "
USE bug_catcher;
UPDATE users
SET role = 'super_admin'
WHERE id = ${PROMOTE_USER_ID};
SELECT id, username, email, role FROM users WHERE id = ${PROMOTE_USER_ID};
SHOW TABLES LIKE 'discord_user_links';
SHOW TABLES LIKE 'discord_channel_bindings';
SHOW TABLES LIKE 'openclaw_runtime_config';
SHOW TABLES LIKE 'ai_provider_configs';
SHOW TABLES LIKE 'ai_models';
SHOW TABLES LIKE 'openclaw_requests';
SHOW TABLES LIKE 'openclaw_request_items';
SHOW TABLES LIKE 'openclaw_request_attachments';
SHOW TABLES LIKE 'checklist_batch_attachments';
SHOW COLUMNS FROM users LIKE 'role';
"

echo "Backup created at: $BACKUP_PATH"
echo "OPENCLAW_TEMP_UPLOAD_DIR: $TEMP_DIR"
echo "OPENCLAW_INTERNAL_SHARED_SECRET generated and written to config."
echo "OPENCLAW_ENCRYPTION_KEY generated and written to config."
