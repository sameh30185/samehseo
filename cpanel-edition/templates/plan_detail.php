<h1>خطة إجراءات #<?= (int)$plan['id'] ?></h1>
<p>
  حالة: <strong><?= \Sameh\App::e($plan['status']) ?></strong> —
  خطر: <?= \Sameh\App::e($plan['risk'] ?? '') ?>
  <?php if (!empty($plan['needs_extra_approval'])): ?> — <span class="badge">يتطلب تأكيداً إضافياً</span><?php endif; ?>
</p>

<div class="card">
  <?php if (in_array($plan['status'], ['draft', 'preview_ready'], true)): ?>
    <form method="post" action="/plans/<?= (int)$plan['id'] ?>/preview" style="display:inline;">
      <?= \Sameh\Security\Csrf::field() ?>
      <button type="submit">حساب المعاينة / Preview</button>
    </form>
  <?php endif; ?>
  <?php if (in_array($plan['status'], ['preview_ready', 'draft'], true)): ?>
    <form method="post" action="/plans/<?= (int)$plan['id'] ?>/request-approval" style="display:inline;">
      <?= \Sameh\Security\Csrf::field() ?>
      <button type="submit">طلب موافقة المالك</button>
    </form>
  <?php endif; ?>
  <?php if ($plan['status'] === 'approved'): ?>
    <h3>رفع مؤقت (READ_ONLY)</h3>
    <p style="font-size:0.9rem;color:var(--muted);">لا يكفي وضع علامة فقط. المطلوب: مالك + 2FA + كتابة اسم الموقع + TTL قصيرة ثم التنفيذ.</p>
    <form method="post" action="/plans/<?= (int)$plan['id'] ?>/request-elevate">
      <?= \Sameh\Security\Csrf::field() ?>
      <label>اكتب اسم الموقع للتأكيد: <strong><?= \Sameh\App::e($activeSite['name'] ?? '') ?></strong></label>
      <input type="text" name="confirm_site_name" required autocomplete="off">
      <label>رمز 2FA</label>
      <input type="text" name="totp_code" inputmode="numeric" required autocomplete="one-time-code">
      <button type="submit">منح رفع مؤقت (5 دقائق)</button>
    </form>
    <form method="post" action="/plans/<?= (int)$plan['id'] ?>/execute" style="margin-top:1rem;">
      <?= \Sameh\Security\Csrf::field() ?>
      <label><input type="checkbox" name="temporary_elevate" value="1"> استخدم الرفع المؤقت الممنوح أعلاه</label>
      <?php if (!empty($plan['needs_extra_approval'])): ?>
        <label><input type="checkbox" name="confirm_extra" value="1" required> تأكيد إضافي للنشر/الدفعات</label>
      <?php endif; ?>
      <button type="submit">تنفيذ / Execute</button>
    </form>
  <?php endif; ?>
  <?php if (in_array($plan['status'], ['verified', 'failed', 'executing'], true)): ?>
    <form method="post" action="/plans/<?= (int)$plan['id'] ?>/rollback" style="display:inline;">
      <?= \Sameh\Security\Csrf::field() ?>
      <button type="submit" class="danger">تراجع / Rollback</button>
    </form>
  <?php endif; ?>
</div>

<?php if (!empty($preview['diffs'])): ?>
<div class="card">
  <h2>Diff المعاينة</h2>
  <p><?= \Sameh\App::e($preview['summary_ar'] ?? '') ?></p>
  <ul>
    <?php foreach ($preview['diffs'] as $d): ?>
      <li>
        <strong><?= \Sameh\App::e($d['action_type'] ?? '') ?></strong>
        (<?= \Sameh\App::e($d['risk'] ?? '') ?>)
        <pre style="white-space:pre-wrap;font-size:0.85rem;"><?= \Sameh\App::e(json_encode($d, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)) ?></pre>
      </li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<?php if (!empty($actions)): ?>
<div class="card">
  <h2>الإجراءات المكتوبة / Typed actions</h2>
  <ul>
    <?php foreach ($actions as $a): ?>
      <li>
        #<?= (int)$a['id'] ?>
        <code><?= \Sameh\App::e($a['action_type']) ?></code>
        — <?= \Sameh\App::e($a['status']) ?>
        <?php if (!empty($a['error_message'])): ?>
          <span style="color:var(--danger);"><?= \Sameh\App::e($a['error_message']) ?></span>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>
<p><a href="/plans">← الخطط</a> · <a href="/approvals">الموافقات</a></p>
