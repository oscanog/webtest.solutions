#!/usr/bin/env bash
set -euo pipefail

mysql -Nse "
USE bug_catcher;
SELECT id, username, email, role
FROM users
ORDER BY
  CASE role
    WHEN 'admin' THEN 0
    ELSE 1
  END,
  id ASC;
"
