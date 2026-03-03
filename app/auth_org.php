<?php

require_once dirname(__DIR__) . '/db.php';

function bugcatcher_post_int(string $key): int
{
    $value = $_POST[$key] ?? '';
    return ctype_digit((string) $value) ? (int) $value : 0;
}

function bugcatcher_get_int(string $key): int
{
    $value = $_GET[$key] ?? '';
    return ctype_digit((string) $value) ? (int) $value : 0;
}

function bugcatcher_fetch_org_membership(mysqli $conn, int $orgId, int $userId): ?array
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

function bugcatcher_require_org_context(mysqli $conn): array
{
    global $current_user_id, $current_username, $current_role;

    $orgId = (int) ($_SESSION['active_org_id'] ?? 0);
    if ($orgId <= 0) {
        header("Location: /organization.php");
        exit;
    }

    $membership = bugcatcher_fetch_org_membership($conn, $orgId, $current_user_id);
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
