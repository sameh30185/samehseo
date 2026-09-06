<?php
$viewFile = __DIR__ . '/' . ($template ?? 'dashboard') . '.php';
if (!is_file($viewFile)) {
    $viewFile = __DIR__ . '/404.php';
}
$authPages = ['login', 'install', '2fa'];
$isAuth = in_array($page ?? '', $authPages, true);
?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= \Sameh\App::e(($title ?? 'SAMEH') . ' — SAMEH 12.0') ?></title>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<?php if (!empty($killSwitch) && !$isAuth): ?>
  <div class="kill-banner" style="margin:0;border-radius:0;">⚠ Kill Switch مفعّل — العمليات موقوفة / Kill switch ON</div>
<?php endif; ?>
<?php if ($isAuth): ?>
  <div class="auth-wrap">
    <div style="text-align:center;margin-bottom:1.5rem;font-size:1.4rem;font-weight:700;">SAMEH 12.0 <span style="color:var(--muted);font-size:0.85rem;">cPanel</span></div>
    <?php if (!empty($flash)): ?>
      <div class="flash <?= \Sameh\App::e($flash['type']) ?>"><?= \Sameh\App::e($flash['message']) ?></div>
    <?php endif; ?>
    <?php require $viewFile; ?>
  </div>
<?php else: ?>
  <div class="layout">
    <aside class="sidebar">
      <div class="brand">SAMEH 12.0</div>
      <nav>
        <a href="/dashboard" class="<?= ($page ?? '') === 'dashboard' ? 'active' : '' ?>">مركز القيادة</a>
        <a href="/sites" class="<?= ($page ?? '') === 'sites' ? 'active' : '' ?>">المواقع / Sites</a>
        <a href="/missions" class="stub <?= ($page ?? '') === 'missions' ? 'active' : '' ?>">المهام / Missions</a>
        <a href="/factory" class="stub <?= ($page ?? '') === 'factory' ? 'active' : '' ?>">المصنع / Factory</a>
        <a href="/growth" class="stub <?= ($page ?? '') === 'growth' ? 'active' : '' ?>">النمو / Growth</a>
        <a href="/audit" class="<?= ($page ?? '') === 'audit' ? 'active' : '' ?>">التدقيق / Audit</a>
        <a href="/settings" class="<?= ($page ?? '') === 'settings' ? 'active' : '' ?>">الإعدادات</a>
        <a href="/2fa/setup">2FA</a>
        <a href="/logout">خروج / Logout</a>
      </nav>
      <div class="meta">
        <?php if (!empty($user)): ?>
          <?= \Sameh\App::e($user['email'] ?? '') ?><br>
          الوضع الافتراضي: <strong>READ ONLY</strong>
        <?php endif; ?>
      </div>
    </aside>
    <main class="main">
      <?php if (!empty($flash)): ?>
        <div class="flash <?= \Sameh\App::e($flash['type']) ?>"><?= \Sameh\App::e($flash['message']) ?></div>
      <?php endif; ?>
      <?php require $viewFile; ?>
    </main>
  </div>
<?php endif; ?>
<script src="/assets/js/app.js"></script>
</body>
</html>
