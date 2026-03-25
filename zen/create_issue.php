<?php
require_once dirname(__DIR__) . '/db.php';

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

$orgId = (int) ($_SESSION['active_org_id'] ?? 0);
if ($orgId <= 0) {
    header("Location: " . bugcatcher_path('zen/organization.php'));
    exit;
}

$mem = require_membership($conn, $orgId, $current_user_id);
if (!$mem) {
    die("You are not a member of the active organization.");
}

if ($mem['role'] !== 'Project Manager') {
    die("Only Project Managers can create issues.");
}

// Always use the logged-in Project Manager as author
$author_id = (int) $current_user_id;

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $title = $_POST['title'] ?? '';
    $description = $_POST['description'] ?? '';
    $author_id = (int) $current_user_id;

    // ✅ REQUIRE AT LEAST ONE LABEL
    if (empty($_POST['labels']) || !is_array($_POST['labels'])) {
        $error = "Please select at least one label.";
    } else {
        bugcatcher_file_storage_ensure_schema($conn);

        // Insert issue safely
        $stmt = $conn->prepare("
            INSERT INTO issues (title, description, author_id, org_id)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param("ssii", $title, $description, $author_id, $orgId);
        $stmt->execute();

        $issueId = $conn->insert_id;

        // ---- Handle image uploads (optional) ----
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];

        $maxBytes = 10 * 1024 * 1024; // 10 MB

        if (!empty($_FILES['images']) && is_array($_FILES['images']['name'])) {

            $stmtAtt = $conn->prepare("
              INSERT INTO issue_attachments (issue_id, file_path, storage_key, storage_provider, original_name, mime_type, file_size)
              VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            for ($i = 0; $i < count($_FILES['images']['name']); $i++) {

                $err = $_FILES['images']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
                if ($err === UPLOAD_ERR_NO_FILE)
                    continue;
                if ($err !== UPLOAD_ERR_OK)
                    continue;

                $tmp = $_FILES['images']['tmp_name'][$i] ?? '';
                if ($tmp === '' || !is_uploaded_file($tmp))
                    continue;

                $size = (int) ($_FILES['images']['size'][$i] ?? 0);
                if ($size <= 0 || $size > $maxBytes)
                    continue;

                // Detect mime securely
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $tmp);
                finfo_close($finfo);

                if (!isset($allowed[$mime]))
                    continue;

                $ext = $allowed[$mime];
                $origName = $_FILES['images']['name'][$i] ?? 'image.' . $ext;

                // Make safe file name
                $safeOrig = preg_replace('/[^a-zA-Z0-9._-]/', '_', $origName);
                try {
                    $stored = bugcatcher_file_storage_upload_file($tmp, $safeOrig, $mime, $size, 'issues');
                } catch (Throwable $e) {
                    continue;
                }

                $filePath = (string) ($stored['file_path'] ?? '');
                $storageKey = (string) ($stored['storage_key'] ?? '');
                $storageProvider = (string) ($stored['storage_provider'] ?? '');
                $storedName = (string) ($stored['original_name'] ?? $safeOrig);
                $storedMime = (string) ($stored['mime_type'] ?? $mime);
                $storedSize = (int) ($stored['file_size'] ?? $size);

                // Save record
                $stmtAtt->bind_param("isssssi", $issueId, $filePath, $storageKey, $storageProvider, $storedName, $storedMime, $storedSize);
                $stmtAtt->execute();
            }

            $stmtAtt->close();
        }

        // Insert labels safely
        $stmtLabel = $conn->prepare("
            INSERT INTO issue_labels (issue_id, label_id)
            VALUES (?, ?)
        ");

        foreach ($_POST['labels'] as $label_id) {
            $label_id = (int) $label_id;
            $stmtLabel->bind_param("ii", $issueId, $label_id);
            $stmtLabel->execute();
        }

        header("Location: " . bugcatcher_path('zen/dashboard.php?page=dashboard'));
        exit();
    }
}

$labels = $conn->query("SELECT id, name, color FROM labels ORDER BY name ASC");
?>

<!doctype html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars(bugcatcher_path('favicon.svg')) ?>">
    <title>New Issue · BugCatcher</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(bugcatcher_path('zen/dashboard.css?v=8')) ?>">
</head>

