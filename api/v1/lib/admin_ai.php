<?php

declare(strict_types=1);

function bc_v1_admin_ai_guard(mysqli $conn): array
{
    $actor = bc_v1_actor($conn, true);
    bc_v1_require_super_admin($actor);
    return $actor;
}

function bc_v1_admin_ai_runtime_get(mysqli $conn, array $params): void
{
    bc_v1_require_method(['GET']);
    bc_v1_admin_ai_guard($conn);

    bc_v1_json_success(bugcatcher_ai_admin_runtime_snapshot($conn));
}

function bc_v1_admin_ai_runtime_put(mysqli $conn, array $params): void
{
    bc_v1_require_method(['PUT', 'PATCH']);
    $actor = bc_v1_admin_ai_guard($conn);
    $payload = bc_v1_request_data();

    try {
        bugcatcher_ai_admin_save_runtime_config(
            $conn,
            (int) $actor['user']['id'],
            bc_v1_get_bool($payload, 'is_enabled', true),
            bc_v1_get_int($payload, 'default_provider_config_id', 0),
            bc_v1_get_int($payload, 'default_model_id', 0),
            trim((string) ($payload['assistant_name'] ?? '')),
            trim((string) ($payload['system_prompt'] ?? '')),
            is_array($payload['personas'] ?? null) ? $payload['personas'] : []
        );
    } catch (Throwable $e) {
        bc_v1_json_error(422, 'ai_runtime_save_failed', $e->getMessage());
    }

    bc_v1_json_success([
        'saved' => true,
    ] + bugcatcher_ai_admin_runtime_snapshot($conn));
}

function bc_v1_admin_ai_providers_get(mysqli $conn, array $params): void
{
    bc_v1_require_method(['GET']);
    bc_v1_admin_ai_guard($conn);

    bc_v1_json_success([
        'providers' => bugcatcher_ai_admin_providers_for_display($conn),
    ]);
}

function bc_v1_admin_ai_providers_post(mysqli $conn, array $params): void
{
    bc_v1_require_method(['POST']);
    $actor = bc_v1_admin_ai_guard($conn);
    $payload = bc_v1_request_data();

    try {
        bugcatcher_openclaw_save_provider(
            $conn,
            (int) $actor['user']['id'],
            bc_v1_get_int($payload, 'provider_id', 0),
            trim((string) ($payload['provider_key'] ?? '')),
            trim((string) ($payload['display_name'] ?? '')),
            trim((string) ($payload['provider_type'] ?? '')),
            trim((string) ($payload['base_url'] ?? '')),
            trim((string) ($payload['api_key'] ?? '')),
            bc_v1_get_bool($payload, 'is_enabled', false),
            bc_v1_get_bool($payload, 'supports_model_sync', false)
        );
    } catch (Throwable $e) {
        bc_v1_json_error(422, 'ai_provider_save_failed', $e->getMessage());
    }

    bc_v1_json_success([
        'saved' => true,
        'providers' => bugcatcher_ai_admin_providers_for_display($conn),
    ]);
}

function bc_v1_admin_ai_providers_delete(mysqli $conn, array $params): void
{
    bc_v1_require_method(['DELETE']);
    $actor = bc_v1_admin_ai_guard($conn);
    $providerId = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
    if ($providerId <= 0) {
        bc_v1_json_error(422, 'invalid_provider', 'Provider id is invalid.');
    }

    bugcatcher_openclaw_delete_provider($conn, $providerId, (int) $actor['user']['id']);
    bc_v1_json_success([
        'deleted' => true,
        'provider_id' => $providerId,
    ]);
}

function bc_v1_admin_ai_models_get(mysqli $conn, array $params): void
{
    bc_v1_require_method(['GET']);
    bc_v1_admin_ai_guard($conn);

    bc_v1_json_success([
        'models' => bugcatcher_ai_admin_models_for_display($conn),
    ]);
}

function bc_v1_admin_ai_models_post(mysqli $conn, array $params): void
{
    bc_v1_require_method(['POST']);
    $actor = bc_v1_admin_ai_guard($conn);
    $payload = bc_v1_request_data();

    try {
        bugcatcher_openclaw_save_model(
            $conn,
            bc_v1_get_int($payload, 'provider_config_id', 0),
            bc_v1_get_int($payload, 'model_id', 0),
            trim((string) ($payload['remote_model_id'] ?? '')),
            trim((string) ($payload['display_name'] ?? '')),
            bc_v1_get_bool($payload, 'supports_vision', false),
            bc_v1_get_bool($payload, 'supports_json_output', false),
            bc_v1_get_bool($payload, 'is_enabled', false),
            bc_v1_get_bool($payload, 'is_default', false),
            (int) $actor['user']['id']
        );
    } catch (Throwable $e) {
        bc_v1_json_error(422, 'ai_model_save_failed', $e->getMessage());
    }

    bc_v1_json_success([
        'saved' => true,
        'models' => bugcatcher_ai_admin_models_for_display($conn),
    ]);
}

function bc_v1_admin_ai_models_delete(mysqli $conn, array $params): void
{
    bc_v1_require_method(['DELETE']);
    $actor = bc_v1_admin_ai_guard($conn);
    $modelId = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
    if ($modelId <= 0) {
        bc_v1_json_error(422, 'invalid_model', 'Model id is invalid.');
    }

    bugcatcher_openclaw_delete_model($conn, $modelId, (int) $actor['user']['id']);
    bc_v1_json_success([
        'deleted' => true,
        'model_id' => $modelId,
    ]);
}
