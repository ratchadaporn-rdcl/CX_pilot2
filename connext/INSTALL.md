# CONNEXT — คู่มือติดตั้งบน server จริง

ชุดติดตั้งนี้สร้างจากแอปที่ใช้งานอยู่บนเครื่อง dev (`C:\xampp\htdocs\connext`) รวม patch ทั้งหมดถึงวันที่สร้าง
และฐานข้อมูลจริงทั้งหมด (ผู้ใช้ · ไซต์ · ประตู · รหัส Mango/LLP/IC · ยอดสต๊อก · เอกสาร · รูปถ่าย)

```
connext-deploy-<วันที่>/
├── INSTALL.md                       ← ไฟล์นี้
├── connext/                         ← โฟลเดอร์เว็บทั้งหมด (อัปโหลดขึ้น server)
│   ├── settings/config.php          ← env=production พร้อมใช้
│   ├── settings/database.php.example ← คัดลอกเป็น database.php แล้วกรอกค่า DB
│   ├── settings/qr_secret.php       ← รหัสลับ QR (ชุดเดียวกับเครื่อง dev)
│   ├── uploads/photos/              ← รูปถ่ายเอกสารเดิม (อ้างอิงจาก DB)
│   └── db/db_setup.sql + db/source_data/  ← ใช้เฉพาะกรณีติดตั้งเปล่าผ่านตัวติดตั้ง (ทางเลือก B)
└── database/connext-full-<วันที่>.sql ← dump ฐานข้อมูล connext (โครงสร้าง + ข้อมูล · utf8mb4)
```

---

## 1. สิ่งที่ server ต้องมี

| รายการ | ค่าที่ต้องการ | หมายเหตุ |
|---|---|---|
| PHP | **7.4 ขึ้นไป** (เครื่อง dev ใช้ 8.2) | โค้ดเขียนให้ใช้ได้กับ 7.4 |
| PHP extension บังคับ | `pdo_mysql` `mbstring` `fileinfo` | ตัวติดตั้งตรวจให้ |
| PHP extension ที่ควรมี | `zip` (นำเข้า/ส่งออก .xlsx) · `curl` (แจ้งเตือน Teams/อีเมล · Gemini OCR) · `gd` (รูป/PDF) · `openssl` | ไม่มี = ฟีเจอร์นั้นใช้ไม่ได้ แต่ระบบหลักทำงาน |
| php.ini | `upload_max_filesize` และ `post_max_size` ≥ 20M · `memory_limit` ≥ 256M · `max_execution_time` ≥ 120 | PDF หลายใบพร้อมรูป / นำเข้า xlsx ใช้เวลานาน |
| ฐานข้อมูล | MariaDB 10.4+ หรือ MySQL 5.7+ · charset `utf8mb4` | dump มาจาก MariaDB 10.4.32 |
| Web server | Apache + `mod_rewrite` + `AllowOverride All` | ใช้ `.htaccess` กันเข้า `settings/` `db/` `lib/` — ถ้าเป็น Nginx ดูข้อ 7 |
| โฟลเดอร์ที่ PHP ต้องเขียนได้ | `settings/` `uploads/` `pdf/imgcache/` `pdf/fontcache/` | ดูข้อ 4 |
| cron / Task Scheduler | รัน `cron/jobs.php` ทุก 1 นาที | ดูข้อ 6 |
| เวลาเครื่อง | ไม่บังคับ — โค้ดบังคับ `Asia/Bangkok` และ `SET time_zone='+07:00'` เอง | |

---

## 2. อัปโหลดไฟล์

1. อัปโหลดโฟลเดอร์ `connext/` ทั้งก้อนไปยังตำแหน่งเว็บ เช่น
   - `/var/www/html/connext/` → เปิดที่ `https://โดเมน/connext/`
   - หรือวางเป็น document root ของโดเมนย่อย เช่น `https://connext.บริษัท.co.th/` ก็ได้
     (โค้ดคำนวณ `APP_BASE` จากตำแหน่งจริง ไม่ hard-code path — แต่ดูหมายเหตุ cron ข้อ 6)
2. ตรวจว่าไฟล์ซ่อนถูกอัปโหลดด้วย: `.htaccess` (ชั้นนอกและใน `settings/`, `db/source_data/`), `.dist-marker`
   (FTP client บางตัวซ่อนไฟล์ที่ขึ้นต้นด้วยจุด)

## 3. สร้างฐานข้อมูลและ import ข้อมูล (ทางเลือก A — แนะนำ)

```sql
CREATE DATABASE connext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'connext'@'localhost' IDENTIFIED BY '<รหัสผ่านยาว ๆ>';
GRANT ALL PRIVILEGES ON connext.* TO 'connext'@'localhost';
FLUSH PRIVILEGES;
```

```bash
mysql -u connext -p connext < database/connext-full-<วันที่>.sql
```

