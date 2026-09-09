# SAMEH Local AI Worker

Runs on **Owner Windows** only. Polls Core HTTPS queue and calls local Ollama (`127.0.0.1`).

cPanel Core **never** calls Ollama.

1. Copy `config.example.json` → `config.json`
2. Set `core_base_url` (HTTPS) + `worker_token` from Core Settings → Local AI → Pair
3. `start-worker.bat` / `shutdown-worker.bat` / `worker-status.bat`
