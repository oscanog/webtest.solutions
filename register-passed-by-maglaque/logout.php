<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';

bugcatcher_start_session();
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}

session_destroy();
header("location: login.php");
exit();
