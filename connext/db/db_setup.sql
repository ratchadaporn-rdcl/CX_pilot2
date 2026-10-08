-- ============================================================
-- CONNEXT — db/db_setup.sql  (โครงสร้างฐานข้อมูล v1.x)
-- MySQL 5.7+ / MariaDB 10.4+ · utf8mb4 · InnoDB
--
-- *** ไฟล์นี้ถูกสร้างขึ้นใหม่ (reconstructed) ***
-- ไฟล์ db_setup.sql ต้นฉบับไม่ได้ถูกรวมมาใน connext-dist.zip
-- โครงสร้างในไฟล์นี้ประกอบจาก db/connext_schema.dbml (21 ตารางหลัก)
-- และจาก SQL ที่โค้ด PHP ใน lib/, api/, *.php ใช้จริง (ตารางที่เพิ่มภายหลัง:
-- sub_projects, sign_requests, finger_scan_*, sub_expense_*, IC/LLP, PO/buffer ฯลฯ)
-- ถ้ามีไฟล์ต้นฉบับ ให้นำมาแทนที่ไฟล์นี้ได้เลย
--
-- ทุกคำสั่งเป็น CREATE TABLE IF NOT EXISTS / INSERT IGNORE — รันซ้ำได้ (idempotent)
-- ห้ามใช้ ; ใน string และห้ามใช้ DELIMITER (ตัวติดตั้ง split ที่ ; นอก '...' เท่านั้น)
-- ============================================================

SET NAMES utf8mb4;

-- ============================================================
-- MASTER / มิติ
-- ============================================================

