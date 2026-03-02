# Database Bootstrap

Production import order:

1. `infra/database/schema.sql`
2. `infra/database/seed_reference_data.sql`
3. `infra/database/seed_admin.sql`

Optional demo data:

1. Import the three files above.
2. Import `infra/database/demo.sql`.

`infra/database/seed_admin.sql` is intentionally a template. Replace the username, email, and password hash before you import it.

Legacy SQL artifacts that are not part of the production bootstrap live under `infra/database/legacy/`.

Generate a bcrypt hash with:

```bash
php -r "echo password_hash('ChangeThisPasswordNow!', PASSWORD_DEFAULT), PHP_EOL;"
```
