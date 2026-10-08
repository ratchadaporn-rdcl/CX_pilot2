# CONNEXT — เอกสารระบบ (System Documentation)

**ระบบ:** CONNEXT — CONstruction Node for EXchange & Tracking · Inventory Control Module
**โครงการ:** Pilot 2 (ไซต์หลัก ARI — เวีย อารีย์)
**ฉบับ:** 8 ตุลาคม 2569 (2026-10-08) · อิงโค้ดจริงบนเครื่อง `C:\xampp\htdocs\connext` และแพตช์ใน `Software/connext-local/patches/`
**ผู้จัดทำ:** รวบรวมจากการอ่านโค้ด ฐานข้อมูล และ README ของทุกแพตช์ (ไม่ได้แก้ไขระบบ)

> เอกสารนี้อธิบาย "ระบบที่เป็นอยู่จริงวันนี้" ไม่ใช่สเปกที่ตั้งใจไว้ จุดที่โค้ดกับเอกสารออกแบบ (Doc 05, Test Cases) ไม่ตรงกัน หรือที่ยังตัดสินใจไม่เสร็จ รวมไว้ในบทที่ 21

---

## สารบัญ

1. ภาพรวมระบบ
2. สถาปัตยกรรมและเส้นทางของคำขอ (request flow)
3. ผู้ใช้ บทบาท และสิทธิ์
4. โครงสร้างฐานข้อมูล
5. ข้อมูลหลักวัสดุ — L1/L2/LLP/IC และ Mango
6. เอกสารและวงจรชีวิต — RD · OD · BD · IN · TD · TG · SC
7. การอนุมัติ ตีกลับให้แก้ และยกเลิก
8. สต๊อก — ยอดคงเหลือ การจอง รายประตู ยอดติดลบ
9. การหยิบและถ่ายรูปยืนยัน (Scenario 05) · รอบจ่าย · ประวัติรอบหยิบ
10. ยืม-คืนอุปกรณ์ (BD)
11. รับเข้าคลัง (IN) · ใบสั่งซื้อ/Buffer · OCR
12. โอนย้ายข้ามไซต์ (TD) · ย้าย Gate (TG) · นับสต๊อก (SC)
13. Bypass
14. ตู้ประตู (Gate cabinet) และ API ของตู้
15. งานผู้รับเหมา — ตรวจสอบประจำวัน · ใบหักเงิน · สแกนนิ้ว · หักคจช. · ลงนามอิเล็กทรอนิกส์
16. รายงาน PDF · ประวัติ · Excel/CSV · Dashboard insights
17. งานอัตโนมัติ (jobs) และการแจ้งเตือน
18. ส่วนหน้าจอ — หน้าในแอป add-on ธีม มือถือ PWA
19. การติดตั้ง การดูแลระบบ และวิธีแก้ไข (patch convention)
20. ประวัติการเปลี่ยนแปลง (ตามลำดับเวลา)
21. รายการค้าง ประเด็นเปิด และข้อสังเกตจากการตรวจโค้ด
22. อภิธานศัพท์
- ภาคผนวก ก. ดัชนีฟังก์ชัน RPC · ข. ดัชนีไฟล์ · ค. คีย์ตั้งค่า

---

## 1. ภาพรวมระบบ

### 1.1 ระบบทำอะไร

CONNEXT เป็นระบบควบคุมสโตร์วัสดุที่ไซต์ก่อสร้าง ครอบคลุม

- **การเบิก-จ่ายวัสดุ** ผ่านเอกสาร (ใบเบิกหลัก RD, เบ็ดเตล็ด OD, ยืม-คืน BD, รับเข้า IN, โอนย้ายข้ามไซต์ TD, ย้าย Gate TG, นับสต๊อก SC) พร้อมสายอนุมัติตามระดับบทบาท
- **ตู้ประตูสโตร์ (gate cabinet)** — ตู้ควบคุมประตูที่ไซต์ (Raspberry Pi + หัวอ่านบัตร/QR + กลอนแม่เหล็ก + ไฟสัญญาณ + กล้อง) ผู้เบิกสแกน QR ของเอกสารแล้วแตะบัตร ตู้เปิดประตูให้หยิบของ ถ่ายรูปยืนยันบนมือถือ ปิดประตูแล้วระบบตัดสต๊อก
- **สต๊อกรายไซต์และรายประตู** รวมถึงการจองของจากใบที่อนุมัติแล้ว ยอดติดลบ Min-Max รอบจ่าย
- **ข้อมูลหลักวัสดุ** ตามบันได L1 → L2 → LLP → IC (รหัส 20 หลัก) และการผูกกับรหัส Mango (ERP เดิม)
- **จัดซื้อ** — ใบสั่งซื้อจาก OCR (Gemini) → buffer → รับเข้าคลังเป็นรหัส IC
- **งานผู้รับเหมา** — ตรวจสอบประจำวัน ใบหักเงินค่าวัสดุ บันทึกสแกนนิ้ว หักค่าใช้จ่าย คจช. และการลงนามอิเล็กทรอนิกส์
- **รายงาน PDF** (ขาว-ดำ) และประวัติ

### 1.2 ที่มา

ระบบเดิมเป็น Google Apps Script (GAS) + Google Sheets ถูกพอร์ตมาเป็น PHP + MariaDB (build 2026-08-28 โดย `make_dist.php`) และติดตั้งใช้งานบนเครื่องนี้เมื่อ 2026-09-21 หน้าจอหลัก `index.php` ยังเป็นไฟล์ที่สร้างจาก GAS (`tools/build_index.py` — ไม่มีบนเครื่องนี้) ฟีเจอร์ที่เพิ่มหลังพอร์ตทำเป็น **add-on JS/CSS** ที่ครอบ (wrap) โค้ดเดิม ไม่แก้โค้ด GAS โดยตรง ยกเว้นบรรทัดที่ติดป้าย `[PHP port …]`

### 1.3 สภาพแวดล้อมที่ใช้งานจริง

| รายการ | ค่า |
|---|---|
| เว็บแอป | `C:\xampp\htdocs\connext` → http://localhost/connext/ (ตู้เรียกผ่าน IP เครื่องนี้) |
| เว็บเซิร์ฟเวอร์ | Apache 2.4.58 (XAMPP, ไม่ได้ติดตั้งเป็น service — start จาก Control Panel) |
| PHP | 8.2.12 (โค้ดเขียนให้รันได้บน PHP 7.4 — ห้ามใช้ syntax PHP 8 มี polyfill ใน `helpers.php`) |
| ฐานข้อมูล | MariaDB 10.4.32 · db `connext` (ใช้งานจริง) · `connext_test` (ทดสอบ) · utf8mb4_unicode_ci · time zone +07:00 |
| PDF | dompdf 2.0.8 (`includes/dompdf`) + ฟอนต์ SarabunPdf (`pdf/fonts`) |
| OCR | Google Gemini API (ยังไม่ได้ใส่ key/model บนเครื่องนี้) |
| ตู้ประตู | Cytron IRIV PiControl (Raspberry Pi CM) + Python/Tk `connext_access.py` |
| ไซต์ในระบบ | 55 โครงการ (ใช้จริง: ARI เวีย อารีย์ · STX สแตนดาร์ด เอ็กซ์ โฮเทล บางเทา ภูเก็ต · HO สำนักงานใหญ่ · `0000` ส่วนกลาง) |
| ประตู | ARI G01, G02 (มีตู้ + key รายตู้ + heartbeat) · ARI G03 (ตั้งเป็นมีตู้ แต่ยังไม่เคยส่ง heartbeat) · HO G01 |

### 1.4 ขนาดของโค้ด

~58,000 บรรทัด: `index.php` 20,166 บรรทัด (1 MB, GAS-generated) · `lib/*.php` 60 ไฟล์ · `api/*.php` 6 ไฟล์ · หน้า PHP เดี่ยว 14 หน้า · `js/*.js` 18 ไฟล์ · `css/*.css` 21 ไฟล์ · template PDF 8 ไฟล์ · ตารางฐานข้อมูล 64 ตาราง (62 จาก `db_setup.sql` + 2 สร้างอัตโนมัติ)

### 1.5 ข้อมูลในระบบ ณ วันจัดทำ

เอกสาร 704 ใบ / 1,609 รายการ · ผู้ใช้ 43 · บทบาท 40 · ชุดผู้รับเหมา 340 · ประตู 4 · รหัส Mango 6,814 · L1 11 · L2 72 · LLP 2,818 · IC 6,697 · ยอดสต๊อก 321 รายการ (รายประตู 222) · activity log 951 · error log 236

---

## 2. สถาปัตยกรรมและเส้นทางของคำขอ

### 2.1 ภาพรวม

```
เบราว์เซอร์ (index.php — GAS UI + add-on JS)
   │  google.script.run.<fn>(args)   ← js/gas-shim.js แปลงเป็น
   ▼  POST api/rpc.php {fn, args}  + header X-CSRF-Token
api/rpc.php ──► lib/registry.php (ชื่อ GAS → ไฟล์ lib + rpc_<fn>) ──► lib/*.php ──► PDO (MariaDB)
   │
   ├─ api/gate.php          ← ตู้ประตู (key รายตู้ / key กลาง, ไม่ใช้ session)
   ├─ api/history_report.php← PDF รายงานประวัติ
   ├─ api/ic_api.php, rc_api.php, setup_master_api.php ← หน้า PHP เดี่ยว (POST csrf)
   ├─ หน้า PHP เดี่ยว (admin.php, po.php, rc.php, …) ← lib/ui.php (เปลือกร่วม) บางหน้าฝังใน index.php เป็น iframe
   └─ cron/jobs.php (CLI) ← lib/jobs.php
```

### 2.2 `config.php` — จุดเริ่มของทุก entry point

1. ถ้าไม่มี `settings/config.php` หรือ `settings/database.php` → redirect ไป `install/index.php`
2. ตั้ง timezone `Asia/Bangkok`, โหลด `$APP_SETTINGS`, `$DB_SETTINGS`
3. ค่าคงที่ `APP_NAME` (ค่าปริยาย CONNEXT), `APP_VERSION = '1.0.0'`, `APP_ENV` (development บนเครื่องนี้ → แสดง error), `APP_BASE` (โฟลเดอร์ย่อย เช่น `/connext`), `ROOT_PATH`, `APP_BUILD` (hash จาก mtime ของ index.php — ใช้แจ้ง "มีเวอร์ชันใหม่" ฝั่ง client)
4. ต่อ PDO (`utf8mb4`, ERRMODE_EXCEPTION, FETCH_ASSOC, EMULATE_PREPARES=false, `SET time_zone='+07:00'`) ต่อไม่ได้ → HTTP 500 "ไม่สามารถเชื่อมต่อฐานข้อมูลได้ กรุณาติดต่อผู้ดูแลระบบ"
5. `require helpers.php`
6. เรียก **ensure-schema** 8 ชุด (ดู 4.1) — ทำงานจริงครั้งเดียวต่อฐานข้อมูล แล้วเขียน marker ใน `settings/.<name>-schema-vN-<db>`
7. `borrowDailyTick()` — รันงานยืมเกินกำหนดในคำขอแรกของแต่ละวัน

> ข้อควรระวัง: การเพิ่ม ensure-schema ใหม่ใน config.php จะ migrate ฐานข้อมูลจริงทันทีที่มีคำขอถัดไป (เบราว์เซอร์ที่เปิดค้างยิง poll ทุกนาที) — ต้องสำรองฐานข้อมูลก่อนบันทึกไฟล์

### 2.3 Session / CSRF / การเข้าสู่ระบบ (`auth.php`, `lib/auth_api.php`)

- Session cookie: path = APP_BASE, `httponly`, `SameSite=Lax`, `secure` เฉพาะ HTTPS, `use_strict_mode`, อายุ 24 ชม.
- CSRF: token 32 ไบต์ใน `$_SESSION['csrf_token']` ส่งผ่าน `<meta name="csrf-token">` → header `X-CSRF-Token` ทุก RPC · ไม่ตรง → HTTP 403 `{code:'csrf', csrf:<token ใหม่>}` และ shim จะเก็บ token ใหม่แล้วลองซ้ำ 1 ครั้ง (แก้ปัญหา logout แล้ว login ใหม่โดยไม่รีโหลด — แพตช์ 10-02)
- Login (`rpc_checkLogin`): จำกัด 5 ครั้ง / 15 นาที ต่อ IP หรือ username (`login_attempts`) → ลอง `users` ก่อน แล้ว fallback ไป `subcontractors` ด้วย `sub_code` 3 หลัก · รหัสผ่านเก็บ bcrypt; ค่าเก่าที่เป็น plaintext จะถูก re-hash อัตโนมัติเมื่อ login สำเร็จ
- นโยบายรหัสผ่าน: ไม่ว่าง ไม่มีอักษรไทย ไม่มีช่องว่าง (installer บังคับ ≥ 4 ตัวเพิ่ม)
- Remember me: cookie `connext_remember` (`selector:validator`) อายุ 30 วัน เก็บ sha256 ใน `remember_tokens` และหมุน token ทุกครั้งที่ใช้
- `establishSession` สร้าง `$_SESSION['connext_user']`: `username, fullName, role, roleId, roleLevel ('R'+level), siteCode, canReq, canDaily, canSC, canBS, accountType (user|subcontractor), accountId, projectId` — ผู้รับเหมาได้ `roleLevel='R1'` และทุก flag เป็น false
- **สิทธิ์ที่เก็บใน session จะมีผลหลัง login ใหม่** (แต่ฟังก์ชันสำคัญ เช่น การอนุมัติ จะ re-query ฐานข้อมูลสด)

### 2.4 กลไก RPC (`api/rpc.php`, `lib/registry.php`, `js/gas-shim.js`)

- `gas-shim.js` ทำให้ `google.script.run` เป็น ES6 **Proxy**: ทุก property ที่ไม่ใช่ `withSuccessHandler/withFailureHandler/withUserObject` จะกลายเป็นฟังก์ชันที่ `fetch(APP_BASE+'/api/rpc.php')` แบบ POST JSON `{fn, args}`
  - ผลลัพธ์ `{ok:true, result}` → success handler `(result, userObject)`
  - `{ok:false, code:'auth'}` หรือ HTTP 401 → ยิง event `connext:session-expired` (php-port.js แสดง login ซ้อน) แล้วเรียก failure handler
  - **กับดัก:** Proxy คืนฟังก์ชันให้ทุกชื่อ property — ห้ามเก็บ flag บน `google.script.run` (ใช้ `google.script.__flag` แทน) และการครอบฟังก์ชันต้อง re-proxy `withSuccessHandler/…`
- `api/rpc.php`: รับเฉพาะ POST → ตรวจ CSRF ทุกคำขอ → อ่าน `{fn,args}` → `fn` ต้องอยู่ใน registry → ถ้าไม่ใช่ฟังก์ชันสาธารณะ (`checkLogin, getAppBuild, getSignPageData, submitContractorSignature`) ต้อง login → `require_once lib/<file>` → `call_user_func('rpc_<fn>', $pdo, $user, $args)`
  - `RpcUserError` → `{ok:true, result:{success:false, message}}` (ข้อผิดพลาดเชิงธุรกิจ แสดงให้ผู้ใช้)
  - Throwable อื่น → error_log + `{ok:false, error:'Server error'}`
  - **กติกาออกแบบ:** เซิร์ฟเวอร์เอา username / site / role จาก session เสมอ อาร์กิวเมนต์จาก client เป็นได้แค่ตัวกรองหรือข้อมูล
- `lib/registry.php`: map ชื่อฟังก์ชัน GAS 136 ชื่อ → `[ไฟล์ใน lib, 'rpc_<ชื่อ>']` (ดัชนีเต็มในภาคผนวก ก.)
- ทุก `rpc_*` มีลายเซ็น `(PDO $pdo, ?array $user, array $args)`

### 2.5 endpoint อื่นที่ไม่ผ่าน RPC

| ไฟล์ | ใช้โดย | การยืนยันตัวตน |
|---|---|---|
| `api/gate.php` | ตู้ประตู | key รายตู้ (SHA-256 ใน `gates.api_key_hash`) หรือ key กลาง `gate_api_key` ใน `settings/config.php` หรือ job token (HMAC รายชั่วโมง) — ไม่ใช้ session/CSRF (รายละเอียดบทที่ 14) |
| `api/history_report.php` | หน้าประวัติ → Export รายงาน | `requireLogin()` + `X-CSRF-Token` → คืน PDF binary |
| `api/ic_api.php`, `api/rc_api.php`, `api/setup_master_api.php` | หน้า ic_new / rc / setup_master | `uiApiGuard()` (CanReq หรือ R0) + POST field `csrf` (ผิด → HTTP 419) |
| `cron/jobs.php` | Task Scheduler | CLI เท่านั้น (เว็บ → 403) |
| `sign.php` | ผู้รับเหมาลงนามผ่านลิงก์ | token 40 hex ใน URL (ไม่ login) |

### 2.6 เปลือกร่วมของหน้า PHP เดี่ยว (`lib/ui.php`, มติ 15)

- `uiGuard()` — ไม่ login → redirect index.php (ถ้าฝังเป็น iframe `embed=1` → แสดง "session หมดอายุ…" + ปุ่ม "โหลดแอปใหม่") · ไม่มี CanReq และไม่ใช่ R0 → 403 "หน้านี้สำหรับสายคลังเท่านั้น (สิทธิ์ CanReq)"
- `uiIsAdmin($user)` = `roleLevel === 'R0'`
- `uiNav()` เมนูโมดูล: "ใบคุม (PO)" → "รับของ/buffer" → "Mango → IC" → "จัดการรหัสวัสดุ" (R0) → "ตั้งค่าระบบ" (R0, ไม่ฝัง)
- `uiHead()` ใส่ตัวแปรธีมชุดเดียวกับ index.php (ฟอนต์ Prompt, `--primary #1e3a8a`), CSS ธีม/แบรนด์/dark, topbar แสดงชื่อ + `roleId · siteCode`
- เมื่อฝังใน index.php (`po.php?embed=1`, `admin.php?embed=1`) หน้าลูกส่ง `postMessage {cnxFrameHeight}` ให้แม่ปรับความสูง (ต่ำสุด 240px สูงสุด 40,000px)

### 2.7 ความปลอดภัยระดับไฟล์ (`.htaccess`)

- `Options -Indexes`; header `nosniff`, `X-Frame-Options SAMEORIGIN`, `Referrer-Policy strict-origin-when-cross-origin`
- ปฏิเสธ `.sql .log .md .json.dist .ini .sh` (ยกเว้น `manifest.json`); mod_rewrite คืน 403 สำหรับ `settings/`, `db/`, `lib/`, `pdf/(fonts|templates)/`
- `uploads/.htaccess` ห้ามรัน PHP — แต่ไฟล์รูป/ลายเซ็น/PDF ใน uploads **เปิดได้ตรง ๆ ถ้ารู้ชื่อไฟล์** (ชื่อสุ่ม 16–32 ไบต์) ไม่มีการตรวจสิทธิ์
- ไม่มี `settings/.htaccess` ตามที่ comment อ้าง — การป้องกันพึ่ง mod_rewrite ที่ root เท่านั้น

### 2.8 PWA

- `manifest.json`: ชื่อ "CONNEXT | Inventory Control Module", standalone, portrait, theme `#1e3a8a`, ไอคอน 144/192/512
- `sw.js` (cache `connext-v1`): precache ฟอนต์ Prompt 3 ไฟล์ + ไอคอน · ไม่แคช `/api/` และคำขอที่ไม่ใช่ GET · navigation และไฟล์ static แบบ network-first · ลงทะเบียนโดย `php-port.js` (`updateViaCache:'none'`, รีโหลดครั้งเดียวเมื่อ controller เปลี่ยน)

---

## 3. ผู้ใช้ บทบาท และสิทธิ์

### 3.1 ประเภทบัญชี

| ประเภท | ตาราง | เข้าระบบด้วย | ระดับ | หมายเหตุ |
|---|---|---|---|---|
| ผู้ใช้ (staff) | `users` + `roles` + `projects` | username | ตาม `roles.level` 0–12 | ผูกไซต์เดียว (`users.project_id`) · มี `card_id` (RFID) สำหรับแตะที่ตู้ |
| ผู้รับเหมา (subcontractor) | `subcontractors` + `sub_projects` | `sub_code` 3 หลัก (เช่น 002) | R1 คงที่ ทุก flag = false | ไซต์ = แถวแรกใน `sub_projects` ที่ `enabled=1` (ถ้าหลายไซต์ใช้แถวแรก — มติจุดที่ 13) · ชื่อที่ใช้ในเอกสาร = ชื่อชุด (fullName) |

### 3.2 บทบาทและระดับ (`roles`)

คอลัมน์: `role_code` (unique) · `name` · `level` 0–12 · flag 4 ตัว: `can_req` (สายคลัง/สายสโตร์) · `can_daily_check` (ตรวจสอบประจำวัน) · `sc` (เลขาไซต์) · `bs` (ผู้ดูแลผู้รับเหมา) · ระดับ 99 ในโค้ดหมายถึง "ไม่พบ/ไม่รู้จัก"

บทบาทที่มีในระบบ (40 บทบาท):

| ระดับ | รหัสบทบาท | flag |
|---|---|---|
| R0 | **ADM** (Administrator — สร้างตอนติดตั้ง), RD (R&D Engineer) | ADM: can_req, can_daily_check, sc, bs ทั้งหมด |
| R1 | **AST** (Asst. Store), **ST1** (Store 1), SV1, MTN, OPR, CB | AST/ST1: can_req + can_daily_check |
| R2 | **ST2** (Store 2), SC1 (Site Secretary 1), SV2, M&E | ST2: can_req + daily · SC1: daily |
| R3 | **SST** (Senior Store), SC2, SSV, SM&E | SST: can_req + daily · SC2: daily |
| R4 | SE1, OE1, FM1, SSC1 | SSC1: daily |
| R5 | SE2, OE2, FM2, SSC2 | SSC2: daily |
| R6 | SSE1, SOE1, GF1 | — |
| R7 | SSE2, SOE2, SF1, SF2, SSF, GF2 | — |
| R8 | APE, ASM | — |
| R9 | PE, SM, SL | — |
| R10 | APM | — |
| R11 | **PM** (Project Manager) | — |
| R12 | PD | — |

หมายเหตุ: flag `sc`/`bs` ไม่ได้มาจากไฟล์นำเข้า ต้องตั้งใน `admin.php` · ระดับถูกจำกัด 0–12 ใน admin.php · ระบบไม่ยอมให้แก้จนไม่เหลือผู้ใช้ระดับ 0 ที่ active แม้แต่คนเดียว

### 3.3 ความหมายของกลุ่มที่โค้ดใช้บ่อย

| คำในโค้ด/README | นิยาม |
|---|---|
| **R0 / ADM** | `level = 0` — ผู้ดูแลระบบ/R&D: เข้า admin.php, setup_master, bypass, เห็นทุกไซต์ (เลือกไซต์ได้), รับแจ้งเตือนระบบ · **อนุมัติเอกสารไม่ได้** · ไม่ได้ auto-approve RD (ต้องเลือกผู้อนุมัติ R6+) |
| **สายสโตร์ / สายคลัง / store / CanReq** | `roles.can_req = 1` และ level 1–98 (AST, ST1, ST2, SST) — `s05UserIsStore()`: canReq ใน session **หรือ** ชื่อบทบาทมีคำว่า "store" **หรือ** re-check ฐานข้อมูล · ทำได้: ออกใบ IN, สร้างและแตะบัตรใบ TG, ตีชำรุด/สูญหาย, ปิดโปรแกรมตู้, ปิด ALARM 4, ขยายเวลาเกินเพดาน (ถ้าเปิด switch), ยืนยันรูปแทนใบของคนอื่น, แก้ Min-Max ไซต์ตัวเอง, เข้า PO/buffer/IC |
| **PM** | `role_code = 'PM'` และ active ของไซต์นั้น — ผู้อนุมัติ TD เพียงคนเดียว · อนุมัติ/ปฏิเสธใบตัวเองได้ (คนเดียวที่ทำได้) · ส่ง RD แล้ว auto-approve |
| **R4+ / R6+ / R8+** | ระดับขั้นต่ำสำหรับอนุมัติ (ดู 7.2) · R8+ อนุมัติผลนับสต๊อก, ดูบอร์ดรอบจ่าย, Error log, สถานะตู้ทุกไซต์ |
| **SC** (เลขาไซต์) | `roles.sc` — บันทึกสแกนนิ้ว, หักคจช., ดูตั้งค่าผู้รับเหมา (แก้ได้เฉพาะลายเซ็น) |
| **BS** (ผู้ดูแลผู้รับเหมา) | `roles.bs` — ตั้งค่าผู้รับเหมา, ตรวจ/เซ็นสแกนนิ้วรายวัน, ตั้งอัตราค่าปรับ, ตั้งค่าหักคจช. |
| **CanDaily** | `roles.can_daily_check` — หน้าตรวจสอบประจำวัน, ตั้งราคาหักเงิน, ออกเลขใบหักเงิน, ขอลายเซ็น, สร้างใบนับสต๊อก, Dashboard สรุปค่าใช้จ่าย |

### 3.4 ขอบเขตไซต์ (site scoping)

- client: `getEffectiveSiteCode()` คืน `''` (ทุกไซต์) สำหรับ R0 นอกนั้นคืน `user.siteCode`
- server: `hs_effectiveSite()` — R0 ใช้ไซต์ที่ส่งมา ที่เหลือ **บังคับเป็นไซต์ใน session** (รูปแบบเดียวกันใน approval, borrow, subsettings, mango_bal)
- ตัวกรองไซต์บน Dashboard: R0 หรือ level ≥ 8
- `rpc_changeSite`: ทั้งผู้เรียกและเป้าหมายต้องเป็น level 0 (ตรวจสด) และบันทึก `activity_log` `change_site`

### 3.5 กฎสิทธิ์เชิงตัวเลขที่ใช้ซ้ำ

| เรื่อง | กฎ |
|---|---|
| ประวัติเอกสาร / ประวัติรอบ / Export รายงาน | `filterSelf = roleNum ≠ 0 && roleNum ≤ 5` → R1–R5 เห็นเฉพาะใบตัวเอง · R6+ เห็นทั้งไซต์ · R0 ทุกไซต์ · (ประวัติรอบ: สายสโตร์เห็นทุกรอบของไซต์) |
| คิวอนุมัติ | R0 เห็นทั้งหมดแบบอ่านอย่างเดียว · R1–R3 และผู้รับเหมาเห็นเฉพาะที่ตัวเองส่ง · R4+ เห็นทั้งไซต์ · `canAct` = ระดับ ≥ ที่ต้องการ และผู้อนุมัติที่ระบุว่างหรือเป็นตัวเอง |
| รายชื่อผู้อนุมัติให้เลือก | ไซต์เดียวกัน active ไม่ใช่ตัวเอง `(level > ผู้ส่ง หรือ level ≥ 6) และ level ≥ 4 และ < 99` |
| การยืม — ใครเห็นทั้งไซต์ | level 0, 4, 6, 8–98 หรือสายสโตร์ · PDF ชำรุด/สูญหาย: level 0, ≥8, สโตร์, ผู้ยืม, ผู้อนุมัติ, ผู้ที่ได้รับแจ้ง |
| Error log ไซต์ / สถานะตู้ | level 0 หรือ ≥ 8 ทุกไซต์ · canReq เฉพาะไซต์ตัวเอง |
| Min-Max แก้ไข | R0 หรือ canReq ไซต์ตัวเอง |
| QR ของเอกสาร (`getQrPayload`) | level 0 ทุกใบ · สโตร์/R8+ ทั้งไซต์ · อื่น ๆ ใบตัวเอง · ผู้รับเหมา: ใบที่ตนเป็นผู้ขอหรือผู้รับ |

### 3.6 หน้าที่กั้นด้วย flag (ตรง client และ server)

| หน้า | ใครเห็น |
|---|---|
| ตรวจสอบประจำวัน | canDaily หรือ SC (server ตรวจ `can_daily_check` เท่านั้น) |
| หักค่าใช้จ่ายผู้รับเหมา | SC หรือ R0 |
| ตั้งค่า (ผู้รับเหมา) | BS หรือ R0 แก้ได้ · SC ดูและแก้ลายเซ็นได้ |
| บันทึกสแกนนิ้ว | SC, BS หรือ R0 |
| รับของตามใบ PO | canReq หรือ R0 |
| ตั้งค่าระบบ (cnxadmin) | R0 เท่านั้น |
| ผู้ใช้ที่มี SC/BS แต่ไม่มี canReq และไม่ใช่ R0 | ถูกจำกัดให้อยู่เฉพาะหน้ากลุ่มนั้น (`_isScRestricted`) หน้าแรก = dailycheck (SC) หรือ fingerscan |

> `PERMISSIONS[pageId]` ใน index.php ระบุ R0–R12 ให้ทุกหน้า — การกั้นจริงอยู่ที่ flag และฝั่งเซิร์ฟเวอร์

---

## 4. โครงสร้างฐานข้อมูล

ฐานข้อมูล `connext` มี **64 ตาราง** (InnoDB, utf8mb4_unicode_ci): 62 ตารางจาก `db/db_setup.sql` และ 2 ตาราง (`app_settings`, `gate_status`) ที่สร้างโดย ensure-schema เท่านั้น

> `db/db_setup.sql` เป็นไฟล์ที่ **ประกอบขึ้นใหม่** (ต้นฉบับไม่มีใน zip) จาก `db/connext_schema.dbml` (21 ตาราง, ล้าสมัย) + SQL ที่โค้ด PHP ใช้ · ใช้ syntax `ADD COLUMN IF NOT EXISTS` ของ MariaDB (บน MySQL จะไม่ผ่าน) · สำเนาใน `connext-local/` เก่ากว่าตัวจริงใน `htdocs/connext/db/` · DDL ของ TG ยังไม่อยู่ในไฟล์

### 4.1 ตาราง/คอลัมน์ที่สร้างอัตโนมัติ (ensure-schema)

เรียกจาก `config.php` ตามลำดับ ทำงานครั้งเดียวต่อฐานข้อมูลแล้วเขียน marker ใน `settings/`:

| ลำดับ | ฟังก์ชัน (ไฟล์) | marker | สร้าง/แก้ |
|---|---|---|---|
| 1 | `s05EnsureSchema` (lib/s05.php) | `.s05-schema-v1` | `document_items.qty_actual, actual_reason, photo_url, photo_return_url` · ตาราง `gate_settings`, `gate_round_events` |
| 2 | `borrowEnsureSchema` → `borrowEnsureRoundSchema` (lib/borrow.php) | `.borrow-schema-v1`, `-v2` | `documents.due_date, overdue_flag, writeoff_flag` · `document_items.qty_returned, return_reason` · ตาราง `borrow_writeoffs`, `user_notices` · v2: `document_items.qty_return_round` |
| 3 | `appSettingsEnsureSchema` (lib/app_settings.php) | `.appsettings-schema-v1` | ตาราง `app_settings` |
| 4 | `gateSecEnsureSchema` (lib/gate_sec.php) | `.gatesec-schema-v1` | `gates.has_cctv, api_key_hash (unique), api_key_hint, api_key_at, api_key_by` |
| 5 | `ghEnsureSchema` (lib/gate_health.php) | `.gatehealth-schema-v1` | ตาราง `gate_status` |
| 6 | `inCtlEnsureSchema` (lib/inbound_ctl.php) | `.inctl-schema-v1` | `documents.in_source, in_ref, in_reason, in_photo_url` |
| 7 | `docExtEnsureSchema` (lib/doc_ext.php) | `.docext-schema-v1` | `doc_type` ENUM + TD, SC · `documents.dest_project_id` · ตาราง `stock_adjustments` |
| 8 | `gmEnsureSchema` (lib/gatemove.php) | `.gatemove-schema-v1` | `doc_type` ENUM + TG · `documents.dest_gate_id` · `gate_logs.leg` ENUM + in |

สร้างเมื่อใช้ครั้งแรก (ไม่ได้เรียกจาก config.php): `stock_minmax`, `stock_minmax_params` (บันทึก Min-Max ครั้งแรก) · `dispatch_settings`, `dispatch_rounds` (บันทึกรอบจ่ายครั้งแรก) · `gate_settings.store_over_cap` (admin.php — **ยังไม่มีบนฐานข้อมูลจริง ณ วันจัดทำ**) · `mango_ic_map` (`smSchemaUpgrade`) · `ic_items.has_serial, is_cx` (`icEnsureFlagColumns`)

### 4.2 แคตตาล็อกตารางตามกลุ่ม

**ก. ผู้ใช้ / บทบาท / โครงการ / ประตู**

