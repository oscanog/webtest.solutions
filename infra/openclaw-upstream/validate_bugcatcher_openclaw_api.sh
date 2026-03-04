#!/usr/bin/env bash
set -euo pipefail

DISCORD_USER_ID="${DISCORD_USER_ID:-999000111222333444}"
TEST_USER_ID="${TEST_USER_ID:-1}"
TEST_ORG_ID="${TEST_ORG_ID:-}"
TEST_PROJECT_ID="${TEST_PROJECT_ID:-}"
RUN_SUBMIT_VALIDATION="${RUN_SUBMIT_VALIDATION:-0}"
CLEANUP_SUBMIT_VALIDATION="${CLEANUP_SUBMIT_VALIDATION:-1}"

eval "$(php <<'PHP'
<?php
$cfg = require '/var/www/bugcatcher/config/local.php';
echo 'APP_BASE_URL=' . var_export($cfg['APP_BASE_URL'] ?? '', true) . PHP_EOL;
echo 'OPENCLAW_INTERNAL_SHARED_SECRET=' . var_export($cfg['OPENCLAW_INTERNAL_SHARED_SECRET'] ?? '', true) . PHP_EOL;
echo 'OPENCLAW_TEMP_UPLOAD_DIR=' . var_export($cfg['OPENCLAW_TEMP_UPLOAD_DIR'] ?? '', true) . PHP_EOL;
PHP
)"

if [[ -z "${APP_BASE_URL:-}" || -z "${OPENCLAW_INTERNAL_SHARED_SECRET:-}" ]]; then
    echo "Missing APP_BASE_URL or OPENCLAW_INTERNAL_SHARED_SECRET in config." >&2
    exit 1
fi

echo "-- health --"
curl -fsS \
    -H "Authorization: Bearer $OPENCLAW_INTERNAL_SHARED_SECRET" \
    "$APP_BASE_URL/api/openclaw/health.php"
echo

echo "-- scope --"
mysql -Nse "
USE bug_catcher;
SELECT o.id, o.name, p.id, p.name
FROM organizations o
JOIN projects p ON p.org_id = o.id
ORDER BY o.id, p.id
LIMIT 20;
SELECT org_id, user_id, role
FROM org_members
WHERE user_id = ${TEST_USER_ID}
ORDER BY org_id;
"

if [[ -z "$TEST_ORG_ID" || -z "$TEST_PROJECT_ID" ]]; then
    echo "Skipping linked-user and duplicate validation because TEST_ORG_ID/TEST_PROJECT_ID were not provided."
    exit 0
fi

mysql -Nse "
USE bug_catcher;
INSERT INTO discord_user_links
    (user_id, discord_user_id, discord_username, discord_global_name, linked_at, last_seen_at, is_active)
VALUES
    (${TEST_USER_ID}, '${DISCORD_USER_ID}', 'openclaw-validation', 'openclaw-validation', NOW(), NOW(), 1)
ON DUPLICATE KEY UPDATE
    discord_user_id = VALUES(discord_user_id),
    discord_username = VALUES(discord_username),
    discord_global_name = VALUES(discord_global_name),
    linked_at = VALUES(linked_at),
    last_seen_at = VALUES(last_seen_at),
    is_active = 1;
"

echo "-- link_context --"
curl -fsS \
    -H "Authorization: Bearer $OPENCLAW_INTERNAL_SHARED_SECRET" \
    -H "Content-Type: application/json" \
    -d "{\"discord_user_id\":\"$DISCORD_USER_ID\"}" \
    "$APP_BASE_URL/api/openclaw/link_context.php"
echo

echo "-- duplicates --"
curl -fsS \
    -H "Authorization: Bearer $OPENCLAW_INTERNAL_SHARED_SECRET" \
    -H "Content-Type: application/json" \
    -d "{\"org_id\":$TEST_ORG_ID,\"project_id\":$TEST_PROJECT_ID,\"items\":[{\"title\":\"OpenClaw validation item\",\"module_name\":\"OpenClaw Validation\",\"submodule_name\":\"API\",\"description\":\"Validation only.\"}]}" \
    "$APP_BASE_URL/api/openclaw/checklist_duplicates.php"
