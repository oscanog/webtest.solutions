USE bug_catcher;

-- Replace the values below before importing this file.
-- Generate a bcrypt hash with:
-- php -r "echo password_hash('ChangeThisPasswordNow!', PASSWORD_DEFAULT), PHP_EOL;"

INSERT INTO users (username, email, password, role)
VALUES (
  'CHANGE_ME_ADMIN',
  'admin@example.com',
  'CHANGE_ME_WITH_PASSWORD_HASH',
  'admin'
);
