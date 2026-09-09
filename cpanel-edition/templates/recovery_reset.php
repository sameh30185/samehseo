<div class="card">
  <h1>تعيين كلمة مرور جديدة / Reset password</h1>
  <?php if (empty($valid)): ?>
    <div class="flash error">الرابط غير صالح أو منتهٍ / Invalid or expired link</div>
    <p><a href="/recovery">طلب رابط جديد</a></p>
  <?php else: ?>
  <form method="post" action="/recovery/reset">
    <?= \Sameh\Security\Csrf::field() ?>
    <input type="hidden" name="token" value="<?= \Sameh\App::e($token ?? '') ?>">
    <label>كلمة المرور الجديدة (10+)</label>
    <input type="password" name="password" required minlength="10" autocomplete="new-password">
    <label>تأكيد / Confirm</label>
    <input type="password" name="password_confirm" required minlength="10" autocomplete="new-password">
    <button type="submit">حفظ / Save</button>
  </form>
  <?php endif; ?>
</div>
