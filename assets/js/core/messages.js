/**
 * User-facing copy (Thai / English) used across components.
 */

const ALL_SIZE_INSERT_MSG = 'หน้า All Size Magazine ไม่สามารถเพิ่มข้อมูลได้ กรุณาเลือกชีตก่อน / Select a sheet before inserting a new record.';

const MSG = {
    loading:             'กำลังโหลด...',
    empty:               'ไม่พบข้อมูล',
    emptyFiltered:       'ไม่มีบอร์ดที่ตรงกับคำค้นหาหรือตัวกรองนี้ ลองใช้คำอื่น หรือล้างตัวกรอง',
    emptyNone:           'ยังไม่มีบอร์ดในชีตนี้ กด New Board Entry เพื่อเพิ่มรายการแรก',
    matrixEmptyTitle:    'ไม่พบบอร์ดที่มีข้อบกพร่อง / No Defect Boards Found',
    matrixEmptyStatusG:  'ตัวกรองเลือกเฉพาะสถานะ Master GOOD (G) จึงไม่มีรายการ Defect แสดง / Filtered by Master GOOD [G] only',
    matrixEmptyFiltered: 'ไม่มีบอร์ดสถานะ D หรือ G+D ที่ตรงกับคำค้นหาหรือตัวกรองนี้ / No D or G+D boards match the active filters',
    matrixEmptyNone:     'ยังไม่มีบอร์ดสถานะ Defect (D / G+D) ในชีตนี้ / No defect boards recorded in this sheet yet',
    error:               'โหลดข้อมูลไม่สำเร็จ',
    errorHint:           'ตรวจสอบการเชื่อมต่อ แล้วลองอีกครั้ง',
    retry:               'ลองอีกครั้ง',
    confirmDel:          (code) => `ลบรายการ ${code} ?\nการลบนี้ไม่สามารถย้อนกลับได้`,
    deleteFailed:        'ลบไม่สำเร็จ ลองอีกครั้ง',
    networkError:        'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ ลองอีกครั้ง',
};
