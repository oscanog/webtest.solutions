<?php

require_once __DIR__ . '/_shared.php';

bugcatcher_openclaw_require_internal_request();
bugcatcher_openclaw_json_response(200, bugcatcher_openclaw_effective_runtime_config($conn));
