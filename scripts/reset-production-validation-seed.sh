#!/usr/bin/env bash

set -euo pipefail

WEBTEST_ROOT="${WEBTEST_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
CONFIG_PATH="${WEBTEST_CONFIG_PATH:-}"

resolve_config_path() {
    local root="$1"
    local explicit="$2"
    local candidate=""

    if [[ -n "$explicit" ]]; then
        if [[ -f "$explicit" ]]; then
            printf '%s\n' "$explicit"
            return 0
        fi
        echo "Config file not found: $explicit" >&2
        return 1
    fi

    for candidate in \
        "$root/shared/config.php" \
        "$root/config/local.php" \
        "$root/infra/config/local.php"
    do
        if [[ -f "$candidate" ]]; then
            printf '%s\n' "$candidate"
            return 0
        fi
    done

    echo "No config file found. Checked: $root/shared/config.php, $root/config/local.php, $root/infra/config/local.php" >&2
    return 1
}

CONFIG_PATH="$(resolve_config_path "$WEBTEST_ROOT" "$CONFIG_PATH")"

if [[ "${WEBTEST_ALLOW_DESTRUCTIVE_RESET:-}" != "1" ]]; then
    echo "Refusing destructive reset. Set WEBTEST_ALLOW_DESTRUCTIVE_RESET=1 to continue." >&2
    exit 1
fi

if [[ -z "${WEBTEST_VALIDATION_SHARED_PASSWORD:-}" ]]; then
    echo "Set WEBTEST_VALIDATION_SHARED_PASSWORD before running the production reset." >&2
    exit 1
fi

export WEBTEST_ROOT
export WEBTEST_CONFIG_PATH="$CONFIG_PATH"

php "$WEBTEST_ROOT/scripts/reset-production-validation-seed.php"
