<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/core.php';
require_once __DIR__ . '/lib/routes.php';

bugcatcher_start_session();
header('Content-Type: application/json');
header('Cache-Control: no-store');

if (bc_v1_method() === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    $conn = bugcatcher_db_connection();
} catch (RuntimeException $e) {
    bc_v1_json_error(500, 'db_connection_failed', $e->getMessage());
}

$method = bc_v1_method();
$path = bc_v1_route_path();

try {
    $matched = bc_v1_dispatch($method, $path, bc_v1_routes(), $conn);
} catch (Throwable $e) {
    bc_v1_json_error(500, 'unhandled_exception', 'Unhandled server error.', $e->getMessage());
}

if (!$matched) {
    bc_v1_json_error(404, 'not_found', 'Route not found.', [
        'method' => $method,
        'path' => $path,
    ]);
}
