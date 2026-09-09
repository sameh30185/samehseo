<h1><?= \Sameh\App::e($mission['title'] ?? '') ?></h1>
<p>
  #<?= (int)$mission['id'] ?> —
  نوع: <code><?= \Sameh\App::e($mission['type']) ?></code> —
  حالة: <strong><?= \Sameh\App::e($mission['status']) ?></strong>
  <?php if (!empty($activeSite)): ?>
    — موقع: <?= \Sameh\App::e($activeSite['name']) ?>
  <?php endif; ?>
</p>

<div class="card">
  <?php if (in_array($mission['status'], ['draft', 'failed'], true)): ?>
    <form method="post" action="/missions/<?= (int)$mission['id'] ?>/run" style="display:inline;">
      <?= \Sameh\Security\Csrf::field() ?>
      <label style="display:inline;margin-left:1rem;">
        <input type="checkbox" name="use_llm" value="1"> إثراء LLM إن كان مفعّلاً
      </label>
      <button type="submit">تشغيل التحقيق / Run investigation</button>
    </form>
  <?php endif; ?>
  <?php if (in_array($mission['status'], ['draft', 'running'], true)): ?>
    <form method="post" action="/missions/<?= (int)$mission['id'] ?>/cancel" style="display:inline;">
      <?= \Sameh\Security\Csrf::field() ?>
      <button type="submit" class="danger">إلغاء</button>
    </form>
  <?php endif; ?>
  <?php if (in_array($mission['status'], ['cancelled', 'failed'], true)): ?>
    <form method="post" action="/missions/<?= (int)$mission['id'] ?>/resume" style="display:inline;">
      <?= \Sameh\Security\Csrf::field() ?>
      <button type="submit">استئناف → مسودة</button>
    </form>
  <?php endif; ?>
  <p style="margin-top:0.75rem;"><a href="/missions">← قائمة المهام</a></p>
</div>

<?php if (!empty($mission['error_message'])): ?>
  <div class="flash error"><?= \Sameh\App::e($mission['error_message']) ?></div>
<?php endif; ?>

<?php if (!empty($mission['summary_ar'])): ?>
<div class="card">
  <h2>الملخص بالعربية</h2>
  <pre style="white-space:pre-wrap;font-family:inherit;"><?= \Sameh\App::e($mission['summary_ar']) ?></pre>
</div>
<?php endif; ?>

<?php if (!empty($findings)): ?>
<div class="card">
  <h2>النتائج / Findings (<?= count($findings) ?>)</h2>
  <ul>
    <?php foreach ($findings as $f): ?>
      <li>
        <strong>[<?= \Sameh\App::e($f['severity'] ?? 'info') ?>]</strong>
        <?= \Sameh\App::e($f['title'] ?? '') ?>
        <?php if (!empty($f['agent'])): ?><em>(<?= \Sameh\App::e($f['agent']) ?>)</em><?php endif; ?>
        <div style="color:var(--muted);font-size:0.9rem;"><?= \Sameh\App::e($f['detail'] ?? '') ?></div>
      </li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<?php if (!empty($runs)): ?>
<div class="card">
  <h2>تشغيلات الوكلاء / Agent runs</h2>
  <ul>
    <?php foreach ($runs as $r): ?>
      <li><?= \Sameh\App::e($r['agent_name']) ?> — <?= \Sameh\App::e($r['status']) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>
