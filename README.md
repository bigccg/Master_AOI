# Master_AOI

ระบบ AOI Inspection & Shelf Management (PHP 8 + MySQL) วิธีติดตั้งและรัน ดู [OFFLINE.md](OFFLINE.md)

## Number Code

รูปแบบ `AOI-P1-YY-MM-000` เช่น `AOI-P1-26-10-007`

- `AOI-P1` คือคำนำหน้าคงที่ (โรงงาน P1) และ `YY-MM` คือปี/เดือนที่บันทึก ตามเวลา Asia/Bangkok
- `000` คือเลขรัน**รวมทั้งระบบ** ทุกโซนและทุกชีตใช้ลำดับเดียวกัน เริ่ม `001` ใหม่ทุกต้นเดือน สูงสุด `999` ต่อเดือน
- เลขใหม่ = เลขสูงสุดของเดือนนั้นทั้งระบบ + 1 (MAX+1) เลขที่ว่างเพราะลบรายการกลางลำดับจะไม่ถูกนำกลับมาใช้
- ระบบออกรหัสทีละรายการด้วย MySQL `GET_LOCK('aoi_board_number_code', 5)` ถ้ารอคิวเกิน 5 วินาที ระบบจะไม่บันทึก (HTTP 503) และแจ้งผู้ใช้ให้กดบันทึกอีกครั้ง
- ระบบไม่ renumber รหัสเดิม ฐานข้อมูลเก่าที่มีรหัสซ้ำข้ามชีต (จากช่วงที่เลขรันแยกตามชีต) ยังใช้งานได้ตามปกติ ระบบจะเพิ่ม unique index `uq_code` (และลบ `uq_sheet_code` เดิม) ให้อัตโนมัติเมื่อไม่มีรหัสซ้ำเหลืออยู่แล้วเท่านั้น
- ระหว่างที่ยังมีรหัสซ้ำ เว็บจะตรวจและเขียน log เรื่องนี้ไม่เกินชั่วโมงละ 1 ครั้ง (ใช้ไฟล์ flag `aoi-<database>-uq_code.flag` ในโฟลเดอร์ temp ของ PHP)

## ตรวจรหัสซ้ำ: `scripts/check-duplicate-codes.php`

สคริปต์นี้อ่านอย่างเดียว ไม่แก้ข้อมูล ไม่ renumber และไม่รัน migration รันได้จาก command line เท่านั้น (ถ้าเปิดผ่านเว็บจะได้ 403)

```bash
# ใน Docker (ใช้ค่าเชื่อมต่อจาก docker-compose.yml)
docker exec -it php_app_web php /var/www/html/aoi-web-php/scripts/check-duplicate-codes.php

# จากเครื่องที่มี PHP + pdo_mysql
DB_HOST=127.0.0.1 php scripts/check-duplicate-codes.php
```

สคริปต์รายงาน 3 ส่วน:

1. Number Code ที่ซ้ำ พร้อมจำนวนแถว รายชื่อชีต และ `created_at` แรก/ล่าสุด
2. แถวที่คอลัมน์ `zone` ไม่ตรงกับ `ZONES` ใน `config/database.php` พร้อม SQL สำหรับแก้
3. สถานะ index `uq_code` / `uq_sheet_code`

Exit code: `0` = ไม่มีอะไรต้องแก้, `2` = พบรหัสซ้ำหรือ zone ไม่ตรง, `1` = ตรวจไม่ได้ (เช่น เชื่อมต่อฐานข้อมูลไม่ได้)

เมื่อสคริปต์ไม่พบรหัสซ้ำแล้ว เว็บจะเพิ่ม `uq_code` ให้เองในการโหลดหน้าเว็บครั้งถัดไป ถ้าเว็บเพิ่งพบรหัสซ้ำภายใน 1 ชั่วโมงที่ผ่านมา สคริปต์จะบอกเวลาที่เว็บจะตรวจใหม่

## Tests

```bash
docker exec php_app_web php /var/www/html/aoi-web-php/tests/number-code-test.php
```

- ผลลัพธ์แสดงเป็น PASS / FAIL ตามหัวข้อ (ก)–(ฉ) และจบด้วย exit code `0` เมื่อผ่านทั้งหมด ใช้เวลาประมาณ 1–2 นาที (คำสั่ง DDL ของ MySQL ใน Docker ช้า)
- สร้างฐานข้อมูลชั่วคราว `aoi_test_*` บน MySQL เดียวกันแล้วลบทิ้งเมื่อจบ ไม่แตะ `Master_AoI` (ต้องใช้ user ที่ CREATE/DROP database ได้ เช่น root ใน docker-compose)
- เปิด PHP built-in server ชั่วคราวเพื่อทดสอบการบันทึกผ่าน `api/save.php` และ `index.php`
- ระหว่างทดสอบ GET_LOCK จะถือ lock `aoi_board_number_code` รวมประมาณ 15 วินาที ใครกดบันทึกบน MySQL เดียวกันในช่วงนั้นจะได้ข้อความให้กดบันทึกอีกครั้ง
