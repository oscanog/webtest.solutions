<?php

declare(strict_types=1);

function bc_v1_projects_get(mysqli $conn, array $params): void
{
    bc_v1_require_method(['GET']);
    $actor = bc_v1_actor($conn, true);
    $requestedOrgId = bc_v1_get_int($_GET, 'org_id', 0);
    $includeArchived = ((string) ($_GET['show'] ?? 'active')) === 'all';

    if (bc_v1_actor_is_all_scope($actor) && $requestedOrgId <= 0) {
        $userId = (int) $actor['user']['id'];
        $sql = "
            SELECT
                p.id,
                p.org_id,
                o.name AS org_name,
                p.name,
                p.code,
                p.description,
                p.status,
                p.created_by,
                p.updated_by,
                p.created_at,
                p.updated_at
            FROM projects p
            JOIN org_members om
                ON om.org_id = p.org_id
               AND om.user_id = ?
            JOIN organizations o ON o.id = p.org_id
        ";
        if (!$includeArchived) {
            $sql .= " WHERE p.status = 'active'";
        }
        $sql .= " ORDER BY o.name ASC, p.name ASC, p.id ASC";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $projects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        bc_v1_json_success([
            'org' => bc_v1_all_org_context($actor),
            'projects' => array_map(static function (array $project): array {
                $project['id'] = (int) ($project['id'] ?? 0);
                $project['org_id'] = (int) ($project['org_id'] ?? 0);
                $project['created_by'] = (int) ($project['created_by'] ?? 0);
                $project['updated_by'] = isset($project['updated_by']) ? (int) $project['updated_by'] : null;
                return $project;
            }, $projects),
        ]);
    }

    $org = bc_v1_org_context($conn, $actor, $requestedOrgId);
    $projects = webtest_checklist_fetch_projects($conn, (int) $org['org_id'], $includeArchived);
    foreach ($projects as &$project) {
        $project['org_name'] = (string) $org['org_name'];
    }
    unset($project);

    bc_v1_json_success([
        'org' => $org,
        'projects' => $projects,
    ]);
}

