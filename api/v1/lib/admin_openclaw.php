<?php

declare(strict_types=1);

function bc_v1_admin_openclaw_guard(mysqli $conn): array
{
    $actor = bc_v1_actor($conn, true);
    bc_v1_require_super_admin($actor);
    return $actor;
}

function bc_v1_admin_openclaw_runtime_get(mysqli $conn, array $params): void
{
    bc_v1_require_method(['GET']);
    bc_v1_admin_openclaw_guard($conn);

    bc_v1_json_success([
        'runtime' => bugcatcher_openclaw_runtime_config_for_display($conn),
        'control_plane' => bugcatcher_openclaw_fetch_control_plane_state($conn),
        'runtime_status' => bugcatcher_openclaw_fetch_runtime_status($conn),
        'pending_reload_request' => bugcatcher_openclaw_fetch_pending_reload_request($conn),
    ]);
}

function bc_v1_admin_openclaw_runtime_put(mysqli $conn, array $params): void
{
    bc_v1_require_method(['PUT', 'PATCH']);
    $actor = bc_v1_admin_openclaw_guard($conn);
    $payload = bc_v1_request_data();

    try {
        bugcatcher_openclaw_save_runtime_config(
            $conn,
            (int) $actor['user']['id'],
            bc_v1_get_bool($payload, 'is_enabled', false),
            trim((string) ($payload['discord_bot_token'] ?? '')),
            bc_v1_get_int($payload, 'default_provider_config_id', 0),
            bc_v1_get_int($payload, 'default_model_id', 0),
            trim((string) ($payload['notes'] ?? ''))
        );
    } catch (Throwable $e) {
        bc_v1_json_error(422, 'runtime_save_failed', $e->getMessage());
    }

    bc_v1_json_success([
        'saved' => true,
        'runtime' => bugcatcher_openclaw_runtime_config_for_display($conn),
        'pending_reload_request' => bugcatcher_openclaw_fetch_pending_reload_request($conn),
    ]);
}

function bc_v1_admin_openclaw_runtime_reload_post(mysqli $conn, array $params): void
{
    bc_v1_require_method(['POST']);
    $actor = bc_v1_admin_openclaw_guard($conn);
    $payload = bc_v1_request_data();
    $reason = trim((string) ($payload['reason'] ?? 'super_admin_manual_reload'));
    if ($reason === '') {
        $reason = 'super_admin_manual_reload';
    }

    $reloadRequestId = bugcatcher_openclaw_queue_reload_request($conn, (int) $actor['user']['id'], $reason);
    bc_v1_json_success([
        'queued' => true,
        'reload_request_id' => $reloadRequestId,
        'pending_reload_request' => bugcatcher_openclaw_fetch_pending_reload_request($conn),
    ], 202);
}

function bc_v1_admin_openclaw_snapshot_post(mysqli $conn, array $params): void
{
    bc_v1_require_method(['POST']);
    bc_v1_admin_openclaw_guard($conn);

    bc_v1_json_success([
        'snapshot' => bugcatcher_openclaw_runtime_config_for_display($conn),
    ]);
}

function bc_v1_admin_openclaw_providers_get(mysqli $conn, array $params): void
{
    bc_v1_require_method(['GET']);
    bc_v1_admin_openclaw_guard($conn);

    bc_v1_json_success([
        'providers' => bugcatcher_openclaw_fetch_providers($conn),
    ]);
}

function bc_v1_admin_openclaw_providers_post(mysqli $conn, array $params): void
{
    bc_v1_require_method(['POST']);
    $actor = bc_v1_admin_openclaw_guard($conn);
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
        bc_v1_json_error(422, 'provider_save_failed', $e->getMessage());
    }

    bc_v1_json_success([
        'saved' => true,
        'providers' => bugcatcher_openclaw_fetch_providers($conn),
    ]);
}

