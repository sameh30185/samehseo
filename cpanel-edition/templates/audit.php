<h1>سجل التدقيق / Audit Log</h1>
<div class="card">
<?php if (empty($rows)): ?>
  <p class="empty">لا سجلات بعد.</p>
<?php else: ?>
  <table>
    <thead>
      <tr><th>الوقت UTC</th><th>المستخدم</th><th>الإجراء</th><th>الكيان</th><th>IP</th><th>تفاصيل</th></tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="mono"><?= \Sameh\App::e($r['created_at']) ?></td>
        <td><?= \Sameh\App::e($r['user_email'] ?? '—') ?></td>
        <td><code><?= \Sameh\App::e($r['action']) ?></code></td>
        <td><?= \Sameh\App::e(trim(($r['entity_type'] ?? '') . ' ' . ($r['entity_id'] ?? ''))) ?></td>
        <td class="mono"><?= \Sameh\App::e($r['ip_address'] ?? '') ?></td>
        <td class="mono" style="max-width:220px;overflow:hidden;text-overflow:ellipsis"><?= \Sameh\App::e($r['details_json'] ?? '') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div>
