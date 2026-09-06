<h1>مركز القيادة / Command Center</h1>
<div class="grid">
  <div class="card stat">
    <div class="num"><?= (int)($sitesCount ?? 0) ?></div>
    <div class="lbl">المواقع / Sites</div>
  </div>
  <div class="card stat">
    <div class="num"><?= (int)($pendingCount ?? 0) ?></div>
    <div class="lbl">موافقات معلّقة / Pending approvals</div>
  </div>
  <div class="card stat">
    <div class="num"><?= !empty($killSwitch) ? 'ON' : 'OFF' ?></div>
    <div class="lbl">Kill Switch</div>
  </div>
</div>
<div class="card">
  <h2>حالة النظام</h2>
  <?php if ((int)($sitesCount ?? 0) === 0): ?>
    <p class="empty">لا مواقع بعد — <a href="/sites/add">أضف موقعاً</a> ثم ثبّت إضافة WordPress Connector.</p>
  <?php else: ?>
    <p>لديك <?= (int)$sitesCount ?> موقع/مواقع. افتح <a href="/sites">قائمة المواقع</a> للربط واكتشاف (Discover).</p>
  <?php endif; ?>
  <p style="color:var(--muted);font-size:0.9rem">لا أرقام وهمية — القيم من قاعدة البيانات فقط. Missions / Factory / Growth: NOT IMPLEMENTED في هذه الطبعة.</p>
</div>