echo

if [[ "$RUN_SUBMIT_VALIDATION" != "1" ]]; then
    echo "Skipping checklist_batches submission validation."
    exit 0
fi

install -d -m 2770 "$OPENCLAW_TEMP_UPLOAD_DIR"
TOKEN="$(openssl rand -hex 16)"
PNG_PATH="$OPENCLAW_TEMP_UPLOAD_DIR/$TOKEN"
printf '\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x02\x00\x00\x00\x90wS\xde\x00\x00\x00\x0cIDAT\x08\x99c``\x00\x00\x00\x04\x00\x01\xf6\x178U\x00\x00\x00\x00IEND\xaeB`\x82' > "$PNG_PATH"

echo "-- checklist_batches --"
SUBMIT_RESPONSE="$(
curl -fsS \
    -H "Authorization: Bearer $OPENCLAW_INTERNAL_SHARED_SECRET" \
    -H "Content-Type: application/json" \
    -d "{
      \"org_id\": $TEST_ORG_ID,
      \"project_id\": $TEST_PROJECT_ID,
      \"requested_by_user_id\": $TEST_USER_ID,
      \"discord_user_id\": \"$DISCORD_USER_ID\",
      \"batch\": {
        \"title\": \"OpenClaw Validation $(date +%Y-%m-%d\ %H:%M:%S)\",
        \"module_name\": \"OpenClaw Validation\",
        \"submodule_name\": \"API\",
        \"notes\": \"Temporary validation batch\",
        \"source_reference\": {\"type\":\"validation\"}
      },
      \"items\": [
        {
          \"sequence_no\": 1,
          \"title\": \"Validation item\",
          \"module_name\": \"OpenClaw Validation\",
          \"submodule_name\": \"API\",
          \"description\": \"Validation item only.\"
        }
      ],
      \"batch_attachments\": [
        {
          \"temp_file_token\": \"$TOKEN\",
          \"original_name\": \"validation.png\"
        }
      ]
    }" \
    "$APP_BASE_URL/api/openclaw/checklist_batches.php"
)"
echo "$SUBMIT_RESPONSE"

BATCH_ID="$(printf '%s' "$SUBMIT_RESPONSE" | python3 -c 'import json,sys; print(json.load(sys.stdin)["batch_id"])')"

if [[ "$CLEANUP_SUBMIT_VALIDATION" != "1" ]]; then
    exit 0
fi

ATTACHMENT_RELATIVE_PATH="$(mysql -Nse "
USE bug_catcher;
SELECT file_path
FROM checklist_batch_attachments
WHERE checklist_batch_id = ${BATCH_ID}
ORDER BY id DESC
LIMIT 1;
")"

if [[ -n "$ATTACHMENT_RELATIVE_PATH" ]]; then
    ATTACHMENT_ABSOLUTE_PATH="$(ATTACHMENT_RELATIVE_PATH="$ATTACHMENT_RELATIVE_PATH" php <<'PHP'
<?php
require '/var/www/bugcatcher/app/bootstrap.php';
$path = getenv('ATTACHMENT_RELATIVE_PATH') ?: '';
echo bugcatcher_checklist_upload_absolute_path($path) ?: '';
PHP
)"
    if [[ -n "$ATTACHMENT_ABSOLUTE_PATH" && -f "$ATTACHMENT_ABSOLUTE_PATH" ]]; then
        rm -f "$ATTACHMENT_ABSOLUTE_PATH"
    fi
fi

mysql -Nse "
USE bug_catcher;
DELETE FROM checklist_batches WHERE id = ${BATCH_ID};
DELETE FROM discord_user_links WHERE discord_user_id = '${DISCORD_USER_ID}';
"

echo "-- cleanup --"
echo "Deleted validation batch ${BATCH_ID} and removed the temporary Discord link."
