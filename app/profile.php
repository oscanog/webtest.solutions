<?php

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/auth_org.php';
require_once __DIR__ . '/checklist_shell.php';

$userStmt = $conn->prepare('SELECT username, email, role FROM users WHERE id = ? LIMIT 1');
$userStmt->bind_param('i', $current_user_id);
$userStmt->execute();
$user = $userStmt->get_result()->fetch_assoc();
$userStmt->close();

if (!$user) {
    http_response_code(404);
    die('User account was not found.');
}

$activeOrgId = (int) ($_SESSION['active_org_id'] ?? 0);
$membership = $activeOrgId > 0 ? bugcatcher_fetch_org_membership($conn, $activeOrgId, $current_user_id) : null;
$orgRole = is_array($membership) ? (string) ($membership['role'] ?? '') : null;
$orgName = is_array($membership) ? (string) ($membership['org_name'] ?? '') : null;
$displayRole = bugcatcher_display_role_label($current_role, $orgRole);
$workspaceLabel = trim((string) $orgName) !== '' ? (string) $orgName : 'No active workspace selected';

$context = [
    'current_username' => (string) $user['username'],
    'current_role' => $current_role,
    'org_role' => $orgRole,
    'org_name' => $orgName,
];

bugcatcher_shell_start('Profile', 'profile', $context);
?>

<div
    class="bc-grid"
    data-profile-root
    data-profile-endpoint="<?= htmlspecialchars(bugcatcher_path('api/v1/auth/profile')) ?>"
    data-password-endpoint="<?= htmlspecialchars(bugcatcher_path('api/v1/auth/change-password')) ?>"
>
    <div class="bc-card bc-hero-card">
        <div class="profile-hero">
            <div class="profile-hero__avatar" data-profile-avatar><?= htmlspecialchars(bugcatcher_user_initials((string) $user['username'])) ?></div>
            <div class="profile-hero__copy">
                <span class="profile-hero__eyebrow">Account Summary</span>
                <strong data-profile-username><?= htmlspecialchars((string) $user['username']) ?></strong>
                <p><?= htmlspecialchars((string) $user['email']) ?></p>
                <div class="profile-hero__meta">
                    <span data-profile-role><?= htmlspecialchars($displayRole) ?></span>
                    <span data-profile-workspace><?= htmlspecialchars($workspaceLabel) ?></span>
                </div>
            </div>
            <span class="profile-status-badge">Online</span>
        </div>
    </div>

    <div class="bc-grid cols-2">
        <div class="bc-card">
            <h2 class="bc-title-tight">Profile Settings</h2>
            <div class="profile-form-card">
                <p class="profile-settings-note">Update the account name shown across BugCatcher. Your email stays read-only so the login address remains stable.</p>
                <div id="profileFormMessage" aria-live="polite"></div>
                <form id="profileSettingsForm" class="bc-form-grid">
                    <div class="bc-field full">
                        <label for="profile_username">Username</label>
                        <input
                            class="bc-input"
                            id="profile_username"
                            name="username"
                            autocomplete="username"
                            value="<?= htmlspecialchars((string) $user['username']) ?>"
                            data-profile-username-input
                            required
                        >
                    </div>
                    <div class="bc-field full">
                        <label for="profile_email">Email</label>
                        <input
                            class="bc-input"
                            id="profile_email"
                            name="email"
                            value="<?= htmlspecialchars((string) $user['email']) ?>"
                            readonly
                            aria-readonly="true"
                        >
                    </div>
                    <div class="bc-field full">
                        <button
                            type="submit"
                            class="bc-btn"
                            data-idle-label="Save Profile"
                            data-pending-label="Saving..."
                        >
                            Save Profile
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="bc-card">
            <h2 class="bc-title-tight">Change Password</h2>
            <div class="profile-form-card">
                <p class="profile-settings-note">Use your current password to set a new one for this legacy account.</p>
                <div id="passwordFormMessage" aria-live="polite"></div>
                <form id="passwordSettingsForm" class="bc-form-grid">
                    <div class="bc-field full">
                        <label for="current_password">Current Password</label>
                        <input
                            class="bc-input"
                            id="current_password"
                            name="current_password"
                            type="password"
                            autocomplete="current-password"
                            required
                        >
                    </div>
                    <div class="bc-field full">
                        <label for="password">New Password</label>
                        <input
                            class="bc-input"
                            id="password"
                            name="password"
                            type="password"
                            autocomplete="new-password"
                            required
                        >
                    </div>
                    <div class="bc-field full">
                        <label for="confirm_password">Confirm New Password</label>
                        <input
                            class="bc-input"
                            id="confirm_password"
                            name="confirm_password"
                            type="password"
                            autocomplete="new-password"
                            required
                        >
                    </div>
                    <div class="bc-field full">
                        <button
                            type="submit"
                            class="bc-btn"
                            data-idle-label="Change Password"
                            data-pending-label="Updating..."
                        >
                            Change Password
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="<?= htmlspecialchars(bugcatcher_path('app/profile_page.js?v=1')) ?>"></script>

<?php bugcatcher_shell_end(); ?>
