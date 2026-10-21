<?php
/**
 * <head>: meta, icons, fonts, Tailwind, component stylesheets (order: includes/assets.php).
 */
?>
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title><?= h($pageTitle ?? 'AOI Inspection & Shelf Management System | ระบบบันทึกและจัดการสต็อกบอร์ด AOI') ?></title>
<!-- Favicons & App Icons -->
<link rel="icon" type="image/x-icon" href="favicon.ico"/>
<link rel="icon" type="image/png" sizes="16x16" href="assets/icons/favicon-16x16.png"/>
<link rel="icon" type="image/png" sizes="32x32" href="assets/icons/favicon-32x32.png"/>
<link rel="icon" type="image/png" sizes="48x48" href="assets/icons/favicon-48x48.png"/>
<link rel="apple-touch-icon" sizes="180x180" href="assets/icons/apple-touch-icon.png"/>
<link rel="manifest" href="assets/icons/site.webmanifest"/>
<meta name="theme-color" content="#047857"/>
<!-- Fonts (Poppins / Inter / Prompt / JetBrains Mono) — local @font-face, referenced by tailwind.config below -->
<link rel="stylesheet" href="assets/css/fonts.css">
<link rel="stylesheet" href="assets/css/fonts-ui.css">
<!-- Preload Font Awesome webfont to prevent layout shift -->
<link rel="preload" href="assets/webfonts/fa-solid-900.woff2" as="font" type="font/woff2" crossorigin>
<!-- Tailwind CSS (local Play CDN build: includes forms + container-queries plugins) -->
<script src="assets/js/tailwindcss.js"></script>
<link rel="stylesheet" href="assets/css/fontawesome.min.css"/>
<script>
  tailwind.config = {
    darkMode: 'class',
    theme: { extend: {
      gridTemplateColumns: { '25': 'repeat(25, minmax(0, 1fr))' },
      fontFamily: {
        sans: ['Poppins', 'Inter', 'Prompt', 'system-ui', 'sans-serif'],
        mono: ['JetBrains Mono', 'Fira Code', 'monospace']
      }
    } }
  }
</script>
<?php foreach (CSS_FILES as $css): 
    $mtime = @filemtime(__DIR__ . '/../../assets/css/' . $css) ?: 1;
?>
<link rel="stylesheet" href="assets/css/<?= h($css) ?>?v=<?= $mtime ?>">
<?php endforeach; ?>
</head>
