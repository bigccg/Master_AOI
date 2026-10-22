<?php
/**
 * Zone & Sheet manager: rules, guards and writes behind api/topology.php (administrators only).
 * Every write runs in one transaction, re-checks every rule on the server (the browser copy of the rules is only
 * for instant feedback), writes one topology_audit row and returns the api/save.php result shape.
 *
 *   zone  create  key: letters / digits / _ (1-30, at least one letter), unique ignoring case; label required.
 *                 Starts without sheets (the existing "no sheets" empty state).
 *         update  label, short_label, sort_order, is_active - the key never changes.
 *         disable not DEFAULT_ZONE, and no enabled sheet left in it.
 *         delete  not DEFAULT_ZONE, no sheet and no board record stored with the zone.
 *   sheet create  in an enabled zone; key as above (not ALL) and shelf_prefix A-Z (1-3), both unique system-wide
 *                 (boards.shelf stores prefix + number); dot colour from SHEET_DOT_PALETTE; starts with 1 Magazine.
 *         update  label, dot_color, sort_order, is_active - key and shelf_prefix never change; enable needs an enabled zone.
 *         move    (zone_key) only while the sheet has no board record (records store their zone), into an enabled zone.
 *         delete  only while the sheet has no board record; otherwise disable it.
 * Board records, Number Codes and shelves are never changed here.
 */

declare(strict_types=1);

// Field rules, shared with the manager form through topologyManagerData()['rules'] (JS RegExp compatible)
const TOPOLOGY_KEY_PATTERN         = '^(?=[A-Za-z0-9_]*[A-Za-z])[A-Za-z0-9_]{1,30}$';
const TOPOLOGY_PREFIX_PATTERN      = '^[A-Z]{1,3}$';
const TOPOLOGY_LABEL_MAX           = 60;
const TOPOLOGY_SHORT_MAX           = 30;
const TOPOLOGY_ORDER_MAX           = 9999;
const TOPOLOGY_RESERVED_SHEET_KEYS = ['ALL'];   // the All Size Magazine tab

/**
 * UX copy (What happened + Why + How to fix, Thai / English). {name} placeholders are filled by topologyMessage();
 * the manager form gets the same templates (topologyManagerData()['copy']) for its instant checks.
 */