- ไฟล์ dump มี `DROP TABLE IF EXISTS` ทุกตาราง → import ซ้ำได้ (ทับข้อมูลเดิมทั้งหมด)
- ตาราง `remember_tokens` และ `login_attempts` มาเฉพาะโครงสร้าง (ไม่เอา session ค้างจากเครื่อง dev)
- ถ้า import ผ่าน phpMyAdmin แล้วติด timeout ให้ใช้ command line หรือแบ่งไฟล์ (ประมาณ 25–30 MB)

แล้วสร้างไฟล์ตั้งค่า DB:

```bash
cd connext/settings
cp database.php.example database.php
```

แก้ `host` `dbname` `user` `pass` ให้ตรงกับที่สร้างไว้ (ไฟล์นี้ **ห้าม** ใช้ root บน server จริง)

> **ทางเลือก B — ติดตั้งเปล่า (ไม่เอาข้อมูลจากเครื่อง dev):** ลบ `settings/config.php` และอย่าสร้าง `database.php`
> แล้วเปิด `https://โดเมน/connext/` ระบบจะพาเข้า `install/` (ตรวจระบบ → ตั้งชื่อ/ผู้ดูแล → DB → ติดตั้งจาก `db/db_setup.sql`)
> จากนั้นค่อยนำเข้า CSV ด้วย `php db/import_from_sheet.php --fresh` และไฟล์ LLP/IC ตามคู่มือใน README-LOCAL.md
> ⚠ ทาง B จะ **ไม่มี** ผู้ใช้/รหัส IC/ยอดสต๊อก/เอกสาร/การตั้งค่าตู้ที่ทำไว้ในเครื่อง dev — ใช้ทาง A เว้นแต่ตั้งใจเริ่มใหม่

## 4. สิทธิ์โฟลเดอร์ (Linux)

```bash
cd /var/www/html/connext
chown -R www-data:www-data .          # หรือ user ที่ PHP-FPM / Apache ใช้
find . -type d -exec chmod 755 {} \;
find . -type f -exec chmod 644 {} \;
chmod 775 settings uploads uploads/photos pdf/imgcache pdf/fontcache
chmod 600 settings/*.php
```

`settings/` ต้องเขียนได้เพราะระบบเก็บ marker สคีมา (`.xxx-schema-v1-<db>`), lock ของงานตั้งเวลา และ `jobs.log` ไว้ที่นั่น
`pdf/fontcache/` ว่างมาในชุดนี้ — dompdf จะสร้าง metrics ฟอนต์ Sarabun ใหม่ตอนออก PDF ครั้งแรก (ช้ากว่าปกติครั้งเดียว)

## 5. เปิดใช้ครั้งแรก

1. เปิด `https://โดเมน/connext/` → ต้องเห็นหน้า login (ถ้าเด้งไป `install/` แปลว่า `settings/database.php` ยังไม่มี)
2. เข้าด้วยบัญชีเดิมจากเครื่อง dev เช่น `admin` (ADM) — **เปลี่ยนรหัสผ่าน admin ทันที** (ค่าเดิมคือรหัสทดสอบ)
   ผู้ใช้อื่น 43 คนใช้รหัสผ่านเดิมตามชีต
3. ตั้งค่าระบบ (admin.php) → ตรวจ/กรอก
   - แท็บ **ประตู** → ความปลอดภัยตู้ / QR: ออก **API key ต่อตู้** ใหม่สำหรับ server นี้แล้วนำไปใส่ในโปรแกรมตู้
     (key กลางใน `settings/config.php` ยังใช้ได้ระหว่างเปลี่ยน — ปิดได้เมื่อทุกตู้มี key ของตัวเอง)
   - แท็บ **ประตู** → แจ้งเตือน R&D: `notify_teams_url` · `notify_email_to` · SMTP (`smtp_host/port/secure/user/pass/from`)
     ค่าเหล่านี้ **ว่างมาใน dump** (เครื่อง dev ไม่ได้ตั้ง) — กดปุ่มทดสอบหลังกรอก
   - `settings/gemini.php` → `api_key` / `model` ถ้าจะใช้ OCR ใบ PO (ว่างมา · ทดสอบที่ `po_ocr_test.php`)
4. ทดลอง: สร้างใบเบิก 1 ใบ → อนุมัติ → เปิด QR → ส่งออก PDF (ตรวจฟอนต์ไทย) → ดูหน้าประวัติ

## 6. งานตั้งเวลา (cron) — จำเป็น

ตรวจตู้ออฟไลน์ · reconcile รอบจ่าย · ใบยืมเกินกำหนด · สุ่มนับสต๊อกรายสัปดาห์ อยู่ใน `lib/jobs.php` และต้องมีตัวเรียกทุก 1 นาที

```bash
# crontab -e  (ใช้ user เดียวกับ web server หรือ user ที่เขียน settings/ ได้)
* * * * * /usr/bin/php /var/www/html/connext/cron/jobs.php >/dev/null 2>&1
```

