# ترقية SAMEH 12.0 → 12.1 Professional

## نظرة عامة
هذه ترقية **تراكمية** على شجرة `cpanel-edition` الحالية — لا إعادة بناء من الصفر.
تُحافظ على أعمدة الربط في جدول `sites` (`hmac_secret`, `connector_token`, `pairing_*`) بدون DROP.

## المتطلبات
- PHP 8.1+ (موصى به 8.2/8.3/8.4)
- MySQL/MariaDB مع utf8mb4
- بدون Composer في الإنتاج

## خطوات الترقية على cPanel
1. خذ نسخة احتياطية من قاعدة البيانات وملفات التطبيق و`config.php`.
2. ارفع حزمة `SAMEH-12.1-professional.zip` فوق المجلد الحالي (أو استبدل الملفات مع الإبقاء على `config.php` / `config.local.php` و`storage`).
3. تأكد أن Document Root يشير إلى `public/`.
4. افتح لوحة التحكم مرة — عند التثبيت المكتمل يشغّل `Migrator` تلقائياً ملفات `sql/migrations/*.sql` ويسجّلها في `schema_migrations`.
5. تحقق من الإصدار في الإعدادات: `12.1.0-dev` (أو رقم الإصدار في `VERSION`).

## ما يُضاف (بدون تدمير بيانات)
- `password_reset_tokens`, `evidence`, `project_brain`, `missions`, `mission_runs`, `decisions`
- `users.session_version` لإبطال الجلسات بعد إعادة تعيين كلمة المرور
- `audit_log.site_id` (nullable)
- مفاتيح إعدادات AI في جدول `settings` (السحابة **مطفأة** افتراضياً)

## استعادة كلمة مرور المالك
- رابط «نسيت كلمة المرور» من صفحة الدخول.
- استجابة موحّدة (لا تعداد بريد).
- رمز عشوائي يُخزَّن **هاشه فقط**، صلاحية 30 دقيقة، لمرة واحدة.
- عند النجاح: رفع `session_version` (إبطال الجلسات) مع **الإبقاء على 2FA**.
- إن لم يُضبط SMTP في `config.php` تُكتب الرسائل إلى `storage/mail-outbox/` مع نفس رسالة النجاح في الواجهة.

### إعداد SMTP المطلوب (اختياري)
```php
'smtp_host' => 'mail.example.com',
'smtp_port' => 587,
'smtp_user' => '...',
'smtp_pass' => '...',
'smtp_from' => 'noreply@example.com',
'smtp_encryption' => 'tls', // tls|ssl|none
```

### مفتاح الطوارئ (اختياري)
```php
'emergency_recovery_key_file' => '/home/USER/sameh-emergency.key', // خارج public
```
لا يُعرض في الواجهة أو السجلات. مقارنة `hash_equals`.

## الموقع النشط
- الجلسة: `active_site_id`
- مبدّل في الشريط الجانبي
- المهام تتطلب سياقاً نشطاً

## المهام والوكلاء
- تشغيل تحليل **حتمي** من Discover حتى بدون LLM.
- إثراء اختياري عند تفعيل AI Provider.

## الأمان
- لا تُرسل أسرار HMAC/pairing إلى الموديل.
- مفتاح API مشفّر عند التخزين عبر `app_key` (أو مشتق احتياطي).

راجع أيضاً: `docs/AI-GATEWAY.md` و`12.1-STATUS.md`.
