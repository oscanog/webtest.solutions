<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

header('Location: ' . webtest_path('super-admin/ai.php'), true, 302);
exit;
