<h1>المصنع / Factory</h1>
<?php if (empty($activeSite)): ?>
  <div class="flash error">اختر موقعاً نشطاً أولاً.</div>
<?php else: ?>
  <div class="card">
    <h2>مخطط المسودات / Draft planner</h2>
    <p>ينشئ <strong>مسودات فقط</strong> عبر خطة إجراءات — لا نشر تلقائي.</p>
    <form method="post" action="/factory/plan">
      <?= \Sameh\Security\Csrf::field() ?>
      <label>القالب / Template</label>
      <select name="template_key" required>
        <?php foreach (($templates ?? []) as $k => $label): ?>
          <option value="<?= \Sameh\App::e($k) ?>"><?= \Sameh\App::e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <label>العنوان / Title</label>
      <input type="text" name="title" required maxlength="200">
      <label>Slug</label>
      <input type="text" name="slug" maxlength="200" placeholder="optional-slug">
      <label>النية / Intent (اختياري)</label>
      <input type="text" name="intent" maxlength="120">
      <label>المحتوى HTML (اختياري — يُكمَّل من القالب)</label>
      <textarea name="content" rows="6" placeholder="<p>...</p>"></textarea>
      <p style="color:var(--muted);font-size:0.9rem;">QA يفحص الشورت كود غير المتوازن وتكرار H1 قبل إنشاء الخطة.</p>
      <button type="submit">إنشاء خطة مسودة / Create draft plan</button>
    </form>
  </div>

  <div class="card">
    <form method="post" action="/factory/mission" style="display:inline;">
      <?= \Sameh\Security\Csrf::field() ?>
      <button type="submit">إنشاء مهمة محتوى / Create content mission</button>
    </form>
  </div>

  <?php if (!empty($factoryPlans)): ?>
  <div class="card">
    <h2>خطط المصنع الأخيرة</h2>
    <ul>
      <?php foreach ($factoryPlans as $fp): ?>
        <li>
          #<?= (int)$fp['id'] ?> — <?= \Sameh\App::e($fp['title']) ?>
          <code><?= \Sameh\App::e($fp['template_key']) ?></code>
          — <?= \Sameh\App::e($fp['status']) ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>
<?php endif; ?>
