<h1>النمو / Growth</h1>
<?php if (empty($activeSite)): ?>
  <div class="flash error">اختر موقعاً نشطاً أولاً.</div>
<?php else: ?>
  <div class="card">
    <p>اشتقاق الفرص من Discover v2 + Project Brain. <strong>بدون إنشاء جماعي للصفحات</strong>. الخصم بالتكرار عبر بصمة الموقع.</p>
    <form method="post" action="/growth/refresh" style="display:inline;">
      <?= \Sameh\Security\Csrf::field() ?>
      <button type="submit">تحديث الفرص من Discover</button>
    </form>
    <form method="post" action="/growth/mission" style="display:inline;">
      <?= \Sameh\Security\Csrf::field() ?>
      <button type="submit">مهمة نمو</button>
    </form>
  </div>
  <?php if (!empty($insufficientMessage)): ?>
    <div class="flash error"><?= \Sameh\App::e($insufficientMessage) ?></div>
  <?php elseif (empty($opportunities)): ?>
    <div class="card"><p style="color:var(--muted);">لا فرص محفوظة بعد — شغّل Discover ثم «تحديث الفرص». إن كانت الأدلة ضعيفة ستظهر رسالة عربية واضحة بدل «0 فرصة».</p></div>
  <?php else: ?>
    <table>
      <thead><tr><th>الفرصة</th><th>أثر</th><th>ثقة</th><th>جهد</th><th>خطر</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($opportunities as $o): ?>
        <tr>
          <td><?= \Sameh\App::e($o['title']) ?><br><small><?= \Sameh\App::e($o['kind']) ?></small></td>
          <td><?= (int)$o['impact_score'] ?></td>
          <td><?= (int)$o['confidence_score'] ?></td>
          <td><?= (int)$o['effort_score'] ?></td>
          <td><?= (int)$o['risk_score'] ?></td>
          <td>
            <form method="post" action="/growth/<?= (int)$o['id'] ?>/mission" style="display:inline;">
              <?= \Sameh\Security\Csrf::field() ?>
              <button type="submit">→ مهمة</button>
            </form>
            <form method="post" action="/growth/<?= (int)$o['id'] ?>/plan" style="display:inline;">
              <?= \Sameh\Security\Csrf::field() ?>
              <button type="submit">→ مسودة واحدة</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
<?php endif; ?>
