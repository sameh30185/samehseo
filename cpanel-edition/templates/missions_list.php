<h1>المهام / Missions</h1>
<?php if (empty($activeSite)): ?>
  <div class="flash error">اختر موقعاً نشطاً من الشريط الجانبي أو <a href="/sites">المواقع</a>.</div>
<?php else: ?>
  <p>الموقع النشط: <strong><?= \Sameh\App::e($activeSite['name']) ?></strong></p>
  <div class="card">
    <h2>إنشاء مهمة / Create mission</h2>
    <form method="post" action="/missions">
      <?= \Sameh\Security\Csrf::field() ?>
      <label>النوع / Type</label>
      <select name="type" required>
        <?php foreach ($types as $k => $label): ?>
          <option value="<?= \Sameh\App::e($k) ?>"><?= \Sameh\App::e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <label>العنوان (اختياري)</label>
      <input type="text" name="title" placeholder="اختياري">
      <button type="submit">إنشاء</button>
    </form>
  </div>
  <div class="card">
    <h2>القائمة</h2>
    <?php if (empty($missions)): ?>
      <p>لا مهام بعد.</p>
    <?php else: ?>
      <table>
        <thead><tr><th>#</th><th>العنوان</th><th>النوع</th><th>الحالة</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($missions as $m): ?>
          <tr>
            <td><?= (int)$m['id'] ?></td>
            <td><?= \Sameh\App::e($m['title']) ?></td>
            <td><?= \Sameh\App::e($m['type']) ?></td>
            <td><?= \Sameh\App::e($m['status']) ?></td>
            <td><a href="/missions/<?= (int)$m['id'] ?>">عرض</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
<?php endif; ?>
