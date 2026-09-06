<?php /** @var array $site */ ?>
<h1><?= \Sameh\App::e($site['name']) ?></h1>
<p>
  <span class="badge muted"><?= \Sameh\App::e($site['mode']) ?></span>
  <?php
    $st = $site['status'] ?? 'NOT_CONNECTED';
    $cls = $st === 'CONNECTED' ? 'ok' : ($st === 'ERROR' ? 'err' : ($st === 'PAIRED' ? 'warn' : 'muted'));
  ?>
  <span class="badge <?= $cls ?>"><?= \Sameh\App::e($st) ?></span>
</p>
<div class="card">
  <p><strong>URL:</strong> <a href="<?= \Sameh\App::e($site['url']) ?>" target="_blank" rel="noopener"><?= \Sameh\App::e($site['url']) ?></a></p>
  <p><strong>Site ID:</strong> <code><?= (int)$site['id'] ?></code></p>
  <p><strong>آخر Health:</strong>
    <?= !empty($site['last_health_at']) ? \Sameh\App::e($site['last_health_at']) . ' UTC' : '— لا بيانات / no data' ?>
  </p>
</div>

<div class="card">
  <h2>1) Pairing — ربط الـ Connector</h2>
  <p>يولّد رمز Connector + سر HMAC مشترك. انسخهما إلى إعدادات إضافة WordPress.</p>
  <form method="post" action="/sites/<?= (int)$site['id'] ?>/pair" onsubmit="return confirm('توليد مفاتيح جديدة يبطل القديمة. متابعة؟');">
    <?= \Sameh\Security\Csrf::field() ?>
    <button type="submit"><?= empty($site['hmac_secret']) ? 'توليد مفاتيح الربط' : 'إعادة توليد المفاتيح' ?></button>
  </form>
  <?php if (!empty($pairResult)): ?>
    <div class="secret-box">
      <p><strong>اعرض مرة واحدة — احفظ الآن:</strong></p>
      <p>Core URL: <code id="core-url"><?= \Sameh\App::e(\Sameh\Config::get('app_url', '')) ?></code>
        <button type="button" class="secondary" data-copy="#core-url">نسخ / Copy</button></p>
      <p>Site ID: <code id="pair-sid"><?= (int)$site['id'] ?></code></p>
      <p>Connector Token: <code id="pair-token"><?= \Sameh\App::e($pairResult['connector_token']) ?></code>
        <button type="button" class="secondary" data-copy="#pair-token">نسخ / Copy</button></p>
      <p>Shared HMAC Secret: <code id="pair-secret"><?= \Sameh\App::e($pairResult['hmac_secret']) ?></code>
        <button type="button" class="secondary" data-copy="#pair-secret">نسخ / Copy</button></p>
      <ol>
        <li>ارفع مجلد <code>sameh-connector</code> إلى <code>wp-content/plugins/</code> أو ثبّت الـ zip</li>
        <li>فعّل الإضافة من لوحة WordPress</li>
        <li>Settings → SAMEH Connector: الصق Core URL + Site ID + Shared Secret</li>
        <li>ارجع هنا واضغط Test Health ثم Discover</li>
      </ol>
    </div>
  <?php elseif (!empty($site['hmac_secret'])): ?>
    <p class="empty">الموقع مقترن (PAIRED). السر لا يُعرض مرة أخرى — أعد التوليد إن فقدته.</p>
    <p>Token (جزئي): <code><?= \Sameh\App::e(substr((string)$site['connector_token'], 0, 8)) ?>…</code></p>
  <?php else: ?>
    <p class="empty">NOT CONNECTED — لم يتم الربط بعد.</p>
  <?php endif; ?>
</div>

<div class="card">
  <h2>2) Bridge — Health / Discover</h2>
  <form method="post" action="/sites/<?= (int)$site['id'] ?>/health" style="display:inline">
    <?= \Sameh\Security\Csrf::field() ?>
    <button type="submit">Test Health</button>
  </form>
  <form method="post" action="/sites/<?= (int)$site['id'] ?>/discover" style="display:inline">
    <?= \Sameh\Security\Csrf::field() ?>
    <button type="submit" class="secondary">Discover</button>
  </form>
</div>

<div class="card">
  <h2>Discover نتيجة</h2>
  <?php if (empty($discover)): ?>
    <p class="empty">لا بيانات اكتشاف — NOT CONNECTED / لم يُنفَّذ Discover بعد.</p>
  <?php else: ?>
    <table>
      <tr><th>WordPress</th><td><?= \Sameh\App::e((string)($discover['wp_version'] ?? '—')) ?></td></tr>
      <tr><th>Theme</th><td><?= \Sameh\App::e((string)($discover['theme'] ?? '—')) ?></td></tr>
      <tr><th>Posts</th><td><?= isset($discover['counts']['posts']) ? (int)$discover['counts']['posts'] : '—' ?></td></tr>
      <tr><th>Pages</th><td><?= isset($discover['counts']['pages']) ? (int)$discover['counts']['pages'] : '—' ?></td></tr>
      <tr><th>Active plugins</th><td>
        <?php
          $plugins = $discover['active_plugins'] ?? [];
          if (empty($plugins)) {
              echo '—';
          } else {
              echo \Sameh\App::e(implode(', ', array_map('strval', $plugins)));
          }
        ?>
      </td></tr>
    </table>
  <?php endif; ?>
</div>
<p><a href="/sites">← العودة للمواقع</a></p>
