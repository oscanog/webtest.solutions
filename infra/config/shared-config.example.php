<?php

return [
    'APP_ENV' => 'production',
    'APP_BASE_URL' => 'https://bugcatcher.example.com',
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => 3306,
    'DB_NAME' => 'bug_catcher',
    'DB_USER' => 'bugcatcher_app',
    'DB_PASS' => 'replace-with-a-strong-random-password',
    'UPLOADS_DIR' => '/var/www/bugcatcher/shared/uploads/issues',
    'UPLOADS_URL' => 'uploads/issues',
    'CHECKLIST_UPLOADS_DIR' => '/var/www/bugcatcher/shared/uploads/checklists',
    'CHECKLIST_UPLOADS_URL' => 'uploads/checklists',
    'CHECKLIST_BOT_SHARED_SECRET' => 'replace-with-a-long-random-secret',
];
