# Feature Parity Matrix — Old Plugin | 12.1 RC1 | FINAL

| Feature (11.9.x concept) | 12.1 RC1 | 12.1.0 FINAL |
|--------------------------|----------|--------------|
| Sites pair + HMAC bridge | ✅ | ✅ |
| Discover (basic) | ✅ | ✅ + discover/v2 |
| Kill Switch | ✅ | ✅ |
| Owner recovery + 2FA | ✅ | ✅ |
| Cloud AI OpenAI-compat | ✅ OFF default | ✅ + SSRF URL guard + schema repair |
| Local Hermes/Ollama via Core | ❌ | ❌ (forbidden) |
| Local AI Worker (Owner Windows) | ❌ | ✅ claim/complete queue |
| Company agents deterministic | ✅ | ✅ |
| Missions through AI jobs | ❌ rules only | ✅ Hermes queue OR Rules-only label |
| Preview → Approve → Execute | ✅ | ✅ |
| Temporary elevation | checkbox only | ✅ Owner+2FA+site name+TTL+audit |
| Factory golden templates AR | placeholders | ✅ real Arabic structure |
| Factory QA hard-block plan | soft warn | ✅ hard fail |
| Growth dedupe fingerprint | insert-only | ✅ upsert site_id+fingerprint |
| Growth insufficient evidence AR | «0 فرصة» risk | ✅ «الأدلة غير كافية…» |
| Project Brain CRUD | ❌ | ✅ |
| Command Center live CTAs | stale copy | ✅ live statuses |
| Missions stepper + goal textarea | basic list | ✅ |
| GSC/GA4/Ads pages honesty | N/A | ✅ disconnected + CSV/sitemap |
| Telegram menu | N/A | omitted (no fake) |
| Logout CSRF | GET allowed | ✅ POST+CSRF only |
| OpenSSL preflight | present | ✅ critical required |
| Media alt/dup findings | soft agent | ✅ from discover v2 |
| Rank Math typed QA | ✅ | ✅ |