<body>

    <button type="button" class="mobile-nav-toggle" data-drawer-toggle data-drawer-target="zen-sidebar-create-issue"
        aria-controls="zen-sidebar-create-issue" aria-expanded="false" aria-label="Open navigation menu">
        <span></span>
        <span></span>
        <span></span>
    </button>
    <div class="mobile-nav-backdrop" data-drawer-backdrop hidden></div>

    <aside class="sidebar" id="zen-sidebar-create-issue" data-drawer data-drawer-breakpoint="900">
        <div class="logo">BugCatcher</div>
        <nav class="nav">
            <a href="<?= htmlspecialchars(bugcatcher_path('zen/dashboard.php?page=dashboard')) ?>">Dashboard</a>
            <?php if (bugcatcher_is_system_admin_role($current_role)): ?>
                <a href="#">Manage Users</a>
            <?php endif; ?>
            <a href="<?= htmlspecialchars(bugcatcher_path('zen/organization.php')) ?>">Organization</a>
            <a href="<?= htmlspecialchars(bugcatcher_path('melvin/project_list.php')) ?>">Projects</a>
            <a href="<?= htmlspecialchars(bugcatcher_path('melvin/checklist_list.php')) ?>">Checklist</a>
            <a href="<?= htmlspecialchars(bugcatcher_path('discord-link.php')) ?>">Discord Link</a>
            <?php if (bugcatcher_is_super_admin_role($current_role)): ?>
                <a href="<?= htmlspecialchars(bugcatcher_path('super-admin/openclaw.php')) ?>">Super Admin</a>
            <?php endif; ?>
            <a href="<?= htmlspecialchars(bugcatcher_path('rainier/logout.php')) ?>" class="nav-logout">Logout</a>
        </nav>
        <div class="sidebar-userbox">
            Logged in as<br>
            <strong><?= htmlspecialchars($current_username) ?></strong><br>
            <span class="sidebar-role">(<?= htmlspecialchars($current_role) ?>)</span>
        </div>
    </aside>

    <main class="main">

        <div class="topbar">
            <h1>New Issue</h1>
            <a href="<?= htmlspecialchars(bugcatcher_path('zen/dashboard.php?page=dashboard')) ?>" class="btn-green create-issue-back-link">Back</a>
        </div>

        <div class="issue-container">
            <div class="issue">
                <?php if (!empty($error)): ?>
                    <div class="issue-form-alert">
                        <?= $error ?>
                    </div>
                <?php endif; ?>
                <form method="POST" enctype="multipart/form-data">

                    <label class="issue-form-label">Title</label>
                    <input type="text" name="title" required class="issue-input">

                    <br><br>

                    <label class="issue-form-label">Description</label>
                    <textarea name="description" class="issue-textarea"></textarea>

                    <br><br>

                    <label class="issue-form-label">Attach Images</label>
                    <input type="file" id="imagesInput" name="images[]" accept="image/*" multiple class="issue-file-input">
                    <small class="issue-help">
                        You can upload JPG/PNG/GIF/WebP. Max 10 MB each.
                    </small>

                    <div id="imgPreview" class="issue-preview"></div>

                    <br><br>

                    <label class="issue-form-label">Author</label>
                    <input type="text" value="<?= htmlspecialchars($current_username) ?> (Project Manager)" disabled
                        class="issue-author-input">

                    <br><br>

                    <div class="issue-label-row">
                        <span class="issue-form-label">Labels</span>

                        <button type="button" id="clearLabelsBtn">Clear Labels</button>
                    </div>

                    <div class="label-pills">
                        <?php while ($l = $labels->fetch_assoc()):
                            $labelId = (int) $l['id'];
                            $labelName = htmlspecialchars($l['name']);
                            $dotColor = $l['color'] ?? '#bbb';
                            ?>
                            <label class="pill" data-pill>
                                <span class="dot" style="background: <?= htmlspecialchars($dotColor) ?>;"></span>

                                <input type="checkbox" name="labels[]" value="<?= $labelId ?>">

                                <span class="pill-text"><?= $labelName ?></span>

                                <span class="pill-close">&times;</span>
                            </label>
                        <?php endwhile; ?>
                    </div>

                    <br><br>

                    <button type="submit" id="submitBtn" class="btn-green" disabled>
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
                // allow normal checkbox behavior if clicked directly
                if (e.target.tagName === "INPUT") return;

                // prevent <label> default toggle to avoid double toggle
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
            // cleanup old urls
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
                link.target = "_blank"; // 👈 Opens in new tab

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
