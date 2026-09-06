# SAMEH AI SEO Platform 12.0

Multi-site SEO OS foundation.

Stack: Go API, Next.js AR RTL, sameh-connector, Postgres, Redis.

## Quick start

### Postgres
docker compose up -d postgres redis
Or local Postgres with user/db sameh.

### API
Export env vars from .env.example
cd apps/api && go mod tidy && go test ./... && go run ./cmd/server
GET /health on port 8080

### Owner and 2FA
POST /auth/register (first user is Platform Owner)
POST /auth/totp/setup then /auth/totp/verify
POST /auth/login then GET /me
See docs/SECURITY.md

### Web UI
Use apps/web with Next.js App Router.
Install Node deps then run the Next.js dev server. Set NEXT_PUBLIC_API_URL.

### Connector
Use apps/connector/sameh-connector plugin.
REST: sameh-connector/v1 health and discover.

### Compose
docker compose up -d postgres redis api

## Docs
docs/ARCHITECTURE.md
docs/MIGRATION-FROM-11.9.6.md
docs/SECURITY.md

## MVP
No fake metrics. Unbuilt modules labeled NOT IMPLEMENTED.
