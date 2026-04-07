<?php

declare(strict_types=1);

function bc_v1_org_membership_role(mysqli $conn, int $orgId, int $userId): ?string
{
    $stmt = $conn->prepare("SELECT role FROM org_members WHERE org_id = ? AND user_id = ? LIMIT 1");
    $stmt->bind_param('ii', $orgId, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (string) $row['role'] : null;
}

function bc_v1_org_member_count(mysqli $conn, int $orgId): int
{
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM org_members WHERE org_id = ?");
    $stmt->bind_param('i', $orgId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row['c'] ?? 0);
}

function bc_v1_orgs_members_get(mysqli $conn, array $params): void
{
    bc_v1_require_method(['GET']);
    $actor = bc_v1_actor($conn, true);
    $orgId = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
    if ($orgId <= 0) {
        bc_v1_json_error(422, 'invalid_org', 'Organization id is invalid.');
    }

    $org = bc_v1_org_context($conn, $actor, $orgId);
    if (!$org['is_org_owner'] && !bc_v1_actor_is_admin($actor)) {
        bc_v1_json_error(403, 'forbidden', 'Only organization owners or system admins can view members.');
    }

    $stmt = $conn->prepare("
      SELECT
        om.user_id,
        om.role,
        om.joined_at,
        u.username,
        u.email,
        u.role AS system_role,
        CASE WHEN o.owner_id = om.user_id THEN 1 ELSE 0 END AS is_owner
      FROM org_members om
      JOIN users u ON u.id = om.user_id
      JOIN organizations o ON o.id = om.org_id
      WHERE om.org_id = ?
      ORDER BY
        CASE WHEN o.owner_id = om.user_id THEN 0 ELSE 1 END ASC,
        u.username ASC
    ");
    $stmt->bind_param('i', $orgId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $members = array_map(static function (array $row): array {
        return [
            'user_id' => (int) $row['user_id'],
            'username' => (string) $row['username'],
            'email' => (string) $row['email'],
            'system_role' => webtest_normalize_system_role((string) $row['system_role']),
            'org_role' => (string) $row['role'],
            'is_owner' => (bool) ((int) $row['is_owner']),
            'joined_at' => (string) $row['joined_at'],
        ];
    }, $rows);

    bc_v1_json_success([
        'org' => $org,
        'members' => $members,
    ]);
}

function bc_v1_orgs_members_post(mysqli $conn, array $params): void
{
    bc_v1_require_method(['POST']);
    $actor = bc_v1_actor($conn, true);
    bc_v1_require_super_admin($actor);

    $orgId = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
    if ($orgId <= 0) {
        bc_v1_json_error(422, 'invalid_org', 'Organization id is invalid.');
    }

    $findOrg = $conn->prepare("SELECT id, name FROM organizations WHERE id = ? LIMIT 1");
    $findOrg->bind_param('i', $orgId);
    $findOrg->execute();
    $org = $findOrg->get_result()->fetch_assoc();
    $findOrg->close();
    if (!$org) {
        bc_v1_json_error(404, 'org_not_found', 'Organization not found.');
    }

    $payload = bc_v1_request_data();

    $username = trim((string) ($payload['username'] ?? ''));
    $email = trim((string) ($payload['email'] ?? ''));
    $password = (string) ($payload['password'] ?? '');
    $confirm = (string) ($payload['confirm_password'] ?? $payload['cpass'] ?? '');
    $orgRole = trim((string) ($payload['org_role'] ?? 'member'));

    if ($username === '' || $email === '' || $password === '' || $confirm === '') {
        bc_v1_json_error(422, 'validation_error', 'username, email, password, and confirm_password are required.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        bc_v1_json_error(422, 'invalid_email', 'Email must be a valid email address.');
    }
    if ($password !== $confirm) {
        bc_v1_json_error(422, 'password_mismatch', 'Password does not match.');
    }

    $assignableRoles = array_values(array_filter(BC_V1_ORG_ROLES, static fn (string $role): bool => $role !== 'owner'));
    if (!in_array($orgRole, $assignableRoles, true)) {
        bc_v1_json_error(422, 'invalid_role', 'Invalid organization role selected.');
    }

    $check = $conn->prepare("SELECT id, username, email FROM users WHERE email = ? OR username = ? LIMIT 1");
    $check->bind_param('ss', $email, $username);
    $check->execute();
    $existing = $check->get_result()->fetch_assoc();
    $check->close();

    if ($existing) {
        if (strcasecmp((string) ($existing['email'] ?? ''), $email) === 0) {
            bc_v1_json_error(409, 'email_exists', 'This email is already used. Try another one.');
        }
        bc_v1_json_error(409, 'username_exists', 'This username is already used. Try another one.');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $conn->begin_transaction();

    try {
        $insertUser = $conn->prepare("INSERT INTO users (username, email, password, role, last_active_org_id) VALUES (?, ?, ?, 'user', ?)");
        $insertUser->bind_param('sssi', $username, $email, $hash, $orgId);
        $insertUser->execute();
        $userId = (int) $conn->insert_id;
        $insertUser->close();

        $insertMember = $conn->prepare("INSERT INTO org_members (org_id, user_id, role) VALUES (?, ?, ?)");
        $insertMember->bind_param('iis', $orgId, $userId, $orgRole);
        $insertMember->execute();
        $insertMember->close();

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        bc_v1_json_error(500, 'create_member_failed', 'Unable to create the new user.', $e->getMessage());
    }

    bc_v1_json_success([
        'created' => true,
        'org_id' => $orgId,
        'user_id' => $userId,
        'member' => [
            'user_id' => $userId,
            'username' => $username,
            'email' => $email,
            'system_role' => 'user',
            'org_role' => $orgRole,
            'is_owner' => false,
            'joined_at' => date('Y-m-d H:i:s'),
        ],
        'message' => 'New user created for ' . (string) ($org['name'] ?? 'the organization') . '.',
    ], 201);
}

function bc_v1_orgs_get(mysqli $conn, array $params): void
{
    bc_v1_require_method(['GET']);
    $actor = bc_v1_actor($conn, true);
    $userId = (int) $actor['user']['id'];

    $memberOrgs = [];
    $stmt = $conn->prepare("
      SELECT o.id, o.name, o.owner_id, o.created_at, om.role AS my_role
      FROM org_members om
      JOIN organizations o ON o.id = om.org_id
      WHERE om.user_id = ?
      ORDER BY o.name ASC
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as $row) {
        $memberOrgs[] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'owner_id' => (int) $row['owner_id'],
            'my_role' => (string) $row['my_role'],
            'is_owner' => (int) $row['owner_id'] === $userId,
            'created_at' => (string) $row['created_at'],
        ];
    }

    $joinable = [];
    $stmt = $conn->prepare("
      SELECT o.id, o.name, u.username AS owner_name,
             (SELECT COUNT(*) FROM org_members om2 WHERE om2.org_id = o.id) AS member_count
      FROM organizations o
      JOIN users u ON u.id = o.owner_id
      WHERE NOT EXISTS (
        SELECT 1 FROM org_members om
        WHERE om.org_id = o.id AND om.user_id = ?
      )
      ORDER BY o.name ASC
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $joinable = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    bc_v1_json_success([
        'active_org_id' => (int) ($actor['active_org_id'] ?? 0),
        'organizations' => $memberOrgs,
        'joinable_organizations' => $joinable,
    ]);
}

function bc_v1_orgs_post(mysqli $conn, array $params): void
{
    bc_v1_require_method(['POST']);
    $actor = bc_v1_actor($conn, true);
    $payload = bc_v1_request_data();
    $name = trim((string) ($payload['name'] ?? $payload['org_name'] ?? ''));
    $name = preg_replace('/\s+/', ' ', $name);
    if ($name === '') {
        bc_v1_json_error(422, 'validation_error', 'Organization name is required.');
    }

    $check = $conn->prepare("SELECT id FROM organizations WHERE LOWER(name) = LOWER(?) LIMIT 1");
    $check->bind_param('s', $name);
    $check->execute();
    $exists = $check->get_result()->fetch_assoc();
    $check->close();
    if ($exists) {
        bc_v1_json_error(409, 'duplicate_org_name', 'Organization name already exists.');
    }

    $userId = (int) $actor['user']['id'];
    $conn->begin_transaction();
    try {
        $insOrg = $conn->prepare("INSERT INTO organizations (name, owner_id) VALUES (?, ?)");
        $insOrg->bind_param('si', $name, $userId);
        $insOrg->execute();
        $orgId = (int) $insOrg->insert_id;
        $insOrg->close();

        $insMember = $conn->prepare("INSERT INTO org_members (org_id, user_id, role) VALUES (?, ?, 'owner')");
        $insMember->bind_param('ii', $orgId, $userId);
        $insMember->execute();
        $insMember->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        bc_v1_json_error(500, 'create_org_failed', 'Failed to create organization.', $e->getMessage());
    }

    bc_v1_set_active_org($conn, $userId, $orgId);
    webtest_notifications_send($conn, [$userId], [
        'type' => 'org',
        'event_key' => 'org_created',
        'title' => 'Organization created',
        'body' => $name . ' is ready to use.',
        'severity' => 'success',
        'link_path' => '/app/organizations',
        'actor_user_id' => $userId,
        'org_id' => $orgId,
    ]);
    bc_v1_json_success(['created' => true, 'org_id' => $orgId, 'active_org_id' => $orgId], 201);
}

function bc_v1_orgs_join_post(mysqli $conn, array $params): void
{
    bc_v1_require_method(['POST']);
    $actor = bc_v1_actor($conn, true);
    $orgId = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
    if ($orgId <= 0) {
        bc_v1_json_error(422, 'invalid_org', 'Organization id is invalid.');
    }

    $find = $conn->prepare("SELECT id FROM organizations WHERE id = ? LIMIT 1");
    $find->bind_param('i', $orgId);
    $find->execute();
    $org = $find->get_result()->fetch_assoc();
    $find->close();
    if (!$org) {
        bc_v1_json_error(404, 'org_not_found', 'Organization not found.');
    }

    $userId = (int) $actor['user']['id'];
    try {
        $ins = $conn->prepare("INSERT INTO org_members (org_id, user_id, role) VALUES (?, ?, 'member')");
        $ins->bind_param('ii', $orgId, $userId);
        $ins->execute();
        $ins->close();
    } catch (mysqli_sql_exception $e) {
        if ((int) $e->getCode() === 1062) {
            bc_v1_json_error(409, 'already_member', 'You already joined this organization.');
        }
        throw $e;
    }

    bc_v1_set_active_org($conn, $userId, $orgId);
    webtest_notifications_send($conn, array_merge([$userId], webtest_notification_org_owner_ids($conn, $orgId)), [
        'type' => 'org',
        'event_key' => 'org_joined',
        'title' => 'Member joined organization',
        'body' => 'A member joined organization #' . $orgId . '.',
        'severity' => 'default',
        'link_path' => '/app/organizations',
        'actor_user_id' => $userId,
        'org_id' => $orgId,
    ]);
    bc_v1_json_success(['joined' => true, 'org_id' => $orgId, 'active_org_id' => $orgId]);
}

function bc_v1_orgs_leave_post(mysqli $conn, array $params): void
{
    bc_v1_require_method(['POST']);
    $actor = bc_v1_actor($conn, true);
    $orgId = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
    if ($orgId <= 0) {
        bc_v1_json_error(422, 'invalid_org', 'Organization id is invalid.');
    }

    $userId = (int) $actor['user']['id'];
    $role = bc_v1_org_membership_role($conn, $orgId, $userId);
    if ($role === null) {
        bc_v1_json_error(403, 'not_member', 'You are not a member of this organization.');
    }

    if ($role === 'owner') {
        if (bc_v1_org_member_count($conn, $orgId) > 1) {
            bc_v1_json_error(422, 'owner_transfer_required', 'Transfer ownership before leaving organization.');
        }
        $stmt = $conn->prepare("DELETE FROM organizations WHERE id = ?");
        $stmt->bind_param('i', $orgId);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare("DELETE FROM org_members WHERE org_id = ? AND user_id = ?");
        $stmt->bind_param('ii', $orgId, $userId);
        $stmt->execute();
        $stmt->close();
    }

    $nextOrgId = bc_v1_first_org_id($conn, $userId);
    bc_v1_set_active_org($conn, $userId, $nextOrgId);
    webtest_notifications_send($conn, webtest_notification_org_owner_ids($conn, $orgId), [
        'type' => 'org',
        'event_key' => 'org_left',
        'title' => 'Member left organization',
        'body' => 'A member left organization #' . $orgId . '.',
        'severity' => 'default',
        'link_path' => '/app/organizations',
        'actor_user_id' => $userId,
        'org_id' => $orgId,
    ]);
    bc_v1_json_success(['left' => true, 'org_id' => $orgId, 'active_org_id' => $nextOrgId]);
}

function bc_v1_orgs_transfer_owner_post(mysqli $conn, array $params): void
{
    bc_v1_require_method(['POST']);
    $actor = bc_v1_actor($conn, true);
    $payload = bc_v1_request_data();
    $orgId = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
    $newOwnerId = bc_v1_get_int($payload, 'new_owner_id', 0);
    $currentUserId = (int) $actor['user']['id'];

    if ($orgId <= 0 || $newOwnerId <= 0) {
        bc_v1_json_error(422, 'validation_error', 'org id and new_owner_id are required.');
    }
    if (bc_v1_org_membership_role($conn, $orgId, $currentUserId) !== 'owner') {
        bc_v1_json_error(403, 'forbidden', 'Only owner can transfer ownership.');
    }
    if ($newOwnerId === $currentUserId) {
        bc_v1_json_error(422, 'invalid_owner', 'You are already the owner.');
    }
    if (bc_v1_org_membership_role($conn, $orgId, $newOwnerId) === null) {
        bc_v1_json_error(422, 'target_not_member', 'Selected user is not a member of this organization.');
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("UPDATE org_members SET role = 'member' WHERE org_id = ? AND user_id = ?");
        $stmt->bind_param('ii', $orgId, $currentUserId);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("UPDATE org_members SET role = 'owner' WHERE org_id = ? AND user_id = ?");
        $stmt->bind_param('ii', $orgId, $newOwnerId);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("UPDATE organizations SET owner_id = ? WHERE id = ?");
        $stmt->bind_param('ii', $newOwnerId, $orgId);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        bc_v1_json_error(500, 'transfer_failed', 'Failed to transfer ownership.', $e->getMessage());
    }

    webtest_notifications_send($conn, [$newOwnerId, $currentUserId], [
        'type' => 'org',
        'event_key' => 'org_transfer_owner',
        'title' => 'Organization ownership transferred',
        'body' => 'Ownership was transferred in organization #' . $orgId . '.',
        'severity' => 'alert',
        'link_path' => '/app/manage-users',
        'actor_user_id' => $currentUserId,
        'org_id' => $orgId,
    ]);

    bc_v1_json_success(['transferred' => true, 'org_id' => $orgId, 'new_owner_id' => $newOwnerId]);
}

function bc_v1_orgs_delete(mysqli $conn, array $params): void
{
    bc_v1_require_method(['DELETE']);
    $actor = bc_v1_actor($conn, true);
    $payload = bc_v1_request_data();
    $orgId = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
    $confirm = trim((string) ($payload['confirm'] ?? $payload['confirm_text'] ?? ''));
    $userId = (int) $actor['user']['id'];

    if ($orgId <= 0) {
        bc_v1_json_error(422, 'invalid_org', 'Organization id is invalid.');
    }
    if ($confirm !== 'DELETE') {
        bc_v1_json_error(422, 'confirmation_required', 'You must send confirm=DELETE.');
    }
    if (bc_v1_org_membership_role($conn, $orgId, $userId) !== 'owner') {
        bc_v1_json_error(403, 'forbidden', 'Only owner can delete organization.');
    }

    $stmt = $conn->prepare("DELETE FROM organizations WHERE id = ?");
    $stmt->bind_param('i', $orgId);
    $stmt->execute();
    $stmt->close();

    $nextOrgId = bc_v1_first_org_id($conn, $userId);
    bc_v1_set_active_org($conn, $userId, $nextOrgId);
    webtest_notifications_send($conn, [$userId], [
        'type' => 'org',
        'event_key' => 'org_deleted',
        'title' => 'Organization deleted',
        'body' => 'Organization #' . $orgId . ' was deleted.',
        'severity' => 'alert',
        'link_path' => '/app/organizations',
        'actor_user_id' => $userId,
    ]);
    bc_v1_json_success(['deleted' => true, 'org_id' => $orgId, 'active_org_id' => $nextOrgId]);
}

function bc_v1_orgs_member_role_patch(mysqli $conn, array $params): void
{
    bc_v1_require_method(['PATCH']);
    $actor = bc_v1_actor($conn, true);
    $payload = bc_v1_request_data();
    $orgId = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
    $targetUserId = ctype_digit((string) ($params['userId'] ?? '')) ? (int) $params['userId'] : 0;
    $newRole = trim((string) ($payload['role'] ?? $payload['new_role'] ?? ''));
    $userId = (int) $actor['user']['id'];

    if ($orgId <= 0 || $targetUserId <= 0 || $newRole === '') {
        bc_v1_json_error(422, 'validation_error', 'org id, target user id, and role are required.');
    }
    if (!in_array($newRole, BC_V1_ORG_ROLES, true)) {
        bc_v1_json_error(422, 'invalid_role', 'Invalid role selected.');
    }
    if (bc_v1_org_membership_role($conn, $orgId, $userId) !== 'owner') {
        bc_v1_json_error(403, 'forbidden', 'Only owner can change roles.');
    }
    if ($targetUserId === $userId) {
        bc_v1_json_error(422, 'invalid_target', 'You cannot change your own role.');
    }
    $targetRole = bc_v1_org_membership_role($conn, $orgId, $targetUserId);
    if ($targetRole === null) {
        bc_v1_json_error(404, 'member_not_found', 'Member not found in this organization.');
    }
    if ($targetRole === 'owner') {
        bc_v1_json_error(422, 'owner_role_locked', 'Use transfer ownership to change owner role.');
    }

    $stmt = $conn->prepare("UPDATE org_members SET role = ? WHERE org_id = ? AND user_id = ?");
    $stmt->bind_param('sii', $newRole, $orgId, $targetUserId);
    $stmt->execute();
    $stmt->close();

    webtest_notifications_send($conn, [$targetUserId, $userId], [
        'type' => 'org',
        'event_key' => 'org_role_changed',
        'title' => 'Organization role updated',
        'body' => 'Role changed to ' . $newRole . '.',
        'severity' => 'alert',
        'link_path' => '/app/manage-users',
        'actor_user_id' => $userId,
        'org_id' => $orgId,
    ]);
    bc_v1_json_success(['updated' => true, 'org_id' => $orgId, 'user_id' => $targetUserId, 'role' => $newRole]);
}

function bc_v1_orgs_member_delete(mysqli $conn, array $params): void
{
    bc_v1_require_method(['DELETE']);
    $actor = bc_v1_actor($conn, true);
    $orgId = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
    $targetUserId = ctype_digit((string) ($params['userId'] ?? '')) ? (int) $params['userId'] : 0;
    $userId = (int) $actor['user']['id'];

    if ($orgId <= 0 || $targetUserId <= 0) {
        bc_v1_json_error(422, 'validation_error', 'org id and target user id are required.');
    }
    if (bc_v1_org_membership_role($conn, $orgId, $userId) !== 'owner') {
        bc_v1_json_error(403, 'forbidden', 'Only owner can kick members.');
    }
    if ($targetUserId === $userId) {
        bc_v1_json_error(422, 'invalid_target', 'Owner cannot kick self.');
    }
    $targetRole = bc_v1_org_membership_role($conn, $orgId, $targetUserId);
    if ($targetRole === null) {
        bc_v1_json_error(404, 'member_not_found', 'Member not found in this organization.');
    }
    if ($targetRole === 'owner') {
        bc_v1_json_error(422, 'owner_role_locked', 'Owner cannot be kicked.');
    }

    $stmt = $conn->prepare("DELETE FROM org_members WHERE org_id = ? AND user_id = ?");
    $stmt->bind_param('ii', $orgId, $targetUserId);
    $stmt->execute();
    $stmt->close();

    webtest_notifications_send($conn, [$targetUserId, $userId], [
        'type' => 'org',
        'event_key' => 'org_member_kicked',
        'title' => 'Member removed from organization',
        'body' => 'A member was removed from organization #' . $orgId . '.',
        'severity' => 'alert',
        'link_path' => '/app/organizations',
        'actor_user_id' => $userId,
        'org_id' => $orgId,
    ]);
    bc_v1_json_success(['kicked' => true, 'org_id' => $orgId, 'user_id' => $targetUserId]);
}
