# تثبيت SAMEH 12.0 (طبعة cPanel) — عربي

هذه الطبعة PHP + MySQL جاهزة للاستضافة المشتركة (cPanel). لا تحتاج Docker ولا Composer للتشغيل.

## المتطلبات
- cPanel مع PHP 8.0+ (يُفضّل 8.1 أو أحدث) وامتدادات: `pdo_mysql`, `curl`, `openssl`, `mbstring`
- قاعدة MySQL من قسم MySQL® Databases
- نطاق فرعي مثل `sameh.almasaralsare3-ksa.com`

## الخطوات

### 1) إنشاء قاعدة البيانات
1. cPanel → MySQL® Databases
2. أنشئ Database واسم مستخدم وكلمة مرور
3. اربط المستخدم بقاعدة البيانات بصلاحيات ALL
4. احفظ: `db_host` (غالباً `localhost`) و `db_name` و `db_user` و `db_pass`

### 2) إنشاء النطاق الفرعي
1. cPanel → Domains / Subdomains
2. أنشئ `sameh` على نطاقك
3. **Document Root** يفضّل أن يشير إلى مجلد `public` داخل الحزمة، مثال:
   - `public_html/sameh-12.0-cpanel/public`
4. إن لم تستطع تغيير الـ docroot: ارفع الحزمة كاملة واعتمد `.htaccess` في جذر الحزمة لإعادة التوجيه إلى `public/`

### 3) رفع الملفات
1. حمّل `SAMEH-12.0-cpanel.zip`
2. File Manager → Extract داخل المجلد المناسب
3. تأكد أن محتويات `public/` هي ما يُخدم عبر HTTPS

### 4) ملف الإعداد
1. انسخ `config.example.php` إلى `config.local.php` (بجانب مجلد `app/`) **أو** إلى `config.php` خارج المجلد العام
2. عدّل القيم:

```php
return [
  'db_host' => 'localhost',
  'db_name' => 'cpaneluser_sameh',
  'db_user' => 'cpaneluser_sameh',
  'db_pass' => '********',
  'app_url' => 'https://sameh.almasaralsare3-ksa.com',
  'session_name' => 'sameh_sess',
];
```

### 5) التثبيت من المتصفح
1. افتح `https://sameh.…/install`
2. أنشئ حساب Owner (كلمة مرور ≥ 10)
3. فعّل 2FA من صفحة الإعداد

### 6) إضافة موقع + Connector
1. Sites → Add Site (الوضع READ_ONLY)
2. Pairing → انسخ Core URL + Site ID + Token + HMAC Secret
3. على موقع WordPress: ثبّت مجلد `connector-plugin/sameh-connector` أو zip الإضافة
4. Settings → SAMEH Connector → الصق البيانات → Save
5. من Core: Test Health ثم Discover

## ملاحظات أمان
- Document root = `public/` فقط
- لا ترفع `config.local.php` إلى مستودع عام
- Kill Switch من الإعدادات يوقف الجسر فوراً
- الجلسات: HttpOnly + SameSite=Lax

## طبعة VPS
نسخة Go/Next موجودة منفصلة في مجلد `sameh-12.0` — ليست مسار النشر لهذه الطبعة.
