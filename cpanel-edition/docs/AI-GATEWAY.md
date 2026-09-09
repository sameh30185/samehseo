# SAMEH AI Gateway (Hermes / remote worker)

## Problem on cPanel
cPanel hosting **cannot** reliably call `127.0.0.1` workers on the Owner’s laptop.
Cloud AI is **OFF by default** (`ai_cloud_enabled=0`).

## Recommended architecture
1. **SAMEH Core** (this cPanel app) stores missions, evidence, and settings.
2. An **Owner-machine worker** (Hermes / local gateway) polls or receives jobs from Core over HTTPS.
3. The worker may call local models (`127.0.0.1`) or private GPUs, then **POSTs results back to Core**.
4. Core never needs inbound access to the Owner LAN.

```
[WordPress sites] ←HMAC→ [SAMEH Core on cPanel]
                              ↑ HTTPS results / job claim
                         [Owner Hermes worker]
                              ↓ optional
                         [Local LLM 127.0.0.1]
```

## OpenAI-compatible settings (optional cloud)
In Settings → AI Provider:
- `base_url` e.g. `https://api.openai.com/v1` or your gateway’s public HTTPS URL
- `api_key` stored encrypted at rest (`ai_api_key_enc`)
- `model`
- timeout + limited retries
- Test connection endpoint

## Security rules
- Never send `hmac_secret`, pairing tokens, connector tokens, passwords, or TOTP to the model.
- Logs are redacted via `Redactor`.
- Prefer Owner worker over enabling cloud on shared hosting.

## Minimal remote gateway contract (future Phase C polish)
- Worker authenticates with an Owner-scoped API token (not site HMAC).
- Claims mission run → returns structured JSON findings matching agent schema.
- Core validates JSON in PHP before persisting.
