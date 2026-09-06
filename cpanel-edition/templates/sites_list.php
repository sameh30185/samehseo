<h1>المواقع / Sites</h1>
<p><a class="btn" href="/sites/add">+ إضافة موقع</a></p>
<div class="card">
<?php if (empty($sites)): ?>
  <p class="empty">لا مواقع — NOT CONNECTED. أضف موقعاً للبدء.</p>
<?php else: ?>
  <table>
    <thead>
      <tr><th>#</th><th>الاسم</th><th>URL</th><th>الوضع</th><th>الحالة</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($sites as $s): ?>
      <tr>
        <td><?= (int)$s['id'] ?></td>
        <td><?= \Sameh\App::e($s['name']) ?></td>
        <td class="mono"><?= \Sameh\App::e($s['url']) ?></td>
        <td><span class="badge muted"><?= \Sameh\App::e($s['mode']) ?></span></td>
        <td>
          <?php
            $st = $s['status'] ?? 'NOT_CONNECTED';
            $cls = $st === 'CONNECTED' ? 'ok' : ($st === 'ERROR' ? 'err' : ($st === 'PAIRED' ? 'warn' : 'muted'));
          ?>
          <span class="badge <?= $cls ?>"><?= \Sameh\App::e($st) ?></span>
        </td>
        <td><a href="/sites/<?= (int)$s['id'] ?>">تفاصيل</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div>
