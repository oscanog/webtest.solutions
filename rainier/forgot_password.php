<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/password_reset.php';

bugcatcher_start_session();
include "connection.php";

if (isset($_SESSION['id'])) {
  header("Location: " . bugcatcher_path('zen/organization.php'));
  exit();
}

$error = "";
$success = "";
$info = "";
$emailValue = bugcatcher_password_reset_session_email();

if (($_GET['restart'] ?? '') === '1') {
  bugcatcher_password_reset_clear_state();
  $emailValue = "";
  $info = "Start over with the email address on your account.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!bugcatcher_password_reset_verify_csrf($_POST['csrf_token'] ?? '')) {
    $error = "Your reset session expired. Refresh the page and try again.";
  } else {
    $action = $_POST['action'] ?? '';

    if ($action === 'start_over') {
      bugcatcher_password_reset_clear_state();
      $emailValue = "";
      $info = "Start over with the email address on your account.";
    } elseif ($action === 'request_otp') {
      $emailValue = trim($_POST['email'] ?? '');
      $result = bugcatcher_password_reset_request_otp($conn, $emailValue);
      if ($result['ok']) {
        $success = (string) ($result['message'] ?? '');
      } else {
        $error = (string) ($result['error'] ?? 'Unable to start the password reset right now.');
      }
    } elseif ($action === 'resend_otp') {
      $emailValue = bugcatcher_password_reset_session_email();
      if ($emailValue === '') {
        $emailValue = trim($_POST['email'] ?? '');
      }
      $result = bugcatcher_password_reset_resend_otp($conn, $emailValue);
      if ($result['ok']) {
        $success = (string) ($result['message'] ?? '');
      } else {
        $error = (string) ($result['error'] ?? 'Unable to resend the reset code right now.');
      }
    } elseif ($action === 'verify_otp') {
      $emailValue = bugcatcher_password_reset_session_email();
      if ($emailValue === '') {
        $emailValue = trim($_POST['email'] ?? '');
      }
      $result = bugcatcher_password_reset_verify_otp($conn, $emailValue, (string) ($_POST['otp'] ?? ''));
      if ($result['ok']) {
        $success = (string) ($result['message'] ?? '');
      } else {
        $error = (string) ($result['error'] ?? 'Unable to verify that code.');
      }
    } elseif ($action === 'reset_password') {
      $emailValue = bugcatcher_password_reset_session_email();
      $result = bugcatcher_password_reset_update_password(
        $conn,
        bugcatcher_password_reset_verified_request_id(),
        $emailValue,
        (string) ($_POST['password'] ?? ''),
        (string) ($_POST['cpass'] ?? '')
      );
      if ($result['ok']) {
        header("Location: " . bugcatcher_path('rainier/login.php?reset=success'));
        exit();
      }

      $error = (string) ($result['error'] ?? 'Unable to reset the password right now.');
    }
  }
}

$currentStep = bugcatcher_password_reset_current_step();
$emailValue = ($emailValue !== '') ? $emailValue : bugcatcher_password_reset_session_email();
$maskedEmail = ($emailValue !== '') ? bugcatcher_password_reset_mask_email($emailValue) : '';
$csrfToken = bugcatcher_password_reset_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Forgot Password</title>
  <link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars(bugcatcher_path('favicon.svg')) ?>">
  <link rel="stylesheet" href="css/style1.css?v=3">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
</head>

<body>
  <div class="container">
    <div class="form-box box">
      <header>Reset Password</header>
      <hr>
      <p class="auth-subtitle">Request a 6-digit OTP and set a new password</p>

      <?php if ($info !== ""): ?>
        <div class='message info'>
          <p><?= htmlspecialchars($info) ?></p>
        </div><br>
      <?php endif; ?>

      <?php if ($success !== ""): ?>
        <div class='message success'>
          <p><?= htmlspecialchars($success) ?></p>
        </div><br>
      <?php endif; ?>

      <?php if ($error !== ""): ?>
        <div class='message'>
          <p><?= htmlspecialchars($error) ?></p>
        </div><br>
      <?php endif; ?>

      <?php if ($currentStep === 'request'): ?>
        <form action="#" method="POST" class="auth-form">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
          <input type="hidden" name="action" value="request_otp">

          <div class="form-box">
            <div class="input-container">
              <i class="fa fa-envelope icon"></i>
              <input
                class="input-field"
                type="email"
                placeholder="Email Address"
                name="email"
                value="<?= htmlspecialchars($emailValue) ?>"
                autocomplete="email"
                required>
            </div>
          </div>

          <input type="submit" value="Send OTP" class="button">
        </form>
      <?php elseif ($currentStep === 'otp'): ?>
        <p class="auth-meta">
          Enter the latest 6-digit code sent to <?= htmlspecialchars($maskedEmail !== '' ? $maskedEmail : $emailValue) ?>.
        </p>

        <form action="#" method="POST" class="auth-form">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
          <input type="hidden" name="action" value="verify_otp">
          <input type="hidden" name="email" value="<?= htmlspecialchars($emailValue) ?>">

          <div class="form-box">
            <div class="input-container">
              <i class="fa fa-key icon"></i>
              <input
                class="input-field otp-code"
                type="text"
                placeholder="000000"
                name="otp"
                inputmode="numeric"
                maxlength="6"
                autocomplete="one-time-code"
                required>
            </div>
          </div>

          <input type="submit" value="Verify OTP" class="button">
        </form>

        <div class="auth-actions">
          <form action="#" method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="resend_otp">
            <input type="hidden" name="email" value="<?= htmlspecialchars($emailValue) ?>">
            <button type="submit" class="btn btn-secondary">Resend Code</button>
          </form>

          <form action="#" method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="start_over">
            <button type="submit" class="btn btn-secondary">Start Over</button>
          </form>
        </div>
      <?php else: ?>
        <p class="auth-meta">
          OTP verified for <?= htmlspecialchars($maskedEmail !== '' ? $maskedEmail : $emailValue) ?>. Set your new password below.
        </p>

        <form action="#" method="POST" class="auth-form">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
          <input type="hidden" name="action" value="reset_password">

          <div class="form-box">
            <div class="input-container">
              <i class="fa fa-lock icon"></i>
              <input
                class="input-field password"
                type="password"
                placeholder="New Password"
                name="password"
                autocomplete="new-password"
                required>
              <i class="fa fa-eye icon toggle-password"></i>
            </div>

            <div class="input-container">
              <i class="fa fa-lock icon"></i>
              <input
                class="input-field confirm-password"
                type="password"
                placeholder="Confirm New Password"
                name="cpass"
                autocomplete="new-password"
                required>
              <i class="fa fa-eye icon toggle-confirm"></i>
            </div>
          </div>

          <input type="submit" value="Reset Password" class="button">
        </form>
      <?php endif; ?>

      <a href="login.php" class="page-back-link">Back to Login</a>
    </div>
  </div>

  <script>
    const togglePassword = document.querySelector(".toggle-password");
    const passwordInput = document.querySelector(".password");
    const toggleConfirm = document.querySelector(".toggle-confirm");
    const confirmInput = document.querySelector(".confirm-password");

    if (togglePassword && passwordInput) {
      togglePassword.addEventListener("click", () => {
        passwordInput.type = (passwordInput.type === "password") ? "text" : "password";
      });
    }

    if (toggleConfirm && confirmInput) {
      toggleConfirm.addEventListener("click", () => {
        confirmInput.type = (confirmInput.type === "password") ? "text" : "password";
      });
    }
  </script>
</body>

</html>
