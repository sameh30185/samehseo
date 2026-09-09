<h1>المصنع / Factory</h1>
<?php if (empty($activeSite)): ?>
  <div class="flash error">اختر موقعاً نشطاً أولاً.</div>
<?php else: ?>
  <div class="card">
    <p>قوالب ذهبية عربية لصفحات شركات النقل. بوابات QA صارمة — الفشل العالي يحظر إنشاء خطة الإجراءات.</p>
    <form method="post" action="/factory/plan">
      <?= \Sameh\Security\Csrf::field() ?>
      <label>القالب</label>
      <select name="template_key" required>
        <?php foreach ($templates as $k => $label): ?>
          <option value="<?= \Sameh\App::e($k) ?>"><?= \Sameh\App::e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <label>العنوان</label>
      <input type="text" name="title" required>
      <label>Slug</label>
      <input type="text" name="slug" placeholder="optional">
      <label>النية / Intent</label>
      <input type="text" name="intent" placeholder="مثال: نقل عفش الرياض">
      <label>محتوى HTML (اختياري — إن فُرغ يُستخدم القالب الذهبي)</label>
      <textarea name="content" rows="6" placeholder="اتركه فارغاً للقالب الذهبي"></textarea>
      <button type="submit">Outline → توليد → QA → خطة مسودة</button>
    </form>
    <form method="post" action="/factory/mission" style="display:inline;margin-top:0.75rem;">
      <?= \Sameh\Security\Csrf::field() ?>
      <button type="submit" class="secondary">مهمة محتوى</button>
    </form>
  </div>
  <?php if (!empty($factoryPlans)): ?>
    <div class="card">
      <h2>خطط المصنع</h2>
      <ul>
        <?php foreach ($factoryPlans as $fp): ?>
          <li>#<?= (int)$fp['id'] ?> <?= \Sameh\App::e($fp['title']) ?> — <?= \Sameh\App::e($fp['status']) ?> (<?= \Sameh\App::e($fp['template_key']) ?>)</li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
<?php endif; ?>
