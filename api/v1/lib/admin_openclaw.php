<?php

declare(strict_types=1);

function bc_v1_admin_openclaw_runtime_get(mysqli $conn, array $params): void
{
    bc_v1_admin_ai_runtime_get($conn, $params);
}

function bc_v1_admin_openclaw_runtime_put(mysqli $conn, array $params): void
{
    bc_v1_admin_ai_runtime_put($conn, $params);
}

function bc_v1_admin_openclaw_providers_get(mysqli $conn, array $params): void
{
    bc_v1_admin_ai_providers_get($conn, $params);
}

function bc_v1_admin_openclaw_providers_post(mysqli $conn, array $params): void
{
    bc_v1_admin_ai_providers_post($conn, $params);
}

function bc_v1_admin_openclaw_providers_delete(mysqli $conn, array $params): void
{
    bc_v1_admin_ai_providers_delete($conn, $params);
}

function bc_v1_admin_openclaw_models_get(mysqli $conn, array $params): void
{
    bc_v1_admin_ai_models_get($conn, $params);
}

function bc_v1_admin_openclaw_models_post(mysqli $conn, array $params): void
{
    bc_v1_admin_ai_models_post($conn, $params);
}

function bc_v1_admin_openclaw_models_delete(mysqli $conn, array $params): void
{
    bc_v1_admin_ai_models_delete($conn, $params);
}
