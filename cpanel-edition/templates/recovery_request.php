<div class="card">
  <h1>استعادة كلمة المرور / Password recovery</h1>
  <p style="color:var(--muted);font-size:0.9rem;">للمالك فقط. لن نؤكد إن كان البريد موجوداً. / Owner-only. Opaque response (no email enumeration).</p>
  <form method="post" action="/recovery">
    <?= \Sameh\Security\Csrf::field() ?>
    <label>البريد / Email</label>
    <input type="email" name="email" required autocomplete="username">
    <button type="submit">إرسال رابط الاستعادة / Send reset link</button>
  </form>
  <p style="margin-top:1rem;"><a href="/login">عودة لتسجيل الدخول</a></p>
</div>