function bc_v1_projects_post(mysqli $conn, array $params): void
{
    bc_v1_require_method(['POST']);
    $actor = bc_v1_actor($conn, true);
    $payload = bc_v1_request_data();
    $org = bc_v1_org_context($conn, $actor, bc_v1_get_int($payload, 'org_id', 0));
    bc_v1_require_manager_role($org);

    $name = trim((string) ($payload['name'] ?? ''));
    $code = trim((string) ($payload['code'] ?? ''));
    $description = trim((string) ($payload['description'] ?? ''));
    $status = webtest_checklist_normalize_enum((string) ($payload['status'] ?? 'active'), ['active', 'archived'], 'active');

    if ($name === '') {
        bc_v1_json_error(422, 'validation_error', 'Project name is required.');
    }

    try {
        $stmt = $conn->prepare("
            INSERT INTO projects (org_id, name, code, description, status, created_by, updated_by)
            VALUES (?, ?, NULLIF(?, ''), NULLIF(?, ''), ?, ?, ?)
        ");
        $stmt->bind_param(
            'issssii',
            $org['org_id'],
            $name,
            $code,
            $description,
            $status,
            $org['user_id'],
            $org['user_id']
        );
        $stmt->execute();
        $projectId = (int) $conn->insert_id;
        $stmt->close();
    } catch (mysqli_sql_exception $e) {
        if ((int) $e->getCode() === 1062) {
            bc_v1_json_error(409, 'duplicate_project', 'Project name or code already exists in this organization.');
        }
        throw $e;
    }

    $project = webtest_checklist_fetch_project($conn, (int) $org['org_id'], $projectId);
    webtest_notifications_send($conn, array_values(array_diff(
        webtest_notification_org_manager_ids($conn, (int) $org['org_id']),
        [(int) $org['user_id']]
    )), [
        'type' => 'project',
        'event_key' => 'project_created',
        'title' => 'Project created',
        'body' => $name . ' was added to ' . $org['org_name'] . '.',
        'severity' => 'success',
        'link_path' => '/app/projects/' . $projectId,
        'actor_user_id' => (int) $org['user_id'],
        'org_id' => (int) $org['org_id'],
        'project_id' => $projectId,
    ]);
    bc_v1_json_success(['project' => $project], 201);
}

function bc_v1_projects_id_get(mysqli $conn, array $params): void
{
    bc_v1_require_method(['GET']);
    $actor = bc_v1_actor($conn, true);
    $projectId = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
    if ($projectId <= 0) {
        bc_v1_json_error(422, 'invalid_project', 'Project id is invalid.');
    }
    $requestedOrgId = bc_v1_get_int($_GET, 'org_id', 0);
    if ($requestedOrgId > 0 || !bc_v1_actor_is_all_scope($actor)) {
        $org = bc_v1_org_context($conn, $actor, $requestedOrgId);
    } else {
        $stmt = $conn->prepare("
            SELECT p.org_id
            FROM projects p
            JOIN org_members om
                ON om.org_id = p.org_id
               AND om.user_id = ?
            WHERE p.id = ?
            LIMIT 1
        ");
        $userId = (int) $actor['user']['id'];
        $stmt->bind_param('ii', $userId, $projectId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            bc_v1_json_error(404, 'project_not_found', 'Project not found.');
        }
        $org = bc_v1_org_context($conn, $actor, (int) $row['org_id']);
    }
    $project = webtest_checklist_fetch_project($conn, (int) $org['org_id'], $projectId);
    if (!$project) {
        bc_v1_json_error(404, 'project_not_found', 'Project not found.');
    }
    $project['org_name'] = (string) $org['org_name'];
    $batches = webtest_checklist_fetch_batches($conn, (int) $org['org_id'], $projectId);
    foreach ($batches as &$batch) {
        $batch['org_name'] = (string) $org['org_name'];
    }
    unset($batch);
    bc_v1_json_success(['project' => $project, 'batches' => $batches]);
}

function bc_v1_projects_id_patch(mysqli $conn, array $params): void
{
    bc_v1_require_method(['PATCH']);
    $actor = bc_v1_actor($conn, true);
    $payload = bc_v1_request_data();
    $projectId = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
    if ($projectId <= 0) {
        bc_v1_json_error(422, 'invalid_project', 'Project id is invalid.');
    }

    $org = bc_v1_org_context($conn, $actor, bc_v1_get_int($payload, 'org_id', 0));
    bc_v1_require_manager_role($org);
    $project = webtest_checklist_fetch_project($conn, (int) $org['org_id'], $projectId);
    if (!$project) {
        bc_v1_json_error(404, 'project_not_found', 'Project not found.');
    }

    $name = trim((string) ($payload['name'] ?? $project['name']));
    $code = trim((string) ($payload['code'] ?? ($project['code'] ?? '')));
    $description = trim((string) ($payload['description'] ?? ($project['description'] ?? '')));
    $status = webtest_checklist_normalize_enum((string) ($payload['status'] ?? $project['status']), ['active', 'archived'], (string) $project['status']);

    if ($name === '') {
        bc_v1_json_error(422, 'validation_error', 'Project name is required.');
    }

    try {
        $stmt = $conn->prepare("
            UPDATE projects
            SET name = ?, code = NULLIF(?, ''), description = NULLIF(?, ''), status = ?, updated_by = ?
            WHERE id = ? AND org_id = ?
        ");
        $stmt->bind_param('ssssiii', $name, $code, $description, $status, $org['user_id'], $projectId, $org['org_id']);
        $stmt->execute();
        $stmt->close();
    } catch (mysqli_sql_exception $e) {
        if ((int) $e->getCode() === 1062) {
            bc_v1_json_error(409, 'duplicate_project', 'Project name or code already exists in this organization.');
        }
        throw $e;
    }

    $updated = webtest_checklist_fetch_project($conn, (int) $org['org_id'], $projectId);
    webtest_notifications_send($conn, array_values(array_diff(
        webtest_notification_org_manager_ids($conn, (int) $org['org_id']),
        [(int) $org['user_id']]
    )), [
        'type' => 'project',
        'event_key' => 'project_updated',
        'title' => 'Project updated',
        'body' => $name . ' was updated.',
        'severity' => 'default',
        'link_path' => '/app/projects/' . $projectId,
        'actor_user_id' => (int) $org['user_id'],
        'org_id' => (int) $org['org_id'],
        'project_id' => $projectId,
    ]);
    bc_v1_json_success(['project' => $updated]);
}

function bc_v1_projects_status_post(mysqli $conn, array $params, string $nextStatus): void
{
    bc_v1_require_method(['POST']);
    $actor = bc_v1_actor($conn, true);
    $payload = bc_v1_request_data();
    $projectId = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
    if ($projectId <= 0) {
        bc_v1_json_error(422, 'invalid_project', 'Project id is invalid.');
    }

    $org = bc_v1_org_context($conn, $actor, bc_v1_get_int($payload, 'org_id', 0));
    bc_v1_require_manager_role($org);
    $project = webtest_checklist_fetch_project($conn, (int) $org['org_id'], $projectId);
    if (!$project) {
        bc_v1_json_error(404, 'project_not_found', 'Project not found.');
    }

    $stmt = $conn->prepare("UPDATE projects SET status = ?, updated_by = ? WHERE id = ? AND org_id = ?");
    $stmt->bind_param('siii', $nextStatus, $org['user_id'], $projectId, $org['org_id']);
    $stmt->execute();
    $stmt->close();

    webtest_notifications_send($conn, array_values(array_diff(
        webtest_notification_org_manager_ids($conn, (int) $org['org_id']),
        [(int) $org['user_id']]
    )), [
        'type' => 'project',
        'event_key' => $nextStatus === 'archived' ? 'project_archived' : 'project_activated',
        'title' => $nextStatus === 'archived' ? 'Project archived' : 'Project activated',
        'body' => 'Project #' . $projectId . ' is now ' . $nextStatus . '.',
        'severity' => $nextStatus === 'archived' ? 'alert' : 'success',
        'link_path' => '/app/projects/' . $projectId,
        'actor_user_id' => (int) $org['user_id'],
        'org_id' => (int) $org['org_id'],
        'project_id' => $projectId,
    ]);

    bc_v1_json_success(['project_id' => $projectId, 'status' => $nextStatus]);
}
