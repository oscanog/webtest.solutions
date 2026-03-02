# Database Bootstrap

Production import order:

1. `database/schema.sql`
2. `database/seed_reference_data.sql`
3. `database/seed_admin.sql`

Optional demo data:

1. Import the three files above.
2. Import `database/demo.sql`.

`database/seed_admin.sql` is intentionally a template. Replace the username, email, and password hash before you import it.

Generate a bcrypt hash with:

```bash
php -r "echo password_hash('ChangeThisPasswordNow!', PASSWORD_DEFAULT), PHP_EOL;"
```
