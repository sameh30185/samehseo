<div class="card">
  <h1>تثبيت SAMEH 12.0</h1>
  <p style="color:var(--muted)">Installer — create tables + Owner account</p>

  <?php if (empty($configured)): ?>
    <div class="flash error">
      ملف الإعداد غير موجود. انسخ <code>config.example.php</code> إلى
      <code>config.local.php</code> (بجانب المجلد) أو
      <code>config.php</code> خارج <code>public/</code> واملأ بيانات MySQL و <code>app_url</code>.
    </div>
    <p>Config file missing. Copy <code>config.example.php</code> → <code>config.local.php</code> or parent <code>config.php</code>.</p>
  <?php elseif (!empty($dbError)): ?>
    <div class="flash error">فشل الاتصال بقاعدة البيانات: <?= \Sameh\App::e($dbError) ?></div>
  <?php else: ?>
    <p>الاتصال بقاعدة البيانات: <span class="badge ok">OK</span>
      <?php if (!empty($configPath)): ?>
        <br><small class="mono"><?= \Sameh\App::e($configPath) ?></small>
      <?php endif; ?>
    </p>
    <form method="post" action="/install">
      <?= \Sameh\Security\Csrf::field() ?>
      <label>البريد / Email</label>
      <input type="email" name="email" required autocomplete="username">
      <label>الاسم / Display name</label>
      <input type="text" name="display_name" value="Owner" required>
      <label>كلمة المرور / Password (min 10)</label>
      <input type="password" name="password" required minlength="10" autocomplete="new-password">
      <button type="submit">تثبيت الآن / Install</button>
    </form>
  <?php endif; ?>
</div>