function bc_v1_admin_openclaw_providers_delete(mysqli $conn, array $params): void
{
    bc_v1_require_method(['DELETE']);
    $actor = bc_v1_admin_openclaw_guard($conn);
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

function bc_v1_admin_openclaw_models_get(mysqli $conn, array $params): void
{
    bc_v1_require_method(['GET']);
    bc_v1_admin_openclaw_guard($conn);

    bc_v1_json_success([
        'models' => bugcatcher_openclaw_fetch_models($conn),
    ]);
}

function bc_v1_admin_openclaw_models_post(mysqli $conn, array $params): void
{
    bc_v1_require_method(['POST']);
    $actor = bc_v1_admin_openclaw_guard($conn);
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
        bc_v1_json_error(422, 'model_save_failed', $e->getMessage());
    }

    bc_v1_json_success([
        'saved' => true,
        'models' => bugcatcher_openclaw_fetch_models($conn),
    ]);
}

function bc_v1_admin_openclaw_models_delete(mysqli $conn, array $params): void
{
    bc_v1_require_method(['DELETE']);
    $actor = bc_v1_admin_openclaw_guard($conn);
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

function bc_v1_admin_openclaw_channels_get(mysqli $conn, array $params): void
{
    bc_v1_require_method(['GET']);
    bc_v1_admin_openclaw_guard($conn);

    bc_v1_json_success([
        'channels' => bugcatcher_openclaw_fetch_channel_bindings($conn),
    ]);
}

function bc_v1_admin_openclaw_channels_post(mysqli $conn, array $params): void
{
    bc_v1_require_method(['POST']);
    $actor = bc_v1_admin_openclaw_guard($conn);
    $payload = bc_v1_request_data();

    try {
        bugcatcher_openclaw_save_channel_binding(
            $conn,
            (int) $actor['user']['id'],
            bc_v1_get_int($payload, 'binding_id', 0),
            trim((string) ($payload['guild_id'] ?? '')),
            trim((string) ($payload['guild_name'] ?? '')),
            trim((string) ($payload['channel_id'] ?? '')),
            trim((string) ($payload['channel_name'] ?? '')),
            bc_v1_get_bool($payload, 'is_enabled', false),
            bc_v1_get_bool($payload, 'allow_dm_followup', false)
        );
    } catch (Throwable $e) {
        bc_v1_json_error(422, 'channel_save_failed', $e->getMessage());
    }

    bc_v1_json_success([
        'saved' => true,
        'channels' => bugcatcher_openclaw_fetch_channel_bindings($conn),
    ]);
}

function bc_v1_admin_openclaw_channels_delete(mysqli $conn, array $params): void
{
    bc_v1_require_method(['DELETE']);
    $actor = bc_v1_admin_openclaw_guard($conn);
    $bindingId = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
    if ($bindingId <= 0) {
        bc_v1_json_error(422, 'invalid_binding', 'Binding id is invalid.');
    }

    bugcatcher_openclaw_delete_channel_binding($conn, $bindingId, (int) $actor['user']['id']);
    bc_v1_json_success([
        'deleted' => true,
        'binding_id' => $bindingId,
    ]);
}

function bc_v1_admin_openclaw_users_get(mysqli $conn, array $params): void
{
    bc_v1_require_method(['GET']);
    bc_v1_admin_openclaw_guard($conn);
    $limit = bc_v1_get_int($_GET, 'limit', 50);
    if ($limit <= 0) {
        $limit = 50;
    }

    bc_v1_json_success([
        'users' => bugcatcher_openclaw_fetch_linked_users($conn, $limit),
    ]);
}

function bc_v1_admin_openclaw_requests_get(mysqli $conn, array $params): void
{
    bc_v1_require_method(['GET']);
    bc_v1_admin_openclaw_guard($conn);
    $limit = bc_v1_get_int($_GET, 'limit', 50);
    if ($limit <= 0) {
        $limit = 50;
    }

    bc_v1_json_success([
        'requests' => bugcatcher_openclaw_fetch_recent_requests($conn, $limit),
    ]);
}
