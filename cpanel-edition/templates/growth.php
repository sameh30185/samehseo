<h1>النمو / Growth</h1>
<?php if (empty($activeSite)): ?>
  <div class="flash error">اختر موقعاً نشطاً أولاً.</div>
<?php else: ?>
  <div class="card">
    <p>قائمة الفرص من الأدلة التفصيلية مؤجلة لـ <strong>Phase C</strong>.</p>
    <form method="post" action="/growth/mission">
      <?= \Sameh\Security\Csrf::field() ?>
      <button type="submit">إنشاء مهمة نمو / Create growth mission</button>
    </form>
  </div>
  <div class="card" style="opacity:0.7;">
    <button type="button" disabled title="Phase C">قائمة فرص متقدمة (معطّل — Phase C)</button>
  </div>
<?php endif; ?>
