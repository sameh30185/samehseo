# تثبيت SAMEH 12.0 (طبعة cPanel) — عربي · `12.0.0-rc1`

هذه الطبعة PHP + MySQL جاهزة للاستضافة المشتركة (cPanel). لا تحتاج Docker ولا Composer للتشغيل.

> **مهم:** 12.0 = أساس المنصة / لوحة التحكم (Platform Foundation / Control Plane).  
> **غير مضمّن في هذه المرحلة:** Missions، Hermes، Workforce، Page Factory، Growth، توليد المحتوى.  
> الصفحات تظهر كـ **NOT IMPLEMENTED** بصدق.

## المتطلبات
- cPanel مع PHP 8.0+ (يُفضّل 8.1 أو أحدث)
- امتدادات: `pdo_mysql`, `curl`, `openssl`, `json`, `mbstring`
- قاعدة MySQL من قسم MySQL® Databases
- نطاق فرعي مثل `sameh.example.com`
- مجلدات قابلة للكتابة: `storage/` (يُنشأ تلقائياً)

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
1. حمّل `SAMEH-12.0-cpanel.zip` (من `dist/` بعد `bash dist/build-release.sh`)
2. File Manager → Extract داخل المجلد المناسب
3. تأكد أن محتويات `public/` هي ما يُخدم عبر HTTPS
4. ثبّت شهادة SSL (Let's Encrypt) — الكوكيز Secure تعتمد على HTTPS

### 4) ملف الإعداد
1. انسخ `config.example.php` إلى `config.local.php` (بجانب مجلد `app/`) **أو** إلى `config.php` خارج المجلد العام
2. عدّل القيم:

```php
return [
  'db_host' => 'localhost',
  'db_name' => 'cpaneluser_sameh',
  'db_user' => 'cpaneluser_sameh',
  'db_pass' => '********',
  'app_url' => 'https://sameh.example.com',
  'session_name' => 'sameh_sess',
];
```

### 5) التثبيت من المتصفح (Preflight)
1. افتح `https://sameh.…/install`
2. راجع جدول **Preflight** — يجب أن تنجح الفحوصات الحرجة (*):
   - PHP ≥ 8.0 والامتدادات
   - مجلدات قابلة للكتابة (`storage/`)
   - الجلسات والكوكيز
   - اتصال DB وصلاحية CREATE TABLE
3. أنشئ حساب Owner (كلمة مرور ≥ 10)
4. فعّل 2FA — السر **ثابت** عند تحديث الصفحة؛ يتغيّر فقط عند **Regenerate**

### 6) إضافة موقع + Connector
1. Sites → Add Site (الوضع READ_ONLY)
2. Pairing → انسخ:
   - Core URL + Site ID
   - **Pairing Token** (قصير العمر، مرة واحدة — يُستهلك عند أول Health ناجح)
   - Connector Token + HMAC Secret
3. على WordPress: ثبّت `SAMEH-connector.zip` أو مجلد `sameh-connector`
4. Settings → SAMEH Connector → الصق البيانات → Save
5. من Core: Test Health ثم Discover

## الأمان
- Document root = `public/` فقط
- لا ترفع `config.local.php` إلى مستودع عام
- الأسرار لا تُكتب في Audit Log (HMAC / TOTP / tokens / كلمات المرور)
- Kill Switch من الإعدادات يوقف الجسر فوراً
- الجلسات: HttpOnly + SameSite=Lax + Secure على HTTPS
- CSRF على كل نماذج POST
- Anti-replay للـ HMAC (nonce + TTL)

## بناء الحزمة محلياً
```bash
bash dist/build-release.sh
# أو من جذر المستودع:
bash scripts/build-cpanel-release.sh
```

## طبعة VPS
نسخة Go/Next موجودة منفصلة في مجلدات `apps/` — ليست مسار النشر لهذه الطبعة.
