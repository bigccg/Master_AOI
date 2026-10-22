<?php
/**
 * Test: Programs <-> Master Board link and per-field metadata audit
 *   1. syncProgramsFromMasterBoards() is idempotent and cheap (anti-join: nothing to do once synced)
 *   2. Only D / GD boards with side F-Side / B-Side create a program row (G, Bare/empty, legacy "Top" do not)
 *   3. Reading the list / KPIs never writes (no audit rows, no new programs)
 *   4. Editing owner + note in one call writes one audit row per field and each can be undone on its own
 * Run: DB_HOST=... DB_USER=... DB_PASS=... php tests/program-sync-test.php
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/programs-data.php';

$pdo = db();
$passed = 0;
$failed = 0;

function t(string $desc, bool $cond, string $detail = ''): void {
    global $passed, $failed;
    if ($cond) { $passed++; echo "  [PASS] $desc\n"; }
    else       { $failed++; echo "  [FAIL] $desc" . ($detail !== '' ? " -> $detail" : '') . "\n"; }
}

$tag    = 'SYNCT' . strtoupper(bin2hex(random_bytes(3)));
$models = ["$tag-D-0C", "$tag-GD-0C", "$tag-G-0C", "$tag-TOP-0C", "$tag-BARE-0C"];
$base   = 900000 + random_int(0, 50000);

function insertBoard(PDO $pdo, string $tag, int $no, string $product, string $side, string $status): void {
    $st = $pdo->prepare(
        'INSERT INTO boards (zone, sheet, code, shelf, shelf_no, mag_slot, product, side, board_type, status, pcs_status, defects, created_at)
         VALUES (:z, :s, :c, :sh, :n, NULL, :p, :side, :bt, :st, :pcs, :d, :now)'
    );
    $st->execute([
        ':z' => 'General', ':s' => 'A150_Gen', ':c' => "$tag-$no", ':sh' => "ZT$no", ':n' => $no,
        ':p' => $product, ':side' => $side, ':bt' => 'ES3261A', ':st' => $status, ':pcs' => '',
        ':d' => in_array($status, ['D', 'GD'], true) ? '1' : '', ':now' => date('Y-m-d H:i:s'),
    ]);
}

function countPrograms(PDO $pdo, string $tag): int {
    $st = $pdo->prepare('SELECT COUNT(*) FROM aoi_programs WHERE product_model LIKE :t');
    $st->execute([':t' => "$tag-%"]);
    return (int)$st->fetchColumn();
}

function countAudit(PDO $pdo): int {
    return (int)$pdo->query('SELECT COUNT(*) FROM aoi_program_audit')->fetchColumn();
}

echo "\n--- Programs <-> Master Board sync & metadata audit ---\n";

try {
    insertBoard($pdo, $tag, $base + 1, $models[0], 'F-Side', 'D');
    insertBoard($pdo, $tag, $base + 2, $models[1], 'B-Side', 'GD');
    insertBoard($pdo, $tag, $base + 3, $models[2], 'F-Side', 'G');       // Good only: must NOT create a program
    insertBoard($pdo, $tag, $base + 4, $models[3], 'Top',    'D');       // legacy side value: must NOT be guessed
    insertBoard($pdo, $tag, $base + 5, $models[4], 'F-Side', '');        // no status (Bare / Bad Mark)

    $added = syncProgramsFromMasterBoards($pdo);
    t('first sync creates exactly the 2 valid D / GD programs', countPrograms($pdo, $tag) === 2 && $added >= 2, "rows=" . countPrograms($pdo, $tag) . " added=$added");

    $again = syncProgramsFromMasterBoards($pdo);
    t('second sync adds nothing (idempotent)', $again === 0 && countPrograms($pdo, $tag) === 2, "added=$again");

    // Reads must not write
    $auditBefore = countAudit($pdo);
    $progBefore  = (int)$pdo->query('SELECT COUNT(*) FROM aoi_programs')->fetchColumn();
    fetchProgramList($pdo, ['search' => '', 'machine' => 'ALL', 'owner' => 'ALL', 'status' => 'ALL', 'stage' => 'ALL'], 'side', 'asc', 50, 0);
    fetchProgramKpis($pdo);
    t('list + KPI reads write no audit rows', countAudit($pdo) === $auditBefore);
    t('list + KPI reads create no programs', (int)$pdo->query('SELECT COUNT(*) FROM aoi_programs')->fetchColumn() === $progBefore);

    // Live Master Samples link
    $res = fetchProgramList($pdo, ['search' => $tag, 'machine' => 'ALL', 'owner' => 'ALL', 'status' => 'ALL', 'stage' => 'ALL'], 'product_model', 'asc', 50, 0);
    $byModel = [];
    foreach ($res['rows'] as $r) { $byModel[$r['product_model']] = $r; }
    t('D board shows master_d = 1, master_gd = 0', (int)($byModel[$models[0]]['master_d'] ?? -1) === 1 && (int)($byModel[$models[0]]['master_gd'] ?? -1) === 0);
    t('GD board shows master_gd = 1, master_d = 0', (int)($byModel[$models[1]]['master_gd'] ?? -1) === 1 && (int)($byModel[$models[1]]['master_d'] ?? -1) === 0);
    t('side filter keeps F-Side / B-Side only', ($byModel[$models[0]]['side'] ?? '') === 'F-Side' && ($byModel[$models[1]]['side'] ?? '') === 'B-Side');

    // Metadata audit: one row per changed field, each undoable
    $pid = (int)($byModel[$models[0]]['id'] ?? 0);
    $ver = (int)($byModel[$models[0]]['version'] ?? 1);
    $upd = updateProgramMeta($pdo, $pid, ['owner' => 'TESTER', 'note' => 'note-1'], 'Tester', $ver, '127.0.0.1');
    t('updateProgramMeta (owner + note) succeeds', ($upd['success'] ?? false) === true);

    $rows = $pdo->prepare("SELECT id, field_name FROM aoi_program_audit WHERE program_id = :p AND action = 'meta_update' ORDER BY id");
    $rows->execute([':p' => $pid]);
    $audits = $rows->fetchAll(PDO::FETCH_ASSOC);
    $fields = array_column($audits, 'field_name');
    t('one audit row per changed field (owner, note)', $fields === ['owner', 'note'], json_encode($fields));

    $ok = true;
    foreach (array_reverse($audits) as $a) {
        $u = undoProgramAudit($pdo, $pid, (int)$a['id'], 'Tester', '127.0.0.1');
        $ok = $ok && ($u['success'] ?? false) === true;
    }
    $cur = fetchProgramById($pdo, $pid);
    t('each field can be undone', $ok);
    t('undo restores empty owner and note', ($cur['owner'] ?? 'x') === '' && ($cur['note'] ?? 'x') === '', json_encode([$cur['owner'] ?? null, $cur['note'] ?? null]));

    // No-op edit must not write an audit row
    $cur = fetchProgramById($pdo, $pid);
    $a0 = countAudit($pdo);
    updateProgramMeta($pdo, $pid, ['owner' => (string)($cur['owner'] ?? '')], 'Tester', (int)$cur['version'], '127.0.0.1');
    t('unchanged value writes no audit row', countAudit($pdo) === $a0);
} finally {
    $pdo->prepare('DELETE FROM aoi_program_audit WHERE program_id IN (SELECT id FROM aoi_programs WHERE product_model LIKE :t)')->execute([':t' => "$tag-%"]);
    $pdo->prepare('DELETE FROM aoi_programs WHERE product_model LIKE :t')->execute([':t' => "$tag-%"]);
    $pdo->prepare('DELETE FROM boards WHERE code LIKE :t')->execute([':t' => "$tag-%"]);
}

echo "\nResult: $passed passed, $failed failed.\n";
exit($failed > 0 ? 1 : 0);
