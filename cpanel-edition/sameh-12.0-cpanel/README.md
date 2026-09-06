# SAMEH 12.0 — cPanel Edition

Arabic-first PHP + MySQL control plane for **shared hosting (cPanel)**.  
Zero Composer required in production. Thin WordPress connector plugin included.

> VPS / Go+Next edition lives separately in `sameh-12.0/` — this package is the uploadable zip path.

## What's included
- Installer (schema + Owner account)
- Login / logout / sessions (HttpOnly cookies)
- TOTP 2FA (pure-PHP, no Composer)
- Command Center dashboard (real DB counts; zeros OK)
- Sites: add (default **READ_ONLY**), list, detail
- Pairing: connector token + HMAC shared secret
- Bridge: Health + Discover against WP connector (signed REST)
- Global Kill Switch
- Audit log viewer
- Honest stubs: Missions / Factory / Growth → **NOT IMPLEMENTED**
- WordPress plugin: `connector-plugin/sameh-connector/`

## Quick install (cPanel)

**English summary** — full Arabic steps: see `INSTALL-CPANEL-AR.md`.

1. Create MySQL database + user in cPanel  
2. Create subdomain (e.g. `sameh.yourdomain.com`)  
3. Set document root to this package's `public/` folder  
4. Upload & extract `SAMEH-12.0-cpanel.zip`  
5. Copy `config.example.php` → `config.local.php` and fill DB + `app_url`  
6. Visit `https://sameh.yourdomain.com/install`  
7. Login → enable 2FA → Add Site → Pair → install WP plugin → Health / Discover  

### PHP requirements
PHP 8.0+ with `pdo_mysql`, `curl`, `openssl`, `mbstring`. No Composer needed.

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
  README.md
  INSTALL-CPANEL-AR.md
  config.example.php
  public/                 ← document root
  app/
  templates/
  sql/schema.sql
  connector-plugin/sameh-connector/
  dist/
```

## WordPress Connector
- Plugin name: **SAMEH Connector**
- REST namespace: `sameh-connector/v1`
  - `GET health`
  - `GET discover`
  - `POST ping`
- Headers: `X-Sameh-Timestamp`, `X-Sameh-Nonce`, `X-Sameh-Signature`
- Signature payload: `timestamp.nonce.METHOD.path.body_hash` (HMAC-SHA256)
- Rejects timestamps older than 5 minutes
- One settings page only — no AI, no large admin UI

Zip the `sameh-connector` folder for upload via WP Admin → Plugins → Add New → Upload, or copy into `wp-content/plugins/`.

## Security defaults
- `password_hash` with Argon2id when available, else `PASSWORD_DEFAULT`
- CSRF tokens on POST forms
- Session cookies: HttpOnly, SameSite=Lax, Secure when `app_url` is https
- Default site mode: **READ_ONLY**
- No fake metrics — empty / NOT CONNECTED when no data

## Local syntax check
```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

## Build zip
```bash
bash dist/build-zip.sh
# → /workspace/SAMEH-12.0-cpanel.zip
```

## Product principles
- Arabic-first RTL UI
- Honest empty states
- Safe-by-default (READ ONLY + Kill Switch)
