#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SERVICE_DIR="${ROOT_DIR}/services/realtime-notifications"
SYSTEMD_NAME="webtest-realtime-notifications.service"
SYSTEMD_SOURCE="${SERVICE_DIR}/systemd/${SYSTEMD_NAME}"
SYSTEMD_TARGET="/etc/systemd/system/${SYSTEMD_NAME}"

if ! command -v node >/dev/null 2>&1; then
  echo "node is required to run realtime notifications" >&2
  exit 1
fi

if ! command -v npm >/dev/null 2>&1; then
  echo "npm is required to install realtime notifications dependencies" >&2
  exit 1
fi

cd "${SERVICE_DIR}"
npm ci --omit=dev

if [ ! -f "${SYSTEMD_SOURCE}" ]; then
  echo "Missing systemd unit: ${SYSTEMD_SOURCE}" >&2
  exit 1
fi

sudo install -m 644 "${SYSTEMD_SOURCE}" "${SYSTEMD_TARGET}"
sudo systemctl daemon-reload
sudo systemctl enable "${SYSTEMD_NAME}"
sudo systemctl restart "${SYSTEMD_NAME}"
sudo systemctl --no-pager --full status "${SYSTEMD_NAME}" | sed -n '1,20p'
