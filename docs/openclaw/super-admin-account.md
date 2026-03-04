# Super Admin Account

This guide explains how to create or reset a BugCatcher `super_admin` account on the GCloud VM.

## Current production account

As of March 5, 2026, production already has this `super_admin` user:

- `username`: `admin`
- `email`: `admin@bugcatcher.local`

BugCatcher stores only a password hash, so the plaintext password cannot be recovered from the database.

## Create or reset a super admin

Use the prompt-driven helper script on the VM:

```bash
cd /var/www/bugcatcher
chmod +x infra/openclaw-upstream/create_super_admin.sh
sudo ./infra/openclaw-upstream/create_super_admin.sh
```

The script prompts for:

1. super admin email address
2. super admin username
3. password
4. password confirmation

Behavior:

- if the email or username already exists, that user is updated
- the password is reset
- the role is forced to `super_admin`
- if no matching user exists, a new `super_admin` row is created
- the script then verifies the stored password hash
- the script then performs a live login check against the production login route

## Expected output

On success, the script prints something like:

```text
Created new super_admin user.
id=13
username=your-admin
email=you@example.com
role=super_admin
Password hash verification: OK
Web login verification: OK (redirected to ../dashboard.php)
```

or:

```text
Updated existing user to super_admin.
id=1
username=admin
email=admin@bugcatcher.local
role=super_admin
Password hash verification: OK
Web login verification: OK (redirected to ../dashboard.php)
```

## Login route

After creating or resetting the account, log in through:

- `https://bugcatcher.online/register-passed-by-maglaque/login.php`

Then open:

- `https://bugcatcher.online/super-admin/openclaw.php`

## Troubleshooting

### Script not found

If you see:

```text
chmod: cannot access 'infra/openclaw-upstream/create_super_admin.sh': No such file or directory
```

the VM checkout has not pulled the latest repo changes yet. Run:

```bash
cd /var/www/bugcatcher
sudo git fetch origin main
sudo git pull origin main
```

Then retry the script.

### Git safe directory warning

If git reports `detected dubious ownership`, run:

```bash
git config --global --add safe.directory /var/www/bugcatcher
```

### Existing password unknown

You do not need the old password. Running the script with the same email or username resets it.

### Script says verification failed

The script now stops if either of these checks fails:

- the stored password hash does not match the password you entered
- the live login request does not redirect to `../dashboard.php`

If that happens, keep the script output. It includes a response preview from the login page to help diagnose the failure.
