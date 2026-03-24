<?php

declare(strict_types=1);

function bc_v1_dashboard_workload_sort_rows(array &$rows): void
{
    usort($rows, static function (array $left, array $right): int {
        $leftUnassigned = !empty($left['is_unassigned']);
        $rightUnassigned = !empty($right['is_unassigned']);
        if ($leftUnassigned !== $rightUnassigned) {
            return $leftUnassigned ? -1 : 1;
        }

        $openCompare = (int) ($right['open_items'] ?? 0) <=> (int) ($left['open_items'] ?? 0);
        if ($openCompare !== 0) {
            return $openCompare;
        }

        $assignedCompare = (int) ($right['assigned_items'] ?? 0) <=> (int) ($left['assigned_items'] ?? 0);
        if ($assignedCompare !== 0) {
            return $assignedCompare;
        }

        return strcasecmp((string) ($left['display_name'] ?? ''), (string) ($right['display_name'] ?? ''));
    });
}

function bc_v1_dashboard_build_qa_lead_checklist(mysqli $conn, int $orgId): array
{
    $testerStmt = $conn->prepare("
        SELECT
            u.id AS user_id,
            COALESCE(NULLIF(TRIM(u.username), ''), NULLIF(TRIM(u.email), ''), CONCAT('User #', u.id)) AS display_name
        FROM org_members om
        JOIN users u ON u.id = om.user_id
        WHERE om.org_id = ? AND om.role = 'QA Tester'
        ORDER BY display_name ASC, u.id ASC
    ");
    $testerStmt->bind_param('i', $orgId);
    $testerStmt->execute();
    $testerRows = $testerStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $testerStmt->close();

    $orgTotals = [];
    foreach ($testerRows as $testerRow) {
        $userId = (int) ($testerRow['user_id'] ?? 0);
        $orgTotals[$userId] = [
            'user_id' => $userId,
            'display_name' => (string) ($testerRow['display_name'] ?? ('User #' . $userId)),
            'assigned_items' => 0,
            'open_items' => 0,
            'is_unassigned' => false,
        ];
    }

    $projectMap = [];
    $workloadStmt = $conn->prepare("
        SELECT
            p.id AS project_id,
            p.name AS project_name,
            ci.assigned_to_user_id,
            ci.status,
            COALESCE(NULLIF(TRIM(u.username), ''), NULLIF(TRIM(u.email), ''), CONCAT('User #', u.id)) AS assigned_name
        FROM checklist_items ci
        JOIN projects p ON p.id = ci.project_id
        LEFT JOIN users u ON u.id = ci.assigned_to_user_id
        LEFT JOIN org_members om_assignee
            ON om_assignee.org_id = ci.org_id
           AND om_assignee.user_id = ci.assigned_to_user_id
        WHERE ci.org_id = ?
          AND p.org_id = ci.org_id
          AND p.status = 'active'
          AND (
            (ci.required_role = 'QA Tester' AND (ci.assigned_to_user_id IS NULL OR ci.assigned_to_user_id = 0))
            OR (ci.assigned_to_user_id IS NOT NULL AND ci.assigned_to_user_id > 0 AND om_assignee.role = 'QA Tester')
          )
        ORDER BY p.name ASC, ci.id ASC
    ");
    $workloadStmt->bind_param('i', $orgId);
    $workloadStmt->execute();
    $workloadRows = $workloadStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $workloadStmt->close();

    foreach ($workloadRows as $workloadRow) {
        $projectId = (int) ($workloadRow['project_id'] ?? 0);
        $status = (string) ($workloadRow['status'] ?? '');
        $assignedToUserId = (int) ($workloadRow['assigned_to_user_id'] ?? 0);
        $isUnassigned = $assignedToUserId <= 0;
        $rowKey = $isUnassigned ? 'unassigned' : (string) $assignedToUserId;

        if (!isset($projectMap[$projectId])) {
            $projectMap[$projectId] = [
                'project_id' => $projectId,
                'project_name' => (string) ($workloadRow['project_name'] ?? ('Project #' . $projectId)),
                'assigned_items' => 0,
                'open_items' => 0,
                'testers' => [],
            ];
        }

        if ($isUnassigned) {
            if (!isset($orgTotals['unassigned'])) {
                $orgTotals['unassigned'] = [
                    'user_id' => null,
                    'display_name' => 'Unassigned',
                    'assigned_items' => 0,
                    'open_items' => 0,
                    'is_unassigned' => true,
                ];
            }
            if (!isset($projectMap[$projectId]['testers'][$rowKey])) {
                $projectMap[$projectId]['testers'][$rowKey] = [
                    'user_id' => null,
                    'display_name' => 'Unassigned',
                    'assigned_items' => 0,
                    'open_items' => 0,
                    'is_unassigned' => true,
                ];
            }
        } else {
            if (!isset($orgTotals[$assignedToUserId])) {
                $orgTotals[$assignedToUserId] = [
                    'user_id' => $assignedToUserId,
                    'display_name' => (string) ($workloadRow['assigned_name'] ?? ('User #' . $assignedToUserId)),
                    'assigned_items' => 0,
                    'open_items' => 0,
                    'is_unassigned' => false,
                ];
            }
            if (!isset($projectMap[$projectId]['testers'][$rowKey])) {
                $projectMap[$projectId]['testers'][$rowKey] = [
                    'user_id' => $assignedToUserId,
                    'display_name' => (string) ($workloadRow['assigned_name'] ?? ('User #' . $assignedToUserId)),
                    'assigned_items' => 0,
                    'open_items' => 0,
                    'is_unassigned' => false,
                ];
            }
        }

        $orgTotals[$rowKey]['assigned_items']++;
        $projectMap[$projectId]['assigned_items']++;
        $projectMap[$projectId]['testers'][$rowKey]['assigned_items']++;

        if ($status === 'open') {
            $orgTotals[$rowKey]['open_items']++;
            $projectMap[$projectId]['open_items']++;
            $projectMap[$projectId]['testers'][$rowKey]['open_items']++;
        }
    }

    if (!isset($orgTotals['unassigned'])) {
        $orgTotals['unassigned'] = [
            'user_id' => null,
            'display_name' => 'Unassigned',
            'assigned_items' => 0,
            'open_items' => 0,
            'is_unassigned' => true,
        ];
    }

    $orgTotalsRows = array_values($orgTotals);
    bc_v1_dashboard_workload_sort_rows($orgTotalsRows);

    $projects = array_values(array_map(static function (array $project): array {
        $testers = array_values($project['testers']);
        bc_v1_dashboard_workload_sort_rows($testers);
        $project['testers'] = $testers;
        return $project;
    }, $projectMap));

    usort($projects, static function (array $left, array $right): int {
        $openCompare = (int) ($right['open_items'] ?? 0) <=> (int) ($left['open_items'] ?? 0);
        if ($openCompare !== 0) {
            return $openCompare;
        }

        return strcasecmp((string) ($left['project_name'] ?? ''), (string) ($right['project_name'] ?? ''));
    });

    return [
        'org_totals' => $orgTotalsRows,
        'projects' => $projects,
    ];
}

function bc_v1_dashboard_summary_get(mysqli $conn, array $params): void
{
    bc_v1_require_method(['GET']);
    $actor = bc_v1_actor($conn, true);
    $org = bc_v1_org_context($conn, $actor, bc_v1_get_int($_GET, 'org_id', 0));
    $scope = bc_v1_issue_visibility_scope($org);

    $summary = [
        'open_issues' => bc_v1_issue_count($conn, (int) $org['org_id'], 'open'),
        'closed_issues' => bc_v1_issue_count($conn, (int) $org['org_id'], 'closed'),
        'active_projects' => 0,
        'checklist_open_items' => 0,
        'unread_notifications' => 0,
    ];

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM projects
        WHERE org_id = ? AND status = 'active'
    ");
    $stmt->bind_param('i', $org['org_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $summary['active_projects'] = (int) ($row['total'] ?? 0);

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM checklist_items
        WHERE org_id = ? AND status IN ('open', 'in_progress', 'blocked', 'failed')
    ");
    $stmt->bind_param('i', $org['org_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $summary['checklist_open_items'] = (int) ($row['total'] ?? 0);

    $counts = bugcatcher_notifications_list($conn, (int) $actor['user']['id'], 'all', 5);
    $summary['unread_notifications'] = (int) ($counts['unread_count'] ?? 0);

    $days = [];
    for ($offset = 6; $offset >= 0; $offset--) {
        $days[] = date('Y-m-d', strtotime("-{$offset} days"));
    }
    $trend = [];
    foreach ($days as $day) {
        $trend[$day] = [
            'day' => date('D', strtotime($day)),
            'issues' => 0,
            'projects' => 0,
            'checklist' => 0,
        ];
    }

    $issueTrendStmt = $conn->prepare("
        SELECT DATE(i.created_at) AS day_key, COUNT(*) AS total
        FROM issues i
        WHERE i.org_id = ? AND DATE(i.created_at) BETWEEN ? AND ?
        GROUP BY DATE(i.created_at)
        ORDER BY DATE(i.created_at) ASC
    ");
    $issueTrendStmt->bind_param('iss', $org['org_id'], $days[0], $days[count($days) - 1]);
    $issueTrendStmt->execute();
    $issueRows = $issueTrendStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $issueTrendStmt->close();
    foreach ($issueRows as $issueRow) {
        $key = (string) $issueRow['day_key'];
        if (isset($trend[$key])) {
            $trend[$key]['issues'] = (int) $issueRow['total'];
        }
    }

    $projectTrendStmt = $conn->prepare("
        SELECT DATE(created_at) AS day_key, COUNT(*) AS total
        FROM projects
        WHERE org_id = ? AND DATE(created_at) BETWEEN ? AND ?
        GROUP BY DATE(created_at)
        ORDER BY DATE(created_at) ASC
    ");
    $projectTrendStmt->bind_param('iss', $org['org_id'], $days[0], $days[count($days) - 1]);
    $projectTrendStmt->execute();
    $projectRows = $projectTrendStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $projectTrendStmt->close();
    foreach ($projectRows as $projectRow) {
        $key = (string) $projectRow['day_key'];
        if (isset($trend[$key])) {
            $trend[$key]['projects'] = (int) $projectRow['total'];
        }
    }

    $checklistTrendStmt = $conn->prepare("
        SELECT DATE(created_at) AS day_key, COUNT(*) AS total
        FROM checklist_batches
        WHERE org_id = ? AND DATE(created_at) BETWEEN ? AND ?
        GROUP BY DATE(created_at)
        ORDER BY DATE(created_at) ASC
    ");
    $checklistTrendStmt->bind_param('iss', $org['org_id'], $days[0], $days[count($days) - 1]);
    $checklistTrendStmt->execute();
    $checklistRows = $checklistTrendStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $checklistTrendStmt->close();
    foreach ($checklistRows as $checklistRow) {
        $key = (string) $checklistRow['day_key'];
        if (isset($trend[$key])) {
            $trend[$key]['checklist'] = (int) $checklistRow['total'];
        }
    }

    $recentIssuesStmt = $conn->prepare("
        SELECT i.id, i.title, i.status, i.assign_status, u.username AS author_username
        FROM issues i
        LEFT JOIN users u ON u.id = i.author_id
        WHERE i.org_id = ?
        ORDER BY i.created_at DESC, i.id DESC
        LIMIT 5
    ");
    $recentIssuesStmt->bind_param('i', $org['org_id']);
    $recentIssuesStmt->execute();
    $recentIssues = $recentIssuesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $recentIssuesStmt->close();

    $qaLeadChecklist = null;
    if ((string) ($org['org_role'] ?? '') === 'QA Lead') {
        $qaLeadChecklist = bc_v1_dashboard_build_qa_lead_checklist($conn, (int) $org['org_id']);
    }

    bc_v1_json_success([
        'org' => $org,
        'scope' => $scope,
        'summary' => $summary,
        'trend' => array_values($trend),
        'recent_issues' => $recentIssues,
        'qa_lead_checklist' => $qaLeadChecklist,
    ]);
}
