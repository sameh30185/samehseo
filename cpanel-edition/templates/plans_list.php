<h1>خطط الإجراءات / Action plans</h1>
<?php if (empty($activeSite)): ?>
  <div class="flash error">اختر موقعاً نشطاً أولاً.</div>
<?php elseif (empty($plans)): ?>
  <div class="card"><p>لا خطط بعد. أنشئ من مهمة (decision_ready) أو المصنع أو النمو.</p></div>
<?php else: ?>
  <div class="card">
    <table>
      <thead><tr><th>#</th><th>الحالة</th><th>خطر</th><th>مهمة</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($plans as $p): ?>
          <tr>
            <td><?= (int)$p['id'] ?></td>
            <td><?= \Sameh\App::e($p['status']) ?></td>
            <td><?= \Sameh\App::e($p['risk']) ?></td>
            <td><?= $p['mission_id'] ? '#'.(int)$p['mission_id'] : '—' ?></td>
            <td><a href="/plans/<?= (int)$p['id'] ?>">عرض / معاينة</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
