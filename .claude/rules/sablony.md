---
paths:
  - "templates/**"
---
# Pravidla pro šablony
- Každý výpis proměnné přes `<?= e($x) ?>`, v atributech `<?= e_attr($x) ?>`.
- Jediná výjimka: `<?= $obsahHtml ?>` u článku vyrenderovaného sanitizovaným Markdownem — označit komentářem `{# bezpecne: sanitizovano #}`.
- Každý `<form method="post">` obsahuje `<?= csrf_field($csrfToken) ?>`.
- Žádný inline `style=` ani `onclick=` (CSP). Skripty jen `<script nonce="<?= e_attr($cspNonce) ?>">` nebo externí soubor.
