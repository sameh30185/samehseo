<div class="card">
  <h1>تسجيل الدخول / Login</h1>
  <form method="post" action="/login">
    <?= \Sameh\Security\Csrf::field() ?>
    <label>البريد / Email</label>
    <input type="email" name="email" required autocomplete="username">
    <label>كلمة المرور / Password</label>
    <input type="password" name="password" required autocomplete="current-password">
    <button type="submit">دخول / Sign in</button>
  </form>
  <p style="margin-top:1rem;font-size:0.9rem;">
    <a href="/recovery">نسيت كلمة المرور؟ / Forgot password</a>
  </p>
</div>
