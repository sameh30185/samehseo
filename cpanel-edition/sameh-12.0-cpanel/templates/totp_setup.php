<div class="card">
  <h1>إعداد المصادقة الثنائية / TOTP 2FA</h1>
  <?php if (!empty($enabled)): ?>
    <p><span class="badge ok">2FA مفعّل حالياً</span> — يمكنك إعادة الإعداد أدناه.</p>
  <?php else: ?>
    <p class="flash warn" style="border:1px solid var(--warn);background:rgba(245,158,11,0.1)">يُفضّل تفعيل 2FA بعد أول دخول.</p>
  <?php endif; ?>
  <ol>
    <li>افتح تطبيق المصادقة (Google Authenticator / Aegis / Authy)</li>
    <li>أضف المفتاح يدوياً أو امسح الرابط otpauth</li>
  </ol>
  <p>السر / Secret:</p>
  <p class="mono" id="totp-secret"><?= \Sameh\App::e($secret ?? '') ?></p>
  <button type="button" class="secondary" data-copy="#totp-secret">نسخ / Copy</button>
  <p style="margin-top:1rem;color:var(--muted);font-size:0.85rem;word-break:break-all">URI: <?= \Sameh\App::e($uri ?? '') ?></p>
  <form method="post" action="/2fa/setup">
    <?= \Sameh\Security\Csrf::field() ?>
    <label>أدخل الرمز من التطبيق / Enter code</label>
    <input type="text" name="code" inputmode="numeric" pattern="[0-9 ]*" required autocomplete="one-time-code">
    <button type="submit">تفعيل / Enable 2FA</button>
  </form>
  <p><a href="/dashboard">تخطي الآن / Skip for now</a></p>
</div>
