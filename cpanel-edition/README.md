# SAMEH 12.0 — cPanel Edition (`12.0.0-rc1`)

Arabic-first PHP + MySQL **Platform Foundation / Control Plane** for shared hosting (cPanel).  
Zero Composer required in production. Thin WordPress connector plugin included.

> **Phase honesty:** 12.0 = Platform Foundation / Control Plane.  
> **NOT in this phase:** Missions, Hermes, autonomous SEO workforce, Page Factory, Growth, content generation.  
> Those routes exist as honest stubs labeled NOT IMPLEMENTED.

## What's included
- Installer with **preflight** (PHP, extensions, writable dirs, sessions, HTTPS, rewrite, DB)
- Login / logout / sessions (HttpOnly + SameSite=Lax + Secure when HTTPS)
- Session fixation prevention (`session_regenerate_id` on login)
- TOTP 2FA (pure-PHP) — **secret sticky** until explicit Regenerate
- Login + TOTP **rate limiting**
- Command Center dashboard (real DB counts; zeros OK)
- Sites: add (default **READ_ONLY**), list, detail
- Pairing: short-lived **one-time pairing token** + connector token + HMAC secret (secrets never logged)
- Bridge: Health + Discover with **response validation** (status, Content-Type, JSON, required keys)
- HMAC anti-replay: durable nonce store + timestamp skew / future rejection + `hash_equals`
- Global Kill Switch
- Audit log with **secret redaction**
- CSRF, prepared statements, XSS escape, no open redirects, `.htaccess` blocks on `app/`, `sql/`, `templates/`, `storage/`
- Honest stubs: Missions / Factory / Growth
- WordPress plugin: `connector-plugin/sameh-connector/`

## Quick install (cPanel)

Full Arabic steps: `INSTALL-CPANEL-AR.md`.

1. Create MySQL database + user in cPanel  
2. Create subdomain (e.g. `sameh.yourdomain.com`)  
3. Set document root to this package's `public/` folder  
4. Upload & extract `SAMEH-12.0-cpanel.zip`  
5. Copy `config.example.php` → `config.local.php` and fill DB + `app_url`  
6. Visit `https://sameh.yourdomain.com/install` — fix any Preflight FAIL  
7. Login → enable 2FA → Add Site → Pair → install WP plugin → Health / Discover  

### PHP requirements
PHP 8.0+ with `pdo_mysql`, `curl`, `openssl`, `json`, `mbstring`. No Composer.

### Config example
```php
return [
  'db_host' => 'localhost',
  'db_name' => '',
  'db_user' => '',
  'db_pass' => '',
  'app_url' => 'https://sameh.example.com',
  'session_name' => 'sameh_sess',
];
```

Preferred config locations (first found wins):
1. `../config.php` (one level above package — outside web tree if possible)
2. `config.php` in package root
3. `config.local.php` in package root

## Directory layout
```
sameh-12.0-cpanel/
  VERSION                 ← 12.0.0-rc1
  README.md
  INSTALL-CPANEL-AR.md
  config.example.php
  public/                 ← document root
  app/
  templates/
  sql/schema.sql
  storage/                ← nonces + rate limits (not web-accessible)
  connector-plugin/sameh-connector/
  tests/run.php
  dist/build-release.sh
```

## WordPress Connector
- Plugin: **SAMEH Connector** `1.0.0-rc1`
- REST: `sameh-connector/v1` — `health`, `discover`, `ping`
- Headers: `X-Sameh-Timestamp`, `X-Sameh-Nonce`, `X-Sameh-Signature` (+ optional `X-Sameh-Site-Token`)
- Signature: `timestamp.nonce.METHOD.path.body_hash` (HMAC-SHA256)
- Anti-replay nonces (WP transients); rejects stale and future timestamps
- No AI, no large admin UI

Zip: `dist/SAMEH-connector.zip` (also at repo root after build).

## Tests (PHP CLI)
```bash
php tests/run.php
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

## Build zip (reproducible)
```bash
bash dist/build-release.sh
# → cpanel-edition/dist/SAMEH-12.0-cpanel.zip
# → cpanel-edition/dist/SAMEH-connector.zip
# + copies to repo root
```

## Product principles
- Arabic-first RTL UI
- Honest empty / stub states
- Safe-by-default (READ ONLY + Kill Switch)
- Stability + Security + Installability + Connector reliability