function topologyCopy(): array
{
    return [
        // ── fields ──
        'zone_key_required'   => 'กรุณากรอกรหัสโซน เนื่องจากลิงก์และข้อมูลบอร์ดใช้รหัสนี้อ้างอิงโซน (ตัวอักษรอังกฤษ ตัวเลข หรือ _ ไม่เกิน 30 ตัว) เช่น Plant2_SMT / Please enter a zone key: links and board records refer to the zone by it (letters, digits or _, up to 30), e.g. Plant2_SMT.',
        'sheet_key_required'  => 'กรุณากรอกรหัส Shelf เนื่องจากลิงก์และข้อมูลบอร์ดใช้รหัสนี้อ้างอิง Shelf (ตัวอักษรอังกฤษ ตัวเลข หรือ _ ไม่เกิน 30 ตัว) เช่น D400_Gen / Please enter a shelf key: links and board records refer to the shelf by it (letters, digits or _, up to 30), e.g. D400_Gen.',
        'key_format'          => 'รหัส "{key}" ใช้ไม่ได้ เนื่องจากใช้ได้เฉพาะตัวอักษรอังกฤษ ตัวเลข และขีดล่าง (_) 1–30 ตัว และต้องมีตัวอักษรอย่างน้อย 1 ตัว (ไม่มีช่องว่างหรือขีด -) กรุณาแก้ เช่น Plant2_SMT / Key "{key}" is not valid: use 1–30 English letters, digits or underscores with at least one letter (no spaces or dashes), e.g. Plant2_SMT.',
        'zone_key_taken'      => 'ใช้รหัสโซน "{key}" ไม่ได้ เนื่องจากมีโซน "{existing}" อยู่แล้ว (ระบบไม่แยกตัวพิมพ์เล็ก/ใหญ่) กรุณาใช้รหัสอื่น / Zone key "{key}" is taken by zone "{existing}" (keys ignore upper/lower case). Please choose another key.',
        'sheet_key_taken'     => 'ใช้รหัส Shelf "{key}" ไม่ได้ เนื่องจากมี Shelf "{existing}" ในโซน {zone} อยู่แล้ว (รหัส Shelf ต้องไม่ซ้ำทั้งระบบ และไม่แยกตัวพิมพ์เล็ก/ใหญ่) กรุณาใช้รหัสอื่น / Shelf key "{key}" is taken by shelf "{existing}" in zone {zone} (shelf keys are unique system-wide and ignore case). Please choose another key.',
        'sheet_key_reserved'  => 'ใช้รหัส Shelf "{key}" ไม่ได้ เนื่องจากระบบสงวนคำนี้ไว้สำหรับแท็บ ทุกขนาด (All Size Magazine) กรุณาใช้รหัสอื่น / Shelf key "{key}" is reserved for the All Size Magazine tab. Please choose another key.',
        'prefix_required'     => 'กรุณากรอก Shelf prefix เนื่องจากใช้ขึ้นต้นรหัสชั้นวางทุกช่องของ Shelf นี้ (ตัวอักษรอังกฤษพิมพ์ใหญ่ 1–3 ตัว) เช่น AX → AX1, AX2 / Please enter a shelf prefix: every shelf of this sheet starts with it (1–3 capital letters), e.g. AX → AX1, AX2.',
        'prefix_format'       => 'Shelf prefix "{prefix}" ใช้ไม่ได้ เนื่องจากต้องเป็นตัวอักษรอังกฤษ 1–3 ตัวเท่านั้น (ตัวเลขต่อท้ายระบบเติมให้เอง) กรุณาแก้ เช่น AX → AX1, AX2 / Shelf prefix "{prefix}" is not valid: use 1–3 English letters only (the system adds the number), e.g. AX → AX1, AX2.',
        'prefix_taken'        => 'ใช้ Shelf prefix "{prefix}" ไม่ได้ เนื่องจาก Shelf "{sheet}" ใช้อยู่แล้ว และรหัสชั้นวางต้องไม่ซ้ำทั้งระบบ กรุณาใช้ prefix อื่น / Shelf prefix "{prefix}" is already used by shelf "{sheet}"; shelf codes must be unique system-wide. Please choose another prefix.',
        'label_required'      => 'กรุณากรอกชื่อที่แสดง เนื่องจากใช้แสดงในตัวเลือกโซน แท็บ Shelf และข้อความต่าง ๆ (ไม่เกิน {max} ตัวอักษร) / Please enter a display name; it appears in the zone selector, shelf tabs and messages (up to {max} characters).',
        'label_too_long'      => 'ชื่อที่แสดงยาวเกินไป ({length}/{max} ตัวอักษร) กรุณาย่อให้ไม่เกิน {max} ตัวอักษร / The display name is too long ({length}/{max} characters). Please shorten it to {max}.',
        'short_too_long'      => 'ชื่อย่อยาวเกินไป ({length}/{max} ตัวอักษร) กรุณาย่อให้ไม่เกิน {max} ตัวอักษร หรือเว้นว่างไว้ / The short name is too long ({length}/{max} characters). Please shorten it to {max} or leave it empty.',
        'order_invalid'       => 'ลำดับไม่ถูกต้อง เนื่องจากต้องเป็นจำนวนเต็ม 0–{max} (เลขน้อยแสดงก่อน) กรุณาแก้ เช่น 10 หรือเว้นว่างให้ต่อท้าย / Invalid order: use a whole number 0–{max} (lower shows first), e.g. 10, or leave it empty to add at the end.',
        'dot_invalid'         => 'กรุณาเลือกสีของ Shelf จากชุดสีที่กำหนด เนื่องจากระบบรับเฉพาะสีในชุดนี้ / Please pick a tab colour from the palette; other values are not accepted.',
        'zone_required'       => 'กรุณาเลือกโซนของ Shelf เนื่องจากทุก Shelf ต้องอยู่ในโซนเดียว / Please choose the zone for this shelf; every shelf belongs to exactly one zone.',
        'target_zone_missing' => 'ใช้โซน "{zone}" ไม่ได้ เนื่องจากไม่พบโซนนี้ (อาจถูกลบไปแล้ว) กรุณาโหลดรายการใหม่แล้วเลือกโซนอีกครั้ง / Zone "{zone}" was not found (it may have been deleted). Reload the list and choose the zone again.',
        'target_zone_off'     => 'ใช้โซน "{zone}" ไม่ได้ เนื่องจากโซนนี้ปิดใช้งานอยู่ กรุณาเปิดใช้งานโซนก่อน หรือเลือกโซนอื่น / Zone "{zone}" is disabled. Enable the zone first, or choose another one.',
        'key_immutable'       => 'แก้รหัสไม่ได้ เนื่องจากข้อมูลบอร์ดและลิงก์ที่บันทึกไว้อ้างอิงรหัสนี้ หากต้องการรหัสใหม่ ให้สร้างรายการใหม่ / Keys cannot be changed because board records and saved links refer to them. Create a new one if you need a different key.',
        'prefix_immutable'    => 'แก้ Shelf prefix ไม่ได้ เนื่องจากทุกรายการเก็บรหัสชั้นวางเป็น prefix + เลข (เช่น {prefix}12) หากต้องการ prefix ใหม่ ให้สร้าง Shelf ใหม่ / The shelf prefix cannot be changed because every record stores its shelf as prefix + number (e.g. {prefix}12). Create a new shelf for a different prefix.',
        // ── state rules (also shown as reasons on disabled buttons) ──
        'default_zone_off'    => 'ปิดใช้งานไม่ได้ เพราะ {zone} เป็นโซนเริ่มต้นของระบบ (ลิงก์ที่ไม่ระบุโซนจะเปิดโซนนี้) / Cannot disable: {zone} is the default zone (links without a zone open it).',
        'default_zone_delete' => 'ลบไม่ได้ เพราะ {zone} เป็นโซนเริ่มต้นของระบบ / Cannot delete: {zone} is the default zone.',
        'zone_has_active'     => 'ปิดใช้งานไม่ได้ เพราะยังมี Shelf ที่เปิดใช้งาน {count} Shelf ({sheets}) ปิดใช้งานหรือย้าย Shelf เหล่านั้นก่อน / Cannot disable: {count} shelf/shelves are still active ({sheets}). Disable or move them first.',
        'zone_has_sheets'     => 'ลบไม่ได้ เพราะยังมี {count} Shelf ในโซนนี้ ลบหรือย้าย Shelf ออกก่อน หรือใช้ปิดใช้งานแทน / Cannot delete: the zone still has {count} shelf/shelves. Delete or move them first, or disable the zone instead.',
        'zone_has_records'    => 'ลบไม่ได้ เพราะมี {count} รายการที่บันทึกด้วยโซนนี้ ใช้ปิดใช้งานแทน / Cannot delete: {count} board record(s) are stored with this zone. Disable it instead.',
        'sheet_has_records'   => 'ลบไม่ได้ เพราะมี {count} รายการ ใช้ปิดใช้งานแทน / Cannot delete: {count} record(s) use this shelf. Disable it instead.',
        'sheet_move_records'  => 'ย้ายโซนไม่ได้ เพราะมี {count} รายการใน Shelf นี้ และแต่ละรายการบันทึกโซนไว้ด้วย ใช้ปิดใช้งาน Shelf นี้ แล้วสร้าง Shelf ใหม่ในโซนปลายทางแทน / Cannot move: {count} record(s) in this shelf store their zone. Disable this shelf and create a new one in the target zone instead.',
        'sheet_zone_off'      => 'เปิดใช้งานไม่ได้ เพราะโซน {zone} ปิดใช้งานอยู่ เปิดใช้งานโซนก่อน / Cannot enable: zone {zone} is disabled. Enable the zone first.',
        'zone_not_found'      => 'ไม่พบโซน "{key}" เนื่องจากอาจถูกลบไปแล้ว กรุณาโหลดรายการใหม่ / Zone "{key}" was not found; it may have been deleted. Please reload the list.',
        'sheet_not_found'     => 'ไม่พบ Shelf "{key}" เนื่องจากอาจถูกลบไปแล้ว กรุณาโหลดรายการใหม่ / Shelf "{key}" was not found; it may have been deleted. Please reload the list.',
        'taken_race'          => 'บันทึกไม่ได้ เนื่องจากผู้ดูแลอีกคนเพิ่งบันทึกรหัสหรือ prefix เดียวกันไปเมื่อสักครู่ กรุณาโหลดรายการใหม่แล้วใช้ค่าอื่น / Not saved: another administrator just saved the same key or prefix. Reload the list and choose another value.',
        'unknown_action'      => 'ทำรายการไม่ได้ เนื่องจากคำสั่งไม่ถูกต้อง กรุณาโหลดหน้าเว็บใหม่แล้วลองอีกครั้ง / Unknown action. Please reload the page and try again.',
        'db_error'            => 'บันทึกไม่สำเร็จ เนื่องจากฐานข้อมูลตอบกลับด้วยข้อผิดพลาด ยังไม่มีการเปลี่ยนแปลงใด ๆ กรุณาลองใหม่อีกครั้ง ({detail}) / Nothing was saved because the database returned an error. Please try again.',
        // ── results ──
        'nothing_changed'     => 'ไม่มีการเปลี่ยนแปลง เนื่องจากข้อมูลเหมือนเดิมทุกช่อง / Nothing changed: every field already had this value.',
        'zone_created'        => 'เพิ่มโซน {label} ({key}) เรียบร้อยแล้ว โซนนี้ยังไม่มี Shelf เพิ่ม Shelf แรกได้จากปุ่ม "เพิ่ม Shelf" / Zone {label} ({key}) added. It has no shelves yet: add the first one with "Add shelf".',
        'zone_updated'        => 'บันทึกโซน {label} ({key}) เรียบร้อยแล้ว / Zone {label} ({key}) saved.',
        'zone_disabled'       => 'ปิดใช้งานโซน {label} ({key}) แล้ว โซนนี้ไม่แสดงในตัวเลือกโซน ข้อมูลเดิมยังอยู่ครบ / Zone {label} ({key}) disabled. It is hidden from the zone selector; its records stay intact.',
        'zone_enabled'        => 'เปิดใช้งานโซน {label} ({key}) แล้ว / Zone {label} ({key}) enabled.',
        'zone_deleted'        => 'ลบโซน {label} ({key}) เรียบร้อยแล้ว / Zone {label} ({key}) deleted.',
        'sheet_created'       => 'เพิ่ม Shelf {label} ({key}) ในโซน {zone} เรียบร้อยแล้ว เริ่มต้นมี 1 Magazine (Shelf {prefix}1–{prefix}{size}) / Shelf {label} ({key}) added to zone {zone} with 1 Magazine (Shelf {prefix}1–{prefix}{size}).',
        'sheet_adopted'       => ' · พบข้อมูลบอร์ดเดิม {count} รายการที่ใช้รหัส Shelf นี้อยู่แล้ว / {count} existing board record(s) already use this shelf key.',
        'sheet_updated'       => 'บันทึก Shelf {label} ({key}) เรียบร้อยแล้ว / Shelf {label} ({key}) saved.',
        'sheet_moved'         => 'ย้าย Shelf {label} ({key}) ไปโซน {zone} เรียบร้อยแล้ว / Shelf {label} ({key}) moved to zone {zone}.',
        'sheet_disabled'      => 'ปิดใช้งาน Shelf {label} ({key}) แล้ว Shelf นี้ไม่แสดงในแท็บและเพิ่มข้อมูลใหม่ไม่ได้ ข้อมูลเดิมยังอยู่ครบ / Shelf {label} ({key}) disabled. It is hidden from the tabs and takes no new records; existing records stay intact.',
        'sheet_enabled'       => 'เปิดใช้งาน Shelf {label} ({key}) แล้ว / Shelf {label} ({key}) enabled.',
        'sheet_deleted'       => 'ลบ Shelf {label} ({key}) เรียบร้อยแล้ว / Shelf {label} ({key}) deleted.',
        'sheet_reordered'     => 'จัดลำดับ Shelf เรียบร้อยแล้ว / Shelf tabs reordered successfully.',
    ];
}

