USE web_test;

-- Replace the values below before importing this file.
-- Generate a bcrypt hash with:
-- php -r "echo password_hash('ChangeThisPasswordNow!', PASSWORD_DEFAULT), PHP_EOL;"

INSERT INTO users (username, email, password, role)
VALUES (
  'CHANGE_ME_SUPER_ADMIN',
  'super-admin@example.com',
  'CHANGE_ME_WITH_PASSWORD_HASH',
  'super_admin'
);
