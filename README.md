# Master_AOI

ระบบ AOI Inspection & Shelf Management (PHP 8 + MySQL) วิธีติดตั้งและรัน ดู [OFFLINE.md](OFFLINE.md)

## โซนและชีต / Zones & Sheets

โซนและชีตเก็บในตาราง `zones` / `sheets` (ไม่ใช่ค่าคงที่ในโค้ดแล้ว) ผู้ดูแลเพิ่ม แก้ไข ปิดใช้งาน หรือลบได้จากปุ่ม "จัดการโซนและชีต / Manage" ที่หัวหน้าเว็บ ทุกการเปลี่ยนแปลงบันทึกใน `topology_audit`

- ครั้งแรกที่เปิดเว็บหลังอัปเดต migration จะสร้างตารางและ seed 3 โซน + 4 ชีตเดิม (ค่า prefix และสีเดิม) **ครั้งเดียว** เมื่อตาราง `zones` ยังว่าง หลังจากนั้นไม่เขียนทับสิ่งที่ผู้ดูแลแก้
- General เป็นโซนเริ่มต้น ปิดหรือลบไม่ได้ · รหัสโซน/ชีต และ Shelf prefix แก้ภายหลังไม่ได้ · ลบหรือย้ายโซนได้เฉพาะชีตที่ยังไม่มีข้อมูลบอร์ด (ถ้ามีข้อมูลให้ปิดใช้งานแทน)
- ชีต/โซนที่ปิดใช้งาน: ข้อมูลเดิมอยู่ครบ เปิดจากลิงก์ตรง ค้นหา และ export ได้ แต่ไม่แสดงในแท็บ/ตัวเลือก และเพิ่มข้อมูลหรือ Magazine ใหม่ไม่ได้
- กฎทั้งหมดและคอมโพเนนต์ของหน้าจัดการ ดู [docs/zone-sheet-manager.md](docs/zone-sheet-manager.md)

## ผู้ดูแลระบบ / Administrator

ผู้ใช้ทั่วไปไม่ต้องล็อกอิน (ดู/เพิ่ม/แก้ข้อมูลบอร์ดได้เหมือนเดิม) การเข้าสู่ระบบใช้เฉพาะหน้าจัดการโซนและชีต (`admin.php`)

รหัสผ่านผู้ดูแลเก็บเป็นแฮช `password_hash()` ในตัวแปร environment `AOI_ADMIN_PASSWORD_HASH` เท่านั้น ถ้ายังไม่ตั้งหรือค่าไม่ใช่แฮช หน้าจัดการและ `api/topology.php` จะปิดทั้งหมด (503) และไม่แสดงลิงก์เข้าสู่ระบบ

```bash
# 1) สร้างแฮช (ถามรหัสผ่าน 2 ครั้ง ไม่แสดงบนจอ ไม่ค้างใน history; อย่างน้อย 10 ตัวอักษร)
docker exec -it php_app_web php /var/www/html/aoi-web-php/scripts/admin-password-hash.php

# 2) ใส่บรรทัดที่สคริปต์พิมพ์ออกมาใน docker-compose.yml → services → web → environment
#    (ใน compose ต้องเขียน $ เป็น $$ สคริปต์แปลงให้แล้ว)
#      AOI_ADMIN_PASSWORD_HASH: "$$2y$$10$$...."

# 3) โหลด environment ใหม่
docker compose up -d web
```

- เข้าสู่ระบบ: ไอคอนโล่ที่หัวเว็บ หรือ `/aoi-web-php/admin.php` → หลังล็อกอินระบบ regenerate session id
- ใส่รหัสผิดครบ 5 ครั้งใน 15 นาที ล็อก 5 นาที (นับต่อ session และต่อ IP `REMOTE_ADDR`) · ไม่ใช้งาน 30 นาที หรือครบ 8 ชั่วโมงหลังล็อกอิน ต้องเข้าสู่ระบบใหม่ · เปลี่ยนแฮชเมื่อไร ทุก session ผู้ดูแลถูกออกจากระบบ
- ทุก endpoint ที่เขียนข้อมูล: POST + CSRF + ตรวจสิทธิ์ผู้ดูแลทุกครั้ง (401 / 403 / 503) และตรวจกฎซ้ำที่ server เสมอ

## Number Code

รูปแบบ `AOI-P1-YY-MM-000` เช่น `AOI-P1-26-10-007`

- `AOI-P1` คือคำนำหน้าคงที่ (โรงงาน P1) และ `YY-MM` คือปี/เดือนที่บันทึก ตามเวลา Asia/Bangkok
- `000` คือเลขรัน**รวมทั้งระบบ** ทุกโซนและทุกชีตใช้ลำดับเดียวกัน เริ่ม `001` ใหม่ทุกต้นเดือน สูงสุด `999` ต่อเดือน
- เลขใหม่ = เลขสูงสุดของเดือนนั้นทั้งระบบ + 1 (MAX+1) เลขที่ว่างเพราะลบรายการกลางลำดับจะไม่ถูกนำกลับมาใช้
- ระบบออกรหัสทีละรายการด้วย MySQL `GET_LOCK('aoi_board_number_code', 5)` ถ้ารอคิวเกิน 5 วินาที ระบบจะไม่บันทึก (HTTP 503) และแจ้งผู้ใช้ให้กดบันทึกอีกครั้ง
- ระบบไม่ renumber รหัสเดิม ฐานข้อมูลเก่าที่มีรหัสซ้ำข้ามชีต (จากช่วงที่เลขรันแยกตามชีต) ยังใช้งานได้ตามปกติ ระบบจะเพิ่ม unique index `uq_code` (และลบ `uq_sheet_code` เดิม) ให้อัตโนมัติเมื่อไม่มีรหัสซ้ำเหลืออยู่แล้วเท่านั้น
- ระหว่างที่ยังมีรหัสซ้ำ เว็บจะตรวจและเขียน log เรื่องนี้ไม่เกินชั่วโมงละ 1 ครั้ง (ใช้ไฟล์ flag `aoi-<database>-uq_code.flag` ในโฟลเดอร์ temp ของ PHP)