function topologyMessage(string $code, array $params = []): string
{
    $text = topologyCopy()[$code] ?? $code;
    foreach ($params as $name => $value) {
        $text = str_replace('{' . $name . '}', (string)$value, $text);
    }
    return $text;
}

// ──────────────────────────────────────────────
// Manager data (GET api/topology.php and every write response)
// ──────────────────────────────────────────────

/** Board record counts by lower-cased sheet / zone value (keys are unique ignoring case) */
function topologyRecordCounts(PDO $pdo, string $column): array
{
    $out = [];
    foreach ($pdo->query("SELECT $column AS k, COUNT(*) AS c FROM boards GROUP BY $column") as $r) {
        $k = strtolower((string)$r['k']);
        $out[$k] = ($out[$k] ?? 0) + (int)$r['c'];
    }
    return $out;
}

/** Why a zone action is not allowed right now (null = allowed); the writes below apply the same rules */
function zoneGuards(string $key, array $activeSheets, int $sheetCount, int $records): array
{
    $deactivate = null;
    if ($key === DEFAULT_ZONE) {
        $deactivate = topologyMessage('default_zone_off', ['zone' => $key]);
    } elseif ($activeSheets) {
        $deactivate = topologyMessage('zone_has_active', ['count' => count($activeSheets), 'sheets' => implode(', ', $activeSheets)]);
    }
    $delete = null;
    if ($key === DEFAULT_ZONE) {
        $delete = topologyMessage('default_zone_delete', ['zone' => $key]);
    } elseif ($sheetCount > 0) {
        $delete = topologyMessage('zone_has_sheets', ['count' => $sheetCount]);
    } elseif ($records > 0) {
        $delete = topologyMessage('zone_has_records', ['count' => $records]);
    }
    return ['deactivate' => $deactivate, 'activate' => null, 'delete' => $delete];
}

