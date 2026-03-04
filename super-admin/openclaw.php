<?php

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/app/openclaw_lib.php';
require_once dirname(__DIR__) . '/app/checklist_shell.php';

bugcatcher_require_super_admin($current_role);

function openclaw_post_int(string $key): int
{
    $value = $_POST[$key] ?? '';
    return ctype_digit((string) $value) ? (int) $value : 0;
}

function openclaw_status_value(?string $value, string $fallback = 'unknown'): string
{
    $value = trim((string) $value);
    return $value !== '' ? $value : $fallback;
}

$tab = $_GET['tab'] ?? 'overview';
$allowedTabs = ['overview', 'discord', 'providers', 'models', 'channels', 'users', 'requests', 'documents'];
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'overview';
}

$flash = '';
$error = '';
$runtimeSnapshotPreview = null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        $redirectTab = $_POST['tab'] ?? $tab;

        if ($action === 'save_runtime') {
            bugcatcher_openclaw_save_runtime_config(
                $conn,
                $current_user_id,
                isset($_POST['is_enabled']),
                trim((string) ($_POST['discord_bot_token'] ?? '')),
                openclaw_post_int('default_provider_config_id'),
                openclaw_post_int('default_model_id'),
                trim((string) ($_POST['notes'] ?? ''))
            );
            $flash = 'Runtime settings saved.';
        } elseif ($action === 'save_provider') {
            bugcatcher_openclaw_save_provider(
                $conn,
                $current_user_id,
                openclaw_post_int('provider_id'),
                trim((string) ($_POST['provider_key'] ?? '')),
                trim((string) ($_POST['display_name'] ?? '')),
                trim((string) ($_POST['provider_type'] ?? '')),
                trim((string) ($_POST['base_url'] ?? '')),
                trim((string) ($_POST['api_key'] ?? '')),
                isset($_POST['is_enabled']),
                isset($_POST['supports_model_sync'])
            );
            $flash = 'Provider saved.';
        } elseif ($action === 'delete_provider') {
            bugcatcher_openclaw_delete_provider($conn, openclaw_post_int('provider_id'), $current_user_id);
            $flash = 'Provider deleted.';
        } elseif ($action === 'save_model') {
            bugcatcher_openclaw_save_model(
                $conn,
                openclaw_post_int('provider_config_id'),
                openclaw_post_int('model_id'),
                trim((string) ($_POST['remote_model_id'] ?? '')),
                trim((string) ($_POST['display_name'] ?? '')),
                isset($_POST['supports_vision']),
                isset($_POST['supports_json_output']),
                isset($_POST['is_enabled']),
                isset($_POST['is_default']),
                $current_user_id
            );
            $flash = 'Model saved.';
        } elseif ($action === 'delete_model') {
            bugcatcher_openclaw_delete_model($conn, openclaw_post_int('model_id'), $current_user_id);
            $flash = 'Model deleted.';
        } elseif ($action === 'save_channel') {
            bugcatcher_openclaw_save_channel_binding(
                $conn,
                $current_user_id,
                openclaw_post_int('binding_id'),
                trim((string) ($_POST['guild_id'] ?? '')),
                trim((string) ($_POST['guild_name'] ?? '')),
                trim((string) ($_POST['channel_id'] ?? '')),
                trim((string) ($_POST['channel_name'] ?? '')),
                isset($_POST['is_enabled']),
                isset($_POST['allow_dm_followup'])
            );
            $flash = 'Channel binding saved.';
        } elseif ($action === 'delete_channel') {
            bugcatcher_openclaw_delete_channel_binding($conn, openclaw_post_int('binding_id'), $current_user_id);
            $flash = 'Channel binding deleted.';
        } elseif ($action === 'reload_runtime') {
            $reloadRequestId = bugcatcher_openclaw_queue_reload_request($conn, $current_user_id, 'super_admin_manual_reload');
            $flash = 'Runtime reload requested. Queue item #' . $reloadRequestId . ' is pending.';
        } elseif ($action === 'test_snapshot') {
            $runtimeSnapshotPreview = bugcatcher_openclaw_runtime_config_for_display($conn);
            $flash = 'Runtime snapshot loaded from the control plane.';
        } else {
            $error = 'Unknown action.';
        }

        $tab = in_array($redirectTab, $allowedTabs, true) ? $redirectTab : $tab;
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$runtime = bugcatcher_openclaw_fetch_runtime_config($conn);
$providers = bugcatcher_openclaw_fetch_providers($conn);
$models = bugcatcher_openclaw_fetch_models($conn);
$channels = bugcatcher_openclaw_fetch_channel_bindings($conn);
$linkedUsers = bugcatcher_openclaw_fetch_linked_users($conn, 50);
$requests = bugcatcher_openclaw_fetch_recent_requests($conn, 50);
$health = bugcatcher_openclaw_health_snapshot($conn);
$controlPlane = bugcatcher_openclaw_fetch_control_plane_state($conn);
$runtimeStatus = bugcatcher_openclaw_fetch_runtime_status($conn);
$pendingReload = bugcatcher_openclaw_fetch_pending_reload_request($conn);
$docs = bugcatcher_openclaw_docs_files();
$docFile = $_GET['doc'] ?? 'README.md';
if (!isset($docs[$docFile])) {
    $docFile = array_key_first($docs) ?: '';
}
$docMarkdown = ($docFile !== '' && isset($docs[$docFile])) ? file_get_contents($docs[$docFile]) : "# No documentation available\nAdd files under docs/openclaw.";

