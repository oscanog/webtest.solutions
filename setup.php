<?php
http_response_code(403);
header('Content-Type: text/plain; charset=UTF-8');

echo "setup.php is disabled.\n";
echo "Use database/schema.sql, database/seed_reference_data.sql, and database/seed_admin.sql instead.\n";
