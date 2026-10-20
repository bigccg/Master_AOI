/**
 * User-facing copy (Thai / English) used across components.
 * Error structure: What happened + Why + How to fix. Buttons start with a verb.
 */

const ALL_SIZE_INSERT_MSG  = 'ไม่สามารถเพิ่มข้อมูลในแท็บรวมทุกขนาดได้ เนื่องจากต้องระบุชีตและชั้นวางปลายทาง กรุณาเลือกแท็บชีตด้านบนก่อนกดเพิ่มข้อมูล / Cannot add a record from All Size Magazine because a target sheet is required. Please select a sheet tab first.';
const EMPTY_ZONE_INSERT_MSG = 'ไม่สามารถเพิ่มข้อมูลได้ เนื่องจากโซนนี้ยังไม่มีชีตในระบบ กรุณาสลับไปโซนที่มีชีต (เช่น General) และเลือกชีตก่อน / Cannot add a record because this zone has no sheets configured yet. Please switch to an active zone first.';
// Same text as the PHP constants of the same name (…_ERROR) in config/database.php
const INACTIVE_ZONE_INSERT_MSG = 'ไม่สามารถเพิ่มข้อมูลได้ เนื่องจากโซนนี้ถูกปิดใช้งานโดยผู้ดูแลระบบ ข้อมูลเดิมยังดูและส่งออกได้ตามปกติ กรุณาสลับไปโซนอื่นที่เปิดใช้งาน / Cannot add a record because an administrator disabled this zone. Existing records can still be viewed and exported. Please switch to an active zone.';
const NO_ACTIVE_SHEET_INSERT_MSG = 'ไม่สามารถเพิ่มข้อมูลได้ เนื่องจากชีตทั้งหมดในโซนนี้ถูกปิดใช้งาน กรุณาสลับไปโซนที่มีชีตเปิดใช้งาน หรือติดต่อผู้ดูแลระบบให้เปิดใช้งานชีต / Cannot add a record because every sheet in this zone is disabled. Switch to a zone with an active sheet, or ask an administrator to enable one.';
const INACTIVE_SHEET_INSERT_MSG = 'ไม่สามารถเพิ่มข้อมูลในชีตนี้ได้ เนื่องจากชีตถูกปิดใช้งานโดยผู้ดูแลระบบ ข้อมูลเดิมยังดู แก้ไข และส่งออกได้ตามปกติ กรุณาเลือกชีตอื่นที่เปิดใช้งานจากแท็บด้านบน / Cannot add a record to this sheet because an administrator disabled it. Existing records can still be viewed, edited and exported. Please pick an active sheet tab above.';

const MSG = {
    loading:             'กำลังโหลดข้อมูล... / Loading data...',
    empty:               'ไม่พบข้อมูลบอร์ด / No Board Records Found',
    emptyFiltered:       'ไม่พบบอร์ดที่ตรงกับคำค้นหาหรือตัวกรองที่เลือก กรุณาเปลี่ยนคำค้นหาหรือกดปุ่มล้างตัวกรอง / No boards match the active search or filters. Try another keyword or clear filters.',
    emptyNone:           'ยังไม่มีข้อมูลบอร์ดในชีตนี้ กดปุ่ม "+ เพิ่มบอร์ด / Add Board" ด้านบนเพื่อบันทึกรายการแรก / No boards recorded in this sheet yet. Click "+ Add Board" above to create the first record.',
    emptyAllSheets:      'ยังไม่มีข้อมูลบอร์ดในโซนนี้ กรุณาเลือกแท็บชีตด้านบนแล้วกด "+ เพิ่มบอร์ด / Add Board" เพื่อเริ่มบันทึก / No boards recorded in this zone yet. Select a sheet tab above and click "+ Add Board".',
    emptyZoneTitle:      (zone) => `โซน ${zone} ยังไม่มีชีตในระบบ / No Sheets Configured in ${zone} Zone`,
    emptyZoneDesc:       (zone, defaultLabel, defaultShort) => `ยังไม่สามารถดูตารางหรือเพิ่มข้อมูลบอร์ดในโซน ${zone} ได้ เนื่องจากยังไม่ได้ตั้งค่ารายการชีตและชั้นวาง (Shelf) สำหรับโซนนี้ กรุณาสลับไปโซน "${defaultLabel}" เพื่อใช้งาน / Cannot view or add records in ${zone} because no sheets or shelves are configured for this zone yet. Switch to "${defaultShort}" to continue.`,
    emptyZoneCta:        (defaultShort) => `สลับไปโซน ${defaultShort} / Switch to ${defaultShort} Zone`,
    matrixEmptyTitle:    'ไม่พบบอร์ดที่มีข้อบกพร่อง / No Defect Boards Found',
    matrixEmptyStatusG:  'ไม่แสดงรายการในตารางนี้ เนื่องจากตัวกรองเลือกเฉพาะสถานะ Master GOOD (G) กรุณาเปลี่ยนตัวกรองสถานะเป็นทุกสถานะ หรือ D / G+D / Filtered by Master GOOD [G] only. Change status filter to view Defect records.',
    matrixEmptyFiltered: 'ไม่พบบอร์ดสถานะ D หรือ G+D ที่ตรงกับคำค้นหาหรือตัวกรองนี้ กรุณาปรับตัวกรองใหม่ / No D or G+D boards match the active filters.',
    matrixEmptyNone:     'ยังไม่มีบอร์ดสถานะ Defect (D / G+D) ในโซนและชีตที่เลือก / No defect boards recorded in this selection yet.',
    error:               'โหลดข้อมูลไม่สำเร็จ / Failed to Load Data',
    errorHint:           'ไม่สามารถดึงข้อมูลจากเซิร์ฟเวอร์ได้ กรุณาตรวจสอบการเชื่อมต่อแล้วลองใหม่อีกครั้ง / Could not reach the server. Please check your connection and retry.',
    retry:               'ลองโหลดใหม่ / Retry',
    confirmDel:          (code) => `ยืนยันการลบรายการ ${code} ?\nการลบนี้ไม่สามารถย้อนกลับได้ / Delete record ${code}? This action cannot be undone.`,
    deleteFailed:        'ลบรายการไม่สำเร็จ เนื่องจากเกิดข้อผิดพลาดจากเซิร์ฟเวอร์ กรุณาลองอีกครั้ง / Failed to delete record. Please try again.',
    networkError:        'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ กรุณาตรวจสอบเครือข่ายแล้วลองอีกครั้ง / Network error. Please check your connection and retry.',
};
