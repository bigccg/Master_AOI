<?php
/**
 * Flash message after a non-AJAX POST, plus the save errors includes/actions.php collects in $formErrors.
 */
?>
  <?php if ($flash): ?>
    <?php $flashOk = ($flash[0] === 'ok'); ?>
    <div class="no-print rounded-md border <?= $flashOk ? 'border-emerald-300 bg-emerald-50 text-emerald-800' : 'border-red-300 bg-red-50 text-red-700' ?> text-xs px-4 py-2.5 flex items-center gap-2">
      <i class="fa-solid <?= $flashOk ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i><?= h($flash[1]) ?>
    </div>
  <?php endif; ?>
  <?php foreach ($formErrors as $formError): ?>
    <div class="no-print rounded-md border border-red-300 bg-red-50 text-red-700 text-xs px-4 py-2.5 flex items-center gap-2" role="alert">
      <i class="fa-solid fa-circle-exclamation"></i><?= h($formError) ?>
    </div>
  <?php endforeach; ?>
