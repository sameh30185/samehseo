# ترقية إلى SAMEH 12.1.0 FINAL

## قبل الرفع
1. خذ نسخة احتياطية من قاعدة البيانات و`config.local.php`.
2. ارفع `SAMEH-12.1-professional-final.zip` فوق التثبيت أو استبدل الملفات مع الإبقاء على `config*.php` و`storage/`.

## بعد الرفع
1. افتح أي صفحة لوحة — Migrator يشغّل `003_12_1_1_local_ai.sql` تلقائياً (إضافة فقط).
2. تأكد من VERSION في الإعدادات = `12.1.0`.
3. OpenSSL يجب أن يمر في فحص التثبيت.
4. اربط Local AI Worker من الإعدادات إن أردت Hermes.
5. ارفع `SAMEH-connector-final.zip` كتحديث لإضافة WP (discover/v2).

## ملاحظات
- لا تُحذف أعمدة pairing/HMAC.
- الخروج أصبح POST+CSRF فقط.
- الرفع المؤقت يتطلب مالك+2FA+اسم الموقع.
