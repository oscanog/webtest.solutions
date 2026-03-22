<?php

declare(strict_types=1);

function bc_v1_discord_link_get(mysqli $conn, array $params): void
{
    bc_v1_require_method(['GET']);
    $actor = bc_v1_actor($conn, true);
    $userId = (int) $actor['user']['id'];
    $link = bugcatcher_openclaw_fetch_user_link_by_user($conn, $userId);
    bc_v1_json_success(['link' => $link]);
}

function bc_v1_discord_link_code_post(mysqli $conn, array $params): void
{
    bc_v1_require_method(['POST']);
    $actor = bc_v1_actor($conn, true);
    $userId = (int) $actor['user']['id'];
    $code = bugcatcher_openclaw_generate_link_code();
    bugcatcher_openclaw_store_link_code($conn, $userId, $code);
    bc_v1_json_success([
        'code' => $code,
        'expires_in_seconds' => 600,
    ]);
}

function bc_v1_discord_link_delete(mysqli $conn, array $params): void
{
    bc_v1_require_method(['DELETE']);
    $actor = bc_v1_actor($conn, true);
    bugcatcher_openclaw_unlink_user($conn, (int) $actor['user']['id']);
    bc_v1_json_success(['unlinked' => true]);
}
