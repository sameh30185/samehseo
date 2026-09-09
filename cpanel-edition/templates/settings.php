<h1>الإعدادات / Settings</h1>
<p>الإصدار: <code><?= \Sameh\App::e($version ?? '') ?></code>
 — SMTP: <?= !empty($smtpConfigured) ? '✅ مُعد' : '⚠ غير مُعد (البريد → storage/mail-outbox)' ?></p>

<div class="card">
  <h2>Kill Switch</h2>
  <form method="post" action="/settings">
    <?= \Sameh\Security\Csrf::field() ?>
    <input type="hidden" name="form_action" value="kill_switch">
    <label>
      <input type="checkbox" name="kill_switch" value="1" <?= !empty($killSwitch) ? 'checked' : '' ?>>
      Kill Switch العام — يوقف عمليات الربط/Health/Discover وإضافة المواقع
    </label>
    <button type="submit" class="danger">حفظ / Save</button>
  </form>
</div>

<div class="card">
  <h2>تغيير كلمة المرور / Change password</h2>
  <form method="post" action="/settings">
    <?= \Sameh\Security\Csrf::field() ?>
    <input type="hidden" name="form_action" value="change_password">
    <label>الحالية / Current</label>
    <input type="password" name="current_password" required autocomplete="current-password">
    <label>الجديدة (10+) / New</label>
    <input type="password" name="new_password" required minlength="10" autocomplete="new-password">
    <?php if (!empty($totpEnabled)): ?>
      <label>رمز 2FA / TOTP</label>
      <input type="text" name="totp_code" inputmode="numeric" autocomplete="one-time-code" required>
    <?php endif; ?>
    <button type="submit">تغيير كلمة المرور</button>
  </form>
</div>

<div class="card">
  <h2>مزوّد الذكاء الاصطناعي / AI Provider</h2>
  <p style="font-size:0.85rem;color:var(--muted);">OpenAI-compatible. السحابة <strong>مطفأة افتراضياً</strong>. لا تُرسل أسرار HMAC للموديل. راجع <code>docs/AI-GATEWAY.md</code>.</p>
  <p>الحالة: <code><?= \Sameh\App::e($ai['last_status'] ?? 'never_tested') ?></code>
     — مفتاح محفوظ: <?= !empty($aiHasKey) ? 'نعم' : 'لا' ?></p>
  <form method="post" action="/settings">
    <?= \Sameh\Security\Csrf::field() ?>
    <input type="hidden" name="form_action" value="ai_settings">
    <label>
      <input type="checkbox" name="ai_cloud_enabled" value="1" <?= !empty($ai['enabled']) ? 'checked' : '' ?>>
      تفعيل السحابة / Enable cloud AI
    </label>
    <label>Base URL (مثل https://api.openai.com/v1)</label>
    <input type="url" name="ai_base_url" value="<?= \Sameh\App::e($ai['base_url'] ?? '') ?>" placeholder="https://api.openai.com/v1">
    <label>Model</label>
    <input type="text" name="ai_model" value="<?= \Sameh\App::e($ai['model'] ?? 'gpt-4o-mini') ?>">
    <label>API Key (اتركه فارغاً للإبقاء على الحالي)</label>
    <input type="password" name="ai_api_key" value="" autocomplete="off" placeholder="sk-...">
    <label><input type="checkbox" name="clear_api_key" value="1"> مسح المفتاح / Clear key</label>
    <label>Timeout (ثوانٍ)</label>
    <input type="number" name="ai_timeout_seconds" min="5" max="120" value="<?= (int)($ai['timeout'] ?? 30) ?>">
    <button type="submit">حفظ إعدادات AI</button>
  </form>
  <form method="post" action="/settings" style="margin-top:0.75rem;">
    <?= \Sameh\Security\Csrf::field() ?>
    <input type="hidden" name="form_action" value="ai_test">
    <button type="submit">اختبار الاتصال / Test connection</button>
  </form>
</div>
