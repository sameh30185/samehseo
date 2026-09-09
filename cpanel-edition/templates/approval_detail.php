<h1>موافقة #<?= (int)$approval['id'] ?></h1>
<p>الحالة: <strong><?= \Sameh\App::e($approval['decision'] ?? $approval['status'] ?? '') ?></strong>
<?php if (!empty($plan)): ?> — خطة #<?= (int)$plan['id'] ?> (<?= \Sameh\App::e($plan['status']) ?>) خطر: <?= \Sameh\App::e($plan['risk'] ?? '') ?><?php endif; ?>
</p>

<?php if (!empty($preview['diffs'])): ?>
<div class="card">
  <h2>المعاينة / Diff</h2>
  <ul>
    <?php foreach ($preview['diffs'] as $d): ?>
      <li>
        <code><?= \Sameh\App::e($d['action_type'] ?? '') ?></code>
        — خطر <?= \Sameh\App::e($d['risk'] ?? '') ?>
        <pre style="white-space:pre-wrap;font-size:0.85rem;"><?= \Sameh\App::e(json_encode(['before'=>$d['before']??null,'after'=>$d['after']??null], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)) ?></pre>
      </li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<?php
$pending = (($approval['decision'] ?? $approval['status'] ?? '') === 'pending');
if ($pending):
?>
<div class="card">
  <form method="post" action="/approvals/<?= (int)$approval['id'] ?>/decide">
    <?= \Sameh\Security\Csrf::field() ?>
    <label>ملاحظة</label>
    <textarea name="note" rows="2"></textarea>
    <?php if (!empty($user['totp_enabled'])): ?>
      <label>رمز 2FA</label>
      <input type="text" name="totp_code" inputmode="numeric" autocomplete="one-time-code" required>
    <?php endif; ?>
    <?php if (!empty($plan['needs_extra_approval'])): ?>
      <label><input type="checkbox" name="confirm_extra" value="1"> تأكيد إضافي (نشر/دفعة كبيرة)</label>
    <?php endif; ?>
    <p>
      <button type="submit" name="decision" value="approved">موافقة / Approve</button>
      <button type="submit" name="decision" value="rejected" class="danger">رفض / Reject</button>
    </p>
  </form>
</div>
<?php endif; ?>
<p><a href="/approvals">← القائمة</a>
<?php if (!empty($plan)): ?> · <a href="/plans/<?= (int)$plan['id'] ?>">الخطة</a><?php endif; ?>
</p>
