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
  <title><?= \Sameh\App::e(($title ?? 'SAMEH') . ' — SAMEH 12.1') ?></title>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<?php if (!empty($killSwitch) && !$isAuth): ?>
  <div class="kill-banner" style="margin:0;border-radius:0;">⚠ Kill Switch مفعّل — العمليات موقوفة / Kill switch ON</div>
<?php endif; ?>
<?php if ($isAuth): ?>
  <div class="auth-wrap">
    <div style="text-align:center;margin-bottom:1.5rem;font-size:1.4rem;font-weight:700;">SAMEH 12.1 <span style="color:var(--muted);font-size:0.85rem;">Professional</span></div>
    <?php if (!empty($flash)): ?>
      <div class="flash <?= \Sameh\App::e($flash['type']) ?>"><?= \Sameh\App::e($flash['message']) ?></div>
    <?php endif; ?>
    <?php require $viewFile; ?>
  </div>
<?php else: ?>
  <div class="layout">
    <aside class="sidebar">
      <div class="brand">SAMEH 12.1</div>
      <?php if (!empty($sitesForSwitcher)): ?>
      <div class="meta" style="margin-bottom:0.75rem;">
        <form method="post" action="/sites/<?= (int)(($activeSite['id'] ?? $sitesForSwitcher[0]['id'])) ?>/activate" style="margin:0;">
          <?= \Sameh\Security\Csrf::field() ?>
          <input type="hidden" name="redirect" value="<?= \Sameh\App::e($_SERVER['REQUEST_URI'] ?? '/dashboard') ?>">
          <label style="font-size:0.75rem;display:block;margin-bottom:0.25rem;">الموقع النشط / Active site</label>
          <select name="site_select" id="active-site-select" style="width:100%;margin-bottom:0.35rem;" onchange="var id=this.value; this.form.action='/sites/'+id+'/activate'; this.form.submit();">
            <?php foreach ($sitesForSwitcher as $s): ?>
              <option value="<?= (int)$s['id'] ?>" <?= (!empty($activeSite) && (int)$activeSite['id'] === (int)$s['id']) ? 'selected' : '' ?>>
                <?= \Sameh\App::e($s['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if (empty($activeSite)): ?>
            <button type="submit" style="width:100%;font-size:0.8rem;">تفعيل الأول / Activate</button>
          <?php endif; ?>
        </form>
      </div>
      <?php endif; ?>
      <nav>
        <a href="/dashboard" class="<?= ($page ?? '') === 'dashboard' ? 'active' : '' ?>">مركز القيادة</a>
        <a href="/sites" class="<?= ($page ?? '') === 'sites' ? 'active' : '' ?>">المواقع / Sites</a>
        <a href="/missions" class="<?= ($page ?? '') === 'missions' ? 'active' : '' ?>">المهام / Missions</a>
        <a href="/factory" class="<?= ($page ?? '') === 'factory' ? 'active' : '' ?>">المصنع / Factory</a>
        <a href="/growth" class="<?= ($page ?? '') === 'growth' ? 'active' : '' ?>">النمو / Growth</a>
        <a href="/plans" class="<?= ($page ?? '') === 'plans' ? 'active' : '' ?>">الخطط / Plans</a>
        <a href="/approvals" class="<?= ($page ?? '') === 'approvals' ? 'active' : '' ?>">الموافقات / Approvals</a>
        <a href="/audit" class="<?= ($page ?? '') === 'audit' ? 'active' : '' ?>">التدقيق / Audit</a>
        <a href="/settings" class="<?= ($page ?? '') === 'settings' ? 'active' : '' ?>">الإعدادات</a>
        <a href="/2fa/setup">2FA</a>
        <a href="/logout">خروج / Logout</a>
      </nav>
      <div class="meta">
        <?php if (!empty($user)): ?>
          <?= \Sameh\App::e($user['email'] ?? '') ?><br>
          <span style="opacity:0.8;"><?= \Sameh\App::e($appVersion ?? '') ?></span><br>
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
