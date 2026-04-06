<?php

$expectedUser = 'fixture-user';
$expectedPass = 'fixture-pass';
$user = (string) ($_SERVER['PHP_AUTH_USER'] ?? '');
$pass = (string) ($_SERVER['PHP_AUTH_PW'] ?? '');

if ($user !== $expectedUser || $pass !== $expectedPass) {
    header('WWW-Authenticate: Basic realm="WebTest AI Fixture"');
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Authentication required.";
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Protected AI Fixture</title>
</head>
<body>
  <main>
    <h1>Protected AI Fixture</h1>
    <p>This protected page is available after HTTP Basic Auth and gives the AI chat preview endpoint readable HTML to analyze for link-based checklist drafting.</p>
    <p>It describes authenticated dashboard content, visible actions, state changes, and verification points that manual QA can turn into checklist coverage.</p>
  </main>
</body>
</html>
