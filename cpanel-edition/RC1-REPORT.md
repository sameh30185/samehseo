# SAMEH 12.0 cPanel Edition — RC1 Report

**Version:** `12.0.0-rc1`  
**Date:** 2026-09-08 (Africa/Cairo)  
**Scope:** Stability + Security + Installability + Connector reliability  
**No new product features** (no Hermes / Missions / Factory / Growth / workforce)

## Bugs found

1. **Critical — TOTP secret not sticky:** Every GET `/2fa/setup` regenerated a new secret; refresh invalidated the authenticator enrollment mid-setup.
2. **High — BridgeClient accepted HTML/malformed JSON:** HTTP 200 with HTML login pages or broken JSON could be treated as partial success (`ok` based only on status code).
3. **High — No anti-replay nonce store:** HMAC verified signature + skew only; reused nonces were accepted on both Core and Connector.
4. **High — No future-timestamp rejection:** Large future timestamps within abs() skew edge cases; Connector lacked explicit future check.
5. **Medium — Pairing secrets not one-time / no TTL token:** Pairing had no short-lived one-time pairing token lifecycle.
6. **Medium — Secrets could reach audit details:** AuditLog wrote details JSON without redaction.
7. **Medium — Installer lacked preflight:** No extension/writable/session/HTTPS/CREATE TABLE checks; opaque failures on cPanel.
8. **Low — Open redirect / template path:** Redirect allowed non-relative theoretically; template name not basenamed.
9. **Low — No login/TOTP rate limiting.**
10. **Low — FOLLOWLOCATION enabled on BridgeClient** (redirect following risk).

## Bugs fixed

- Sticky TOTP secret in session until explicit Regenerate / `?reset=1`
- `BridgeClient::validateResponse()` — status, Content-Type, HTML body, JSON parse, `ok`/`success`, required keys
- Durable `NonceStore` (MySQL `hmac_nonces` + file fallback) + `HmacSigner::verifyAndConsume`
- Connector `Sameh_Connector_HMAC` nonce via WP transients + future ts rejection
- Short-lived one-time `pairing_token` (15m TTL), consumed on first successful Health
- `Redactor` applied in AuditLog; pairing audit never logs secrets
- `Install\Preflight` + clear FAIL/PASS UI; block install on critical failures
- Rate limiting on login + TOTP verify
- Session cookie Secure/HttpOnly/SameSite; regenerate on login (already present, retained)
- Safe `App::redirect` (blocks `://`, `//`, `..`); template basename guard
- Bridge CURLOPT_FOLLOWLOCATION disabled

## Security fixes

| Area | Change |
|------|--------|
| CSRF | Unchanged (POST forms + `hash_equals`) |
| Session fixation | `session_regenerate_id(true)` on login |
| Cookies | HttpOnly, SameSite=Lax, Secure when HTTPS |
| Rate limit | Login 8/15m; TOTP 10/10m (file store) |
| SQL | Prepared statements retained |
| XSS | `App::e()` in templates |
| Open redirect | Relative-only Location |
| Path traversal | Template basename; URL http(s) check |
| `.htaccess` | Deny `app/`, `sql/`, `templates/`, `storage/`, `tests/`, `dist/` |
| HMAC | `hash_equals` + nonce TTL + skew/future |
| Secrets in logs | Redactor project-wide for audit |

## Files changed (key)

- `VERSION` → `12.0.0-rc1`
- `app/Security/{HmacSigner,NonceStore,PairingToken,Redactor,RateLimiter}.php`
- `app/Connector/BridgeClient.php`
- `app/Auth/Auth.php`, `app/Sites/SiteRepository.php`, `app/Audit/AuditLog.php`
- `app/Install/Preflight.php`, `app/Http/Controllers.php`, `app/bootstrap.php`, `app/Config.php`
- `sql/schema.sql` (+ `hmac_nonces`, pairing columns)
- `connector-plugin/sameh-connector/**`
- `templates/{install,totp_setup,site_detail,stub}.php`
- `tests/run.php`, `dist/build-release.sh`, `scripts/build-cpanel-release.sh`
- `README.md`, `INSTALL-CPANEL-AR.md`, `RC1-REPORT.md`

## Tests

```
php tests/run.php
```

| Result | Count |
|--------|------:|
| PASS | 44 |
| FAIL | 0 |
| TOTAL | 44 |

Also: `php -l` on all 38 PHP files — clean.

Coverage includes: sticky TOTP; Bridge 200/403/404/500/HTML/malformed/incomplete/missing/401; HMAC valid/replay/expired/future/bad sig/wrong secret; pairing valid/wrong/expired/reused; redactor; CSRF; rate limit; preflight; XSS escape; open redirect; path traversal; connector nonce; VERSION.

## Remaining known issues

- This build box has no `pdo_mysql` / MySQL server — full installer+DB integration not exercised live here (unit-level + file nonce store covered). **Must verify on real cPanel with MySQL.**
- `GITHUB_TOKEN` was empty in `/home/box/agent-data/box-secrets.json` at build time — push may be local-only.
- Sibling `/workspace/sameh-12.0-cpanel` synced from `cpanel-edition` (source of truth).
- Connector WP transient nonce store requires object cache / options table (standard WP) — fine on normal hosts.
- Rate limiter is file-based (cPanel-safe); not multi-server shared.

## Artifacts

- Commit:  (branch , tag )
- Zips:
  - `/workspace/sameh-12.0/cpanel-edition/dist/SAMEH-12.0-cpanel.zip`
  - `/workspace/sameh-12.0/cpanel-edition/dist/SAMEH-connector.zip`
  - `/workspace/sameh-12.0/SAMEH-12.0-cpanel.zip`
  - `/workspace/sameh-12.0/SAMEH-connector.zip`

## Verdict

**READY FOR CPANEL TESTING** (RC1) — contingent on real-host MySQL install smoke after upload.
