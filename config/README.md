# Legacy Config Path

`config/` is a compatibility path during migration.

Canonical local config files now live under `infra/config/`.

Load order in app bootstrap is:

1. `WEBTEST_CONFIG_PATH` (env override)
2. `/var/www/webtest/shared/config.php`
3. `infra/config/local.php`
4. `config/local.php` (legacy fallback)

Keep `config/local.php` only for older operators/scripts that have not yet switched.

When adding new runtime keys such as the password reset mail settings, update both:

- `infra/config/*.php`
- `config/local.php.example`
