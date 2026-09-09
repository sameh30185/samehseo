<h1>النمو / Growth</h1>
<?php if (empty($activeSite)): ?>
  <div class="flash error">اختر موقعاً نشطاً أولاً.</div>
<?php else: ?>
  <div class="card">
    <p>اشتقاق الفرص من آخر Discover (صفحات رقيقة، أحياء ناقصة، تشابه عناوين). <strong>بدون إنشاء جماعي للصفحات</strong>.</p>
    <form method="post" action="/growth/refresh" style="display:inline;">
      <?= \Sameh\Security\Csrf::field() ?>
      <button type="submit">تحديث الفرص من Discover</button>
    </form>
    <form method="post" action="/growth/mission" style="display:inline;">
      <?= \Sameh\Security\Csrf::field() ?>
      <button type="submit">إنشاء مهمة نمو</button>
    </form>
  </div>

  <?php if (empty($opportunities)): ?>
    <div class="card"><p style="color:var(--muted);">لا فرص بعد — شغّل Discover ثم «تحديث الفرص».</p></div>
  <?php else: ?>
    <div class="card">
      <h2>الفرص (مرتّبة بالنتيجة)</h2>
      <table>
        <thead>
          <tr>
            <th>العنوان</th>
            <th>النوع</th>
            <th>أثر</th>
            <th>ثقة</th>
            <th>جهد</th>
            <th>خطر</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($opportunities as $o): ?>
            <tr>
              <td><?= \Sameh\App::e($o['title']) ?></td>
              <td><code><?= \Sameh\App::e($o['kind']) ?></code></td>
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
    </div>
  <?php endif; ?>
<?php endif; ?>
