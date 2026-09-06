<h1>الإعدادات / Settings</h1>
<div class="card">
  <p>الإصدار: <code><?= \Sameh\App::e($version ?? '12.0.0-cpanel') ?></code></p>
  <form method="post" action="/settings">
    <?= \Sameh\Security\Csrf::field() ?>
    <label>
      <input type="checkbox" name="kill_switch" value="1" <?= !empty($killSwitch) ? 'checked' : '' ?>>
      Kill Switch العام — يوقف عمليات الربط/Health/Discover وإضافة المواقع
    </label>
    <button type="submit" class="danger">حفظ / Save</button>
  </form>
</div>