-- โครงการ/ไซต์ — เดิมชีต Sites
CREATE TABLE IF NOT EXISTS projects (
  id         INT NOT NULL AUTO_INCREMENT,
  code       VARCHAR(20)  NOT NULL,
  name       VARCHAR(150) NOT NULL,
  site_ref   VARCHAR(50)  NULL,
  status     ENUM('active','archived') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_projects_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- บทบาท — เดิมชีต Roles (sc/bs = สิทธิ์ตั้งค่าชุดผู้รับเหมา / ธุรการ-บัญชีไซต์)
CREATE TABLE IF NOT EXISTS roles (
  id              INT NOT NULL AUTO_INCREMENT,
  role_code       VARCHAR(20)  NOT NULL,
  name            VARCHAR(100) NOT NULL,
  level           TINYINT NOT NULL DEFAULT 1,
  can_req         TINYINT(1) NOT NULL DEFAULT 0,
  can_daily_check TINYINT(1) NOT NULL DEFAULT 0,
  sc              TINYINT(1) NOT NULL DEFAULT 0,
  bs              TINYINT(1) NOT NULL DEFAULT 0,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_code (role_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- พนักงาน — เดิมชีต Users
CREATE TABLE IF NOT EXISTS users (
  id             INT NOT NULL AUTO_INCREMENT,
  username       VARCHAR(100) NOT NULL,
  password       VARCHAR(255) NOT NULL,
  emp_code       VARCHAR(20)  NULL,
  full_name      VARCHAR(150) NOT NULL DEFAULT '',
  role_id        INT NOT NULL,
  project_id     INT NOT NULL,
  card_id        VARCHAR(50)  NULL,
  signature_path VARCHAR(255) NULL,
  status         ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  KEY idx_users_project (project_id),
  KEY idx_users_card (card_id),
  KEY idx_users_role (role_id),
  CONSTRAINT fk_users_role    FOREIGN KEY (role_id)    REFERENCES roles (id)    ON DELETE RESTRICT,
  CONSTRAINT fk_users_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ชุดผู้รับเหมา (login ได้) — เดิมชีต Subcontracts
-- ชุดเป็นของกลาง (sub_code ไม่ซ้ำทั้งระบบ) · การเปิดใช้ต่อโครงการอยู่ที่ sub_projects
CREATE TABLE IF NOT EXISTS subcontractors (
  id             INT NOT NULL AUTO_INCREMENT,
  sub_code       VARCHAR(10)  NOT NULL,
  password       VARCHAR(255) NOT NULL,
  name           VARCHAR(150) NOT NULL,
  status         ENUM('active','inactive') NOT NULL DEFAULT 'active',
  signature_path VARCHAR(255) NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sub_code (sub_code),
  KEY idx_sub_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ชุดผู้รับเหมา x โครงการ — เปิด/ปิดใช้ + ผลจับคู่ vendor Mango ต่อโครงการ
CREATE TABLE IF NOT EXISTS sub_projects (
  id                INT NOT NULL AUTO_INCREMENT,
  sub_id            INT NOT NULL,
  project_id        INT NOT NULL,
  enabled           TINYINT(1) NOT NULL DEFAULT 1,
  mango_vendor_code VARCHAR(50)  NULL,
  mango_vendor_name VARCHAR(150) NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sub_project (sub_id, project_id),
  KEY idx_sp_project (project_id),
  CONSTRAINT fk_sp_sub     FOREIGN KEY (sub_id)     REFERENCES subcontractors (id) ON DELETE CASCADE,
  CONSTRAINT fk_sp_project FOREIGN KEY (project_id) REFERENCES projects (id)       ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- วัสดุ master กลาง — code_type: mango = ทะเบียน MANGO (นำเข้าครั้งแรก) · ic = รหัส IC ที่ออกเอง
-- cat_id/char_id ที่นี่เป็น "สำเนา" — ต้นทางจริงอยู่ที่ llp_products (มติ 28-29) ระบบ cascade ให้แถว ic
--   ส่วนแถว mango ค่านี้ไม่มีผลแล้ว (มติ 34: รหัส Mango เบิก/ถือยอดไม่ได้)
-- การผูกรหัส Mango → IC อยู่ตาราง mango_ic_map (มติ 40/41) ไม่ใช่คอลัมน์ในตารางนี้
CREATE TABLE IF NOT EXISTS materials (
  id            INT NOT NULL AUTO_INCREMENT,
  mat_code      VARCHAR(50)  NOT NULL,
  code_type     ENUM('mango','ic') NOT NULL DEFAULT 'mango',
  name          VARCHAR(255) NOT NULL,
  unit          VARCHAR(50)  NOT NULL DEFAULT '',
  cat_id        VARCHAR(10)  NOT NULL DEFAULT 'C02',
  char_id       VARCHAR(10)  NULL,
  subgroup_name VARCHAR(150) NULL,
  item_photo    VARCHAR(500) NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_materials_code (mat_code),
  KEY idx_materials_cat (cat_id),
  KEY idx_materials_char (char_id),
  KEY idx_materials_subgroup (subgroup_name),
  KEY idx_materials_type (code_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ประตูคลังต่อโครงการ — เดิม Gates
CREATE TABLE IF NOT EXISTS gates (
  id             INT NOT NULL AUTO_INCREMENT,
  gate_code      VARCHAR(20)  NOT NULL,
  name           VARCHAR(150) NOT NULL DEFAULT '',
  project_id     INT NOT NULL,
  hardware_close TINYINT(1) NOT NULL DEFAULT 0,
  status         ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_gate_project (gate_code, project_id),
  KEY idx_gates_project (project_id),
  CONSTRAINT fk_gates_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ผู้ขายจากระบบ MANGO — เดิม MangoVendors
CREATE TABLE IF NOT EXISTS mango_vendors (
  id          INT NOT NULL AUTO_INCREMENT,
  vendor_code VARCHAR(50)  NOT NULL,
  vendor_name VARCHAR(150) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_vendor_code (vendor_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TRANSACTIONAL
-- ============================================================

-- วัสดุที่มีในโครงการ + ประตูจ่าย — เดิม SiteMaterials
CREATE TABLE IF NOT EXISTS project_materials (
  id          INT NOT NULL AUTO_INCREMENT,
  project_id  INT NOT NULL,
  material_id INT NOT NULL,
  gate_id     INT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pm (project_id, material_id),
  KEY idx_pm_material (material_id),
  KEY idx_pm_gate (gate_id),
  CONSTRAINT fk_pm_project  FOREIGN KEY (project_id)  REFERENCES projects (id)  ON DELETE CASCADE,
  CONSTRAINT fk_pm_material FOREIGN KEY (material_id) REFERENCES materials (id) ON DELETE CASCADE,
  CONSTRAINT fk_pm_gate     FOREIGN KEY (gate_id)     REFERENCES gates (id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ยอดสต๊อกต่อวัสดุต่อโครงการ — เดิม Balance
CREATE TABLE IF NOT EXISTS stock_balances (
  id          INT NOT NULL AUTO_INCREMENT,
  project_id  INT NOT NULL,
  material_id INT NOT NULL,
  qty_in      DECIMAL(14,3) NOT NULL DEFAULT 0,
  qty_out     DECIMAL(14,3) NOT NULL DEFAULT 0,
  on_hand     DECIMAL(14,3) NOT NULL DEFAULT 0,
  pending     DECIMAL(14,3) NOT NULL DEFAULT 0,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_balance (project_id, material_id),
  KEY idx_sb_material (material_id),
  CONSTRAINT fk_sb_project  FOREIGN KEY (project_id)  REFERENCES projects (id)  ON DELETE CASCADE,
  CONSTRAINT fk_sb_material FOREIGN KEY (material_id) REFERENCES materials (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ยอดสต๊อกแยกตามประตู (project x material x gate)
CREATE TABLE IF NOT EXISTS stock_gate_balances (
  id          INT NOT NULL AUTO_INCREMENT,
  project_id  INT NOT NULL,
  material_id INT NOT NULL,
  gate_id     INT NOT NULL,
  qty_in      DECIMAL(14,3) NOT NULL DEFAULT 0,
  qty_out     DECIMAL(14,3) NOT NULL DEFAULT 0,
  on_hand     DECIMAL(14,3) NOT NULL DEFAULT 0,
  pending     DECIMAL(14,3) NOT NULL DEFAULT 0,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_gate_balance (project_id, material_id, gate_id),
  KEY idx_sgb_material (material_id),
  KEY idx_sgb_gate (gate_id),
  CONSTRAINT fk_sgb_project  FOREIGN KEY (project_id)  REFERENCES projects (id)  ON DELETE CASCADE,
  CONSTRAINT fk_sgb_material FOREIGN KEY (material_id) REFERENCES materials (id) ON DELETE RESTRICT,
  CONSTRAINT fk_sgb_gate     FOREIGN KEY (gate_id)     REFERENCES gates (id)     ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- หัวเอกสาร 4 ชนิด (RD/OD/BD/IN) — เดิม RequisitionLogs/OddsLogs/Borrow_Return/InboundLogs
-- origin_type/origin_ref = ธงที่มา (เช่น po = ออกจาก buffer ใบสั่งซื้อ)
CREATE TABLE IF NOT EXISTS documents (
  id                 INT NOT NULL AUTO_INCREMENT,
  doc_no             VARCHAR(30)  NOT NULL,
  doc_type           ENUM('RD','OD','BD','IN','TD','SC') NOT NULL,   -- TD เบิกโอนย้ายข้ามไซต์ · SC ใบนับสต๊อก (2026-09-29)
  project_id         INT NOT NULL,
  requester_username VARCHAR(100) NOT NULL,
  receiver_name      VARCHAR(150) NULL,
  receiver_sub_id    INT NULL,
  gate_id            INT NULL,
  usage_area         VARCHAR(150) NULL,
  notice             TEXT NULL,
  approver_username  VARCHAR(100) NULL,
  approved_by        VARCHAR(100) NULL,
  status             VARCHAR(30)  NOT NULL,
  rs_no              VARCHAR(50)  NULL,
  photo_url          TEXT NULL,
  photo_return_url   TEXT NULL,
  origin_type        VARCHAR(10)  NULL,
  origin_ref         VARCHAR(30)  NULL,
  doc_ts             DATETIME NOT NULL,
  return_ts          DATETIME NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_doc_no (doc_no),
  KEY idx_doc_proj_type_ts (project_id, doc_type, doc_ts),
  KEY idx_doc_proj_status (project_id, status),
  KEY idx_doc_proj_requester (project_id, requester_username, doc_ts),
  KEY idx_doc_requester (requester_username),
  KEY idx_doc_receiver (receiver_name),
  KEY idx_doc_receiver_sub (receiver_sub_id),
  KEY idx_doc_gate (gate_id),
  KEY idx_doc_ts (doc_ts),
  KEY idx_doc_origin (origin_ref),
  CONSTRAINT fk_doc_project FOREIGN KEY (project_id)      REFERENCES projects (id)       ON DELETE RESTRICT,
  CONSTRAINT fk_doc_sub     FOREIGN KEY (receiver_sub_id) REFERENCES subcontractors (id) ON DELETE SET NULL,
  CONSTRAINT fk_doc_gate    FOREIGN KEY (gate_id)         REFERENCES gates (id)          ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- รายการวัสดุในเอกสาร
CREATE TABLE IF NOT EXISTS document_items (
  id             INT NOT NULL AUTO_INCREMENT,
  document_id    INT NOT NULL,
  material_id    INT NULL,
  mat_code       VARCHAR(50)  NOT NULL,
  mat_name       VARCHAR(255) NOT NULL DEFAULT '',
  unit           VARCHAR(50)  NOT NULL DEFAULT '',
  qty            DECIMAL(14,3) NOT NULL DEFAULT 0,
  usage_area     VARCHAR(150) NULL,
  notice         TEXT NULL,
  rs_no          VARCHAR(50)  NULL,
  charge_money   TINYINT(1) NOT NULL DEFAULT 0,
  stock_deducted TINYINT(1) NOT NULL DEFAULT 0,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_di_document (document_id),
  KEY idx_di_material (material_id),
  KEY idx_di_mat_code (mat_code),
  KEY idx_di_charge (charge_money),
  CONSTRAINT fk_di_document FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE CASCADE,
  CONSTRAINT fk_di_material FOREIGN KEY (material_id) REFERENCES materials (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- สถานะเอกสารที่ประตูคลัง — เดิม GateLogs (1 แถวต่อเอกสาร, RT = ขาคืน)
CREATE TABLE IF NOT EXISTS gate_logs (
  id          INT NOT NULL AUTO_INCREMENT,
  project_id  INT NOT NULL,
  doc_no      VARCHAR(35) NOT NULL,
  document_id INT NULL,
  leg         ENUM('out','return') NOT NULL DEFAULT 'out',
  gate_id     INT NULL,
  picking_id  VARCHAR(20) NULL,
  card_id     VARCHAR(50) NULL,
  scanned_at  DATETIME NULL,
  status      VARCHAR(20) NOT NULL DEFAULT 'Awaiting',
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_gl_doc_no (doc_no),
  KEY idx_gl_proj_status (project_id, status),
  KEY idx_gl_picking (picking_id),
  KEY idx_gl_document (document_id),
  KEY idx_gl_gate (gate_id),
  CONSTRAINT fk_gl_project  FOREIGN KEY (project_id)  REFERENCES projects (id)  ON DELETE CASCADE,
  CONSTRAINT fk_gl_document FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE CASCADE,
  CONSTRAINT fk_gl_gate     FOREIGN KEY (gate_id)     REFERENCES gates (id)     ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ราคาหักเงินต่อวัสดุต่อโครงการ — เดิม RateCard
CREATE TABLE IF NOT EXISTS rate_cards (
  id          INT NOT NULL AUTO_INCREMENT,
  project_id  INT NOT NULL,
  material_id INT NOT NULL,
  unit_price  DECIMAL(12,4) NULL,
  updated_by  VARCHAR(100) NULL,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rate (project_id, material_id),
  KEY idx_rc_material (material_id),
  CONSTRAINT fk_rc_project  FOREIGN KEY (project_id)  REFERENCES projects (id)  ON DELETE CASCADE,
  CONSTRAINT fk_rc_material FOREIGN KEY (material_id) REFERENCES materials (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ยืนยันการตรวจประจำวัน — เดิม DailyCheck
CREATE TABLE IF NOT EXISTS daily_check_confirms (
  id           INT NOT NULL AUTO_INCREMENT,
  project_id   INT NOT NULL,
  check_date   DATE NOT NULL,
  confirmed_by VARCHAR(100) NOT NULL,
  confirmed_at DATETIME NOT NULL,
  item_count   INT NOT NULL DEFAULT 0,
  notes        VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dc (project_id, check_date),
  CONSTRAINT fk_dc_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ทะเบียนเลขที่ใบหักเงิน — เดิม DeductionDocs
CREATE TABLE IF NOT EXISTS deduction_docs (
  id         INT NOT NULL AUTO_INCREMENT,
  doc_no     VARCHAR(40)  NOT NULL,
  project_id INT NOT NULL,
  ym         CHAR(7)      NOT NULL,
  running_no INT NOT NULL,
  sub_id     INT NULL,
  sub_name   VARCHAR(150) NOT NULL,
  days_label VARCHAR(255) NOT NULL DEFAULT '',
  days_json  TEXT NULL,
  item_count INT NOT NULL DEFAULT 0,
  issued_by  VARCHAR(100) NOT NULL,
  issued_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ded_doc_no (doc_no),
  UNIQUE KEY uq_ded_run (project_id, ym, running_no),
  KEY idx_ded_lookup (project_id, ym, sub_name),
  KEY idx_ded_sub (sub_id),
  CONSTRAINT fk_ded_project FOREIGN KEY (project_id) REFERENCES projects (id)       ON DELETE RESTRICT,
  CONSTRAINT fk_ded_sub     FOREIGN KEY (sub_id)     REFERENCES subcontractors (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ราคาที่ตรึงไว้ต่อใบหักเงิน (frozen rate ต่อ doc_no x mat_code)
CREATE TABLE IF NOT EXISTS deduction_doc_rates (
  id         INT NOT NULL AUTO_INCREMENT,
  doc_no     VARCHAR(40)  NOT NULL,
  mat_code   VARCHAR(50)  NOT NULL,
  project_id INT NOT NULL,
  ym         CHAR(7)      NOT NULL,
  sub_name   VARCHAR(150) NOT NULL DEFAULT '',
  unit_price DECIMAL(12,4) NULL,
  frozen_by  VARCHAR(100) NULL,
  frozen_at  DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ddr (doc_no, mat_code),
  KEY idx_ddr_project (project_id, ym),
  CONSTRAINT fk_ddr_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- LOOKUP / COUNTER
-- ============================================================

-- เลขรันประจำวันต่อชนิดเอกสาร (RD/OD/BD/IN/PK)
CREATE TABLE IF NOT EXISTS doc_counters (
  counter_type VARCHAR(10) NOT NULL,
  date_key     CHAR(6)     NOT NULL,
  last_no      INT NOT NULL DEFAULT 0,
  PRIMARY KEY (counter_type, date_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ตัวเลือก vendor ที่อนุญาตต่อผู้รับเหมา — เดิม SubMangoMap
CREATE TABLE IF NOT EXISTS sub_mango_map (
  id          INT NOT NULL AUTO_INCREMENT,
  sub_code    VARCHAR(10)  NOT NULL,
  vendor_code VARCHAR(50)  NOT NULL,
  vendor_name VARCHAR(150) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_smm (sub_code, vendor_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ประวัติการจับคู่ Mango ของชุดผู้รับเหมา
CREATE TABLE IF NOT EXISTS sub_mango_history (
  id         INT NOT NULL AUTO_INCREMENT,
  sub_id     INT NOT NULL,
  project_id INT NOT NULL,
  sub_name   VARCHAR(150) NOT NULL DEFAULT '',
  action     VARCHAR(30)  NOT NULL,
  from_code  VARCHAR(50)  NULL,
  from_name  VARCHAR(150) NULL,
  to_code    VARCHAR(50)  NULL,
  to_name    VARCHAR(150) NULL,
  changed_by VARCHAR(150) NULL,
  changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_smh_lookup (project_id, sub_id),
  CONSTRAINT fk_smh_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ประวัติการโอน/เปลี่ยนชื่อชุดผู้รับเหมา (remap)
CREATE TABLE IF NOT EXISTS remap_logs (
  id            INT NOT NULL AUTO_INCREMENT,
  project_id    INT NOT NULL,
  from_sub_id   INT NULL,
  from_sub_name VARCHAR(150) NOT NULL DEFAULT '',
  to_sub_id     INT NULL,
  to_sub_name   VARCHAR(150) NOT NULL DEFAULT '',
  borrow_moved  INT NOT NULL DEFAULT 0,
  charge_moved  INT NOT NULL DEFAULT 0,
  se_skipped    INT NOT NULL DEFAULT 0,
  changed_by    VARCHAR(150) NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_remap_project (project_id),
  CONSTRAINT fk_remap_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ทะเบียนการปิดชุดผู้รับเหมา
CREATE TABLE IF NOT EXISTS sub_close_logs (
  id               INT NOT NULL AUTO_INCREMENT,
  project_id       INT NOT NULL,
  sub_id           INT NULL,
  sub_name         VARCHAR(150) NOT NULL DEFAULT '',
  docs_issued      INT NOT NULL DEFAULT 0,
  docs_already     INT NOT NULL DEFAULT 0,
  periods_issued   VARCHAR(255) NOT NULL DEFAULT '',
  se_period        VARCHAR(12)  NULL,
  face_scan_fine   DECIMAL(12,2) NOT NULL DEFAULT 0,
  material_amt     DECIMAL(12,2) NOT NULL DEFAULT 0,
  borrow_open_left INT NOT NULL DEFAULT 0,
  changed_by       VARCHAR(150) NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_scl_project (project_id, sub_id),
  CONSTRAINT fk_scl_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SYSTEM / AUDIT
-- ============================================================

-- log จาก gate controller — เดิม ErrorLogs
CREATE TABLE IF NOT EXISTS error_logs (
  id         INT NOT NULL AUTO_INCREMENT,
  project_id INT NULL,
  gate_code  VARCHAR(20) NULL,
  message    TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_err_time (created_at),
  KEY idx_err_project (project_id),
  CONSTRAINT fk_err_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- audit กลาง (รวม UserLogs เดิม + เหตุการณ์เอกสาร/สต๊อก)
CREATE TABLE IF NOT EXISTS activity_log (
  id          INT NOT NULL AUTO_INCREMENT,
  entity_type VARCHAR(30)  NOT NULL,
  entity_id   VARCHAR(40)  NOT NULL,
  user_name   VARCHAR(100) NULL,
  action      VARCHAR(50)  NOT NULL,
  old_value   TEXT NULL,
  new_value   TEXT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_al_entity (entity_type, entity_id),
  KEY idx_al_user_time (user_name, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- rate-limit login (5 ครั้ง / 15 นาที)
CREATE TABLE IF NOT EXISTS login_attempts (
  id           INT NOT NULL AUTO_INCREMENT,
  ip_address   VARCHAR(45)  NOT NULL,
  username     VARCHAR(150) NULL,
  attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ip_time (ip_address, attempted_at),
  KEY idx_user_time (username, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- จดจำอัตโนมัติ 30 วัน (selector + validator)
CREATE TABLE IF NOT EXISTS remember_tokens (
  id             INT NOT NULL AUTO_INCREMENT,
  account_type   ENUM('user','subcontractor') NOT NULL,
  account_id     INT NOT NULL,
  selector       CHAR(24) NOT NULL,
  validator_hash CHAR(64) NOT NULL,
  expires_at     DATETIME NOT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rt_selector (selector),
  KEY idx_rt_account (account_type, account_id),
  KEY idx_rt_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- สแกนนิ้ว / ลายเซ็น / หักค่าใช้จ่ายผู้รับเหมา
-- ============================================================

-- บันทึกสแกนนิ้วรายวันต่อชุดผู้รับเหมา — เดิมชีต FingerScan
CREATE TABLE IF NOT EXISTS finger_scan_logs (
  id                  INT NOT NULL AUTO_INCREMENT,
  scan_date           DATE NOT NULL,
  project_id          INT NOT NULL,
  sub_id              INT NULL,
  sub_name            VARCHAR(150) NOT NULL DEFAULT '',
  worker_count        DECIMAL(8,2) NULL,
  scan_count          DECIMAL(8,2) NULL,
  no_report           TINYINT(1) NOT NULL DEFAULT 0,
  system_worker_count DECIMAL(8,2) NULL,
  fine_amt            DECIMAL(12,2) NULL,
  note                VARCHAR(500) NULL,
  scan_exceed_note    VARCHAR(500) NULL,
  scan_exceed_by      VARCHAR(100) NULL,
  scan_exceed_at      DATETIME NULL,
  recorded_by         VARCHAR(100) NULL,
  recorded_at         DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_fsl_proj_date (project_id, scan_date),
  KEY idx_fsl_proj_sub (project_id, sub_name),
  KEY idx_fsl_sub (sub_id),
  CONSTRAINT fk_fsl_project FOREIGN KEY (project_id) REFERENCES projects (id)       ON DELETE CASCADE,
  CONSTRAINT fk_fsl_sub     FOREIGN KEY (sub_id)     REFERENCES subcontractors (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ยืนยันการตรวจสแกนนิ้วรายวัน (BS ตรวจ + เซ็น)
CREATE TABLE IF NOT EXISTS finger_scan_verify (
  id             INT NOT NULL AUTO_INCREMENT,
  scan_date      DATE NOT NULL,
  project_id     INT NOT NULL,
  verified_by    VARCHAR(100) NOT NULL DEFAULT '',
  verified_at    DATETIME NULL,
  signature_path VARCHAR(255) NULL,
  note           VARCHAR(500) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fsv (project_id, scan_date),
  CONSTRAINT fk_fsv_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ค่าตั้งสแกนนิ้วต่อโครงการ (เช่น rate = ค่าปรับต่อคน)
CREATE TABLE IF NOT EXISTS finger_scan_config (
  id         INT NOT NULL AUTO_INCREMENT,
  project_id INT NOT NULL,
  cfg_key    VARCHAR(30)  NOT NULL,
  cfg_value  VARCHAR(100) NOT NULL DEFAULT '',
  updated_by VARCHAR(100) NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fsc (project_id, cfg_key),
  CONSTRAINT fk_fsc_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- คำขอลายเซ็น (contractor = ผู้รับเหมาเซ็นผ่านลิงก์ token · inspector/approver = พนักงาน)
CREATE TABLE IF NOT EXISTS sign_requests (
  id             INT NOT NULL AUTO_INCREMENT,
  doc_no         VARCHAR(60)  NOT NULL,
  project_id     INT NOT NULL,
  ym             CHAR(7)      NOT NULL,
  sub_name       VARCHAR(150) NOT NULL DEFAULT '',
  days_label     VARCHAR(255) NOT NULL DEFAULT '',
  role           VARCHAR(20)  NOT NULL,
  assignee       VARCHAR(100) NULL,
  assignee_pos   VARCHAR(150) NULL,
  token          VARCHAR(64)  NULL,
  status         VARCHAR(20)  NOT NULL DEFAULT 'pending',
  deadline_days  DECIMAL(6,2) NULL,
  sent_at        DATETIME NULL,
  signer_name    VARCHAR(150) NULL,
  signer_pos     VARCHAR(150) NULL,
  signature_path VARCHAR(255) NULL,
  signed_at      DATETIME NULL,
  note           VARCHAR(500) NULL,
  requested_by   VARCHAR(100) NULL,
  requested_at   DATETIME NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sr_doc_role (doc_no, role),
  UNIQUE KEY uq_sr_token (token),
  KEY idx_sr_proj_status (project_id, status),
  KEY idx_sr_assignee (assignee, status),
  CONSTRAINT fk_sr_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- แถวหักค่าใช้จ่ายผู้รับเหมาต่องวดครึ่งเดือน (period_key = yyyy-MM/H1|H2)
CREATE TABLE IF NOT EXISTS sub_expense_rows (
  id              INT NOT NULL AUTO_INCREMENT,
  project_id      INT NOT NULL,
  period_key      VARCHAR(12)  NOT NULL,
  sub_name        VARCHAR(150) NOT NULL,
  room_qty        DECIMAL(12,2) NULL,
  room_rate       DECIMAL(12,2) NULL,
  room_amt        DECIMAL(12,2) NULL,
  elec_used       DECIMAL(12,2) NULL,
  elec_over       DECIMAL(12,2) NULL,
  elec_amt        DECIMAL(12,2) NULL,
  shop_qty        DECIMAL(12,2) NULL,
  shop_rate       DECIMAL(12,2) NULL,
  shop_amt        DECIMAL(12,2) NULL,
  shop_items      TEXT NULL,
  shop_elec_prev  DECIMAL(12,2) NULL,
  shop_elec_curr  DECIMAL(12,2) NULL,
  shop_elec_units DECIMAL(12,2) NULL,
  shop_elec_amt   DECIMAL(12,2) NULL,
  material_amt    DECIMAL(12,2) NULL,
  face_scan_fine  DECIMAL(12,2) NULL,
  advance_amt     DECIMAL(12,2) NULL,
  safety_fine     DECIMAL(12,2) NULL,
  total_amt       DECIMAL(12,2) NULL,
  outside_stay    TINYINT(1) NOT NULL DEFAULT 0,
  note            VARCHAR(500) NULL,
  updated_by      VARCHAR(100) NULL,
  updated_at      DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ser (project_id, period_key, sub_name),
  CONSTRAINT fk_ser_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- อัตรา/รายการร้านค้าของหน้าหักค่าใช้จ่าย (kind = rate | shopItem)
CREATE TABLE IF NOT EXISTS sub_expense_config (
  id         INT NOT NULL AUTO_INCREMENT,
  project_id INT NOT NULL,
  kind       VARCHAR(20)  NOT NULL,
  cfg_key    VARCHAR(30)  NOT NULL,
  label      VARCHAR(150) NOT NULL DEFAULT '',
  value      DECIMAL(12,2) NOT NULL DEFAULT 0,
  updated_by VARCHAR(100) NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sec (project_id, kind, cfg_key),
  CONSTRAINT fk_sec_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- รหัส IC (บันได LLP) — ic_code(20) = llp(8) + size(3) + brand(3) + unit(3) + extra(3)
-- ============================================================

-- กลุ่มใหญ่ (L1) 3 หลัก
CREATE TABLE IF NOT EXISTS l1_groups (
  l1_code    CHAR(3)      NOT NULL,
  l1_name    VARCHAR(150) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (l1_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- หมวด (L2) 2 หลัก ใต้ L1
CREATE TABLE IF NOT EXISTS l2_categories (
  l1_code   CHAR(3)      NOT NULL,
  l2_code   CHAR(2)      NOT NULL,
  l2_name   VARCHAR(150) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (l1_code, l2_code),
  CONSTRAINT fk_l2_l1 FOREIGN KEY (l1_code) REFERENCES l1_groups (l1_code) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- พจนานุกรมกลาง: ขนาด / ยี่ห้อ / หน่วย (รหัส 3 หลัก, 000 = ไม่ระบุ)
CREATE TABLE IF NOT EXISTS sizes (
  size_code CHAR(3)      NOT NULL,
  size_name VARCHAR(100) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (size_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS brands (
  brand_code CHAR(3)      NOT NULL,
  brand_name VARCHAR(100) NOT NULL,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (brand_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS units (
  unit_code CHAR(3)     NOT NULL,
  unit_name VARCHAR(50) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (unit_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ขนาด/ยี่ห้อที่อนุญาตต่อหมวด (l1,l2)
CREATE TABLE IF NOT EXISTS l2_sizes (
  l1_code   CHAR(3) NOT NULL,
  l2_code   CHAR(2) NOT NULL,
  size_code CHAR(3) NOT NULL,
  PRIMARY KEY (l1_code, l2_code, size_code),
  KEY idx_l2s_size (size_code),
  CONSTRAINT fk_l2s_l2   FOREIGN KEY (l1_code, l2_code) REFERENCES l2_categories (l1_code, l2_code) ON DELETE CASCADE,
  CONSTRAINT fk_l2s_size FOREIGN KEY (size_code)        REFERENCES sizes (size_code)                ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS l2_brands (
  l1_code    CHAR(3) NOT NULL,
  l2_code    CHAR(2) NOT NULL,
  brand_code CHAR(3) NOT NULL,
  PRIMARY KEY (l1_code, l2_code, brand_code),
  KEY idx_l2b_brand (brand_code),
  CONSTRAINT fk_l2b_l2    FOREIGN KEY (l1_code, l2_code) REFERENCES l2_categories (l1_code, l2_code) ON DELETE CASCADE,
  CONSTRAINT fk_l2b_brand FOREIGN KEY (brand_code)       REFERENCES brands (brand_code)              ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ตัวสินค้า (LLP) llp_code(8) = l1(3) + l2(2) + product(3) · cat_id/char_id ตั้งที่ระดับนี้
CREATE TABLE IF NOT EXISTS llp_products (
  llp_code     CHAR(8)      NOT NULL,
  l1_code      CHAR(3)      NOT NULL,
  l2_code      CHAR(2)      NOT NULL,
  product_code CHAR(3)      NOT NULL,
  llp_name     VARCHAR(255) NOT NULL,
  cat_id       VARCHAR(10)  NULL,
  char_id      VARCHAR(10)  NULL,
  charcat_by   INT NULL,
  charcat_at   DATETIME NULL,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  created_by   INT NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (llp_code),
  UNIQUE KEY uq_llp_parts (l1_code, l2_code, product_code),
  KEY idx_llp_name (llp_name),
  CONSTRAINT fk_llp_l2 FOREIGN KEY (l1_code, l2_code) REFERENCES l2_categories (l1_code, l2_code) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- คุณสมบัติเพิ่มของตัวสินค้า (extra 3 หลัก ต่อ llp)
CREATE TABLE IF NOT EXISTS extra_attrs (
  llp_code   CHAR(8)      NOT NULL,
  extra_code CHAR(3)      NOT NULL,
  extra_name VARCHAR(150) NOT NULL,
  PRIMARY KEY (llp_code, extra_code),
  CONSTRAINT fk_extra_llp FOREIGN KEY (llp_code) REFERENCES llp_products (llp_code) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- รหัส IC ที่ออกแล้ว (มีแถวคู่ใน materials code_type = ic เสมอ)
-- has_serial = ของนับเป็นชิ้นและมี Serial Number (ตู้ MDB / เครื่องจักร)
-- is_cx      = อยู่ในระบบเบิกจ่าย CX · ปิด = วัสดุ WC (คลังกลางจ่ายแล้วจบ ไซต์ไม่รับเข้า)
--   ⚠ ธง 2 ตัวนี้ (มติ 43) เก็บค่าอย่างเดียว — ยังไม่มีสายรับ/เบิก/ประตูไหนอ่านไปใช้
CREATE TABLE IF NOT EXISTS ic_items (
  ic_code            CHAR(20)     NOT NULL,
  llp_code           CHAR(8)      NOT NULL,
  l1_code            CHAR(3)      NOT NULL,
  l2_code            CHAR(2)      NOT NULL,
  size_code          CHAR(3)      NOT NULL DEFAULT '000',
  brand_code         CHAR(3)      NOT NULL DEFAULT '000',
  unit_code          CHAR(3)      NOT NULL,
  extra_code         CHAR(3)      NOT NULL DEFAULT '000',
  ic_name            VARCHAR(255) NOT NULL,
  cat_id             VARCHAR(10)  NULL,
  char_id            VARCHAR(10)  NULL,
  is_active          TINYINT(1) NOT NULL DEFAULT 1,
  has_serial         TINYINT(1) NOT NULL DEFAULT 0,
  is_cx              TINYINT(1) NOT NULL DEFAULT 1,
  created_project_id INT NULL,
  created_by         INT NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (ic_code),
  KEY idx_ic_llp (llp_code),
  KEY idx_ic_created (created_at),
  KEY idx_ic_unit (unit_code),
  CONSTRAINT fk_ic_llp  FOREIGN KEY (llp_code)  REFERENCES llp_products (llp_code) ON DELETE RESTRICT,
  CONSTRAINT fk_ic_unit FOREIGN KEY (unit_code) REFERENCES units (unit_code)       ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ความจำการจับคู่ (ผู้ขาย + รหัส Mango) → IC ที่คนเคยเลือก
CREATE TABLE IF NOT EXISTS ic_suggest_map (
  id         INT NOT NULL AUTO_INCREMENT,
  vendor_key VARCHAR(120) NOT NULL,
  mat_code   VARCHAR(50)  NOT NULL,
  ic_code    CHAR(20)     NOT NULL,
  hit_count  INT NOT NULL DEFAULT 1,
  last_used  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ism (vendor_key, mat_code, ic_code),
  KEY idx_ism_mat (mat_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- การผูกรหัส Mango → LLP → IC แบบ master (มติ 40-42 — หน้า setup_master.php)
-- 1 แถวต่อ (Mango × LLP × IC) · ic_code = '' แปลว่ายังอยู่ขั้น 1 (ผูกตัวสินค้าแล้วแต่ยังไม่ออกรหัส IC)
-- 1 Mango มี LLP ได้ตัวเดียว (มติ 45) · 1 LLP มีหลาย Mango/หลาย IC · 1 Mango มีหลาย IC ได้ใต้ LLP ของมัน
-- is_primary = แถวหลัก (รับบรรทัดเอกสารเดิม/ราคาที่ล็อก) · กติกาบังคับที่ชั้น PHP (smMapLlp/smAttachIc)
-- ข้อมูลตั้งต้นนำเข้าจากไฟล์ "สร้าง LLP.xlsx" ของฝ่ายจัดซื้อ (db/import_llp_master.php)
-- ไม่ผูก FK ไป materials/llp_products/ic_items โดยตั้งใจ — PHP ตรวจให้ (เหมือน ic_suggest_map / push_allocs)
CREATE TABLE IF NOT EXISTS mango_ic_map (
  id         INT NOT NULL AUTO_INCREMENT,
  mat_code   VARCHAR(50) NOT NULL,
  llp_code   CHAR(8)     NOT NULL DEFAULT '',
  ic_code    CHAR(20)    NOT NULL DEFAULT '',
  is_primary TINYINT(1)  NOT NULL DEFAULT 0,
  mapped_by  INT NULL,
  mapped_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mim (mat_code, llp_code, ic_code),
  KEY idx_mim_llp (llp_code),
  KEY idx_mim_ic (ic_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- ใบสั่งซื้อ (PO) → OCR → รับของ → buffer → push เข้า gate
-- ============================================================

-- log การอ่านใบสั่งซื้อด้วย OCR (Gemini)
CREATE TABLE IF NOT EXISTS ocr_logs (
  log_id     INT NOT NULL AUTO_INCREMENT,
  po_no      VARCHAR(30)  NULL,
  source     VARCHAR(20)  NOT NULL DEFAULT 'GEMINI',
  model      VARCHAR(100) NULL,
  status     VARCHAR(20)  NOT NULL DEFAULT 'failed',
  file_name  VARCHAR(255) NULL,
  file_path  VARCHAR(255) NULL,
  payload    LONGTEXT NULL,
  raw        LONGTEXT NULL,
  error_note VARCHAR(500) NULL,
  ms         INT NOT NULL DEFAULT 0,
  tokens     INT NOT NULL DEFAULT 0,
  project_id INT NULL,
  created_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (log_id),
  KEY idx_ocr_po (po_no),
  KEY idx_ocr_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- หัวใบสั่งซื้อ (ใบคุม)
CREATE TABLE IF NOT EXISTS po_headers (
  po_no            VARCHAR(30)  NOT NULL,
  project_id       INT NOT NULL,
  po_date          DATE NULL,
  pr_no            VARCHAR(50)  NULL,
  vendor_name      VARCHAR(255) NOT NULL DEFAULT '',
  vendor_code      VARCHAR(50)  NULL,
  vendor_tax_id    VARCHAR(30)  NULL,
  vendor_address   VARCHAR(500) NULL,
  vendor_contact   VARCHAR(150) NULL,
  vendor_phone     VARCHAR(100) NULL,
  quotation_no     VARCHAR(100) NULL,
  quotation_date   VARCHAR(50)  NULL,
  delivery_date    VARCHAR(100) NULL,
  payment_terms    VARCHAR(255) NULL,
  deposit_text     VARCHAR(255) NULL,
  retention_text   VARCHAR(255) NULL,
  page_count       INT NOT NULL DEFAULT 0,
  sum_before       DECIMAL(14,2) NOT NULL DEFAULT 0,
  special_discount DECIMAL(14,2) NOT NULL DEFAULT 0,
  sum_after        DECIMAL(14,2) NOT NULL DEFAULT 0,
  vat              DECIMAL(14,2) NOT NULL DEFAULT 0,
  grand_total      DECIMAL(14,2) NOT NULL DEFAULT 0,
  amount_in_words  VARCHAR(500) NULL,
  notes            TEXT NULL,
  src_file         VARCHAR(255) NULL,
  ocr_log_id       INT NULL,
  status           ENUM('open','partial','received','cancelled') NOT NULL DEFAULT 'open',
  created_by       INT NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (po_no),
  KEY idx_po_project (project_id, status),
  KEY idx_po_vendor (vendor_name),
  CONSTRAINT fk_po_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- บรรทัดใบสั่งซื้อ (line_kind: item = ของ · adjust = รายการเงิน ห้ามรับ)
CREATE TABLE IF NOT EXISTS po_lines (
  po_no        VARCHAR(30)  NOT NULL,
  line_no      INT NOT NULL,
  line_kind    ENUM('item','adjust') NOT NULL DEFAULT 'item',
  mat_code     VARCHAR(50)  NULL,
  material_id  INT NULL,
  mat_name     VARCHAR(255) NOT NULL DEFAULT '',
  description  VARCHAR(500) NULL,
  qty          DECIMAL(14,4) NOT NULL DEFAULT 0,
  unit_po_name VARCHAR(50)  NOT NULL DEFAULT '',
  unit_price   DECIMAL(14,4) NOT NULL DEFAULT 0,
  discount     DECIMAL(14,2) NOT NULL DEFAULT 0,
  amount       DECIMAL(14,2) NOT NULL DEFAULT 0,
  qty_received DECIMAL(14,4) NOT NULL DEFAULT 0,
  PRIMARY KEY (po_no, line_no),
  KEY idx_pl_mat (mat_code),
  KEY idx_pl_material (material_id),
  CONSTRAINT fk_pl_po FOREIGN KEY (po_no) REFERENCES po_headers (po_no) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- รอบรับของ RCV-{po_no}-{nn}
CREATE TABLE IF NOT EXISTS po_receipts (
  rcv_no     VARCHAR(40)  NOT NULL,
  po_no      VARCHAR(30)  NOT NULL,
  project_id INT NOT NULL,
  rcv_date   DATE NOT NULL,
  note       VARCHAR(255) NULL,
  created_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (rcv_no),
  KEY idx_pr_po (po_no),
  KEY idx_pr_project (project_id),
  CONSTRAINT fk_pr_po      FOREIGN KEY (po_no)      REFERENCES po_headers (po_no) ON DELETE CASCADE,
  CONSTRAINT fk_pr_project FOREIGN KEY (project_id) REFERENCES projects (id)      ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS po_receipt_lines (
  rcv_no  VARCHAR(40) NOT NULL,
  line_no INT NOT NULL,
  qty     DECIMAL(14,4) NOT NULL DEFAULT 0,
  PRIMARY KEY (rcv_no, line_no),
  CONSTRAINT fk_prl_rcv FOREIGN KEY (rcv_no) REFERENCES po_receipts (rcv_no) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ของที่รับแล้วแต่ยังไม่ได้กำหนด IC / ยังไม่เข้า gate (หน่วยซื้อ)
CREATE TABLE IF NOT EXISTS buffer_lines (
  id           INT NOT NULL AUTO_INCREMENT,
  project_id   INT NOT NULL,
  po_no        VARCHAR(30) NOT NULL,
  line_no      INT NOT NULL,
  unit_po_name VARCHAR(50) NOT NULL DEFAULT '',
  qty_received DECIMAL(14,4) NOT NULL DEFAULT 0,
  qty_consumed DECIMAL(14,4) NOT NULL DEFAULT 0,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_buffer_line (po_no, line_no),
  KEY idx_bl_project (project_id),
  CONSTRAINT fk_bl_project FOREIGN KEY (project_id)     REFERENCES projects (id)              ON DELETE RESTRICT,
  CONSTRAINT fk_bl_line    FOREIGN KEY (po_no, line_no) REFERENCES po_lines (po_no, line_no) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- การนำออกจาก buffer 1 ครั้ง (ตัดยอดหน่วยซื้อ 1 ค่า) → ใบ IN
CREATE TABLE IF NOT EXISTS buffer_pushes (
  push_id      INT NOT NULL AUTO_INCREMENT,
  buffer_id    INT NOT NULL,
  project_id   INT NOT NULL,
  qty_consumed DECIMAL(14,4) NOT NULL DEFAULT 0,
  doc_no       VARCHAR(35)  NULL,
  note         VARCHAR(255) NULL,
  created_by   INT NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  cancelled_at DATETIME NULL,
  cancelled_by INT NULL,
  PRIMARY KEY (push_id),
  KEY idx_bp_buffer (buffer_id),
  KEY idx_bp_doc (doc_no),
  KEY idx_bp_project (project_id),
  CONSTRAINT fk_bp_buffer FOREIGN KEY (buffer_id) REFERENCES buffer_lines (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- การแตกบรรทัด buffer เป็นรหัส IC (หน่วยเก็บ) ต่อ push
CREATE TABLE IF NOT EXISTS push_allocs (
  id          INT NOT NULL AUTO_INCREMENT,
  push_id     INT NOT NULL,
  ic_code     CHAR(20) NOT NULL,
  material_id INT NULL,
  qty         DECIMAL(14,4) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_pa_push (push_id),
  KEY idx_pa_ic (ic_code),
  CONSTRAINT fk_pa_push FOREIGN KEY (push_id) REFERENCES buffer_pushes (push_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SEED — พจนานุกรม IC (รันซ้ำได้ด้วย INSERT IGNORE)
-- ============================================================

-- 000 = ไม่ระบุ (ระบบใช้เป็นค่า "ไม่มี" ของ size/brand/extra)
INSERT IGNORE INTO sizes (size_code, size_name, is_active) VALUES ('000', 'ไม่ระบุ', 1);
INSERT IGNORE INTO brands (brand_code, brand_name, is_active) VALUES ('000', 'ไม่ระบุ', 1);

-- หน่วยนับพื้นฐาน — แก้/ปิดใช้งานได้ภายหลัง
INSERT IGNORE INTO units (unit_code, unit_name, is_active) VALUES
  ('000', 'ไม่ระบุ', 1),
  ('001', 'ชิ้น', 1),
  ('002', 'อัน', 1),
  ('003', 'ตัว', 1),
  ('004', 'ชุด', 1),
  ('005', 'เส้น', 1),
  ('006', 'ท่อน', 1),
  ('007', 'แผ่น', 1),
  ('008', 'ม้วน', 1),
  ('009', 'ถุง', 1),
  ('010', 'กล่อง', 1),
  ('011', 'ลัง', 1),
  ('012', 'ถัง', 1),
  ('013', 'แกลลอน', 1),
  ('014', 'ลิตร', 1),
  ('015', 'กก.', 1),
  ('016', 'ตัน', 1),
  ('017', 'เมตร', 1),
  ('018', 'ตร.ม.', 1),
  ('019', 'ลบ.ม.', 1),
  ('020', 'คู่', 1),
  ('021', 'มัด', 1),
  ('022', 'หลอด', 1),
  ('023', 'ก้อน', 1),
  ('024', 'คัน', 1),
  ('025', 'ใบ', 1),
  ('026', 'ดอก', 1),
  ('027', 'กระป๋อง', 1);

-- ตัวอย่างบันได L1/L2 (placeholder) — ไม่ใช่ taxonomy ต้นฉบับ ให้ปรับ/แทนที่ตามจริง
INSERT IGNORE INTO l1_groups (l1_code, l1_name, sort_order, is_active) VALUES
  ('STR', 'งานโครงสร้าง (ตัวอย่าง)', 1, 1),
  ('ARC', 'งานสถาปัตยกรรม (ตัวอย่าง)', 2, 1),
  ('MEP', 'งานระบบไฟฟ้า-สุขาภิบาล (ตัวอย่าง)', 3, 1),
  ('TOL', 'เครื่องมือ-อุปกรณ์ (ตัวอย่าง)', 4, 1),
  ('SAF', 'ความปลอดภัย (ตัวอย่าง)', 5, 1),
  ('GEN', 'วัสดุสิ้นเปลืองทั่วไป (ตัวอย่าง)', 6, 1);

INSERT IGNORE INTO l2_categories (l1_code, l2_code, l2_name, is_active) VALUES
  ('STR', '01', 'ปูนซีเมนต์-คอนกรีต', 1),
  ('STR', '02', 'เหล็กเส้น-เหล็กรูปพรรณ', 1),
  ('STR', '03', 'ไม้แบบ-ไม้โครง', 1),
  ('STR', '04', 'อิฐ-บล็อก', 1),
  ('ARC', '01', 'กระเบื้อง-วัสดุปูพื้นผนัง', 1),
  ('ARC', '02', 'สี-เคมีภัณฑ์', 1),
  ('ARC', '03', 'ฝ้า-ผนังเบา', 1),
  ('ARC', '04', 'ประตู-หน้าต่าง-อุปกรณ์', 1),
  ('MEP', '01', 'สายไฟ-ท่อร้อยสาย', 1),
  ('MEP', '02', 'อุปกรณ์ไฟฟ้า-โคมไฟ', 1),
  ('MEP', '03', 'ท่อประปา-ข้อต่อ', 1),
  ('MEP', '04', 'สุขภัณฑ์-อุปกรณ์', 1),
  ('TOL', '01', 'เครื่องมือช่าง', 1),
  ('TOL', '02', 'เครื่องมือไฟฟ้า', 1),
  ('SAF', '01', 'อุปกรณ์ป้องกันส่วนบุคคล (PPE)', 1),
  ('SAF', '02', 'ป้าย-อุปกรณ์เตือน', 1),
  ('GEN', '01', 'วัสดุสิ้นเปลือง', 1),
  ('GEN', '02', 'อื่น ๆ', 1);

-- ผูก 000 (ไม่ระบุ) ให้ทุกหมวด เพื่อให้ออกรหัส IC ได้ทันที
INSERT IGNORE INTO l2_sizes (l1_code, l2_code, size_code)
  SELECT l1_code, l2_code, '000' FROM l2_categories;
INSERT IGNORE INTO l2_brands (l1_code, l2_code, brand_code)
  SELECT l1_code, l2_code, '000' FROM l2_categories;

-- ---------------------------------------------------------------------------
-- Min-Max stock ต่อไซต์ × รหัส IC (มติ 49 · 2026-09-24) — lib/inventory_insights.php
-- สร้างเองตอนบันทึกครั้งแรกอยู่แล้ว (_iiEnsureTables) · ใส่ไว้ที่นี่ให้ติดตั้งใหม่ได้ครบ
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS stock_minmax (
  id          INT NOT NULL AUTO_INCREMENT,
  project_id  INT NOT NULL,
  material_id INT NOT NULL,
  min_qty     DECIMAL(14,3) NULL COMMENT 'จุดสั่งซื้อ — พร้อมเบิกถึง/ต่ำกว่านี้ต้องสั่งเติม',
  max_qty     DECIMAL(14,3) NULL COMMENT 'เติมขึ้นไปถึง',
  updated_by  VARCHAR(100) NULL,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_minmax (project_id, material_id),
  KEY idx_minmax_material (material_id),
  CONSTRAINT fk_minmax_project  FOREIGN KEY (project_id)  REFERENCES projects (id)  ON DELETE CASCADE,
  CONSTRAINT fk_minmax_material FOREIGN KEY (material_id) REFERENCES materials (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Min-Max stock ต่อไซต์ × รหัส IC (มติ 49)';

CREATE TABLE IF NOT EXISTS stock_minmax_params (
  project_id     INT NOT NULL,
  lead_time_days DECIMAL(6,1) NOT NULL DEFAULT 7.0,
  cycle_days     DECIMAL(6,1) NOT NULL DEFAULT 14.0,
  service_level  DECIMAL(5,2) NOT NULL DEFAULT 95.00,
  lookback_days  INT NOT NULL DEFAULT 180,
  updated_by     VARCHAR(100) NULL,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (project_id),
  CONSTRAINT fk_minmax_params_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ค่าตั้งต้นการคำนวณ Min-Max แนะนำ ต่อไซต์ (มติ 49)';

-- ---------------------------------------------------------------------------
-- รอบจ่าย (มติ 50 · 2026-09-24) — lib/dispatch_rounds.php
-- สร้างเองตอนบันทึกครั้งแรกอยู่แล้ว (_drEnsureTables) · ใส่ไว้ที่นี่ให้ติดตั้งใหม่ได้ครบ
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS dispatch_settings (
  project_id  INT NOT NULL,
  enabled     TINYINT(1) NOT NULL DEFAULT 1,
  work_days   VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5,6' COMMENT 'ISO-8601 จันทร์=1 … อาทิตย์=7',
  updated_by  VARCHAR(100) NULL,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (project_id),
  CONSTRAINT fk_dispatch_settings_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='รอบจ่าย: เปิด/ปิด + วันทำงาน ต่อไซต์ (มติ 50)';

CREATE TABLE IF NOT EXISTS dispatch_rounds (
  id            INT NOT NULL AUTO_INCREMENT,
  project_id    INT NOT NULL,
  cutoff_time   TIME NOT NULL COMMENT 'เบิกก่อนเวลานี้',
  dispatch_time TIME NOT NULL COMMENT 'จ่ายของเวลานี้',
  sort_no       INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_dispatch_rounds_project (project_id),
  CONSTRAINT fk_dispatch_rounds_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='รอบจ่าย: เบิกก่อน → จ่ายเวลา ต่อไซต์ (มติ 50)';

-- ---------------------------------------------------------------------------
-- เอกสาร 05 Scenario การทำงานของระบบ (2026-09-28) — lib/s05.php (s05EnsureSchema สร้างเองครั้งแรกอยู่แล้ว)
--   document_items: qty_actual (หยิบจริง) · actual_reason · photo_url / photo_return_url (รูปรายรายการ)
--   gate_settings: เวลาหยิบของต่อรอบต่อไซต์ · gate_round_events: หมดเวลา/ขอเวลาเพิ่ม/เลื่อนปิดประตู/สรุปรอบ จากตู้
-- ---------------------------------------------------------------------------
ALTER TABLE document_items
  ADD COLUMN IF NOT EXISTS qty_actual       DECIMAL(14,3) NULL DEFAULT NULL COMMENT 'จำนวนหยิบจริง (Scenario 05 ⑦) · NULL = ใช้จำนวนที่ขอ' AFTER qty,
  ADD COLUMN IF NOT EXISTS actual_reason    VARCHAR(255) NULL DEFAULT NULL COMMENT 'เหตุผลเมื่อหยิบน้อยกว่าที่ขอ' AFTER qty_actual,
  ADD COLUMN IF NOT EXISTS photo_url        TEXT NULL DEFAULT NULL COMMENT 'รูปยืนยันรายรายการ (กติกาข้อ 21)' AFTER actual_reason,
  ADD COLUMN IF NOT EXISTS photo_return_url TEXT NULL DEFAULT NULL COMMENT 'รูปยืนยันคืนรายรายการ (ใบยืมขาคืน)' AFTER photo_url;

CREATE TABLE IF NOT EXISTS gate_settings (
  project_id        INT NOT NULL,
  pick_min_per_item DECIMAL(5,2) NOT NULL DEFAULT 3.00 COMMENT 'นาทีต่อรหัส IC ที่ไม่ซ้ำในรอบ',
  pick_cap_min      INT NOT NULL DEFAULT 120 COMMENT 'เพดานเวลาหยิบของต่อรอบ (นาที)',
  extend_min        INT NOT NULL DEFAULT 5 COMMENT 'นาทีต่อการกดขอเวลาเพิ่ม/เลื่อนปิดประตู',
  store_over_cap    TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'บัตรสายสโตร์ขอเวลาเกินเพดานได้ (1) / เพดานตายตัว (0) — 2026-10-08',
  updated_by        VARCHAR(100) NULL,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (project_id),
  CONSTRAINT fk_gs_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='เวลาหยิบของต่อรอบ ต่อไซต์ (Scenario 05 ⑧) — ตู้ดึงพร้อมรายชื่อบัตร';

CREATE TABLE IF NOT EXISTS gate_round_events (
  id          INT NOT NULL AUTO_INCREMENT,
  project_id  INT NULL,
  gate_code   VARCHAR(20) NULL,
  picking_id  VARCHAR(20) NULL,
  event       VARCHAR(30) NOT NULL COMMENT 'pick_timeout · pick_extend · close_timeout · close_extend · round_end',
  item_count  INT NULL,
  seq         INT NULL COMMENT 'ครั้งที่ (เลื่อน) / จำนวนครั้งที่เลื่อน (round_end)',
  card_id     VARCHAR(50) NULL,
  cardholder  VARCHAR(150) NULL,
  total_min   DECIMAL(8,2) NULL COMMENT 'เวลารวมของรอบ (นาที)',
  over_cap    TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'เวลารวมเกินเพดาน → เตือนบน Dashboard',
  detail      TEXT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_gre_proj_time (project_id, created_at),
  KEY idx_gre_picking (picking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='เหตุการณ์ของรอบเบิกจากตู้ประตู (Scenario 05 ⑧ ⑨)';

-- ---------------------------------------------------------------------------
-- เอกสาร 05 Scenario ฉบับแก้ (2026-09-29) — ใบยืม: กำหนดวันคืน · เกินกำหนด · คืนบางส่วน · ตีชำรุด/สูญหาย
--   lib/borrow.php (borrowEnsureSchema สร้างเองครั้งแรกอยู่แล้ว)
-- ---------------------------------------------------------------------------
ALTER TABLE documents
  ADD COLUMN IF NOT EXISTS due_date      DATE NULL DEFAULT NULL COMMENT 'กำหนดวันคืน (ใบยืม BD · Scenario 05 ③)' AFTER return_ts,
  ADD COLUMN IF NOT EXISTS overdue_flag  TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'ธงเกินกำหนดคืน (งานตรวจรายวัน)' AFTER due_date,
  ADD COLUMN IF NOT EXISTS writeoff_flag TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'มีรายการตีเป็นชำรุด/สูญหาย' AFTER overdue_flag;

ALTER TABLE document_items
  ADD COLUMN IF NOT EXISTS qty_returned  DECIMAL(14,3) NULL DEFAULT NULL COMMENT 'จำนวนคืนจริง (ขาคืน RT · Scenario 05 ⑦) · NULL = ยังไม่คืน' AFTER photo_return_url,
  ADD COLUMN IF NOT EXISTS return_reason VARCHAR(255) NULL DEFAULT NULL COMMENT 'เหตุผลเมื่อคืนน้อยกว่าที่ยืม' AFTER qty_returned;

CREATE TABLE IF NOT EXISTS borrow_writeoffs (
  id           INT NOT NULL AUTO_INCREMENT,
  document_id  INT NOT NULL,
  item_id      INT NOT NULL,
  project_id   INT NOT NULL,
  mat_code     VARCHAR(50) NOT NULL DEFAULT '',
  qty          DECIMAL(14,3) NOT NULL,
  kind         VARCHAR(10) NOT NULL COMMENT 'damaged | lost',
  reason       VARCHAR(255) NOT NULL,
  photo_url    TEXT NULL,
  ref_price    DECIMAL(12,4) NULL COMMENT 'มูลค่าอ้างอิงต่อหน่วย ณ วันที่ตี',
  ref_source   VARCHAR(10) NULL COMMENT 'ratecard | manual',
  stage        VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'borrowed | return_pending | writeoff_pending',
  created_by   VARCHAR(100) NOT NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_bw_doc (document_id),
  KEY idx_bw_proj_time (project_id, created_at),
  CONSTRAINT fk_bw_doc  FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE CASCADE,
  CONSTRAINT fk_bw_item FOREIGN KEY (item_id) REFERENCES document_items (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ใบยืม: รายการที่สายสโตร์ตีเป็นชำรุด/สูญหาย (Scenario 05 ③ ขั้น 6) — ไม่คืนยอดเข้า G · ไม่สร้างรายการหักเงิน';

CREATE TABLE IF NOT EXISTS user_notices (
  id          INT NOT NULL AUTO_INCREMENT,
  project_id  INT NULL,
  username    VARCHAR(150) NOT NULL,
  kind        VARCHAR(30) NOT NULL,
  title       VARCHAR(200) NOT NULL,
  body        TEXT NULL,
  ref_doc     VARCHAR(35) NULL,
  created_by  VARCHAR(100) NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at     DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_un_user (username, read_at),
  KEY idx_un_doc (ref_doc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='แจ้งเตือนในแอป (เช่น อุปกรณ์ยืมถูกตีเป็นชำรุด/สูญหาย → ผู้เบิก ผู้อนุมัติ ผู้จัดการโครงการ)';

-- ---------------------------------------------------------------------------
-- เอกสารชนิดเพิ่ม (2026-09-29) — TD ใบเบิกโอนย้ายข้ามไซต์ · SC ใบนับสต๊อก (QR เข้า gate จากหน้าตรวจสอบประจำวัน)
--   lib/doc_ext.php (docExtEnsureSchema สร้างเองครั้งแรกอยู่แล้ว) · lib/transfer.php · lib/stockcount.php
-- ---------------------------------------------------------------------------
ALTER TABLE documents
  MODIFY doc_type ENUM('RD','OD','BD','IN','TD','SC') NOT NULL,
  ADD COLUMN IF NOT EXISTS dest_project_id INT NULL DEFAULT NULL COMMENT 'ไซต์ปลายทางของใบโอนย้าย (TD)' AFTER project_id,
  ADD KEY IF NOT EXISTS idx_doc_dest_project (dest_project_id);

CREATE TABLE IF NOT EXISTS stock_adjustments (
  id           INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  document_id  INT NOT NULL COMMENT 'ใบนับสต๊อก (SC)',
  item_id      INT NOT NULL,
  project_id   INT NOT NULL,
  gate_id      INT NULL,
  material_id  INT NULL,
  mat_code     VARCHAR(50) NOT NULL DEFAULT '',
  system_qty   DECIMAL(14,3) NOT NULL COMMENT 'ยอดในระบบตอนนับ',
  counted_qty  DECIMAL(14,3) NOT NULL COMMENT 'ที่นับได้',
  delta        DECIMAL(14,3) NOT NULL COMMENT 'นับได้ − ในระบบ (อนุมัติ = ยอดที่ G ขยับเท่านี้)',
  status       VARCHAR(10) NOT NULL COMMENT 'approved | rejected',
  note         VARCHAR(255) NULL,
  decided_by   VARCHAR(100) NOT NULL,
  decided_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_adj_item (item_id),
  KEY idx_adj_doc (document_id),
  KEY idx_adj_proj_time (project_id, decided_at),
  CONSTRAINT fk_adj_doc  FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE CASCADE,
  CONSTRAINT fk_adj_item FOREIGN KEY (item_id) REFERENCES document_items (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ใบนับสต๊อก: ผลการอนุมัติปรับยอด (ADM / R8+) — approved = ยอดที่ G ขยับเท่า delta แล้ว';
