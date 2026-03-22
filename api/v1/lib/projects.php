<?php

declare(strict_types=1);

function bc_v1_projects_get(mysqli $conn, array $params): void
{
    bc_v1_require_method(['GET']);
    $actor = bc_v1_actor($conn, true);
    $org = bc_v1_org_context($conn, $actor, bc_v1_get_int($_GET, 'org_id', 0));
    $includeArchived = ((string) ($_GET['show'] ?? 'active')) === 'all';
    $projects = bugcatcher_checklist_fetch_projects($conn, (int) $org['org_id'], $includeArchived);

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
    $status = bugcatcher_checklist_normalize_enum((string) ($payload['status'] ?? 'active'), ['active', 'archived'], 'active');

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

    $project = bugcatcher_checklist_fetch_project($conn, (int) $org['org_id'], $projectId);
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
    $org = bc_v1_org_context($conn, $actor, bc_v1_get_int($_GET, 'org_id', 0));
    $project = bugcatcher_checklist_fetch_project($conn, (int) $org['org_id'], $projectId);
    if (!$project) {
        bc_v1_json_error(404, 'project_not_found', 'Project not found.');
    }
    $batches = bugcatcher_checklist_fetch_batches($conn, (int) $org['org_id'], $projectId);
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
    $project = bugcatcher_checklist_fetch_project($conn, (int) $org['org_id'], $projectId);
    if (!$project) {
        bc_v1_json_error(404, 'project_not_found', 'Project not found.');
    }

    $name = trim((string) ($payload['name'] ?? $project['name']));
    $code = trim((string) ($payload['code'] ?? ($project['code'] ?? '')));
    $description = trim((string) ($payload['description'] ?? ($project['description'] ?? '')));
    $status = bugcatcher_checklist_normalize_enum((string) ($payload['status'] ?? $project['status']), ['active', 'archived'], (string) $project['status']);

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

    $updated = bugcatcher_checklist_fetch_project($conn, (int) $org['org_id'], $projectId);
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
    $project = bugcatcher_checklist_fetch_project($conn, (int) $org['org_id'], $projectId);
    if (!$project) {
        bc_v1_json_error(404, 'project_not_found', 'Project not found.');
    }

    $stmt = $conn->prepare("UPDATE projects SET status = ?, updated_by = ? WHERE id = ? AND org_id = ?");
    $stmt->bind_param('siii', $nextStatus, $org['user_id'], $projectId, $org['org_id']);
    $stmt->execute();
    $stmt->close();

    bc_v1_json_success(['project_id' => $projectId, 'status' => $nextStatus]);
}
