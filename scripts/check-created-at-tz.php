<?php
/**
 * Created_at Timezone Boundary Check (read-only, CLI only)
 *
 * Checks for board records near month boundaries that could be affected by UTC vs Asia/Bangkok (+07:00) shifts:
 *   [1] Day 1 morning: 00:00:00 - 06:59:59 (If server was UTC, UTC would be previous month 17:00-23:59)
 *   [2] Month-end evening: 17:00:00 - 23:59:59 on the last day of month (If server was UTC, UTC 17:00+ is next month in Thai time)
 *
 * Only SELECTs: never changes data, never runs migrations.
 *
 *   php scripts/check-created-at-tz.php
 *   docker exec php_app_web php /var/www/html/aoi-web-php/scripts/check-created-at-tz.php
 *
 * Exit code: 0 = no boundary records, 2 = boundary records found (review needed), 1 = could not check.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("สคริปต์นี้รันได้จาก Command Line เท่านั้น / This script can only be run from the command line.\n");
}

require_once __DIR__ . '/../config/database.php';
session_destroy();

// ──────────────────────────────────────────────
// Output helpers
// ──────────────────────────────────────────────

function cellWidth(string $s): int
{
    return function_exists('mb_strwidth') ? mb_strwidth($s, 'UTF-8') : strlen($s);
}

function printTable(array $headers, array $rows, array $right = []): void
{
    $widths = array_map('cellWidth', $headers);
    foreach ($rows as $row) {
        foreach (array_values($row) as $i => $cell) {
            $widths[$i] = max($widths[$i], cellWidth((string)$cell));
        }
    }
    $line = static function (array $cells) use ($widths, $right): string {
        $out = [];
        foreach (array_values($cells) as $i => $cell) {
            $cell  = (string)$cell;
            $pad   = str_repeat(' ', $widths[$i] - cellWidth($cell));
            $out[] = in_array($i, $right, true) ? $pad . $cell : $cell . $pad;
        }
        return '    ' . rtrim(implode('  ', $out)) . PHP_EOL;
    };

    echo $line($headers);
    echo $line(array_map(static fn(int $w): string => str_repeat('-', $w), $widths));
    foreach ($rows as $row) {
        echo $line($row);
    }
}

// ──────────────────────────────────────────────
// Check
// ──────────────────────────────────────────────

echo "=================================================================" . PHP_EOL;
echo " ตรวจสอบเวลา created_at รอยต่อเดือน / created_at Timezone Boundary Check" . PHP_EOL;
echo " เขตเวลาปัจจุบัน / Active timezone: " . date_default_timezone_get() . " (" . CODE_TZ . ")" . PHP_EOL;
echo " เวลาที่ตรวจ / Checked at        : " . date('Y-m-d H:i:s') . PHP_EOL;
echo "=================================================================" . PHP_EOL . PHP_EOL;

try {
    $pdo = db(false);
} catch (Throwable $e) {
    echo "ERROR: ไม่สามารถเชื่อมต่อฐานข้อมูล / Could not connect to database: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

$st = $pdo->query("
    SELECT id, zone, sheet, code, shelf, product, created_at
    FROM boards
    WHERE created_at IS NOT NULL AND created_at > '1970-01-01'
    ORDER BY created_at ASC, id ASC
");
$allRows = $st->fetchAll();
$totalChecked = count($allRows);

$dayOneMorning   = [];
$monthEndEvening = [];

foreach ($allRows as $r) {
    $raw = (string)$r['created_at'];
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw, new DateTimeZone(CODE_TZ));
    if (!$dt) {
        continue;
    }

    $day     = (int)$dt->format('j');
    $hour    = (int)$dt->format('G');
    $lastDay = (int)$dt->format('t');

    // Window 1: Day 1 between 00:00:00 and 06:59:59
    if ($day === 1 && $hour >= 0 && $hour < 7) {
        $dayOneMorning[] = array_merge($r, ['window' => 'Day 1 (00:00-06:59)']);
    }

    // Window 2: Last day of month between 17:00:00 and 23:59:59
    if ($day === $lastDay && $hour >= 17 && $hour <= 23) {
        $monthEndEvening[] = array_merge($r, ['window' => "Last Day ($lastDay) 17:00-23:59"]);
    }
}

echo "จำนวนบอร์ดทั้งหมดที่ตรวจ / Total boards checked: $totalChecked แถว" . PHP_EOL . PHP_EOL;

// Report Window 1
echo "-----------------------------------------------------------------" . PHP_EOL;
echo " [1] บอร์ดที่บันทึกช่วง 00:00-06:59 ของวันที่ 1 ของเดือน" . PHP_EOL;
echo "     Day 1 (00:00-06:59) records: " . count($dayOneMorning) . " แถว" . PHP_EOL;
echo "-----------------------------------------------------------------" . PHP_EOL;

if (empty($dayOneMorning)) {
    echo "    (ไม่พบรายการในหน้าต่างเวลานี้ / None found)" . PHP_EOL . PHP_EOL;
} else {
    $headers = ['ID', 'Number Code', 'Zone', 'Sheet', 'Shelf', 'Product Model', 'Created At'];
    $tableRows = [];
    foreach ($dayOneMorning as $row) {
        $tableRows[] = [
            $row['id'],
            $row['code'],
            $row['zone'],
            $row['sheet'],
            $row['shelf'],
            $row['product'],
            $row['created_at'],
        ];
    }
    printTable($headers, $tableRows, [0]);
    echo PHP_EOL;
}

// Report Window 2
echo "-----------------------------------------------------------------" . PHP_EOL;
echo " [2] บอร์ดที่บันทึกช่วง 17:00-23:59 ของวันสุดท้ายของเดือน" . PHP_EOL;
echo "     Month-end (17:00-23:59) records: " . count($monthEndEvening) . " แถว" . PHP_EOL;
echo "-----------------------------------------------------------------" . PHP_EOL;

if (empty($monthEndEvening)) {
    echo "    (ไม่พบรายการในหน้าต่างเวลานี้ / None found)" . PHP_EOL . PHP_EOL;
} else {
    $headers = ['ID', 'Number Code', 'Zone', 'Sheet', 'Shelf', 'Product Model', 'Created At'];
    $tableRows = [];
    foreach ($monthEndEvening as $row) {
        $tableRows[] = [
            $row['id'],
            $row['code'],
            $row['zone'],
            $row['sheet'],
            $row['shelf'],
            $row['product'],
            $row['created_at'],
        ];
    }
    printTable($headers, $tableRows, [0]);
    echo PHP_EOL;
}

// Summary and recommendation
$totalAtRisk = count($dayOneMorning) + count($monthEndEvening);
echo "=================================================================" . PHP_EOL;
echo " สรุปผลการตรวจสอบ / Summary:" . PHP_EOL;
echo "   - วันที่ 1 (00:00-06:59) : " . count($dayOneMorning) . " รายการ" . PHP_EOL;
echo "   - สิ้นเดือน (17:00-23:59): " . count($monthEndEvening) . " รายการ" . PHP_EOL;
echo "   - รวมช่วงรอยต่อทั้งหมด    : $totalAtRisk รายการ" . PHP_EOL;
echo "=================================================================" . PHP_EOL;

if ($totalAtRisk === 0) {
    echo " ผลลัพธ์: ปลอดภัย ข้อมูลทั้งหมดไม่ได้อยู่ในช่วงรอยต่อเวลาที่เสี่ยงข้ามเดือน" . PHP_EOL;
    echo " Result : Clean. No records fall within timezone boundary windows." . PHP_EOL;
    exit(0);
} else {
    echo " ข้อสังเกต: พบข้อมูลในหน้าต่างรอยต่อเวลา หากในอดีตเซิร์ฟเวอร์รันด้วย UTC ข้อมูลข้างต้นอาจถูกนับต่างเดือน" . PHP_EOL;
    echo " Note    : Boundary records found. Review the list above to decide if past dates shifted." . PHP_EOL;
    exit(2);
}
