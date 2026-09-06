<div class="card">
  <h1>التحقق الثنائي / 2FA</h1>
  <form method="post" action="/2fa/verify">
    <?= \Sameh\Security\Csrf::field() ?>
    <label>رمز التطبيق / Authenticator code</label>
    <input type="text" name="code" inputmode="numeric" required autocomplete="one-time-code" autofocus>
    <button type="submit">تحقق / Verify</button>
  </form>
</div>
