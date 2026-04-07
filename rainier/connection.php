<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';

try {
    $conn = webtest_db_connection();
} catch (RuntimeException $e) {
    die($e->getMessage());
}