Windows Server:
```
schtasks /Create /TN "CONNEXT jobs" /SC MINUTE /MO 1 /TR "C:\php\php.exe C:\inetpub\connext\cron\jobs.php" /RU SYSTEM
```

- งาน reconcile เรียก `api/gate.php` ของเครื่องตัวเองผ่าน `http://127.0.0.1` + `APP_BASE`
  ตอนรันจาก CLI โค้ดเดาว่าแอปอยู่ที่ `/connext/` — **ถ้าติดตั้งไว้ path อื่น** (เช่น document root ของโดเมนย่อย หรือ HTTPS อย่างเดียว)
  ให้ตั้งค่า `internal_base_url` เป็น URL ที่เครื่องเรียกตัวเองได้ เช่น `http://127.0.0.1/` หรือ `https://connext.บริษัท.co.th`:
  ```sql
  INSERT INTO app_settings (skey, svalue, updated_by) VALUES ('internal_base_url', 'http://127.0.0.1/', 'install')
  ON DUPLICATE KEY UPDATE svalue = VALUES(svalue);
  ```
- ทดสอบรันมือ: `php cron/jobs.php --force` → ต้องพิมพ์ JSON ผลงาน 1 บรรทัด และต่อท้าย `settings/jobs.log`
- ถ้าไม่มี cron: ใบยืมเกินกำหนดยังถูกตรวจกับคำขอแรกของวัน (ใน `config.php`) แต่ตู้ออฟไลน์/reconcile จะไม่ทำงาน

## 7. ถ้าใช้ Nginx (ไม่อ่าน .htaccess)

ต้องกันเข้าโฟลเดอร์เหล่านี้เองใน server block:

```nginx
location ~ ^/connext/(settings|db|lib|cron|pdf/(fonts|templates))/ { deny all; }
location ~ /connext/.*\.(sql|log|md|ini|sh)$ { deny all; }
location ~ \.php$ { fastcgi_pass ...; }
```

และเพิ่ม header `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN` ตาม `.htaccess`

## 8. ตู้ (gate cabinet) ชี้มาที่ server ใหม่

โปรแกรมตู้ `Pilot2/Hardware/connext_access.py` มีค่า `BACKEND_URL` ชี้ไปที่เครื่อง dev อยู่ → แก้เป็น
`https://โดเมน/connext/api/gate.php` และใส่ API key ของตู้นั้น (ข้อ 5.3) แล้วรีสตาร์ทโปรแกรมทุกตู้
ตู้ต้องเข้าถึง server ได้ทาง HTTPS (หรือ HTTP ใน LAN) และส่งสัญญาณชีพได้ — ดูที่ ตั้งค่าระบบ → ประตู → สถานะตู้

## 9. หลังติดตั้ง — ตรวจสอบ

- [ ] `settings/config.php` → `env` = `production` (ไม่โชว์ error บนหน้าเว็บ)
- [ ] เปิด `https://โดเมน/connext/settings/config.php` ต้องได้ **403** (ถ้าเห็นหน้าว่าง/โค้ด = .htaccess ไม่ทำงาน → ข้อ 7)
- [ ] เปิด `https://โดเมน/connext/db/db_setup.sql` ต้องได้ 403
- [ ] `php cron/jobs.php --force` รันผ่าน และ cron ทำงาน (ดู `settings/jobs.log` มีบรรทัดใหม่ทุกนาที)
- [ ] ส่งออก PDF 1 ใบ ฟอนต์ไทยถูกต้อง · รูปถ่ายเอกสารเก่าเปิดได้ (ใน `uploads/photos/2026-09`, `2026-10`)
- [ ] ตู้แต่ละตู้ขึ้นสถานะออนไลน์ใน ตั้งค่าระบบ → ประตู
- [ ] เปลี่ยนรหัสผ่าน `admin` แล้ว
- [ ] ตั้ง backup ฐานข้อมูลรายวัน (`mysqldump connext`) และ backup `uploads/photos/`

## 10. ย้อนกลับ / อัปเดตรอบถัดไป

- ชุดนี้ใช้แทนของเดิมทั้งหมด: อัปเดตรอบหน้า = ทับโฟลเดอร์ `connext/` ด้วยชุดใหม่ **ยกเว้น** `settings/` และ `uploads/`
  (ตรวจ README ของ patch ว่ามีการเปลี่ยนสคีมาหรือไม่ — สคีมาใหม่ส่วนใหญ่สร้างเองตอนเปิดใช้ครั้งแรกผ่าน `config.php`)
- ก่อนทับทุกครั้ง: `mysqldump connext > backup-<วันที่>.sql` และสำเนา `uploads/`
- ชุดนี้สร้างด้วย `Software/connext-local/dist/make_deploy.py` บนเครื่อง dev — รันใหม่ได้ทุกครั้งที่จะส่งรอบถัดไป