function sheetGuards(int $records, string $zone, bool $zoneActive): array
{
    return [
        'deactivate' => null,
        'activate'   => $zoneActive ? null : topologyMessage('sheet_zone_off', ['zone' => $zone]),
        'delete'     => $records > 0 ? topologyMessage('sheet_has_records', ['count' => $records]) : null,
        'move'       => $records > 0 ? topologyMessage('sheet_move_records', ['count' => $records]) : null,
    ];
}

/** Everything the manager screen shows: zones with their sheets, record counts, guards, palette, rules and copy */
function topologyManagerData(PDO $pdo): array
{
    $topo         = topology($pdo);
    $sheetRecords = topologyRecordCounts($pdo, 'sheet');
    $zoneRecords  = topologyRecordCounts($pdo, 'zone');

    $zones = [];
    $sheetTotal = 0;
    foreach ($topo['zones'] as $zKey => $z) {
        $zKey   = (string)$zKey;
        $sheets = [];
        $active = [];
        foreach ($z['sheets'] as $shKey) {
            $sh      = $topo['sheets'][$shKey];
            $records = $sheetRecords[strtolower((string)$shKey)] ?? 0;
            if ($sh['is_active']) {
                $active[] = (string)$shKey;
            }
            $sheets[] = [
                'key'        => (string)$shKey,
                'label'      => $sh['label'],
                'prefix'     => $sh['prefix'],
                'dot'        => $sh['dot'],
                'sort_order' => $sh['sort_order'],
                'is_active'  => $sh['is_active'],
                'records'    => $records,
                'magazines'  => magazineCount($pdo, (string)$shKey),
                'guards'     => sheetGuards($records, $zKey, $z['is_active']),
            ];
        }
        $sheetTotal += count($sheets);
        $records = $zoneRecords[strtolower($zKey)] ?? 0;
        $zones[] = [
            'key'          => $zKey,
            'label'        => $z['label'],
            'short_label'  => $z['short_label'],
            'sort_order'   => $z['sort_order'],
            'is_active'    => $z['is_active'],
            'is_default'   => $zKey === DEFAULT_ZONE,
            'records'      => $records,
            'active_sheets'=> count($active),
            'sheets'       => $sheets,
            'guards'       => zoneGuards($zKey, $active, count($sheets), $records),
        ];
    }

    $palette = [];
    foreach (SHEET_DOT_PALETTE as $value => $label) {
        $palette[] = ['value' => $value, 'label' => $label];
    }

    return [
        'zones'        => $zones,
        'totals'       => ['zones' => count($zones), 'sheets' => $sheetTotal, 'records' => array_sum($zoneRecords)],
        'default_zone' => DEFAULT_ZONE,
        'palette'      => $palette,
        'rules'        => [
            'key_pattern'         => TOPOLOGY_KEY_PATTERN,
            'prefix_pattern'      => TOPOLOGY_PREFIX_PATTERN,
            'label_max'           => TOPOLOGY_LABEL_MAX,
            'short_max'           => TOPOLOGY_SHORT_MAX,
            'order_max'           => TOPOLOGY_ORDER_MAX,
            'reserved_sheet_keys' => TOPOLOGY_RESERVED_SHEET_KEYS,
            'magazine_size'       => MAGAZINE_SIZE,
        ],
        'copy'         => topologyCopy(),
    ];
}

// ──────────────────────────────────────────────
// Input checks
// ──────────────────────────────────────────────

/** Trimmed string field, or null when absent (arrays are treated as absent) */
function topologyField(array $in, string $name): ?string
{
    if (!array_key_exists($name, $in) || !is_scalar($in[$name])) {
        return null;
    }
    return trim((string)$in[$name]);
}

/** Display text: control characters removed, inner whitespace collapsed */
function topologyLabel(?string $value): ?string
{
    if ($value === null) {
        return null;
    }
    $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
    return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
}

function topologyKeyError(string $type, string $key): ?string
{
    if ($key === '') {
        return topologyMessage($type . '_key_required');
    }
    if (!preg_match('/' . TOPOLOGY_KEY_PATTERN . '/', $key)) {
        return topologyMessage('key_format', ['key' => $key]);
    }
    if ($type === 'sheet' && in_array(strtoupper($key), TOPOLOGY_RESERVED_SHEET_KEYS, true)) {
        return topologyMessage('sheet_key_reserved', ['key' => $key]);
    }
    return null;
}

function topologyLabelError(?string $label, bool $required, int $max, string $tooLongCode): ?string
{
    if ($label === null || $label === '') {
        return $required ? topologyMessage('label_required', ['max' => $max]) : null;
    }
    $length = mb_strlen($label);
    return $length > $max ? topologyMessage($tooLongCode, ['length' => $length, 'max' => $max]) : null;
}

/** sort_order field: [int|null (null = absent / blank = add at the end), error|null] */
function topologyOrder(array $in): array
{
    $raw = topologyField($in, 'sort_order');
    if ($raw === null || $raw === '') {
        return [null, null];
    }
    if (!preg_match('/^\d{1,4}$/', $raw) || (int)$raw > TOPOLOGY_ORDER_MAX) {
        return [null, topologyMessage('order_invalid', ['max' => TOPOLOGY_ORDER_MAX])];
    }
    return [(int)$raw, null];
}

/** is_active field: true / false, or null when absent */
function topologyActiveFlag(array $in): ?bool
{
    $raw = $in['is_active'] ?? null;
    if ($raw === null || !is_scalar($raw)) {
        return null;
    }
    return in_array(strtolower(trim((string)$raw)), ['1', 'true', 'on', 'yes'], true);
}

