<?php

declare(strict_types=1);

function bc_v1_alias_require_actor_session(mysqli $conn): void
{
    bc_v1_bridge_session_auth($conn, true);
}

function bc_v1_alias_checklist_batches(mysqli $conn, array $params): void
{
    bc_v1_alias_require_actor_session($conn);
    bc_v1_include_legacy('api/checklist/v1/batches.php');
}

function bc_v1_alias_checklist_batch(mysqli $conn, array $params): void
{
    bc_v1_alias_require_actor_session($conn);
    bc_v1_include_legacy('api/checklist/v1/batch.php');
}

function bc_v1_alias_checklist_batch_by_id(mysqli $conn, array $params): void
{
    bc_v1_alias_require_actor_session($conn);
    bc_v1_include_legacy('api/checklist/v1/batch.php', ['id' => (string) ($params['id'] ?? '')]);
}

function bc_v1_alias_checklist_items(mysqli $conn, array $params): void
{
    bc_v1_alias_require_actor_session($conn);
    bc_v1_include_legacy('api/checklist/v1/items.php');
}

function bc_v1_alias_checklist_item(mysqli $conn, array $params): void
{
    bc_v1_alias_require_actor_session($conn);
    bc_v1_include_legacy('api/checklist/v1/item.php');
}

function bc_v1_alias_checklist_item_by_id(mysqli $conn, array $params): void
{
    bc_v1_alias_require_actor_session($conn);
    bc_v1_include_legacy('api/checklist/v1/item.php', ['id' => (string) ($params['id'] ?? '')]);
}

function bc_v1_alias_checklist_item_status(mysqli $conn, array $params): void
{
    bc_v1_alias_require_actor_session($conn);
    bc_v1_include_legacy('api/checklist/v1/item_status.php');
}

function bc_v1_alias_checklist_item_attachments(mysqli $conn, array $params): void
{
    bc_v1_alias_require_actor_session($conn);
    bc_v1_include_legacy('api/checklist/v1/item_attachments.php');
}

function bc_v1_alias_checklist_item_attachment(mysqli $conn, array $params): void
{
    bc_v1_alias_require_actor_session($conn);
    bc_v1_include_legacy('api/checklist/v1/item_attachment.php');
}

function bc_v1_alias_checklist_item_attachment_by_id(mysqli $conn, array $params): void
{
    bc_v1_alias_require_actor_session($conn);
    bc_v1_include_legacy('api/checklist/v1/item_attachment.php', ['id' => (string) ($params['id'] ?? '')]);
}

function bc_v1_alias_checklist_batch_attachments(mysqli $conn, array $params): void
{
    bc_v1_alias_require_actor_session($conn);
    bc_v1_include_legacy('api/checklist/v1/batch_attachments.php');
}

function bc_v1_alias_checklist_batch_attachment(mysqli $conn, array $params): void
{
    bc_v1_alias_require_actor_session($conn);
    bc_v1_include_legacy('api/checklist/v1/batch_attachment.php');
}

function bc_v1_alias_checklist_batch_attachment_by_id(mysqli $conn, array $params): void
{
    bc_v1_alias_require_actor_session($conn);
    bc_v1_include_legacy('api/checklist/v1/batch_attachment.php', ['id' => (string) ($params['id'] ?? '')]);
}

function bc_v1_alias_openclaw_checklist_duplicates(mysqli $conn, array $params): void
{
    bc_v1_include_legacy('api/openclaw/checklist_duplicates.php');
}

function bc_v1_alias_openclaw_checklist_batches(mysqli $conn, array $params): void
{
    bc_v1_include_legacy('api/openclaw/checklist_batches.php');
}

function bc_v1_alias_openclaw_checklist_ingest(mysqli $conn, array $params): void
{
    bc_v1_include_legacy('melvin/checklist_bot_ingest.php');
}
