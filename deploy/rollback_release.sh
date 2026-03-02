#!/usr/bin/env bash
set -euo pipefail

APP_NAME="${APP_NAME:-bugcatcher}"
APP_ROOT="${APP_ROOT:-/var/www/${APP_NAME}}"
RELEASES_DIR="${RELEASES_DIR:-${APP_ROOT}/releases}"
CURRENT_LINK="${CURRENT_LINK:-${APP_ROOT}/current}"
APP_USER="${APP_USER:-bugcatcher}"
APP_GROUP="${APP_GROUP:-www-data}"
TARGET="${1:-}"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-}"

if [[ -n "${TARGET}" ]]; then
  candidate="${RELEASES_DIR}/${TARGET}"
else
  mapfile -t releases < <(find "${RELEASES_DIR}" -mindepth 1 -maxdepth 1 -type d | sort)
  if [[ "${#releases[@]}" -lt 2 ]]; then
    echo "Need at least two releases to roll back."
    exit 1
  fi
  candidate="${releases[$((${#releases[@]} - 2))]}"
fi

if [[ ! -d "${candidate}" ]]; then
  echo "Release not found: ${candidate}"
  exit 1
fi

ln -sfn "${candidate}" "${CURRENT_LINK}"
chown -h "${APP_USER}:${APP_GROUP}" "${CURRENT_LINK}"

if [[ -z "${PHP_FPM_SERVICE}" ]]; then
  PHP_FPM_SERVICE="$(systemctl list-unit-files 'php*-fpm.service' --no-legend 2>/dev/null | awk 'NR==1 { print $1 }')"
fi

if [[ -n "${PHP_FPM_SERVICE}" ]]; then
  systemctl reload "${PHP_FPM_SERVICE}"
fi

systemctl reload nginx

echo "Rolled back to ${candidate}"