// ──────────────────────────────────────────────
// Writes
// ──────────────────────────────────────────────

function topologyResult(bool $ok, int $status, string $message = '', array $errors = [], array $fieldErrors = []): array
{
    return ['success' => $ok, 'status_code' => $status, 'message' => $message, 'errors' => $errors, 'field_errors' => $fieldErrors];
}

function topologyFail(int $status, array $fieldErrors = [], array $errors = []): array
{
    return topologyResult(false, $status, '', $errors ?: array_values($fieldErrors), $fieldErrors);
}

/** Row lock for the transaction (MySQL); the SQLite test branch serialises writes anyway */
function topologyLock(PDO $pdo): string
{
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
}

function topologyRow(PDO $pdo, string $table, string $key): ?array
{
    $col = $table === 'zones' ? 'zone_key' : 'sheet_key';
    $st  = $pdo->prepare("SELECT * FROM $table WHERE $col = ?" . topologyLock($pdo));
    $st->execute([$key]);
    $row = $st->fetch();
    return $row ?: null;
}

/** Existing key equal to $key ignoring case, or null */
function topologyTakenKey(PDO $pdo, string $table, string $key): ?array
{
    $col = $table === 'zones' ? 'zone_key' : 'sheet_key';
    $st  = $pdo->prepare("SELECT * FROM $table WHERE LOWER($col) = LOWER(?)");
    $st->execute([$key]);
    $row = $st->fetch();
    return $row ?: null;
}

function topologyCount(PDO $pdo, string $sql, array $params): int
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (int)$st->fetchColumn();
}

/** Next sort_order at the end of a list (step 10, like the seed) */
function topologyNextOrder(PDO $pdo, string $table, ?string $zone = null): int
{
    $sql = "SELECT COALESCE(MAX(sort_order), 0) FROM $table" . ($zone !== null ? ' WHERE zone_key = ?' : '');
    return min(TOPOLOGY_ORDER_MAX, topologyCount($pdo, $sql, $zone !== null ? [$zone] : []) + 10);
}

