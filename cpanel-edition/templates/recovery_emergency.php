<div class="card">
  <h1>استعادة طارئة / Emergency recovery</h1>
  <p style="color:var(--muted);font-size:0.85rem;">يتطلب ملف مفتاح على الخادم خارج public (config: emergency_recovery_key_file). لا يُعرض المفتاح في الواجهة.</p>
  <form method="post" action="/recovery/emergency">
    <?= \Sameh\Security\Csrf::field() ?>
    <label>مفتاح الطوارئ / Emergency key</label>
    <input type="password" name="emergency_key" required autocomplete="off">
    <label>كلمة مرور جديدة للمالك</label>
    <input type="password" name="password" required minlength="10" autocomplete="new-password">
    <button type="submit">استعادة / Recover</button>
  </form>
  <p style="margin-top:1rem;"><a href="/login">عودة</a></p>
</div>
