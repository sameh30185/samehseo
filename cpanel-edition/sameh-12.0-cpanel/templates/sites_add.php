<h1>إضافة موقع / Add Site</h1>
<div class="card">
  <form method="post" action="/sites/add">
    <?= \Sameh\Security\Csrf::field() ?>
    <label>الاسم / Name</label>
    <input type="text" name="name" required placeholder="مثال: المسار السريع">
    <label>رابط WordPress / Site URL</label>
    <input type="url" name="url" required placeholder="https://example.com">
    <p style="color:var(--muted)">الوضع الافتراضي: <strong>READ_ONLY</strong> — لا كتابة من Core في هذه الطبعة إلا عبر عمليات صريحة لاحقاً.</p>
    <button type="submit">حفظ / Save</button>
    <a class="btn secondary" href="/sites">إلغاء</a>
  </form>
</div>
