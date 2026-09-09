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
  <h2>Local AI / Hermes + Ollama</h2>
  <p style="font-size:0.9rem;color:var(--muted);">
    يعمل على جهاز المالك (Windows) فقط عبر Worker. <strong>Core لا يستدعي 127.0.0.1/Ollama أبداً.</strong>
    هذا القسم منفصل تماماً عن Cloud AI أدناه — Hermes ليس «تفعيل السحابة».
  </p>
  <p>
    متصل: <strong><?= (int)($localAi['online_workers'] ?? 0) ?></strong> —
    عمق الطابور: <strong><?= (int)($localAi['queue_depth'] ?? 0) ?></strong> —
    آخر نبضة: <code><?= \Sameh\App::e($localAi['last_heartbeat'] ?? '—') ?></code>
  </p>
  <?php if (!empty($localAi['models'])): ?>
    <p>النماذج: <?= \Sameh\App::e(implode(', ', $localAi['models'])) ?></p>
  <?php else: ?>
    <p style="color:var(--muted);">لا نماذج بعد — Pair العامل بعد تشغيل Ollama.</p>
  <?php endif; ?>
  <form method="post" action="/settings" style="display:inline;">
    <?= \Sameh\Security\Csrf::field() ?>
    <input type="hidden" name="form_action" value="local_ai_pair">
    <label>اسم العامل</label>
    <input type="text" name="worker_name" value="local-worker">
    <button type="submit">Pair — إنشاء رمز ربط</button>
  </form>
  <form method="post" action="/settings" style="display:inline;">
    <?= \Sameh\Security\Csrf::field() ?>
    <input type="hidden" name="form_action" value="local_ai_test">
    <button type="submit">Test AI / حالة العامل</button>
  </form>
  <p><a href="/download/local-ai-worker">Download Worker</a> · راجع <code>docs/OLLAMA-HERMES-AR.md</code></p>
  <?php if (!empty($aiWorkers)): ?>
    <ul>
      <?php foreach ($aiWorkers as $w): ?>
        <li>
          #<?= (int)$w['id'] ?> <?= \Sameh\App::e($w['name']) ?> — <?= \Sameh\App::e($w['status']) ?>
          <?php if (empty($w['revoked_at'])): ?>
            <form method="post" action="/settings" style="display:inline;">
              <?= \Sameh\Security\Csrf::field() ?>
              <input type="hidden" name="form_action" value="local_ai_revoke">
              <input type="hidden" name="worker_id" value="<?= (int)$w['id'] ?>">
              <button type="submit" class="danger">Revoke</button>
            </form>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>

<div class="card">
  <h2>مزوّد الذكاء الاصطناعي السحابي / Cloud AI Provider</h2>
  <p style="font-size:0.85rem;color:var(--muted);">OpenAI-compatible HTTPS عام فقط (SSRF محمي). السحابة <strong>مطفأة افتراضياً</strong>. لا تُرسل أسرار HMAC للموديل.</p>
  <p>الحالة: <code><?= \Sameh\App::e($ai['last_status'] ?? 'never_tested') ?></code>
     — مفتاح محفوظ: <?= !empty($aiHasKey) ? 'نعم' : 'لا' ?></p>
  <form method="post" action="/settings">
    <?= \Sameh\Security\Csrf::field() ?>
    <input type="hidden" name="form_action" value="ai_settings">
    <label>
      <input type="checkbox" name="ai_cloud_enabled" value="1" <?= !empty($ai['enabled']) ? 'checked' : '' ?>>
      تفعيل السحابة / Enable cloud AI
    </label>
    <label>Base URL (HTTPS عام — مثل https://api.openai.com/v1)</label>
    <input type="url" name="ai_base_url" value="<?= \Sameh\App::e($ai['base_url'] ?? '') ?>" placeholder="https://api.openai.com/v1">
    <label>Model</label>
    <input type="text" name="ai_model" value="<?= \Sameh\App::e($ai['model'] ?? 'gpt-4o-mini') ?>">
    <label>API Key (اتركه فارغاً للإبقاء على الحالي)</label>
    <input type="password" name="ai_api_key" value="" autocomplete="off" placeholder="sk-...">
    <label><input type="checkbox" name="clear_api_key" value="1"> مسح المفتاح / Clear key</label>
    <label>Timeout (ثوانٍ)</label>
    <input type="number" name="ai_timeout_seconds" min="5" max="120" value="<?= (int)($ai['timeout'] ?? 30) ?>">
    <button type="submit">حفظ إعدادات Cloud AI</button>
  </form>
  <form method="post" action="/settings" style="margin-top:0.75rem;">
    <?= \Sameh\Security\Csrf::field() ?>
    <input type="hidden" name="form_action" value="ai_test">
    <button type="submit">اختبار الاتصال السحابي / Test cloud connection</button>
  </form>
</div>

<div class="card">
  <h2>طبقة البحث / Research allowlist</h2>
  <form method="post" action="/settings">
    <?= \Sameh\Security\Csrf::field() ?>
    <input type="hidden" name="form_action" value="research_settings">
    <label><input type="checkbox" name="research_enabled" value="1" <?= !empty($researchEnabled) ? 'checked' : '' ?>> تفعيل الجلب المسموح (مع حماية SSRF)</label>
    <label>مضيفون مسموحون (فاصلة)</label>
    <input type="text" name="research_allowlist" value="<?= \Sameh\App::e($researchAllowlist ?? '') ?>" placeholder="example.com, docs.example.org">
    <button type="submit">حفظ</button>
  </form>
</div>
