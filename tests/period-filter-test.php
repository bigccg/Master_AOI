<?php
/**
 * Test Suite: Period Filter (งานที่ 1 — ฟิลเตอร์ "เดือน/ปี")
 * Tests:
 * 1. periodRange() helper (ALL, THIS, normal months, year-end boundary, invalid formats)
 * 2. periodOptions() helper on both SQLite and MySQL (ordering, counts, Thai labels, date boundaries)
 * 3. Range query edge cases (00:00:00 start, 23:59:59 end, Dec->Jan leap)
 * 4. boards.php API integration (table mode, matrix mode, period_options in JSON)
 * 5. export.php respect for period filter
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

function test_assert(string $desc, bool $condition, string $detail = ''): void {
    if ($condition) {
        echo "  PASS: $desc\n";
    } else {
        echo "  FAIL: $desc" . ($detail !== '' ? " ($detail)" : '') . "\n";
        exit(1);
    }
}

echo "Running Period Filter Tests...\n\n";

// ──────────────────────────────────────────────
// 1. periodRange() Logic Tests
// ──────────────────────────────────────────────
echo "1. periodRange() Helper:\n";

test_assert("periodRange('ALL') returns null", periodRange('ALL') === null);
test_assert("periodRange('') returns null", periodRange('') === null);
test_assert("periodRange('invalid') returns null", periodRange('invalid') === null);
test_assert("periodRange('2026') returns null", periodRange('2026') === null);
test_assert("periodRange('2026-13') returns null", periodRange('2026-13') === null);
test_assert("periodRange('2026-00') returns null", periodRange('2026-00') === null);

// Normal month
$r = periodRange('2026-10');
test_assert("periodRange('2026-10') returns 2026-10-01 to 2026-11-01",
    $r === ['2026-10-01 00:00:00', '2026-11-01 00:00:00']);

// January (start of year)
$rJan = periodRange('2026-01');
test_assert("periodRange('2026-01') returns 2026-01-01 to 2026-02-01",
    $rJan === ['2026-01-01 00:00:00', '2026-02-01 00:00:00']);

// December (year boundary ธ.ค. -> ม.ค.)
$rDec = periodRange('2026-12');
test_assert("periodRange('2026-12') crosses year boundary to 2027-01-01",
    $rDec === ['2026-12-01 00:00:00', '2027-01-01 00:00:00']);

// THIS month alias
$rThis = periodRange('THIS');
$expectedThis = periodRange(date('Y-m'));
test_assert("periodRange('THIS') resolves to current month range",
    $rThis === $expectedThis);

// ──────────────────────────────────────────────
// 2. SQLite Database Tests (In-Memory)
// ──────────────────────────────────────────────
echo "\n2. SQLite in-memory DB tests:\n";

if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $sqlitePdo = new PDO('sqlite::memory:');
    $sqlitePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $sqlitePdo->exec("
        CREATE TABLE boards (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            zone TEXT NOT NULL,
            sheet TEXT NOT NULL,
            code TEXT NOT NULL,
            shelf TEXT NOT NULL,
            shelf_no INTEGER NOT NULL,
            product TEXT NOT NULL,
            side TEXT NOT NULL,
            board_type TEXT NOT NULL,
            status TEXT NOT NULL,
            pcs_status TEXT,
            created_at TEXT NOT NULL
        )
    ");

    // Insert sample records across different months, edges, and year boundary
    $insertStmt = $sqlitePdo->prepare("
        INSERT INTO boards (zone, sheet, code, shelf, shelf_no, product, side, board_type, status, pcs_status, created_at)
        VALUES (:zone, :sheet, :code, :shelf, :shelf_no, :product, :side, :board_type, :status, :pcs_status, :created_at)
    ");

    $records = [
        ['General', 'B200_Gen', 'C-01', 'B1', 1, 'P1', 'F', 'ES3261A', 'G', '', '2026-09-15 12:00:00'],
        ['General', 'B200_Gen', 'C-02', 'B2', 2, 'P2', 'F', 'ES3261A', 'G', '', '2026-10-01 00:00:00'], // start of month
        ['General', 'B200_Gen', 'C-03', 'B3', 3, 'P3', 'F', 'ES3261A', 'D', '', '2026-10-15 08:30:00'],
        ['General', 'B200_Gen', 'C-04', 'B4', 4, 'P4', 'F', 'ES3261A', 'GD', '', '2026-10-31 23:59:59'], // end of month
        ['General', 'B200_Gen', 'C-05', 'B5', 5, 'P5', 'F', 'ES3261A', 'G', '', '2026-12-31 23:59:59'], // end of year
        ['General', 'B200_Gen', 'C-06', 'B6', 6, 'P6', 'F', 'ES3261A', 'G', '', '2027-01-01 00:00:00'], // new year
        ['AIXZ',    'X100',     'C-07', 'X1', 1, 'P7', 'F', 'ES3261A', 'G', '', '2026-10-10 10:00:00'], // other zone
    ];

    foreach ($records as $rec) {
        $insertStmt->execute([
            ':zone'       => $rec[0],
            ':sheet'      => $rec[1],
            ':code'       => $rec[2],
            ':shelf'      => $rec[3],
            ':shelf_no'   => $rec[4],
            ':product'    => $rec[5],
            ':side'       => $rec[6],
            ':board_type' => $rec[7],
            ':status'     => $rec[8],
            ':pcs_status' => $rec[9],
            ':created_at' => $rec[10],
        ]);
    }

    // Test periodOptions on SQLite
    $opts = periodOptions($sqlitePdo, 'General', 'B200_Gen');
    test_assert("SQLite periodOptions returned 4 distinct months", count($opts) === 4);
    test_assert("SQLite periodOptions ordered newest first",
        $opts[0]['value'] === '2027-01' && $opts[1]['value'] === '2026-12' && $opts[2]['value'] === '2026-10' && $opts[3]['value'] === '2026-09');
    test_assert("SQLite periodOptions 2026-10 count is 3 (includes 00:00:00 and 23:59:59)",
        $opts[2]['count'] === 3);
    test_assert("SQLite periodOptions Thai label for 2026-10 is 'ต.ค. 2026'",
        $opts[2]['label'] === 'ต.ค. 2026');
    test_assert("SQLite periodOptions Thai label for 2027-01 is 'ม.ค. 2027'",
        $opts[0]['label'] === 'ม.ค. 2027');
    test_assert("Month with no data (2026-11) is omitted",
        !in_array('2026-11', array_column($opts, 'value'), true));

    // Test date range query on SQLite for 2026-10
    $pRange = periodRange('2026-10');
    $q = $sqlitePdo->prepare("SELECT COUNT(*) FROM boards WHERE zone = 'General' AND created_at >= :from AND created_at < :to");
    $q->execute([':from' => $pRange[0], ':to' => $pRange[1]]);
    test_assert("SQLite query 2026-10 matches exactly 3 records", (int)$q->fetchColumn() === 3);

    // Test date range query for 2026-12 (does NOT include 2027-01-01 00:00:00)
    $pRangeDec = periodRange('2026-12');
    $q->execute([':from' => $pRangeDec[0], ':to' => $pRangeDec[1]]);
    test_assert("SQLite query 2026-12 matches 2026-12-31 23:59:59 and excludes 2027-01-01 00:00:00", (int)$q->fetchColumn() === 1);
} else {
    echo "  SKIP: pdo_sqlite driver not loaded in this PHP environment\n";
}

// ──────────────────────────────────────────────
// 3. MySQL Database Tests
// ──────────────────────────────────────────────
echo "\n3. MySQL Database tests:\n";

try {
    $mysqlPdo = db();
    test_assert("Connected to MySQL database", $mysqlPdo instanceof PDO);

    // Call periodOptions with both signatures
    $opts1 = periodOptions($mysqlPdo, 'General', 'ALL');
    $opts2 = periodOptions('General', 'ALL');
    test_assert("periodOptions works with both (PDO, zone, sheet) and (zone, sheet) signatures",
        json_encode($opts1) === json_encode($opts2));

    test_assert("periodOptions on MySQL executed without DATETIME conversion errors",
        is_array($opts1));

    if (!empty($opts1)) {
        test_assert("First option has value matching YYYY-MM format",
            (bool)preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $opts1[0]['value']));
        test_assert("First option has positive count",
            $opts1[0]['count'] > 0);
        test_assert("First option has Thai label with year",
            str_contains($opts1[0]['label'], '20'));
    }

    // Test period range filter query against real MySQL boards table
    $latestYm = !empty($opts1) ? $opts1[0]['value'] : '2026-10';
    $pRange = periodRange($latestYm);
    $st = $mysqlPdo->prepare("
        SELECT COUNT(*) FROM boards
        WHERE zone = :zone AND created_at >= :from AND created_at < :to
    ");
    $st->execute([':zone' => 'General', ':from' => $pRange[0], ':to' => $pRange[1]]);
    $matched = (int)$st->fetchColumn();
    test_assert("MySQL query with created_at >= AND < matches expected period count ($matched)",
        !empty($opts1) ? $matched === $opts1[0]['count'] : true);

} catch (Throwable $e) {
    test_assert("MySQL test error", false, $e->getMessage());
}

// ──────────────────────────────────────────────
// 4. API Response Tests
// ──────────────────────────────────────────────
echo "\n4. API boards.php Response tests:\n";

// Test boards.php with period filter via isolated CLI PHP run
$cmd1 = PHP_BINARY . ' -r ' . escapeshellarg('
    $_GET = ["zone" => "General", "sheet" => "B200_Gen", "period" => "2026-10"];
    require "' . __DIR__ . '/../api/boards.php";
');
$jsonOutput = shell_exec($cmd1);
$res = json_decode((string)$jsonOutput, true);
test_assert("api/boards.php returns valid JSON with period filter", is_array($res));
test_assert("api/boards.php includes period_options in JSON response", isset($res['period_options']) && is_array($res['period_options']));
test_assert("api/boards.php returned data array", isset($res['data']) && is_array($res['data']));

// Test Defect Matrix mode with period filter
$cmd2 = PHP_BINARY . ' -r ' . escapeshellarg('
    $_GET = ["zone" => "General", "sheet" => "ALL", "matrix" => "1", "period" => "2026-10"];
    require "' . __DIR__ . '/../api/boards.php";
');
$matrixJsonOutput = shell_exec($cmd2);
$matrixRes = json_decode((string)$matrixJsonOutput, true);
test_assert("api/boards.php in matrix mode returns valid JSON", is_array($matrixRes));
test_assert("api/boards.php matrix mode includes period_options in JSON", isset($matrixRes['period_options']) && is_array($matrixRes['period_options']));
test_assert("api/boards.php matrix mode returns success=true", ($matrixRes['success'] ?? false) === true);

// ──────────────────────────────────────────────
// 5. Export CSV Test with Period Filter
// ──────────────────────────────────────────────
echo "\n5. Export CSV with period filter tests:\n";

$cmd3 = PHP_BINARY . ' -r ' . escapeshellarg('
    $_GET = ["export" => "1", "zone" => "General", "sheet" => "B200_Gen", "period" => "2026-10"];
    require "' . __DIR__ . '/../index.php";
');
$csvOutput = shell_exec($cmd3);
test_assert("export CSV with period filter returns CSV content", is_string($csvOutput) && str_contains($csvOutput, 'Number Code'));
test_assert("export CSV includes 2026-10 records", str_contains((string)$csvOutput, '2026-10-'));

echo "\nAll Period Filter Tests Passed Successfully!\n";
