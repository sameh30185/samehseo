<h1>التكاملات / Integrations</h1>
<p>لا مقاييس وهمية. إن لم يُعدّ التكامل يظهر «غير متصل» مع خطوات الإعداد.</p>

<div class="card">
  <h2>Google Search Console</h2>
  <?php if (empty($gsc['connected'])): ?>
    <p class="empty">غير متصل — <?= \Sameh\App::e($gsc['message'] ?? 'ارفع CSV من تقرير الأداء') ?></p>
    <ol>
      <li>من GSC صدّر تقرير الأداء كـ CSV</li>
      <li>الصق المحتوى أو ارفع الملف أدناه</li>
    </ol>
  <?php else: ?>
    <p>✅ مستورد: <?= (int)($gsc['row_count'] ?? 0) ?> صف — <?= \Sameh\App::e($gsc['imported_at'] ?? '') ?></p>
  <?php endif; ?>
  <?php if (!empty($activeSite)): ?>
  <form method="post" action="/integrations" enctype="multipart/form-data">
    <?= \Sameh\Security\Csrf::field() ?>
    <input type="hidden" name="form_action" value="gsc_csv">
    <label>CSV نصاً</label>
    <textarea name="csv_text" rows="4" placeholder="query,clicks,impressions,ctr,position"></textarea>
    <label>أو ملف CSV</label>
    <input type="file" name="csv_file" accept=".csv,text/csv">
    <button type="submit">استيراد CSV</button>
  </form>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Sitemap</h2>
  <?php if (empty($sitemap['connected'])): ?>
    <p class="empty">غير متصل — <?= \Sameh\App::e($sitemap['message'] ?? '') ?></p>
  <?php else: ?>
    <p>✅ <?= (int)($sitemap['count'] ?? 0) ?> رابط — <?= \Sameh\App::e($sitemap['fetched_at'] ?? '') ?></p>
  <?php endif; ?>
  <?php if (!empty($activeSite)): ?>
  <form method="post" action="/integrations">
    <?= \Sameh\Security\Csrf::field() ?>
    <input type="hidden" name="form_action" value="sitemap_fetch">
    <label>رابط sitemap.xml (HTTPS عام)</label>
    <input type="url" name="sitemap_url" placeholder="https://example.com/sitemap.xml" required>
    <button type="submit">جلب Sitemap</button>
  </form>
  <?php endif; ?>
</div>

<div class="card">
  <h2>GA4</h2>
  <p class="empty"><?= \Sameh\App::e($ga4['message'] ?? 'غير متصل') ?></p>
</div>

<div class="card">
  <h2>Google Ads</h2>
  <p class="empty"><?= \Sameh\App::e($ads['message'] ?? 'غير متصل') ?></p>
</div>

<div class="card">
  <h2>Research allowlist</h2>
  <?php if (empty($research['enabled'])): ?>
    <p class="empty">غير مفعّل — اضبط القائمة من الإعدادات.</p>
  <?php else: ?>
    <p>مفعّل للمضيفين: <?= \Sameh\App::e(implode(', ', $research['allowlist'] ?? [])) ?></p>
  <?php endif; ?>
</div>
