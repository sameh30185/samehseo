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
</div>
