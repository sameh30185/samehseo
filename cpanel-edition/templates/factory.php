<h1>المصنع / Factory</h1>
<?php if (empty($activeSite)): ?>
  <div class="flash error">اختر موقعاً نشطاً أولاً.</div>
<?php else: ?>
  <div class="card">
    <p>المخطط التفصيلي للمسودات (Draft planner) مؤجل لـ <strong>Phase C</strong>.</p>
    <p>يمكنك الآن إنشاء مهمة محتوى حقيقية من هنا:</p>
    <form method="post" action="/factory/mission">
      <?= \Sameh\Security\Csrf::field() ?>
      <button type="submit">إنشاء مهمة محتوى / Create content mission</button>
    </form>
  </div>
  <div class="card" style="opacity:0.7;">
    <button type="button" disabled title="Phase C — لم يُنفَّذ بعد">مولّد مسودات متقدم (معطّل — Phase C)</button>
  </div>
<?php endif; ?>
