# password_reset_requests

## Purpose

Stores one-time password reset challenges for email-based password recovery.

## Columns

| Column | Type | Nullable | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `INT(11)` | No | auto increment | Primary key. |
| `user_id` | `INT(11)` | No | none | Account receiving the reset code. |
| `otp_hash` | `CHAR(64)` | No | none | SHA-256 hash of the 6-digit OTP. |
| `expires_at` | `DATETIME` | No | none | OTP expiration timestamp. |
| `verify_attempt_count` | `INT(11)` | No | `0` | Failed OTP verification attempts on this request. |
| `resend_count` | `INT(11)` | No | `0` | Number of resend operations applied to this request. |
| `last_sent_at` | `DATETIME` | No | none | Timestamp of the latest OTP email send. |
| `verified_at` | `DATETIME` | Yes | `NULL` | Set after the OTP is verified successfully. |
| `used_at` | `DATETIME` | Yes | `NULL` | Set when the request is invalidated or consumed. |
| `created_at` | `DATETIME` | No | `CURRENT_TIMESTAMP` | Request creation time. |
| `updated_at` | `DATETIME` | No | `CURRENT_TIMESTAMP` with update | Last update time. |

## Keys and indexes

- Primary key: `id`
- Index: `idx_password_reset_requests_user_active (user_id, used_at, verified_at, expires_at)`
- Index: `idx_password_reset_requests_expires (expires_at)`

## Relationships

- `user_id -> users.id`

## How the application uses it

- A new row is created when a password reset OTP is issued.
- Older unused rows for the same user are invalidated when a fresh reset request starts.
- `verify_attempt_count` and `resend_count` enforce OTP retry and resend limits.
- `verified_at` unlocks the password change step.
- `used_at` closes the request after password reset, expiry cleanup, or explicit invalidation.
