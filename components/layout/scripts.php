<?php
/**
 * Page bootstrap: PHP state for JS + component scripts (order: includes/assets.php).
 */
?>
<!-- Bootstrap script for initial PHP state -->
<script>
  window.csrfToken = <?= json_encode(csrf()) ?>;
  window.initialSheet = <?= json_encode($sheet) ?>;
  window.currentNextCode = <?= json_encode($nextCode) ?>;
  window.DEFECT_LABELS = <?= json_encode(DEFECT_LABELS, JSON_UNESCAPED_UNICODE) ?>;
  window.OTHER_NO_CLASS = <?= json_encode(OTHER_NO_CLASS) ?>;
  const PER_PAGE = <?= PER_PAGE ?>;
</script>

<!-- Must load AFTER the inline script above: core/utils.js reads window.csrfToken -->
<?php foreach (JS_FILES as $js): ?>
<script src="assets/js/<?= h($js) ?>"></script>
<?php endforeach; ?>
<script src="assets/js/components/search-fx.js" defer></script>
