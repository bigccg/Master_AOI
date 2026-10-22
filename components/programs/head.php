<?php
/**
 * Head component for AOI Program Tracker
 * Uses PROGRAM_CSS_FILES (isolated from Master Board CSS).
 */

declare(strict_types=1);
?>
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title><?= h($pageTitle ?? 'โปรแกรม AOI | AOI Program Tracker - Precision SMT Hub') ?></title>

<!-- Favicons & App Icons -->
<link rel="icon" type="image/x-icon" href="favicon.ico"/>
<link rel="icon" type="image/png" sizes="16x16" href="assets/icons/favicon-16x16.png"/>
<link rel="icon" type="image/png" sizes="32x32" href="assets/icons/favicon-32x32.png"/>
<link rel="icon" type="image/png" sizes="48x48" href="assets/icons/favicon-48x48.png"/>
<link rel="apple-touch-icon" sizes="180x180" href="assets/icons/apple-touch-icon.png"/>
<link rel="manifest" href="assets/icons/site.webmanifest"/>
<meta name="theme-color" content="#047857"/>

<!-- Offline Fonts (Prompt, Inter, JetBrains Mono) -->
<link rel="stylesheet" href="assets/css/fonts.css">
<link rel="stylesheet" href="assets/css/fonts-ui.css">

<!-- Preload Font Awesome webfont -->
<link rel="preload" href="assets/webfonts/fa-solid-900.woff2" as="font" type="font/woff2" crossorigin>

<!-- Local Tailwind CSS + Font Awesome -->
<script src="assets/js/tailwindcss.js"></script>
<link rel="stylesheet" href="assets/css/fontawesome.min.css"/>

<script>
  tailwind.config = {
    darkMode: 'class',
    theme: {
      extend: {
        fontFamily: {
          sans: ['Prompt', 'Inter', 'system-ui', 'sans-serif'],
          mono: ['JetBrains Mono', 'monospace'],
        },
        colors: {
          primary: {
            DEFAULT: '#059669',
            hover: '#047857',
            tint: '#f0fdf4',
          }
        }
      }
    }
  }
</script>

<?php foreach (PROGRAM_CSS_FILES as $css): 
    $mtime = @filemtime(__DIR__ . '/../../assets/css/' . $css) ?: 1;
?>
<link rel="stylesheet" href="assets/css/<?= h($css) ?>?v=<?= $mtime ?>">
<?php endforeach; ?>
</head>
