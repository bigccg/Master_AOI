<?php
/**
 * Page bootstrap: PHP state for JS + component scripts (order: includes/assets.php).
 */
?>
<!-- Bootstrap script for initial PHP state -->
<script>
  window.csrfToken = <?= json_encode(csrf()) ?>;
  window.isAdmin = <?= json_encode((bool)$isAdmin) ?>;
  window.initialZone = <?= json_encode($zone) ?>;
  window.initialSheet = <?= json_encode($sheet) ?>;
  window.currentNextCode = <?= json_encode($nextCode) ?>;
  // Zones + sheets from the database (topologyForJs()). JSON_HEX_TAG / _AMP: admin-entered labels can never close this <script>
  window.TOPOLOGY = <?= json_encode(topologyForJs(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  window.DEFECT_LABELS = <?= json_encode(DEFECT_LABELS, JSON_UNESCAPED_UNICODE) ?>;
  window.OTHER_NO_CLASS = <?= json_encode(OTHER_NO_CLASS) ?>;
  const PER_PAGE = <?= PER_PAGE ?>;
</script>

<!-- Must load AFTER the inline script above: core/utils.js reads window.csrfToken -->
<?php
  $jsFiles = JS_FILES;
  if ($isAdmin) {
      array_splice($jsFiles, -1, 0, ADMIN_JS_FILES);   // main.js stays last
  }
?>
<?php foreach ($jsFiles as $js): 
    $mtime = @filemtime(__DIR__ . '/../../assets/js/' . $js) ?: 1;
?>
<script src="assets/js/<?= h($js) ?>?v=<?= $mtime ?>"></script>
<?php endforeach; ?>
<script src="assets/js/components/search-fx.js?v=<?= @filemtime(__DIR__ . '/../../assets/js/components/search-fx.js') ?: 1 ?>" defer></script>
