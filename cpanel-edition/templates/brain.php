<h1>عقل المشروع / Project Brain</h1>
<?php if (empty($activeSite)): ?>
  <div class="flash error">اختر موقعاً نشطاً أولاً.</div>
<?php else: ?>
  <p>نطاق الموقع: <strong><?= \Sameh\App::e($activeSite['name']) ?></strong> — حقائق موثّقة مقابل استنتاجات.</p>
  <div class="card">
    <h2>إضافة عنصر</h2>
    <form method="post" action="/brain">
      <?= \Sameh\Security\Csrf::field() ?>
      <label>النوع</label>
      <select name="kind" required>
        <?php foreach ($kinds as $k => $label): ?>
          <option value="<?= \Sameh\App::e($k) ?>"><?= \Sameh\App::e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <label>التسمية</label>
      <input type="text" name="label" required>
      <label>القيمة</label>
      <textarea name="value_text" rows="3" required></textarea>
      <label><input type="checkbox" name="is_inference" value="1"> استنتاج (غير موثّق بعد)</label>
      <button type="submit">حفظ</button>
    </form>
  </div>
  <div class="card">
    <h2>العناصر</h2>
    <?php if (empty($items)): ?>
      <p style="color:var(--muted);">لا بيانات بعد لهذا الموقع.</p>
    <?php else: ?>
      <table>
        <thead><tr><th>نوع</th><th>تسمية</th><th>قيمة</th><th>حالة</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($items as $it): ?>
          <tr>
            <td><?= \Sameh\App::e($kinds[$it['kind']] ?? $it['kind']) ?></td>
            <td><?= \Sameh\App::e($it['label']) ?></td>
            <td><?= \Sameh\App::e(mb_substr((string)$it['value_text'], 0, 120)) ?></td>
            <td>
              <?php if (!empty($it['approved'])): ?>
                ✅ حقيقة موثّقة
              <?php elseif (!empty($it['is_inference'])): ?>
                ⚠ استنتاج
              <?php else: ?>
                بانتظار موافقة المالك
              <?php endif; ?>
            </td>
            <td>
              <?php if (empty($it['approved'])): ?>
                <form method="post" action="/brain/<?= (int)$it['id'] ?>/approve" style="display:inline;">
                  <?= \Sameh\Security\Csrf::field() ?>
                  <button type="submit">موافقة مالك</button>
                </form>
              <?php endif; ?>
              <form method="post" action="/brain/<?= (int)$it['id'] ?>/delete" style="display:inline;" onsubmit="return confirm('حذف؟');">
                <?= \Sameh\Security\Csrf::field() ?>
                <button type="submit" class="danger">حذف</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
<?php endif; ?>
