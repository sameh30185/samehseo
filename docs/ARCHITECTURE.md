# SAMEH AI SEO Platform 12.0 — Architecture

## Goal

Multi-site SEO operating system. WordPress is a **thin connector**, not the product host.
Central Core owns truth, missions, contracts, QA, audit, and kill switches.

## Apps

| Path | Role |
|------|------|
| `apps/api` | Go HTTP API — auth, orgs/sites, audit, kill switch, future missions |
| `apps/web` | Next.js App Router — Arabic-first RTL Command Center |
| `apps/connector` | PHP plugin `sameh-connector` — health + discover + HMAC stub |

## Data plane

- **Postgres** — source of truth (users, sessions, sites, audit, …)
- **Redis** — reserved for queues / rate limits (compose included; API MVP does not require it yet)

## Security baseline

- Argon2id passwords
- Server-side sessions (HttpOnly cookie)
- TOTP 2FA (encrypt secrets at rest with `ENCRYPTION_KEY`)
- Global kill switch (platform owner)
- Workspace isolation: sites filtered by organization membership
- Connector HMAC shared secret (options) for signed discover

## Safe pipeline (target — mostly NOT IMPLEMENTED in MVP)

Evidence → Workforce → QA → Director → Typed Action → Preview → Approval → Execute → Verify → Rollback

MVP implements foundation only: Owner+2FA, Sites CRUD stubs, Kill switch, Audit list.

## Isolation

`internal/workspace` helpers assert site↔org membership. List/Get sites join `memberships`.
