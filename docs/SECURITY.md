# Security

## Secrets (required)

| Env | Purpose |
|-----|---------|
| `DATABASE_URL` | Postgres DSN |
| `SESSION_SECRET` | Reserved for future signed cookies / CSRF; sessions currently random opaque tokens hashed with SHA-256 |
| `ENCRYPTION_KEY` | 32 raw bytes **or** 64 hex chars — AES-256-GCM for TOTP secrets / connector blobs |

## Auth

- First registered user becomes **Platform Owner** (`is_platform_owner=true`)
- Passwords: Argon2id (`m=65536,t=1,p=4`)
- Sessions stored in `sessions.token_hash` (SHA-256 of raw cookie)
- Cookie: `sameh_session`, HttpOnly, SameSite=Lax
- TOTP: `POST /auth/totp/setup` then `/auth/totp/verify`; login requires code when enabled

## Kill switch

- Global row in `kill_switches`
- Only platform owner may toggle via `POST /security/kill-switch`
- Downstream executors (future) must refuse writes when enabled

## Connector

- Shared secret in WP option `sameh_connector_shared_secret`
- Discover requires HMAC headers; health is public for liveness only
- Never embed AI keys in the connector

## Defaults

- Safe mode / default deny for write operations (future missions)
- No fabricated metrics in UI — show NOT CONNECTED / NOT IMPLEMENTED
