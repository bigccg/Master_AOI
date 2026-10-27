/**
 * User-facing copy (Thai / English) used across components.
 * Error structure: What happened + Why + How to fix. Buttons start with a verb.
 */

const ALL_SIZE_INSERT_MSG  = 'ไม่สามารถเพิ่มข้อมูลในแท็บรวมทุกขนาดได้ เนื่องจากต้องระบุ Shelf และชั้นวางปลายทาง กรุณาเลือกแท็บ Shelf ด้านบนก่อนกดเพิ่มข้อมูล / Cannot add a record from All Size Magazine because a target shelf is required. Please select a shelf tab first.';
const EMPTY_ZONE_INSERT_MSG = 'ไม่สามารถเพิ่มข้อมูลได้ เนื่องจากโซนนี้ยังไม่มี Shelf ในระบบ กรุณาสลับไปโซนที่มี Shelf (เช่น General) และเลือก Shelf ก่อน / Cannot add a record because this zone has no shelves configured yet. Please switch to an active zone first.';
// Same text as the PHP constants of the same name (…_ERROR) in config/database.php
const INACTIVE_ZONE_INSERT_MSG = 'ไม่สามารถเพิ่มข้อมูลได้ เนื่องจากโซนนี้ถูกปิดใช้งานโดยผู้ดูแลระบบ ข้อมูลเดิมยังดูและส่งออกได้ตามปกติ กรุณาสลับไปโซนอื่นที่เปิดใช้งาน / Cannot add a record because an administrator disabled this zone. Existing records can still be viewed and exported. Please switch to an active zone.';
const NO_ACTIVE_SHEET_INSERT_MSG = 'ไม่สามารถเพิ่มข้อมูลได้ เนื่องจาก Shelf ทั้งหมดในโซนนี้ถูกปิดใช้งาน กรุณาสลับไปโซนที่มี Shelf เปิดใช้งาน หรือติดต่อผู้ดูแลระบบให้เปิดใช้งาน Shelf / Cannot add a record because every shelf in this zone is disabled. Switch to a zone with an active shelf, or ask an administrator to enable one.';
const INACTIVE_SHEET_INSERT_MSG = 'ไม่สามารถเพิ่มข้อมูลใน Shelf นี้ได้ เนื่องจาก Shelf ถูกปิดใช้งานโดยผู้ดูแลระบบ ข้อมูลเดิมยังดู แก้ไข และส่งออกได้ตามปกติ กรุณาเลือก Shelf อื่นที่เปิดใช้งานจากแท็บด้านบน / Cannot add a record to this shelf because an administrator disabled it. Existing records can still be viewed, edited and exported. Please pick an active shelf tab above.';

// Same text as ALL_ZONES_INSERT_ERROR in config/database.php
const ALL_ZONES_INSERT_MSG = 'ไม่สามารถเพิ่มข้อมูลในมุมมองทุกโซนได้ เนื่องจากต้องระบุโซนและ Shelf ปลายทาง กรุณาเลือกโซนและ Shelf ด้านบนก่อนกดเพิ่มข้อมูล / Cannot add a record from All Zones because a target zone and shelf are required. Please select a zone and a shelf first.';

