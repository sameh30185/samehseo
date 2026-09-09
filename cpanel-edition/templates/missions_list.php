<h1>المهام / Missions</h1>
<?php if (empty($activeSite)): ?>
  <div class="flash error">اختر موقعاً نشطاً من الشريط الجانبي أو <a href="/sites">المواقع</a>.</div>
<?php else: ?>
  <p>الموقع النشط: <strong><?= \Sameh\App::e($activeSite['name']) ?></strong></p>

  <div class="card">
    <h2>① الهدف من الشركة</h2>
    <form method="post" action="/missions">
      <?= \Sameh\Security\Csrf::field() ?>
      <label>ماذا تريد من الشركة؟</label>
      <textarea name="goal" rows="3" required placeholder="مثال: راجع صفحات النقل في الرياض واقترح مسودات أحياء ناقصة دون نشر"></textarea>
      <label>نوع المهمة</label>
      <select name="type" required>
        <?php foreach ($types as $k => $label): ?>
          <option value="<?= \Sameh\App::e($k) ?>"><?= \Sameh\App::e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <label>مصدر التحليل</label>
      <select name="analysis_source">
        <option value="rules">Rules-only (حتمي — بدون Local AI)</option>
        <option value="hermes">Hermes عبر Local AI Worker (طابور)</option>
      </select>
      <label>عنوان مختصر (اختياري)</label>
      <input type="text" name="title" placeholder="يُشتق من الهدف إن تُرك فارغاً">
      <button type="submit">② إنشاء المهمة</button>
    </form>
  </div>

  <div class="card">
    <h2>③ بطاقات المهام</h2>
    <?php if (empty($missions)): ?>
      <p>لا مهام بعد.</p>
    <?php else: ?>
      <div class="grid">
        <?php foreach ($missions as $m): ?>
          <div class="card" style="margin:0;">
            <h3 style="margin-top:0;"><a href="/missions/<?= (int)$m['id'] ?>"><?= \Sameh\App::e($m['title']) ?></a></h3>
            <p>
              <span class="badge"><?= \Sameh\App::e(\Sameh\Missions\MissionService::statusLabelAr((string)$m['status'])) ?></span>
              <span class="badge"><?= (($m['analysis_source'] ?? 'rules') === 'hermes') ? 'Hermes' : 'Rules-only' ?></span>
            </p>
            <?php if (!empty($m['goal_text'])): ?>
              <p style="color:var(--muted);font-size:0.9rem;"><?= \Sameh\App::e(mb_substr((string)$m['goal_text'], 0, 160)) ?></p>
            <?php endif; ?>
            <p><a href="/missions/<?= (int)$m['id'] ?>">فتح الخطوة التالية →</a></p>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>
