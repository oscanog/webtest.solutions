<?php
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/app/legacy_issue_helpers.php';
require_once dirname(__DIR__) . '/app/sidebar.php';

function post_int($key): int
{
    $v = $_POST[$key] ?? '';
    return ctype_digit((string) $v) ? (int) $v : 0;
}

$orgId = (int) ($_SESSION['active_org_id'] ?? 0);
if ($orgId <= 0) {
    header("Location: " . bugcatcher_path('zen/organization.php'));
    exit;
}

$mem = bugcatcher_issue_find_membership($conn, $orgId, $current_user_id);
if (!$mem) {
    die("You are not a member of the active organization.");
}

$authorId = (int) $current_user_id;
$error = '';
$selectedLabels = array_map('strval', $_POST['labels'] ?? []);
$selectedProjectId = post_int('project_id');

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        bugcatcher_issue_create_from_form($conn, $orgId, $authorId, $_POST, $_FILES);
        header("Location: " . bugcatcher_path('zen/dashboard.php?page=issues&view=kanban&status=all'));
        exit();
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$labels = bugcatcher_issue_label_catalog($conn);
$projects = bugcatcher_issue_project_catalog($conn, $orgId);
if ($selectedProjectId <= 0 && $projects) {
    $selectedProjectId = (int) ($projects[0]['id'] ?? 0);
}
?>

<!doctype html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars(bugcatcher_path('favicon.svg')) ?>">
    <title>New Issue · BugCatcher</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(bugcatcher_path('app/legacy_theme.css?v=2')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(bugcatcher_path('app/legacy_issues.css?v=2')) ?>">
</head>

<body>
    <?php bugcatcher_render_sidebar('issues', $current_username, $current_role, (string) ($mem['role'] ?? ''), null); ?>

    <main class="main">
        <div class="topbar">
            <h1>New Issue</h1>
            <a href="<?= htmlspecialchars(bugcatcher_path('zen/dashboard.php?page=issues&view=kanban&status=all')) ?>"
                class="btn-green create-issue-back-link">Back</a>
        </div>

        <div class="issue-container">
            <div class="issue issue-form-shell">
                <?php if ($error !== ''): ?>
                    <div class="bc-alert error">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data">
                    <label class="issue-form-label">Project</label>
                    <select name="project_id" required class="issue-input">
                        <option value="">Select a project</option>
                        <?php foreach ($projects as $project):
                            $projectId = (int) ($project['id'] ?? 0);
                            $projectCode = trim((string) ($project['code'] ?? ''));
                            $projectLabel = $projectCode !== ''
                                ? ((string) ($project['name'] ?? 'Project') . ' (' . $projectCode . ')')
                                : (string) ($project['name'] ?? 'Project');
                            ?>
                            <option value="<?= $projectId ?>" <?= $selectedProjectId === $projectId ? 'selected' : '' ?>>
                                <?= htmlspecialchars($projectLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$projects): ?>
                        <small class="issue-help">Create an active project first before opening a new issue.</small>
                    <?php endif; ?>

                    <br><br>

                    <label class="issue-form-label">Title</label>
                    <input type="text" name="title" required class="issue-input"
                        value="<?= htmlspecialchars((string) ($_POST['title'] ?? '')) ?>">

                    <br><br>

                    <label class="issue-form-label">Description</label>
                    <textarea name="description"
                        class="issue-textarea"><?= htmlspecialchars((string) ($_POST['description'] ?? '')) ?></textarea>

                    <br><br>

                    <label class="issue-form-label">Attach Images</label>
                    <input type="file" id="imagesInput" name="images[]" accept="image/*" multiple class="issue-file-input">
                    <small class="issue-help">
                        You can upload JPG/PNG/GIF/WebP. Max 10 MB each.
                    </small>

                    <div id="imgPreview" class="issue-preview"></div>

                    <br><br>

                    <label class="issue-form-label">Author</label>
                    <input type="text"
                        value="<?= htmlspecialchars($current_username) ?> (<?= htmlspecialchars((string) ($mem['role'] ?? 'Org Member')) ?>)"
                        disabled class="issue-author-input">

                    <br><br>

                    <div class="issue-label-row">
                        <span class="issue-form-label">Labels</span>
                        <button type="button" id="clearLabelsBtn">Clear Labels</button>
                    </div>

                    <div class="label-pills">
                        <?php foreach ($labels as $l):
                            $labelId = (int) $l['id'];
                            $labelName = htmlspecialchars((string) ($l['name'] ?? ''));
                            $dotColor = (string) ($l['color'] ?? '#bbb');
                            $checked = in_array((string) $labelId, $selectedLabels, true);
                            ?>
                            <label class="pill <?= $checked ? 'selected' : '' ?>" data-pill>
                                <span class="dot" style="background: <?= htmlspecialchars($dotColor) ?>;"></span>
                                <input type="checkbox" name="labels[]" value="<?= $labelId ?>" <?= $checked ? 'checked' : '' ?>>
                                <span class="pill-text"><?= $labelName ?></span>
                                <span class="pill-close">&times;</span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <br><br>

                    <button type="submit" id="submitBtn" class="btn-green" <?= ($selectedLabels && $projects) ? '' : 'disabled' ?>>
                        Submit
                    </button>
                </form>
            </div>
        </div>
    </main>

    <script>
        const pills = document.querySelectorAll("[data-pill]");
        const clearBtn = document.getElementById("clearLabelsBtn");
        const submitBtn = document.getElementById("submitBtn");

        function updateSubmitState() {
            const checked = document.querySelectorAll("input[name='labels[]']:checked");
            submitBtn.disabled = checked.length === 0;
        }

        function syncPill(pill) {
            const cb = pill.querySelector('input[type="checkbox"]');
            pill.classList.toggle("selected", cb.checked);
        }

        pills.forEach(pill => {
            const cb = pill.querySelector('input[type="checkbox"]');

            pill.addEventListener("click", (e) => {
                if (e.target.tagName === "INPUT") return;

                e.preventDefault();

                if (e.target.classList.contains("pill-close")) {
                    cb.checked = false;
                } else {
                    cb.checked = !cb.checked;
                }

                syncPill(pill);
                updateSubmitState();
            });

            cb.addEventListener("change", () => {
                syncPill(pill);
                updateSubmitState();
            });

            syncPill(pill);
        });

        clearBtn?.addEventListener("click", () => {
            document.querySelectorAll("input[name='labels[]']:checked").forEach(cb => {
                cb.checked = false;
                cb.dispatchEvent(new Event("change", { bubbles: true }));
            });
        });

        updateSubmitState();
    </script>

    <script src="<?= htmlspecialchars(bugcatcher_path('app/mobile_nav.js?v=1')) ?>"></script>
    <script>
        const imagesInput = document.getElementById("imagesInput");
        const imgPreview = document.getElementById("imgPreview");

        let oldUrls = [];

        imagesInput?.addEventListener("change", () => {
            oldUrls.forEach(u => URL.revokeObjectURL(u));
            oldUrls = [];

            imgPreview.innerHTML = "";
            const files = Array.from(imagesInput.files || []);

            files.forEach(file => {
                if (!file.type.startsWith("image/")) return;

                const url = URL.createObjectURL(file);
                oldUrls.push(url);

                const wrap = document.createElement("div");
                wrap.className = "issue-preview-card";

                const link = document.createElement("a");
                link.href = url;
                link.target = "_blank";

                const img = document.createElement("img");
                img.src = url;
                img.alt = file.name;

                link.appendChild(img);
                wrap.appendChild(link);
                imgPreview.appendChild(wrap);
            });
        });
    </script>

</body>

</html>
