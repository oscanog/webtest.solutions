<?php

declare(strict_types=1);

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

    bc_v1_json_success([
        'org' => $org,
        'scope' => $scope,
        'summary' => $summary,
        'trend' => array_values($trend),
        'recent_issues' => $recentIssues,
    ]);
}
