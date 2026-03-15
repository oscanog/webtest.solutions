<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';

bugcatcher_start_session();
include "connection.php";

if (isset($_SESSION['id'])) {
  header("Location: " . bugcatcher_path('zen/dashboard.php'));
  exit();
}

$error = "";
$success = "";

if (isset($_POST['register'])) {
  $name = trim($_POST['username'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $pass = $_POST['password'] ?? '';
  $cpass = $_POST['cpass'] ?? '';

  if ($name === '' || $email === '' || $pass === '' || $cpass === '') {
    $error = "Please fill in all fields.";
  } elseif ($pass !== $cpass) {
    $error = "Password does not match.";
  } else {
    $checkStmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $checkStmt->bind_param("s", $email);
    $checkStmt->execute();
    $checkRes = $checkStmt->get_result();

    if ($checkRes && $checkRes->num_rows > 0) {
      $error = "This email is already used. Try another one.";
    } else {
      $hashedPassword = password_hash($pass, PASSWORD_DEFAULT);
      $insertStmt = $conn->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, 'user')");
      $insertStmt->bind_param("sss", $name, $email, $hashedPassword);

      if ($insertStmt->execute()) {
        $success = "You are registered successfully. You can now login.";
      } else {
        $error = "Registration failed. Please try again.";
      }
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Register</title>
  <link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars(bugcatcher_path('favicon.svg')) ?>">
  <link rel="stylesheet" href="css/style1.css?v=3">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
</head>

<body>
  <div class="container">
    <div class="form-box box">
      <header>Sign Up</header>
      <hr>
      <p class="auth-subtitle">Create your BugCatcher account</p>

      <?php if ($error !== ""): ?>
        <div class='message'>
          <p><?= htmlspecialchars($error) ?></p>
        </div><br>
      <?php endif; ?>

      <?php if ($success !== ""): ?>
        <div class='message success'>
          <p><?= htmlspecialchars($success) ?></p>
        </div><br>
        <a href="login.php" class="button-link btn">Login Now</a>
      <?php else: ?>
        <form action="#" method="POST" class="auth-form">
          <div class="form-box">
            <div class="input-container">
              <i class="fa fa-user icon"></i>
              <input class="input-field" type="text" placeholder="Username" name="username" required>
            </div>

            <div class="input-container">
              <i class="fa fa-envelope icon"></i>
              <input class="input-field" type="email" placeholder="Email Address" name="email" required>
            </div>

            <div class="input-container">
              <i class="fa fa-lock icon"></i>
              <input class="input-field password" type="password" placeholder="Password" name="password" required>
              <i class="fa fa-eye icon toggle-password"></i>
            </div>

            <div class="input-container">
              <i class="fa fa-lock icon"></i>
              <input class="input-field confirm-password" type="password" placeholder="Confirm Password" name="cpass" required>
              <i class="fa fa-eye icon toggle-confirm"></i>
            </div>
          </div>

          <center><input type="submit" name="register" id="submit" value="Signup" class="btn"></center>

          <div class="links">
            Already have an account? <a href="login.php">Signin Now</a>
          </div>
        </form>
      <?php endif; ?>
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
