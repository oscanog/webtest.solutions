<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';

bugcatcher_start_session();
include "connection.php";

if (isset($_SESSION['id'])) {
  header("Location: " . bugcatcher_path('zen/organization.php'));
  exit();
}

$error = "";
$infoMessages = [];
if (($_GET['reason'] ?? '') === 'expired') {
  $infoMessages[] = "Your session expired. Please sign in again.";
}
if (($_GET['reset'] ?? '') === 'success') {
  $infoMessages[] = "Your password has been reset. Please sign in with your new password.";
}

if (isset($_POST['login'])) {
  $email = trim($_POST['email'] ?? '');
  $pass = $_POST['password'] ?? '';

  if ($email === '' || $pass === '') {
    $error = "Please enter email and password.";
  } else {
    $stmt = $conn->prepare("SELECT id, username, password, role, last_active_org_id FROM users WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res && $res->num_rows === 1) {
      $row = $res->fetch_assoc();

      if (password_verify($pass, $row['password'])) {
        $role = bugcatcher_normalize_system_role($row['role'] ?? 'user');

        $_SESSION['id'] = (int) $row['id'];
        $_SESSION['username'] = $row['username'];
        $_SESSION['role'] = $role;
        bugcatcher_mark_known_user_browser();

        // Restore last active org into session (survives logout)
        $lastOrgId = (int) ($row['last_active_org_id'] ?? 0);

        if ($lastOrgId > 0) {
          // Verify user is still a member of that org
          $check = $conn->prepare("SELECT 1 FROM org_members WHERE org_id=? AND user_id=? LIMIT 1");
          $uid = (int) $row['id'];
          $check->bind_param("ii", $lastOrgId, $uid);
          $check->execute();
          $ok = $check->get_result()->fetch_assoc();
          $check->close();

          if ($ok) {
            $_SESSION['active_org_id'] = $lastOrgId;
          }
        }

        // Optional fallback: if no last org, auto-pick first org they belong to
        if (empty($_SESSION['active_org_id'])) {
          $uid = (int) $row['id'];
          $pick = $conn->prepare("SELECT org_id FROM org_members WHERE user_id=? ORDER BY org_id ASC LIMIT 1");
          $pick->bind_param("i", $uid);
          $pick->execute();
          $r = $pick->get_result()->fetch_assoc();
          $pick->close();

          if ($r) {
            $firstOrg = (int) $r['org_id'];
            $_SESSION['active_org_id'] = $firstOrg;

            // persist it too
            $up = $conn->prepare("UPDATE users SET last_active_org_id=? WHERE id=?");
            $up->bind_param("ii", $firstOrg, $uid);
            $up->execute();
            $up->close();
          }
        }

        header("Location: " . bugcatcher_path('zen/organization.php'));
        exit();
      }
    }

    $error = "Wrong email or password.";
  }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login</title>
  <link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars(bugcatcher_path('favicon.svg')) ?>">
  <link rel="stylesheet" href="css/style1.css?v=3">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
</head>

<body>
  <div class="container">
    <div class="form-box box">
      <header>Login</header>
      <hr>
      <p class="auth-subtitle">Sign in to continue to WebTest</p>

      <?php foreach ($infoMessages as $infoMessage): ?>
        <div class='message info'>
          <p><?= htmlspecialchars($infoMessage) ?></p>
        </div><br>
      <?php endforeach; ?>

      <?php if ($error !== ""): ?>
        <div class='message'>
          <p><?= htmlspecialchars($error) ?></p>
        </div><br>
      <?php endif; ?>

      <form action="#" method="POST" class="auth-form">
        <div class="form-box">
          <div class="input-container">
            <i class="fa fa-envelope icon"></i>
            <input class="input-field" type="email" placeholder="Email Address" name="email" required>
          </div>

          <div class="input-container">
            <i class="fa fa-lock icon"></i>
            <input class="input-field password" type="password" placeholder="Password" name="password" required>
            <i class="fa fa-eye toggle icon"></i>
          </div>
        </div>

        <input type="submit" name="login" id="submit" value="Login" class="button login-submit">

        <div class="forgot-password">
          <a href="forgot_password.php">Forgot Password?</a>
        </div>

        <div class="links">
          Don't have an account? <a href="signup.php">Signup Now</a>
        </div>
      </form>
    </div>
  </div>

  <script>
    const toggle = document.querySelector(".toggle");
    const input = document.querySelector(".password");

    if (toggle && input) {
      toggle.addEventListener("click", () => {
        if (input.type === "password") {
          input.type = "text";
        } else {
          input.type = "password";
        }
      });
    }
  </script>
</body>

</html>
