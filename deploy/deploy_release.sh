#!/usr/bin/env bash
set -euo pipefail

APP_NAME="${APP_NAME:-bugcatcher}"
APP_ROOT="${APP_ROOT:-/var/www/${APP_NAME}}"
RELEASES_DIR="${RELEASES_DIR:-${APP_ROOT}/releases}"
SHARED_DIR="${SHARED_DIR:-${APP_ROOT}/shared}"
CURRENT_LINK="${CURRENT_LINK:-${APP_ROOT}/current}"
MIRROR_DIR="${MIRROR_DIR:-/opt/${APP_NAME}/repo.git}"
APP_USER="${APP_USER:-bugcatcher}"
APP_GROUP="${APP_GROUP:-www-data}"
REF="${1:-}"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-}"

if [[ -z "${REF}" ]]; then
  echo "Usage: $0 <git-ref>"
  exit 1
fi

if [[ ! -d "${MIRROR_DIR}" ]]; then
  echo "Git mirror not found: ${MIRROR_DIR}"
  exit 1
fi

timestamp="$(date +%Y%m%d%H%M%S)"
release_dir="${RELEASES_DIR}/${timestamp}"

sudo -u "${APP_USER}" git --git-dir="${MIRROR_DIR}" fetch origin --tags
install -d -o "${APP_USER}" -g "${APP_GROUP}" -m 0755 "${release_dir}"
sudo -u "${APP_USER}" sh -c "git --git-dir='${MIRROR_DIR}' archive '${REF}' | tar -x -C '${release_dir}'"

rm -rf "${release_dir}/uploads"
ln -sfn "${SHARED_DIR}/uploads" "${release_dir}/uploads"

if [[ ! -f "${SHARED_DIR}/config.php" ]]; then
  echo "Shared config missing: ${SHARED_DIR}/config.php"
  exit 1
fi

find "${release_dir}" -name '*.php' -print0 | while IFS= read -r -d '' file; do
  php -l "${file}" >/dev/null
done

ln -sfn "${release_dir}" "${CURRENT_LINK}"
chown -h "${APP_USER}:${APP_GROUP}" "${CURRENT_LINK}"

if [[ -z "${PHP_FPM_SERVICE}" ]]; then
  PHP_FPM_SERVICE="$(systemctl list-unit-files 'php*-fpm.service' --no-legend 2>/dev/null | awk 'NR==1 { print $1 }')"
fi

if [[ -n "${PHP_FPM_SERVICE}" ]]; then
  systemctl reload "${PHP_FPM_SERVICE}"
fi

systemctl reload nginx

echo "Deployed ${REF} to ${release_dir}"
