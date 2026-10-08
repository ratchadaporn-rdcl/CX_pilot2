<?php
/**
 * CONNEXT — lib/registry.php : ทะเบียนฟังก์ชัน RPC ทั้งหมด (ชื่อ GAS เดิม 1:1)
 * รูปแบบ: 'ชื่อฟังก์ชัน GAS' => [ไฟล์ใน lib/, callable]
 * ทุก callable รับ (PDO $pdo, ?array $user, array $args)
 */

if (!class_exists('RpcUserError')) {
    class RpcUserError extends RuntimeException {}
}

return [
    // ---- auth / บัญชี ----------------------------------------------------
    'checkLogin'            => ['auth_api.php', 'rpc_checkLogin'],
    'getUserPermissions'    => ['auth_api.php', 'rpc_getUserPermissions'],
    'changePassword'        => ['auth_api.php', 'rpc_changePassword'],
    'changeSite'            => ['auth_api.php', 'rpc_changeSite'],
    'getAvailableSites'     => ['auth_api.php', 'rpc_getAvailableSites'],
    'getAppBuild'           => ['auth_api.php', 'rpc_getAppBuild'],
    'logoutServer'          => ['auth_api.php', 'rpc_logoutServer'], // เพิ่มใหม่: ล้าง session ฝั่ง server

    // ---- ข้อมูลอ้างอิง / directory ----------------------------------------
    'getUserDirectory'      => ['directory.php', 'rpc_getUserDirectory'],
    'getApproversList'      => ['directory.php', 'rpc_getApproversList'],
    'getSubcontractsList'   => ['directory.php', 'rpc_getSubcontractsList'],
    'getMaterialsList'      => ['directory.php', 'rpc_getMaterialsList'],
    'getMaterialsMainList'  => ['directory.php', 'rpc_getMaterialsMainList'],
    'getGatesList'          => ['directory.php', 'rpc_getGatesList'],
    'getBalanceList'        => ['directory.php', 'rpc_getBalanceList'],
    'getGateBalanceList'    => ['directory.php', 'rpc_getGateBalanceList'],
    'getMaterialBalance'    => ['directory.php', 'rpc_getMaterialBalance'],
    'getLegacyMangoStock'   => ['directory.php', 'rpc_getLegacyMangoStock'], // เพิ่มใหม่: แถบเตือน Dashboard (มติ 34)

    // ---- Dashboard คลัง: วัสดุยอดนิยม + Min-Max stock (เพิ่มใหม่ 2026-09-24 · มติ 49) -------------
    'getPopularMaterials'   => ['inventory_insights.php', 'rpc_getPopularMaterials'],
    'getMinMaxData'         => ['inventory_insights.php', 'rpc_getMinMaxData'],
    'getMinMaxSettings'     => ['inventory_insights.php', 'rpc_getMinMaxSettings'],
    'saveMinMax'            => ['inventory_insights.php', 'rpc_saveMinMax'],
    'saveMinMaxParams'      => ['inventory_insights.php', 'rpc_saveMinMaxParams'],
    'getSiteErrorLog'       => ['site_errors.php', 'rpc_getSiteErrorLog'],   // Error log รายไซต์ (อ่านอย่างเดียว)
    // ---- รอบจ่าย (เพิ่มใหม่ 2026-09-24 · มติ 50) ----------------------------------------------------
    'getDispatchRounds'     => ['dispatch_rounds.php', 'rpc_getDispatchRounds'],
    'saveDispatchRounds'    => ['dispatch_rounds.php', 'rpc_saveDispatchRounds'],
    'getDispatchBoard'      => ['dispatch_rounds.php', 'rpc_getDispatchBoard'],

    // ---- ส่งเอกสาร + ยืม-คืน ----------------------------------------------
    'processRequisitionSubmission' => ['documents.php', 'rpc_processRequisitionSubmission'],
    'processOddsSubmission'        => ['documents.php', 'rpc_processOddsSubmission'],
    'processBorrowSubmission'      => ['documents.php', 'rpc_processBorrowSubmission'],
    'processBorrowBatch'           => ['documents.php', 'rpc_processBorrowBatch'],
    'processInboundBatch'          => ['documents.php', 'rpc_processInboundBatch'],
    // [2026-10-02 · GP-17/GP-21] รหัสตรวจสอบ QR (หน้า QR เรียกก่อนวาด — js/qr-sign.js)
    'getQrPayload'                 => ['qr_sign.php', 'rpc_getQrPayload'],
    // [2026-10-02 · health] Dashboard "สถานะตู้" (สัญญาณชีพ · อุปกรณ์ — js/gate-health.js)
    'getGateHealth'                => ['gate_health.php', 'rpc_getGateHealth'],
    'getUnreturnedItems'           => ['documents.php', 'rpc_getUnreturnedItems'],
    'returnBorrowItem'             => ['documents.php', 'rpc_returnBorrowItem'],
    'registerReturnGateLog'        => ['documents.php', 'rpc_registerReturnGateLog'],
    // v1.10.0: หน้าถ่ายรูปยืนยัน poll ถามว่าใบปิดงานครบหรือยัง (ปลดล็อกชุดถัดไป)
    'getDocsCloseState'            => ['documents.php', 'rpc_getDocsCloseState'],
    'confirmReturnAtGate'          => ['documents.php', 'rpc_confirmReturnAtGate'],

    // ---- อนุมัติ / ยกเลิก ---------------------------------------------------
    'getApprovalRequests'   => ['approval.php', 'rpc_getApprovalRequests'],
    'updateApprovalStatus'  => ['approval.php', 'rpc_updateApprovalStatus'],
    'cancelRequisition'     => ['approval.php', 'rpc_cancelRequisition'],
    'reviseRequisition'     => ['doc_revise.php', 'rpc_reviseRequisition'],   // [2026-10-06] ผู้ขอแก้ยอดใบที่ถูกตีกลับแล้วส่งใหม่

    // ---- QR / gate / ถ่ายรูปยืนยัน -------------------------------------------
    'getApprovedDocuments'       => ['gate_api.php', 'rpc_getApprovedDocuments'],
    'getAwaitingGateDocIds'      => ['gate_api.php', 'rpc_getAwaitingGateDocIds'],
    'getConfirmableGateSignature'=> ['gate_api.php', 'rpc_getConfirmableGateSignature'],
    'getConfirmableDocuments'    => ['gate_api.php', 'rpc_getConfirmableDocuments'],
    'checkGateStatusForDoc'      => ['gate_api.php', 'rpc_checkGateStatusForDoc'],
    'saveConfirmationData'       => ['gate_api.php', 'rpc_saveConfirmationData'],
    // ---- Scenario 05 (2026-09-28): สถานะรอบหลังบันทึกรูป + การ์ดเตือน Dashboard ----
    'getPickRoundState'          => ['gate_api.php', 'rpc_getPickRoundState'],
    'getPickAlerts'              => ['pick_alerts.php', 'rpc_getPickAlerts'],
    // ---- Scenario 05 ③ (2026-09-29): ใบยืม — ตีชำรุด/สูญหาย · รายงาน PDF · Dashboard ค้างคืน · แจ้งเตือนในแอป ----
    'getBorrowWriteoffInfo'      => ['borrow.php', 'rpc_getBorrowWriteoffInfo'],
    'writeOffBorrowItems'        => ['borrow.php', 'rpc_writeOffBorrowItems'],
    'generateBorrowLossPDF'      => ['borrow.php', 'rpc_generateBorrowLossPDF'],
    'getBorrowAlerts'            => ['borrow.php', 'rpc_getBorrowAlerts'],
    'getMyBorrowOverdue'         => ['borrow.php', 'rpc_getMyBorrowOverdue'],   // 2026-10-02 GP-13 คำเตือน delay ที่ฟอร์มยืม
    'getMyNotices'               => ['borrow.php', 'rpc_getMyNotices'],
    'ackNotices'                 => ['borrow.php', 'rpc_ackNotices'],
    // ---- TD เบิกโอนย้ายข้ามไซต์ (2026-09-29 · ไม่มีใน GAS) ----
    'getTransferFormData'        => ['transfer.php', 'rpc_getTransferFormData'],
    'processTransferSubmission'  => ['transfer.php', 'rpc_processTransferSubmission'],
    'getTransferList'            => ['transfer.php', 'rpc_getTransferList'],
    // [2026-10-02] TG ใบย้าย Gate (ภายในไซต์) — แท็บโอนย้าย โหมดภายใน
    'getGateMoveFormData'        => ['gatemove.php', 'rpc_getGateMoveFormData'],
    'processGateMoveSubmission'  => ['gatemove.php', 'rpc_processGateMoveSubmission'],
    'getGateMoveList'            => ['gatemove.php', 'rpc_getGateMoveList'],
    // ---- SC ใบนับสต๊อก — QR เข้า gate จากหน้าตรวจสอบประจำวัน (2026-09-29 · ไม่มีใน GAS) ----
    'getStockCountData'          => ['stockcount.php', 'rpc_getStockCountData'],
    'createStockCount'           => ['stockcount.php', 'rpc_createStockCount'],
    'getStockCountSheet'         => ['stockcount.php', 'rpc_getStockCountSheet'],
    'saveStockCount'             => ['stockcount.php', 'rpc_saveStockCount'],
    'getStockAdjustQueue'        => ['stockcount.php', 'rpc_getStockAdjustQueue'],
    'decideStockAdjust'          => ['stockcount.php', 'rpc_decideStockAdjust'],

    // ---- ประวัติ / แดชบอร์ดหักเงิน (หน้าสถิติเอาออก 2026-10-02) ------------------
    'getRequisitionHistory' => ['history_stats.php', 'rpc_getRequisitionHistory'],
    'getChargeDashboard'    => ['history_stats.php', 'rpc_getChargeDashboard'],
    'getPickingHistory'     => ['picking_history.php', 'rpc_getPickingHistory'],   // 2026-10-08 ประวัติราย Picking list

    // ---- ตรวจสอบประจำวัน / RateCard / จับคู่ Mango ---------------------------
    'getDailyCheckData'      => ['dailycheck.php', 'rpc_getDailyCheckData'],
    'setDailyCheckCharge'    => ['dailycheck.php', 'rpc_setDailyCheckCharge'],
    'confirmDailyCheck'      => ['dailycheck.php', 'rpc_confirmDailyCheck'],
    'getRateCardData'        => ['dailycheck.php', 'rpc_getRateCardData'],
    'saveRateCard'           => ['dailycheck.php', 'rpc_saveRateCard'],
    'getSubMangoVendorData'  => ['dailycheck.php', 'rpc_getSubMangoVendorData'],
    'saveSubMangoVendor'     => ['dailycheck.php', 'rpc_saveSubMangoVendor'],

    // ---- PDF ------------------------------------------------------------------
    'generateDocReportPDF'   => ['pdf_api.php', 'rpc_generateDocReportPDF'],
    'generateBalancePDF'     => ['pdf_api.php', 'rpc_generateBalancePDF'],
    'generateDeductionPDF'   => ['pdf_api.php', 'rpc_generateDeductionPDF'],

    // ---- ตั้งค่าผู้รับเหมา + จับคู่ Mango (GAS v1.8-1.10) ----------------------
    'getSubSettingsData'         => ['subsettings.php', 'rpc_getSubSettingsData'],
    'saveSubSettings'            => ['subsettings.php', 'rpc_saveSubSettings'],
    'addSubcontractor'           => ['subsettings.php', 'rpc_addSubcontractor'],
    'renameSubcontractor'        => ['subsettings.php', 'rpc_renameSubcontractor'],
    'remapSubcontractor'         => ['subsettings.php', 'rpc_remapSubcontractor'],
    'addSubMangoOption'          => ['subsettings.php', 'rpc_addSubMangoOption'],
    'removeSubMangoOption'       => ['subsettings.php', 'rpc_removeSubMangoOption'],
    'setSubMango'                => ['subsettings.php', 'rpc_setSubMango'],
    'getSubMangoHistory'         => ['subsettings.php', 'rpc_getSubMangoHistory'],
    'getSubcontractorSignature'  => ['subsettings.php', 'rpc_getSubcontractorSignature'],
    'saveSubcontractorSignature' => ['subsettings.php', 'rpc_saveSubcontractorSignature'],
    // v1.10.0 — ยังไม่เปิดใช้ (ตอบข้อความอธิบายแทน) ดูเหตุผลในไฟล์ subsettings.php
    'closeSubcontractorSettle'   => ['subsettings.php', 'rpc_closeSubcontractorSettle'],

    // ---- ลายเซ็นกลาง + ใบหักเงินครบวงจร (GAS v1.9.x) -------------------------
    'getMySignature'             => ['signatures.php', 'rpc_getMySignature'],
    'saveMySignature'            => ['signatures.php', 'rpc_saveMySignature'],
    'deleteMySignature'          => ['signatures.php', 'rpc_deleteMySignature'],
    'getMySignTasks'             => ['signatures.php', 'rpc_getMySignTasks'],
    'requestSignature'           => ['signatures.php', 'rpc_requestSignature'],
    'cancelSignRequest'          => ['signatures.php', 'rpc_cancelSignRequest'],
    'submitSignature'            => ['signatures.php', 'rpc_submitSignature'],
    'getSignatureView'           => ['signatures.php', 'rpc_getSignatureView'],
    'getSignDocDetail'           => ['signatures.php', 'rpc_getSignDocDetail'],
    'getDeductionSignData'       => ['signatures.php', 'rpc_getDeductionSignData'],
    'createContractorLink'       => ['signatures.php', 'rpc_createContractorLink'],
    'setContractorSent'          => ['signatures.php', 'rpc_setContractorSent'],
    'issueDeductionDocs'         => ['signatures.php', 'rpc_issueDeductionDocs'],
    'freezeDeductionRatesNow'    => ['signatures.php', 'rpc_freezeDeductionRatesNow'],
    // หน้า sign.php (public token) เรียก 2 ตัวนี้ — ไม่ต้องล็อกอิน (ดู api/rpc.php)
    'getSignPageData'            => ['signatures.php', 'rpc_getSignPageData'],
    'submitContractorSignature'  => ['signatures.php', 'rpc_submitContractorSignature'],

    // ---- บันทึกสแกนนิ้ว (GAS v1.9.0-1.10.0) ----------------------------------
    'getFingerScanData'          => ['fingerscan.php', 'rpc_getFingerScanData'],
    'saveFingerScanDay'          => ['fingerscan.php', 'rpc_saveFingerScanDay'],
    'saveFingerScanRate'         => ['fingerscan.php', 'rpc_saveFingerScanRate'],
    'getFingerScanBadge'         => ['fingerscan.php', 'rpc_getFingerScanBadge'],
    'getFingerScanSummary'       => ['fingerscan.php', 'rpc_getFingerScanSummary'],
    'getFingerScanAlerts'        => ['fingerscan.php', 'rpc_getFingerScanAlerts'],
    'saveFingerScanAlertNote'    => ['fingerscan.php', 'rpc_saveFingerScanAlertNote'],
    'saveFingerScanAlertNotes'   => ['fingerscan.php', 'rpc_saveFingerScanAlertNotes'],
    'getFingerScanDashboard'     => ['fingerscan.php', 'rpc_getFingerScanDashboard'],
    'verifyFingerScanDay'        => ['fingerscan.php', 'rpc_verifyFingerScanDay'],
    'cancelFingerScanVerify'     => ['fingerscan.php', 'rpc_cancelFingerScanVerify'],
    'getFingerScanVerifyView'    => ['fingerscan.php', 'rpc_getFingerScanVerifyView'],
    'createFingerScanLink'       => ['fingerscan.php', 'rpc_createFingerScanLink'],
    'createAllFingerScanLinks'   => ['fingerscan.php', 'rpc_createAllFingerScanLinks'],
    'setFingerScanSent'          => ['fingerscan.php', 'rpc_setFingerScanSent'],
    'cancelFingerScanLink'       => ['fingerscan.php', 'rpc_cancelFingerScanLink'],
    'cancelAllFingerScanLinks'   => ['fingerscan.php', 'rpc_cancelAllFingerScanLinks'],
    'generateFingerScanPDF'      => ['fingerscan.php', 'rpc_generateFingerScanPDF'],

    // ---- หักค่าใช้จ่ายผู้รับเหมา (GAS v1.9.x) --------------------------------
    'getSubExpenseData'           => ['subexpense.php', 'rpc_getSubExpenseData'],
    'saveSubExpenseData'          => ['subexpense.php', 'rpc_saveSubExpenseData'],
    'saveSubExpenseConfig'        => ['subexpense.php', 'rpc_saveSubExpenseConfig'],
    'getSubExpenseSignData'       => ['subexpense.php', 'rpc_getSubExpenseSignData'],
    'requestSubExpenseSignature'  => ['subexpense.php', 'rpc_requestSubExpenseSignature'],
    'cancelSubExpenseSignRequest' => ['subexpense.php', 'rpc_cancelSubExpenseSignRequest'],
    'generateSubExpensePDF'       => ['subexpense.php', 'rpc_generateSubExpensePDF'],
];
