<?php

require_once dirname(__DIR__) . '/db.php';

function webtest_post_int(string $key): int
{
    $value = $_POST[$key] ?? '';
    return ctype_digit((string) $value) ? (int) $value : 0;
}

function webtest_get_int(string $key): int
{
    $value = $_GET[$key] ?? '';
    return ctype_digit((string) $value) ? (int) $value : 0;
}

function webtest_fetch_org_membership(mysqli $conn, int $orgId, int $userId): ?array
{
    $stmt = $conn->prepare("
        SELECT om.role, o.name AS org_name, o.owner_id
        FROM org_members om
        JOIN organizations o ON o.id = om.org_id
        WHERE om.org_id = ? AND om.user_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $orgId, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function webtest_sync_active_org_from_request(mysqli $conn): int
{
    global $current_user_id;

    $requestedOrgId = webtest_get_int('org_id');
    if ($requestedOrgId > 0 && (int) $current_user_id > 0) {
        $membership = webtest_fetch_org_membership($conn, $requestedOrgId, (int) $current_user_id);
        if ($membership) {
            $_SESSION['active_org_id'] = $requestedOrgId;

            $stmt = $conn->prepare("UPDATE users SET last_active_org_id = ? WHERE id = ?");
            $stmt->bind_param('ii', $requestedOrgId, $current_user_id);
            $stmt->execute();
            $stmt->close();

            return $requestedOrgId;
        }
    }

    return (int) ($_SESSION['active_org_id'] ?? 0);
}

function webtest_require_org_context(mysqli $conn): array
{
    global $current_user_id, $current_username, $current_role;

    $orgId = webtest_sync_active_org_from_request($conn);
    if ($orgId <= 0) {
        header("Location: " . webtest_path('zen/organization.php'));
        exit;
    }

    $membership = webtest_fetch_org_membership($conn, $orgId, $current_user_id);
    if (!$membership) {
        die("You are not a member of the active organization.");
    }

    return [
        'org_id' => $orgId,
        'org_name' => $membership['org_name'],
        'org_owner_id' => (int) $membership['owner_id'],
        'org_role' => $membership['role'],
        'is_org_owner' => (int) $membership['owner_id'] === (int) $current_user_id,
        'current_user_id' => $current_user_id,
        'current_username' => $current_username,
        'current_role' => $current_role,
    ];
}
