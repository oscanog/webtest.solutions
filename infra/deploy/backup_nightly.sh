#!/usr/bin/env bash
set -euo pipefail

BACKUP_ROOT="${BACKUP_ROOT:-/var/backups/webtest}"
UPLOADS_DIR="${UPLOADS_DIR:-/var/www/webtest/shared/uploads}"
DB_NAME="${DB_NAME:-web_test}"
DB_USER="${DB_USER:-webtest_app}"
DB_PASS="${DB_PASS:-}"
STAMP="$(date +%Y%m%d-%H%M%S)"

install -d -m 0750 "${BACKUP_ROOT}"

MYSQL_PWD="${DB_PASS}" mysqldump --single-transaction --quick --lock-tables=false \
  --user="${DB_USER}" \
  "${DB_NAME}" > "${BACKUP_ROOT}/db-${STAMP}.sql"

tar -czf "${BACKUP_ROOT}/uploads-${STAMP}.tar.gz" -C "${UPLOADS_DIR}" .

find "${BACKUP_ROOT}" -type f -mtime +7 -delete
