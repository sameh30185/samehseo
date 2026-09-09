# دليل تثبيت Ollama + Hermes (جهاز المالك — Windows)

SAMEH Core على cPanel **لا** يتصل أبداً بـ `127.0.0.1` أو Ollama.  
الذكاء المحلي يعمل فقط عبر **Local AI Worker** على جهازك.

## 1) ثبّت Ollama

1. حمّل من الموقع الرسمي لـ Ollama لـ Windows.
2. شغّل Ollama وتأكد أن الخدمة على `http://127.0.0.1:11434`.
3. اسحب نموذجاً (يُفضّل Hermes إن توفر، وأي نموذج آخر مقبول):

```bat
ollama pull hermes3
```

أو أي نموذج متاح لديك: `ollama list`

## 2) ثبّت Local AI Worker

1. من إعدادات SAMEH → **Local AI / Hermes + Ollama** اضغط **Pair** وانسخ الرمز `sw_…` (مرة واحدة).
2. حمّل `SAMEH-local-ai-worker-final.zip` وفك الضغط.
3. انسخ `config.example.json` إلى `config.json` واملأ:

```json
{
  "core_base_url": "https://your-domain.example",
  "worker_token": "sw_....",
  "ollama_base_url": "http://127.0.0.1:11434",
  "prefer_hermes": true
}
```

4. شغّل `start-worker.bat` — يكتب `worker.pid`.
5. `worker-status.bat` يعرض online/offline.
6. `shutdown-worker.bat` يوقف شجرة العملية عبر PID.

## 3) اختبار

- من الإعدادات: **Test AI / حالة العامل** — يجب أن يظهر عامل متصل + نماذج.
- أنشئ مهمة بمصدر **Hermes** — ستُصفّ مهام في الطابور ويستهلكها العامل.

## أمان

- لا تضع HMAC WordPress داخل إعدادات العامل.
- السجلات تُنقّح الرموز.
- أوقف العامل عند عدم الحاجة: `shutdown-worker.bat`.