## ความปลอดภัยของไฟล์บนเว็บ

`.htaccess` (Apache, ไม่ต้องใช้ mod_rewrite) ปิด `.git/` และไฟล์ที่ขึ้นต้นด้วยจุด (404), โฟลเดอร์ `config/ includes/ components/ tests/ scripts/ docs/` และไฟล์ `.zip .tar.gz .bak .old .sql .log .md .sh .bat` (403) ส่วน PHP built-in server (`start-offline.sh`) ปิด path เดียวกันใน router ของ `index.php` ถ้าใช้ web server อื่น (เช่น Nginx) ต้องตั้ง deny ให้ path เหล่านี้เอง

ห้ามวางไฟล์ zip / ไฟล์ backup ไว้ใน `./src` (document root ของ docker-compose) เพราะดาวน์โหลดผ่านเว็บได้

## ตรวจรหัสซ้ำ: `scripts/check-duplicate-codes.php`

สคริปต์นี้อ่านอย่างเดียว ไม่แก้ข้อมูล ไม่ renumber และไม่รัน migration รันได้จาก command line เท่านั้น (ถ้าเปิดผ่านเว็บจะได้ 403)

```bash
# ใน Docker (ใช้ค่าเชื่อมต่อจาก docker-compose.yml)
docker exec -it php_app_web php /var/www/html/aoi-web-php/scripts/check-duplicate-codes.php

# จากเครื่องที่มี PHP + pdo_mysql (ต้องตั้ง DB_USER / DB_PASS เอง โค้ดไม่มีรหัสผ่านสำรองแล้ว)
DB_HOST=127.0.0.1 DB_USER=root DB_PASS='…' php scripts/check-duplicate-codes.php
```

สคริปต์รายงาน 3 ส่วน:

1. Number Code ที่ซ้ำ พร้อมจำนวนแถว รายชื่อชีต และ `created_at` แรก/ล่าสุด
2. แถวที่คอลัมน์ `zone` ไม่ตรงกับโซนของชีตในตาราง `sheets` พร้อม SQL สำหรับแก้ (ถ้าฐานข้อมูลยังไม่ผ่าน migration ที่สร้างตาราง จะข้ามหัวข้อนี้พร้อมคำอธิบาย)
3. สถานะ index `uq_code` / `uq_sheet_code`

Exit code: `0` = ไม่มีอะไรต้องแก้, `2` = พบรหัสซ้ำหรือ zone ไม่ตรง, `1` = ตรวจไม่ได้ (เช่น เชื่อมต่อฐานข้อมูลไม่ได้)

เมื่อสคริปต์ไม่พบรหัสซ้ำแล้ว เว็บจะเพิ่ม `uq_code` ให้เองในการโหลดหน้าเว็บครั้งถัดไป ถ้าเว็บเพิ่งพบรหัสซ้ำภายใน 1 ชั่วโมงที่ผ่านมา สคริปต์จะบอกเวลาที่เว็บจะตรวจใหม่

## Tests

```bash
docker exec php_app_web php /var/www/html/aoi-web-php/tests/number-code-test.php
docker exec php_app_web php /var/www/html/aoi-web-php/tests/topology-test.php
```

`topology-test.php` ทดสอบตาราง zones / sheets, กฎของหน้าจัดการ, การล็อกอินผู้ดูแล (401 / 403 / fail closed), การ escape และไฟล์ที่ห้ามเข้าถึงผ่านเว็บ (PHP built-in server และ Apache ใน container ถ้าเข้าถึงได้) ใช้ฐานข้อมูล `aoi_test_*` เท่านั้น และตรวจตอนจบว่าไม่มีการต่อฐานข้อมูลอื่นโดยไม่ตั้งใจ ไม่ครอบคลุมการคลิกบน browser จริง

- ผลลัพธ์แสดงเป็น PASS / FAIL ตามหัวข้อ (ก)–(ฉ) และจบด้วย exit code `0` เมื่อผ่านทั้งหมด ใช้เวลาประมาณ 1–2 นาที (คำสั่ง DDL ของ MySQL ใน Docker ช้า)
- สร้างฐานข้อมูลชั่วคราว `aoi_test_*` บน MySQL เดียวกันแล้วลบทิ้งเมื่อจบ ไม่แตะ `Master_AoI` (ต้องใช้ user ที่ CREATE/DROP database ได้ เช่น root ใน docker-compose)
- เปิด PHP built-in server ชั่วคราวเพื่อทดสอบการบันทึกผ่าน `api/save.php` และ `index.php`
- ระหว่างทดสอบ GET_LOCK จะถือ lock `aoi_board_number_code` รวมประมาณ 15 วินาที ใครกดบันทึกบน MySQL เดียวกันในช่วงนั้นจะได้ข้อความให้กดบันทึกอีกครั้ง
