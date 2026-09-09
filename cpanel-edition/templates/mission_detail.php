<?php
$statusAr = \Sameh\Missions\MissionService::statusLabelAr((string)($mission['status'] ?? ''));
$src = (string)($mission['analysis_source'] ?? 'rules');
$steps = ['draft' => 1, 'running' => 2, 'waiting_ai' => 2, 'decision_ready' => 3, 'completed' => 4, 'failed' => 2, 'cancelled' => 1];
$step = $steps[$mission['status'] ?? 'draft'] ?? 1;
?>
<?php if (in_array($mission['status'] ?? '', ['running', 'waiting_ai'], true)): ?>
<meta http-equiv="refresh" content="8">
<script>
(function(){
  async function poll(){
    try {
      const r = await fetch('/missions/<?= (int)$mission['id'] ?>/status.json', {credentials:'same-origin'});
      const j = await r.json();
      if (j && j.status && j.status !== '<?= \Sameh\App::e($mission['status']) ?>') {
        location.reload();
      }
    } catch(e) {}
  }
  setInterval(poll, 5000);
})();
</script>
<?php endif; ?>

<h1><?= \Sameh\App::e($mission['title'] ?? '') ?></h1>
<p>
  #<?= (int)$mission['id'] ?> —
  حالة: <strong><?= \Sameh\App::e($statusAr) ?></strong>
  — مصدر: <strong><?= $src === 'hermes' ? 'Hermes (طابور Local AI)' : 'Rules-only' ?></strong>
</p>

<div class="card">
  <h2>مسار المهمة</h2>
  <ol>
    <li style="<?= $step>=1?'font-weight:700':'' ?>">هدف وتعريف</li>
    <li style="<?= $step>=2?'font-weight:700':'' ?>">تحقيق الوكلاء <?= $src==='hermes'?'(Hermes queue)':'(Rules-only)' ?></li>
    <li style="<?= $step>=3?'font-weight:700':'' ?>">قرار → خطة إجراءات (معاينة ثم موافقة قبل أي تنفيذ WP)</li>
    <li style="<?= $step>=4?'font-weight:700':'' ?>">اكتمال</li>
  </ol>
  <?php if (!empty($mission['goal_text'])): ?>
    <p><strong>الهدف:</strong> <?= \Sameh\App::e($mission['goal_text']) ?></p>
  <?php endif; ?>
</div>

<div class="card">
  <?php if (in_array($mission['status'], ['draft', 'failed'], true)): ?>
    <form method="post" action="/missions/<?= (int)$mission['id'] ?>/run" style="display:inline;">
      <?= \Sameh\Security\Csrf::field() ?>
      <input type="hidden" name="analysis_source" value="<?= \Sameh\App::e($src) ?>">
      <label style="display:inline;margin-left:1rem;">
        <input type="checkbox" name="use_llm" value="1"> إثراء Cloud AI (إن كان مفعّلاً — منفصل عن Hermes)
      </label>
      <button type="submit">تشغيل التحقيق</button>
    </form>
  <?php endif; ?>
  <?php if (in_array($mission['status'], ['draft', 'running', 'waiting_ai'], true)): ?>
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
  <?php if (in_array($mission['status'], ['decision_ready', 'completed'], true)): ?>
    <form method="post" action="/missions/<?= (int)$mission['id'] ?>/action-plan" style="display:inline;">
      <?= \Sameh\Security\Csrf::field() ?>
      <button type="submit">إنشاء خطة إجراءات (مسودة فقط)</button>
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
  <h2>تشغيلات الوكلاء</h2>
  <ul>
    <?php foreach ($runs as $r): ?>
      <li><?= \Sameh\App::e($r['agent_name']) ?> — <?= \Sameh\App::e($r['status']) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>
