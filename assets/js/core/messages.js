/**
 * User-facing copy (Thai / English) used across components.
 * Error structure: What happened + Why + How to fix. Buttons start with a verb.
 */

const ALL_SIZE_INSERT_MSG  = 'ไม่สามารถเพิ่มข้อมูลในแท็บรวมทุกขนาดได้ เนื่องจากต้องระบุชีตและชั้นวางปลายทาง กรุณาเลือกแท็บชีตด้านบนก่อนกดเพิ่มข้อมูล / Cannot add a record from All Size Magazine because a target sheet is required. Please select a sheet tab first.';
const EMPTY_ZONE_INSERT_MSG = 'ไม่สามารถเพิ่มข้อมูลได้ เนื่องจากโซนนี้ยังไม่มีชีตในระบบ กรุณาสลับไปโซนที่มีชีต (เช่น General) และเลือกชีตก่อน / Cannot add a record because this zone has no sheets configured yet. Please switch to an active zone first.';

const MSG = {
    loading:             'กำลังโหลดข้อมูล... / Loading data...',
    empty:               'ไม่พบข้อมูลบอร์ด / No Board Records Found',
    emptyFiltered:       'ไม่พบบอร์ดที่ตรงกับคำค้นหาหรือตัวกรองที่เลือก กรุณาเปลี่ยนคำค้นหาหรือกดปุ่มล้างตัวกรอง / No boards match the active search or filters. Try another keyword or clear filters.',
    emptyNone:           'ยังไม่มีข้อมูลบอร์ดในชีตนี้ กดปุ่ม "+ เพิ่มบอร์ด / Add Board" ด้านบนเพื่อบันทึกรายการแรก / No boards recorded in this sheet yet. Click "+ Add Board" above to create the first record.',
    emptyAllSheets:      'ยังไม่มีข้อมูลบอร์ดในโซนนี้ กรุณาเลือกแท็บชีตด้านบนแล้วกด "+ เพิ่มบอร์ด / Add Board" เพื่อเริ่มบันทึก / No boards recorded in this zone yet. Select a sheet tab above and click "+ Add Board".',
    emptyZoneTitle:      (zone) => `โซน ${zone} ยังไม่มีชีตในระบบ / No Sheets Configured in ${zone} Zone`,
    emptyZoneDesc:       (zone) => `ยังไม่สามารถดูตารางหรือเพิ่มข้อมูลบอร์ดในโซน ${zone} ได้ เนื่องจากยังไม่ได้ตั้งค่ารายการชีตและชั้นวาง (Shelf) สำหรับโซนนี้ กรุณาสลับไปโซน "ทั่วไป / General" เพื่อใช้งาน / Cannot view or add records in ${zone} because no sheets or shelves are configured for this zone yet. Switch to "General" to continue.`,
    emptyZoneCta:        'สลับไปโซน General / Switch to General Zone',
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
