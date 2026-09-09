<h1>الموافقات / Approvals</h1>
<?php if (empty($activeSite)): ?>
  <div class="flash error">اختر موقعاً نشطاً أولاً.</div>
<?php elseif (empty($approvals)): ?>
  <div class="card"><p>لا موافقات لهذا الموقع.</p></div>
<?php else: ?>
  <div class="card">
    <table>
      <thead><tr><th>#</th><th>العنوان</th><th>القرار</th><th>خطة</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($approvals as $a): ?>
          <tr>
            <td><?= (int)$a['id'] ?></td>
            <td><?= \Sameh\App::e($a['title'] ?? '') ?></td>
            <td><?= \Sameh\App::e($a['decision'] ?? $a['status'] ?? '') ?></td>
            <td><?= !empty($a['plan_id']) ? '#'.(int)$a['plan_id'] : '—' ?></td>
            <td><a href="/approvals/<?= (int)$a['id'] ?>">عرض</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
<p><a href="/plans">خطط الإجراءات ←</a></p>
