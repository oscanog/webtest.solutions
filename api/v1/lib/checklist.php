<?php

declare(strict_types=1);

function bc_v1_checklist_actor_orgs(mysqli $conn, array $actor): array
{
    $stmt = $conn->prepare("
        SELECT om.org_id, om.role, o.name AS org_name, o.owner_id
        FROM org_members om
        JOIN organizations o ON o.id = om.org_id
        WHERE om.user_id = ?
        ORDER BY o.name ASC, om.org_id ASC
    ");
    $userId = (int) ($actor['user']['id'] ?? 0);
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function bc_v1_checklist_resolve_batch_org_id(mysqli $conn, array $actor, int $batchId): int
{
    $stmt = $conn->prepare("
        SELECT cb.org_id
        FROM checklist_batches cb
        JOIN org_members om
            ON om.org_id = cb.org_id
           AND om.user_id = ?
        WHERE cb.id = ?
        LIMIT 1
    ");
    $userId = (int) ($actor['user']['id'] ?? 0);
    $stmt->bind_param('ii', $userId, $batchId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row['org_id'] ?? 0);
}

function bc_v1_checklist_resolve_item_org_id(mysqli $conn, array $actor, int $itemId): int
{
    $stmt = $conn->prepare("
        SELECT ci.org_id
        FROM checklist_items ci
        JOIN org_members om
            ON om.org_id = ci.org_id
           AND om.user_id = ?
        WHERE ci.id = ?
        LIMIT 1
    ");
    $userId = (int) ($actor['user']['id'] ?? 0);
    $stmt->bind_param('ii', $userId, $itemId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row['org_id'] ?? 0);
}

function bc_v1_checklist_batches_get(mysqli $conn, array $params): void
{
    bc_v1_require_method(['GET']);
    $actor = bc_v1_actor($conn, true);
    $requestedOrgId = bc_v1_get_int($_GET, 'org_id', 0);
    $projectId = bc_v1_get_int($_GET, 'project_id', 0);
    $status = trim((string) ($_GET['status'] ?? ''));
    $search = trim((string) ($_GET['q'] ?? ''));

    if (!bc_v1_actor_is_all_scope($actor) || $requestedOrgId > 0) {
        $org = bc_v1_org_context($conn, $actor, $requestedOrgId);
        $batches = webtest_checklist_fetch_batches($conn, (int) $org['org_id'], $projectId, $status, $search);
        foreach ($batches as &$batch) {
            $batch['org_name'] = (string) $org['org_name'];
        }
        unset($batch);

        bc_v1_json_success([
            'org' => $org,
            'batches' => $batches,
        ]);
    }

    $batches = [];
    foreach (bc_v1_checklist_actor_orgs($conn, $actor) as $membership) {
        $orgBatches = webtest_checklist_fetch_batches(
            $conn,
            (int) ($membership['org_id'] ?? 0),
            $projectId,
            $status,
            $search
        );
        foreach ($orgBatches as $batch) {
            $batch['org_name'] = (string) ($membership['org_name'] ?? '');
            $batches[] = $batch;
        }
    }

    usort($batches, static function (array $left, array $right): int {
        $createdCompare = strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? ''));
        if ($createdCompare !== 0) {
            return $createdCompare;
        }
        return ((int) ($right['id'] ?? 0)) <=> ((int) ($left['id'] ?? 0));
    });

    bc_v1_json_success([
        'org' => bc_v1_all_org_context($actor),
        'batches' => $batches,
    ]);
}

function bc_v1_checklist_batch_get(mysqli $conn, array $params): void
{
    bc_v1_require_method(['GET']);
    $actor = bc_v1_actor($conn, true);
    $batchId = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
    if ($batchId <= 0) {
        bc_v1_json_error(422, 'invalid_batch', 'Batch id is invalid.');
    }

    $requestedOrgId = bc_v1_get_int($_GET, 'org_id', 0);
    $resolvedOrgId = $requestedOrgId > 0
        ? $requestedOrgId
        : (
            bc_v1_actor_is_all_scope($actor)
                ? bc_v1_checklist_resolve_batch_org_id($conn, $actor, $batchId)
                : (int) ($actor['active_org_id'] ?? 0)
        );
    if ($resolvedOrgId <= 0) {
        bc_v1_json_error(404, 'batch_not_found', 'Checklist batch not found.');
    }
    $org = bc_v1_org_context($conn, $actor, $resolvedOrgId);
    $batch = webtest_checklist_fetch_batch($conn, (int) $org['org_id'], $batchId);
    if (!$batch) {
        bc_v1_json_error(404, 'batch_not_found', 'Checklist batch not found.');
    }

    $batch['org_name'] = (string) $org['org_name'];
    $items = webtest_checklist_fetch_items_for_batch($conn, $batchId);
    foreach ($items as &$item) {
        $item['org_name'] = (string) $org['org_name'];
    }
    unset($item);

    bc_v1_json_success([
        'org' => $org,
        'batch' => $batch,
        'items' => $items,
        'attachments' => webtest_checklist_shape_attachments(webtest_openclaw_fetch_batch_attachments($conn, $batchId)),
        'assignable_qa_leads' => webtest_checklist_is_manager_role((string) $org['org_role'])
            ? array_map(static function (array $member): array {
                return [
                    'user_id' => (int) ($member['id'] ?? 0),
                    'username' => (string) ($member['username'] ?? ''),
                    'role' => (string) ($member['role'] ?? ''),
                ];
            }, webtest_checklist_fetch_org_members($conn, (int) $org['org_id'], ['QA Lead']))
            : [],
        'assignable_testers' => webtest_checklist_is_manager_role((string) $org['org_role'])
            ? array_map(static function (array $member): array {
                return [
                    'user_id' => (int) ($member['id'] ?? 0),
                    'username' => (string) ($member['username'] ?? ''),
                    'role' => (string) ($member['role'] ?? ''),
                ];
            }, webtest_checklist_fetch_org_members($conn, (int) $org['org_id'], ['QA Tester']))
            : [],
    ]);
}

function bc_v1_checklist_item_get(mysqli $conn, array $params): void
{
    bc_v1_require_method(['GET']);
    $actor = bc_v1_actor($conn, true);
    $itemId = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
    if ($itemId <= 0) {
        bc_v1_json_error(422, 'invalid_item', 'Checklist item id is invalid.');
    }

    $requestedOrgId = bc_v1_get_int($_GET, 'org_id', 0);
    $resolvedOrgId = $requestedOrgId > 0
        ? $requestedOrgId
        : (
            bc_v1_actor_is_all_scope($actor)
                ? bc_v1_checklist_resolve_item_org_id($conn, $actor, $itemId)
                : (int) ($actor['active_org_id'] ?? 0)
        );
    if ($resolvedOrgId <= 0) {
        bc_v1_json_error(404, 'item_not_found', 'Checklist item not found.');
    }
    $org = bc_v1_org_context($conn, $actor, $resolvedOrgId);
    $item = webtest_checklist_fetch_item($conn, (int) $org['org_id'], $itemId);
    if (!$item) {
        bc_v1_json_error(404, 'item_not_found', 'Checklist item not found.');
    }
    if (!bc_v1_actor_is_admin($actor) && !webtest_checklist_user_can_work_item($org, $item)) {
        bc_v1_json_error(403, 'forbidden', 'You cannot view this checklist item.');
    }

    $item['org_name'] = (string) $org['org_name'];
    $response = [
        'org' => $org,
        'item' => $item,
        'attachments' => webtest_checklist_shape_attachments(webtest_checklist_fetch_item_attachments($conn, $itemId)),
    ];
    if (webtest_checklist_is_manager_role((string) $org['org_role'])) {
        $response['assignable_testers'] = array_map(static function (array $member): array {
            return [
                'user_id' => (int) ($member['id'] ?? 0),
                'username' => (string) ($member['username'] ?? ''),
                'role' => (string) ($member['role'] ?? ''),
            ];
        }, webtest_checklist_fetch_org_members($conn, (int) $org['org_id'], ['QA Tester']));
    }

    bc_v1_json_success($response);
}
