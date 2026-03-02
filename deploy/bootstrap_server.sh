#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

APP_NAME="${APP_NAME:-bugcatcher}"
APP_USER="${APP_USER:-bugcatcher}"
APP_GROUP="${APP_GROUP:-www-data}"
APP_ROOT="${APP_ROOT:-/var/www/${APP_NAME}}"
SHARED_DIR="${SHARED_DIR:-${APP_ROOT}/shared}"
RELEASES_DIR="${RELEASES_DIR:-${APP_ROOT}/releases}"
MIRROR_DIR="${MIRROR_DIR:-/opt/${APP_NAME}/repo.git}"
REPO_URL="${REPO_URL:-https://github.com/oscanog/bugcatcher.git}"
CONFIG_TEMPLATE="${CONFIG_TEMPLATE:-${REPO_ROOT}/config/shared-config.example.php}"

if [[ "${EUID}" -ne 0 ]]; then
  echo "Run this script as root."
  exit 1
fi

apt-get update
apt-get install -y \
  nginx \
  php-fpm \
  php-cli \
  php-mysql \
  php-mbstring \
  php-xml \
  php-gd \
  php-curl \
  php-zip \
  mariadb-server \
  git \
  certbot \
  python3-certbot-nginx

if ! id -u "${APP_USER}" >/dev/null 2>&1; then
  useradd --system --create-home --shell /bin/bash "${APP_USER}"
fi

install -d -o "${APP_USER}" -g "${APP_GROUP}" -m 0755 "${APP_ROOT}" "${RELEASES_DIR}" "${SHARED_DIR}"
install -d -o "${APP_USER}" -g "${APP_GROUP}" -m 2775 "${SHARED_DIR}/uploads" "${SHARED_DIR}/uploads/issues"

if [[ ! -f "${SHARED_DIR}/config.php" ]]; then
  install -o "${APP_USER}" -g "${APP_GROUP}" -m 0640 "${CONFIG_TEMPLATE}" "${SHARED_DIR}/config.php"
fi

if [[ ! -d "${MIRROR_DIR}" ]]; then
  install -d -o "${APP_USER}" -g "${APP_GROUP}" -m 0755 "$(dirname "${MIRROR_DIR}")"
  sudo -u "${APP_USER}" git clone --mirror "${REPO_URL}" "${MIRROR_DIR}"
else
  sudo -u "${APP_USER}" git --git-dir="${MIRROR_DIR}" remote set-url origin "${REPO_URL}"
  sudo -u "${APP_USER}" git --git-dir="${MIRROR_DIR}" fetch origin --tags
fi

echo "Server bootstrap complete."
echo "Next: configure MariaDB, install the nginx vhost, and run deploy/deploy_release.sh."
