<h1>مركز القيادة / Command Center</h1>
<div class="grid">
  <div class="card stat">
    <div class="num"><?= (int)($sitesCount ?? 0) ?></div>
    <div class="lbl">المواقع / Sites</div>
  </div>
  <div class="card stat">
    <div class="num"><?= (int)($missionsRunning ?? 0) ?></div>
    <div class="lbl">مهام نشطة / Active missions</div>
  </div>
  <div class="card stat">
    <div class="num"><?= (int)($pendingCount ?? 0) ?></div>
    <div class="lbl">موافقات معلّقة</div>
  </div>
  <div class="card stat">
    <div class="num"><?= (int)($localAiOnline ?? 0) ?></div>
    <div class="lbl">Local AI متصل</div>
  </div>
  <div class="card stat">
    <div class="num"><?= (int)($queueDepth ?? 0) ?></div>
    <div class="lbl">عمق طابور AI</div>
  </div>
  <div class="card stat">
    <div class="num"><?= !empty($killSwitch) ? 'ON' : 'OFF' ?></div>
    <div class="lbl">Kill Switch</div>
  </div>
</div>

<div class="card">
  <h2>ابدأ مهمة للشركة</h2>
  <?php if (empty($activeSite)): ?>
    <p class="empty">اختر موقعاً نشطاً أولاً من الشريط أو <a href="/sites/add">أضف موقعاً</a>.</p>
  <?php else: ?>
    <p>الموقع النشط: <strong><?= \Sameh\App::e($activeSite['name']) ?></strong></p>
    <p><a class="button" href="/missions">إنشاء مهمة جديدة ←</a>
       · <a href="/brain">عقل المشروع</a>
       · <a href="/factory">المصنع</a>
       · <a href="/growth">النمو</a></p>
  <?php endif; ?>
</div>

<div class="card">
  <h2>أحدث المهام</h2>
  <?php if (empty($recentMissions)): ?>
    <p style="color:var(--muted);">لا مهام بعد — القيم من قاعدة البيانات فقط (لا أرقام وهمية).</p>
  <?php else: ?>
    <ul>
      <?php foreach ($recentMissions as $m): ?>
        <li>
          <a href="/missions/<?= (int)$m['id'] ?>"><?= \Sameh\App::e($m['title']) ?></a>
          — <?= \Sameh\App::e(\Sameh\Missions\MissionService::statusLabelAr((string)$m['status'])) ?>
          <?php if (!empty($m['analysis_source'])): ?>
            <span class="badge"><?= $m['analysis_source'] === 'hermes' ? 'Hermes' : 'Rules-only' ?></span>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>
