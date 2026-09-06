document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var sel = btn.getAttribute('data-copy');
      var el = document.querySelector(sel);
      if (!el) return;
      var text = el.textContent || el.value || '';
      if (navigator.clipboard) {
        navigator.clipboard.writeText(text.trim());
        btn.textContent = 'تم النسخ / Copied';
        setTimeout(function () { btn.textContent = 'نسخ / Copy'; }, 1500);
      }
    });
  });
});