| ตาราง | หน้าที่ | คอลัมน์สำคัญ |
|---|---|---|
| `projects` | ไซต์/โครงการ | `code` (unique, เช่น ARI) · `name` · `site_ref` (SiteID ของ Mango) · `status` active/archived · โครงการพิเศษ `0000` = ส่วนกลาง |
| `roles` | บทบาท | `role_code` · `name` · `level` 0–12 · `can_req` · `can_daily_check` · `sc` · `bs` |
| `users` | ผู้ใช้ | `username` (unique) · `password` (bcrypt) · `emp_code` · `full_name` · `role_id` → roles · `project_id` → projects · `card_id` (RFID hex 4–16 ตัว, unique ในผู้ใช้ active) · `signature_path` · `status` |
| `subcontractors` | ชุดผู้รับเหมา | `sub_code` 3 หลัก (unique ทั้งระบบ) · `password` · `name` · `status` · `signature_path` |
| `sub_projects` | ชุด × โครงการ | `enabled` · `mango_vendor_code/name` (vendor ที่ใช้เบิก Payment) · unique (sub_id, project_id) |
| `gates` | ประตู/ตู้ | `gate_code` (`^G\d{1,3}$`, unique ต่อโครงการ) · `name` · `hardware_close` (1 = มีตู้+กลอน ปิดรอบที่ตู้ / 0 = scan-flow ปิดที่ถ่ายรูป) · `status` · `has_cctv` (0 = ไม่มี ALARM 4) · `api_key_hash/hint/at/by` |
| `remember_tokens` | จดจำการเข้าสู่ระบบ 30 วัน | `account_type` user/subcontractor · `account_id` · `selector` · `validator_hash` · `expires_at` |
| `login_attempts` | จำกัดการ login | `ip_address` · `username` · `attempted_at` (ลบที่เก่ากว่า 1 วัน) |

**ข. ข้อมูลหลักวัสดุ** (รายละเอียดบทที่ 5)

| ตาราง | หน้าที่ | คอลัมน์สำคัญ |
|---|---|---|
| `materials` | ทะเบียนวัสดุกลาง | `mat_code` (unique) · `code_type` ENUM mango/ic · `name` · `unit` · `cat_id` · `char_id` (สำเนาจาก LLP) · `subgroup_name` · `item_photo` — เฉพาะ `code_type='ic'` ถือสต๊อกได้ |
| `mango_vendors` | vendor จาก Mango | `vendor_code` (unique) · `vendor_name` |
| `l1_groups` | กลุ่มใหญ่ | `l1_code` CHAR(3) PK · `l1_name` · `sort_order` · `is_active` |
| `l2_categories` | หมวด | PK (`l1_code`, `l2_code` CHAR(2)) · `l2_name` |
| `sizes`, `brands`, `units` | พจนานุกรม 3 หลัก | `000` = ไม่ระบุ · `units` seed 000–027 |
| `l2_sizes`, `l2_brands` | ขนาด/ยี่ห้อที่อนุญาตต่อหมวด | FK → l2_categories, sizes/brands (CASCADE) |
| `llp_products` | ตัวสินค้า (LLP) | `llp_code` CHAR(8) · `llp_name` · `cat_id` · `char_id` (**แหล่งจริงของ Cat/Char**) · `charcat_by/at` · `is_active` |
| `extra_attrs` | คุณสมบัติเพิ่ม | PK (`llp_code`, `extra_code`) · `extra_name` |
| `ic_items` | รหัส IC ที่ออกแล้ว | `ic_code` CHAR(20) · `llp_code, l1_code, l2_code, size_code, brand_code, unit_code, extra_code` · `ic_name` · `cat_id` · `char_id` · `is_active` · `has_serial`, `is_cx` (เก็บไว้ ยังไม่มีโฟลว์ใช้) · `created_project_id/by` |
| `ic_suggest_map` | จำการเลือก IC ต่อ (vendor, Mango) | `vendor_key` · `mat_code` · `ic_code` · `hit_count` · `last_used` |
| `mango_ic_map` | ผูก Mango → LLP → IC | `mat_code` · `llp_code` · `ic_code` (`''` = ขั้นที่ 1) · `is_primary` · `mapped_by/at` · unique (mat_code, llp_code, ic_code) |
| `sub_mango_map` | ตัวเลือก vendor ต่อชุดผู้รับเหมา | (`sub_code`, `vendor_code`) unique |

**ค. เอกสาร**

| ตาราง | หน้าที่ |
|---|---|
| `documents` | หัวเอกสารทุกประเภท (รายละเอียด 4.3) |
| `document_items` | รายการในเอกสาร (รายละเอียด 4.4) |
| `doc_counters` | เลขรันรายวัน PK (`counter_type`, `date_key` DDMMYY) · `last_no` · ประเภท RD OD BD IN TD TG SC และ PK (รอบหยิบ) · **นับรวมทุกไซต์** |

รูปถ่ายไม่มีตาราง — เก็บไฟล์ใน `uploads/photos/YYYY-MM/` แล้วเขียน path คั่นด้วย `, ` ในคอลัมน์ `photo_url` / `photo_return_url` / `in_photo_url` ของ documents และ document_items

**ง. สต๊อก** (รายละเอียดบทที่ 8)

| ตาราง | หน้าที่ | คอลัมน์สำคัญ |
|---|---|---|
| `project_materials` | วัสดุที่เปิดใช้ในโครงการ + ประตูตั้งต้น | unique (project_id, material_id) · `gate_id` (SET NULL) |
| `stock_balances` | ยอดรวมไซต์ | (project_id, material_id) · `qty_in` · `qty_out` · `on_hand` · `pending` |
| `stock_gate_balances` | ยอดรายประตู | (project_id, material_id, gate_id) · คอลัมน์เดียวกัน |
| `stock_adjustments` | ผลตัดสินส่วนต่างจากนับสต๊อก | `item_id` (unique) · `system_qty` · `counted_qty` · `delta` · `status` approved/rejected · `note` · `decided_by/at` |
| `stock_minmax`, `stock_minmax_params` | จุดสั่งซื้อ/เติม และพารามิเตอร์ต่อไซต์ | `min_qty` · `max_qty` · `lead_time_days` · `cycle_days` · `service_level` · `lookback_days` |
| `daily_check_confirms` | ยืนยันตรวจสอบประจำวัน | (project_id, check_date) · `confirmed_by` · `item_count` |

**จ. ตู้ประตู** (รายละเอียดบทที่ 14)

| ตาราง | หน้าที่ | คอลัมน์สำคัญ |
|---|---|---|
| `gate_logs` | 1 แถวต่อ "ขา" ของเอกสาร | `doc_no` (unique: เลขใบ / `…RT` ขาคืน / `TG…GsrcGdst` ขานำเข้า) · `document_id` · `leg` out/return/in · `gate_id` · `picking_id` (PK…) · `card_id` · `scanned_at` · `status` Awaiting → Opened/Scanned → Confirmed → Closed, Cancelled |
| `gate_settings` | เวลาหยิบต่อไซต์ | `pick_min_per_item` (3.00) · `pick_cap_min` (120) · `extend_min` (5) · `store_over_cap` (0) · ARI ตั้งไว้ 3 / 9 / 3 |
| `gate_round_events` | เหตุการณ์ของรอบ | `picking_id` · `event` (pick_timeout, pick_extend, close_timeout, close_extend, round_end, bypass_open, bypass_close) · `item_count` · `seq` · `card_id` · `cardholder` · `total_min` · `over_cap` · `detail` JSON |
| `gate_status` | heartbeat ล่าสุดต่อตู้ | `gate_id` PK · `last_seen` · `app_version` · `screen` · `picking_id` · `camera/detector/reader` ok/fail/off · `lock_state` · `payload` · `offline_since` · `device_alert` |
| `error_logs` | เหตุการณ์จากตู้และระบบ | `project_id` · `gate_code` · `message` (จัดหมวดด้วย regex ใน `lib/site_errors.php`) |
| `dispatch_settings`, `dispatch_rounds` | รอบจ่ายต่อไซต์ | `enabled` · `work_days` ("1,2,3,4,5,6") · `cutoff_time` · `dispatch_time` · `sort_no` |

**ฉ. ยืม-คืน**

| ตาราง | หน้าที่ |
|---|---|
| `borrow_writeoffs` | รายการตีชำรุด/สูญหาย: `item_id` · `qty` · `kind` damaged/lost · `reason` · `photo_url` · `ref_price` · `ref_source` ratecard/manual · `stage` · `created_by` |
| `user_notices` | แจ้งเตือนในแอป: `username` · `kind` (borrow_writeoff, revise, revise_back, sc_weekly, gate_setting, gate_key, material_category, gate_online, gate_offline, gate_device) · `title` · `body` · `ref_doc` · `read_at` |

**ช. ใบสั่งซื้อ / buffer** (รายละเอียด 11.3)

`ocr_logs` (log OCR: model, status, payload, raw, ms, tokens) · `po_headers` (PK `po_no`, vendor_*, ยอดรวม, `status` open/partial/received/cancelled) · `po_lines` (PK po_no+line_no, `line_kind` item/adjust, `mat_code` Mango, `unit_po_name`, `qty_received`) · `po_receipts` (`RCV-{po_no}-{nn}`) · `po_receipt_lines` · `buffer_lines` (`qty_received`, `qty_consumed` หน่วยซื้อ) · `buffer_pushes` (`qty_consumed`, `doc_no` ใบ IN, `cancelled_at`) · `push_allocs` (`ic_code`, `qty` หน่วยเก็บ)

**ซ. งานผู้รับเหมา** (รายละเอียดบทที่ 15)

`rate_cards` (ราคาหักเงิน ต่อ project × material, `unit_price` NULL = ยังไม่ตั้ง) · `deduction_docs` (`doc_no` SUB-{SITE}-{YYYYMM}-{NN}, `sub_name`, `days_label`, `item_count`) · `deduction_doc_rates` (ราคาแช่ต่อ doc_no × mat_code) · `finger_scan_logs` / `finger_scan_verify` / `finger_scan_config` · `sign_requests` (unique (doc_no, role), `token`, `status`) · `sub_expense_rows` / `sub_expense_config` · `remap_logs` · `sub_close_logs` · `sub_mango_history`

**ฌ. ตั้งค่าและ log**

`app_settings` (`skey` PK · `svalue` · `updated_by`) · `activity_log` (`entity_type`, `entity_id`, `user_name`, `action`, `old_value`, `new_value`) · `error_logs`

### 4.3 ตาราง `documents` (ละเอียด)

| คอลัมน์ | ความหมาย |
|---|---|
| `doc_no` VARCHAR(30) unique | เลขใบ = ประเภท + DDMMYY + เลขรัน (≥2 หลัก) + Gxx เช่น `RD06102602G01` · IN ใหม่ตั้งแต่ 2026-10-06 **ไม่มี G** (`IN08102601`) · TG ใช้ประตูต้นทาง (ขานำเข้าอยู่เฉพาะใน gate_logs) |
| `doc_type` | ENUM RD, OD, BD, IN, TD, SC, TG |
| `project_id` / `dest_project_id` | ไซต์ต้นทาง / ไซต์ปลายทาง (TD) |
| `requester_username` | username หรือชื่อชุดผู้รับเหมา (ไม่มี FK) — เอาจาก session เสมอ |
| `receiver_name` / `receiver_sub_id` | ชื่อชุด หรือ `DC:…` ตามที่กรอก · ค่าสังเคราะห์: `โอนไป <site>` (TD), `ย้ายไป Gxx` (TG), `นับสต๊อก Gxx` (SC) |
| `gate_id` / `dest_gate_id` | ประตู (ต้นทางสำหรับ TG; IN = ประตูที่สแกนจริง) / ประตูปลายทาง (TG) |
| `usage_area` / `notice` | สำเนาจากรายการแรก · TD เก็บผู้ติดต่อปลายทางใน usage_area · notice ถูกเติมคำนำหน้า `[Confirm]:`, `[Count]:`, `[BYPASS]` |
| `approver_username` / `approved_by` | ผู้อนุมัติที่ระบุ (ว่าง = ใครก็ได้ที่ระดับถึง) ถูกเขียนทับด้วยผู้อนุมัติจริง / ผู้กดอนุมัติจริง (audit) |
| `status` VARCHAR(30) | ดู 6.2 |
| `rs_no` | เลขใบส่งของ/PO (IN) |
| `photo_url` / `photo_return_url` | รูปขาออก (หรือแบบฟอร์มกระดาษ) / รูปขาคืน BD, ขานำเข้า TG |
| `origin_type` / `origin_ref` | `bypass` (+`PAPER`) · `po` (+เลข PO ถ้าใบเดียว) |
| `doc_ts` | เวลาไทย (bypass = เวลาจ่ายจริง) |
| `return_ts` | BD: เวลาคืนรอบล่าสุด · TG: เวลาปิดขานำเข้า |
| `due_date` / `overdue_flag` / `writeoff_flag` | BD: กำหนดคืน (บังคับ) / เกินกำหนด (งานรายวันตั้ง) / มีรายการชำรุด-สูญหาย |
| `in_source` / `in_ref` / `in_reason` / `in_photo_url` | IN: `supplier` (มีใบส่งของ) หรือ `nonote` / อ้างอิง TD/BD / เหตุผล / รูปใบส่งของหรือรูปของ |
| `created_at`, `updated_at` | |

### 4.4 ตาราง `document_items` (ละเอียด)

| คอลัมน์ | ความหมาย |
|---|---|
| `material_id` (SET NULL) · `mat_code` · `mat_name` · `unit` | snapshot ตอนสร้าง (ใบเก่าที่จับคู่ไม่ได้ material_id = NULL) |
| `qty` | จำนวนที่ขอ · SC = ยอดในระบบตอนบันทึก |
| `qty_actual` | หยิบจริงตอนถ่ายรูปยืนยัน (NULL = ใช้ qty) · SC = นับได้ · TG = หยิบขาออก |
| `actual_reason` | เหตุผลเมื่อหยิบน้อยกว่าขอ |
| `photo_url` / `photo_return_url` | รูปรายรายการขาออก / ขาคืน BD, ขานำเข้า TG |
| `qty_returned` | BD: คืนสะสม · TG: จำนวนนำเข้า |
| `qty_return_round` | BD: คืนรอบนี้ (ตั้งตอนยืนยันรูป สะสมเข้า qty_returned แล้วล้างตอนปิดประตู) |
| `return_reason` | เหตุผลเมื่อคืนน้อยกว่ายืม |
| `usage_area` · `notice` | ต่อรายการ (SC แถวที่พบเพิ่ม = `พบเพิ่มระหว่างนับ`) |
| `rs_no` | เลขใบส่งของต่อแถว (IN) |
| `charge_money` | หักเงินผู้รับเหมา (RD/OD; ติ๊กในตรวจสอบประจำวัน) |
| `stock_deducted` | flag กันทำซ้ำ: 1 = ตัดสต๊อกแล้ว (TG คงเป็น 1 ระหว่าง In Transit) |

จำนวน "มีผล" ที่ใช้ทุกที่ = `COALESCE(qty_actual, qty)` (`s05EffQty`) · การจอง (pending) ใช้ `qty` ที่ขอ

### 4.5 Log การกระทำ (`activity_log`)

| entity_type | action ที่พบ (ผู้เขียน) |
|---|---|
| `document` | approve, reject, cancel, revise_request, revise_submit, return_round, bypass_create, in_gate_rebind, gate_bypass_open/close, borrow_writeoff, pick_actual, return_actual, gate_confirm, zero_pick, create_td, create_tg, tg_out_done, tg_in_done, tg_nothing_moved, sc_create, sc_count, sc_adjust, sc_adjust_reject |
| `picking` | gate_add_docs |
| `gate_settings` / `stock_gate_balances` / `stock_minmax` / `dispatch_rounds` | update / gate_transfer, allocate_unassigned / save, params / save |
| `user` / `gate` / `project` / `role` / `project_material` | change_site, create, update, reset_password / create, update, hardware_on, api_key_new, api_key_revoke / … / set_gate |
| `material` | update, mango_create, mango_update, llp_map, llp_change, llp_unmap, ic_map, ic_unmap, ic_primary, ic_migrate, ic_edit, ic_recode, ic_renumber, llp_align, ic_import |
| `llp_product` | charcat_change |
| `app_setting` | setting_change (รหัสผ่าน SMTP ถูกปิดบัง) |
| `report` | history_report_export |

### 4.6 สคริปต์นำเข้า/ย้ายข้อมูล (`db/`, CLI เท่านั้น)

| สคริปต์ | ทำอะไร |
|---|---|
| `import_from_sheet.php [--fresh]` | นำเข้าข้อมูลจาก Google Sheets เดิม (CSV ใน `db/source_data/`) 17 ขั้น: Sites → Roles → Users → Subcontracts → MaterialsMain (Mango) → MangoVendors → SubMangoMap → Gates → SiteMaterials → Balance (+10b ลงยอดรายประตู) → RequisitionLogs/OddsLogs/Borrow_Return/InboundLogs → GateLogs → RateCard → DailyCheck → DeductionDocs → ErrorLogs/UserLogs → seed doc_counters · `--fresh` ล้าง 22 ตาราง **รวม users (ลบ admin ด้วย)** และไม่ล้าง IC/LLP/mango_ic_map/ตารางใหม่ ๆ → อาจเหลือแถวกำพร้า · ท้ายสคริปต์เตือนให้ลบ `db/source_data/` (มีรหัสผ่าน plaintext) |
| `add_local_admin.php [user] [pass]` | upsert บทบาท ADM (level 0 ทุก flag) + ผู้ใช้ admin ที่ไซต์ ARI (ใช้เฉพาะเครื่องนี้) |
| `import_llp_master.php --file=… [--dry] [--no-charcat]` | นำเข้า "สร้าง LLP.xlsx" → L1/L2/LLP/Mango→LLP/หน่วย |
| `import_ic_master.php --file=… [--dry] [--report] [--db]` | ออก IC จำนวนมากจากไฟล์ (มติ 44) ต้องนำเข้า LLP ก่อน |
| `align_flowhub.php --file=… [--dry] [--report] [--db]` | จัดเลข LLP/IC ตาม LLP-Flowhub.xlsx (มติ 48) |
| `migrate_setup_master.php` | สร้าง/อัปเกรด `mango_ic_map` สำหรับฐานข้อมูลเก่า |
| `borrow_overdue_job.php [--date=]` | งานยืมเกินกำหนด (ทางเลือกสำหรับ Task Scheduler) |
| `reimport-local.bat` | ควรรัน import --fresh แล้ว add_local_admin — **ไฟล์เสีย** (`\x`, `\a` ในพาธกลายเป็น newline/BEL) ใช้ไม่ได้ |

ลำดับติดตั้งใหม่ (สรุปจากสคริปต์): installer → ensure-schema (คำขอแรก) → `import_from_sheet.php` (+ `add_local_admin.php` ถ้า --fresh) → `import_llp_master.php` → `import_ic_master.php` → (ถ้าต้อง) `align_flowhub.php` → ย้ายยอด Mango → IC ใน `setup_master.php` → ตั้ง Task Scheduler ให้ `cron/jobs.php`

---

## 5. ข้อมูลหลักวัสดุ — L1/L2/LLP/IC และ Mango

### 5.1 บันไดรหัส (ladder)

```
L1 กลุ่มใหญ่ (3 ตัว)  →  L2 หมวด (2 หลัก)  →  LLP ตัวสินค้า (8 ตัว = L1+L2+ลำดับ 3 หลัก)  →  IC (20 ตัว)
IC = LLP(8) + ขนาด(3) + ยี่ห้อ(3) + หน่วยเก็บ(3) + คุณสมบัติเพิ่ม(3)
ตัวอย่าง: TMP01019 000 000 037 014  →  TMP01019000000037014  "(BPI) ตู้ห้องน้ำ สำเร็จรูปแบบตู้คู่"
```

| ระดับ | ตาราง | รหัส | กติกา |
|---|---|---|---|
| L1 กลุ่มใหญ่ | `l1_groups` | `^[A-Z0-9]{3}$` เช่น STR, MRO, ARC, EQP, HDW, MEP, CON, TMP | ตั้งเอง (ไม่สร้างอัตโนมัติ) · มี 11 กลุ่ม |
| L2 หมวด | `l2_categories` | 2 หลักภายใต้ L1 (MAX+1 ≤ 99) | สร้างแล้วผูกขนาด `000` และยี่ห้อ `000` ให้อัตโนมัติ · มี 72 หมวด |
| LLP ตัวสินค้า | `llp_products` | เช่น `CON05002`, `MRO06029` (ลำดับ MAX+1 ภายใน L1+L2 ≤ 999) | ถือ `cat_id`/`char_id` ซึ่งเป็น **แหล่งจริง** (มติ 28–29) · มี 2,818 ตัว |
| ขนาด / ยี่ห้อ / หน่วยเก็บ | `sizes` / `brands` / `units` | 3 หลัก, `000` = ไม่ระบุ | ขนาด/ยี่ห้อใช้ได้ในหมวดเมื่อผูกผ่าน `l2_sizes`/`l2_brands` · `icAttachToL2` รับทั้งแบบระบุชื่อ (ใช้ซ้ำหรือสร้างใหม่) และแบบระบุรหัส (มติ 43) |
| คุณสมบัติเพิ่ม | `extra_attrs` | 001–999 ต่อ LLP, `000` = ไม่มี | |
| IC | `ic_items` + แถวคู่ใน `materials` (`code_type='ic'`) | 20 ตัว | มี 6,697 รหัส |

**การออก IC (`icCreate`)**
1. ตรวจทุกส่วน: ความยาว, LLP มีและ active, ขนาด/ยี่ห้ออยู่ใน l2_sizes/l2_brands, หน่วย active, extra เป็น 000 หรือมีใน extra_attrs
2. LLP ต้องตั้ง Cat/Char แล้ว ไม่งั้นปฏิเสธ (มติ 32) — ค่า Cat/Char ที่ส่งมาจากฟอร์มถูกละเลย (มติ 28)
3. idempotent: ถ้ารหัสมีอยู่แล้ว แค่ sync Cat/Char จาก LLP
4. สร้างแถวคู่ใน `materials` ใน transaction เดียวกัน (`mat_code=ic_code`, `code_type='ic'`, `unit=ชื่อหน่วย`, `subgroup = "L1name › L2name"`) — จำเป็นเพื่อให้ IC ใช้ในสต๊อก/เบิก/QR/หักเงินได้ (มติ 3)
5. ชื่อแนะนำ = ชื่อ LLP + ชื่อขนาด + ยี่ห้อ + คุณสมบัติ (ข้ามส่วนที่เป็น 000)

**Cat/Char (รายการกลาง `IC_CAT_IDS` / `IC_CHAR_IDS`, มติ 30–31)**

| CatID | ความหมาย | CharID | ความหมาย |
|---|---|---|---|
| C01 | วัสดุควบคุม (อนุมัติ R6+) | CSB | เบิกวัสดุหลัก |
| C02 | วัสดุทั่วไป (อนุมัติ R4+) | BRB | ยืม-คืน |
| NAR | ไม่เข้าเส้นทางอนุมัติ | NAR | เบ็ดเตล็ด |
| | | WMS | ไม่เข้าฟอร์มเบิก (ดูยอดอย่างเดียว) |

เปลี่ยน Cat/Char ของ LLP → cascade ลงทุก `ic_items` และ `materials` ใต้ LLP นั้น (`icCascadeLlp`) · การเพิ่มค่าใหม่ต้องแก้ `tools/build_index.py` ด้วย

**หน่วย:** หน่วยเก็บ (`units.unit_code`, อยู่ในรหัส IC) กับหน่วยซื้อ (ข้อความอิสระใน `po_lines.unit_po_name` / `materials.unit` ของแถว Mango) — **ไม่มีอัตราแปลงเป็นข้อมูลหลัก** คนกรอกจำนวนหน่วยเก็บตอนออกใบ IN (มติ 9) อัตราคำนวณย้อนต่อ push

### 5.2 หน้าจอข้อมูลหลัก

| หน้า | สิทธิ์ | หน้าที่ |
|---|---|---|
| `ic_new.php` สร้างรหัส IC | CanReq (แก้บันได/flag = R0) | ต้องเริ่มจากรหัส Mango (มติ 47): ข้อ 0 Mango (บังคับ) → ข้อ 1 หมวดหมู่และสินค้า (ล็อกถ้า Mango มี LLP แล้ว) → ข้อ 2 คุณสมบัติ (ว่าง = 000) → ข้อ 3 หน่วยนับและประเภท · สโตร์ออก IC ได้เฉพาะใต้ LLP ที่ Mango ผูกอยู่แล้ว (มติ 16) · สร้างแบบ atomic ผ่าน `create_for_mango` |
| `ic_list.php` ทะเบียน IC | CanReq (ปุ่มแก้ R0) | รายการ IC กรอง active/off/all |
| `ic_edit.php` แก้ไข IC | R0 (มติ 46) | `save_info` แก้ชื่อ/Serial/CX/active (ปิดใช้ไม่ได้ถ้ายังมียอด, จอง หรือ project_materials) · `recode` เปลี่ยนสเปก → สร้าง/รวมเข้ารหัสใหม่ แล้วย้าย stock, gate balances, project_materials, rate_cards, document_items, po_lines, deduction_doc_rates, push_allocs, mango_ic_map, ic_suggest_map ปิดรหัสเก่า log `ic_recode` (ยอดย้ายตามตัวเลขเดิม ไม่แปลงหน่วย) · เปลี่ยน LLP ได้เมื่อ Mango ที่ผูกไม่มี IC อื่น (มติ 45) |
| `llp_master.php` ตั้งค่า LLP | R0 | ตั้ง CatID/CharID ทีละตัวหรือ import xlsx (คอลัมน์: รหัสตัวสินค้า (LLP), กลุ่มใหญ่, ชื่อกลุ่มใหญ่, หมวด, ชื่อหมวด, ชื่อตัวสินค้า, IC ที่ออกแล้ว, CatID, CharID) |
| `mango_master.php` ทะเบียน Mango | R0 | เพิ่ม/แก้ทีละรายการหรือ xlsx (รหัส Mango, ชื่อวัสดุ, หน่วย, กลุ่มย่อย, CatID, CharID, ใช้ในใบ PO) ไม่ลบ |
| `setup_master.php` จัดการรหัสวัสดุ Mango → LLP → IC | R0 | ดู 5.4 |
| `admin.php?t=ladder` บันได IC | R0 | นับต่อระดับ, LLP ที่ยังไม่ตั้ง Cat/Char, IC 15 ตัวล่าสุด, ลิงก์ไปหน้าต่าง ๆ |
| `admin.php?t=materials` วัสดุ | R0 | ค้นหา/แก้ชื่อ-หน่วย (Cat/Char ล็อกบนแถว IC, มติ 29) · ประตูตั้งต้นต่อไซต์ (`mat_gate` — ลงยอดที่ยังไม่อยู่ประตูให้ด้วย) · ย้ายของข้ามประตู (`mat_transfer`, มติ 51) · "ลงยอดที่ประตูตั้งต้น" ทั้งหมด (`gate_alloc`) |

### 5.3 Mango (รหัส ERP เดิม)

- แถวใน `materials` ที่ `code_type='mango'` นำเข้าครั้งเดียว 6,814 รหัส (รูปแบบโดยทั่วไป 14 ตัวอักษรพิมพ์ใหญ่ เช่น `PBPI1200100100`) ปรับเป็นพิมพ์ใหญ่ตัดช่องว่าง ≤ 50 ตัว ห้ามซ้ำกับ IC
- หลังมติ 34 **รหัส Mango เบิกไม่ได้และถือสต๊อกไม่ได้** (`ensureProjectMaterial()` โยน error สำหรับ Mango — มติ 38) ใช้เพื่อ (1) จับคู่บรรทัด OCR (2) `ic_suggest_map` (3) รายงาน Mango → IcCode เท่านั้น
- เพิ่มรหัส Mango ได้จาก `mango_master.php` หรือช่อง "＋ เพิ่มเข้าทะเบียน" ในหน้าตรวจ PO (insert อย่างเดียว ไม่ทับ — มติ 35)
- `mango_vendors.vendor_code` (เช่น 00001) ใช้เฉพาะผูกชื่อใช้เบิก Payment ของชุดผู้รับเหมา

**การผูก Mango → LLP → IC (`mango_ic_map`, มติ 40–42, 45)**
- 2 ขั้น: Mango → LLP (แถวที่ `ic_code=''`) แล้ว LLP → IC
- 1 Mango = 1 LLP เท่านั้น · 1 LLP มีได้หลาย Mango และหลาย IC · 1 Mango มีได้หลาย IC แต่ต้องใต้ LLP ของตน · `is_primary` = IC ที่รับรายการเอกสารเก่าและราคาแช่
- ย้าย Mango ไป LLP อื่น = "เปลี่ยนสินค้า" ถ้าจะทำให้ IC หลุดต้องยืนยัน `replace=1` · ผูก IC จาก LLP อื่นถูกปฏิเสธ · ทุกการเปลี่ยนเขียน `ic_suggest_map` ด้วย vendor_key `*setup_master*`
- `lib/ic_mango.php` (`icmFind/icmInfo/icmCreate`): ผูก LLP (ถ้ายัง, R0) → ตั้ง Cat/Char (R0) → `icCreate` → `smAttachIc` ใน transaction เดียว (SAVEPOINT เมื่อซ้อน) ค่าตั้งต้นขนาด/ยี่ห้อ/หน่วยมาจาก IC หลักของ Mango ไม่งั้นหน่วยที่ชื่อตรงกับ Mango (ไม่ตรง → `unit_warn`)

### 5.4 `setup_master.php` — จัดการรหัสวัสดุ (R0)