$tabs = [
    'overview' => 'Overview',
    'discord' => 'Discord',
    'providers' => 'Providers',
    'models' => 'Models',
    'channels' => 'Channels',
    'users' => 'Users',
    'requests' => 'Requests',
    'documents' => 'Documents',
];

$context = [
    'current_username' => $current_username,
    'current_role' => $current_role,
    'org_role' => null,
    'org_name' => 'Global Administration',
];

bugcatcher_shell_start('OpenClaw Setup', 'super_admin', $context);
?>

<?php if ($flash): ?>
    <div class="bc-alert success"><?= bugcatcher_html($flash) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="bc-alert error"><?= bugcatcher_html($error) ?></div>
<?php endif; ?>

<div class="bc-tabs">
    <?php foreach ($tabs as $key => $label): ?>
        <a class="bc-tab <?= $tab === $key ? 'active' : '' ?>" href="?tab=<?= bugcatcher_html($key) ?>">
            <?= bugcatcher_html($label) ?>
        </a>
    <?php endforeach; ?>
</div>

<?php if ($tab === 'overview'): ?>
    <?php $configMismatch = ($controlPlane['config_version'] ?? '') !== ($runtimeStatus['config_version_applied'] ?? ''); ?>
    <div class="bc-grid cols-3">
        <div class="bc-stat"><span>Runtime enabled</span><strong><?= $health['runtime_enabled'] ? 'Yes' : 'No' ?></strong></div>
        <div class="bc-stat"><span>Providers</span><strong><?= (int) $health['enabled_provider_count'] ?>/<?= (int) $health['provider_count'] ?></strong></div>
        <div class="bc-stat"><span>Channels</span><strong><?= (int) $health['enabled_channel_count'] ?>/<?= (int) $health['channel_count'] ?></strong></div>
    </div>
    <?php if ($configMismatch): ?>
        <div class="bc-alert error">Desired config version <?= bugcatcher_html($controlPlane['config_version'] ?? 'n/a') ?> has not been applied yet. Runtime is still on <?= bugcatcher_html($runtimeStatus['config_version_applied'] ?? 'n/a') ?>.</div>
    <?php endif; ?>
    <div class="bc-grid cols-2">
        <div class="bc-panel">
            <h2>Desired Config</h2>
            <div class="bc-kv">
                <div class="bc-kv-row"><strong>Discord token</strong><span><?= bugcatcher_html(bugcatcher_openclaw_mask_secret($runtime['encrypted_discord_bot_token'] ?? '')) ?></span></div>
                <div class="bc-kv-row"><strong>Default provider</strong><span><?= bugcatcher_html($runtime['default_provider_name'] ?? 'Not set') ?></span></div>
                <div class="bc-kv-row"><strong>Default model</strong><span><?= bugcatcher_html($runtime['default_model_name'] ?? 'Not set') ?></span></div>
                <div class="bc-kv-row"><strong>Desired config version</strong><span><?= bugcatcher_html($controlPlane['config_version'] ?? 'Not set') ?></span></div>
                <div class="bc-kv-row"><strong>Pending reload</strong><span><?= bugcatcher_html($pendingReload ? '#' . $pendingReload['id'] . ' ' . ($pendingReload['status'] ?? 'pending') : 'None') ?></span></div>
                <div class="bc-kv-row"><strong>Last request</strong><span><?= bugcatcher_html($health['last_successful_request_at'] ?? 'Never') ?></span></div>
            </div>
            <form method="post" class="bc-inline-actions">
                <input type="hidden" name="action" value="reload_runtime">
                <input type="hidden" name="tab" value="overview">
                <button type="submit" class="bc-btn">Reload OpenClaw Config</button>
            </form>
        </div>
        <div class="bc-panel">
            <h2>Runtime Status</h2>
            <div class="bc-kv">
                <div class="bc-kv-row"><strong>Applied config version</strong><span><?= bugcatcher_html($runtimeStatus['config_version_applied'] ?? 'Not reported') ?></span></div>
                <div class="bc-kv-row"><strong>Gateway state</strong><span><?= bugcatcher_html(openclaw_status_value($runtimeStatus['gateway_state'] ?? null)) ?></span></div>
                <div class="bc-kv-row"><strong>Discord state</strong><span><?= bugcatcher_html(openclaw_status_value($runtimeStatus['discord_state'] ?? null)) ?></span></div>
                <div class="bc-kv-row"><strong>Discord app id</strong><span><?= bugcatcher_html($runtimeStatus['discord_application_id'] ?? 'Not reported') ?></span></div>
                <div class="bc-kv-row"><strong>Last heartbeat</strong><span><?= bugcatcher_html(bugcatcher_checklist_format_datetime($runtimeStatus['last_heartbeat_at'] ?? null)) ?></span></div>
                <div class="bc-kv-row"><strong>Last reload</strong><span><?= bugcatcher_html(bugcatcher_checklist_format_datetime($runtimeStatus['last_reload_at'] ?? null)) ?></span></div>
                <div class="bc-kv-row"><strong>Provider error</strong><span><?= bugcatcher_html(($runtimeStatus['last_provider_error'] ?? '') ?: 'None') ?></span></div>
                <div class="bc-kv-row"><strong>Discord error</strong><span><?= bugcatcher_html(($runtimeStatus['last_discord_error'] ?? '') ?: 'None') ?></span></div>
            </div>
            <form method="post" class="bc-inline-actions">
                <input type="hidden" name="action" value="test_snapshot">
                <input type="hidden" name="tab" value="overview">
                <button type="submit" class="bc-btn secondary">Test Runtime Snapshot</button>
            </form>
        </div>
    </div>
    <?php if ($runtimeSnapshotPreview !== null): ?>
        <div class="bc-panel">
            <h2>Snapshot Preview</h2>
            <pre class="bc-code-block"><?= bugcatcher_html(json_encode($runtimeSnapshotPreview, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
        </div>
    <?php endif; ?>
<?php elseif ($tab === 'discord'): ?>
    <div class="bc-panel">
        <h2>Discord Runtime</h2>
        <form method="post" class="bc-form-grid">
            <input type="hidden" name="tab" value="discord">
            <div class="bc-field">
                <label><input type="checkbox" name="is_enabled" <?= !empty($runtime['is_enabled']) ? 'checked' : '' ?>> Enable OpenClaw</label>
            </div>
            <div class="bc-field">
                <label for="discord_bot_token">Discord bot token</label>
                <input class="bc-input" id="discord_bot_token" name="discord_bot_token" placeholder="Leave blank to keep current token">
            </div>
            <div class="bc-field">
                <label>Runtime Discord state</label>
                <div class="bc-input" style="display:flex;align-items:center;"><?= bugcatcher_html(openclaw_status_value($runtimeStatus['discord_state'] ?? null)) ?></div>
            </div>
            <div class="bc-field">
                <label>Discord application id</label>
                <div class="bc-input" style="display:flex;align-items:center;"><?= bugcatcher_html($runtimeStatus['discord_application_id'] ?? 'Not reported') ?></div>
            </div>
            <div class="bc-field">
                <label for="default_provider_config_id">Default provider</label>
                <select class="bc-select" id="default_provider_config_id" name="default_provider_config_id">
                    <option value="0">Select provider</option>
                    <?php foreach ($providers as $provider): ?>
                        <option value="<?= (int) $provider['id'] ?>" <?= (int) ($runtime['default_provider_config_id'] ?? 0) === (int) $provider['id'] ? 'selected' : '' ?>>
                            <?= bugcatcher_html($provider['display_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="bc-field">
                <label for="default_model_id">Default model</label>
                <select class="bc-select" id="default_model_id" name="default_model_id">
                    <option value="0">Select model</option>
                    <?php foreach ($models as $model): ?>
                        <option value="<?= (int) $model['id'] ?>" <?= (int) ($runtime['default_model_id'] ?? 0) === (int) $model['id'] ? 'selected' : '' ?>>
                            <?= bugcatcher_html($model['provider_name'] . ' - ' . $model['display_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="bc-field full">
                <label for="notes">Notes</label>
                <textarea class="bc-textarea" id="notes" name="notes" placeholder="Operational notes, rollout notes, or restart reminders."><?= bugcatcher_html($runtime['notes'] ?? '') ?></textarea>
            </div>
            <div class="bc-field full">
                <button type="submit" name="action" value="save_runtime" class="bc-btn">Save Runtime</button>
                <button type="submit" name="action" value="reload_runtime" class="bc-btn secondary">Reload OpenClaw Config</button>
            </div>
        </form>
    </div>
<?php elseif ($tab === 'providers'): ?>
    <div class="bc-grid cols-2">
        <div class="bc-panel">
            <h2>Add Provider</h2>
            <form method="post" class="bc-form-grid">
                <input type="hidden" name="action" value="save_provider">
                <input type="hidden" name="tab" value="providers">
                <input type="hidden" name="provider_id" value="0">
                <div class="bc-field"><label>Provider key</label><input class="bc-input" name="provider_key" placeholder="openai"></div>
                <div class="bc-field"><label>Display name</label><input class="bc-input" name="display_name" placeholder="OpenAI"></div>
                <div class="bc-field"><label>Provider type</label><input class="bc-input" name="provider_type" placeholder="openai-compatible"></div>
                <div class="bc-field"><label>Base URL</label><input class="bc-input" name="base_url" placeholder="https://api.openai.com/v1"></div>
                <div class="bc-field full"><label>API key</label><input class="bc-input" name="api_key" placeholder="sk-..."></div>
                <div class="bc-field"><label><input type="checkbox" name="is_enabled" checked> Enabled</label></div>
                <div class="bc-field"><label><input type="checkbox" name="supports_model_sync"> Supports model sync</label></div>
                <div class="bc-field full"><button type="submit" class="bc-btn">Save Provider</button></div>
            </form>
        </div>
        <div class="bc-table-wrap">
            <table class="bc-table">
                <thead><tr><th>Name</th><th>Type</th><th>Base URL</th><th>Key</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($providers as $provider): ?>
                    <tr>
                        <td><?= bugcatcher_html($provider['display_name']) ?><br><span class="bc-meta"><?= bugcatcher_html($provider['provider_key']) ?></span></td>
                        <td><?= bugcatcher_html($provider['provider_type']) ?></td>
                        <td><?= bugcatcher_html($provider['base_url'] ?: 'Default') ?></td>
                        <td><?= bugcatcher_html(bugcatcher_openclaw_mask_secret($provider['encrypted_api_key'] ?? '')) ?></td>
                        <td>
                            <form method="post">
                                <input type="hidden" name="action" value="delete_provider">
                                <input type="hidden" name="tab" value="providers">
                                <input type="hidden" name="provider_id" value="<?= (int) $provider['id'] ?>">
                                <button class="bc-btn secondary" type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php elseif ($tab === 'models'): ?>
    <div class="bc-grid cols-2">
        <div class="bc-panel">
            <h2>Add Model</h2>
            <form method="post" class="bc-form-grid">
                <input type="hidden" name="action" value="save_model">
                <input type="hidden" name="tab" value="models">
                <input type="hidden" name="model_id" value="0">
                <div class="bc-field">
                    <label>Provider</label>
                    <select class="bc-select" name="provider_config_id">
                        <option value="0">Select provider</option>
                        <?php foreach ($providers as $provider): ?>
                            <option value="<?= (int) $provider['id'] ?>"><?= bugcatcher_html($provider['display_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="bc-field"><label>Remote model id</label><input class="bc-input" name="remote_model_id" placeholder="gpt-4.1-mini"></div>
                <div class="bc-field full"><label>Display name</label><input class="bc-input" name="display_name" placeholder="GPT-4.1 Mini"></div>
                <div class="bc-field"><label><input type="checkbox" name="supports_vision" checked> Supports vision</label></div>
                <div class="bc-field"><label><input type="checkbox" name="supports_json_output" checked> Supports JSON</label></div>
                <div class="bc-field"><label><input type="checkbox" name="is_enabled" checked> Enabled</label></div>
                <div class="bc-field"><label><input type="checkbox" name="is_default"> Default for provider</label></div>
                <div class="bc-field full"><button type="submit" class="bc-btn">Save Model</button></div>
            </form>
        </div>
        <div class="bc-table-wrap">
            <table class="bc-table">
                <thead><tr><th>Provider</th><th>Model</th><th>Capabilities</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($models as $model): ?>
                    <tr>
                        <td><?= bugcatcher_html($model['provider_name']) ?></td>
                        <td><?= bugcatcher_html($model['display_name']) ?><?= (int) $model['is_default'] === 1 ? ' (default)' : '' ?><br><span class="bc-meta"><?= bugcatcher_html($model['model_id']) ?></span></td>
                        <td><?= $model['supports_vision'] ? 'Vision ' : '' ?><?= $model['supports_json_output'] ? 'JSON' : '' ?></td>
                        <td>
                            <form method="post">
                                <input type="hidden" name="action" value="delete_model">
                                <input type="hidden" name="tab" value="models">
                                <input type="hidden" name="model_id" value="<?= (int) $model['id'] ?>">
                                <button class="bc-btn secondary" type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php elseif ($tab === 'channels'): ?>
    <div class="bc-grid cols-2">
        <div class="bc-panel">
            <h2>Approved Discord Channels</h2>
            <form method="post" class="bc-form-grid">
                <input type="hidden" name="action" value="save_channel">
                <input type="hidden" name="tab" value="channels">
                <input type="hidden" name="binding_id" value="0">
                <div class="bc-field"><label>Guild ID</label><input class="bc-input" name="guild_id" placeholder="1234567890"></div>
                <div class="bc-field"><label>Guild name</label><input class="bc-input" name="guild_name" placeholder="BugCatcher HQ"></div>
                <div class="bc-field"><label>Channel ID</label><input class="bc-input" name="channel_id" placeholder="1234567890"></div>
                <div class="bc-field"><label>Channel name</label><input class="bc-input" name="channel_name" placeholder="qa-intake"></div>
                <div class="bc-field"><label><input type="checkbox" name="is_enabled" checked> Enabled</label></div>
                <div class="bc-field"><label><input type="checkbox" name="allow_dm_followup" checked> Allow DM follow-up</label></div>
                <div class="bc-field full"><button type="submit" class="bc-btn">Save Channel</button></div>
            </form>
        </div>
        <div class="bc-table-wrap">
            <table class="bc-table">
                <thead><tr><th>Guild</th><th>Channel</th><th>Flags</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($channels as $channel): ?>
                    <tr>
                        <td><?= bugcatcher_html($channel['guild_name'] ?: $channel['guild_id']) ?><br><span class="bc-meta"><?= bugcatcher_html($channel['guild_id']) ?></span></td>
                        <td><?= bugcatcher_html($channel['channel_name'] ?: $channel['channel_id']) ?><br><span class="bc-meta"><?= bugcatcher_html($channel['channel_id']) ?></span></td>
                        <td><?= (int) $channel['is_enabled'] === 1 ? 'Enabled' : 'Disabled' ?> / <?= (int) $channel['allow_dm_followup'] === 1 ? 'DM allowed' : 'Channel only' ?></td>
                        <td>
                            <form method="post">
                                <input type="hidden" name="action" value="delete_channel">
                                <input type="hidden" name="tab" value="channels">
                                <input type="hidden" name="binding_id" value="<?= (int) $channel['id'] ?>">
                                <button class="bc-btn secondary" type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php elseif ($tab === 'users'): ?>
    <div class="bc-table-wrap">
        <table class="bc-table">
            <thead><tr><th>User</th><th>Discord</th><th>Linked</th><th>Last seen</th></tr></thead>
            <tbody>
            <?php foreach ($linkedUsers as $linkedUser): ?>
                <tr>
                    <td><?= bugcatcher_html($linkedUser['username']) ?><br><span class="bc-meta"><?= bugcatcher_html($linkedUser['email']) ?></span></td>
                    <td><?= bugcatcher_html($linkedUser['discord_global_name'] ?: $linkedUser['discord_username'] ?: 'Not linked') ?></td>
                    <td><?= bugcatcher_html(bugcatcher_checklist_format_datetime($linkedUser['linked_at'] ?? null)) ?></td>
                    <td><?= bugcatcher_html(bugcatcher_checklist_format_datetime($linkedUser['last_seen_at'] ?? null)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php elseif ($tab === 'requests'): ?>
    <div class="bc-table-wrap">
        <table class="bc-table">
            <thead><tr><th>Status</th><th>Requester</th><th>Org / Project</th><th>Summary</th><th>Batch</th></tr></thead>
            <tbody>
            <?php foreach ($requests as $request): ?>
                <tr>
                    <td><?= bugcatcher_html($request['status']) ?><br><span class="bc-meta"><?= bugcatcher_html($request['current_step'] ?: 'n/a') ?></span></td>
                    <td><?= bugcatcher_html($request['requested_by_name'] ?: 'Unknown') ?><br><span class="bc-meta"><?= bugcatcher_html($request['discord_global_name'] ?: $request['discord_username'] ?: 'Discord unknown') ?></span></td>
                    <td><?= bugcatcher_html(($request['org_name'] ?: 'No org') . ' / ' . ($request['project_name'] ?: 'No project')) ?></td>
                    <td><?= bugcatcher_html($request['request_summary'] ?: 'No summary yet') ?></td>
                    <td><?= bugcatcher_html($request['submitted_batch_title'] ?: 'Not submitted') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php elseif ($tab === 'documents'): ?>
    <div class="bc-docs">
        <div class="bc-docs-nav">
            <?php foreach ($docs as $file => $path): ?>
                <a class="<?= $docFile === $file ? 'active' : '' ?>" href="?tab=documents&doc=<?= urlencode($file) ?>">
                    <?= bugcatcher_html(bugcatcher_openclaw_doc_title($file)) ?>
                </a>
            <?php endforeach; ?>
        </div>
        <div class="bc-panel bc-markdown">
            <?= bugcatcher_openclaw_render_markdown((string) $docMarkdown) ?>
        </div>
    </div>
<?php endif; ?>

<?php bugcatcher_shell_end(); ?>
