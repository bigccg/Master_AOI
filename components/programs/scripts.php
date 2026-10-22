<?php
/**
 * Bootstrap script for AOI Program Tracker
 * Passes PHP state to JS and loads PROGRAM_JS_FILES in order.
 */

declare(strict_types=1);
?>
<!-- Bootstrap PHP State to JavaScript -->
<script>
  window.csrfToken = <?= json_encode(csrf()) ?>;
  window.isAdmin = <?= json_encode((bool)$isAdmin) ?>;
  window.PROGRAM_STAGES = <?= json_encode(PROGRAM_STAGES, JSON_UNESCAPED_UNICODE) ?>;
  window.PROGRAM_MACHINES = <?= json_encode(PROGRAM_MACHINES, JSON_UNESCAPED_UNICODE) ?>;
  window.PROGRAM_STAGE_STATUS = <?= json_encode(PROGRAM_STAGE_STATUS, JSON_UNESCAPED_UNICODE) ?>;
  window.PROGRAM_SIDES = <?= json_encode(PROGRAM_SIDES, JSON_UNESCAPED_UNICODE) ?>;
</script>

<?php foreach (PROGRAM_JS_FILES as $js): 
    $mtime = @filemtime(__DIR__ . '/../../assets/js/' . $js) ?: 1;
?>
<script src="assets/js/<?= h($js) ?>?v=<?= $mtime ?>"></script>
<?php endforeach; ?>