const MSG = {
    loading:             'กำลังโหลดข้อมูล... / Loading data...',
    empty:               'ไม่พบข้อมูลบอร์ด / No Board Records Found',
    emptyFiltered:       'ไม่พบบอร์ดที่ตรงกับคำค้นหาหรือตัวกรองที่เลือก กรุณาเปลี่ยนคำค้นหาหรือกดปุ่มล้างตัวกรอง / No boards match the active search or filters. Try another keyword or clear filters.',
    emptyNone:           'ยังไม่มีข้อมูลบอร์ดใน Shelf นี้ กดปุ่ม "+ เพิ่มบอร์ด / Add Board" ด้านบนเพื่อบันทึกรายการแรก / No boards recorded in this shelf yet. Click "+ Add Board" above to create the first record.',
    emptyAllZones:       'ยังไม่มีข้อมูลบอร์ดในทุกโซน กรุณาเลือกโซนและ Shelf ด้านบนแล้วกด "+ เพิ่มบอร์ด / Add Board" เพื่อเริ่มบันทึก / No boards recorded in any zone yet. Select a zone and a shelf above and click "+ Add Board".',
    emptyAllSheets:      'ยังไม่มีข้อมูลบอร์ดในโซนนี้ กรุณาเลือกแท็บ Shelf ด้านบนแล้วกด "+ เพิ่มบอร์ด / Add Board" เพื่อเริ่มบันทึก / No boards recorded in this zone yet. Select a shelf tab above and click "+ Add Board".',
    emptyZoneTitle:      (zone) => `โซน ${zone} ยังไม่มี Shelf ในระบบ / No Shelves Configured in ${zone} Zone`,
    emptyZoneDesc:       (zone, defaultLabel, defaultShort) => `ยังไม่สามารถดูตารางหรือเพิ่มข้อมูลบอร์ดในโซน ${zone} ได้ เนื่องจากยังไม่ได้ตั้งค่ารายการ Shelf และชั้นวางสำหรับโซนนี้ กรุณาสลับไปโซน "${defaultLabel}" เพื่อใช้งาน / Cannot view or add records in ${zone} because no shelves are configured for this zone yet. Switch to "${defaultShort}" to continue.`,
    emptyZoneCta:        (defaultShort) => `สลับไปโซน ${defaultShort} / Switch to ${defaultShort} Zone`,
    matrixEmptyTitle:    'ไม่พบบอร์ดที่มีข้อบกพร่อง / No Defect Boards Found',
    matrixEmptyStatusG:  'ไม่แสดงรายการในตารางนี้ เนื่องจากตัวกรองเลือกเฉพาะสถานะ Master GOOD (G) กรุณาเปลี่ยนตัวกรองสถานะเป็นทุกสถานะ หรือ D / G+D / Filtered by Master GOOD [G] only. Change status filter to view Defect records.',
    matrixEmptyFiltered: 'ไม่พบบอร์ดสถานะ D หรือ G+D ที่ตรงกับคำค้นหาหรือตัวกรองนี้ กรุณาปรับตัวกรองใหม่ / No D or G+D boards match the active filters.',
    matrixEmptyNone:     'ยังไม่มีบอร์ดสถานะ Defect (D / G+D) ในโซนและ Shelf ที่เลือก / No defect boards recorded in this selection yet.',
    error:               'โหลดข้อมูลไม่สำเร็จ / Failed to Load Data',
    errorHint:           'ไม่สามารถดึงข้อมูลจากเซิร์ฟเวอร์ได้ กรุณาตรวจสอบการเชื่อมต่อแล้วลองใหม่อีกครั้ง / Could not reach the server. Please check your connection and retry.',
    retry:               'ลองโหลดใหม่ / Retry',
    confirmDel:          (code) => `ยืนยันการลบรายการ ${code} ?\nการลบนี้ไม่สามารถย้อนกลับได้ / Delete record ${code}? This action cannot be undone.`,
    deleteFailed:        'ลบรายการไม่สำเร็จ เนื่องจากเกิดข้อผิดพลาดจากเซิร์ฟเวอร์ กรุณาลองอีกครั้ง / Failed to delete record. Please try again.',
    networkError:        'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ กรุณาตรวจสอบเครือข่ายแล้วลองอีกครั้ง / Network error. Please check your connection and retry.',
    importExpired:       'แม่แบบนี้หมดอายุ เนื่องจากมีการบันทึกข้อมูลใหม่ในระบบแล้ว กรุณาดาวน์โหลดแม่แบบใหม่แล้วนำข้อมูลไปวาง / This template has expired because new records were saved in the system. Please download a new template.',
    importSelectSheet:   'ไม่สามารถสร้างแม่แบบในมุมมองรวมได้ กรุณาเลือกโซนและชีตปลายทางก่อน / Please select an active target Zone and Sheet first.',
    importFileInvalid:   'ไม่สามารถอ่านไฟล์ได้ กรุณาเลือกไฟล์แม่แบบ Excel (.xlsx) ที่ดาวน์โหลดจากระบบขนาดไม่เกิน 2 MB / Please select a valid .xlsx template file (max 2 MB).',
};
