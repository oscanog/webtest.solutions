<?php
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/app/sidebar.php';

$page = 'organization';

/* ---------------- Helpers ---------------- */

function h($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function post_int($key): int
{
    $v = $_POST[$key] ?? '';
    return ctype_digit((string) $v) ? (int) $v : 0;
}

function require_membership(mysqli $conn, int $orgId, int $userId): ?array
{
    $stmt = $conn->prepare("SELECT role FROM org_members WHERE org_id=? AND user_id=? LIMIT 1");
    $stmt->bind_param("ii", $orgId, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function count_members(mysqli $conn, int $orgId): int
{
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM org_members WHERE org_id=?");
    $stmt->bind_param("i", $orgId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row['c'] ?? 0);
}

/* ---------------- Roles ---------------- */

const ORG_ROLES = [
    'owner',
    'member',
    'Project Manager',
    'QA Lead',
    'Senior Developer',
    'Senior QA',
    'Junior Developer',
    'QA Tester',
];

function is_valid_role(string $role): bool
{
    return in_array($role, ORG_ROLES, true);
}

function role_class(string $role): string
{
    $role = strtolower(trim($role));
    $role = preg_replace('/[^a-z0-9]+/', '-', $role);
    $role = trim($role, '-');
    return 'role-' . $role;
}

/* ---------------- Load orgs user belongs to ---------------- */

$userOrgs = [];
$stmt = $conn->prepare("
  SELECT o.id, o.name, o.owner_id, o.created_at,
         om.role AS my_role
  FROM org_members om
  JOIN organizations o ON o.id = om.org_id
  WHERE om.user_id = ?
  ORDER BY o.name ASC
");
$stmt->bind_param("i", $current_user_id);
$stmt->execute();
$userOrgs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ---------------- Pick active org via ?org_id= (fallback first org) ---------------- */

$activeOrgId = (isset($_GET['org_id']) && ctype_digit($_GET['org_id'])) ? (int) $_GET['org_id'] : 0;

$activeOrg = null;
if (!empty($userOrgs)) {
    if ($activeOrgId > 0) {
        foreach ($userOrgs as $o) {
            if ((int) $o['id'] === $activeOrgId) {
                $activeOrg = $o;
                break;
            }
        }
    }
    if (!$activeOrg) {
        $activeOrg = $userOrgs[0];
    }
}

$isInOrg = (bool) $activeOrg;

// Save active org in session for session-based org context + persist for next login
if ($activeOrg) {
    $activeOrgId = (int) $activeOrg['id'];
    $_SESSION['active_org_id'] = $activeOrgId;

    // Persist last selected org for this user
    $stmt = $conn->prepare("UPDATE users SET last_active_org_id=? WHERE id=?");
    $stmt->bind_param("ii", $activeOrgId, $current_user_id);
    $stmt->execute();
    $stmt->close();
} else {
    unset($_SESSION['active_org_id']);

    // Optional: clear persisted org if user has no orgs
    $stmt = $conn->prepare("UPDATE users SET last_active_org_id=NULL WHERE id=?");
    $stmt->bind_param("i", $current_user_id);
    $stmt->execute();
    $stmt->close();
}

/* ---------------- Load joinable orgs (exclude ones already joined) ---------------- */

$orgsArr = [];
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
$stmt->bind_param("i", $current_user_id);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
    $orgsArr[] = $r;
}
$stmt->close();

$error = $_SESSION['org_error'] ?? '';
unset($_SESSION['org_error']);

$success = '';

/* ---------------- Handle Create Organization ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $orgName = trim($_POST['org_name'] ?? '');
    $orgName = preg_replace('/\s+/', ' ', $orgName);

    if ($orgName === '') {
        $error = "Organization name is required.";
    } else {
        $chk = $conn->prepare("SELECT id FROM organizations WHERE LOWER(name) = LOWER(?) LIMIT 1");
        $chk->bind_param("s", $orgName);
        $chk->execute();
        $exists = $chk->get_result()->fetch_assoc();
        $chk->close();

        if ($exists) {
            $error = "Organization name already exists. Please choose a different name.";
        } else {
            $ins = null;
            $mem = null;

            try {
                $conn->begin_transaction();

                $ins = $conn->prepare("INSERT INTO organizations (name, owner_id) VALUES (?, ?)");
                $ins->bind_param("si", $orgName, $current_user_id);
                $ins->execute();
                $orgId = (int) $ins->insert_id;
                $ins->close();
                $ins = null;

                $mem = $conn->prepare("INSERT INTO org_members (org_id, user_id, role) VALUES (?, ?, 'owner')");
                $mem->bind_param("ii", $orgId, $current_user_id);
                $mem->execute();
                $mem->close();
                $mem = null;

                $conn->commit();

                header("Location: " . bugcatcher_path('zen/organization.php?org_id=' . $orgId));
                exit;
            } catch (mysqli_sql_exception $e) {
                $conn->rollback();
                if ($ins)
                    $ins->close();
                if ($mem)
                    $mem->close();

                if ((int) $e->getCode() === 1062) {
                    $error = "Organization name already exists (or you already joined).";
                } else {
                    $error = "Failed to create organization: " . h($e->getMessage());
                }
            }
        }
    }
}

/* ---------------- Handle Join Organization ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'join') {
    $orgId = post_int('org_id');

    if ($orgId <= 0) {
        $error = "Please select an organization.";
    } else {
        $find = $conn->prepare("SELECT id FROM organizations WHERE id = ? LIMIT 1");
        $find->bind_param("i", $orgId);
        $find->execute();
        $org = $find->get_result()->fetch_assoc();
        $find->close();

        if (!$org) {
            $error = "Organization not found.";
        } else {
            try {
                $mem = $conn->prepare("INSERT INTO org_members (org_id, user_id, role) VALUES (?, ?, 'member')");
                $mem->bind_param("ii", $orgId, $current_user_id);
                $mem->execute();
                $mem->close();

                header("Location: " . bugcatcher_path('zen/organization.php?org_id=' . $orgId));
                exit;
            } catch (mysqli_sql_exception $e) {
                if ((int) $e->getCode() === 1062) {
                    $error = "You already joined this organization.";
                } else {
                    $error = "Failed to join organization: " . h($e->getMessage());
                }
            }
        }
    }
}

/* ---------------- Handle Leave Organization ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'leave') {
    $orgId = post_int('org_id');

    if ($orgId <= 0) {
        $error = "Missing organization.";
    } else {
        $me = require_membership($conn, $orgId, $current_user_id);

        if (!$me) {
            $error = "You are not a member of this organization.";
        } else {
            $myRole = $me['role'];
            $memberCount = count_members($conn, $orgId);

            if ($myRole === 'owner') {
                if ($memberCount > 1) {
                    $error = "You are the owner. Transfer ownership to a member first, then you can leave.";
                } else {
                    $del = $conn->prepare("DELETE FROM organizations WHERE id = ?");
                    $del->bind_param("i", $orgId);

                    if ($del->execute()) {
                        $del->close();
                        header("Location: " . bugcatcher_path('zen/organization.php'));
                        exit;
                    } else {
                        $error = "Failed to delete organization: " . h($del->error);
                        $del->close();
                    }
                }
            } else {
                $del = $conn->prepare("DELETE FROM org_members WHERE org_id = ? AND user_id = ?");
                $del->bind_param("ii", $orgId, $current_user_id);

                if ($del->execute()) {
                    $del->close();
                    header("Location: " . bugcatcher_path('zen/organization.php'));
                    exit;
                } else {
                    $error = "Failed to leave organization: " . h($del->error);
                    $del->close();
                }
            }
        }
    }
}

/* ---------------- Handle Transfer Ownership ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'transfer_owner') {
    $orgId = post_int('org_id');
    $newOwnerId = post_int('new_owner_id');

    if ($orgId <= 0) {
        $error = "Missing organization.";
    } else {
        $me = require_membership($conn, $orgId, $current_user_id);

        if (!$me) {
            $error = "You are not a member of this organization.";
        } else if ($me['role'] !== 'owner') {
            $error = "Only the organization owner can transfer ownership.";
        } else if ($newOwnerId <= 0) {
            $error = "Please select a member.";
        } else if ($newOwnerId === (int) $current_user_id) {
            $error = "You are already the owner.";
        } else {
            $stmt = $conn->prepare("SELECT role FROM org_members WHERE org_id=? AND user_id=? LIMIT 1");
            $stmt->bind_param("ii", $orgId, $newOwnerId);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$exists) {
                $error = "Selected user is not a member of this organization.";
            } else {
                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare("UPDATE org_members SET role='member' WHERE org_id=? AND user_id=?");
                    $stmt->bind_param("ii", $orgId, $current_user_id);
                    if (!$stmt->execute())
                        throw new Exception($stmt->error);
                    $stmt->close();

                    $stmt = $conn->prepare("UPDATE org_members SET role='owner' WHERE org_id=? AND user_id=?");
                    $stmt->bind_param("ii", $orgId, $newOwnerId);
                    if (!$stmt->execute())
                        throw new Exception($stmt->error);
                    $stmt->close();

                    $stmt = $conn->prepare("UPDATE organizations SET owner_id=? WHERE id=?");
                    $stmt->bind_param("ii", $newOwnerId, $orgId);
                    if (!$stmt->execute())
                        throw new Exception($stmt->error);
                    $stmt->close();

                    $conn->commit();

                    header("Location: " . bugcatcher_path('zen/organization.php?org_id=' . $orgId));
                    exit;
                } catch (Exception $e) {
                    $conn->rollback();
                    $error = "Failed to transfer ownership: " . h($e->getMessage());
                }
            }
        }
    }
}

/* ---------------- Handle Delete Organization ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_org') {
    $orgId = post_int('org_id');
    $confirm = trim($_POST['confirm_text'] ?? '');

    if ($orgId <= 0) {
        $error = "Missing organization.";
    } else {
        $me = require_membership($conn, $orgId, $current_user_id);

        if (!$me) {
            $error = "You are not a member of this organization.";
        } else if ($me['role'] !== 'owner') {
            $error = "Only the organization owner can delete the organization.";
        } else if ($confirm !== 'DELETE') {
            $error = "You must type DELETE to confirm.";
        } else {
            $del = $conn->prepare("DELETE FROM organizations WHERE id = ?");
            $del->bind_param("i", $orgId);

            if ($del->execute()) {
                $del->close();
                header("Location: " . bugcatcher_path('zen/organization.php'));
                exit;
            } else {
                $error = "Failed to delete organization: " . h($del->error);
                $del->close();
            }
        }
    }
}

/* ---------------- Handle Kick Member ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'kick_member') {
    $orgId = post_int('org_id');
    $kickUserId = post_int('kick_user_id');

    if ($orgId <= 0) {
        $error = "Missing organization.";
    } else {
        $me = require_membership($conn, $orgId, $current_user_id);

        if (!$me) {
            $error = "You are not a member of this organization.";
        } else if ($me['role'] !== 'owner') {
            $error = "Only the organization owner can kick members.";
        } else if ($kickUserId <= 0) {
            $error = "Invalid member.";
        } else if ($kickUserId === (int) $current_user_id) {
            $error = "You can't kick yourself. Transfer ownership or delete the organization instead.";
        } else {
            $stmt = $conn->prepare("SELECT role FROM org_members WHERE org_id=? AND user_id=? LIMIT 1");
            $stmt->bind_param("ii", $orgId, $kickUserId);
            $stmt->execute();
            $target = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$target) {
                $error = "Member not found in this organization.";
            } else if ($target['role'] === 'owner') {
                $error = "You can't kick the owner.";
            } else {
                $del = $conn->prepare("DELETE FROM org_members WHERE org_id=? AND user_id=?");
                $del->bind_param("ii", $orgId, $kickUserId);

                if ($del->execute()) {
                    $del->close();
                    header("Location: " . bugcatcher_path('zen/organization.php?org_id=' . $orgId));
                    exit;
                } else {
                    $error = "Failed to kick member: " . h($del->error);
                    $del->close();
                }
            }
        }
    }
}

/* ---------------- Handle Change Member Role ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_role') {
    $orgId = post_int('org_id');
    $targetUserId = post_int('target_user_id');
    $newRole = trim($_POST['new_role'] ?? '');

    if ($orgId <= 0) {
        $error = "Missing organization.";
    } else {
        $me = require_membership($conn, $orgId, $current_user_id);

        if (!$me) {
            $error = "You are not a member of this organization.";
        } else if ($me['role'] !== 'owner') {
            $error = "Only the organization owner can change roles.";
        } else if ($targetUserId <= 0) {
            $error = "Invalid member.";
        } else if (!is_valid_role($newRole)) {
            $error = "Invalid role selected.";
        } else {
            // Check target exists in org
            $stmt = $conn->prepare("SELECT role FROM org_members WHERE org_id=? AND user_id=? LIMIT 1");
            $stmt->bind_param("ii", $orgId, $targetUserId);
            $stmt->execute();
            $target = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$target) {
                $error = "Member not found in this organization.";
            } else if ($target['role'] === 'owner') {
                // Don’t allow changing the owner's role here (use Transfer Ownership instead)
                $error = "You can't change the owner's role. Use Transfer Ownership.";
            } else if ($targetUserId === (int) $current_user_id) {
                // Optional safety rule
                $error = "You can't change your own role.";
            } else {
                $upd = $conn->prepare("UPDATE org_members SET role=? WHERE org_id=? AND user_id=?");
                $upd->bind_param("sii", $newRole, $orgId, $targetUserId);

                if ($upd->execute()) {
                    $upd->close();
                    header("Location: " . bugcatcher_path('zen/organization.php?org_id=' . $orgId));
                    exit;
                } else {
                    $error = "Failed to change role: " . h($upd->error);
                    $upd->close();
                }
            }
        }
    }
}

/* ---------------- Refresh orgs + active org after POST errors ---------------- */

$userOrgs = [];
$stmt = $conn->prepare("
  SELECT o.id, o.name, o.owner_id, o.created_at,
         om.role AS my_role
  FROM org_members om
  JOIN organizations o ON o.id = om.org_id
  WHERE om.user_id = ?
  ORDER BY o.name ASC
");
$stmt->bind_param("i", $current_user_id);
$stmt->execute();
$userOrgs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$activeOrg = null;
if (!empty($userOrgs)) {
    if ($activeOrgId > 0) {
        foreach ($userOrgs as $o) {
            if ((int) $o['id'] === $activeOrgId) {
                $activeOrg = $o;
                break;
            }
        }
    }
    if (!$activeOrg)
        $activeOrg = $userOrgs[0];
}

$isInOrg = (bool) $activeOrg;
$isOwner = ($activeOrg && (int) $activeOrg['owner_id'] === (int) $current_user_id);

/* joinable orgs refresh too */
$orgsArr = [];
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
$stmt->bind_param("i", $current_user_id);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
    $orgsArr[] = $r;
}
$stmt->close();

/* ---------------- Load owner + members for active org ---------------- */

$owner = null;
$members = [];

if ($activeOrg) {
    $stmt = $conn->prepare("SELECT id, username FROM users WHERE id = ? LIMIT 1");
    $oid = (int) $activeOrg['owner_id'];
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $owner = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT u.id, u.username, om.role, om.joined_at
        FROM org_members om
        JOIN users u ON u.id = om.user_id
        WHERE om.org_id = ?
        ORDER BY (om.role='owner') DESC, u.username ASC
    ");
    $oid2 = (int) $activeOrg['id'];
    $stmt->bind_param("i", $oid2);
    $stmt->execute();
    $members = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>BugCatcher - Organization</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars(bugcatcher_path('favicon.svg')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(bugcatcher_path('app/legacy_theme.css?v=2')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(bugcatcher_path('zen/organization.css?v=3')) ?>">
</head>

<body>
    <?php bugcatcher_render_sidebar('organization', $current_username, $current_role, (string) ($activeOrg['my_role'] ?? ''), (string) ($activeOrg['name'] ?? '')); ?>

    <main class="main">
        <div class="topbar">
            <h1>Organization</h1>
            <a href="<?= htmlspecialchars(bugcatcher_path('zen/dashboard.php?page=dashboard')) ?>" class="btn">Back to Dashboard</a>
        </div>

        <?php if ($error): ?>
            <div class="bc-alert error"><?= h($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="bc-alert success"><?= h($success) ?></div>
        <?php endif; ?>

        <div class="bc-grid cols-2 organization-grid">
            <!-- Create -->
            <div class="bc-card organization-card">
                <h2 class="card-title">Create an Organization</h2>
                <p class="muted card-subtitle">
                    You will become the owner and members can join by selecting your organization.
                </p>

                <form method="POST" autocomplete="off">
                    <input type="hidden" name="action" value="create">

                    <label class="muted">Organization name</label>
                    <input class="inp" name="org_name" placeholder="e.g. Team Alpha" maxlength="120" required
                        value="<?= h($_POST['org_name'] ?? '') ?>">

                    <div class="mt-12">
                        <button class="btn-green" type="submit">Create</button>
                    </div>
                </form>
            </div>

            <!-- Join -->
            <div class="bc-card organization-card">
                <h2 class="card-title">Join an Organization</h2>
                <p class="muted card-subtitle">Search and join from the list.</p>

                <div class="gh-dd" data-dd="orgs">
                    <button type="button" class="gh-dd-btn">
                        Select Organization <span class="caret">▾</span>
                    </button>

                    <div class="gh-dd-menu">
                        <div class="gh-dd-header">Filter organizations</div>

                        <div class="gh-dd-search">
                            <input type="text" placeholder="Filter organizations" data-search="orgs">
                        </div>

                        <div class="gh-dd-list dd-list-scroll" data-list="orgs">
                            <?php if (empty($orgsArr)): ?>
                                <div class="gh-dd-item opacity-70 cursor-default">
                                    <span class="txt">No organizations available</span>
                                </div>
                            <?php else: ?>
                                <?php foreach ($orgsArr as $o): ?>
                                    <?php
                                    $orgName = $o['name'];
                                    $ownerName = $o['owner_name'];
                                    $membersCount = (int) $o['member_count'];
                                    $searchText = strtolower($orgName . ' ' . $ownerName);
                                    $initial = strtoupper(substr($orgName, 0, 1));
                                    ?>

                                    <form method="POST" class="m-0">
                                        <input type="hidden" name="action" value="join">
                                        <input type="hidden" name="org_id" value="<?= (int) $o['id'] ?>">

                                        <button type="submit" class="gh-dd-item w-100 no-border bg-transparent"
                                            data-text="<?= h($searchText) ?>">
                                            <span class="avatar"><?= h($initial) ?></span>
                                            <span class="txt">
                                                <?= h($orgName) ?>
                                                <span class="sub">Owner: <?= h($ownerName) ?> · <?= $membersCount ?>
                                                    members</span>
                                            </span>
                                        </button>
                                    </form>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="muted join-tip">
                    Tip: Type in the search box to filter by organization name or owner.
                </div>
            </div>
        </div>

        <?php if ($isInOrg): ?>

            <?php if (!empty($userOrgs)): ?>
                <div class="bc-card">
                    <div class="muted orgs-label">Your Organizations</div>
                    <div class="orgs-links">
                        <?php foreach ($userOrgs as $o): ?>
                            <a class="btn <?= ((int) $activeOrg['id'] === (int) $o['id']) ? 'org-link-active' : '' ?>"
                                href="<?= htmlspecialchars(bugcatcher_path('zen/organization.php?org_id=' . (int) $o['id'])) ?>">
                                <?= h($o['name']) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="bc-card">
                <h2 class="org-name"><?= h($activeOrg['name']) ?></h2>
                <div class="muted">
                    Owner: <strong><?= h($owner['username'] ?? 'Unknown') ?></strong>
                </div>

                <form method="POST" class="mt-12">
                    <input type="hidden" name="action" value="leave">
                    <input type="hidden" name="org_id" value="<?= (int) $activeOrg['id'] ?>">
                    <button type="submit" class="btn"
                        onclick="return confirm('Are you sure you want to leave this organization?');">
                        Leave Organization
                    </button>
                </form>

                <?php if ($isOwner): ?>
                    <div class="bc-card danger-card">
                        <h3 class="danger-title">Delete Organization</h3>
                        <p class="muted card-subtitle">
                            This action cannot be undone. This will permanently delete the organization and remove all members.
                        </p>

                        <form method="POST" id="deleteOrgForm">
                            <input type="hidden" name="action" value="delete_org">
                            <input type="hidden" name="org_id" value="<?= (int) $activeOrg['id'] ?>">

                            <label class="muted">Type <strong>DELETE</strong> to confirm</label>
                            <input type="text" id="deleteConfirmInput" class="inp delete-confirm-input"
                                placeholder="Type DELETE" autocomplete="off">

                            <input type="hidden" name="confirm_text" id="confirmTextHidden">

                            <div class="mt-12">
                                <button type="submit" id="deleteOrgBtn" class="btn btn-danger-disabled" disabled>
                                    Delete Organization
                                </button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($isOwner && !empty($members)): ?>
                <div class="bc-card">
                    <h3 class="transfer-title">Transfer Ownership</h3>
                    <p class="muted card-subtitle">Select a member to become the new owner.</p>

                    <form method="POST">
                        <input type="hidden" name="action" value="transfer_owner">
                        <input type="hidden" name="org_id" value="<?= (int) $activeOrg['id'] ?>">

                        <label class="muted">New owner</label>
                        <select class="inp" name="new_owner_id" required>
                            <option value="">-- Select member --</option>
                            <?php foreach ($members as $m): ?>
                                <?php if ($m['role'] !== 'owner'): ?>
                                    <option value="<?= (int) $m['id'] ?>"><?= h($m['username']) ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>

                        <div class="mt-12">
                            <button class="btn" type="submit"
                                onclick="return confirm('Transfer ownership? This will make the selected member the new owner.');">
                                Transfer Ownership
                            </button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <div class="bc-card">
                <h3 class="members-title">Members</h3>
                <div class="bc-table-wrap org-members-table-wrap">
                <table class="bc-table organization-members-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Role</th>
                            <th>Joined</th>
                            <?php if ($isOwner): ?>
                                <th>Actions</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($members as $m): ?>
                            <tr>
                                <td><?= h($m['username']) ?></td>
                                <td>
                                    <?php if ($isOwner && $m['role'] !== 'owner' && (int) $m['id'] !== (int) $current_user_id): ?>
                                        <div class="org-member-role-controls">
                                            <span class="role-badge <?= h(role_class($m['role'])) ?>">
                                                <span class="dot"></span>
                                                <?= h($m['role']) ?>
                                            </span>

                                            <form method="POST" class="m-0 org-inline-form">
                                                <input type="hidden" name="action" value="change_role">
                                                <input type="hidden" name="org_id" value="<?= (int) $activeOrg['id'] ?>">
                                                <input type="hidden" name="target_user_id" value="<?= (int) $m['id'] ?>">

                                                <select class="inp members-role-select" name="new_role"
                                                    onchange="this.form.submit()">
                                                    <?php foreach (ORG_ROLES as $r): ?>
                                                        <?php if ($r === 'owner')
                                                            continue; ?>
                                                        <option value="<?= h($r) ?>" <?= ($m['role'] === $r) ? 'selected' : '' ?>>
                                                            <?= h($r) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </form>
                                        </div>
                                    <?php else: ?>
                                        <span class="role-badge <?= h(role_class($m['role'])) ?>">
                                            <?= h($m['role']) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td><?= h(date("M d, Y", strtotime($m['joined_at']))) ?></td>

                                <?php if ($isOwner): ?>
                                    <td>
                                        <?php $canKick = ($m['role'] !== 'owner' && (int) $m['id'] !== (int) $current_user_id); ?>
                                        <?php if ($canKick): ?>
                                            <form method="POST" class="m-0">
                                                <input type="hidden" name="action" value="kick_member">
                                                <input type="hidden" name="org_id" value="<?= (int) $activeOrg['id'] ?>">
                                                <input type="hidden" name="kick_user_id" value="<?= (int) $m['id'] ?>">
                                                <button type="submit" class="btn btn-kick"
                                                    onclick="return confirm('Kick <?= h($m['username']) ?> from the organization?');">
                                                    Kick
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>

        <?php endif; ?>

    </main>

    <script>
        // Toggle dropdown open/close
        document.querySelectorAll(".gh-dd-btn").forEach(btn => {
            btn.addEventListener("click", (e) => {
                e.stopPropagation();
                const dd = btn.closest(".gh-dd");
                const willOpen = !dd.classList.contains("open");
                document.querySelectorAll(".gh-dd").forEach(x => x.classList.remove("open"));
                if (willOpen) dd.classList.add("open");

                const input = dd.querySelector('input[data-search="orgs"]');
                if (willOpen && input) setTimeout(() => input.focus(), 0);
            });
        });

        document.addEventListener("click", () => {
            document.querySelectorAll(".gh-dd").forEach(x => x.classList.remove("open"));
        });

        // Search filter
        (function setupOrgSearch() {
            const input = document.querySelector('[data-search="orgs"]');
            const list = document.querySelector('[data-list="orgs"]');
            if (!input || !list) return;

            input.addEventListener("input", () => {
                const q = input.value.trim().toLowerCase();
                list.querySelectorAll(".gh-dd-item").forEach(item => {
                    const t = (item.getAttribute("data-text") || item.innerText).toLowerCase();
                    item.style.display = t.includes(q) ? "flex" : "none";
                });
            });
        })();

        // Delete confirm behavior (no inline styles)
        (function setupDeleteConfirm() {
            const deleteInput = document.getElementById("deleteConfirmInput");
            const deleteBtn = document.getElementById("deleteOrgBtn");
            const hiddenInput = document.getElementById("confirmTextHidden");
            const deleteForm = document.getElementById("deleteOrgForm");

            if (!deleteInput || !deleteBtn || !hiddenInput || !deleteForm) return;

            deleteInput.addEventListener("input", function () {
                this.value = this.value.toUpperCase();
                const value = this.value.trim();
                hiddenInput.value = value;

                this.classList.remove("inp-danger", "inp-success");

                if (value.length === 0) {
                    deleteBtn.disabled = true;
                    deleteBtn.classList.add("btn-danger-disabled");
                    deleteBtn.classList.remove("btn-danger-enabled");
                    return;
                }

                if (value === "DELETE") {
                    deleteBtn.disabled = false;
                    deleteBtn.classList.remove("btn-danger-disabled");
                    deleteBtn.classList.add("btn-danger-enabled");
                    this.classList.add("inp-success");
                } else {
                    deleteBtn.disabled = true;
                    deleteBtn.classList.add("btn-danger-disabled");
                    deleteBtn.classList.remove("btn-danger-enabled");
                    this.classList.add("inp-danger");
                }
            });

            deleteForm.addEventListener("submit", function (e) {
                if (!confirm("Are you absolutely sure you want to permanently delete this organization?")) {
                    e.preventDefault();
                }
            });
        })();
    </script>
    <script src="<?= htmlspecialchars(bugcatcher_path('app/mobile_nav.js?v=1')) ?>"></script>
</body>

</html>
