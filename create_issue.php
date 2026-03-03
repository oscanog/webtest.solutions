<?php
include 'db.php';

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
    header("Location: organization.php");
    exit;
}

$mem = require_membership($conn, $orgId, $current_user_id);
if (!$mem) {
    die("You are not a member of the active organization.");
}

// Only admin and users can create issues (both can create)
// No restriction needed, both roles can create issues

// Pre-select current user as author
$default_author_id = $current_user_id;

if ($orgId <= 0) {
    die("Missing organization. Open Create Issue from a selected organization.");
}

$myMembership = require_membership($conn, $orgId, $current_user_id);
if (!$myMembership) {
    die("You are not a member of this organization.");
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $title = $_POST['title'] ?? '';
    $description = $_POST['description'] ?? '';
    $author_id = ($current_role === 'admin')
        ? (int) ($_POST['author'] ?? 0)
        : $current_user_id;

    // ✅ REQUIRE AT LEAST ONE LABEL
    if (empty($_POST['labels']) || !is_array($_POST['labels'])) {
        $error = "Please select at least one label.";
    } else {

        // Insert issue safely
        $stmt = $conn->prepare("
            INSERT INTO issues (title, description, author_id, org_id)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param("ssii", $title, $description, $author_id, $orgId);
        $stmt->execute();

        $issueId = $conn->insert_id;

        // ---- Handle image uploads (optional) ----
        $uploadDir = bugcatcher_uploads_dir();

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 02775, true);
        }

        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];

        $maxBytes = 10 * 1024 * 1024; // 10 MB

        if (!empty($_FILES['images']) && is_array($_FILES['images']['name'])) {

            $stmtAtt = $conn->prepare("
              INSERT INTO issue_attachments (issue_id, file_path, original_name, mime_type, file_size)
              VALUES (?, ?, ?, ?, ?)
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
                $newName = "issue_" . $issueId . "_" . bin2hex(random_bytes(8)) . "." . $ext;

                $destAbs = $uploadDir . "/" . $newName;
                $destRel = bugcatcher_upload_relative_path($newName);

                if (!move_uploaded_file($tmp, $destAbs))
                    continue;

                // Save record
                $stmtAtt->bind_param("isssi", $issueId, $destRel, $safeOrig, $mime, $size);
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

        header("Location: dashboard.php?page=dashboard");
        exit();
    }
}

// GET request: show form data
$users = null;
if ($current_role === 'admin') {
    $users = $conn->query("SELECT id, username FROM users ORDER BY username ASC");
}
$labels = $conn->query("SELECT id, name, color FROM labels ORDER BY name ASC");
?>

<!doctype html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <title>New Issue · BugCatcher</title>
    <link rel="stylesheet" href="dashboard.css?v=8">
</head>

<body>

    <aside class="sidebar">
        <div class="logo">BugCatcher</div>
        <nav class="nav">
            <a href="dashboard.php">Dashboard</a>
            <?php if ($current_role == 'admin'): ?>
                <a href="#">Manage Users</a>
            <?php endif; ?>
            <a href="organization.php">Organization</a>
            <a href="project-passed-by-melvin/project_list.php">Projects</a>
            <a href="checklist-passed-by-melvin/checklist_list.php">Checklist</a>
            <a href="register-passed-by-maglaque/logout.php" style="color:#ff7b72;">Logout</a>
        </nav>
        <div style="margin-top:auto; color:#8b949e; font-size:12px;">
            Logged in as<br>
            <strong><?= htmlspecialchars($current_username) ?></strong><br>
            <span style="text-transform:uppercase; font-size:10px;">(<?= htmlspecialchars($current_role) ?>)</span>
        </div>
    </aside>

    <main class="main">

        <div class="topbar">
            <h1>New Issue</h1>
            <a href="dashboard.php?page=dashboard" class="btn-green" style="background:#57606a;">Back</a>
        </div>

        <div class="issue-container">
            <div class="issue">
                <?php if (!empty($error)): ?>
                    <div style="background:#ffebe9; color:#cf222e; padding:10px; border-radius:6px; margin-bottom:15px;">
                        <?= $error ?>
                    </div>
                <?php endif; ?>
                <form method="POST" enctype="multipart/form-data">

                    <label style="display:block; font-weight:600; margin-bottom:6px;">Title</label>
                    <input type="text" name="title" required
                        style="width:100%; padding:10px; border:1px solid #d0d7de; border-radius:6px;">

                    <br><br>

                    <label style="display:block; font-weight:600; margin-bottom:6px;">Description</label>
                    <textarea name="description"
                        style="width:100%; height:140px; padding:10px; border:1px solid #d0d7de; border-radius:6px;"></textarea>

                    <br><br>

                    <label style="display:block; font-weight:600; margin-bottom:6px;">Attach Images</label>
                    <input type="file" id="imagesInput" name="images[]" accept="image/*" multiple
                        style="padding:10px; border:1px solid #d0d7de; border-radius:6px; background:#fff; width:100%;">
                    <small style="color:#57606a; display:block; margin-top:6px;">
                        You can upload JPG/PNG/GIF/WebP. Max 10 MB each.
                    </small>

                    <div id="imgPreview" style="display:flex; flex-wrap:wrap; gap:10px; margin-top:10px;"></div>

                    <br><br>

                    <?php if ($current_role === 'admin'): ?>
                        <label style="display:block; font-weight:600; margin-bottom:6px;">Author</label>
                        <select name="author" required style="padding:10px; border:1px solid #d0d7de; border-radius:6px;">
                            <?php while ($u = $users->fetch_assoc()): ?>
                                <option value="<?= (int) $u['id'] ?>" <?= ($u['id'] == $default_author_id) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($u['username']) ?>
                                    <?= ($u['id'] == $default_author_id) ? '(You)' : '' ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    <?php else: ?>
                        <label style="display:block; font-weight:600; margin-bottom:6px;">Author</label>
                        <input type="text" value="<?= htmlspecialchars($current_username) ?> (You)" disabled
                            style="padding:10px; border:1px solid #d0d7de; border-radius:6px; background:#f6f8fa;">
                    <?php endif; ?>

                    <br><br>

                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                        <span style="font-weight:600;">Labels</span>

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
                wrap.style.width = "120px";
                wrap.style.height = "120px";
                wrap.style.border = "1px solid #d0d7de";
                wrap.style.borderRadius = "8px";
                wrap.style.overflow = "hidden";
                wrap.style.background = "#fff";

                const link = document.createElement("a");
                link.href = url;
                link.target = "_blank"; // 👈 Opens in new tab

                const img = document.createElement("img");
                img.src = url;
                img.alt = file.name;
                img.style.width = "100%";
                img.style.height = "100%";
                img.style.objectFit = "cover";
                img.style.cursor = "pointer";

                link.appendChild(img);
                wrap.appendChild(link);
                imgPreview.appendChild(wrap);
            });
        });
    </script>

</body>

</html>