function topologyAudit(PDO $pdo, string $action, string $type, string $key, ?array $before, ?array $after, string $ip): void
{
    $json = static fn(?array $row): ?string => $row === null ? null : json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pdo->prepare('INSERT INTO topology_audit (action, entity_type, entity_key, before_json, after_json, ip, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$action, $type, $key, $json($before), $json($after), substr($ip, 0, 45), date('Y-m-d H:i:s')]);
}

/**
 * Run $work inside one transaction: commit when it reports success, roll back otherwise or on any exception.
 * A unique-key clash from a parallel request is answered like the duplicate check it slipped past.
 */
function topologyTransaction(PDO $pdo, callable $work): array
{
    $pdo->beginTransaction();
    try {
        $result = $work();
        if ($result['success']) {
            $pdo->commit();
            topology($pdo, true);
        } else {
            $pdo->rollBack();
        }
        return $result;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $msg   = $e->getMessage();
        $isDup = (($e->errorInfo[1] ?? 0) === 1062) || str_contains($msg, 'UNIQUE constraint failed');
        if ($isDup) {
            $field = str_contains($msg, 'shelf_prefix') ? 'shelf_prefix' : (str_contains($msg, 'sheet') ? 'sheet_key' : 'zone_key');
            return topologyFail(422, [$field => topologyMessage('taken_race')]);
        }
        return topologyFail(500, [], [topologyMessage('db_error', ['detail' => $msg])]);
    }
}

function createZone(PDO $pdo, array $in, string $ip): array
{
    $key   = topologyField($in, 'zone_key') ?? '';
    $label = topologyLabel(topologyField($in, 'label'));
    $short = topologyLabel(topologyField($in, 'short_label')) ?? '';
    [$order, $orderError] = topologyOrder($in);

    // Auto-generate zone_key from label if omitted
    if ($key === '' && $label !== null && $label !== '') {
        $baseKey = preg_replace('/[^A-Za-z0-9_]+/', '_', $label);
        $baseKey = trim($baseKey, '_');
        if (!preg_match('/[A-Za-z]/', $baseKey)) {
            $baseKey = 'Zone';
        }
        $baseKey = substr($baseKey, 0, 24);
        $key = $baseKey;
        $suffix = 1;
        while (topologyTakenKey($pdo, 'zones', $key)) {
            $key = substr($baseKey, 0, 20) . '_' . $suffix++;
        }
    }

    $fe = array_filter([
        'zone_key'    => topologyKeyError('zone', $key),
        'label'       => topologyLabelError($label, true, TOPOLOGY_LABEL_MAX, 'label_too_long'),
        'short_label' => topologyLabelError($short, false, TOPOLOGY_SHORT_MAX, 'short_too_long'),
        'sort_order'  => $orderError,
    ]);
    if ($fe) {
        return topologyFail(422, $fe);
    }

    return topologyTransaction($pdo, static function () use ($pdo, $key, $label, $short, $order, $ip): array {
        if ($taken = topologyTakenKey($pdo, 'zones', $key)) {
            return topologyFail(422, ['zone_key' => topologyMessage('zone_key_taken', ['key' => $key, 'existing' => $taken['zone_key']])]);
        }
        $row = [
            'zone_key' => $key, 'label' => $label, 'short_label' => $short,
            'sort_order' => $order ?? topologyNextOrder($pdo, 'zones'), 'is_active' => 1, 'created_at' => date('Y-m-d H:i:s'),
        ];
        $pdo->prepare('INSERT INTO zones (zone_key, label, short_label, sort_order, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute(array_values($row));
        topologyAudit($pdo, 'create', 'zone', $key, null, $row, $ip);
        return topologyResult(true, 201, topologyMessage('zone_created', ['label' => $label, 'key' => $key]));
    });
}

function updateZone(PDO $pdo, array $in, string $ip): array
{
    $key = topologyField($in, 'zone_key') ?? '';
    return topologyTransaction($pdo, static function () use ($pdo, $in, $key, $ip): array {
        $before = topologyRow($pdo, 'zones', $key);
        if ($before === null) {
            return topologyFail(404, [], [topologyMessage('zone_not_found', ['key' => $key])]);
        }
        $key = (string)$before['zone_key'];   // canonical case: lookups ignore case, the guards must not
        $after  = $before;
        $label  = topologyLabel(topologyField($in, 'label'));
        $short  = topologyLabel(topologyField($in, 'short_label'));
        $active = topologyActiveFlag($in);
        [$order, $orderError] = topologyOrder($in);

        $fe = array_filter([
            'label'       => $label !== null ? topologyLabelError($label, true, TOPOLOGY_LABEL_MAX, 'label_too_long') : null,
            'short_label' => $short !== null ? topologyLabelError($short, false, TOPOLOGY_SHORT_MAX, 'short_too_long') : null,
            'sort_order'  => $orderError,
        ]);
        if ($fe) {
            return topologyFail(422, $fe);
        }
        if ($label !== null) $after['label'] = $label;
        if ($short !== null) $after['short_label'] = $short;
        if ($order !== null) $after['sort_order'] = $order;
        if ($active !== null) $after['is_active'] = $active ? 1 : 0;

        $disabling = (int)$before['is_active'] === 1 && (int)$after['is_active'] === 0;
        if ($disabling) {
            $st = $pdo->prepare('SELECT sheet_key FROM sheets WHERE zone_key = ? AND is_active = 1 ORDER BY sort_order, sheet_key' . topologyLock($pdo));
            $st->execute([$key]);
            $guard = zoneGuards($key, $st->fetchAll(PDO::FETCH_COLUMN), 0, 0)['deactivate'];
            if ($guard !== null) {
                return topologyFail(409, [], [$guard]);
            }
        }

        $changed = array_diff_assoc(array_map('strval', $after), array_map('strval', $before));
        if (!$changed) {
            return topologyResult(true, 200, topologyMessage('nothing_changed'));
        }
        $pdo->prepare('UPDATE zones SET label = ?, short_label = ?, sort_order = ?, is_active = ? WHERE zone_key = ?')
            ->execute([$after['label'], $after['short_label'], $after['sort_order'], $after['is_active'], $key]);

        $enabling = (int)$before['is_active'] === 0 && (int)$after['is_active'] === 1;
        $action   = $disabling ? 'disable' : ($enabling ? 'enable' : 'update');
        topologyAudit($pdo, $action, 'zone', $key, $before, $after, $ip);
        $code = $disabling ? 'zone_disabled' : ($enabling ? 'zone_enabled' : 'zone_updated');
        return topologyResult(true, 200, topologyMessage($code, ['label' => $after['label'], 'key' => $key]));
    });
}

function deleteZone(PDO $pdo, array $in, string $ip): array
{
    $key = topologyField($in, 'zone_key') ?? '';
    return topologyTransaction($pdo, static function () use ($pdo, $key, $ip): array {
        $before = topologyRow($pdo, 'zones', $key);
        if ($before === null) {
            return topologyFail(404, [], [topologyMessage('zone_not_found', ['key' => $key])]);
        }
        $key = (string)$before['zone_key'];   // canonical case: lookups ignore case, the guards must not
        $sheets  = topologyCount($pdo, 'SELECT COUNT(*) FROM sheets WHERE zone_key = ?', [$key]);
        $records = topologyCount($pdo, 'SELECT COUNT(*) FROM boards WHERE LOWER(zone) = LOWER(?)', [$key]);
        $guard   = zoneGuards($key, [], $sheets, $records)['delete'];
        if ($guard !== null) {
            return topologyFail(409, [], [$guard]);
        }
        $pdo->prepare('DELETE FROM zones WHERE zone_key = ?')->execute([$key]);
        topologyAudit($pdo, 'delete', 'zone', $key, $before, null, $ip);
        return topologyResult(true, 200, topologyMessage('zone_deleted', ['label' => $before['label'], 'key' => $key]));
    });
}

/** Target zone of a sheet create / move: [row|null, error|null], locked for the transaction */
function topologyTargetZone(PDO $pdo, string $zone): array
{
    if ($zone === '') {
        return [null, topologyMessage('zone_required')];
    }
    $row = topologyRow($pdo, 'zones', $zone);
    if ($row === null) {
        return [null, topologyMessage('target_zone_missing', ['zone' => $zone])];
    }
    if ((int)$row['is_active'] !== 1) {
        return [null, topologyMessage('target_zone_off', ['zone' => $zone])];
    }
    return [$row, null];
}

function createSheet(PDO $pdo, array $in, string $ip): array
{
    $key    = topologyField($in, 'sheet_key') ?? '';
    $zone   = topologyField($in, 'zone_key') ?? '';
    $label  = topologyLabel(topologyField($in, 'label'));
    $prefix = strtoupper(topologyField($in, 'shelf_prefix') ?? '');
    $dot    = topologyField($in, 'dot_color') ?? '';
    [$order, $orderError] = topologyOrder($in);

    // Auto-generate sheet_key from label if omitted
    if ($key === '' && $label !== null && $label !== '') {
        $baseKey = preg_replace('/[^A-Za-z0-9_]+/', '_', $label);
        $baseKey = trim($baseKey, '_');
        if (!preg_match('/[A-Za-z]/', $baseKey)) {
            $baseKey = 'Sheet';
        }
        $baseKey = substr($baseKey, 0, 24);
        $key = $baseKey;
        $suffix = 1;
        while (topologyTakenKey($pdo, 'sheets', $key) || in_array(strtoupper($key), TOPOLOGY_RESERVED_SHEET_KEYS, true)) {
            $key = substr($baseKey, 0, 20) . '_' . $suffix++;
        }
    }

    // Auto-generate shelf_prefix from label if omitted
    if ($prefix === '' && $label !== null && $label !== '') {
        if (preg_match('/^[A-Za-z]{1,3}/', $label, $m)) {
            $prefix = strtoupper($m[0]);
        } else {
            $clean = preg_replace('/[^A-Za-z]/', '', $label);
            $prefix = strtoupper(substr($clean, 0, 2)) ?: 'A';
        }
    }

    if ($dot === '') {
        $dot = 'bg-lime-500';
    }

    $fe = array_filter([
        'sheet_key'    => topologyKeyError('sheet', $key),
        'zone_key'     => $zone === '' ? topologyMessage('zone_required') : null,
        'label'        => topologyLabelError($label, true, TOPOLOGY_LABEL_MAX, 'label_too_long'),
        'shelf_prefix' => $prefix === '' ? topologyMessage('prefix_required')
            : (preg_match('/' . TOPOLOGY_PREFIX_PATTERN . '/', $prefix) ? null : topologyMessage('prefix_format', ['prefix' => $prefix])),
        'dot_color'    => isset(SHEET_DOT_PALETTE[$dot]) ? null : topologyMessage('dot_invalid'),
        'sort_order'   => $orderError,
    ]);
    if ($fe) {
        return topologyFail(422, $fe);
    }

    return topologyTransaction($pdo, static function () use ($pdo, $key, $zone, $label, $prefix, $dot, $order, $ip): array {
        [$zoneRow, $zoneError] = topologyTargetZone($pdo, $zone);
        $fe = [];
        if ($zoneError !== null) {
            $fe['zone_key'] = $zoneError;
        }
        if ($taken = topologyTakenKey($pdo, 'sheets', $key)) {
            $fe['sheet_key'] = topologyMessage('sheet_key_taken', ['key' => $key, 'existing' => $taken['sheet_key'], 'zone' => $taken['zone_key']]);
        }
        // Shelf prefix is allowed to be shared across multiple sheets (e.g. CD200_Gen, CD250_Gen, CD300)
        if ($fe) {
            return topologyFail(422, $fe);
        }

        $row = [
            'sheet_key' => $key, 'zone_key' => $zoneRow['zone_key'], 'label' => $label, 'shelf_prefix' => $prefix,
            'dot_color' => $dot, 'sort_order' => $order ?? topologyNextOrder($pdo, 'sheets', $zoneRow['zone_key']),
            'is_active' => 1, 'created_at' => date('Y-m-d H:i:s'),
        ];
        $pdo->prepare('INSERT INTO sheets (sheet_key, zone_key, label, shelf_prefix, dot_color, sort_order, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(array_values($row));
        // A new sheet starts with 1 Magazine (no sheet_magazines row): drop a row left behind under the same key
        $pdo->prepare('DELETE FROM sheet_magazines WHERE LOWER(sheet) = LOWER(?)')->execute([$key]);
        topologyAudit($pdo, 'create', 'sheet', $key, null, $row, $ip);

        $message = topologyMessage('sheet_created', ['label' => $label, 'key' => $key, 'zone' => $zoneRow['zone_key'], 'prefix' => $prefix, 'size' => MAGAZINE_SIZE]);
        $adopted = topologyCount($pdo, 'SELECT COUNT(*) FROM boards WHERE LOWER(sheet) = LOWER(?)', [$key]);
        if ($adopted > 0) {
            $message .= topologyMessage('sheet_adopted', ['count' => $adopted]);
        }
        return topologyResult(true, 201, $message);
    });
}

function updateSheet(PDO $pdo, array $in, string $ip): array
{
    $key = topologyField($in, 'sheet_key') ?? '';
    return topologyTransaction($pdo, static function () use ($pdo, $in, $key, $ip): array {
        $before = topologyRow($pdo, 'sheets', $key);
        if ($before === null) {
            return topologyFail(404, [], [topologyMessage('sheet_not_found', ['key' => $key])]);
        }
        $key = (string)$before['sheet_key'];   // canonical case: lookups ignore case
        $after  = $before;
        $label  = topologyLabel(topologyField($in, 'label'));
        $dot    = topologyField($in, 'dot_color');
        $prefix = topologyField($in, 'shelf_prefix');
        $zone   = topologyField($in, 'zone_key');
        $active = topologyActiveFlag($in);
        [$order, $orderError] = topologyOrder($in);

        $fe = array_filter([
            'label'        => $label !== null ? topologyLabelError($label, true, TOPOLOGY_LABEL_MAX, 'label_too_long') : null,
            'dot_color'    => ($dot !== null && !isset(SHEET_DOT_PALETTE[$dot])) ? topologyMessage('dot_invalid') : null,
            'shelf_prefix' => ($prefix !== null && strtoupper($prefix) !== strtoupper((string)$before['shelf_prefix']))
                ? topologyMessage('prefix_immutable', ['prefix' => $before['shelf_prefix']]) : null,
            'sort_order'   => $orderError,
        ]);
        if ($fe) {
            return topologyFail(422, $fe);
        }
        if ($label !== null) $after['label'] = $label;
        if ($dot !== null) $after['dot_color'] = $dot;
        if ($order !== null) $after['sort_order'] = $order;
        if ($active !== null) $after['is_active'] = $active ? 1 : 0;

        // Move to another zone: only without records (every record stores its zone), into an enabled zone
        $moving = $zone !== null && $zone !== '' && strcasecmp($zone, (string)$before['zone_key']) !== 0;
        if ($moving) {
            $records = topologyCount($pdo, 'SELECT COUNT(*) FROM boards WHERE LOWER(sheet) = LOWER(?)', [$key]);
            if ($records > 0) {
                return topologyFail(409, [], [topologyMessage('sheet_move_records', ['count' => $records])]);
            }
            [$zoneRow, $zoneError] = topologyTargetZone($pdo, $zone);
            if ($zoneError !== null) {
                return topologyFail(422, ['zone_key' => $zoneError]);
            }
            $after['zone_key'] = $zoneRow['zone_key'];
            if ($order === null) {
                $after['sort_order'] = topologyNextOrder($pdo, 'sheets', $zoneRow['zone_key']);
            }
        }

        $enabling = (int)$before['is_active'] === 0 && (int)$after['is_active'] === 1;
        if ($enabling || ($moving && (int)$after['is_active'] === 1)) {
            $zoneRow = topologyRow($pdo, 'zones', (string)$after['zone_key']);
            if ($zoneRow === null || (int)$zoneRow['is_active'] !== 1) {
                return topologyFail(409, [], [topologyMessage('sheet_zone_off', ['zone' => $after['zone_key']])]);
            }
        }

        $changed = array_diff_assoc(array_map('strval', $after), array_map('strval', $before));
        if (!$changed) {
            return topologyResult(true, 200, topologyMessage('nothing_changed'));
        }
        $pdo->prepare('UPDATE sheets SET zone_key = ?, label = ?, dot_color = ?, sort_order = ?, is_active = ? WHERE sheet_key = ?')
            ->execute([$after['zone_key'], $after['label'], $after['dot_color'], $after['sort_order'], $after['is_active'], $key]);

        $disabling = (int)$before['is_active'] === 1 && (int)$after['is_active'] === 0;
        $action    = $moving ? 'move' : ($disabling ? 'disable' : ($enabling ? 'enable' : 'update'));
        topologyAudit($pdo, $action, 'sheet', $key, $before, $after, $ip);
        $code = $moving ? 'sheet_moved' : ($disabling ? 'sheet_disabled' : ($enabling ? 'sheet_enabled' : 'sheet_updated'));
        return topologyResult(true, 200, topologyMessage($code, ['label' => $after['label'], 'key' => $key, 'zone' => $after['zone_key']]));
    });
}

function deleteSheet(PDO $pdo, array $in, string $ip): array
{
    $key = topologyField($in, 'sheet_key') ?? '';
    return topologyTransaction($pdo, static function () use ($pdo, $key, $ip): array {
        $before = topologyRow($pdo, 'sheets', $key);
        if ($before === null) {
            return topologyFail(404, [], [topologyMessage('sheet_not_found', ['key' => $key])]);
        }
        $key = (string)$before['sheet_key'];   // canonical case: lookups ignore case
        $records = topologyCount($pdo, 'SELECT COUNT(*) FROM boards WHERE LOWER(sheet) = LOWER(?)', [$key]);
        if ($records > 0) {
            return topologyFail(409, [], [topologyMessage('sheet_has_records', ['count' => $records])]);
        }
        $pdo->prepare('DELETE FROM sheets WHERE sheet_key = ?')->execute([$key]);
        $pdo->prepare('DELETE FROM sheet_magazines WHERE LOWER(sheet) = LOWER(?)')->execute([$key]);
        topologyAudit($pdo, 'delete', 'sheet', $key, $before, null, $ip);
        return topologyResult(true, 200, topologyMessage('sheet_deleted', ['label' => $before['label'], 'key' => $key]));
    });
}

function reorderSheets(PDO $pdo, array $in, string $ip): array
{
    $zone = topologyField($in, 'zone_key') ?? '';
    $order = $in['order'] ?? [];
    if (!is_array($order)) {
        return topologyFail(400, [], ['รูปแบบข้อมูลการจัดลำดับไม่ถูกต้อง / Invalid order data.']);
    }

    return topologyTransaction($pdo, static function () use ($pdo, $zone, $order, $ip): array {
        $zoneRow = topologyRow($pdo, 'zones', $zone);
        if ($zoneRow === null) {
            return topologyFail(404, [], [topologyMessage('zone_not_found', ['key' => $zone])]);
        }
        $zoneKey = (string)$zoneRow['zone_key'];

        $stmt = $pdo->prepare('SELECT sheet_key, sort_order FROM sheets WHERE LOWER(zone_key) = LOWER(?) ORDER BY sort_order ASC, sheet_key ASC' . topologyLock($pdo));
        $stmt->execute([$zoneKey]);
        $existing = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($existing)) {
            return topologyResult(true, 200, topologyMessage('nothing_changed'));
        }

        $existingMap = [];
        foreach ($existing as $r) {
            $existingMap[strtolower((string)$r['sheet_key'])] = (string)$r['sheet_key'];
        }

        $sortedKeys = [];
        foreach ($order as $k) {
            if (!is_string($k)) {
                continue;
            }
            $lk = strtolower(trim($k));
            if ($lk === 'all') {
                continue;
            }
            if (isset($existingMap[$lk]) && !in_array($existingMap[$lk], $sortedKeys, true)) {
                $sortedKeys[] = $existingMap[$lk];
            }
        }
        // Append any existing sheets that weren't in order list
        foreach ($existingMap as $canonicalKey) {
            if (!in_array($canonicalKey, $sortedKeys, true)) {
                $sortedKeys[] = $canonicalKey;
            }
        }

        $updateStmt = $pdo->prepare('UPDATE sheets SET sort_order = ? WHERE sheet_key = ?');
        $step = 10;
        foreach ($sortedKeys as $sKey) {
            $updateStmt->execute([$step, $sKey]);
            $step += 10;
        }

        topologyAudit($pdo, 'reorder', 'sheet', $zoneKey, null, ['order' => $sortedKeys], $ip);
        return topologyResult(true, 200, topologyMessage('sheet_reordered', ['zone' => $zoneKey]));
    });
}

/** Dispatch one manager action (api/topology.php); the caller has already checked the admin session and CSRF */
function topologyApply(PDO $pdo, string $action, array $in, string $ip): array
{
    topology($pdo);
    return match ($action) {
        'zone_create'   => createZone($pdo, $in, $ip),
        'zone_update'   => updateZone($pdo, $in, $ip),
        'zone_delete'   => deleteZone($pdo, $in, $ip),
        'sheet_create'  => createSheet($pdo, $in, $ip),
        'sheet_update'  => updateSheet($pdo, $in, $ip),
        'sheet_delete'  => deleteSheet($pdo, $in, $ip),
        'sheet_reorder' => reorderSheets($pdo, $in, $ip),
        default         => topologyFail(400, [], [topologyMessage('unknown_action')]),
    };
}
