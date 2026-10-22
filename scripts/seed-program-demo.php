<?php
/**
 * Seed AOI Program Tracker with realistic demo data
 * CLI only - refuses web requests (403)
 *
 * Usage:
 *   php scripts/seed-program-demo.php
 *   docker exec -w /var/www/html/aoi-web-php php_app_web php scripts/seed-program-demo.php
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("สคริปต์นี้รันได้จาก Command Line เท่านั้น / This script can only be run from the command line.\n");
}

require_once __DIR__ . '/../config/database.php';
session_destroy();

$pdo = db();

echo "=== Seeding AOI Program Demo Data ===\n";

// Clear existing demo rows
$pdo->exec("DELETE FROM aoi_program_audit");
$pdo->exec("DELETE FROM aoi_programs");

$now = new DateTime('now', new DateTimeZone('Asia/Bangkok'));

// 60 Realistic records inspired by Excel & Stitch
$sampleData = [
    // 1. Complete rows
    ['prod' => 'NKSZ-288MW-0B', 'side' => 'F-Side', 'mach' => 'OMRON (2D)', 's1' => 2, 's2' => 2, 's3' => 2, 's4' => 2, 'owner' => 'SONGSREM', 'shelf' => 'CA_157', 'ok' => 'CAKZ-164MS-0A', 'days' => 15, 'note' => 'บอร์ดสมบูรณ์ พร้อมผลิต', 'reason' => ''],
    ['prod' => 'NKSZ-288MW-0B', 'side' => 'B-Side', 'mach' => 'OMRON (2D)', 's1' => 2, 's2' => 2, 's3' => 2, 's4' => 2, 'owner' => 'SONGSREM', 'shelf' => 'CA_158', 'ok' => 'CAWZ-041MW-4A', 'days' => 15, 'note' => 'พร้อมผลิต', 'reason' => ''],
    ['prod' => 'NKGZ-140MS-1B', 'side' => 'F-Side', 'mach' => 'OMRON (2D)', 's1' => 2, 's2' => 2, 's3' => 2, 's4' => 2, 'owner' => 'CHATCHAI', 'shelf' => 'CA_138', 'ok' => 'MIBZ-064MS-1B', 'days' => 12, 'note' => 'โปรแกรมผ่านเกณฑ์ 100%', 'reason' => ''],
    ['prod' => 'NKGZ-143MS-1B', 'side' => 'F-Side', 'mach' => 'OMRON (2D)', 's1' => 2, 's2' => 2, 's3' => 2, 's4' => 2, 'owner' => 'CHATCHAI', 'shelf' => 'CB_47', 'ok' => 'NKGZ-166MS-1C', 'days' => 11, 'note' => 'ส่งมอบไลน์ผลิตแล้ว', 'reason' => ''],
    ['prod' => 'NKGZ-202MS-1C', 'side' => 'F-Side', 'mach' => 'OMRON (2D)', 's1' => 2, 's2' => 2, 's3' => 2, 's4' => 2, 'owner' => 'Thanapol', 'shelf' => 'CA_145', 'ok' => 'NKSZ-230MW-5D', 'days' => 11, 'note' => 'Master บอร์ดครบสมบูรณ์', 'reason' => ''],
    ['prod' => 'NKSZ-300MW-0C', 'side' => 'F-Side', 'mach' => 'OMRON (2D)', 's1' => 2, 's2' => 2, 's3' => 2, 's4' => 2, 'owner' => 'SONGSREM', 'shelf' => 'CA_149', 'ok' => 'NKSZ-300MW-0B', 'days' => 14, 'note' => 'ผ่านการรับรอง', 'reason' => ''],
    ['prod' => 'NKGZ-169MS-0E', 'side' => 'F-Side', 'mach' => 'OMRON (2D)', 's1' => 2, 's2' => 2, 's3' => 2, 's4' => 2, 'owner' => 'AUTTHAPON', 'shelf' => 'CA_121', 'ok' => 'NKGZ-169MS-0D', 'days' => 18, 'note' => '', 'reason' => ''],
    ['prod' => 'NKGZ-120MS-1D', 'side' => 'F-Side', 'mach' => 'OMRON (2D)', 's1' => 2, 's2' => 2, 's3' => 2, 's4' => 2, 'owner' => 'Nuttaphong', 'shelf' => 'CA_150', 'ok' => 'NKGZ-120MS-1C', 'days' => 3, 'note' => 'บอร์ดทดสอบผ่าน', 'reason' => ''],
    ['prod' => 'NKGZ-165MS-0C', 'side' => 'F-Side', 'mach' => 'OMRON (2D)', 's1' => 2, 's2' => 2, 's3' => 2, 's4' => 2, 'owner' => 'AUTTHAPON', 'shelf' => 'CA_122', 'ok' => 'NKGZ-165MS-0B', 'days' => 4, 'note' => 'โปรแกรมพร้อมใช้งาน', 'reason' => ''],
    ['prod' => 'NKSZ-268MW-0C-B', 'side' => 'F-Side', 'mach' => 'OMRON (2D)', 's1' => 2, 's2' => 2, 's3' => 2, 's4' => 2, 'owner' => 'Thanapol', 'shelf' => 'CA_267', 'ok' => 'NKSZ-268MW-0B', 'days' => 22, 'note' => 'โปรแกรมผ่านเกณฑ์ 100%', 'reason' => ''],
    ['prod' => 'CASZ-315MS-0B', 'side' => 'B-Side', 'mach' => 'OMRON (2D)', 's1' => 2, 's2' => 2, 's3' => 2, 's4' => 2, 'owner' => 'CHATCHAI', 'shelf' => 'CA_268', 'ok' => 'CASZ-315MS-0A', 'days' => 27, 'note' => '-', 'reason' => ''],
    ['prod' => 'SYLZ-042MS-1B', 'side' => 'B-Side', 'mach' => 'YAMAHA (3D)', 's1' => 2, 's2' => 2, 's3' => 2, 's4' => 2, 'owner' => 'WARAPORN', 'shelf' => 'CA_160', 'ok' => 'SYLZ-042MS-1A', 'days' => 6, 'note' => 'YAMAHA 3D ผ่านเกณฑ์', 'reason' => ''],
    ['prod' => 'YAMZ-101MS-0A', 'side' => 'F-Side', 'mach' => 'YAMAHA (3D)', 's1' => 2, 's2' => 2, 's3' => 2, 's4' => 2, 'owner' => 'METEE', 'shelf' => 'CB_10', 'ok' => '', 'days' => 8, 'note' => 'เสร็จเรียบร้อย', 'reason' => ''],
    ['prod' => 'YAMZ-102MS-0A', 'side' => 'B-Side', 'mach' => 'YAMAHA (3D)', 's1' => 2, 's2' => 2, 's3' => 2, 's4' => 2, 'owner' => 'METEE', 'shelf' => 'CB_11', 'ok' => '', 'days' => 7, 'note' => 'ตรวจสอบ 3D Pass', 'reason' => ''],
    ['prod' => 'TRIZ-501MW-1A', 'side' => 'F-Side', 'mach' => 'TRI INNOVATION', 's1' => 2, 's2' => 9, 's3' => 2, 's4' => 2, 'owner' => 'METEE', 'shelf' => 'A3_1', 'ok' => '', 'days' => 10, 'note' => 'ไม่ต้องทำ Sheet', 'reason' => ''],
    ['prod' => 'TRIZ-502MW-1A', 'side' => 'B-Side', 'mach' => 'TRI INNOVATION', 's1' => 2, 's2' => 9, 's3' => 2, 's4' => 2, 'owner' => 'METEE', 'shelf' => 'A3_2', 'ok' => '', 'days' => 9, 'note' => 'TRI รันผ่าน 100%', 'reason' => ''],
    ['prod' => 'AIXZ-020ML-0A', 'side' => 'F-Side', 'mach' => 'TRI INNOVATION', 's1' => 2, 's2' => 9, 's3' => 2, 's4' => 2, 'owner' => 'METEE', 'shelf' => 'A3_5', 'ok' => '', 'days' => 12, 'note' => 'ไม่ต้องทำ Sheet', 'reason' => ''],
    ['prod' => 'AIXZ-020ML-0A', 'side' => 'B-Side', 'mach' => 'TRI INNOVATION', 's1' => 2, 's2' => 9, 's3' => 2, 's4' => 2, 'owner' => 'METEE', 'shelf' => 'A3_6', 'ok' => '', 'days' => 12, 'note' => 'TRI complete', 'reason' => ''],
    ['prod' => 'CACZ-136MW-2D', 'side' => 'F-Side', 'mach' => 'OMRON (2D)', 's1' => 2, 's2' => 2, 's3' => 2, 's4' => 2, 'owner' => 'Nuttaphong', 'shelf' => 'CA_265', 'ok' => '', 'days' => 2, 'note' => 'เสร็จสิ้นการรัน', 'reason' => ''],
    ['prod' => 'CACZ-136MW-2D', 'side' => 'B-Side', 'mach' => 'OMRON (2D)', 's1' => 2, 's2' => 2, 's3' => 1, 's4' => 0, 'owner' => 'Nuttaphong', 'shelf' => 'CA_267, CA_268, CA_269', 'ok' => '', 'days' => 1, 'note' => 'ทดสอบซ้ำรอบสอง (หลาย shelf)', 'reason' => ''],

    // 2. Wait Master rows
    ['prod' => 'NKGZ-081MW-1C', 'side' => 'B-Side', 'mach' => 'OMRON (2D)', 's1' => 2, 's2' => 2, 's3' => 0, 's4' => 0, 'owner' => 'Thanapol', 'shelf' => 'CB_47', 'ok' => '', 'days' => 10, 'note' => 'รอแผ่น Defect จากไลน์', 'reason' => 'รอแผ่น Defect'],
    ['prod' => 'CADZ-299MW-1B', 'side' => 'F-Side', 'mach' => 'OMRON (2D)', 's1' => 2, 's2' => 1, 's3' => 0, 's4' => 0, 'owner' => 'WARAPORN', 'shelf' => 'CA_269', 'ok' => '', 'days' => 5, 'note' => 'กำลังสร้าง Sheet', 'reason' => 'รอแผ่น Bare'],
    ['prod' => 'NKSZ-375MW-0C', 'side' => 'B-Side', 'mach' => 'OMRON (2D)', 's1' => 2, 's2' => 0, 's3' => 0, 's4' => 0, 'owner' => 'ADISAK', 'shelf' => 'CA_270', 'ok' => '', 'days' => 0, 'note' => 'รอไฟล์ Gerber และบอร์ด Good', 'reason' => 'รอไฟล์ Gerber'],
    ['prod' => 'NKSZ-375MW-0C', 'side' => 'F-Side', 'mach' => 'OMRON (2D)', 's1' => 2, 's2' => 1, 's3' => 0, 's4' => 0, 'owner' => 'ADISAK', 'shelf' => 'CA_270', 'ok' => '', 'days' => 1, 'note' => 'กำลังเซ็ตตำแหน่ง', 'reason' => 'รอตรวจสอบ'],
    ['prod' => 'MSBZ-090MW-1A', 'side' => 'F-Side', 'mach' => 'OMRON (2D)', 's1' => 2, 's2' => 2, 's3' => 1, 's4' => 0, 'owner' => 'CHATCHAI', 'shelf' => 'CA_180', 'ok' => '', 'days' => 4, 'note' => 'รอ Master G+D รวม', 'reason' => 'รอรวม Master G+D'],
    ['prod' => 'MSBZ-090MW-1A', 'side' => 'B-Side', 'mach' => 'OMRON (2D)', 's1' => 2, 's2' => 2, 's3' => 0, 's4' => 0, 'owner' => 'CHATCHAI', 'shelf' => 'CA_181', 'ok' => '', 'days' => 4, 'note' => 'รอแผ่น Defect', 'reason' => 'รอแผ่น Defect'],
    ['prod' => 'ALMZ-015MS-2A', 'side' => 'F-Side', 'mach' => 'YAMAHA (3D)', 's1' => 2, 's2' => 2, 's3' => 0, 's4' => 0, 'owner' => 'AUTTHAPON', 'shelf' => 'CA_90', 'ok' => '', 'days' => 9, 'note' => 'ขาด Master Defect', 'reason' => 'ขาด Defect'],
    ['prod' => 'ALMZ-015MS-2A', 'side' => 'B-Side', 'mach' => 'YAMAHA (3D)', 's1' => 2, 's2' => 2, 's3' => 0, 's4' => 0, 'owner' => 'AUTTHAPON', 'shelf' => 'CA_91', 'ok' => '', 'days' => 9, 'note' => 'รอแผ่น Defect', 'reason' => 'รอแผ่น Defect'],
    ['prod' => 'KOBZ-330MW-0A', 'side' => 'F-Side', 'mach' => 'OMRON (2D)', 's1' => 2, 's2' => 1, 's3' => 0, 's4' => 0, 'owner' => 'Thanapol', 'shelf' => 'CB_12', 'ok' => '', 'days' => 3, 'note' => 'จัดทำ Sheet ร่วม', 'reason' => 'รอ Master'],
    ['prod' => 'KOBZ-330MW-0A', 'side' => 'B-Side', 'mach' => 'OMRON (2D)', 's1' => 2, 's2' => 0, 's3' => 0, 's4' => 0, 'owner' => 'Thanapol', 'shelf' => 'CB_13', 'ok' => '', 'days' => 2, 'note' => 'รอตรวจสอบชิ้นงาน', 'reason' => 'รอ Master'],

    // 3. Queued / In-Progress (Wait for Program)
    ['prod' => 'SYLZ-042MS-1B', 'side' => 'F-Side', 'mach' => 'YAMAHA (3D)', 's1' => 0, 's2' => 0, 's3' => 0, 's4' => 0, 'owner' => null, 'shelf' => null, 'ok' => '', 'days' => 2, 'note' => 'เพิ่งรับชิ้นงานใหม่ คิวด่วน', 'reason' => 'รอคิวทำโปรแกรม'],
    ['prod' => 'PANZ-088MW-0A', 'side' => 'F-Side', 'mach' => 'OMRON (2D)', 's1' => 1, 's2' => 0, 's3' => 0, 's4' => 0, 'owner' => 'ADISAK', 'shelf' => 'CA_210', 'ok' => '', 'days' => 1, 'note' => 'กำลังถ่ายภาพ Capture P/D', 'reason' => ''],
    ['prod' => 'PANZ-088MW-0A', 'side' => 'B-Side', 'mach' => 'OMRON (2D)', 's1' => 0, 's2' => 0, 's3' => 0, 's4' => 0, 'owner' => 'ADISAK', 'shelf' => 'CA_211', 'ok' => '', 'days' => 1, 'note' => 'รอต่อคิว B-Side', 'reason' => 'รอต่อคิว'],
    ['prod' => 'FUKZ-112MW-3A', 'side' => 'F-Side', 'mach' => 'OMRON (2D)', 's1' => 0, 's2' => 0, 's3' => 0, 's4' => 0, 'owner' => null, 'shelf' => null, 'ok' => '', 'days' => 3, 'note' => 'งานเข้าใหม่ รอจ่ายงาน', 'reason' => 'ยังไม่มอบหมาย'],
    ['prod' => 'FUKZ-112MW-3A', 'side' => 'B-Side', 'mach' => 'OMRON (2D)', 's1' => 0, 's2' => 0, 's3' => 0, 's4' => 0, 'owner' => null, 'shelf' => null, 'ok' => '', 'days' => 3, 'note' => 'งานเข้าใหม่', 'reason' => 'ยังไม่มอบหมาย'],
    ['prod' => 'DENZ-441MS-0C', 'side' => 'F-Side', 'mach' => 'YAMAHA (3D)', 's1' => 1, 's2' => 0, 's3' => 0, 's4' => 0, 'owner' => 'WARAPORN', 'shelf' => 'CB_05', 'ok' => '', 'days' => 0, 'note' => 'กำลังสอนชิ้นงาน (Teaching)', 'reason' => ''],
    ['prod' => 'DENZ-441MS-0C', 'side' => 'B-Side', 'mach' => 'YAMAHA (3D)', 's1' => 0, 's2' => 0, 's3' => 0, 's4' => 0, 'owner' => 'WARAPORN', 'shelf' => 'CB_06', 'ok' => '', 'days' => 0, 'note' => 'รอทำต่อ', 'reason' => 'รอคิว'],
    ['prod' => 'HIT-992MW-1A',  'side' => 'F-Side', 'mach' => 'TRI INNOVATION', 's1' => 1, 's2' => 9, 's3' => 0, 's4' => 0, 'owner' => 'METEE', 'shelf' => 'A3_10', 'ok' => '', 'days' => 1, 'note' => 'TRI กำลังโปรแกรม', 'reason' => ''],
    ['prod' => 'HIT-992MW-1A',  'side' => 'B-Side', 'mach' => 'TRI INNOVATION', 's1' => 0, 's2' => 9, 's3' => 0, 's4' => 0, 'owner' => 'METEE', 'shelf' => 'A3_11', 'ok' => '', 'days' => 1, 'note' => 'TRI รอคิว', 'reason' => 'รอคิว'],

    // 4. Overdue rows (> 14 days)
    ['prod' => 'COPZ-078MS-1A', 'side' => 'F-Side', 'mach' => 'OMRON (2D)', 's1' => 2, 's2' => 2, 's3' => 1, 's4' => 0, 'owner' => 'AUTTHAPON', 'shelf' => 'CA_271', 'ok' => '', 'days' => 23, 'note' => 'รอ Bare เพิ่มเติม ชิ้นงานติดขัด', 'reason' => 'รอ Bare เพิ่มเติม'],
    ['prod' => 'COPZ-078MS-1A', 'side' => 'B-Side', 'mach' => 'OMRON (2D)', 's1' => 2, 's2' => 1, 's3' => 0, 's4' => 0, 'owner' => 'AUTTHAPON', 'shelf' => 'CA_272', 'ok' => '', 'days' => 24, 'note' => 'ค้างนานเกิน 3 สัปดาห์', 'reason' => 'รออนุมัติแบบ'],
    ['prod' => 'SONZ-003MW-9B', 'side' => 'F-Side', 'mach' => 'OMRON (2D)', 's1' => 1, 's2' => 0, 's3' => 0, 's4' => 0, 'owner' => 'CHATCHAI', 'shelf' => 'CA_88', 'ok' => '', 'days' => 19, 'note' => 'ติดปัญหาพารามิเตอร์กล้อง', 'reason' => 'รอแก้ไขโปรแกรม'],
    ['prod' => 'SONZ-003MW-9B', 'side' => 'B-Side', 'mach' => 'OMRON (2D)', 's1' => 0, 's2' => 0, 's3' => 0, 's4' => 0, 'owner' => 'CHATCHAI', 'shelf' => 'CA_89', 'ok' => '', 'days' => 19, 'note' => 'รอดำเนินการ', 'reason' => 'ติดปัญหาเทคนิค'],
    ['prod' => 'MITZ-771MS-0B', 'side' => 'F-Side', 'mach' => 'YAMAHA (3D)', 's1' => 2, 's2' => 2, 's3' => 0, 's4' => 0, 'owner' => 'WARAPORN', 'shelf' => 'CA_05', 'ok' => '', 'days' => 16, 'note' => 'รอแผ่น Defect เพิ่มเติม', 'reason' => 'รอแผ่น Defect'],
    ['prod' => 'MITZ-771MS-0B', 'side' => 'B-Side', 'mach' => 'YAMAHA (3D)', 's1' => 2, 's2' => 2, 's3' => 0, 's4' => 0, 'owner' => 'WARAPORN', 'shelf' => 'CA_06', 'ok' => '', 'days' => 16, 'note' => 'ค้างนาน รออะไหล่บอร์ด', 'reason' => 'รออะไหล่'],

    // 5. Additional Realistic Rows to bring total to 60
    ['prod' => 'NKSZ-380MW-0C', 'side' => 'F-Side', 'mach' => 'OMRON (2D)', 's1' => 2, 's2' => 2, 's3' => 2, 's4' => 2, 'owner' => 'Thanapol', 'shelf' => 'CA_273', 'ok' => 'NKSZ-380MW-0A', 'days' => 5, 'note' => 'ผ่านการตรวจสอบ 100%', 'reason' => ''],
    ['prod' => 'NKSZ-380MW-0C', 'side' => 'B-Side', 'mach' => 'OMRON (2D)', 's1' => 2, 's2' => 2, 's3' => 2, 's4' => 1, 'owner' => 'Thanapol', 'shelf' => 'CA_274', 'ok' => '', 'days' => 2, 'note' => 'กำลัง Run Test รอบสุดท้าย', 'reason' => ''],
    ['prod' => 'CASZ-400MS-1A', 'side' => 'F-Side', 'mach' => 'OMRON (2D)', 's1' => 2, 's2' => 2, 's3' => 2, 's4' => 2, 'owner' => 'SONGSREM', 'shelf' => 'CA_201', 'ok' => 'CASZ-400MS-0A', 'days' => 6, 'note' => 'สมบูรณ์', 'reason' => ''],
    ['prod' => 'CASZ-400MS-1A', 'side' => 'B-Side', 'mach' => 'OMRON (2D)', 's1' => 2, 's2' => 2, 's3' => 2, 's4' => 2, 'owner' => 'SONGSREM', 'shelf' => 'CA_202', 'ok' => '', 'days' => 6, 'note' => 'สมบูรณ์', 'reason' => ''],
    ['prod' => 'TOSZ-110MW-2B', 'side' => 'F-Side', 'mach' => 'OMRON (2D)', 's1' => 2, 's2' => 2, 's3' => 2, 's4' => 2, 'owner' => 'ADISAK', 'shelf' => 'CA_205', 'ok' => '', 'days' => 8, 'note' => 'ผ่านการผลิต', 'reason' => ''],
    ['prod' => 'TOSZ-110MW-2B', 'side' => 'B-Side', 'mach' => 'OMRON (2D)', 's1' => 2, 's2' => 2, 's3' => 2, 's4' => 2, 'owner' => 'ADISAK', 'shelf' => 'CA_206', 'ok' => '', 'days' => 8, 'note' => 'ผ่านการผลิต', 'reason' => ''],
    ['prod' => 'ROHM-022MS-0A', 'side' => 'F-Side', 'mach' => 'YAMAHA (3D)', 's1' => 2, 's2' => 2, 's3' => 2, 's4' => 2, 'owner' => 'WARAPORN', 'shelf' => 'CB_22', 'ok' => '', 'days' => 7, 'note' => 'Pass 3D', 'reason' => ''],
    ['prod' => 'ROHM-022MS-0A', 'side' => 'B-Side', 'mach' => 'YAMAHA (3D)', 's1' => 2, 's2' => 2, 's3' => 2, 's4' => 2, 'owner' => 'WARAPORN', 'shelf' => 'CB_23', 'ok' => '', 'days' => 7, 'note' => 'Pass 3D', 'reason' => ''],
    ['prod' => 'MURZ-880MW-1C', 'side' => 'F-Side', 'mach' => 'OMRON (2D)', 's1' => 2, 's2' => 2, 's3' => 2, 's4' => 2, 'owner' => 'CHATCHAI', 'shelf' => 'CA_177', 'ok' => '', 'days' => 13, 'note' => '', 'reason' => ''],
    ['prod' => 'MURZ-880MW-1C', 'side' => 'B-Side', 'mach' => 'OMRON (2D)', 's1' => 2, 's2' => 2, 's3' => 2, 's4' => 2, 'owner' => 'CHATCHAI', 'shelf' => 'CA_178', 'ok' => '', 'days' => 13, 'note' => '', 'reason' => ''],
    ['prod' => 'EPSZ-332MS-0A', 'side' => 'F-Side', 'mach' => 'OMRON (2D)', 's1' => 2, 's2' => 2, 's3' => 2, 's4' => 2, 'owner' => 'Nuttaphong', 'shelf' => 'CA_115', 'ok' => '', 'days' => 4, 'note' => 'เสร็จเรียบร้อย', 'reason' => ''],
    ['prod' => 'EPSZ-332MS-0A', 'side' => 'B-Side', 'mach' => 'OMRON (2D)', 's1' => 2, 's2' => 2, 's3' => 2, 's4' => 2, 'owner' => 'Nuttaphong', 'shelf' => 'CA_116', 'ok' => '', 'days' => 4, 'note' => 'เสร็จเรียบร้อย', 'reason' => ''],
    ['prod' => 'CANZ-550MW-2D', 'side' => 'F-Side', 'mach' => 'OMRON (2D)', 's1' => 2, 's2' => 2, 's3' => 2, 's4' => 2, 'owner' => 'AUTTHAPON', 'shelf' => 'CA_118', 'ok' => '', 'days' => 5, 'note' => 'Master บอร์ดส่งคืนแล้ว', 'reason' => ''],
    ['prod' => 'CANZ-550MW-2D', 'side' => 'B-Side', 'mach' => 'OMRON (2D)', 's1' => 2, 's2' => 2, 's3' => 2, 's4' => 2, 'owner' => 'AUTTHAPON', 'shelf' => 'CA_119', 'ok' => '', 'days' => 5, 'note' => 'Master บอร์ดส่งคืนแล้ว', 'reason' => ''],
];

$insProg = $pdo->prepare('
    INSERT INTO aoi_programs (
        product_model, side, family_key, machine_type,
        stage_1, stage_1_by, stage_1_at,
        stage_2, stage_2_by, stage_2_at,
        stage_3, stage_3_by, stage_3_at,
        stage_4, stage_4_by, stage_4_at,
        owner, shelf, program_ok, wait_reason, note, version,
        created_at, updated_at
    ) VALUES (
        :prod, :side, :family, :mach,
        :s1, :s1_by, :s1_at,
        :s2, :s2_by, :s2_at,
        :s3, :s3_by, :s3_at,
        :s4, :s4_by, :s4_at,
        :owner, :shelf, :ok, :reason, :note, 1,
        :created_at, :updated_at
    )
');

$insAudit = $pdo->prepare('
    INSERT INTO aoi_program_audit (
        program_id, action, field_name, old_value, new_value, operator, ip_address, created_at
    ) VALUES (
        :prog_id, "seed", "all", NULL, :new_val, "System Seed", "127.0.0.1", :created_at
    )
');

$count = 0;
foreach ($sampleData as $d) {
    $daysAgo = (int)$d['days'];
    $created = clone $now;
    if ($daysAgo > 0) {
        $created->modify("-{$daysAgo} days");
    }
    $createdStr = $created->format('Y-m-d H:i:s');
    $updatedStr = $createdStr;

    // Determine family key (e.g. NKSZ-288MW from NKSZ-288MW-0B)
    $prod = trim($d['prod']);
    $parts = explode('-', $prod);
    $family = count($parts) >= 2 ? ($parts[0] . '-' . $parts[1]) : substr($prod, 0, 10);

    $owner = $d['owner'];
    $s1 = (int)$d['s1'];
    $s2 = (int)$d['s2'];
    $s3 = (int)$d['s3'];
    $s4 = (int)$d['s4'];

    $insProg->execute([
        ':prod'       => $prod,
        ':side'       => $d['side'],
        ':family'     => $family,
        ':mach'       => $d['mach'],
        ':s1'         => $s1,
        ':s1_by'      => $s1 > 0 ? $owner : null,
        ':s1_at'      => $s1 > 0 ? $createdStr : null,
        ':s2'         => $s2,
        ':s2_by'      => $s2 > 0 && $s2 !== 9 ? $owner : null,
        ':s2_at'      => $s2 > 0 && $s2 !== 9 ? $createdStr : null,
        ':s3'         => $s3,
        ':s3_by'      => $s3 > 0 ? $owner : null,
        ':s3_at'      => $s3 > 0 ? $createdStr : null,
        ':s4'         => $s4,
        ':s4_by'      => $s4 > 0 ? $owner : null,
        ':s4_at'      => $s4 > 0 ? $createdStr : null,
        ':owner'      => $owner,
        ':shelf'      => $d['shelf'],
        ':ok'         => $d['ok'],
        ':reason'     => $d['reason'],
        ':note'       => $d['note'],
        ':created_at' => $createdStr,
        ':updated_at' => $updatedStr,
    ]);

    $id = (int)$pdo->lastInsertId();
    $insAudit->execute([
        ':prog_id'    => $id,
        ':new_val'    => json_encode(['product' => $prod, 'side' => $d['side'], 'machine' => $d['mach']], JSON_UNESCAPED_UNICODE),
        ':created_at' => $createdStr,
    ]);

    $count++;
}

echo "Successfully seeded {$count} AOI program demo records into database!\n";
