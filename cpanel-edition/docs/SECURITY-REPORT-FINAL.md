# SECURITY REPORT — SAMEH 12.1.0 FINAL

## Fixed / enforced
1. **Logout**: GET removed; POST + CSRF only.
2. **SSRF**: `UrlGuard` rejects non-HTTPS public Cloud AI URLs and private/localhost hosts/IPs.
3. **Secrets to AI**: prompts containing HMAC/WP credentials rejected; Redactor on payloads/logs; worker logs redact tokens.
4. **Worker auth**: token hashed at rest; timestamp+nonce replay table; rate limit; lease exclusivity on claim.
5. **Temp elevation**: Owner + enabled 2FA + typed site name + 5m TTL + audit; checkbox alone cannot bypass READ_ONLY.
6. **Factory gates**: high QA / unbalanced shortcodes / duplicate H1 / slug|title|intent conflict / invalid template → hard fail (no action plan).
7. **OpenSSL**: critical preflight requirement.
8. **JSON AI results**: SchemaValidator with one repair attempt then fail.
9. **Core isolation**: no Core→127.0.0.1 Ollama calls; only Local AI Worker on Owner Windows.
10. Additive SQL only — no DROP of sites pairing/HMAC columns.

## Honest gaps (non-stub)
- GA4/Ads: disconnected empty states (no fake metrics); CSV/sitemap real.
- Telegram omitted from nav (no fake module).
- Live WP execute E2E still environment-dependent.