1. **สถานะ** — ตัวเลขสรุป (`smStats`)
2. **Import "สร้าง LLP.xlsx"** (อัปโหลดหรือพาธบนเซิร์ฟเวอร์, preview/commit) — ชีต "LL Code" → L1/L2 · "Create_LLP_All Mat" → LLP + Mango→LLP · "IC_All Mat" → หน่วย · กติกา: รับ LLP 8 ตัวที่ L1/L2 มีจริง, Mango ซ้ำใช้แถวแรก (มติ 45), ไม่ผูกซ้ำ Mango ที่ผูกแล้ว, Cat/Char ตั้งเมื่อทุก Mango ใต้ LLP ตรงกันและไม่ทับค่าเดิม, L1/L2 ที่ไม่มีในไฟล์ลบถ้าไม่ถูกใช้ ไม่งั้นปิด
   - 2b **ออก IC จากไฟล์** (มติ 44, `lib/ic_import.php`): ใช้ SIZE NAME / BRAND NAME / Unit Name / ชื่อในPO / Serial No. · "ขนาดมาตรฐาน" = ดูเหมือนขนาด (ขึ้นต้นด้วยตัวเลข หรือ ยาว/หนา/กว้าง/สูง/เบอร์/size/no./db/rb/dia./ø/#/@) ≤ 100 ตัว และใช้ร่วมอย่างน้อย 2 LLP หรือมีในพจนานุกรม ที่เหลือเป็นคุณสมบัติเพิ่ม (≤ 150 ตัว) · Mango ที่ส่วนประกอบเหมือนกันรวมเป็น IC เดียว · สถานะ: map / issued / has_ic / llp_unset / no_llp / multi_llp / llp_missing / llp_off / no_unit / too_long / full / not_in_file · รายงาน xlsx: สรุป / ทะเบียน IC / Mango → IC / ขนาดมาตรฐาน / ยี่ห้อ / รอตั้ง Cat-Char / รวมหลาย Mango · **ไม่ย้ายสต๊อก**
3. **ตาราง Mango** ผูก/ถอด LLP, ผูก/ถอด IC, ตั้ง primary ทีละแถว (+ modal ผ่าน `api/setup_master_api.php`)
4. **ย้ายยอดสต๊อก Mango → IC** (`smMigrate`): IC เดียว → ย้ายทั้งก้อน (รวมถ้าปลายทางมี) · หลาย IC → ADM กรอกแยก ผลรวมต้องเท่ากับ on_hand ±0.0005 ยอดรายประตูแบ่งตามสัดส่วน · ย้าย project_materials, stock_balances, stock_gate_balances, rate_cards (ราคา IC ชนะ), document_items (→ IC หลัก), deduction_doc_rates แล้ว `recalcPending` · log `ic_migrate`
- `?export` ดาวน์โหลด xlsx "ผูก Mango → LLP" (รหัส Mango, ชื่อวัสดุ, หน่วย, คงเหลือรวม, รหัส LLP, ชื่อตัวสินค้า (LLP), รหัส IC (ถ้ามี), ชื่อ IC) นำกลับมา preview/commit แบบเพิ่มอย่างเดียว
- `upgrade_schema` สร้าง/อัปเกรด `mango_ic_map`

### 5.5 Flow Hub alignment (`lib/llp_align.php`, `db/align_flowhub.php`, มติ 48)

- **เหตุ:** เลข LLP เดิมมาจาก "สร้าง LLP.xlsx" แต่จัดซื้อใช้ `LLP-Flowhub.xlsx` จริง เลขเดียวกันชี้คนละสินค้าแบบเลื่อนเป็นลูกโซ่ → 2026-09-23 ผู้ใช้สั่งให้ Flow Hub เป็นแม่บท · ใช้กับข้อมูลจริง 2026-10-02 16:24
- **รับไฟล์:** แบบมีหัว (`Material Code`, `LLP`, `PRODUCT NAME CLEAN`) หรือไม่มีหัว (A = Mango, C = L1, E = L2, I = LLP, L = ชื่อ; ต้องถูก ≥ 80% ของแถว) · Mango ต้องตรง `^[A-Z][A-Z0-9]{5,}$`
- **กติกา:** Mango ในไฟล์ย้ายไป LLP ของไฟล์ (ชื่อ LLP = PRODUCT NAME CLEAN) · Mango เดียวหลาย LLP → เลือกตัวที่ชื่อตรง ไม่งั้นเลขต่ำสุด · Mango ที่ไม่อยู่ในไฟล์และ IC ที่ไม่มี Mango ตามสินค้าเดิม · LLP ในระบบที่ครองเลขของ Flow Hub แต่ไม่มี Mango ในไฟล์ → ย้ายไปเลขว่างถัดไปใน L2 เดียวกัน (**เลขชั่วคราว**) · สินค้ารวมกัน (เช่น ลวดเชื่อม 2.6/3.2 → ลวดเชื่อม) ส่วนชื่อที่เหลือย้ายไปช่องยี่ห้อ/ขนาด/คุณสมบัติ · Mango+IC ที่ผูกกันย้ายทั้งกลุ่ม ถ้าไฟล์แยกกลุ่ม → แช่และรายงาน · Cat/Char ของปลายทาง = เสียงข้างมาก · LLP ว่างที่ไม่อยู่ในไฟล์ถูกลบ
- **กลไก:** เปลี่ยนเลข IC **ในที่** (`materials.id` คงเดิม ยอดและเอกสารไม่ย้าย) ผ่านรหัสชั่วคราว `Z`+19 หลัก ในตาราง ic_items, materials, document_items, po_lines, deduction_doc_rates, mango_ic_map, ic_suggest_map, push_allocs, borrow_writeoffs, stock_adjustments · transaction เดียว + fingerprint กันแก้พร้อมกัน + `lalVerify` ก่อน commit · ชนกัน → หยุดทั้งหมด · log `ic_renumber`, `llp_change`, `llp_map`, `llp_align` · `ic_edit.php` redirect รหัสเก่า (`iceRenumbered`)
- **ผล 2026-10-02:** Mango 260/260 ตรงไฟล์ · IC เปลี่ยนเลข 244 (เอกสาร 536 บรรทัดตาม) · สินค้าสร้าง 19 / เปลี่ยนชื่อ 84 / ลบ 35 · เลขชั่วคราว 17 · ขนาดใหม่ 3 · ชน 0 · รายงาน `Software/Data/ผลจัดเลข LLP ตาม Flow Hub 2026-10-02.xlsx`
- CLI: `php db/align_flowhub.php --file=… [--dry] [--report=…] [--db=…] [--quiet]`

### 5.6 ชุดผู้รับเหมาและชื่อใช้เบิก (`lib/subsettings.php`, `lib/directory.php`)

- สิทธิ์: BS หรือ R0 แก้ทั้งหมด · SC ดูและตั้งลายเซ็นได้ · ไม่ใช่ R0 ล็อกไซต์ตัวเอง
- `subcontractors.sub_code` 3 หลัก global MAX+1 · รหัสผ่านปริยายเมื่อสร้าง (bcrypt) · ชื่อซ้ำที่มีอยู่แล้ว = ผูกเข้าโครงการแทนสร้างใหม่ · เปลี่ยนชื่อต้องไม่ซ้ำทั้งระบบและอัปเดต `documents.receiver_name` (ใบหักเงินที่ออกแล้วคงชื่อเดิม)
- ปิดใช้ชุดถูกบล็อกเมื่อมีงานค้าง (`_ssPendingRefs`): ยืมสถานะ Borrowed/Sent Return · รายการ RD/OD ที่ติ๊กหักเงินในงวดที่ยังไม่ออกใบหักเงิน · สแกนนิ้วงวดนี้
- `rpc_remapSubcontractor` รวมชุด from → to ย้ายเอกสาร BD/RD/OD แล้วปิดต้นทาง (`remap_logs`) · `rpc_closeSubcontractorSettle` 4 ขั้น: ออกเลขใบหักเงินงวดที่เปิดอยู่ → แช่ยอดหักคจช. งวดนี้ → ประทับใบยืมที่เปิดอยู่ → ปิดชุด + `sub_close_logs`
- ลายเซ็นชุด: data URL png/jpeg ≤ 45,000 ตัวอักษร → `uploads/signatures/sub_<code>_<hex>`
- `lib/directory.php` (พอร์ตจาก GAS): รายชื่อผู้ใช้, ผู้อนุมัติ, ชุดผู้รับเหมา (+ Mango vendor), วัสดุ (IC เท่านั้น), ประตู, ยอดคงเหลือ (+ รายประตู), ยอดวัสดุเดียว, `getLegacyMangoStock` (แถว Mango ที่ยังมียอด → แบนเนอร์ Dashboard)

---

## 6. เอกสารและวงจรชีวิต — RD · OD · BD · IN · TD · TG · SC

### 6.1 ประเภทเอกสาร

| ประเภท | ชื่อ | ใครสร้าง | อนุมัติ | ผลต่อสต๊อก | ขาที่ตู้ |
|---|---|---|---|---|---|
| **RD** | เบิกวัสดุหลัก | ผู้ใช้ที่ login (รวมผู้รับเหมา — ไม่มีการกั้นบทบาทฝั่งเซิร์ฟเวอร์) | R6+ (ผู้ส่ง R6+ หรือ PM → auto) | ตัดออกตามหยิบจริง | 1 ขา |
| **OD** | เบิกเบ็ดเตล็ด (Odds) | เหมือน RD · เฉพาะวัสดุ NAR | ไม่มี — อนุมัติทันที | ตัดออก | 1 ขา |
| **BD** | ยืม-คืนอุปกรณ์ | เหมือน RD · ห้าม NAR · ต้องมีกำหนดคืน | มี C01 → R6+ ไม่งั้น R4+ (ผู้ส่งระดับถึง → auto) | ยืม: ตัดออก · คืน: ลดยอดออก | ขาออก + ขาคืน `…RT` (ใช้ซ้ำได้หลายรอบ) |
| **IN** | รับเข้าคลัง | สายสโตร์ของไซต์ (ADM ทุกไซต์) | ไม่มี — `Sent Inbound` ทันที | เพิ่มเข้าที่ประตูที่สแกน | 1 ขา สแกนได้ทุกประตูของไซต์ |
| **TD** | เบิกโอนย้ายข้ามไซต์ | บัญชี staff ที่ผูกไซต์ (ไม่ใช่ผู้รับเหมา) | PM ไซต์ต้นทางเท่านั้น | ตัดที่ประตูต้นทางอย่างเดียว ปลายทางคีย์ IN เอง | 1 ขา |
| **TG** | ย้าย Gate (ภายในไซต์) | สายสโตร์ (level 1–98 + can_req) | ตามหมวด IC ทั้งตะกร้า: C01 → R6, C02 → R4, NAR ล้วน → ทันที | ขาออก: ตัดประตูต้นทาง · ขาเข้า: เพิ่มประตูปลายทาง (ยอดไซต์รวมไม่เปลี่ยน) | ขาออก `TG…Gsrc` + ขาเข้า `TG…GsrcGdst` ต้องใช้บัตรสายสโตร์ทั้งสองขา |
| **SC** | นับสต๊อก | ผู้มี `can_daily_check` | ไม่มี — สร้างเป็น `Approved` | ไม่ตัด/เพิ่มเอง — ส่วนต่างรอ ADM/R8+ ตัดสิน | 1 ขา ต้องเป็นรอบของตัวเอง |

### 6.2 สถานะเอกสาร (`documents.status`)

ค่าที่โค้ดเขียน (ตรวจส่วนใหญ่แบบ lowercase substring): `Awaiting approval` · `Awaiting revision` (ตีกลับให้แก้) · `Approved` · `Rejected` · `Cancelled` · `Completed` · `Sent Borrow` · `Borrowed` · `Sent Return` · `Returned` · `Sent Inbound` · `In Transit` (TG)

ค่าที่ตัวอ่านยอมรับแต่โค้ดปัจจุบันไม่เขียนแล้ว: `Awaiting for approval`, `Pending`, `Sent to gate`, `Denied`, `Closed` และภาษาไทย `อนุมัติแล้ว`, `รออนุมัติ`, `ไม่อนุมัติ`, `ยกเลิก` (ข้อมูลนำเข้าจาก GAS)

ป้ายภาษาไทยที่แสดง: รออนุมัติ · ตีกลับให้แก้ · อนุมัติแล้ว · ไม่อนุมัติ · นำจ่ายแล้ว (closed) · ส่งยืม · ยืมอยู่ · ส่งคืน · คืนแล้ว · สำเร็จ · ยกเลิก

**เส้นทางสถานะต่อประเภท**

| ประเภท | เส้นทาง |
|---|---|
| RD | Awaiting approval ⇄ Awaiting revision → Approved → (ปิดประตู) Completed · ยกเลิกได้จนกว่าจะแตะบัตร · Rejected |
| OD | Approved → Completed (หรือ Cancelled) |
| BD | Awaiting approval → Sent Borrow → (ปิดประตู) Borrowed → (แจ้งคืน) Sent Return → (ปิดขาคืน) Returned ถ้าคืนครบ ไม่งั้นกลับเป็น Borrowed · ตีชำรุด/สูญหายจนครบ → Returned + `writeoff_flag` |
| IN | Sent Inbound → Completed |
| TD | Awaiting approval → Approved → Completed |
| TG | Awaiting approval → Approved → (ปิดขาออก) In Transit → (ปิดขาเข้า) Completed · ถ้าหยิบจริง 0 ทุกรายการ → Completed โดยไม่มีขาเข้า (`tg_nothing_moved`) |
| SC | Approved → Completed |
| Bypass กระดาษ | สร้างเป็น Completed ทันที |

**สถานะ gate log (`gate_logs.status`):** `Awaiting` → `Opened` (ประตูมีตู้) หรือ `Scanned` (scan-flow) → `Confirmed` (ถ่ายรูปยืนยันแล้ว) → `Closed` (ตู้ส่ง closeGate) · `Cancelled` · ประตู scan-flow (ไม่มีตู้) จบที่ Confirmed และ finalize ทันทีตอนยืนยันรูป

### 6.3 เลขที่เอกสาร (`lib/docnum.php`)

- รูปแบบ **ประเภท + DDMMYY (เวลาไทย) + เลขรัน + รหัสประตู** เช่น `OD08102608G01` = OD · 08/10/26 · ใบที่ 08 · G01 · เลขรัน `str_pad(n,2,'0')` (โตเกิน 2 หลักได้) · ไม่มีประตู → ไม่มี Gxx (IN ใหม่)
- ตัวนับ `doc_counters(counter_type, date_key)` เพิ่มด้วย `SELECT … FOR UPDATE` · **นับต่อประเภทต่อวัน รวมทุกไซต์** เริ่ม 1 ใหม่ทุกวัน
- ตะกร้า RD/OD/BD ถูกแยกใบ **ต่อผู้รับ แล้วต่อประตู** (`_docsSubmitReceiverSplit`) — เลขรันเพิ่มครั้งเดียวต่อผู้รับ ผู้รับคนเดียวที่ของอยู่ G01 และ G03 ได้ `…08G01` และ `…08G03`
- TD/TG: เลขรันเดียวทั้งตะกร้า แยกใบตามประตูต้นทาง
- รอบหยิบ: `nextPickingId()` → `PK + DDMMYY + NN`

### 6.4 กฎที่ทุกเอกสารใหม่ต้องผ่าน

1. **IC เท่านั้น (มติ 34, `_docsIcOnlyError`)** — RD/OD/BD/IN/TD/TG/bypass: รหัส Mango, รหัสที่ไม่รู้จัก หรือว่าง → ปฏิเสธทั้งใบก่อนเขียนอะไร (ใบยืมเก่าที่ถือรหัส Mango ยังคืนได้ เพราะขาคืนไม่ตรวจ)
2. **กฎ NAR (`_docsNarRuleError`, Scenario 05)** — NAR = `char_id` หรือ `cat_id` = NAR: OD มีได้ **เฉพาะ** NAR · RD และ BD ห้ามมี NAR · TD/TG รับทุกประเภทรวม WMS
3. **ตรวจสต๊อกรายประตูตอนส่ง (มติ 51, `_docsGateStockGuard`)** — RD/OD/BD/TD(/TG): รวมความต้องการต่อ (โครงการ, ประตู, วัสดุ) เทียบกับ `on_hand − pending` ของประตู (ล็อกแถว) · ประตู = `item.GateID` ที่ผู้ใช้เลือก ไม่งั้นประตูตั้งต้นใน `project_materials.gate_id` · วัสดุที่ไม่มีแถวรายประตูเลยเทียบกับยอดไซต์ · ไม่ผ่าน: `ของที่ประตูไม่พอ — …` (+ `· ยอดติดลบ` / `· จองเกินของที่มี`)
4. **ตัวตนจากเซิร์ฟเวอร์ (GP-10/16)** — ผู้ขอ = session (username หรือ fullName ของผู้รับเหมา) ค่าที่ client ส่งถูกละเลย
5. **ผู้อนุมัติที่เลือก (`_docsApproverError`)** — ไม่ใช่ตัวเอง ไซต์เดียวกัน active ระดับ ≥ 6 (RD) / ≥ ขั้นต่ำ และ < 99

### 6.5 รายละเอียดต่อประเภท

**RD — `rpc_processRequisitionSubmission`**
- ฟิลด์ต่อรายการ: `MatCode` (IC, ไม่ใช่ NAR), `Qty`, `Receiver` (SubID / ชื่อชุด / `DC:…`), `GateID` (ไม่บังคับ), `SiteCode`, `UsageArea`, `Notice`, `Charge` (→ `charge_money`), `Approver` (บังคับเว้นแต่ auto)
- Auto-approve เมื่อผู้ส่ง level ≥ 6 (<99) **หรือ** เป็น PM ของไซต์ → `Approved` ผู้อนุมัติ = ผู้ส่ง · level 0 **ไม่** auto
- gate log `Awaiting` ถูกสร้างตอน auto-approve หรือตอนอนุมัติ · จอง (pending) เมื่อ Approved · ตัดออกตามหยิบจริงตอน finalize

**OD — `rpc_processOddsSubmission`** — เหมือน RD แต่ NAR เท่านั้น ไม่ผ่านอนุมัติ `Approved` ทันที gate log สร้างทันที · ผู้ใช้เลือกประตูได้ (ต่างจาก GAS เดิม)

**BD — `rpc_processBorrowBatch`** (เก่า: `rpc_processBorrowSubmission` ทีละรายการ — เอาผู้ขอจาก client, ไม่ตรวจสต๊อกประตู)
- ต้องมี `DueDate` ต่อรายการ (วันนี้ถึง +366 วัน) · `due_date` ของใบ = วันที่เร็วที่สุด · `charge` = 0 เสมอ
- ระดับอนุมัติตัดสินทั้งตะกร้า: มี C01 ที่ไหน → ทุกใบที่แยกต้อง R6+ ไม่งั้น R4+ (⚠ หน้าคิวอนุมัติคิดต่อใบ จึงอาจแสดง R4 สำหรับใบย่อยที่ไม่มี C01)
- auto เมื่อผู้ส่ง level ≥ ขั้นต่ำ → `Sent Borrow` · หน้าอนุมัติแสดง `dueDate`, `borrowerOverdue`, `borrowerOverdueDocs` (สูงสุด 5)

**IN — `rpc_processInboundBatch`** — ดูบทที่ 11

**TD — `lib/transfer.php`** — ดู 12.1 · **TG — `lib/gatemove.php`** — ดู 12.2 · **SC — `lib/stockcount.php`** — ดู 12.3

### 6.6 รูปถ่ายที่บังคับ

| จุด | กติกา |
|---|---|
| หน้าถ่ายรูปยืนยัน (`saveConfirmationData`) | ≥ 1 รูปต่อรายการที่หยิบจริง > 0 (กติกาข้อ 21) ครอบคลุมขาออก IN ขาเข้า TG ขาคืน BD → `document_items.photo_url` (ขาคืน/ขาเข้า → `photo_return_url`) และต่อท้ายรูประดับใบด้วย |
| IN ตอนสร้าง | รูปแหล่งที่มา ≥ 1 ≤ 6 |
| Bypass กระดาษ | รูปแบบฟอร์ม + รูปต่อรายการสำหรับ RD/OD/TD (≥1, ≤6 ต่อรายการ, ≤60 ต่อใบ, ≤8 MB ต่อรูป) IN รายการไม่บังคับ |
| ตีชำรุด/สูญหาย | ไม่บังคับ ≤ 3 |
| SC | ไม่มีรูป |

การบีบอัดฝั่ง browser: `compressImage` 1024px คุณภาพ 0.6 (inbound-ctl 1600px/0.75, bypass 1024px WebP/0.6) · ส่งเป็น data URI (เพราะ `max_file_uploads=20`, `post_max_size=40M`) · เซิร์ฟเวอร์ตรวจชนิดจริงด้วย finfo/magic bytes รับ webp/jpeg/png เท่านั้น

---

## 7. การอนุมัติ ตีกลับให้แก้ และยกเลิก (`lib/approval.php`, `lib/doc_revise.php`)

### 7.1 คิวอนุมัติ (`rpc_getApprovalRequests`)

- รวม RD/BD/IN/TD/TG ที่สถานะมี `awaiting` หรือ `รออนุมัติ` (รวม `Awaiting revision`) · OD และ SC ไม่เข้าคิว
- การมองเห็น: R0 ทั้งหมด (อ่านอย่างเดียว เลือกไซต์ได้) · R4+ ทั้งไซต์ · R1–R3 และผู้รับเหมาเฉพาะใบตัวเอง
- `canAct` = ระดับ ≥ ที่ต้องการ และผู้อนุมัติที่ระบุว่างหรือเป็นตัวเอง (R0 ไม่เคย act ได้)
- แต่ละการ์ดแสดง `stockShort`/`shortText` ต่อรายการ (`docReviseStockCheck`) · ใบ BD แสดงกำหนดคืนและประวัติเกินกำหนดของผู้ยืม

### 7.2 `rpc_updateApprovalStatus(docNo, newStatus, docType, -, -, reviseNote)`

1. `newStatus` ∈ {Approved, Rejected, Awaiting revision} · ใบต้องเป็น `Awaiting approval` พอดีและอยู่ไซต์ของผู้กระทำ
2. **อนุมัติ/ปฏิเสธใบตัวเองได้เฉพาะ PM ของไซต์** (GP-16) ไม่งั้น `อนุมัติ/ปฏิเสธใบของตัวเองไม่ได้`
3. ระดับที่ต้องการ (คำนวณจากรายการจริงของใบ): C01 → 6 · NAR ล้วน → 1 · RD → 6 · อื่น → 4 · TG → `gmDocRequiredLevel` · TD → PM ต้นทางเท่านั้น
4. ถ้าระบุผู้อนุมัติไว้ **คนนั้นเท่านั้น** ที่ทำได้
5. **ตีกลับให้แก้** ต้องมีเหตุผล ≥ 3 ตัวอักษร → `Awaiting revision`, log `revise_request`, แจ้ง `revise` ไปผู้ขอ · ไม่จอง ไม่สร้าง gate log
6. **ตรวจสต๊อกตอนอนุมัติ** (RD/OD/BD/TD/TG): `on_hand − pending` ของประตู (pending นับเฉพาะใบอื่นที่อนุมัติแล้ว) → ขาด: `สต๊อกที่ประตู Gxx ไม่พอ — … (ใบรออนุมัติไม่จองของ — ใบที่อนุมัติก่อนได้ของก่อน)`
7. สถานะที่เขียน: BD → `Sent Borrow` · IN → `Sent Inbound` · อื่น → `Approved` · ตั้ง `approver_username`/`approved_by` = ผู้กระทำ · สร้าง gate log `Awaiting`
8. ทุกเส้นทางจบด้วย `recalcPending` · ปฏิเสธไม่ต้องใส่เหตุผล
- ⚠ หน้าคิวใช้ `roleLevel` จาก session (ตอน login) แต่การกระทำ re-query ฐานข้อมูล — การเปลี่ยนบทบาทมีผลต่อการกระทำทันที แต่หน้าคิวเปลี่ยนหลัง login ใหม่

### 7.3 แก้ไขใบ (`rpc_reviseRequisition(docNo, items[{matCode, qty}], note)`)

- เฉพาะผู้ขอ และเฉพาะขณะ `Awaiting approval` หรือ `Awaiting revision` (rev2 10-06: แก้ได้ใน "คำขอของฉัน" แม้ยังรออนุมัติ)
- ลดจำนวนได้อย่างเดียว · 0 = ตัดรายการ · ต้องเหลือ ≥ 1 รายการ · ห้ามเพิ่มวัสดุ ห้ามเปลี่ยนประตู · ตรวจสต๊อกประตูซ้ำ
- ผล: กลับเป็น `Awaiting approval` เลขเดิม ผู้อนุมัติเดิม · log `revise_submit` · ถ้ามีอะไรเปลี่ยน แจ้ง `revise_back` ไปผู้อนุมัติและคนที่ตีกลับ

### 7.4 ยกเลิก (`rpc_cancelRequisition`)

- เฉพาะผู้ขอ (username หรือ fullName ตรง)
- ห้ามเมื่อสถานะมี: completed, borrowed, returned, closed, cancelled, rejected, sent return
- **ห้ามเมื่อ gate log เป็น Opened/Scanned/Confirmed/Closed แล้ว** (Scenario 05 ⑩) — ข้อความบอกให้จบรอบ: หยิบจริง 0 หรือเอาของกลับด้วยใบ IN
- ผล: คืนสต๊อกที่ตัดไปแล้ว (`restoreStockForDoc`) · IN จาก PO buffer คืน `qty_consumed` ให้ buffer (`poRollbackPushesForDoc`, มติ 26 — ถ้าคืนไม่ได้ ยกเลิกไม่ผ่านทั้งหมด) · ใบ → `Cancelled`, gate log → `Cancelled` (แถว RT ไม่แตะ) → `recalcPending`

---

## 8. สต๊อก — ยอดคงเหลือ การจอง รายประตู ยอดติดลบ (`lib/stock.php`)

### 8.1 โครงสร้าง

- `stock_balances` (ยอดไซต์) และ `stock_gate_balances` (ยอดรายประตู) คอลัมน์ `qty_in`, `qty_out`, `on_hand`, `pending`
- **ทุกการเคลื่อนไหวผ่าน `applyBalanceDelta(project, material, dIn, dOut, gateId)`** ซึ่งอัปเดตทั้งสองตารางใน transaction เดียว · ประตูไม่ทราบ → ลงที่ประตู active ตัวแรกของโครงการ
- `on_hand = round(qty_in − qty_out, 3)` · `qty_in`/`qty_out` สะสมและ ≥ 0 · **ตั้งแต่ GP-42 (2026-10-02) on_hand ติดลบได้** (เดิมปัดเป็น 0) ทุกครั้งที่ติดลบเขียน error log `applyBalanceDelta: … kept negative (GP-42)`

### 8.2 การจอง (`recalcPending`)

- คำนวณใหม่ทั้งโครงการหลังทุกการเปลี่ยนแปลง: ล้าง `pending` ทั้งคอลัมน์ แล้วรวม **`qty` ที่ขอ** ของรายการใน RD/OD/BD/TD/TG ที่สถานะมี `approved` หรือ `sent borrow` (`Doc::PENDING_STATUSES`)
- **ตั้งแต่ 2026-10-06 ใบที่รออนุมัติไม่จอง** ("pending approved only") → จองเกินได้ ตัวตัดสินคือการตรวจตอนอนุมัติ "ใบที่อนุมัติก่อนได้ของก่อน"
- พร้อมเบิก (available) = `on_hand − pending` ใช้ใน `stockGateAvail`, `_docsGateStockGuard`, และ guard ตอนอนุมัติ

### 8.3 ประตูของเอกสาร (`stockResolveDocGateId`)

1. `documents.gate_id` → 2. Gxx ท้ายเลขใบ → 3. `gate_logs.gate_id` → 4. ประตูแรกของโครงการ

### 8.4 การตัด/เพิ่มสต๊อก (`finalizeGateDoc` — ตอนปิดประตู หรือตอนยืนยันรูปสำหรับ scan-flow)

| เอกสาร | การเคลื่อนไหว | สถานะใหม่ |
|---|---|---|
| RD/OD/TD | out += จำนวนมีผล (`qty_actual ?? qty`) | Completed |
| BD | out += จำนวนมีผล | Borrowed |
| IN | in += qty ที่ประตูที่สแกน · ตั้ง `project_materials.gate_id` ถ้ายัง NULL | Completed |
| BD `…RT` | out −= `qty_return_round` (ไม่เกินค้าง) ที่ประตูเดิม · `qty_returned` สะสม | Returned (+`return_ts`, ล้าง overdue/stock_deducted) ถ้าไม่เหลือ ไม่งั้น Borrowed |
| SC | ไม่มี | Completed |
| TG | ผ่าน `gmFinalizeGateDoc` (ดู 12.2) | In Transit / Completed |

- `stock_deducted` ต่อรายการทำให้ตัด/คืนเป็น idempotent · ใบที่ตาย (cancel/reject/return) ถูกข้าม · หลังจากนั้น gate log → `Closed` (ประตูมีตู้) หรือ `Confirmed` (scan-flow)
- การเคลื่อนไหวอื่น: ยกเลิก/ปฏิเสธ → `restoreStockForDoc` (ย้อนเฉพาะแถว stock_deducted=1) · Bypass กระดาษ → `deductStockForDoc` ทันที · ผลนับสต๊อกที่อนุมัติ (ดู 12.3) · admin: `stockTransferBetweenGates` (ย้ายระหว่างประตูไม่มีเอกสาร จำกัด `on_hand − pending` ต้นทาง log `gate_transfer`), `stockAllocateUnassigned` (ยอดไซต์ที่ไม่อยู่ประตูใด → ประตูตั้งต้น) · ย้าย Mango→IC / recode IC ย้ายทั้งยอด

### 8.5 ยอดติดลบบนหน้าจอ (`js/neg-stock.js`, GP-42/OP-74)

- Dashboard: ตัวเลขแดง + ป้าย `ติดลบ` · chip ประตูที่ติดลบแสดงเป็นแดงแทนซ่อน · รายละเอียดวัสดุ `ยอดติดลบ`
- ฟอร์มเบิกเตือนต่อประตู: `ยอดติดลบ … — แจ้งสายสโตร์ตรวจนับ` / `ยอดไม่พอ: ในคลัง X · จองไว้ Y → ขาด Z (ใบที่อนุมัติแล้วจองเกินของที่มี)` · ทางแก้คือ SC หรือ IN
- ⚠ 2 แถวเก่าที่ถูกปัดเป็น 0 ก่อนแพตช์ยังค้าง (ดูบทที่ 21)

### 8.6 เวลาหยิบและเพดาน (`gate_settings`, `s05ValidateGateSettings`)

| ค่า | ปริยาย | ช่วง | ARI ปัจจุบัน |
|---|---|---|---|
| `pick_min_per_item` นาที/รายการ | 3 | 0.5–30 | 3.00 |
| `pick_cap_min` เพดาน | 120 | 5–600 | 9 |
| `extend_min` ต่อครั้ง | 5 | 1–60 | 3 |
| `store_over_cap` สายสโตร์เกินเพดานได้ | 0 | 0/1 | **คอลัมน์ยังไม่ถูกสร้างบนฐานจริง** = ปิด |

เวลาหยิบ = นาที/รายการ × จำนวน **รหัส IC ที่ต่างกันในรอบ** · เปิด switch แล้วบัตรสายสโตร์ขยายเวลาหยิบและเวลาปิดประตู (ALARM 3) เกินเพดานได้ โดยทุกครั้งขึ้นเตือน OVER CAP บน Dashboard · การบังคับจริงอยู่ที่โปรแกรมตู้ (เซิร์ฟเวอร์แค่ส่ง flag ผ่าน `getCardList`/`getGateSettings` และ log)

### 8.7 Min-Max (มติ 49, `lib/inventory_insights.php`)

- ต่อ IC: คงเหลือ, พร้อมเบิก (= คงเหลือ − pending), ADU (ใช้เฉลี่ยต่อวัน), σ — คำนวณจาก **วันที่ไซต์มีเอกสารจริง** ไม่ใช่วันปฏิทิน ใช้ RD + OD (ไม่รวม BD)
- Safety = Z·σ·√LT · Min แนะนำ = ⌈ADU·LT + Safety⌉ · Max = max(Min+1, ⌈ADU·LT + Safety + ADU·cycle⌉) ต้องมีข้อมูล ≥ 2 วัน
- สถานะ: low (พร้อมเบิก ≤ Min; Min = 0 → เฉพาะ < 0) / over (คงเหลือ > Max) / ok / unset · จำนวนสั่ง = เป้า − พร้อมเบิก (เป้า = Max หรือ Min + ADU·cycle)
- ค่าปริยาย: lead time 7 วัน, รอบสั่ง 14 วัน, service level 95% (Z 90=1.2816, 95=1.6449, 98=2.0537, 99=2.3263), ย้อนหลัง 180 วัน (14–730) · บันทึกได้ครั้งละ ≤ 2,000 รายการ · แก้ไข: ADM ทุกไซต์, สโตร์ไซต์ตัวเอง · **ยังไม่ได้ตั้งค่าบนฐานจริง**

---

## 9. การหยิบและถ่ายรูปยืนยัน (Scenario 05) · รอบจ่าย · ประวัติรอบหยิบ

### 9.1 ลำดับการจ่ายของที่ประตู (ภาพรวม)

```
ผู้เบิกเปิด QR ของใบ (หน้า "QR เอกสาร")  →  สแกน QR ที่ตู้ (หลายใบได้)  →  แตะบัตร
→ ตู้เรียก submitPickingList → เว็บออก PK (รอบหยิบ) + gate_logs = Opened  →  ตู้ปลดล็อก เริ่มนับเวลาหยิบ
→ ผู้เบิก/สโตร์เปิดหน้า "ถ่ายรูปยืนยัน" บนมือถือ: กรอกหยิบจริง + รูปต่อรายการ → saveConfirmationData → gate_logs = Confirmed
→ ครบทุกใบ → ตู้ขึ้น "กรุณาปิดประตู" (5 นาที)  →  ปิดประตู → ตู้ส่ง closeGate → finalizeGateDoc ตัดสต๊อก → Closed / Completed
```

ประตูที่ไม่มีตู้ (scan-flow, `hardware_close=0`): ไม่มีขั้นแตะบัตร/ปิดประตู gate log = Scanned และ finalize ทันทีเมื่อยืนยันรูป

### 9.2 หน้าถ่ายรูปยืนยัน (`lib/gate_api.php::saveConfirmationData`, `js/scenario05.js`)

1. **ใคร:** หลังแตะบัตรแล้วเท่านั้น (gate log Opened/Scanned) — ผู้ขอ, สายสโตร์ หรือ R0 · TG: สโตร์เท่านั้น · SC ถูกส่งไปหน้านับสต๊อกแทน · บันทึกแล้วแก้ไม่ได้ (`บันทึกยืนยันไปแล้ว แก้ไขไม่ได้`)
2. **ขอ vs หยิบจริง:** แก้ได้สำหรับขาออก RD/OD/BD/TD/TG และขาคืน BD · **0 ≤ หยิบจริง ≤ ที่ขอ** (`S05_MAX_OVER_PICK = 0` — เดิมเคยให้ +3) อยากได้เพิ่มต้องเปิดใบใหม่ · IN และขาเข้า TG ใช้จำนวนตามใบ แก้ไม่ได้ · เก็บใน `qty_actual` + `actual_reason`
3. **หยิบน้อยกว่าขอ (ขาออก):** ต้องเลือกเหตุผลจากรายการ `S05_PICK_REASONS = ['ของไม่พอ','สแกนผิด','ไม่ต้องการแล้ว']` (GP-05) · `สแกนผิด` = สแกนใบผิดหลังแตะบัตรแล้ว ให้ใส่ 0 แทนการเอาของกลับด้วย IN · ขาคืนเป็นข้อความอิสระ (chip: `ยังใช้งานอยู่ — ทยอยคืน`, `ของหาย`, `ชำรุดจนใช้ไม่ได้`, `ผู้ยืมไม่ได้นำมาคืน`) · หยิบ = ขอ → ล้างเหตุผล · log `pick_actual`/`return_actual`
4. **Zero pick:** หยิบจริง 0 ทุกรายการขาออก = ยกเลิกทางอ้อมหลังแตะบัตร — ยังบันทึกได้ แต่เขียน error log `Zero pick: <doc> …` และ activity `zero_pick` toast `บันทึกเป็น ALARM: หยิบจริง 0 ทุกรายการ` · รายการที่ 0 ไม่ถูกตัด
5. **หลังบันทึก:** gate log → `Confirmed`, log `gate_confirm` · scan-flow → finalize ทันที · มีตู้ → รอ `closeGate` · ถ้าตู้ปิดรอบไปแล้ว → `Late confirm:` log, ตั้ง Closed, finalize · UI: `ยืนยันแล้ว x/y ใบ` → `รอปิดประตูที่ตู้` → `ปิดประตูแล้ว — ตัดสต๊อกสำเร็จ` (ไม่มี lock กั้นชุดถัดไปแล้ว)
6. **สัญญาณของขาด (`s05ReasonIsShortage`):** นับเฉพาะ `ของไม่พอ` (เหตุผลอิสระก่อน 10-02 เดาด้วย regex `ไม่พอ|ของหมด|…`)
7. หน้ายืนยันแสดง `gateOnHand` (GP-42) · ตรวจสอบประจำวันแสดง "หยิบจริง (ขอ N)" และป้าย bypass

### 9.3 Pick alerts บน Dashboard — การ์ด "ต้องตรวจสอบจากหน้าประตู" (`rpc_getPickAlerts`)

- ดูได้: R0 และ level ≥ 8 ทุกไซต์ · สโตร์ (`can_req`) ไซต์ตัวเอง
- `shortfalls` 30 วัน: กลุ่มตามประตู × IC × (ของไม่พอ / อื่น) + on_hand ปัจจุบัน → `หยิบได้น้อยกว่าที่ขอเพราะของไม่พอ — … ควรตรวจนับที่ประตู`
- `overCap` 14 วัน: จาก `gate_round_events` + error log `OVER-CAP`/`OVER CAP`/`เกินเพดาน`
- `alarm4`: `Person detected … LATCHED` ที่ยังไม่มี `ALARM 4 killed` ตามหลัง (7 วัน)
- `zeroPick` 30 วัน: พร้อมเหตุผล ผู้ยืนยัน ผู้ถือบัตร

### 9.4 รอบจ่าย (dispatch rounds, มติ 50, `lib/dispatch_rounds.php`)

- "รอบจ่าย" (dispatch) ≠ "รอบหยิบ" (PK) — รอบจ่าย = ช่วงเวลาต่อไซต์ `[cutoff เบิกก่อน, dispatch จ่ายเวลา]` สูงสุด 12 รอบ/วัน ในวันทำงาน (`work_days` ปริยาย จ–ส) · ใบเข้ารอบที่ชนะ cutoff แบบ strict (ส่ง 08:00 พอดีไม่ทัน "ก่อน 8 โมง") · หลังรอบสุดท้ายหรือวันหยุด → รอบแรกของวันทำงานถัดไป · คำนวณสดจาก `doc_ts` ไม่เก็บลงใบ
- แก้ไขได้: ADM ทุกไซต์, สโตร์ไซต์ตัวเอง · ดูบอร์ด: level 0, ≥ 8, สโตร์ไซต์ตัวเอง
- บอร์ด "รอบจ่าย" ใน Dashboard: RD/OD/BD/TD สถานะใน `DR_OPEN_STATUSES` (รวมรออนุมัติ แต่ไม่นับเป็น "ของที่ต้องเตรียม") · สถานะรอบ past/closed/open/upcoming + `overdue` (เลยเวลาจ่ายแล้วยังค้าง) / later
- UI: แถบ `#drStrip` บนหน้าเบิก (รอบปัจจุบัน + นับถอยหลัง), ป้าย "รอบจ่าย 11:00"/"เลยรอบ" บนการ์ด QR, ข้อความรอบใน popup ยืนยัน/สำเร็จ · **ยังไม่ได้ตั้งค่าบนฐานจริง**

### 9.5 ประวัติรอบหยิบ (`lib/picking_history.php`, `js/history-picking.js`, 2026-10-08)

- หน้าประวัติ สวิตช์ "ราย เอกสาร | ราย Picking list" → การ์ดต่อ PK: ประตู, เริ่ม/จบ, สถานะ (open กำลังหยิบ / confirmed ยืนยันแล้ว·ยังไม่ปิดรอบ / closed ปิดรอบแล้ว / cancelled), `usedSec`, การขยายเวลา, overrun, overCap, ผู้ถือบัตร, ผู้ยืนยัน, เอกสารและรายการ, ขอ/หยิบจริง
- แหล่งข้อมูล: `gate_logs.picking_id`, `gate_round_events` (`round_end` + bypass), activity `gate_add_docs`/`gate_confirm`/`zero_pick`, error log ที่มี `PickingID=` (ALARM — แสดงเฉพาะผู้มีสิทธิ์ดู Error log)
- การมองเห็น: R0 และ ≥R6 ทั้งไซต์ · R1–R5 เฉพาะรอบที่มีใบตัวเอง · สโตร์ทุกรอบของไซต์ (การตีความของผู้พัฒนา ยังรอยืนยัน) · สูงสุด 1,500 รอบ
- คืนเป็นงวดใช้แถว RT เดิม → PK เก่าของ RT นั้นดูได้จาก `closedDocs` และ activity log เท่านั้น

---

## 10. ยืม-คืนอุปกรณ์ (BD) (`lib/borrow.php`, `js/borrow-return.js`)

1. **กำหนดคืน** บังคับทุกใบใหม่ (วันนี้ถึง +366 วัน) → `documents.due_date` · ฟอร์มเตือนใบเกินกำหนดของผู้ยืมเอง (`rpc_getMyBorrowOverdue`, GP-13) เตือนอย่างเดียวไม่บล็อก
2. **จำนวนต่อรายการ:** ยืม = `qty_actual ?? qty` · คืน = `qty_returned` · ตีจำหน่าย = Σ `borrow_writeoffs.qty` · **ค้าง = ยืม − คืน − ตีจำหน่าย**
3. **สถานะแสดง (`borrowDocState`):** `borrowed` ยืมอยู่ · `return_pending` แจ้งคืนแล้ว — รอสแกน QR คืน · `return_open` กำลังคืนที่ประตู · `writeoff_pending` (ข้อมูลเก่า: ขาคืนจบแต่ไม่ครบ) · `closed` (Returned) · `other`
4. **การคืน / ทยอยคืน (2026-10-08):**
   - `rpc_registerReturnGateLog` (`rpc_returnBorrowItem` เป็น alias) เรียกได้โดยผู้ยืมหรือผู้เห็นทั้งไซต์ · ต้องเป็น `Borrowed` (หรือ `Sent Return` เก่าที่มี return_ts) และค้าง > 0
   - ผล: → `Sent Return`, `return_ts=NULL`, ล้าง `qty_return_round` · **ใช้แถว gate log `<doc>RT` และ QR เดิมซ้ำ** (รีเซ็ตเป็น Awaiting ถ้าเคย Closed/Cancelled) ต้องสแกนที่ **ประตูเดิม** · log `return_round`
   - ยืนยันรูปบันทึก `qty_return_round` (≤ ค้าง ถ้าน้อยกว่าต้องใส่เหตุผล) → ปิดประตู: คืนสต๊อก · ค้าง 0 → `Returned` ไม่งั้นกลับ `Borrowed` (กำหนดคืนเดิมยังใช้นับเกินกำหนด) · ปุ่ม "คืนเพิ่ม (ทยอยคืน)" เปิด QR RT เดิม
   - `rpc_confirmReturnAtGate` (คืนตรงโดยไม่ถ่ายรูป) **ปิดอยู่** เว้นแต่ `settings allow_direct_return=true`
5. **งานเกินกำหนด:** `borrowDailyTick()` จาก config.php ในคำขอแรกของวัน (marker `settings/.borrow-daily-<db>`, flock) หรือ `db/borrow_overdue_job.php` · `borrowOverdueRun` ดู BD Borrowed/Sent Return ที่ `due_date < วันนี้` และค้าง > 0 → `overdue_flag=1` + error log `Borrow overdue: <doc> | borrower=… | sub=… | due=… | days=… | items=…` วันละครั้งต่อใบ · ล้าง flag ที่ไม่เกินแล้ว · **ไม่ส่งแจ้งเตือนใด ๆ**
6. **Dashboard `rpc_getBorrowAlerts`** การ์ด "อุปกรณ์ยืมค้างคืน": overdue, dueSoon (≤ 2 วัน), writeoffPending (รวมคืนบางส่วน), noDueDate, overdueBorrowers
7. **ตีชำรุด/สูญหาย (`rpc_writeOffBorrowItems`)** — สายสโตร์เท่านั้น และ **ไม่ใช่ใบยืมของตัวเอง** (GP-16) · ทำได้ที่ borrowed/return_pending/writeoff_pending (ห้ามขณะ return_open หรือ closed) · ต่อรายการ: qty ≤ ค้าง, `kind` damaged/lost, เหตุผล ≥ 3 ตัว, รูป ≤ 3 · ราคาอ้างอิง: rate card ก่อน ไม่งั้นสโตร์กรอก (`ref_source` ratecard|manual) · **ไม่คืนสต๊อก ไม่สร้างรายการหักเงิน** (`writeoff_flag=1`) · ค้าง 0 → `Returned` แถว RT ที่ Awaiting → Cancelled · แจ้ง `borrow_writeoff` ในแอปไปผู้ยืม ผู้อนุมัติ และ PM ของไซต์
8. **รายงานชำรุด/สูญหาย** `rpc_generateBorrowLossPDF` → `<docNo>_ชำรุด-สูญหาย.pdf` (template `borrow_loss.php`: สรุปรายการ ยืม/คืน/ชำรุด/สูญหาย/ค้าง, รายการที่ตีพร้อมมูลค่าอ้างอิง, กล่องตัดสินใจ หักผู้เบิก / หักผู้รับเหมา / ลงงบโครงการ / อื่น ๆ, ช่องเซ็น 3 คน, รูป)
9. **ใบยืมเก่าที่ไม่มีกำหนดคืน** (`due_date NULL`): ไม่นับเกินกำหนด แสดง `ไม่มีกำหนดคืน (ใบรุ่นเก่า)` · มี 10 ใบ (มิ.ย.–ก.ค.) รอตัดสินใจ
10. `rpc_getUnreturnedItems` 1 แถวต่อรายการ (`qty` = ค้าง) + `canReturn`/`canWriteoff` · ตาราง "รายการอุปกรณ์ที่ยังไม่ส่งคืน" ถูกแทนที่โดย borrow-return.js (แสดงกำหนดคืน, "เกินกำหนด N วัน", ปุ่มตีชำรุด/สูญหายสำหรับสโตร์)
11. แจ้งเตือนในแอป `#brNotices` (`rpc_getMyNotices`/`rpc_ackNotices`) poll ทุก 5 นาที

---

## 11. รับเข้าคลัง (IN) · ใบสั่งซื้อ/Buffer · OCR

### 11.1 ใบรับเข้า (`rpc_processInboundBatch`, `lib/inbound_ctl.php`, `js/inbound-ctl.js`)

1. **ใคร (`inCtlAccess`, GP-10/OP-70):** บัญชี staff ที่มี `can_req` และ level < 99 · ADM ออกให้ทุกไซต์ได้ ที่เหลือเฉพาะไซต์ตัวเอง (`SiteCode` จาก client ถูกละเลย) · แท็บ "รับเข้าคลัง" ซ่อนสำหรับคนที่ไม่ใช่สโตร์
2. **แหล่งที่มา (`documents.in_source`) บังคับเลือก:**
   - `supplier` **มีใบส่งของ** — ต้องมีเลข RS/PO/ใบส่งของ (จาก meta หรือ `RS` ของรายการ) **และ** รูปใบส่งของ ≥ 1
   - `nonote` **ไม่มีใบส่งของ** — ต้องมี `ref` (TD ของไซต์นี้เป็นต้นทางหรือปลายทาง หรือ BD ของไซต์นี้ — ตรวจโดย `inCtlRefError`) **หรือ** เหตุผล ≥ 3 ตัว + รูปของ ≥ 1 · เหตุผลใน UI: `ของคืนจากหน้างาน`, `ของโอนมาโดยไม่มีใบ TD`, `ของเดิมที่ยังไม่ได้ลงระบบ`, `ของแถม / ตัวอย่างจากผู้ขาย`
   - รูป ≤ 6 (JPEG/PNG/WebP) → `in_photo_url` ลบทิ้งถ้าใบไม่ถูกเขียน
3. **ไม่มีประตูบนใบ IN (2026-10-06):** 1 ใบต่อการส่ง ไซต์เดียว (`ใบรับเข้าหนึ่งใบต้องเป็นของไซต์เดียว`) · เลขไม่มี G · `GateID`/`NoGate` จาก client ถูกละเลย · `Sent Inbound` + gate log Awaiting (gate NULL) ทันที · ที่ตู้: สแกนได้ทุกประตู active ของไซต์ → ผูกประตูที่สแกน (`in_gate_rebind`) สต๊อกเข้าที่ประตูนั้น · รับครั้งแรกตั้งประตูตั้งต้นของวัสดุ
4. `rs_no` ของใบ = `RS` ของรายการแรก ไม่งั้น meta `rs` · แต่ละรายการเก็บ `rs_no` ของตัวเอง
5. **หน่วย:** รหัส IC เท่านั้น (มติ 33/34/38) รหัสไม่รู้จัก → `MatCode ไม่ถูกต้อง (ไม่มีใน MaterialsMain)` · จำนวนที่รับเข้าแก้ตอนถ่ายรูปไม่ได้ (ยังไม่ตัดสินใน Doc 05)

### 11.2 ใบคุม (PO) — `po.php`, `po_view.php`, `lib/po.php` (CanReq หรือ ADM)

1. **อัปโหลด PDF → OCR Gemini → ตรวจทาน → บันทึก** (`poCommit`): ไฟล์เก็บที่ `uploads/po/Y-m/` + แถว `ocr_logs` · หน้าตรวจทานติ๊กบรรทัดออก สลับ item/adjust ลากเรียง (เลขบรรทัดใหม่ 1..N) ติ๊ก "เพิ่มเข้าทะเบียน Mango" · PO ซ้ำถูกปฏิเสธ · **OCR ไม่สร้าง PO เอง** (มติ 21) · ไซต์ตั้งต้นมาจากเลข PO `PO-XXXX-nnnnn` ไม่ตรงแค่เตือน (มติ 19)
2. **รับของ (`po_view.php` receive):** รับเกินจำนวนใน PO ไม่ได้ (มติ 6) · รับหลายรอบได้ (มติ 8) `RCV-{po_no}-{nn}` · บรรทัด adjust รับไม่ได้ (มติ 18) · แต่ละรอบ insert receipt, เพิ่ม `qty_received`, upsert `buffer_lines`, อัปเดตสถานะ open → partial → received · `setstatus` รับ cancelled/closed/open (⚠ `closed` ไม่อยู่ใน ENUM)
3. **ตรวจความถูกต้องหลัง OCR:** บรรทัดรวม = subtotal, subtotal − ส่วนลด = หลังหัก, VAT 7%, หลังหัก + VAT = รวม (tolerance 0.05) · เลขคณิตต่อบรรทัด (0.01) · เลขบรรทัดต่อเนื่อง · จัดเป็น adjust เมื่อจำนวนเงินหรือราคาติดลบ, หน่วย "บาท", ชื่อขึ้นต้น ค่าส่วนลด/ส่วนลด/ค่าขนส่ง/ค่าบริการ/ค่าดำเนินการ · จับคู่รหัสกับทะเบียน
- ⚠ PO ไม่กั้นไซต์: ผู้มี CanReq เห็นและยกเลิก PO ทุกไซต์ได้

### 11.3 รับของ / buffer — `rc.php` + `api/rc_api.php` (มติ 5, 7, 9–11, 20, 23, 26)

- buffer เก็บเป็น **หน่วยซื้อ** (`buffer_lines.qty_received/qty_consumed`) · ผู้ใช้ลากบรรทัด buffer เข้าตะกร้า กรอก "ใช้หน่วยซื้อไป" (1 ค่าต่อ push) แล้วแตกเป็น IC ≥ 1 ตัว กรอกจำนวน **หน่วยเก็บ** เอง · อัตราแปลงไม่เก็บเป็นข้อมูลหลัก คำนวณย้อน `rate = qty_buy / qty_store` ต่อ push (push ที่แตกหลาย IC ติดธง `split`)
- `poPushBatch`: IC บังคับ (มติ 7) · ไซต์เดียวต่อชุด · สโตร์ push ได้เฉพาะไซต์ตัวเอง ADM ทุกไซต์ · ต้องมีรูปใบส่งของ ≥ 1 · ล็อก buffer `FOR UPDATE` · เขียน `buffer_pushes` + `push_allocs` · เรียก `rpc_processInboundBatch` (มติ 5) ด้วย `RS=po_no`, source supplier → **ใบ IN ไม่มีประตู 1 ใบ** (มติ 23) · `origin_type='po'`, `origin_ref=po_no` (ถ้า PO เดียว) · จำ `ic_suggest_map` (`hit_count++`, มติ 20) · คำแนะนำ IC จาก `icSuggestFor(vendorKey, matCode)` (vendor เดียวกันก่อน; `poVendorKey` ตัดคำ บริษัท/จำกัด/(มหาชน)/หจก./ห้างหุ้นส่วนจำกัด)
- ยกเลิกใบ IN → `poRollbackPushesForDoc` คืน `qty_consumed` ให้ buffer (idempotent ผ่าน `cancelled_at`)
- ⚠ rc.php ยังออก IC ได้โดยไม่ผูก Mango (`create_ic`) — โค้ดบันทึกว่าเป็นข้อยกเว้นของมติ 47 โดยตั้งใจ

### 11.4 OCR ด้วย Gemini (`lib/gemini.php`, `lib/po_ocr.php`, `po_ocr_test.php`)

- `POST {endpoint}/models/{model}:generateContent` header `x-goog-api-key` · ส่ง **PDF ทั้งไฟล์** เป็น `inline_data` base64 (ไม่ดึงข้อความก่อน เพราะลำดับข้อความใน PDF สลับป้ายกับค่า — มติ 22) · `responseMimeType=application/json` + `responseSchema` (header: po_no, po_date, pr_no, project_*, vendor_*, quotation_*, delivery_date, payment_terms, deposit/retention, page_count, notes[], totals, amount_in_words · lines: line_no, mat_code, name, description, qty, unit, unit_price, discount, amount)
- prompt ภาษาไทย 8 กฎ (หัว/ท้ายซ้ำ = ชุดเดียว · บริษัทบนหัวจดหมาย = ผู้ซื้อ · รายการขึ้นต้นด้วยลำดับ คำอธิบายข้ามหน้าได้ · ข้อความสัญญาไป notes · คงบรรทัดส่วนลดติดลบ · ตัวเลขตามจริงไม่ปัด · วันที่ตามพิมพ์ · ยอดรวมเฉพาะหน้าสุดท้าย)
- `settings/gemini.php` คีย์: `api_key, model, endpoint, timeout (240), connect_timeout (15), max_output_tokens (32768), temperature (0), thinking_budget, max_pdf_bytes (15 MB), sample_dir` · **บนเครื่องนี้ `model` ว่างและไม่มี key → OCR ยังใช้งานไม่ได้**
- จำกัด: ไฟล์ต้องขึ้นต้น `%PDF-` ≤ 15 MB · `set_time_limit(300)` · **PDF เท่านั้น ไม่รับรูป (มติ 14)** · **ไม่มี fallback** (ล้มเหลว → `ocr_logs` status failed + แสดงผู้ใช้ ไม่มีหน้ากรอก PO มือ)
- `po_ocr_test.php` ทดสอบ/ตั้งค่า OCR (ไม่เขียน DB): `models` (รายชื่อรุ่นที่รองรับ generateContent), `run`, `usemodel` (session), `savemodel` (ADM — เขียนบรรทัด `'model'` ในไฟล์ตั้งค่าพร้อมตรวจ syntax/rollback), `clearmodel` · ⚠ po.php อ่านเฉพาะไฟล์ ไม่อ่านค่าใน session

---

## 12. โอนย้ายข้ามไซต์ (TD) · ย้าย Gate (TG) · นับสต๊อก (SC)

### 12.1 TD — เบิกโอนย้ายข้ามไซต์ (`lib/transfer.php`, `lib/doc_ext.php`, `js/transfer.js`)

- แท็บ "โอนย้าย (ข้ามไซต์ / ย้าย Gate)" โหมด **ภายนอก — ข้ามไซต์**
- สร้างได้: บัญชี staff ที่ผูกไซต์ (`accountType='user'`) ไม่ใช่ผู้รับเหมา · payload `{destSite, items[{MatCode,Qty,GateID}] (≤60 บรรทัด, ซ้ำรวมกัน), contact (บังคับ: ผู้รับที่ปลายทาง → usage_area), note, approver}` · ปลายทางต้องเป็นไซต์อื่นที่ active · รับทุก Char รวม WMS
- **อนุมัติ: PM ของไซต์ต้นทางเท่านั้น** — ผู้ส่งเป็น PM → Approved ทันที · ไซต์มี PM คนเดียว → เลือกให้อัตโนมัติ · หลายคน → ผู้ใช้เลือก · ไม่มี PM → สร้างใบไม่ได้ · `updateApprovalStatus` ปฏิเสธคนที่ไม่ใช่ PM ต้นทาง (`requiredRole` 11 ใช้แสดงผลเท่านั้น)
- เลข `TD+DDMMYY+NN+Gxx` เลขรันเดียวทั้งตะกร้า แยกตามประตูต้นทาง · สถานะ Awaiting approval → Approved → Completed
- **สต๊อก:** จองที่ประตูต้นทางขณะ Approved · ปิดประตู **ตัดต้นทางอย่างเดียว ไม่เพิ่มที่ไหน** · ปลายทางคีย์ IN ของตัวเอง (แหล่ง `nonote` + อ้างอิงเลข TD) และจับคู่ "นำเข้า = ส่ง" ด้วยมือ · **ปลายทางไม่ถูกเชื่อมกับต้นทาง** `getTransferList` แสดง TD "ขาเข้า" เพื่ออ้างอิงเท่านั้น (120 วัน ไม่รวม cancelled/rejected)
- RPC: `getTransferFormData` (ไซต์, PM, ยอดรายประตู), `processTransferSubmission`, `getTransferList`

### 12.2 TG — ย้าย Gate ภายในไซต์ (`lib/gatemove.php`, `js/gate-move.js`, 2026-10-02)

- โหมด **ภายใน site — ย้าย Gate** ในแท็บโอนย้าย · สร้างได้: สายสโตร์เท่านั้น (`can_req` + level 1–98 — ADM ไม่ได้) · payload `{destGate, items[{MatCode,Qty,GateID=ต้นทาง}] (≤60), note, approver}` ต้นทาง ≠ ปลายทาง
- **อนุมัติตามหมวด IC ทั้งตะกร้า (`gmRequiredLevel`):** มี C01 → R6 · มี C02 → R4 · NAR ล้วน → Approved ทันที (`approved_by` = ผู้สร้าง) · ผู้อนุมัติเลือกได้ level 4–98 ไม่ใช่ตัวเอง ≥ ระดับที่ต้อง (มีคนเดียวเลือกให้)
- **2 QR:** ขาออก `TG+DDMMYY+NN+Gsrc` สแกนที่ประตูต้นทาง · ขาเข้า `TG…GsrcGdest` (เช่น `TG02102601G01G03`) สแกนที่ประตูปลายทาง — แถว gate log ของขาเข้าถูกสร้างเมื่อขาออกปิด
- สถานะ: Awaiting approval → Approved → (ขาออกปิด) `In Transit` → (ขาเข้าปิด) Completed · หยิบจริง 0 ทุกรายการ → Completed ไม่มีขาเข้า (`tg_nothing_moved`)
- **ตู้:** ทั้งสองขาต้องแตะ **บัตรสายสโตร์ของไซต์นั้น** (`gmGateAcceptError`/`gmStoreCard`) และยืนยันรูปโดยสโตร์ · **จำนวนขาเข้า = หยิบจริงขาออก แก้ไม่ได้** · ยกเลิกได้จนกว่าจะแตะบัตรขาออก
- สต๊อก (`gmFinalizeGateDoc`): ขาออกปิด → ประตูต้นทาง out += หยิบจริง (ยอดไซต์ลดชั่วคราว) · ขาเข้าปิด → ยอดไซต์ out −= qty (แบบคืน ไม่ให้ in/out สะสมพอง), ประตูต้นทาง out += qty, ประตูปลายทาง in += qty → สุทธิยอดไซต์ไม่เปลี่ยน · ฟิลด์: `qty` ขอ · `qty_actual` ออกจริง · `qty_returned` นำเข้า · `stock_deducted=1` ระหว่างขนส่ง
- ⚠ นำเข้าต้องเท่ากับส่ง → ของหายต้องแก้ด้วย SC · ไม่มีเตือน In Transit ค้างและไม่มีเวลาจำกัด · Doc 05 ยังไม่มี flow TG
- RPC: `getGateMoveFormData` (ประตู, สต๊อก, ผู้อนุมัติ, `canCreate`), `processGateMoveSubmission`, `getGateMoveList` (ทั้งสองขา + QR ที่ active)

### 12.3 SC — นับสต๊อก (`lib/stockcount.php`, `js/stock-count.js`)

- สร้างจากแท็บ "นับสต๊อก" ในหน้าตรวจสอบประจำวัน โดยผู้มี `can_daily_check` (`createStockCount(gateCode)`) · **1 ใบที่ยังไม่เสร็จต่อประตู** (ขอซ้ำได้ใบเดิม) · ปฏิเสธถ้าประตูมีใบ Opened/Scanned ที่ยังไม่ยืนยัน · เตือนใบ "ค้าง" (Confirmed แต่ยังไม่ปิด)
- รายการ = ทุกวัสดุที่ `on_hand <> 0` ที่ประตูนั้น (รวมติดลบ) `qty` = ยอดระบบ · เลข `SC+DDMMYY+NN+Gxx` สถานะ Approved (ผู้อนุมัติ = ผู้สร้าง) gate log Awaiting ทันที · `receiver_name = นับสต๊อก Gxx`
- **ตู้:** SC ต้องเปิด **รอบของตัวเอง** — สแกนรวมกับใบอื่น SC ถูกข้าม · `addToPicking` ไม่รับ SC และรอบ SC ไม่รับใบอื่น · โปรแกรมตู้แสดงข้อความ "นับสต๊อก" (ตัวที่ติดตั้งยังขึ้น "ตัดสต็อก" จนกว่าจะ deploy)
- ขั้นตอน (`_scStageThai`): awaiting / counting / counted / done / cancelled · **นับแบบ blind** ซ่อนยอดระบบจนบันทึก · `saveStockCount` ต้องกรอกทุกบรรทัด (0 ถ้าไม่พบ) · ของที่พบเพิ่ม: IC เท่านั้น ≤ 50 ไม่ซ้ำ ใส่เป็น `พบเพิ่มระหว่างนับ` · ตอนบันทึก `qty` ถูกเขียนทับด้วย on_hand ณ ตอนนั้น `qty_actual` = นับได้ gate log → Confirmed · draft เก็บใน localStorage `cnx.sc.draft.<docId>`
- ปิดประตู → Completed **ไม่มีการเคลื่อนสต๊อก** · scan-flow finalize ตอนบันทึก · ถ้ารอบถูกปิดแล้ว → `Late count:` + finalize ทันที
- **การปรับยอด (`rpc_decideStockAdjust`)** ในหน้าการอนุมัติ `#scAdjustSection`: เฉพาะ **ADM (`role_code='ADM'`) หรือ level 8–98** · **ไม่ใช่คนที่นับ เว้นแต่เป็น PM** (GP-16) · เฉพาะ stage counted/done ต่อบรรทัด · อนุมัติ: delta = นับได้ − ระบบ → delta>0 in += delta, delta<0 out += |delta| ที่ประตู SC · ปฏิเสธต้องใส่หมายเหตุ · ทุกการตัดสินเขียน `stock_adjustments` (unique ต่อบรรทัด) + log `sc_adjust`/`sc_adjust_reject` · คิว `rpc_getStockAdjustQueue` แสดงใบค้างพร้อม `stuckQty`
- KPI **Stock Accuracy 30 วัน** = บรรทัดที่ตรง ÷ บรรทัดที่นับ (ใบ Completed)
- งานสุ่มนับรายสัปดาห์ (GP-03): ดูบทที่ 17

---

## 13. Bypass (`bypass.php` — R0 เท่านั้น, `lib/bypass.php`)

### 13.1 โหมด 1 — คีย์ย้อนหลังจากแบบฟอร์มกระดาษ (`bypassCreate`)

สำหรับวันที่ระบบล่ม ให้คีย์ภายใน 1 วันทำงาน

- **ข้าม:** การอนุมัติ · ประตู/QR/แตะบัตร · หน้าถ่ายรูปยืนยัน → สร้างใบเป็น `Completed` ทันทีและ **ตัด/เพิ่มสต๊อกทันที** ที่ประตูที่เลือก · `origin_type='bypass'`, `origin_ref='PAPER'`, notice `[BYPASS] คีย์ย้อนหลังจากแบบฟอร์มกระดาษโดย …`
- **ยังบังคับ:** ประเภท RD/OD/TD/IN เท่านั้น · โครงการและประตู active · เวลาจ่ายจริง (ไม่เกินอนาคต +5 นาที ไม่เก่ากว่า 60 วัน — `doc_ts` และวันที่ในเลขใบใช้วันจริง) · ผู้ขอต้องมีอยู่ · ผู้รับ (ยกเว้น IN) · TD: ไซต์ปลายทาง active ต่างจากต้นทาง + ผู้ติดต่อ · IC เท่านั้น + กฎ NAR · ≤ 30 บรรทัด · รูปต่อรายการ (RD/OD/TD) + รูปแบบฟอร์มกระดาษ (บังคับ) · ตรวจสต๊อกประตู — ขาด → `needForce` ติ๊ก `บันทึกตามแบบฟอร์ม` บันทึกได้แต่เขียน error log `Bypass <doc>: …` · log `bypass_create`
- ⚠ ข้อความใน lib/bypass.php ยังพูดถึง "ยอดปัดที่ 0" ทั้งที่ GP-42 ให้ติดลบได้แล้ว

### 13.2 โหมด 2 — Bypass ประตู เลือกเลขเอกสาร (`bypassGateOpen` / `bypassGateClose`, 2026-09-29)

สำหรับใบที่ออกแล้วแต่ตู้/ประตูเสีย

- **ข้ามเฉพาะ** การแตะบัตรและ `closeGate` ของตู้
- **Bypass เปิด:** ใบต้องเป็น Awaiting และ "live" ≤ 30 ใบ · ออก PK ใหม่ต่อประตู (SC ได้รอบของตัวเองเสมอ) · สถานะ Opened/Scanned โดย `card_id` NULL · IN ผูกประตูใหม่ได้
- **ยังต้องทำ:** ถ่ายรูปยืนยันตามปกติ (หยิบจริง ≤ ขอ รูปต่อรายการ) หรือกรอกผลนับ SC
- **Bypass ปิด:** เฉพาะรอบที่เปิดด้วย bypass (`รอบที่เปิดจากตู้ต้องปิดที่ตู้`) และไม่มีแถว Opened/Scanned ค้าง → Confirmed → Closed + `finalizeGateDoc`
- บันทึก: `gate_round_events` `bypass_open`/`bypass_close` (ผู้ถือบัตร = ADM), activity `gate_bypass_open`/`gate_bypass_close`, error log `Gate bypass open/close PickingID=…` · หน้าประวัติ/Error log/PDF แสดงป้าย bypass
- ⚠ รอบที่ตู้เปิดแล้วตู้ตายคาสถานะ Confirmed ปิดด้วย bypass ไม่ได้ และ `reconcile` ก็ไม่ finalize ใบประตูมีตู้ที่ค้าง Confirmed — ต้องให้ตู้นั้นส่ง closeGate เอง (ดู 21)

---

## 14. ตู้ประตู (Gate cabinet) และ API ของตู้

### 14.1 ฮาร์ดแวร์และโปรแกรมตู้ (`Pilot2/Hardware/connext_access.py`)

| ส่วน | รายละเอียด |
|---|---|
| คอนโทรลเลอร์ | Cytron IRIV PiControl (Raspberry Pi CM) จอสัมผัส 1024×600, Tk fullscreen · GPIO ผ่าน `RPi.GPIO` (BCM), Wiegand ผ่าน `lgpio` |
| หัวอ่าน | QR500 / QR50M (ZKTeco) — ข้อความ QR มาทาง RS485 `/dev/ttyACM0` 115200 (เว้น 0.12 s = จบเฟรม) · บัตรมาทาง Wiegand-26 → hex 3 ไบต์ (เช่น `497311`) · หัวอ่านสะท้อน QR ออก Wiegand ก่อน RS485 ~170 ms จึงถือ ID ไว้ 0.4 s แล้วทิ้งถ้ามีเฟรม RS485 · บัตรซ้ำใน 1.5 s ถูกเมิน · ข้อความตัวเลขล้วน ≥ 6 หลัก = แตะบัตร |
| กลอน | DO1 → รีเลย์ K1 → กลอนแม่เหล็กบนหน้าสัมผัส NC: GPIO24 LOW = จ่ายไฟกลอน = **ล็อก** · `lock_failsafe` ใน `gate_local.json` กลับขั้วสำหรับสาย NO (GP-34) |
| ไฟสัญญาณ | DO2 → K2 → tower light GPIO25 (HIGH = แดง+buzzer, LOW = เขียว) · DO3 → ไฟเหลือง GPIO26 · buzzer/LED บนบอร์ด GPIO19/20/21 |
| เซ็นเซอร์ | DI3 ← หน้าสัมผัสสถานะกลอน GPIO27 (0 = ล็อก; สายขาดอ่านเป็น "ล็อก" — ข้อจำกัดที่รู้) override `sensor_locked_level` (GP-28) · DI2 ← reed ประตู GPIO22 เมื่อ `door_sensor=true` ต้องตรงกับ DI3 ไม่งั้น "Door sensor mismatch" (GP-27/55) · DI0/DI1 ← Wiegand WG0/WG1 GPIO13/17 |
| กล้อง | Dahua IP RTSP sub-stream 704×576 · โปรเซสแยก `gate_guard.py` (MobileNet-SSD, conf 0.5, 3–5 fps, เข้า 0.4 s ≥ 2 hits, ออก 5 s, โซนจาก `cx_config/zone.json`) คุยผ่านไฟล์ JSON `gate_state.json` (ตู้→guard: armed, screen, door_open) / `guard_state.json` (guard→ตู้: present, evidence, camera) · หลักฐาน `logs/CCTV/<date>/<HHMMSS>.jpg` + `_full.jpg` (4MP) |
| ไฟล์ตั้งค่า | `gate_local.json`: `backend_url, api_key, site, gate, lock_failsafe, sensor_locked_level, door_sensor, door_closed_level, alarm4_kill_any_card, voice_please_close` · `gate_flags.json`: `hasCctv`, `qrRequireCode` ล่าสุดที่รับจากเว็บ |
| ไฟล์สถานะ | `round_cache.json` (fsync — รีสตาร์ทแล้วกลับเข้ารอบ), `alarm4_latch.json`, `pending_logs.jsonl` (≤ 500), `CardList.txt`, `hardware.log` |
| รัน/รีสตาร์ท | ปุ่มบน taskbar → `/home/pi/Code/gate_launch.sh`: หยุดโปรเซสที่จับ gpiochip/ttyACM, ตั้ง DO0–DO3 LOW, start `gate_guard.py` แล้ว `python3 -u connext_access.py` · แตะซ้ำขณะรันไม่ทำอะไร · crash → zenity dialog **ไม่มี watchdog/systemd** |

> ⚠ สำเนา `Hardware/connext_access.py` (version label "2026.10.02", BACKEND_URL 10.1.0.184) **ไม่ใช่ตัวที่ติดตั้งบนตู้** — ตัวบนตู้ (สำรองใน `Hardware/CX_Backup/connext_access_pilot2.py`, "2026.10.08", 5,405 บรรทัด, BACKEND_URL 10.1.0.173) ใช้โครงโฟลเดอร์ `/home/pi/Code`, `cx_config/`, `cx_state/`, `logs/` เพิ่ม VoiceLoop (เล่นเสียง "เปิดประตู" ขณะรอปิด) และซ่อมชื่อไทยใน QR · payload HTTP เหมือนกันทุกสำเนา · ค่า `PROGRAM_VERSION` ถูกตรึงไว้ heartbeat จึงบอกไม่ได้ว่าตู้รัน build ไหน · key กลางและรหัสผ่านกล้องถูก hardcode ในไฟล์ (ควรหมุน)

**หน้าจอของตู้ (Tk frames):** `selfcheck` "กำลังตรวจสอบระบบ" (Internet, CardList, Locked?, Scanner, Camera) → `scan_idle` "แสกน QR Code เพื่อเริ่มการเบิกจ่าย" → `scan_doc` (DocID ผู้เบิก ผู้รับ "แสกน QR Code ต่อไป หรือแตะบัตรเพื่อเปิด" ปุ่ม ยกเลิก) → `gate_open` "ประตูเปิด" (ตาราง DocID/Requester/Receiver/Cardholder/Status, "เวลาหยิบของเหลือ MM:SS", ปุ่ม "เพิ่มใบเบิก", "ขอเวลาเพิ่ม +N นาที") → `please_close` "กรุณาปิดประตู เพื่อตัดสต็อก เหลือเวลา 05:00" (ALARM 3: ปุ่ม "ยังขนของไม่เสร็จ — เลื่อนเวลา") → `locked` "LOCKED ตัดสต็อกสำเร็จ" (3 s) · `denied` "ไม่อนุญาต" · overlay: กล่องปิดโปรแกรม, กล่องปิด ALARM 4, แบนเนอร์เตือน · ข้อความตามชนิด (`KIND_TEXT`): PK, IN รับเข้าคลัง, RT คืนอุปกรณ์, SC นับสต๊อก, MO/MI ย้าย Gate

**ไฟสัญญาณ:** ALARM = แดง+buzzer กะพริบ 0.3 s · WARNING = เหลืองกะพริบ 0.5 s ขณะประตูปลดล็อก (0.15 s ใน 60 s สุดท้าย) · ERROR = เหลืองค้าง (self-check ล้ม, CardList ว่าง, อุปกรณ์เสีย) · ปกติ = เขียว

**ค่าคงที่เวลา:** poll 0.5 s · pick 3 นาที/รายการ (เว็บ override ตอนเริ่ม) cap 120 · extend 5 · เตือน 60 s สุดท้าย · หน้าต่างขยาย 60 s · ปิดประตู 300 s · รอแตะบัตร 60 s · retry close 1 s (ไม่จำกัด) · HTTP timeout 8 s · heartbeat 60 s · web-down 60 s / ต้องปิดใน 300 s · resume รอบเก่ากว่า 2 ชม. ต้องใช้บัตรสโตร์ · กล่องยืนยัน kill 60 s

**เมื่อเว็บล่ม:** เริ่มรอบใหม่ไม่ได้เลย ("ไม่สามารถเริ่มรอบได้ — เซิร์ฟเวอร์ไม่ตอบ") · CardList/flags ใช้ cache · logError เข้าคิว `pending_logs.jsonl` ส่ง 50 รายการต่อ heartbeat ที่สำเร็จ · ระหว่างรอบ poll ล้ม 60 s → โหมด web-down: log "Web unreachable during round", ต้องปิดประตูใน 5 นาที (ไม่งั้น ALARM 3), ปิดแล้ว **จ่ายไฟกลอนแม้ยังยืนยันไม่ครบ** (ข้อยกเว้นเดียวของกติกา 6), เว็บกลับมาต้องแตะบัตรของรอบหรือบัตรสโตร์ถึงปลดล็อกใหม่ · `closeGate` retry ทุก 1 s จนกว่าจะถึง

### 14.2 สัญญาณเตือน (ALARM)

| ALARM | เหตุ | หยุดเมื่อ |
|---|---|---|
| **1** `tamper` | DI3 (และ DI2) ไม่ล็อกขณะ selfcheck/scan_idle/scan_doc/locked/denied · เมินทุกการสแกน/แตะ · แบนเนอร์ "ประตูถูกเปิดโดยไม่ได้สแกน — กรุณาปิดประตูให้สนิท สัญญาณจะหยุดเมื่อประตูปิด" | ประตูปิด |
| **2** `early_close` | ปิดประตูก่อนยืนยันครบ · กลอนไม่จ่ายไฟ เวลาหยิบหยุด ปุ่มปิด · log "Gate closed before all Confirmed PickingID=… pending=…" ครั้งเดียว/รอบ | เปิดประตูใหม่ (ถ้ายืนยันใบสุดท้ายขณะปิด → ล็อกทันทีจบรอบ) |
| `pick_timeout` | หมดเวลาหยิบ · log "Pick time expired PickingID=… items=… allotted=… extends=… pending=…" | "ขอเวลาเพิ่ม" + บัตร (กดได้ใน 60 s สุดท้ายหรือหลังหมด) หรือยืนยันครบ |
| **3** `close_timeout` | ไม่ปิดใน 5 นาทีหลังยืนยันครบ · ขยายแต่ละครั้ง log "Close time extended +5 min PickingID=… #n [OVER CAP (store card)]" · ปิดช้า log "Cardholder did not close the door within the time limit … overrun=MM:SS" | ประตูปิด หรือ "เลื่อนเวลา" + บัตร |
| **4** `intruder` | guard เห็นคนในโซนขณะไม่อยู่หน้าจอเปิดรอบ · **LATCH** ใน `alarm4_latch.json` (รอดรีสตาร์ท/ไฟดับ) ปฏิเสธทุกการสแกน บัตร และปุ่มออก · log "Person detected inside the zone without a scan (door locked, screen=…) evidence=<path บน Pi> LATCHED until a store card kills it" | แตะ **บัตรสายสโตร์** + กล่อง "ปิดสัญญาณ ALARM 4 ใช่ไหม?" (แสดงรูปหลักฐาน ผู้ยืนยัน เวลาที่เริ่ม) ใส่เหตุผล ≥ 3 ตัว (ตัวเลือก: ตรวจแล้วไม่มีผู้บุกรุก / เจ้าหน้าที่ทำงานในพื้นที่ / ทดสอบระบบ) กด "ยืนยันปิดสัญญาณ" (หมดอายุ 60 s) → log "ALARM 4 killed by store card X after … evidence=… \| reason=…" (retry 6 ครั้ง) · ปฏิเสธ → "ALARM 4 kill refused" · ทางหนี: ลบไฟล์ latch ผ่าน SSH · ประตู `has_cctv=0` ไม่มี ALARM 4 |
| A `denied` | บัตรไม่รู้จักหลังโหลด CardList ใหม่ · log "Unauthorized card X (not in CardList after re-pull)" | 3 s |

**บัตรสายสโตร์** (CardList `Store` = `can_req` และ level ≥ 1) จำเป็นสำหรับ: ปิดโปรแกรม (ปุ่ม/Esc/Alt+F4 → log "Program closed by store card …") · ปิด ALARM 4 (เว้นแต่ `alarm4_kill_any_card`) · ขยายเวลาหยิบ/ปิดประตูเกินเพดาน (เมื่อ `storeOverCap` เปิด) · resume รอบที่เก่ากว่า 2 ชม. · ใบ TG ทั้งสองขา (ตรวจฝั่งเซิร์ฟเวอร์ "ใบย้าย Gate ต้องแตะบัตรสายสโตร์")

### 14.3 สัญญาการเชื่อมต่อ `api/gate.php`

**การยืนยันตัวตน** (`key` ใน query string สำหรับ GET / ใน JSON body สำหรับ POST) ตรวจตามลำดับ:
1. **Key รายตู้ (GP-22):** key ≥ 24 ตัว → SHA-256 เทียบ `gates.api_key_hash` ของประตู active → ตรงแล้ว `gateSecBindRequest` **เขียนทับ** `SiteCode/siteCode/site` และ `GateID/gateId/gateID` ด้วยไซต์/ประตูของ key
2. **Job token:** `GET action=reconcile&jobtoken=` HMAC-SHA256('jobs|YmdH', qrSecret) รับชั่วโมงปัจจุบันและก่อนหน้า
3. **Key กลาง:** `settings/config.php` `gate_api_key` (`hash_equals`) รับเฉพาะเมื่อ `app_settings.gate_shared_key_ok=1` (ปริยาย 1) · ใช้ key กลาง POST `submitPickingList/addToPicking/closeGate/logRoundEvent/heartbeat` ต้องมี `GateID` (3 ตัวแรกต้องมี `SiteCode` ด้วย) ไม่งั้น "ต้องระบุรหัสประตู (GateID) และรหัสไซต์ (SiteCode) — ตู้ที่ยังใช้ key กลางต้องส่งทุกคำสั่ง"
- ไม่ผ่าน → 403 `{"success":false,"message":"Unauthorized"}` · `gate_api_key` ว่างและไม่มี key รายตู้ → endpoint เปิด (compat) · ไม่มี rate limit/replay protection · HTTP 405 ถ้าไม่ใช่ GET/POST · ข้อผิดพลาดอื่นเป็น 200 + `success:false`
- สถานะปัจจุบัน: G01/G02 มี key รายตู้ · G03 และ HO G01 ไม่มี · key กลางยังเปิดรับ

**GET**

| action | คำขอ | คำตอบ |
|---|---|---|
| `getCardList` | `siteCode`, `gateId` | `cardList {CardID: Fullname}` (CardID พิมพ์ใหญ่ ไม่มีช่องว่าง) · `data[] {CardID, Fullname, Role, Store}` · `cardRoles` · `storeCards[]` · `timing`/`settings {pickMinPerItem, pickCapMin, extendMin, storeOverCap}` · `gate {code, hasCctv, hardwareClose, ownKey}` · `qrRequireCode` · `keyBound` · ⚠ `siteCode` ว่าง → บัตรทุกไซต์ |
| `getGateSettings` (ตู้ไม่เรียก) | `siteCode` | `timing`, `settings`, `custom`, `serverTime`, `gate`, `qrRequireCode`, `keyBound` |
| `checkPickingStatus` | `pickingId` (บังคับ), `gateId` | `allConfirmed`, `total`, `confirmed`, `open`, `closed`, `icCount`, `pickLimitMin`, `timing`, `docs[] {docId, status, type, isReturn, cardId, cardholder, confirmed}` |
| `reconcile` | job token หรือ key | finalize ใบที่ gate log ถึงสถานะปลายทางแต่ใบยังไม่ปิด → `{finalized, flagged, errors}` |
| `?docID=` (เก่า) | | ข้อความสถานะ / "Not Found" |

**POST**

| action | body | พฤติกรรม / คำตอบ |
|---|---|---|
| `heartbeat` | `SiteCode, GateID, version, screen, pickingId, devices {camera, detector, reader, lock}, alarms[], uptime` | เขียน `gate_status` → `{success, serverTime, qrRequireCode, gate, keyBound}` แล้ว **ปิดการเชื่อมต่อและรันงานอัตโนมัติ** (บทที่ 17) · ประตูไม่รู้จัก → "ไม่พบประตู Gxx ของไซต์ …" |
| `submitPickingList` (`submitPicking`) | `SiteCode, GateID, CardID, PickingList[]` (หรือ `pickingList`/`docIds`), `Codes {DOC: CHK}` | ลบซ้ำ → SC ที่ปนใบอื่นถูกข้าม → `gateSecFilterDocs` (ไซต์ + รหัส QR) → transaction: ออก `PK…` + `gateAcceptDocs` (ดู 14.5) · ไม่รับสักใบ → rollback `pickingId=""` "ไม่มีใบที่รอสแกนของประตูนี้ — ประตูไม่เปิด" · คืน `pickingId, updated, notFound[], skipped[] ("DOC (เหตุ)"), wrongGate[], unmatched[], rejected[] {docId, reason, kind}, icCount, itemCount, pickLimitMin, timing, docs[]` · ตู้เปิดประตูเมื่อ `pickingId` ไม่ว่างและ `updated > 0` |
| `addToPicking` (`addPicking`, `addPickingList`) | + `PickingID` | ปฏิเสธเมื่อไม่มี pickingId, รอบเป็น SC หรือเพิ่ม SC, รอบ missing/closed/complete ("ไม่พบรอบ…" / "… ปิดประตูไปแล้ว — เริ่มรอบใหม่" / "… ยืนยันครบแล้ว (รอปิดประตู) …") · คืน `success, pickingId, roundState, updated, added[], …` · ตู้ตรวจว่า `pickingId` ตรงของตน (ไม่งั้น log `foreignPickingId`) |
| `closeGate` (`close`) | `pickingId` (บังคับ), `gateId`, สถิติ `usedSec, icCount, pickExtends, closeExtends, overrunSec, cardId, cardholder` | เฉพาะแถว Confirmed ของรอบ → Closed + `finalizeGateDoc` ต่อใบ · แถว Opened/Scanned → `notConfirmed` + error log "closeGate PickingID=… ขณะยังมีใบไม่ได้ถ่ายรูปยืนยัน …" · มีสถิติ → `gate_round_events` `round_end` · คืน `{closedDocs[], notConfirmed[], docs[]}` · ตู้ไม่ส่ง cardId/cardholder |
| `logRoundEvent` (`roundEvent`, `logTimer`) | `siteCode, gateId, pickingId, event, itemCount, seq, cardId, cardholder, totalMin, addMin, overCap, message` | เขียน `gate_round_events` (+ error log เว้น round_end ที่ไม่ over cap) · **ตู้ไม่เคยเรียก** |
| `logError` (`error`) | `siteCode, gateId, message` (+ `cardholder` ถูกละเลย — ตู้ต่อท้ายใน message เองแล้ว) | 1 แถว `error_logs` |
| เก่า `{docID, status}` | | ห้ามถอยจากแตะแล้วกลับ Awaiting · Confirmed เฉพาะจากหน้ารูป · Closed เฉพาะจาก Confirmed · IN ไม่มีประตูเปิดทางนี้ไม่ได้ |

ตู้ไม่อัปโหลดรูป (มือถืออัปโหลดผ่าน RPC `saveConfirmationData`) · `serverTime` ถูกส่งแต่ตู้ไม่ใช้ (ไม่มี clock sync)

### 14.4 รูปแบบ QR และการลงนาม (`lib/qr_sign.php`, `js/qr-sign.js`)

- สร้างในเบราว์เซอร์: `index.php showQRModal` → `{"Doc","Req","Receiver"}` · `qr-sign.js` ครอบแล้วเรียก `getQrPayload(scanId)` วาดใหม่เป็น **`{"Doc":…,"Site":…,"Chk":…,"Req":…,"Receiver":…}`** — Site/Chk อยู่หลัง Doc เพราะหัวอ่านตัด QR ยาวเป็น 2 เฟรม RS485 (แก้ 10-02 หลังตู้ขึ้น "QR ไม่มีรหัสตรวจสอบ") · วาดด้วย `qrcode(0,'M')` 220px · ลงนามไม่ได้ → แสดง QR เดิม + เตือน "QR ยังไม่มีรหัสตรวจสอบ … — ตู้ที่บังคับรหัสแล้วจะไม่รับ"
- `Receiver`: PK = ชื่อผู้รับ · IN = RS/PO · RT = SubID/ชื่อ
- **`Chk` = `qrCheckCode(site, scanId)`** = 12 hex ตัวแรกพิมพ์ใหญ่ของ HMAC-SHA256(secret, `"v2|"+UPPER(site)+"|"+UPPER(scanId)`) · secret ใน `settings/qr_secret.php` (32 ไบต์สุ่ม สร้างอัตโนมัติ — **เปลี่ยน/หายแล้ว QR ที่เปิดค้างทุกใบใช้ไม่ได้ และยังเป็น key ของ job token**) · `scanId` = ข้อความ Doc ทั้งหมด (`…G01`, `…G01RT`, `TG…G01G03`, `SC…`)
- `qr_require_code` (app setting ปริยาย 0 — **บนเครื่องนี้เปิดเป็น 1 ตั้งแต่ 2026-10-02 15:36**): ตู้ปฏิเสธ QR ที่ Site ไม่ตรง ("QR นี้เป็นของไซต์ X — ใช้ที่ตู้ไซต์ Y ไม่ได้") หรือไม่มี Chk ("QR ไม่มีรหัสตรวจสอบ — เปิด QR ใหม่จากหน้า "QR เอกสาร"") · เว็บ (`gateSecFilterDocs`) ปฏิเสธ kind `site` / `qr_code` ("รหัสตรวจสอบ QR ไม่ถูกต้อง (QR ปลอมหรือถูกแก้) …") + error log "QR rejected: …"
- กู้ QR ที่อ่านไม่ครบ: parse JSON ไม่ได้ → ดึง `Doc`/`Site`/`Chk` ด้วย regex เฉพาะฟิลด์ที่ปิด quote ครบ · ข้อความธรรมดาที่ตรง `^[A-Za-z0-9][A-Za-z0-9_\-./]{0,31}$` = DocID ไม่มี Chk · อื่น ๆ 3 beep "QR ไม่สมบูรณ์ — กรุณาสแกนใหม่อีกครั้ง"
- ตรวจประตูที่ตู้ (`_gate_check`): Gxx ท้ายเลขต้องเท่า `GATE_ID` (ตัด RT; ขาเข้า TG ใช้ G ตัวท้าย) · IN ผ่านทุกประตู · ไม่มี G → "ไม่ใช่ใบของประตูนี้ (ไม่มีรหัส G ท้ายเลขใบ)"

### 14.5 ใบที่ตู้รับ (`gateAcceptDocs` / `gateDocIsLive`)

| เอกสาร | รับเมื่อสถานะ |
|---|---|
| RD / OD / TD / SC | `Approved` |
| BD | `Sent Borrow` · ขา `…RT`: `Sent Return` |
| IN | `Sent Inbound` |
| TG | `Approved` (ขาออก) / `In Transit` (ขาเข้า) + ต้องบัตรสโตร์ |

gate log ต้องเป็น Awaiting · ประตูต้องตรง (`gate_strict_match` ปริยายเปิด) · RT ต้องคืนที่ประตูเดิม · IN สแกนได้ทุกประตู active ของไซต์ (ผูกใหม่ `in_gate_rebind`; ประตูไม่รู้จัก/ไซต์อื่น/ว่าง → ปฏิเสธ) · แถวที่รับได้ `picking_id, card_id, scanned_at` + Opened (ตู้) / Scanned (scan-flow)

### 14.6 ความปลอดภัยและ log ประตู (`lib/gate_sec.php`)

- ประเภทประตู มี/ไม่มี CCTV (checkbox "มีตัวล็อกจริง" เดิมถูกถอด) · key รายตู้ออก/เพิกถอนใน `admin.php?t=gates` (แสดงครั้งเดียว 40 hex เก็บเฉพาะ hash + 4 ตัวท้าย) · switch "บังคับรหัสตรวจสอบ QR" และ "ยังรับ key กลาง" · ทุกการเปลี่ยนลง `activity_log` และแจ้งผู้ใช้ระดับ 0
- ลำดับเปิดใช้ (README 10-02): เว็บ → copy โปรแกรมตู้ทุกตู้ → ออก key รายตู้ใส่ `gate_local.json` → ทุกตู้อัปเดตแล้วค่อยบังคับ QR → ทุกตู้มี key แล้วค่อยปิด key กลาง
- ⚠ key รายตู้ override ไซต์/ประตูฝั่งเว็บ แต่ตู้ยังใช้ `SITE_CODE/GATE_ID` ของตัวเองตรวจเอกสาร/QR/cache — ถ้า `gate_local.json` ไม่ตรง key สองฝั่งจะเห็นต่างกัน
- log: `gate_logs` (Awaiting→Opened/Scanned→Confirmed→Closed) · `gate_round_events` · `activity_log` (`gate_add_docs`, `in_gate_rebind`, `gate_confirm`, `pick_actual`, `return_actual`, `zero_pick`, `gate_bypass_*`) · `error_logs` · ตู้ไม่รายงาน "เปิดประตูจริง" — การเปิดอนุมานจาก submit ที่สำเร็จ

### 14.7 สุขภาพตู้และการเฝ้าดู (`lib/gate_health.php`, `js/gate-health.js`, `lib/site_errors.php`)

- ตู้ส่ง heartbeat ทุก 60 s (ครั้งแรก 5 s หลังเริ่ม) `camera/detector` จากความสดของ guard (`off` ถ้าไม่มี CCTV) `reader` = ok ถ้าพอร์ต serial เปิด · หลังสำเร็จส่งคิว log ที่ค้าง
- เว็บ upsert `gate_status` ล้าง `offline_since` · อุปกรณ์เสียใหม่ → error log "Device check failed: …" + แจ้ง "ตู้ Gxx (SITE): กล้อง · … ใช้ไม่ได้" · หาย → "Device check recovered" · กลับมาหลังหาย → "Gate back online: Gxx — offline since …"
- `ghCheckOffline` (รันกับทุกงานอัตโนมัติ): ออฟไลน์เมื่อไม่มี heartbeat **5 นาที** (`GH_OFFLINE_MIN`) เฉพาะประตู active `hardware_close=1` ที่เคยส่งอย่างน้อย 1 ครั้ง → `offline_since` + error log "Gate offline: Gxx — no heartbeat since … (โปรแกรมตู้ถูกปิด HH:MM โดย …)" + แจ้งครั้งเดียวต่อเหตุการณ์
- การ์ด "สถานะตู้" บน Dashboard (R0, R8+, สโตร์; ไม่ใช่ R0 เห็นไซต์ตัวเอง) รีเฟรช 60 s: online / "ออฟไลน์" / "ยังไม่เคยส่งสัญญาณ" + อุปกรณ์เสีย, "ไม่มี CCTV", รอบปัจจุบัน, เวอร์ชัน, เหตุผล
- **Error log ไซต์** (`rpc_getSiteErrorLog(siteCode, days)` ปริยาย 90 วัน ≤ 3,000 แถว; R0/≥8 ทุกไซต์, สโตร์ไซต์ตัวเอง): จัดหมวดด้วย regex ตามลำดับ test, card, early_close, not_locked, unknown_doc, network, selfcheck, stock, gate_offline, device, program_closed, qr_reject, over_cap, pick_time, close_late, intruder, alarm_kill, wrong_gate, skipped_doc, late_confirm, borrow_overdue, gate_bypass, zero_pick, other · ดึง card, PickingID, cardholder, material, borrower, doc, `by=` · กราฟรายวัน + CSV (เวลา, ไซต์, ประตู, ประเภท, ข้อความ, บัตร, ชุดหยิบ, ผู้ถือบัตร, วัสดุ) · badge 7 วัน
- ⚠ ข้อความของตู้บางแบบไม่เข้าหมวด: "Close time extended" (regex คาด "Close-door time extended") · "OVER CAP" (regex `OVER-CAP` → ตกเป็น pick_time) · "Web unreachable…", "Door sensor mismatch", "Unreadable QR JSON", "CardList empty…" → อื่น ๆ

---

## 15. งานผู้รับเหมา — ตรวจสอบประจำวัน · ใบหักเงิน · สแกนนิ้ว · หักคจช. · ลงนามอิเล็กทรอนิกส์

> สถานะข้อมูลจริง (2026-10-08): `deduction_docs` 19 ใบ (นำเข้าจาก GAS ทั้งหมด, `days_label` 'ทั้งเดือน') · `rate_cards` 30 · `daily_check_confirms` 13 · `finger_scan_logs` / `sub_expense_rows` / `sign_requests` / `deduction_doc_rates` = 0 · ยังไม่มีไฟล์ลายเซ็นเลย — โมดูลสแกนนิ้ว/หักคจช./e-sign พอร์ตแล้วแต่ **ยังไม่เคยใช้กับข้อมูลจริง**

### 15.1 ตรวจสอบประจำวัน (`lib/dailycheck.php`)

- สิทธิ์ `roles.can_daily_check` (re-read ทุกครั้ง, บัญชี staff เท่านั้น, R0 เลือกไซต์ได้)
- `getDailyCheckData(site)`: ทุกบรรทัด RD/OD ที่ใบ Completed กลุ่มตามวันของ `doc_ts` — type, docId, matCode, name, subName (ผู้รับ), subgroup, `qty` = หยิบจริง (ถ้าต่างจากขอแสดง `qtyReq`, `actualReason`), `bypass` · วันยังไม่ตรวจก่อน แล้ววันใหม่ก่อน · หัวเดือนมีปุ่ม "ออกเอกสารหักเงิน" ถ้ามีรายการติ๊กหักเงิน
- ติ๊ก "หักเงิน" (`setDailyCheckCharge`) → `document_items.charge_money` · ยืนยันวัน (`confirmDailyCheck`) → `daily_check_confirms`
- แท็บ: ตรวจสอบประจำวัน · Dashboard สรุป (`getChargeDashboard`: ยอดรวม หัก/ไม่หัก แยกประเภท top 15 ชุด/หมวด/วัสดุ รายวัน) · ตั้งราคาหักเงิน · นับสต๊อก (add-on)
- **ราคาหักเงิน (rate card):** `rate_cards(project, material)` `unit_price` NULL = ยังไม่ตั้ง (≠ 0) · รายการ = วัสดุที่เคยหักเงินที่ไซต์ ∪ ที่ตั้งราคาแล้ว · บันทึกแบบ batch upsert ค่าว่าง/ติดลบ = NULL · ⚠ ไม่กั้นไซต์ของผู้เรียก (UI อาจกั้น)
- vendor ที่ใช้เบิกต่อชุด (`getSubMangoVendorData`/`saveSubMangoVendor`) → `sub_projects.mango_vendor_*` ตัวเลือกจาก `sub_mango_map` (ถ้าไม่มีเสนอ `mango_vendors` ทั้งหมด)

### 15.2 ใบหักเงินผู้รับเหมาชุด (`deduction_docs`, `lib/signatures.php`, `lib/pdf_api.php`)

- งวด: `งวด 1-15` / `งวด 16-สิ้นเดือน` (`_sigHalfLabel`) หรือ `ทั้งเดือน` / รายการวัน
- **เลขที่ `SUB-{SITE}-{YYYYMM ค.ศ.}-{NN}`** เช่น SUB-ARI-202610-01 — เลขรันต่อ (project, ym) ใช้ร่วมทั้งสองงวด ออกใน transaction `FOR UPDATE` · unique `doc_no` และ (project_id, ym, running_no)
- `issueDeductionDocs({siteCode, ym, half})` "ออกเลขเอกสาร": 1 ใบต่อชุดที่มีรายการหักเงินในงวดนั้น เรียงชื่อชุด ข้ามชุดที่มีใบของ label นั้นแล้ว · รายการ = `charge_money=1`, จำนวนมีผล > 0, RD/OD, Completed, `doc_ts` ในเดือน/งวด, กลุ่มตาม `receiver_name` (ว่าง/'-' ข้าม)
- **ราคาแช่:** ออกใบครั้งแรก copy ราคาลง `deduction_doc_rates` (INSERT IGNORE — ครั้งแรกชนะ) รายการที่ยังไม่ตั้งราคาไม่ถูกแช่ ใช้ราคาสดตอนพิมพ์ซ้ำ · `freezeDeductionRatesNow` แช่ย้อนหลังใบเก่า · ลำดับราคา: แช่ → rate card สด → ว่าง · ไม่คิด VAT
- PDF (`generateDeductionPDF`, template `deduction.php`, A4 แนวนอน 1 ชุด/หน้า, 14 คอลัมน์: ลำดับ ว/ด/ป เลขที่ รหัสวัสดุ หมวดวัสดุ รายการ หน่วย ปริมาณ ราคา/หน่วย จำนวนเงิน ชื่อผู้เบิก (= ชื่อเต็มผู้อนุมัติ) ชื่อผู้รับ แผนก หมายเหตุ · "รวมเงินหักทั้งสิ้น" · ช่องเซ็น 4: ผู้สรุปเอกสาร (ลายเซ็นบัญชีฝังอัตโนมัติ) · ผู้รับเหมารับทราบ (ลิงก์ token) · ผู้ตรวจสอบ (PE / SSE) · ผู้อนุมัติ (PM)) · ⚠ พิมพ์โหมดปกติ (ตามชุด/งวด) จะออกเลขและแช่ราคาด้วย (idempotent) · UI ส่ง `confirmOverlap=true` เสมอ
- สิทธิ์ออกเลข/ขอลายเซ็น/สร้างลิงก์/แช่ราคา/พิมพ์: CanDailyCheck

### 15.3 บันทึกสแกนนิ้ว (`lib/fingerscan.php`)

- แท็บ: บันทึกรายวัน (SC) · สรุปงวด · ชี้แจงแสกนเกิน · แดชบอร์ด (BS) · R0 ทำได้ทั้งหมด
- 1 แถวต่อ (วัน, ชุด) ใน `finger_scan_logs` ครอบคลุมชุดที่ active ในไซต์: ลงงาน (`worker_count`), แสกน (`scan_count`), ไม่แจ้งยอด (`no_report`), แรงงานในระบบ (`system_worker_count`), ค่าปรับ, หมายเหตุ
- **อัตราค่าปรับ** `FS_DEFAULT_RATE = 100` บาท/คน/วัน · override ต่อไซต์ `finger_scan_config` key `rate` (BS/R0)
- **สูตรค่าปรับ (คำนวณฝั่งเซิร์ฟเวอร์เท่านั้น):** ไม่แจ้งยอด → `max(0, แรงงานในระบบ) × rate` · ปกติ → `max(0, ลงงาน − แสกน) × rate` · แถวไม่ครบ (กรอกอย่างเดียว) → ค่าปรับ NULL และ BS เซ็นวันนั้นไม่ได้ · ⚠ ท้ายหน้า sign.php บอกว่า NoReport คิดจาก "จำนวนที่สแกน" แต่โค้ดใช้แรงงานในระบบ
- บันทึก (`saveFingerScanDay`): แทนทั้งวัน (คงแถวของชุดที่ inactive ที่ไม่ได้ส่งมา) · ห้ามวันอนาคต · แถวว่างไม่บันทึก · คำชี้แจงแสกนเกินยกมา · ทุกการบันทึกล้างการตรวจของ BS และลบไฟล์ลายเซ็นวันนั้น
- prefill: ลงงานเติมพื้นเหลืองจากวันก่อนหน้าล่าสุดที่ `no_report=0` · client ไม่บันทึกแถว prefill จนกว่าจะกรอกแสกน · ปุ่ม "ใช้ค่าเริ่มต้นทั้งหมด"
- BS ตรวจรายวัน (`verifyFingerScanDay`): ต้องมีแถว ไม่มีแถวไม่ครบ และ BS มีลายเซ็น → copy ลายเซ็นเป็น `uploads/signatures/fsv_{pid}_{yyyymmdd}_*` + `finger_scan_verify`
- งวด H1 = 1–15, H2 = 16–สิ้นเดือน label 'วันที่ 1-15 ต.ค.69' · badge: `notRecordedDays`, `unverifiedDays`, `pendingVerifyDays`, `alertPending` (แสกน > ลงงาน ไม่มีคำชี้แจง)
- สรุปงวด (`_fsSummarize`) ต่อชุด: วัน, วันไม่แจ้งยอด, แรงงาน, แสกน, ขาด, ค่าปรับ, อัตรา = แสกน ÷ แรงงาน เรียงค่าปรับมากก่อน
- PDF `generateFingerScanPDF` (template `fingerscan_summary.php` A4 แนวตั้ง): ลำดับที่ · ชุดผู้รับเหมา · ค่าปรับไม่แสกนนิ้ว(บาท) · อัตราการแสกนนิ้ว/งวด(%) · ผู้รับเหมาลงนามรับทราบ · หมายเหตุ ("ไม่แจ้งยอด N วัน") · ช่อง "ผู้จัดทำ (ผู้ดูแลผู้รับเหมา)" + ลายเซ็นผู้สร้าง
- ลงนามรับทราบของผู้รับเหมา: เลข `FS-{SITE}-{YYYYMM}-G{half}-{subId}` role contractor สร้างด้วย `createFingerScanLink` / `createAllFingerScanLinks` (⚠ ตัวหลังสร้างให้ทุกชุด ไม่ใช่เฉพาะที่มีค่าปรับ) กำหนด 0–60 วัน · `setFingerScanSent` เริ่มนับเวลา · บน PDF: เซ็นจริง (รูป + "( ชื่อ )" + "ลงนาม d MMM yy · HH:mm น.") / รับทราบโดยปริยาย (ลายเซ็นชุดที่เก็บไว้ถ้ามี) / "รอลงนาม (ส่งแล้ว)" / "รอลงนาม"
- ⚠ **น่าจะใช้ไม่ได้:** `getSignPageData`/`submitContractorSignature` ค้นเอกสารใน `deduction_docs` เท่านั้น เลข FS- ไม่เคยถูกเก็บที่นั่น → ผู้รับเหมาเปิดลิงก์ FS จะได้ "ไม่พบเอกสารของลิงก์นี้" ทำได้แค่รับทราบโดยปริยาย

### 15.4 หักค่าใช้จ่ายผู้รับเหมา คจช. (`lib/subexpense.php`)

- สิทธิ์: SC หรือ R0 (ข้อมูล ขอลายเซ็น PDF) · BS หรือ R0 ตั้งค่าไซต์
- 1 แถวต่อ (ไซต์, งวดครึ่งเดือน `period_key` 'YYYY-MM/H1|H2', ชุด) ใน `sub_expense_rows` · บันทึกแทนทั้งงวด แถวว่างทิ้ง
- **ค่าตั้งต้นต่อไซต์ (`_seDefaults`):** `roomRate` 160 บาท/งวด · `elecRate` 6 บาท/หน่วย · `elecFreeUnits` 30 หน่วย/งวด · `shopElecRate` 7 บาท/หน่วย · `shopItems`: ร้านค้า 1000, เครื่องซักผ้า 500, ตู้กดน้ำ 300 → `sub_expense_config` (kind rate/shopItem) บันทึกแล้วไม่ fallback ค่าตั้งต้นอีก
- **22 คอลัมน์ของ PDF:** ลำดับ · ผู้รับเหมาชุด · ชื่อใช้เบิก Payment · ห้องพัก (จำนวน / ราคา/งวด / จำนวนเงิน) · ค่ามิเตอร์ไฟส่วนที่ใช้เกิน N หน่วย (จำนวนที่ใช้ / จำนวนที่เกิน / จำนวนเงิน) · ร้านค้าฯ (จำนวน / ราคา/งวด / จำนวนเงิน) · มิเตอร์ไฟร้านค้า (ครั้งก่อน / ปัจจุบัน / รวมหน่วย / จำนวนเงิน) · วัสดุฯ · Advance · หักผิดกฏระเบียบความปลอดภัย · หักผิดกฏระเบียบ ไม่สแกนนิ้ว/หน้า · รวม · หมายเหตุ + แถว "รวมทั้งสิ้น"
- คำนวณ client: roomAmt = qty × roomRate · elecOver = max(0, used − free) · elecAmt = over × elecRate · หน่วยร้าน = ปัจจุบัน − ครั้งก่อน × shopElecRate · shopAmt = ผลรวมรายการที่ติ๊ก · server: total = 8 ช่องเงิน ปัด 2 ตำแหน่ง
- **เซิร์ฟเวอร์เป็นเจ้าของ (มติจุดที่ 14):** `material_amt` = Σ ราคา × จำนวนของรายการหักเงินของชุดในงวด (ราคาแช่จากใบหักเงินเดือนนั้นก่อน → rate card) · `face_scan_fine` = ค่าปรับสแกนนิ้วงวดนั้น · ค่าที่ client ส่งสองช่องนี้ถูกทิ้ง ปุ่ม "ดึงค่าวัสดุ" แค่เติมหน้าจอ
- ยกยอดจากงวดก่อน: มิเตอร์ร้านค้าครั้งก่อน ← ปัจจุบันงวดก่อน · จำนวนห้อง (ข้ามถ้างวดก่อน "พักข้างนอก") · รายการร้านที่ติ๊ก
- **พักข้างนอก:** ล้างและล็อกห้องพัก/ค่าไฟ · PDF ช่องหมายเหตุขึ้นต้น "พักข้างนอก"
- ลายเซ็น: เลข `SE-{SITE}-{YYYYMM}-G{half}` role inspector/approver (ผู้ใช้ระบบ) · PDF: ผู้จัดทำ (+ลายเซ็นผู้สร้าง) [+ ผู้ตรวจสอบ] + ผู้อนุมัติ/ผู้ตรวจสอบ/ผู้รับทราบ ฝังรูปเฉพาะสถานะ signed
- PDF (`generateSubExpensePDF`, A4 แนวนอน) ชื่อ "รายการหักคจช. ผรม งวด1-15 ต.ค.69_ARI.pdf" · **พิมพ์รวม** (checkbox "พิมพ์รวมเอกสารแนบของงวดนี้" / `includeDeduction`) = 1 ไฟล์: หักคจช. → สรุปงวดสแกนนิ้ว → ตารางหักเงิน ผรม (ทุกหน้าแนวนอน) และออกเลข SUB- ของงวดไปด้วย

### 15.5 ลงนามอิเล็กทรอนิกส์ (`lib/signatures.php`, `sign.php`, `sign_requests`)

- `sign_requests` unique (`doc_no`, `role`) และ `token` · role: inspector / approver (ผู้ใช้ระบบ เซ็นใน "งานเซ็นของฉัน" `getMySignTasks` → `submitSignature`) · contractor (ไม่ login ผ่าน `sign.php?token=`)
- ตระกูลเลข: SUB-… (contractor, inspector, approver) · FS-… (contractor) · SE-… (inspector, approver)
- `requestSignature({docNos[], role, assignee, assigneePos})` (CanDailyCheck) ผู้รับต้องมีและไม่ inactive · ข้ามใบที่มี pending/signed/auto, เปิดแถว cancelled ใหม่ · `requestSubExpenseSignature` (SC/R0)
- **ลิงก์ผู้รับเหมา:** `createContractorLink({docNo, deadlineDays 0–60})` token 40 hex → `APP_BASE/sign.php?token=…` (หน้าเดียวจาก GAS SignPage.html โหลดเฉพาะ gas-shim; CSRF ยังต้องมี) · ผู้รับเหมาวาดลายเซ็นหรือใช้ลายเซ็นชุดที่เก็บไว้ (`useBound`) · ชื่อผู้เซ็น = ชื่อชุด ตำแหน่ง "ผู้รับเหมา"
- สถานะ `pending` → `signed` | `auto` | `cancelled` · ยกเลิกตั้ง `token=NULL` · **auto (รับทราบโดยปริยาย)** เมื่อยัง pending, มี `sent_at`, `deadline_days > 0` และเลยกำหนด — ใช้แบบขี้เกียจตอนอ่าน (`_sigApplyAutoAck`, หมายเหตุ "รับทราบโดยปริยาย — ไม่ตอบกลับภายใน N วัน (ครบกำหนด …)") ไม่ใช่งานตั้งเวลา · ไม่กด "ส่งลิงก์แล้ว" จะไม่ auto
- ไฟล์ลายเซ็น: png/jpeg data URL ≤ 45,000 ตัว → `uploads/signatures/{prefix}_{16hex}` prefix `user_{id}`, `sign_{doc}_{role}`, `sub_{doc}`, `fsv_…`, `sub_{subCode}_…` · ตอนเซ็น copy รูป ลายเซ็นบัญชีที่เปลี่ยนทีหลังไม่กระทบใบที่เซ็นแล้ว
- ⚠ client/server ไม่ตรงกัน (จากการอ่านโค้ด ยังไม่ทดสอบ): `getSignatureView`/`getFingerScanVerifyView` คืน `dataUrl` แต่ index.php อ่าน `signatureData` · `getSignDocDetail` คืน object แบน แต่ index.php อ่าน `res.detail` และ SE- หา `noVat`/`seRows` ที่เซิร์ฟเวอร์ไม่ส่ง (และ SE- ไม่อยู่ใน deduction_docs) · sign.php อ่าน `signatureData` (เซิร์ฟเวอร์ส่ง `signedData`) และ `deadlineAt` ผิดระดับ · แบนเนอร์ auto บอกว่ายังเซ็นจริงได้ แต่เซิร์ฟเวอร์ปฏิเสธแถวที่ไม่ pending

---

## 16. รายงาน PDF · ประวัติ · Excel/CSV · Dashboard insights

### 16.1 เครื่องยนต์ PDF (`lib/pdf_engine.php`, `lib/pdf_thai.php`)

- dompdf 2.0.8: `isRemoteEnabled=false` (ลิงก์ Drive พิมพ์เป็นข้อความ), PHP off, 96 dpi, chroot = root, font ปริยาย `sarabun` · ฟอนต์ใน `pdf/fonts/` (SarabunPdf-Regular/Bold; italic = regular; fallback Sarabun-*) อ้างด้วยชื่อไฟล์ล้วน (แก้ 09-23 — เดิม `installed-fonts.json` ชี้พาธเครื่อง dev ทำให้ไทยหาย) · `.ufm` หายสร้างใหม่เอง · cache `pdf/fontcache`
- **สระบน/วรรณยุกต์ไทย:** SarabunPdf มีรูปอักษรตามตำแหน่ง 95 ตัวที่ PUA U+EE00… (`SarabunPdf-thai.php`) `PdfThaiCanvas::text` แทนตอนเขียนข้อความ (layout/ตัดบรรทัดไม่เปลี่ยน) จัดการ ป ฝ ฟ, วรรณยุกต์ซ้อน, ำ+วรรณยุกต์, ญ ฐ ฎ ฏ ฤ ฦ ฬ · `PdfThaiCpdf` เขียน ToUnicode กลับเป็นไทยจริง (ค้นหา/copy ได้) · ยังไม่มี kerning และการตัดคำไทย · Σ ⚖ แสดงเป็นกล่อง
- ตัววิ่ง: `headerText` มุมบนขวา 8.5pt เทา #595959 · `pageNumbers` "หน้า {PAGE_NUM}/{PAGE_COUNT}" ล่างขวา 8pt · ความกว้างคอลัมน์ต้องเป็น % บนเซลล์หัว (dompdf ไม่อ่าน `<col>`/px)
- **กติกาสีตั้งแต่ 2026-10-08 (แพตช์ pdf-monotone → pdf-lines → history-keep-doc):** ขาว-ดำเท่านั้น (ทุกสีใน CSS เป็นเฉดเทา; รูปถ่าย/ลายเซ็นคงเดิม) · แบ่งด้วยเส้น: หัวตารางไม่มีพื้น (ตัวหนา เส้นดำ) เส้นเนื้อ #737373 แถวรวมเส้นบนหนา 1.5px ไม่มีแถวสลับสี · พื้นหลังเฉพาะแถบหัวข้อหลัก (ดำตัวขาว; เทาจาง #f0f0f0 สำหรับหัวหมวดใน doc_report/borrow_loss) · รายงานประวัติ: 1 ใบไม่แยกหน้า (`.doc{page-break-inside:avoid}`) หัวหมวดไม่ค้างท้ายหน้า (`.sec-title{page-break-after:avoid}`) ใบที่ยาวเกินหน้าเริ่มหน้าใหม่แล้วต่อ · ใบหักเงิน 1 ชุด/หน้า · ตรวจด้วย `monotone.py verify`, `lines.py verify`, `check_gray.py`

### 16.2 template ทั้ง 8

| template | ผู้เรียก | กระดาษ | เลขหน้า/หัว | เนื้อหา |
|---|---|---|---|---|
| `doc_report.php` | RPC `generateDocReportPDF(docId, docType)` (RD/OD/BD/IN/TD/TG/SC) | A4 ตั้ง | — | ตารางข้อมูลใบ + รายการ ขอ/หยิบจริง/ผลต่าง/หมายเหตุ (SC: ในระบบ/นับได้) + หมายเหตุ + รูป 2/แถว (ไฟล์ต้นฉบับ ≤300px; Drive = ข้อความ) ไฟล์ `{docId}_Report.pdf` ⚠ ตรวจแค่ login ไม่ตรวจไซต์ |
| `balance.php` | `generateBalancePDF(siteCode)` | A4 นอน | เลขหน้า | ยอดคงเหลือ IC: ลำดับ (Site) รหัส IC หมวด ชื่อ หน่วย รับเข้า รอจ่าย จ่ายออก คงเหลือ รายประตู สถานะ (มีของ/ติดลบ/หมด) หัวซ้ำทุกหน้า |
| `deduction.php` | `generateDeductionPDF` + fragment พิมพ์รวม | A4 นอน | — | ตารางหักเงินผู้รับเหมาชุด (15.2) |
| `fingerscan_summary.php` | `generateFingerScanPDF` + fragment | A4 ตั้ง (นอนเมื่อรวม) | — | สรุปค่าปรับสแกนนิ้ว (15.3) |
| `subexpense.php` | `generateSubExpensePDF` | A4 นอน | — | หักคจช. 22 คอลัมน์ (15.4) |
| `history_report.php` | `POST api/history_report.php` → `historyReportBuild` | A4 ตั้ง | เลขหน้า + "รายงานการเบิกจ่าย · {site} · d/m/Y" | ตัวกรอง → สรุปตามรหัส IC (ใบ ขอรวม จ่ายจริงรวม) → รายละเอียดรายใบ (หัวใบ ข้อมูล รายการ รูป 3/แถว) |
| `borrow_loss.php` | `generateBorrowLossPDF` | A4 ตั้ง | เลขหน้า | รายงานชำรุด/สูญหาย (10 ข้อ 8) |
| `mango_balance.php` | `mango_bal.php?export=pdf` (มี CSV) | A4 นอน | เลขหน้า | Mango → IcCode (5.4): ตารางเตือนรหัส Mango ผิดปกติ (ถ้ามี) → ตารางหลัก (รับเข้า ค้าง buffer ตัดเบิกแล้ว คงเหลือ IC ที่ได้·อัตราแปลง ใบสั่งซื้อ) → ตารางปรับยอด |

**รายงานประวัติ (Export พร้อมรูป):** ≤ 100 ใบ / 300 รูป ต่อไฟล์ · รูปย่อ JPEG ด้านยาว 900px คุณภาพ 72 cache `pdf/imgcache/` (ลบที่ไม่ใช้ > 14 วันทุกครั้งที่สร้าง) · header `X-Report-Docs/Photos/Skipped` · `set_time_limit(300)` · log `history_report_export` · สิทธิ์ตามประวัติ · ใบเก่า GAS มีแต่ลิงก์ Drive (643 ใน 668) แสดงเป็นลิงก์คลิกได้ ⚠ ลิงก์ยาวล้นช่อง

### 16.3 หน้าประวัติ (`lib/history_stats.php`, `js/history-report.js`, `js/history-picking.js`)

- `getRequisitionHistory`: ต่อใบ (RD/OD/BD/IN/TD/TG ไม่รวม SC) รายละเอียด "ชื่อ (x8 · ขอ 10)" · `mats` · `payer` ผู้นำจ่าย (ผู้ถือบัตร / "Bypass ประตู (user)" / "แบบฟอร์มกระดาษ") · จำนวนรูปในเครื่องและลิงก์ Drive · ป้าย (bypass) / (มีรายการชำรุด/สูญหาย)
- ตัวกรอง (history-report.js แทน `filterHistoryTable`): ประเภท สถานะ ค้นหา วันที่ตั้งแต่/ถึง รหัส IC ผู้นำจ่าย + ช่อง "เลือก" + ปุ่ม "Export รายงาน" (ปุ่ม PDF รายใบถูกถอด) · สวิตช์ "ราย เอกสาร | ราย Picking list" (9.5)
- หน้า "สถิติ" (`getRequisitionStats`, `generateStatsReportPDF`, `stats_report.php`) **ถูกถอดเมื่อ 2026-10-02** — build ใหม่จาก GAS จะกลับมา

### 16.4 Excel / CSV (`includes/xlsx_lite.php`)

`xlsxWrite`: เซลล์ข้อความ หัวตัวหนา freeze dropdown ได้ · ใช้ใน llp_master (ตั้งค่า LLP + คำอธิบาย), mango_master (ทะเบียน Mango), setup_master (ผูก Mango → LLP; รายงานตรวจ IC import), รายงาน CLI ของ llp_align · **หน้าประวัติ/ตรวจสอบประจำวัน/สแกนนิ้ว/หักคจช. ไม่มี Excel** · CSV: mango_bal (19 คอลัมน์ UTF-8 BOM) และ Error log บน Dashboard

### 16.5 Dashboard insights (`lib/inventory_insights.php`, `js/inventory-insights.js`)

แท็บมุมมอง "ภาพรวมสต๊อก | ตั้ง Min-Max (badge = ต่ำกว่า Min) | รอบจ่าย (เมื่อมีสิทธิ์) | Error log" (จำใน localStorage `cnx.dash.view`) · **วัสดุยอดนิยม** (`getPopularMaterials(site, 30/90/180/0 วัน, ''/RD/OD/BD)`) top 20 IC ตามจำนวนใบ (ไม่นับ cancelled/rejected) กราฟแท่งซ้อน เบิกหลัก/เบ็ดเตล็ด/ยืม-คืน (Chart.js) · **Min-Max** (8.7) · บอร์ดรอบจ่าย (9.4) · Error log ไซต์ (14.7)

---

## 17. งานอัตโนมัติ (jobs) และการแจ้งเตือน (`lib/jobs.php`, `cron/jobs.php`, `lib/notify.php`)

### 17.1 ตัวกระตุ้น (`cnxJobsMaybeRun(mode)`)

1. `cron/jobs.php` (CLI เท่านั้น) — ตั้งใจให้ Windows Task Scheduler รันทุกนาที: `schtasks /Create /TN "CONNEXT jobs" /SC MINUTE /MO 1 /TR "C:\xampp\php\php.exe C:\xampp\htdocs\connext\cron\jobs.php" /RU SYSTEM` · `--force` รันทั้งหมดทันที · log `settings/jobs.log` (เก็บ 500 บรรทัด) — **ยังไม่ได้ตั้งบนเครื่องนี้**
2. **heartbeat ของตู้ทุกนาที** — เซิร์ฟเวอร์ตอบตู้ก่อนแล้วรันชุดเต็ม (ปัจจุบันงานทำงานผ่านทางนี้เป็นหลัก)
3. Dashboard `getGateHealth` → โหมด light (เฉพาะตรวจออฟไลน์)
4. กัน: lock `settings/.jobs-lock-<db>` และเว้น ≥ 55 s ต่อโหมด (`.jobs-last-<db>-<mode>`)

### 17.2 งาน

| งาน | รอบ | ทำอะไร |
|---|---|---|
| ตรวจตู้ออฟไลน์ (GP-32/33, AL-27) | ทุกครั้ง | `ghCheckOffline` — ไม่มี heartbeat 5 นาที → `offline_since` + error log + แจ้ง `gate_offline` ให้ level 0 |
| ยืมเกินกำหนด (OP-30) | วันละครั้งหลังเที่ยงคืน (+ คำขอแรกของวันจาก config.php + `db/borrow_overdue_job.php`) | `borrowOverdueRun` ตั้ง `overdue_flag` + error log "Borrow overdue: …" ไม่ส่งแจ้งเตือน |
| Reconcile (GP-41) | ทุก `reconcile_every_min` นาที (ปริยาย 5, 0 = ปิด, ≤ 60) | เรียกตัวเองผ่าน HTTP `{internal_base_url หรือ http://127.0.0.1+APP_BASE}/api/gate.php?action=reconcile&jobtoken=…` (timeout 120 s) — finalize ใบที่ gate log ถึงปลายทางแต่ใบยังไม่ปิด |
| สุ่มนับสต๊อกรายสัปดาห์ (GP-03) | `sc_weekly_random` (ปริยายเปิด) | แผนรายสัปดาห์ใน `app_settings.sc_weekly_plan` สุ่มวัน (วันนี้–เสาร์) ต่อประตู active ที่มีตู้และมีสต๊อก → ถึงวัน 07:00–17:00 จ–ส สร้าง `user_notices` `sc_weekly` ให้สโตร์ของไซต์ "สุ่มนับสต๊อกประจำสัปดาห์ — ประตู Gxx" |

ไม่ใช่งาน: ลบ `pdf/imgcache` (ทำตอนสร้างรายงานประวัติ) · รับทราบลายเซ็นโดยปริยาย (ตอนอ่าน) · **ไม่มีงานเตือนใบรออนุมัติค้าง**

### 17.3 การแจ้งเตือน

- **ในแอป** `cnxNotifyAdmins` → `user_notices` ให้ผู้ใช้ระดับ 0 ทุกคน (kind gate_setting, material_category, gate_key, gate_offline, gate_online, gate_device) · แจ้งเฉพาะบุคคลจากโมดูลยืม (borrow_writeoff), ตีกลับ (revise, revise_back), สุ่มนับ (sc_weekly) · แสดงใน `#brNotices` poll 5 นาที
- **ภายนอก** (`cnxNotifyExternal`): Teams webhook (Adaptive Card 1.4, URL ต้อง https) และอีเมล SMTP (STARTTLS/SSL/none, AUTH LOGIN, UTF-8, หัวข้อ "[CONNEXT] …") — **ปิดอยู่ในโค้ด** `CNX_NOTIFY_EXTERNAL = false` (ตั้งแต่ 2026-10-05) admin.php ซ่อนฟิลด์และปุ่มทดสอบ · คีย์ `notify_teams_url, notify_email_to, smtp_host, smtp_port (587), smtp_secure (tls), smtp_user, smtp_pass, smtp_from` ยังไม่ได้ตั้ง
- ไม่มี LINE

---

## 18. ส่วนหน้าจอ — หน้าในแอป · add-on · ธีม · มือถือ

### 18.1 `index.php` (SPA จาก GAS)

- PHP prologue: ผู้ใช้จาก `currentUser()`/`tryRememberLogin()` → `window.__SERVER_USER`, `window.APP_BASE`, `<meta name="csrf-token">` · deep link `?page=` รับเฉพาะ `requisition, dashboard, approve, qr, confirm, history, dailycheck, pobuffer, cnxadmin` (fingerscan/subexpense/subsettings ลิงก์ตรงไม่ได้)
- ลำดับใน `<head>`: `js/inventory-theme.js` (ก่อนทุกอย่าง กันแฟลชธีม) → prompt.css → fontawesome, jquery 3.7.1, select2, chart.umd.js, qrcode.js → inline APP_BASE/__SERVER_USER → `gas-shim.js`, `php-port.js` → `<style>` ของ GAS → **บล็อก add-on** (บรรทัด ~3800–3839 แต่ละคู่มี comment "build ใหม่จาก GAS ต้องเติม…กลับ") → style รายประตู → `inventory-brand.css/js`, `inventory-theme.css`, `inventory-palette.css`, `theme-dark-addons.css`, `theme-dark-fixes.css` → `</head>`
- **ทำไม add-on ครอบได้:** script `defer` รันตอน `readyState=interactive` ก่อน `bootApp` (รอ DOMContentLoaded) · ฟังก์ชัน GAS เป็น `function`/`var` ระดับบนสุด (เขียนทับบน `window` ได้) และถูกเรียกด้วยชื่อ → ห่วงโซ่ wrapper ตามลำดับ script (เช่น `showQRModal`: mobile-flow → transfer → stock-count → qr-sign นอกสุด)
- Login overlay `#inlineLogin`: ช่อง "ชื่อผู้ใช้" (placeholder "ใช้รหัสเดียวกับ MANGO"), "รหัสผ่าน", ปุ่ม "เข้าสู่ระบบ" → `checkLogin` → `bootApp()` (รีเฟรช flag จาก `getUserPermissions`, badge สแกนนิ้ว, เฝ้าเวอร์ชันด้วย `getAppBuild` ทุก 3 นาที)
- ตั้งค่าบัญชี `#userSettingsModal`: เปลี่ยนรหัสผ่าน · ลายเซ็นของฉัน · เปลี่ยน Site (R0)

### 18.2 หน้าและเมนู

| data-page | เมนู (มือถือ) | เนื้อหา | ใครเห็น |
|---|---|---|---|
| `requisition` | เบิก-จ่าย และ ยืม-คืน (เบิก-จ่าย) | แท็บ `req-normal` เบิกวัสดุหลัก · `req-odds` เบิกวัสดุเบ็ดเตล็ด (Odds) · `req-borrow` ยืม-คืน อุปกรณ์ (+ รายการอุปกรณ์ที่ยังไม่ส่งคืน) · `req-inbound` รับเข้าคลัง (ซ่อนถ้าไม่ใช่สโตร์) · `req-transfer` โอนย้าย (ข้ามไซต์ / ย้าย Gate) (add-on) · bottom sheet จำนวน `#qtyModalBackdrop` | ทุกคน (หน้าแรก) |
| `dashboard` | Dashboard | hero (ค้นหาวัสดุในคลัง / ตรวจสอบวัสดุใกล้หมด / ย่อภาพ) · การ์ด: รายการวัสดุทั้งหมด, หมดสต๊อก, ใกล้หมด (Critical/Non-Critical), อนุมัติแล้ว รอเบิก (Pending → modal), วัสดุ WMS · ตาราง "รายการวัสดุคงคลัง (Balance)" + Export Balance · ตัวกรอง search/หมวด/Site · รายละเอียดวัสดุ `#mdModalBackdrop` · การ์ด add-on: แท็บมุมมอง, วัสดุยอดนิยม, Min-Max, ต้องตรวจสอบจากหน้าประตู, อุปกรณ์ยืมค้างคืน, สถานะตู้ | ทุกคน |
| `approve` | การอนุมัติ | ตัวกรองผู้รับเหมา/ผู้ขอ · "ตรวจสอบและลงลายเซ็น — เอกสารหักเงิน" · คิวอนุมัติ · ส่วนปรับยอดนับสต๊อก (add-on) · badge | ทุกคน (เนื้อหาตามสิทธิ์) |
| `qr` | QR เอกสาร (QR) | การ์ดใบที่รอสแกน ตัวกรอง ทั้งหมด/RD/OD/BD/IN/TD/ย้าย G · modal QR `#qrModal` poll `checkGateStatusForDoc` ทุก 3 s → `_onDocScannedAtGate` · "site manager" (level ≥ 8 / store / canReq) เห็นทุกใบของไซต์ อื่นเห็นใบตัวเอง | ทุกคน |
| `confirm` | ถ่ายรูปยืนยัน (ถ่ายรูป) | `#pickSummaryCard` รายการที่ต้องหยิบ · `#confirmWizard` (แทนที่โดย scenario05) | ทุกคน |
| `history` | ประวัติ | ตาราง + ตัวกรอง + Export + สวิตช์ราย Picking list | ทุกคน (ขอบเขตตามสิทธิ์) |
| `dailycheck` | ตรวจสอบประจำวัน (ตรวจสอบ) | แท็บ ตรวจสอบประจำวัน · Dashboard สรุป · ตั้งราคาหักเงิน · นับสต๊อก | canDaily / SC |
| `fingerscan` | บันทึกสแกนนิ้ว (สแกนนิ้ว) | บันทึกรายวัน · สรุปงวด · ชี้แจงแสกนเกิน · แดชบอร์ด | SC / BS / R0 |
| `subexpense` | หักค่าใช้จ่ายผู้รับเหมา (หักค่าใช้จ่าย) | ตารางงวดครึ่งเดือน + PDF | SC / R0 |
| `subsettings` | ตั้งค่า (ผู้รับเหมา/Mango) | ตั้งค่าผู้รับเหมา | BS / SC / R0 |
| `pobuffer` | รับของตามใบ PO | iframe `po.php?embed=1` | canReq / R0 |
| `cnxadmin` | ตั้งค่าระบบ | iframe `admin.php?embed=1` | R0 |

เมนู desktop ล้นไป "เพิ่มเติม" · มือถือ: แถบล่าง Dashboard · เบิก-จ่าย · การอนุมัติ · QR · ถ่ายรูป (+ ตรวจสอบ/หักค่าใช้จ่าย/สแกนนิ้ว/ตั้งค่า ในโหมดจำกัด) + "เพิ่มเติม" → drawer (ประวัติ, ตรวจสอบประจำวัน, บันทึกสแกนนิ้ว, หักค่าใช้จ่ายผู้รับเหมา, ตั้งค่า, รับของตามใบ PO, ตั้งค่าระบบ, ตั้งค่าบัญชี, รีเฟรช, ออกจากระบบ)

### 18.3 หน้า PHP เดี่ยว (นอก index.php)

| หน้า | หน้าที่ | สิทธิ์ |
|---|---|---|
| `admin.php` ตั้งค่าระบบ | แท็บ `?t=` gates ประตู (Gate) (สร้าง/แก้ประตู, มี/ไม่มี CCTV, key รายตู้, ความปลอดภัยตู้/QR, การแจ้งเตือน, เวลาหยิบต่อไซต์) · projects โครงการ / ไซต์ · materials วัสดุ · ladder บันได IC · users ผู้ใช้ / สิทธิ์ (RFID card, รหัสผ่าน, บทบาท) · ลิงก์ ตั้งค่า OCR, Bypass · ไม่มี hard delete ทุกอย่างลง activity_log | R0 |
| `bypass.php` | บทที่ 13 | R0 |
| `ic_new.php`, `ic_list.php`, `ic_edit.php`, `llp_master.php`, `mango_master.php`, `setup_master.php` | บทที่ 5 | CanReq / R0 ตามที่ระบุ |
| `po.php`, `po_view.php`, `rc.php`, `po_ocr_test.php` | บทที่ 11 | CanReq หรือ R0 |
| `mango_bal.php` | รายงาน Mango → IcCode + PDF/CSV | CanReq หรือ R0 (ไม่ใช่ R0 ล็อกไซต์) |
| `sign.php` | ลงนามผู้รับเหมา | สาธารณะด้วย token |

### 18.4 add-on JS (ใน `js/`)

| ไฟล์ | เพิ่มอะไร | ครอบ/แทนที่ | RPC |
|---|---|---|---|
| `gas-shim.js` | shim `google.script.run` (2.4) | — | — |
| `php-port.js` | session หมดอายุ → login ซ้อน · `_localQrImgTag` · ลงทะเบียน sw.js | — | — |
| `mobile-flow.js` (09-23) | มือถือ ≤768px: ชื่อแท็บสั้น, แถบ "เตรียมไว้ N รายการ · ยังไม่ส่ง", ปุ่มค้นหา QR, modal QR เต็มจอ + Wake Lock + "รอสแกนที่ประตู — ยื่นหน้าจอนี้ให้เครื่องสแกน" | after `renderQRCards`, `showQRModal`, `closeQRModal` | — |
| `inventory-insights.js` (09-24) | แท็บมุมมอง Dashboard, วัสดุยอดนิยม, Min-Max, บอร์ดรอบจ่าย, Error log | after `loadDashboard`, `renderDashboardTable`, `openMaterialDetail`; `isLowStockRow` | getPopularMaterials, getMinMaxData, getMinMaxSettings, saveMinMax, saveMinMaxParams, getDispatchBoard, getSiteErrorLog |
| `dispatch-rounds.js` (09-24) | แถบรอบจ่าย `#drStrip` + ตั้งค่า, ป้ายบนการ์ด QR, ข้อความรอบใน popup | after `renderQRCards`; `submitRequisition`, `submitOddsRequisition`, `submitBorrowDraft`, `showConfirmPopup`, `showInfoPopup` | getDispatchRounds, saveDispatchRounds |
| `scenario05.js` (09-28…) | หน้าถ่ายรูปยืนยันใหม่ทั้งหมด (รูปต่อรายการ, หยิบจริง, เหตุผล, แถบรอบ), ป้ายในตรวจสอบประจำวัน, การ์ด "ต้องตรวจสอบจากหน้าประตู" | **แทนที่** `renderConfirmWizard` + stub ที่เกี่ยว; before `_applyConfirmableResponse`; `_dcItemRow`, `_dcBadDcBox`; after `loadDashboard` | saveConfirmationData, getPickRoundState, getPickAlerts |
| `borrow-return.js` (09-29…) | กำหนดวันคืน, ตารางค้างคืนใหม่, ตีชำรุด/สูญหาย, การ์ดยืมค้างคืน, แจ้งเตือนในแอป | **แทนที่** `loadUnreturnedItems`; `_renderApprovalCard`, `updateApprovalStatusClient`, `submitBorrowDraft`, `showConfirmPopup`, after `resetDraftForm`, `renderQRCards` · **Proxy ครอบ `google.script.run`** เพื่อแทรก `DueDate` (flag บน `google.script.__brRunWrapped`) | getUnreturnedItems, getBorrowWriteoffInfo, writeOffBorrowItems, generateBorrowLossPDF, getBorrowAlerts, getMyBorrowOverdue, getMyNotices, ackNotices |
| `transfer.js` (09-29/10-02) | แท็บโอนย้าย + โหมดภายนอก/ภายใน, ฟอร์ม TD, ใบโอนของไซต์, ขาเข้า | `getDraftedQtyAtGate`, `getDraftedQty`, `getTypeBadge`, after `switchRequisitionTab`, `showQRModal` | getTransferFormData, processTransferSubmission, getTransferList, cancelRequisition |
| `gate-move.js` (10-02) | ฟอร์ม TG `#tgPane`, ป้ายขาออก/เข้าบน QR, ตัวกรอง TG ในประวัติ | `getDraftedQty*`, after `renderQRCards`; MutationObserver บน `#confirmWizard` | getGateMoveFormData, processGateMoveSubmission, getGateMoveList, cancelRequisition |
| `stock-count.js` (09-29…) | แท็บนับสต๊อก, ใบนับ blind (draft localStorage), ส่วนปรับยอดในหน้าอนุมัติ, SC สแกนแล้วเปิดใบนับ | after `switchDailyCheckTab`, `loadApprovalQueue`, `showQRModal`; `_onDocScannedAtGate` | getStockCountData, createStockCount, getStockCountSheet, saveStockCount, getStockAdjustQueue, decideStockAdjust, cancelRequisition |
| `history-report.js` (09-30) | ตัวกรองวันที่/IC/ผู้นำจ่าย, ช่องเลือก, Export รายงาน | **แทนที่** `filterHistoryTable` | fetch `api/history_report.php` |
| `history-picking.js` (10-08) | สวิตช์ราย Picking list, การ์ดต่อ PK | `filterHistoryTable` (ตัวของ history-report), `loadHistory` | getPickingHistory |
| `inbound-ctl.js` (10-02/10-06) | ซ่อนแท็บรับเข้าถ้าไม่ใช่สโตร์ (ตรวจทุก 1.5 s), แหล่งที่มา + รูป | after `switchRequisitionTab`; index.php เรียก `window.inboundCtl.*` และส่ง `meta()` เป็น arg 2 ของ `processInboundBatch` | — |
| `neg-stock.js` (10-02) | ยอดติดลบสีแดง/ป้าย/คำเตือน | **แทนที่** `_dashGatesWithStock`; after `_dashGateCellHtml`, `renderDashboardTable`, `openMaterialDetail`, `renderMdGateBreakdown`, `refreshGateSelectForForm`, `renderGateHint` | — |
| `qr-sign.js` (10-02) | QR แบบมี Site/Chk (14.4) | `showQRModal` (นอกสุด) | getQrPayload |
| `gate-health.js` (10-02) | การ์ด "สถานะตู้" รีเฟรช 60 s | after `loadDashboard` | getGateHealth |
| `inventory-brand.js` (10-07) | hero Dashboard: ย่อภาพ (localStorage `connext.compactInventory`), ปุ่ม, คีย์บอร์ด | — | — |
| `inventory-theme.js` (10-07) | ธีม (18.5) | — | — |

wrapper ที่ไวต่อลำดับโหลด (ถ้า index.php ถูกสร้างใหม่หรือสลับบรรทัด): history-report แทนที่ `filterHistoryTable` แล้ว history-picking ครอบ · transfer และ gate-move ครอบ `getDraftedQty*` ทั้งคู่ · dispatch-rounds และ borrow-return ครอบ `submitBorrowDraft`/`showConfirmPopup` ทั้งคู่ · inventory-insights และ neg-stock ครอบ `renderDashboardTable`/`openMaterialDetail` ทั้งคู่

**วิธีครอบอย่างปลอดภัย:** (ก) ครอบฟังก์ชัน GAS บน `window` — ตรวจ `typeof orig === 'function'` + flag `orig.__xxWrapped`, เรียกของเดิมก่อนแล้วค่อยทำของตัวเองใน try/catch, ติดตั้งก่อน DOMContentLoaded · (ข) ดักระดับ RPC — แทน `google.script.run` ด้วย Proxy ของตัวเอง, re-wrap runner ที่คืนจาก `withSuccessHandler/withFailureHandler/withUserObject`, **เก็บ flag บน `google.script` หรือ closure ห้ามเก็บบน `run`** (run.__anything เป็นฟังก์ชันจึง truthy เสมอ — บั๊กนี้เคยเกิด) · ห้าม `await`/`Promise.resolve`/`JSON.stringify` runner (Proxy ตอบ `then`/`toJSON` เป็นฟังก์ชัน → RPC "Unknown function" ค้าง) · helper ที่ใช้กัน: `rpc(fn,args,ok,fail)`

**helper ร่วมใน index.php:** popup `showAppPopup/showInfoPopup/showConfirmPopup/showLoadingPopup/closeAppPopup`, `showToast(msg,type)` · QR `showQRModal/closeQRModal/startGateStatusPolling/_onDocScannedAtGate/renderQRCards/setQRFilter` · format `escapeHtml`, `formatBalanceValue` (คู่ JS ของ `fmtQ` ซึ่งมีเฉพาะใน PHP), `getTypeBadge`, `getStatusBadge`, `normalizeStatus`, `userFullName`, `getReceiverDisplayName` · บริบท `user`, `getRoleNumber`, `getEffectiveSiteCode`, `getEffectiveUserName`, `getDashboardSiteFilter`, `getRowBalance`, `getGateRows` และ global data (`dashboardData`, `qrPageData`, `confirmDocsData`, `approvalQueueData`, `historyData`, `unreturnedData`, …) · นำทาง `switchToPage/goToPage/refreshCurrentPage/PAGE_LOADERS/switchRequisitionTab/switchDailyCheckTab/updateNavBadges/openNavDrawer/openUserSettings/cnxLoadFrame` · cache `connextCache.{get,set,invalidate,invalidateMany,clearAll,swr}` (localStorage `connext.cache.v7.<username>.`), `invalidateAfterWrite(kind)` · ฟอร์ม/สื่อ `openQtyModal`, `getDraftedQty(AtGate)`, `compressImage`, `downloadDocReport`, `exportBalancePDF`, `openMaterialDetail`, `filterDashboardBy`, `hapticTap/Success/Warn`, `bindNoThaiOn` · event `connext:session-expired`, `connext:dispatch-rounds-saved` · localStorage keys: `connext.colorTheme`, `connext.compactInventory`, `cnx.dash.view`, `cnx.transfer.mode`, `connext.historyView`, `cnx.sc.draft.<docId>`, `currentPage`, `requisitionActiveTab`, `userData`

### 18.5 ธีมและแบรนด์ (แพตช์ 10-07/10-08)

- `js/inventory-theme.js` เป็น script แรกใน `<head>` ของ index.php และ lib/ui.php · key **`localStorage['connext.colorTheme']`** = dark|light (ไม่มี → ตาม `prefers-color-scheme` สด) · ตั้ง `html[data-theme]`, `color-scheme`, `meta[theme-color]` (dark `#091a2c`, light `#f4f6f8`) · สวิตช์ `.cnx-theme-toggle` ใน navbar, การ์ด login, topbar โมดูล · plugin Chart.js `connextTheme` เปลี่ยนสีแกน/ชุดข้อมูล
- โมดูล PHP เดี่ยวใช้ไฟล์ชุดเดียวกัน (`inventory-brand.css` → `inventory-theme.css` → `inventory-palette.css` → `theme-dark-modules.css` → `theme-dark-fixes.css`) อ่าน localStorage เดียวกัน (same-origin) ตาม `storage` event · เมื่อฝัง iframe แม่ส่ง `postMessage {type:'connext-theme', theme}` (รับจาก `window.parent` เท่านั้น) หน้าที่ฝังไม่มีสวิตช์ของตัวเอง
- สีแบรนด์: navy `#102f50` · เหลือง `#ffda4d` · แดง `#b82029` · light: primary/text navy, accent เหลือง, พื้น `#f4f6f8` · dark: พื้น `#091a2c`, surface `#132d46`, border `#35516a`, **เหลืองเป็น primary/action** · โลโก้ `assets/connext-brand.png` · ภาพ `assets/inventory-shelves.svg` (login + hero) · `inventory-warehouse.png` 731 KB อ้างถึงใน CSS แต่ถูก override น่าจะไม่เคยแสดง
- `inventory-palette.css` (generated by build.ps1) แปลง inline color เดิมเป็นตัวแปรธีม · `theme-dark-addons.css`/`theme-dark-modules.css` generated โดย `gen_dark_addons.py` (ห้ามแก้มือ) · `theme-dark-fixes.css` แก้มือได้ · `:root` ของ GAS (`--primary:#1e3a8a`) ถูก override เพราะธีมโหลดทีหลัง — "Page styles precede brand/theme styles. Keep this order when regenerating index.php." · ⚠ `manifest.json` theme_color ยังเป็นน้ำเงิน GAS `#1e3a8a`

### 18.6 มือถือ

- breakpoint 768px · `inventory-theme.css` เปลี่ยน `#mobileTabNav` เป็นแถบล่างแบบ fixed (safe-area, แท็บ active เหลืองบน navy, body padding-bottom 86px) · navbar ซ่อน user-info/avatar/logout (ย้ายไป drawer) · toast/FAB/แถบ draft ยกเหนือแถบ · การ์ด Dashboard 2 คอลัมน์ · login เรียงแนวตั้ง
- bottom sheet: จำนวน `#qtyModalBackdrop`, รายละเอียดวัสดุ `#mdModalBackdrop` · กล้อง `capture="environment"` (ถ่ายรูปยืนยัน, ตีชำรุด) · haptic ผ่าน `navigator.vibrate` · Wake Lock ในหน้า QR (เฉพาะ HTTPS/localhost) · `viewport-fit=cover` + apple-mobile-web-app meta

---

## 19. การติดตั้ง การดูแลระบบ และวิธีแก้ไข (patch convention)

### 19.1 ตำแหน่งและการเริ่มใช้งาน

| รายการ | ค่า |
|---|---|
| โฟลเดอร์เว็บ | `C:\xampp\htdocs\connext` → http://localhost/connext/ |
| ฐานข้อมูล | MariaDB 10.4 (XAMPP) user root · `connext` ใช้งานจริง · `connext_test` ทดสอบ (clone ถาวร) · ค้าง: `connext_pctest`, `connext_pctg`, `connext_db` และสำเนาแอป `htdocs/connext_pctest` จากงาน scan-precheck ที่ยังไม่เสร็จ |
| ไฟล์ตั้งค่า | `settings/config.php` (`app_name, env, installed_at, gate_api_key` + อ่านเพิ่ม `gate_strict_match`, `allow_direct_return`) · `settings/database.php` (`host, dbname, user, pass`) · `settings/gemini.php` (ยังไม่ใส่ key/model) · `settings/qr_secret.php` (สร้าง 2026-10-02 — **ยังไม่ได้สำรอง**) |
| เริ่มใช้งาน | XAMPP Control Panel → Start Apache + MySQL (หรือ `C:\xampp\apache_start.bat`, `C:\xampp\mysql_start.bat`) · Apache ไม่ใช่ service |
| PHP ที่ต้องเปิด | `extension=zip` ใน `C:\xampp\php\php.ini` (สำหรับ xlsx) · `pdo_mysql`, `mbstring`, `fileinfo`, `gd` |
| บัญชี | ผู้ใช้จากชีตเดิม 43 คน + ชุดผู้รับเหมา 340 ชุด (รหัสผ่านตามชีต) · บัญชี ADM ที่สร้างเพิ่มสำหรับ local — รหัสผ่านอยู่ใน `README-LOCAL.md` และ **ควรเปลี่ยน** |

### 19.2 ตัวติดตั้ง (`install/`)

เปิด `install/index.php` (ถูกบล็อกเมื่อมีไฟล์ตั้งค่าครบ): step0 ตรวจ PHP ≥ 7.4 + extension + `settings/`, `uploads/` เขียนได้ → step1 ชื่อแอป โครงการแรก (ปริยาย ARI "เวีย อารีย์") บัญชี admin (≥ 4 ตัว ไม่มีช่องว่าง/ไทย) → step2 ทดสอบ DB (เสนอ `CREATE DATABASE … utf8mb4`) → step3 รัน `db/db_setup.sql`, seed โครงการ + บทบาท ADM (level 0, can_req, can_daily_check) + admin (bcrypt), เขียน `settings/database.php`, `config.php` (`env='production'`, คง `gate_api_key` เดิมถ้ารันซ้ำ) → complete

### 19.3 กติกาการแก้ไข (ไม่มี git — ทุกการเปลี่ยนเป็นแพตช์)

ทุกการเปลี่ยนแปลงอยู่ใน `Software/connext-local/patches/<YYYY-MM-DD>-<ชื่อ>/`:
- `README.md` ภาษาไทย: โจทย์/ที่มา · คำตอบที่เลือก · ตัดสินใจเอง · เปลี่ยนอะไร · ไฟล์ · schema · ทดสอบ (ผ่าน/ตก) · ยังไม่ได้ทำ · **วิธีย้อนกลับ**
- `before/` สำเนาก่อนแก้ตามพาธเดิมของแอป (ตัวหลังแก้อยู่ที่รากโฟลเดอร์แพตช์หรือ `after/`)
- `test_*.php` ต่อแพตช์ รันกับ `connext_test` (มี `*_bootstrap.php` ที่ชี้ `ROOT_PATH` ไปแอปจริง → การทดสอบที่บันทึกรูปจะเขียนลง `uploads/photos/` จริง ต้องลบเฉพาะของตัวเอง) · ชุดเก่าบางชุดตกโดยตั้งใจหลังกฎเปลี่ยน ให้ใช้ `*_rev`, `_rev2`, `_rev3`
- **ตั้งแต่ 10-02 หลาย session แก้ไฟล์เดียวกัน** → แพตช์ใหม่ใช้ **สคริปต์แก้แบบมีจุดยึด** โหมด `check|apply` (`apply_gate_move.py`, `apply_scan_precheck.py`, `monotone.py`, `lines.py`, `keepdoc.py`, `apply.ps1` แบบตรวจ hash ฯลฯ) · **ย้อนกลับ: อย่าคัดลอก `before/` ทับทั้งไฟล์** ให้ถอดเฉพาะบล็อกที่ติดป้าย `[2026-10-02 · GP-xx]`, `[PHP port …]`, `[มติ 52]`, `[2026-10-06]`, `[2026-10-08]`
- เพิ่มหัวข้อสั้นใน `connext-local/README-LOCAL.md` (บันทึกการเปลี่ยนแปลงหลัก — ยังขาดบางแพตช์ ดูบทที่ 20)
- add-on JS/CSS ใส่ด้วยบรรทัด `<link>`/`<script defer>` ก่อน `</head>` ของ index.php ห้ามแก้โค้ด GAS โดยตรง (ยกเว้นบรรทัดติดป้าย `[PHP port …]`) · **ถ้า build index.php ใหม่จาก GAS ต้องเติมบรรทัด include ทั้งหมดกลับ ใส่ `[PHP port …]` ทุกจุด และถอดหน้าสถิติอีกครั้ง** (`tools/build_index.py` ไม่มีบนเครื่องนี้)

### 19.4 สำรองข้อมูล

- `mysqldump` ฐาน `connext` ทั้งก้อนลง `connext-local/backup-connext-before-<ชื่อ>-<วันที่>.sql` **ก่อนทุกการ migrate schema หรือเขียนข้อมูลจำนวนมาก** (แพตช์ที่แก้แค่โค้ดไม่ dump) · ใช้ `mysqldump --result-file=…` (PowerShell `>` ใส่ BOM)
- สำรองที่มี: llp-import, ic-import, stock-migrate, gate-alloc (09-23) · scenario05 (09-28) · borrow-return, td-stockcount (09-29) · gate-move ×2, inbound-control, gate-security, gate-health, llp-flowhub (10-02) · stock tables ก่อน pending-approved (10-06) · **ไม่มีสำรองหลัง 10-06** (การแก้ card ID และคอลัมน์ `qty_return_round` ทำโดยไม่ dump)

### 19.5 วิธีทดสอบโดยไม่กระทบระบบจริง

- สำเนาแอป `htdocs/connext_<x>test` (robocopy ผ่าน PowerShell ข้าม uploads) ชี้ `settings/database.php` ไป clone ของตัวเอง: `mysqldump --single-transaction connext` → `CREATE DATABASE connext_<x> utf8mb4_unicode_ci` → load → ทดสอบ → `DROP` · ตั้งรหัสผ่านชั่วคราวเฉพาะใน clone · ลบสำเนาเมื่อเสร็จ · ตรวจว่า `app_settings` ไม่มี URL แจ้งเตือนจริง
- จำลองตู้ด้วยการ POST ไป `api/gate.php` ของสำเนา (key จาก settings ของสำเนา) ส่ง `Codes {scanId: qrCheckCode(site, scanId)}` เมื่อ `qr_require_code=1`
- ทดสอบโปรแกรมตู้แบบ headless บน PC: import ด้วย importlib, `FULLSCREEN=False`, stub `GasClient._post/_call`, no-op thread/selfcheck, `withdraw()` Tk root — **ยังไม่เคยทดสอบบนตู้จริง**
- กับดักที่เคยเจอ: เปลี่ยน `settings/database.php` ของแอปจริงไป `connext_test` ทำให้ Chrome ที่เปิดค้างเขียนลง test (ใช้ CLI `--db=` แทน) · browser pane ของเครื่องมือมี quirk (screenshot ค้าง เปิด PDF ไม่ได้ → ใช้ PyMuPDF) · DDL ใน test รันนอก transaction → ตารางว่างค้างใน connext_test · RPC 500 "MySQL server has gone away" เป็นครั้งคราวเมื่อ Dashboard ยิง ~30 คำขอพร้อมกัน (ไม่ใช่ regression) · หน้าที่เปิดค้างต้อง F5 หลังแพตช์ CSRF/เลข LLP/QR · Wake Lock เฉพาะ HTTPS/localhost
- `reimport-local.bat` ใช้ไม่ได้ (4.6) ให้รันคำสั่ง import ตรง

### 19.6 การดูแลตู้

- โปรแกรมตู้ต้อง **copy ไปทุกตู้แล้วรีสตาร์ท** ผ่านปุ่ม taskbar/`gate_launch.sh` · ต่อตู้มี `gate_local.json` (site, gate, api_key, backend_url) · ไฟล์ runtime: `alarm4_latch.json`, `gate_flags.json`, `pending_logs.jsonl`, `CardList.txt`, `hardware.log` · ไม่มีบัตรสโตร์ให้ปิด ALARM 4 → ลบ `alarm4_latch.json` ผ่าน SSH
- ลำดับเปิดใช้ความปลอดภัย: ดู 14.6 · งานเดินสายที่รอ: GP-34 (กลอนไป NO → `lock_failsafe:true`), GP-28 (สลับขั้วเซ็นเซอร์ → `sensor_locked_level:1`), GP-27 (reed DI2 → `door_sensor:true`), kiosk mode, GP-33 ปลั๊ก/เบรกเกอร์ในตู้, GP-29 เสากล้อง, GP-39 key switch ตัดไฟกลอน

---

## 20. ประวัติการเปลี่ยนแปลง (ตามลำดับเวลา)

รูปแบบ: วันที่ · โฟลเดอร์แพตช์ — สิ่งที่เปลี่ยน · DB · ประเด็นค้าง (รายละเอียดเต็มใน README ของแต่ละแพตช์)

| วันที่ | แพตช์ | สิ่งที่เปลี่ยน | DB |
|---|---|---|---|
| 09-21 | (README-LOCAL) ติดตั้ง local | ติดตั้งจาก `connext-dist.zip` · นำเข้าชีต 55 โครงการ / 43 ผู้ใช้ / 6,814 Mango / 667 ใบ · ประกอบ `db_setup.sql` ใหม่ · `add_local_admin.php` | ทั้งก้อน |
| 09-22/23 | `setup-master` (มติ 40–46) | หน้า `setup_master.php` ผูก Mango → LLP → IC, import "สร้าง LLP.xlsx", ออก IC จากไฟล์ (มติ 44), ย้ายยอด Mango → IC, `ic_edit.php` (มติ 46), `ic_new.php` ใหม่ (มติ 43), เครื่องมือ Flow Hub (dry-run) · ข้อมูลจริง: L1 11 · L2 72 · LLP 2,833 · Mango→LLP 6,735 · IC 6,681 · ย้ายยอด 307 รหัส | `mango_ic_map`, `ic_items.has_serial/is_cx` |
| 09-23 | `settings-theme` | admin/โมดูลใช้ฟอนต์ Prompt + Font Awesome + แท็บ | — |
| 09-23 | `ic-only-transactions` (มติ 34) | Dashboard/Export/dropdown แสดง IC เท่านั้น · ใบใหม่ที่มี Mango ถูกปฏิเสธ · แบนเนอร์ Mango ที่ยังมียอด | — |
| 09-23 | `pdf-thai-font` | ฟอนต์ไทยใน PDF หาย (installed-fonts.json ชี้เครื่อง dev) → ฟอนต์ใน `pdf/fonts` + SarabunPdf วางวรรณยุกต์ + `lib/pdf_thai.php` | — |
| 09-23 | `ic-new-mango-first` (มติ 47) | `ic_new.php` ต้องเริ่มจาก Mango · `lib/ic_mango.php` | — |
| 09-23 | `gate-stock-pagination` | การ์ด "ยอดคงเหลือที่ยังไม่อยู่ประตูใด" + ปุ่มลงยอดประตูตั้งต้น · importer ขั้น 10b · ตารางวัสดุแบ่งหน้า | — (สำรอง gate-alloc) |
| 09-23 | `mobile-flow` | หน้าเบิก/QR/ถ่ายรูปสำหรับมือถือ Wake Lock | — |
| 09-24 | `inventory-minmax` (มติ 49) | วัสดุยอดนิยม + Min-Max + Error log ไซต์บน Dashboard | `stock_minmax*` (lazy) |
| 09-24 | `dispatch-rounds` (มติ 50) | รอบจ่ายต่อไซต์ แถบ/ป้าย/บอร์ด | `dispatch_*` (lazy) |
| 09-25 | `gate-stock-per-gate` (มติ 51) | RD/OD/IN เลือกประตู · guard สต๊อกรายประตูตอนส่ง/อนุมัติ · ย้ายของข้ามประตู (admin) · คอลัมน์รายประตูใน PDF · `gate_strict_match` | — |
| 09-25 | `rd-approval-r6` (มติ 52) | RD ต้อง R6+ (ถูกแทนด้วย 10-02/10-06) | — |
| 09-28 | `scenario05` (Doc 05 ฉบับ 1) | G1–G6: `addToPicking`, `icCount`, สถานะรายใบ, รับเฉพาะ Awaiting ของประตู, บทบาทบัตร + timing, `logRoundEvent`, ตัดตามหยิบจริง · W1–W6: รูปต่อรายการ, หยิบจริง, แถบรอบ, ห้ามยกเลิกหลัง Opened, ตั้งเวลาหยิบ, `bypass.php` · IN สแกนทุกประตู, NAR ผ่าน OD เท่านั้น | `document_items.qty_actual/actual_reason/photo_url/photo_return_url`, `gate_settings`, `gate_round_events` |
| 09-29 | `scenario05-borrow-return` (Doc 05 ฉบับ 2) | หยิบจริง ≤ ขอ · ขาคืนบันทึกจำนวนจริง · กำหนดวันคืน + งานเกินกำหนด · ตีชำรุด/สูญหาย + PDF + แจ้งในแอป · แก้บั๊ก QR ขาคืนไม่ขึ้น | `documents.due_date/overdue_flag/writeoff_flag`, `document_items.qty_returned/return_reason`, `borrow_writeoffs`, `user_notices` |
| 09-29 | `td-transfer-stock-count` | TD โอนข้ามไซต์ (PM ต้นทางอนุมัติ ตัดต้นทาง) · SC นับสต๊อก blind + ปรับยอดโดย ADM/R8+ + KPI | `doc_type` + TD/SC, `dest_project_id`, `stock_adjustments` |
| 09-29 | `bypass-select-doc` | Bypass ประตูแบบเลือกเลขเอกสาร (เปิด/ปิด) · กระดาษรองรับ TD | — |
| 09-29 | `bypass-item-photos` | Bypass กระดาษต้องมีรูปต่อรายการ | — |
| 09-30 | `alarm4-kill-latch` (ตู้+เว็บ) | ALARM 4 latch จนบัตรสโตร์ + ยืนยัน · Dashboard "ALARM 4 ค้างอยู่" | — |
| 09-30 | `zero-pick-alarm` | หยิบจริง 0 ทุกรายการ = ALARM (log + การ์ด) | — |
| 09-30 | `history-export-report` | ตัวกรองประวัติ + Export รายงานหลายใบพร้อมรูป (ถอดปุ่ม PDF รายใบ) | — |
| 10-02 | (README-LOCAL) ทบทวน `CONNEXT_Gate_Test_Cases.xlsx` | รายการ "เหลือง" → 7 แพตช์ · ลำดับเปิดใช้ (เว็บ → ตู้ → key → QR → ปิด key กลาง) | |
| 10-02 | `confirm-reason-list` (GP-05) | เหตุผลลดหยิบต้องเลือกจากรายการ | — |
| 10-02 | `approval-self-rules` (GP-16/45/13) | RD auto เฉพาะ PM, ห้ามอนุมัติใบตัวเอง, ผู้นับไม่อนุมัติผลนับ, สโตร์ไม่ตีจำหน่ายใบตัวเอง, เตือนเกินกำหนด | — |
| 10-02 | `inbound-control` (GP-10/OP-70) | IN เฉพาะสโตร์ · แหล่งที่มาบังคับ + รูป | `documents.in_source/in_ref/in_reason/in_photo_url` |
| 10-02 | `gate-move` (TG) | ย้าย Gate 2 QR อนุมัติตามหมวด | `doc_type` + TG, `dest_gate_id`, `gate_logs.leg` + in |
| 10-02 | `negative-stock` (GP-42/OP-74) | on_hand ติดลบได้ แสดงแดง | — |
| 10-02 | `gate-security` (GP-17/21/22/23/46) | QR มี Site/Chk (HMAC) · key รายตู้ · ประเภท CCTV · `app_settings` · แจ้ง level 0 | `gates.has_cctv/api_key_*`, `app_settings` |
| 10-02 | `gate-health` (GP-30–33/41/03, OP-30) | heartbeat/ออฟไลน์/อุปกรณ์ · `cron/jobs.php` (ออฟไลน์, reconcile, เกินกำหนด, สุ่มนับ) · Teams/SMTP | `gate_status` |
| 10-02 | `cabinet-decisions` (ตู้) | ปิดโปรแกรมต้องบัตรสโตร์ · ตรวจ Site/Chk · `gate_local.json` · SC รอบแยก · ไม่มี CCTV ไม่มี ALARM 4 · heartbeat · resume ถามเว็บ · web-down mode · DI2+DI3 · kill ALARM 4 ต้องมีรูป+เหตุผล | — |
| 10-02 | `admin-gate-settings-layout` | การ์ด switch เหลืองเมื่อยังไม่บันทึก + ยืนยัน | — |
| 10-02 | `remove-stats-page` | ถอดหน้า "สถิติ" และ RPC/template ที่เกี่ยว | — |
| 10-02 | `llp-flowhub-apply` (มติ 48) | จัดเลข LLP/IC ตาม LLP-Flowhub.xlsx บนข้อมูลจริง: IC 244 เปลี่ยนเลข, สินค้า +19/แก้ 84/ลบ 35, เลขชั่วคราว 17 | ข้อมูล (สำรอง 16:23) |
| 10-02 | `csrf-after-logout` | logout แล้ว login ใหม่ไม่รีโหลด → CSRF ใหม่ + retry | — |
| 10-02 | `qr-chk-salvage-heartbeat` | เรียง QR `{Doc, Site, Chk, …}` · ตู้กู้ Site/Chk · แก้ heartbeat ส่งครั้งเดียว | — |
| 10-05 | `scan-precheck` — **ไม่มี README, ยังไม่ลงระบบจริง** | action `checkDocs` ตรวจใบตอนสแกน QR (preview ของ `gateAcceptDocs`) · ทิ้งสำเนา `connext_pctest` + DB ไว้ | — |
| 10-06 | `in-gateless` | เลข IN ไม่มี G · 1 ใบต่อการส่ง · ผูกประตูตอนสแกน | — |
| 10-06 | `pending-approved-only` | จองเฉพาะใบ Approved/Sent Borrow · รัน recalc จริง (ARI 136 → 115) | ข้อมูล stock (สำรอง) |
| 10-06 | `approval-revise` (+rev2) | สถานะ `Awaiting revision` ตีกลับให้แก้ · `reviseRequisition` ลดจำนวน · การ์ดอนุมัติแสดงสต๊อกประตู | ค่าสถานะใหม่ |
| 10-06 | `rd-auto-r6` | RD จาก R6+ หรือ PM auto-approve ต่ำกว่านั้น (รวม ADM) ต้องเลือก R6+ | — |
| 10-07 | `inventory-style` | แบรนด์ navy/แดง/เหลือง, login art, hero (ทดสอบตอน Apache/MySQL ปิด) | — |
| 10-07 | `inventory-themes` | สวิตช์ Light/Dark ถาวร sync ข้าม frame · แถบล่างมือถือ · `inventory-palette.css` | — |
| 10-07 | `dark-theme-fix`, `full-theme-audit` (164 กรณี), `dashboard-dialog-theme` | แก้ dark mode / audit / dialog | — |
| 10-08 | `md-modal-resync` | dialog รายละเอียดวัสดุเทียบ cache เก่าแล้วเตือนผิด → รีโหลดแล้วเปิดใหม่ | — |
| 10-08 | `borrow-installment-return` | ทยอยคืน: คืนบางส่วนกลับ Borrowed, "คืนเพิ่ม" เปิด QR RT เดิม | `document_items.qty_return_round` (ไม่ได้ dump) |
| 10-08 | `balance-search-filter` | ค้นหาแบบแยกคำ (นิ้ว ≈ ") + ป้าย "กำลังกรอง" | — |
| 10-08 | `store-over-cap-switch` | `gate_settings.store_over_cap` · ขอเวลาเพิ่มเฉพาะ 60 s สุดท้าย · Dashboard จับ "OVER CAP" (ตู้: ต้อง deploy) | คอลัมน์ (lazy — ยังไม่ถูกสร้าง) |
| 10-08 | `card-id-validate` | RFID พิมพ์ใหญ่ O→0 `[0-9A-F]{4,16}` ห้ามซ้ำ · แก้ข้อมูล user 49 `OFA76A → 0FA76A` | ข้อมูล |
| 10-08 | `pdf-monotone` → `pdf-lines` | PDF ทั้ง 8 แบบขาว-ดำ → ใช้เส้นแบ่ง พื้นหลังเฉพาะหัวข้อหลัก · "ช่องพื้นฟ้า" → "ช่องกรอบหนา" | — |
| 10-08 | `history-picking-list` | ประวัติราย Picking list · `getPickingHistory` | — |
| 10-08 | `dark-theme-addons` | CSS dark สำหรับ add-on (generated) | — |
| 10-08 | `history-keep-doc` | รายงานประวัติ 1 ใบไม่แยกหน้า (ARI 34 ใบ: 12 → 0) | — |

README-LOCAL.md ยังไม่มีหัวข้อของ: 09-25 ทั้งสอง · 10-05 · 10-06 ทั้งสี่ · 10-07 ทั้งห้า · 10-08 md-modal-resync, borrow-installment-return, balance-search-filter, store-over-cap-switch, card-id-validate

---

## 21. รายการค้าง ประเด็นเปิด และข้อสังเกตจากการตรวจโค้ด

### 21.1 การตัดสินใจเรื่องข้อมูล (รอผู้ใช้)

1. **ใบ OD 4 ใบค้างที่ ARI G01** (OD19062601G01, OD25062610G01, OD30062608G01, OD05072614G01 มิ.ย.–ก.ค.): หยิบและถ่ายรูปแล้วแต่ตู้ไม่เคยส่ง closeGate สต๊อกไม่ถูกตัด · เลือก **อย่างใดอย่างหนึ่ง** ก่อนนับ G01 ครั้งแรก: ปิดใบ (ตัดสต๊อก) หรือให้ SC ปรับยอดแล้วยกเลิกใบ
2. **ใบยืมเก่า 10 ใบไม่มีกำหนดคืน** (Borrowed มิ.ย.–ก.ค.) ไม่ถูกนับเกินกำหนด — ให้สโตร์ตั้งย้อนหลังหรือกำหนดวันรวม?
3. **2 แถวที่ถูกปัดเป็น 0 ก่อนแพตช์ติดลบ:** ARI `MRO12008046000002000` (−1) และ `MRO10012000000002001` (เดิม MRO10013…, −17) — นับแล้วปรับ หรือ `UPDATE on_hand = qty_in − qty_out`
4. **Cat/Char ยังไม่ตั้ง 8 LLP:** ARC04001, HDW01009, MEP05001, MRO01001, MRO06014, MRO09001, MRO09009, MRO11002 (ออก IC ใต้ LLP เหล่านี้ไม่ได้)
5. **Mango ใน Flow Hub ที่ยังไม่มี IC 15 รหัส** — 3 รหัสยังถือยอดบน Mango: PG150011100000 แปรงสลัดน้ำ 8 · PG260070100200 สายเอ็น #80 60 · PG270070200300 หมึกจีน 8 oz 7
6. **เลข LLP ชั่วคราว 17 ตัว** รอจัดซื้อจัดเลข: ARC01050, ARC04068/69, EQP03035–40, EQP06046, EQP07014, HDW01054/55, MEP05063, MRO01044, MRO09024, MRO11099
7. SIKA TOPSEAL-107 (400 ชุด vs IC หน่วยแกลลอน) และรหัสอื่นจาก 09-23 ที่ยังไม่ย้าย (ใบมีดคัตเตอร์ 947, บันได, รหัสทดสอบ RD123456789000 184) — สถานะปัจจุบันไม่ชัด · 744 LLP ไม่มี Cat/Char (ณ 09-23) · IC 146 ตัวไม่ผูก Mango
8. ไม่มีสคริปต์ย้อนกลับการจัดเลข LLP ที่คง transaction หลังจากนั้น
9. "ลงยอดที่ประตูตั้งต้น" ยังไม่ได้ยืนยันว่ากดแล้วหรือยัง (09-23)

### 21.2 การตั้งค่าและโครงสร้างพื้นฐาน

10. **Task Scheduler ยังไม่ตั้ง** ("CONNEXT jobs" ทุกนาที / "CONNEXT borrow overdue" 00:05) — งานรันเฉพาะเมื่อตู้ heartbeat หรือมีคนเปิด Dashboard
11. **Teams/SMTP ยังไม่ตั้ง** และการส่งภายนอกปิดในโค้ด (`CNX_NOTIFY_EXTERNAL=false`) · ไม่มี LINE
12. **สำรอง `settings/qr_secret.php`** — หาย/เปลี่ยน = QR ที่เปิดค้างทุกใบใช้ไม่ได้ + job token เปลี่ยน · ยังไม่มีสำเนาใน connext-local
13. **rollout key/QR:** G01/G02 มี key รายตู้ · G03 และ HO G01 ไม่มี · key กลางยังเปิด · บังคับ QR เปิดอยู่แล้วตั้งแต่ 10-02 15:36 (README-LOCAL ยังเขียนว่าปิด) · G03 `hardware_close=1` แต่ไม่เคย heartbeat
14. รอบจ่าย, Min-Max, `store_over_cap` ยังไม่ตั้งค่า/ยังไม่มีคอลัมน์บนฐานจริง
15. `db_setup.sql` ต้นฉบับหาย (ไฟล์ที่ประกอบอาจต่าง) · DDL ของ TG ไม่อยู่ในไฟล์ · สำเนาใน connext-local เก่ากว่าตัวจริง · `reimport-local.bat` เสีย
16. รหัสผ่าน admin ยังไม่เปลี่ยน · Gemini key/model ยังไม่ใส่ · `extension=zip` ต้องเปิดไว้ · zip deploy ต้องมี `lib/pdf_thai.php` + `pdf/fonts/*` · `tools/build_index.py` ไม่มี
17. RPC 500 "MySQL server has gone away" เป็นครั้งคราวภายใต้ burst · console error 10-07 (คำขอ confirmation-documents คืน non-JSON)
18. สำเนาทดสอบค้าง: `htdocs/connext_pctest`, DB `connext_pctest`, `connext_pctg`, `connext_db`
19. **ความลับใน source:** key กลางของตู้ hardcode ใน `connext_access.py` (= `gate_api_key`), รหัสผ่านกล้องใน `RTSP_URL`, key รายตู้ใน `gate_local.json` ที่ copy ไว้ · ส่ง HTTP ธรรมดา key อยู่ใน query string (ลง access log) — ควรหมุนและย้ายออกจาก source · ค่า `qr_secret` เคยถูกแสดงใน transcript ของ agent สำรวจบนเครื่องนี้ (ไม่อยู่ในเอกสารนี้)

### 21.3 ตู้/ฮาร์ดแวร์

20. copy `connext_access.py` ล่าสุดไปทุกตู้แล้วรีสตาร์ท (มี alarm4 latch, cabinet-decisions, QR/heartbeat fix, store-over-cap) — `PROGRAM_VERSION` ตรึงไว้ จึงตรวจจากระยะไกลไม่ได้ว่าตู้รัน build ไหน · สำเนาใน `Hardware/` ไม่ใช่ตัวที่ติดตั้ง (14.1)
21. `gate_local.json` ทุกตู้ · งานเดินสาย (19.6) · kiosk mode
22. ทดสอบบนตู้จริงที่ค้าง: AL-04, AL-14, AL-15, AL-25–27, OP-54–57, OP-67, OP-73, OP-77 — **ยังไม่เคยทดสอบอะไรบนตู้จริง**
23. รอยืนยัน "(รอยืนยัน)": `alarm4_kill_any_card` (ตอนนี้สโตร์เท่านั้น) · ไม่มี CCTV ⇒ ไม่มี ALARM 4 · ทุกประตูมีตู้+กลอน · web-down 60 s · reconcile 5 นาที · สุ่มนับรายสัปดาห์
24. `scan-precheck` (10-05) ยังไม่ลง ไม่มี README — รัน `apply_scan_precheck.py … check` ก่อน
25. ข้อความบนตู้สำหรับ SC/TG แก้ในโปรแกรมแล้วแต่ยังไม่ deploy (ยังขึ้น "ตัดสต็อก")
26. ตู้ไม่ส่ง `logRoundEvent` (เหตุการณ์หมดเวลา/ขยายเวลาไปถึงเว็บแค่เป็นข้อความ error log) · `round_end` ไม่มี cardId/cardholder · ไม่มี clock sync · รูปหลักฐาน ALARM 4 ดูบนเว็บไม่ได้ (ส่งแค่พาธบน Pi) · รอบที่ตู้เปิดแล้วตู้ตาย ปิดด้วย bypass/reconcile ไม่ได้ · ประตูไม่ทราบถูกตีความต่างกันระหว่าง `stock.php` (hardware) และ `gateUsesScanFlow('')` (scan-flow) · ตัวเลข ≥ 6 หลักใด ๆ ถือเป็นบัตร · reader "ok" แค่พอร์ตเปิด · ข้อความ fail-safe ในเว็บ/แผนผังขัดกับค่าปริยาย NC ของโค้ด · แผนผัง `GateController.drawio` ไม่ตรง pin map ในโค้ด · หมวด Error log ไม่จับข้อความบางแบบของตู้ (14.7)

### 21.4 ช่องว่างเชิงฟังก์ชันและเอกสาร

27. แก้จำนวนรับเข้าจริงของ IN — ยังไม่ตัดสินใน Doc 05
28. TD: ปลายทางไม่เชื่อมต้นทาง ไม่มีตรวจการมาถึง ไม่แจ้ง PM · TG: นำเข้าต้องเท่าส่ง ไม่มีเตือน In Transit/เวลาจำกัด ไม่อยู่ใน Doc 05
29. แจ้งเตือนในแอปเท่านั้น · มูลค่าชำรุด/สูญหายใช้ rate card ไม่ใช่ราคา PO · SC ต่อประตูเท่านั้น
30. หน้าอนุมัติไม่มีป้ายรอบจ่าย · ไม่เก็บเวลาอนุมัติ · รอบจ่ายไม่มีวันหยุด/รอบรายวัน · Min-Max ไม่มี Excel และ recode IC ทำ Min-Max หาย · Error log ไม่มี "ตรวจแล้ว"
31. PDF: รูปไม่แปลงเป็นขาว-ดำ ยังไม่ทดสอบเครื่องพิมพ์จริง Σ ⚖ เป็นกล่อง ไม่มีตัดคำไทย ลิงก์ Drive ล้นช่องในรายงานประวัติ · `sign.php` ไม่มีธีม
32. `rc.php` ออก IC โดยไม่ผูก Mango · modal ใน setup_master ไม่ atomic (create_ic + attach_ic แยกกัน)
33. การมองเห็นประวัติรอบของสโตร์เป็นการตีความ · Doc 05 และ Test Cases ต้องอัปเดตเรื่อง rd-auto-r6 และทยอยคืน · ก่อน rollback แพตช์ bypass/TG/TD/SC ต้องปิดรอบ/ใบที่ค้างก่อน

### 21.5 ข้อสังเกตจากการอ่านโค้ด (พบระหว่างจัดทำเอกสาร ยังไม่ได้ทดสอบ/ยืนยัน)

- **e-sign:** ลิงก์ FS- ของผู้รับเหมาน่าจะใช้ไม่ได้ (`_sigDeductionDoc` หาใน deduction_docs เท่านั้น) · `getSignatureView`/`getFingerScanVerifyView` คืน `dataUrl` แต่ client อ่าน `signatureData` · `getSignDocDetail` รูปแบบไม่ตรงกับ client และไม่รองรับ SE- · sign.php อ่าน `signatureData`/`deadlineAt` ผิดชื่อ/ระดับ · `createAllFingerScanLinks` สร้างทุกชุดไม่ใช่เฉพาะที่มีค่าปรับ
- **สิทธิ์:** RPC อ่านยอด (`getBalanceList`, `getMaterialsList`, `getGateBalanceList`, `getMaterialBalance`) เชื่อ siteCode จาก client → ผู้ใช้ที่ login ดูยอดไซต์อื่นได้ · `generateDocReportPDF` ตรวจแค่ login · rate card ไม่กั้นไซต์ · PO ไม่กั้นไซต์ · `getCardList` ไม่ส่ง siteCode คืนบัตรทุกไซต์ · ไม่มีการกั้นบทบาทฝั่งเซิร์ฟเวอร์ว่าใครสร้าง RD/OD/BD ได้ · ไฟล์ใน `uploads/` เปิดได้ถ้ารู้ชื่อ · ไม่มี `settings/.htaccess` ตามที่ comment อ้าง
- **ความสอดคล้อง:** ระดับอนุมัติ BD ต่างกันระหว่างตอนส่ง (ทั้งตะกร้า) กับคิว (ต่อใบ) · ป้าย C02 "อนุมัติ R4+" vs มติ 52 RD ต้อง R6+ · `po_headers.status` ENUM ไม่มี `closed` ที่ `setstatus` ยอมรับ · `admin.php mat_save` แก้ `materials.name/unit` แต่ไม่แก้ `ic_items` → drift · comment ใน registry ว่า `closeSubcontractorSettle` "ยังไม่เปิดใช้" ทั้งที่ทำงานได้ · ข้อความ "ยอดปัดที่ 0" ใน bypass.php ล้าสมัย · `DR_OPEN_STATUSES` comment ไม่ตรง · ค่า "ไม่รู้ระดับ" client = 0 แต่ server = 99 · `APP_BUILD` hash ไฟล์ที่ไม่มี (js/app.js, css/app.css) · `import_from_sheet --fresh` ล้างไม่ครบทำให้แถวกำพร้า · `gate_round_events.event` comment ไม่มี bypass_* · `ic_items.has_serial/is_cx` ยังไม่มีโฟลว์ใช้ · `db/design_po_ocr_ic_v1.md` ถูกอ้างถึงแต่ไม่มีไฟล์ (มติ 1, 4, 13, 17, 24, 25, 27, 36, 41 ไม่มีคำอธิบายในโค้ด)
- **PWA:** sw.js แคชหน้า HTML ที่มี CSRF token/`__SERVER_USER` (offline อาจเห็นตัวตนเก่า) · ชื่อ cache `connext-v1` ไม่เคยเพิ่ม · `?v=mtime` สะสม · `ignoreSearch` อาจเสิร์ฟ JS/CSS เก่าตอน offline · `gate-health.js` ฟัง `#dashboardSiteFilter` ที่ไม่มีอยู่ (ยังทำงานได้ผ่าน wrapper)

---

## 22. อภิธานศัพท์

| คำ | ความหมาย |
|---|---|
| **GAS / gas-shim** | ระบบเดิมบน Google Apps Script + Sheets / JS ที่จำลอง `google.script.run` ให้ยิง `api/rpc.php` |
| **มติ N / มติจุดที่ N** | ข้อสรุปจากการประชุมที่โค้ดอ้าง (เช่น 34 IC เท่านั้น · 38 Mango ถือยอดไม่ได้ · 45 1 Mango = 1 LLP · 47 เริ่มจาก Mango · 48 Flow Hub · 49 Min-Max · 50 รอบจ่าย · 51 สต๊อกรายประตู · 52 RD ต้อง R6+) — รายการเต็มใน 5.1–5.5 และ README แพตช์ |
| **Doc 05 / Scenario 05** | `Doc/05_CONNEXT_Scenario_การทำงานของระบบ.docx` (ฉบับ 28/29 ก.ย.) — เลขวงกลม ①–⑬ อ้างหัวข้อย่อย (① flow หยิบหลัก · ② ตาราง auto-approve · ③ ยืม-คืน · ⑥ เพิ่มใบในรอบ · ⑦ ถ่ายรูป/หยิบจริง · ⑧ เวลาหยิบ · ⑩ ห้ามยกเลิกหลัง Opened/Bypass · ⑪ คืนผ่าน IN · ⑬ ไม่หักเงินอัตโนมัติ — ตีความจากบริบท) |
| **GP-xx / OP-xx / AL-xx** | รหัส test case จาก `Doc/CONNEXT_Gate_Test_Cases.xlsx` (ฉบับ 2 ต.ค.): GP = ช่องโหว่ (เช่น GP-05 เหตุผล · GP-10 IN · GP-16 อนุมัติตัวเอง · GP-17/21 รหัส QR · GP-22/23 key รายตู้ · GP-42 ติดลบ) · OP = operational · AL = alarm · "เหลือง" = รับทำ · "(รอยืนยัน)" = รอตัดสิน |
| **R0…R12** | ระดับบทบาท (3.2) · R0 = ADM/R&D · R1–R3 สายสโตร์ (ถ้ามี can_req) · R4/R5 SE · R6+ ผู้อนุมัติ RD · R8+ ผู้อนุมัติผลนับ · R11 PM · R12 PD |
| **สายสโตร์ / สายคลัง / CanReq / store** | ผู้ใช้ที่ `roles.can_req=1` (AST, ST1, ST2, SST) |
| **SC / BS / CanDaily** | เลขาไซต์ / ผู้ดูแลผู้รับเหมา / ผู้ตรวจสอบประจำวัน (flag ของบทบาท) |
| **PM** | Project Manager (role_code PM) ผู้อนุมัติ TD เพียงคนเดียว อนุมัติใบตัวเองได้ |
| **RD / OD / BD / IN / TD / TG / SC** | ประเภทเอกสาร (6.1) · `…RT` = ขาคืนของ BD · ตู้แสดง MO/MI = ขาออก/ขาเข้าของ TG |
| **PK / รอบหยิบ / Picking list** | รอบที่ตู้ออกตอนแตะบัตร `PK+DDMMYY+NN` · ≠ **รอบจ่าย** (dispatch round ช่วงเวลาจ่ายต่อไซต์) |
| **gate log / ขา (leg)** | แถวใน `gate_logs` ต่อขาของเอกสาร (out / return / in) |
| **scan-flow** | ประตูไม่มีตู้ (`hardware_close=0`) ใบจบที่ถ่ายรูปยืนยัน |
| **Bypass** | ADM คีย์ย้อนหลังจากแบบฟอร์มกระดาษ หรือเปิด/ปิดประตูแทนตู้ |
| **ผู้นำจ่าย** | ผู้ถือบัตรที่แตะเปิดประตู (card holder) |
| **Zero pick** | หยิบจริง 0 ทุกรายการ (ALARM) · **OVER CAP** = ขยายเวลาเกินเพดาน |
| **ALARM 1 / 2 / 3 / 4 / A** | ประตูถูกเปิดโดยไม่สแกน / ปิดก่อนยืนยันครบ / ไม่ปิดใน 5 นาที / คนในโซน (CCTV, latch) / บัตรไม่รู้จัก |
| **Mango** | รหัสวัสดุ/vendor จาก ERP เดิม (เช่น PG080010200800) ใช้เบิกไม่ได้แล้ว |
| **L1 / L2 / LLP / IC** | กลุ่มใหญ่ (3) / หมวด (2) / ตัวสินค้า (8) / รหัสวัสดุ 20 หลัก (5.1) · `000` = ไม่ระบุ |
| **Cat / Char** | CatID C01/C02/NAR (เส้นทางอนุมัติ) · CharID CSB/BRB/NAR/WMS (ประเภทการใช้) |
| **NAR** | ไม่เข้าเส้นทางอนุมัติ — เบิกผ่าน OD เท่านั้น · **WMS** = ดูยอดอย่างเดียว ไม่เข้าฟอร์มเบิก |
| **Flow Hub** | `Software/Data/LLP-Flowhub.xlsx` แม่บทเลข LLP ของจัดซื้อ (มติ 48) · **"สร้าง LLP.xlsx"** = ไฟล์ taxonomy ของจัดซื้อ |
| **buffer / push** | ของที่รับตาม PO แล้วแต่ยังไม่แปลงเป็น IC (หน่วยซื้อ) / การปล่อยจาก buffer เป็นใบ IN |
| **rate card / ราคาหักเงิน** | ราคาต่อหน่วยสำหรับหักเงินผู้รับเหมา ต่อไซต์ × วัสดุ |
| **คจช.** | ใช้ใน "หักค่าใช้จ่ายผู้รับเหมา คจช." (ค่าห้อง ค่าไฟ ร้านค้า ฯลฯ) — ตัวย่อไม่ได้ขยายในโค้ด |
| **SUB- / FS- / SE-** | เลขใบหักเงิน / ใบรับทราบค่าปรับสแกนนิ้ว / ใบหักคจช. (15) |
| **รับทราบโดยปริยาย (auto)** | ลายเซ็นผู้รับเหมาที่ถือว่ารับทราบเมื่อเลยกำหนดหลังส่งลิงก์ |
| **reconcile / jobtoken** | งานซ่อมใบที่ค้างระหว่าง gate log กับเอกสาร / HMAC รายชั่วโมงที่ใช้เรียก |
| **ensure-schema / marker** | ฟังก์ชันสร้างตาราง/คอลัมน์อัตโนมัติ + ไฟล์ `settings/.<name>-schema-vN-<db>` ที่บอกว่าทำแล้ว |
| **ARI / STX / HO / 0000** | เวีย อารีย์ (ไซต์นำร่อง) / สแตนดาร์ด เอ็กซ์ โฮเทล บางเทา ภูเก็ต (ใช้ทดสอบ TD) / สำนักงานใหญ่ / ส่วนกลาง (ไม่ระบุโครงการ) |
| **G01 / G02 / G03** | ประตู/ตู้ที่ ARI (G03 = outdoor ยังไม่มีตู้จริง) |

---

## ภาคผนวก ก. ดัชนีฟังก์ชัน RPC (`lib/registry.php` — 136 ชื่อ, เรียกผ่าน `google.script.run.<ชื่อ>` → `rpc_<ชื่อ>`)

| ไฟล์ใน `lib/` | ฟังก์ชัน |
|---|---|
| `auth_api.php` | checkLogin¹, getUserPermissions, changePassword, changeSite, getAvailableSites, getAppBuild¹, logoutServer |
| `directory.php` | getUserDirectory, getApproversList, getSubcontractsList, getMaterialsList, getMaterialsMainList, getGatesList, getBalanceList, getGateBalanceList, getMaterialBalance, getLegacyMangoStock |
| `documents.php` | processRequisitionSubmission, processOddsSubmission, processBorrowSubmission (เก่า), processBorrowBatch, processInboundBatch, getUnreturnedItems, returnBorrowItem (alias), registerReturnGateLog, getDocsCloseState, confirmReturnAtGate (ปิดอยู่) |
| `approval.php` | getApprovalRequests, updateApprovalStatus, cancelRequisition |
| `doc_revise.php` | reviseRequisition |
| `gate_api.php` | getApprovedDocuments, getAwaitingGateDocIds, getConfirmableGateSignature, getConfirmableDocuments, checkGateStatusForDoc, saveConfirmationData, getPickRoundState |
| `qr_sign.php` | getQrPayload |
| `pick_alerts.php` | getPickAlerts |
| `gate_health.php` | getGateHealth |
| `site_errors.php` | getSiteErrorLog |
| `borrow.php` | getBorrowWriteoffInfo, writeOffBorrowItems, generateBorrowLossPDF, getBorrowAlerts, getMyBorrowOverdue, getMyNotices, ackNotices |
| `transfer.php` | getTransferFormData, processTransferSubmission, getTransferList |
| `gatemove.php` | getGateMoveFormData, processGateMoveSubmission, getGateMoveList |
| `stockcount.php` | getStockCountData, createStockCount, getStockCountSheet, saveStockCount, getStockAdjustQueue, decideStockAdjust, rejectStockCount |
| `dispatch_rounds.php` | getDispatchRounds, saveDispatchRounds, getDispatchBoard |
| `inventory_insights.php` | getPopularMaterials, getMinMaxData, getMinMaxSettings, saveMinMax, saveMinMaxParams |
| `history_stats.php` | getRequisitionHistory, getChargeDashboard |
| `picking_history.php` | getPickingHistory |
| `dailycheck.php` | getDailyCheckData, setDailyCheckCharge, confirmDailyCheck, getRateCardData, saveRateCard, getSubMangoVendorData, saveSubMangoVendor |
| `pdf_api.php` | generateDocReportPDF, generateBalancePDF, generateDeductionPDF |
| `subsettings.php` | getSubSettingsData, saveSubSettings, addSubcontractor, renameSubcontractor, remapSubcontractor, addSubMangoOption, removeSubMangoOption, setSubMango, getSubMangoHistory, getSubcontractorSignature, saveSubcontractorSignature, closeSubcontractorSettle |
| `signatures.php` | getMySignature, saveMySignature, deleteMySignature, getMySignTasks, requestSignature, cancelSignRequest, submitSignature, getSignatureView, getSignDocDetail, getDeductionSignData, createContractorLink, setContractorSent, issueDeductionDocs, freezeDeductionRatesNow, getSignPageData¹, submitContractorSignature¹ |
| `fingerscan.php` | getFingerScanData, saveFingerScanDay, saveFingerScanRate, getFingerScanBadge, getFingerScanSummary, getFingerScanAlerts, saveFingerScanAlertNote, saveFingerScanAlertNotes, getFingerScanDashboard, verifyFingerScanDay, cancelFingerScanVerify, getFingerScanVerifyView, createFingerScanLink, createAllFingerScanLinks, setFingerScanSent, cancelFingerScanLink, cancelAllFingerScanLinks, generateFingerScanPDF |
| `subexpense.php` | getSubExpenseData, saveSubExpenseData, saveSubExpenseConfig, getSubExpenseSignData, requestSubExpenseSignature, cancelSubExpenseSignRequest, generateSubExpensePDF |

¹ ฟังก์ชันสาธารณะ (ไม่ต้อง login แต่ต้องมี CSRF) · ลงทะเบียนแต่ไม่มี client เรียก: getSubMangoVendorData, saveSubMangoVendor, returnBorrowItem, confirmReturnAtGate

ไลบรารีที่ไม่มี rpc_* (เรียกจากหน้า PHP เดี่ยว/JSON API/CLI): ic, ic_edit, ic_import, ic_mango, mango, mango_bal, llp_import, llp_align, po, po_ocr, gemini, setup_master, docnum, doc_ext, s05, stock, bypass, inbound_ctl, photos, pdf_engine, pdf_thai, history_report, app_settings, gate_sec, notify, jobs, registry, ui

## ภาคผนวก ข. ดัชนีไฟล์

**ราก `C:\xampp\htdocs\connext`**

| ไฟล์/โฟลเดอร์ | หน้าที่ |
|---|---|
| `config.php`, `helpers.php`, `auth.php` | bootstrap · helper/polyfill/ค่าคงที่ · session/CSRF/login |
| `index.php` | SPA หลัก (GAS) |
| `admin.php`, `bypass.php`, `ic_new.php`, `ic_edit.php`, `ic_list.php`, `llp_master.php`, `mango_master.php`, `mango_bal.php`, `setup_master.php`, `po.php`, `po_view.php`, `po_ocr_test.php`, `rc.php`, `sign.php` | หน้า PHP เดี่ยว (18.3) |
| `api/` | `rpc.php`, `gate.php`, `history_report.php`, `ic_api.php`, `rc_api.php`, `setup_master_api.php` |
| `lib/` | 60 ไลบรารี (ตารางด้านล่าง) |
| `js/`, `css/` | add-on (18.4), ธีม (18.5), `prompt.css`, `sarabun.css` |
| `pdf/templates/` (8), `pdf/fonts/`, `pdf/fontcache/`, `pdf/imgcache/` | PDF |
| `includes/dompdf/`, `includes/xlsx_lite.php` | ไลบรารีภายนอก |
| `db/` | `db_setup.sql`, `connext_schema.dbml/.drawio`, สคริปต์นำเข้า (4.6), `source_data/` (CSV จากชีต — มีรหัสผ่าน plaintext ควรลบ) |
| `settings/` | `config.php`, `database.php`, `gemini.php`, `qr_secret.php`, marker `.…-schema-*`, `.borrow-daily-*`, `.jobs-*`, `jobs.log` |
| `cron/jobs.php` | งานอัตโนมัติ (CLI) |
| `install/` | ตัวติดตั้ง step0–3, complete, `gemini.php.example` |
| `uploads/photos/YYYY-MM/`, `uploads/signatures/`, `uploads/po/Y-m/` | รูป ลายเซ็น PDF ใบสั่งซื้อ |
| `assets/`, `icons/`, `fonts/`, `vendor-assets/`, `manifest.json`, `sw.js` | ภาพแบรนด์ ไอคอน PWA ฟอนต์ ไลบรารี JS |
| `.htaccess`, `.dist-marker` | กติกา Apache · ข้อมูล build (2026-08-28, `make_dist.php`) |

**`lib/*.php`** (คำอธิบายจาก docblock ของไฟล์)

| ไฟล์ | หน้าที่ |
|---|---|
| `app_settings.php` | ค่าตั้งระบบที่ ADM แก้ได้จากหน้าเว็บ (ตาราง app_settings) |
| `approval.php` | คิวอนุมัติ + อนุมัติ/ปฏิเสธ + ยกเลิกเอกสาร |
| `auth_api.php` | RPC auth/บัญชี |
| `borrow.php` | ใบยืม (BD) ตาม Doc 05 ③ ⑦ ⑬ |
| `bypass.php` | คีย์ใบย้อนหลังจากแบบฟอร์มกระดาษ + bypass ประตู |
| `dailycheck.php` | ตรวจสอบประจำวัน + rate card |
| `directory.php` | ข้อมูลอ้างอิง (ผู้ใช้ ผู้อนุมัติ ชุด วัสดุ ประตู ยอด) |
| `dispatch_rounds.php` | รอบจ่ายรายไซต์ (มติ 50) |
| `doc_ext.php` | ส่วนกลางของ TD/SC (schema, label) |
| `doc_revise.php` | ตีกลับใบให้ผู้ขอแก้ยอด |
| `docnum.php` | เลขรันเอกสาร + PickingID |
| `documents.php` | ส่งเอกสารเบิก/ยืม/รับเข้า + วงจรยืม-คืน |
| `fingerscan.php` | บันทึกสแกนนิ้วรายวัน |
| `gate_api.php` | หน้า QR + polling ประตู + หน้าถ่ายรูปยืนยัน |
| `gate_health.php` | heartbeat ตู้ + สถานะอุปกรณ์ |
| `gate_sec.php` | ความปลอดภัยตู้ (key รายตู้, กรอง QR) |
| `gatemove.php` | TG ย้าย Gate |
| `gemini.php` | เรียก Gemini อ่าน PDF ใบสั่งซื้อ |
| `history_report.php` | รายงานประวัติหลายใบพร้อมรูป |
| `history_stats.php` | ประวัติเอกสาร + Dashboard สรุปค่าใช้จ่าย |
| `ic.php` | ประกอบ/ตรวจ/ออกรหัส IC 20 ตัว |
| `ic_edit.php` | แก้ไข/recode IC (มติ 46) |
| `ic_import.php` | ออก IC ทั้งก้อนจากไฟล์ (มติ 44) |
| `ic_mango.php` | ออก IC โดยตั้งต้นจาก Mango (มติ 47) |
| `inbound_ctl.php` | คุมการออกใบ IN (GP-10) |
| `inventory_insights.php` | วัสดุยอดนิยม + Min-Max |
| `jobs.php` | งานตั้งเวลา |
| `llp_align.php` | จัดเลข LLP ตาม Flow Hub (มติ 48) |
| `llp_import.php` | นำเข้า "สร้าง LLP.xlsx" |
| `mango.php` | ทะเบียน Mango |
| `mango_bal.php` | รายงาน Mango → IC |
| `notify.php` | แจ้งเตือน (ในแอป/Teams/SMTP) |
| `pdf_api.php` | RPC PDF 3 ตัว (ใบรายใบ ยอดคงเหลือ ใบหักเงิน) |
| `pdf_engine.php` | dompdf wrapper + helper รูปแบบ |
| `pdf_thai.php` | วางวรรณยุกต์ไทยใน PDF |
| `photos.php` | เก็บรูปถ่าย |
| `pick_alerts.php` | การ์ดเตือนบน Dashboard (Scenario 05) |
| `picking_history.php` | ประวัติราย Picking list |
| `po.php` | ใบคุม PO → รับ → buffer → push |
| `po_ocr.php` | ตรวจ/จัดประเภทผล OCR |
| `qr_sign.php` | รหัสตรวจสอบ QR |
| `registry.php` | ทะเบียน RPC |
| `s05.php` | ส่วนกลางของ Scenario 05 (schema, เหตุผล, timing, store check) |
| `setup_master.php` | ผูก Mango → LLP → IC + ย้ายยอด |
| `signatures.php` | ลายเซ็นกลาง + คำขอลงนาม + ใบหักเงิน |
| `site_errors.php` | Error log รายไซต์ |
| `stock.php` | stock engine |
| `stockcount.php` | SC นับสต๊อก |
| `subexpense.php` | หักค่าใช้จ่ายผู้รับเหมา คจช. |
| `subsettings.php` | ตั้งค่าผู้รับเหมา |
| `transfer.php` | TD โอนย้ายข้ามไซต์ |
| `ui.php` | โครงหน้าร่วมของโมดูล PHP เดี่ยว |

**นอกแอป**

| ที่ | เนื้อหา |
|---|---|
| `Software/connext-local/README-LOCAL.md` | บันทึกการติดตั้งและการเปลี่ยนแปลงหลัก |
| `Software/connext-local/patches/<date>-<name>/` | แพตช์ทั้งหมด (README, before/, tests, apply scripts) |
| `Software/connext-local/backup-connext-before-*.sql` | สำรองฐานข้อมูล |
| `Software/Data/LLP-Flowhub.xlsx`, `สร้าง LLP.xlsx`, `ผลจัดเลข LLP ตาม Flow Hub 2026-10-02.xlsx` | ไฟล์ข้อมูลหลักและผลจัดเลข |
| `Doc/05_CONNEXT_Scenario_การทำงานของระบบ.docx`, `Doc/CONNEXT_Gate_Test_Cases.xlsx` | สเปกพฤติกรรมและ test cases |
| `Pilot2/Hardware/connext_access.py`, `gate_local.json`, `CX_Backup/`, `GateController.drawio` | โปรแกรมตู้ ตั้งค่า สำรองจากตู้ แผนผังสาย |

## ภาคผนวก ค. คีย์ตั้งค่า

**`settings/config.php`:** `app_name` · `env` (development|production) · `installed_at` · `gate_api_key` (key กลางของตู้) · อ่านเพิ่มถ้ามี: `gate_strict_match` (ปริยายเปิด) · `allow_direct_return` (ปริยายปิด)
**`settings/database.php`:** `host` · `dbname` · `user` · `pass`
**`settings/gemini.php`:** `api_key` · `model` · `endpoint` · `timeout` · `connect_timeout` · `max_output_tokens` · `temperature` · `thinking_budget` · `max_pdf_bytes` · `sample_dir`
**`settings/qr_secret.php`:** คืนสตริง secret 64 hex (HMAC ของ QR และ job token)

**`app_settings` (ตาราง, แก้จาก admin.php):**

| key | ปริยาย | ความหมาย |
|---|---|---|
| `qr_require_code` | 0 (**จริง = 1**) | ตู้ต้องส่งรหัสตรวจสอบ QR |
| `gate_shared_key_ok` | 1 | ยังรับ key กลาง |
| `reconcile_every_min` | 5 | รอบงาน reconcile (0 = ปิด) |
| `sc_weekly_random` | 1 | สุ่มนับสต๊อกรายสัปดาห์ |
| `sc_weekly_plan` | (งานเขียน) | แผนสุ่มนับของสัปดาห์ (JSON) |
| `internal_base_url` | '' | URL loopback ของ cron (ว่าง = http://127.0.0.1 + APP_BASE) |
| `notify_teams_url`, `notify_email_to`, `smtp_host`, `smtp_port` (587), `smtp_secure` (tls), `smtp_user`, `smtp_pass`, `smtp_from` | '' | การแจ้งเตือนภายนอก (ปิดในโค้ด) |

**`gate_settings` (ต่อไซต์):** `pick_min_per_item` 3 · `pick_cap_min` 120 · `extend_min` 5 · `store_over_cap` 0
**`gate_local.json` (บนตู้):** `backend_url` · `api_key` · `site` · `gate` · `lock_failsafe` · `sensor_locked_level` · `door_sensor` · `door_closed_level` · `alarm4_kill_any_card` · `voice_please_close`
**ค่าคงที่ในโค้ด:** `HR_MAX_DOCS` 100 · `HR_MAX_PHOTOS` 300 · `IN_MAX_PHOTOS` 6 · `S05_MAX_OVER_PICK` 0 · `S05_PICK_REASONS` · `GH_OFFLINE_MIN` 5 · `FS_DEFAULT_RATE` 100 · `REMEMBER_DAYS` 30 · `VAT_RATE` 0.07 · `CNX_NOTIFY_EXTERNAL` false · login 5 ครั้ง/15 นาที · ลายเซ็น ≤ 45,000 ตัวอักษร · ยืม ≤ +366 วัน · bypass ≤ 60 วันย้อนหลัง ≤ 30 บรรทัด · TD/TG ≤ 60 บรรทัด · SC พบเพิ่ม ≤ 50

---

*จบเอกสาร — จัดทำ 2026-10-08 จากโค้ดและข้อมูลจริง ณ วันนั้น · เมื่อมีแพตช์ใหม่ให้ปรับบทที่ 20–21 และหัวข้อที่เกี่ยวข้อง*
