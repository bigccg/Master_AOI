<?php
/**
 * Stage Guide — a compact "how to read this table" strip shown above the data table.
 * Built from PROGRAM_STAGES / PROGRAM_STAGE_STATUS (config/database.php) so it never drifts
 * from the real pipeline. Collapsible; the open/closed choice is remembered per browser.
 */

declare(strict_types=1);

// Two user-selectable states (order = lifecycle)
$guideStatuses = [
    0 => ['cls' => 'is-idle',     'icon' => '<span class="prog-step-num">1</span>',                          'text' => 'ยังไม่ได้ทำ'],
    2 => ['cls' => 'is-complete', 'icon' => '<i class="fa-solid fa-check prog-step-icon"></i>',              'text' => 'เสร็จแล้ว'],
];
?>
<!-- 2.5 STAGE GUIDE -->
<section id="prog-stage-guide" class="prog-guide" aria-label="คู่มืออ่านสถานะ 4 ขั้นตอน">
  <button type="button" class="prog-guide-toggle" id="prog-guide-toggle" aria-expanded="true" aria-controls="prog-guide-body">
    <i class="fa-solid fa-route" aria-hidden="true"></i>
    <span class="prog-guide-toggle-title">วิธีอ่านสถานะ 4 ขั้นตอน</span>
    <span class="prog-guide-toggle-hint" id="prog-guide-hint">ซ่อน</span>
    <i class="fa-solid fa-chevron-up prog-guide-chevron" aria-hidden="true"></i>
  </button>

  <div class="prog-guide-body" id="prog-guide-body">
    <!-- Flow: 1 -> 2 -> 3 -> 4 -->
    <ol class="prog-guide-flow">
      <?php $lastStage = array_key_last(PROGRAM_STAGES); ?>
      <?php foreach (PROGRAM_STAGES as $n => $stage): ?>
        <li class="prog-guide-step">
          <span class="prog-guide-badge"><?= (int)$n ?></span>
          <div class="prog-guide-text">
            <strong><?= h($stage['label']) ?></strong>
            <span><?= h($stage['th']) ?></span>
          </div>
        </li>
        <?php if ($n !== $lastStage): ?>
          <li class="prog-guide-arrow" aria-hidden="true"><i class="fa-solid fa-chevron-right"></i></li>
        <?php endif; ?>
      <?php endforeach; ?>
    </ol>

    <!-- Legend + interaction hints -->
    <div class="prog-guide-foot">
      <div class="prog-guide-legend" aria-label="ความหมายของสัญลักษณ์">
        <?php foreach ($guideStatuses as $st): ?>
          <span class="prog-guide-legend-item">
            <span class="prog-step-cell <?= h($st['cls']) ?> prog-guide-sample" aria-hidden="true"><?= $st['icon'] ?></span>
            <?= h($st['text']) ?>
          </span>
        <?php endforeach; ?>
        <span class="prog-guide-legend-item">
          <span class="prog-step-cell is-idle is-next prog-guide-sample" aria-hidden="true"><span class="prog-step-num">2</span></span>
          ขอบเรืองแสง = ขั้นถัดไปที่ต้องทำ
        </span>
      </div>
      <p class="prog-guide-tips">
        <i class="fa-regular fa-hand-pointer" aria-hidden="true"></i>
        คลิกที่ช่องเพื่อสลับ ยังไม่ได้ทำ ⇄ เสร็จแล้ว (จะมีหน้าต่างให้ยืนยันก่อนบันทึกทุกครั้ง) ·
        เอาเมาส์ชี้ช่องเพื่อดูว่าใครทำและเมื่อไร
      </p>
    </div>
  </div>
</section>
