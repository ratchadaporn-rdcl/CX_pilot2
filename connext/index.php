<?php
/**
 * CONNEXT — index.php : SPA ทั้งแอป (port จาก GAS Index.html โดย tools/build_index.py)
 * ห้ามแก้ไฟล์นี้ตรง ๆ ในส่วนที่มาจากต้นฉบับ — แก้ transform ใน tools/build_index.py แล้ว build ใหม่
 */
require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';

$sessionUser = currentUser();
if (!$sessionUser) { $sessionUser = tryRememberLogin(); }

$allowedPages = ['requisition', 'dashboard', 'approve', 'qr', 'confirm', 'history', 'dailycheck',   // [2026-10-02] หน้า stats (สถิติ) เอาออกแล้ว
                 'pobuffer', 'cnxadmin'];   // สองตัวท้าย = หน้าโมดูล PO/buffer ที่ฝังด้วย iframe
$pageParam    = isset($_GET['page']) ? (string)$_GET['page'] : '';
$initialPage  = in_array($pageParam, $allowedPages, true) ? $pageParam : '';

$serverUserJson = $sessionUser
    ? json_encode($sessionUser, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE)
    : 'null';
?>
<!DOCTYPE html>
<html lang="th">

<head>
<script src="<?= assetHref('js/inventory-theme.js') ?>"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <meta name="theme-color" content="#1e3a8a">
    


    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="CONNEXT">
    <title>CONNEXT | Inventory Control Module</title>
    
    <link rel="manifest" href="<?= APP_BASE ?>/manifest.json">
    <link rel="icon" type="image/png" href="<?= APP_BASE ?>/favicon.png">
    <link rel="apple-touch-icon" href="<?= APP_BASE ?>/apple-touch-icon.png">
    <meta name="csrf-token" content="<?= e(csrfToken()) ?>">
    
    <link href="<?= assetHref('css/prompt.css') ?>" rel="stylesheet">
    <link rel="stylesheet" href="<?= assetHref('vendor-assets/fontawesome/css/all.min.css') ?>">
    <script src="<?= assetHref('vendor-assets/jquery-3.7.1.min.js') ?>"></script>
    <link href="<?= assetHref('vendor-assets/select2.min.css') ?>" rel="stylesheet" />
    <script src="<?= assetHref('vendor-assets/select2.min.js') ?>"></script>
    <script src="<?= assetHref('vendor-assets/chart.umd.js') ?>"></script>
    <script src="<?= assetHref('vendor-assets/qrcode.js') ?>"></script>
    <script>
        window.APP_BASE = "<?= APP_BASE ?>";
        window.__SERVER_USER = <?= $serverUserJson ?>;
    </script>
    <script src="<?= assetHref('js/gas-shim.js') ?>"></script>
    <script src="<?= assetHref('js/php-port.js') ?>"></script>

    <style>
:root{--primary: #1e3a8a;--primary-light: #3b82f6;--secondary: #0f172a;--accent: #f59e0b;--success: #10b981;--danger: #ef4444;--surface: #ffffff;--background: #f1f5f9;--text-main: #1e293b;--text-muted: #64748b;--border: #e2e8f0;--radius-lg: 16px;--radius-md: 10px;--shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);--shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);--shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1)}*{margin:0;padding:0;box-sizing:border-box;font-family:"Prompt",sans-serif}body{background-color:var(--background);color:var(--text-main);min-height:100vh;display:flex;flex-direction:column}.navbar{background-color:var(--surface);box-shadow:var(--shadow-sm);padding:0.75rem 1rem;display:flex;justify-content:space-between;align-items:flex-start;position:sticky;top:0;z-index:100;flex-wrap:wrap;gap:0.75rem}.brand{display:flex;align-items:center;gap:10px;min-width:0}.logo-icon{width:40px;height:40px;background:linear-gradient(135deg,var(--primary),var(--primary-light));color:white;border-radius:8px;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1.2rem;box-shadow:var(--shadow-sm)}.brand-text h1{font-size:1.05rem;font-weight:700;color:var(--secondary);letter-spacing:0.3px}.brand-text p{font-size:0.7rem;color:var(--text-muted);line-height:1.35}.nav-links{display:flex;gap:0.5rem;overflow-x:auto;white-space:nowrap;-ms-overflow-style:none;scrollbar-width:none;width:100%;order:3;margin-top:0.25rem;padding-bottom:0.25rem}.nav-links::-webkit-scrollbar{display:none}.nav-link{color:var(--text-muted);text-decoration:none;font-weight:500;padding:0.72rem 0.95rem;transition:color 0.2s,background-color 0.2s,border-color 0.2s;position:relative;cursor:pointer;border-radius:999px;background:#f8fafc;border:1px solid var(--border);display:inline-flex;align-items:center;gap:6px;flex-shrink:0;min-height:44px}.nav-link:hover,.nav-link.active{color:var(--primary);background:rgba(59,130,246,0.08);border-color:rgba(59,130,246,0.18)}.nav-link.active::after{display:none}.nav-link:focus-visible,.nav-more-btn:focus-visible{outline:2px solid var(--primary-light);outline-offset:-2px}.user-profile{display:flex;align-items:center;gap:10px;margin-left:auto}.btn-logout{background:none;border:none;color:var(--danger);font-size:1.2rem;cursor:pointer;padding:8px;border-radius:10px;transition:all 0.2s;display:flex;align-items:center;justify-content:center;min-width:42px;min-height:42px}.btn-logout:hover{background:rgba(239,68,68,0.1)}.user-info{text-align:right;display:block;min-width:0;overflow:hidden}.user-name{font-weight:600;font-size:0.9rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.user-role{font-size:0.75rem;color:var(--text-muted);background:var(--border);padding:2px 8px;border-radius:12px;display:inline-block;margin-top:2px}.avatar{width:36px;height:36px;border-radius:50%;background-color:var(--border);display:flex;align-items:center;justify-content:center;color:var(--text-muted);font-size:1.2rem}.page-container{max-width:1400px;margin:1rem auto 1.25rem;padding:0 0.85rem;flex:1;width:100%;display:none}.cnx-frame{display:block;width:100%;border:0;min-height:240px;background:transparent;overflow:hidden}.page-container.active-page{display:block;animation:fadeInPage 0.25s ease}@keyframes fadeInPage{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:0.75rem 1.5rem;border:none;border-radius:8px;font-size:0.95rem;font-weight:600;cursor:pointer;transition:all 0.2s;min-height:44px}.page-header{margin-bottom:1rem;display:grid;grid-template-columns:1fr auto;column-gap:0.6rem;row-gap:0.35rem;align-items:center}.page-header>.page-title{grid-column:1;grid-row:1;min-width:0}.page-header>.page-refresh-btn{grid-column:2;grid-row:1;justify-self:end}.page-header>.page-subtitle{grid-column:1 / -1;grid-row:2;margin-top:0}.page-title{font-size:1.25rem;line-height:1.35;display:flex;align-items:center;gap:0.55rem;flex-wrap:wrap}.page-refresh-btn{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:50%;border:none;background:transparent;color:var(--text-muted);cursor:pointer;transition:background 0.15s ease,color 0.15s ease;font-size:0.95rem;-webkit-tap-highlight-color:transparent}.page-refresh-btn:hover{background:rgba(30,58,138,0.08);color:var(--primary)}.page-refresh-btn:active{background:rgba(30,58,138,0.14)}.page-refresh-btn.is-refreshing i{animation:spin 0.9s linear infinite;color:var(--primary)}@keyframes spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}.page-subtitle{margin-top:0.45rem;font-size:0.86rem;line-height:1.55;color:var(--text-muted)}.form-card{background:white;border-radius:var(--radius-lg);padding:1rem;box-shadow:var(--shadow-sm)}.filter-bar{display:flex;flex-direction:column;gap:0.75rem;margin-bottom:1rem}.actions{display:flex;flex-direction:column;gap:0.75rem}.actions .btn,.qr-filter-bar .btn,.card-actions .btn-approve,.card-actions .btn-reject{width:100%}.btn-primary{background:var(--primary);color:white;box-shadow:var(--shadow-sm)}.btn-primary:hover{background:#1d4ed8;transform:translateY(-1px)}.btn-danger{background:var(--danger);color:#fff;box-shadow:var(--shadow-sm)}.btn-danger:hover{background:#dc2626;transform:translateY(-1px)}.btn-success{background:var(--success);color:#fff;box-shadow:var(--shadow-sm)}.btn-success:hover{background:#059669;transform:translateY(-1px)}.btn-secondary{background:#f1f5f9;color:var(--text-main);border:1px solid var(--border)}.btn-secondary:hover{background:#e2e8f0}.badge-secondary{background:rgba(100,116,139,0.1);color:var(--text-muted)}.form-control,select.form-control{width:100%;min-height:48px;padding:0.75rem 1rem;border:1px solid var(--border);border-radius:8px;font-size:0.95rem;background:var(--surface);transition:all 0.2s;outline:none}.form-control:focus,select.form-control:focus{border-color:var(--primary-light);box-shadow:0 0 0 3px rgba(59,130,246,0.1)}.tabs-container{background:var(--surface);border-radius:var(--radius-lg);box-shadow:var(--shadow-md);overflow:hidden;margin-top:1rem}.tabs-header{display:flex;border-bottom:1px solid var(--border);background:#f8fafc;overflow-x:auto;-webkit-overflow-scrolling:touch;scrollbar-width:none}.tabs-header::-webkit-scrollbar{display:none}.tab-btn{padding:0.9rem 1rem;border:none;background:none;font-weight:600;color:var(--text-muted);cursor:pointer;display:flex;align-items:center;gap:8px;white-space:nowrap;flex-shrink:0;transition:all 0.3s ease}.tab-btn.active{color:var(--primary);background:var(--surface);position:relative}.tab-btn.active::after{content:"";position:absolute;bottom:0;left:0;width:100%;height:3px;background:var(--primary);border-radius:3px 3px 0 0}.tab-content{padding:1rem;display:none;animation:fadeIn 0.3s ease}.tab-content.active{display:block}.grid-layout{display:grid;grid-template-columns:1fr;gap:1rem}.grid-layout>div{min-width:0}@media(min-width:900px){.grid-layout{grid-template-columns:350px 1fr}}.grid-three{display:grid;grid-template-columns:1fr;gap:1.5rem;align-items:start}@media(min-width:1100px){.grid-three{grid-template-columns:280px 1fr 1fr}}.form-section{background:#f8fafc;padding:1rem;border-radius:var(--radius-md);border:1px solid var(--border)}.draft-actions{margin-top:1.5rem;text-align:right}@media(max-width:768px){.form-control,input[type=text],input[type=number],input[type=password],input[type=search],input[type=tel],input[type=email],select,textarea{font-size:16px!important;min-height:48px}textarea{min-height:88px}.btn{min-height:48px;padding-left:1rem;padding-right:1rem}.draft-actions{position:sticky;bottom:0;margin:1rem -0.85rem -0.85rem;padding:0.75rem 0.85rem calc(0.75rem + env(safe-area-inset-bottom,0));background:var(--surface);border-top:1px solid var(--border);box-shadow:0 -6px 16px rgba(15,23,42,0.06);text-align:center;z-index:50}.draft-actions .btn,.mobile-sticky-submit{width:100%!important;font-size:1rem}.page-refresh-btn{width:42px;height:42px}}.table-container{background:var(--surface);border-radius:var(--radius-md);border:1px solid var(--border);overflow-x:auto;width:100%;-webkit-overflow-scrolling:touch}.data-table{width:100%;border-collapse:collapse;min-width:560px}.data-table th{background:#f8fafc;padding:1rem;font-weight:600;border-bottom:2px solid var(--border)}.data-table td{padding:1rem;border-bottom:1px solid var(--border)}.data-table td.text-right,.data-table th.text-right{text-align:right}.draft-table{width:100%;border-collapse:separate;border-spacing:0 0.45rem;table-layout:fixed}.draft-table thead th{padding:0.3rem 0.75rem;font-size:0.78rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.03em;border:none;background:transparent}.draft-table colgroup,.draft-table col:nth-child(1){width:auto}.draft-table thead th:nth-child(1){width:auto}.draft-table thead th:nth-child(2){width:110px}.draft-table thead th:nth-child(3){width:28%}.draft-table thead th:nth-child(4){width:22%}.draft-table thead th:nth-child(5){width:38px}.draft-table.has-charge thead th:nth-child(2){width:76px}.draft-table.has-charge thead th:nth-child(3){width:19%}.draft-table.has-charge thead th:nth-child(4){width:13%}.draft-table.has-charge thead th:nth-child(5){width:140px}.draft-table tbody tr{background:var(--surface);box-shadow:var(--shadow-sm)}.draft-table tbody td{padding:0.7rem 0.75rem;border-top:1px solid var(--border);border-bottom:1px solid var(--border);vertical-align:middle;font-size:0.9rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.draft-table tbody td:first-child{border-left:1px solid var(--border);border-radius:10px 0 0 10px;white-space:normal;word-break:break-word;font-weight:500}.draft-table tbody td:last-child{border-right:1px solid var(--border);border-radius:0 10px 10px 0;text-align:center;width:80px;min-width:80px;padding:0.4rem;overflow:visible;white-space:nowrap}.draft-table tbody .draft-empty-row td{border:1px dashed var(--border);border-radius:10px;text-align:center;color:var(--text-muted);font-size:0.88rem;padding:1.1rem;background:transparent;box-shadow:none}.draft-delete-btn{background:none;border:none;color:var(--danger);cursor:pointer;font-size:0.95rem;padding:0.25rem;line-height:1}.draft-edit-btn{background:none;border:none;color:var(--primary);cursor:pointer;font-size:0.95rem;padding:0.25rem;line-height:1;margin-right:0.25rem}.draft-edit-btn:hover{color:var(--primary-light)}.password-wrapper{position:relative;display:block}.password-wrapper>.form-control{padding-right:44px}.password-toggle{position:absolute;top:50%;right:4px;transform:translateY(-50%);width:38px;height:38px;border:none;background:transparent;color:var(--text-muted);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:0.95rem;border-radius:8px;-webkit-tap-highlight-color:transparent}.password-toggle:hover{color:var(--primary);background:rgba(30,58,138,0.06)}.password-toggle:active{background:rgba(30,58,138,0.12)}.qty-modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,0.48);display:none;align-items:flex-end;justify-content:center;z-index:9998;backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px)}.qty-modal-backdrop.open{display:flex}.qty-modal{background:#fff;width:100%;max-width:480px;border-radius:20px 20px 0 0;padding:20px 18px calc(20px + env(safe-area-inset-bottom,0));box-shadow:0 -10px 30px rgba(0,0,0,0.18);transform:translateY(20px);opacity:0;transition:transform 0.25s cubic-bezier(0.22,1,0.36,1),opacity 0.2s ease;max-height:92vh;overflow-y:auto;overflow-x:hidden}.qty-modal-backdrop.open .qty-modal{transform:translateY(0);opacity:1}@media(min-width:600px){.qty-modal-backdrop{align-items:center}.qty-modal{border-radius:20px}}.qty-modal-handle{width:44px;height:4px;background:#cbd5e1;border-radius:4px;margin:0 auto 14px}.qty-modal-title{font-size:1.05rem;font-weight:700;color:var(--secondary);margin-bottom:4px;display:flex;align-items:center;gap:0.5rem}.qty-modal-title span{min-width:0;overflow-wrap:anywhere;word-break:break-word}.qty-modal-subtitle{font-size:0.85rem;color:var(--text-muted);margin-bottom:14px;overflow-wrap:anywhere;word-break:break-word}.qty-stock-card{background:linear-gradient(135deg,#f1f5f9 0%,#e2e8f0 100%);border:1px solid #cbd5e1;border-radius:14px;padding:14px;margin-bottom:18px}.qty-stock-row{display:flex;justify-content:space-between;align-items:baseline;padding:6px 0;font-size:0.92rem}.qty-stock-row .label{color:var(--text-muted)}.qty-stock-row .value{font-weight:600;color:var(--secondary);font-variant-numeric:tabular-nums}.qty-stock-row.divider{border-top:1px dashed #94a3b8;margin-top:6px;padding-top:10px}.qty-stock-row.highlight .label{color:var(--primary);font-weight:600}.qty-stock-row.highlight .value{color:var(--primary);font-size:1.15rem}.qty-stock-row.danger .value{color:var(--danger)}.qty-stepper-label{font-size:0.88rem;color:var(--text-muted);margin-bottom:8px}.qty-stepper{display:flex;gap:8px;align-items:stretch;margin-bottom:8px}.qty-step-btn{flex:0 0 56px;min-height:56px;border:1px solid var(--border);background:#fff;color:var(--primary);font-size:1.4rem;font-weight:600;border-radius:14px;cursor:pointer;-webkit-tap-highlight-color:transparent;transition:background 0.15s ease,transform 0.1s ease}.qty-step-btn:hover{background:#eef2ff}.qty-step-btn:active{transform:scale(0.95)}.qty-step-btn:disabled{opacity:0.4;cursor:not-allowed}.qty-step-input{flex:1;min-width:0;min-height:56px;text-align:center;font-size:1.5rem!important;font-weight:700;border:2px solid var(--border);border-radius:14px;background:#fff;color:var(--secondary);outline:none;transition:border-color 0.15s ease}.qty-step-input:focus{border-color:var(--primary)}.qty-step-input.invalid{border-color:var(--danger);color:var(--danger)}.qty-quick-row{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px}.qty-quick-btn{flex:1;min-width:56px;padding:8px 6px;border:1px solid var(--border);background:#fff;color:var(--text-muted);border-radius:10px;font-size:0.85rem;cursor:pointer;font-family:inherit;-webkit-tap-highlight-color:transparent}.qty-quick-btn:hover{background:#f1f5f9;color:var(--primary);border-color:var(--primary)}.qty-quick-btn.active{background:var(--primary);color:#fff;border-color:var(--primary);font-weight:600}.fs-genall-hint{display:flex;align-items:flex-start;gap:0.5rem;font-size:0.83rem;line-height:1.5;padding:0.7rem 0.85rem;border-radius:12px;background:#eff6ff;color:#1e3a8a;border:1px solid #bfdbfe;margin:0.9rem 0 0.2rem}.fs-genall-hint i{margin-top:0.15rem;flex:none}.fs-genall-hint.muted{background:#f1f5f9;color:#475569;border-color:#e2e8f0}.qty-warn{font-size:0.85rem;color:var(--danger);padding:8px 12px;background:rgba(239,68,68,0.08);border-radius:10px;margin-bottom:14px;min-height:0;display:none}.qty-warn.show{display:block}.qty-modal-actions{display:grid;grid-template-columns:1fr 1fr;gap:10px}.qty-modal-actions .btn{width:100%;min-height:50px}.qty-trigger{cursor:pointer;background-image:linear-gradient(white,white),linear-gradient(90deg,transparent 0%,transparent 100%);background-clip:padding-box,border-box;position:relative;padding-right:38px!important}.qty-trigger::after{content:"\\f067";font-family:"Font Awesome 6 Free";font-weight:900;color:var(--primary);position:absolute;right:14px;top:50%;transform:translateY(-50%);font-size:0.85rem;pointer-events:none}.qty-trigger[data-has-value="1"]::after{content:"\\f303"}.qc-modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,0.55);display:none;align-items:flex-end;justify-content:center;z-index:9997;backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px)}.qc-modal-backdrop.open{display:flex}.qc-modal{background:#fff;width:100%;max-width:540px;border-radius:20px 20px 0 0;padding:18px 16px calc(18px + env(safe-area-inset-bottom,0));box-shadow:0 -10px 30px rgba(0,0,0,0.2);transform:translateY(24px);opacity:0;transition:transform 0.25s cubic-bezier(0.22,1,0.36,1),opacity 0.2s ease;max-height:94vh;overflow-y:auto}.qc-modal-backdrop.open .qc-modal{transform:translateY(0);opacity:1}@media(min-width:600px){.qc-modal-backdrop{align-items:center}.qc-modal{border-radius:20px}}.qc-handle{width:44px;height:4px;background:#cbd5e1;border-radius:4px;margin:0 auto 12px}.qc-success-banner{display:flex;align-items:center;gap:10px;background:linear-gradient(135deg,#10b981 0%,#059669 100%);color:#fff;padding:12px 14px;border-radius:12px;margin-bottom:14px;box-shadow:0 4px 12px rgba(16,185,129,0.3)}.qc-success-banner i{font-size:1.4rem}.qc-success-banner .qc-banner-title{font-weight:700;font-size:1rem}.qc-success-banner .qc-banner-sub{font-size:0.82rem;opacity:0.92}.qc-doc-card{background:#f8fafc;border:1px solid var(--border);border-radius:12px;padding:12px;margin-bottom:14px}.qc-doc-id{font-weight:700;color:var(--secondary);font-size:1.05rem;margin-bottom:4px;display:flex;justify-content:space-between;align-items:center;gap:8px}.qc-doc-id .qc-type-pill{display:inline-block;font-size:0.7rem;font-weight:700;padding:3px 10px;border-radius:999px;background:var(--primary);color:#fff}.qc-doc-meta{color:var(--text-muted);font-size:0.88rem;margin-bottom:6px}.qc-doc-items{font-size:0.88rem;color:var(--text)}.qc-section-label{font-size:0.85rem;color:var(--text-muted);margin-bottom:6px;font-weight:500}.qc-upload-row{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px}.qc-upload-btn{display:flex;align-items:center;justify-content:center;gap:8px;padding:14px;border:2px dashed var(--border);border-radius:12px;background:#fff;color:var(--primary);font-weight:600;cursor:pointer;font-family:inherit;font-size:0.92rem;-webkit-tap-highlight-color:transparent;transition:border-color 0.15s ease,background 0.15s ease}.qc-upload-btn:hover{border-color:var(--primary);background:#eef2ff}.qc-upload-btn:active{background:#dbeafe}.qc-upload-btn i{font-size:1.1rem}.qc-upload-hint{font-size:0.78rem;color:var(--text-muted);text-align:center}.qc-preview-grid{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}.qc-preview-item{position:relative;width:80px;height:80px;border-radius:10px;overflow:hidden;box-shadow:0 2px 6px rgba(0,0,0,0.1)}.qc-preview-item img{width:100%;height:100%;object-fit:cover}.qc-preview-remove{position:absolute;top:2px;right:2px;width:22px;height:22px;border-radius:50%;background:rgba(239,68,68,0.95);color:#fff;border:none;font-size:0.8rem;cursor:pointer;display:flex;align-items:center;justify-content:center;line-height:1}.qc-modal-actions{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:14px}.qc-modal-actions .btn{width:100%;min-height:52px;font-size:1rem}.md-modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,0.5);display:none;align-items:flex-end;justify-content:center;z-index:9996;backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px)}.md-modal-backdrop.open{display:flex}.md-modal{background:#fff;width:100%;max-width:520px;border-radius:20px 20px 0 0;padding:16px 16px calc(18px + env(safe-area-inset-bottom,0));box-shadow:0 -10px 30px rgba(0,0,0,0.18);transform:translateY(24px);opacity:0;transition:transform 0.25s cubic-bezier(0.22,1,0.36,1),opacity 0.2s ease;max-height:94vh;overflow-y:auto}.md-modal-backdrop.open .md-modal{transform:translateY(0);opacity:1}@media(min-width:600px){.md-modal-backdrop{align-items:center}.md-modal{border-radius:20px}}.md-handle{width:44px;height:4px;background:#cbd5e1;border-radius:4px;margin:0 auto 12px}.md-status-banner{display:flex;align-items:center;gap:12px;padding:14px 16px;border-radius:14px;margin-bottom:16px;color:#fff}.md-status-banner i{font-size:1.5rem}.md-status-banner .md-banner-title{font-weight:700;font-size:1rem}.md-status-banner .md-banner-sub{font-size:0.82rem;opacity:0.95}.md-banner-ok{background:linear-gradient(135deg,#10b981 0%,#059669 100%)}.md-banner-warn{background:linear-gradient(135deg,#f59e0b 0%,#d97706 100%)}.md-banner-out{background:linear-gradient(135deg,#ef4444 0%,#dc2626 100%)}.md-meta{background:#f8fafc;border:1px solid var(--border);border-radius:12px;padding:12px;margin-bottom:14px;font-size:0.92rem}.md-meta-row{display:flex;justify-content:space-between;padding:4px 0}.md-meta-row .label{color:var(--text-muted)}.md-meta-row .value{font-weight:600;color:var(--secondary)}.md-meta-row .pill{display:inline-block;font-size:0.72rem;font-weight:700;padding:2px 10px;border-radius:999px;background:var(--primary);color:#fff}.md-meta-row .pill.cat-c01{background:#dc2626}.md-meta-row .pill.cat-c02{background:#3b82f6}.md-meta-row .pill.cat-nar{background:#6b7280}.md-section-title{font-size:0.86rem;color:var(--text-muted);margin:14px 0 8px;font-weight:600}.md-stock-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px}.md-stock-cell{background:#fff;border:1px solid var(--border);border-radius:12px;padding:12px 14px}.md-stock-cell .lbl{font-size:0.78rem;color:var(--text-muted)}.md-stock-cell .num{font-size:1.4rem;font-weight:700;color:var(--secondary);font-variant-numeric:tabular-nums}.md-stock-cell.in{background:linear-gradient(135deg,#eff6ff 0%,#dbeafe 100%);border-color:#bfdbfe}.md-stock-cell.pending{background:linear-gradient(135deg,#fffbeb 0%,#fef3c7 100%);border-color:#fde68a}.md-stock-cell.out{background:linear-gradient(135deg,#fef2f2 0%,#fee2e2 100%);border-color:#fecaca}.md-stock-cell.balance{background:linear-gradient(135deg,#ecfdf5 0%,#d1fae5 100%);border-color:#a7f3d0}.md-stock-cell.balance .num{color:#059669;font-size:1.6rem}.md-actions{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:16px}.md-actions .btn{width:100%;min-height:48px}.md-actions.single{grid-template-columns:1fr}.md-gate-list{display:flex;flex-direction:column;gap:8px}.md-gate-row{display:grid;grid-template-columns:1fr auto;align-items:center;gap:10px;background:#fff;border:1px solid var(--border);border-radius:12px;padding:10px 12px}.md-gate-row.is-empty{background:#f8fafc}.md-gate-row.is-unassigned{background:#fffbeb;border-color:#fde68a;border-style:dashed}.md-gate-row .g-name{font-weight:600;color:var(--secondary);font-size:0.9rem}.md-gate-row .g-sub{font-size:0.74rem;color:var(--text-muted);margin-top:2px}.md-gate-row .g-num{text-align:right;font-weight:700;font-size:1.15rem;color:#059669;font-variant-numeric:tabular-nums;white-space:nowrap}.md-gate-row.is-empty .g-num{color:var(--text-muted)}.md-gate-row .g-unit{font-size:0.72rem;font-weight:500;color:var(--text-muted)}.md-gate-empty{background:#f8fafc;border:1px dashed var(--border);border-radius:12px;padding:14px;text-align:center;font-size:0.85rem;color:var(--text-muted)}.md-gate-note{font-size:0.76rem;color:var(--text-muted);margin-top:8px;line-height:1.5}@media(max-width:768px){.draft-table{display:block;min-width:0;border-collapse:collapse}.draft-table thead{display:none}.draft-table tbody,.draft-table tr,.draft-table td{display:block;width:100%}.draft-table tbody tr{background:#fff;border:1px solid var(--border);border-radius:14px;margin:0 0 0.6rem;box-shadow:0 1px 3px rgba(15,23,42,0.06);padding:0;position:relative;overflow:hidden}.draft-table tbody tr.draft-empty-row{background:transparent;border-style:dashed;box-shadow:none}.draft-table tbody tr.draft-empty-row td{display:block;width:100%;text-align:center;padding:1rem 0.8rem;color:var(--text-muted);font-size:0.92rem;border:none}.draft-table tbody td{padding:0.45rem 0.95rem;border:none!important;font-size:0.92rem;word-break:break-word;white-space:normal;background:#fff;position:relative;z-index:1;display:flex!important;justify-content:space-between;align-items:baseline;gap:0.75rem}.draft-table tbody tr:not(.draft-empty-row) td::before{content:attr(data-label);color:var(--text-muted);font-size:0.8rem;font-weight:500;flex:0 0 auto}.draft-table tbody tr:not(.draft-empty-row) td{color:var(--text);text-align:right}.draft-table tbody tr:not(.draft-empty-row) td:nth-child(1){font-weight:700;font-size:1rem;color:var(--secondary);padding:0.7rem 0.95rem 0.55rem;background:linear-gradient(180deg,#f8fafc 0%,#ffffff 100%);border-bottom:1px solid var(--border)!important;display:block!important;text-align:left}.draft-table tbody tr:not(.draft-empty-row) td:nth-child(1)::before{content:none}.draft-table tbody tr:not(.draft-empty-row) td:last-child{display:flex!important;justify-content:flex-end;align-items:center;width:100%!important;padding:0.4rem 0.7rem 0.7rem!important;border-radius:0!important;border-top:1px solid var(--border)!important;background:#f8fafc!important;margin-top:0.2rem}.draft-table tbody tr:not(.draft-empty-row) td:last-child::before{content:none}.draft-table tbody tr:not(.draft-empty-row) td:last-child .draft-delete-btn,.draft-table tbody tr:not(.draft-empty-row) td:last-child .draft-edit-btn{font-size:1rem;padding:6px 14px;border-radius:8px}.draft-table tbody tr:not(.draft-empty-row) td:last-child .draft-delete-btn{color:var(--text-muted)}.draft-table tbody tr:not(.draft-empty-row) td:last-child .draft-delete-btn:hover{color:var(--danger);background:rgba(239,68,68,0.08)}.draft-table tbody tr:not(.draft-empty-row) td:last-child .draft-edit-btn:hover{background:rgba(30,58,138,0.08)}}.draft-item-sub{font-weight:400;font-size:0.76rem;color:var(--text-muted);margin-top:2px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;white-space:normal}.draft-c01-pill{display:inline-block;background:#dc2626;color:#fff;font-size:0.66rem;font-weight:700;padding:1px 7px;border-radius:999px;line-height:1.5}.fab-scroll-top{position:fixed;right:16px;bottom:calc(20px + env(safe-area-inset-bottom,0));z-index:80;width:44px;height:44px;border-radius:50%;border:none;background:var(--primary);color:#fff;font-size:1rem;display:flex;align-items:center;justify-content:center;box-shadow:0 6px 16px rgba(30,58,138,0.32);cursor:pointer;opacity:0;transform:translateY(12px);pointer-events:none;transition:opacity 0.2s ease,transform 0.2s ease;-webkit-tap-highlight-color:transparent}.fab-scroll-top.show{opacity:1;transform:translateY(0);pointer-events:auto}.fab-scroll-top:active{transform:translateY(0) scale(0.94)}@media(max-width:480px){.draft-table thead th:nth-child(4){display:none}.draft-table tbody td:nth-child(4){display:none}.draft-table thead th:nth-child(3){width:32%}}@media(max-width:640px){#inventoryTable{min-width:0;border-collapse:separate}#inventoryTable thead{display:none}#inventoryTable tbody,#inventoryTable tr,#inventoryTable td{display:block;width:100%}#inventoryTable tr{background:#fff;border:1px solid var(--border);border-radius:14px;margin:0 0 0.75rem;box-shadow:0 1px 3px rgba(15,23,42,0.05);padding:0.5rem 0 0.3rem;cursor:pointer;transition:transform 0.12s ease,box-shadow 0.15s ease,border-color 0.15s ease;-webkit-tap-highlight-color:transparent}#inventoryTable tr:active{transform:scale(0.985);box-shadow:0 4px 12px rgba(30,58,138,0.12);border-color:rgba(59,130,246,0.25)}#inventoryTable td[data-label=Balance]{font-size:1.05rem!important;color:var(--primary)}#inventoryTable td[data-label=ชื่อวัสดุ]{font-weight:600;color:var(--secondary);background:#f8fafc}#inventoryTable td{display:flex;justify-content:space-between;align-items:center;padding:0.5rem 0.9rem;border-bottom:1px dashed var(--border);gap:0.75rem;text-align:right;font-size:0.92rem;word-break:break-word}#inventoryTable td:last-child{border-bottom:none}#inventoryTable td::before{content:attr(data-label);font-weight:600;color:var(--text-muted);font-size:0.8rem;text-align:left;flex-shrink:0;min-width:90px}#inventoryTable td.text-right{text-align:right}#inventoryTable td[colspan]::before{content:none}#inventoryTable td[colspan]{justify-content:center;color:var(--text-muted)}.dashboard-pagination-actions .btn{flex:1;min-width:0}#unreturnedTable{min-width:0;border-collapse:collapse;border-spacing:0;table-layout:auto}#unreturnedTable thead{display:none}#unreturnedTable tbody,#unreturnedTable tr,#unreturnedTable td{display:block;width:100%}#unreturnedTable tbody tr{background:#fff;border:1px solid var(--border);border-radius:12px;margin:0 0 0.75rem;box-shadow:var(--shadow-sm);padding:0.25rem 0}#unreturnedTable tbody tr.draft-empty-row{border-radius:12px;border-style:dashed;box-shadow:none}#unreturnedTable td{display:flex;justify-content:space-between;align-items:center;padding:0.55rem 0.9rem;border-bottom:1px dashed var(--border);border-top:none;border-left:none;border-right:none;border-radius:0!important;box-shadow:none;gap:0.75rem;text-align:right;font-size:0.92rem;word-break:break-word}#unreturnedTable td:last-child{border-bottom:none;justify-content:center;padding-top:0.65rem;padding-bottom:0.65rem}#unreturnedTable td::before{content:attr(data-label);font-weight:600;color:var(--text-muted);font-size:0.8rem;text-align:left;flex-shrink:0;min-width:90px}#unreturnedTable td:last-child::before{content:none}#unreturnedTable td[colspan]::before{content:none}#unreturnedTable td[colspan]{justify-content:center;color:var(--text-muted)}#unreturnedTable td:last-child .btn{width:100%;justify-content:center}#historyTable{min-width:0;border-collapse:separate}#historyTable thead{display:none}#historyTable tbody,#historyTable tr,#historyTable td{display:block;width:100%}#historyTable tr{background:#fff;border:1px solid var(--border);border-radius:12px;margin:0 0 0.75rem;box-shadow:var(--shadow-sm);padding:0.25rem 0}#historyTable td{display:flex;justify-content:space-between;align-items:center;padding:0.55rem 0.9rem;border-bottom:1px dashed var(--border);gap:0.75rem;text-align:right;font-size:0.92rem;word-break:break-word}#historyTable td:last-child{border-bottom:none}#historyTable td::before{content:attr(data-label);font-weight:600;color:var(--text-muted);font-size:0.8rem;text-align:left;flex-shrink:0;min-width:90px}#historyTable td[colspan]::before{content:none}#historyTable td[colspan]{justify-content:center;color:var(--text-muted)}#historyTable td[data-label=รายละเอียด]{flex-direction:column;align-items:flex-start;text-align:left}#historyTable td[data-label=รายละเอียด]::before{min-width:0;margin-bottom:0.25rem}.table-container{border:none;background:transparent;overflow:visible}}.metrics-grid{display:grid;grid-template-columns:repeat(1,1fr);gap:1rem;margin-bottom:1.25rem}@media(min-width:600px){.metrics-grid{grid-template-columns:repeat(2,1fr)}}@media(min-width:1000px){.metrics-grid{grid-template-columns:repeat(4,1fr)}}.metric-card{background:var(--surface);padding:1rem;border-radius:var(--radius-lg);box-shadow:var(--shadow-sm);display:flex;align-items:flex-start;gap:1rem}.metric-icon{width:60px;height:60px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.8rem}.icon-blue{background:rgba(59,130,246,0.1);color:var(--primary-light)}.icon-green{background:rgba(16,185,129,0.1);color:var(--success)}.icon-yellow{background:rgba(245,158,11,0.1);color:var(--accent)}.icon-red{background:rgba(239,68,68,0.1);color:var(--danger)}.icon-purple{background:rgba(139,92,246,0.1);color:#8b5cf6}.metric-info h3{font-size:0.9rem;color:var(--text-muted)}.metric-value{font-size:1.8rem;font-weight:700}.metric-split{display:flex;gap:6px;margin-top:6px;flex-wrap:wrap}.metric-chip{border:1px solid var(--border);background:#f8fafc;color:var(--text-muted);font-size:0.72rem;font-weight:500;padding:3px 9px;border-radius:999px;cursor:pointer;font-family:inherit;transition:background 0.15s ease,color 0.15s ease,border-color 0.15s ease;-webkit-tap-highlight-color:transparent}.metric-chip b{font-weight:700}.metric-chip.chip-critical:hover,.metric-chip.chip-critical:active{color:#dc2626;border-color:rgba(220,38,38,0.45);background:rgba(220,38,38,0.06)}.metric-chip.chip-normal:hover,.metric-chip.chip-normal:active{color:var(--primary);border-color:rgba(30,58,138,0.4);background:rgba(30,58,138,0.06)}.pd-doc{background:#f8fafc;border:1px solid var(--border);border-radius:12px;padding:10px 12px;margin-bottom:10px}.pd-doc-head{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:2px}.pd-doc-id{font-weight:700;color:var(--secondary);word-break:break-all}.pd-doc-meta{font-size:0.82rem;color:var(--text-muted);margin-top:2px}.pd-doc-items{font-size:0.86rem;color:var(--text-main);margin-top:6px;line-height:1.5}#inventoryTable tr.subgroup-row td{background:#eef2f7;color:var(--secondary);font-weight:700;font-size:0.88rem;padding:0.55rem 1rem;border-top:2px solid #dbe3ee;border-bottom:1px solid var(--border)}#inventoryTable tr.subgroup-row td i{color:var(--primary);margin-right:6px}#inventoryTable tr.subgroup-row .subgroup-count{font-weight:500;color:var(--text-muted);font-size:0.78rem;margin-left:6px}@media(max-width:640px){#inventoryTable tr.subgroup-row{background:transparent;border:none;box-shadow:none;margin:0.9rem 0 0.5rem;padding:0;cursor:default}#inventoryTable tr.subgroup-row:active{transform:none;box-shadow:none;border-color:transparent}#inventoryTable tr.subgroup-row td{justify-content:flex-start;text-align:left;color:var(--secondary);background:#eef2f7;border-radius:10px;border-bottom:none;padding:0.55rem 0.9rem}}.approval-queue{display:flex;flex-direction:column;gap:1rem}.approval-section-head{display:flex;align-items:center;gap:0.6rem;margin:0.5rem 0 0.25rem;padding:0.6rem 0.9rem;border-radius:12px;font-weight:700;font-size:0.95rem}.approval-section-head:first-child{margin-top:0}.approval-section-head .sec-count{margin-left:auto;font-size:0.8rem;font-weight:700;min-width:22px;height:22px;padding:0 7px;border-radius:999px;display:inline-flex;align-items:center;justify-content:center}.approval-section-head.sec-action{background:rgba(16,185,129,0.1);color:#047857}.approval-section-head.sec-action .sec-count{background:var(--success);color:#fff}.approval-section-head.sec-mine{background:rgba(245,158,11,0.12);color:#b45309}.approval-section-head.sec-mine .sec-count{background:var(--accent);color:#fff}.approval-section-head.sec-other{background:rgba(100,116,139,0.1);color:var(--text-muted)}.approval-section-head.sec-other .sec-count{background:var(--text-muted);color:#fff}.approval-section-sub{font-size:0.78rem;font-weight:400;color:inherit;opacity:0.85}.approval-card{background:var(--surface);border-radius:var(--radius-md);box-shadow:var(--shadow-sm);border:1px solid var(--border);overflow:hidden}.card-header{padding:1rem;background:#f8fafc;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:flex-start;flex-direction:column;gap:0.5rem}.req-id{font-weight:700;font-size:1rem}.card-body{padding:1rem;display:grid;grid-template-columns:1fr;gap:1rem}@media(min-width:768px){.card-body{grid-template-columns:2fr 1fr}}.card-actions{display:flex;flex-direction:column;gap:0.75rem;justify-content:center;border-top:1px dashed var(--border);padding-top:1rem}@media(min-width:768px){.card-actions{border-top:none;border-left:1px dashed var(--border);padding-left:1.5rem}}.btn-approve{background:var(--success);color:white}.btn-reject{background:var(--surface);color:var(--danger);border:1px solid var(--danger)}.qr-filter-bar{display:flex;flex-direction:column;gap:0.75rem;margin-bottom:1.25rem;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);padding:1rem;box-shadow:var(--shadow-sm)}.qr-tab-row{display:flex;flex-wrap:wrap;gap:0.4rem}.qr-filter-bar input{flex:1;min-width:100%;min-height:48px;padding:0.7rem 0.95rem;border:1px solid var(--border);border-radius:var(--radius-sm);font-family:inherit;font-size:0.9rem;background:var(--surface);color:var(--text)}.qr-clear-filter-btn{background:transparent;border:1px solid var(--border);color:var(--text-muted);padding:0.55rem 0.9rem;border-radius:10px;font-size:0.85rem;cursor:pointer;white-space:nowrap;font-family:inherit;min-height:44px;transition:background 0.15s ease,color 0.15s ease,border-color 0.15s ease;-webkit-tap-highlight-color:transparent}.qr-clear-filter-btn:hover,.qr-clear-filter-btn:active{background:rgba(239,68,68,0.08);border-color:rgba(239,68,68,0.3);color:var(--danger)}.qr-summary-bar{display:grid;grid-template-columns:repeat(1,minmax(0,1fr));gap:0.85rem;margin-bottom:1rem}.qr-summary-card{background:linear-gradient(135deg,#ffffff,#f8fbff);border:1px solid var(--border);border-radius:var(--radius-md);padding:1rem;box-shadow:var(--shadow-sm)}.qr-summary-label{font-size:0.8rem;color:var(--text-muted);margin-bottom:0.35rem}.qr-summary-value{font-size:1.35rem;font-weight:700;color:var(--secondary)}.qr-tab{display:inline-flex;align-items:center;gap:0.35rem;padding:0.5rem 0.85rem;border-radius:999px;border:1px solid var(--border);background:var(--surface);color:var(--text-muted);font-family:inherit;font-size:0.82rem;font-weight:500;cursor:pointer;transition:background 0.15s ease,color 0.15s ease,border-color 0.15s ease,transform 0.1s ease;min-height:38px;-webkit-tap-highlight-color:transparent;flex:0 0 auto}.qr-tab i{font-size:0.85rem}.qr-tab.active{background:var(--primary);color:#fff;border-color:var(--primary);box-shadow:0 2px 6px rgba(30,58,138,0.25)}.qr-tab:hover{color:var(--primary);border-color:var(--primary)}.qr-tab:active{transform:scale(0.97)}.qr-gate-header.collapsible{cursor:pointer}.qr-gate-header.collapsible:active{transform:scale(0.99)}.qr-gate-header .qr-gate-chevron{margin-left:0.5rem;transition:transform 0.2s ease;opacity:0.85;flex-shrink:0}.qr-gate-group.collapsed .qr-gate-chevron{transform:rotate(-90deg)}.qr-gate-group.collapsed .qr-type-section{display:none}.qr-type-subheader{cursor:pointer}.qr-type-subheader .qr-type-chevron{margin-left:0.4rem;transition:transform 0.2s ease;color:var(--text-muted)}.qr-type-section.collapsed .qr-type-chevron{transform:rotate(-90deg)}.qr-type-section.collapsed .qr-cards-grid{display:none}.qr-cards-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:1rem}.qr-gate-group{margin-bottom:1.5rem;grid-column:1 / -1}.qr-gate-header{display:flex;align-items:center;gap:0.75rem;padding:0.7rem 1rem;background:linear-gradient(135deg,#1e3a8a 0%,#3b82f6 100%);color:#fff;border-radius:12px;margin-bottom:0.85rem;box-shadow:0 4px 12px rgba(30,58,138,0.18)}.qr-gate-header .qr-gate-icon{width:36px;height:36px;border-radius:10px;background:rgba(255,255,255,0.18);display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0}.qr-gate-header .qr-gate-title{font-weight:700;font-size:1.05rem;line-height:1.2}.qr-gate-header .qr-gate-sub{font-size:0.82rem;opacity:0.9}.qr-gate-header .qr-gate-count{margin-left:auto;background:rgba(255,255,255,0.25);padding:4px 12px;border-radius:999px;font-size:0.85rem;font-weight:700}.qr-type-subheader{display:flex;align-items:center;gap:0.5rem;margin:0.85rem 0.25rem 0.6rem;padding-bottom:0.4rem;border-bottom:2px solid var(--border);font-size:0.92rem;font-weight:600;color:var(--secondary)}.qr-type-subheader i{color:var(--primary)}.qr-type-subheader .qr-type-count{margin-left:auto;font-size:0.78rem;font-weight:600;color:var(--text-muted);background:#f1f5f9;padding:2px 10px;border-radius:999px}.qr-doc-card{background:var(--surface);border:1px solid var(--border);border-radius:14px;box-shadow:0 1px 3px rgba(15,23,42,0.06);display:flex;gap:0;padding:0;align-items:stretch;flex-direction:column;transition:box-shadow 0.2s,transform 0.2s;overflow:hidden}.qr-doc-card .qrc-section{padding:0.85rem 1rem}.qr-doc-card .qrc-divider{border-bottom:1px solid var(--border)}.qr-doc-card .qrc-header{display:flex;align-items:flex-start;justify-content:space-between;gap:0.75rem}.qr-doc-card .qrc-doc-id{font-weight:700;font-size:1rem;color:var(--secondary);letter-spacing:0.2px;line-height:1.2}.qr-doc-card .qrc-date{font-size:0.78rem;color:var(--text-muted);margin-top:0.2rem}.qrc-return-badge{display:inline-flex;align-items:center;gap:4px;background:rgba(245,158,11,0.16);color:#b45309;font-size:0.72rem;font-weight:700;padding:2px 8px;border-radius:999px;margin-right:6px}.qrc-status{display:inline-flex;align-items:center;gap:5px;margin-top:0.4rem;padding:3px 10px;border-radius:999px;font-size:0.74rem;font-weight:600;line-height:1.4}.qrc-status.st-wait{background:#f1f5f9;color:var(--text-muted)}.qrc-status.st-viewed{background:#eff6ff;color:var(--primary);border:1px solid rgba(59,130,246,0.25)}.qrc-status.st-scanned{background:rgba(16,185,129,0.12);color:#047857;border:1px solid rgba(16,185,129,0.3)}.qr-doc-card .qrc-meta-row{display:flex;gap:0.5rem;font-size:0.88rem;line-height:1.5}.qr-doc-card .qrc-meta-row .qrc-label{flex:0 0 60px;color:var(--text-muted)}.qr-doc-card .qrc-meta-row .qrc-value{color:var(--text);font-weight:500;word-break:break-word;flex:1;min-width:0}.qr-doc-card .qrc-item{display:flex;gap:0.6rem;align-items:flex-start;padding:0.4rem 0}.qr-doc-card .qrc-item+.qrc-item{border-top:1px dashed var(--border)}.qr-doc-card .qrc-item-info{flex:1;min-width:0}.qr-doc-card .qrc-item-code{font-family:ui-monospace,"SFMono-Regular",Menlo,monospace;font-size:0.74rem;color:var(--primary);font-weight:600;letter-spacing:0.3px}.qr-doc-card .qrc-item-name{color:var(--text);font-size:0.9rem;line-height:1.4;word-break:break-word;margin-top:0.1rem}.qr-doc-card .qrc-item-qty{flex:0 0 auto;text-align:right;font-weight:700;color:var(--secondary);font-size:0.95rem;white-space:nowrap;padding-top:0.1rem}.qr-doc-card .qrc-actions{display:flex;gap:0.5rem;justify-content:flex-end;background:#f8fafc}.qr-doc-card .qrc-actions .btn{min-height:40px}.qr-doc-card:hover{box-shadow:var(--shadow-lg);transform:translateY(-2px)}.qr-code-wrap{flex-shrink:0;display:flex;flex-direction:column;align-items:center;gap:0.65rem;background:#f8fafc;border-radius:14px;padding:0.85rem;border:1px solid var(--border)}.qr-code-wrap img{width:168px;height:168px;border-radius:10px;background:#fff;padding:0.35rem;border:1px solid rgba(15,23,42,0.06)}.btn-dl-qr{font-size:0.8rem;padding:0.5rem 0.9rem;border:1px solid var(--primary);border-radius:8px;background:transparent;color:var(--primary);cursor:pointer;font-family:inherit;white-space:nowrap;min-height:40px}.btn-dl-qr:hover{background:var(--primary);color:#fff}.qr-doc-info{flex:1;min-width:0;width:100%;display:flex;flex-direction:column;gap:0.85rem}.qr-doc-top{display:flex;flex-direction:column;gap:0.65rem}.qr-doc-header-row{display:flex;align-items:flex-start;justify-content:space-between;gap:0.75rem;flex-wrap:wrap}.qr-doc-id{font-size:1.05rem;font-weight:700;color:var(--text);display:flex;align-items:flex-start;gap:0.4rem;flex-wrap:wrap}.qr-doc-badges{display:flex;gap:0.45rem;flex-wrap:wrap}.qr-doc-chip{display:inline-flex;align-items:center;gap:0.35rem;padding:0.35rem 0.7rem;border-radius:999px;background:#eff6ff;color:var(--primary);font-size:0.76rem;font-weight:600;border:1px solid rgba(59,130,246,0.16)}.qr-doc-date{font-size:0.82rem;color:var(--text-muted);display:flex;align-items:center;gap:0.45rem}.qr-meta-grid{display:grid;grid-template-columns:repeat(1,minmax(0,1fr));gap:0.65rem}.qr-meta-box{background:#f8fafc;border:1px solid var(--border);border-radius:10px;padding:0.75rem 0.85rem}.qr-meta-row{display:flex;flex-direction:column;gap:0.2rem;font-size:0.83rem;color:var(--text-muted)}.qr-meta-row strong{color:var(--text);font-size:0.92rem}.qr-items-mini{margin-top:0.15rem;font-size:0.85rem;color:var(--text-muted);background:#f8fafc;border-radius:10px;padding:0.85rem;border:1px solid var(--border)}.qr-items-title{display:flex;align-items:center;justify-content:space-between;gap:0.75rem;margin-bottom:0.7rem;color:var(--secondary);font-weight:600}.qr-items-list-wrap{display:flex;flex-direction:column;gap:0.55rem}.qr-item-row{display:grid;grid-template-columns:1fr auto;gap:0.75rem;align-items:start;padding:0.6rem 0;border-bottom:1px solid var(--border)}.qr-item-row:last-child{border-bottom:none}.qr-item-main{min-width:0}.qr-item-code{font-size:0.76rem;font-weight:700;color:var(--primary);margin-bottom:0.15rem}.qr-item-name{color:var(--text-main);line-height:1.45;word-break:break-word}.qr-item-qty{font-weight:700;color:var(--secondary);white-space:nowrap;background:white;border:1px solid var(--border);border-radius:999px;padding:0.35rem 0.7rem}.qr-empty{text-align:center;padding:3rem 1rem;color:var(--text-muted);grid-column:1 / -1;background:var(--surface);border-radius:var(--radius-lg);border:1px dashed var(--border)}.qr-empty i{font-size:2.5rem;margin-bottom:0.75rem;display:block}@media(max-width:480px){.qr-doc-card{flex-direction:column;align-items:center}.qr-cards-grid{grid-template-columns:1fr}}.dashboard-toolbar{padding:1rem 1.25rem;border-bottom:1px solid var(--border);display:flex;flex-direction:column;gap:0.9rem}.dashboard-toolbar-head{display:flex;align-items:center;justify-content:space-between;gap:0.75rem;flex-wrap:wrap}.dashboard-toolbar-head h3{margin:0;font-size:1.05rem;display:flex;align-items:center;gap:0.5rem}.dashboard-toolbar-head h3 i{color:var(--primary)}.dashboard-toolbar-filters{display:flex;align-items:center;gap:0.6rem;flex-wrap:wrap}.dash-search-wrap{position:relative;flex:1 1 240px;min-width:0}.dash-search-wrap>i{position:absolute;left:0.9rem;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:0.85rem;pointer-events:none}.dash-search-wrap .form-control{width:100%;min-height:42px;padding-left:2.35rem}.dash-export-btn{width:auto;white-space:nowrap;padding:0.55rem 1.05rem;min-height:42px;flex-shrink:0;margin-left:auto}.dashboard-page-size{display:flex;align-items:center;gap:0.6rem;flex-wrap:wrap;color:var(--text-muted);font-size:0.85rem}.dashboard-subgroup-filter,.dashboard-site-filter{display:flex;align-items:center;gap:0.5rem;color:var(--text-muted);font-size:0.88rem;white-space:nowrap}.dashboard-subgroup-filter select,.dashboard-site-filter select{width:auto;min-width:175px;min-height:42px}.dashboard-subgroup-filter .select2-container{width:200px!important}@media(max-width:640px){.dashboard-toolbar-filters{flex-direction:column;align-items:stretch}.dash-search-wrap{flex:1 1 auto}.dashboard-subgroup-filter,.dashboard-site-filter{width:100%}.dashboard-subgroup-filter select,.dashboard-site-filter select{flex:1;min-width:0}.dashboard-subgroup-filter .select2-container{width:100%!important;flex:1;min-width:0}.dash-export-btn{width:100%}}.dashboard-page-size select{width:auto;min-width:96px;min-height:42px}.dashboard-pagination{display:flex;flex-direction:column;gap:0.75rem;padding:0.9rem 1rem 1rem;border-top:1px solid var(--border);background:#f8fafc}.dashboard-pagination-info{color:var(--text-muted);font-size:0.85rem}.dashboard-pagination-actions{display:flex;gap:0.6rem;flex-wrap:wrap}.dashboard-pagination-actions .btn{min-width:120px}@keyframes slideUp{from{opacity:0;transform:translateY(18px)}to{opacity:1;transform:translateY(0)}}@keyframes slideUpSheet{from{transform:translateY(100%)}to{transform:translateY(0)}}#appPopupModal{display:none;position:fixed;inset:0;z-index:10001;background:rgba(15,23,42,0.58);align-items:center;justify-content:center;padding:1rem;backdrop-filter:blur(6px)}#appPopupModal.open{display:flex}.app-popup-box{width:min(92vw,420px);background:#fff;border-radius:20px;box-shadow:0 28px 70px rgba(15,23,42,0.24);padding:1.25rem;animation:slideUp 0.2s ease-out}.app-popup-head{display:flex;align-items:flex-start;gap:0.9rem;margin-bottom:0.9rem}.app-popup-icon{width:46px;height:46px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0}.app-popup-icon.info{background:rgba(59,130,246,0.12);color:var(--primary)}.app-popup-icon.success{background:rgba(16,185,129,0.12);color:var(--success)}.app-popup-icon.warning{background:rgba(245,158,11,0.14);color:var(--accent)}.app-popup-icon.danger{background:rgba(239,68,68,0.12);color:var(--danger)}.app-popup-title{font-size:1.05rem;font-weight:700;color:var(--secondary);margin-bottom:0.2rem}.app-popup-message{color:var(--text-muted);font-size:0.92rem;line-height:1.6;white-space:pre-wrap}.app-popup-actions{display:flex;flex-direction:column-reverse;gap:0.65rem;margin-top:1rem}.app-popup-actions .btn{width:100%}.app-toast{position:fixed;left:50%;bottom:calc(24px + env(safe-area-inset-bottom,0));transform:translateX(-50%) translateY(20px);background:#1e293b;color:#fff;padding:12px 18px;border-radius:12px;font-size:0.92rem;font-weight:500;display:flex;align-items:center;gap:10px;box-shadow:0 10px 30px rgba(0,0,0,0.25);z-index:10050;opacity:0;pointer-events:none;transition:opacity 0.2s ease,transform 0.2s ease;max-width:90vw}.app-toast.show{opacity:1;transform:translateX(-50%) translateY(0)}.app-toast.success i{color:#34d399}.app-toast.danger i{color:#f87171}.app-toast.warning i{color:#fbbf24}.app-toast.info i{color:#60a5fa}.balance-hint{margin-top:0.45rem;padding:0.65rem 0.8rem;border-radius:12px;background:#f8fafc;border:1px solid var(--border);color:var(--text-muted);font-size:0.88rem;line-height:1.5}.balance-hint strong{color:var(--secondary)}.approver-hint{margin-top:0.45rem;font-size:0.8rem;line-height:1.5;color:var(--text-muted);background:#f8fafc;border:1px dashed var(--border);border-radius:10px;padding:0.5rem 0.7rem}.approver-hint i{color:var(--primary);margin-right:4px}.approver-hint b{color:var(--secondary);font-weight:700}.select2-container--default .select2-selection--single{height:42px;border:1.5px solid var(--border);border-radius:var(--radius-md);display:flex;align-items:center;padding:0 0.75rem;background:#fff}.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:42px;padding-left:0;color:var(--text-main)}.select2-container--default .select2-selection--single .select2-selection__arrow{height:40px}.select2-container--default .select2-search--dropdown .select2-search__field{border:1px solid var(--border);border-radius:6px;padding:6px 10px;font-family:"Prompt",sans-serif;font-size:0.9rem}.select2-dropdown{border:1.5px solid var(--border);border-radius:var(--radius-md);box-shadow:var(--shadow-md)}.select2-results__option{font-family:"Prompt",sans-serif;font-size:0.9rem;padding:8px 12px}.select2-container--default .select2-results__option--highlighted[aria-selected]{background-color:var(--primary)}.popup-spinner{width:18px;height:18px;border:2px solid rgba(59,130,246,0.2);border-top-color:var(--primary);border-radius:50%;animation:spin 0.8s linear infinite}@keyframes spin{to{transform:rotate(360deg)}}#qrModal{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:9999;align-items:center;justify-content:center}#qrModal.open{display:flex}.qr-modal-box{background:#fff;border-radius:16px;padding:2rem;max-width:420px;width:92%;box-shadow:0 24px 64px rgba(0,0,0,0.3);text-align:center;animation:slideUp 0.25s ease-out}.qr-modal-box h3{font-size:1.1rem;font-weight:700;color:#122d4f;margin-bottom:0.25rem}.qr-modal-box .qr-meta{font-size:0.82rem;color:#666;margin-bottom:1rem}#qrCanvas{display:flex;justify-content:center;margin-bottom:1rem}#qrCanvas img,#qrCanvas canvas{border:6px solid #f0f0f0;border-radius:8px}.qr-items-list{text-align:left;font-size:0.82rem;color:#444;background:#f8f9fb;border-radius:8px;padding:0.75rem 1rem;margin-bottom:1rem;max-height:140px;overflow-y:auto}.qr-items-list div{padding:0.15rem 0;border-bottom:1px solid #eee}.qr-items-list div:last-child{border-bottom:none}.qr-modal-actions{display:flex;gap:0.75rem;justify-content:center;flex-direction:column}.btn-qr-dl{background:#122d4f;color:#fff;border:none;border-radius:8px;padding:0.6rem 1.4rem;font-size:0.9rem;cursor:pointer;font-family:inherit}.btn-qr-close{background:#f0f0f0;color:#333;border:none;border-radius:8px;padding:0.6rem 1.4rem;font-size:0.9rem;cursor:pointer;font-family:inherit}.btn-gate{background:var(--primary);color:white}.upload-area{border:2px dashed var(--primary-light);border-radius:var(--radius-md);padding:1.25rem 1rem;text-align:center;background:rgba(59,130,246,0.05)}.upload-btn-row{display:flex;gap:0.75rem;justify-content:center}.upload-btn{display:flex;flex-direction:column;align-items:center;gap:0.35rem;padding:0.85rem 1rem;border-radius:var(--radius-md);border:none;cursor:pointer;font-family:inherit;font-size:0.88rem;font-weight:500;flex:1;transition:transform 0.15s,opacity 0.15s;-webkit-tap-highlight-color:transparent}.upload-btn:active{transform:scale(0.96);opacity:0.85}.upload-btn i{font-size:1.4rem}.upload-btn-camera{background:linear-gradient(135deg,var(--primary),var(--primary-light));color:#fff;box-shadow:0 2px 8px rgba(59,130,246,0.3)}.upload-btn-file{background:#f1f5f9;color:var(--text-main);box-shadow:var(--shadow-sm);border:1px solid var(--border)}.upload-hint{font-size:0.75rem;color:var(--text-muted);margin-top:0.65rem}.file-input{position:absolute;top:0;left:0;width:100%;height:100%;opacity:0;cursor:pointer}.preview-images{display:flex;gap:10px;margin-top:1rem;flex-wrap:wrap}.preview-img-container{position:relative;width:100px;height:100px;border-radius:8px;overflow:hidden;box-shadow:var(--shadow-sm)}.preview-img-container img{width:100%;height:100%;object-fit:cover}.remove-img{position:absolute;top:5px;right:5px;background:rgba(239,68,68,0.9);color:white;border:none;border-radius:50%;width:24px;height:24px;cursor:pointer}.doc-preview{background:#f8fafc;border:1px dashed var(--border);border-radius:var(--radius-md);padding:1.5rem;margin-top:1rem;display:none}.doc-preview.active{display:block}.pick-summary{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);box-shadow:var(--shadow-sm);margin-bottom:1rem;overflow:hidden}.pick-summary-head{display:flex;align-items:center;gap:0.6rem;padding:0.9rem 1rem;cursor:pointer;font-weight:700;color:var(--secondary);background:linear-gradient(135deg,#f8fafc 0%,#eef2f7 100%);-webkit-tap-highlight-color:transparent}.pick-summary-head>i:first-child{color:var(--primary)}.pick-summary-count{background:var(--primary);color:#fff;font-size:0.78rem;padding:1px 9px;border-radius:999px;margin-left:4px}.pick-summary-pk{font-weight:700;color:var(--primary);font-size:0.82rem}.pick-summary-chevron{margin-left:auto;transition:transform 0.2s ease;color:var(--text-muted)}.pick-summary.collapsed .pick-summary-chevron{transform:rotate(-90deg)}.pick-summary.collapsed .pick-summary-body{display:none}.pick-summary-body{padding:0.5rem 1rem 0.9rem}.pick-sum-row{display:flex;align-items:baseline;justify-content:space-between;gap:0.75rem;padding:0.5rem 0;border-bottom:1px dashed var(--border)}.pick-sum-row:last-child{border-bottom:none}.pick-sum-name{color:var(--text-main);font-size:0.92rem;word-break:break-word}.pick-sum-name .pick-sum-code{font-family:ui-monospace,Menlo,monospace;font-size:0.72rem;color:var(--primary);font-weight:600;display:block}.pick-sum-qty{font-weight:700;color:var(--secondary);white-space:nowrap;font-variant-numeric:tabular-nums}.pick-sum-qty .dup{color:var(--accent);font-size:0.74rem;margin-left:5px}.pick-sum-group{display:flex;align-items:center;gap:6px;font-size:0.82rem;font-weight:700;color:var(--primary);background:#eef2ff;border-radius:8px;padding:5px 10px;margin:0.7rem 0 0.15rem}.pick-sum-group:first-child{margin-top:0}.pick-sum-group-count{margin-left:auto;background:var(--primary);color:#fff;font-size:0.72rem;font-weight:700;border-radius:10px;padding:1px 8px}.cw-progress{display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;margin-bottom:0.9rem}.cw-progress-label{font-weight:700;color:var(--secondary);font-size:0.95rem;margin-right:0.25rem}.cw-phase-pill{display:inline-flex;align-items:center;gap:5px;font-size:0.76rem;font-weight:700;padding:3px 10px;border-radius:999px}.cw-phase-pill.p1{background:rgba(16,185,129,0.14);color:#047857}.cw-phase-pill.p2{background:rgba(245,158,11,0.16);color:#b45309}.cw-locked-banner{display:flex;align-items:center;gap:8px;background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;border-radius:12px;padding:0.7rem 0.9rem;font-size:0.86rem;margin-bottom:0.9rem;line-height:1.5}.cw-locked-banner i{color:#ea580c}.cw-locked-banner b{font-weight:700}.cw-closing-wait{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);box-shadow:var(--shadow-sm);padding:1.6rem 1.2rem;text-align:center}.cw-closing-spinner{width:54px;height:54px;margin:0 auto 0.9rem;border-radius:50%;border:4px solid #fde68a;border-top-color:#d97706;animation:cw-spin 0.9s linear infinite}@keyframes cw-spin{to{transform:rotate(360deg)}}.cw-closing-title{font-weight:800;font-size:1.08rem;color:var(--secondary)}.cw-closing-sub{color:var(--text-muted);font-size:0.88rem;line-height:1.6;margin-top:0.4rem;max-width:460px;margin-left:auto;margin-right:auto}.cw-closing-list{margin:1.1rem auto 0;max-width:460px;text-align:left}.cw-closing-row{display:flex;align-items:center;justify-content:space-between;gap:0.6rem;flex-wrap:wrap;padding:0.6rem 0.8rem;border:1px solid var(--border);border-radius:10px;margin-bottom:0.5rem;background:#f8fafc}.cw-closing-row.done{background:#f0fdf4;border-color:#bbf7d0}.cw-closing-id{font-weight:700;color:var(--secondary);word-break:break-all;font-size:0.92rem;flex:1;min-width:0}.cw-closing-chips{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end}.cw-closing-chip{display:inline-flex;align-items:center;gap:4px;font-size:0.72rem;font-weight:700;white-space:nowrap;padding:2px 8px;border-radius:999px}.cw-closing-chip.wait{color:#b45309;background:rgba(245,158,11,0.14)}.cw-closing-chip.done{color:#15803d;background:rgba(22,163,74,0.14)}.cw-closing-next{display:inline-flex;align-items:center;gap:6px;margin-top:1rem;font-size:0.84rem;font-weight:700;color:#b45309;background:rgba(245,158,11,0.14);padding:5px 12px;border-radius:999px}.cw-dots{display:flex;gap:6px;flex-wrap:wrap}.cw-dot{width:26px;height:26px;border-radius:50%;border:1px solid var(--border);background:#fff;color:var(--text-muted);font-size:0.72rem;font-weight:700;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;-webkit-tap-highlight-color:transparent;transition:background 0.15s ease,color 0.15s ease,border-color 0.15s ease}.cw-dot.done{background:var(--success);color:#fff;border-color:var(--success)}.cw-dot.current{box-shadow:0 0 0 3px rgba(59,130,246,0.25);border-color:var(--primary);color:var(--primary)}.cw-dot.current.done{color:#fff}.cw-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);box-shadow:var(--shadow-sm);overflow:hidden}.cw-card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:0.75rem;padding:1rem;border-bottom:1px solid var(--border);background:#f8fafc}.cw-doc-id{font-weight:700;font-size:1.1rem;color:var(--secondary);word-break:break-all}.cw-doc-meta{color:var(--text-muted);font-size:0.85rem;margin-top:0.25rem}.cw-section{padding:1rem}.cw-section+.cw-section{border-top:1px solid var(--border)}.cw-section-title{font-weight:600;color:var(--secondary);margin-bottom:0.6rem;font-size:0.95rem}.cw-item{display:flex;gap:0.6rem;align-items:flex-start;padding:0.5rem 0;border-bottom:1px dashed var(--border)}.cw-item:last-child{border-bottom:none}.cw-item-info{flex:1;min-width:0}.cw-item-code{font-family:ui-monospace,Menlo,monospace;font-size:0.72rem;color:var(--primary);font-weight:600}.cw-item-name{color:var(--text-main);font-size:0.92rem;line-height:1.4;word-break:break-word;margin-top:0.1rem}.cw-item-qty{font-weight:700;color:var(--secondary);white-space:nowrap;padding-top:0.1rem}.cw-nav{display:flex;gap:0.6rem;padding:1rem;border-top:1px solid var(--border);background:#f8fafc;flex-wrap:wrap}.cw-nav .btn{flex:1;min-width:120px}.cw-save-bar{position:sticky;bottom:0;z-index:30;margin:1.25rem -0.4rem 0;padding:0.7rem 0.4rem calc(0.7rem + env(safe-area-inset-bottom,0px));background:linear-gradient(to top,var(--surface) 58%,rgba(255,255,255,0))}.cw-save-hint{text-align:center;font-size:0.84rem;font-weight:600;color:#15803d;margin-bottom:0.45rem}.cw-save-hint.muted{color:var(--text-muted);font-weight:500}.cw-save-bar .btn{width:100%;min-height:58px;font-size:1.12rem;font-weight:700;border-radius:12px}.cw-save-bar .btn.cw-save-ready{box-shadow:0 8px 22px rgba(22,163,74,0.40);animation:cwSavePulse 2.2s ease-in-out infinite}@keyframes cwSavePulse{0%,100%{box-shadow:0 8px 20px rgba(22,163,74,0.32)}50%{box-shadow:0 10px 30px rgba(22,163,74,0.60)}}.cw-gate-tag{display:inline-block;font-family:ui-monospace,Menlo,monospace;font-size:0.78rem;font-weight:700;color:var(--primary);background:#eef2ff;border:1px solid #c7d2fe;padding:1px 7px;border-radius:6px}.inline-login{position:fixed;inset:0;z-index:100000;display:flex;align-items:center;justify-content:center;padding:16px;padding-top:calc(16px + env(safe-area-inset-top,0));padding-bottom:calc(16px + env(safe-area-inset-bottom,0));background:linear-gradient(135deg,#122d4f 0%,#000000 50%,#0073ff 100%);overflow-y:auto}.il-card{width:100%;max-width:420px;background:rgba(255,255,255,0.08);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,0.12);border-radius:22px;padding:26px 20px;box-shadow:0 20px 60px rgba(0,0,0,0.35)}.il-brand{text-align:center;margin-bottom:18px}.il-logo{width:64px;height:64px;margin:0 auto 10px;border-radius:50%;overflow:hidden;background:rgba(255,255,255,0.1);border:2px solid rgba(255,255,255,0.2);display:flex;align-items:center;justify-content:center}.il-logo img{width:100%;height:100%;object-fit:contain}.il-brand h1{color:#fff;font-size:1.6rem;font-weight:700;letter-spacing:1px}.il-brand p{color:rgba(255,255,255,0.6);font-size:0.78rem;margin-top:4px}.il-card h2{color:#fff;font-size:1.1rem;font-weight:600;text-align:center;margin-bottom:18px}.il-group{margin-bottom:14px}.il-group label{display:block;color:rgba(255,255,255,0.7);font-size:0.85rem;margin-bottom:7px}.il-group input{width:100%;min-height:52px;padding:14px 16px;border-radius:12px;border:1px solid rgba(255,255,255,0.15);background:rgba(255,255,255,0.06);color:#fff;font-size:16px;font-family:inherit;outline:none;transition:border-color 0.2s,background 0.2s}.il-group input::placeholder{color:rgba(255,255,255,0.3)}.il-group input:focus{border-color:rgba(253,214,85,0.8);background:rgba(255,255,255,0.1);box-shadow:0 0 0 3px rgba(253,214,85,0.15)}.il-btn{width:100%;min-height:54px;padding:15px;border:none;border-radius:12px;background:linear-gradient(135deg,#fdd655,#ffe387);color:#000;font-size:1.05rem;font-weight:600;font-family:inherit;cursor:pointer;margin-top:6px;transition:transform 0.15s,box-shadow 0.2s;touch-action:manipulation;-webkit-tap-highlight-color:transparent}.il-btn:hover{box-shadow:0 8px 25px rgba(192,176,29,0.4)}.il-btn:active{transform:translateY(1px)}.il-btn:disabled{opacity:0.6;cursor:not-allowed}#ilMsg{text-align:center;margin-top:12px;font-size:0.84rem;min-height:20px;line-height:1.45}#ilMsg.err{color:#ff6b6b}#ilMsg.ok{color:#69f0ae}#ilMsg.info{color:rgba(255,255,255,0.7)}.il-footer{text-align:center;margin-top:14px;color:rgba(255,255,255,0.35);font-size:0.7rem}.cw-photo-flag{display:inline-flex;align-items:center;gap:5px;font-size:0.8rem;font-weight:600;padding:3px 10px;border-radius:999px}.cw-photo-flag.ok{background:rgba(16,185,129,0.12);color:#047857}.cw-photo-flag.none{background:#f1f5f9;color:var(--text-muted)}@media(min-width:600px){.cw-nav .btn{flex:0 1 auto;min-width:150px}}@media(min-width:600px){.avatar{width:40px;height:40px}.page-container{padding:0 1rem}.filter-bar{flex-direction:row;align-items:center}.filter-bar>*{flex:1}.metric-card{align-items:center}.qr-filter-bar{flex-direction:row;align-items:center}.qr-filter-bar input{min-width:180px}.qr-modal-actions{flex-direction:row}}.mobile-tab-nav{position:sticky;top:60px;z-index:90;display:flex;justify-content:space-around;align-items:stretch;background:var(--surface);border-bottom:1px solid var(--border);box-shadow:0 2px 8px rgba(15,23,42,0.04)}.mobile-tab-item{flex:1 1 0;min-width:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;padding:8px 2px;min-height:56px;color:var(--text-muted);background:none;border:none;cursor:pointer;font:inherit;font-size:0.66rem;line-height:1.15;text-align:center;transition:color 0.15s ease,background 0.15s ease;-webkit-tap-highlight-color:transparent;position:relative}.mobile-tab-item i{font-size:1.05rem;position:relative}.mobile-tab-item .tab-badge{position:absolute;top:4px;right:calc(50% - 22px);min-width:16px;height:16px;padding:0 4px;background:var(--danger);color:#fff;border-radius:999px;font-size:0.62rem;font-weight:700;line-height:16px;text-align:center;box-shadow:0 0 0 2px var(--surface);pointer-events:none}.mobile-tab-item .tab-badge[data-hidden="1"]{display:none}.mobile-tab-item span{display:block;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}@media(min-width:380px){.mobile-tab-item{font-size:0.7rem;padding:8px 4px}.mobile-tab-item i{font-size:1.1rem}}.mobile-tab-item.active{color:var(--primary)}.mobile-tab-item.active::after{content:"";position:absolute;left:18%;right:18%;bottom:0;height:3px;background:var(--primary);border-radius:3px 3px 0 0}.mobile-tab-item[hidden]{display:none!important}.nav-link-badge{display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;padding:0 6px;margin-left:6px;background:var(--danger);color:#fff;border-radius:999px;font-size:0.7rem;font-weight:700;line-height:1;box-shadow:0 0 0 2px var(--surface)}.nav-link-badge[data-hidden="1"]{display:none}.nav-more{position:relative;flex:0 0 auto}.nav-more[hidden]{display:none!important}.nav-more-btn{display:inline-flex;align-items:center;gap:7px;padding:0.44rem 0.62rem;min-height:36px;border:1px solid transparent;border-radius:10px;background:transparent;color:var(--text-muted);font-family:inherit;font-size:0.875rem;font-weight:500;line-height:1.2;white-space:nowrap;cursor:pointer;transition:background-color 0.18s,color 0.18s,border-color 0.18s}.nav-more-btn:hover{background:rgba(30,58,138,0.06);color:var(--text-main)}.nav-more.open .nav-more-btn{background:rgba(30,58,138,0.10);border-color:rgba(30,58,138,0.14);color:var(--primary)}.nav-more-caret{font-size:0.68em;opacity:0.65;transition:transform 0.18s ease}.nav-more.open .nav-more-caret{transform:rotate(180deg)}.nav-more-menu{position:fixed;top:0;left:0;z-index:220;display:none;min-width:236px;max-height:min(70vh,520px);overflow-y:auto;padding:6px;background:var(--surface);border:1px solid var(--border);border-radius:14px;box-shadow:0 12px 32px rgba(15,23,42,0.16)}.nav-more.open .nav-more-menu{display:flex;flex-direction:column;gap:2px;animation:navMoreIn 0.16s cubic-bezier(0.22,1,0.36,1)}@keyframes navMoreIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}.nav-more-menu .nav-link{width:100%;justify-content:flex-start;border:1px solid transparent;border-radius:10px;padding:0.6rem 0.7rem;min-height:40px;font-size:0.9rem}.nav-more-menu .nav-link i{width:18px;text-align:center}.nav-more-menu .nav-link-badge{margin-left:auto}@media(prefers-reduced-motion:reduce){.nav-more.open .nav-more-menu{animation:none}.nav-more-caret{transition:none}}.nav-drawer-backdrop{position:fixed;inset:0;background:rgba(15,23,42,0.45);opacity:0;visibility:hidden;transition:opacity 0.2s ease,visibility 0.2s ease;z-index:250}.nav-drawer-backdrop.open{opacity:1;visibility:visible}.nav-drawer{position:fixed;left:0;right:0;bottom:0;background:var(--surface);border-radius:18px 18px 0 0;padding:14px 16px calc(20px + env(safe-area-inset-bottom,0));box-shadow:0 -8px 28px rgba(15,23,42,0.16);transform:translateY(100%);transition:transform 0.25s cubic-bezier(0.22,1,0.36,1);z-index:260}.nav-drawer.open{transform:translateY(0)}.nav-drawer-handle{width:40px;height:4px;border-radius:4px;background:#cbd5e1;margin:0 auto 14px}.nav-drawer-title{font-size:1rem;font-weight:600;color:var(--secondary);margin-bottom:10px;padding-left:4px}.nav-drawer-list{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.nav-drawer-item{display:flex;flex-direction:column;align-items:center;gap:6px;padding:14px 6px;border-radius:14px;background:#f8fafc;border:1px solid var(--border);color:var(--text);font-size:0.8rem;cursor:pointer;font-family:inherit;-webkit-tap-highlight-color:transparent}.nav-drawer-item:hover,.nav-drawer-item:active{background:rgba(59,130,246,0.08);border-color:rgba(59,130,246,0.25);color:var(--primary)}.nav-drawer-item i{font-size:1.3rem;color:var(--primary)}.nav-drawer-item[hidden]{display:none!important}@media(max-width:768px){.nav-links{display:none}.navbar{padding:0.6rem 0.85rem;align-items:center;flex-wrap:nowrap}.brand-text p{display:none}.brand-text h1{font-size:1rem}.user-profile{flex:1;min-width:0;justify-content:flex-end}.user-info{flex:0 1 auto}.user-name{font-size:0.8rem}.user-role{font-size:0.66rem;padding:1px 7px}}@media(min-width:769px){.mobile-tab-nav,.nav-drawer,.nav-drawer-backdrop{display:none!important}}@media(min-width:769px)and (max-width:1180px){.brand-text p{display:none}.navbar{padding:0.85rem 1rem;gap:0.85rem}}@media(min-width:769px){.navbar{padding:0.85rem 1.5rem;align-items:center;gap:1.25rem;flex-wrap:nowrap}.brand,.user-profile{flex:0 0 auto}.nav-links{order:0;flex:1 1 auto;min-width:0;width:auto;margin-top:0;align-items:center;justify-content:flex-start;gap:0.15rem;padding-bottom:0;overflow:hidden}.nav-link{background:transparent;border:1px solid transparent;border-radius:10px;padding:0.44rem 0.62rem;min-height:36px;font-size:0.875rem;line-height:1.2;gap:7px;color:var(--text-muted)}.nav-link i{font-size:0.92em;opacity:0.8}.nav-link:hover{background:rgba(30,58,138,0.06);border-color:transparent;color:var(--text-main)}.nav-link.active{background:rgba(30,58,138,0.10);border-color:rgba(30,58,138,0.14);color:var(--primary);font-weight:600}.nav-link.active i{opacity:1}.page-title{font-size:1.4rem}.tab-content{padding:1.5rem}.grid-layout{gap:2rem}.form-section{padding:1.5rem}.card-header{padding:1rem 1.5rem;align-items:center;flex-direction:row;gap:1rem}.req-id{font-size:1.1rem}.card-body{padding:1.5rem;gap:1.5rem}.actions .btn,.qr-filter-bar .btn{width:auto}}



























































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































































</style>
    <!-- [PHP port 2026-09-23 mobile-first] หน้าเบิก / QR / ถ่ายรูป แบบมือถือเป็นหลัก (≤768px) — build ใหม่จาก GAS ต้องเติมสองบรรทัดนี้กลับ -->
    <link rel="stylesheet" href="<?= assetHref('css/mobile-flow.css') ?>">
    <script src="<?= assetHref('js/mobile-flow.js') ?>" defer></script>
    <!-- [PHP port 2026-09-24 มติ 49] Dashboard: วัสดุยอดนิยม + ตั้ง Min-Max stock — build ใหม่จาก GAS ต้องเติมสองบรรทัดนี้กลับ -->
    <link rel="stylesheet" href="<?= assetHref('css/inventory-insights.css') ?>">
    <script src="<?= assetHref('js/inventory-insights.js') ?>" defer></script>
    <!-- [PHP port 2026-09-24 มติ 50] รอบจ่าย (เบิกก่อน → จ่ายเวลา) — build ใหม่จาก GAS ต้องเติมสองบรรทัดนี้กลับ -->
    <link rel="stylesheet" href="<?= assetHref('css/dispatch-rounds.css') ?>">
    <script src="<?= assetHref('js/dispatch-rounds.js') ?>" defer></script>
    <!-- [PHP port 2026-09-28 Scenario 05] ถ่ายรูปยืนยันรายรายการ + หยิบจริง · สถานะรอบ · Dashboard ต้องตรวจสอบ — build ใหม่จาก GAS ต้องเติมสองบรรทัดนี้กลับ -->
    <link rel="stylesheet" href="<?= assetHref('css/scenario05.css') ?>">
    <script src="<?= assetHref('js/scenario05.js') ?>" defer></script>
    <!-- [PHP port 2026-09-29 Scenario 05 ③] ใบยืม: กำหนดวันคืน · ค้างคืน/เกินกำหนด · ตีชำรุด/สูญหาย · แจ้งเตือน — build ใหม่จาก GAS ต้องเติมสองบรรทัดนี้กลับ -->
    <link rel="stylesheet" href="<?= assetHref('css/borrow-return.css') ?>">
    <script src="<?= assetHref('js/borrow-return.js') ?>" defer></script>
    <!-- [PHP port 2026-09-29] TD ใบเบิกโอนย้ายข้ามไซต์ (แท็บในหน้าเบิก · PM ต้นทางอนุมัติ) — build ใหม่จาก GAS ต้องเติมสองบรรทัดนี้กลับ -->
    <link rel="stylesheet" href="<?= assetHref('css/transfer.css') ?>">
    <script src="<?= assetHref('js/transfer.js') ?>" defer></script>
    <!-- [2026-10-02] TG ใบย้าย Gate (ภายในไซต์) — แท็บโอนย้าย โหมด "ภายใน site" · QR เบิกออก/นำเข้า — build ใหม่จาก GAS ต้องเติมสองบรรทัดนี้กลับ -->
    <link rel="stylesheet" href="<?= assetHref('css/gate-move.css') ?>">
    <script src="<?= assetHref('js/gate-move.js') ?>" defer></script>
    <!-- [PHP port 2026-09-29] SC ใบนับสต๊อก — QR เข้า gate จากหน้าตรวจสอบประจำวัน · อนุมัติปรับยอด — build ใหม่จาก GAS ต้องเติมสองบรรทัดนี้กลับ -->
    <link rel="stylesheet" href="<?= assetHref('css/stock-count.css') ?>">
    <script src="<?= assetHref('js/stock-count.js') ?>" defer></script>
    <!-- [PHP port 2026-09-30] หน้าประวัติ: กรอง IC / วันที่ / ผู้นำจ่าย · ติ๊กเลือกใบ → Export รายงาน PDF พร้อมรูป (api/history_report.php) — build ใหม่จาก GAS ต้องเติมสองบรรทัดนี้กลับ -->
    <link rel="stylesheet" href="<?= assetHref('css/history-report.css') ?>">
    <script src="<?= assetHref('js/history-report.js') ?>" defer></script>
    <!-- [PHP port 2026-10-08] หน้าประวัติ: สวิตช์ ราย เอกสาร / ราย Picking list (RPC getPickingHistory · lib/picking_history.php) — build ใหม่จาก GAS ต้องเติมสามบรรทัดนี้กลับ (หลัง history-report.js) -->
    <link rel="stylesheet" href="<?= assetHref('css/history-picking.css') ?>">
    <script src="<?= assetHref('js/history-picking.js') ?>" defer></script>
    <!-- [PHP port 2026-10-02 · GP-10] รับเข้าคลัง (IN): สายสโตร์ของไซต์เท่านั้น · ที่มาของของ + รูปใบส่งของ/รูปของ — build ใหม่จาก GAS ต้องเติมสองบรรทัดนี้กลับ -->
    <link rel="stylesheet" href="<?= assetHref('css/inbound-ctl.css') ?>">
    <script src="<?= assetHref('js/inbound-ctl.js') ?>" defer></script>
    <!-- [PHP port 2026-10-02 · GP-42] ยอดติดลบ/ยอดไม่พอ = สีแดง + คำเตือน (Dashboard · รายละเอียดวัสดุ · ฟอร์มเบิก) — build ใหม่จาก GAS ต้องเติมสองบรรทัดนี้กลับ -->
    <link rel="stylesheet" href="<?= assetHref('css/neg-stock.css') ?>">
    <script src="<?= assetHref('js/neg-stock.js') ?>" defer></script>
    <!-- [PHP port 2026-10-02 · GP-17/GP-21] QR = Doc + Site + รหัสตรวจสอบจากเว็บ (ห่อ showQRModal · getQrPayload) — build ใหม่จาก GAS ต้องเติมบรรทัดนี้กลับ -->
    <script src="<?= assetHref('js/qr-sign.js') ?>" defer></script>
    <!-- [PHP port 2026-10-02 · health] Dashboard "สถานะตู้" (สัญญาณชีพ · กล้อง/หัวอ่าน · ตู้ออฟไลน์) — build ใหม่จาก GAS ต้องเติมสองบรรทัดนี้กลับ -->
    <link rel="stylesheet" href="<?= assetHref('css/gate-health.css') ?>">
    <script src="<?= assetHref('js/gate-health.js') ?>" defer></script>
    <!-- CONNEXT inventory identity: keep these includes when rebuilding the PHP port. -->
    <!-- Page styles precede brand/theme styles. Keep this order when regenerating index.php. -->
<style>
/* [PHP port 2026-09-25 per-gate · มติ 51] ป้ายยอดรายประตูในตาราง Dashboard */
.dash-gate-chip{display:inline-block;margin:1px 4px 1px 0;padding:1px 7px;border-radius:6px;font-size:0.8rem;white-space:nowrap;background:#eef2ff;border:1px solid #c7d2fe;color:#1e3a8a}
.dash-gate-chip b{font-family:ui-monospace,Menlo,monospace;font-weight:700;margin-right:3px}
.dash-gate-chip.is-empty{background:#f8fafc;border-color:#e2e8f0;color:#94a3b8}
.dash-gate-chip.is-default{background:#fff;border-style:dashed;color:#64748b}
</style>
<style>
.stats-range{margin-bottom:1rem}.stats-range-chips{display:flex;flex-wrap:wrap;gap:6px}.stats-chip{padding:7px 14px;border:1px solid var(--border);background:#fff;border-radius:20px;font-size:0.85rem;cursor:pointer;font-family:inherit;color:var(--text-muted);-webkit-tap-highlight-color:transparent}.stats-chip.active{background:var(--primary);color:#fff;border-color:var(--primary);font-weight:600}.stats-range-custom{display:flex;gap:8px;align-items:center;margin-top:10px;flex-wrap:wrap}.stats-range-custom .form-control{width:auto;flex:1;min-width:120px}.stats-range-label{font-size:0.82rem;color:var(--text-muted);margin-top:8px}.stats-metrics{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:1rem}.stats-metric{color:#fff;border-radius:14px;padding:14px;background:linear-gradient(135deg,#1e3a8a,#2563eb)}.stats-metric.m2{background:linear-gradient(135deg,#0f766e,#14b8a6)}.stats-metric.m3{background:linear-gradient(135deg,#9a3412,#f97316)}.stats-metric.m4{background:linear-gradient(135deg,#6d28d9,#a78bfa)}.stats-metric .v{font-size:1.5rem;font-weight:800;line-height:1.1}.stats-metric .l{font-size:0.78rem;opacity:0.92;margin-top:3px}.stats-type-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin-bottom:1rem}.stats-type{border:1px solid var(--border);border-radius:12px;padding:10px 12px;background:#fff}.stats-type .t{font-size:0.76rem;color:var(--text-muted);display:flex;align-items:center;gap:5px}.stats-type .n{font-size:1.18rem;font-weight:700;color:var(--secondary)}.stats-type .s{font-size:0.72rem;color:var(--text-muted)}.dot{width:9px;height:9px;border-radius:50%;display:inline-block}.stats-card{background:#fff;border:1px solid var(--border);border-radius:14px;padding:14px;margin-bottom:1rem}.stats-card-head{font-weight:700;color:var(--secondary);margin-bottom:12px;display:flex;align-items:center;gap:8px;font-size:0.95rem}.stats-card-head i{color:var(--primary)}.stats-card-head .hint{margin-left:auto;font-size:0.72rem;font-weight:400;color:var(--text-muted)}.stats-chart-wrap{position:relative;height:250px}.stats-bars{display:flex;flex-direction:column;gap:10px}.stats-bar-top{display:flex;justify-content:space-between;align-items:baseline;gap:8px;font-size:0.86rem;margin-bottom:3px}.stats-bar-name{color:var(--text-main);min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.stats-bar-name .rank{display:inline-block;min-width:18px;font-weight:700;color:var(--primary)}.stats-bar-val{font-weight:700;color:var(--secondary);white-space:nowrap;font-variant-numeric:tabular-nums}.stats-bar-track{height:8px;background:#eef2ff;border-radius:6px;overflow:hidden}.stats-bar-fill{height:100%;background:linear-gradient(90deg,#2563eb,#60a5fa);border-radius:6px;min-width:3px}.stats-empty{text-align:center;color:var(--text-muted);padding:1.5rem 0;font-size:0.9rem}.stats-delta{font-size:0.72rem;font-weight:700;background:rgba(255,255,255,0.22);padding:1px 7px;border-radius:8px;vertical-align:middle;white-space:nowrap}.stats-bar-row.drill{cursor:pointer;border-radius:8px;padding:4px 6px;margin:-4px -6px;transition:background 0.12s ease;-webkit-tap-highlight-color:transparent}.stats-bar-row.drill:hover,.stats-bar-row.drill:active{background:#f1f5f9}#statsCompareToggle.active{background:var(--primary);color:#fff;border-color:var(--primary)}@media(min-width:700px){.stats-metrics{grid-template-columns:repeat(4,1fr)}.stats-type-grid{grid-template-columns:repeat(4,1fr)}}@media(min-width:920px){.stats-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem;align-items:start}.stats-grid .stats-card{margin-bottom:0}}

















































</style>
<style>
.dc-wrap{display:flex;flex-direction:column;gap:0.85rem}.dc-month{display:flex;flex-direction:column}.dc-month-head{display:flex;align-items:center;gap:0.55rem;flex-wrap:wrap;padding:0.6rem 0.85rem;background:linear-gradient(135deg,var(--primary) 0%,var(--primary-light) 100%);color:#fff;border-radius:var(--radius-md);cursor:pointer;-webkit-tap-highlight-color:transparent;box-shadow:var(--shadow-sm)}.dc-month-head:hover{filter:brightness(1.06)}.dc-month-chevron{transition:transform 0.2s ease;font-size:0.82rem;opacity:0.92;flex:none}.dc-month.collapsed .dc-month-chevron{transform:rotate(-90deg)}.dc-month.collapsed .dc-month-body{display:none}.dc-month-cal{opacity:0.92;flex:none}.dc-month-title{font-weight:700;font-size:0.98rem}.dc-month-meta{margin-left:auto;font-size:0.78rem;opacity:0.93;font-weight:500;white-space:nowrap}.dc-month-export{margin-left:0.45rem;display:inline-flex;align-items:center;gap:0.35rem;padding:0.34rem 0.72rem;border:none;border-radius:8px;background:#ffffff;color:#dc2626;font-weight:700;font-size:0.78rem;cursor:pointer;white-space:nowrap;box-shadow:var(--shadow-sm)}.dc-month-export:hover{background:#fff5f5}.deduct-field{display:flex;flex-direction:column;gap:0.35rem}.deduct-label{font-weight:600;font-size:0.85rem;color:var(--text);display:flex;align-items:center;gap:0.4rem}.deduct-radio{display:flex;align-items:center;gap:0.45rem;font-size:0.86rem;padding:0.28rem 0;cursor:pointer}.deduct-radio input{width:auto;margin:0}.deduct-half-row{display:grid;grid-template-columns:1fr 1fr;gap:0.6rem}.deduct-half-opt{position:relative;border:1.5px solid var(--border);border-radius:11px;padding:0.6rem 0.75rem;cursor:pointer;display:flex;flex-direction:column;gap:0.12rem;background:#fff;transition:border-color 0.15s,background 0.15s}.deduct-half-opt input{position:absolute;opacity:0;pointer-events:none}.deduct-half-opt.sel{border-color:var(--primary);background:#eff6ff;box-shadow:0 0 0 1px var(--primary) inset}.deduct-half-opt.disabled{opacity:0.45;pointer-events:none}.deduct-half-title{font-weight:800;font-size:0.9rem;color:var(--secondary)}.deduct-half-opt.sel .deduct-half-title{color:var(--primary)}.deduct-half-sub{font-size:0.78rem;color:var(--text)}.deduct-half-count{font-size:0.72rem;color:var(--text-muted)}.dc-month-sign{color:#1d4ed8}.dc-month-sign:hover{background:#eff6ff}.deduct-modal-tabs{display:flex;gap:0.4rem;margin:0.15rem 0 0.7rem;border-bottom:2px solid var(--border)}.deduct-modal-tabs button{flex:1;border:none;background:none;padding:0.55rem 0.5rem;font-family:inherit;font-size:0.86rem;font-weight:700;color:var(--text-muted);cursor:pointer;border-bottom:3px solid transparent;margin-bottom:-2px;display:inline-flex;align-items:center;justify-content:center;gap:0.4rem}.deduct-modal-tabs button.on{color:var(--primary);border-bottom-color:var(--primary)}.deduct-modal-tabs button:hover{color:var(--primary)}.esign-pfilter{display:flex;gap:0.4rem;margin-bottom:0.7rem;flex-wrap:wrap}.esign-pfilter button{border:1.5px solid var(--border);background:#fff;color:var(--text-muted);border-radius:999px;padding:0.32rem 0.85rem;font-family:inherit;font-size:0.78rem;font-weight:700;cursor:pointer}.esign-pfilter button.on{border-color:var(--primary);background:var(--primary);color:#fff}.sig-preview-box{background:#fff;border:1.5px dashed var(--border);border-radius:10px;min-height:92px;display:flex;align-items:center;justify-content:center;padding:0.6rem;margin-bottom:0.75rem}.sig-preview-box img{max-height:80px;max-width:100%}.sig-preview-empty{color:var(--text-muted);font-size:0.85rem;display:flex;align-items:center;gap:0.45rem}.sig-pad-shell{position:relative;border:1.8px dashed #94a3b8;border-radius:12px;background:#fff}.sig-pad-shell.inked{border-style:solid;border-color:var(--primary)}.sig-pad-shell canvas{display:block;width:100%;height:180px;border-radius:12px;touch-action:none}.sig-pad-hint{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#b6c2d4;font-size:0.92rem;font-weight:600;pointer-events:none}.esign-doc{border:1px solid var(--border);border-radius:12px;background:#fff;margin-bottom:0.7rem;overflow:hidden}.esign-doc-head{display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;padding:0.6rem 0.75rem;background:#f8fafc;border-bottom:1px solid var(--border)}.esign-doc-no{font-weight:800;font-size:0.86rem;color:var(--secondary)}.esign-doc-meta{color:var(--text-muted);font-size:0.78rem;flex:1;min-width:120px}.esign-doc-act{display:inline-flex;align-items:center;gap:0.3rem;border:1px solid var(--border);background:#fff;border-radius:8px;padding:0.3rem 0.6rem;font-size:0.76rem;font-weight:700;color:var(--secondary);cursor:pointer;white-space:nowrap}.esign-doc-act:hover{background:#f1f5f9}.esign-doc-act.pdf{color:#dc2626}.esign-slot{display:flex;flex-direction:column;gap:0.45rem;padding:0.6rem 0.75rem;border-bottom:1px solid #f1f5f9}.esign-slot:last-child{border-bottom:none}.esign-slot-head{display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap}.esign-slot-title{font-weight:700;font-size:0.82rem;color:var(--text);min-width:120px;display:inline-flex;align-items:center;gap:0.4rem}.esign-chip{display:inline-flex;align-items:center;gap:0.3rem;border-radius:999px;padding:0.18rem 0.6rem;font-size:0.72rem;font-weight:700}.esign-chip.none{background:#f1f5f9;color:#64748b}.esign-chip.wait{background:#fef9c3;color:#854d0e}.esign-chip.sent{background:#dbeafe;color:#1e40af}.esign-chip.signed{background:#dcfce7;color:#166534}.esign-chip.auto{background:#ffedd5;color:#9a3412}.esign-ctrl{display:flex;align-items:center;gap:0.45rem;flex-wrap:wrap}.esign-ctrl select,.esign-ctrl input[type=text],.esign-ctrl input[type=number]{border:1.5px solid var(--border);border-radius:8px;padding:0.38rem 0.55rem;font-family:inherit;font-size:0.8rem;background:#fff}.esign-ctrl input[type=number]{width:64px}.esign-btn{border:none;border-radius:8px;padding:0.42rem 0.75rem;font-family:inherit;font-size:0.78rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:0.35rem;white-space:nowrap}.esign-btn.primary{background:var(--primary);color:#fff}.esign-btn.soft{background:#eef2ff;color:#3730a3}.esign-btn.ghost{background:#f1f5f9;color:var(--text-muted)}.esign-btn.danger{background:#fee2e2;color:#b91c1c}.esign-btn:disabled{opacity:0.55;cursor:default}.signopen-frame{height:66vh;min-height:340px;border:1px solid var(--border);border-radius:12px;overflow:hidden;background:#f8fafc}.signopen-frame iframe{width:100%;height:100%;border:0;display:block}.esign-link-row{display:flex;gap:0.4rem;align-items:center;width:100%}.esign-link-row input{flex:1;min-width:0;border:1.5px solid var(--border);border-radius:8px;padding:0.38rem 0.55rem;font-size:0.74rem;color:var(--text-muted);background:#f8fafc}.esign-note{font-size:0.74rem;color:var(--text-muted);line-height:1.45}.esign-bulk{border:1px solid #c7d2fe;background:#eef2ff;border-radius:12px;padding:0.6rem 0.75rem;margin-bottom:0.8rem;display:flex;flex-direction:column;gap:0.45rem}.esign-bulk-title{font-weight:700;font-size:0.8rem;color:#3730a3;display:flex;align-items:center;gap:0.4rem}.esign-issue{border:1px solid #a7f3d0;background:#ecfdf5;border-radius:12px;padding:0.6rem 0.75rem;margin-bottom:0.7rem;display:flex;align-items:center;gap:0.6rem;flex-wrap:wrap}.esign-issue-txt{flex:1;min-width:220px;font-size:0.8rem;color:#065f46;line-height:1.55}.sign-tasks-section{background:linear-gradient(135deg,#eef2ff,#f5f3ff);border:1px solid #c7d2fe;border-radius:14px;padding:0.9rem 1rem;margin-bottom:1.1rem}.sign-tasks-head{display:flex;align-items:center;gap:0.5rem;font-weight:800;color:#3730a3;font-size:0.95rem}.sign-tasks-count{background:#4f46e5;color:#fff;border-radius:999px;font-size:0.72rem;padding:0.1rem 0.55rem}.sign-task-card{background:#fff;border:1px solid var(--border);border-radius:12px;padding:0.7rem 0.85rem;margin-top:0.65rem;display:flex;flex-direction:column;gap:0.4rem}.sign-task-top{display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap}.sign-task-role{display:inline-flex;align-items:center;gap:0.3rem;border-radius:8px;padding:0.2rem 0.55rem;font-size:0.72rem;font-weight:800}.sign-task-role.inspector{background:#e0f2fe;color:#075985}.sign-task-role.approver{background:#ede9fe;color:#5b21b6}.sign-task-meta{color:var(--text-muted);font-size:0.78rem}.sign-task-actions{display:flex;gap:0.5rem;flex-wrap:wrap;margin-top:0.2rem}.sig-use-box{border:1.5px solid var(--border);border-radius:10px;background:#fff;min-height:84px;display:flex;align-items:center;justify-content:center;padding:0.5rem}.sig-use-box img{max-height:72px;max-width:100%}.sign-detail-tbl{width:100%;border-collapse:collapse;font-size:0.78rem;min-width:560px}.sign-detail-tbl th{background:var(--secondary);color:#fff;padding:0.4rem 0.5rem;font-weight:600;white-space:nowrap}.sign-detail-tbl td{padding:0.35rem 0.5rem;border-bottom:1px solid var(--border)}.sign-detail-tbl tr:nth-child(even){background:#f8fafc}.sign-detail-totals{display:flex;justify-content:flex-end;gap:1rem;padding:0.45rem 0.6rem;font-size:0.85rem}.sign-detail-totals b{min-width:100px;text-align:right}.deduct-day-quick{display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap}.deduct-day-allbtn{display:inline-flex;align-items:center;gap:0.35rem;padding:0.3rem 0.75rem;border:1px solid var(--primary);background:#fff;color:var(--primary);border-radius:999px;font-size:0.8rem;font-weight:700;cursor:pointer;font-family:inherit;-webkit-tap-highlight-color:transparent}.deduct-day-allbtn.all-on{background:var(--primary);color:#fff}.deduct-day-rangebtn{display:inline-flex;align-items:center;padding:0.3rem 0.7rem;border:1px solid var(--border);background:#fff;color:var(--text-main);border-radius:999px;font-size:0.78rem;font-weight:600;cursor:pointer;font-family:inherit;-webkit-tap-highlight-color:transparent}.deduct-day-rangebtn:hover{border-color:var(--primary);color:var(--primary)}.deduct-day-hint{font-size:0.78rem;color:var(--text-muted);margin-left:auto}.deduct-day-chips{display:flex;flex-wrap:wrap;gap:0.4rem;max-height:300px;overflow-y:auto;padding-top:0.1rem}.deduct-day-chip{display:inline-flex;align-items:center;gap:0.35rem;padding:0.34rem 0.7rem;border:1px solid var(--border);background:#fff;color:var(--text-main);border-radius:999px;font-size:0.82rem;font-weight:600;cursor:pointer;-webkit-tap-highlight-color:transparent;user-select:none;transition:background 0.12s ease,border-color 0.12s ease,color 0.12s ease}.deduct-day-chip .d-ck{font-size:0.7rem;width:0;opacity:0;overflow:hidden;transition:width 0.12s ease,opacity 0.12s ease}.deduct-day-chip.sel{background:var(--primary);color:#fff;border-color:var(--primary)}.deduct-day-chip.sel .d-ck{width:0.7rem;opacity:1}.deduct-day-chip .d-cnt{font-size:0.72rem;opacity:0.65}.deduct-day-chip.sel .d-cnt{opacity:0.92}.deduct-cal{width:100%;display:grid;grid-template-columns:repeat(7,1fr);gap:5px}.deduct-cal-wd{text-align:center;font-size:0.72rem;font-weight:700;color:var(--text-muted);padding:2px 0 4px}.deduct-cal-wd.we{color:#dc2626}.deduct-cal-pad{min-height:42px}.dd-cell{display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:42px;border-radius:9px;font-size:0.92rem;font-weight:700;line-height:1.05}.dd-cell.dd-off{color:#cbd5e1;background:transparent;font-weight:500}.deduct-day-chip.dd-cell{padding:0;gap:0;border-radius:9px}.dd-num{line-height:1.1}.dd-cnt{font-size:0.62rem;font-weight:600;opacity:0.6;margin-top:1px}.deduct-day-chip.dd-cell.sel .dd-cnt{opacity:0.92}.rate-toolbar{display:flex;flex-wrap:wrap;align-items:center;gap:0.6rem;margin:0.4rem 0 0.9rem}.rate-site-box{display:flex;align-items:center;gap:0.4rem}.rate-site-label{font-weight:600;font-size:0.85rem;color:var(--text-muted);white-space:nowrap}.rate-search-box{position:relative;flex:1 1 220px;min-width:180px}.rate-search-box>i{position:absolute;left:0.7rem;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:0.85rem}.rate-search-box input{padding-left:2rem;width:100%}.rate-summary{display:flex;flex-wrap:wrap;align-items:center;gap:0.6rem;margin-bottom:0.7rem;font-size:0.9rem}.rate-summary-chip{padding:0.2rem 0.65rem;border-radius:999px;background:#f1f5f9;color:var(--text-muted);font-size:0.8rem;font-weight:600}.rate-summary-chip.ok{background:#dcfce7;color:#15803d}.rate-table-wrap{overflow-x:auto;border:1px solid var(--border);border-radius:var(--radius-lg,12px)}.rate-table{width:100%;border-collapse:collapse;font-size:0.9rem}.rate-table thead th{background:var(--primary);color:#fff;font-weight:600;padding:0.6rem 0.7rem;text-align:left;white-space:nowrap}.rate-table thead th.rate-th-price{text-align:right;width:170px}.rate-table tbody td{padding:0.5rem 0.7rem;border-top:1px solid var(--border);vertical-align:middle}.rate-row td{background:#fff}.rate-row.rate-dirty td{background:#fef9e7!important}.rate-grp-row td{background:#e8eef7!important;padding:0.45rem 0.7rem!important}.rate-grp-icon{color:var(--primary);margin-right:0.4rem}.rate-grp-count{margin-left:0.6rem;font-size:0.78rem;color:var(--text-muted);font-weight:500}.rate-td-code{font-family:monospace;font-size:0.82rem;color:var(--text-muted);white-space:nowrap}.rate-charged{display:inline-block;margin-left:0.35rem;padding:0.05rem 0.4rem;border-radius:999px;background:#fef3c7;color:#92400e;font-size:0.7rem;font-weight:700}.rate-td-name{font-weight:600;color:var(--text)}.rate-unit{display:inline-block;margin-left:0.5rem;padding:0.05rem 0.5rem;border-radius:6px;background:#eef2f7;color:var(--text-muted);font-size:0.78rem;font-weight:500;white-space:nowrap;vertical-align:middle}.rate-td-price{text-align:right}.rate-price-wrap{position:relative;display:inline-flex;align-items:center}.rate-input{width:120px;text-align:right;padding-right:1.6rem!important}.rate-baht{position:absolute;right:0.6rem;color:var(--text-muted);font-size:0.85rem;pointer-events:none}.rate-empty-search{padding:1rem;text-align:center;color:var(--text-muted)}.rate-alert{display:flex;align-items:flex-start;gap:0.55rem;padding:0.7rem 0.9rem;margin-bottom:0.7rem;border-radius:10px;background:#fef3c7;border:1px solid #fcd34d;color:#92400e;font-size:0.9rem;font-weight:600}.rate-alert>i{margin-top:0.1rem;color:#d97706;font-size:1.05rem;flex:none}.rate-alert .rate-alert-sub{display:block;font-weight:400;font-size:0.82rem;margin-top:0.15rem;color:#a16207}.rate-summary-chip.warn{background:#fee2e2;color:#b91c1c}.rate-grp-row td{cursor:pointer;-webkit-tap-highlight-color:transparent}.rate-grp-row td:hover{background:#dde6f3!important}.rate-grp-chevron{color:var(--text-muted);margin-right:0.2rem;font-size:0.8rem;transition:transform 0.2s ease;display:inline-block;width:0.85rem;text-align:center}.rate-group.collapsed .rate-grp-chevron{transform:rotate(-90deg)}.rate-only-btn{display:inline-flex;align-items:center;gap:0.4rem;padding:0.5rem 0.85rem;border:1.5px solid var(--border);border-radius:var(--radius-md,8px);background:#fff;color:var(--text-muted);font-family:inherit;font-size:0.85rem;font-weight:600;cursor:pointer;white-space:nowrap;-webkit-tap-highlight-color:transparent}.rate-only-btn:hover{border-color:#f59e0b;color:#b45309}.rate-only-btn.active{background:#fef2f2;border-color:#ef4444;color:#b91c1c}.rate-only-btn.active i{color:#ef4444}.rate-row-unset td{background:#fef2f2!important}.rate-row-unset td:first-child{box-shadow:inset 4px 0 0 #ef4444}.rcfm-head{font-size:0.9rem;color:var(--text);margin-bottom:0.6rem}.rcfm-head i{color:var(--primary)}.rcfm-list{max-height:46vh;overflow-y:auto;border:1px solid var(--border);border-radius:8px}.rcfm-row{display:flex;align-items:center;justify-content:space-between;gap:0.8rem;padding:0.45rem 0.7rem;border-top:1px solid var(--border)}.rcfm-row:first-child{border-top:none}.rcfm-name{font-weight:600;font-size:0.85rem;color:var(--text);line-height:1.25}.rcfm-unit{font-weight:400;color:var(--text-muted);font-size:0.78rem}.rcfm-price{white-space:nowrap;font-size:0.85rem;color:var(--text)}.rcfm-arrow{color:var(--text-muted);margin:0 0.15rem;font-size:0.72rem}.rcfm-none{color:var(--text-muted)}.rcfm-clear{color:#dc2626;font-weight:600}.se-toolbar{display:flex;flex-wrap:wrap;align-items:flex-end;gap:0.6rem;margin:0.4rem 0 0.9rem}.se-field{display:flex;flex-direction:column;gap:0.25rem}.se-label{font-weight:600;font-size:0.8rem;color:var(--text-muted);white-space:nowrap}.se-label i{color:var(--primary);margin-right:0.2rem}.se-period-label{align-self:center;padding:0.35rem 0.8rem;border-radius:999px;background:#fef3c7;color:#92400e;font-size:0.85rem;font-weight:700;white-space:nowrap}.se-pdf-btn{background:#dc2626!important;border-color:#dc2626!important}.se-pdf-btn:hover{background:#b91c1c!important}.se-wrap{overflow-x:auto;border:1px solid var(--border);border-radius:var(--radius-lg,12px);background:#fff}.se-table{border-collapse:collapse;width:100%;min-width:1150px;font-size:0.74rem}.se-table thead th{background:var(--secondary,#13294b);color:#fff;font-weight:600;padding:0.26rem 0.18rem;text-align:center;border:1px solid #3b4a63;font-size:0.66rem;line-height:1.2;vertical-align:middle}.se-table thead th.se-th-sub{background:#1d3a6b;font-weight:500;font-size:0.62rem}.se-table tbody td{border:1px solid var(--border);padding:0;vertical-align:middle;background:#fff}.se-table tbody tr:nth-child(even) td{background:#f8fafc}.se-table td.se-td-idx{text-align:center;color:var(--text-muted);font-weight:600;min-width:26px;font-size:0.7rem}.se-in{width:100%;min-width:0;border:none;background:transparent;font-family:inherit;font-size:0.74rem;text-align:right;padding:0.34rem 0.2rem;outline:none}.se-in::-webkit-outer-spin-button,.se-in::-webkit-inner-spin-button{-webkit-appearance:none;margin:0}.se-in[type=number]{-moz-appearance:textfield}.se-in:focus{background:#eff6ff;box-shadow:inset 0 0 0 2px var(--primary);border-radius:4px}.se-in:disabled{background:transparent;color:#c3c9d2;cursor:not-allowed}.se-in.se-ro{background:#eef2f7;cursor:default}.se-in.se-ro:focus{background:#eef2f7;box-shadow:none;border-radius:0}.se-in.se-prev,.se-shop-btn.se-prev{background:#fff4d6!important}.se-row-outside .se-in.se-ro,.se-row-outside .se-in.se-prev{background:transparent!important}.fs-in.fs-prev{background:#fff4d6!important}.fs-tablebtn{border:1.5px dashed #a78bfa;background:#f5f3ff;color:#6d28d9;border-radius:10px;padding:0.5rem 0.85rem;font-family:inherit;font-size:0.84rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:0.4rem;margin-top:0.6rem}.fs-tablebtn:hover{background:#ede9fe}.fs-subnav{display:flex;flex-wrap:wrap;gap:0.4rem;border-bottom:2px solid #eef2f7;margin-bottom:0.9rem;padding-bottom:0.1rem}.fs-subtab{border:none;background:transparent;color:var(--text-muted, #64748b);font-family:inherit;font-weight:700;font-size:0.92rem;cursor:pointer;padding:0.6rem 0.95rem;border-radius:9px 9px 0 0;position:relative;display:inline-flex;align-items:center;gap:0.4rem;border-bottom:2.5px solid transparent;margin-bottom:-2px;transition:color 0.12s,background 0.12s}.fs-subtab:hover{color:var(--primary, #13294b);background:#f6f8fc}.fs-subtab.active{color:var(--primary, #13294b);border-bottom-color:var(--primary, #13294b)}#fingerscan-page [hidden],.fs-subtab[hidden],.fs-section[hidden],.fs-bs-sub[hidden],.fs-bs-only[hidden]{display:none!important}.fs-subbadge{background:#dc2626;color:#fff;font-size:0.68rem;font-weight:800;min-width:1.15rem;height:1.15rem;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;padding:0 0.32rem}.fs-subbadge[data-hidden="1"]{display:none}.fs-datenav{display:inline-flex;align-items:center;gap:0.35rem}.fs-datebtn{border:1px solid var(--border, #d7deea);background:#fff;color:var(--secondary, #1e293b);border-radius:9px;padding:0.45rem 0.6rem;font-family:inherit;font-size:0.9rem;cursor:pointer;display:inline-flex;align-items:center;gap:0.3rem;transition:background 0.12s,border-color 0.12s}.fs-datebtn:hover{background:#eef2f8;border-color:#b9c4d6}.fs-datebtn.fs-today{font-weight:700;color:var(--primary, #13294b)}.fs-datebtn:disabled{opacity:0.4;cursor:not-allowed}.fs-daystrip{display:flex;flex-wrap:wrap;gap:0.3rem;margin:0.15rem 0 0.7rem}.fs-strip-half{flex-basis:100%;height:0;margin:0.1rem 0 0}.fs-link-item{padding:0.55rem 0.1rem;border-bottom:1px solid #eef2f7}.fs-link-item:last-child{border-bottom:none}.fs-link-head{display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;font-size:0.9rem}.fs-link-head .fs-link-name{font-weight:700;color:var(--secondary)}.fs-link-url{display:flex;gap:0.4rem;margin-top:0.35rem}.fs-link-url input{flex:1;min-width:0;font-size:0.76rem;padding:0.35rem 0.5rem}.fs-link-url .btn{width:auto;flex:none;padding:0.35rem 0.65rem}.fs-day-group{border:1px solid #e5e9f0;border-radius:12px;margin-bottom:0.7rem;overflow:hidden}.fs-day-head{display:flex;align-items:center;gap:0.6rem;padding:0.6rem 0.85rem;background:#f8fafc;cursor:pointer;user-select:none}.fs-day-head:hover{background:#f1f5f9}.fs-day-caret{transition:transform 0.18s ease;color:#64748b;font-size:0.82rem;width:0.9rem}.fs-day-head.collapsed .fs-day-caret{transform:rotate(-90deg)}.fs-day-date{font-weight:700;color:var(--secondary)}.fs-day-count{font-size:0.8rem;color:#64748b;margin-left:auto;text-align:right}.fs-day-body{padding:0.15rem 0.5rem 0.5rem}.fs-mango-line{display:flex;align-items:center;gap:0.3rem;flex-wrap:wrap;font-size:0.74rem;color:var(--text-muted);margin-top:0.12rem;font-weight:500}.fs-mango-line i{font-size:0.72rem;color:#94a3b8;flex:none}.fs-mango-code{font-family:monospace;font-size:0.7rem;color:var(--text-muted);white-space:nowrap}.fs-mango-none{color:#b45309;font-weight:400;font-style:italic}.fs-mango-none i{color:#d97706}.fs-save-row{display:flex;align-items:center;gap:0.55rem;padding:0.45rem 0.15rem;border-bottom:1px dashed #eef2f7;font-size:0.85rem}.fs-save-row:last-child{border-bottom:none}.fs-save-name{font-weight:600;color:var(--secondary);flex:1;min-width:0;overflow-wrap:anywhere}.fs-save-meta{color:#475569;font-size:0.8rem;white-space:nowrap}.fs-save-badge{font-size:0.68rem;padding:0.12rem 0.5rem;border-radius:999px;font-weight:700;flex:none;white-space:nowrap}.fs-dash-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:0.6rem;margin-bottom:0.4rem}.fs-dash-card{background:#f8fafc;border:1px solid #e5e9f0;border-radius:12px;padding:0.65rem 0.8rem}.fs-dash-card .fsd-lbl{font-size:0.74rem;color:#64748b;display:flex;align-items:center;gap:0.35rem}.fs-dash-card .fsd-val{font-size:1.28rem;font-weight:700;color:var(--secondary);margin-top:0.12rem}.fs-dash-card .fsd-sub{font-size:0.72rem;color:#94a3b8;margin-top:0.12rem}.fs-dash-sec{font-weight:700;color:var(--secondary);margin:1.1rem 0 0.5rem;display:flex;align-items:center;gap:0.45rem}.fs-dash-bars{display:flex;align-items:flex-end;gap:2px;padding:0.3rem 0.1rem 0}.fs-dash-col{flex:1;min-width:0;display:flex;flex-direction:column;align-items:center;gap:2px}.fs-dash-col .bars{display:flex;align-items:flex-end;gap:1px;height:100px;width:100%;justify-content:center}.fs-dash-col .b1{width:38%;max-width:9px;background:#93c5fd;border-radius:2px 2px 0 0}.fs-dash-col .b2{width:38%;max-width:9px;background:#34d399;border-radius:2px 2px 0 0}.fs-dash-col .dnum{font-size:0.58rem;color:#94a3b8}.fs-dash-col.fsd-today .dnum{color:var(--primary);font-weight:700}.fs-dash-legend{display:flex;gap:0.9rem;font-size:0.72rem;color:#64748b;margin-top:0.35rem;flex-wrap:wrap}.fs-dash-legend b{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:0.25rem;vertical-align:-1px}.fs-daychip{min-width:2.05rem;text-align:center;border-radius:8px;padding:0.28rem 0.35rem;font-size:0.78rem;font-weight:700;cursor:pointer;border:1.5px solid transparent;line-height:1.05;position:relative;transition:transform 0.08s;user-select:none}.fs-daychip:hover{transform:translateY(-1px)}.fs-daychip small{display:block;font-size:0.58rem;font-weight:600;opacity:0.85;margin-top:1px}.fs-daychip.dc-none{background:#f1f5f9;color:#94a3b8;border-color:#e2e8f0}.fs-daychip.dc-recorded{background:#fff7ed;color:#c2410c;border-color:#fed7aa}.fs-daychip.dc-verified{background:#f0fdf4;color:#15803d;border-color:#bbf7d0}.fs-daychip.dc-future{background:#fbfcfe;color:#cbd5e1;border-color:#eef2f7;cursor:default}.fs-daychip.dc-future:hover{transform:none}.fs-daychip.dc-sel{outline:2.5px solid var(--primary, #13294b);outline-offset:1px}.fs-daychip.dc-today{box-shadow:0 0 0 2px #fde68a inset}.fs-strip-legend{display:inline-flex;align-items:center;gap:0.9rem;flex-wrap:wrap;font-size:0.72rem;color:var(--text-muted, #64748b);margin-left:0.2rem}.fs-strip-legend b{display:inline-block;width:11px;height:11px;border-radius:3px;vertical-align:-1px;margin-right:0.25rem}.fs-vcard{border:1.5px solid var(--border, #e2e8f0);border-radius:14px;margin-top:0.9rem;overflow:hidden}.fs-vcard-head{padding:0.7rem 1rem;font-weight:800;font-size:0.95rem;display:flex;align-items:center;gap:0.5rem}.fs-vcard-body{padding:0.9rem 1rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap}.fs-vcard.v-pending{border-color:#fed7aa}.fs-vcard.v-pending .fs-vcard-head{background:#fff7ed;color:#c2410c}.fs-vcard.v-done{border-color:#bbf7d0}.fs-vcard.v-done .fs-vcard-head{background:#f0fdf4;color:#15803d}.fs-vcard.v-none{border-color:#e2e8f0}.fs-vcard.v-none .fs-vcard-head{background:#f8fafc;color:#64748b}.fs-vcard-sig{max-width:190px;max-height:74px;border:1px solid #eef2f7;border-radius:8px;background:#fff;padding:3px}.se-legend{display:inline-flex;align-items:center;gap:0.3rem;font-size:0.74rem;color:var(--text-muted);white-space:nowrap}.se-lg-prev,.se-lg-ro{display:inline-block;width:12px;height:12px;border-radius:3px;border:1px solid var(--border);flex:none}.se-lg-prev{background:#fff4d6}.se-lg-ro{background:#eef2f7}.se-in.se-name{min-width:0;text-align:left;font-weight:600}.se-in.se-note{min-width:0;text-align:left}.se-in.se-auto{color:#1d4ed8}.se-td-total{text-align:right;font-weight:700;background:#fff7d6!important;padding:0.34rem 0.25rem!important;white-space:nowrap;font-size:0.74rem}.se-table tfoot td{background:#fdecc8!important;font-weight:700;padding:0.3rem 0.18rem;text-align:right;border:1px solid #e7c26a;white-space:nowrap;font-size:0.68rem}.se-table tfoot td.se-foot-lbl{text-align:center;color:#92400e}.se-del-btn{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;margin:0 3px;border:none;border-radius:8px;background:#fee2e2;color:#b91c1c;cursor:pointer;font-size:0.8rem}.se-del-btn:hover{background:#fecaca}.se-summary-bar{display:flex;flex-wrap:wrap;gap:0.6rem;align-items:center;margin:0.8rem 0 0.2rem}.se-sum-chip{padding:0.35rem 0.85rem;border-radius:999px;background:#f1f5f9;font-size:0.83rem;font-weight:600;color:var(--text-muted)}.se-sum-chip b{color:var(--secondary)}.se-sum-chip.se-sum-grand{background:#13294b;color:#fbe9a6}.se-sum-chip.se-sum-grand b{color:#fff;font-size:0.95rem}.se-dirty-hint{font-size:0.8rem;color:#b45309;font-weight:600;display:none;align-items:center;gap:0.3rem}.se-dirty-hint.show{display:inline-flex}.se-sign-box{border:1px solid var(--border);border-radius:10px;padding:0.7rem 0.8rem;background:#f8fafc}.se-sign-box-title{font-weight:700;font-size:0.85rem;color:var(--secondary);margin-bottom:0.5rem}.se-sign-box-title i{color:var(--primary);margin-right:0.25rem}.se-export-hint{font-size:0.78rem;color:var(--text-muted);background:#eef2f7;border-radius:8px;padding:0.5rem 0.7rem}.se-export-hint i{color:var(--primary);margin-right:0.25rem}.se-td-pay{padding:0.34rem 0.25rem!important;font-size:0.66rem;color:#1d4ed8;font-weight:600;line-height:1.25;word-break:break-word}.se-pay-none{color:#b0b8c4;font-weight:400}.se-pay-code{color:var(--text-muted);font-weight:500;font-size:0.6rem;font-family:monospace}.se-td-out{text-align:center;min-width:32px}.se-out-chk{display:inline-flex;align-items:center;justify-content:center;cursor:pointer;padding:0.25rem}.se-out-chk input{width:1rem;height:1rem;cursor:pointer;accent-color:#dc2626}.se-table tbody tr.se-row-outside td{background:#fee2e2!important}.se-table tbody tr.se-row-outside td.se-td-total{background:#fecaca!important}.se-table tbody tr.se-row-outside .se-name{color:#b91c1c}.se-shop-btn{display:flex;flex-direction:column;gap:1px;width:100%;min-width:0;border:none;background:transparent;font-family:inherit;font-size:0.66rem;cursor:pointer;text-align:left;padding:0.28rem 0.25rem;border-radius:6px;-webkit-tap-highlight-color:transparent}.se-shop-btn:hover{background:#eff6ff;box-shadow:inset 0 0 0 1.5px var(--primary)}.se-shop-btn .se-shop-items{font-weight:600;color:var(--text);line-height:1.25}.se-shop-btn .se-shop-amt{color:#1d4ed8;font-weight:700}.se-shop-btn .se-shop-none{color:#b0b8c4}.se-shop-row{display:flex;align-items:center;gap:0.55rem;border:1px solid var(--border);border-radius:8px;padding:0.45rem 0.6rem;cursor:pointer;background:#fff}.se-shop-row:hover{border-color:var(--primary);background:#f0f6ff}.se-shop-row input.se-shop-cb{width:1.15rem;height:1.15rem;cursor:pointer;flex:none}.se-shop-lb{font-weight:600;font-size:0.85rem;flex:1}.se-shop-legacy{color:#b45309;font-size:0.72rem;font-weight:500}.se-shop-pr{color:var(--text-muted);font-size:0.78rem;white-space:nowrap}.se-shop-x{color:var(--text-muted);font-size:0.8rem}.se-shop-qty{width:56px;text-align:center;border:1px solid var(--border);border-radius:6px;padding:0.25rem;font-family:inherit;font-size:0.82rem}.se-shop-total-bar{background:#fff7d6;border:1px solid #e7c26a;border-radius:8px;padding:0.5rem 0.7rem;font-size:0.85rem;text-align:right}.secfg-item-row{display:grid;grid-template-columns:1fr 110px 36px;gap:0.45rem;align-items:center}.scfg-addmango-sel{display:flex;align-items:center;gap:0.5rem;border:1px solid #86efac;background:#f0fdf4;border-radius:8px;padding:0.45rem 0.65rem;font-size:0.83rem;margin-bottom:0.4rem}.scfg-addmango-sel .mp-name{font-weight:600;overflow:hidden;text-overflow:ellipsis;flex:1}.scfg-addmango-sel .mp-code{font-family:monospace;font-size:0.72rem;color:var(--text-muted);flex:none}.scfg-addmango-x{border:none;background:transparent;color:#b91c1c;font-size:1.15rem;line-height:1;cursor:pointer;padding:0 0.2rem;flex:none}.scfg-addmango-x:hover{color:#7f1d1d}@media(max-width:640px){.se-toolbar{align-items:stretch}.se-toolbar .se-field,.se-toolbar .form-control{width:100%!important}.se-toolbar .btn{width:100%!important}.se-period-label{align-self:flex-start}}.scfg-switch{display:inline-flex;align-items:center;cursor:pointer;-webkit-tap-highlight-color:transparent;user-select:none}.scfg-switch input{position:absolute;opacity:0;width:0;height:0}.scfg-track{width:46px;height:26px;border-radius:999px;background:#cbd5e1;position:relative;flex:none;transition:background 0.18s ease}.scfg-track::after{content:"";position:absolute;top:3px;left:3px;width:20px;height:20px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,0.25);transition:left 0.18s ease}.scfg-switch input:checked+.scfg-track{background:#16a34a}.scfg-switch input:checked+.scfg-track::after{left:23px}.scfg-state{margin-left:0.55rem;font-weight:700;font-size:0.82rem;min-width:74px;text-align:left}.scfg-row-off td{background:#fef2f2!important}.scfg-row-off td:first-child{box-shadow:inset 4px 0 0 #ef4444}.scfg-row-off .rate-td-name{color:#9ca3af;text-decoration:line-through}.scfg-row.rate-dirty td{background:#fef9e7!important}.scfg-mango-btn{display:inline-flex;align-items:center;gap:0.45rem;max-width:100%;border:1px solid var(--border);background:#fff;border-radius:8px;padding:0.35rem 0.6rem;font-family:inherit;font-size:0.82rem;cursor:pointer;text-align:left;-webkit-tap-highlight-color:transparent}.scfg-mango-btn:hover{border-color:var(--primary);background:#f0f6ff}.scfg-mango-btn .scfg-mango-name{font-weight:600;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:190px}.scfg-mango-btn .scfg-mango-code{color:var(--text-muted);font-family:monospace;font-size:0.72rem;white-space:nowrap}.scfg-mango-btn .scfg-mango-none{color:#b45309;font-weight:600}.scfg-row-off .scfg-mango-btn{opacity:0.55}.scfg-borrow-badge{display:inline-flex;align-items:center;gap:0.25rem;margin-left:0.4rem;vertical-align:middle;background:#fffbeb;border:1px solid #fde68a;color:#b45309;border-radius:999px;padding:0.1rem 0.45rem;font-size:0.7rem;font-weight:700;white-space:nowrap;text-decoration:none}.scfg-row-off .scfg-borrow-badge{background:#fef2f2;border-color:#fecaca;color:#b91c1c}.scfg-mp-label{font-weight:700;font-size:0.82rem;color:var(--secondary);margin-bottom:0.35rem}.scfg-mp-label i{color:var(--primary);margin-right:0.25rem}.scfg-mp-list{display:flex;flex-direction:column;gap:0.3rem;max-height:220px;overflow-y:auto}.scfg-mp-item{display:flex;align-items:center;gap:0.55rem;border:1px solid var(--border);background:#fff;border-radius:8px;padding:0.45rem 0.65rem;font-family:inherit;font-size:0.83rem;cursor:pointer;text-align:left;width:100%;-webkit-tap-highlight-color:transparent}.scfg-mp-item:hover{border-color:var(--primary);background:#f0f6ff}.scfg-mp-item.current{border-color:#16a34a;background:#f0fdf4}.scfg-mp-row{display:flex;gap:0.35rem;align-items:stretch}.scfg-mp-row .scfg-mp-item{flex:1;min-width:0}.scfg-mp-addbtn{flex:none;width:2.3rem;border:1px dashed #94a3b8;background:#f8fafc;color:#475569;border-radius:8px;cursor:pointer;font-size:0.85rem;-webkit-tap-highlight-color:transparent}.scfg-mp-addbtn:hover{background:#eef2ff;color:var(--primary);border-color:var(--primary)}.scfg-mp-addbtn:disabled{opacity:0.5;cursor:wait}.scfg-mp-delbtn{flex:none;width:2.3rem;border:1px solid #fecaca;background:#fff;color:#b91c1c;border-radius:8px;cursor:pointer;font-size:0.8rem;-webkit-tap-highlight-color:transparent}.scfg-mp-delbtn:hover{background:#fef2f2;border-color:#dc2626}.scfg-mp-delbtn:disabled{opacity:0.5;cursor:wait}.scfg-hist-item{padding:0.4rem 0.15rem;border-bottom:1px dashed #eef2f7;font-size:0.82rem}.scfg-hist-item:last-child{border-bottom:none}.scfg-hist-tag{display:inline-block;font-size:0.68rem;font-weight:700;padding:0.08rem 0.45rem;border-radius:999px;border:1px solid}.scfg-mp-item .mp-code{font-family:monospace;font-size:0.72rem;color:var(--text-muted);flex:none}.scfg-mp-item .mp-name{font-weight:600;overflow:hidden;text-overflow:ellipsis}.scfg-mp-note{font-size:0.75rem;color:var(--text-muted);padding:0.25rem 0.1rem}.dc-month-body{margin-top:0.7rem;display:flex;flex-direction:column;gap:0.85rem}.dc-day{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);box-shadow:var(--shadow-sm);overflow:hidden}.dc-day.dc-confirmed{opacity:0.96}.dc-day-head{display:flex;align-items:flex-start;gap:0.6rem;flex-wrap:wrap;padding:0.85rem 0.95rem;cursor:pointer;-webkit-tap-highlight-color:transparent;background:linear-gradient(135deg,#f8fafc 0%,#eef2f7 100%)}.dc-day-head:hover{background:linear-gradient(135deg,#f1f5f9 0%,#e6ebf2 100%)}.dc-chevron{transition:transform 0.2s ease;color:var(--text-muted);flex:none;margin-top:0.25rem}.dc-day.collapsed .dc-chevron{transform:rotate(-90deg)}.dc-day.collapsed .dc-day-body{display:none}.dc-head-main{flex:1 1 55%;min-width:150px}.dc-day-title{font-weight:700;color:var(--secondary);font-size:1rem;display:flex;align-items:center;gap:0.45rem;flex-wrap:wrap}.dc-day-title .dc-cal{color:var(--primary)}.dc-badges{display:flex;gap:0.35rem;flex-wrap:wrap;margin-top:0.4rem}.dc-badge{font-size:0.72rem;font-weight:600;padding:2px 9px;border-radius:999px;background:#eef2f7;color:var(--text-muted);white-space:nowrap;display:inline-flex;align-items:center;gap:0.3rem}.dc-badge.dc-badge-charge{background:#fef3c7;color:#92400e}.dc-badge.dc-badge-tick{background:#dcfce7;color:#166534}.dc-head-actions{flex:0 0 auto;margin-left:auto;display:flex;align-items:center;gap:0.5rem}.dc-confirm-btn{width:auto!important;padding:0.5rem 1rem!important;font-size:0.83rem;white-space:nowrap}.dc-confirmed-badge{display:inline-flex;align-items:center;gap:0.35rem;color:#16a34a;font-weight:600;font-size:0.82rem;flex-wrap:wrap}.dc-day-foot{display:flex;align-items:center;gap:0.6rem;flex-wrap:wrap;margin-top:0.7rem;padding-top:0.7rem;border-top:1px dashed var(--border)}.dc-foot-hint{font-size:0.78rem;color:var(--text-muted);margin-right:auto;display:inline-flex;align-items:center;gap:0.35rem}.dc-day-body{padding:0.7rem;background:var(--background)}.dc-table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;border:1px solid var(--border);border-radius:var(--radius-md);background:var(--surface)}.dc-table{width:100%;border-collapse:collapse;font-size:0.88rem;min-width:660px}.dc-table thead th{background:linear-gradient(135deg,#eef2f7 0%,#dde5ee 100%);color:var(--secondary);font-weight:700;font-size:0.78rem;text-align:left;padding:0.6rem 0.7rem;border-bottom:2px solid #cbd5e1;white-space:nowrap}.dc-table tbody td{padding:0.5rem 0.7rem;border-bottom:1px solid var(--border);vertical-align:middle}.dc-row:hover td{background:#f8fafc}.dc-row.dc-ticked td{background:#f0fdf4}.dc-th-tick,.dc-td-tick{width:44px;text-align:center}.dc-td-tick input{width:1.3rem;height:1.3rem;cursor:pointer;accent-color:#16a34a;vertical-align:middle}.dc-th-qty,.dc-td-qty{text-align:right;white-space:nowrap}.dc-td-qty b{color:var(--text-main);font-weight:700}.dc-th-charge,.dc-td-charge{text-align:center;white-space:nowrap}.dc-td-doc{font-family:monospace;font-size:0.8rem;color:var(--text-muted);white-space:nowrap}.dc-td-mat{font-family:monospace;font-weight:700;font-size:0.86rem;color:var(--secondary);white-space:nowrap}.dc-td-name{color:var(--text-main);font-weight:500;min-width:160px;word-break:break-word}.dc-type{font-size:0.7rem;font-weight:700;padding:2px 9px;border-radius:6px;white-space:nowrap}.dc-type-rd{background:#dbeafe;color:#1e40af}.dc-type-od{background:#ede9fe;color:#6d28d9}.dc-grp-row td{padding:0.45rem 0.7rem}.dc-grp-subgroup td{background:#e8eef6;border-top:2px solid #94a3b8;border-bottom:1px solid #cbd5e1;color:var(--secondary);font-size:0.9rem}.dc-grp-subgroup b{font-size:0.95rem}.dc-grp-subname td{background:#eef4fc;padding:0.5rem 0.7rem 0.5rem 1.5rem;color:var(--secondary);font-weight:700;font-size:0.92rem;border-bottom:1px solid #d8e3f1;box-shadow:inset 4px 0 0 var(--primary-light)}.dc-subname-name{display:inline-flex;align-items:center;gap:0.4rem;background:var(--primary);color:#fff;padding:0.18rem 0.7rem;border-radius:999px;font-size:0.9rem;font-weight:700}.dc-subname-name .dc-grp-icon{color:#fff;margin-right:0;font-size:0.82rem;opacity:0.95}.dc-grp-icon{margin-right:0.3rem}.dc-grp-subgroup .dc-grp-icon{color:var(--primary)}.dc-grp-count{font-weight:500;font-size:0.76rem;color:var(--text-muted);margin-left:0.55rem}.dc-grp-charge{color:#92400e;font-weight:700}.dc-bad-box{border:1px solid #fca5a5;background:#fef2f2;border-radius:var(--radius-md);margin-bottom:0.7rem;overflow:hidden}.dc-bad-head{display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;padding:0.6rem 0.8rem;background:linear-gradient(135deg,#fee2e2 0%,#fecaca 100%);border-bottom:1px solid #fca5a5}.dc-bad-head .dc-bad-ic{color:#dc2626}.dc-bad-title{font-weight:700;color:#991b1b;font-size:0.9rem}.dc-bad-count{font-weight:700;font-size:0.74rem;background:#dc2626;color:#fff;border-radius:999px;padding:1px 9px}.dc-bad-hint{font-size:0.76rem;color:#b91c1c;width:100%}.dc-bad-table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}.dc-bad-table{width:100%;border-collapse:collapse;font-size:0.86rem;min-width:640px;background:var(--surface)}.dc-bad-table thead th{background:#fff1f1;color:#991b1b;font-weight:700;font-size:0.76rem;text-align:left;padding:0.5rem 0.7rem;border-bottom:1px solid #fca5a5;white-space:nowrap}.dc-bad-table tbody td{padding:0.5rem 0.7rem;border-bottom:1px solid #fde2e2;vertical-align:middle}.dc-bad-table tbody tr:last-child td{border-bottom:none}.dc-bad-recv{font-weight:700;color:#b91c1c;white-space:nowrap}.dc-bad-td-qty{text-align:right;white-space:nowrap}.dc-bad-td-charge{text-align:center;white-space:nowrap}.dc-badge.dc-badge-baddc{background:#fee2e2;color:#b91c1c}.dc-sub{display:flex;flex-direction:column}.dc-sub-head{display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;padding:0.5rem 0.8rem;border-radius:var(--radius-md);border:1px solid var(--border);cursor:pointer;-webkit-tap-highlight-color:transparent}.dc-sub-head:hover{filter:brightness(0.98)}.dc-sub-p{background:#fff7ed;color:#9a3412;border-color:#fed7aa}.dc-sub-c{background:#f0fdf4;color:#166534;border-color:#bbf7d0}.dc-sub-chevron{transition:transform 0.2s ease;font-size:0.76rem;opacity:0.8;flex:none}.dc-sub.collapsed .dc-sub-chevron{transform:rotate(-90deg)}.dc-sub.collapsed .dc-sub-body{display:none}.dc-sub-ic{flex:none;opacity:0.9}.dc-sub-title{font-weight:700;font-size:0.88rem}.dc-sub-count{font-weight:800;font-size:0.72rem;border-radius:999px;padding:1px 9px}.dc-sub-p .dc-sub-count{background:#fdba74;color:#7c2d12}.dc-sub-c .dc-sub-count{background:#86efac;color:#14532d}.dc-sub-meta{margin-left:auto;font-size:0.76rem;font-weight:500;opacity:0.85;white-space:nowrap}.dc-sub-body{margin-top:0.6rem;display:flex;flex-direction:column;gap:0.7rem;padding-left:0.35rem;border-left:2px solid var(--border);margin-left:0.3rem;padding-top:0.1rem}@media(max-width:560px){.dc-head-actions{width:100%;margin-left:0;margin-top:0.5rem}.dc-confirm-btn{width:100%!important}}

















































































































































































































































































































































































































































































































































































</style>
<link rel="stylesheet" href="<?= assetHref('css/inventory-brand.css') ?>">
    <script src="<?= assetHref('js/inventory-brand.js') ?>" defer></script>
<link rel="stylesheet" href="<?= assetHref('css/inventory-theme.css') ?>">
<link rel="stylesheet" href="<?= assetHref('css/inventory-palette.css') ?>"><link rel="stylesheet" href="<?= assetHref('css/theme-dark-addons.css') ?>"><link rel="stylesheet" href="<?= assetHref('css/theme-dark-fixes.css') ?>"></head>

<body>
    


    <div id="inlineLogin" class="inline-login" style="display:flex;"><div class="cnx-login-layout">
                <section class="cnx-login-story" aria-label="CONNEXT Inventory Control"><img class="cnx-login-art" src="<?= assetHref('assets/inventory-shelves.svg') ?>" alt="" aria-hidden="true">
            <div class="cnx-eyebrow">CONNEXT / INVENTORY CONTROL</div>
            <h2>เชื่อมต่อทุกการเคลื่อนไหว<br>มั่นใจทุกยอดคงคลัง</h2>
            <p>จัดการวัสดุในไซต์งาน ตั้งแต่รับเข้า เบิกจ่าย ยืมคืน<br>ไปจนถึงตรวจสอบและติดตามในระบบเดียว</p>
            <div class="cnx-login-tags"><span>รับเข้า · เบิกจ่าย</span><span>ยืม · คืน</span><span>ตรวจสอบ · ติดตาม</span></div>
        </section>
        <div class="il-card">
            <div class="il-brand">
                <div class="il-logo">
                    <img src="<?= assetHref('assets/connext-brand.png') ?>" alt="CONNEXT">
                </div>
                <h1>CONNEXT</h1>
                <p>Inventory Control Module</p>
            </div>
            <h2><i class="fa-solid fa-lock" aria-hidden="true"></i> เข้าสู่ระบบ</h2>
            <div class="il-group">
                <label for="ilUser"><i class="fa-solid fa-user" aria-hidden="true"></i> ชื่อผู้ใช้</label>
                <input type="text" id="ilUser" placeholder="ใช้รหัสเดียวกับ MANGO" autocomplete="username" autocapitalize="off" autocorrect="off" spellcheck="false" inputmode="email">
            </div>
            <div class="il-group">
                <label for="ilPass"><i class="fa-solid fa-lock" aria-hidden="true"></i> รหัสผ่าน</label>
                <input type="password" id="ilPass" placeholder="รหัสผ่านของคุณ" autocomplete="current-password">
            </div>
            <button class="il-btn" id="ilBtn" onclick="performInlineLogin()">เข้าสู่ระบบ</button>
            <div id="ilMsg"></div>
            <div class="il-footer">ระบบปลอดภัยสำหรับสมาชิกเท่านั้น · CONNEXT v1.0</div></div>
        </div>
    </div>

    
    <nav class="navbar">
        <div class="brand">
            <div class="logo-icon" style="background: transparent; box-shadow: none;">
                <img src="<?= assetHref('assets/connext-brand.png') ?>" alt="CONNEXT Logo"
                    style="width: 100%; height: 100%; object-fit: contain; border-radius: 50%;">
            </div>
            <div class="brand-text">
                <h1>CONNEXT</h1>
                <p>Inventory Control Module</p>
            </div>
        </div>
        

        <div class="nav-links" id="navLinks">
            <a class="nav-link" data-page="requisition"><i class="fa-solid fa-boxes-stacked"></i> เบิก-จ่าย และ ยืม-คืน</a>
            <a class="nav-link" data-page="dashboard"><i class="fa-solid fa-chart-line"></i> Dashboard</a>
            <a class="nav-link" data-page="approve"><i class="fa-solid fa-stamp"></i> การอนุมัติ<span class="nav-link-badge" id="navLinkBadgeApprove" data-hidden="1">0</span></a>
            <a class="nav-link" data-page="qr"><i class="fa-solid fa-qrcode"></i> QR เอกสาร<span class="nav-link-badge" id="navLinkBadgeQR" data-hidden="1">0</span></a>
            <a class="nav-link" data-page="confirm"><i class="fa-solid fa-camera"></i> ถ่ายรูปยืนยัน<span class="nav-link-badge" id="navLinkBadgeConfirm" data-hidden="1">0</span></a>
            <a class="nav-link" data-page="history"><i class="fa-solid fa-clock-rotate-left"></i> ประวัติ</a>
            <a class="nav-link" data-page="dailycheck"><i class="fa-solid fa-clipboard-check"></i> ตรวจสอบประจำวัน</a>
            <a class="nav-link" data-page="fingerscan"><i class="fa-solid fa-fingerprint"></i> บันทึกสแกนนิ้ว<span class="nav-link-badge" id="navLinkBadgeFingerScan" data-hidden="1">0</span></a>
            <a class="nav-link" data-page="subexpense"><i class="fa-solid fa-file-invoice-dollar"></i> หักค่าใช้จ่ายผู้รับเหมา</a>
            <a class="nav-link" data-page="subsettings"><i class="fa-solid fa-gear"></i> ตั้งค่า</a>
            
            <a class="nav-link" data-page="pobuffer"><i class="fa-solid fa-file-import"></i> รับของตามใบ PO</a>
            <a class="nav-link" data-page="cnxadmin"><i class="fa-solid fa-sliders"></i> ตั้งค่าระบบ</a>
            <div class="nav-more" id="navMore" hidden>
                <button type="button" class="nav-more-btn" id="navMoreBtn" aria-haspopup="true"
                        aria-expanded="false" aria-controls="navMoreMenu" title="เมนูเพิ่มเติม"
                        onclick="toggleNavMore(event)">
                    <i class="fa-solid fa-ellipsis"></i>
                    <span>เพิ่มเติม</span>
                    <span class="nav-link-badge" id="navMoreBadge" data-hidden="1">0</span>
                    <i class="fa-solid fa-chevron-down nav-more-caret"></i>
                </button>
                <div class="nav-more-menu" id="navMoreMenu" role="menu" aria-labelledby="navMoreBtn"></div>
            </div>
        </div>
        <div class="user-profile">
            <div class="user-info">
                <div class="user-name" id="navUserName">กำลังโหลด...</div>
                <div class="user-role" id="navUserRole">Loading...</div>
            </div>
            <div class="avatar" onclick="openUserSettings()" title="ตั้งค่าบัญชี" style="cursor:pointer;">
                <i class="fa-solid fa-user-shield"></i>
            </div>
            <button onclick="logout()" class="btn-logout" title="ออกจากระบบ">
                <i class="fa-solid fa-right-from-bracket"></i>
            </button>
        </div>
    </nav>

    




    <nav class="mobile-tab-nav" id="mobileTabNav" aria-label="เมนูหลัก">
        <button type="button" class="mobile-tab-item" data-page="dashboard" onclick="goToPage('dashboard')">
            <i class="fa-solid fa-chart-line"></i><span>Dashboard</span>
        </button>
        <button type="button" class="mobile-tab-item" data-page="requisition" onclick="goToPage('requisition')">
            <i class="fa-solid fa-boxes-stacked"></i><span>เบิก-จ่าย</span>
        </button>
        <button type="button" class="mobile-tab-item" data-page="approve" onclick="goToPage('approve')">
            <i class="fa-solid fa-stamp"></i><span class="tab-badge" id="navBadgeApprove" data-hidden="1">0</span><span>การอนุมัติ</span>
        </button>
        <button type="button" class="mobile-tab-item" data-page="qr" onclick="goToPage('qr')">
            <i class="fa-solid fa-qrcode"></i><span class="tab-badge" id="navBadgeQR" data-hidden="1">0</span><span>QR</span>
        </button>
        <button type="button" class="mobile-tab-item" data-page="confirm" onclick="goToPage('confirm')">
            <i class="fa-solid fa-camera"></i><span class="tab-badge" id="navBadgeConfirm" data-hidden="1">0</span><span>ถ่ายรูป</span>
        </button>
        
        <button type="button" class="mobile-tab-item sc-only-tab" data-page="dailycheck" onclick="goToPage('dailycheck')" hidden>
            <i class="fa-solid fa-clipboard-check"></i><span>ตรวจสอบ</span>
        </button>
        <button type="button" class="mobile-tab-item sc-only-tab" data-page="subexpense" onclick="goToPage('subexpense')" hidden>
            <i class="fa-solid fa-file-invoice-dollar"></i><span>หักค่าใช้จ่าย</span>
        </button>
        

        <button type="button" class="mobile-tab-item bs-only-tab" data-page="fingerscan" onclick="goToPage('fingerscan')" hidden>
            <i class="fa-solid fa-fingerprint"></i><span class="tab-badge" id="navBadgeFingerScan" data-hidden="1">0</span><span>สแกนนิ้ว</span>
        </button>
        <button type="button" class="mobile-tab-item bs-only-tab" data-page="subsettings" onclick="goToPage('subsettings')" hidden>
            <i class="fa-solid fa-gear"></i><span>ตั้งค่า</span>
        </button>
        <button type="button" class="mobile-tab-item" id="mobileTabNavMore" onclick="openNavDrawer()">
            <i class="fa-solid fa-ellipsis"></i><span>เพิ่มเติม</span>
        </button>
    </nav>

    





    <div class="qty-modal-backdrop" id="qtyModalBackdrop" onclick="if(event.target===this) closeQtyModal()">
        <div class="qty-modal" role="dialog" aria-label="ระบุจำนวน">
            <div class="qty-modal-handle"></div>
            <div class="qty-modal-title">
                <i class="fa-solid fa-calculator" style="color:var(--primary);"></i>
                <span id="qtyModalMatName">ระบุจำนวน</span>
            </div>
            <div class="qty-modal-subtitle" id="qtyModalMatCode">—</div>

            <div class="qty-stock-card">
                <div class="qty-stock-row">
                    <span class="label"><i class="fa-solid fa-warehouse" style="width:16px; color:#64748b;"></i> <span id="qtyLabelOnHand">คงเหลือ (Stock)</span></span>
                    <span class="value" id="qtyStatOnHand">0</span>
                </div>
                <div class="qty-stock-row" id="qtyRowPending">
                    <span class="label"><i class="fa-solid fa-hourglass-half" id="qtyIconPending" style="width:16px; color:#f59e0b;"></i> <span id="qtyLabelPending">จองแล้ว — อนุมัติรอรับ (Pending)</span></span>
                    <span class="value" id="qtyStatPending">0</span>
                </div>
                <div class="qty-stock-row" id="qtyRowDrafted">
                    <span class="label"><i class="fa-solid fa-clipboard-list" style="width:16px; color:#6366f1;"></i> ในร่างของคุณ (Draft)</span>
                    <span class="value" id="qtyStatDrafted">0</span>
                </div>
                <div class="qty-stock-row divider highlight">
                    <span class="label"><i class="fa-solid fa-circle-check" style="width:16px;"></i> <span id="qtyLabelAvailable">คงเหลือที่จองได้</span></span>
                    <span class="value" id="qtyStatAvailable">0</span>
                </div>
            </div>
            
            <div id="qtyRefreshHint" style="display:none; text-align:center; font-size:12px; color:var(--text-muted); margin-top:6px;"></div>

            <div class="qty-stepper-label">จำนวนที่ต้องการ <span id="qtyStepUnit" style="color:var(--text-muted);"></span></div>
            <div class="qty-stepper">
                <button type="button" class="qty-step-btn" onclick="qtyStep(-1)" aria-label="ลด">−</button>
                



                <input type="number" class="qty-step-input" id="qtyStepValue" value="1" min="1" inputmode="numeric"
                       oninput="onQtyStepChange()"
                       onfocus="var el=this; setTimeout(function(){ try{ el.select(); }catch(e){} }, 0);"
                       onclick="var el=this; setTimeout(function(){ try{ el.select(); }catch(e){} }, 0);">
                <button type="button" class="qty-step-btn" onclick="qtyStep(1)" aria-label="เพิ่ม">+</button>
            </div>
            <div class="qty-quick-row" id="qtyQuickRow"></div>

            <div class="qty-warn" id="qtyWarn"></div>

            
            <div id="qtyChargeRow" style="display:none; margin:0.25rem 0 0.2rem;">
                <label style="display:flex; align-items:center; gap:0.6rem; cursor:pointer; padding:0.7rem 0.8rem; border:1px solid var(--border); border-radius:12px; background:#f8fafc;">
                    <input type="checkbox" id="qtyChargeToggle" style="width:1.3rem; height:1.3rem; cursor:pointer; flex:none;">
                    <span style="font-weight:600;">หักเงิน <span style="font-weight:400; color:var(--text-muted); font-size:0.83rem;">(ติ๊กถ้ารายการนี้ต้องหักเงินผู้รับเหมา)</span></span>
                </label>
            </div>

            <div class="qty-modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeQtyModal()">ยกเลิก</button>
                <button type="button" class="btn btn-primary" id="qtyConfirmBtn" onclick="confirmQtyModal()">
                    <i class="fa-solid fa-check"></i> ยืนยัน
                </button>
            </div>
        </div>
    </div>

    


    <div class="md-modal-backdrop" id="mdModalBackdrop" onclick="if(event.target===this) closeMaterialDetail()">
        <div class="md-modal" role="dialog" aria-label="รายละเอียดวัสดุ">
            <div class="md-handle"></div>
            <div class="md-status-banner" id="mdStatusBanner">
                <i class="fa-solid fa-circle-check"></i>
                <div>
                    <div class="md-banner-title" id="mdStatusTitle">เพียงพอ</div>
                    <div class="md-banner-sub" id="mdStatusSub">สต๊อกพร้อมใช้งาน</div>
                </div>
            </div>
            <div class="md-meta">
                <div class="md-meta-row">
                    <span class="label">รหัส IC</span>
                    <span class="value" id="mdMatCode">—</span>
                </div>
                <div class="md-meta-row">
                    <span class="label">ชื่อวัสดุ</span>
                    <span class="value" id="mdMatName" style="text-align:right; max-width:60%;">—</span>
                </div>
                <div class="md-meta-row">
                    <span class="label" title="ประตูประจำวัสดุจากทะเบียนวัสดุของไซต์ (project_materials) เป็นค่าตั้งต้นเท่านั้น — ของจริงกองอยู่ประตูไหนบ้างดูที่ &quot;คงเหลือแยกตามประตู&quot; ด้านล่าง">ประตูตั้งต้น</span>
                    <span class="value" id="mdGate">—</span>
                </div>
                <div class="md-meta-row">
                    <span class="label">หน่วย</span>
                    <span class="value" id="mdUnit">—</span>
                </div>
                <div class="md-meta-row">
                    <span class="label">หมวด</span>
                    <span class="pill" id="mdCatPill">—</span>
                </div>
            </div>

            <div class="md-section-title">การเคลื่อนไหวสต๊อก</div>
            <div class="md-stock-grid">
                <div class="md-stock-cell in">
                    <div class="lbl"><i class="fa-solid fa-arrow-down" style="color:#3b82f6;"></i> รับเข้า (In)</div>
                    <div class="num" id="mdValIn">0</div>
                </div>
                <div class="md-stock-cell pending">
                    <div class="lbl"><i class="fa-solid fa-hourglass-half" style="color:#f59e0b;"></i> จอง — อนุมัติแล้ว (Pending)</div>
                    <div class="num" id="mdValPending">0</div>
                </div>
                <div class="md-stock-cell out">
                    <div class="lbl"><i class="fa-solid fa-arrow-up" style="color:#ef4444;"></i> จ่ายออก (Out)</div>
                    <div class="num" id="mdValOut">0</div>
                </div>
                <div class="md-stock-cell balance">
                    <div class="lbl"><i class="fa-solid fa-warehouse" style="color:#10b981;"></i> คงเหลือ (Balance)</div>
                    <div class="num" id="mdValBalance">0</div>
                </div>
            </div>

            


            <div class="md-section-title">
                คงเหลือแยกตามประตู <span id="mdGateCount" style="font-weight:400;"></span>
            </div>
            <div class="md-gate-list" id="mdGateBox"></div>
            <div class="md-gate-note" id="mdGateNote"></div>

            <div class="md-actions single">
                <button type="button" class="btn btn-primary" onclick="closeMaterialDetail()">
                    <i class="fa-solid fa-xmark"></i> ปิด
                </button>
            </div>
        </div>
    </div>

    





    <div class="md-modal-backdrop" id="pendingDocsBackdrop" onclick="if(event.target===this) closePendingDocsModal()">
        <div class="md-modal" role="dialog" aria-label="รายการอนุมัติแล้วรอเบิก">
            <div class="md-handle"></div>
            <div class="md-status-banner md-banner-ok">
                <i class="fa-solid fa-hourglass-half"></i>
                <div>
                    <div class="md-banner-title">อนุมัติแล้ว รอเบิกที่ Gate</div>
                    <div class="md-banner-sub">เอกสารของทุกผู้ใช้ในไซต์ <b id="pendingDocsCount">0</b> รายการ</div>
                </div>
            </div>
            <div id="pendingDocsList"></div>
            <div class="md-actions single">
                <button type="button" class="btn btn-primary" onclick="closePendingDocsModal()">
                    <i class="fa-solid fa-xmark"></i> ปิด
                </button>
            </div>
        </div>
    </div>

    


    
    <button type="button" class="fab-scroll-top" id="fabScrollTop" onclick="scrollToTop()" title="กลับขึ้นบน" aria-label="กลับขึ้นบน">
        <i class="fa-solid fa-arrow-up"></i>
    </button>

    <div class="nav-drawer-backdrop" id="navDrawerBackdrop" onclick="closeNavDrawer()"></div>
    <div class="nav-drawer" id="navDrawer" role="dialog" aria-label="เมนูเพิ่มเติม">
        <div class="nav-drawer-handle"></div>
        <div class="nav-drawer-title">เมนูเพิ่มเติม</div>
        <div class="nav-drawer-list">
            <button type="button" class="nav-drawer-item" data-page="history" onclick="goToPage('history')">
                <i class="fa-solid fa-clock-rotate-left"></i><span>ประวัติ</span>
            </button>
            <button type="button" class="nav-drawer-item" data-page="dailycheck" onclick="goToPage('dailycheck')">
                <i class="fa-solid fa-clipboard-check"></i><span>ตรวจสอบประจำวัน</span>
            </button>
            <button type="button" class="nav-drawer-item" data-page="fingerscan" onclick="goToPage('fingerscan')">
                <i class="fa-solid fa-fingerprint"></i><span>บันทึกสแกนนิ้ว</span>
            </button>
            <button type="button" class="nav-drawer-item" data-page="subexpense" onclick="goToPage('subexpense')">
                <i class="fa-solid fa-file-invoice-dollar"></i><span>หักค่าใช้จ่ายผู้รับเหมา</span>
            </button>
            <button type="button" class="nav-drawer-item" data-page="subsettings" onclick="goToPage('subsettings')">
                <i class="fa-solid fa-gear"></i><span>ตั้งค่า (ผู้รับเหมา/Mango)</span>
            </button>
            
            <button type="button" class="nav-drawer-item" data-page="pobuffer" onclick="goToPage('pobuffer')">
                <i class="fa-solid fa-file-import"></i><span>รับของตามใบ PO</span>
            </button>
            <button type="button" class="nav-drawer-item" data-page="cnxadmin" onclick="goToPage('cnxadmin')">
                <i class="fa-solid fa-sliders"></i><span>ตั้งค่าระบบ</span>
            </button>
            <button type="button" class="nav-drawer-item" onclick="closeNavDrawer(); openUserSettings();">
                <i class="fa-solid fa-user-gear"></i><span>ตั้งค่าบัญชี</span>
            </button>
            <button type="button" class="nav-drawer-item" onclick="closeNavDrawer(); refreshCurrentPage();">
                <i class="fa-solid fa-arrows-rotate"></i><span>รีเฟรช</span>
            </button>
            <button type="button" class="nav-drawer-item" onclick="closeNavDrawer(); logout();" style="color:var(--danger); grid-column: span 1;">
                <i class="fa-solid fa-right-from-bracket" style="color:var(--danger);"></i><span>ออกจากระบบ</span>
            </button>
        </div>
    </div>

    
    <div id="userSettingsModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:9999; align-items:center; justify-content:center;">
        <div style="background:#fff; border-radius:16px; padding:2rem; width:min(520px,92vw); box-shadow:0 20px 60px rgba(0,0,0,0.2); max-height:90vh; overflow-y:auto;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
                <h2 style="font-size:1.2rem; font-weight:700; color:var(--secondary); margin:0;">
                    <i class="fa-solid fa-user-gear" style="color:var(--primary); margin-right:0.5rem;"></i>ตั้งค่าบัญชีผู้ใช้
                </h2>
                <button onclick="closeUserSettings()" style="background:none; border:none; font-size:1.4rem; cursor:pointer; color:var(--text-muted);">&times;</button>
            </div>

            
            <div style="display:flex; align-items:center; gap:13px; background:linear-gradient(135deg,#1e3a8a,#2563eb); color:#fff; border-radius:13px; padding:1rem 1.1rem; margin-bottom:1.25rem;">
                <div style="width:50px; height:50px; border-radius:50%; background:rgba(255,255,255,0.18); display:flex; align-items:center; justify-content:center; font-size:1.5rem; flex-shrink:0;">
                    <i class="fa-solid fa-user-shield"></i>
                </div>
                <div style="min-width:0; flex:1;">
                    <div id="usIdentityName" style="font-weight:700; font-size:1.05rem; line-height:1.3; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">—</div>
                    <div id="usIdentityRole" style="font-size:0.83rem; opacity:0.92; margin-top:3px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">—</div>
                </div>
            </div>

            
            <div style="background:var(--background); border-radius:12px; padding:1.25rem; margin-bottom:1.25rem;">
                <h3 style="font-size:1rem; font-weight:600; margin-bottom:1rem; color:var(--secondary);">
                    <i class="fa-solid fa-lock" style="margin-right:0.4rem; color:var(--primary);"></i>เปลี่ยนรหัสผ่าน
                </h3>
                <div class="form-group" style="margin-bottom:0.75rem;">
                    <label style="font-size:0.85rem; color:var(--text-muted);">รหัสผ่านใหม่</label>
                    <div class="password-wrapper">
                        <input type="password" id="usNewPass" class="form-control" placeholder="กรอกรหัสผ่านใหม่" autocapitalize="off" autocorrect="off" spellcheck="false">
                        <button type="button" class="password-toggle" onclick="togglePasswordVisibility('usNewPass', this)" aria-label="แสดง/ซ่อนรหัสผ่าน">
                            <i class="fa-solid fa-eye"></i>
                        </button>
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:1rem;">
                    <label style="font-size:0.85rem; color:var(--text-muted);">ยืนยันรหัสผ่านใหม่</label>
                    <div class="password-wrapper">
                        <input type="password" id="usConfirmPass" class="form-control" placeholder="กรอกรหัสผ่านใหม่อีกครั้ง" autocapitalize="off" autocorrect="off" spellcheck="false">
                        <button type="button" class="password-toggle" onclick="togglePasswordVisibility('usConfirmPass', this)" aria-label="แสดง/ซ่อนรหัสผ่าน">
                            <i class="fa-solid fa-eye"></i>
                        </button>
                    </div>
                </div>
                <div id="usPassMsg" style="font-size:0.85rem; margin-bottom:0.75rem; min-height:1.2rem;"></div>
                <button class="btn btn-primary" style="width:100%;" onclick="submitChangePassword()">
                    <i class="fa-solid fa-key"></i> บันทึกรหัสผ่านใหม่
                </button>
            </div>

            
            <div id="usSigCard" style="background:var(--background); border-radius:12px; padding:1.25rem; margin-bottom:1.25rem;">
                <h3 style="font-size:1rem; font-weight:600; margin-bottom:0.75rem; color:var(--secondary);">
                    <i class="fa-solid fa-signature" style="margin-right:0.4rem; color:var(--primary);"></i>ลายเซ็นของฉัน
                </h3>
                <div class="sig-preview-box" id="usSigPreview">
                    <span class="sig-preview-empty"><i class="fa-solid fa-spinner fa-spin"></i> กำลังโหลด...</span>
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.6rem; margin-bottom:0.6rem;">
                    <button class="btn btn-primary" style="width:100%;" onclick="usDrawSignature()">
                        <i class="fa-solid fa-pen-nib"></i> วาดลายเซ็น
                    </button>
                    <button class="btn btn-secondary" style="width:100%;" onclick="document.getElementById('usSigFile').click()">
                        <i class="fa-solid fa-file-import"></i> นำเข้ารูป
                    </button>
                </div>
                <input type="file" id="usSigFile" accept="image/*" style="display:none;" onchange="handleSigImport(this)">
                <button class="btn" id="usSigDeleteBtn" style="width:100%; background:#fee2e2; color:#b91c1c; display:none;" onclick="deleteMySignatureClient()">
                    <i class="fa-solid fa-trash-can"></i> ลบลายเซ็น
                </button>
                <div style="font-size:0.78rem; color:var(--text-muted); margin-top:0.6rem; line-height:1.5;">
                    <i class="fa-solid fa-circle-info" style="color:var(--primary);"></i>
                    ลายเซ็นนี้จะถูกฝังลงเอกสาร PDF อัตโนมัติเมื่อคุณเป็นผู้ออกเอกสารหักเงิน (ผู้สรุปเอกสาร)
                    หรือเมื่อคุณลงนามคำขอ ผู้ตรวจสอบ/ผู้อนุมัติ ในหน้า "การอนุมัติ"
                </div>
            </div>

            
            <div style="background:var(--background); border-radius:12px; padding:1.25rem;">
                <h3 style="font-size:1rem; font-weight:600; margin-bottom:1rem; color:var(--secondary);">
                    <i class="fa-solid fa-location-dot" style="margin-right:0.4rem; color:var(--primary);"></i>เปลี่ยน Site
                </h3>
                <div class="form-group" style="margin-bottom:0.5rem;">
                    <label style="font-size:0.85rem; color:var(--text-muted);">Site ปัจจุบัน</label>
                    <div id="usCurrentSite" style="font-weight:600; color:var(--secondary); padding:0.4rem 0;">—</div>
                </div>
                
                <div id="usChangeSiteControls" style="display:none;">
                    <div class="form-group" style="margin-bottom:1rem;">
                        <label style="font-size:0.85rem; color:var(--text-muted);">เลือก Site ใหม่</label>
                        <select id="usSiteSelect" class="form-control">
                            <option value="">กำลังโหลด...</option>
                        </select>
                    </div>
                    <div id="usSiteMsg" style="font-size:0.85rem; margin-bottom:0.75rem; min-height:1.2rem;"></div>
                    <button class="btn btn-primary" style="width:100%;" onclick="submitChangeSite()">
                        <i class="fa-solid fa-arrows-rotate"></i> บันทึก Site ใหม่
                    </button>
                </div>
                <div id="usSiteLockedNote" style="display:none; font-size:0.85rem; color:var(--text-muted); margin-top:0.3rem;">
                    <i class="fa-solid fa-lock" style="color:var(--primary);"></i> ไซต์ถูกกำหนดโดยผู้ดูแลระบบ — ดู/เบิก/อนุมัติ ได้เฉพาะไซต์นี้เท่านั้น
                </div>
            </div>
        </div>
    </div>
    

    
    


    <div id="pobuffer-page" class="page-container">
        <div class="page-header">
            <h2 class="page-title"><i class="fa-solid fa-file-import"></i> รับของตามใบ PO</h2>
            <button type="button" class="page-refresh-btn" onclick="refreshCurrentPage()" title="รีเฟรชข้อมูล"><i class="fa-solid fa-arrows-rotate"></i></button>
            <p class="page-subtitle">อ่านใบสั่งซื้อจาก PDF → รับของเข้า buffer → กำหนดรหัส IC → ออกใบเข้า gate</p>
        </div>
        <iframe id="pobufferFrame" class="cnx-frame" title="รับของตามใบ PO"></iframe>
    </div>

    <div id="cnxadmin-page" class="page-container">
        <div class="page-header">
            <h2 class="page-title"><i class="fa-solid fa-sliders"></i> ตั้งค่าระบบ</h2>
            <button type="button" class="page-refresh-btn" onclick="refreshCurrentPage()" title="รีเฟรชข้อมูล"><i class="fa-solid fa-arrows-rotate"></i></button>
            <p class="page-subtitle">ประตู · โครงการ/ไซต์ · วัสดุ · ผู้ใช้และสิทธิ์ · บันได IC</p>
        </div>
        <iframe id="cnxadminFrame" class="cnx-frame" title="ตั้งค่าระบบ"></iframe>
    </div>

    <div id="requisition-page" class="page-container">
        <div class="page-header">
            <h2 class="page-title"><i class="fa-solid fa-boxes-stacked"></i> ระบบเบิก-จ่ายวัสดุ</h2>
            <button type="button" class="page-refresh-btn" onclick="refreshCurrentPage()" title="รีเฟรชข้อมูล"><i class="fa-solid fa-arrows-rotate"></i></button>
            <p class="page-subtitle">จัดการรายการขอเบิกวัสดุ อุปกรณ์ และการยืม-คืน สำหรับไซต์งานก่อสร้าง</p>
        </div>
        <div class="tabs-container">
            <div class="tabs-header">
                <button class="tab-btn active" onclick="switchRequisitionTab('req-normal', this)"><i
                        class="fa-solid fa-box-open"></i> เบิกวัสดุหลัก</button>
                <button class="tab-btn" onclick="switchRequisitionTab('req-odds', this)"><i
                        class="fa-solid fa-screwdriver-wrench"></i> เบิกวัสดุเบ็ดเตล็ด (Odds)</button>
                <button class="tab-btn" onclick="switchRequisitionTab('req-borrow', this)"><i
                        class="fa-solid fa-handshake"></i> ยืม-คืน อุปกรณ์</button>
                <button class="tab-btn" onclick="switchRequisitionTab('req-inbound', this)"><i
                        class="fa-solid fa-truck-ramp-box"></i> รับเข้าคลัง</button>
            </div>
            
            <div id="req-normal" class="tab-content active">
                <div class="grid-layout">
                    <div class="form-section">
                        <h3 style="margin-bottom:1rem;">แบบฟอร์มขอเบิกวัสดุ</h3>
                        <div class="form-group"><label>วัสดุที่ต้องการเบิก</label><select id="reqMaterialSelect"
                                class="form-control req-select material-select" onchange="updateMaterialBalanceHint('reqMaterialSelect', 'reqMaterialBalanceHint')">
                                <option value="" disabled selected>กำลังโหลดข้อมูล...</option>
                            </select>
                            <div id="reqMaterialBalanceHint" class="balance-hint">เลือกวัสดุเพื่อดูจำนวนคงเหลือ</div></div>
                        <div class="form-group">
                            <label>ประตูที่ไปรับของ <span style="color:var(--danger);">*</span></label>
                            <select id="reqGateSelect" class="form-control" onchange="onGateSelectChange('req')">
                                <option value="" disabled selected>-- เลือกวัสดุก่อน --</option>
                            </select>
                            <div id="reqGateHint" class="balance-hint">วัสดุชนิดเดียวกันอยู่ได้หลายประตู — เลือกประตูที่จะไปรับ</div>
                        </div>
                        <div class="form-group"><label>จำนวน</label><input type="text" id="reqQtyInput"
                                class="form-control qty-trigger" placeholder="แตะเพื่อระบุจำนวน" readonly
                                onclick="openQtyModal('reqQtyInput','reqMaterialSelect','req')"></div>
                        <div class="form-group">
                            <label>ผู้รับ (Receiver)</label>
                            <select id="reqReceiverSelect" class="form-control receiver-select" onchange="onReqReceiverChange(this)">
                                <option value="" disabled selected>-- เลือกผู้รับ --</option>
                                <option value="DC">DC (กรอกชื่อ)</option>
                            </select>
                            <input type="text" id="reqReceiverDCInput" class="form-control" placeholder="ระบุชื่อผู้รับ (DC)" style="margin-top:0.5rem; display:none;">
                        </div>
                        <div class="form-group">
                            <label>พื้นที่ใช้งาน (UsageArea) <span style="color:#dc2626;">*</span></label>
                            <input type="text" id="reqUsageAreaInput" class="form-control" placeholder="เช่น Gate A, ชั้น 3" required>
                        </div>
                        <div class="form-group">
                            <label>หมายเหตุ (Notice)</label>
                            <textarea id="reqNoticeInput" class="form-control" rows="2" placeholder="ระบุหมายเหตุ (ถ้ามี)"></textarea>
                        </div>
                        <div class="form-group">
                            <label style="display:flex; align-items:center; gap:0.55rem; cursor:pointer; font-weight:600;">
                                <input type="checkbox" id="reqChargeInput" style="width:1.2rem; height:1.2rem; cursor:pointer; flex:none;">
                                <span>หักเงิน <span style="font-weight:400; color:var(--text-muted); font-size:0.85rem;">(ติ๊กเมื่อรายการนี้ต้องหักเงินผู้รับเหมา)</span></span>
                            </label>
                        </div>
                        




                        <div class="form-group approver-picker-group" id="reqApproverGroup" style="display:none;">
                            <label>ส่งขออนุมัติไปที่ <span style="color:#dc2626;">*</span></label>
                            <select id="reqApproverSelect" class="form-control approver-select" data-form="req">
                                <option value="" disabled selected>-- เลือกผู้อนุมัติ --</option>
                            </select>
                        </div>
                        <button class="btn btn-primary" style="margin-top: 1rem;" onclick="addToDraft()"><i
                                class="fa-solid fa-paper-plane"></i>
                            บันทึกรายการขอเบิก</button>
                    </div>
                    <div>
                        <h3 style="margin-bottom:1rem;">รายการที่เตรียมเบิก (Draft)</h3>
                        <table class="draft-table has-charge">
                            <thead><tr>
                                <th>ชื่อวัสดุ</th>
                                <th>จำนวน / หน่วย</th>
                                <th>ผู้รับ</th>
                                <th>พื้นที่ / หมายเหตุ</th>
                                <th>หักเงิน</th>
                                <th></th>
                            </tr></thead>
                            <tbody id="draftTableBody">
                                <tr class="draft-empty-row"><td colspan="6">ยังไม่มีรายการเตรียมเบิก</td></tr>
                            </tbody>
                        </table>
                        <div class="draft-actions">
                            <button class="btn btn-primary" style="width:auto;" onclick="submitRequisition()"><i
                                    class="fa-solid fa-check-to-slot"></i> ยืนยันส่งขออนุมัติทั้งหมด</button>
                        </div>
                    </div>
                </div>
            </div>
            
            <div id="req-odds" class="tab-content">
                <div class="grid-layout">
                    <div class="form-section">
                        <h3>เบิกวัสดุเบ็ดเตล็ด (Non-Approve)</h3>
                        <div class="form-group">
                            <label>วัสดุที่ต้องการเบิก</label>
                            <select id="oddsMaterialSelect" class="form-control req-select material-select" onchange="updateMaterialBalanceHint('oddsMaterialSelect', 'oddsMaterialBalanceHint')">
                                <option value="" disabled selected>กำลังโหลดข้อมูล...</option>
                            </select>
                            <div id="oddsMaterialBalanceHint" class="balance-hint">เลือกวัสดุเพื่อดูจำนวนคงเหลือ</div>
                        </div>
                        <div class="form-group">
                            <label>ประตูที่ไปรับของ <span style="color:var(--danger);">*</span></label>
                            <select id="oddsGateSelect" class="form-control" onchange="onGateSelectChange('odds')">
                                <option value="" disabled selected>-- เลือกวัสดุก่อน --</option>
                            </select>
                            <div id="oddsGateHint" class="balance-hint">วัสดุชนิดเดียวกันอยู่ได้หลายประตู — เลือกประตูที่จะไปรับ</div>
                        </div>
                        <div class="form-group">
                            <label>จำนวน</label>
                            <input type="text" id="oddsQtyInput" class="form-control qty-trigger" placeholder="แตะเพื่อระบุจำนวน" readonly onclick="openQtyModal('oddsQtyInput','oddsMaterialSelect','odds')">
                        </div>
                        <div class="form-group">
                            <label>ผู้รับ (Receiver)</label>
                            <select id="oddsReceiverSelect" class="form-control receiver-select" onchange="onOddsReceiverChange(this)">
                                <option value="" disabled selected>-- เลือกผู้รับ --</option>
                                <option value="DC">DC (กรอกชื่อ)</option>
                            </select>
                            <input type="text" id="oddsReceiverDCInput" class="form-control" placeholder="ระบุชื่อผู้รับ (DC)" style="margin-top:0.5rem; display:none;">
                        </div>
                        <div class="form-group">
                            <label>หมายเหตุ</label>
                            <textarea id="oddsNoteInput" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="form-group">
                            <label style="display:flex; align-items:center; gap:0.55rem; cursor:pointer; font-weight:600;">
                                <input type="checkbox" id="oddsChargeInput" style="width:1.2rem; height:1.2rem; cursor:pointer; flex:none;">
                                <span>หักเงิน <span style="font-weight:400; color:var(--text-muted); font-size:0.85rem;">(ติ๊กเมื่อรายการนี้ต้องหักเงินผู้รับเหมา)</span></span>
                            </label>
                        </div>
                        <button class="btn btn-primary" onclick="addToOddsDraft()" style="margin-top: 1rem;"><i
                                class="fa-solid fa-plus"></i> เพิ่มลงรายการ</button>
                    </div>
                    <div>
                        <h3 style="margin-bottom:1rem;">รายการที่เตรียมเบิก (Draft)</h3>
                        <table class="draft-table has-charge">
                            <thead><tr>
                                <th>ชื่อวัสดุ</th>
                                <th>จำนวน / หน่วย</th>
                                <th>ผู้รับ</th>
                                <th>หมายเหตุ</th>
                                <th>หักเงิน</th>
                                <th></th>
                            </tr></thead>
                            <tbody id="oddsDraftTableBody">
                                <tr class="draft-empty-row"><td colspan="6">ยังไม่มีรายการเตรียมเบิก</td></tr>
                            </tbody>
                        </table>
                        <div class="draft-actions">
                            <button class="btn btn-success" style="width:auto;" onclick="submitOddsRequisition()">
                                <i class="fa-solid fa-truck-fast"></i> ยืนยันส่งเบิกจ่ายทันที
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            
            <div id="req-borrow" class="tab-content">
                
                <div class="grid-layout">
                    
                    <div class="form-section">
                        <h3 style="margin-bottom:1.25rem;">บันทึกการยืมอุปกรณ์</h3>
                        <div class="form-group">
                            <label>อุปกรณ์ <span style="color:var(--danger);">*</span></label>
                            <select id="borrowMaterialSelect" class="form-control req-select material-select">
                                <option value="" disabled selected>-- เลือกวัสดุ --</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>ประตูที่ไปรับของ <span style="color:var(--danger);">*</span></label>
                            <select id="borrowGateSelect" class="form-control" onchange="onGateSelectChange('borrow')">
                                <option value="" disabled selected>-- เลือกวัสดุก่อน --</option>
                            </select>
                            <div id="borrowGateHint" class="balance-hint">วัสดุชนิดเดียวกันอยู่ได้หลายประตู — เลือกประตูที่จะไปรับ</div>
                        </div>
                        <div class="form-group">
                            <label>จำนวน</label>
                            <input type="text" id="borrowQtyInput" class="form-control qty-trigger" placeholder="แตะเพื่อระบุจำนวน" readonly onclick="openQtyModal('borrowQtyInput','borrowMaterialSelect','borrow')">
                        </div>
                        <div class="form-group">
                            <label>ผู้รับ (Receiver)</label>
                            <select id="borrowReceiverSelect" class="form-control receiver-select" onchange="onBorrowReceiverChange(this)">
                                <option value="" disabled selected>-- เลือกผู้รับ --</option>
                                <option value="DC">DC (กรอกชื่อ)</option>
                            </select>
                            <input type="text" id="borrowReceiverDCInput" class="form-control" placeholder="ระบุชื่อผู้รับ (DC)" style="margin-top:0.5rem; display:none;">
                        </div>
                        <div class="form-group">
                            <label>พื้นที่ใช้งาน (UsageArea) <span style="color:#dc2626;">*</span></label>
                            <input type="text" id="borrowUsageAreaInput" class="form-control" placeholder="เช่น Gate A, ชั้น 3" required>
                        </div>
                        <div class="form-group">
                            <label>หมายเหตุ</label>
                            <textarea id="borrowNoticeInput" class="form-control" rows="2"
                                placeholder="ระบุหมายเหตุ (ถ้ามี)"></textarea>
                        </div>
                        <div class="form-group approver-picker-group" id="borrowApproverGroup" style="display:none;">
                            <label>ส่งขออนุมัติไปที่ <span style="color:#dc2626;">*</span></label>
                            <select id="borrowApproverSelect" class="form-control approver-select" data-form="borrow">
                                <option value="" disabled selected>-- เลือกผู้อนุมัติ --</option>
                            </select>
                        </div>
                        <button class="btn btn-primary" style="margin-top:0.5rem;" onclick="addToBorrowDraft()">
                            <i class="fa-solid fa-plus"></i> เพิ่มรายการ
                        </button>
                    </div>

                    
                    <div>
                        <h3 style="margin-bottom:1rem;">รายการขอยืม</h3>
                        <table class="draft-table">
                            <thead><tr>
                                <th>ชื่อวัสดุ</th>
                                <th>จำนวน / หน่วย</th>
                                <th>ผู้รับ</th>
                                <th>พื้นที่ใช้งาน</th>
                                <th></th>
                            </tr></thead>
                            <tbody id="borrowDraftTableBody">
                                <tr class="draft-empty-row"><td colspan="5">ยังไม่มีรายการ</td></tr>
                            </tbody>
                        </table>
                        <div style="margin-top:1rem;text-align:right;">
                            <button class="btn btn-primary" style="width:auto;" onclick="submitBorrowDraft()">
                                <i class="fa-solid fa-hand-holding-hand"></i> ยืนยันบันทึกการยืม
                            </button>
                        </div>
                    </div>
                </div>

                
                <div style="margin-top: 3rem; border-top: 1px solid var(--border); padding-top: 2rem;">
                    <h3 style="margin-bottom:1.5rem;"><i class="fa-solid fa-rotate-left"></i>
                        รายการอุปกรณ์ที่ยังไม่ส่งคืน</h3>
                    <table class="draft-table" id="unreturnedTable">
                        <thead>
                            <tr>
                                <th style="width:110px;">BorrowID</th>
                                <th>ผู้ยืม/บริษัท</th>
                                <th>รายการ</th>
                                <th style="width:60px;"></th>
                            </tr>
                        </thead>
                        <tbody id="unreturnedTableBody">
                            <tr class="draft-empty-row">
                                <td colspan="4">กำลังโหลดข้อมูล...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div id="req-inbound" class="tab-content">
                <div class="grid-layout">
                    
                    <div class="form-section">
                        <h3 style="margin-bottom:0.85rem;">บันทึกการรับเข้าคลัง</h3>
                        <div style="display:flex; gap:9px; align-items:flex-start; background:#eff6ff; border:1px solid #bfdbfe; color:#1e40af; padding:10px 12px; border-radius:9px; margin-bottom:1.25rem; font-size:0.86rem; line-height:1.5;">
                            <i class="fa-solid fa-circle-info" style="margin-top:2px; color:#2563eb;"></i>
                            <span>รับเข้าคลัง<b>ไม่ต้องขออนุมัติ</b> — บันทึกเสร็จได้ QR ทันที จากนั้นไปสแกนที่ตู้ G ไหนของไซต์นี้ก็ได้แล้ว<b>ถ่ายรูปยืนยันรับเข้า</b></span>
                        </div>
                        <div class="form-group">
                            <label>เลขที่ใบรับสินค้า (RS) หรือ PO <span style="color:var(--danger);">*</span></label>
                            <input type="text" id="inboundRSInput" class="form-control" placeholder="ระบุเลขที่ใบรับสินค้า (RS) หรือ PO">
                        </div>
                        <div class="form-group">
                            <label>วัสดุ / อุปกรณ์ <span style="color:var(--danger);">*</span></label>
                            <select id="inboundMaterialSelect" class="form-control req-select material-select">
                                <option value="" disabled selected>-- เลือกวัสดุ --</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>จำนวน</label>
                            <input type="text" id="inboundQtyInput" class="form-control qty-trigger" placeholder="แตะเพื่อระบุจำนวนที่รับเข้า" readonly onclick="openQtyModal('inboundQtyInput','inboundMaterialSelect','inbound')">
                        </div>
                        <div class="form-group">
                            <label>ประตูที่รับเข้า</label>
                            <!-- [2026-10-06] ใบ IN ไม่มีรหัส G ต่อท้าย — ไม่ต้องเลือกประตู ยอดขึ้นที่ G ที่สแกน (ไซต์เดียวกันเท่านั้น) -->
                            <div class="balance-hint" style="display:block;">
                                <i class="fa-solid fa-door-open" style="color:var(--accent);"></i>
                                ไม่ต้องเลือก — นำของไปสแกนที่ตู้ G ไหน<strong>ของไซต์นี้</strong>ก็ได้ ยอดจะขึ้นที่ G ที่สแกน
                            </div>
                            <div id="inboundGateHint" class="balance-hint" style="display:none;"></div>
                        </div>
                        <div class="form-group">
                            <label>หมายเหตุ</label>
                            <textarea id="inboundNoticeInput" class="form-control" rows="2"
                                placeholder="ระบุหมายเหตุ (ถ้ามี)"></textarea>
                        </div>
                        
                        <button class="btn btn-primary" style="margin-top:0.5rem;" onclick="addToInboundDraft()">
                            <i class="fa-solid fa-plus"></i> เพิ่มรายการ
                        </button>
                    </div>

                    
                    <div>
                        <h3 style="margin-bottom:1rem;">รายการรับเข้าคลัง</h3>
                        <table class="draft-table">
                            <thead><tr>
                                <th>ชื่อวัสดุ</th>
                                <th>จำนวน / หน่วย</th>
                                <th>เลขที่ RS</th>
                                <th>หมายเหตุ</th>
                                <th></th>
                            </tr></thead>
                            <tbody id="inboundDraftTableBody">
                                <tr class="draft-empty-row"><td colspan="5">ยังไม่มีรายการ</td></tr>
                            </tbody>
                        </table>
                        <div style="margin-top:1rem;text-align:right;">
                            <button class="btn btn-primary" style="width:auto;" onclick="submitInboundDraft()">
                                <i class="fa-solid fa-truck-ramp-box"></i> ยืนยันบันทึกรับเข้า
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    
    <div id="dashboard-page" class="page-container">
        <div class="page-header">
            <h2 class="page-title"><i class="fa-solid fa-chart-line"></i> แดชบอร์ดสรุปยอดสต๊อก</h2>
            <button type="button" class="page-refresh-btn" onclick="refreshCurrentPage()" title="รีเฟรชข้อมูล"><i class="fa-solid fa-arrows-rotate"></i></button>
            <p class="page-subtitle">ภาพรวมวัสดุคงคลัง ขาเข้า-ขาออก และรายการที่กำลังดำเนินการ</p>
        </div>
                <section class="cnx-hero" aria-labelledby="cnxInventoryTitle">
            <img class="cnx-hero-art" src="<?= assetHref('assets/inventory-shelves.svg') ?>" alt="" aria-hidden="true"><div class="cnx-scan" aria-hidden="true"></div>
            <button type="button" class="cnx-display-toggle" aria-pressed="false" aria-label="ย่อหรือแสดงภาพตกแต่งคลัง">ย่อภาพ</button>
            <div class="cnx-hero-copy">
                <div class="cnx-eyebrow">INVENTORY / OPERATIONS OVERVIEW</div>
                <h3 id="cnxInventoryTitle">ทุกการเคลื่อนไหว อยู่ในการควบคุม</h3>
                <p>ตรวจสอบยอดคงเหลือ ติดตามวัสดุ และวางแผนเติมสต๊อก</p>
                <div class="cnx-hero-actions">
                    <button type="button" data-inventory-search><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> ค้นหาวัสดุในคลัง</button>
                    <button type="button" data-inventory-low><i class="fa-solid fa-arrow-trend-down" aria-hidden="true"></i> ตรวจสอบวัสดุใกล้หมด</button>
                </div>
            </div>
        </section>
        <div class="metrics-grid">
            <div class="metric-card" data-dash-mode="all" style="cursor:pointer;" onclick="filterDashboardBy('all')">
                <div class="metric-icon icon-blue"><i class="fa-solid fa-boxes-stacked"></i></div>
                <div class="metric-info">
                    <h3>รายการวัสดุทั้งหมด</h3>
                    <div class="metric-value" id="dashTotalItems">-</div>
                </div>
            </div>
            <div class="metric-card" data-dash-mode="outofstock" style="cursor:pointer;" onclick="filterDashboardBy('outofstock')">
                <div class="metric-icon icon-red"><i class="fa-solid fa-triangle-exclamation"></i></div>
                <div class="metric-info">
                    <h3>หมดสต๊อก (Out of Stock)</h3>
                    <div class="metric-value" id="dashOutOfStock">-</div>
                </div>
            </div>
            <div class="metric-card" data-dash-mode="lowstock" style="cursor:pointer;" onclick="filterDashboardBy('lowstock')">
                <div class="metric-icon icon-yellow"><i class="fa-solid fa-arrow-trend-down"></i></div>
                <div class="metric-info">
                    <h3>ใกล้หมด (Low Stock)</h3>
                    <div class="metric-value" id="dashLowStockTotal">-</div>
                    <div class="metric-split">
                        <button type="button" class="metric-chip chip-critical" data-dash-mode="lowstockc01" onclick="event.stopPropagation(); filterDashboardBy('lowstockc01')">Critical <b id="dashLowStockC01">-</b></button>
                        <button type="button" class="metric-chip chip-normal" data-dash-mode="lowstocknon" onclick="event.stopPropagation(); filterDashboardBy('lowstocknon')">Non-Critical <b id="dashLowStockNon">-</b></button>
                    </div>
                </div>
            </div>
            <div class="metric-card" style="cursor:pointer;" onclick="openPendingDocsModal()">
                <div class="metric-icon icon-green"><i class="fa-solid fa-hourglass-half"></i></div>
                <div class="metric-info">
                    <h3>อนุมัติแล้ว รอเบิก (Pending)</h3>
                    <div class="metric-value" id="dashPendingDocs">-</div>
                </div>
            </div>
            <div class="metric-card" data-dash-mode="wms" style="cursor:pointer;" onclick="filterDashboardBy('wms')">
                <div class="metric-icon icon-purple"><i class="fa-solid fa-cubes"></i></div>
                <div class="metric-info">
                    <h3>วัสดุ WMS</h3>
                    <div class="metric-value" id="dashWmsCount">-</div>
                </div>
            </div>
        </div>
        <div class="data-panel" style="display: none; background: white; border-radius: var(--radius-lg); padding:1.5rem; margin-bottom:2rem; box-shadow:var(--shadow-sm);">
            <h3 style="margin-bottom:1rem;"><i class="fa-solid fa-chart-column"></i> สรุปปริมาณวัสดุ เหล็ก & ปูน (C01 / CSB)</h3>
            <div style="position: relative; height: 350px;"><canvas id="c01Chart"></canvas></div>
        </div>
        <div id="dashLegacyMango" class="rate-alert" style="display:none; margin-bottom:1rem;"></div>
        <div id="dashTablePanel" class="data-panel" style="background:white; border-radius:var(--radius-lg); overflow:hidden;">
            <div class="dashboard-toolbar">
                <div class="dashboard-toolbar-head">
                    <h3><i class="fa-solid fa-boxes-stacked"></i> รายการวัสดุคงคลัง (Balance)</h3>
                    <button type="button" class="btn btn-danger dash-export-btn" onclick="exportBalancePDF()"><i class="fa-solid fa-file-pdf"></i> Export Balance</button>
                </div>
                <div class="dashboard-toolbar-filters">
                    <div class="dash-search-wrap">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" id="dashSearchInput" class="form-control" placeholder="ค้นหา รหัส IC / ชื่อ / หมวด..." oninput="filterDashboardTable()">
                    </div>
                    <div class="dashboard-subgroup-filter">
                        <span><i class="fa-solid fa-layer-group"></i> หมวด:</span>
                        <select id="dashSubgroupFilter" class="form-control" onchange="changeDashboardSubgroup()">
                            <option value="">-- ทุกหมวด --</option>
                        </select>
                    </div>
                    <div class="dashboard-site-filter" id="dashSiteFilterWrap" style="display:none;">
                        <span><i class="fa-solid fa-map-location-dot"></i> Site:</span>
                        <select id="dashSiteFilter" class="form-control" onchange="changeDashboardSite()">
                            <option value="">-- ทุก Site --</option>
                        </select>
                    </div>
                </div>
                <div class="dashboard-page-size">
                    <span>แสดงต่อหน้า</span>
                    <select id="dashPageSize" class="form-control" onchange="changeDashboardPageSize()">
                        <option value="10">10</option>
                        <option value="25" selected>25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                    <span>รายการ</span>
                </div>
            </div>
            <!-- [2026-10-08] บอกว่ากำลังกรองการ์ดไหนอยู่ (เดิมไม่มีอะไรบอก → ของที่มียอดดูเหมือนหายไป) -->
            <div id="dashActiveFilter" class="dash-active-filter" style="display:none;"></div>
            <style>
                .dash-active-filter{display:flex;align-items:center;flex-wrap:wrap;gap:.5rem .75rem;margin:0 1.25rem .75rem;padding:.55rem .85rem;border:1px solid var(--warn-text,#d97706);background:var(--warn-bg,#fffbeb);color:var(--warn-text,#92400e);border-radius:var(--radius-md);font-size:.9rem}
                .dash-active-filter b{color:inherit;font-weight:700}
                .dash-active-filter .dash-af-clear{margin-left:auto;border:1px solid var(--warn-text,#d97706);background:var(--surface,#fff);color:var(--warn-text,#92400e);border-radius:999px;padding:.2rem .75rem;font:inherit;font-size:.85rem;cursor:pointer}
                .dash-active-filter .dash-af-clear:hover{background:var(--warn-bg,#fef3c7)}
                .metric-card.dash-mode-on{outline:2px solid var(--action,var(--primary-light));outline-offset:-2px}
                .metric-chip.dash-mode-on{outline:2px solid currentColor;outline-offset:1px}
                .dash-empty-hint{display:block;margin-top:.35rem;color:var(--text-muted);font-size:.88rem}
                .dash-empty-hint button{margin-left:.35rem;border:1px solid var(--border,#d6dfe7);background:var(--surface,#fff);color:var(--text-main,#102f50);border-radius:999px;padding:.15rem .7rem;font:inherit;font-size:.85rem;cursor:pointer}
                @media (max-width:768px){.dash-active-filter{margin:0 .75rem .75rem}}
            </style>
            <div class="table-responsive">
                
<table class="data-table" id="inventoryTable">
                    <thead>
                        <tr>
                            <th>รหัส IC</th>
                            <th>ชื่อวัสดุ</th>
                            <th>Gate · คงเหลือรายประตู</th>
                            <th class="text-right">In</th>
                            <th class="text-right">Pending</th>
                            <th class="text-right">Out</th>
                            <th class="text-right">Balance</th>
                            <th>หน่วย</th>
                            <th>สถานะ</th>
                        </tr>
                    </thead>
                    <tbody id="inventoryTableBody">
                        <tr>
                            <td colspan="9" style="text-align:center;">
                                <i class="fa-solid fa-spinner fa-spin"></i> กำลังโหลดข้อมูล...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="dashboard-pagination">
                <div class="dashboard-pagination-info" id="dashPaginationInfo">กำลังโหลดข้อมูล...</div>
                <div class="dashboard-pagination-actions">
                    <button class="btn btn-secondary" id="dashPrevBtn" onclick="changeDashboardPage(-1)">
                        <i class="fa-solid fa-chevron-left"></i> ก่อนหน้า
                    </button>
                    <button class="btn btn-primary" id="dashNextBtn" onclick="changeDashboardPage(1)">
                        ถัดไป <i class="fa-solid fa-chevron-right"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div id="qr-page" class="page-container">
        <div class="page-header">
            <h2 class="page-title"><i class="fa-solid fa-qrcode"></i> QR Code เอกสารรออนุมัติที่ประตู</h2>
            <button type="button" class="page-refresh-btn" onclick="refreshCurrentPage()" title="รีเฟรชข้อมูล"><i class="fa-solid fa-arrows-rotate"></i></button>
            <p class="page-subtitle">ดูรายละเอียดเอกสาร รายการวัสดุ และสร้าง QR สำหรับส่งต่อที่หน้างานได้อย่างชัดเจน</p>
        </div>
        <div class="qr-summary-bar">
            <div class="qr-summary-card">
                <div class="qr-summary-label">เอกสารทั้งหมด</div>
                <div class="qr-summary-value" id="qrSummaryTotal">-</div>
            </div>
            <div class="qr-summary-card">
                <div class="qr-summary-label">จำนวนรายการวัสดุรวม</div>
                <div class="qr-summary-value" id="qrSummaryItems">-</div>
            </div>
            <div class="qr-summary-card">
                <div class="qr-summary-label">ผลลัพธ์หลังกรอง</div>
                <div class="qr-summary-value" id="qrSummaryFiltered">-</div>
            </div>
        </div>
        <div class="qr-filter-bar">
            

            <div class="qr-tab-row">
                <button class="qr-tab active" onclick="setQRFilter('all', this)"><i class="fa-solid fa-layer-group"></i> ทั้งหมด</button>
                <button class="qr-tab" onclick="setQRFilter('RD', this)"><i class="fa-solid fa-boxes-stacked"></i> RD</button>
                <button class="qr-tab" onclick="setQRFilter('OD', this)"><i class="fa-solid fa-screwdriver-wrench"></i> OD</button>
                <button class="qr-tab" onclick="setQRFilter('BD', this)"><i class="fa-solid fa-handshake"></i> BD</button>
                <button class="qr-tab" onclick="setQRFilter('IN', this)"><i class="fa-solid fa-truck-ramp-box"></i> IN</button>
            </div>
            

            <select id="qrReqSelect" class="form-control" onchange="renderQRCards()">
                <option value="" selected>-- กรองผู้ขอเบิก --</option>
            </select>
            <input type="text" id="qrSearchInput" placeholder="🔍 ค้นหา รหัสเอกสาร / วัสดุ..." oninput="renderQRCards()">
            <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
                <button type="button" class="qr-clear-filter-btn" onclick="clearQRFilters()">
                    <i class="fa-solid fa-eraser"></i> ล้างตัวกรอง
                </button>
                <button class="btn btn-primary" style="white-space:nowrap;padding:0.55rem 1rem; flex:1;" onclick="loadQRPage()">
                    <i class="fa-solid fa-rotate-right"></i> รีเฟรช
                </button>
            </div>
        </div>
        <div class="qr-cards-grid" id="qrCardsGrid">
            <div class="qr-empty"><i class="fa-solid fa-spinner fa-spin"></i><span>กำลังโหลด...</span></div>
        </div>
    </div>

    <div id="qrModal">
        <div class="qr-modal-box">
            <h3 id="qrModalTitle">QR Code อนุมัติแล้ว</h3>
            <div class="qr-meta" id="qrModalMeta"></div>
            <div id="qrCanvas"></div>
            <div class="qr-items-list" id="qrItemsList"></div>
            <div class="qr-modal-actions">
                <button class="btn-qr-dl" onclick="downloadQR()"><i class="fa-solid fa-download"></i> บันทึก QR</button>
                <button class="btn-qr-close" onclick="closeQRModal()"><i class="fa-solid fa-xmark"></i> ปิด</button>
            </div>
        </div>
    </div>

    <div id="approve-page" class="page-container">
        <div class="page-header">
            <h2 class="page-title"><i class="fa-solid fa-stamp"></i> การอนุมัติ</h2>
            <button type="button" class="page-refresh-btn" onclick="refreshCurrentPage()" title="รีเฟรชข้อมูล"><i class="fa-solid fa-arrows-rotate"></i></button>
            <p class="page-subtitle">อนุมัติรายการที่ได้รับมอบหมาย และติดตามสถานะรายการที่กำลังรออนุมัติ</p>
        </div>
        <div class="filter-bar" style="margin-bottom:1rem;">
            <select id="approveSubSelect" class="form-control" onchange="renderApprovalQueue()">
                <option value="" selected>-- กรองผู้รับเหมา --</option>
            </select>
            


            <select id="approveReqSelect" class="form-control" onchange="renderApprovalQueue()">
                <option value="" selected>-- กรองผู้ขอเบิก --</option>
            </select>
            <button class="btn btn-primary" style="width:auto;" onclick="loadApprovalQueue()">
                <i class="fa-solid fa-arrows-rotate"></i> โหลดใหม่
            </button>
        </div>
        

        <div id="signTasksSection" class="sign-tasks-section" style="display:none;">
            <div class="sign-tasks-head">
                <i class="fa-solid fa-file-signature"></i> ตรวจสอบและลงลายเซ็น — เอกสารหักเงิน
                <span class="sign-tasks-count" id="signTasksCount">0</span>
                <button type="button" class="page-refresh-btn" style="margin-left:auto;" onclick="loadMySignTasks()" title="รีเฟรชคำขอลายเซ็น"><i class="fa-solid fa-arrows-rotate"></i></button>
            </div>
            <div id="signTasksList"></div>
        </div>

        <div id="approvalQueue" class="approval-queue">
            <div class="qr-empty"><i class="fa-solid fa-spinner fa-spin"></i><span>กำลังโหลดข้อมูล...</span></div>
        </div>
    </div>

    <div id="confirm-page" class="page-container">
        <div class="page-header">
            <h2 class="page-title"><i class="fa-solid fa-camera"></i> ถ่ายรูปยืนยัน</h2>
            <button type="button" class="page-refresh-btn" onclick="refreshCurrentPage()" title="รีเฟรชข้อมูล"><i class="fa-solid fa-arrows-rotate"></i></button>
            <p class="page-subtitle">หยิบของตามรายการรวม แล้วถ่ายรูปยืนยันทีละใบเอกสาร เรียงตามลำดับที่สแกนผ่านประตู</p>
        </div>

        

        <div id="confirmNewBanner" onclick="loadConfirmNewDocs()"
             style="display:none; align-items:center; gap:10px; cursor:pointer; margin-bottom:12px;
                    padding:11px 14px; border-radius:10px; background:#eff6ff; border:1px solid #bfdbfe; color:#1e40af;">
            <i class="fa-solid fa-bell" style="color:#2563eb;"></i>
            <span style="flex:1; font-weight:600;">มีเอกสารใหม่ถูกสแกนเข้ามา — แตะเพื่อแสดง</span>
            <i class="fa-solid fa-arrows-rotate"></i>
        </div>

        
        <div id="pickSummaryCard" class="pick-summary" style="display:none;">
            <div class="pick-summary-head" onclick="togglePickSummary()">
                <i class="fa-solid fa-clipboard-list"></i>
                <span>รายการที่ต้องหยิบ<span id="pickSummaryPk" class="pick-summary-pk"></span> <span id="pickSummaryCount" class="pick-summary-count">0</span></span>
                <i class="fa-solid fa-chevron-down pick-summary-chevron"></i>
            </div>
            <div class="pick-summary-body" id="pickSummaryBody"></div>
        </div>

        
        <div id="confirmWizard"></div>
    </div>

    <div id="history-page" class="page-container">
        <div class="page-header">
            <h2 class="page-title"><i class="fa-solid fa-clock-rotate-left"></i> ประวัติเอกสาร</h2>
            <button type="button" class="page-refresh-btn" onclick="refreshCurrentPage()" title="รีเฟรชข้อมูล"><i class="fa-solid fa-arrows-rotate"></i></button>
            <p class="page-subtitle">ดูประวัติการเบิก-จ่าย และ ยืม-คืน พร้อมดาวน์โหลดรายงาน PDF</p>
        </div>
        <div style="display:flex; gap:1rem; flex-wrap:wrap; margin-bottom:1rem; align-items:flex-end;">
            <div class="form-group" style="margin-bottom:0; min-width:140px;">
                <label style="font-size:0.8rem;">ประเภท</label>
                <select id="historyFilterType" class="form-control" onchange="filterHistoryTable()">
                    <option value="">ทั้งหมด</option>
                    <option value="RD">เบิกวัสดุหลัก (RD)</option>
                    <option value="OD">เบิกเบ็ดเตล็ด (OD)</option>
                    <option value="BD">ยืมอุปกรณ์ (BD)</option>
                    <option value="IN">รับเข้าคลัง (IN)</option>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:0; min-width:140px;">
                <label style="font-size:0.8rem;">สถานะ</label>
                <select id="historyFilterStatus" class="form-control" onchange="filterHistoryTable()">
                    <option value="">ทั้งหมด</option>
                    <option value="Awaiting approval">รออนุมัติ</option>
                    <option value="Approved">อนุมัติแล้ว</option>
                    <option value="Rejected">ไม่อนุมัติ</option>
                    <option value="Closed">นำจ่ายแล้ว</option>
                    <option value="Sent Borrow">ส่งยืม</option>
                    <option value="Borrowed">ยืมอยู่</option>
                    <option value="Sent Return">ส่งคืน</option>
                    <option value="Returned">คืนแล้ว</option>
                    <option value="Completed">สำเร็จ</option>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:0; flex:1; min-width:180px;">
                <label style="font-size:0.8rem;">ค้นหา</label>
                <input type="text" id="historyFilterSearch" class="form-control"
                    placeholder="พิมพ์เลขเอกสาร หรือ รายการ..." oninput="filterHistoryTable()">
            </div>
            <button class="btn btn-primary" style="height:38px; margin-bottom:0;" onclick="loadHistory()">
                <i class="fa-solid fa-arrows-rotate"></i> โหลดใหม่
            </button>
        </div>
        <div class="table-container">
            <table class="data-table" id="historyTable">
                <thead>
                    <tr id="historyHeadRow">
                        <th id="historyRsHeader" data-sort="rs" onclick="sortHistory('rs')" style="display:none; cursor:pointer; white-space:nowrap; user-select:none;">เลขที่ใบรับสินค้า <span class="sort-ind" style="color:var(--primary); font-size:0.8em;"></span></th>
                        <th data-sort="docId" onclick="sortHistory('docId')" style="cursor:pointer; white-space:nowrap; user-select:none;">เลขที่เอกสาร <span class="sort-ind" style="color:var(--primary); font-size:0.8em;"></span></th>
                        <th data-sort="timestamp" onclick="sortHistory('timestamp')" style="cursor:pointer; white-space:nowrap; user-select:none;">วันที่ <span class="sort-ind" style="color:var(--primary); font-size:0.8em;"></span></th>
                        <th data-sort="type" onclick="sortHistory('type')" style="cursor:pointer; white-space:nowrap; user-select:none;">ประเภท <span class="sort-ind" style="color:var(--primary); font-size:0.8em;"></span></th>
                        <th>รายละเอียด</th>
                        <th data-sort="status" onclick="sortHistory('status')" style="cursor:pointer; white-space:nowrap; user-select:none;">สถานะ <span class="sort-ind" style="color:var(--primary); font-size:0.8em;"></span></th>
                        <th>รายงาน</th>
                    </tr>
                </thead>
                <tbody id="historyTableBody">
                    <tr>
                        <td colspan="6" style="text-align:center;">
                            <i class="fa-solid fa-spinner fa-spin"></i> กำลังโหลดข้อมูล...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    
    
    <!-- [PHP port 2026-10-02] หน้า "สถิติ" (stats-page) เอาออกตามที่ผู้ใช้สั่ง: เมนู · หน้า · หน้าต่างเจาะลึก · สคริปต์ · RPC getRequisitionStats / generateStatsReportPDF — build ใหม่จาก GAS ต้องลบซ้ำ (connext-local/patches/2026-10-02-remove-stats-page) -->

    
    
    <div id="dailycheck-page" class="page-container">
        <div class="page-header">
            <h2 class="page-title"><i class="fa-solid fa-clipboard-check"></i> ตรวจสอบประจำวัน</h2>
            <button type="button" class="page-refresh-btn" onclick="refreshCurrentPage()" title="รีเฟรชข้อมูล"><i class="fa-solid fa-arrows-rotate"></i></button>
            <p class="page-subtitle">ตรวจสอบรายการที่รับของแล้ว แก้ไขสถานะหักเงิน และยืนยันการตรวจประจำวัน</p>
        </div>
        <div class="tabs-container">
            <div class="tabs-header">
                <button class="tab-btn active" id="dcTabCheckBtn" onclick="switchDailyCheckTab('check', this)"><i class="fa-solid fa-list-check"></i> ตรวจสอบประจำวัน</button>
                <button class="tab-btn" id="dcTabDashBtn" onclick="switchDailyCheckTab('dash', this)"><i class="fa-solid fa-chart-pie"></i> Dashboard สรุป</button>
                <button class="tab-btn" id="dcTabRateBtn" onclick="switchDailyCheckTab('rate', this)"><i class="fa-solid fa-tags"></i> ตั้งราคาหักเงิน</button>
                
            </div>

            
            <div id="dc-tab-check">
                <div id="dailyCheckBody">
                    <div class="qr-empty"><i class="fa-solid fa-spinner fa-spin"></i><span>กำลังโหลดรายการ...</span></div>
                </div>
            </div>

            
            <div id="dc-tab-dash" style="display:none;">
                <div class="stats-range">
                    <div class="stats-range-chips" id="chargeRangeChips">
                        <button type="button" class="stats-chip" data-range="7" onclick="setChargeRange('7', this)">7 วัน</button>
                        <button type="button" class="stats-chip active" data-range="30" onclick="setChargeRange('30', this)">30 วัน</button>
                        <button type="button" class="stats-chip" data-range="90" onclick="setChargeRange('90', this)">90 วัน</button>
                        <button type="button" class="stats-chip" data-range="month" onclick="setChargeRange('month', this)">เดือนนี้</button>
                        <button type="button" class="stats-chip" data-range="all" onclick="setChargeRange('all', this)">ทั้งหมด</button>
                        <button type="button" class="stats-chip" data-range="custom" onclick="setChargeRange('custom', this)">กำหนดเอง</button>
                    </div>
                    <div class="stats-range-custom" id="chargeRangeCustom" style="display:none;">
                        <input type="date" id="chargeFrom" class="form-control">
                        <span style="color:var(--text-muted);">ถึง</span>
                        <input type="date" id="chargeTo" class="form-control">
                        <button type="button" class="btn btn-primary" style="width:auto;" onclick="loadChargeDashboard({force:true})"><i class="fa-solid fa-magnifying-glass"></i> ดู</button>
                    </div>
                    <div class="stats-range-label" id="chargeRangeLabel"></div>
                </div>
                <div id="chargeDashBody">
                    <div class="qr-empty"><i class="fa-solid fa-spinner fa-spin"></i><span>กำลังโหลดสรุป...</span></div>
                </div>
            </div>

            
            <div id="dc-tab-rate" style="display:none;">
                <div class="rate-toolbar">
                    <div class="rate-site-box">
                        <span class="rate-site-label"><i class="fa-solid fa-location-dot"></i> Site</span>
                        <select id="rateSiteSelect" class="form-control" onchange="onRateSiteChange()" style="width:auto; min-width:170px;"></select>
                    </div>
                    <div class="rate-search-box">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" id="rateSearch" class="form-control" placeholder="ค้นหา รหัส / ชื่อวัสดุ..." oninput="filterRateCard()">
                    </div>
                    <button type="button" class="rate-only-btn" id="rateOnlyUnsetBtn" onclick="toggleRateOnlyUnset()"><i class="fa-solid fa-triangle-exclamation"></i> เฉพาะที่ยังไม่ตั้งราคา</button>
                    <button type="button" class="btn btn-primary" id="rateSaveBtn" style="width:auto;" onclick="saveAllRates()" disabled><i class="fa-solid fa-floppy-disk"></i> บันทึกราคา</button>
                </div>
                <div id="rateCardBody">
                    <div class="qr-empty"><i class="fa-solid fa-spinner fa-spin"></i><span>กำลังโหลด...</span></div>
                </div>
            </div>

            
        </div>
    </div>

    




    <div id="subexpense-page" class="page-container">
        <div class="page-header">
            <h2 class="page-title"><i class="fa-solid fa-file-invoice-dollar"></i> หักค่าใช้จ่ายผู้รับเหมา</h2>
            <button type="button" class="page-refresh-btn" onclick="refreshCurrentPage()" title="รีเฟรชข้อมูล"><i class="fa-solid fa-arrows-rotate"></i></button>
            <p class="page-subtitle">รายการหักค่าห้อง พักร้านค้า และวัสดุอื่น ๆ ของผู้รับเหมา รายงวดครึ่งเดือน — กรอกข้อมูล บันทึก และพิมพ์เอกสาร PDF</p>
        </div>
        <div class="tabs-container">
            <div class="se-toolbar">
                <label class="se-field" id="seSiteField">
                    <span class="se-label"><i class="fa-solid fa-location-dot"></i> Site</span>
                    <select id="seSiteSelect" class="form-control" onchange="onSePeriodChange()" style="width:auto; min-width:150px;"></select>
                </label>
                <label class="se-field">
                    <span class="se-label"><i class="fa-solid fa-calendar-days"></i> เดือน</span>
                    <input type="month" id="seMonth" class="form-control" onchange="onSePeriodChange()" style="width:auto;">
                </label>
                <label class="se-field">
                    <span class="se-label"><i class="fa-solid fa-clock"></i> งวด</span>
                    <select id="seHalf" class="form-control" onchange="onSePeriodChange()" style="width:auto;">
                        <option value="1">งวดที่ 1 (วันที่ 1-15)</option>
                        <option value="2">งวดที่ 2 (วันที่ 16-สิ้นเดือน)</option>
                    </select>
                </label>
                <span class="se-period-label" id="sePeriodLabel"></span>
                <span style="flex:1;"></span>
                <button type="button" class="btn btn-secondary" style="width:auto;" onclick="openSeCfg()" title="ตั้งค่าราคา/อัตราของหมวดต่างๆ และรายการร้านค้า/เครื่องใช้ (ต่อไซต์)"><i class="fa-solid fa-sliders"></i> ตั้งค่าราคา</button>
                <button type="button" class="btn btn-secondary" style="width:auto;" onclick="seAddRow()"><i class="fa-solid fa-plus"></i> เพิ่มแถว</button>
                <button type="button" class="btn btn-primary" id="seSaveBtn" style="width:auto;" onclick="saveSubExpense()" disabled><i class="fa-solid fa-floppy-disk"></i> บันทึก</button>
                <button type="button" class="btn btn-primary se-pdf-btn" id="seExportBtn" style="width:auto;" onclick="openSeExport()" title="พิมพ์เอกสาร + ขอลายเซ็นออนไลน์ผู้ตรวจสอบ/ผู้อนุมัติ อยู่ในหน้าต่างเดียวกัน"><i class="fa-solid fa-file-pdf"></i> พิมพ์เอกสาร PDF</button>
            </div>
            <div id="seBody">
                <div class="qr-empty"><i class="fa-solid fa-spinner fa-spin"></i><span>กำลังโหลด...</span></div>
            </div>
        </div>
    </div>

    


    <div id="fingerscan-page" class="page-container">
        <div class="page-header">
            <h2 class="page-title"><i class="fa-solid fa-fingerprint"></i> <span id="fsPageTitle">บันทึกสแกนนิ้วแรงงานผู้รับเหมา</span></h2>
            <button type="button" class="page-refresh-btn" onclick="refreshCurrentPage()" title="รีเฟรชข้อมูล"><i class="fa-solid fa-arrows-rotate"></i></button>
            <p class="page-subtitle" id="fsPageSubtitle">กรอกจำนวนลงงานและจำนวนแสกนนิ้วของแต่ละชุดต่อวัน — ระบบคิดอัตรา% และค่าปรับให้อัตโนมัติ · ผู้ดูแล (BS) ตรวจสอบและลงลายเซ็นยืนยันรายวัน</p>
        </div>
        <div class="tabs-container">
            
            <div class="fs-subnav">
                <button type="button" class="fs-subtab active" id="fsSubBtnDaily" onclick="fsSubTab('daily')"><i class="fa-solid fa-pen-to-square"></i> บันทึกรายวัน<span class="fs-subbadge" id="fsSubBadgeDaily" data-hidden="1">0</span></button>
                <button type="button" class="fs-subtab fs-bs-sub" id="fsSubBtnSummary" onclick="fsSubTab('summary')" hidden><i class="fa-solid fa-table-list"></i> สรุปงวด</button>
                <button type="button" class="fs-subtab fs-bs-sub" id="fsSubBtnAlerts" onclick="fsSubTab('alerts')" hidden><i class="fa-solid fa-triangle-exclamation"></i> ชี้แจงแสกนเกิน<span class="fs-subbadge" id="fsSubBadgeAlert" data-hidden="1">0</span></button>
                <button type="button" class="fs-subtab fs-bs-sub" id="fsSubBtnDash" onclick="fsSubTab('dash')" hidden><i class="fa-solid fa-chart-pie"></i> แดชบอร์ด</button>
            </div>

            
            <div id="fsSecDaily" class="fs-section">
                <div class="se-toolbar">
                    <label class="se-field" id="fsSiteField">
                        <span class="se-label"><i class="fa-solid fa-location-dot"></i> Site</span>
                        <select id="fsSiteSelect" class="form-control" onchange="onFsSiteChange()" style="width:auto; min-width:150px;"></select>
                    </label>
                    <div class="fs-datenav">
                        <button type="button" class="fs-datebtn" onclick="fsShiftDay(-1)" title="วันก่อนหน้า"><i class="fa-solid fa-chevron-left"></i></button>
                        <input type="date" id="fsDate" class="form-control" onchange="onFsDateChange()" style="width:auto;">
                        <button type="button" class="fs-datebtn" id="fsNextBtn" onclick="fsShiftDay(1)" title="วันถัดไป"><i class="fa-solid fa-chevron-right"></i></button>
                        <button type="button" class="fs-datebtn fs-today" onclick="fsGoToday()" title="ไปวันนี้"><i class="fa-solid fa-calendar-day"></i> วันนี้</button>
                        <input type="month" id="fsMonthPick" class="form-control" onchange="onFsMonthPick()" style="width:auto;" title="ข้ามไปดูเดือนอื่น — แถบวันด้านล่างจะเปลี่ยนตาม">
                    </div>
                    <span class="se-period-label" id="fsRateLabel"></span>
                    <span style="flex:1;"></span>
                    <button type="button" class="btn btn-secondary" style="width:auto;" onclick="fsOpenLineSummary()" title="สรุปข้อมูลลงงาน/แสกนของวันนี้เป็นข้อความสำหรับส่งไลน์"><i class="fa-solid fa-comment-dots"></i> สรุปส่งไลน์</button>
                    <button type="button" class="btn btn-secondary fs-bs-only" style="width:auto;" onclick="openFsRate()" hidden title="ตั้งอัตราค่าปรับต่อคนต่อวันของไซต์นี้"><i class="fa-solid fa-sliders"></i> อัตราปรับ</button>
                    <button type="button" class="btn btn-primary" id="fsSaveBtn" style="width:auto;" onclick="saveFingerScan()"><i class="fa-solid fa-floppy-disk"></i> บันทึก</button>
                </div>
                <div id="fsDayStrip" class="fs-daystrip"></div>
                <div id="fsBody">
                    <div class="qr-empty"><i class="fa-solid fa-spinner fa-spin"></i><span>กำลังโหลด...</span></div>
                </div>
                <div id="fsVerifyCard"></div>
            </div>

            
            <div id="fsSecSummary" class="fs-section" hidden>
                <div class="se-toolbar">
                    <label class="se-field" id="fsSumSiteField">
                        <span class="se-label"><i class="fa-solid fa-location-dot"></i> Site</span>
                        <select id="fsSumSiteSelect" class="form-control" onchange="onFsSumSiteChange()" style="width:auto; min-width:150px;"></select>
                    </label>
                    <label class="se-field">
                        <span class="se-label"><i class="fa-solid fa-calendar-days"></i> เดือน</span>
                        <input type="month" id="fsMonth" class="form-control" onchange="onFsSumChange()" style="width:auto;">
                    </label>
                    <label class="se-field">
                        <span class="se-label"><i class="fa-solid fa-clock"></i> งวด</span>
                        <select id="fsHalf" class="form-control" onchange="onFsSumChange()" style="width:auto;">
                            <option value="1">งวดที่ 1 (วันที่ 1-15)</option>
                            <option value="2">งวดที่ 2 (วันที่ 16-สิ้นเดือน)</option>
                        </select>
                    </label>
                    <span class="se-period-label" id="fsSumLabel"></span>
                    <span style="flex:1;"></span>
                    <button type="button" class="btn btn-secondary fs-bs-only" style="width:auto;" onclick="openFsRate()" title="ตั้งอัตราค่าปรับต่อคนต่อวันของไซต์นี้"><i class="fa-solid fa-sliders"></i> อัตราปรับ</button>
                    <button type="button" class="btn btn-secondary" id="fsGenAllBtn" style="width:auto;" onclick="fsGenAllLinks()" title="สร้างลิงก์เซ็นรับทราบให้ทุกชุดในงวดนี้ทีเดียว"><i class="fa-solid fa-link"></i> สร้างลิงก์เซ็นทั้งหมด</button>
                    <button type="button" class="btn btn-secondary" id="fsAllLinksBtn" style="width:auto;" onclick="fsOpenAllLinks()" title="ดู/คัดลอกลิงก์เซ็นของทุกชุดในงวด เพื่อส่งให้ผู้รับเหมา"><i class="fa-solid fa-share-nodes"></i> รวมลิงก์ส่งผู้รับเหมา</button>
                    <button type="button" class="btn btn-secondary" id="fsCancelAllBtn" style="width:auto; color:#b91c1c;" onclick="fsCancelAllLinks()" title="ยกเลิกลิงก์เซ็นที่ยังไม่ตอบกลับทั้งงวด"><i class="fa-solid fa-link-slash"></i> ยกเลิกลิงก์ทั้งหมด</button>
                    <button type="button" class="btn btn-primary se-pdf-btn" id="fsPdfBtn" style="width:auto;" onclick="fsExportPdf()"><i class="fa-solid fa-file-pdf"></i> พิมพ์ PDF สรุปงวด</button>
                </div>
                <div id="fsSumBody">
                    <div class="qr-empty"><i class="fa-solid fa-spinner fa-spin"></i><span>กำลังโหลด...</span></div>
                </div>
            </div>

            
            <div id="fsSecAlerts" class="fs-section" hidden>
                <div class="se-toolbar">
                    <label class="se-field" id="fsAlertSiteField">
                        <span class="se-label"><i class="fa-solid fa-location-dot"></i> Site</span>
                        <select id="fsAlertSiteSelect" class="form-control" onchange="onFsAlertSiteChange()" style="width:auto; min-width:150px;"></select>
                    </label>
                    <label class="se-field">
                        <span class="se-label"><i class="fa-solid fa-calendar"></i> เดือน</span>
                        <select id="fsAlertMonthSelect" class="form-control" onchange="onFsAlertFilterChange('month')" style="width:auto;"></select>
                    </label>
                    <label class="se-field">
                        <span class="se-label"><i class="fa-solid fa-clock"></i> งวด</span>
                        <select id="fsAlertHalfSelect" class="form-control" onchange="onFsAlertFilterChange('half')" style="width:auto;">
                            <option value="">ทั้งเดือน</option>
                            <option value="1">งวดที่ 1 (วันที่ 1-15)</option>
                            <option value="2">งวดที่ 2 (วันที่ 16-สิ้นเดือน)</option>
                        </select>
                    </label>
                    <label class="se-field">
                        <span class="se-label"><i class="fa-solid fa-users"></i> ชุด</span>
                        <select id="fsAlertSubSelect" class="form-control" onchange="onFsAlertFilterChange('sub')" style="width:auto; min-width:160px;"></select>
                    </label>
                    <span class="se-period-label" id="fsAlertLabel"></span>
                    <span style="flex:1;"></span>
                    <button type="button" class="btn btn-secondary" style="width:auto;" onclick="_fsAlertSetAll(false)" title="ยุบทุกวัน"><i class="fa-solid fa-compress"></i> ยุบทั้งหมด</button>
                    <button type="button" class="btn btn-secondary" style="width:auto;" onclick="_fsAlertSetAll(true)" title="ขยายทุกวัน"><i class="fa-solid fa-expand"></i> ขยายทั้งหมด</button>
                    <button type="button" class="btn btn-primary" id="fsSaveAllAlertBtn" style="width:auto;" onclick="saveAllFsAlerts()"><i class="fa-solid fa-floppy-disk"></i> บันทึกชี้แจงทั้งหมด</button>
                </div>
                <div id="fsAlertBody">
                    <div class="qr-empty"><i class="fa-solid fa-spinner fa-spin"></i><span>กำลังโหลด...</span></div>
                </div>
            </div>

            
            <div id="fsSecDash" class="fs-section" hidden>
                <div class="se-toolbar">
                    <label class="se-field" id="fsDashSiteField">
                        <span class="se-label"><i class="fa-solid fa-location-dot"></i> Site</span>
                        <select id="fsDashSiteSelect" class="form-control" onchange="onFsDashChange()" style="width:auto; min-width:150px;"></select>
                    </label>
                    <label class="se-field">
                        <span class="se-label"><i class="fa-solid fa-calendar"></i> เดือน</span>
                        <input type="month" id="fsDashMonth" class="form-control" onchange="onFsDashChange()" style="width:auto;">
                    </label>
                    <span class="se-period-label" id="fsDashLabel"></span>
                    <span style="flex:1;"></span>
                </div>
                <div id="fsDashBody">
                    <div class="qr-empty"><i class="fa-solid fa-spinner fa-spin"></i><span>กำลังโหลด...</span></div>
                </div>
            </div>
        </div>
    </div>

    
    <div class="qty-modal-backdrop" id="fsRateBackdrop" onclick="if(event.target===this) closeFsRate()">
        <div class="qty-modal" role="dialog" aria-label="อัตราค่าปรับ" style="max-width:400px;">
            <div class="qty-modal-handle"></div>
            <div class="qty-modal-title"><i class="fa-solid fa-sliders" style="color:var(--primary);"></i> อัตราค่าปรับไม่แสกนนิ้ว</div>
            <div class="qty-modal-subtitle" id="fsRateSub">—</div>
            <div style="text-align:left; padding:0.2rem 0.1rem;">
                <label class="deduct-field"><span class="deduct-label">บาท / คน / วัน</span>
                    <input type="number" id="fsRateInput" class="form-control" min="0" step="1" inputmode="numeric" placeholder="100">
                </label>
                <div class="se-export-hint" style="margin-top:0.6rem;"><i class="fa-solid fa-circle-info"></i> ใช้กับการคำนวณครั้งถัดไป — วันที่บันทึกไว้แล้วไม่ถูกคิดย้อนหลัง</div>
            </div>
            <div class="qty-modal-actions" style="grid-template-columns:1fr 1fr;">
                <button type="button" class="btn btn-secondary" onclick="closeFsRate()">ยกเลิก</button>
                <button type="button" class="btn btn-primary" id="fsRateGo" onclick="saveFsRate()"><i class="fa-solid fa-floppy-disk"></i> บันทึกอัตรา</button>
            </div>
        </div>
    </div>

    
    <div class="qty-modal-backdrop" id="fsSaveBackdrop" onclick="if(event.target===this) closeFsSave()">
        <div class="qty-modal" role="dialog" aria-label="ตรวจสอบก่อนบันทึก" style="max-width:560px;">
            <div class="qty-modal-handle"></div>
            <div class="qty-modal-title"><i class="fa-solid fa-clipboard-check" style="color:var(--primary);"></i> ตรวจสอบก่อนบันทึก</div>
            <div class="qty-modal-subtitle" id="fsSaveSub">—</div>
            <div class="qty-stock-card" id="fsSaveSummary" style="margin-bottom:12px;"></div>
            <div id="fsSaveList" style="text-align:left; padding:0 0.1rem; max-height:38vh; overflow-y:auto;"></div>
            <div id="fsSaveWarn" style="display:none;"></div>
            <div class="qty-modal-actions" style="grid-template-columns:1fr 1.4fr; margin-top:14px;">
                <button type="button" class="btn btn-secondary" onclick="closeFsSave()">กลับไปแก้ไข</button>
                <button type="button" class="btn btn-primary" id="fsSaveGo" onclick="confirmFsSave()"><i class="fa-solid fa-floppy-disk"></i> ยืนยันบันทึก</button>
            </div>
        </div>
    </div>

    
    <div class="qty-modal-backdrop" id="fsLineBackdrop" onclick="if(event.target===this) closeFsLine()">
        <div class="qty-modal" role="dialog" aria-label="สรุปส่งไลน์" style="max-width:480px;">
            <div class="qty-modal-handle"></div>
            <div class="qty-modal-title"><i class="fa-solid fa-comment-dots" style="color:var(--primary);"></i> สรุปส่งไลน์</div>
            <div class="qty-modal-subtitle" id="fsLineSub">สรุปจากข้อมูลบนหน้าจอ — แก้ไขข้อความได้ก่อนคัดลอก</div>
            <textarea id="fsLineText" class="form-control" spellcheck="false" style="width:100%; min-height:260px; font-family:inherit; font-size:0.92rem; line-height:1.6; resize:vertical;"></textarea>
            <div class="qty-modal-actions" style="grid-template-columns:1fr 1fr;">
                <button type="button" class="btn btn-secondary" onclick="closeFsLine()">ปิด</button>
                <button type="button" class="btn btn-primary" id="fsLineCopyBtn" onclick="fsCopyLineText()"><i class="fa-solid fa-copy"></i> คัดลอกข้อความ</button>
            </div>
        </div>
    </div>

    
    <div class="qty-modal-backdrop" id="fsAllLinksBackdrop" onclick="if(event.target===this) closeFsAllLinks()">
        <div class="qty-modal" role="dialog" aria-label="รวมลิงก์เซ็นรับทราบ" style="max-width:560px;">
            <div class="qty-modal-handle"></div>
            <div class="qty-modal-title"><i class="fa-solid fa-share-nodes" style="color:var(--primary);"></i> รวมลิงก์เซ็นรับทราบ</div>
            <div class="qty-modal-subtitle" id="fsAllLinksSub">—</div>
            <div id="fsAllLinksBody" style="text-align:left; padding:0.1rem; max-height:56vh; overflow-y:auto;"></div>
            <div class="qty-modal-actions" style="grid-template-columns:1fr 1fr;">
                <button type="button" class="btn btn-secondary" onclick="closeFsAllLinks()">ปิด</button>
                <button type="button" class="btn btn-primary" id="fsAllLinksCopyAll" onclick="fsCopyAllLinks()"><i class="fa-solid fa-copy"></i> คัดลอกลิงก์ทั้งหมด</button>
            </div>
        </div>
    </div>

    
    <div class="qty-modal-backdrop" id="fsSignBackdrop" onclick="if(event.target===this) closeFsSign()">
        <div class="qty-modal" role="dialog" aria-label="เซ็นรับทราบค่าปรับ" style="max-width:500px;">
            <div class="qty-modal-handle"></div>
            <div class="qty-modal-title"><i class="fa-solid fa-signature" style="color:var(--primary);"></i> เซ็นรับทราบค่าปรับสแกนนิ้ว</div>
            <div class="qty-modal-subtitle" id="fsSignSub">—</div>
            <div id="fsSignBody" style="text-align:left; padding:0.2rem 0.1rem;"></div>
            <div class="qty-modal-actions" style="grid-template-columns:1fr;">
                <button type="button" class="btn btn-secondary" onclick="closeFsSign()">ปิดหน้าต่าง</button>
            </div>
        </div>
    </div>

    
    <div class="qty-modal-backdrop" id="fsGenAllBackdrop" onclick="if(event.target===this) closeFsGenAll()">
        <div class="qty-modal" role="dialog" aria-label="สร้างลิงก์เซ็นรับทราบทั้งงวด" style="max-width:440px;">
            <div class="qty-modal-handle"></div>
            <div class="qty-modal-title"><i class="fa-solid fa-link" style="color:var(--primary);"></i> สร้างลิงก์เซ็นรับทราบทั้งงวด</div>
            <div class="qty-modal-subtitle" id="fsGenAllSub">—</div>

            <div class="qty-stock-card" style="margin-bottom:16px;">
                <div class="qty-stock-row highlight">
                    <span class="label"><i class="fa-solid fa-hourglass-half" style="width:16px;"></i> ชุดที่ยังไม่มีลิงก์ / ยังไม่เซ็น</span>
                    <span class="value" id="fsGenAllPending">0</span>
                </div>
            </div>

            <div class="qty-stepper-label"><i class="fa-solid fa-calendar-day"></i> กำหนดวันรับทราบ</div>
            <div class="qty-stepper">
                <button type="button" class="qty-step-btn" onclick="fsGenAllStep(-1)" aria-label="ลด">−</button>
                <input type="number" class="qty-step-input" id="fsGenAllDays" value="3" min="0" max="60" inputmode="numeric"
                       oninput="_fsGenAllRender()"
                       onkeydown="if(event.key==='Enter'){event.preventDefault();confirmFsGenAll();}"
                       onfocus="var el=this; setTimeout(function(){ try{ el.select(); }catch(e){} }, 0);"
                       onclick="var el=this; setTimeout(function(){ try{ el.select(); }catch(e){} }, 0);">
                <button type="button" class="qty-step-btn" onclick="fsGenAllStep(1)" aria-label="เพิ่ม">+</button>
            </div>
            <div class="qty-quick-row">
                <button type="button" class="qty-quick-btn" data-days="0" onclick="fsGenAllSetDays(0)">ไม่กำหนด</button>
                <button type="button" class="qty-quick-btn" data-days="3" onclick="fsGenAllSetDays(3)">3 วัน</button>
                <button type="button" class="qty-quick-btn" data-days="5" onclick="fsGenAllSetDays(5)">5 วัน</button>
                <button type="button" class="qty-quick-btn" data-days="7" onclick="fsGenAllSetDays(7)">7 วัน</button>
                <button type="button" class="qty-quick-btn" data-days="15" onclick="fsGenAllSetDays(15)">15 วัน</button>
            </div>

            <div class="fs-genall-hint" id="fsGenAllHint"></div>

            <div class="qty-modal-actions" style="grid-template-columns:1fr 1fr;">
                <button type="button" class="btn btn-secondary" onclick="closeFsGenAll()">ยกเลิก</button>
                <button type="button" class="btn btn-primary" id="fsGenAllGo" onclick="confirmFsGenAll()"><i class="fa-solid fa-link"></i> สร้างลิงก์</button>
            </div>
        </div>
    </div>

    


    <div class="qty-modal-backdrop" id="scfgRemapBackdrop" onclick="if(event.target===this) closeScfgRemap()">
        <div class="qty-modal" role="dialog" aria-label="ปิดชุดที่มีงานค้าง" style="max-width:560px;">
            <div class="qty-modal-handle"></div>
            <div class="qty-modal-title"><i class="fa-solid fa-triangle-exclamation" style="color:#d97706;"></i> ปิดชุด — มีงานค้าง เลือกวิธีจัดการ</div>
            <div class="qty-modal-subtitle"><b>ปิดเลย</b> = ชุดนี้ทำงานจบแล้ว ระบบเคลียร์งานค้างให้ ประวัติยังเป็นของชุดเดิมทั้งหมด &nbsp;·&nbsp; <b>โอน + ปิดชุด</b> = ยุบชุดนี้เข้ากับชุดอื่น <b style="color:#b91c1c;">ประวัติทั้งหมดเปลี่ยนเป็นชื่อชุดปลายทาง</b> (เอกสารที่พิมพ์/เซ็นไปแล้วยังเป็นชื่อเดิม)</div>
            <div id="scfgRemapBody" style="text-align:left; padding:0.2rem 0.1rem; max-height:55vh; overflow-y:auto;"></div>
            <div class="qty-modal-actions" style="grid-template-columns:1fr;">
                <button type="button" class="btn btn-secondary" onclick="closeScfgRemap()">ปิดหน้าต่าง</button>
            </div>
        </div>
    </div>

    




    <div id="subsettings-page" class="page-container">
        <div class="page-header">
            <h2 class="page-title"><i class="fa-solid fa-gear"></i> ตั้งค่าผู้รับเหมา</h2>
            <button type="button" class="page-refresh-btn" onclick="refreshCurrentPage()" title="รีเฟรชข้อมูล"><i class="fa-solid fa-arrows-rotate"></i></button>
            <p class="page-subtitle">เปิด/ปิดใช้งานผู้รับเหมา จับคู่ Mango Vendor และเพิ่มผู้รับเหมาใหม่ — ชุดที่เปิดใช้งานจะถูกเติมในหน้าหักค่าใช้จ่ายผู้รับเหมา</p>
        </div>
        <div class="tabs-container">
            <div class="rate-toolbar">
                <div class="rate-site-box" id="scfgSiteBox">
                    <span class="rate-site-label"><i class="fa-solid fa-location-dot"></i> Site</span>
                    <select id="scfgSiteSelect" class="form-control" onchange="onScfgSiteChange()" style="width:auto; min-width:150px;"></select>
                </div>
                <div class="rate-search-box">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="scfgSearch" class="form-control" placeholder="ค้นหา SubID / ชื่อชุด / MangoVendor..." oninput="filterScfg()">
                </div>
                <button type="button" class="rate-only-btn" id="scfgOnlyOnBtn" onclick="scfgSetFilter('on')"><i class="fa-solid fa-toggle-on"></i> เฉพาะที่เปิดใช้งาน</button>
                <button type="button" class="rate-only-btn" id="scfgOnlyOffBtn" onclick="scfgSetFilter('off')"><i class="fa-solid fa-toggle-off"></i> เฉพาะที่ปิดใช้งาน</button>
                <button type="button" class="rate-only-btn" onclick="scfgBulkSet(true)" title="เปิดใช้งานทุกชุดที่แสดงอยู่ (ตามผลค้นหา/ตัวกรอง)"><i class="fa-solid fa-check-double"></i> เปิดทั้งหมดที่แสดง</button>
                <button type="button" class="rate-only-btn" onclick="scfgBulkSet(false)" title="ปิดใช้งานทุกชุดที่แสดงอยู่ (ตามผลค้นหา/ตัวกรอง)"><i class="fa-solid fa-ban"></i> ปิดทั้งหมดที่แสดง</button>
                <button type="button" class="btn btn-secondary" style="width:auto;" onclick="openScfgAdd()"><i class="fa-solid fa-user-plus"></i> เพิ่มผู้รับเหมา</button>
                <button type="button" class="btn btn-primary" id="scfgSaveBtn" style="width:auto;" onclick="saveScfg()" disabled><i class="fa-solid fa-floppy-disk"></i> บันทึกการตั้งค่า</button>
            </div>
            <div id="scfgBody">
                <div class="qr-empty"><i class="fa-solid fa-spinner fa-spin"></i><span>กำลังโหลด...</span></div>
            </div>
        </div>
    </div>

    

    <div class="qty-modal-backdrop" id="scfgMangoBackdrop" onclick="if(event.target===this) closeScfgMango()">
        <div class="qty-modal" role="dialog" aria-label="เลือก MangoVendor" style="max-width:520px;">
            <div class="qty-modal-handle"></div>
            <div class="qty-modal-title"><i class="fa-solid fa-link" style="color:var(--primary);"></i> ชื่อทาง Payment (Mango Vendor)</div>
            <div class="qty-modal-subtitle" id="scfgMangoSub">—</div>
            <div style="display:flex; flex-direction:column; gap:0.75rem; text-align:left; padding:0.2rem 0.1rem;">
                <div id="scfgMangoOptsWrap">
                    <div class="scfg-mp-label"><i class="fa-solid fa-star"></i> ตัวเลือกของชุดนี้ <span style="font-weight:400; color:#94a3b8;">(ตั้งไว้ได้หลายชื่อ — กดเพื่อเลือกใช้เป็นชื่อ payment)</span></div>
                    <div id="scfgMangoOpts" class="scfg-mp-list"></div>
                </div>
                <div>
                    <div class="scfg-mp-label"><i class="fa-solid fa-magnifying-glass"></i> ค้นหาจาก MangoVendors ทั้งหมด <span style="font-weight:400; color:#94a3b8;">(กดชื่อ = เลือกใช้เลย · กด <i class="fa-solid fa-plus"></i> = เก็บเข้าตัวเลือก)</span></div>
                    <input type="text" id="scfgMangoSearch" class="form-control" placeholder="พิมพ์รหัส / ชื่อ vendor..." oninput="_scfgMangoSearchRender()">
                    <div id="scfgMangoResults" class="scfg-mp-list" style="margin-top:0.45rem;"></div>
                </div>
                <div>
                    <div class="scfg-mp-label"><i class="fa-regular fa-clock"></i> ประวัติการเปลี่ยนชื่อ payment
                        <button type="button" class="btn btn-secondary" style="width:auto; padding:0.2rem 0.6rem; font-size:0.75rem; margin-left:0.4rem;" onclick="_scfgMangoLoadHist()"><i class="fa-solid fa-rotate"></i> ดูประวัติ</button>
                    </div>
                    <div id="scfgMangoHist" class="scfg-mp-note">กด "ดูประวัติ" เพื่อแสดงรายการเปลี่ยนชื่อย้อนหลังของชุดนี้</div>
                </div>
            </div>
            <div class="qty-modal-actions" style="grid-template-columns:1fr 1fr;">
                <button type="button" class="btn btn-secondary" onclick="closeScfgMango()">ปิด</button>
                <button type="button" class="btn btn-secondary" style="color:#b91c1c;" onclick="_scfgPickMango('', '')"><i class="fa-solid fa-eraser"></i> ล้างการจับคู่</button>
            </div>
        </div>
    </div>

    
    <div class="qty-modal-backdrop" id="scfgRenameBackdrop" onclick="if(event.target===this) closeScfgRename()">
        <div class="qty-modal" role="dialog" aria-label="เปลี่ยนชื่อชุด" style="max-width:480px;">
            <div class="qty-modal-handle"></div>
            <div class="qty-modal-title"><i class="fa-solid fa-pen" style="color:var(--primary);"></i> เปลี่ยนชื่อชุด (ผู้รับเหมา)</div>
            <div class="qty-modal-subtitle" id="scfgRenameSub">—</div>
            <div style="text-align:left; padding:0.2rem 0.1rem;">
                <label class="deduct-field">
                    <span class="deduct-label"><i class="fa-solid fa-signature"></i> ชื่อชุดใหม่</span>
                    <input type="text" id="scfgRenameInput" class="form-control" maxlength="60" placeholder="พิมพ์ชื่อชุดใหม่">
                </label>
                <div class="rate-alert" style="background:#fffbeb; border-color:#fde68a; color:#92400e; margin-top:0.7rem;">
                    <i class="fa-solid fa-triangle-exclamation" style="color:#d97706;"></i>
                    <div>เปลี่ยนชื่อแล้วระบบจะ<b>อัปเดตประวัติเดิมทั้งหมด</b>ให้ตามชื่อใหม่ (ใบเบิก · เบ็ดเตล็ด · ยืม-คืน · สแกนนิ้ว · หักคจช. · เอกสารหักเงิน · คำขอลายเซ็น)
                        <span class="rate-alert-sub">⚠️ ชื่อชุดคือ<b>ชื่อผู้ใช้สำหรับล็อกอิน</b>ของผู้รับเหมาด้วย — เปลี่ยนแล้วต้องแจ้งเขาให้ใช้ชื่อใหม่ · SubID และรหัสผ่านไม่เปลี่ยน · เอกสาร PDF ที่ออกไปแล้วยังเป็นชื่อเดิม</span>
                    </div>
                </div>
            </div>
            <div class="qty-modal-actions" style="grid-template-columns:1fr 1fr;">
                <button type="button" class="btn btn-secondary" onclick="closeScfgRename()">ยกเลิก</button>
                <button type="button" class="btn btn-primary" id="scfgRenameGo" onclick="submitScfgRename()"><i class="fa-solid fa-check"></i> เปลี่ยนชื่อ</button>
            </div>
        </div>
    </div>

    
    <div class="qty-modal-backdrop" id="scfgSigBackdrop" onclick="if(event.target===this) closeScfgSig()">
        <div class="qty-modal" role="dialog" aria-label="ลายเซ็นผู้รับเหมา" style="max-width:460px;">
            <div class="qty-modal-handle"></div>
            <div class="qty-modal-title"><i class="fa-solid fa-signature" style="color:var(--primary);"></i> ลายเซ็นผู้รับเหมา</div>
            <div class="qty-modal-subtitle" id="scfgSigSub">—</div>
            <div class="sig-preview-box" id="scfgSigPreview">
                <span class="sig-preview-empty"><i class="fa-solid fa-spinner fa-spin"></i> กำลังโหลด...</span>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.6rem; margin-bottom:0.6rem;">
                <button type="button" class="btn btn-primary" style="width:100%;" onclick="scfgSigDraw()">
                    <i class="fa-solid fa-pen-nib"></i> วาดลายเซ็น
                </button>
                <button type="button" class="btn btn-secondary" style="width:100%;" onclick="document.getElementById('scfgSigFile').click()">
                    <i class="fa-solid fa-file-import"></i> นำเข้ารูป
                </button>
            </div>
            <input type="file" id="scfgSigFile" accept="image/*" style="display:none;" onchange="scfgSigImport(this)">
            <button type="button" class="btn" id="scfgSigDelBtn" style="width:100%; background:#fee2e2; color:#b91c1c; display:none;" onclick="scfgSigDelete()">
                <i class="fa-solid fa-trash-can"></i> ลบลายเซ็นที่ผูกไว้
            </button>
            <div class="se-export-hint" style="margin-top:0.7rem;">
                <i class="fa-solid fa-circle-info"></i> ลายเซ็นนี้ผูกกับชุด (ชีต Subcontracts) — ในลิงก์ขอลายเซ็นของชุดนี้
                ผู้รับเหมาจะกด <b>"ยืนยันด้วยลายเซ็นนี้"</b> ได้ทันที หรือจะเซ็นสดในกรอบเองก็ได้
                · ใช้ได้ทั้งใบ<b>หักค่าวัสดุ</b> และใบ<b>ค่าปรับสแกนนิ้ว</b> · บันทึกทันทีที่วาด/นำเข้าเสร็จ (ไม่ต้องกดบันทึกหน้าตั้งค่า)
            </div>
            <div class="qty-modal-actions" style="grid-template-columns:1fr;">
                <button type="button" class="btn btn-secondary" onclick="closeScfgSig()">ปิด</button>
            </div>
        </div>
    </div>

    
    <div class="qty-modal-backdrop" id="scfgAddBackdrop" onclick="if(event.target===this) closeScfgAdd()">
        <div class="qty-modal" role="dialog" aria-label="เพิ่มผู้รับเหมา" style="max-width:460px;">
            <div class="qty-modal-handle"></div>
            <div class="qty-modal-title"><i class="fa-solid fa-user-plus" style="color:var(--primary);"></i> เพิ่มผู้รับเหมาใหม่</div>
            <div class="qty-modal-subtitle" id="scfgAddSub">—</div>
            <div style="display:flex; flex-direction:column; gap:0.8rem; text-align:left; padding:0.2rem 0.1rem;">
                <label class="deduct-field"><span class="deduct-label">ชื่อชุด (ผู้รับเหมา) *</span>
                    <input type="text" id="scfgAddName" class="form-control" placeholder="เช่น ชุด NAING WIN (MR. NAING WIN)">
                </label>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.7rem;">
                    <label class="deduct-field"><span class="deduct-label">SubID (เว้นว่าง = รันอัตโนมัติ)</span>
                        <input type="text" id="scfgAddId" class="form-control" placeholder="เช่น 341" inputmode="numeric">
                    </label>
                    <label class="deduct-field"><span class="deduct-label">รหัสผ่าน (เว้นว่าง = 1234)</span>
                        <input type="text" id="scfgAddPassword" class="form-control" placeholder="1234">
                    </label>
                </div>
                <div class="deduct-field">
                    <span class="deduct-label"><i class="fa-solid fa-link"></i> จับคู่ Mango Vendor (ชื่อใช้เบิก Payment) — ไม่บังคับ</span>
                    <div id="scfgAddMangoSel" class="scfg-addmango-sel" style="display:none;"></div>
                    <input type="text" id="scfgAddMangoSearch" class="form-control" placeholder="พิมพ์รหัส / ชื่อ vendor เพื่อค้นหา..." oninput="_scfgAddMangoRender()">
                    <div id="scfgAddMangoResults" class="scfg-mp-list" style="margin-top:0.4rem; max-height:150px;"></div>
                </div>
                <div class="se-export-hint"><i class="fa-solid fa-circle-info"></i> ชุดใหม่จะถูกเพิ่มในไซต์นี้และตั้งเป็น "เปิดใช้งาน" (Status = active) ทันที</div>
            </div>
            <div class="qty-modal-actions" style="grid-template-columns:1fr 1fr;">
                <button type="button" class="btn btn-secondary" onclick="closeScfgAdd()">ยกเลิก</button>
                <button type="button" class="btn btn-primary" id="scfgAddGo" onclick="submitScfgAdd()"><i class="fa-solid fa-plus"></i> เพิ่มผู้รับเหมา</button>
            </div>
        </div>
    </div>

    
    <div class="qty-modal-backdrop" id="seExportBackdrop" onclick="if(event.target===this) closeSeExport()">
        <div class="qty-modal" role="dialog" aria-label="พิมพ์เอกสารหักค่าใช้จ่าย" style="max-width:520px;">
            <div class="qty-modal-handle"></div>
            <div class="qty-modal-title"><i class="fa-solid fa-file-pdf" style="color:#dc2626;"></i> พิมพ์เอกสารหักคจช. (PDF)</div>
            <div class="qty-modal-subtitle" id="seExportSub">—</div>
            <div style="display:flex; flex-direction:column; gap:0.95rem; padding:0.3rem 0.15rem 0.2rem; text-align:left; max-height:min(62vh,540px); overflow-y:auto;">
                <label class="deduct-field">
                    <span class="deduct-label"><i class="fa-solid fa-diagram-project"></i> ชื่อ Project บนหัวเอกสาร</span>
                    <input type="text" id="seProjectName" class="form-control" placeholder="เช่น LAKELAND">
                </label>
                <div class="se-sign-box">
                    <div class="se-sign-box-title"><i class="fa-solid fa-users"></i> จำนวนผู้ลงนาม</div>
                    <label class="deduct-radio"><input type="radio" name="seSignerCount" value="2" checked onchange="_seSignerCountChanged()"> 2 คน — ผู้จัดทำ + ผู้อนุมัติ</label>
                    <label class="deduct-radio"><input type="radio" name="seSignerCount" value="3" onchange="_seSignerCountChanged()"> 3 คน — ผู้จัดทำ + ผู้ตรวจสอบ + ผู้อนุมัติ</label>
                </div>
                <div class="se-sign-box">
                    <div class="se-sign-box-title"><i class="fa-solid fa-user-pen"></i> ผู้ลงนามคนที่ 1 — ผู้จัดทำ</div>
                    <div style="display:grid; grid-template-columns:1.6fr 1fr; gap:0.6rem;">
                        <label class="deduct-field"><span class="deduct-label">ชื่อ-สกุล</span>
                            <input type="text" id="seSigner1Name" class="form-control" list="seUserNamesDl" placeholder="ชื่อผู้จัดทำ">
                        </label>
                        <label class="deduct-field"><span class="deduct-label">ตำแหน่ง</span>
                            <input type="text" id="seSigner1Pos" class="form-control" placeholder="เช่น SSC1">
                        </label>
                    </div>
                    <div class="esign-note" style="margin-top:0.4rem;"><i class="fa-solid fa-signature" style="color:var(--primary);"></i>
                        ถ้าคุณตั้งลายเซ็นไว้ในตั้งค่าบัญชี ลายเซ็นจะถูกฝังในช่อง "ผู้จัดทำ" อัตโนมัติ</div>
                </div>
                <div class="se-sign-box" id="seInspectorBox" style="display:none;">
                    <div class="se-sign-box-title"><i class="fa-solid fa-user-magnifying-glass"></i> ผู้ลงนามคนที่ 2 — ผู้ตรวจสอบ</div>
                    <div id="seInsOnline" style="margin-bottom:0.5rem;"></div>
                    <div style="display:grid; grid-template-columns:1.6fr 1fr; gap:0.6rem;">
                        <label class="deduct-field"><span class="deduct-label">ชื่อ-สกุล (เว้นว่าง = จุดไข่ปลาให้เขียนเอง)</span>
                            <input type="text" id="seInspectorName" class="form-control" list="seUserNamesDl" placeholder="พิมพ์หรือเลือกรายชื่อ">
                        </label>
                        <label class="deduct-field"><span class="deduct-label">ตำแหน่ง</span>
                            <input type="text" id="seInspectorPos" class="form-control" placeholder="เช่น PE / SSE">
                        </label>
                    </div>
                </div>
                <div class="se-sign-box">
                    <div class="se-sign-box-title"><i class="fa-solid fa-user-check"></i> <span id="seApproverBoxTitle">ผู้ลงนามคนที่ 2 — ผู้อนุมัติ</span></div>
                    <div id="seApvOnline" style="margin-bottom:0.5rem;"></div>
                    <div style="display:grid; grid-template-columns:1fr; gap:0.6rem;">
                        <label class="deduct-field"><span class="deduct-label">ลงนามในฐานะ</span>
                            <select id="seSigner2Label" class="form-control">
                                <option value="ผู้อนุมัติ" selected>ผู้อนุมัติ</option>
                                <option value="ผู้ตรวจสอบ">ผู้ตรวจสอบ</option>
                                <option value="ผู้รับทราบ">ผู้รับทราบ</option>
                            </select>
                        </label>
                        <div style="display:grid; grid-template-columns:1.6fr 1fr; gap:0.6rem;">
                            <label class="deduct-field"><span class="deduct-label">ชื่อ-สกุล (เว้นว่าง = จุดไข่ปลาให้เขียนเอง)</span>
                                <input type="text" id="seSigner2Name" class="form-control" list="seUserNamesDl" placeholder="พิมพ์หรือเลือกรายชื่อ">
                            </label>
                            <label class="deduct-field"><span class="deduct-label">ตำแหน่ง</span>
                                <input type="text" id="seSigner2Pos" class="form-control" placeholder="เช่น APM / PM">
                            </label>
                        </div>
                    </div>
                </div>
                <div class="se-sign-box" id="seComboBox">
                    <div class="se-sign-box-title"><i class="fa-solid fa-layer-group"></i> พิมพ์รวมเอกสารแนบของงวดนี้</div>
                    <label class="deduct-radio"><input type="checkbox" id="seComboDeduct">
                        พิมพ์รวม "สรุปงวดสแกนนิ้ว" และ "ตารางหักเงิน ผรม." (แนบประกอบการหักเงินค่าวัสดุ รายชุด) ของงวดเดียวกัน ต่อท้ายเป็น PDF ไฟล์เดียว — เรียงหน้า: หักคจช. → สรุปงวดสแกนนิ้ว → ตารางหักเงิน</label>
                    <div class="esign-note" id="seComboStatus" style="margin-top:0.25rem;"><i class="fa-solid fa-spinner fa-spin"></i> กำลังโหลดสถานะ...</div>
                </div>
                <datalist id="seUserNamesDl"></datalist>
                <div class="se-export-hint"><i class="fa-solid fa-circle-info"></i> ระบบจะบันทึกข้อมูลในตารางให้อัตโนมัติก่อนสร้าง PDF</div>
            </div>
            <div class="qty-modal-actions" style="grid-template-columns:1fr 1fr;">
                <button type="button" class="btn btn-secondary" onclick="closeSeExport()">ยกเลิก</button>
                <button type="button" class="btn btn-primary" id="seExportGo" onclick="submitSeExport()"><i class="fa-solid fa-file-pdf"></i> สร้าง PDF</button>
            </div>
        </div>
    </div>

    
    <div class="qty-modal-backdrop" id="seAddRowBackdrop" onclick="if(event.target===this) closeSeAddRow()">
        <div class="qty-modal" role="dialog" aria-label="เพิ่มแถวผู้รับเหมา" style="max-width:480px;">
            <div class="qty-modal-handle"></div>
            <div class="qty-modal-title"><i class="fa-solid fa-user-plus" style="color:var(--primary);"></i> เพิ่มแถวผู้รับเหมา</div>
            <div class="qty-modal-subtitle">เลือกจากชุดที่เปิดใช้งานในหน้า "ตั้งค่า" — เลือกได้ต่อเนื่องหลายชุด</div>
            <div style="display:flex; flex-direction:column; gap:0.6rem; text-align:left; padding:0.2rem 0.1rem;">
                <input type="text" id="seAddRowSearch" class="form-control" placeholder="ค้นหาชื่อชุด / ชื่อ Payment..." oninput="_seAddRowRender()">
                <div id="seAddRowList" class="scfg-mp-list" style="max-height:300px;"></div>
            </div>
            <div class="qty-modal-actions" style="grid-template-columns:1fr;">
                <button type="button" class="btn btn-primary" onclick="closeSeAddRow()"><i class="fa-solid fa-check"></i> เสร็จสิ้น</button>
            </div>
        </div>
    </div>

    
    <div class="qty-modal-backdrop" id="seShopBackdrop" onclick="if(event.target===this) closeSeShop()">
        <div class="qty-modal" role="dialog" aria-label="เลือกรายการร้านค้า" style="max-width:480px;">
            <div class="qty-modal-handle"></div>
            <div class="qty-modal-title"><i class="fa-solid fa-store" style="color:var(--primary);"></i> ร้านค้า / เครื่องใช้</div>
            <div class="qty-modal-subtitle" id="seShopSub">—</div>
            <div style="display:flex; flex-direction:column; gap:0.6rem; text-align:left; padding:0.2rem 0.1rem;">
                <div id="seShopList" class="scfg-mp-list" style="max-height:280px;"></div>
                <div class="se-shop-total-bar">รวมค่าใช้จ่ายหมวดนี้: <b id="seShopTotal">—</b></div>
            </div>
            <div class="qty-modal-actions" style="grid-template-columns:1fr 1fr;">
                <button type="button" class="btn btn-secondary" onclick="closeSeShop()">ยกเลิก</button>
                <button type="button" class="btn btn-primary" onclick="seShopApply()"><i class="fa-solid fa-check"></i> ตกลง</button>
            </div>
        </div>
    </div>

    
    <div class="qty-modal-backdrop" id="seCfgBackdrop" onclick="if(event.target===this) closeSeCfg()">
        <div class="qty-modal" role="dialog" aria-label="ตั้งค่าราคา" style="max-width:540px;">
            <div class="qty-modal-handle"></div>
            <div class="qty-modal-title"><i class="fa-solid fa-sliders" style="color:var(--primary);"></i> ตั้งค่าราคา — หักค่าใช้จ่ายผู้รับเหมา</div>
            <div class="qty-modal-subtitle" id="seCfgSub">—</div>
            <div style="display:flex; flex-direction:column; gap:0.8rem; text-align:left; padding:0.2rem 0.1rem; max-height:60vh; overflow-y:auto;">
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.6rem;">
                    <label class="deduct-field"><span class="deduct-label">ค่าห้องพัก บ./งวด</span>
                        <input type="number" id="seCfgRoomRate" class="form-control" step="any" min="0" inputmode="decimal">
                    </label>
                    <label class="deduct-field"><span class="deduct-label">หน่วยไฟที่ใช้ได้ฟรี/งวด (เกินจึงคิดเงิน)</span>
                        <input type="number" id="seCfgElecFree" class="form-control" step="any" min="0" inputmode="decimal">
                    </label>
                    <label class="deduct-field"><span class="deduct-label">ค่าไฟส่วนเกิน บ./หน่วย</span>
                        <input type="number" id="seCfgElecRate" class="form-control" step="any" min="0" inputmode="decimal">
                    </label>
                    <label class="deduct-field"><span class="deduct-label">มิเตอร์ไฟร้านค้า บ./หน่วย</span>
                        <input type="number" id="seCfgShopElecRate" class="form-control" step="any" min="0" inputmode="decimal">
                    </label>
                </div>
                <div>
                    <div class="scfg-mp-label"><i class="fa-solid fa-store"></i> รายการร้านค้า / เครื่องใช้ (ชื่อ + ราคา/งวด — เพิ่มได้ไม่จำกัด)</div>
                    <div id="seCfgItems" style="display:flex; flex-direction:column; gap:0.45rem;"></div>
                    <button type="button" class="btn btn-secondary" style="width:auto; margin-top:0.5rem;" onclick="seCfgAddItem()"><i class="fa-solid fa-plus"></i> เพิ่มรายการ</button>
                </div>
                <div class="se-export-hint"><i class="fa-solid fa-circle-info"></i> ราคาที่ตั้งจะใช้เป็นค่า default ของไซต์นี้ (หัวตาราง, สูตรคำนวณ และหัวตารางใน PDF) — งวดที่บันทึกไปแล้วใช้ราคาที่บันทึกไว้เดิม ไม่ถูกเปลี่ยนย้อนหลัง</div>
            </div>
            <div class="qty-modal-actions" style="grid-template-columns:1fr 1fr;">
                <button type="button" class="btn btn-secondary" onclick="closeSeCfg()">ยกเลิก</button>
                <button type="button" class="btn btn-primary" id="seCfgGo" onclick="submitSeCfg()"><i class="fa-solid fa-floppy-disk"></i> บันทึกตั้งค่า</button>
            </div>
        </div>
    </div>

    

    
    
    <div class="qty-modal-backdrop" id="deductExportBackdrop" onclick="if(event.target===this) closeDeductExport()">
        <div class="qty-modal" role="dialog" aria-label="ออกเอกสารหักเงิน" style="max-width:560px;" id="deductModalCard">
            <div class="qty-modal-handle"></div>
            <div class="qty-modal-title"><i class="fa-solid fa-file-pdf" style="color:#dc2626;"></i> ออกเอกสารหักเงิน</div>
            <div class="qty-modal-subtitle" id="deductExportSub">—</div>
            <div class="deduct-modal-tabs">
                <button type="button" id="deductTabBtnExport" class="on" onclick="deductModalTab('export')"><i class="fa-solid fa-file-pdf"></i> ออกเอกสาร PDF</button>
                <button type="button" id="deductTabBtnSign" onclick="deductModalTab('sign')"><i class="fa-solid fa-signature"></i> ลายเซ็นออนไลน์</button>
            </div>

            
            <div id="deductPanelExport">
                <div style="display:flex; flex-direction:column; gap:0.95rem; padding:0.3rem 0.15rem 0.2rem; text-align:left;">
                    <div class="deduct-field">
                        <span class="deduct-label"><i class="fa-solid fa-people-carry-box"></i> เลือกชุด (ผู้รับเหมา) — เลือกได้หลายชุด</span>
                        <label class="deduct-radio" style="font-weight:700; border-bottom:1px solid var(--border); padding-bottom:0.45rem; margin-bottom:0.1rem;"><input type="checkbox" id="deductSubAll" onchange="_deductToggleAll(this)"> เลือกทั้งหมด</label>
                        <div id="deductSubList" style="max-height:190px; overflow-y:auto; display:flex; flex-direction:column; gap:0.1rem; padding-top:0.15rem;"></div>
                    </div>
                    <div class="deduct-field">
                        <span class="deduct-label"><i class="fa-solid fa-calendar-week"></i> เลือกงวด (รายงวดครึ่งเดือน)</span>
                        <div class="deduct-half-row">
                            <label class="deduct-half-opt" id="deductHalfOpt1">
                                <input type="radio" name="deductHalf" value="1" onchange="_deductHalfChanged()">
                                <span class="deduct-half-title">งวดที่ 1</span>
                                <span class="deduct-half-sub">วันที่ 1–15</span>
                                <span class="deduct-half-count" id="deductHalfCount1">—</span>
                            </label>
                            <label class="deduct-half-opt" id="deductHalfOpt2">
                                <input type="radio" name="deductHalf" value="2" onchange="_deductHalfChanged()">
                                <span class="deduct-half-title">งวดที่ 2</span>
                                <span class="deduct-half-sub">วันที่ 16–สิ้นเดือน</span>
                                <span class="deduct-half-count" id="deductHalfCount2">—</span>
                            </label>
                        </div>
                    </div>
                    <div class="deduct-field">
                        <span class="deduct-label"><i class="fa-solid fa-user-pen"></i> ผู้สรุปเอกสาร</span>
                        <label class="deduct-radio"><input type="radio" name="deductSummarizer" value="name" checked> ใช้ชื่อผู้ออกเอกสาร: <b id="deductExporterName">—</b></label>
                        <label class="deduct-radio"><input type="radio" name="deductSummarizer" value="none"> ไม่ระบุ (เว้นว่างให้เซ็น)</label>
                        <div class="esign-note"><i class="fa-solid fa-signature" style="color:var(--primary);"></i>
                            ถ้าตั้งลายเซ็นไว้ในตั้งค่าบัญชี ลายเซ็นของคุณจะถูกฝังในช่อง “ผู้สรุปเอกสาร” อัตโนมัติ ·
                            ลายเซ็นออนไลน์ที่เก็บได้แล้ว (ผู้รับเหมา/ผู้ตรวจสอบ/ผู้อนุมัติ — แท็บ “ลายเซ็นออนไลน์” ด้านบน) จะถูกฝังลง PDF ด้วย</div>
                    </div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.7rem;">
                        <label class="deduct-field"><span class="deduct-label"><i class="fa-solid fa-user-check"></i> ตำแหน่งผู้ตรวจสอบ</span>
                            <input type="text" id="deductInspectorPos" class="form-control" value="PE / SSE" placeholder="เช่น PE / SSE">
                        </label>
                        <label class="deduct-field"><span class="deduct-label"><i class="fa-solid fa-user-shield"></i> ตำแหน่งผู้อนุมัติ</span>
                            <input type="text" id="deductApproverPos" class="form-control" value="PM" placeholder="เช่น PM">
                        </label>
                    </div>
                </div>
                <div class="qty-modal-actions" style="grid-template-columns:1fr 1fr;">
                    <button type="button" class="btn btn-secondary" onclick="closeDeductExport()">ยกเลิก</button>
                    <button type="button" class="btn btn-primary" id="deductExportGo" onclick="submitDeductExport()"><i class="fa-solid fa-file-pdf"></i> สร้าง PDF</button>
                </div>
            </div>

            
            <div id="deductPanelSign" style="display:none;">
                <div id="signManageBody" style="max-height:min(62vh,560px); overflow-y:auto; text-align:left; padding:0.2rem 0.1rem;">
                    <div class="qr-empty"><i class="fa-solid fa-spinner fa-spin"></i><span>กำลังโหลดข้อมูล...</span></div>
                </div>
                <div class="qty-modal-actions" style="grid-template-columns:1fr;">
                    <button type="button" class="btn btn-secondary" onclick="closeDeductExport()">ปิด</button>
                </div>
            </div>
        </div>
    </div>

    

    <div class="qty-modal-backdrop" id="sgAllLinksBackdrop" onclick="if(event.target===this) closeSgAllLinks()">
        <div class="qty-modal" role="dialog" aria-label="รวมลิงก์เซ็นรับทราบรายชุด" style="max-width:560px;">
            <div class="qty-modal-handle"></div>
            <div class="qty-modal-title"><i class="fa-solid fa-share-nodes" style="color:var(--primary);"></i> รวมชุด · เปิดหน้าเซ็นรับทราบ</div>
            <div class="qty-modal-subtitle" id="sgAllLinksSub">—</div>
            <div id="sgAllLinksBody" style="text-align:left; padding:0.1rem; max-height:56vh; overflow-y:auto;"></div>
            <div class="qty-modal-actions" style="grid-template-columns:1fr 1fr;">
                <button type="button" class="btn btn-secondary" onclick="closeSgAllLinks()">ปิด</button>
                <button type="button" class="btn btn-primary" id="sgAllLinksCopyAll" onclick="sgCopyAllLinks()"><i class="fa-solid fa-copy"></i> คัดลอกลิงก์ทั้งหมด</button>
            </div>
        </div>
    </div>

    
    <div class="qty-modal-backdrop" id="signDetailBackdrop" onclick="if(event.target===this) closeSignDetail()">
        <div class="qty-modal" role="dialog" aria-label="รายละเอียดเอกสารหักเงิน" style="max-width:820px;">
            <div class="qty-modal-handle"></div>
            <div class="qty-modal-title"><i class="fa-solid fa-file-invoice" style="color:var(--primary);"></i> <span id="signDetailTitle">รายละเอียดเอกสาร</span></div>
            <div class="qty-modal-subtitle" id="signDetailSub">—</div>
            <div id="signDetailBody" style="max-height:min(58vh,520px); overflow:auto; text-align:left; padding:0.2rem 0.1rem;">
                <div class="qr-empty"><i class="fa-solid fa-spinner fa-spin"></i><span>กำลังโหลดข้อมูล...</span></div>
            </div>
            <div class="qty-modal-actions" id="signDetailActions" style="grid-template-columns:1fr;">
                <button type="button" class="btn btn-secondary" onclick="closeSignDetail()">ปิด</button>
            </div>
        </div>
    </div>

    
    <div class="qty-modal-backdrop" id="signOpenBackdrop" onclick="if(event.target===this) closeSignOpen()">
        <div class="qty-modal" role="dialog" aria-label="หน้าลงนามรับทราบ" style="max-width:860px; width:96vw;">
            <div class="qty-modal-handle"></div>
            <div class="qty-modal-title"><i class="fa-solid fa-signature" style="color:var(--primary);"></i> หน้าลงนามรับทราบ (ผู้รับเหมา)</div>
            <div class="qty-modal-subtitle" id="signOpenSub">—</div>
            <div class="rate-alert" id="signOpenWarn" style="display:none; background:#fffbeb; border-color:#fde68a; color:#92400e;">
                <i class="fa-solid fa-triangle-exclamation" style="color:#d97706;"></i>
                <div>เปิดหน้าเซ็นในกรอบนี้ไม่สำเร็จ
                    <span class="rate-alert-sub">กด <b>"เปิดแท็บใหม่"</b> ด้านล่าง · หรือใช้ปุ่ม <b>"คัดลอกลิงก์"</b> ส่งให้ผู้รับเหมาเปิดบนเครื่องตัวเอง (ลิงก์นี้เปิดได้โดยไม่ต้องล็อกอิน)</span>
                </div>
            </div>
            <div class="signopen-frame"><iframe id="signOpenFrame" src="" title="หน้าลงนามรับทราบ"></iframe></div>
            <div class="se-export-hint" style="margin-top:0.5rem;">
                <i class="fa-solid fa-circle-info"></i> ยื่นเครื่องให้ผู้รับเหมาเซ็นได้เลย · เซ็นเสร็จกด "ปิด" แล้วระบบจะรีเฟรชสถานะให้
            </div>
            <div class="qty-modal-actions" style="grid-template-columns:1fr 1fr;">
                <button type="button" class="btn btn-secondary" onclick="closeSignOpen()">ปิด</button>
                <button type="button" class="btn btn-primary" onclick="signOpenNewTab()"><i class="fa-solid fa-up-right-from-square"></i> เปิดแท็บใหม่</button>
            </div>
        </div>
    </div>

    
    <div class="qty-modal-backdrop" id="signTaskBackdrop" onclick="if(event.target===this) closeSignTaskModal()">
        <div class="qty-modal" role="dialog" aria-label="ลงนามเอกสาร" style="max-width:520px;">
            <div class="qty-modal-handle"></div>
            <div class="qty-modal-title"><i class="fa-solid fa-pen-nib" style="color:var(--primary);"></i> <span id="signTaskTitle">ลงนามเอกสาร</span></div>
            <div class="qty-modal-subtitle" id="signTaskSub">—</div>
            <div style="display:flex; flex-direction:column; gap:0.8rem; text-align:left; padding:0.3rem 0.15rem 0.2rem;">
                <label class="deduct-field">
                    <span class="deduct-label"><i class="fa-solid fa-id-badge"></i> ตำแหน่งที่แสดงบนเอกสาร</span>
                    <input type="text" id="signTaskPos" class="form-control" placeholder="เช่น PE / SSE หรือ PM">
                </label>
                <div class="deduct-field">
                    <span class="deduct-label"><i class="fa-solid fa-signature"></i> ลายเซ็นที่จะใช้</span>
                    <div class="sig-use-box" id="signTaskSigBox">
                        <span class="sig-preview-empty">กำลังโหลด...</span>
                    </div>
                    <div style="display:flex; gap:0.5rem; margin-top:0.45rem;">
                        <button type="button" class="esign-btn soft" onclick="signTaskDraw()"><i class="fa-solid fa-pen"></i> วาดลายเซ็นใหม่</button>
                    </div>
                    <label class="deduct-radio" id="signTaskSaveWrap" style="display:none;">
                        <input type="checkbox" id="signTaskSaveProfile" checked> บันทึกลายเซ็นใหม่นี้เป็นลายเซ็นของฉัน
                    </label>
                </div>
            </div>
            <div class="qty-modal-actions" style="grid-template-columns:1fr 1fr;">
                <button type="button" class="btn btn-secondary" onclick="closeSignTaskModal()">ยกเลิก</button>
                <button type="button" class="btn btn-primary" id="signTaskGo" onclick="submitSignTask()"><i class="fa-solid fa-check"></i> ยืนยันลงนาม</button>
            </div>
        </div>
    </div>

    
    <div class="qty-modal-backdrop" id="sigViewBackdrop" onclick="if(event.target===this) closeSigView()">
        <div class="qty-modal" role="dialog" aria-label="ดูลายเซ็น" style="max-width:440px;">
            <div class="qty-modal-handle"></div>
            <div class="qty-modal-title"><i class="fa-solid fa-magnifying-glass" style="color:var(--primary);"></i> <span id="sigViewTitle">ลายเซ็น</span></div>
            <div class="qty-modal-subtitle" id="sigViewSub">—</div>
            <div id="sigViewBody" style="text-align:left; padding:0.3rem 0.15rem 0.2rem;"></div>
            <div class="qty-modal-actions" style="grid-template-columns:1fr;">
                <button type="button" class="btn btn-secondary" onclick="closeSigView()">ปิด</button>
            </div>
        </div>
    </div>

    
    <div id="sigPadBackdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:10000; align-items:center; justify-content:center; padding:1rem;">
        <div style="background:#fff; border-radius:16px; padding:1.3rem; width:min(560px,94vw); box-shadow:0 20px 60px rgba(0,0,0,0.25);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.8rem;">
                <div style="font-weight:800; color:var(--secondary); font-size:1.02rem;">
                    <i class="fa-solid fa-pen-nib" style="color:var(--primary); margin-right:0.4rem;"></i>วาดลายเซ็น
                </div>
                <button onclick="closeSigPad()" style="background:none; border:none; font-size:1.35rem; cursor:pointer; color:var(--text-muted);">&times;</button>
            </div>
            <div class="sig-pad-shell" id="sigPadShell">
                <canvas id="sigPadCanvas"></canvas>
                <div class="sig-pad-hint" id="sigPadHint">✍️ เซ็นชื่อในกรอบนี้ (ใช้นิ้วหรือเมาส์)</div>
            </div>
            <div style="display:flex; gap:0.6rem; margin-top:0.85rem;">
                <button type="button" class="esign-btn ghost" style="flex:0 0 auto;" onclick="sigPadClear()"><i class="fa-solid fa-eraser"></i> ล้าง</button>
                <button type="button" class="btn btn-secondary" style="flex:1;" onclick="closeSigPad()">ยกเลิก</button>
                <button type="button" class="btn btn-primary" style="flex:1.4;" onclick="sigPadUse()"><i class="fa-solid fa-check"></i> ใช้ลายเซ็นนี้</button>
            </div>
        </div>
    </div>

    <div id="appPopupModal">
        <div class="app-popup-box">
            <div class="app-popup-head">
                <div class="app-popup-icon info" id="appPopupIcon"><i class="fa-solid fa-circle-info"></i></div>
                <div>
                    <div class="app-popup-title" id="appPopupTitle">แจ้งเตือน</div>
                    <div class="app-popup-message" id="appPopupMessage">-</div>
                </div>
            </div>
            <div class="app-popup-actions" id="appPopupActions"></div>
        </div>
    </div>

    <script>
         
         
         
         
        var _qrViewedDocIds = window._qrViewedDocIds || (window._qrViewedDocIds = {});
        var _scannedDocIds  = window._scannedDocIds  || (window._scannedDocIds  = {});

         
         
         
        function _onDocScannedAtGate(docId) {
            if (!docId) return;
            _scannedDocIds[docId] = true;
            hapticSuccess();
            try { renderQRCards(); } catch (e) {}
            showInfoPopup(
                '✅ สแกนที่ประตูแล้ว',
                'เอกสาร ' + docId + ' ผ่านประตูเรียบร้อย\nไปที่เมนู "ถ่ายรูปยืนยัน" เพื่อถ่ายรูปยืนยันการรับของ',
                'success',
                function () {
                    loadQRPage({ force: true });
                    loadConfirmableDocuments({ force: true });
                }
            );
        }

        function showQRModal(docId, docType, items, receiver, reqName) {
             
            if (docId) { _qrViewedDocIds[docId] = true; try { renderQRCards(); } catch (e) {} }
             
             
            var payload = {
                Doc:      docId,
                Req:      reqName  || '',
                Receiver: receiver || ''
            };
            var jsonStr = JSON.stringify(payload);

            var typeLabel = docType === 'RD' ? '✅ เบิกวัสดุ'
                          : docType === 'BD' ? '✅ ยืมอุปกรณ์'
                          : docType === 'OD' ? '✅ เบ็ดเตล็ด'
                          : docType === 'IN' ? '📦 รับเข้าคลัง'
                          : '✅ เอกสาร';
            document.getElementById('qrModalTitle').textContent = typeLabel + ' — พร้อมนำจ่าย';
            document.getElementById('qrModalMeta').innerHTML =
                '<strong>' + escapeHtml(docId) + '</strong>' +
                (receiver ? ' &nbsp;|&nbsp; ' + (docType === 'IN' ? 'RS: ' : 'ผู้รับ: ') + escapeHtml(receiver) : '') +
                (reqName  ? ' &nbsp;|&nbsp; ผู้ขอ: ' + escapeHtml(reqName) : '');

             
            var listHtml = items.map(function(i) {
                var bal = globalBalance[i.matCode];
                var unit = (bal && bal['Unit']) ? bal['Unit'].toString() : (i.unit || '');
                return '<div>' + (i.matName || i.matCode || '') +
                       ' &nbsp;<strong>' + i.qty + (unit ? ' ' + unit : '') + '</strong></div>';
            }).join('');
            document.getElementById('qrItemsList').innerHTML = listHtml;

            var container = document.getElementById('qrCanvas');
            container.innerHTML = _localQrImgTag(jsonStr);  

            window._qrPayload = { docId: docId, jsonStr: jsonStr };
            document.getElementById('qrModal').classList.add('open');

            startGateStatusPolling(docId);
        }

        var _gateStatusPollTimer = null;
        var _gateStatusPollInFlight = false;
        function startGateStatusPolling(docId) {
            stopGateStatusPolling();
            if (!docId) return;
            var tick = function () {
                if (_gateStatusPollInFlight) return;
                if (!document.getElementById('qrModal').classList.contains('open')) {
                    stopGateStatusPolling();
                    return;
                }
                _gateStatusPollInFlight = true;
                google.script.run
                    .withSuccessHandler(function (res) {
                        _gateStatusPollInFlight = false;
                        var raw = (res && res.status != null) ? String(res.status) : '';
                        var status = raw.trim().toLowerCase();
                        console.log('[GatePoll] docId=' + docId + ' status="' + raw + '"');
                         
                        if (status === 'opened' || status === 'scanned') {
                            stopGateStatusPolling();
                            closeQRModal();
                             
                            _onDocScannedAtGate(docId);
                        }
                    })
                    .withFailureHandler(function (err) {
                        _gateStatusPollInFlight = false;
                        console.warn('[GatePoll] failed:', err);
                    })
                    .checkGateStatusForDoc(docId);
            };
            tick();
            _gateStatusPollTimer = setInterval(tick, 3000);
        }
        function stopGateStatusPolling() {
            if (_gateStatusPollTimer) {
                clearInterval(_gateStatusPollTimer);
                _gateStatusPollTimer = null;
            }
            _gateStatusPollInFlight = false;
        }

         
         

        function closeQRModal() {
            stopGateStatusPolling();
            document.getElementById('qrModal').classList.remove('open');
            document.getElementById('qrCanvas').innerHTML = '';
        }

         
         
         
         
         
        function requestReturnConfirm(borrowId) {
            hapticTap();
            var data = unreturnedData[borrowId] || {};
            var rowItems = data.rowItems || [];
            var listHtml = rowItems.map(function (it) {
                return '• ' + escapeHtml(it.matName || it.matCode || '') +
                    ' &nbsp;<strong>x' + escapeHtml(String(it.qty)) + '</strong>';
            }).join('<br>') || '-';

            showAppPopup({
                type: 'warning',
                title: 'ยืนยันส่งคืนอุปกรณ์',
                message: '<strong>' + escapeHtml(borrowId) + '</strong>' +
                    (data.borrowerInfo ? '<br><span style="color:var(--text-muted);">ผู้ยืม: ' + escapeHtml(data.borrowerInfo) + '</span>' : '') +
                    '<br><br>' + listHtml +
                    '<br><br>เมื่อยืนยัน ใบนี้จะถูกส่งไปที่หน้า <strong>QR เอกสาร</strong> เพื่อนำไปสแกนคืนที่ประตู',
                buttons: [
                    { text: 'ยกเลิก', className: 'btn btn-secondary', onClick: closeAppPopup },
                    { text: 'ยืนยันส่งคืน', className: 'btn btn-primary', onClick: function () { closeAppPopup(); _doRegisterReturn(borrowId); } }
                ]
            });
        }

         
         
        function _doRegisterReturn(borrowId) {
            showLoadingPopup('กำลังส่งข้อมูลคืน', 'เอกสาร ' + borrowId + 'RT\nกรุณารอสักครู่...');
            google.script.run
                .withSuccessHandler(function (res) {
                    closeAppPopup();
                    if (!res || !res.success) {
                        showInfoPopup('ส่งคืนไม่สำเร็จ', (res && res.message) ? res.message : 'ไม่สามารถส่งข้อมูลคืนได้', 'danger');
                        return;
                    }
                    hapticSuccess();
                    connextCache.invalidateMany(['unreturned', 'approvedDocs', 'confirmableDocs', 'balance', 'history']);
                    if (typeof loadUnreturnedItems === 'function') loadUnreturnedItems({ force: true });
                    showToast('📦 ส่งคืน ' + borrowId + ' แล้ว — สแกน QR ที่หน้า QR เอกสารได้เลย', 'success');
                    if (typeof switchToPage === 'function') switchToPage('qr');
                    if (typeof loadQRPage === 'function') loadQRPage({ force: true });
                })
                .withFailureHandler(function (err) {
                    closeAppPopup();
                    showInfoPopup('ส่งคืนไม่สำเร็จ', (err && err.message) ? err.message : String(err || 'unknown'), 'danger');
                })
                .registerReturnGateLog(borrowId);
        }

        function downloadQR() {
            var img = document.querySelector('#qrCanvas img');
            if (!img) return;
            fetch(img.src)
                .then(function (r) { return r.blob(); })
                .then(function (blob) {
                    var a = document.createElement('a');
                    a.href = URL.createObjectURL(blob);
                    a.download = (window._qrPayload ? window._qrPayload.docId : 'qr') + '_QR.png';
                    a.click();
                    setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
                })
                .catch(function () { window.open(img.src); });
        }

        document.getElementById('qrModal').addEventListener('click', function(e) {
            if (e.target === this) closeQRModal();
        });
    </script>

        <script>
        var globalBalance = {};
        var unreturnedData = {};  
        var draftItems = [];
        var subcontractNameMap = {};  
        var globalGateMap = {};       
        var globalUserMap = {};       

         
         
        function userFullName(username) {
            if (!username) return '';
            var key = username.toString().trim().toLowerCase();
            return globalUserMap[key] || username;
        }
         
        function loadUserDirectory(opts) {
            connextCache.swr('userDirectory', [],
                function (done, fail) {
                    google.script.run.withSuccessHandler(done).withFailureHandler(fail).getUserDirectory();
                },
                function (rows) {
                    var map = {};
                    (rows || []).forEach(function (u) {
                        if (!u || !u.username) return;
                        map[u.username.toString().trim().toLowerCase()] = u.fullName || u.username;
                    });
                    globalUserMap = map;
                     
                    try { if (document.getElementById('qrCardsGrid')) renderQRCards(); } catch (e) {}
                    try { if (document.getElementById('approvalQueue')) renderApprovalQueue(); } catch (e) {}
                },
                function (err) { console.warn('loadUserDirectory failed:', err); },
                opts
            );
        }

        function getReceiverDisplayName(receiverId) {
            if (!receiverId || receiverId === '-') return '-';
            if (receiverId.indexOf('DC: ') === 0) return receiverId;  
            return subcontractNameMap[receiverId] || receiverId;       
        }
        var oddsDraftItems = [];
        var borrowDraftItems = [];
        var historyData = [];

        var appPopupState = {
            onClose: null
        };

        function closeAppPopup() {
            var modal = document.getElementById('appPopupModal');
            if (!modal) return;
            modal.classList.remove('open');
            document.body.style.overflow = '';
            var callback = appPopupState.onClose;
            appPopupState.onClose = null;
            if (typeof callback === 'function') callback();
        }

        function showAppPopup(options) {
            var modal = document.getElementById('appPopupModal');
            var icon = document.getElementById('appPopupIcon');
            var title = document.getElementById('appPopupTitle');
            var message = document.getElementById('appPopupMessage');
            var actions = document.getElementById('appPopupActions');
            if (!modal || !icon || !title || !message || !actions) return;

            var type = options && options.type ? options.type : 'info';
            var iconHtmlMap = {
                info: '<i class="fa-solid fa-circle-info"></i>',
                success: '<i class="fa-solid fa-circle-check"></i>',
                warning: '<i class="fa-solid fa-triangle-exclamation"></i>',
                danger: '<i class="fa-solid fa-circle-xmark"></i>',
                loading: '<div class="popup-spinner"></div>'
            };

            icon.className = 'app-popup-icon ' + (type === 'loading' ? 'info' : type);
            icon.innerHTML = iconHtmlMap[type] || iconHtmlMap.info;
            title.textContent = options && options.title ? options.title : 'แจ้งเตือน';
            message.innerHTML = options && options.message ? options.message : '';
            actions.innerHTML = '';
            appPopupState.onClose = options && typeof options.onClose === 'function' ? options.onClose : null;

            var buttons = options && options.buttons ? options.buttons : [{
                text: 'ตกลง',
                className: 'btn btn-primary',
                onClick: closeAppPopup
            }];

            buttons.forEach(function (buttonConfig) {
                var button = document.createElement('button');
                button.className = buttonConfig.className || 'btn btn-primary';
                button.textContent = buttonConfig.text || 'ตกลง';
                button.disabled = !!buttonConfig.disabled;
                button.onclick = function () {
                    if (typeof buttonConfig.onClick === 'function') {
                        buttonConfig.onClick();
                    } else {
                        closeAppPopup();
                    }
                };
                actions.appendChild(button);
            });

            modal.classList.add('open');
            document.body.style.overflow = 'hidden';
        }

        function showInfoPopup(title, message, type, onClose) {
            showAppPopup({
                type: type || 'info',
                title: title,
                message: message,
                onClose: onClose,
                buttons: [{
                    text: 'ตกลง',
                    className: 'btn btn-primary',
                    onClick: closeAppPopup
                }]
            });
        }

        function showLoadingPopup(title, message) {
            showAppPopup({
                type: 'loading',
                title: title,
                message: message,
                buttons: []
            });
        }

         
        var _toastTimer = null;
        function showToast(message, type) {
            var el = document.getElementById('appToast');
            if (!el) {
                el = document.createElement('div');
                el.id = 'appToast';
                document.body.appendChild(el);
            }
            var iconMap = {
                success: '<i class="fa-solid fa-circle-check"></i>',
                danger:  '<i class="fa-solid fa-circle-xmark"></i>',
                warning: '<i class="fa-solid fa-triangle-exclamation"></i>',
                info:    '<i class="fa-solid fa-circle-info"></i>'
            };
            el.className = 'app-toast ' + (type || 'info');
            el.innerHTML = (iconMap[type] || iconMap.info) + '<span>' + message + '</span>';
             
            void el.offsetWidth;
            el.classList.add('show');
            if (_toastTimer) clearTimeout(_toastTimer);
            _toastTimer = setTimeout(function () { if (el) el.classList.remove('show'); }, 2200);
        }

        function showConfirmPopup(title, message, onConfirm, confirmText, confirmClassName) {
            showAppPopup({
                type: 'warning',
                title: title,
                message: message,
                buttons: [{
                    text: 'ยกเลิก',
                    className: 'btn btn-secondary',
                    onClick: closeAppPopup
                }, {
                    text: confirmText || 'ยืนยัน',
                    className: confirmClassName || 'btn btn-primary',
                    onClick: function () {
                        closeAppPopup();
                        if (typeof onConfirm === 'function') onConfirm();
                    }
                }]
            });
        }

        function getRoleNumber(roleLevel) {
            var matched = (roleLevel || '').toString().match(/\d+/);
            return matched ? parseInt(matched[0], 10) : 0;
        }

         
        function getEffectiveSiteCode() {
            if (!user) return '';
            if (user.roleLevel === 'R0') return '';
            return user.siteCode || '';
        }

         
        function getEffectiveUserName() {
            if (!user) return '';
            if (user.role === 'Subcontractor') return user.name || user.username || '';
            return user.username || '';
        }

        function formatBalanceValue(value) {
            var num = parseFloat(value);
            if (!isFinite(num)) return '0';
            if (Math.abs(num % 1) < 0.000001) return String(Math.round(num));
            return num.toFixed(2).replace(/\.00$/, '');
        }

        function updateMaterialBalanceHint(selectId, hintId) {
            var select = document.getElementById(selectId);
            var hint = document.getElementById(hintId);
            if (!select || !hint) return;

            var matCode = select.value || '';
            if (!matCode) {
                hint.innerHTML = 'เลือกวัสดุเพื่อดูจำนวนคงเหลือ';
                return;
            }

            var selectedOpt = select.selectedIndex >= 0 ? select.options[select.selectedIndex] : null;
            var matName = selectedOpt ? selectedOpt.textContent : matCode;
            var unit = selectedOpt ? (selectedOpt.getAttribute('data-unit') || '') : '';
            var balance = getAvailableBalance(matCode);
            if (balance <= 0) {
                 
                hint.innerHTML = '<strong style="color:#dc2626;"><i class="fa-solid fa-circle-xmark"></i> หมดสต๊อก — เบิกไม่ได้</strong> (คงเหลือ 0' + (unit ? ' ' + unit : '') + ')<br><strong>วัสดุ:</strong> ' + escapeHtml(matName) + _gateBreakdownHtml(matCode, unit);
            } else {
                hint.innerHTML = '<strong>คงเหลือ Balance:</strong> ' + formatBalanceValue(balance) + (unit ? ' ' + unit : '') + '<br><strong>วัสดุ:</strong> ' + escapeHtml(matName) + _gateBreakdownHtml(matCode, unit);
            }
        }

        function switchRequisitionTab(tabId, btnElement) {
            var container = document.querySelector('#requisition-page .tabs-container');
            if (!container) return;
            container.querySelectorAll('.tab-content').forEach(function (item) {
                item.classList.remove('active');
            });
            container.querySelectorAll('.tab-btn').forEach(function (btn) {
                btn.classList.remove('active');
            });

            var targetContent = document.getElementById(tabId);
            if (targetContent) targetContent.classList.add('active');
            if (btnElement) btnElement.classList.add('active');
            persistCurrentRequisitionTab(tabId);
        }

        function onReqReceiverChange(sel) {
            var dcInput = document.getElementById('reqReceiverDCInput');
            if (!dcInput) return;
            dcInput.style.display = sel.value === 'DC' ? 'block' : 'none';
            if (sel.value !== 'DC') dcInput.value = '';
        }

        function onOddsReceiverChange(sel) {
            var dcInput = document.getElementById('oddsReceiverDCInput');
            if (!dcInput) return;
            dcInput.style.display = sel.value === 'DC' ? 'block' : 'none';
            if (sel.value !== 'DC') dcInput.value = '';
        }

        function onBorrowReceiverChange(sel) {
            var dcInput = document.getElementById('borrowReceiverDCInput');
            if (!dcInput) return;
            dcInput.style.display = sel.value === 'DC' ? 'block' : 'none';
            if (sel.value !== 'DC') dcInput.value = '';
        }

         
         
         
         
         
         
        function _submitterRoleNum() {
            var lvl = user && user.roleLevel ? user.roleLevel : '';
            var m = lvl.toString().match(/\d+/);
            return m ? parseInt(m[0], 10) : 99;
        }
        function _draftForForm(formKey) {
            return formKey === 'borrow'  ? borrowDraftItems
                 : formKey === 'inbound' ? inboundDraftItems
                 : formKey === 'odds'    ? oddsDraftItems
                 : draftItems;
        }
         
         
         
         
        function _selectedMatCat(formKey) {
            var selId = formKey === 'borrow'  ? 'borrowMaterialSelect'
                      : formKey === 'inbound' ? 'inboundMaterialSelect'
                      : 'reqMaterialSelect';
            var sel = document.getElementById(selId);
            if (!sel || sel.selectedIndex < 0) return '';
            var opt = sel.options[sel.selectedIndex];
            if (!opt || !sel.value) return '';
            return ((opt.getAttribute('data-catid') || '') + '').toUpperCase();
        }

         
         
         
         
         
         
         
        function _requiredApproverRole(formKey) {
            // [PHP port 2026-09-25 · มติ 52] เบิกวัสดุหลัก (RD) ต้องผู้อนุมัติ R6 ขึ้นไปทุกใบ
            // (เดิม C01→6 · อื่น→4 ทำให้ SE1/SE2 = R4–R5 เบิก C02 ผ่านทันทีโดยไม่ขออนุมัติ) · ยืม (BD) คงเกณฑ์เดิม
            if (formKey === 'req') return 6;
            var arr = _draftForForm(formKey);
            for (var i = 0; i < (arr || []).length; i++) {
                if ((arr[i].catId || '').toString().toUpperCase() === 'C01') return 6;
            }
            if (_selectedMatCat(formKey) === 'C01') return 6;
            return 4;
        }

         
         
         
         
         
        function _shouldShowPickerFor(formKey) {
             
            if (formKey === 'inbound') return false;
            var arr = _draftForForm(formKey);
            var hasSelection = !!_selectedMatCat(formKey);
            if ((!arr || arr.length === 0) && !hasSelection) return false;
            // [2026-10-06] เบิกวัสดุหลัก: ผู้ส่ง R6 ขึ้นไป / PM อนุมัติอัตโนมัติ — ต่ำกว่า R6 ต้องเลือกผู้อนุมัติ R6 ขึ้นไป (server ตัดสินซ้ำ)
            if (formKey === 'req') return !_isSitePm() && _submitterRoleNum() < 6;
            return _submitterRoleNum() < _requiredApproverRole(formKey);
        }
        function _isSitePm() { return !!(user && String(user.roleId || '').toUpperCase() === 'PM'); }
         
        function isApproverPickerRequired() {
            return _submitterRoleNum() <= 4;
        }

         
         
        var _allApprovers = [];

        function loadApproversIntoSelect(opts) {
            if (!user || !user.username) return;
             
             
             
            connextCache.swr('approversList', [user.username],
                function (done, fail) {
                    google.script.run.withSuccessHandler(done).withFailureHandler(fail).getApproversList(user.username);
                },
                function (list) {
                    _allApprovers = list || [];
                    refreshApproverPickers();
                },
                function (err) { console.warn('loadApprovers failed:', err); },
                opts
            );
        }

         
         
         
         
        function refreshApproverPickers() {
            var escalatedCleared = false;    
            ['req', 'borrow', 'inbound'].forEach(function (formKey) {
                var groupId = (formKey === 'req' ? 'reqApproverGroup'
                              : formKey === 'borrow' ? 'borrowApproverGroup'
                              : 'inboundApproverGroup');
                var selectId = (formKey === 'req' ? 'reqApproverSelect'
                               : formKey === 'borrow' ? 'borrowApproverSelect'
                               : 'inboundApproverSelect');
                var group = document.getElementById(groupId);
                var sel   = document.getElementById(selectId);
                if (!group || !sel) return;

                var show = _shouldShowPickerFor(formKey);
                group.style.display = show ? '' : 'none';
                if (!show) {
                    if ($(sel).data('select2')) { try { $(sel).val(null).trigger('change.select2'); } catch (e) {} }
                    sel.value = '';
                    return;
                }

                var minRole = _requiredApproverRole(formKey);
                var prior   = '';
                try { prior = ($(sel).data('select2') ? $(sel).val() : sel.value) || ''; } catch (e) {}

                var label = formKey === 'req' ? '-- เลือกผู้อนุมัติ (R6 ขึ้นไป) --'   // [มติ 52] เบิกวัสดุหลักทุกใบ
                          : minRole >= 6 ? '-- เลือกผู้อนุมัติ (R6 ขึ้นไป สำหรับ C01) --'
                                         : '-- เลือกผู้อนุมัติ (R4 ขึ้นไป สำหรับ C02) --';
                var options = '<option value="" disabled selected>' + label + '</option>';
                _allApprovers.forEach(function (u) {
                    var lvl = parseInt((u.roleLevel || '').replace(/\D/g, ''), 10) || 0;
                    if (lvl < minRole) return;
                    var lbl = (u.fullName || u.username) + ' (' + (u.roleName || u.roleLevel) + ')';
                    options += '<option value="' + escapeHtml(u.username) + '">' + escapeHtml(lbl) + '</option>';
                });

                if ($(sel).data('select2')) { try { $(sel).select2('destroy'); } catch (e) {} }
                sel.innerHTML = options;
                 
                 
                var priorStillValid = prior && sel.querySelector('option[value="' + prior.replace(/"/g, '\\"') + '"]');
                if (prior && !priorStillValid && minRole >= 6) escalatedCleared = true;
                try {
                    $(sel).select2({
                        placeholder: label,
                        width: '100%',
                        language: { noResults: function () { return 'ไม่พบผู้อนุมัติ'; } }
                    });
                    if (priorStillValid) {
                        $(sel).val(prior).trigger('change.select2');
                    }
                } catch (e) {   }

                 
                var hint = group.querySelector('.approver-hint');
                if (!hint) {
                    hint = document.createElement('div');
                    hint.className = 'approver-hint';
                    group.appendChild(hint);
                }
                hint.innerHTML = '<i class="fa-solid fa-circle-info"></i> ' +
                    (formKey === 'req' ? 'เบิกวัสดุหลักต้องผ่านผู้อนุมัติ <b>R6 ขึ้นไป</b> ทุกใบ'   // [มติ 52]
                     : minRole >= 6 ? 'ใบนี้มีวัสดุ <b>C01</b> — ต้องผู้อนุมัติ <b>R6 ขึ้นไป</b>'
                                    : 'เลือกผู้อนุมัติ <b>R4 ขึ้นไป</b>') +
                    ' · เลือกได้ <b>1 คนต่อใบ</b> · ต้องการหลายผู้อนุมัติให้<b>แยกใบเบิก</b>';
            });

             
            if (escalatedCleared && typeof showToast === 'function') {
                showToast('เพิ่มวัสดุ C01 แล้ว — กรุณาเลือกผู้อนุมัติ R6 ขึ้นไปใหม่', 'warning');
            }
        }

         
        function getSelectedApprover(formKey) {
            var id = formKey === 'borrow'  ? 'borrowApproverSelect'
                   : formKey === 'inbound' ? 'inboundApproverSelect'
                   : 'reqApproverSelect';
            var sel = document.getElementById(id);
            if (!sel) return '';
            try { if ($(sel).data('select2')) return $(sel).val() || ''; } catch (e) {}
            return sel.value || '';
        }

        function loadSubcontracts(opts) {
            function render(subcontracts) {
                 
                subcontractNameMap = {};
                (subcontracts || []).forEach(function (sub) {
                    subcontractNameMap[sub.SubID] = sub.SubName || sub.SubID;
                });

                var isSub = user && user.role === 'Subcontractor';

                 
                 
                 
                var sortedSubs = (subcontracts || []).slice().sort(function (a, b) {
                    var aId = parseInt(((a.SubID || '') + '').replace(/\D/g, ''), 10);
                    var bId = parseInt(((b.SubID || '') + '').replace(/\D/g, ''), 10);
                    if (isNaN(aId) && isNaN(bId)) return ((a.SubID || '') + '').localeCompare((b.SubID || '') + '');
                    if (isNaN(aId)) return 1;
                    if (isNaN(bId)) return -1;
                    return aId - bId;
                });

                 
                var _prevRecvSel = {};
                document.querySelectorAll('.receiver-select').forEach(function (select) {
                    if (!select.id) return;
                    try { _prevRecvSel[select.id] = ($(select).data('select2') ? $(select).val() : select.value) || ''; }
                    catch (e) { _prevRecvSel[select.id] = select.value || ''; }
                });

                document.querySelectorAll('.receiver-select').forEach(function (select) {
                    if ($(select).data('select2')) $(select).select2('destroy');
                    select.innerHTML = isSub
                        ? ''
                        : '<option value="" disabled selected>-- เลือกผู้รับ --</option><option value="DC">DC (กรอกชื่อ)</option>';
                    sortedSubs.forEach(function (sub) {
                        if (isSub && sub.SubID !== user.username) return;
                        var option = document.createElement('option');
                         
                         
                         
                        option.value = sub.SubID;
                        option.textContent = (sub.SubName || sub.SubID || '').toString().trim() || sub.SubID;
                        select.appendChild(option);
                    });
                });

                initReceiverSelect2();
                if (isSub) {
                    $('.receiver-select').val(user.username).trigger('change').prop('disabled', true);
                } else {
                     
                     
                    document.querySelectorAll('.receiver-select').forEach(function (select) {
                        var prev = select.id ? _prevRecvSel[select.id] : '';
                        if (prev && select.querySelector('option[value="' + prev.replace(/"/g, '\\"') + '"]')) {
                            try { $(select).val(prev).trigger('change'); } catch (e) { select.value = prev; }
                        }
                    });
                }

                var approveSubSelect = document.getElementById('approveSubSelect');
                if (approveSubSelect) {
                    approveSubSelect.innerHTML = '<option value="" selected>-- กรองผู้รับเหมา --</option>';
                    sortedSubs.forEach(function (sub) {
                        var option = document.createElement('option');
                         
                         
                        var nm = (sub.SubName || sub.SubID || '').toString().trim() || sub.SubID;
                        option.value = nm;
                        option.textContent = nm;
                        approveSubSelect.appendChild(option);
                    });
                }
            }

             
            connextCache.swr('subcontracts', [user ? user.username : ''],
                function (done, fail) {
                    google.script.run.withSuccessHandler(done).withFailureHandler(fail).getSubcontractsList(user ? user.username : '');
                },
                function (data) { render(data); },
                function (err) { console.error('Failed to load subcontracts:', err); },
                opts
            );
        }

        function loadBalance(callback, opts) {
            var site = getEffectiveSiteCode();
            var calledBack = false;
             
            if (typeof loadGateBalance === 'function') loadGateBalance(null, opts);
            connextCache.swr('balance', [site],
                function (done, fail) {
                    google.script.run.withSuccessHandler(done).withFailureHandler(fail).getBalanceList(site);
                },
                function (balanceData  ) {
                    globalBalance = {};
                    (balanceData || []).forEach(function (item) { globalBalance[item.MatCode] = item; });
                     
                    if (!calledBack && typeof callback === 'function') {
                        calledBack = true;
                        callback();
                    }
                },
                function (err) {
                    console.error('Failed to load balance:', err);
                    if (!calledBack && typeof callback === 'function') {
                        calledBack = true;
                        callback();
                    }
                },
                opts
            );
        }

         
         
        function getAvailableBalance(matCode) {
            var record = globalBalance[matCode];
            if (!record) return 0;
            var onHand;
            if (record.OnHand !== undefined && record.OnHand !== '') {
                onHand = parseFloat(record.OnHand) || 0;
            } else if (record.Balance !== undefined && record.Balance !== '') {
                onHand = parseFloat(record.Balance) || 0;
            } else {
                onHand = (parseFloat(record.In) || 0) - (parseFloat(record.Out) || 0);
            }
            var pendingVal = parseFloat(record.Pending) || 0;
            return onHand - pendingVal;
        }

         
         
        function getOnHand(matCode) {
            var r = globalBalance[matCode];
            if (!r) return 0;
            if (r.OnHand !== undefined && r.OnHand !== '') return parseFloat(r.OnHand) || 0;
            if (r.Balance !== undefined && r.Balance !== '') return parseFloat(r.Balance) || 0;
            return parseFloat(r.In || 0) - parseFloat(r.Out || 0);
        }
        function getPendingQty(matCode) {
            var r = globalBalance[matCode];
            if (!r) return 0;
            return parseFloat(r.Pending) || 0;
        }
         
         
         
         
        var globalGateBalance = {};

        function loadGateBalance(callback, opts) {
            var site = getEffectiveSiteCode();
            var calledBack = false;
            connextCache.swr('gatebalance', [site],
                function (done, fail) {
                    google.script.run.withSuccessHandler(done).withFailureHandler(fail).getGateBalanceList(site);
                },
                function (rows) {
                    globalGateBalance = {};
                    (rows || []).forEach(function (r) {
                        var mc = (r.MatCode || '').toString();
                        if (!mc) return;
                        if (!globalGateBalance[mc]) globalGateBalance[mc] = [];
                        globalGateBalance[mc].push(r);
                    });
                     
                    ['req', 'odds', 'borrow'].forEach(function (k) {
                        var sel = document.getElementById(k + 'GateSelect');
                        if (sel && sel.options.length > 1) refreshGateSelectForForm(k, sel.value);
                    });
                    if (!calledBack && typeof callback === 'function') { calledBack = true; callback(); }
                },
                function (err) {
                    console.error('Failed to load gate balance:', err);
                    if (!calledBack && typeof callback === 'function') { calledBack = true; callback(); }
                },
                opts
            );
        }

        function getGateRows(matCode) { return globalGateBalance[matCode] || []; }

        // [PHP port 2026-09-25 per-gate · มติ 51] "แยกตามประตู: G01 10 · G03 50" ใต้ยอดรวมในฟอร์มเบิก
        // ตัวเลข = พร้อมเบิก (ในคลัง − จองไว้) ของแต่ละประตู เหมือนที่ดรอปดาวน์ประตูใช้ตัดสิน
        function _gateBreakdownHtml(matCode, unit) {
            var rows = getGateRows(matCode).slice().sort(function (a, b) {
                return (a.GateID || '').localeCompare(b.GateID || '');
            });
            if (!rows.length) return '';
            var u = unit ? ' ' + unit : '';
            var parts = rows.map(function (r) {
                var on = parseFloat(r.OnHand) || 0, pen = parseFloat(r.Pending) || 0;
                var txt = '<span class="cw-gate-tag">' + escapeHtml(r.GateID || '') + '</span> ' + formatBalanceValue(on - pen) + u;
                if (pen > 0) txt += ' <span style="color:var(--text-muted);">(จองไว้ ' + formatBalanceValue(pen) + ')</span>';
                return txt;
            });
            return '<br><strong>แยกตามประตู:</strong> ' + parts.join(' · ');
        }

        function getGateRow(matCode, gateId) {
            var rows = getGateRows(matCode);
            for (var i = 0; i < rows.length; i++) {
                if ((rows[i].GateID || '') === gateId) return rows[i];
            }
            return null;
        }
        function getGateOnHand(matCode, gateId) {
            var r = getGateRow(matCode, gateId);
            return r ? (parseFloat(r.OnHand) || 0) : 0;
        }
        function getGatePending(matCode, gateId) {
            var r = getGateRow(matCode, gateId);
            return r ? (parseFloat(r.Pending) || 0) : 0;
        }
         
        function getGateAvailable(matCode, gateId) {
            return getGateOnHand(matCode, gateId) - getGatePending(matCode, gateId);
        }

         
        function getDraftedQtyAtGate(matCode, formKey, gateId) {
            var arr = formKey === 'odds'   ? oddsDraftItems   :
                      formKey === 'borrow' ? borrowDraftItems :
                                             draftItems;
            if (!arr || !arr.length) return 0;
            var sum = 0;
            for (var i = 0; i < arr.length; i++) {
                if (arr[i] && arr[i].matCode === matCode && (arr[i].gateId || '') === gateId) {
                    sum += parseFloat(arr[i].qty) || 0;
                }
            }
            return sum;
        }

        function getSelectedGate(formKey) {
            var sel = document.getElementById(formKey + 'GateSelect');
            return sel ? (sel.value || '') : '';
        }

         
        function availableForForm(matCode, formKey) {
            var gid = getSelectedGate(formKey);
            if (gid) return getGateAvailable(matCode, gid);
            return getAvailableBalance(matCode);
        }

         
        function refreshGateSelectForForm(formKey, keepGate) {
            var matSel = document.getElementById(
                formKey === 'odds' ? 'oddsMaterialSelect' :
                formKey === 'borrow' ? 'borrowMaterialSelect' : 'reqMaterialSelect');
            var sel  = document.getElementById(formKey + 'GateSelect');
            var hint = document.getElementById(formKey + 'GateHint');
            if (!sel) return;

            var matCode = matSel ? (matSel.value || '') : '';
            var unit = '';
            if (matSel && matSel.selectedIndex >= 0) {
                var o = matSel.options[matSel.selectedIndex];
                unit = o ? (o.getAttribute('data-unit') || '') : '';
            }

            if (!matCode) {
                sel.innerHTML = '<option value="" disabled selected>-- เลือกวัสดุก่อน --</option>';
                if (hint) hint.innerHTML = 'วัสดุชนิดเดียวกันอยู่ได้หลายประตู — เลือกประตูที่จะไปรับ';
                return;
            }

            var rows = getGateRows(matCode).slice().sort(function (a, b) {
                return (a.GateID || '').localeCompare(b.GateID || '');
            });
            var withStock = rows.filter(function (r) {
                return (parseFloat(r.OnHand) || 0) - (parseFloat(r.Pending) || 0) > 0;
            });

            if (!rows.length) {
                sel.innerHTML = '<option value="" disabled selected>-- ไม่มีของในประตูใดเลย --</option>';
                if (hint) hint.innerHTML = '<strong style="color:#dc2626;"><i class="fa-solid fa-circle-xmark"></i> วัสดุนี้ยังไม่มีของในประตูใดของไซต์นี้</strong>';
                return;
            }

            var html = '<option value="" disabled' + (withStock.length === 1 ? '' : ' selected') + '>-- เลือกประตู --</option>';
            rows.forEach(function (r) {
                var avail = (parseFloat(r.OnHand) || 0) - (parseFloat(r.Pending) || 0);
                var label = (r.GateID || '') + (r.GateName ? ' · ' + r.GateName : '')
                          + ' — คงเหลือ ' + formatBalanceValue(avail) + (unit ? ' ' + unit : '');
                html += '<option value="' + escapeHtml(r.GateID || '') + '"'
                     + (avail > 0 ? '' : ' disabled')
                     + '>' + escapeHtml(label) + (avail > 0 ? '' : ' (หมด)') + '</option>';
            });
            sel.innerHTML = html;

             
            var want = '';
            if (keepGate && getGateAvailable(matCode, keepGate) > 0) want = keepGate;
            else if (withStock.length === 1) want = withStock[0].GateID || '';
            if (want) sel.value = want;

            renderGateHint(formKey, matCode, unit);
        }

        function renderGateHint(formKey, matCode, unit) {
            var hint = document.getElementById(formKey + 'GateHint');
            if (!hint) return;
            var gid = getSelectedGate(formKey);
            var rows = getGateRows(matCode);
            if (!gid) {
                hint.innerHTML = 'วัสดุนี้มีของอยู่ ' + rows.length + ' ประตู — เลือกประตูที่จะไปรับ';
                return;
            }
            var row     = getGateRow(matCode, gid);
            var onHand  = getGateOnHand(matCode, gid);
            var pending = getGatePending(matCode, gid);
            var avail   = onHand - pending;
            hint.innerHTML = '<strong>คงเหลือที่ประตูนี้:</strong> ' + formatBalanceValue(avail)
                + (unit ? ' ' + unit : '')
                + (pending > 0 ? ' <span style="color:var(--text-muted);">(ในคลัง ' + formatBalanceValue(onHand)
                                 + ' · จองไว้ ' + formatBalanceValue(pending) + ')</span>' : '')
                + (row && row.GateName ? '<br><strong>ประตู:</strong> ' + escapeHtml(gid + ' · ' + row.GateName) : '');
        }

         
        function onGateSelectChange(formKey) {
            var matSel = document.getElementById(
                formKey === 'odds' ? 'oddsMaterialSelect' :
                formKey === 'borrow' ? 'borrowMaterialSelect' : 'reqMaterialSelect');
            var matCode = matSel ? (matSel.value || '') : '';
            var unit = '';
            if (matSel && matSel.selectedIndex >= 0) {
                var o = matSel.options[matSel.selectedIndex];
                unit = o ? (o.getAttribute('data-unit') || '') : '';
            }
            renderGateHint(formKey, matCode, unit);
            _resetQtyTrigger(formKey === 'odds' ? 'oddsQtyInput'
                           : formKey === 'borrow' ? 'borrowQtyInput' : 'reqQtyInput');
        }

         
        function getDraftedQty(matCode, formKey) {
            var arr = formKey === 'odds'   ? oddsDraftItems   :
                      formKey === 'borrow' ? borrowDraftItems :
                                             draftItems;
            if (!arr || !arr.length) return 0;
            var sum = 0;
            for (var i = 0; i < arr.length; i++) {
                if (arr[i] && arr[i].matCode === matCode) {
                    sum += parseFloat(arr[i].qty) || 0;
                }
            }
            return sum;
        }

         
         
         
         
         
         
        var _qtyCtx = null;
        function openQtyModal(inputId, matSelectId, formKey) {
            var matSelect = document.getElementById(matSelectId);
            if (!matSelect || !matSelect.value) {
                showInfoPopup('ยังไม่ได้เลือกวัสดุ', 'กรุณาเลือกวัสดุก่อนระบุจำนวน', 'warning');
                return;
            }
            var matCode = matSelect.value;
            var opt = matSelect.options[matSelect.selectedIndex];
            var matName = opt ? opt.textContent : matCode;
            var unit = opt ? (opt.getAttribute('data-unit') || '') : '';

             
            if ((formKey === 'req' || formKey === 'odds') && availableForForm(matCode, formKey) <= 0) {
                var _avNow = availableForForm(matCode, formKey);   // [2026-10-02 · GP-42] คงเหลือจริง (ติดลบได้) แทน "0"
                showInfoPopup('วัสดุหมดสต๊อก', escapeHtml(matName) + ' หมดสต๊อก (คงเหลือ ' + formatBalanceValue(_avNow) + (unit ? ' ' + unit : '') + ') จึงไม่สามารถเบิกได้ในขณะนี้'
                    + (_avNow < 0 ? '\n⚠️ ยอดไม่พอ: ใบที่อนุมัติแล้วจองเกินของที่มี หรือยอดติดลบ — แจ้งสายสโตร์ตรวจนับ' : ''), 'warning');
                return;
            }

             
             
            if (formKey === 'inbound') {
                var inOnHand = getOnHand(matCode);
                _qtyCtx = {
                    inputId: inputId, matSelectId: matSelectId, formKey: 'inbound', matCode: matCode,
                    unit: unit, onHand: inOnHand, pending: 0, drafted: 0, available: Infinity, inbound: true
                };
                document.getElementById('qtyModalMatName').textContent = matName || 'ระบุจำนวนรับเข้า';
                document.getElementById('qtyModalMatCode').textContent = matCode + (unit ? ' · หน่วย: ' + unit : '');
                var trigIn = document.getElementById(inputId);
                var priorIn = parseInt((trigIn && trigIn.dataset.qty) || (trigIn && trigIn.value) || '', 10);
                document.getElementById('qtyStepValue').value = (!isNaN(priorIn) && priorIn > 0) ? priorIn : 1;
                _qtyRenderModal();
                hapticTap();
                document.getElementById('qtyModalBackdrop').classList.add('open');
                _qtyRefreshBalance(matCode);
                return;
            }

             
            var _gid      = getSelectedGate(formKey);
            var onHand    = _gid ? getGateOnHand(matCode, _gid)  : getOnHand(matCode);
            var pending   = _gid ? getGatePending(matCode, _gid) : getPendingQty(matCode);
            var drafted   = _gid ? getDraftedQtyAtGate(matCode, formKey, _gid)
                                 : getDraftedQty(matCode, formKey);
             
             
             
             
             
             
             
            var available = Math.max(0, onHand - pending - drafted);

            _qtyCtx = {
                inputId: inputId,
                matSelectId: matSelectId,
                formKey: formKey,
                matCode: matCode,
                unit: unit,
                onHand: onHand,
                pending: pending,
                drafted: drafted,
                available: available,
                gateId: _gid
            };

             
            document.getElementById('qtyModalMatName').textContent = matName || 'ระบุจำนวน';
            document.getElementById('qtyModalMatCode').textContent = matCode + (unit ? ' · หน่วย: ' + unit : '');

             
            var prior = parseInt((document.getElementById(inputId) || {}).value, 10);
            var initialVal = !isNaN(prior) && prior > 0 ? prior : (available > 0 ? 1 : 0);
            document.getElementById('qtyStepValue').value = initialVal;

             
            _qtyRenderModal();
            hapticTap();
            document.getElementById('qtyModalBackdrop').classList.add('open');

             
             
            _qtyRefreshBalance(matCode);
        }

         
         
         
         
         
         
         
         
        function _qtySyncChargeRow() {
            var row = document.getElementById('qtyChargeRow');
            var cb  = document.getElementById('qtyChargeToggle');
            if (!row || !cb || !_qtyCtx) return;
            var fk = _qtyCtx.formKey;
            if (fk !== 'req' && fk !== 'odds') { row.style.display = 'none'; return; }
            row.style.display = '';
            if (_qtyCtx._chargeInit) return;           
            _qtyCtx._chargeInit = true;
            var on = false;
            if (_qtyCtx.editIdx != null) {
                var arr = _draftForForm(fk);
                on = !!(arr && arr[_qtyCtx.editIdx] && arr[_qtyCtx.editIdx].charge);
            } else {
                var formCb = document.getElementById(fk === 'odds' ? 'oddsChargeInput' : 'reqChargeInput');
                on = !!(formCb && formCb.checked);
            }
            cb.checked = on;
        }

        function _qtyRenderModal() {
            if (!_qtyCtx) return;
            _qtySyncChargeRow();
            var unit = _qtyCtx.unit || '';
            var u = unit ? ' ' + unit : '';
            var stepInput = document.getElementById('qtyStepValue');
            var unitSpan = document.getElementById('qtyStepUnit');
            if (unitSpan) unitSpan.textContent = unit ? '(' + unit + ')' : '';
            var quickRow   = document.getElementById('qtyQuickRow');
            var rowPending = document.getElementById('qtyRowPending');
            var rowDrafted = document.getElementById('qtyRowDrafted');
            var lblOnHand  = document.getElementById('qtyLabelOnHand');
            var lblPending = document.getElementById('qtyLabelPending');
            var lblAvail   = document.getElementById('qtyLabelAvailable');

             
            if (_qtyCtx.inbound) {
                var v = parseInt((stepInput || {}).value, 10) || 0;
                if (lblOnHand)  lblOnHand.textContent  = 'คงเหลือปัจจุบัน (Stock)';
                if (lblPending) lblPending.textContent = '+ กำลังรับเข้า';
                if (lblAvail)   lblAvail.textContent   = '= รวมหลังรับเข้า';
                if (rowDrafted) rowDrafted.style.display = 'none';    
                if (rowPending) rowPending.style.display = '';
                document.getElementById('qtyStatOnHand').textContent    = formatBalanceValue(_qtyCtx.onHand) + u;
                document.getElementById('qtyStatPending').textContent   = '+' + formatBalanceValue(v) + u;
                document.getElementById('qtyStatAvailable').textContent = formatBalanceValue((_qtyCtx.onHand || 0) + v) + u;
                if (stepInput) stepInput.max = '';    
                if (quickRow) {
                    quickRow.innerHTML = '';
                    [1, 5, 10].forEach(function (n) {
                        var b = document.createElement('button');
                        b.type = 'button'; b.className = 'qty-quick-btn'; b.textContent = '+' + n;
                        b.onclick = (function (delta) { return function () { hapticTap(); var cur = parseInt(document.getElementById('qtyStepValue').value, 10) || 0; setQtyStep(cur + delta); }; })(n);
                        quickRow.appendChild(b);
                    });
                }
                onQtyStepChange();
                return;
            }

             
            if (lblOnHand)  lblOnHand.textContent  = _qtyCtx.gateId ? 'คงเหลือที่ประตู ' + _qtyCtx.gateId : 'คงเหลือ (Stock)';   // [มติ 51]
            if (lblPending) lblPending.textContent = 'จองแล้ว — อนุมัติรอรับ (Pending)';   // [2026-10-06] ใบรออนุมัติไม่จอง
            if (lblAvail)   lblAvail.textContent   = 'คงเหลือที่จองได้';
            if (rowDrafted) rowDrafted.style.display = '';
            if (rowPending) rowPending.style.display = '';
            var available = _qtyCtx.available;
            document.getElementById('qtyStatOnHand').textContent    = formatBalanceValue(_qtyCtx.onHand)  + u;
            document.getElementById('qtyStatPending').textContent   = formatBalanceValue(_qtyCtx.pending) + u;
            document.getElementById('qtyStatDrafted').textContent   = formatBalanceValue(_qtyCtx.drafted) + u;
            document.getElementById('qtyStatAvailable').textContent = formatBalanceValue(available)        + u;
            if (stepInput) stepInput.max = available > 0 ? available : '';

             
            quickRow.innerHTML = '';
            if (available > 0) {
                var increments = [1, 5, 10].filter(function (n) { return n < available; });
                increments.forEach(function (n) {
                    var b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'qty-quick-btn';
                    b.textContent = '+' + n;
                    b.onclick = (function (delta) {
                        return function () {
                            hapticTap();
                            var cur = parseInt(document.getElementById('qtyStepValue').value, 10) || 0;
                            setQtyStep(Math.min(cur + delta, _qtyCtx.available));
                        };
                    })(n);
                    quickRow.appendChild(b);
                });
                var maxBtn = document.createElement('button');
                maxBtn.type = 'button';
                maxBtn.className = 'qty-quick-btn';
                maxBtn.textContent = 'สูงสุด ' + available;
                maxBtn.onclick = function () { hapticTap(); setQtyStep(_qtyCtx.available); };
                quickRow.appendChild(maxBtn);
            }

            onQtyStepChange();  
        }

         
         
         
        function _recomputeQtyAvailable() {
            if (!_qtyCtx) return 0;
            if (_qtyCtx.inbound) return Infinity;    
            var base = Math.max(0, (_qtyCtx.onHand || 0) - (_qtyCtx.pending || 0) - (_qtyCtx.drafted || 0));
            if (_qtyCtx.editIdx != null) return base + (_qtyCtx.editQty || 0);
            return base;
        }

        var _qtyRefreshHideT = null;
         
        function _qtySetRefreshing(on, doneMsg) {
            var hint = document.getElementById('qtyRefreshHint');
            if (!hint) return;
            if (on) {
                hint.innerHTML = '<i class="fa-solid fa-rotate fa-spin" style="margin-right:5px;"></i>กำลังอัปเดตยอดล่าสุด…';
                hint.style.display = 'block';
            } else if (doneMsg) {
                hint.innerHTML = doneMsg;
                hint.style.display = 'block';
            } else {
                hint.style.display = 'none';
            }
        }

         
         
         
         
        function _qtyRefreshBalance(matCode) {
            if (!matCode || typeof google === 'undefined' || !google.script || !google.script.run) return;
            var _rgid = (_qtyCtx && _qtyCtx.gateId) ? _qtyCtx.gateId : '';   // [มติ 51] ยอดของประตูที่เลือก (เดิมข้ามการรีเฟรช)    
            _qtySetRefreshing(true);
            var site = (typeof getEffectiveSiteCode === 'function') ? getEffectiveSiteCode() : '';
            google.script.run
                .withSuccessHandler(function (res) {
                    if (!_qtyCtx || _qtyCtx.matCode !== matCode) return;  
                    if (!res || res.error) { _qtySetRefreshing(false); return; }
                    var onHand  = parseFloat(res.onHand)  || 0;
                    var pending = parseFloat(res.pending) || 0;
                     
                    if (_rgid) {
                        // [มติ 51] ยอดของประตู → อัปเดตแถวรายประตู (ยอดรวมไซต์ไม่แตะ)
                        var _grow = getGateRow(matCode, _rgid);
                        if (_grow) { _grow.OnHand = onHand; _grow.Pending = pending; }
                    } else {
                        if (!globalBalance[matCode]) globalBalance[matCode] = {};
                        globalBalance[matCode].OnHand  = onHand;
                        globalBalance[matCode].Pending = pending;
                    }

                    var changed = (onHand !== _qtyCtx.onHand) || (pending !== _qtyCtx.pending);
                    _qtyCtx.onHand    = onHand;
                    _qtyCtx.pending   = pending;
                    _qtyCtx.available = _recomputeQtyAvailable();
                    _qtyRenderModal();

                    if (changed) {
                        _qtySetRefreshing(false, '<i class="fa-solid fa-circle-check" style="color:#16a34a; margin-right:5px;"></i>อัปเดตยอดล่าสุดแล้ว');
                        clearTimeout(_qtyRefreshHideT);
                        _qtyRefreshHideT = setTimeout(function () { _qtySetRefreshing(false); }, 2500);
                    } else {
                        _qtySetRefreshing(false);
                    }
                })
                .withFailureHandler(function () {
                    if (_qtyCtx && _qtyCtx.matCode === matCode) _qtySetRefreshing(false);
                })
                .getMaterialBalance(matCode, site, _rgid);
        }

        function closeQtyModal() {
            document.getElementById('qtyModalBackdrop').classList.remove('open');
            _qtyCtx = null;
        }

         
         
         
         
        function openEditQtyModal(formKey, index) {
            var arr = _draftForForm(formKey);
            var item = arr && arr[index];
            if (!item) return;

            var matCode = item.matCode;
            var matName = item.matName || matCode;
            var unit    = item.unit || '';

             
             
            if (formKey === 'inbound') {
                var inOnHand = getOnHand(matCode);
                var inEditQty = parseInt(item.qty, 10) || 0;
                _qtyCtx = {
                    inputId: null, matSelectId: null, formKey: 'inbound', matCode: matCode,
                    unit: unit, onHand: inOnHand, pending: 0, drafted: 0,
                    available: Infinity, editIdx: index, editQty: inEditQty, inbound: true
                };
                document.getElementById('qtyModalMatName').textContent = matName || 'แก้ไขจำนวนรับเข้า';
                document.getElementById('qtyModalMatCode').textContent = matCode + (unit ? ' · หน่วย: ' + unit : '');
                document.getElementById('qtyStepValue').value = inEditQty || 1;
                _qtyRenderModal();
                hapticTap();
                document.getElementById('qtyModalBackdrop').classList.add('open');
                _qtyRefreshBalance(matCode);
                return;
            }

            // [PHP port 2026-09-25 per-gate · มติ 51] แก้จำนวน = ยอดของประตูที่รายการนี้เลือกไว้ (เดิมใช้ยอดรวมไซต์)
            var _egid     = (item.gateId || '').toString();
            var onHand    = _egid ? getGateOnHand(matCode, _egid)  : getOnHand(matCode);
            var pending   = _egid ? getGatePending(matCode, _egid) : getPendingQty(matCode);
             
             
            var draftedOther = (_egid ? getDraftedQtyAtGate(matCode, formKey, _egid) : getDraftedQty(matCode, formKey)) - (parseFloat(item.qty) || 0);
            if (draftedOther < 0) draftedOther = 0;
            var available = Math.max(0, onHand - pending - draftedOther);

            _qtyCtx = {
                inputId: null,            
                matSelectId: null,
                formKey: formKey,
                matCode: matCode,
                unit: unit,
                onHand: onHand,
                pending: pending,
                drafted: draftedOther,
                available: available,
                editIdx: index             
            };

             
            document.getElementById('qtyModalMatName').textContent = matName || 'แก้ไขจำนวน';
            document.getElementById('qtyModalMatCode').textContent = matCode + (unit ? ' · หน่วย: ' + unit : '');

             
             
            var realMax = available + (parseInt(item.qty, 10) || 0);
            _qtyCtx.editQty   = parseInt(item.qty, 10) || 0;
            _qtyCtx.available = realMax;
            _qtyCtx.gateId    = _egid;   // [มติ 51] ให้ป้ายในหน้าต่างและการรีเฟรชยอดรู้ประตู

            document.getElementById('qtyStepValue').value = parseInt(item.qty, 10) || 1;

             
            _qtyRenderModal();
            hapticTap();
            document.getElementById('qtyModalBackdrop').classList.add('open');

             
            _qtyRefreshBalance(matCode);
        }

        function qtyStep(delta) {
            if (!_qtyCtx) return;
            hapticTap();
            var el = document.getElementById('qtyStepValue');
            var v  = parseInt(el.value, 10) || 0;
            setQtyStep(v + delta);
        }
        function setQtyStep(val) {
            var el = document.getElementById('qtyStepValue');
            el.value = Math.max(1, parseInt(val, 10) || 1);
            onQtyStepChange();
        }
        function onQtyStepChange() {
            if (!_qtyCtx) return;
            var el = document.getElementById('qtyStepValue');
            var warn = document.getElementById('qtyWarn');
            var btn = document.getElementById('qtyConfirmBtn');
            var v  = parseInt(el.value, 10) || 0;

             
            if (v < 1) {
                el.value = 1;
                v = 1;
            }

             
            if (_qtyCtx.inbound) {
                var ui = _qtyCtx.unit ? ' ' + _qtyCtx.unit : '';
                var sp = document.getElementById('qtyStatPending');
                var sa = document.getElementById('qtyStatAvailable');
                if (sp) sp.textContent = '+' + formatBalanceValue(v) + ui;
                if (sa) sa.textContent = formatBalanceValue((_qtyCtx.onHand || 0) + v) + ui;
                el.classList.remove('invalid');
                warn.classList.remove('show');
                btn.disabled = false; btn.style.opacity = ''; btn.style.cursor = '';
                return;
            }

            var over = v > _qtyCtx.available;
            el.classList.toggle('invalid', over);

             
            if (over) {
                warn.textContent = '⚠️ จำนวนเกินคงเหลือที่จองได้ (' + _qtyCtx.available + (_qtyCtx.unit ? ' ' + _qtyCtx.unit : '') + ') — ลดจำนวนลงก่อน';
                warn.classList.add('show');
                btn.disabled = true;
                btn.style.opacity = '0.5';
                btn.style.cursor = 'not-allowed';
            } else {
                warn.classList.remove('show');
                btn.disabled = false;
                btn.style.opacity = '';
                btn.style.cursor = '';
            }
        }

        function confirmQtyModal() {
            if (!_qtyCtx) return;
            var el = document.getElementById('qtyStepValue');
            var v  = parseInt(el.value, 10) || 0;
            if (v < 1) return;
             
            if (v > _qtyCtx.available) {
                showInfoPopup('จำนวนเกินคงเหลือ', 'จำนวนที่ขอ (' + v + ') เกินคงเหลือที่จองได้ (' + _qtyCtx.available + (_qtyCtx.unit ? ' ' + _qtyCtx.unit : '') + ') กรุณาลดจำนวนลง', 'warning');
                return;
            }

            if (_qtyCtx.editIdx != null) {
                 
                 
                 
                 
                var arr = _draftForForm(_qtyCtx.formKey);
                if (arr && arr[_qtyCtx.editIdx]) {
                    arr[_qtyCtx.editIdx].qty = v;
                     
                    if (_qtyCtx.formKey === 'req' || _qtyCtx.formKey === 'odds') {
                        var _editCb = document.getElementById('qtyChargeToggle');
                        if (_editCb) arr[_qtyCtx.editIdx].charge = !!_editCb.checked;
                    }
                    if (_qtyCtx.formKey === 'odds')      renderOddsDraftTable();
                    else if (_qtyCtx.formKey === 'borrow') renderBorrowDraftTable();
                    else if (_qtyCtx.formKey === 'inbound') renderInboundDraftTable();
                    else                                  renderDraftTable();
                    refreshApproverPickers();
                }
            } else {
                 
                var trigger = document.getElementById(_qtyCtx.inputId);
                if (trigger) {
                    trigger.value = v + (_qtyCtx.unit ? ' ' + _qtyCtx.unit : '');
                    trigger.dataset.qty = v;
                    trigger.dataset.hasValue = '1';
                    trigger.setAttribute('data-has-value', '1');
                }
                 
                if (_qtyCtx.formKey === 'req' || _qtyCtx.formKey === 'odds') {
                    var _addCb = document.getElementById('qtyChargeToggle');
                    var _formCb = document.getElementById(_qtyCtx.formKey === 'odds' ? 'oddsChargeInput' : 'reqChargeInput');
                    if (_addCb && _formCb) _formCb.checked = !!_addCb.checked;
                }
            }
            hapticSuccess();
            closeQtyModal();
        }

         
         
        function _resetQtyTrigger(inputId) {
            var el = document.getElementById(inputId);
            if (!el) return;
            el.value = '';
            el.dataset.qty = '';
            el.dataset.hasValue = '';
            el.removeAttribute('data-has-value');
        }

         
         

         
         
         
         
         
         
         
         
         
         
         
        function compressImage(file, opts) {
            opts = opts || {};
             
            var maxDim  = opts.maxDim  || 1024;
            var quality = opts.quality != null ? opts.quality : 0.6;
            return new Promise(function (resolve) {
                if (!file) { resolve(null); return; }
                var reader = new FileReader();
                reader.onerror = function () {
                     
                    resolve({ name: file.name, dataUrl: '' });
                };
                reader.onload = function (evt) {
                    var rawUrl = evt.target.result;
                     
                    if (!file.type || file.type.indexOf('image/') !== 0) {
                        resolve({ name: file.name, dataUrl: rawUrl });
                        return;
                    }
                    var img = new Image();
                    img.onerror = function () { resolve({ name: file.name, dataUrl: rawUrl }); };
                    img.onload  = function () {
                        var w = img.naturalWidth  || img.width  || 0;
                        var h = img.naturalHeight || img.height || 0;
                        if (!w || !h) { resolve({ name: file.name, dataUrl: rawUrl }); return; }
                         
                        var ratio = Math.min(maxDim / w, maxDim / h, 1);
                        var tw = Math.round(w * ratio);
                        var th = Math.round(h * ratio);
                        try {
                            var canvas = document.createElement('canvas');
                            canvas.width  = tw;
                            canvas.height = th;
                            var ctx = canvas.getContext('2d');
                             
                            ctx.fillStyle = '#ffffff';
                            ctx.fillRect(0, 0, tw, th);
                            ctx.drawImage(img, 0, 0, tw, th);
                             
                             
                             
                            var webpUrl = canvas.toDataURL('image/webp', quality);
                            var outUrl = (webpUrl.indexOf('data:image/webp') === 0)
                                ? webpUrl
                                : canvas.toDataURL('image/jpeg', quality);
                             
                            var out = outUrl.length < rawUrl.length ? outUrl : rawUrl;
                            resolve({ name: file.name, dataUrl: out });
                        } catch (e) {
                            console.warn('compressImage canvas error:', e);
                            resolve({ name: file.name, dataUrl: rawUrl });
                        }
                    };
                    img.src = rawUrl;
                };
                reader.readAsDataURL(file);
            });
        }

         
         

        var RECEIVER_DC_MAP = {
            reqReceiverSelect:    { fn: 'onReqReceiverChange',    dcInput: 'reqReceiverDCInput'    },
            oddsReceiverSelect:   { fn: 'onOddsReceiverChange',   dcInput: 'oddsReceiverDCInput'   },
            borrowReceiverSelect: { fn: 'onBorrowReceiverChange', dcInput: 'borrowReceiverDCInput' }
        };

        function initReceiverSelect2() {
            $('.receiver-select').each(function () {
                var $sel = $(this);
                if ($sel.data('select2')) $sel.select2('destroy');
                $sel.select2({
                    placeholder: '-- เลือกผู้รับ --',
                    allowClear: true,
                    width: '100%',
                    language: { noResults: function () { return 'ไม่พบผู้รับ'; } }
                });
                 
                $sel.off('change.s2recv').on('change.s2recv', function () {
                    var cfg = RECEIVER_DC_MAP[this.id];
                    if (!cfg) return;
                    var dcEl = document.getElementById(cfg.dcInput);
                    if (!dcEl) return;
                    dcEl.style.display = this.value === 'DC' ? 'block' : 'none';
                    if (this.value !== 'DC') dcEl.value = '';
                });
            });
        }

         
         
        function c01Template(item) {
            if (!item.element) return item.text;
            var catId = (item.element.getAttribute('data-catid') || '').toUpperCase();
            var oos = item.element.getAttribute('data-oos') === '1';
            var nameHtml = $('<span>').text(item.text).html();
            function badge(text, bg) {
                return ' <span style="display:inline-block;font-size:0.7em;background:' + bg + ';color:#fff;' +
                    'padding:0.1em 0.5em;border-radius:3px;vertical-align:middle;font-weight:700;">' + text + '</span>';
            }
            var c01 = (catId === 'C01') ? badge('C01', '#dc2626') : '';
            if (oos) {
                return $('<span style="color:#94a3b8;">' +
                    '<span style="text-decoration:line-through;">' + nameHtml + '</span>' +
                    c01 + badge('หมดสต๊อก', '#94a3b8') + '</span>');
            }
            if (catId === 'C01') {
                return $('<span style="color:#dc2626;font-weight:600;">' + nameHtml + c01 + '</span>');
            }
            return item.text;
        }

        // [PHP port 2026-09-23 · มติ 34] ตัวเลือกวัสดุทุกฟอร์มเป็นรหัส IC — โชว์รหัสใต้ชื่อในรายการ
        // และพิมพ์ค้นด้วยรหัสได้ (ค่าของ option = รหัส IC)
        function matResultTemplate(item) {
            var base = c01Template(item);
            if (!item.element || !item.id) return base;
            var $wrap = $('<span>').append(typeof base === 'string' ? $('<span>').text(base) : base);
            $wrap.append($('<span>').text(item.id).css({
                display: 'block', fontSize: '0.75em', opacity: 0.75, textDecoration: 'none',
                fontFamily: 'ui-monospace, Consolas, monospace', letterSpacing: '0.02em'
            }));
            return $wrap;
        }

        function matCodeMatcher(params, data) {
            var term = $.trim(params.term || '').toLowerCase();
            if (term === '') return data;
            if (typeof data.text === 'undefined') return null;
            if (data.text.toLowerCase().indexOf(term) > -1) return data;
            if ((data.id || '').toString().toLowerCase().indexOf(term) > -1) return data;
            return null;
        }

        function initReqSelect2() {
            $('.req-select').each(function () {
                var $sel = $(this);
                if ($sel.data('select2')) $sel.select2('destroy');
                $sel.select2({
                    placeholder: '-- เลือกวัสดุ --',
                    allowClear: true,
                    width: '100%',
                    language: { noResults: function () { return 'ไม่พบวัสดุ'; } },
                    templateResult: matResultTemplate,
                    templateSelection: c01Template,
                    matcher: matCodeMatcher
                });
                 
                 
                $sel.off('change.s2hint').on('change.s2hint', function () {
                    var hintId = this.id === 'reqMaterialSelect'  ? 'reqMaterialBalanceHint'
                               : this.id === 'oddsMaterialSelect' ? 'oddsMaterialBalanceHint'
                               : null;
                    if (hintId) updateMaterialBalanceHint(this.id, hintId);
                     
                    var gKey = this.id === 'oddsMaterialSelect'   ? 'odds'
                             : this.id === 'borrowMaterialSelect' ? 'borrow'
                             : this.id === 'reqMaterialSelect'    ? 'req' : null;
                    if (gKey) refreshGateSelectForForm(gKey, '');
                     
                    var qtyTrigger = this.id === 'reqMaterialSelect'    ? 'reqQtyInput'
                                   : this.id === 'oddsMaterialSelect'   ? 'oddsQtyInput'
                                   : this.id === 'borrowMaterialSelect' ? 'borrowQtyInput'
                                   : null;
                    if (qtyTrigger) _resetQtyTrigger(qtyTrigger);
                     
                     
                     
                     
                    if (typeof refreshApproverPickers === 'function') refreshApproverPickers();
                });
            });
        }

         
         
        var siteGateByMat = {};

        function loadMaterials(opts) {
            var site = getEffectiveSiteCode();
            function render(materials) {
                 
                siteGateByMat = {};
                (materials || []).forEach(function (mat) {
                    var mc = (mat.MatCode || '').toString().trim();
                    var g  = (mat.GateID  || '').toString().trim();
                    if (mc && g) siteGateByMat[mc] = g;
                });

                 
                 
                var _prevMatSel = {};
                document.querySelectorAll('.material-select').forEach(function (select) {
                    if (select.id === 'inboundMaterialSelect') return;
                    try { _prevMatSel[select.id] = ($(select).data('select2') ? $(select).val() : select.value) || ''; }
                    catch (e) { _prevMatSel[select.id] = select.value || ''; }
                });

                document.querySelectorAll('.material-select').forEach(function (select) {
                         
                        if (select.id === 'inboundMaterialSelect') return;

                         
                        if (select.classList.contains('req-select') && $(select).data('select2')) {
                            $(select).select2('destroy');
                        }

                        var defaultText = '-- เลือกวัสดุ --';
                        select.innerHTML = '<option value="" disabled selected>' + defaultText + '</option>';

                        (materials || []).forEach(function (mat) {
                            var matCode = (mat.MatCode || '').toString().trim();
                            var name = (mat.Name || matCode).toString().trim();

                            var unit = (mat.Unit || '').toString().trim();
                            var charId = (mat.CharID || '').toString().trim().toUpperCase();
                            var gateId = (mat.GateID || '').toString().trim();

                             
                             
                             
                             
                             
                             
                             
                            var isOos = false;
                            if (select.id === 'reqMaterialSelect') {
                                if (charId !== 'CSB') return;
                                isOos = getAvailableBalance(matCode) <= 0;
                            }
                            if (select.id === 'oddsMaterialSelect') {
                                if (charId !== 'NAR') return;
                                isOos = getAvailableBalance(matCode) <= 0;
                            }
                            if (select.id === 'borrowMaterialSelect') {
                                if (charId !== 'BRB') return;
                            }

                            var catId = (mat.CatID || '').toString().trim();
                            var option = document.createElement('option');
                            option.value = matCode;
                            option.textContent = name;
                            option.setAttribute('data-unit', unit);
                            option.setAttribute('data-gate', gateId);
                            option.setAttribute('data-catid', catId);
                            if (isOos) option.setAttribute('data-oos', '1');
                            select.appendChild(option);
                        });
                });

                initReqSelect2();

                 
                Object.keys(_prevMatSel).forEach(function (id) {
                    var prev = _prevMatSel[id];
                    if (!prev) return;
                    var sel = document.getElementById(id);
                    if (sel && sel.querySelector('option[value="' + prev.replace(/"/g, '\\"') + '"]')) {
                        try { $(sel).val(prev).trigger('change.select2'); } catch (e) { sel.value = prev; }
                    }
                });

                updateMaterialBalanceHint('reqMaterialSelect', 'reqMaterialBalanceHint');
                updateMaterialBalanceHint('oddsMaterialSelect', 'oddsMaterialBalanceHint');

                 
                loadInboundMaterials(opts);
                loadInboundGates(opts);
            }

            connextCache.swr('materialsList', [site],
                function (done, fail) {
                    google.script.run.withSuccessHandler(done).withFailureHandler(fail).getMaterialsList(site);
                },
                function (materials) { render(materials); },
                function (err) { console.error('Failed to load materials:', err); },
                opts
            );
        }

         
         
        function loadInboundMaterials(opts) {
            var select = document.getElementById('inboundMaterialSelect');
            if (!select) return;
            function render(rows) {
                if ($(select).data('select2')) $(select).select2('destroy');
                select.innerHTML = '<option value="" disabled selected>-- เลือกวัสดุ --</option>';

                (rows || []).forEach(function (mat) {
                    var matCode = (mat.MatCode || '').toString().trim();
                    if (!matCode) return;
                    var name  = (mat.Name  || matCode).toString().trim();
                    var unit  = (mat.Unit  || '').toString().trim();
                    var catId = (mat.CatID || '').toString().trim();

                    var option = document.createElement('option');
                    option.value = matCode;
                    option.textContent = name;
                    option.setAttribute('data-unit',  unit);
                    option.setAttribute('data-gate',  '');      
                    option.setAttribute('data-catid', catId);
                    select.appendChild(option);
                });

                if (select.classList.contains('req-select')) {
                    $(select).select2({
                        placeholder: '-- เลือกวัสดุ --',
                        allowClear: true,
                        width: '100%',
                        language: { noResults: function () { return 'ไม่พบวัสดุ'; } },
                        templateResult: matResultTemplate,
                        templateSelection: c01Template,
                        matcher: matCodeMatcher
                    });
                }
                 
                $(select).off('change.ingate').on('change.ingate', onInboundMaterialChange);
            }
            connextCache.swr('materialsMain', [],
                function (done, fail) {
                    google.script.run.withSuccessHandler(done).withFailureHandler(fail).getMaterialsMainList();
                },
                function (rows) { render(rows); },
                function (err) { console.error('loadInboundMaterials failed:', err); },
                opts
            );
        }

         
         
         
        function onInboundMaterialChange() {
            // [2026-10-06] ใบ IN ไม่ผูก G — ไม่มีช่องเลือกประตูแล้ว · แสดงเฉพาะว่าตอนนี้วัสดุนี้อยู่ G ไหนเท่าไหร่
            //   (ช่วยสโตร์ตัดสินใจว่าจะเอาไปเก็บตู้ไหน) — ยอดขึ้นที่ G ที่สแกนจริง
            var matSel  = document.getElementById('inboundMaterialSelect');
            var hint    = document.getElementById('inboundGateHint');
            if (!matSel) return;
            if (typeof _resetQtyTrigger === 'function') _resetQtyTrigger('inboundQtyInput');
            var matCode  = matSel.value || '';
            var unit = '';
            if (matSel.selectedIndex >= 0) {
                var o = matSel.options[matSel.selectedIndex];
                unit = o ? (o.getAttribute('data-unit') || '') : '';
            }
            var u = unit ? ' ' + unit : '';
            if (!hint) return;
            if (!matCode) {
                hint.style.display = 'none';
                return;
            }
            var rows = getGateRows(matCode);
            var parts = rows.slice().sort(function (a, b) { return (a.GateID || '').localeCompare(b.GateID || ''); })
                .filter(function (r) { return (parseFloat(r.OnHand) || 0) > 0; })
                .map(function (r) {
                    return '<span class="cw-gate-tag">' + escapeHtml(r.GateID || '') + '</span> ' + formatBalanceValue(parseFloat(r.OnHand) || 0) + u;
                });
            hint.style.display = '';
            hint.innerHTML = parts.length
                ? '<i class="fa-solid fa-circle-info" style="color:var(--accent);"></i> <strong>ตอนนี้มีอยู่:</strong> ' + parts.join(' · ')
                : '<i class="fa-solid fa-circle-info" style="color:var(--accent);"></i> ยังไม่มีของนี้ในตู้ใดของไซต์';
        }

        function loadInboundGates(opts) {
            var select = document.getElementById('inboundGateSelect');
            if (!select) return;
            var mySite = getEffectiveSiteCode();
            function render(rows) {
                select.innerHTML = '<option value="" disabled selected>-- เลือกประตู --</option>';
                (rows || []).forEach(function (g) {
                    var rowSite = (g.SiteCode || '').toString().trim();
                    if (mySite && rowSite && rowSite !== mySite) return;

                    var gateId   = (g.GateID   || '').toString().trim();
                    var gateName = (g.GateName || '').toString().trim();
                    if (!gateId) return;

                    var label = gateName ? (gateId + ' — ' + gateName) : gateId;
                    var option = document.createElement('option');
                    option.value = gateId;
                    option.textContent = label;
                    option.setAttribute('data-gate-name', gateName);
                    select.appendChild(option);
                });
            }
            connextCache.swr('gates', [],
                function (done, fail) {
                    google.script.run.withSuccessHandler(done).withFailureHandler(fail).getGatesList();
                },
                function (rows) { render(rows); },
                function (err) { console.error('loadInboundGates failed:', err); },
                opts
            );
        }

         
         
        function draftItemTitleHtml(item) {
            var bits = [];
            if (item.matCode) bits.push('<span>' + escapeHtml(item.matCode) + '</span>');
            if ((item.catId || '').toString().toUpperCase() === 'C01') {
                bits.push('<span class="draft-c01-pill" title="วัสดุ Critical — ต้องผู้อนุมัติ R6 ขึ้นไป">C01</span>');
            }
            if (item.gateId) {
                bits.push('<span><i class="fa-solid fa-door-open" style="font-size:0.7rem;"></i> ' + escapeHtml(item.gateId) + '</span>');
            }
            return escapeHtml(item.matName || '-') +
                (bits.length ? '<div class="draft-item-sub">' + bits.join('') + '</div>' : '');
        }

         
         
        function draftDeleteDetailHtml(item) {
            if (!item) return '';
            var sub = [];
            if (item.matCode) sub.push('รหัส ' + escapeHtml(item.matCode));
            var qty = (item.qty !== undefined && item.qty !== null && item.qty !== '')
                ? (item.qty + ' ' + escapeHtml(item.unit || '')).trim()
                : '';
            if (qty) sub.push('จำนวน ' + qty);
            if (item.gateId) sub.push('ประตู ' + escapeHtml(item.gateId));
            var critical = (item.catId || '').toString().toUpperCase() === 'C01'
                ? ' <span class="draft-c01-pill" title="วัสดุ Critical">C01</span>'
                : '';
            return '<div style="margin-top:0.85rem;padding:0.65rem 0.85rem;border-radius:var(--radius-md);' +
                'background:var(--background);border:1px solid var(--border);text-align:left;">' +
                '<div style="font-weight:600;color:var(--text-main);">' + escapeHtml(item.matName || '-') + critical + '</div>' +
                (sub.length ? '<div style="font-size:0.86rem;color:var(--text-muted);margin-top:0.2rem;">' + sub.join(' · ') + '</div>' : '') +
                '</div>';
        }

         
         
        var DRAFT_FORM_FIELDS = {
            req:     { mat: 'reqMaterialSelect',     qty: 'reqQtyInput',    recv: 'reqReceiverSelect',    dc: 'reqReceiverDCInput',    texts: ['reqUsageAreaInput', 'reqNoticeInput'], hint: ['reqMaterialSelect', 'reqMaterialBalanceHint'], charge: 'reqChargeInput' },
            odds:    { mat: 'oddsMaterialSelect',    qty: 'oddsQtyInput',   recv: 'oddsReceiverSelect',   dc: 'oddsReceiverDCInput',   texts: ['oddsNoteInput'],                       hint: ['oddsMaterialSelect', 'oddsMaterialBalanceHint'], charge: 'oddsChargeInput' },
            borrow:  { mat: 'borrowMaterialSelect',  qty: 'borrowQtyInput', recv: 'borrowReceiverSelect', dc: 'borrowReceiverDCInput', texts: ['borrowUsageAreaInput', 'borrowNoticeInput'], hint: null },
            inbound: { mat: 'inboundMaterialSelect', qty: 'inboundQtyInput', recv: null,                   dc: null,                    texts: ['inboundRSInput', 'inboundNoticeInput'], hint: null, numQty: null,             gate: 'inboundGateSelect' }
        };

        function resetDraftForm(formKey) {
            var cfg = DRAFT_FORM_FIELDS[formKey];
            if (!cfg) return;

             
            var mat = document.getElementById(cfg.mat);
            if (mat) {
                try {
                    if ($(mat).data('select2')) $(mat).val(null).trigger('change');
                    else mat.value = '';
                } catch (e) { mat.value = ''; }
            }

             
            if (cfg.qty) _resetQtyTrigger(cfg.qty);
            if (cfg.numQty) {
                var nq = document.getElementById(cfg.numQty);
                if (nq) nq.value = '';    
            }
            if (cfg.gate) {
                var g = document.getElementById(cfg.gate);
                if (g) g.value = '';
            }

             
            if (cfg.recv && !(user && user.role === 'Subcontractor')) {
                var recv = document.getElementById(cfg.recv);
                if (recv) {
                    try {
                        if ($(recv).data('select2')) $(recv).val(null).trigger('change');
                        else recv.value = '';
                    } catch (e) { recv.value = ''; }
                }
                var dc = document.getElementById(cfg.dc);
                if (dc) { dc.value = ''; dc.style.display = 'none'; }
            }

             
            (cfg.texts || []).forEach(function (id) {
                var el = document.getElementById(id);
                if (el) el.value = '';
            });

             
            if (cfg.charge) {
                var chargeCb = document.getElementById(cfg.charge);
                if (chargeCb) chargeCb.checked = false;
            }

            if (cfg.hint) updateMaterialBalanceHint(cfg.hint[0], cfg.hint[1]);
            refreshApproverPickers();
        }

         
        function chargeChipHtml(on) {
            return on
                ? '<span style="display:inline-flex;align-items:center;gap:0.3rem;padding:0.15rem 0.55rem;border-radius:999px;background:#fef3c7;color:#92400e;font-size:0.8rem;font-weight:600;white-space:nowrap;"><i class="fa-solid fa-coins"></i> หักเงิน</span>'
                : '<span style="display:inline-flex;align-items:center;padding:0.15rem 0.55rem;border-radius:999px;background:#f1f5f9;color:#64748b;font-size:0.8rem;white-space:nowrap;">ไม่หักเงิน</span>';
        }

         
        function chargeToggleCellHtml(formKey, index, on) {
            var st = on
                ? 'background:#fef3c7;color:#92400e;border:1px solid #fcd34d;'
                : 'background:#f1f5f9;color:#64748b;border:1px solid #e2e8f0;';
            return '<button type="button" onclick="toggleDraftCharge(\'' + formKey + '\',' + index + ')" ' +
                'title="แตะเพื่อสลับ หักเงิน / ไม่หักเงิน" ' +
                'style="' + st + 'padding:0.4rem 0.6rem;border-radius:999px;cursor:pointer;font-weight:600;font-size:0.78rem;white-space:nowrap;display:inline-flex;align-items:center;gap:0.3rem;line-height:1.1;max-width:100%;">' +
                '<i class="fa-solid ' + (on ? 'fa-coins' : 'fa-ban') + '"></i> ' + (on ? 'หักเงิน' : 'ไม่หักเงิน') + '</button>';
        }
        function toggleDraftCharge(formKey, index) {
            hapticTap();
            var arr = formKey === 'odds' ? oddsDraftItems : draftItems;
            if (!arr[index]) return;
            arr[index].charge = !arr[index].charge;
            if (formKey === 'odds') renderOddsDraftTable(); else renderDraftTable();
        }

        function renderDraftTable() {
            var tbody = document.getElementById('draftTableBody');
            if (!tbody) return;
            if (draftItems.length === 0) {
                tbody.innerHTML = '<tr class="draft-empty-row"><td colspan="6">ยังไม่มีรายการเตรียมเบิก</td></tr>';
                return;
            }
            tbody.innerHTML = draftItems.map(function (item, index) {
                var areaCell = '<div>' +
                    '<div>' + escapeHtml(item.usageArea || '-') + '</div>' +
                    (item.notice ? '<div style="color:var(--text-muted); font-size:0.84rem;">หมายเหตุ: ' + escapeHtml(item.notice) + '</div>' : '') +
                '</div>';
                return '<tr>' +
                    '<td data-label="วัสดุ">' + draftItemTitleHtml(item) + '</td>' +
                    '<td data-label="จำนวน">' + item.qty + ' ' + escapeHtml(item.unit || '') + '</td>' +
                    '<td data-label="ผู้รับ">' + escapeHtml(getReceiverDisplayName(item.receiver)) + '</td>' +
                    '<td data-label="พื้นที่ใช้งาน">' + areaCell + '</td>' +
                    '<td data-label="หักเงิน">' + chargeToggleCellHtml('req', index, item.charge) + '</td>' +
                    '<td><button class="draft-edit-btn" onclick="openEditQtyModal(\'req\',' + index + ')" title="แก้ไขจำนวน"><i class="fa-solid fa-pen-to-square"></i></button>' +
                    '<button class="draft-delete-btn" onclick="removeFromDraft(' + index + ')" title="ลบรายการ"><i class="fa-solid fa-trash"></i></button></td>' +
                '</tr>';
            }).join('');
        }

         
         
        function _mergeOrPushDraft(arr, item, keys) {
            function norm(v) { return (v == null ? '' : v).toString().trim(); }
            for (var i = 0; i < arr.length; i++) {
                var same = keys.every(function (k) { return norm(arr[i][k]) === norm(item[k]); });
                if (same) {
                    arr[i].qty = (parseFloat(arr[i].qty) || 0) + (parseFloat(item.qty) || 0);
                    return true;
                }
            }
            arr.push(item);
            return false;
        }

        function addToDraft() {
            var matSelect = document.getElementById('reqMaterialSelect');
            var qtyInput = document.getElementById('reqQtyInput');
            var recvSelect = document.getElementById('reqReceiverSelect');
            var dcInput = document.getElementById('reqReceiverDCInput');
            var usageAreaInput = document.getElementById('reqUsageAreaInput');
            var noticeInput = document.getElementById('reqNoticeInput');
            var chargeInput = document.getElementById('reqChargeInput');

            var matCode = matSelect.value;
            var selectedOpt = matSelect.selectedIndex >= 0 ? matSelect.options[matSelect.selectedIndex] : null;
            var matName = selectedOpt ? selectedOpt.text : '';
            var unit = selectedOpt ? (selectedOpt.getAttribute('data-unit') || '') : '';
             
             
            var qty = parseInt((qtyInput && qtyInput.dataset.qty) || qtyInput.value, 10) || 0;
            var usageArea = usageAreaInput ? usageAreaInput.value.trim() : '';
            var notice = noticeInput ? noticeInput.value.trim() : '';
            var charge = !!(chargeInput && chargeInput.checked);
            var recvVal = recvSelect.value;
            var receiver = '';

            if (recvVal === 'DC') {
                receiver = dcInput.value.trim();
                if (!receiver) { showInfoPopup('ข้อมูลไม่ครบ', 'กรุณาระบุชื่อผู้รับ (DC)', 'warning'); return; }
                receiver = 'DC: ' + receiver;
            } else {
                receiver = recvVal;
            }

             
             
            var gateId = getSelectedGate('req');
            if (matCode && !gateId) {
                showInfoPopup('ข้อมูลไม่ครบ', 'กรุณาเลือกประตูที่จะไปรับของ', 'warning');
                return;
            }
            if (matCode && availableForForm(matCode, 'req') <= 0) {
                showInfoPopup('วัสดุหมดสต๊อก', escapeHtml(matName || matCode) + ' หมดสต๊อกที่ประตู ' + escapeHtml(gateId) + ' จึงไม่สามารถเบิกได้', 'warning');
                return;
            }
            if (!matCode || qty <= 0) { showInfoPopup('ข้อมูลไม่ครบ', 'กรุณาเลือกวัสดุและระบุจำนวนให้ถูกต้อง', 'warning'); return; }
            if (!receiver) { showInfoPopup('ข้อมูลไม่ครบ', 'กรุณาเลือกผู้รับ', 'warning'); return; }
            if (!usageArea) {
                showInfoPopup('ข้อมูลไม่ครบ', 'กรุณาระบุพื้นที่ใช้งาน (UsageArea)', 'warning');
                if (usageAreaInput) usageAreaInput.focus();
                return;
            }

             
            var available = availableForForm(matCode, 'req') - getDraftedQtyAtGate(matCode, 'req', gateId);
            if (qty > available) {
                showInfoPopup('จำนวนไม่เพียงพอ', 'จำนวนที่ต้องการเบิกมากกว่าคงเหลือที่ประตู ' + escapeHtml(gateId) + '\nคงเหลือที่ประตูนี้: ' + formatBalanceValue(Math.max(0, available)) + (unit ? ' ' + unit : ''), 'warning');
                return;
            }

             
             
            var catId = selectedOpt ? (selectedOpt.getAttribute('data-catid') || '').toString().trim().toUpperCase() : '';
            var _merged = _mergeOrPushDraft(draftItems,
                { matCode: matCode, matName: matName, unit: unit, qty: qty, receiver: receiver, usageArea: usageArea, notice: notice, catId: catId, gateId: gateId, charge: charge },
                ['matCode', 'receiver', 'usageArea', 'notice', 'gateId', 'charge']);
            if (_merged) showToast('รวมจำนวนกับรายการเดิมแล้ว', 'info');
            renderDraftTable();
            _resetQtyTrigger('reqQtyInput');
             
             
            if (noticeInput) noticeInput.value = '';
            if (chargeInput) chargeInput.checked = false;
            updateMaterialBalanceHint('reqMaterialSelect', 'reqMaterialBalanceHint');
            refreshGateSelectForForm('req', gateId);
            refreshApproverPickers();
        }

        function removeFromDraft(index) {
            showConfirmPopup('ลบรายการ', 'ต้องการลบรายการนี้ออกจากรายการเตรียมเบิกใช่หรือไม่?' + draftDeleteDetailHtml(draftItems[index]), function () {
                hapticTap();
                draftItems.splice(index, 1);
                renderDraftTable();
                refreshApproverPickers();
            }, 'ลบรายการ', 'btn btn-danger');
        }

         
        function _distinctReceiverCount(items) {
            var seen = {};
            (items || []).forEach(function (it) { seen[(it.receiver || '').toString().trim()] = 1; });
            return Object.keys(seen).length;
        }
        // [PHP port 2026-09-25 per-gate · มติ 51] ใบแตกตาม "ผู้รับ × ประตู" — บอกจำนวนใบจริงก่อนส่ง
        function _distinctDocCount(items) {
            var seen = {};
            (items || []).forEach(function (it) {
                seen[(it.receiver || '').toString().trim() + '|' + (it.gateId || '').toString().trim()] = 1;
            });
            return Object.keys(seen).length;
        }
        function _splitByReceiverNote(items) {
            var n = _distinctReceiverCount(items);
            var d = _distinctDocCount(items);
            if (d <= 1) return '';
            if (n > 1 && d === n) return '\n\n📄 มีผู้รับ ' + n + ' ราย → ระบบจะแยกเป็น ' + n + ' เอกสาร (คนละใบตามผู้รับ)';
            if (n <= 1) return '\n\n📄 ของอยู่คนละประตู → ระบบจะแยกเป็น ' + d + ' เอกสาร (คนละใบตามประตูที่ไปรับ)';
            return '\n\n📄 มีผู้รับ ' + n + ' ราย และของอยู่คนละประตู → ระบบจะแยกเป็น ' + d + ' เอกสาร (คนละใบตามผู้รับ/ประตู)';
        }

        function submitRequisition() {
            if (draftItems.length === 0) {
                showInfoPopup('ยังไม่มีรายการ', 'ไม่มีรายการใน Draft กรุณาเพิ่มรายการก่อน', 'warning');
                return;
            }

             
             
             
            var needsPicker = _shouldShowPickerFor('req');
            var chosenApprover = needsPicker ? getSelectedApprover('req') : (user ? user.username : '');
            if (needsPicker && !chosenApprover) {
                var minR = _requiredApproverRole('req');
                showInfoPopup('ยังไม่ได้เลือกผู้อนุมัติ',
                    'กรุณาเลือกผู้อนุมัติ (R' + minR + ' ขึ้นไป) ก่อนส่งคำขอ',   // [มติ 52] เบิกวัสดุหลัก = R6 ทุกใบ
                    'warning');
                return;
            }

            var payload = draftItems.map(function (item) {
                return {
                    MatCode: item.matCode,
                    Qty: item.qty,
                    UserName: getEffectiveUserName(),
                    Receiver: item.receiver || '',
                    GateID: item.gateId || '',   // [PHP port 2026-09-25 per-gate · มติ 51] ประตูที่เลือกในฟอร์ม (เดิมหาย → server ใช้ประตูตั้งต้น)
                    Notice: item.notice || '',
                    UsageArea: item.usageArea || '',
                    SiteCode: user ? (user.siteCode || '') : '',
                    Charge: !!item.charge,
                    AutoApprove: !needsPicker,
                    Approver: chosenApprover
                };
            });

            // [มติ 52] ให้ตรงกับเกณฑ์ server: ไม่ต้องเลือกผู้อนุมัติ (สิทธิ์ถึง R6) = อนุมัติทันที
            // (เดิมใช้ "ระดับ ≥ R5" ซึ่งไม่ตรง — R5 เบิก C01 เห็นว่า "จะอนุมัติทันที" ทั้งที่ไปรออนุมัติ)
            var isImmediateApprove = !needsPicker;

            showConfirmPopup(
                isImmediateApprove ? 'ยืนยันเบิกวัสดุหลัก' : 'ยืนยันส่งขออนุมัติ',
                (isImmediateApprove ? 'รายการนี้จะถูกอนุมัติทันทีตามสิทธิ์ของคุณ ต้องการดำเนินการต่อหรือไม่?' : 'ยืนยันการส่งคำขออนุมัติทั้งหมดใช่หรือไม่?') + _splitByReceiverNote(draftItems),
                function () {
                    showLoadingPopup(
                        isImmediateApprove ? 'กำลังบันทึกและอนุมัติรายการ' : 'กำลังส่งขออนุมัติ',
                        isImmediateApprove ? 'กรุณารอสักครู่ ระบบกำลังบันทึกรายการและอนุมัติให้อัตโนมัติ' : 'กรุณารอสักครู่ ระบบกำลังส่งคำขออนุมัติทั้งหมด'
                    );

                    google.script.run
                        .withSuccessHandler(function (response) {
                            if (response.success) {
                                draftItems = [];
                                renderDraftTable();
                                resetDraftForm('req');
                                closeAppPopup();
                                var docList = (response.docs && response.docs.length)
                                    ? response.docs.join(', ')
                                    : response.requisId;
                                hapticSuccess();
                                invalidateAfterWrite('requisition');
                                 
                                loadDashboard({force:true});
                                loadApprovalQueue({force:true});
                                loadQRPage({force:true});
                                loadConfirmableDocuments({force:true});
                                loadBalance(function () { loadMaterials({force:true}); }, {force:true});
                                showInfoPopup(
                                    isImmediateApprove ? '✅ บันทึกและอนุมัติเรียบร้อย' : '✅ ส่งคำขออนุมัติเรียบร้อย',
                                    (isImmediateApprove ? 'ระบบอนุมัติรายการให้ทันทีแล้ว' : 'ส่งคำขออนุมัติเรียบร้อยแล้ว') + '\nเลขเอกสาร: ' + docList,
                                    'success'
                                );
                            } else {
                                closeAppPopup();
                                showInfoPopup('เกิดข้อผิดพลาด', response.message || 'ไม่สามารถบันทึกรายการได้', 'danger');
                            }
                        })
                        .withFailureHandler(function (error) {
                            console.error('Submission failed:', error);
                            closeAppPopup();
                            showInfoPopup('เชื่อมต่อเซิร์ฟเวอร์ไม่สำเร็จ', 'เกิดข้อผิดพลาดในการเชื่อมต่อเซิร์ฟเวอร์', 'danger');
                        })
                        .processRequisitionSubmission(payload);
                },
                isImmediateApprove ? 'ยืนยันและอนุมัติทันที' : 'ยืนยันส่งคำขอ',
                'btn btn-primary'
            );
        }

        function renderOddsDraftTable() {
            var tbody = document.getElementById('oddsDraftTableBody');
            if (!tbody) return;
            if (oddsDraftItems.length === 0) {
                tbody.innerHTML = '<tr class="draft-empty-row"><td colspan="6">ยังไม่มีรายการเตรียมเบิก</td></tr>';
                return;
            }
            tbody.innerHTML = oddsDraftItems.map(function (item, index) {
                return '<tr>' +
                    '<td data-label="วัสดุ">' + draftItemTitleHtml(item) + '</td>' +
                    '<td data-label="จำนวน">' + item.qty + ' ' + escapeHtml(item.unit || '') + '</td>' +
                    '<td data-label="ผู้รับ">' + escapeHtml(getReceiverDisplayName(item.receiver)) + '</td>' +
                    '<td data-label="หมายเหตุ">' + escapeHtml(item.note || '-') + '</td>' +
                    '<td data-label="หักเงิน">' + chargeToggleCellHtml('odds', index, item.charge) + '</td>' +
                    '<td><button class="draft-edit-btn" onclick="openEditQtyModal(\'odds\',' + index + ')" title="แก้ไขจำนวน"><i class="fa-solid fa-pen-to-square"></i></button>' +
                    '<button class="draft-delete-btn" onclick="removeFromOddsDraft(' + index + ')" title="ลบรายการ"><i class="fa-solid fa-trash"></i></button></td>' +
                '</tr>';
            }).join('');
        }

        function addToOddsDraft() {
            var matSelect = document.getElementById('oddsMaterialSelect');
            var qtyInput = document.getElementById('oddsQtyInput');
            var recvSelect = document.getElementById('oddsReceiverSelect');
            var dcInput = document.getElementById('oddsReceiverDCInput');
            var noteInput = document.getElementById('oddsNoteInput');
            var chargeInput = document.getElementById('oddsChargeInput');
            var matCode = matSelect.value;
            var selectedOpt = matSelect.selectedIndex >= 0 ? matSelect.options[matSelect.selectedIndex] : null;
            var matName = selectedOpt ? selectedOpt.text : '';
            var unit = selectedOpt ? (selectedOpt.getAttribute('data-unit') || '') : '';
            var qty = parseInt((qtyInput && qtyInput.dataset.qty) || qtyInput.value, 10) || 0;
            var note = noteInput ? noteInput.value.trim() : '';
            var charge = !!(chargeInput && chargeInput.checked);
            var recvVal = recvSelect.value;
            var receiver = '';

            if (recvVal === 'DC') {
                receiver = dcInput.value.trim();
                if (!receiver) { showInfoPopup('ข้อมูลไม่ครบ', 'กรุณาระบุชื่อผู้รับ (DC)', 'warning'); return; }
                receiver = 'DC: ' + receiver;
            } else {
                receiver = recvVal;
            }

            var gateId = getSelectedGate('odds');
            if (matCode && !gateId) {
                showInfoPopup('ข้อมูลไม่ครบ', 'กรุณาเลือกประตูที่จะไปรับของ', 'warning');
                return;
            }
            if (matCode && availableForForm(matCode, 'odds') <= 0) {
                showInfoPopup('วัสดุหมดสต๊อก', escapeHtml(matName || matCode) + ' หมดสต๊อกที่ประตู ' + escapeHtml(gateId) + ' จึงไม่สามารถเบิกได้', 'warning');
                return;
            }
            if (!matCode || qty <= 0) { showInfoPopup('ข้อมูลไม่ครบ', 'กรุณาเลือกวัสดุและระบุจำนวนให้ถูกต้อง', 'warning'); return; }
            if (!receiver) { showInfoPopup('ข้อมูลไม่ครบ', 'กรุณาเลือกผู้รับ', 'warning'); return; }

            var available = availableForForm(matCode, 'odds') - getDraftedQtyAtGate(matCode, 'odds', gateId);
            if (qty > available) {
                showInfoPopup('จำนวนไม่เพียงพอ', 'จำนวนที่ต้องการเบิกมากกว่าคงเหลือที่ประตู ' + escapeHtml(gateId) + '\nคงเหลือที่ประตูนี้: ' + formatBalanceValue(Math.max(0, available)) + (unit ? ' ' + unit : ''), 'warning');
                return;
            }

            var catId = selectedOpt ? (selectedOpt.getAttribute('data-catid') || '').toString().trim().toUpperCase() : '';
            var _merged = _mergeOrPushDraft(oddsDraftItems,
                { matCode: matCode, matName: matName, unit: unit, qty: qty, receiver: receiver, note: note, catId: catId, gateId: gateId, charge: charge },
                ['matCode', 'receiver', 'note', 'gateId', 'charge']);
            if (_merged) showToast('รวมจำนวนกับรายการเดิมแล้ว', 'info');
            renderOddsDraftTable();
            _resetQtyTrigger('oddsQtyInput');
            if (noteInput) noteInput.value = '';
            if (chargeInput) chargeInput.checked = false;
            updateMaterialBalanceHint('oddsMaterialSelect', 'oddsMaterialBalanceHint');
            refreshGateSelectForForm('odds', gateId);
        }

        function removeFromOddsDraft(index) {
            showConfirmPopup('ลบรายการ', 'ต้องการลบรายการนี้ออกจากรายการเตรียมเบิกใช่หรือไม่?' + draftDeleteDetailHtml(oddsDraftItems[index]), function () {
                hapticTap();
                oddsDraftItems.splice(index, 1);
                renderOddsDraftTable();
            }, 'ลบรายการ', 'btn btn-danger');
        }

        function submitOddsRequisition() {
            if (oddsDraftItems.length === 0) {
                showInfoPopup('ยังไม่มีรายการ', 'ไม่มีรายการใน Draft กรุณาเพิ่มรายการก่อน', 'warning');
                return;
            }

            var payload = oddsDraftItems.map(function (item) {
                return {
                    MatCode: item.matCode,
                    Qty: item.qty,
                    UserName: getEffectiveUserName(),
                    Receiver: item.receiver || '',
                    GateID: item.gateId || '',   // [PHP port 2026-09-25 per-gate · มติ 51]
                    UsageArea: item.note || '',
                    SiteCode: user ? (user.siteCode || '') : '',
                    Charge: !!item.charge
                };
            });

            showConfirmPopup(
                'ยืนยันส่งเบิกวัสดุเบ็ดเตล็ด',
                'ยืนยันการส่งเบิกวัสดุเบ็ดเตล็ดทั้งหมดใช่หรือไม่?' + _splitByReceiverNote(oddsDraftItems),
                function () {
                    showLoadingPopup('กำลังส่งเบิกวัสดุเบ็ดเตล็ด', 'กรุณารอสักครู่ ระบบกำลังบันทึกข้อมูลและส่งเบิกทันที');

                    google.script.run
                        .withSuccessHandler(function (response) {
                            if (response.success) {
                                oddsDraftItems = [];
                                renderOddsDraftTable();
                                resetDraftForm('odds');
                                closeAppPopup();
                                hapticSuccess();
                                invalidateAfterWrite('requisition');
                                 
                                loadDashboard({force:true});
                                loadQRPage({force:true});
                                loadConfirmableDocuments({force:true});
                                loadBalance(function () {
                                    loadMaterials({force:true});
                                }, {force:true});
                                var odDocs = (response.docs && response.docs.length) ? response.docs.join(', ') : response.oddsId;
                                showInfoPopup(
                                    'ส่งเบิกวัสดุเบ็ดเตล็ดเรียบร้อย',
                                    'บันทึกรายการเรียบร้อยแล้ว\nเลขเอกสาร: ' + odDocs,
                                    'success'
                                );
                            } else {
                                closeAppPopup();
                                showInfoPopup('เกิดข้อผิดพลาด', response.message || 'ไม่สามารถบันทึกรายการได้', 'danger');
                            }
                        })
                        .withFailureHandler(function (error) {
                            console.error('Odds submission failed:', error);
                            closeAppPopup();
                            showInfoPopup('เชื่อมต่อเซิร์ฟเวอร์ไม่สำเร็จ', 'เกิดข้อผิดพลาดในการเชื่อมต่อเซิร์ฟเวอร์', 'danger');
                        })
                        .processOddsSubmission(payload);
                },
                'ยืนยันส่งรายการ',
                'btn btn-success'
            );
        }

        function renderBorrowDraftTable() {
            var tbody = document.getElementById('borrowDraftTableBody');
            if (!tbody) return;
            if (borrowDraftItems.length === 0) {
                tbody.innerHTML = '<tr class="draft-empty-row"><td colspan="5">ยังไม่มีรายการ</td></tr>';
                return;
            }
            tbody.innerHTML = borrowDraftItems.map(function (item, index) {
                var areaCell = '<div>' +
                    '<div>' + escapeHtml(item.usageArea || '-') + '</div>' +
                    (item.notice ? '<div style="color:var(--text-muted); font-size:0.84rem;">หมายเหตุ: ' + escapeHtml(item.notice) + '</div>' : '') +
                '</div>';
                return '<tr>' +
                    '<td data-label="อุปกรณ์">' + draftItemTitleHtml(item) + '</td>' +
                    '<td data-label="จำนวน">' + item.qty + ' ' + escapeHtml(item.unit || '') + '</td>' +
                    '<td data-label="ผู้รับ">' + escapeHtml(getReceiverDisplayName(item.receiver)) + '</td>' +
                    '<td data-label="พื้นที่ใช้งาน">' + areaCell + '</td>' +
                    '<td><button class="draft-edit-btn" onclick="openEditQtyModal(\'borrow\',' + index + ')" title="แก้ไขจำนวน"><i class="fa-solid fa-pen-to-square"></i></button>' +
                    '<button class="draft-delete-btn" onclick="removeFromBorrowDraft(' + index + ')" title="ลบรายการ"><i class="fa-solid fa-trash"></i></button></td>' +
                '</tr>';
            }).join('');
        }

        function addToBorrowDraft() {
            var recvSelect = document.getElementById('borrowReceiverSelect');
            var dcInput = document.getElementById('borrowReceiverDCInput');
            var matSelect = document.getElementById('borrowMaterialSelect');
            var qtyInput = document.getElementById('borrowQtyInput');
            var recvVal = recvSelect ? recvSelect.value : '';
            var receiver = '';

            if (recvVal === 'DC') {
                receiver = dcInput ? dcInput.value.trim() : '';
                if (!receiver) { showInfoPopup('ข้อมูลไม่ครบ', 'กรุณาระบุชื่อผู้รับ (DC)', 'warning'); return; }
                receiver = 'DC: ' + receiver;
            } else {
                receiver = recvVal;
            }

            var matCode = matSelect.value;
            var selectedOpt = matSelect.selectedIndex >= 0 ? matSelect.options[matSelect.selectedIndex] : null;
            var matName = selectedOpt ? selectedOpt.text : '';
            var unit = selectedOpt ? (selectedOpt.getAttribute('data-unit') || '') : '';
            var gateId = getSelectedGate('borrow');
            var qty = parseInt((qtyInput && qtyInput.dataset.qty) || qtyInput.value, 10) || 0;
            var usageAreaInput = document.getElementById('borrowUsageAreaInput');
            var usageArea = usageAreaInput ? usageAreaInput.value.trim() : '';
            var noticeInput = document.getElementById('borrowNoticeInput');
            var notice = noticeInput ? noticeInput.value.trim() : '';

            if (!receiver) { showInfoPopup('ข้อมูลไม่ครบ', 'กรุณาเลือกผู้รับ', 'warning'); return; }
            if (!matCode || qty <= 0) { showInfoPopup('ข้อมูลไม่ครบ', 'กรุณาเลือกอุปกรณ์และระบุจำนวนให้ถูกต้อง', 'warning'); return; }
            if (matCode && !gateId) { showInfoPopup('ข้อมูลไม่ครบ', 'กรุณาเลือกประตูที่จะไปรับอุปกรณ์', 'warning'); return; }
            var _brwAvail = availableForForm(matCode, 'borrow') - getDraftedQtyAtGate(matCode, 'borrow', gateId);
            if (qty > _brwAvail) {
                showInfoPopup('จำนวนไม่เพียงพอ', 'จำนวนที่ต้องการยืมมากกว่าคงเหลือที่ประตู ' + escapeHtml(gateId) + '\nคงเหลือที่ประตูนี้: ' + formatBalanceValue(Math.max(0, _brwAvail)) + (unit ? ' ' + unit : ''), 'warning');
                return;
            }
            if (!usageArea) {
                showInfoPopup('ข้อมูลไม่ครบ', 'กรุณาระบุพื้นที่ใช้งาน (UsageArea)', 'warning');
                if (usageAreaInput) usageAreaInput.focus();
                return;
            }

            var catId = selectedOpt ? (selectedOpt.getAttribute('data-catid') || '').toString().trim().toUpperCase() : '';
            var _merged = _mergeOrPushDraft(borrowDraftItems,
                { receiver: receiver, matCode: matCode, matName: matName, qty: qty, unit: unit, gateId: gateId, usageArea: usageArea, notice: notice, catId: catId },
                ['matCode', 'receiver', 'usageArea', 'notice', 'gateId']);
            if (_merged) showToast('รวมจำนวนกับรายการเดิมแล้ว', 'info');
            renderBorrowDraftTable();
            _resetQtyTrigger('borrowQtyInput');
            refreshGateSelectForForm('borrow', gateId);
            refreshApproverPickers();
        }

        function removeFromBorrowDraft(index) {
            showConfirmPopup('ลบรายการ', 'ต้องการลบรายการนี้ออกจากรายการที่เตรียมไว้ใช่หรือไม่?' + draftDeleteDetailHtml(borrowDraftItems[index]), function () {
                hapticTap();
                borrowDraftItems.splice(index, 1);
                renderBorrowDraftTable();
                refreshApproverPickers();
            }, 'ลบรายการ', 'btn btn-danger');
        }

        function submitBorrowDraft() {
            if (borrowDraftItems.length === 0) {
                showInfoPopup('ยังไม่มีรายการ', 'ไม่มีรายการ กรุณาเพิ่มรายการก่อน', 'warning');
                return;
            }

            var needsPicker = _shouldShowPickerFor('borrow');
            var chosenApprover = needsPicker ? getSelectedApprover('borrow') : (user ? user.username : '');
            if (needsPicker && !chosenApprover) {
                var minR = _requiredApproverRole('borrow');
                showInfoPopup('ยังไม่ได้เลือกผู้อนุมัติ',
                    'กรุณาเลือกผู้อนุมัติ' + (minR >= 6 ? ' (R6 ขึ้นไป สำหรับวัสดุ C01)' : ' (R4 ขึ้นไป สำหรับวัสดุ C02)') + ' ก่อนส่งคำขอ',
                    'warning');
                return;
            }

            var payload = borrowDraftItems.map(function (item) {
                return {
                    UserName: getEffectiveUserName(),
                    MatCode: item.matCode,
                    Qty: item.qty,
                    Receiver: item.receiver || '',
                    GateID: item.gateId || '',
                    Notice: item.notice || '',
                    UsageArea: item.usageArea || '',
                    SiteCode: user ? (user.siteCode || '') : '',
                    Approver: chosenApprover
                };
            });

            showConfirmPopup(
                'ยืนยันบันทึกการยืม',
                'ยืนยันบันทึกการยืมอุปกรณ์ทั้งหมดใช่หรือไม่?' + _splitByReceiverNote(borrowDraftItems),
                function () {
                    showLoadingPopup('กำลังบันทึกการยืม', 'กรุณารอสักครู่ ระบบกำลังบันทึกข้อมูล');

                    google.script.run
                        .withSuccessHandler(function (response) {
                            if (response.success) {
                                borrowDraftItems = [];
                                renderBorrowDraftTable();
                                resetDraftForm('borrow');
                                closeAppPopup();
                                hapticSuccess();
                                invalidateAfterWrite('requisition');
                                 
                                loadUnreturnedItems({force:true});
                                loadDashboard({force:true});
                                loadQRPage({force:true});
                                loadApprovalQueue({force:true});
                                var bdDocs = (response.borrowIds && response.borrowIds.length) ? response.borrowIds.join(', ') : response.borrowId;
                                showInfoPopup(
                                    'บันทึกการยืมสำเร็จ',
                                    'บันทึกรายการเรียบร้อยแล้ว ' + response.count + ' รายการ\nเลขเอกสาร: ' + bdDocs,
                                    'success'
                                );
                            } else {
                                closeAppPopup();
                                showInfoPopup('เกิดข้อผิดพลาด', response.message || 'ไม่สามารถบันทึกรายการได้', 'danger');
                            }
                        })
                        .withFailureHandler(function (error) {
                            console.error('Borrow batch failed:', error);
                            closeAppPopup();
                            showInfoPopup('เชื่อมต่อเซิร์ฟเวอร์ไม่สำเร็จ', 'เกิดข้อผิดพลาดในการเชื่อมต่อเซิร์ฟเวอร์', 'danger');
                        })
                        .processBorrowBatch(payload);
                },
                'ยืนยันบันทึก',
                'btn btn-primary'
            );
        }

         
         
         
        var inboundDraftItems = [];

        function renderInboundDraftTable() {
            var tbody = document.getElementById('inboundDraftTableBody');
            if (!tbody) return;
            if (inboundDraftItems.length === 0) {
                tbody.innerHTML = '<tr class="draft-empty-row"><td colspan="5">ยังไม่มีรายการ</td></tr>';
                return;
            }
            // [2026-10-06] ไม่มีคอลัมน์ประตู — ใบ IN ไม่ผูก G (ยอดขึ้นที่ G ที่สแกน)
            tbody.innerHTML = inboundDraftItems.map(function (item, index) {
                return '<tr>' +
                    '<td data-label="วัสดุ">' + draftItemTitleHtml(item) + '</td>' +
                    '<td data-label="จำนวน">' + item.qty + ' ' + escapeHtml(item.unit || '') + '</td>' +
                    '<td data-label="RS">' + escapeHtml(item.rs || '-') + '</td>' +
                    '<td data-label="หมายเหตุ">' + escapeHtml(item.notice || '-') + '</td>' +
                    '<td><button class="draft-edit-btn" onclick="openEditQtyModal(\'inbound\',' + index + ')" title="แก้ไขจำนวน"><i class="fa-solid fa-pen-to-square"></i></button>' +
                    '<button class="draft-delete-btn" onclick="removeFromInboundDraft(' + index + ')" title="ลบรายการ"><i class="fa-solid fa-trash"></i></button></td>' +
                '</tr>';
            }).join('');
        }

        function addToInboundDraft() {
            var rsInput     = document.getElementById('inboundRSInput');
            var matSelect   = document.getElementById('inboundMaterialSelect');
            var qtyInput    = document.getElementById('inboundQtyInput');
            var noticeInput = document.getElementById('inboundNoticeInput');

            var rs       = rsInput ? rsInput.value.trim() : '';
            var matCode  = matSelect ? matSelect.value : '';
            var selectedOpt = matSelect && matSelect.selectedIndex >= 0 ? matSelect.options[matSelect.selectedIndex] : null;
            var matName  = selectedOpt ? selectedOpt.text : '';
            var unit     = selectedOpt ? (selectedOpt.getAttribute('data-unit') || '') : '';
            var qty      = parseInt((qtyInput && qtyInput.dataset.qty) || (qtyInput && qtyInput.value) || '', 10) || 0;
            var notice   = noticeInput ? noticeInput.value.trim() : '';

            if (!rs && !(window.inboundCtl && window.inboundCtl.rsOptional())) { showInfoPopup('ข้อมูลไม่ครบ', 'กรุณาระบุเลขที่ใบรับสินค้า (RS) หรือ PO', 'warning'); return; }   // [2026-10-02 · GP-10] ไม่มีใบส่งของ = RS ไม่บังคับ
            if (!matCode || qty <= 0) { showInfoPopup('ข้อมูลไม่ครบ', 'กรุณาเลือกวัสดุและระบุจำนวนให้ถูกต้อง', 'warning'); return; }
            // [2026-10-06] ไม่เลือกประตูแล้ว — ใบ IN ไม่ผูก G (สแกนที่ตู้ G ไหนของไซต์ = ยอดขึ้นที่ G นั้น)

            var catId = selectedOpt ? (selectedOpt.getAttribute('data-catid') || '').toString().trim().toUpperCase() : '';
            var _merged = _mergeOrPushDraft(inboundDraftItems,
                { rs: rs, matCode: matCode, matName: matName, qty: qty, unit: unit, gateId: '', notice: notice, catId: catId },
                ['matCode', 'rs', 'notice']);
            if (_merged) showToast('รวมจำนวนกับรายการเดิมแล้ว', 'info');
            renderInboundDraftTable();
             
             
            try { if ($(matSelect).data('select2')) $(matSelect).val(null).trigger('change'); else { matSelect.value=''; onInboundMaterialChange(); } } catch (e) { onInboundMaterialChange(); }
            _resetQtyTrigger('inboundQtyInput');    
            if (noticeInput) noticeInput.value = '';
            refreshApproverPickers();
        }

        function removeFromInboundDraft(index) {
            showConfirmPopup('ลบรายการ', 'ต้องการลบรายการนี้ออกจากรายการรับเข้าใช่หรือไม่?' + draftDeleteDetailHtml(inboundDraftItems[index]), function () {
                hapticTap();
                inboundDraftItems.splice(index, 1);
                renderInboundDraftTable();
                refreshApproverPickers();
            }, 'ลบรายการ', 'btn btn-danger');
        }

        function submitInboundDraft() {
            if (inboundDraftItems.length === 0) {
                showInfoPopup('ยังไม่มีรายการ', 'ไม่มีรายการ กรุณาเพิ่มรายการก่อน', 'warning');
                return;
            }
            // [2026-10-02 · GP-10] ที่มาของของ + รูปใบส่งของ / รูปของ (js/inbound-ctl.js) — server ตรวจซ้ำ
            if (window.inboundCtl) {
                var _inErr = window.inboundCtl.check();
                if (_inErr) { showInfoPopup('ข้อมูลไม่ครบ', _inErr, 'warning'); return; }
            }

            var needsPicker = _shouldShowPickerFor('inbound');
            var chosenApprover = needsPicker ? getSelectedApprover('inbound') : (user ? user.username : '');
            if (needsPicker && !chosenApprover) {
                var minR = _requiredApproverRole('inbound');
                showInfoPopup('ยังไม่ได้เลือกผู้อนุมัติ',
                    'กรุณาเลือกผู้อนุมัติ' + (minR >= 6 ? ' (R6 ขึ้นไป สำหรับวัสดุ C01)' : ' (R4 ขึ้นไป สำหรับวัสดุ C02)') + ' ก่อนส่งคำขอ',
                    'warning');
                return;
            }

            var payload = inboundDraftItems.map(function (item) {
                return {
                    UserName: getEffectiveUserName(),
                    MatCode:  item.matCode,
                    Qty:      item.qty,
                    RS:       item.rs || '',
                    GateID:   '',   // [2026-10-06] ใบ IN ไม่ผูก G — server ไม่ใช้ค่านี้แล้ว
                    Notice:   item.notice || '',
                    SiteCode: user ? (user.siteCode || '') : '',
                    Approver: chosenApprover
                };
            });

            showConfirmPopup(
                'ยืนยันบันทึกรับเข้าคลัง',
                'ยืนยันบันทึกการรับเข้าคลังทั้งหมดใช่หรือไม่?',
                function () {
                    showLoadingPopup('กำลังบันทึก', 'กรุณารอสักครู่ ระบบกำลังบันทึกข้อมูล');
                    google.script.run
                        .withSuccessHandler(function (response) {
                            closeAppPopup();
                            if (response && response.success) {
                                inboundDraftItems = [];
                                renderInboundDraftTable();
                                resetDraftForm('inbound');
                                if (window.inboundCtl) window.inboundCtl.reset();   // [2026-10-02 · GP-10]
                                var docList = (response.inboundIds || [response.inboundId]).join(', ');
                                hapticSuccess();
                                invalidateAfterWrite('inbound');
                                 
                                 
                                loadDashboard({force:true});
                                loadApprovalQueue({force:true});
                                pageLoadState.qr = false;
                                loadQRPage({force:true});
                                loadConfirmableDocuments({force:true});
                                showInfoPopup(
                                    'บันทึกรับเข้าสำเร็จ',
                                    'เลขเอกสาร: ' + docList + '\n' +
                                    'ระบบสร้าง QR ให้แล้ว (ไม่ต้องขออนุมัติ) — นำของไปสแกนที่ตู้ G ไหนของไซต์นี้ก็ได้ ยอดจะขึ้นที่ G ที่สแกน แล้วถ่ายรูปยืนยันรับเข้า',
                                    'success',
                                    function () {
                                        switchToPage('qr');
                                    }
                                );
                            } else {
                                showInfoPopup('เกิดข้อผิดพลาด', (response && response.message) || 'ไม่สามารถบันทึกรายการได้', 'danger');
                            }
                        })
                        .withFailureHandler(function (error) {
                            console.error('Inbound batch failed:', error);
                            closeAppPopup();
                            showInfoPopup('เชื่อมต่อเซิร์ฟเวอร์ไม่สำเร็จ', 'เกิดข้อผิดพลาดในการเชื่อมต่อเซิร์ฟเวอร์', 'danger');
                        })
                        .processInboundBatch(payload, window.inboundCtl ? window.inboundCtl.meta() : {});   // [2026-10-02 · GP-10]
                },
                'ยืนยันบันทึก',
                'btn btn-primary'
            );
        }

        function loadUnreturnedItems(opts) {
            var tbody = document.getElementById('unreturnedTableBody');
            if (!tbody) return;
            var roleLevel = user ? user.roleLevel : '';
            var roleName  = user ? (user.role || '') : '';
            var userName  = getEffectiveUserName();
            var site      = getEffectiveSiteCode();

             
            if (!connextCache.get('unreturned', [roleLevel, userName, site, roleName])) {
                tbody.innerHTML = '<tr class="draft-empty-row"><td colspan="4"><i class="fa-solid fa-spinner fa-spin"></i> Loading...</td></tr>';
            }

            function render(data) {
                tbody.innerHTML = '';
                if (!data || data.length === 0) {
                    tbody.innerHTML = '<tr class="draft-empty-row"><td colspan="4">ไม่มีรายการที่ยังไม่ส่งคืน</td></tr>';
                    return;
                }
                var grouped = {};
                var groupOrder = [];
                data.forEach(function (item) {
                    if (!grouped[item.borrowId]) {
                        grouped[item.borrowId] = [];
                        groupOrder.push(item.borrowId);
                    }
                    grouped[item.borrowId].push(item);
                });

                unreturnedData = {};
                groupOrder.forEach(function (borrowId) {
                    var rowItems = grouped[borrowId];
                    var itemList = rowItems.map(function (it) { return it.matName + ' (x' + it.qty + ')'; }).join(', ');
                    var firstItem = rowItems[0];
                    var borrowerInfo = firstItem.borrower + ' / ' + firstItem.subId;
                    unreturnedData[borrowId] = { rowItems: rowItems, borrowerInfo: borrowerInfo };
                    var tr = document.createElement('tr');
                    tr.innerHTML = '<td data-label="BorrowID" style="font-weight:600;">' + escapeHtml(borrowId) + '</td>' +
                        '<td data-label="ผู้ยืม/บริษัท">' + escapeHtml(borrowerInfo) + '</td>' +
                        '<td data-label="รายการ">' + escapeHtml(itemList) + '</td>' +
                        '<td><button class="btn btn-primary" style="padding:0.4rem 1.2rem; font-size:0.9rem;" onclick="requestReturnConfirm(\'' + escapeHtml(borrowId) + '\')"><i class="fa-solid fa-rotate-left"></i> คืน</button></td>';
                    tbody.appendChild(tr);
                });
            }

            connextCache.swr('unreturned', [roleLevel, userName, site, roleName],
                function (done, fail) {
                    google.script.run.withSuccessHandler(done).withFailureHandler(fail)
                        .getUnreturnedItems(roleLevel, userName, site, roleName);
                },
                function (data) { render(data); },
                function (err) {
                    console.error('Failed to load unreturned items:', err);
                    tbody.innerHTML = '<tr class="draft-empty-row"><td colspan="4" style="color:var(--danger);">โหลดข้อมูลล้มเหลว</td></tr>';
                },
                opts
            );
        }

         
         
         
         
         
         
         
        var _dailyCheckData = null;
        var _dcOpen = {};                                  
        var _dcMonthOpen = {};                             
        var _dcSubOpen = {};                               
        var _dcTicks = {};                                 
        var _DC_TICK_PREFIX = 'connext.dccheck.ticks.';    

        function switchDailyCheckTab(which, btn) {
            hapticTap();
            var checkTab = document.getElementById('dc-tab-check');
            var dashTab  = document.getElementById('dc-tab-dash');
            var rateTab  = document.getElementById('dc-tab-rate');
            if (checkTab) checkTab.style.display = (which === 'check') ? '' : 'none';
            if (dashTab)  dashTab.style.display  = (which === 'dash')  ? '' : 'none';
            if (rateTab)  rateTab.style.display  = (which === 'rate')  ? '' : 'none';
            var cb = document.getElementById('dcTabCheckBtn'), db = document.getElementById('dcTabDashBtn'), rb = document.getElementById('dcTabRateBtn');
            if (cb) cb.classList.toggle('active', which === 'check');
            if (db) db.classList.toggle('active', which === 'dash');
            if (rb) rb.classList.toggle('active', which === 'rate');
            if (which === 'dash') loadChargeDashboard();    
            if (which === 'rate') loadRateCard();
             
        }

         
        function _dcThaiDate(ymd) {
            if (!ymd) return '-';
            var p = ymd.toString().split('-');
            if (p.length !== 3) return ymd;
            var months = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
            var m = parseInt(p[1], 10);
            return parseInt(p[2], 10) + ' ' + (months[m - 1] || p[1]) + ' ' + (parseInt(p[0], 10) + 543);
        }

         
        function _dcDayChipLabel(ymd) {
            var p = (ymd || '').toString().split('-');
            if (p.length !== 3) return ymd;
            var months = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
            return parseInt(p[2], 10) + ' ' + (months[parseInt(p[1], 10) - 1] || p[1]);
        }

        function loadDailyCheck(opts) {
            var body = document.getElementById('dailyCheckBody');
            var site = getEffectiveSiteCode();
            var uname = user ? user.username : '';
            var rname = user ? (user.roleName || user.role || '') : '';
            if (body && !connextCache.get('dailycheck', [site, uname])) {
                body.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-spinner fa-spin"></i><span>กำลังโหลดรายการ...</span></div>';
            }
            connextCache.swr('dailycheck', [site, uname],
                function (done, fail) { google.script.run.withSuccessHandler(done).withFailureHandler(fail).getDailyCheckData(site, uname, rname); },
                function (resp) { _dailyCheckData = resp; renderDailyCheck(resp); },
                function (err) { if (body) body.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-triangle-exclamation"></i><span>โหลดรายการไม่สำเร็จ</span></div>'; console.error('loadDailyCheck', err); },
                opts
            );
        }

         
        function _dcTickKey(it) { return (it.type || '') + '|' + (it.docId || '') + '|' + (it.matCode || '') + '|' + (it.rowIndex != null ? it.rowIndex : ''); }
        function _dcTickStoreKey() { return _DC_TICK_PREFIX + ((user && user.username) ? user.username : 'anon'); }
        function _dcLoadTicks() {
            try { var raw = localStorage.getItem(_dcTickStoreKey()); return raw ? (JSON.parse(raw) || {}) : {}; }
            catch (e) { return {}; }
        }
        function _dcSaveTicks() {
            try { localStorage.setItem(_dcTickStoreKey(), JSON.stringify(_dcTicks)); } catch (e) {}
        }
        function _dcIsTicked(it) { return !!_dcTicks[_dcTickKey(it)]; }
        function _dcDayTickCount(day) {
            var n = 0;
            _dcVisibleItems(day).forEach(function (it) { if (_dcIsTicked(it)) n++; });
            return n;
        }
        function toggleDcItemTick(cb, dayKey) {
            var key = cb.getAttribute('data-key');
            if (cb.checked) _dcTicks[key] = 1; else delete _dcTicks[key];
            _dcSaveTicks();
            hapticTap();
            var row = cb.closest('.dc-row');
            if (row) row.classList.toggle('dc-ticked', cb.checked);
            _dcUpdateTickBadge(dayKey);
        }
        function _dcUpdateTickBadge(dayKey) {
            if (!_dailyCheckData || !_dailyCheckData.days) return;
            var day = _dailyCheckData.days.filter(function (x) { return x.day === dayKey; })[0];
            if (!day) return;
            var el = document.getElementById('dc-tick-' + dayKey);
            if (el) el.innerHTML = '<i class="fa-solid fa-check"></i> ติ๊กแล้ว ' + _dcDayTickCount(day) + '/' + _dcVisibleItems(day).length;
        }

         
        function toggleDcDay(dayKey) {
            var el = document.getElementById('dc-day-' + dayKey);
            if (!el) return;
            var collapsed = el.classList.toggle('collapsed');
            _dcOpen[dayKey] = !collapsed;
            hapticTap();
        }

         
         
        function _dcThaiMonth(ym) {
            var p = (ym || '').toString().split('-');
            var monthsFull = ['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
            var m = parseInt(p[1], 10);
            return (monthsFull[m - 1] || p[1] || '') + ' ' + (parseInt(p[0], 10) + 543);
        }
         
        function _dcGroupByMonth(days) {
            var map = {};
            (days || []).forEach(function (day) {
                var p = (day.day || '').toString().split('-');
                var ym = (p[0] || '') + '-' + (p[1] || '');
                if (!map[ym]) map[ym] = [];
                map[ym].push(day);
            });
            return Object.keys(map).sort().map(function (ym) {
                var inMonth = map[ym].slice().sort(function (a, b) {
                    return a.day < b.day ? -1 : (a.day > b.day ? 1 : 0);
                });
                return { ym: ym, label: _dcThaiMonth(ym), days: inMonth };
            });
        }
        function _dcMonthDomId(key) { return 'dc-month-' + key.replace(/[^a-zA-Z0-9]/g, '_'); }
        function toggleDcMonth(ym) {
            var el = document.getElementById(_dcMonthDomId(ym));
            if (!el) return;
            var collapsed = el.classList.toggle('collapsed');
            _dcMonthOpen[ym] = !collapsed;
            hapticTap();
        }
        function _dcSubDomId(ym, kind) { return 'dc-sub-' + kind + '-' + ym.replace(/[^a-zA-Z0-9]/g, '_'); }
        function toggleDcSub(ym, kind) {
            var el = document.getElementById(_dcSubDomId(ym, kind));
            if (!el) return;
            var collapsed = el.classList.toggle('collapsed');
            _dcSubOpen[ym + ':' + kind] = !collapsed;
            hapticTap();
        }
         
        function _dcSubSection(ym, kind, days) {
            if (!days.length) return '';
            var isConfirmed = (kind === 'c');
            var open = _dcSubOpen.hasOwnProperty(ym + ':' + kind) ? _dcSubOpen[ym + ':' + kind] : !isConfirmed;
            var charge = 0, cards = '';
            days.forEach(function (day, i) { charge += (day.chargedCount || 0); cards += _dcDayCard(day, isConfirmed, i); });
            var label = isConfirmed ? 'ตรวจแล้ว' : 'ยังไม่ได้ตรวจ';
            var icon  = isConfirmed ? 'fa-circle-check' : 'fa-hourglass-half';
            var meta  = days.length + ' วัน' + (charge ? ' · หักเงิน ' + charge : '');
            var head = '<div class="dc-sub-head dc-sub-' + kind + '" onclick="toggleDcSub(\'' + ym + '\',\'' + kind + '\')">' +
                '<i class="fa-solid fa-chevron-down dc-sub-chevron"></i>' +
                '<i class="fa-solid ' + icon + ' dc-sub-ic"></i>' +
                '<span class="dc-sub-title">' + label + '</span>' +
                '<span class="dc-sub-count">' + days.length + '</span>' +
                '<span class="dc-sub-meta">' + meta + '</span>' +
            '</div>';
            return '<div class="dc-sub' + (open ? '' : ' collapsed') + '" id="' + _dcSubDomId(ym, kind) + '">' +
                head +
                '<div class="dc-sub-body">' + cards + '</div>' +
            '</div>';
        }
        function _dcMonthGroup(group) {
            var ym = group.ym;
            var open = _dcMonthOpen.hasOwnProperty(ym) ? _dcMonthOpen[ym] : true;    
            var pending   = group.days.filter(function (x) { return !x.confirmed; });
            var confirmed = group.days.filter(function (x) { return x.confirmed; });
            var dayCount = group.days.length, itemTotal = 0, chargeTotal = 0;
            group.days.forEach(function (day) {
                itemTotal += _dcVisibleItems(day).length;    
                chargeTotal += (day.chargedCount || 0);
            });
            var meta = dayCount + ' วัน · ' + itemTotal + ' รายการ' + (chargeTotal ? ' · หักเงิน ' + chargeTotal : '');
            var exportBtn = chargeTotal
                ? '<button type="button" class="dc-month-export" title="ออกเอกสารหักเงิน (PDF) + จัดการลายเซ็นออนไลน์" onclick="event.stopPropagation(); openDeductExport(\'' + ym + '\')"><i class="fa-solid fa-file-pdf"></i> ออกเอกสารหักเงิน</button>'
                : '';
            var head = '<div class="dc-month-head" onclick="toggleDcMonth(\'' + ym + '\')">' +
                '<i class="fa-solid fa-chevron-down dc-month-chevron"></i>' +
                '<i class="fa-solid fa-calendar-days dc-month-cal"></i>' +
                '<span class="dc-month-title">' + escapeHtml(group.label) + '</span>' +
                '<span class="dc-month-meta">' + meta + '</span>' +
                exportBtn +
            '</div>';
            return '<div class="dc-month' + (open ? '' : ' collapsed') + '" id="' + _dcMonthDomId(ym) + '">' +
                head +
                '<div class="dc-month-body">' +
                    _dcSubSection(ym, 'p', pending) +
                    _dcSubSection(ym, 'c', confirmed) +
                '</div>' +
            '</div>';
        }

        function renderDailyCheck(d) {
            var body = document.getElementById('dailyCheckBody');
            if (!body) return;
            if (!d || !d.success) {
                var msg = (d && d.message === 'no_permission') ? 'คุณไม่มีสิทธิ์ดูหน้านี้' : 'โหลดรายการไม่สำเร็จ';
                body.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-triangle-exclamation"></i><span>' + msg + '</span></div>';
                return;
            }
            _dcTicks = _dcLoadTicks();
            var days = d.days || [];
            if (!days.length) {
                body.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-clipboard-check"></i><span>ยังไม่มีรายการที่รับของแล้วให้ตรวจสอบ</span></div>';
                return;
            }
            var pending = days.filter(function (x) { return !x.confirmed; });
            var confirmed = days.filter(function (x) { return x.confirmed; });

            var html = '<div class="stats-metrics" style="margin-bottom:1rem;">' +
                '<div class="stats-metric"><div class="v">' + pending.length + '</div><div class="l"><i class="fa-solid fa-hourglass-half"></i> วันที่ค้างตรวจ</div></div>' +
                '<div class="stats-metric m3"><div class="v">' + confirmed.length + '</div><div class="l"><i class="fa-solid fa-circle-check"></i> วันที่ตรวจแล้ว</div></div>' +
            '</div>';

            if (!pending.length) {
                html += '<div class="qr-empty" style="padding:1.2rem; margin-bottom:0.85rem;"><i class="fa-solid fa-circle-check" style="color:#16a34a;"></i><span>ตรวจสอบครบทุกวันแล้ว 🎉</span></div>';
            }

             
            html += '<div class="dc-wrap">';
            _dcGroupByMonth(days).forEach(function (g) { html += _dcMonthGroup(g); });
            html += '</div>';

            body.innerHTML = html;
        }

        function _dcDayCard(day, isConfirmed, idx) {
            var dayKey = day.day;
             
            var visible = _dcVisibleItems(day);
            var count = visible.length;
            var tableHtml = visible.length
                ? _dcDayTable(day)
                : '<div class="qr-empty" style="padding:0.8rem;"><span>ไม่มีรายการที่ต้องตรวจ</span></div>';

             
            var defOpen = !isConfirmed && idx === 0;
            var open = _dcOpen.hasOwnProperty(dayKey) ? _dcOpen[dayKey] : defOpen;
            var tickCount = _dcDayTickCount(day);
             
            var badDcCount = 0;
            visible.forEach(function (it) { if (_dcIsDC(it) && it.charge) badDcCount++; });

            var badges = '<span class="dc-badge"><i class="fa-solid fa-list-ul"></i> ' + count + ' รายการ</span>' +
                '<span class="dc-badge dc-badge-charge"><i class="fa-solid fa-coins"></i> หักเงิน <span id="dc-charge-' + dayKey + '">' + (day.chargedCount || 0) + '</span></span>' +
                (badDcCount ? '<span class="dc-badge dc-badge-baddc"><i class="fa-solid fa-triangle-exclamation"></i> DC ต้องแก้ ' + badDcCount + '</span>' : '') +
                '<span class="dc-badge dc-badge-tick" id="dc-tick-' + dayKey + '"><i class="fa-solid fa-check"></i> ติ๊กแล้ว ' + tickCount + '/' + count + '</span>';

             
             
            var headAction = isConfirmed
                ? '<div class="dc-head-actions"><span class="dc-confirmed-badge"><i class="fa-solid fa-circle-check"></i> ตรวจแล้ว' +
                    (day.confirmedBy ? ' · ' + escapeHtml(day.confirmedBy) : '') + (day.confirmedAt ? ' · ' + escapeHtml(day.confirmedAt) : '') + '</span></div>'
                : '';

            var head = '<div class="dc-day-head" onclick="toggleDcDay(\'' + dayKey + '\')">' +
                '<i class="fa-solid fa-chevron-down dc-chevron"></i>' +
                '<div class="dc-head-main">' +
                    '<div class="dc-day-title"><i class="fa-solid fa-calendar-day dc-cal"></i> ' + escapeHtml(_dcThaiDate(dayKey)) + '</div>' +
                    '<div class="dc-badges">' + badges + '</div>' +
                '</div>' +
                headAction +
            '</div>';

             
            var foot = isConfirmed ? '' :
                '<div class="dc-day-foot">' +
                    '<span class="dc-foot-hint"><i class="fa-solid fa-clipboard-check"></i> ตรวจรายการครบแล้วจึงกดยืนยัน</span>' +
                    '<button type="button" class="btn btn-success dc-confirm-btn" onclick="confirmDailyCheckDay(\'' + dayKey + '\')"><i class="fa-solid fa-check-double"></i> ยืนยันตรวจสอบแล้ว</button>' +
                '</div>';

            return '<div class="dc-day' + (isConfirmed ? ' dc-confirmed' : '') + (open ? '' : ' collapsed') + '" id="dc-day-' + dayKey + '">' +
                head +
                '<div class="dc-day-body">' + tableHtml + foot + '</div>' +
            '</div>';
        }

         
         
        function _dcIsDC(it) {
            return (it && it.subName != null ? it.subName.toString().trim() : '').indexOf('DC:') === 0;
        }

         
         
         
        function _dcVisibleItems(day) {
            return (day && day.items ? day.items : []).filter(function (it) {
                return !(_dcIsDC(it) && !it.charge);
            });
        }

         
         
        function _dcGroupItems(items) {
            var NO_SG = '— ไม่ระบุหมวด —', NO_SN = '— ไม่ระบุผู้รับ —';
            var map = {};
            (items || []).forEach(function (it) {
                var sg = (it.subgroup || '').toString().trim(); if (!sg) sg = NO_SG;
                var sn = (it.subName || '').toString().trim();  if (!sn || sn === '-') sn = NO_SN;
                if (!map[sg]) map[sg] = {};
                if (!map[sg][sn]) map[sg][sn] = [];
                map[sg][sn].push(it);
            });
            return Object.keys(map).sort(_dcGroupSort).map(function (sg) {
                var subs = Object.keys(map[sg]).sort(_dcGroupSort).map(function (sn) {
                    return { name: sn, items: map[sg][sn] };
                });
                return { name: sg, subs: subs };
            });
        }
         
        function _dcGroupSort(a, b) {
            var ax = a.charAt(0) === '—' ? 1 : 0, bx = b.charAt(0) === '—' ? 1 : 0;
            if (ax !== bx) return ax - bx;
            return a.localeCompare(b);
        }

         
         
        function _dcBadDcBox(day, list) {
            var rows = list.map(function (it) {
                var typeLabel = (it.type === 'OD') ? 'Odds' : 'เบิก';
                var typeCls = (it.type === 'OD') ? 'dc-type-od' : 'dc-type-rd';
                return '<tr>' +
                    '<td class="dc-td-doc">' + escapeHtml(it.docId || '-') + '</td>' +
                    '<td><span class="dc-type ' + typeCls + '">' + typeLabel + '</span></td>' +
                    '<td class="dc-td-mat">' + escapeHtml(it.matCode || '-') + '</td>' +
                    '<td class="dc-td-name">' + escapeHtml(it.name || '-') + '</td>' +
                    '<td class="dc-bad-recv"><i class="fa-solid fa-user-tag"></i> ' + escapeHtml(it.subName || '-') + '</td>' +
                    '<td class="dc-bad-td-qty"><b>' + escapeHtml((it.qty != null ? it.qty : '-').toString()) + '</b></td>' +
                    '<td class="dc-bad-td-charge">' + _dcChargeToggle(it) + '</td>' +
                '</tr>';
            }).join('');
            return '<div class="dc-bad-box">' +
                '<div class="dc-bad-head">' +
                    '<i class="fa-solid fa-triangle-exclamation dc-bad-ic"></i>' +
                    '<span class="dc-bad-title">DC ที่ต้องแก้เป็น "ไม่หักเงิน"</span>' +
                    '<span class="dc-bad-count">' + list.length + '</span>' +
                    '<span class="dc-bad-hint">รายการของผู้รับแบบ DC ไม่ควรหักเงิน — กดปุ่มทางขวาเพื่อเปลี่ยนเป็น "ไม่หักเงิน"</span>' +
                '</div>' +
                '<div class="dc-bad-table-wrap"><table class="dc-bad-table"><thead><tr>' +
                    '<th>เอกสาร</th><th>ประเภท</th><th>รหัสวัสดุ</th><th>ชื่อวัสดุ</th><th>ผู้รับ (DC)</th>' +
                    '<th class="dc-bad-td-qty">จำนวน</th><th class="dc-bad-td-charge">หักเงิน</th>' +
                '</tr></thead><tbody>' + rows + '</tbody></table></div>' +
            '</div>';
        }

         
        function _dcDayTable(day) {
            var dayKey = day.day;
             
             
             
             
            var badDc = [], normalItems = [];
            (day.items || []).forEach(function (it) {
                if (_dcIsDC(it)) { if (it.charge) badDc.push(it); }
                else normalItems.push(it);
            });
            var box = badDc.length ? _dcBadDcBox(day, badDc) : '';

            var groups = _dcGroupItems(normalItems);
            if (!groups.length) {
                 
                return box || '<div class="qr-empty" style="padding:0.8rem;"><span>ไม่มีรายการ</span></div>';
            }
            var rows = '';
            groups.forEach(function (g, gi) {
                var gTotal = 0, gCharged = 0;
                g.subs.forEach(function (s) {
                    s.items.forEach(function (it) { gTotal++; if (it.charge) gCharged++; });
                });
                rows += '<tr class="dc-grp-row dc-grp-subgroup"><td colspan="7">' +
                        '<i class="fa-solid fa-layer-group dc-grp-icon"></i><b>' + escapeHtml(g.name) + '</b>' +
                        '<span class="dc-grp-count">' + gTotal + ' รายการ · หักเงิน <span class="dc-grp-charge" id="dc-sgc-' + dayKey + '-' + gi + '">' + gCharged + '</span></span>' +
                    '</td></tr>';
                g.subs.forEach(function (s, si) {
                    var sCharged = 0;
                    s.items.forEach(function (it) { if (it.charge) sCharged++; });
                    rows += '<tr class="dc-grp-row dc-grp-subname"><td colspan="7">' +
                            '<span class="dc-subname-name"><i class="fa-solid fa-user dc-grp-icon"></i>' + escapeHtml(s.name) + '</span>' +
                            '<span class="dc-grp-count">' + s.items.length + ' รายการ · หักเงิน <span class="dc-grp-charge" id="dc-snc-' + dayKey + '-' + gi + '-' + si + '">' + sCharged + '</span></span>' +
                        '</td></tr>';
                    s.items.forEach(function (it) { rows += _dcItemRow(it, dayKey); });
                });
            });
            return box + '<div class="dc-table-wrap"><table class="dc-table">' +
                    '<thead><tr>' +
                        '<th class="dc-th-tick" title="ตรวจแล้ว"><i class="fa-solid fa-check"></i></th>' +
                        '<th>เอกสาร</th>' +
                        '<th>ประเภท</th>' +
                        '<th>รหัสวัสดุ</th>' +
                        '<th>ชื่อวัสดุ</th>' +
                        '<th class="dc-th-qty">จำนวน</th>' +
                        '<th class="dc-th-charge">หักเงิน</th>' +
                    '</tr></thead>' +
                    '<tbody>' + rows + '</tbody>' +
                '</table></div>';
        }

        function _dcItemRow(it, dayKey) {
            var ticked = _dcIsTicked(it);
            var typeLabel = (it.type === 'OD') ? 'Odds' : 'เบิก';
            var typeCls = (it.type === 'OD') ? 'dc-type-od' : 'dc-type-rd';
            return '<tr class="dc-row' + (ticked ? ' dc-ticked' : '') + '">' +
                '<td class="dc-td-tick"><input type="checkbox"' + (ticked ? ' checked' : '') +
                    ' data-key="' + escapeHtml(_dcTickKey(it)) + '" title="ทำเครื่องหมายว่าตรวจรายการนี้แล้ว"' +
                    ' onclick="toggleDcItemTick(this,\'' + dayKey + '\')"></td>' +
                '<td class="dc-td-doc">' + escapeHtml(it.docId || '-') + '</td>' +
                '<td><span class="dc-type ' + typeCls + '">' + typeLabel + '</span></td>' +
                '<td class="dc-td-mat">' + escapeHtml(it.matCode || '-') + '</td>' +
                '<td class="dc-td-name">' + escapeHtml(it.name || '-') + '</td>' +
                '<td class="dc-td-qty"><b>' + escapeHtml((it.qty != null ? it.qty : '-').toString()) + '</b></td>' +
                '<td class="dc-td-charge">' + _dcChargeToggle(it) + '</td>' +
            '</tr>';
        }

        function _dcToggleStyle(on) {
            return (on ? 'background:#fef3c7;color:#92400e;border:1px solid #fcd34d;' : 'background:#f1f5f9;color:#64748b;border:1px solid #e2e8f0;') +
                'padding:0.4rem 0.8rem;border-radius:999px;cursor:pointer;font-weight:600;font-size:0.82rem;white-space:nowrap;';
        }
        function _dcChargeToggle(it) {
            var on = !!it.charge;
            var next = on ? 'false' : 'true';
            return '<button type="button" style="' + _dcToggleStyle(on) + '" ' +
                'onclick="toggleDailyCharge(this,\'' + it.type + '\',\'' + it.docId + '\',' + it.rowIndex + ',' + next + ')">' +
                '<i class="fa-solid ' + (on ? 'fa-coins' : 'fa-ban') + '"></i> ' + (on ? 'หักเงิน' : 'ไม่หักเงิน') + '</button>';
        }
        function _applyDcChargeLocal(type, docId, rowIndex, on) {
            if (!_dailyCheckData || !_dailyCheckData.days) return;
            _dailyCheckData.days.forEach(function (day) {
                var c = 0;
                (day.items || []).forEach(function (it) {
                    if (it.type === type && it.docId === docId && it.rowIndex === rowIndex) it.charge = on;
                    if (it.charge) c++;
                });
                day.chargedCount = c;
            });
        }

        function toggleDailyCharge(btn, type, docId, rowIndex, nextVal) {
            if (btn.dataset.busy === '1') return;
            var newOn = (nextVal === true || nextVal === 'true');
            var uname = user ? user.username : '';

             
             
            btn.dataset.busy = '1';
            _applyDcChargeLocal(type, docId, rowIndex, newOn);
            renderDailyCheck(_dailyCheckData);
            hapticTap();
            showToast(newOn ? 'เปลี่ยนเป็น "หักเงิน" เรียบร้อยแล้ว' : 'เปลี่ยนเป็น "ไม่หักเงิน" เรียบร้อยแล้ว', 'success');

            function _revert(msg) {
                _applyDcChargeLocal(type, docId, rowIndex, !newOn);
                renderDailyCheck(_dailyCheckData);
                if (msg) showToast(msg, 'danger');
            }

             
            google.script.run
                .withSuccessHandler(function (resp) {
                    if (resp && resp.success) {
                        if (resp.charge !== newOn) {    
                            _applyDcChargeLocal(type, docId, rowIndex, resp.charge);
                            renderDailyCheck(_dailyCheckData);
                        }
                        connextCache.invalidateMany(['dailycheck', 'chargeDashboard']);
                    } else {
                        _revert('บันทึกไม่สำเร็จ ย้อนค่ากลับแล้ว');
                    }
                })
                .withFailureHandler(function (err) {
                    console.error('toggleDailyCharge', err);
                    _revert('เชื่อมต่อเซิร์ฟเวอร์ไม่สำเร็จ ย้อนค่ากลับแล้ว');
                })
                .setDailyCheckCharge({ type: type, docId: docId, rowIndex: rowIndex, charge: newOn, username: uname });
        }

        function confirmDailyCheckDay(day) {
            var site = getEffectiveSiteCode();
            var uname = user ? user.username : '';
            showConfirmPopup(
                'ยืนยันการตรวจสอบ',
                'ยืนยันว่าได้ตรวจสอบรายการของวันที่ ' + _dcThaiDate(day) + ' ครบถ้วนแล้วใช่หรือไม่?',
                function () {
                    showLoadingPopup('กำลังบันทึก', 'กรุณารอสักครู่');
                    google.script.run
                        .withSuccessHandler(function (resp) {
                            closeAppPopup();
                            if (resp && resp.success) {
                                hapticSuccess();
                                connextCache.invalidateMany(['dailycheck', 'chargeDashboard']);
                                loadDailyCheck({ force: true });
                                showToast('บันทึกการตรวจสอบวันที่ ' + _dcThaiDate(day) + ' แล้ว', 'success');
                            } else {
                                showInfoPopup('ไม่สำเร็จ', (resp && resp.message) || 'บันทึกไม่สำเร็จ', 'danger');
                            }
                        })
                        .withFailureHandler(function (err) { closeAppPopup(); console.error('confirmDailyCheck', err); showInfoPopup('เชื่อมต่อไม่สำเร็จ', 'กรุณาลองอีกครั้ง', 'danger'); })
                        .confirmDailyCheck(site, day, uname);
                },
                'ยืนยันตรวจสอบแล้ว',
                'btn btn-success'
            );
        }

         
         
         
        var _chargeCharts = {};
        var _chargeData = null;
        var _chargeRangeMode = '30';

        function setChargeRange(mode, btn) {
            _chargeRangeMode = mode;
            document.querySelectorAll('#chargeRangeChips .stats-chip').forEach(function (c) { c.classList.remove('active'); });
            if (btn) btn.classList.add('active');
            var custom = document.getElementById('chargeRangeCustom');
            if (custom) custom.style.display = (mode === 'custom') ? 'flex' : 'none';
            if (mode === 'custom') {
                var f = document.getElementById('chargeFrom'), t = document.getElementById('chargeTo');
                if (f && !f.value) { var dd = new Date(); dd.setDate(dd.getDate() - 30); f.value = _statsDateInput(dd); }
                if (t && !t.value) t.value = _statsDateInput(new Date());
                return;
            }
            loadChargeDashboard({ force: true });
        }

        function _chargeComputeRange() {
            var now = new Date();
            var to = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 23, 59, 59, 999);
            var from;
            if (_chargeRangeMode === 'all') return { fromMs: new Date(2020, 0, 1).getTime(), toMs: to.getTime(), label: 'ทั้งหมด' };
            if (_chargeRangeMode === 'month') { from = new Date(now.getFullYear(), now.getMonth(), 1); return { fromMs: from.getTime(), toMs: to.getTime(), label: 'เดือนนี้' }; }
            if (_chargeRangeMode === 'custom') {
                var fv = (document.getElementById('chargeFrom') || {}).value, tv = (document.getElementById('chargeTo') || {}).value;
                from = fv ? new Date(fv + 'T00:00:00') : new Date(now.getFullYear(), now.getMonth(), now.getDate() - 30);
                var toC = tv ? new Date(tv + 'T23:59:59') : to;
                return { fromMs: from.getTime(), toMs: toC.getTime(), label: _statsDateInput(from) + ' – ' + _statsDateInput(toC) };
            }
            var days = parseInt(_chargeRangeMode, 10) || 30;
            from = new Date(now.getFullYear(), now.getMonth(), now.getDate() - (days - 1));
            return { fromMs: from.getTime(), toMs: to.getTime(), label: days + ' วันล่าสุด' };
        }

        function loadChargeDashboard(opts) {
            var body = document.getElementById('chargeDashBody');
            var range = _chargeComputeRange();
            var labelEl = document.getElementById('chargeRangeLabel');
            if (labelEl) labelEl.textContent = '📅 ช่วง: ' + range.label;
            var site = getEffectiveSiteCode();
            var uname = (user && user.username) || '';
            if (body && !connextCache.get('chargeDashboard', [site, range.fromMs, range.toMs])) {
                body.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-spinner fa-spin"></i><span>กำลังโหลดสรุป...</span></div>';
            }
            connextCache.swr('chargeDashboard', [site, range.fromMs, range.toMs],
                function (done, fail) { google.script.run.withSuccessHandler(done).withFailureHandler(fail).getChargeDashboard(site, range.fromMs, range.toMs, uname); },
                function (resp) { _chargeData = resp; renderChargeDashboard(resp); },
                function (err) { if (body) body.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-triangle-exclamation"></i><span>โหลดสรุปไม่สำเร็จ</span></div>'; console.error('loadChargeDashboard', err); },
                opts
            );
        }

        function _chargeTypeCard(t, label, color, bt) {
            bt = bt || {};
            return '<div class="stats-type">' +
                '<div class="t"><span class="dot" style="background:' + color + '"></span>' + label + ' (' + t + ')</div>' +
                '<div class="n">' + (bt.charged || 0) + ' <span class="s">หักเงิน</span></div>' +
                '<div class="s">จาก ' + (bt.items || 0) + ' รายการ</div>' +
            '</div>';
        }

        function renderChargeDashboard(d) {
            var body = document.getElementById('chargeDashBody');
            if (!body) return;
            Object.keys(_chargeCharts).forEach(function (k) { try { _chargeCharts[k].destroy(); } catch (e) {} });
            _chargeCharts = {};
            if (!d || !d.success) { body.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-triangle-exclamation"></i><span>โหลดสรุปไม่สำเร็จ</span></div>'; return; }

            var s = d.summary || {};
            var bt = s.byType || {};
            body.innerHTML =
                '<div class="stats-metrics">' +
                    '<div class="stats-metric"><div class="v">' + (s.totalItems || 0) + '</div><div class="l"><i class="fa-solid fa-list-check"></i> รายการทั้งหมด</div></div>' +
                    '<div class="stats-metric m4"><div class="v" style="color:#92400e;">' + (s.chargedItems || 0) + '</div><div class="l"><i class="fa-solid fa-coins"></i> หักเงิน</div></div>' +
                    '<div class="stats-metric m3"><div class="v">' + (s.notChargedItems || 0) + '</div><div class="l"><i class="fa-solid fa-ban"></i> ไม่หักเงิน</div></div>' +
                    '<div class="stats-metric m2"><div class="v">' + formatBalanceValue(s.chargedQty || 0) + '</div><div class="l"><i class="fa-solid fa-cubes"></i> จำนวนที่หักเงิน</div></div>' +
                '</div>' +
                '<div class="stats-type-grid">' +
                    _chargeTypeCard('RD', 'เบิกหลัก', '#2563eb', bt.RD) +
                    _chargeTypeCard('OD', 'เบ็ดเตล็ด', '#f59e0b', bt.OD) +
                '</div>' +
                '<div class="stats-card"><div class="stats-card-head"><i class="fa-solid fa-chart-line"></i> แนวโน้มหักเงินรายวัน <span class="hint">รายการ/วัน</span></div><div class="stats-chart-wrap"><canvas id="chargeLineChart"></canvas></div></div>' +
                '<div class="stats-card"><div class="stats-card-head"><i class="fa-solid fa-chart-pie"></i> สัดส่วน หักเงิน vs ไม่หักเงิน</div><div class="stats-chart-wrap" style="height:230px;"><canvas id="chargeDoughnut"></canvas></div></div>' +
                '<div class="stats-card"><div class="stats-card-head"><i class="fa-solid fa-user-tie"></i> หักเงินตามผู้รับเหมา (SubName)</div>' +
                    _statsBars(d.bySubName, { valFn: function (it) { return it.charged; }, valLabel: function (it) { return it.charged + ' / ' + it.items + ' รายการ'; }, color: '#f59e0b' }) + '</div>' +
                '<div class="stats-card"><div class="stats-card-head"><i class="fa-solid fa-layer-group"></i> หักเงินตามหมวด (SubgroupName)</div>' +
                    _statsBars(d.bySubgroup, { valFn: function (it) { return it.charged; }, valLabel: function (it) { return it.charged + ' / ' + it.items + ' รายการ'; }, color: '#6366f1' }) + '</div>';

            _renderChargeLineChart(d.timeSeries);
            _renderChargeDoughnut(s);
        }

        function _renderChargeLineChart(ts) {
            var el = document.getElementById('chargeLineChart');
            if (!el || typeof Chart === 'undefined') return;
            ts = ts || { labels: [], charged: [], notCharged: [] };
            _chargeCharts.line = new Chart(el.getContext('2d'), {
                type: 'line',
                data: { labels: ts.labels || [], datasets: [
                    { label: 'หักเงิน', data: ts.charged || [], borderColor: '#f59e0b', backgroundColor: '#f59e0b', tension: 0.3, fill: false, pointRadius: 2, borderWidth: 2 },
                    { label: 'ไม่หักเงิน', data: ts.notCharged || [], borderColor: '#94a3b8', backgroundColor: '#94a3b8', tension: 0.3, fill: false, pointRadius: 2, borderWidth: 2 }
                ] },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
            });
        }

        function _renderChargeDoughnut(s) {
            var el = document.getElementById('chargeDoughnut');
            if (!el || typeof Chart === 'undefined') return;
            _chargeCharts.doughnut = new Chart(el.getContext('2d'), {
                type: 'doughnut',
                data: { labels: ['หักเงิน', 'ไม่หักเงิน'], datasets: [{ data: [s.chargedItems || 0, s.notChargedItems || 0], backgroundColor: ['#f59e0b', '#cbd5e1'], borderWidth: 2, borderColor: '#fff' }] },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
            });
        }

        // [PHP port 2026-10-02] หน้า "สถิติ" เอาออกแล้ว — เหลือ 2 ตัวช่วยที่ Dashboard หักเงิน ยังใช้ (_statsBars ตัดโหมดเจาะลึกออก)
        function _statsDateInput(d) { return d.getFullYear() + '-' + ('0'+(d.getMonth()+1)).slice(-2) + '-' + ('0'+d.getDate()).slice(-2); }

        function _statsBars(list, opts) {
            opts = opts || {};
            if (!list || !list.length) return '<div class="stats-empty">ไม่มีข้อมูลในช่วงนี้</div>';
            var max = 0;
            list.forEach(function (it) { var v = opts.valFn ? opts.valFn(it) : it.qty; if (v > max) max = v; });
            if (max <= 0) max = 1;
            return '<div class="stats-bars">' + list.map(function (it, i) {
                var v = opts.valFn ? opts.valFn(it) : it.qty;
                var pct = Math.max(3, Math.round(v / max * 100));
                var nm = opts.nameFn ? opts.nameFn(it) : it.name;
                var val = opts.valLabel ? opts.valLabel(it) : (formatBalanceValue(v) + (opts.unit || ''));
                var rowAttr = ' class="stats-bar-row"';
                return '<div' + rowAttr + '>' +
                    '<div class="stats-bar-top">' +
                        '<span class="stats-bar-name"><span class="rank">' + (i + 1) + '</span> ' + escapeHtml(nm || '-') + '</span>' +
                        '<span class="stats-bar-val">' + escapeHtml(val) + '</span>' +
                    '</div>' +
                    '<div class="stats-bar-track"><div class="stats-bar-fill" style="width:' + pct + '%' + (opts.color ? ';background:' + opts.color : '') + '"></div></div>' +
                '</div>';
            }).join('') + '</div>';
        }

         
        var _rateData = null;
        var _rateDirty = {};    
        var _rateOnlyUnset = false;    

        function loadRateCard() {
            var body = document.getElementById('rateCardBody');
            if (body) body.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-spinner fa-spin"></i><span>กำลังโหลด...</span></div>';
            var site = (_rateData && _rateData.siteCode) || getEffectiveSiteCode();
            var uname = user ? user.username : '';
            google.script.run
                .withSuccessHandler(function (resp) { _rateData = resp; _rateDirty = {}; renderRateCard(resp); })
                .withFailureHandler(function (err) {
                    if (body) body.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-triangle-exclamation"></i><span>โหลดไม่สำเร็จ</span></div>';
                    console.error('loadRateCard', err);
                })
                .getRateCardData(site, uname);
        }

        function onRateSiteChange() {
            var sel = document.getElementById('rateSiteSelect');
            if (!sel) return;
            if (Object.keys(_rateDirty).length && !confirm('มีราคาที่ยังไม่ได้บันทึก — เปลี่ยน Site แล้วจะไม่บันทึก ดำเนินการต่อหรือไม่?')) {
                if (_rateData) sel.value = _rateData.siteCode;
                return;
            }
            var body = document.getElementById('rateCardBody');
            if (body) body.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-spinner fa-spin"></i><span>กำลังโหลด...</span></div>';
            google.script.run
                .withSuccessHandler(function (resp) { _rateData = resp; _rateDirty = {}; renderRateCard(resp); })
                .withFailureHandler(function (err) { console.error('onRateSiteChange', err); })
                .getRateCardData(sel.value, user ? user.username : '');
        }

        function renderRateCard(d) {
            var body = document.getElementById('rateCardBody');
            if (!body) return;
            if (!d || !d.success) {
                var msg = (d && d.message === 'no_permission') ? 'คุณไม่มีสิทธิ์ดูหน้านี้' : ((d && d.message) || 'โหลดไม่สำเร็จ');
                body.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-triangle-exclamation"></i><span>' + escapeHtml(msg) + '</span></div>';
                return;
            }
            var sel = document.getElementById('rateSiteSelect');
            if (sel) {
                var isR0 = user && user.roleLevel === 'R0';
                sel.innerHTML = (d.sites || []).map(function (s) {
                    return '<option value="' + escapeHtml(s.code) + '"' + (s.code === d.siteCode ? ' selected' : '') + '>' +
                        escapeHtml(s.code + (s.name ? ' · ' + s.name : '')) + '</option>';
                }).join('');
                sel.disabled = !isR0;
            }

            var rates = d.rates || [];
            var pricedCount = rates.filter(function (r) { return r.price !== '' && r.price != null; }).length;
            var unpriced = rates.length - pricedCount;
            var head = '<div class="rate-summary">' +
                '<span><i class="fa-solid fa-location-dot" style="color:var(--primary);"></i> <b>' + escapeHtml(d.siteCode) + '</b>' + (d.siteName ? ' · ' + escapeHtml(d.siteName) : '') + '</span>' +
                '<span class="rate-summary-chip"><i class="fa-solid fa-list"></i> ' + rates.length + ' วัสดุ</span>' +
                '<span class="rate-summary-chip ok"><i class="fa-solid fa-tag"></i> ตั้งราคาแล้ว ' + pricedCount + '</span>' +
                (unpriced > 0 ? '<span class="rate-summary-chip warn"><i class="fa-solid fa-triangle-exclamation"></i> ยังไม่ตั้ง ' + unpriced + '</span>' : '') +
            '</div>';
            var alertBox = unpriced > 0
                ? '<div class="rate-alert"><i class="fa-solid fa-triangle-exclamation"></i><div>ยังตั้งราคาไม่ครบ — เหลืออีก <b>' + unpriced + '</b> รายการที่ยังไม่ได้ตั้งราคา' +
                    '<span class="rate-alert-sub">กรุณาตั้งราคาให้ครบทุกรายการ เพื่อให้การหักเงินคำนวณได้ครบถ้วนถูกต้อง</span></div></div>'
                : '';
             
            alertBox += '<div class="rate-alert" style="background:#eef2f7; border-color:#c7d4e8; color:#33415c;">' +
                '<i class="fa-solid fa-lock" style="color:var(--primary);"></i><div>ราคาที่แก้ที่นี่มีผลกับ<b>ใบที่ยังไม่ออกเอกสาร</b>เท่านั้น' +
                '<span class="rate-alert-sub">ใบที่ออกเลขเอกสาร/ออก PDF ไปแล้ว ระบบล็อกราคาไว้กับใบนั้น — ยอดในใบเดิมและใบที่ผู้รับเหมาเซ็นไปแล้วไม่เปลี่ยนตาม จึงตั้งราคางวดใหม่ให้ต่างจากงวดก่อนได้ · รายการที่ยังไม่ได้ตั้งราคาในใบเก่า จะใช้ราคาที่ตั้งใหม่นี้เมื่อออกใบซ้ำ</span></div></div>';

            if (!rates.length) {
                body.innerHTML = head + '<div class="qr-empty"><i class="fa-solid fa-tags"></i><span>ยังไม่มีวัสดุที่เคยหักเงินใน Site นี้</span></div>';
                _updateRateSaveBtn();
                return;
            }

             
            var NO_SG = '— ไม่ระบุหมวด —';
            var map = {};
            rates.forEach(function (r) {
                var sg = (r.subgroup || '').toString().trim() || NO_SG;
                if (!map[sg]) map[sg] = [];
                map[sg].push(r);
            });
            var groupNames = Object.keys(map).sort(function (a, b) {
                var ax = a === NO_SG ? 1 : 0, bx = b === NO_SG ? 1 : 0;
                if (ax !== bx) return ax - bx;
                return a.localeCompare(b, 'th');
            });

            var tbodies = groupNames.map(function (sg) {
                var list = map[sg];
                var priced = list.filter(function (r) { return r.price !== '' && r.price != null; }).length;
                var grpUnpriced = list.length - priced;
                var grpHead = '<tr class="rate-grp-row" onclick="toggleRateGroup(this)"><td colspan="3">' +
                    '<i class="fa-solid fa-chevron-down rate-grp-chevron"></i>' +
                    '<i class="fa-solid fa-layer-group rate-grp-icon"></i><b>' + escapeHtml(sg) + '</b>' +
                    '<span class="rate-grp-count">' + list.length + ' วัสดุ · ตั้งราคา ' + priced +
                        (grpUnpriced > 0 ? ' · <span style="color:#b91c1c;font-weight:600;">ยังไม่ตั้ง ' + grpUnpriced + '</span>' : '') +
                    '</span></td></tr>';
                var rows = list.map(function (r) {
                    var pr = (r.price === '' || r.price == null) ? '' : r.price;
                    var unset = (pr === '');
                    return '<tr class="rate-row' + (unset ? ' rate-row-unset' : '') + '" data-name="' + escapeHtml((r.name || '').toLowerCase()) + '" data-code="' + escapeHtml((r.matCode || '').toLowerCase()) + '" data-unset="' + (unset ? '1' : '0') + '">' +
                        '<td class="rate-td-code">' + escapeHtml(r.matCode) +
                            (r.chargedCount ? '<span class="rate-charged" title="ถูกหักเงิน ' + r.chargedCount + ' ครั้ง"><i class="fa-solid fa-coins"></i> ' + r.chargedCount + '</span>' : '') + '</td>' +
                        '<td class="rate-td-name">' + escapeHtml(r.name) + '<span class="rate-unit">' + escapeHtml(r.unit || '-') + '</span></td>' +
                        '<td class="rate-td-price"><div class="rate-price-wrap">' +
                            '<input type="number" inputmode="decimal" min="0" step="0.01" class="rate-input form-control" value="' + escapeHtml(pr.toString()) + '" ' +
                            'data-code="' + escapeHtml(r.matCode) + '" placeholder="-" oninput="markRateDirty(this)"><span class="rate-baht">฿</span>' +
                        '</div></td>' +
                    '</tr>';
                }).join('');
                return '<tbody class="rate-group">' + grpHead + rows + '</tbody>';
            }).join('');

            body.innerHTML = alertBox + head +
                '<div class="rate-table-wrap"><table class="rate-table">' +
                '<thead><tr><th>รหัสวัสดุ</th><th>ชื่อวัสดุ · หน่วย</th><th class="rate-th-price">ราคา/หน่วย (บาท)</th></tr></thead>' +
                tbodies + '</table></div>' +
                '<div class="rate-empty-search" id="rateNoMatch" style="display:none;"><i class="fa-solid fa-magnifying-glass"></i> ไม่พบวัสดุที่ค้นหา</div>';

            filterRateCard();
            _updateRateSaveBtn();
        }

         
        function toggleRateGroup(el) {
            var tb = el.closest('.rate-group');
            if (!tb) return;
            tb.classList.toggle('collapsed');
            filterRateCard();
        }

         
        function toggleRateOnlyUnset() {
            _rateOnlyUnset = !_rateOnlyUnset;
            var btn = document.getElementById('rateOnlyUnsetBtn');
            if (btn) btn.classList.toggle('active', _rateOnlyUnset);
            filterRateCard();
        }

        function filterRateCard() {
            var q = ((document.getElementById('rateSearch') || {}).value || '').trim().toLowerCase();
            var expand = !!q || _rateOnlyUnset;    
            var groups = document.querySelectorAll('#rateCardBody .rate-group');
            var totalShown = 0;
            groups.forEach(function (g) {
                var collapsed = g.classList.contains('collapsed');
                var shown = 0;
                g.querySelectorAll('.rate-row').forEach(function (tr) {
                    var match = (!q || (tr.getAttribute('data-name') || '').indexOf(q) !== -1 || (tr.getAttribute('data-code') || '').indexOf(q) !== -1)
                        && (!_rateOnlyUnset || tr.getAttribute('data-unset') === '1');
                    tr.style.display = (match && (!collapsed || expand)) ? '' : 'none';
                    if (match) shown++;
                });
                g.style.display = shown ? '' : 'none';    
                totalShown += shown;
            });
            var no = document.getElementById('rateNoMatch');
            if (no) no.style.display = totalShown === 0 ? '' : 'none';
        }

        function markRateDirty(input) {
            var code = input.getAttribute('data-code');
            if (code) _rateDirty[code] = true;
            var tr = input.closest('.rate-row');
            if (tr) {
                tr.classList.add('rate-dirty');
                var hasVal = (input.value || '').trim() !== '';    
                tr.classList.toggle('rate-row-unset', !hasVal);
                tr.setAttribute('data-unset', hasVal ? '0' : '1');
            }
            _updateRateSaveBtn();
        }

        function _updateRateSaveBtn() {
            var btn = document.getElementById('rateSaveBtn');
            if (!btn) return;
            var n = Object.keys(_rateDirty).length;
            btn.disabled = n === 0;
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> บันทึกราคา' + (n ? ' (' + n + ')' : '');
        }

        function _rateFmt(v) {
            var n = parseFloat(v);
            if (isNaN(n)) return escapeHtml((v == null ? '' : v).toString());
            return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

         
        function saveAllRates() {
            var rates = [], changes = [];
            document.querySelectorAll('#rateCardBody .rate-input').forEach(function (inp) {
                var code = inp.getAttribute('data-code');
                if (!code || !_rateDirty[code]) return;
                var newVal = (inp.value || '').trim();
                rates.push({ matCode: code, price: newVal });
                var orig = null;
                if (_rateData && _rateData.rates) {
                    for (var i = 0; i < _rateData.rates.length; i++) { if (_rateData.rates[i].matCode === code) { orig = _rateData.rates[i]; break; } }
                }
                changes.push({
                    name: orig ? orig.name : code,
                    unit: orig ? orig.unit : '',
                    oldPrice: (orig && orig.price !== '' && orig.price != null) ? orig.price : '',
                    newPrice: newVal
                });
            });
            if (!rates.length) { showToast('ยังไม่มีการแก้ไขราคา', 'danger'); return; }

            var rows = changes.map(function (c) {
                var oldTxt = (c.oldPrice === '') ? '<span class="rcfm-none">—</span>' : _rateFmt(c.oldPrice);
                var newTxt = (c.newPrice === '') ? '<span class="rcfm-clear">ล้างราคา</span>' : '<b>' + _rateFmt(c.newPrice) + ' ฿</b>';
                return '<div class="rcfm-row"><div class="rcfm-name">' + escapeHtml(c.name) +
                    (c.unit ? ' <span class="rcfm-unit">/ ' + escapeHtml(c.unit) + '</span>' : '') + '</div>' +
                    '<div class="rcfm-price">' + oldTxt + ' <i class="fa-solid fa-arrow-right-long rcfm-arrow"></i> ' + newTxt + '</div></div>';
            }).join('');
            var siteTxt = (_rateData && (_rateData.siteCode + (_rateData.siteName ? ' · ' + _rateData.siteName : ''))) || '';
            var msg = '<div style="text-align:left;"><div class="rcfm-head"><i class="fa-solid fa-location-dot"></i> ' + escapeHtml(siteTxt) +
                ' · แก้ไข <b>' + rates.length + '</b> รายการ</div><div class="rcfm-list">' + rows + '</div></div>';

            showAppPopup({
                type: 'warning',
                title: 'ตรวจสอบราคาก่อนบันทึก',
                message: msg,
                buttons: [
                    { text: 'ยกเลิก', className: 'btn btn-secondary', onClick: closeAppPopup },
                    { text: 'ยืนยันบันทึก', className: 'btn btn-primary', onClick: function () { _doSaveRates(rates); } }
                ]
            });
        }

         
        function _doSaveRates(rates) {
            var site = (_rateData && _rateData.siteCode) || getEffectiveSiteCode();
            showLoadingPopup('กำลังบันทึกราคา', 'กำลังอัปโหลดข้อมูล ' + rates.length + ' รายการ\nกรุณารอสักครู่...');
            google.script.run
                .withSuccessHandler(function (resp) {
                    if (resp && resp.success) {
                        _rateDirty = {};
                        document.querySelectorAll('#rateCardBody .rate-dirty').forEach(function (tr) { tr.classList.remove('rate-dirty'); });
                        _updateRateSaveBtn();
                        if (_rateData && _rateData.rates) {    
                            rates.forEach(function (rt) {
                                for (var i = 0; i < _rateData.rates.length; i++) {
                                    if (_rateData.rates[i].matCode === rt.matCode) { _rateData.rates[i].price = (rt.price === '' ? '' : parseFloat(rt.price)); break; }
                                }
                            });
                        }
                        showAppPopup({
                            type: 'success',
                            title: 'บันทึกสำเร็จ',
                            message: 'บันทึกราคา <b>' + (resp.saved || rates.length) + '</b> รายการเรียบร้อยแล้ว',
                            buttons: [{ text: 'ตกลง', className: 'btn btn-primary', onClick: closeAppPopup }]
                        });
                    } else {
                        showInfoPopup('บันทึกไม่สำเร็จ', (resp && resp.message) || 'ลองอีกครั้ง', 'danger');
                    }
                })
                .withFailureHandler(function (err) {
                    showInfoPopup('บันทึกไม่สำเร็จ', (err && err.message) || String(err || ''), 'danger');
                })
                .saveRateCard({ siteCode: site, username: user ? user.username : '', rates: rates });
        }

         
         
         
         
         
         
         
        var _scfgData = null;
        var _scfgFilterMode = 'all';     
        var _scfgOpts = {};              
        var _scfgAllVendors = [];        
        var _scfgMangoTargetTr = null;   
        var _scfgCanEdit = true;         

         
        function _scfgRowOn(tr) {
            var cb = tr.querySelector('input[type="checkbox"]');
            if (cb) return !!cb.checked;
            return (tr.getAttribute('data-orig') || '') === '1';
        }

         
        function _scfgApplyEditMode() {
            ['scfgOnlyOnBtn', 'scfgOnlyOffBtn'].forEach(function (id) {
                var el = document.getElementById(id);
                if (el) el.style.display = '';    
            });
            document.querySelectorAll('#subsettings-page .rate-toolbar .rate-only-btn, #subsettings-page .rate-toolbar .btn').forEach(function (btn) {
                var keep = btn.id === 'scfgOnlyOnBtn' || btn.id === 'scfgOnlyOffBtn';
                if (!keep) btn.style.display = _scfgCanEdit ? '' : 'none';
            });
        }

        function loadScfg() {
            var body = document.getElementById('scfgBody');
            if (body) body.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-spinner fa-spin"></i><span>กำลังโหลด...</span></div>';
            var site = (_scfgData && _scfgData.siteCode) || getEffectiveSiteCode();
            google.script.run
                .withSuccessHandler(function (resp) { _scfgData = resp; renderScfg(resp); })
                .withFailureHandler(function (err) {
                    if (body) body.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-triangle-exclamation"></i><span>โหลดไม่สำเร็จ</span></div>';
                    console.error('loadScfg', err);
                })
                .getSubSettingsData({ siteCode: site, username: user ? user.username : '' });
        }

        function onScfgSiteChange() {
            var sel = document.getElementById('scfgSiteSelect');
            if (!sel) return;
            if (_scfgDirtyCount() > 0 && !confirm('มีการตั้งค่าที่ยังไม่ได้บันทึก — เปลี่ยน Site แล้วจะไม่บันทึก ดำเนินการต่อหรือไม่?')) {
                if (_scfgData) sel.value = _scfgData.siteCode;
                return;
            }
            _scfgData = null;
            var body = document.getElementById('scfgBody');
            if (body) body.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-spinner fa-spin"></i><span>กำลังโหลด...</span></div>';
            google.script.run
                .withSuccessHandler(function (resp) { _scfgData = resp; renderScfg(resp); })
                .withFailureHandler(function (err) { console.error('onScfgSiteChange', err); })
                .getSubSettingsData({ siteCode: sel.value, username: user ? user.username : '' });
        }

        function renderScfg(d) {
            var body = document.getElementById('scfgBody');
            if (!body) return;
            if (!d || !d.success) {
                var msg = (d && d.message === 'no_permission') ? 'คุณไม่มีสิทธิ์ใช้งานเมนูนี้ (เฉพาะผู้ดูแลผู้รับเหมา สิทธิ์ BS)' : ((d && d.message) || 'โหลดไม่สำเร็จ');
                body.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-triangle-exclamation"></i><span>' + escapeHtml(msg) + '</span></div>';
                return;
            }
             
            var sel = document.getElementById('scfgSiteSelect');
            if (sel) {
                sel.innerHTML = (d.sites || []).map(function (s) {
                    return '<option value="' + escapeHtml(s.code) + '"' + (s.code === d.siteCode ? ' selected' : '') + '>' +
                        escapeHtml(s.code + (s.name ? ' · ' + s.name : '')) + '</option>';
                }).join('');
                sel.disabled = !d.isAdmin;
            }
            var subs = d.subs || [];
            _scfgAllVendors = d.allVendors || [];
            _scfgOpts = {};
            subs.forEach(function (s) { _scfgOpts[s.subId] = s.options || []; });
             
            _scfgCanEdit = (d.canEdit !== false);
            _scfgApplyEditMode();

            var html = '<div class="rate-summary" id="scfgSummary"></div>';
            if (!subs.length) {
                html += '<div class="qr-empty"><i class="fa-solid fa-circle-info"></i><span>ไซต์นี้ยังไม่มีรายชื่อผู้รับเหมา — กดปุ่ม "เพิ่มผู้รับเหมา" เพื่อเริ่มต้นได้เลย</span></div>';
                body.innerHTML = html;
                _scfgUpdateSummary();
                return;
            }
            if (!_scfgCanEdit) {
                html += '<div class="rate-alert" style="background:#eef2f7; border-color:#c7d4e8; color:#33415c;"><i class="fa-solid fa-signature" style="color:var(--primary);"></i><div>' +
                        'สิทธิ์ของคุณ (SC) ในหน้านี้ทำได้อย่างเดียวคือ <b>ตั้งลายเซ็นผู้รับเหมา</b> — คลิกช่อง "ลายเซ็นผู้รับเหมา" ของชุดนั้นเพื่อวาด/นำเข้ารูป/ลบ (บันทึกทันที)' +
                        '<span class="rate-alert-sub">ชื่อชุด · การจับคู่ Mango · เปิด-ปิดใช้งาน · เพิ่มผู้รับเหมา เป็นสิทธิ์ของผู้ดูแลผู้รับเหมา (BS) — แสดงให้ดูอย่างเดียว</span>' +
                        '</div></div>';
            } else
            html += '<div class="rate-alert" style="background:#eef2f7; border-color:#c7d4e8; color:#33415c;"><i class="fa-solid fa-circle-info" style="color:var(--primary);"></i><div>' +
                    'สวิตช์เขียนคอลัมน์ <b>Status</b> (เปิด = active, ปิด = inactive) — ชุดที่ <b>ปิดใช้งาน</b> จะหายจาก dropdown ผู้รับในใบเบิก, ไม่ถูกเติมในหน้า "หักค่าใช้จ่ายผู้รับเหมา"/"บันทึกสแกนนิ้ว" และล็อกอินด้วย SubID นั้นไม่ได้ · ชุดที่ยังมี<b>งานค้าง</b> (ยืมไม่คืน / เบิกยังไม่ออกเอกสาร / สแกนนิ้ว-หักคจช.งวดนี้) ระบบจะให้เลือกชุด active รับโอนก่อนจึงปิดได้ · คลิกช่อง MangoVendor เพื่อจับคู่/แก้ไข — ทุกอย่างมีผลจริงเมื่อกด "บันทึกการตั้งค่า" · ช่อง <b>ลายเซ็นผู้รับเหมา</b> ตั้งลายเซ็นผูกกับชุด (บันทึกทันที ไม่ต้องกดบันทึก) เพื่อให้ผู้รับเหมากดยืนยันรับทราบในลิงก์ได้เลย · คลิก<b>ชื่อชุด</b>เพื่อเปลี่ยนชื่อ — ระบบอัปเดตประวัติเดิมให้ตามชื่อใหม่ทั้งหมด (บันทึกทันทีเช่นกัน)' +
                    '</div></div>';
            html += '<div class="rate-table-wrap"><table class="rate-table"><thead><tr>' +
                    '<th style="width:80px;">SubID</th><th>ชื่อชุด (ผู้รับเหมา)</th>' +
                    '<th style="width:280px;">MangoVendor ที่หักเงิน</th>' +
                    '<th style="width:150px;" title="ลายเซ็นที่ผูกกับชุด — ใช้กดยืนยันรับทราบในลิงก์ขอลายเซ็น">ลายเซ็นผู้รับเหมา</th>' +
                    '<th style="text-align:right; width:220px;">ใช้งานในหน้าหักค่าใช้จ่าย</th>' +
                    '</tr></thead><tbody>';
            subs.forEach(function (s) {
                var en = s.enabled !== false;
                 
                 
                 
                var borrowBadge = (s.borrowOpen > 0)
                    ? '<span class="scfg-borrow-badge" title="ชุดนี้มีของยืมที่ยังไม่คืน ' + s.borrowOpen + ' รายการ — ปิดชุดได้ แต่ของยังค้างชื่อชุดนี้ · คืนได้ตามปกติที่หน้ายืม-คืน"><i class="fa-solid fa-box-open"></i> ยืมค้าง ' + s.borrowOpen + '</span>'
                    : '';
                html += '<tr class="rate-row scfg-row' + (en ? '' : ' scfg-row-off') + '"' +
                        ' data-subid="' + escapeHtml(s.subId) + '" data-name="' + escapeHtml(s.subName) + '"' +
                        ' data-orig="' + (en ? '1' : '0') + '"' +
                        ' data-mango-code="' + escapeHtml(s.mangoCode || '') + '" data-mango-name="' + escapeHtml(s.mangoName || '') + '"' +
                        ' data-orig-mango="' + escapeHtml(s.mangoCode || '') + '"' +
                        ' data-hassig="' + (s.hasSignature ? '1' : '0') + '">' +
                    '<td class="rate-td-code">' + escapeHtml(s.subId) + '</td>' +
                     
                    (_scfgCanEdit
                        ? '<td class="rate-td-name"><button type="button" class="scfg-mango-btn scfg-name-btn" onclick="openScfgRename(this)" title="คลิกเพื่อแก้ไขชื่อชุด (ประวัติเดิมจะถูกอัปเดตตามให้)">' +
                              '<span class="scfg-mango-name" style="max-width:220px;">' + escapeHtml(s.subName) + '</span>' +
                              '<i class="fa-solid fa-pen" style="color:#94a3b8; font-size:0.68rem;"></i></button>' + borrowBadge + '</td>'
                        : '<td class="rate-td-name">' + escapeHtml(s.subName) + borrowBadge + '</td>') +
                    (_scfgCanEdit
                        ? '<td><button type="button" class="scfg-mango-btn" onclick="openScfgMango(this)" title="คลิกเพื่อจับคู่ / แก้ไข MangoVendor">' + _scfgMangoLabelHtml(s.mangoCode, s.mangoName) + '</button></td>'
                        : '<td>' + _scfgMangoLabelHtml(s.mangoCode, s.mangoName) + '</td>') +
                    '<td><button type="button" class="scfg-mango-btn scfg-sig-btn" onclick="openScfgSig(this)" title="ตั้ง/แก้ไขลายเซ็นที่ผูกกับชุดนี้">' + _scfgSigLabelHtml(s.hasSignature) + '</button></td>' +
                    '<td style="text-align:right;">' +
                        (_scfgCanEdit
                            ? '<label class="scfg-switch">' +
                                  '<input type="checkbox"' + (en ? ' checked' : '') + ' onchange="_scfgToggle(this)">' +
                                  '<span class="scfg-track"></span>' +
                                  '<span class="scfg-state" style="color:' + (en ? '#16a34a' : '#b91c1c') + ';">' + (en ? 'เปิดใช้งาน' : 'ปิดใช้งาน') + '</span>' +
                              '</label>'
                            : '<span class="scfg-state" style="color:' + (en ? '#16a34a' : '#b91c1c') + ';">' + (en ? 'เปิดใช้งาน' : 'ปิดใช้งาน') + '</span>') +
                    '</td></tr>';
            });
            html += '</tbody></table></div>' +
                    '<div class="rate-empty-search" id="scfgNoMatch" style="display:none;"><i class="fa-solid fa-magnifying-glass"></i> ไม่พบชุดที่ค้นหา</div>';
            body.innerHTML = html;
            filterScfg();
            _scfgUpdateSummary();
        }

         
        var _scfgRenameTr = null;
        function openScfgRename(btn) {
            var tr = btn && btn.closest ? btn.closest('tr') : null;
            if (!tr || !_scfgData) return;
            hapticTap();
            _scfgRenameTr = tr;
            var nm = tr.getAttribute('data-name') || '';
            var sub = document.getElementById('scfgRenameSub');
            if (sub) sub.textContent = 'ชื่อปัจจุบัน: ' + nm + ' · SubID ' + (tr.getAttribute('data-subid') || '');
            var inp = document.getElementById('scfgRenameInput');
            if (inp) inp.value = nm;
            var go = document.getElementById('scfgRenameGo');
            if (go) go.disabled = false;
            var bd = document.getElementById('scfgRenameBackdrop');
            if (bd) bd.classList.add('open');
            setTimeout(function () { if (inp) { try { inp.focus(); inp.select(); } catch (e) {} } }, 80);
        }
        function closeScfgRename() {
            var bd = document.getElementById('scfgRenameBackdrop');
            if (bd) bd.classList.remove('open');
            _scfgRenameTr = null;
        }
        function submitScfgRename() {
            if (!_scfgRenameTr || !_scfgData) return;
            var tr = _scfgRenameTr;
            var oldName = tr.getAttribute('data-name') || '';
            var subId = tr.getAttribute('data-subid') || '';
            var inp = document.getElementById('scfgRenameInput');
            var newName = ((inp && inp.value) || '').toString().trim().replace(/\s+/g, ' ');
            if (!newName) { showToast('กรุณากรอกชื่อชุดใหม่', 'danger'); return; }
            if (newName === oldName) { showToast('ชื่อใหม่เหมือนเดิม', 'info'); return; }
             
            var dup = false;
            document.querySelectorAll('#scfgBody .scfg-row').forEach(function (row) {
                if (row === tr) return;
                if ((row.getAttribute('data-name') || '').trim().toLowerCase() === newName.toLowerCase()) dup = true;
            });
            if (dup) { showInfoPopup('ชื่อซ้ำ', 'ไซต์นี้มีชุดชื่อ <b>' + escapeHtml(newName) + '</b> อยู่แล้ว — ตั้งชื่อซ้ำไม่ได้', 'danger'); return; }

            var go = document.getElementById('scfgRenameGo');
            if (go) go.disabled = true;
            closeScfgRename();
            showLoadingPopup('กำลังเปลี่ยนชื่อชุด', '"' + oldName + '" → "' + newName + '"\nกำลังอัปเดตประวัติทุกรายการ กรุณารอสักครู่...');
            google.script.run
                .withSuccessHandler(function (res) {
                    closeAppPopup();
                    if (!res || !res.success) {
                        showInfoPopup('เปลี่ยนชื่อไม่สำเร็จ', (res && res.message) || 'เกิดข้อผิดพลาด', 'danger');
                        return;
                    }
                    hapticSuccess();
                    showAppPopup({
                        type: 'success', title: 'เปลี่ยนชื่อเรียบร้อย',
                        message: escapeHtml(res.message || ''),
                        buttons: [{ text: 'ตกลง', className: 'btn btn-primary', onClick: closeAppPopup }]
                    });
                    loadScfg();    
                })
                .withFailureHandler(function (err) {
                    closeAppPopup();
                    showInfoPopup('เปลี่ยนชื่อไม่สำเร็จ', (err && err.message) || String(err || ''), 'danger');
                })
                .renameSubcontractor({
                    siteCode: _scfgData.siteCode, subId: subId,
                    newName: newName, username: user ? user.username : ''
                });
        }

         
        var _scfgSigTr = null;    
        function _scfgSigLabelHtml(has) {
            return has
                ? '<span class="scfg-mango-name" style="color:#15803d;"><i class="fa-solid fa-circle-check"></i> ผูกลายเซ็นแล้ว</span>'
                : '<span class="scfg-mango-none">— ยังไม่ผูก —</span>';
        }
        function openScfgSig(btn) {
            var tr = btn && btn.closest ? btn.closest('tr') : null;
            if (!tr || !_scfgData) return;
            hapticTap();
            _scfgSigTr = tr;
            var sub = document.getElementById('scfgSigSub');
            if (sub) sub.textContent = (tr.getAttribute('data-name') || '') + ' · SubID ' + (tr.getAttribute('data-subid') || '');
            var pv = document.getElementById('scfgSigPreview');
            if (pv) pv.innerHTML = '<span class="sig-preview-empty"><i class="fa-solid fa-spinner fa-spin"></i> กำลังโหลด...</span>';
            var del = document.getElementById('scfgSigDelBtn');
            if (del) del.style.display = 'none';
            var bd = document.getElementById('scfgSigBackdrop');
            if (bd) bd.classList.add('open');
            google.script.run
                .withSuccessHandler(function (res) {
                    _scfgSigRenderPreview((res && res.success) ? (res.dataUrl || '') : '');
                })
                .withFailureHandler(function () { _scfgSigRenderPreview(''); })
                .getSubcontractorSignature({
                    siteCode: _scfgData.siteCode,
                    subId: tr.getAttribute('data-subid') || '',
                    username: user ? user.username : ''
                });
        }
        function closeScfgSig() {
            var bd = document.getElementById('scfgSigBackdrop');
            if (bd) bd.classList.remove('open');
            _scfgSigTr = null;
        }
        function _scfgSigRenderPreview(dataUrl) {
            var pv = document.getElementById('scfgSigPreview');
            var del = document.getElementById('scfgSigDelBtn');
            if (pv) {
                pv.innerHTML = dataUrl
                    ? '<img src="' + dataUrl + '" alt="ลายเซ็นผู้รับเหมา">'
                    : '<span class="sig-preview-empty"><i class="fa-solid fa-signature"></i> ยังไม่ได้ผูกลายเซ็นของชุดนี้</span>';
            }
            if (del) del.style.display = dataUrl ? '' : 'none';
        }
        function scfgSigDraw() {
            if (!_scfgSigTr) return;
            openSigPad({ onSave: function (dataUrl) { _scfgSigSave(dataUrl); } });
        }
        function scfgSigImport(input) {
            var file = input && input.files && input.files[0];
            if (input) input.value = '';
            if (!file) return;
            if (!/^image\//.test(file.type)) { showToast('กรุณาเลือกไฟล์รูปภาพ', 'danger'); return; }
            var reader = new FileReader();
            reader.onload = function (ev) {
                var img = new Image();
                img.onload = function () {
                    var dataUrl = _sigNormalizeImage(img);    
                    if (!dataUrl) { showToast('ประมวลผลรูปไม่สำเร็จ ลองรูปอื่น', 'danger'); return; }
                    _scfgSigSave(dataUrl);
                };
                img.onerror = function () { showToast('เปิดรูปไม่สำเร็จ', 'danger'); };
                img.src = ev.target.result;
            };
            reader.readAsDataURL(file);
        }
        function scfgSigDelete() {
            if (!_scfgSigTr) return;
            var nm = _scfgSigTr.getAttribute('data-name') || '';
            showConfirmPopup('ลบลายเซ็นที่ผูกไว้?',
                'ลบลายเซ็นของ <b>' + escapeHtml(nm) + '</b> ออกจากระบบ · ลิงก์ขอลายเซ็นของชุดนี้จะกลับไปเซ็นสดในกรอบเท่านั้น' +
                '<br><br>ใบที่<b>ลงนามไปแล้ว</b>ไม่ได้รับผลกระทบ (ลายเซ็นถูกเก็บไว้กับใบนั้นแล้ว)',
                function () { _scfgSigSave(''); }, 'ลบลายเซ็น', 'btn btn-danger');
        }
         
        function _scfgSigSave(dataUrl) {
            if (!_scfgSigTr || !_scfgData) return;
            var tr = _scfgSigTr;
            var subId = tr.getAttribute('data-subid') || '';
            showLoadingPopup(dataUrl ? 'กำลังบันทึกลายเซ็น' : 'กำลังลบลายเซ็น',
                (tr.getAttribute('data-name') || '') + ' · SubID ' + subId + '\nกรุณารอสักครู่...');
            google.script.run
                .withSuccessHandler(function (res) {
                    closeAppPopup();
                    if (!res || !res.success) {
                        showInfoPopup('บันทึกไม่สำเร็จ', (res && res.message) || 'เกิดข้อผิดพลาด', 'danger');
                        return;
                    }
                    hapticSuccess();
                    tr.setAttribute('data-hassig', dataUrl ? '1' : '0');
                    var btn = tr.querySelector('.scfg-sig-btn');
                    if (btn) btn.innerHTML = _scfgSigLabelHtml(!!dataUrl);
                     
                    if (_scfgData && _scfgData.subs) {
                        _scfgData.subs.forEach(function (s) { if (s.subId === subId) s.hasSignature = !!dataUrl; });
                    }
                    _scfgSigRenderPreview(dataUrl);
                    showToast(dataUrl ? 'ผูกลายเซ็นให้ชุดนี้แล้ว' : 'ลบลายเซ็นที่ผูกไว้แล้ว', 'success');
                })
                .withFailureHandler(function (err) {
                    closeAppPopup();
                    showInfoPopup('บันทึกไม่สำเร็จ', (err && err.message) || 'เกิดข้อผิดพลาด', 'danger');
                })
                .saveSubcontractorSignature({
                    siteCode: _scfgData.siteCode, subId: subId,
                    dataUrl: dataUrl, username: user ? user.username : ''
                });
        }

        function _scfgMangoLabelHtml(code, name) {
            if (!code && !name) return '<span class="scfg-mango-none">— ไม่ระบุ —</span>';
            return '<span class="scfg-mango-name">' + escapeHtml(name || code) + '</span>' +
                   (code ? '<span class="scfg-mango-code">' + escapeHtml(code) + '</span>' : '');
        }

         
        function _scfgRefreshRowDirty(tr) {
            var cb = tr.querySelector('input[type="checkbox"]');
            var stChanged = ((cb && cb.checked) ? '1' : '0') !== tr.getAttribute('data-orig');
            var mChanged = (tr.getAttribute('data-mango-code') || '') !== (tr.getAttribute('data-orig-mango') || '');
            tr.classList.toggle('rate-dirty', stChanged || mChanged);
        }

         
        function _scfgApplyRowState(tr, cb) {
            var en = cb.checked;
            var st = tr.querySelector('.scfg-state');
            if (st) { st.textContent = en ? 'เปิดใช้งาน' : 'ปิดใช้งาน'; st.style.color = en ? '#16a34a' : '#b91c1c'; }
            tr.classList.toggle('scfg-row-off', !en);
            _scfgRefreshRowDirty(tr);
        }

        function _scfgToggle(cb) {
            hapticTap();
            var tr = cb.closest('tr');
            if (!tr) return;
            _scfgApplyRowState(tr, cb);
            _scfgUpdateSummary();
        }

         
         
        function scfgBulkSet(enabled) {
            hapticTap();
            var rows = document.querySelectorAll('#scfgBody .scfg-row');
            var visible = [];
            Array.prototype.forEach.call(rows, function (tr) {
                if (tr.style.display !== 'none') visible.push(tr);
            });
            if (!visible.length) { showToast('ไม่มีชุดที่แสดงอยู่', 'info'); return; }
            if (!confirm((enabled ? 'เปิด' : 'ปิด') + 'ใช้งานผู้รับเหมาทั้งหมดที่แสดงอยู่ ' + visible.length + ' ชุด ?\n(มีผลจริงเมื่อกด "บันทึกการตั้งค่า")')) return;
            visible.forEach(function (tr) {
                var cb = tr.querySelector('input[type="checkbox"]');
                if (!cb) return;
                cb.checked = enabled;
                _scfgApplyRowState(tr, cb);
            });
            _scfgUpdateSummary();
            filterScfg();    
        }

        function _scfgDirtyCount() {
            return document.querySelectorAll('#scfgBody .scfg-row.rate-dirty').length;
        }

        function _scfgUpdateSummary() {
            var rows = document.querySelectorAll('#scfgBody .scfg-row');
            var on = 0, off = 0, mango = 0, sig = 0;
            Array.prototype.forEach.call(rows, function (tr) {
                if (_scfgRowOn(tr)) on++; else off++;
                if ((tr.getAttribute('data-mango-code') || '') !== '') mango++;
                if (tr.getAttribute('data-hassig') === '1') sig++;
            });
            var dirty = _scfgDirtyCount();
            var sum = document.getElementById('scfgSummary');
            if (sum) {
                sum.innerHTML =
                    '<span><i class="fa-solid fa-location-dot" style="color:var(--primary);"></i> <b>' + escapeHtml((_scfgData && _scfgData.siteCode) || '') + '</b>' +
                        ((_scfgData && _scfgData.siteName) ? ' · ' + escapeHtml(_scfgData.siteName) : '') + '</span>' +
                    '<span class="rate-summary-chip">' + rows.length + ' ชุด</span>' +
                    '<span class="rate-summary-chip ok"><i class="fa-solid fa-toggle-on"></i> เปิดใช้งาน ' + on + '</span>' +
                    '<span class="rate-summary-chip warn"><i class="fa-solid fa-toggle-off"></i> ปิดใช้งาน ' + off + '</span>' +
                    '<span class="rate-summary-chip"><i class="fa-solid fa-link"></i> จับคู่ Mango แล้ว ' + mango + '</span>' +
                    '<span class="rate-summary-chip"><i class="fa-solid fa-signature"></i> ผูกลายเซ็นแล้ว ' + sig + '</span>' +
                    (dirty ? '<span class="rate-summary-chip" style="background:#fef3c7; color:#92400e;"><i class="fa-solid fa-pen"></i> แก้ไข ' + dirty + ' รายการ (ยังไม่บันทึก)</span>' : '');
            }
            var saveBtn = document.getElementById('scfgSaveBtn');
            if (saveBtn) saveBtn.disabled = dirty === 0;
        }

         
        function scfgSetFilter(mode) {
            hapticTap();
            _scfgFilterMode = (_scfgFilterMode === mode) ? 'all' : mode;
            var onBtn = document.getElementById('scfgOnlyOnBtn');
            var offBtn = document.getElementById('scfgOnlyOffBtn');
            if (onBtn) onBtn.classList.toggle('active', _scfgFilterMode === 'on');
            if (offBtn) offBtn.classList.toggle('active', _scfgFilterMode === 'off');
            filterScfg();
        }

        function filterScfg() {
            var q = ((document.getElementById('scfgSearch') || {}).value || '').toString().trim().toLowerCase();
            var rows = document.querySelectorAll('#scfgBody .scfg-row');
            var visible = 0;
            Array.prototype.forEach.call(rows, function (tr) {
                var hay = ((tr.getAttribute('data-subid') || '') + ' ' + (tr.getAttribute('data-name') || '') + ' ' +
                           (tr.getAttribute('data-mango-code') || '') + ' ' + (tr.getAttribute('data-mango-name') || '')).toLowerCase();
                var isOn = _scfgRowOn(tr);
                var stOk = _scfgFilterMode === 'all' || (_scfgFilterMode === 'on' ? isOn : !isOn);
                var show = (!q || hay.indexOf(q) !== -1) && stOk;
                tr.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            var nm = document.getElementById('scfgNoMatch');
            if (nm) nm.style.display = (rows.length && !visible) ? '' : 'none';
        }

         
        function openScfgMango(btn) {
            var tr = btn && btn.closest ? btn.closest('tr') : null;
            if (!tr) return;
            hapticTap();
            _scfgMangoTargetTr = tr;
            var subId = tr.getAttribute('data-subid') || '';
            var curCode = tr.getAttribute('data-mango-code') || '';
            var sub = document.getElementById('scfgMangoSub');
            if (sub) sub.textContent = (tr.getAttribute('data-name') || '') + ' · SubID ' + subId +
                (curCode ? ' · ใช้อยู่: ' + (tr.getAttribute('data-mango-name') || curCode) : ' · ยังไม่ได้จับคู่');
            var wrap = document.getElementById('scfgMangoOptsWrap');
            if (wrap) wrap.style.display = '';
            _scfgRenderMangoOpts(subId);
            var sc = document.getElementById('scfgMangoSearch');
            if (sc) sc.value = '';
            _scfgMangoSearchRender();
             
            var hist = document.getElementById('scfgMangoHist');
            if (hist) { hist.className = 'scfg-mp-note'; hist.innerHTML = 'กด "ดูประวัติ" เพื่อแสดงรายการเปลี่ยนชื่อย้อนหลังของชุดนี้'; }
            var bd = document.getElementById('scfgMangoBackdrop');
            if (bd) bd.classList.add('open');
        }
         
        function _scfgRenderMangoOpts(subId) {
            var tr = _scfgMangoTargetTr;
            var curCode = tr ? (tr.getAttribute('data-mango-code') || '') : '';
            var opts = _scfgOpts[subId] || [];
            var list = document.getElementById('scfgMangoOpts');
            if (!list) return;
            list.innerHTML = opts.length
                ? opts.map(function (o) {
                    return '<div class="scfg-mp-row">' + _scfgMpItemHtml(o.code, o.name, o.code === curCode) +
                        '<button type="button" class="scfg-mp-delbtn" title="ลบออกจากตัวเลือก (ไม่กระทบชื่อที่ใช้อยู่)"' +
                        ' data-code="' + escapeHtml(o.code || '') + '" onclick="_scfgRemoveOption(this)"><i class="fa-solid fa-trash"></i></button></div>';
                }).join('')
                : '<div class="scfg-mp-note">— ชุดนี้ยังไม่มีตัวเลือก — ค้นหาด้านล่าง แล้วกดชื่อเพื่อใช้เลย หรือกด <b>+</b> เพื่อเก็บเป็นตัวเลือกไว้ก่อน</div>';
        }
         
        function _scfgRemoveOption(btn) {
            var tr = _scfgMangoTargetTr;
            if (!tr || !_scfgData) return;
            var subId = tr.getAttribute('data-subid') || '';
            var code = btn.getAttribute('data-code') || '';
            if (!subId || !code) return;
            var opt = (_scfgOpts[subId] || []).filter(function (o) { return o.code === code; })[0];
            if (!confirm('ลบ "' + ((opt && opt.name) || code) + '" ออกจากตัวเลือกของชุดนี้ ?\n(ไม่กระทบชื่อ payment ที่ใช้อยู่)')) return;
            btn.disabled = true;
            google.script.run
                .withSuccessHandler(function (res) {
                    btn.disabled = false;
                    if (!res || !res.success) { showToast((res && res.message) || 'ลบไม่สำเร็จ', 'danger'); return; }
                    _scfgOpts[subId] = (_scfgOpts[subId] || []).filter(function (o) { return o.code !== code; });
                    _scfgRenderMangoOpts(subId);
                    hapticTap();
                    showToast('ลบออกจากตัวเลือกแล้ว', 'success');
                })
                .withFailureHandler(function (err) { btn.disabled = false; showToast((err && err.message) || 'ลบไม่สำเร็จ', 'danger'); })
                .removeSubMangoOption({ siteCode: _scfgData.siteCode, subId: subId, code: code, username: user ? user.username : '' });
        }
         
        function _scfgAddOption(btn) {
            var tr = _scfgMangoTargetTr;
            if (!tr || !_scfgData) return;
            var subId = tr.getAttribute('data-subid') || '';
            var code = btn.getAttribute('data-code') || '';
            if (!subId || !code) return;
            btn.disabled = true;
            google.script.run
                .withSuccessHandler(function (res) {
                    btn.disabled = false;
                    if (!res || !res.success) { showToast((res && res.message) || 'เพิ่มไม่สำเร็จ', 'danger'); return; }
                     
                    var opts = (_scfgOpts[subId] = _scfgOpts[subId] || []);
                    if (!opts.some(function (o) { return o.code === res.code; })) opts.push({ code: res.code, name: res.name });
                    _scfgRenderMangoOpts(subId);
                    if (res.duplicated) { showToast('ชุดนี้มีตัวเลือกนี้อยู่แล้ว', 'info'); }
                    else { hapticSuccess(); showToast('เก็บ "' + (res.name || res.code) + '" เข้าตัวเลือกแล้ว', 'success'); }
                })
                .withFailureHandler(function (err) { btn.disabled = false; showToast((err && err.message) || 'เพิ่มไม่สำเร็จ', 'danger'); })
                .addSubMangoOption({ siteCode: _scfgData.siteCode, subId: subId, code: code, username: user ? user.username : '' });
        }
         
        function _scfgMangoLoadHist() {
            var tr = _scfgMangoTargetTr;
            var box = document.getElementById('scfgMangoHist');
            if (!tr || !_scfgData || !box) return;
            box.className = '';
            box.innerHTML = '<div class="scfg-mp-note"><i class="fa-solid fa-spinner fa-spin"></i> กำลังโหลดประวัติ...</div>';
            google.script.run
                .withSuccessHandler(function (res) {
                    if (!res || !res.success) { box.innerHTML = '<div class="scfg-mp-note">โหลดประวัติไม่สำเร็จ' + ((res && res.message) ? ' — ' + escapeHtml(res.message) : '') + '</div>'; return; }
                    if (!res.list || !res.list.length) { box.innerHTML = '<div class="scfg-mp-note">ยังไม่มีประวัติของชุดนี้</div>'; return; }
                    var ACT = {
                        'add-option':    { t: 'เพิ่มตัวเลือก',   c: '#2563eb', ic: 'fa-plus' },
                        'remove-option': { t: 'ลบตัวเลือก',     c: '#b91c1c', ic: 'fa-trash' },
                        'set-payment':   { t: 'เลือกใช้',       c: '#16a34a', ic: 'fa-circle-check' },
                        'clear-payment': { t: 'ล้างการจับคู่',  c: '#d97706', ic: 'fa-eraser' }
                    };
                    box.innerHTML = res.list.map(function (x) {
                        var when = x.at ? new Date(x.at).toLocaleString('th-TH', { dateStyle: 'medium', timeStyle: 'short' }) : '-';
                        var a = ACT[x.action] || { t: (x.action || 'เปลี่ยน'), c: '#64748b', ic: 'fa-clock-rotate-left' };
                         
                        var subj = (x.action === 'remove-option' || x.action === 'clear-payment')
                            ? (x.fromName || x.fromCode) : (x.toName || x.toCode);
                        var detail = '<b>' + escapeHtml(subj || '— ไม่ระบุ —') + '</b>';
                        if (x.action === 'set-payment' && (x.fromName || x.fromCode)) {
                            detail += ' <span style="color:#94a3b8;">← เดิม ' + escapeHtml(x.fromName || x.fromCode) + '</span>';
                        }
                        return '<div class="scfg-hist-item">' +
                               '<span class="scfg-hist-tag" style="color:' + a.c + '; border-color:' + a.c + '33;"><i class="fa-solid ' + a.ic + '"></i> ' + a.t + '</span> ' + detail +
                               '<span style="display:block; font-size:0.7rem; color:#94a3b8;">' + escapeHtml(when) + ' · โดย ' + escapeHtml(x.by || '-') + '</span></div>';
                    }).join('');
                })
                .withFailureHandler(function (err) { box.innerHTML = '<div class="scfg-mp-note">โหลดประวัติไม่สำเร็จ — ' + escapeHtml((err && err.message) || '') + '</div>'; })
                .getSubMangoHistory({ siteCode: _scfgData.siteCode, subId: tr.getAttribute('data-subid') || '', username: user ? user.username : '' });
        }
        function closeScfgMango() {
            var bd = document.getElementById('scfgMangoBackdrop');
            if (bd) bd.classList.remove('open');
            _scfgMangoTargetTr = null;
        }
        function _scfgMpItemHtml(code, name, current) {
            return '<button type="button" class="scfg-mp-item' + (current ? ' current' : '') + '"' +
                ' data-code="' + escapeHtml(code || '') + '" data-name="' + escapeHtml(name || '') + '"' +
                ' onclick="_scfgPickFromEl(this)">' +
                '<span class="mp-code">' + escapeHtml(code || '-') + '</span>' +
                '<span class="mp-name">' + escapeHtml(name || code || '-') + '</span>' +
                (current ? '<i class="fa-solid fa-circle-check" style="color:#16a34a; margin-left:auto;"></i>' : '') +
                '</button>';
        }
         
        function _scfgMangoSearchRender() {
            var box = document.getElementById('scfgMangoResults');
            if (!box) return;
            var term = ((document.getElementById('scfgMangoSearch') || {}).value || '').toString().trim().toLowerCase();
            var tr = _scfgMangoTargetTr;
            var curCode = tr ? (tr.getAttribute('data-mango-code') || '') : '';
            var matched = [];
            for (var i = 0; i < _scfgAllVendors.length; i++) {
                var v = _scfgAllVendors[i];
                if (!term || ((v.code || '') + ' ' + (v.name || '')).toLowerCase().indexOf(term) !== -1) matched.push(v);
                if (matched.length >= 500) break;    
            }
            var top = matched.slice(0, 50);
            box.innerHTML = top.map(function (v) {
                return '<div class="scfg-mp-row">' + _scfgMpItemHtml(v.code, v.name, v.code === curCode) +
                    '<button type="button" class="scfg-mp-addbtn" title="เก็บเข้าตัวเลือกของชุดนี้ (ตั้งไว้ได้หลายชื่อ — ไม่เปลี่ยนชื่อที่ใช้อยู่)"' +
                    ' data-code="' + escapeHtml(v.code || '') + '" onclick="_scfgAddOption(this)"><i class="fa-solid fa-plus"></i></button></div>';
            }).join('') +
                (matched.length > top.length ? '<div class="scfg-mp-note">แสดง ' + top.length + ' รายการแรก — พิมพ์เพิ่มเพื่อจำกัดผลค้นหา</div>' : (top.length ? '' : '<div class="scfg-mp-note">ไม่พบ vendor ที่ค้นหา</div>'));
        }
        function _scfgPickFromEl(el) {
            _scfgPickMango(el.getAttribute('data-code') || '', el.getAttribute('data-name') || '');
        }
         
        function _scfgPickMango(code, name) {
            var tr = _scfgMangoTargetTr;
            if (!tr || !_scfgData) { closeScfgMango(); return; }
            code = code || '';
            var prevCode = tr.getAttribute('data-mango-code') || '';
            if (code === prevCode) {    
                showToast(code ? 'ชุดนี้ใช้ชื่อ payment นี้อยู่แล้ว' : 'ยังไม่มีการจับคู่ให้ล้าง', 'info');
                closeScfgMango();
                return;
            }
            showLoadingPopup(code ? 'กำลังตั้งชื่อ payment' : 'กำลังล้างการจับคู่', tr.getAttribute('data-name') || '');
            google.script.run
                .withSuccessHandler(function (res) {
                    closeAppPopup();
                    if (!res || !res.success) { showToast((res && res.message) || 'ไม่สำเร็จ', 'danger'); return; }
                     
                    tr.setAttribute('data-mango-code', res.code || '');
                    tr.setAttribute('data-mango-name', res.name || '');
                    tr.setAttribute('data-orig-mango', res.code || '');
                    var mbtn = tr.querySelector('.scfg-mango-btn');
                    if (mbtn) mbtn.innerHTML = _scfgMangoLabelHtml(res.code, res.name);
                    _scfgRefreshRowDirty(tr);
                    _scfgUpdateSummary();
                    hapticSuccess();
                    showToast(res.code ? ('ตั้งชื่อ payment เป็น "' + (res.name || res.code) + '" แล้ว') : 'ล้างการจับคู่แล้ว', 'success');
                    closeScfgMango();
                })
                .withFailureHandler(function (err) { closeAppPopup(); showToast((err && err.message) || 'ไม่สำเร็จ', 'danger'); })
                .setSubMango({ siteCode: _scfgData.siteCode, subId: tr.getAttribute('data-subid') || '', code: code, username: user ? user.username : '' });
        }

         
        var _scfgAddMango = null;    

        function openScfgAdd() {
            if (!_scfgData) { showToast('กรุณารอหน้าโหลดเสร็จก่อน', 'danger'); return; }
            if (_scfgDirtyCount() > 0) {
                showInfoPopup('บันทึกการตั้งค่าก่อน', 'มีการแก้ไขที่ยังไม่ได้บันทึก — กด "บันทึกการตั้งค่า" ก่อนเพิ่มผู้รับเหมาใหม่ (หลังเพิ่มเสร็จ รายการจะโหลดใหม่ทั้งหน้า)', 'info');
                return;
            }
            ['scfgAddName', 'scfgAddId', 'scfgAddPassword', 'scfgAddMangoSearch'].forEach(function (id) {
                var el = document.getElementById(id);
                if (el) el.value = '';
            });
            _scfgAddMangoClear();
            var sub = document.getElementById('scfgAddSub');
            if (sub) sub.textContent = 'Site ' + _scfgData.siteCode + (_scfgData.siteName ? ' (' + _scfgData.siteName + ')' : '');
            var bd = document.getElementById('scfgAddBackdrop');
            if (bd) bd.classList.add('open');
            var nameEl = document.getElementById('scfgAddName');
            if (nameEl) setTimeout(function () { try { nameEl.focus(); } catch (e) {} }, 150);
        }
        function closeScfgAdd() {
            var bd = document.getElementById('scfgAddBackdrop');
            if (bd) bd.classList.remove('open');
        }

         
        function _scfgAddMangoRender() {
            var box = document.getElementById('scfgAddMangoResults');
            if (!box) return;
            var term = ((document.getElementById('scfgAddMangoSearch') || {}).value || '').toString().trim().toLowerCase();
            if (!term) {
                box.innerHTML = '<div class="scfg-mp-note">พิมพ์รหัสหรือชื่อ vendor เพื่อค้นหา (' + _scfgAllVendors.length + ' รายการ)</div>';
                return;
            }
            var matched = [];
            for (var i = 0; i < _scfgAllVendors.length; i++) {
                var v = _scfgAllVendors[i];
                if (((v.code || '') + ' ' + (v.name || '')).toLowerCase().indexOf(term) !== -1) matched.push(v);
                if (matched.length >= 200) break;
            }
            var top = matched.slice(0, 8);
            box.innerHTML = top.map(function (v) {
                return '<button type="button" class="scfg-mp-item" data-code="' + escapeHtml(v.code || '') + '" data-name="' + escapeHtml(v.name || '') + '" onclick="_scfgAddMangoPick(this)">' +
                    '<span class="mp-code">' + escapeHtml(v.code || '-') + '</span>' +
                    '<span class="mp-name">' + escapeHtml(v.name || v.code || '-') + '</span>' +
                    '</button>';
            }).join('') +
            (matched.length > top.length ? '<div class="scfg-mp-note">แสดง ' + top.length + ' รายการแรก — พิมพ์เพิ่มเพื่อจำกัดผลค้นหา</div>' : (top.length ? '' : '<div class="scfg-mp-note">ไม่พบ vendor ที่ค้นหา</div>'));
        }
        function _scfgAddMangoPick(el) {
            hapticTap();
            _scfgAddMango = { code: el.getAttribute('data-code') || '', name: el.getAttribute('data-name') || '' };
            var chip = document.getElementById('scfgAddMangoSel');
            if (chip) {
                chip.style.display = '';
                chip.innerHTML = '<i class="fa-solid fa-circle-check" style="color:#16a34a;"></i> ' +
                    '<span class="mp-name">' + escapeHtml(_scfgAddMango.name || _scfgAddMango.code) + '</span>' +
                    '<span class="mp-code">' + escapeHtml(_scfgAddMango.code) + '</span>' +
                    '<button type="button" class="scfg-addmango-x" onclick="_scfgAddMangoClear()" title="ล้างการจับคู่">&times;</button>';
            }
            var sc = document.getElementById('scfgAddMangoSearch');
            if (sc) { sc.value = ''; sc.style.display = 'none'; }
            var box = document.getElementById('scfgAddMangoResults');
            if (box) box.innerHTML = '';
        }
        function _scfgAddMangoClear() {
            _scfgAddMango = null;
            var chip = document.getElementById('scfgAddMangoSel');
            if (chip) { chip.style.display = 'none'; chip.innerHTML = ''; }
            var sc = document.getElementById('scfgAddMangoSearch');
            if (sc) sc.style.display = '';
            _scfgAddMangoRender();
        }
        function submitScfgAdd() {
            if (!_scfgData) return;
            var name = ((document.getElementById('scfgAddName') || {}).value || '').toString().trim();
            if (!name) { showToast('กรุณาระบุชื่อชุด (ผู้รับเหมา)', 'danger'); return; }
            var goBtn = document.getElementById('scfgAddGo');
            if (goBtn) goBtn.disabled = true;
            showLoadingPopup('กำลังเพิ่มผู้รับเหมา', name + '\nกรุณารอสักครู่...');
            google.script.run
                .withSuccessHandler(function (res) {
                    closeAppPopup();
                    if (goBtn) goBtn.disabled = false;
                    if (!res || !res.success) {
                        showInfoPopup('เพิ่มไม่สำเร็จ', (res && res.message) || 'ไม่สามารถเพิ่มผู้รับเหมาได้', 'danger');
                        return;
                    }
                    closeScfgAdd();
                    showToast('เพิ่ม "' + res.subName + '" แล้ว (SubID ' + res.subId + ')' +
                        (res.mangoCode ? ' · จับคู่ ' + (res.mangoName || res.mangoCode) : ''), 'success');
                     
                    loadScfg();
                    if (typeof _seDirty === 'undefined' || !_seDirty) {
                        pageLoadState.subexpense = false;
                        _seData = null;
                    }
                })
                .withFailureHandler(function (err) {
                    closeAppPopup();
                    if (goBtn) goBtn.disabled = false;
                    showInfoPopup('เพิ่มไม่สำเร็จ', (err && err.message) ? err.message : String(err || 'unknown'), 'danger');
                })
                .addSubcontractor({
                    siteCode: _scfgData.siteCode,
                    subName: name,
                    subId: ((document.getElementById('scfgAddId') || {}).value || '').toString().trim(),
                    password: ((document.getElementById('scfgAddPassword') || {}).value || '').toString().trim(),
                    mangoCode: _scfgAddMango ? _scfgAddMango.code : '',
                    username: user ? user.username : ''
                });
        }

         
        function saveScfg() {
            if (!_scfgData) return;
            var rows = document.querySelectorAll('#scfgBody .scfg-row');
            if (!rows.length) return;
            var dirty = _scfgDirtyCount();
            if (!dirty) { showToast('ไม่มีการแก้ไข', 'info'); return; }
            if (!confirm('บันทึกการตั้งค่าผู้รับเหมา ' + dirty + ' รายการที่แก้ไข ?')) return;
            var selections = [];
            Array.prototype.forEach.call(rows, function (tr) {
                var cb = tr.querySelector('input[type="checkbox"]');
                selections.push({
                    subId: tr.getAttribute('data-subid'),
                    enabled: !!(cb && cb.checked)
                     
                });
            });
            var saveBtn = document.getElementById('scfgSaveBtn');
            if (saveBtn) saveBtn.disabled = true;
            showLoadingPopup('กำลังบันทึกการตั้งค่า', 'Site ' + _scfgData.siteCode + ' · ' + selections.length + ' ชุด\nกรุณารอสักครู่...');
            google.script.run
                .withSuccessHandler(function (res) {
                    closeAppPopup();
                    if (!res || !res.success) {
                        if (saveBtn) saveBtn.disabled = false;
                        var msg = (res && res.message === 'no_permission') ? 'คุณไม่มีสิทธิ์บันทึกการตั้งค่านี้' : ((res && res.message) || 'บันทึกไม่สำเร็จ');
                        showInfoPopup('บันทึกไม่สำเร็จ', msg, 'danger');
                        return;
                    }
                     
                     
                    if (res.blocked && res.blocked.length) {
                        showToast('บันทึกแล้ว ' + res.updated + ' รายการ — มี ' + res.blocked.length + ' ชุดที่มีงานค้าง เลือกวิธีปิดก่อน', 'info');
                        openScfgRemap(res.blocked);
                        return;
                    }
                     
                    Array.prototype.forEach.call(rows, function (tr) {
                        var cb = tr.querySelector('input[type="checkbox"]');
                        tr.setAttribute('data-orig', (cb && cb.checked) ? '1' : '0');
                        tr.setAttribute('data-orig-mango', tr.getAttribute('data-mango-code') || '');
                        tr.classList.remove('rate-dirty');
                    });
                    _scfgUpdateSummary();
                    showToast('บันทึกการตั้งค่าแล้ว ' + res.updated + ' รายการ', 'success');
                     
                     
                    if (typeof _seDirty === 'undefined' || !_seDirty) {
                        pageLoadState.subexpense = false;
                        _seData = null;
                    }
                })
                .withFailureHandler(function (err) {
                    closeAppPopup();
                    if (saveBtn) saveBtn.disabled = false;
                    showInfoPopup('บันทึกไม่สำเร็จ', (err && err.message) ? err.message : String(err || 'unknown'), 'danger');
                })
                .saveSubSettings({
                    siteCode: _scfgData.siteCode,
                    selections: selections,
                    username: user ? user.username : ''
                });
        }

         
         
         
         
         
         
        var _fsData = null;         
        var _fsSum = null;          
        var _fsAlert = null;        
        var _fsDash = null;         
        var _fsAlertMonth = '';     
        var _fsAlertHalf = '';      
        var _fsAlertSub = '';       
        var _fsAlertCollapsed = {}; 
        var _fsSaveRows = null;     
        var _fsDirty = false;
        var _fsSignRec = null;      
        var _fsSub = 'daily';       
        var _fsBadgeState = { record: 0, verify: 0, alert: 0, pendv: 0 };    

         
        function fsSubTab(which) {
            if (which === _fsSub) return;
             
            if (_fsSub === 'daily' && _fsDirty && !confirm('มีข้อมูลที่ยังไม่ได้บันทึก — สลับแท็บแล้วการแก้ไขจะหายไป ดำเนินการต่อหรือไม่?')) return;
             
            if ((which === 'summary' || which === 'alerts' || which === 'dash') && !_canSeeFingerScan()) return;
            _fsDirty = false;
            _fsShowSub(which);
        }
         
         
        function _fsShowSub(which, force) {
            if (which !== 'daily' && !_canSeeFingerScan()) which = 'daily';    
            _fsSub = which;
            var map = { daily: 'fsSecDaily', summary: 'fsSecSummary', alerts: 'fsSecAlerts', dash: 'fsSecDash' };
            var btn = { daily: 'fsSubBtnDaily', summary: 'fsSubBtnSummary', alerts: 'fsSubBtnAlerts', dash: 'fsSubBtnDash' };
            ['daily', 'summary', 'alerts', 'dash'].forEach(function (s) {
                var sec = document.getElementById(map[s]); if (sec) sec.hidden = s !== which;
                var b = document.getElementById(btn[s]); if (b) b.classList.toggle('active', s === which);
            });
             
            var canSee = _canSeeFingerScan(), bs = _canSeeBsPages();
            document.querySelectorAll('#fingerscan-page .fs-bs-sub').forEach(function (el) { el.hidden = !canSee; });
            document.querySelectorAll('#fingerscan-page .fs-bs-only').forEach(function (el) { el.hidden = !bs; });
            var titles = {
                daily:   ['บันทึกสแกนนิ้วแรงงานผู้รับเหมา', 'กรอกจำนวนลงงานและจำนวนแสกนนิ้วของแต่ละชุดต่อวัน — ระบบคิดอัตรา% และค่าปรับให้อัตโนมัติ · ผู้ดูแล (BS) ตรวจสอบและลงลายเซ็นยืนยันรายวัน'],
                summary: ['สรุปงวดสแกนนิ้ว', 'สรุปค่าปรับต่อชุดของงวดครึ่งเดือน — พิมพ์เอกสาร PDF และสร้างลิงก์ให้ผู้รับเหมาเซ็นรับทราบ'],
                alerts:  ['ชี้แจงแสกนเกินลงงาน', 'เคสที่จำนวนแสกนนิ้วมากกว่าจำนวนลงงาน — บันทึกสาเหตุเพื่อเก็บเป็นหลักฐาน'],
                dash:    ['แดชบอร์ดสแกนนิ้ว', 'ภาพรวมทั้งเดือน — การบันทึก/ตรวจลงนามรายวัน ค่าปรับ อัตราแสกน การเซ็นรับทราบ และเคสแสกนเกิน']
            };
            var t = document.getElementById('fsPageTitle'), st = document.getElementById('fsPageSubtitle');
            if (t) t.textContent = titles[which][0];
            if (st) st.textContent = titles[which][1];
             
             
            if (which === 'daily') {
                if (!force && _fsData && !_fsDirty) renderFingerScan(_fsData); else loadFingerScanPage();
            } else if (which === 'summary') {
                if (!force && _fsSum) renderFsSummary(_fsSum); else loadFsSummary();
            } else if (which === 'dash') {
                if (!force && _fsDash) renderFsDash(_fsDash); else loadFsDash();
            } else {
                if (!force && _fsAlert) renderFsAlerts(_fsAlert); else loadFsAlerts();
            }
        }
         
        function fsReloadSub(force) { _fsShowSub(_fsSub, force); }

         
        function _fsRefreshBadges() {
             
             
            var daily = _fsBadgeState.pendv || 0;
            var alert = _fsBadgeState.alert;
             
            _setBadge('navLinkBadgeFingerScan', daily);
            _setBadge('navBadgeFingerScan', daily);
            _setBadge('fsSubBadgeDaily', daily);
            _setBadge('fsSubBadgeAlert', alert);
        }
        function _fsUpdateAlertBadge(n) { _fsBadgeState.alert = parseInt(n, 10) || 0; _fsRefreshBadges(); }
        function _fsUpdateVerifyBadge(resp) {
            if (!resp) return;
            if (resp.notRecordedDays !== undefined) _fsBadgeState.record = parseInt(resp.notRecordedDays, 10) || 0;
            if (resp.unverifiedDays !== undefined) _fsBadgeState.verify = parseInt(resp.unverifiedDays, 10) || 0;
            if (resp.pendingVerifyDays !== undefined) _fsBadgeState.pendv = parseInt(resp.pendingVerifyDays, 10) || 0;
            _fsRefreshBadges();
        }

        function _fsNum(v) {
            if (v === null || v === undefined || v === '') return '';
            var n = parseFloat(String(v).replace(/,/g, ''));
            return isNaN(n) ? '' : n;
        }
        function _fsFmt(n) {
            if (n === '' || n === null || n === undefined || isNaN(n)) return '';
            return Number(n).toLocaleString('en-US', { maximumFractionDigits: 2 });
        }
        function _fsTodayStr() {
            var d = new Date();
            return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
        }
        var _FS_TH_MON = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
        function _fsThDate(key) {
            var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec((key || '').toString());
            if (!m) return (key || '').toString();
            return parseInt(m[3], 10) + ' ' + _FS_TH_MON[parseInt(m[2], 10) - 1] + ' ' + ((parseInt(m[1], 10) + 543) % 100);
        }
         
         
        function _fsRowEngaged(tr) {
            var s = _fsNum((tr.querySelector('[data-f="scan"]') || {}).value);
            var sys = _fsNum((tr.querySelector('[data-f="system"]') || {}).value);
            var nr = !!(tr.querySelector('[data-f="noReport"]') || {}).checked;
            var note = ((tr.querySelector('[data-f="note"]') || {}).value || '').toString().trim();
            var w = _fsNum((tr.querySelector('[data-f="worker"]') || {}).value);
            var prefill = tr.getAttribute('data-prefill') || '';
            var workerTouched = (w !== '' && String(w) !== prefill);
            return (s !== '' || sys !== '' || nr || note !== '' || workerTouched);
        }
        function _fsSpin(msg) {
            return '<div class="qr-empty"><i class="fa-solid fa-spinner fa-spin"></i><span>' + (msg || 'กำลังโหลด...') + '</span></div>';
        }
        function _fsErr(d) {
            var msg = (d && d.message === 'no_permission') ? 'คุณไม่มีสิทธิ์ใช้งานเมนูนี้ (เฉพาะผู้ดูแลผู้รับเหมา สิทธิ์ BS)' : ((d && d.message) || 'โหลดไม่สำเร็จ');
            return '<div class="qr-empty"><i class="fa-solid fa-triangle-exclamation"></i><span>' + escapeHtml(msg) + '</span></div>';
        }

         
         
         
         
         
        function _fsMangoLine(rec, quiet) {
            if (!rec || !rec.mangoName) {
                if (quiet) return '';
                return '<span class="fs-mango-line fs-mango-none" title="จับคู่ได้ที่เมนู ตั้งค่า → ตั้งค่าผู้รับเหมา">' +
                       '<i class="fa-solid fa-link-slash"></i> ยังไม่จับคู่ Mango</span>';
            }
            return '<span class="fs-mango-line" title="ชื่อที่ใช้เบิกจ่าย (Mango Vendor)">' +
                   '<i class="fa-solid fa-file-invoice-dollar"></i> ' + escapeHtml(rec.mangoName) +
                   (rec.mangoCode ? '<span class="fs-mango-code">' + escapeHtml(rec.mangoCode) + '</span>' : '') +
                   '</span>';
        }
         
        function _fsMangoText(rec) {
            return (rec && rec.mangoName) ? (rec.subName + ' — ' + rec.mangoName) : ((rec && rec.subName) || '');
        }

        function loadFingerScanPage() {
            var body = document.getElementById('fsBody');
            if (body) body.innerHTML = _fsSpin('กำลังโหลดข้อมูล...');
            var sEl = document.getElementById('fsSiteSelect');
            var dEl = document.getElementById('fsDate');
            var site = (sEl && sEl.value) ? sEl.value : ((_fsData && _fsData.siteCode) || getEffectiveSiteCode());
            google.script.run
                .withSuccessHandler(renderFingerScan)
                .withFailureHandler(function (err) {
                    if (body) body.innerHTML = _fsErr({ message: (err && err.message) || String(err || '') });
                })
                .getFingerScanData({ siteCode: site, date: (dEl && dEl.value) || '', username: user ? user.username : '' });
        }

        function onFsSiteChange() {
            if (_fsDirty && !confirm('มีข้อมูลที่ยังไม่ได้บันทึก — เปลี่ยน Site แล้วการแก้ไขจะหายไป ดำเนินการต่อหรือไม่?')) {
                var sEl = document.getElementById('fsSiteSelect');
                if (sEl && _fsData) sEl.value = _fsData.siteCode;
                return;
            }
            _fsDirty = false;
            loadFingerScanPage();
        }
        function onFsDateChange() {
            if (_fsDirty && !confirm('มีข้อมูลที่ยังไม่ได้บันทึก — เปลี่ยนวันแล้วการแก้ไขจะหายไป ดำเนินการต่อหรือไม่?')) {
                var dEl = document.getElementById('fsDate');
                if (dEl && _fsData) dEl.value = _fsData.date;
                return;
            }
            _fsDirty = false;
            loadFingerScanPage();
        }
         
        function fsShiftDay(delta) {
            var dEl = document.getElementById('fsDate');
            if (!dEl || !dEl.value) return;
            if (_fsDirty && !confirm('มีข้อมูลที่ยังไม่ได้บันทึก — เปลี่ยนวันแล้วการแก้ไขจะหายไป ดำเนินการต่อหรือไม่?')) return;
            var d = new Date(dEl.value + 'T00:00:00');
            d.setDate(d.getDate() + delta);
            var next = d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
            if (next > _fsTodayStr()) return;    
            dEl.value = next; _fsDirty = false; loadFingerScanPage();
        }
        function fsGoToday() {
            var dEl = document.getElementById('fsDate');
            if (_fsDirty && !confirm('มีข้อมูลที่ยังไม่ได้บันทึก — เปลี่ยนวันแล้วการแก้ไขจะหายไป ดำเนินการต่อหรือไม่?')) return;
            if (dEl) dEl.value = _fsTodayStr();
            _fsDirty = false; loadFingerScanPage();
        }
         
        function onFsMonthPick() {
            var mEl = document.getElementById('fsMonthPick');
            if (!mEl || !/^\d{4}-\d{2}$/.test(mEl.value || '')) return;
            var today = _fsTodayStr();
            var curYm = _fsData ? _fsData.date.slice(0, 7) : today.slice(0, 7);
            if (mEl.value === curYm) return;    
            if (_fsDirty && !confirm('มีข้อมูลที่ยังไม่ได้บันทึก — เปลี่ยนเดือนแล้วการแก้ไขจะหายไป ดำเนินการต่อหรือไม่?')) {
                mEl.value = curYm;
                return;
            }
            if (mEl.value > today.slice(0, 7)) { showToast('เลือกได้ไม่เกินเดือนปัจจุบัน', 'info'); mEl.value = curYm; return; }
            var target;
            if (mEl.value === today.slice(0, 7)) target = today;
            else {
                var y = parseInt(mEl.value.slice(0, 4), 10), mo = parseInt(mEl.value.slice(5, 7), 10);
                target = mEl.value + '-' + ('0' + new Date(y, mo, 0).getDate()).slice(-2);
            }
            var dEl = document.getElementById('fsDate');
            if (dEl) dEl.value = target;
            _fsDirty = false; loadFingerScanPage();
        }
         
        function fsJumpDay(dk) {
            if (dk > _fsTodayStr()) return;
            var dEl = document.getElementById('fsDate');
            if (_fsDirty && !confirm('มีข้อมูลที่ยังไม่ได้บันทึก — เปลี่ยนวันแล้วการแก้ไขจะหายไป ดำเนินการต่อหรือไม่?')) return;
            if (dEl) dEl.value = dk;
            _fsDirty = false; loadFingerScanPage();
        }


        function renderFingerScan(resp) {
            var body = document.getElementById('fsBody');
            if (!body) return;
            if (!resp || !resp.success) { body.innerHTML = _fsErr(resp); return; }
            _fsData = resp;
            _fsDirty = false;
             
            var saveBtn = document.getElementById('fsSaveBtn');
            if (saveBtn) saveBtn.disabled = false;

            var sField = document.getElementById('fsSiteField');
            var sEl = document.getElementById('fsSiteSelect');
            if (sEl) {
                sEl.innerHTML = (resp.sites || []).map(function (s) {
                    return '<option value="' + escapeHtml(s.code) + '">' + escapeHtml(s.code + (s.name ? ' — ' + s.name : '')) + '</option>';
                }).join('');
                sEl.value = resp.siteCode;
            }
            if (sField) sField.style.display = resp.isAdmin ? '' : 'none';
            var dEl = document.getElementById('fsDate');
            if (dEl) { dEl.value = resp.date; dEl.max = _fsTodayStr(); }
            var mPick = document.getElementById('fsMonthPick');
            if (mPick) { mPick.value = resp.date.slice(0, 7); mPick.max = _fsTodayStr().slice(0, 7); }
            var nextBtn = document.getElementById('fsNextBtn');
            if (nextBtn) nextBtn.disabled = resp.date >= _fsTodayStr();
            var rl = document.getElementById('fsRateLabel');
            if (rl) rl.innerHTML = '<i class="fa-solid fa-money-bill-wave" style="color:#64748b;"></i> อัตราปรับ ' + resp.rate + ' บาท/คน/วัน';
             
            document.querySelectorAll('#fingerscan-page .fs-bs-only').forEach(function (el) { el.hidden = !resp.canBS; });
             
            _fsRenderDayStrip(resp);
            _fsRenderVerifyCard(resp);
            _fsUpdateVerifyBadge(resp);
            _fsUpdateAlertBadge(resp.alertPending);

            var byId = {};
            (resp.rows || []).forEach(function (r) { byId[r.subId] = r; });
            var prevW = resp.prevWorkerBySubId || {};    
            var subs = resp.subs || [];
            if (!subs.length) {
                body.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-circle-info"></i><span>ไซต์นี้ยังไม่มีชุดผู้รับเหมาที่เปิดใช้งาน — เปิด/เพิ่มชุดได้ที่หน้า "ตั้งค่า"</span></div>';
                return;
            }
             
            var closedRows = resp.closedRows || [];
            var savedCount = (resp.rows || []).length - closedRows.length;    
            var prevNote = resp.prevWorkerDate
                ? ' · ช่อง <b>ลงงาน</b> เติมค่าเริ่มต้นจากวันที่ ' + _fsThDate(resp.prevWorkerDate) + ' ให้ (พื้นเหลือง) — แก้ได้ · ' +
                  'ถ้ายอดเท่าวันก่อน กดปุ่ม <b>"ใช้ค่าเริ่มต้นทั้งหมด"</b> ใต้ตารางแล้วบันทึกได้เลย · ชุดที่ไม่ลงงานวันนี้ลบเลขออกให้ว่างไว้'
                : '';
            var html = '<div class="rate-alert" style="background:#eef2f7; border-color:#c7d4e8; color:#33415c;"><i class="fa-solid fa-circle-info" style="color:var(--primary);"></i><div>' +
                'กรอกเฉพาะชุดที่ลงงานวันนี้ — <b>ลงงาน</b> + <b>แสกน</b> แล้วระบบคิดอัตรา/ค่าปรับให้เอง (ปรับ = คนไม่แสกน × ' + resp.rate + ' บาท) · ' +
                'ชุดที่ไม่รายงานยอด ให้ติ๊ก <b>"ไม่แจ้งยอด"</b> แล้วกรอก <b>แรงงานในระบบ</b> (ปรับ = แรงงานในระบบ × ' + resp.rate + ' บาท — ติ๊กแล้วช่อง <b>ลงงาน/แสกน</b> จะปิดอัตโนมัติ) · ' +
                'ค่าปรับคำนวณอัตโนมัติ แก้มือไม่ได้ · แถวว่าง = ชุดไม่ลงงาน ไม่ถูกบันทึก' +
                (savedCount ? ' · วันนี้บันทึกไว้แล้ว <b>' + savedCount + '</b> ชุด (แก้แล้วกดบันทึกซ้ำได้)' : '') +
                prevNote +
                '</div></div>';
            html += '<div class="rate-table-wrap"><table class="rate-table"><thead><tr>' +
                '<th>ชุดผู้รับเหมา</th>' +
                '<th style="width:90px; text-align:center;">ลงงาน (คน)</th>' +
                '<th style="width:90px; text-align:center;">แสกน (คน)</th>' +
                '<th style="width:150px; text-align:center;">อัตรา / สถานะ</th>' +
                '<th style="width:80px; text-align:center;" title="ชุดไม่รายงานยอดแรงงาน — ปรับตามจำนวนแรงงานผู้รับเหมาในระบบ">ไม่แจ้งยอด</th>' +
                '<th style="width:100px; text-align:center;" title="จำนวนแรงงานผู้รับเหมาในระบบ — ฐานคิดค่าปรับของเคสไม่แจ้งยอด">แรงงาน<br>ในระบบ</th>' +
                '<th style="width:100px; text-align:center;">ค่าปรับ (บาท)</th>' +
                '<th>หมายเหตุ</th>' +
                '</tr></thead><tbody id="fsTbody">';
            var nPrefill = 0;    
            subs.forEach(function (s) {
                var d = byId[s.subId];
                var todaySaved = !!d;
                d = d || {};
                var hasWorker = !(d.workerCount === undefined || d.workerCount === '');
                 
                var pw = (!todaySaved && !d.noReport && prevW[s.subId] !== undefined && prevW[s.subId] !== '') ? prevW[s.subId] : '';
                var isPrefill = !hasWorker && pw !== '';
                if (isPrefill) nPrefill++;
                var wShown = hasWorker ? d.workerCount : (isPrefill ? pw : '');
                html += '<tr data-subid="' + escapeHtml(s.subId) + '" data-name="' + escapeHtml(s.subName) + '" data-fine="0" data-prefill="' + (isPrefill ? escapeHtml(String(pw)) : '') + '">' +
                    '<td class="rate-td-name">' + escapeHtml(s.subName) + _fsMangoLine(s, true) +
                    '<span style="display:block; font-size:0.7rem; color:#94a3b8;">SubID ' + escapeHtml(s.subId) + (d.recordedBy ? ' · บันทึกแล้ว' : '') + '</span></td>' +
                    '<td><input type="number" class="form-control fs-in' + (isPrefill ? ' fs-prev' : '') + '" data-f="worker" min="0" step="1" inputmode="numeric" style="text-align:center; padding:0.35rem;" value="' + wShown + '" oninput="fsRowCalc(this)"' + (d.noReport ? ' disabled' : '') + (isPrefill ? ' title="ค่าเริ่มต้นจากวันก่อน — แก้ได้"' : '') + '></td>' +
                    '<td><input type="number" class="form-control fs-in" data-f="scan" min="0" step="1" inputmode="numeric" style="text-align:center; padding:0.35rem;" value="' + (d.scanCount === undefined || d.scanCount === '' ? '' : d.scanCount) + '" oninput="fsRowCalc(this)"' + (d.noReport ? ' disabled' : '') + '></td>' +
                    '<td class="fs-status" style="text-align:center; font-size:0.78rem;">—</td>' +
                    '<td style="text-align:center;"><input type="checkbox" data-f="noReport"' + (d.noReport ? ' checked' : '') + ' onchange="fsNoReportToggle(this)"></td>' +
                    '<td><input type="number" class="form-control fs-in" data-f="system" min="0" step="1" inputmode="numeric" style="text-align:center; padding:0.35rem;" value="' + (d.systemWorkerCount === undefined || d.systemWorkerCount === '' ? '' : d.systemWorkerCount) + '" oninput="fsRowCalc(this)" title="จำนวนแรงงานผู้รับเหมาในระบบ"></td>' +
                    '<td class="fs-fine-cell" style="text-align:center; font-weight:700;">—</td>' +
                    '<td><input type="text" class="form-control" data-f="note" value="' + escapeHtml(d.note || '') + '" placeholder="เช่น หยุดงาน" style="padding:0.35rem;" oninput="_fsMarkDirty()"></td>' +
                    '</tr>';
            });
            html += '</tbody></table></div>' +
                    (nPrefill
                        ? '<button type="button" class="fs-tablebtn" style="border-color:#fbbf24; background:#fffbeb; color:#92400e;" ' +
                          'onclick="fsConfirmAllPrefill()" title="ใช้ยอดลงงานเท่าวันก่อนทั้งหมด ไม่ต้องพิมพ์ทับเอง">' +
                          '<i class="fa-solid fa-check-double"></i> ใช้ค่าเริ่มต้นทั้งหมด (' + nPrefill + ' ชุด)</button>'
                        : '') +
                     
                     
                    (closedRows.length ? _fsClosedTableHtml(closedRows) : '') +
                    '<div class="se-export-hint" id="fsDayTotal" style="margin-top:0.5rem;"></div>';
            body.innerHTML = html;
             
            document.querySelectorAll('#fsTbody tr').forEach(function (tr) { _fsCalcRow(tr, false); });
            _fsUpdateDayTotal();
        }

         
         
        function _fsClosedTableHtml(rows) {
            var body = rows.map(function (r) {
                var w = (r.workerCount === '' || r.workerCount === undefined) ? '—' : _fsFmt(r.workerCount);
                var s = (r.scanCount === '' || r.scanCount === undefined) ? '—' : _fsFmt(r.scanCount);
                var detail = r.noReport
                    ? 'ไม่แจ้งยอด' + (r.systemWorkerCount !== '' && r.systemWorkerCount !== undefined ? ' · ในระบบ ' + _fsFmt(r.systemWorkerCount) + ' คน' : '')
                    : 'ลงงาน ' + w + ' · แสกน ' + s;
                var fine = (r.fineAmt === '' || r.fineAmt === undefined) ? '—' : _fsFmt(r.fineAmt);
                return '<tr>' +
                    '<td class="rate-td-name" style="color:#64748b;">' + escapeHtml(r.subName || ('SubID ' + r.subId)) +
                        '<span style="display:block; font-size:0.7rem; color:#94a3b8;">SubID ' + escapeHtml(r.subId) + '</span></td>' +
                    '<td style="color:#475569;">' + escapeHtml(detail) + (r.note ? ' · ' + escapeHtml(r.note) : '') + '</td>' +
                    '<td style="text-align:center; font-weight:700; color:#475569;">' + fine + '</td>' +
                    '</tr>';
            }).join('');
            return '<div style="margin-top:0.7rem; border:1px solid #e2e8f0; border-radius:0.6rem; background:#f8fafc; padding:0.6rem 0.7rem;">' +
                '<div style="font-size:0.8rem; font-weight:700; color:#475569; margin-bottom:0.4rem;">' +
                    '<i class="fa-solid fa-lock" style="color:#94a3b8;"></i> ชุดที่ปิดใช้งานแล้ว ' + rows.length + ' ชุด — ข้อมูลของวันนี้ยังถูกเก็บไว้ (แก้ไขไม่ได้)</div>' +
                '<div style="font-size:0.73rem; color:#64748b; margin-bottom:0.5rem;">ยอดเหล่านี้ยังนับรวมในสรุปงวดและค่าปรับตามปกติ · กดบันทึกรายวันซ้ำไม่ลบทิ้ง · เปิดชุดกลับที่หน้า "ตั้งค่า" ถ้าต้องแก้</div>' +
                '<div class="rate-table-wrap"><table class="rate-table"><thead><tr>' +
                    '<th>ชุดผู้รับเหมา</th><th>ข้อมูลที่บันทึกไว้</th><th style="width:100px; text-align:center;">ค่าปรับ (บาท)</th>' +
                    '</tr></thead><tbody>' + body + '</tbody></table></div></div>';
        }

         
         
        function _fsBuildLineSummary() {
            var trs = document.querySelectorAll('#fsTbody tr');
            var siteName = _fsData ? (_fsData.siteName ? (_fsData.siteCode + ' — ' + _fsData.siteName) : _fsData.siteCode) : '';
            var dateTh = _fsData ? _fsThDate(_fsData.date) : '';
            var items = [], totW = 0, totS = 0, nCrew = 0;
            trs.forEach(function (tr) {
                if (typeof _fsRowEngaged === 'function' && !_fsRowEngaged(tr)) return;    
                var name = tr.getAttribute('data-name') || ('SubID ' + (tr.getAttribute('data-subid') || ''));
                var w   = _fsNum((tr.querySelector('[data-f="worker"]') || {}).value);
                var s   = _fsNum((tr.querySelector('[data-f="scan"]') || {}).value);
                var sys = _fsNum((tr.querySelector('[data-f="system"]') || {}).value);
                var nr  = !!(tr.querySelector('[data-f="noReport"]') || {}).checked;
                var note = ((tr.querySelector('[data-f="note"]') || {}).value || '').toString().trim();
                var detail;
                if (nr) {
                    detail = 'ไม่แจ้งยอด' + (sys !== '' ? ' · ในระบบ ' + _fsFmt(sys) + ' คน' : '');
                } else if ((w !== '' && w > 0) || (s !== '' && s > 0)) {
                    var wv = (w === '' ? 0 : w), sv = (s === '' ? 0 : s);
                    totW += wv; totS += sv;
                    detail = 'ลงงาน ' + _fsFmt(wv) + ' · แสกน ' + _fsFmt(sv);
                    if (sv < wv) detail += ' (ขาด ' + _fsFmt(wv - sv) + ')';
                } else {
                    detail = note || 'ไม่ลงงาน';
                    note = '';
                }
                if (note) detail += ' · ' + note;
                nCrew++;
                items.push(nCrew + '. ' + name + ' — ' + detail);
            });
            var head = '📋 สรุปสแกนนิ้วรายวัน';
            if (siteName) head += '\n📍 ' + siteName;
            head += '\n📅 ' + dateTh;
            if (!items.length) return head + '\n\n— ยังไม่มีชุดที่ลงงานในวันนี้ —';
            var foot = 'รวม ' + nCrew + ' ชุด · ลงงาน ' + _fsFmt(totW) + ' คน · แสกน ' + _fsFmt(totS) + ' คน';
            return head + '\n\n' + items.join('\n') + '\n\n' + foot;
        }
        function fsOpenLineSummary() {
            if (!_fsData) { showToast('กรุณารอข้อมูลโหลดเสร็จก่อน', 'info'); return; }
            var ta = document.getElementById('fsLineText');
            if (ta) ta.value = _fsBuildLineSummary();
            var sub = document.getElementById('fsLineSub');
            if (sub) sub.textContent = 'วันที่ ' + _fsThDate(_fsData.date) + ' · สรุปจากข้อมูลบนหน้าจอ — แก้ไขได้ก่อนคัดลอก';
            var bd = document.getElementById('fsLineBackdrop');
            if (bd) bd.classList.add('open');
        }
        function closeFsLine() {
            var bd = document.getElementById('fsLineBackdrop');
            if (bd) bd.classList.remove('open');
        }
        function fsCopyLineText() {
            var ta = document.getElementById('fsLineText');
            var txt = ta ? ta.value : '';
            if (!txt) { showToast('ไม่มีข้อความให้คัดลอก', 'info'); return; }
            _esCopyText(txt, function (ok) {
                if (ok) { hapticSuccess(); showToast('คัดลอกข้อความแล้ว — วางในไลน์ได้เลย', 'success'); }
                else { showToast('คัดลอกไม่สำเร็จ — กดค้างที่ข้อความเพื่อคัดลอกเอง', 'danger'); }
            });
        }

         
        function _fsRenderDayStrip(resp) {
            var el = document.getElementById('fsDayStrip');
            if (!el) return;
             
            var days = resp.monthDays || resp.periodDays || [];
            if (!days.length) { el.innerHTML = ''; return; }
            var chips = days.map(function (x) {
                var dnum = parseInt(x.d.slice(8, 10), 10);
                var half = (dnum === 16) ? '<span class="fs-strip-half"></span>' : '';    
                var cls = 'fs-daychip dc-' + (x.isFuture ? 'future' : x.status);
                if (x.d === resp.date) cls += ' dc-sel';
                if (x.isToday) cls += ' dc-today';
                var sub = x.isFuture ? '' : (x.status === 'verified' ? '<i class="fa-solid fa-check"></i>' :
                          (x.status === 'recorded' ? x.crews + ' ชุด' : '—'));
                var onclick = x.isFuture ? '' : ' onclick="fsJumpDay(\'' + x.d + '\')"';
                return half + '<div class="' + cls + '"' + onclick + ' title="' + _fsThDate(x.d) + '">' + dnum + '<small>' + sub + '</small></div>';
            }).join('');
            var legend = '<span class="fs-strip-legend">' +
                '<span><b style="background:#f0fdf4;border:1px solid #bbf7d0;"></b>ตรวจแล้ว</span>' +
                '<span><b style="background:#fff7ed;border:1px solid #fed7aa;"></b>บันทึกแล้ว·รอตรวจ</span>' +
                '<span><b style="background:#f1f5f9;border:1px solid #e2e8f0;"></b>ยังไม่บันทึก</span></span>';
            el.innerHTML = chips + ' ' + legend;
        }

         
        function _fsRenderVerifyCard(resp) {
            var el = document.getElementById('fsVerifyCard');
            if (!el) return;
            var v = resp.dayVerify;
            var dateTh = _fsThDate(resp.date);
            if (v) {
                 
                var when = v.at ? new Date(v.at).toLocaleString('th-TH', { dateStyle: 'medium', timeStyle: 'short' }) : '';
                el.innerHTML = '<div class="fs-vcard v-done">' +
                    '<div class="fs-vcard-head"><i class="fa-solid fa-circle-check"></i> ตรวจสอบและลงนามยืนยันแล้ว — ' + dateTh + '</div>' +
                    '<div class="fs-vcard-body">' +
                        '<div style="flex:1; min-width:180px;"><div style="font-weight:700;">โดย ' + escapeHtml(v.by || '-') + '</div>' +
                        (when ? '<div style="color:#64748b; font-size:0.82rem;">เมื่อ ' + escapeHtml(when) + '</div>' : '') + '</div>' +
                        '<button type="button" class="btn btn-secondary" style="width:auto;" onclick="fsViewVerify()"><i class="fa-solid fa-signature"></i> ดูลายเซ็น</button>' +
                        (resp.canBS ? '<button type="button" class="btn btn-secondary" style="width:auto; color:#b91c1c;" onclick="fsCancelVerify()"><i class="fa-solid fa-rotate-left"></i> ยกเลิกการยืนยัน</button>' : '') +
                    '</div></div>';
                return;
            }
            if (!resp.dayRecorded) {
                 
                 
                var pendDays = resp.canBS ? (resp.monthDays || []).filter(function (x) { return x.status === 'recorded'; }) : [];
                var pendHtml = pendDays.length
                    ? '<div class="fs-vcard-body" style="flex-wrap:wrap; gap:0.4rem; align-items:center;">' +
                      '<span style="font-size:0.85rem; color:#78350f;"><i class="fa-solid fa-hourglass-half"></i> วันที่บันทึกแล้วรอตรวจ+ลงนาม:</span>' +
                      pendDays.map(function (x) {
                          return '<button type="button" class="btn btn-secondary" style="width:auto; padding:0.3rem 0.65rem; font-size:0.82rem;" onclick="fsJumpDay(\'' + x.d + '\')">' +
                                 '<i class="fa-solid fa-signature"></i> ' + _fsThDate(x.d) + ' (' + x.crews + ' ชุด)</button>';
                      }).join('') + '</div>'
                    : '';
                el.innerHTML = '<div class="fs-vcard v-none"><div class="fs-vcard-head"><i class="fa-solid fa-circle-info"></i> ยังไม่มีการบันทึกข้อมูลของ ' + dateTh + '</div>' + pendHtml + '</div>';
                return;
            }
             
            if (resp.dayIncomplete > 0) {
                el.innerHTML = '<div class="fs-vcard v-pending">' +
                    '<div class="fs-vcard-head" style="color:#b91c1c;"><i class="fa-solid fa-triangle-exclamation"></i> ข้อมูลยังกรอกไม่ครบ ' + resp.dayIncomplete + ' ชุด — ลงนามยืนยันไม่ได้ · ' + dateTh + '</div>' +
                    '<div class="fs-vcard-body"><div style="flex:1; min-width:200px; color:#78350f; font-size:0.86rem;">' +
                    'มีชุดที่กรอก <b>ลงงาน</b> แล้วแต่ยังไม่กรอก <b>แสกน</b> (หรือติ๊กไม่แจ้งยอดแต่ยังไม่กรอกแรงงานในระบบ) — ดูสถานะสีแดง "กรอกไม่ครบ" ในตารางด้านบน ' +
                    (resp.canBS ? 'กรอกให้ครบแล้วบันทึก จึงจะลงลายเซ็นยืนยันได้' : 'กรอกให้ครบแล้วบันทึก เพื่อให้ผู้ดูแล (BS) ลงนามได้') +
                    '</div></div></div>';
                return;
            }
             
            if (resp.canBS) {
                var sigWarn = resp.myHasSignature ? '' :
                    '<div style="flex:1; min-width:200px; color:#b91c1c; font-size:0.85rem;"><i class="fa-solid fa-triangle-exclamation"></i> คุณยังไม่ได้ตั้งลายเซ็น — ตั้งได้ที่ "ตั้งค่าบัญชี" ก่อนกดยืนยัน</div>';
                el.innerHTML = '<div class="fs-vcard v-pending">' +
                    '<div class="fs-vcard-head"><i class="fa-solid fa-clipboard-check"></i> รอผู้ดูแล (BS) ตรวจสอบและลงนาม — ' + dateTh + '</div>' +
                    '<div class="fs-vcard-body">' +
                        '<div style="flex:1; min-width:180px; color:#78350f; font-size:0.86rem;">ตรวจความถูกต้องของข้อมูลด้านบนแล้ว กดปุ่มเพื่อลงลายเซ็นยืนยันการตรวจสอบของวันนี้</div>' +
                        sigWarn +
                        '<button type="button" class="btn btn-primary" style="width:auto;"' + (resp.myHasSignature ? '' : ' disabled') + ' onclick="fsVerifyDay()"><i class="fa-solid fa-signature"></i> ตรวจสอบแล้ว · ลงลายเซ็นยืนยัน</button>' +
                    '</div></div>';
            } else {
                el.innerHTML = '<div class="fs-vcard v-pending"><div class="fs-vcard-head"><i class="fa-solid fa-clock"></i> บันทึกแล้ว · รอผู้ดูแล (BS) ตรวจสอบและลงนาม — ' + dateTh + '</div></div>';
            }
        }

         
        function fsVerifyDay() {
            if (!_fsData) return;
            if (!confirm('ยืนยันว่าตรวจสอบข้อมูลสแกนนิ้วของ ' + _fsThDate(_fsData.date) + ' ถูกต้องแล้ว และลงลายเซ็นของคุณ ?')) return;
            showLoadingPopup('กำลังลงลายเซ็นยืนยัน', _fsThDate(_fsData.date));
            google.script.run
                .withSuccessHandler(function (res) {
                    closeAppPopup();
                    if (!res || !res.success) {
                        if (res && res.message === 'no_signature') {
                            showInfoPopup('ยังไม่มีลายเซ็น', 'กรุณาตั้งลายเซ็นของคุณที่ "ตั้งค่าบัญชี" ก่อน แล้วจึงกดยืนยันอีกครั้ง', 'warning');
                            return;
                        }
                        if (res && res.message === 'day_incomplete') {
                            showInfoPopup('ยังลงนามไม่ได้ — ข้อมูลไม่ครบ',
                                'มี ' + (res.incomplete || '') + ' ชุดที่กรอกไม่ครบ (ลงงานแล้วแต่ยังไม่กรอกแสกน หรือไม่แจ้งยอดแต่ไม่กรอกแรงงานในระบบ) — กรอกให้ครบแล้วบันทึกก่อน จึงจะลงลายเซ็นยืนยันได้', 'warning');
                            loadFingerScanPage();    
                            return;
                        }
                        showInfoPopup('ยืนยันไม่สำเร็จ', (res && res.message) || 'เกิดข้อผิดพลาด', 'danger');
                        return;
                    }
                    hapticSuccess();
                    showToast('ลงลายเซ็นยืนยันแล้ว', 'success');
                    loadFingerScanPage();
                })
                .withFailureHandler(function (err) {
                    closeAppPopup();
                    showInfoPopup('ยืนยันไม่สำเร็จ', (err && err.message) || String(err || ''), 'danger');
                })
                .verifyFingerScanDay({ siteCode: _fsData.siteCode, date: _fsData.date, username: user ? user.username : '' });
        }
        function fsCancelVerify() {
            if (!_fsData) return;
            if (!confirm('ยกเลิกการยืนยันของ ' + _fsThDate(_fsData.date) + ' ? (ลายเซ็นจะถูกลบ ต้องตรวจและลงนามใหม่)')) return;
            google.script.run
                .withSuccessHandler(function (res) {
                    if (!res || !res.success) { showToast((res && res.message) || 'ยกเลิกไม่สำเร็จ', 'danger'); return; }
                    showToast('ยกเลิกการยืนยันแล้ว', 'success');
                    loadFingerScanPage();
                })
                .withFailureHandler(function (err) { showToast((err && err.message) || 'ไม่สำเร็จ', 'danger'); })
                .cancelFingerScanVerify({ siteCode: _fsData.siteCode, date: _fsData.date, username: user ? user.username : '' });
        }
        function fsViewVerify() {
            if (!_fsData) return;
            showLoadingPopup('กำลังโหลดลายเซ็น', _fsThDate(_fsData.date));
            google.script.run
                .withSuccessHandler(function (res) {
                    closeAppPopup();
                    if (!res || !res.success) { showInfoPopup('ไม่พบลายเซ็น', (res && res.message) || '', 'danger'); return; }
                    var when = res.verifiedAt ? new Date(res.verifiedAt).toLocaleString('th-TH', { dateStyle: 'medium', timeStyle: 'short' }) : '';
                    var img = res.signatureData ? '<img src="' + res.signatureData + '" alt="ลายเซ็น" style="max-width:260px; max-height:110px; border:1px solid #eef2f7; border-radius:8px; background:#fff; padding:4px;">' : '<div style="color:#94a3b8;">— ไม่มีภาพลายเซ็น —</div>';
                    showInfoPopup('ลายเซ็นยืนยันการตรวจสอบ', '<div style="text-align:center;">' + img +
                        '<div style="font-weight:800; margin-top:0.6rem;">( ' + escapeHtml(res.verifiedBy || '-') + ' )</div>' +
                        (when ? '<div style="color:#64748b; font-size:0.83rem;">ตรวจสอบเมื่อ ' + escapeHtml(when) + '</div>' : '') + '</div>', 'info');
                })
                .withFailureHandler(function (err) { closeAppPopup(); showInfoPopup('โหลดไม่สำเร็จ', (err && err.message) || '', 'danger'); })
                .getFingerScanVerifyView({ siteCode: _fsData.siteCode, date: _fsData.date, username: user ? user.username : '' });
        }

        function _fsUpdateDayTotal() {
            var el = document.getElementById('fsDayTotal');
            if (!el) return;
            var crews = 0, workers = 0, scans = 0, fine = 0;
            document.querySelectorAll('#fsTbody tr').forEach(function (tr) {
                if (!_fsRowEngaged(tr)) return;    
                var w = _fsNum((tr.querySelector('[data-f="worker"]') || {}).value);
                var s = _fsNum((tr.querySelector('[data-f="scan"]') || {}).value);
                var f = _fsNum(tr.getAttribute('data-fine'));
                crews++;
                if (w !== '') workers += w;
                if (s !== '') scans += s;
                if (f !== '') fine += f;
            });
            el.innerHTML = '<i class="fa-solid fa-calculator"></i> วันนี้: <b>' + crews + '</b> ชุดลงงาน · ลงงานรวม <b>' + _fsFmt(workers) + '</b> คน · แสกนรวม <b>' + _fsFmt(scans) + '</b> คน · ค่าปรับรวม <b style="color:#b91c1c;">' + _fsFmt(fine) + '</b> บาท';
        }

         
         
        function _fsCalcRow(tr, markDirty) {
            var rate = (_fsData && _fsData.rate) || 100;
            var wEl = tr.querySelector('[data-f="worker"]'), sEl = tr.querySelector('[data-f="scan"]');
            var nrEl = tr.querySelector('[data-f="noReport"]'), sysEl = tr.querySelector('[data-f="system"]');
            var fCell = tr.querySelector('.fs-fine-cell');
            var stEl = tr.querySelector('.fs-status');
            var w = _fsNum(wEl ? wEl.value : ''), s = _fsNum(sEl ? sEl.value : '');
            var sys = _fsNum(sysEl ? sysEl.value : '');
            var nr = !!(nrEl && nrEl.checked);
            var fine = 0, hasFine = false;
            var statusHtml = '<span style="color:#94a3b8;">— ไม่ลงงาน —</span>';
            if (nr) {
                if (sys === '' || sys <= 0) {
                    statusHtml = '<span style="color:#b91c1c; font-weight:700;">กรอกแรงงานในระบบ *</span>';
                } else {
                    fine = sys * rate;
                    hasFine = true;
                    statusHtml = '<span style="color:#d97706; font-weight:700;">ไม่แจ้งยอด</span>' +
                        '<span style="display:block; color:#64748b;">ปรับตามแรงงานในระบบ ' + sys + ' คน</span>';
                }
            } else if (w === '' && s === '') {
                fine = 0;
            } else if (w === '' || s === '') {
                 
                 
                var prefill = tr.getAttribute('data-prefill') || '';
                if (w !== '' && s === '' && String(w) === prefill) {
                    statusHtml = '<span style="color:#a16207;">ค่าเริ่มต้นวันก่อน · กรอกแสกนถ้าลงงาน</span>';
                } else {
                    statusHtml = '<span style="color:#b91c1c; font-weight:700;">กรอกไม่ครบ</span>';
                }
            } else if (s > w) {
                 
                fine = 0; hasFine = true;
                statusHtml = '<span style="color:#d97706; font-weight:700;"><i class="fa-solid fa-triangle-exclamation"></i> แสกนเกินลงงาน ' + (s - w) + ' คน</span>' +
                    '<span style="display:block; color:#a16207; font-size:0.72rem;">บันทึกได้ · ต้องชี้แจงในเมนู "ชี้แจงแสกนเกิน"</span>';
            } else {
                fine = Math.max(0, w - s) * rate;
                hasFine = true;
                var pct = w > 0 ? Math.round((s / w) * 1000) / 10 : 100;
                statusHtml = (s === w)
                    ? '<span style="color:#16a34a; font-weight:700;">ครบ 100%</span>'
                    : '<span style="color:#b91c1c; font-weight:700;">' + pct + '% · ขาด ' + (w - s) + ' คน</span>';
            }
            tr.setAttribute('data-fine', hasFine ? fine : '');
            if (stEl) stEl.innerHTML = statusHtml;
            if (fCell) {
                fCell.textContent = hasFine ? (fine > 0 ? _fsFmt(fine) : 'ไม่มีปรับ') : '—';
                fCell.style.color = hasFine && fine > 0 ? '#b91c1c' : '#64748b';
            }
             
            if (sysEl) {
                var needSys = nr && (sys === '' || sys <= 0);
                sysEl.style.borderColor = needSys ? '#dc2626' : '';
                sysEl.style.background = nr ? '#fffbeb' : '';
            }
            if (markDirty) { _fsMarkDirty(); _fsUpdateDayTotal(); }
        }

        function fsRowCalc(el) {
            var tr = el.closest('tr');
            if (!tr) return;
             
            if (el.getAttribute('data-f') === 'worker') {
                var prefill = tr.getAttribute('data-prefill') || '';
                if (String(_fsNum(el.value)) !== prefill) el.classList.remove('fs-prev');
            }
            _fsCalcRow(tr, true);
        }

        function fsNoReportToggle(cb) {
            hapticTap();
            var tr = cb.closest('tr');
            if (!tr) return;
             
             
            ['worker', 'scan'].forEach(function (f) {
                var el = tr.querySelector('[data-f="' + f + '"]');
                if (!el) return;
                el.disabled = cb.checked;
                if (cb.checked) {
                    el.value = '';
                    el.classList.remove('fs-prev');    
                }
            });
             
            if (cb.checked) {
                var sysEl = tr.querySelector('[data-f="system"]');
                if (sysEl) { try { sysEl.focus(); } catch (e) {} }
            }
            _fsCalcRow(tr, true);
        }

        function _fsMarkDirty() {
            _fsDirty = true;
            var btn = document.getElementById('fsSaveBtn');
            if (btn) btn.disabled = false;
        }

         
         
        function _fsRowShowsPrefill(tr) {
            var pf = tr.getAttribute('data-prefill') || '';
            if (!pf) return false;
            var w = _fsNum((tr.querySelector('[data-f="worker"]') || {}).value);
            return w !== '' && String(w) === pf;
        }
         
        function _fsConfirmPrefill(trs) {
            (trs || []).forEach(function (tr) {
                tr.setAttribute('data-prefill', '');
                var wEl = tr.querySelector('[data-f="worker"]');
                if (wEl) { wEl.classList.remove('fs-prev'); wEl.removeAttribute('title'); }
                _fsCalcRow(tr, false);
            });
            _fsMarkDirty();
            _fsUpdateDayTotal();
        }
         
        function fsConfirmAllPrefill() {
            var trs = [];
            document.querySelectorAll('#fsTbody tr').forEach(function (tr) { if (_fsRowShowsPrefill(tr)) trs.push(tr); });
            if (!trs.length) { showToast('ไม่มีค่าเริ่มต้นที่ต้องยืนยัน', 'info'); return; }
            hapticTap();
            _fsConfirmPrefill(trs);
            showToast('ยืนยันค่าเริ่มต้น ' + trs.length + ' ชุดแล้ว — กดบันทึกได้เลย', 'success');
        }

        function saveFingerScan() {
            if (!_fsData) return;
            var rows = [];
            var prefillRows = [];    
            document.querySelectorAll('#fsTbody tr').forEach(function (tr) {
                 
                 
                if (!_fsRowEngaged(tr)) {
                    if (_fsRowShowsPrefill(tr)) prefillRows.push(tr);
                    return;
                }
                var w = ((tr.querySelector('[data-f="worker"]') || {}).value || '').toString().trim();
                var s = ((tr.querySelector('[data-f="scan"]') || {}).value || '').toString().trim();
                var sys = ((tr.querySelector('[data-f="system"]') || {}).value || '').toString().trim();
                var nr = !!(tr.querySelector('[data-f="noReport"]') || {}).checked;
                var note = ((tr.querySelector('[data-f="note"]') || {}).value || '').toString().trim();
                 
                rows.push({
                    subId: tr.getAttribute('data-subid'),
                    workerCount: w, scanCount: s, noReport: nr,
                    systemWorkerCount: sys,
                    note: note
                });
            });
             
            var prefillPending = prefillRows.length;
            if (!rows.length) {
                 
                 
                var savedBefore = ((_fsData && _fsData.rows) || []).length -
                                  ((_fsData && _fsData.closedRows) || []).length;
                 
                if (prefillPending > 0 && !savedBefore) {
                    showConfirmPopup('ใช้ค่าเริ่มต้นจากวันก่อนเลยไหม?',
                        'ช่อง "ลงงาน" พื้นเหลือง <b>' + prefillPending + ' ชุด</b> เป็นค่าเริ่มต้นจากวันก่อน ระบบจึงยังไม่นับเป็นข้อมูลของวันนี้<br><br>' +
                        'กด <b>ใช้ค่าเริ่มต้น</b> = ยืนยันยอดลงงานเท่าวันก่อนทั้ง ' + prefillPending + ' ชุด แล้วบันทึกได้เลย ' +
                        '(ยังไม่มีเลขแสกน → จะขึ้นเป็น <b>"กรอกไม่ครบ"</b> บันทึกได้ แต่ BS จะเซ็นยืนยันวันนี้ไม่ได้จนกว่าจะกรอกแสกนครบ)<br><br>' +
                        'ถ้าชุดไหนไม่ลงงานวันนี้ ให้กด <b>ยกเลิก</b> แล้วลบเลขในช่องนั้นออก (ปล่อยว่าง = ไม่ลงงาน)',
                        function () {
                            _fsConfirmPrefill(prefillRows);
                            saveFingerScan();    
                        }, 'ใช้ค่าเริ่มต้น ' + prefillPending + ' ชุด');
                    return;
                }
                 
                if (savedBefore > 0) {
                    showConfirmPopup('ลบข้อมูลของวันนี้ทั้งหมด?',
                        'ตารางนี้ไม่มีข้อมูลเหลืออยู่แล้ว แต่วันที่ ' + _fsThDate(_fsData.date) + ' เคยบันทึกไว้ <b>' + savedBefore + ' ชุด</b><br><br>' +
                        'กดยืนยัน = <b>ลบข้อมูลสแกนนิ้วของวันนี้ทั้งวัน</b> (ลายเซ็นยืนยันของ BS ของวันนี้จะถูกล้างด้วย) · ' +
                        'ถ้าไม่ได้ต้องการลบ ให้กดยกเลิกแล้วกรอกข้อมูลกลับเข้าไป',
                        function () {
                            _fsSaveRows = [];
                            confirmFsSave();
                        }, 'ลบข้อมูลของวันนี้', 'btn btn-danger');
                    return;
                }
                showInfoPopup('ยังไม่มีข้อมูลให้บันทึก',
                    'กรอกข้อมูลอย่างน้อย 1 ชุดก่อนบันทึก — ลงงาน + แสกน (หรือติ๊ก "ไม่แจ้งยอด" พร้อมแรงงานในระบบ) · ชุดที่ไม่ลงงานปล่อยว่างไว้ได้', 'info');
                return;
            }
            _fsSaveRows = rows;
            _fsRenderSaveReview(rows, prefillPending);
            var bd = document.getElementById('fsSaveBackdrop');
            if (bd) bd.classList.add('open');
        }
        function closeFsSave() {
            var bd = document.getElementById('fsSaveBackdrop');
            if (bd) bd.classList.remove('open');
            _fsSaveRows = null;
        }
         
        function _fsRenderSaveReview(rows, prefillPending) {
            var nameById = {};
            document.querySelectorAll('#fsTbody tr').forEach(function (tr) {
                nameById[tr.getAttribute('data-subid')] = tr.getAttribute('data-name') || '';
            });
            var sub = document.getElementById('fsSaveSub');
            if (sub) sub.textContent = 'วันที่ ' + _fsThDate(_fsData.date) + ' · Site ' + _fsData.siteCode + ' — แทนที่ข้อมูลเดิมของวันนี้ทั้งวัน';
            var rate = (_fsData && _fsData.rate) || 100;
            var totW = 0, totS = 0, totFine = 0, nInc = 0;
            var listHtml = rows.map(function (r) {
                var w = _fsNum(r.workerCount), s = _fsNum(r.scanCount), sys = _fsNum(r.systemWorkerCount);
                var badge, meta = '', fine = 0, inc = false;
                if (r.noReport) {
                    inc = (sys === '' || sys <= 0);
                    if (!inc) { fine = sys * rate; meta = 'ในระบบ ' + _fsFmt(sys) + ' คน'; }
                    badge = inc ? '<span class="fs-save-badge" style="background:#fee2e2; color:#b91c1c;">กรอกไม่ครบ</span>'
                                : '<span class="fs-save-badge" style="background:#fef3c7; color:#b45309;">ไม่แจ้งยอด</span>';
                } else if ((w !== '' && s === '') || (w === '' && s !== '')) {
                    inc = true;
                    meta = (w !== '' ? 'ลงงาน ' + _fsFmt(w) : 'แสกน ' + _fsFmt(s)) + ' · อีกช่องยังว่าง';
                    badge = '<span class="fs-save-badge" style="background:#fee2e2; color:#b91c1c;">กรอกไม่ครบ</span>';
                } else if (w === '' && s === '') {
                    meta = r.note ? 'หมายเหตุ: ' + escapeHtml(r.note) : 'บันทึกเฉพาะหมายเหตุ';
                    badge = '<span class="fs-save-badge" style="background:#f1f5f9; color:#64748b;">ไม่ลงงาน</span>';
                } else {
                    totW += w; totS += s;
                    meta = 'ลงงาน ' + _fsFmt(w) + ' · แสกน ' + _fsFmt(s);
                    if (s > w) {
                        badge = '<span class="fs-save-badge" style="background:#fef3c7; color:#b45309;">แสกนเกิน +' + _fsFmt(s - w) + '</span>';
                    } else if (s === w) {
                        badge = '<span class="fs-save-badge" style="background:#dcfce7; color:#15803d;">ครบ 100%</span>';
                    } else {
                        fine = (w - s) * rate;
                        badge = '<span class="fs-save-badge" style="background:#fee2e2; color:#b91c1c;">ขาด ' + _fsFmt(w - s) + ' · ปรับ ' + _fsFmt(fine) + '</span>';
                    }
                }
                if (inc) nInc++; else totFine += fine;
                return '<div class="fs-save-row"><span class="fs-save-name">' + escapeHtml(nameById[r.subId] || r.subId) + '</span>' +
                       (meta ? '<span class="fs-save-meta">' + meta + '</span>' : '') + badge + '</div>';
            }).join('');
            var listEl = document.getElementById('fsSaveList');
            if (listEl) listEl.innerHTML = listHtml;
            var sum = document.getElementById('fsSaveSummary');
            if (sum) {
                sum.innerHTML =
                    '<div class="qty-stock-row"><span class="label">จะบันทึก</span><span class="value">' + rows.length + ' ชุด</span></div>' +
                    '<div class="qty-stock-row"><span class="label">ลงงาน / แสกน</span><span class="value">' + _fsFmt(totW) + ' / ' + _fsFmt(totS) + ' คน</span></div>' +
                    '<div class="qty-stock-row divider highlight"><span class="label">ค่าปรับรวม (' + rate + ' บาท/คน)</span><span class="value">' +
                    _fsFmt(totFine) + ' บาท' + (nInc ? ' *' : '') + '</span></div>' +
                    (nInc ? '<div style="font-size:0.72rem; color:#94a3b8; text-align:right;">* ยังไม่รวมชุดที่กรอกไม่ครบ (คำนวณไม่ได้)</div>' : '');
            }
            var warn = document.getElementById('fsSaveWarn');
            if (warn) {
                var warnHtml = '';
                if (nInc > 0) {
                    warnHtml += '<div class="rate-alert" style="background:#fef2f2; border-color:#fecaca; color:#7f1d1d; margin-top:0.7rem;">' +
                        '<i class="fa-solid fa-triangle-exclamation" style="color:#dc2626;"></i><div>' +
                        'มี <b>' + nInc + ' ชุดที่กรอกไม่ครบ</b> — บันทึกเก็บไว้ก่อนได้ แต่วันนี้จะถือว่า<b>ยังไม่สมบูรณ์</b> ' +
                        'ผู้ดูแล (BS) จะ<b>ลงลายเซ็นยืนยันไม่ได้</b>จนกว่าจะกรอกครบแล้วบันทึกอีกครั้ง</div></div>';
                }
                if (prefillPending > 0) {
                    warnHtml += '<div class="se-export-hint" style="margin-top:0.6rem;"><i class="fa-solid fa-circle-info"></i> ' +
                        'อีก <b>' + prefillPending + ' ชุด</b> ยังเป็นค่าลงงานเริ่มต้นจากวันก่อน (พื้นเหลือง) และไม่ได้กรอกแสกน — <b>จะไม่ถูกบันทึก</b>ในรอบนี้ (ถือว่าไม่ลงงาน)</div>';
                }
                warn.style.display = warnHtml ? '' : 'none';
                warn.innerHTML = warnHtml;
            }
            var go = document.getElementById('fsSaveGo');
            if (go) go.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> ยืนยันบันทึก ' + rows.length + ' ชุด';
        }
         
        function confirmFsSave() {
            if (!_fsSaveRows || !_fsData) { closeFsSave(); return; }
            var rows = _fsSaveRows;
            closeFsSave();
            var btn = document.getElementById('fsSaveBtn');
            if (btn) btn.disabled = true;
            showLoadingPopup(rows.length ? 'กำลังบันทึกข้อมูลสแกนนิ้ว' : 'กำลังลบข้อมูลของวันนี้',
                'วันที่ ' + _fsData.date + (rows.length ? ' · ' + rows.length + ' ชุด' : '') + '\nกรุณารอสักครู่...');
            google.script.run
                .withSuccessHandler(function (res) {
                    closeAppPopup();
                    if (!res || !res.success) {
                        if (btn) btn.disabled = false;
                        showInfoPopup('บันทึกไม่สำเร็จ', (res && res.message) || 'เกิดข้อผิดพลาด', 'danger');
                        return;
                    }
                    hapticSuccess();
                    var keptTxt = res.keptClosed ? ' · คงข้อมูลชุดที่ปิดแล้วไว้ ' + res.keptClosed + ' ชุด' : '';
                    showToast(res.saved
                        ? ('บันทึกแล้ว ' + res.saved + ' ชุด (วันที่ ' + res.date + ')' + keptTxt)
                        : ('ลบข้อมูลสแกนนิ้วของวันที่ ' + res.date + ' แล้ว' + keptTxt), 'success');
                    _fsUpdateAlertBadge(res.alertPending);
                     
                    if (res.exceedTodayPending > 0) {
                        showInfoPopup('พบแสกนเกินลงงาน ' + res.exceedTodayPending + ' ชุด',
                            'ระบบบันทึกให้แล้ว (ไม่มีค่าปรับ) แต่ต้องชี้แจงสาเหตุ — เปิดเมนู "ชี้แจงแสกนเกิน" เพื่อบันทึกเหตุผล', 'warning');
                    } else if (res.incomplete > 0) {
                        showInfoPopup('บันทึกแล้ว — แต่ยังไม่สมบูรณ์',
                            'มี ' + res.incomplete + ' ชุดที่กรอกไม่ครบ — ผู้ดูแล (BS) จะยังลงลายเซ็นยืนยันวันนี้ไม่ได้ กรอกแสกน/แรงงานในระบบให้ครบแล้วบันทึกอีกครั้ง', 'warning');
                    }
                    _fsDirty = false;
                    _fsSum = null; _fsAlert = null; _fsDash = null;    
                    loadFingerScanPage();
                })
                .withFailureHandler(function (err) {
                    closeAppPopup();
                    if (btn) btn.disabled = false;
                    showInfoPopup('บันทึกไม่สำเร็จ', (err && err.message) ? err.message : String(err || 'unknown'), 'danger');
                })
                .saveFingerScanDay({
                    siteCode: _fsData.siteCode,
                    date: _fsData.date,
                    rows: rows,
                    username: user ? user.username : ''
                });
        }

         
        function loadFsSummary() {
            var body = document.getElementById('fsSumBody');
            if (body) body.innerHTML = _fsSpin('กำลังสรุปงวด...');
            var sEl = document.getElementById('fsSumSiteSelect');
            var mEl = document.getElementById('fsMonth'), hEl = document.getElementById('fsHalf');
            var ym = (mEl && /^\d{4}-\d{2}$/.test(mEl.value || '')) ? mEl.value : '';
            if (!ym) {
                var today = _fsTodayStr();
                ym = today.slice(0, 7);
                if (mEl) mEl.value = ym;
                if (hEl) hEl.value = parseInt(today.slice(8, 10), 10) <= 15 ? '1' : '2';
            }
            var half = hEl ? (parseInt(hEl.value, 10) === 2 ? 2 : 1) : 1;
            var site = (sEl && sEl.value) ? sEl.value : ((_fsSum && _fsSum.siteCode) || (_fsData && _fsData.siteCode) || getEffectiveSiteCode());
            google.script.run
                .withSuccessHandler(renderFsSummary)
                .withFailureHandler(function (err) {
                    if (body) body.innerHTML = _fsErr({ message: (err && err.message) || String(err || '') });
                })
                .getFingerScanSummary({ siteCode: site, ym: ym, half: half, username: user ? user.username : '' });
        }
        function onFsSumChange() { loadFsSummary(); }
        function onFsSumSiteChange() { loadFsSummary(); }

        function _fsSignChipHtml(rec, idx) {
            var s = rec.sign;
            var btn = '<button type="button" class="btn btn-secondary" style="width:auto; padding:0.25rem 0.55rem; font-size:0.72rem;" onclick="openFsSign(' + idx + ')">';
            if (!s) return btn + '<i class="fa-solid fa-link"></i> สร้างลิงก์เซ็น</button>';
            if (s.status === 'signed') {
                return '<span style="color:#16a34a; font-weight:700; font-size:0.78rem;"><i class="fa-solid fa-circle-check"></i> เซ็นแล้ว</span>' +
                       '<span style="display:block; font-size:0.7rem; color:#64748b;">' + escapeHtml(s.signerName || '') + '</span>';
            }
            if (s.status === 'auto') {
                return '<span style="color:#d97706; font-weight:700; font-size:0.78rem;"><i class="fa-solid fa-clock"></i> รับทราบโดยปริยาย</span>';
            }
            return btn + '<i class="fa-solid fa-hourglass-half"></i> ' + (s.sentAt ? 'รอลงนาม (ส่งแล้ว)' : 'รอส่งลิงก์') + '</button>';
        }

        function renderFsSummary(resp) {
            var body = document.getElementById('fsSumBody');
            if (!body) return;
            if (!resp || !resp.success) { body.innerHTML = _fsErr(resp); return; }
            _fsSum = resp;
            var sField = document.getElementById('fsSumSiteField');
            var sEl = document.getElementById('fsSumSiteSelect');
            if (sEl) {
                sEl.innerHTML = (resp.sites || []).map(function (s) {
                    return '<option value="' + escapeHtml(s.code) + '">' + escapeHtml(s.code + (s.name ? ' — ' + s.name : '')) + '</option>';
                }).join('');
                sEl.value = resp.siteCode;
            }
            if (sField) sField.style.display = resp.isAdmin ? '' : 'none';
            var mEl = document.getElementById('fsMonth'), hEl = document.getElementById('fsHalf');
            if (mEl) mEl.value = resp.ym;
            if (hEl) hEl.value = String(resp.half);
            var rl = document.getElementById('fsSumLabel');
            if (rl) rl.textContent = 'งวด' + resp.periodLabel + ' · บันทึกแล้ว ' + resp.dayCount + ' วัน';
            _fsUpdateAlertBadge(resp.alertPending);

            if (!resp.list || !resp.list.length) {
                body.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-circle-info"></i><span>ยังไม่มีข้อมูลสแกนนิ้วของงวด' + escapeHtml(resp.periodLabel) + ' — เริ่มบันทึกได้ที่เมนู "บันทึกสแกนนิ้ว"</span></div>';
                return;
            }
            var html = '<div class="rate-summary" style="display:flex; gap:1rem; flex-wrap:wrap; align-items:center; padding:0.5rem 0.2rem;">' +
                '<span>ชุดที่ลงงาน <b>' + resp.list.length + '</b> ชุด</span>' +
                '<span>ยอดปรับรวมทั้งงวด <b style="color:#b91c1c; font-size:1.05rem;">' + _fsFmt(resp.total) + '</b> บาท</span>' +
                '<span style="color:#64748b;">อัตราปรับ ' + resp.rate + ' บาท/คน/วัน</span></div>';
            html += '<div class="rate-table-wrap"><table class="rate-table"><thead><tr>' +
                '<th style="width:50px; text-align:center;">ลำดับ</th>' +
                '<th>ชุดผู้รับเหมา</th>' +
                '<th style="width:60px; text-align:center;">วัน</th>' +
                '<th style="width:80px; text-align:right;">ลงงาน</th>' +
                '<th style="width:80px; text-align:right;">แสกน</th>' +
                '<th style="width:90px; text-align:center;">อัตรา/งวด</th>' +
                '<th style="width:110px; text-align:right;">ค่าปรับ (บาท)</th>' +
                '<th style="width:150px; text-align:center;">เซ็นรับทราบ</th>' +
                '</tr></thead><tbody>';
            resp.list.forEach(function (rec, i) {
                var rateTxt = rec.rate === null ? '—' : (Math.round(rec.rate * 1000) / 10) + '%';
                html += '<tr>' +
                    '<td style="text-align:center;">' + (i + 1) + '</td>' +
                    '<td class="rate-td-name">' + escapeHtml(rec.subName) + _fsMangoLine(rec) + (rec.noReportDays ? '<span style="display:block; font-size:0.7rem; color:#d97706;">ไม่แจ้งยอด ' + rec.noReportDays + ' วัน</span>' : '') + '</td>' +
                    '<td style="text-align:center;">' + rec.days + '</td>' +
                    '<td style="text-align:right;">' + _fsFmt(rec.workers) + '</td>' +
                    '<td style="text-align:right;">' + _fsFmt(rec.scans) + '</td>' +
                    '<td style="text-align:center;">' + rateTxt + '</td>' +
                    '<td style="text-align:right; font-weight:700;' + (rec.fine > 0 ? ' color:#b91c1c;' : '') + '">' + _fsFmt(rec.fine) + '</td>' +
                    '<td style="text-align:center;">' + _fsSignChipHtml(rec, i) + '</td>' +
                    '</tr>';
            });
            html += '</tbody></table></div>';
            body.innerHTML = html;
        }

         
        function loadFsAlerts() {
            var body = document.getElementById('fsAlertBody');
            if (body) body.innerHTML = _fsSpin('กำลังโหลดรายการแสกนเกิน...');
            var sEl = document.getElementById('fsAlertSiteSelect');
            var site = (sEl && sEl.value) ? sEl.value : ((_fsAlert && _fsAlert.siteCode) || (_fsData && _fsData.siteCode) || getEffectiveSiteCode());
            google.script.run
                .withSuccessHandler(renderFsAlerts)
                .withFailureHandler(function (err) {
                    if (body) body.innerHTML = _fsErr({ message: (err && err.message) || String(err || '') });
                })
                .getFingerScanAlerts({ siteCode: site, username: user ? user.username : '' });
        }
        function onFsAlertSiteChange() { loadFsAlerts(); }

        function renderFsAlerts(resp) {
            var body = document.getElementById('fsAlertBody');
            if (!body) return;
            if (!resp || !resp.success) { body.innerHTML = _fsErr(resp); return; }
            _fsAlert = resp;
             
            (resp.list || []).forEach(function (a) { if (a._orig === undefined) a._orig = a.note || ''; });
            var sField = document.getElementById('fsAlertSiteField');
            var sEl = document.getElementById('fsAlertSiteSelect');
            if (sEl) {
                sEl.innerHTML = (resp.sites || []).map(function (s) {
                    return '<option value="' + escapeHtml(s.code) + '">' + escapeHtml(s.code + (s.name ? ' — ' + s.name : '')) + '</option>';
                }).join('');
                sEl.value = resp.siteCode;
            }
            if (sField) sField.style.display = resp.isAdmin ? '' : 'none';
            _fsUpdateAlertBadge(resp.pendingCount);
            _fsAlertFillMonths();    
            _fsAlertRenderList();
        }
         
        function _fsMonthLabelOf(ym) {
            var y = parseInt(ym.slice(0, 4), 10), mo = parseInt(ym.slice(5, 7), 10);
            return _FS_TH_MON[mo - 1] + ' ' + ((y + 543) % 100);
        }
         
        function _fsAlertMatch(a) {
            if (_fsAlertMonth && a.date.slice(0, 7) !== _fsAlertMonth) return false;
            if (_fsAlertHalf) {
                var d = parseInt(a.date.slice(8, 10), 10);
                if ((d <= 15 ? '1' : '2') !== _fsAlertHalf) return false;
            }
            if (_fsAlertSub && a.subId !== _fsAlertSub) return false;
            return true;
        }
         
        function _fsAlertFillMonths() {
            var sel = document.getElementById('fsAlertMonthSelect');
            if (!sel || !_fsAlert) return;
            var setM = {};
            (_fsAlert.list || []).forEach(function (a) { setM[a.date.slice(0, 7)] = 1; });
            var months = Object.keys(setM).sort().reverse();
            if (!_fsAlertMonth || !setM[_fsAlertMonth]) _fsAlertMonth = months[0] || '';
            sel.innerHTML = '<option value="">ทุกเดือน</option>' + months.map(function (ym) {
                return '<option value="' + ym + '">' + escapeHtml(_fsMonthLabelOf(ym)) + '</option>';
            }).join('');
            sel.value = _fsAlertMonth;
            var hEl = document.getElementById('fsAlertHalfSelect');
            if (hEl) hEl.value = _fsAlertHalf;
            _fsAlertFillSubs();
        }
         
        function _fsAlertFillSubs() {
            var sel = document.getElementById('fsAlertSubSelect');
            if (!sel || !_fsAlert) return;
            var keepSub = _fsAlertSub; _fsAlertSub = '';    
            var setS = {};
            (_fsAlert.list || []).forEach(function (a) {
                if (_fsAlertMatch(a)) setS[a.subId] = { name: a.subName, mango: a.mangoName || '' };
            });
            _fsAlertSub = (keepSub && setS[keepSub]) ? keepSub : '';
            var subs = Object.keys(setS).sort(function (x, y) {
                return (setS[x].name || '').localeCompare(setS[y].name || '', 'th');
            });
             
            sel.innerHTML = '<option value="">ทุกชุด</option>' + subs.map(function (id) {
                var s = setS[id];
                return '<option value="' + escapeHtml(id) + '">' +
                       escapeHtml(s.name + (s.mango ? ' — ' + s.mango : '')) + '</option>';
            }).join('');
            sel.value = _fsAlertSub;
        }
        function onFsAlertFilterChange(which) {
            if (which === 'month') {
                var mEl = document.getElementById('fsAlertMonthSelect');
                _fsAlertMonth = mEl ? mEl.value : '';
                _fsAlertSub = '';             
                _fsAlertFillSubs();
            } else if (which === 'half') {
                var hEl2 = document.getElementById('fsAlertHalfSelect');
                _fsAlertHalf = hEl2 ? hEl2.value : '';
                _fsAlertSub = '';             
                _fsAlertFillSubs();
            } else {
                var sEl2 = document.getElementById('fsAlertSubSelect');
                _fsAlertSub = sEl2 ? sEl2.value : '';
            }
            _fsAlertRenderList();
        }
         
        function _fsAlertRenderList() {
            var body = document.getElementById('fsAlertBody');
            if (!body || !_fsAlert) return;
            var full = _fsAlert.list || [];
            var rl = document.getElementById('fsAlertLabel');
            if (!full.length) {
                if (rl) rl.textContent = '';
                body.innerHTML = '<div class="qr-empty" style="color:#16a34a;"><i class="fa-solid fa-circle-check"></i><span>ไม่มีเคสแสกนเกินลงงานของไซต์นี้ — ทุกอย่างปกติ</span></div>';
                return;
            }
            var list = full.filter(_fsAlertMatch);
            var pendingShown = list.filter(function (a) { return !a.cleared; }).length;
            if (rl) rl.textContent = 'แสดง ' + list.length + ' รายการ · รอชี้แจง ' + pendingShown;
            if (!list.length) {
                body.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-filter-circle-xmark"></i><span>ไม่มีเคสตามตัวกรองนี้ — ลองเปลี่ยนงวด/ชุด</span></div>';
                return;
            }
            var byDay = {};
            list.forEach(function (a) { (byDay[a.date] = byDay[a.date] || []).push(a); });
            var days = Object.keys(byDay).sort().reverse();
            var banner = '<div class="rate-alert" style="background:#fffbeb; border-color:#fde68a; color:#78350f;"><i class="fa-solid fa-triangle-exclamation" style="color:#d97706;"></i><div>' +
                'เคสที่ <b>จำนวนแสกนนิ้ว &gt; จำนวนลงงาน</b> (เช่น มีคนสแกนแทน/สแกนซ้ำ หรือแจ้งยอดคลาดเคลื่อน) — บันทึกไว้แล้วโดย<b>ไม่มีค่าปรับ</b> กรุณากรอกสาเหตุเพื่อเก็บเป็นหลักฐาน</div></div>';
            var html = banner + days.map(function (dk) {
                var items = byDay[dk];
                var dayPending = items.filter(function (a) { return !a.cleared; }).length;
                var collapsed = !!_fsAlertCollapsed[dk];
                var head = '<div class="fs-day-head' + (collapsed ? ' collapsed' : '') + '" onclick="_fsAlertToggleDay(\'' + dk + '\')">' +
                    '<i class="fa-solid fa-chevron-down fs-day-caret"></i>' +
                    '<span class="fs-day-date">' + escapeHtml(items[0].dateTh) + '</span>' +
                    '<span class="fs-day-count">' + items.length + ' รายการ · ' +
                    (dayPending ? '<b style="color:#d97706;">รอชี้แจง ' + dayPending + '</b>' : '<span style="color:#16a34a;">ชี้แจงครบ</span>') + '</span></div>';
                var rows = items.map(function (a) {
                    var gi = full.indexOf(a);
                    return '<tr' + (a.cleared ? '' : ' style="background:#fffdf5;"') + '>' +
                        '<td class="rate-td-name">' + escapeHtml(a.subName) + _fsMangoLine(a) +
                        '<span style="display:block; font-size:0.7rem; color:#94a3b8;">SubID ' + escapeHtml(a.subId) +
                        (a.cleared ? ' · <span style="color:#16a34a;">ชี้แจงแล้ว</span>' : ' · <span style="color:#d97706;">รอชี้แจง</span>') + '</span></td>' +
                        '<td style="text-align:right;">' + _fsFmt(a.workerCount) + '</td>' +
                        '<td style="text-align:right;">' + _fsFmt(a.scanCount) + '</td>' +
                        '<td style="text-align:center; font-weight:700; color:#d97706;">+' + a.exceedBy + '</td>' +
                        '<td><input type="text" class="form-control fs-alert-note" data-i="' + gi + '" value="' + escapeHtml(a.note || '') + '" placeholder="ระบุสาเหตุ เช่น มีแรงงานชุดอื่นสแกนปนมา" oninput="_fsAlertEdit(' + gi + ', this.value)"></td>' +
                        '<td style="text-align:center;"><button type="button" class="btn btn-primary" style="width:auto; padding:0.3rem 0.6rem;" onclick="saveFsAlertNote(' + gi + ')"><i class="fa-solid fa-floppy-disk"></i></button></td>' +
                        '</tr>';
                }).join('');
                var table = '<div class="fs-day-body"' + (collapsed ? ' hidden' : '') + '><div class="rate-table-wrap"><table class="rate-table"><thead><tr>' +
                    '<th>ชุดผู้รับเหมา</th><th style="width:70px; text-align:right;">ลงงาน</th><th style="width:70px; text-align:right;">แสกน</th>' +
                    '<th style="width:60px; text-align:center;">เกิน</th><th>สาเหตุ (ชี้แจง)</th><th style="width:80px; text-align:center;">บันทึก</th>' +
                    '</tr></thead><tbody>' + rows + '</tbody></table></div></div>';
                return '<div class="fs-day-group" data-day="' + dk + '">' + head + table + '</div>';
            }).join('');
            body.innerHTML = html;
        }
        function _fsAlertToggleDay(dk) {
            _fsAlertCollapsed[dk] = !_fsAlertCollapsed[dk];
            var grp = document.querySelector('.fs-day-group[data-day="' + dk + '"]');
            if (!grp) return;
            var head = grp.querySelector('.fs-day-head'), bodyEl = grp.querySelector('.fs-day-body');
            if (head) head.classList.toggle('collapsed', _fsAlertCollapsed[dk]);
            if (bodyEl) bodyEl.hidden = _fsAlertCollapsed[dk];
        }
         
        function _fsAlertSetAll(expand) {
            _fsAlertCollapsed = {};
            if (!expand) {
                ((_fsAlert && _fsAlert.list) || []).forEach(function (a) {
                    if (_fsAlertMatch(a)) _fsAlertCollapsed[a.date] = true;
                });
            }
            _fsAlertRenderList();
        }
         
        function _fsAlertEdit(i, val) {
            if (_fsAlert && _fsAlert.list && _fsAlert.list[i]) _fsAlert.list[i].note = val;
        }

        function saveFsAlertNote(i) {
            if (!_fsAlert || !_fsAlert.list || !_fsAlert.list[i]) return;
            var a = _fsAlert.list[i];
            var note = (a.note || '').toString().trim();    
            if (!note && !confirm('ยังไม่กรอกสาเหตุ — ต้องการล้างการชี้แจงเดิม (กลับเป็นรอชี้แจง) หรือไม่?')) return;
            showLoadingPopup('กำลังบันทึกคำชี้แจง', a.subName + ' · ' + a.dateTh);
            google.script.run
                .withSuccessHandler(function (res) {
                    closeAppPopup();
                    if (!res || !res.success) { showInfoPopup('บันทึกไม่สำเร็จ', (res && res.message) || 'เกิดข้อผิดพลาด', 'danger'); return; }
                    hapticSuccess();
                    showToast(note ? 'บันทึกคำชี้แจงแล้ว' : 'ล้างคำชี้แจงแล้ว', 'success');
                    a.note = note; a._orig = note; a.cleared = !!note;    
                    _fsUpdateAlertBadge(res.alertPending);
                    _fsAlertRenderList();    
                })
                .withFailureHandler(function (err) {
                    closeAppPopup();
                    showInfoPopup('บันทึกไม่สำเร็จ', (err && err.message) || String(err || ''), 'danger');
                })
                .saveFingerScanAlertNote({ siteCode: _fsAlert.siteCode, date: a.date, subId: a.subId, note: note, username: user ? user.username : '' });
        }

         
        function saveAllFsAlerts() {
            if (!_fsAlert || !_fsAlert.list || !_fsAlert.list.length) { showToast('ไม่มีรายการ', 'info'); return; }
            var changed = _fsAlert.list.filter(function (a) { return (a.note || '').toString().trim() !== (a._orig || ''); });
            if (!changed.length) { showToast('ไม่มีการแก้ไขให้บันทึก', 'info'); return; }
            if (!confirm('บันทึกคำชี้แจง ' + changed.length + ' รายการที่แก้ไข ?')) return;
            var items = changed.map(function (a) { return { date: a.date, subId: a.subId, note: (a.note || '').toString().trim() }; });
            showLoadingPopup('กำลังบันทึกคำชี้แจงทั้งหมด', changed.length + ' รายการ');
            google.script.run
                .withSuccessHandler(function (res) {
                    closeAppPopup();
                    if (!res || !res.success) { showInfoPopup('บันทึกไม่สำเร็จ', (res && res.message) || 'เกิดข้อผิดพลาด', 'danger'); return; }
                    hapticSuccess();
                    showToast('บันทึกคำชี้แจงแล้ว ' + res.saved + ' รายการ', 'success');
                    changed.forEach(function (a) { var n = (a.note || '').toString().trim(); a._orig = n; a.cleared = !!n; });
                    _fsUpdateAlertBadge(res.alertPending);
                    _fsAlertRenderList();
                })
                .withFailureHandler(function (err) {
                    closeAppPopup();
                    showInfoPopup('บันทึกไม่สำเร็จ', (err && err.message) || String(err || ''), 'danger');
                })
                .saveFingerScanAlertNotes({ siteCode: _fsAlert.siteCode, items: items, username: user ? user.username : '' });
        }

         
        function onFsDashChange() { _fsDash = null; loadFsDash(); }
        function loadFsDash() {
            var body = document.getElementById('fsDashBody');
            if (body) body.innerHTML = _fsSpin('กำลังสรุปภาพรวม...');
            var sEl = document.getElementById('fsDashSiteSelect');
            var mEl = document.getElementById('fsDashMonth');
            var ym = (mEl && /^\d{4}-\d{2}$/.test(mEl.value || '')) ? mEl.value : _fsTodayStr().slice(0, 7);
            var site = (sEl && sEl.value) ? sEl.value : ((_fsDash && _fsDash.siteCode) || (_fsData && _fsData.siteCode) || getEffectiveSiteCode());
            google.script.run
                .withSuccessHandler(renderFsDash)
                .withFailureHandler(function (err) {
                    if (body) body.innerHTML = _fsErr({ message: (err && err.message) || String(err || '') });
                })
                .getFingerScanDashboard({ siteCode: site, ym: ym, username: user ? user.username : '' });
        }
        function renderFsDash(resp) {
            var body = document.getElementById('fsDashBody');
            if (!body) return;
            if (!resp || !resp.success) { body.innerHTML = _fsErr(resp); return; }
            _fsDash = resp;
            var sField = document.getElementById('fsDashSiteField');
            var sEl = document.getElementById('fsDashSiteSelect');
            if (sEl) {
                sEl.innerHTML = (resp.sites || []).map(function (s) {
                    return '<option value="' + escapeHtml(s.code) + '">' + escapeHtml(s.code + (s.name ? ' — ' + s.name : '')) + '</option>';
                }).join('');
                sEl.value = resp.siteCode;
            }
            if (sField) sField.style.display = resp.isAdmin ? '' : 'none';
            var mEl = document.getElementById('fsDashMonth');
            if (mEl) { mEl.value = resp.ym; mEl.max = _fsTodayStr().slice(0, 7); }
            var lbl = document.getElementById('fsDashLabel');
            if (lbl) lbl.textContent = 'เดือน ' + _fsMonthLabelOf(resp.ym) + ' · อัตราปรับ ' + resp.rate + ' บาท/คน/วัน';

            var h1 = (resp.halves && resp.halves[0]) || {}, h2 = (resp.halves && resp.halves[1]) || {};
            function signTxt(h) { return ((h.signed || 0) + (h.auto || 0)) + '/' + (h.crews || 0); }
            var scanRate = resp.workers > 0 ? Math.round(resp.scans / resp.workers * 1000) / 10 : 0;
            var waitVerify = Math.max(0, (resp.recordedDays || 0) - (resp.verifiedDays || 0));

            var html = '<div class="fs-dash-grid">' +
                '<div class="fs-dash-card"><div class="fsd-lbl"><i class="fa-solid fa-pen-to-square"></i> บันทึกแล้ว</div>' +
                    '<div class="fsd-val">' + resp.recordedDays + ' <span style="font-size:0.85rem; color:#94a3b8;">/ ' + resp.pastDays + ' วัน</span></div>' +
                    '<div class="fsd-sub">' + (resp.incompleteDays > 0 ? '<span style="color:#b91c1c; font-weight:700;">กรอกไม่ครบ ' + resp.incompleteDays + ' วัน</span>' : 'นับถึงวันนี้') + '</div></div>' +
                '<div class="fs-dash-card"><div class="fsd-lbl"><i class="fa-solid fa-signature"></i> ตรวจ+ลงนามแล้ว</div>' +
                    '<div class="fsd-val">' + resp.verifiedDays + ' <span style="font-size:0.85rem; color:#94a3b8;">วัน</span></div>' +
                    '<div class="fsd-sub">' + (waitVerify > 0 ? '<span style="color:#c2410c; font-weight:700;">รอตรวจ ' + waitVerify + ' วัน</span>' : 'ครบทุกวันที่บันทึก') + '</div></div>' +
                '<div class="fs-dash-card"><div class="fsd-lbl"><i class="fa-solid fa-money-bill-wave"></i> ค่าปรับรวมเดือน</div>' +
                    '<div class="fsd-val" style="color:' + (resp.fineTotal > 0 ? '#b91c1c' : 'var(--secondary)') + ';">' + _fsFmt(resp.fineTotal) + ' <span style="font-size:0.85rem; color:#94a3b8;">บาท</span></div>' +
                    '<div class="fsd-sub">งวด 1: ' + _fsFmt(h1.fine || 0) + ' · งวด 2: ' + _fsFmt(h2.fine || 0) + '</div></div>' +
                '<div class="fs-dash-card"><div class="fsd-lbl"><i class="fa-solid fa-fingerprint"></i> อัตราแสกนเฉลี่ย</div>' +
                    '<div class="fsd-val" style="color:' + (scanRate >= 100 ? '#15803d' : (scanRate >= 90 ? '#c2410c' : '#b91c1c')) + ';">' + scanRate + '%</div>' +
                    '<div class="fsd-sub">ลงงาน ' + _fsFmt(resp.workers) + ' · แสกน ' + _fsFmt(resp.scans) + ' คน</div></div>' +
                '<div class="fs-dash-card"><div class="fsd-lbl"><i class="fa-solid fa-users"></i> ชุดที่ลงงาน</div>' +
                    '<div class="fsd-val">' + resp.crewsCount + ' <span style="font-size:0.85rem; color:#94a3b8;">ชุด</span></div>' +
                    '<div class="fsd-sub">เดือน ' + _fsMonthLabelOf(resp.ym) + '</div></div>' +
                '<div class="fs-dash-card"><div class="fsd-lbl"><i class="fa-solid fa-file-signature"></i> เซ็นรับทราบค่าปรับ</div>' +
                    '<div class="fsd-val">' + signTxt(h1) + ' <span style="font-size:0.85rem; color:#94a3b8;">· ง2</span> ' + signTxt(h2) + '</div>' +
                    '<div class="fsd-sub">เซ็นแล้ว+รับทราบโดยปริยาย / ชุดทั้งงวด (ง1 · ง2)</div></div>' +
                '<div class="fs-dash-card"><div class="fsd-lbl"><i class="fa-solid fa-triangle-exclamation"></i> แสกนเกินลงงาน</div>' +
                    '<div class="fsd-val" style="color:' + (resp.alertMonthPending > 0 ? '#c2410c' : 'var(--secondary)') + ';">' + resp.alertMonth + ' <span style="font-size:0.85rem; color:#94a3b8;">เคส</span></div>' +
                    '<div class="fsd-sub">' + (resp.alertMonthPending > 0 ? '<span style="color:#c2410c; font-weight:700;">รอชี้แจง ' + resp.alertMonthPending + ' เคส</span>' : 'ชี้แจงครบแล้ว') + '</div></div>' +
                '</div>';

             
            var days = resp.daily || [];
            var maxV = 1;
            days.forEach(function (x) { if (x.workers > maxV) maxV = x.workers; if (x.scans > maxV) maxV = x.scans; });
            html += '<div class="fs-dash-sec"><i class="fa-solid fa-chart-column"></i> ลงงาน / แสกน รายวัน</div>';
            if (resp.recordedDays > 0) {
                html += '<div class="fs-dash-bars">' + days.map(function (x) {
                    var dnum = parseInt(x.d.slice(8, 10), 10);
                    var tip = _fsThDate(x.d) + (x.recorded ? ' — ลงงาน ' + _fsFmt(x.workers) + ' · แสกน ' + _fsFmt(x.scans) + (x.fine > 0 ? ' · ปรับ ' + _fsFmt(x.fine) + ' บาท' : '') + (x.inc ? ' · กรอกไม่ครบ ' + x.inc + ' ชุด' : '') : ' — ไม่มีข้อมูล');
                    var hw = Math.round(x.workers / maxV * 100), hs = Math.round(x.scans / maxV * 100);
                    return '<div class="fs-dash-col' + (x.isToday ? ' fsd-today' : '') + '" title="' + escapeHtml(tip) + '">' +
                        '<div class="bars">' +
                        (x.recorded ? '<div class="b1" style="height:' + Math.max(hw, 2) + '%;"></div><div class="b2" style="height:' + Math.max(hs, 2) + '%;"></div>' : '') +
                        '</div><div class="dnum">' + dnum + '</div></div>';
                }).join('') + '</div>' +
                '<div class="fs-dash-legend">' +
                    '<span><b style="background:#93c5fd;"></b>ลงงาน</span>' +
                    '<span><b style="background:#34d399;"></b>แสกน</span>' +
                    '<span style="color:#94a3b8;">ชี้ที่แท่งเพื่อดูตัวเลขของวันนั้น</span></div>';
            } else {
                html += '<div class="qr-empty"><i class="fa-solid fa-circle-info"></i><span>ยังไม่มีข้อมูลบันทึกของเดือนนี้</span></div>';
            }

             
            if (resp.crews && resp.crews.length) {
                html += '<div class="fs-dash-sec"><i class="fa-solid fa-table-list"></i> สรุปรายชุด (ทั้งเดือน)</div>';
                html += '<div class="rate-table-wrap"><table class="rate-table"><thead><tr>' +
                    '<th>ชุดผู้รับเหมา</th>' +
                    '<th style="width:60px; text-align:center;">วัน</th>' +
                    '<th style="width:80px; text-align:right;">ลงงาน</th>' +
                    '<th style="width:80px; text-align:right;">แสกน</th>' +
                    '<th style="width:90px; text-align:center;">อัตรา</th>' +
                    '<th style="width:110px; text-align:right;">ค่าปรับ (บาท)</th>' +
                    '</tr></thead><tbody>';
                resp.crews.forEach(function (rec) {
                    var rateTxt = rec.rate === null ? '—' : (Math.round(rec.rate * 1000) / 10) + '%';
                    html += '<tr>' +
                        '<td class="rate-td-name">' + escapeHtml(rec.subName) + _fsMangoLine(rec) + (rec.noReportDays ? '<span style="display:block; font-size:0.7rem; color:#d97706;">ไม่แจ้งยอด ' + rec.noReportDays + ' วัน</span>' : '') + '</td>' +
                        '<td style="text-align:center;">' + rec.days + '</td>' +
                        '<td style="text-align:right;">' + _fsFmt(rec.workers) + '</td>' +
                        '<td style="text-align:right;">' + _fsFmt(rec.scans) + '</td>' +
                        '<td style="text-align:center;">' + rateTxt + '</td>' +
                        '<td style="text-align:right; font-weight:700;' + (rec.fine > 0 ? ' color:#b91c1c;' : '') + '">' + _fsFmt(rec.fine) + '</td>' +
                        '</tr>';
                });
                html += '</tbody></table></div>';
            }
            body.innerHTML = html;
        }

         
        function openFsSign(idx) {
            if (!_fsSum || !_fsSum.list || !_fsSum.list[idx]) return;
            _fsSignRec = _fsSum.list[idx];
            var sub = document.getElementById('fsSignSub');
            if (sub) sub.textContent = _fsMangoText(_fsSignRec) + ' · งวด' + _fsSum.periodLabel + ' · ค่าปรับ ' + _fsFmt(_fsSignRec.fine) + ' บาท';
            _fsSignRender();
            var bd = document.getElementById('fsSignBackdrop');
            if (bd) bd.classList.add('open');
        }
        function closeFsSign() {
            var bd = document.getElementById('fsSignBackdrop');
            if (bd) bd.classList.remove('open');
            _fsSignRec = null;
            loadFsSummary();    
        }
        function _fsSignRender() {
            var body = document.getElementById('fsSignBody');
            if (!body || !_fsSignRec) return;
            var s = _fsSignRec.sign;
            if (!s) {
                body.innerHTML =
                    '<label class="deduct-field"><span class="deduct-label">ครบกำหนดรับทราบ (auto-ack เมื่อเกินกำหนด)</span>' +
                    '<select id="fsSignDeadline" class="form-control">' +
                    '<option value="3" selected>3 วันหลังส่งลิงก์</option><option value="5">5 วัน</option>' +
                    '<option value="7">7 วัน</option><option value="0">ไม่กำหนด (รอจนกว่าจะเซ็น)</option>' +
                    '</select></label>' +
                    '<button type="button" class="btn btn-primary" style="width:100%; margin-top:0.7rem;" onclick="fsSignCreate()"><i class="fa-solid fa-link"></i> สร้างลิงก์เซ็นรับทราบ</button>';
                return;
            }
            if (s.status === 'signed') {
                body.innerHTML = '<div class="se-export-hint" style="color:#16a34a;"><i class="fa-solid fa-circle-check"></i> ลงนามแล้วโดย <b>' + escapeHtml(s.signerName || '-') + '</b></div>';
                return;
            }
            if (s.status === 'auto') {
                var autoUrl = (_fsSum.webAppUrl || '') + '?page=sign&token=' + (s.token || '');
                body.innerHTML = '<div class="se-export-hint" style="color:#d97706;"><i class="fa-solid fa-clock"></i> เกินกำหนด — ระบบบันทึกเป็น "รับทราบโดยปริยาย" แล้ว (ผู้รับเหมายังเปิดลิงก์เพื่อเซ็นจริงทับได้)</div>' +
                    (s.token
                        ? '<input type="text" id="fsSignUrl" class="form-control" readonly value="' + escapeHtml(autoUrl) + '" onclick="this.select()" style="margin-top:0.6rem; font-size:0.78rem;">' +
                          '<div style="display:grid; grid-template-columns:1fr 1fr; gap:0.5rem; margin-top:0.5rem;">' +
                          '<button type="button" class="btn btn-secondary" onclick="fsCopySignUrl()"><i class="fa-solid fa-copy"></i> คัดลอกลิงก์</button>' +
                          '<button type="button" class="btn btn-primary" onclick="fsOpenSignPage()"><i class="fa-solid fa-pen-nib"></i> เปิดหน้าเซ็น</button></div>'
                        : '');
                return;
            }
            var url = (_fsSum.webAppUrl || '') + '?page=sign&token=' + (s.token || '');
            body.innerHTML =
                '<label class="deduct-field"><span class="deduct-label">ลิงก์ให้ผู้รับเหมาเปิดเซ็นบนมือถือ</span>' +
                '<input type="text" id="fsSignUrl" class="form-control" readonly value="' + escapeHtml(url) + '" onclick="this.select()"></label>' +
                '<button type="button" class="btn btn-primary" style="width:100%; margin-top:0.6rem;" onclick="fsOpenSignPage()">' +
                    '<i class="fa-solid fa-pen-nib"></i> เปิดหน้าเซ็นให้เซ็นตรงนี้เลย</button>' +
                '<div style="display:grid; grid-template-columns:1fr 1fr; gap:0.5rem; margin-top:0.5rem;">' +
                '<button type="button" class="btn btn-secondary" onclick="fsCopySignUrl()"><i class="fa-solid fa-copy"></i> คัดลอกลิงก์</button>' +
                (s.sentAt
                    ? '<button type="button" class="btn btn-secondary" onclick="fsSignMarkSent(false)"><i class="fa-solid fa-rotate-left"></i> ยกเลิกเริ่มนับเวลา</button>'
                    : '<button type="button" class="btn btn-primary" onclick="fsSignMarkSent(true)"><i class="fa-solid fa-paper-plane"></i> ส่งให้ผู้รับเหมาแล้ว</button>') +
                '</div>' +
                '<div class="se-export-hint" style="margin-top:0.6rem;">' +
                (s.sentAt
                    ? '<i class="fa-solid fa-hourglass-half"></i> เริ่มนับเวลาแล้ว' + (s.deadlineAt ? ' — ครบกำหนด ' + new Date(s.deadlineAt).toLocaleDateString('th-TH') + ' (เกินแล้วถือว่ารับทราบโดยปริยาย)' : '')
                    : '<i class="fa-solid fa-circle-info"></i> กด "ส่งให้ผู้รับเหมาแล้ว" เพื่อเริ่มนับเวลาครบกำหนด' + (s.deadlineDays ? ' ' + s.deadlineDays + ' วัน' : '')) +
                '</div>' +
                '<button type="button" class="btn btn-secondary" style="width:100%; margin-top:0.6rem; color:#b91c1c;" onclick="fsSignCancel()"><i class="fa-solid fa-trash"></i> ยกเลิกลิงก์นี้</button>';
        }
        function fsSignCreate() {
            if (!_fsSignRec || !_fsSum) return;
            var dl = parseInt(((document.getElementById('fsSignDeadline') || {}).value || '0'), 10) || 0;
            showLoadingPopup('กำลังสร้างลิงก์', _fsSignRec.subName);
            google.script.run
                .withSuccessHandler(function (res) {
                    closeAppPopup();
                    if (!res || !res.success) { showInfoPopup('สร้างลิงก์ไม่สำเร็จ', (res && res.message) || 'เกิดข้อผิดพลาด', 'danger'); return; }
                    _fsSignRec.sign = res.contractor;
                    _fsSignRec.docNo = res.docNo;
                    _fsSignRender();
                })
                .withFailureHandler(function (err) { closeAppPopup(); showInfoPopup('สร้างลิงก์ไม่สำเร็จ', (err && err.message) || String(err || ''), 'danger'); })
                .createFingerScanLink({ siteCode: _fsSum.siteCode, ym: _fsSum.ym, half: _fsSum.half, subId: _fsSignRec.subId, deadlineDays: dl, username: user ? user.username : '' });
        }
        function fsSignMarkSent(sent) {
            if (!_fsSignRec) return;
            google.script.run
                .withSuccessHandler(function (res) {
                    if (!res || !res.success) { showToast((res && res.message) || 'ไม่สำเร็จ', 'danger'); return; }
                    _fsSignRec.sign = res.contractor;
                    _fsSignRender();
                    showToast(sent ? 'เริ่มนับเวลาครบกำหนดแล้ว' : 'ยกเลิกการนับเวลาแล้ว', 'success');
                })
                .withFailureHandler(function (err) { showToast((err && err.message) || 'ไม่สำเร็จ', 'danger'); })
                .setFingerScanSent({ docNo: _fsSignRec.docNo, sent: sent, username: user ? user.username : '' });
        }
        function fsSignCancel() {
            if (!_fsSignRec) return;
            if (!confirm('ยกเลิกลิงก์เซ็นของ "' + _fsSignRec.subName + '" ?')) return;
            google.script.run
                .withSuccessHandler(function (res) {
                    if (!res || !res.success) { showToast((res && res.message) || 'ยกเลิกไม่ได้', 'danger'); return; }
                    _fsSignRec.sign = null;
                    _fsSignRender();
                })
                .withFailureHandler(function (err) { showToast((err && err.message) || 'ไม่สำเร็จ', 'danger'); })
                .cancelFingerScanLink({ docNo: _fsSignRec.docNo, username: user ? user.username : '' });
        }
         
        function fsOpenSignPage() {
            var el = document.getElementById('fsSignUrl');
            var url = el ? (el.value || '') : '';
            if (!url) { showToast('ยังไม่มีลิงก์ของชุดนี้', 'info'); return; }
            var nm = (_fsSignRec && _fsSignRec.subName) || '';
            openSignWindow(url, nm + (_fsSum ? ' · งวด' + _fsSum.periodLabel : ''), function () {
                closeFsSign();    
            });
        }
        function fsCopySignUrl() {
            var el = document.getElementById('fsSignUrl');
            if (!el) return;
            el.select();
            el.setSelectionRange(0, 99999);
            var ok = false;
            try { ok = document.execCommand('copy'); } catch (e) {}
            if (!ok && navigator.clipboard) { try { navigator.clipboard.writeText(el.value); ok = true; } catch (e) {} }
            showToast(ok ? 'คัดลอกลิงก์แล้ว' : 'คัดลอกไม่สำเร็จ — เลือกข้อความแล้วคัดลอกเอง', ok ? 'success' : 'danger');
        }

         
         
        function _fsSignUrlOf(rec) {
            var s = rec && rec.sign;
            if (!_fsSum || !s || !s.token || s.status === 'signed' || s.status === 'auto') return '';
            return (_fsSum.webAppUrl || '') + '?page=sign&token=' + s.token;
        }
        function _fsRenderAllLinks() {
            var body = document.getElementById('fsAllLinksBody');
            if (!body || !_fsSum) return;
            var pending = 0, signed = 0, none = 0;
            var html = (_fsSum.list || []).map(function (rec) {
                var s = rec.sign, url = _fsSignUrlOf(rec), statusHtml, urlHtml = '';
                if (s && s.status === 'signed') { signed++; statusHtml = '<span style="color:#16a34a; font-size:0.8rem;"><i class="fa-solid fa-circle-check"></i> เซ็นแล้ว</span>'; }
                else if (s && s.status === 'auto') { signed++; statusHtml = '<span style="color:#d97706; font-size:0.8rem;"><i class="fa-solid fa-clock"></i> รับทราบโดยปริยาย</span>'; }
                else if (url) {
                    pending++;
                    statusHtml = '<span style="color:#2563eb; font-size:0.8rem;">' + (s.sentAt ? '<i class="fa-solid fa-paper-plane"></i> ส่งแล้ว·รอลงนาม' : '<i class="fa-solid fa-hourglass-half"></i> รอส่งลิงก์') + '</span>';
                    urlHtml = '<div class="fs-link-url"><input type="text" readonly value="' + escapeHtml(url) + '" onclick="this.select()">' +
                        '<button type="button" class="btn btn-secondary" title="คัดลอกลิงก์นี้" onclick="_fsCopyOneLink(\'' + escapeHtml(url) + '\')"><i class="fa-solid fa-copy"></i></button>' +
                        '<button type="button" class="btn btn-primary" title="เปิดหน้าเซ็นให้เซ็นตรงนี้เลย" onclick="_fsOpenOneLink(\'' + escapeHtml(url) + '\', \'' + escapeHtml((rec.subName || '').replace(/'/g, '')) + '\')"><i class="fa-solid fa-pen-nib"></i></button>' +
                        '</div>';
                }
                else { none++; statusHtml = '<span style="color:#94a3b8; font-size:0.8rem;">ยังไม่สร้างลิงก์</span>'; }
                return '<div class="fs-link-item"><div class="fs-link-head"><span class="fs-link-name">' + escapeHtml(rec.subName) + '</span> ' + statusHtml + '</div>' +
                    _fsMangoLine(rec) + urlHtml + '</div>';
            }).join('');
            body.innerHTML = html || '<div class="qr-empty"><i class="fa-solid fa-circle-info"></i><span>ไม่มีข้อมูล</span></div>';
            var sub = document.getElementById('fsAllLinksSub');
            if (sub) sub.textContent = 'งวด' + _fsSum.periodLabel + ' · มีลิงก์รอเซ็น ' + pending + ' ชุด' + (signed ? ' · เซ็น/รับทราบแล้ว ' + signed + ' ชุด' : '') + (none ? ' · ยังไม่สร้าง ' + none + ' ชุด' : '');
            var copyBtn = document.getElementById('fsAllLinksCopyAll');
            if (copyBtn) copyBtn.disabled = pending === 0;
        }
        function fsOpenAllLinks() {
            if (!_fsSum || !_fsSum.list || !_fsSum.list.length) { showToast('ยังไม่มีข้อมูลของงวดนี้', 'info'); return; }
            _fsRenderAllLinks();
            var bd = document.getElementById('fsAllLinksBackdrop');
            if (bd) bd.classList.add('open');
        }
        function closeFsAllLinks() {
            var bd = document.getElementById('fsAllLinksBackdrop');
            if (bd) bd.classList.remove('open');
        }
        function _fsCopyOneLink(url) {
            _esCopyText(url, function (ok) {
                if (ok) { hapticSuccess(); showToast('คัดลอกลิงก์แล้ว', 'success'); }
                else showToast('คัดลอกไม่สำเร็จ', 'danger');
            });
        }
         
        function _fsOpenOneLink(url, name) {
            openSignWindow(url, (name || '') + (_fsSum ? ' · งวด' + _fsSum.periodLabel : ''), function () {
                loadFsSummary();
                setTimeout(_fsRenderAllLinks, 600);    
            });
        }
        function fsCopyAllLinks() {
            if (!_fsSum) return;
            var lines = [];
            (_fsSum.list || []).forEach(function (rec) {
                var url = _fsSignUrlOf(rec);
                if (url) lines.push('• ' + rec.subName + '\n' + url);
            });
            if (!lines.length) { showToast('ไม่มีลิงก์ที่รอเซ็นให้คัดลอก', 'info'); return; }
            var txt = 'ลิงก์เซ็นรับทราบค่าปรับสแกนนิ้ว งวด' + _fsSum.periodLabel + '\n(เปิดลิงก์บนมือถือเพื่อลงลายเซ็น)\n\n' + lines.join('\n\n');
            _esCopyText(txt, function (ok) {
                if (ok) { hapticSuccess(); showToast('คัดลอกลิงก์ทั้งหมด ' + lines.length + ' ชุดแล้ว', 'success'); }
                else showToast('คัดลอกไม่สำเร็จ', 'danger');
            });
        }
         
        function fsCancelAllLinks() {
            if (!_fsSum || !_fsSum.list || !_fsSum.list.length) { showToast('ยังไม่มีข้อมูลของงวดนี้', 'info'); return; }
            var pending = _fsSum.list.filter(function (r) { return r.sign && r.sign.token && r.sign.status !== 'signed' && r.sign.status !== 'auto'; }).length;
            if (!pending) { showToast('ไม่มีลิงก์ที่รอตอบกลับให้ยกเลิก', 'info'); return; }
            if (!confirm('ยกเลิกลิงก์เซ็นที่ยังไม่ตอบกลับทั้งงวด' + _fsSum.periodLabel + ' (' + pending + ' ชุด) ?\nชุดที่เซ็น/รับทราบแล้วจะไม่ถูกยกเลิก')) return;
            showLoadingPopup('กำลังยกเลิกลิงก์ทั้งหมด', _fsSum.periodLabel);
            google.script.run
                .withSuccessHandler(function (res) {
                    closeAppPopup();
                    if (!res || !res.success) { showInfoPopup('ยกเลิกไม่สำเร็จ', (res && res.message) || 'เกิดข้อผิดพลาด', 'danger'); return; }
                    hapticSuccess();
                    showToast(res.message || 'ยกเลิกลิงก์แล้ว', 'success');
                    closeFsAllLinks();
                    loadFsSummary();    
                })
                .withFailureHandler(function (err) {
                    closeAppPopup();
                    showInfoPopup('ยกเลิกไม่สำเร็จ', (err && err.message) || String(err || ''), 'danger');
                })
                .cancelAllFingerScanLinks({ siteCode: _fsSum.siteCode, ym: _fsSum.ym, half: _fsSum.half, username: user ? user.username : '' });
        }

         
        function fsExportPdf() {
            if (!_fsSum || !_fsSum.list || !_fsSum.list.length) { showToast('ยังไม่มีข้อมูลของงวดนี้', 'info'); return; }
            if (!confirm('พิมพ์ PDF สรุปค่าปรับสแกนนิ้ว งวด' + _fsSum.periodLabel + ' · Site ' + _fsSum.siteCode + ' ?\n(ลายเซ็นชุดที่เซ็นออนไลน์แล้วจะถูกฝังลงเอกสาร)')) return;
            showLoadingPopup('กำลังสร้าง PDF', 'สรุปค่าปรับสแกนนิ้ว งวด' + _fsSum.periodLabel + '\nกรุณารอสักครู่...');
            google.script.run
                .withSuccessHandler(function (res) {
                    closeAppPopup();
                    if (!res || !res.success || !res.dataUri) {
                        showInfoPopup('สร้าง PDF ไม่สำเร็จ', (res && res.message) || 'เกิดข้อผิดพลาด', 'danger');
                        return;
                    }
                    var a = document.createElement('a');
                    a.href = res.dataUri; a.download = res.fileName || 'fingerscan.pdf'; a.style.display = 'none';
                    document.body.appendChild(a); a.click(); document.body.removeChild(a);
                    hapticSuccess();
                    showToast('ดาวน์โหลด PDF แล้ว', 'success');
                })
                .withFailureHandler(function (err) {
                    closeAppPopup();
                    showInfoPopup('สร้าง PDF ไม่สำเร็จ', (err && err.message) || String(err || ''), 'danger');
                })
                .generateFingerScanPDF({
                    siteCode: _fsSum.siteCode, ym: _fsSum.ym, half: _fsSum.half,
                    preparerName: (user && (user.fullName || user.name)) || '',
                    preparerPos: (user && user.roleId) || '',
                    username: user ? user.username : ''
                });
        }

         
        function fsGenAllLinks() {
            if (!_fsSum || !_fsSum.list || !_fsSum.list.length) { showToast('ยังไม่มีข้อมูลของงวดนี้', 'info'); return; }
            var pending = _fsSum.list.filter(function (r) { return !r.sign || (r.sign.status !== 'signed' && r.sign.status !== 'auto'); }).length;
            if (!pending) { showToast('ทุกชุดมีลิงก์/เซ็นครบแล้ว', 'info'); return; }
            var sub = document.getElementById('fsGenAllSub');
            if (sub) sub.textContent = 'งวด' + _fsSum.periodLabel;
            var pd = document.getElementById('fsGenAllPending');
            if (pd) pd.textContent = pending + ' ชุด';
            fsGenAllSetDays(3);    
            var bd = document.getElementById('fsGenAllBackdrop');
            if (bd) bd.classList.add('open');
            var inp = document.getElementById('fsGenAllDays');
            if (inp) setTimeout(function () { try { inp.focus(); inp.select(); } catch (e) {} }, 250);
        }
        function closeFsGenAll() {
            var bd = document.getElementById('fsGenAllBackdrop');
            if (bd) bd.classList.remove('open');
        }
        function fsGenAllStep(delta) {
            hapticTap();
            var el = document.getElementById('fsGenAllDays');
            var v = parseInt(el && el.value, 10); if (isNaN(v)) v = 0;
            fsGenAllSetDays(v + delta);
        }
        function fsGenAllSetDays(val) {
            var v = parseInt(val, 10); if (isNaN(v) || v < 0) v = 0; if (v > 60) v = 60;
            var el = document.getElementById('fsGenAllDays');
            if (el) el.value = v;
            _fsGenAllRender();
        }
         
        function _fsGenAllRender() {
            var el = document.getElementById('fsGenAllDays');
            var v = parseInt(el && el.value, 10); if (isNaN(v) || v < 0) v = 0;
            if (v > 60) { v = 60; if (el) el.value = 60; }
            document.querySelectorAll('#fsGenAllBackdrop .qty-quick-btn').forEach(function (b) {
                b.classList.toggle('active', parseInt(b.getAttribute('data-days'), 10) === v);
            });
            var hint = document.getElementById('fsGenAllHint');
            if (hint) {
                if (v === 0) {
                    hint.className = 'fs-genall-hint muted';
                    hint.innerHTML = '<i class="fa-solid fa-infinity"></i><span>ไม่กำหนดเวลา — ผู้รับเหมาเซ็นรับทราบเมื่อใดก็ได้ ไม่มีการรับทราบโดยปริยาย</span>';
                } else {
                    hint.className = 'fs-genall-hint';
                    hint.innerHTML = '<i class="fa-solid fa-circle-info"></i><span>หากไม่เซ็นภายใน <b>' + v + ' วัน</b> หลังส่งลิงก์ ระบบจะถือว่า <b>รับทราบโดยปริยาย</b> ให้อัตโนมัติ</span>';
                }
            }
        }
        function confirmFsGenAll() {
            if (!_fsSum) { closeFsGenAll(); return; }
            var el = document.getElementById('fsGenAllDays');
            var days = parseInt(el && el.value, 10); if (isNaN(days) || days < 0) days = 0; if (days > 60) days = 60;
            closeFsGenAll();
            showLoadingPopup('กำลังสร้างลิงก์เซ็นทั้งหมด', _fsSum.periodLabel);
            google.script.run
                .withSuccessHandler(function (res) {
                    closeAppPopup();
                    if (!res || !res.success) { showInfoPopup('สร้างลิงก์ไม่สำเร็จ', (res && res.message) || 'เกิดข้อผิดพลาด', 'danger'); return; }
                    hapticSuccess();
                    showToast(res.message || 'สร้างลิงก์เซ็นแล้ว', 'success');
                    loadFsSummary();    
                })
                .withFailureHandler(function (err) {
                    closeAppPopup();
                    showInfoPopup('สร้างลิงก์ไม่สำเร็จ', (err && err.message) || String(err || ''), 'danger');
                })
                .createAllFingerScanLinks({ siteCode: _fsSum.siteCode, ym: _fsSum.ym, half: _fsSum.half, deadlineDays: days, username: user ? user.username : '' });
        }

         
         
        function _fsRateCtx() {
            if (_fsSub === 'summary' && _fsSum) return { site: _fsSum.siteCode, rate: _fsSum.rate };
            if (_fsData) return { site: _fsData.siteCode, rate: _fsData.rate };
            if (_fsSum) return { site: _fsSum.siteCode, rate: _fsSum.rate };
            return { site: '', rate: '' };
        }
        function openFsRate() {
            var ctx = _fsRateCtx();
            if (!ctx.site) { showToast('กรุณารอหน้าโหลดเสร็จก่อน', 'info'); return; }
            var sub = document.getElementById('fsRateSub');
            if (sub) sub.textContent = 'Site ' + ctx.site + ' · อัตราปัจจุบัน ' + ctx.rate + ' บาท/คน/วัน';
            var inp = document.getElementById('fsRateInput');
            if (inp) inp.value = ctx.rate;
            var bd = document.getElementById('fsRateBackdrop');
            if (bd) bd.classList.add('open');
        }
        function closeFsRate() {
            var bd = document.getElementById('fsRateBackdrop');
            if (bd) bd.classList.remove('open');
        }
        function saveFsRate() {
            var ctx = _fsRateCtx();
            var v = _fsNum((document.getElementById('fsRateInput') || {}).value);
            if (!ctx.site || v === '' || v < 0) { showToast('กรุณาระบุอัตราเป็นตัวเลข 0 ขึ้นไป', 'danger'); return; }
            google.script.run
                .withSuccessHandler(function (res) {
                    if (!res || !res.success) { showToast((res && res.message) || 'บันทึกไม่สำเร็จ', 'danger'); return; }
                    closeFsRate();
                    showToast('บันทึกอัตราค่าปรับ ' + res.rate + ' บาท/คน/วัน แล้ว', 'success');
                    if (_fsSub === 'summary') loadFsSummary(); else loadFingerScanPage();
                })
                .withFailureHandler(function (err) { showToast((err && err.message) || 'บันทึกไม่สำเร็จ', 'danger'); })
                .saveFingerScanRate({ siteCode: ctx.site, rate: v, username: user ? user.username : '' });
        }

         
        var _scfgBlocked = [];    

        function _scfgFmtBaht(n) {
            var v = parseFloat(n);
            if (isNaN(v) || v === 0) return '0';
            return v.toLocaleString('en-US', { maximumFractionDigits: 2 });
        }

         
        function _scfgClosePlan(r) {
            r = r || {};
            var lines = [];
            var cps = r.chargePeriods || [];
            if (cps.length) {
                lines.push('ออกเลขเอกสารหักเงิน ' + cps.length + ' งวด: ' +
                           cps.map(function (p) { return p.label + ' (' + p.count + ' รายการ)'; }).join(', ') +
                           ' — ออกให้ทุกชุดที่มีรายการในงวดนั้น ไม่ใช่เฉพาะชุดนี้');
            }
            var fine = parseFloat(r.fsFine) || 0, mat = parseFloat(r.matAmt) || 0;
            if (fine > 0 || mat > 0) {
                lines.push((r.seExists ? 'เติมยอดหักคจช.งวด' : 'สร้างแถวหักคจช.งวด') + ((r.cur && r.cur.label) || 'ปัจจุบัน') +
                           ' — ค่าปรับสแกนนิ้ว ' + _scfgFmtBaht(fine) + ' + ค่าวัสดุ ' + _scfgFmtBaht(mat) + ' บ.' +
                           (r.seExists ? ' (เติมเฉพาะช่องที่ยังว่าง ไม่ทับของที่กรอกไว้)' : ''));
            } else if (r.fsRows > 0) {
                lines.push('สแกนนิ้วงวดนี้ ' + r.fsRows + ' วัน — ไม่มียอดต้องหัก จึงไม่สร้างแถวหักคจช.');
            }
            if (r.borrowOpen > 0) {
                lines.push('ของยืมค้าง ' + r.borrowOpen + ' รายการ — <b>ไม่แตะสถานะ</b> ยังค้างเป็นของชุดนี้ คืนทีหลังได้ตามปกติ');
            }
            if (!lines.length) lines.push('ไม่มีอะไรต้องเคลียร์ — ปิดชุดได้เลย');
            return lines;
        }

        function openScfgRemap(blocked) {
            var body = document.getElementById('scfgRemapBody');
            if (!body) return;
            _scfgBlocked = blocked || [];
             
            var actives = [];
            document.querySelectorAll('#scfgBody .scfg-row').forEach(function (tr) {
                var cb = tr.querySelector('input[type="checkbox"]');
                if (cb && cb.checked) actives.push({ subId: tr.getAttribute('data-subid'), subName: tr.getAttribute('data-name') });
            });
            body.innerHTML = _scfgBlocked.map(function (b, i) {
                var r = b.refs || {};
                var opts = actives.filter(function (a) { return a.subId !== b.subId; }).map(function (a) {
                    return '<option value="' + escapeHtml(a.subId) + '">' + escapeHtml(a.subName + ' (' + a.subId + ')') + '</option>';
                }).join('');
                var old = (r.fsOldPeriods || []).map(function (p) { return p.label + ' (' + _scfgFmtBaht(p.fine) + ' บ.)'; });
                return '<div class="rate-alert" id="scfgRemapItem' + i + '" style="flex-direction:column; align-items:stretch; gap:0.5rem; background:#fffbeb; border-color:#fde68a; color:#78350f; margin-bottom:0.7rem;">' +
                    '<div><b>' + escapeHtml(b.subName) + '</b> <span style="color:#a16207;">(SubID ' + escapeHtml(b.subId) + ')</span><br>' +
                    '<span style="font-size:0.78rem;">งานค้าง: ยืมไม่คืน <b>' + (r.borrowOpen || 0) + '</b> · เบิกหักเงินยังไม่ออกเอกสาร <b>' + (r.chargeOpen || 0) + '</b> · สแกนนิ้วงวดนี้ <b>' + (r.fsRows || 0) + '</b> · หักคจช.งวดนี้ <b>' + (r.seRows || 0) + '</b></span></div>' +
                     
                    '<div style="background:#fff; border:1px solid #fde68a; border-radius:0.5rem; padding:0.5rem 0.6rem;">' +
                      '<div style="font-size:0.78rem; font-weight:700; margin-bottom:0.3rem;">กด "ปิดเลย" แล้วระบบจะ:</div>' +
                      '<ul style="margin:0 0 0.5rem 0; padding-left:1.05rem; font-size:0.76rem; line-height:1.5;">' +
                        _scfgClosePlan(r).map(function (t) { return '<li>' + t + '</li>'; }).join('') +
                      '</ul>' +
                      (old.length
                        ? '<div style="font-size:0.74rem; color:#b91c1c; margin-bottom:0.45rem;"><i class="fa-solid fa-circle-exclamation"></i> งวดเก่าที่มีค่าปรับสแกนนิ้วแต่ยังไม่เคยถูกหัก: ' + escapeHtml(old.join(', ')) + ' — ระบบไม่แตะย้อนหลังให้ ต้องไปจัดการเองที่หน้าหักคจช.</div>'
                        : '') +
                      '<button type="button" class="btn btn-primary" style="width:100%;" onclick="scfgCloseGo(' + i + ')"><i class="fa-solid fa-circle-check"></i> ปิดเลย (ระบบจัดการให้)</button>' +
                    '</div>' +
                     
                    (opts
                        ? '<div style="display:flex; gap:0.5rem; align-items:center;">' +
                          '<select class="form-control" id="scfgRemapTo' + i + '" style="flex:1;"><option value="">— หรือเลือกชุด active รับโอน —</option>' + opts + '</select>' +
                          '<button type="button" class="btn btn-secondary" style="width:auto; white-space:nowrap;" onclick="scfgRemapGo(' + i + ', \'' + escapeHtml(b.subId) + '\')"><i class="fa-solid fa-right-left"></i> โอน + ปิดชุด</button></div>'
                        : '<div style="font-size:0.76rem; color:#a16207;">ไม่มีชุด active อื่นในไซต์ให้รับโอน — ใช้ "ปิดเลย" ได้ตามปกติ</div>') +
                    '</div>';
            }).join('');
            var bd = document.getElementById('scfgRemapBackdrop');
            if (bd) bd.classList.add('open');
        }

         
        function scfgCloseGo(i) {
            var b = _scfgBlocked[i];
            if (!b) return;
            var r = b.refs || {};
             
            var msg = 'ปิดชุด <b>' + escapeHtml(b.subName) + '</b> (SubID ' + escapeHtml(b.subId) + ')<br><br>' +
                      'ระบบจะทำให้อัตโนมัติ:' +
                      '<ol style="text-align:left; margin:0.4rem 0 0.6rem 0; padding-left:1.2rem; line-height:1.55;">' +
                      _scfgClosePlan(r).map(function (t) { return '<li>' + t + '</li>'; }).join('') +
                      '</ol>' +
                      '<b style="color:#b91c1c;">เลขเอกสารที่ออกแล้วยกเลิกไม่ได้</b> — ยืนยันหรือไม่ ?';
            showConfirmPopup('ยืนยันปิดชุด', msg, function () {
                showLoadingPopup('กำลังปิดชุด', 'ออกเอกสาร + บันทึกยอดหักเงิน<br>กรุณารอสักครู่...');
                google.script.run
                    .withSuccessHandler(function (res) {
                        closeAppPopup();
                        if (!res || !res.success) {
                            showInfoPopup('ปิดชุดไม่สำเร็จ', (res && res.message === 'no_permission') ? 'คุณไม่มีสิทธิ์ปิดชุด' : ((res && res.message) || 'เกิดข้อผิดพลาด'), 'danger');
                            return;
                        }
                        hapticSuccess();
                        var item = document.getElementById('scfgRemapItem' + i);
                        if (item) item.innerHTML = '<div style="color:#166534;"><i class="fa-solid fa-circle-check"></i> ' + escapeHtml(res.message || 'ปิดชุดแล้ว') + '</div>';
                    })
                    .withFailureHandler(function (err) {
                        closeAppPopup();
                        showInfoPopup('ปิดชุดไม่สำเร็จ', (err && err.message) || String(err || ''), 'danger');
                    })
                    .closeSubcontractorSettle({
                        siteCode: _scfgData ? _scfgData.siteCode : '',
                        subId: b.subId,
                        username: user ? user.username : ''
                    });
            }, 'ปิดเลย', 'btn btn-primary');
        }
        function closeScfgRemap() {
            var bd = document.getElementById('scfgRemapBackdrop');
            if (bd) bd.classList.remove('open');
            loadScfg();    
        }
        function scfgRemapGo(i, fromSubId) {
            var sel = document.getElementById('scfgRemapTo' + i);
            var toSubId = sel ? sel.value : '';
            if (!toSubId) { showToast('กรุณาเลือกชุดปลายทางก่อน', 'danger'); return; }
            var toName = sel.options[sel.selectedIndex].text;
             
            showConfirmPopup('ยืนยันโอน + ปิดชุด',
                'โอน <b>ประวัติทั้งหมด</b> (เบิก/ยืม/หักเงิน/สแกนนิ้ว ทุกช่วงเวลา) ไปยัง <b>' + escapeHtml(toName) + '</b> แล้วปิดชุดต้นทาง ?<br><br>' +
                'เอกสารที่พิมพ์/เซ็นไปแล้วจะยังเป็นชื่อเดิม · มีบันทึก audit (RemapLogs) ทุกครั้ง',
                function () { _scfgRemapRun(i, fromSubId, toSubId); }, 'โอน + ปิดชุด', 'btn btn-primary');
        }
        function _scfgRemapRun(i, fromSubId, toSubId) {
            showLoadingPopup('กำลังโอนงานค้าง', 'กรุณารอสักครู่...');
            google.script.run
                .withSuccessHandler(function (res) {
                    closeAppPopup();
                    if (!res || !res.success) { showInfoPopup('โอนไม่สำเร็จ', (res && res.message) || 'เกิดข้อผิดพลาด', 'danger'); return; }
                    hapticSuccess();
                    var item = document.getElementById('scfgRemapItem' + i);
                    if (item) item.innerHTML = '<div style="color:#166534;"><i class="fa-solid fa-circle-check"></i> ' + escapeHtml(res.message || 'โอนและปิดชุดแล้ว') + '</div>';
                })
                .withFailureHandler(function (err) {
                    closeAppPopup();
                    showInfoPopup('โอนไม่สำเร็จ', (err && err.message) || String(err || ''), 'danger');
                })
                .remapSubcontractor({ siteCode: _scfgData ? _scfgData.siteCode : '', fromSubId: fromSubId, toSubId: toSubId, username: user ? user.username : '' });
        }

         
        var _deductExportYm = '';

         
         
        function _deductSubsForMonth(ym, half) {
            var counts = {};
            if (_dailyCheckData && _dailyCheckData.days) {
                _dailyCheckData.days.forEach(function (day) {
                    var dk = ((day.day || '') + '');
                    if (dk.slice(0, 7) !== ym) return;
                    if (half === 1 || half === 2) {
                        var dom = parseInt(dk.slice(8, 10), 10);
                        if (half === 1 ? dom > 15 : dom < 16) return;
                    }
                    (day.items || []).forEach(function (it) {
                        if (!it.charge) return;
                        var sn = (it.subName || '').toString().trim();
                        if (!sn || sn === '-') return;    
                        counts[sn] = (counts[sn] || 0) + 1;
                    });
                });
            }
            return Object.keys(counts).sort(function (a, b) { return a.localeCompare(b, 'th'); })
                .map(function (sn) { return { name: sn, count: counts[sn] }; });
        }
        function _deductExporter() { return (user && (user.fullName || user.name || user.username)) || ''; }
        function _deductExporterDept() { return (user && (user.roleId || user.roleName || user.roleLevel)) || ''; }

        function _deductRenderList(subs) {
            var list = document.getElementById('deductSubList');
            if (!list) return;
            if (!subs.length) {
                list.innerHTML = '<div style="color:var(--text-muted); font-size:0.85rem; padding:0.3rem 0;">— ไม่มีรายการหักเงินในเดือนนี้ —</div>';
                return;
            }
            list.innerHTML = subs.map(function (s) {
                return '<label class="deduct-radio"><input type="checkbox" class="deduct-sub-cb" value="' + escapeHtml(s.name) + '" checked onchange="_deductSyncAll()"> ' +
                    escapeHtml(s.name) + ' <span style="color:var(--text-muted); font-size:0.8rem;">(' + s.count + ' รายการ)</span></label>';
            }).join('');
        }
        function _deductToggleAll(master) {
            document.querySelectorAll('#deductSubList .deduct-sub-cb').forEach(function (cb) { cb.checked = master.checked; });
        }
        function _deductSyncAll() {
            var all = document.querySelectorAll('#deductSubList .deduct-sub-cb');
            var checked = document.querySelectorAll('#deductSubList .deduct-sub-cb:checked');
            var master = document.getElementById('deductSubAll');
            if (master) master.checked = all.length > 0 && checked.length === all.length;
        }

         
        function _deductDaysForMonth(ym) {
            var counts = {};
            if (_dailyCheckData && _dailyCheckData.days) {
                _dailyCheckData.days.forEach(function (day) {
                    var dk = (day.day || '').toString();
                    if (dk.slice(0, 7) !== ym) return;
                    (day.items || []).forEach(function (it) {
                        if (!it.charge) return;
                        var sn = (it.subName || '').toString().trim();
                        if (!sn || sn === '-') return;    
                        counts[dk] = (counts[dk] || 0) + 1;
                    });
                });
            }
            return Object.keys(counts).sort().map(function (dk) { return { day: dk, count: counts[dk] }; });
        }
         
         
         
        function _deductHalfStats(ym) {
            var out = { 1: { days: 0, items: 0 }, 2: { days: 0, items: 0 } };
            _deductDaysForMonth(ym).forEach(function (d) {
                var dom = parseInt(d.day.slice(8, 10), 10);
                var h = dom <= 15 ? 1 : 2;
                out[h].days++;
                out[h].items += d.count;
            });
            return out;
        }
        function _deductSelHalf() {
            var r = document.querySelector('input[name="deductHalf"]:checked');
            return r ? parseInt(r.value, 10) : 0;
        }
         
        function _deductHalfChanged() {
            var half = _deductSelHalf();
            [1, 2].forEach(function (h) {
                var opt = document.getElementById('deductHalfOpt' + h);
                if (opt) opt.classList.toggle('sel', half === h);
            });
            var subs = _deductSubsForMonth(_deductExportYm, half);
            _deductRenderList(subs);
            var master = document.getElementById('deductSubAll');
            if (master) master.checked = subs.length > 0;
        }

        function openDeductExport(ym) {
            _deductExportYm = ym;
            var sub = document.getElementById('deductExportSub');
            var nameEl = document.getElementById('deductExporterName');
            if (nameEl) nameEl.textContent = _deductExporter() || '(ไม่มีชื่อ)';
            if (sub) sub.textContent = 'ประจำเดือน ' + _dcThaiMonth(ym) + ' · เลือกงวด ชุด และผู้ลงนาม';

             
            var st = _deductHalfStats(ym);
            [1, 2].forEach(function (h) {
                var cnt = document.getElementById('deductHalfCount' + h);
                if (cnt) cnt.textContent = st[h].items ? (st[h].days + ' วัน · ' + st[h].items + ' รายการ') : 'ไม่มีรายการหักเงิน';
                var opt = document.getElementById('deductHalfOpt' + h);
                if (opt) opt.classList.toggle('disabled', !st[h].items);
            });
            var defHalf = 0;
            if (st[1].items && st[2].items) {
                 
                var today = new Date();
                var curYm = today.getFullYear() + '-' + ('0' + (today.getMonth() + 1)).slice(-2);
                defHalf = (ym === curYm && today.getDate() <= 15) ? 1 : 2;
            } else if (st[1].items) defHalf = 1;
            else if (st[2].items) defHalf = 2;
            var rd = document.querySelector('input[name="deductHalf"][value="' + defHalf + '"]');
            if (rd) rd.checked = true;
            else document.querySelectorAll('input[name="deductHalf"]').forEach(function (x) { x.checked = false; });
            _deductHalfChanged();

            var ip = document.getElementById('deductInspectorPos'); if (ip) ip.value = 'PE / SSE';
            var ap = document.getElementById('deductApproverPos'); if (ap) ap.value = 'PM';
            var rn = document.querySelector('input[name="deductSummarizer"][value="name"]'); if (rn) rn.checked = true;
            deductModalTab('export');    
            var bd = document.getElementById('deductExportBackdrop'); if (bd) bd.classList.add('open');
        }
        function closeDeductExport() {
            var bd = document.getElementById('deductExportBackdrop'); if (bd) bd.classList.remove('open');
        }
        function submitDeductExport() {
            var half = _deductSelHalf();
            if (half !== 1 && half !== 2) { showToast('กรุณาเลือกงวด (วันที่ 1–15 หรือ 16–สิ้นเดือน)', 'danger'); return; }
            var subNames = Array.prototype.map.call(
                document.querySelectorAll('#deductSubList .deduct-sub-cb:checked'),
                function (cb) { return cb.value; });
            if (!subNames.length) { showToast('กรุณาเลือกอย่างน้อย 1 ชุด', 'danger'); return; }
            var summ = document.querySelector('input[name="deductSummarizer"]:checked');
            var showSummarizer = !summ || summ.value === 'name';
            var payload = {
                siteCode: getEffectiveSiteCode(),
                ym: _deductExportYm,
                subNames: subNames,
                half: half,                
                days: null,
                showSummarizer: showSummarizer,
                summarizerName: _deductExporter(),
                summarizerDept: _deductExporterDept(),
                inspectorPos: (document.getElementById('deductInspectorPos') || {}).value || 'PE / SSE',
                approverPos: (document.getElementById('deductApproverPos') || {}).value || 'PM',
                projectName: '',
                username: user ? user.username : ''
            };
            closeDeductExport();
            _deductRunExport(payload);
        }
         
         
         
        function _deductRunExport(payload) {
            payload.confirmOverlap = true;
            var nSub = (payload.subNames || []).length;
            var nDoc = (payload.docNos || []).length;
            var goBtn = document.getElementById('deductExportGo');
            if (goBtn) goBtn.disabled = true;
            showLoadingPopup('กำลังสร้างเอกสารหักเงิน PDF',
                (nDoc
                    ? 'กำลังรวมเอกสาร ' + nDoc + ' ใบ เป็น PDF ไฟล์เดียว (ใช้เลขที่เดิมทุกใบ)'
                    : 'กำลังรวบรวมรายการ ' + nSub + ' ชุด (' + nSub + ' หน้า)' +
                      (payload.half === 1 ? ' · งวดที่ 1 (วันที่ 1–15)'
                          : payload.half === 2 ? ' · งวดที่ 2 (วันที่ 16–สิ้นเดือน)'
                          : payload.days ? ' · เฉพาะ ' + payload.days.length + ' วัน' : ' · ทั้งเดือน')) +
                '\nกรุณารอสักครู่...');
            google.script.run
                .withSuccessHandler(function (res) {
                    closeAppPopup();
                    if (goBtn) goBtn.disabled = false;
                    if (!res || !res.success) {
                        showInfoPopup('สร้างเอกสารไม่สำเร็จ', (res && res.message) ? res.message : 'ไม่สามารถสร้างเอกสารได้', 'danger');
                        return;
                    }
                    if (res.dataUri) {
                        var a = document.createElement('a');
                        a.href = res.dataUri; a.download = res.fileName || 'deduction.pdf'; a.style.display = 'none';
                        document.body.appendChild(a); a.click();
                        setTimeout(function () { try { document.body.removeChild(a); } catch (e) {} }, 300);
                        showToast('ดาวน์โหลดเอกสารหักเงินแล้ว', 'success');
                    }
                })
                .withFailureHandler(function (err) {
                    closeAppPopup();
                    if (goBtn) goBtn.disabled = false;
                    showInfoPopup('สร้างเอกสารไม่สำเร็จ', (err && err.message) ? err.message : String(err || 'unknown'), 'danger');
                })
                .generateDeductionPDF(payload);
        }

         
         
         
         
         
         
         
         
         

         
        var _mySigData = { loaded: false, dataUrl: '' };    

        function loadMySignature(cb) {
            if (!PORT_PHASE.signature) { if (cb) cb(); return; }    
            if (!user || !user.username) { if (cb) cb(); return; }
            google.script.run
                .withSuccessHandler(function (res) {
                    _mySigData = { loaded: true, dataUrl: (res && res.success && res.dataUrl) ? res.dataUrl : '' };
                    renderUsSigPreview();
                    if (cb) cb();
                })
                .withFailureHandler(function () { if (cb) cb(); })
                .getMySignature(user.username);
        }

        function renderUsSigPreview() {
            var box = document.getElementById('usSigPreview');
            var delBtn = document.getElementById('usSigDeleteBtn');
            if (!box) return;
            if (!_mySigData.loaded) {
                box.innerHTML = '<span class="sig-preview-empty"><i class="fa-solid fa-spinner fa-spin"></i> กำลังโหลด...</span>';
            } else if (_mySigData.dataUrl) {
                box.innerHTML = '<img src="' + _mySigData.dataUrl + '" alt="ลายเซ็นของฉัน">';
            } else {
                box.innerHTML = '<span class="sig-preview-empty"><i class="fa-solid fa-pen-nib"></i> ยังไม่ได้ตั้งลายเซ็น — วาดหรือนำเข้ารูปด้านล่าง</span>';
            }
            if (delBtn) delBtn.style.display = _mySigData.dataUrl ? '' : 'none';
        }

        function usDrawSignature() {
            openSigPad({
                onSave: function (dataUrl) { _saveSigDataUrl(dataUrl); }
            });
        }

        function _saveSigDataUrl(dataUrl) {
            if (!user || !user.username) return;
            showLoadingPopup('กำลังบันทึกลายเซ็น', 'กรุณารอสักครู่...');
            google.script.run
                .withSuccessHandler(function (res) {
                    closeAppPopup();
                    if (!res || !res.success) {
                        showInfoPopup('บันทึกลายเซ็นไม่สำเร็จ', (res && res.message) || 'กรุณาลองใหม่', 'danger');
                        return;
                    }
                    _mySigData = { loaded: true, dataUrl: dataUrl };
                    renderUsSigPreview();
                    _signTaskRefreshSigBox();
                    showToast('บันทึกลายเซ็นแล้ว', 'success');
                })
                .withFailureHandler(function (err) {
                    closeAppPopup();
                    showInfoPopup('บันทึกลายเซ็นไม่สำเร็จ', (err && err.message) || 'กรุณาลองใหม่', 'danger');
                })
                .saveMySignature({ username: user.username, dataUrl: dataUrl });
        }

        function deleteMySignatureClient() {
            showConfirmPopup('ลบลายเซ็น', 'ต้องการลบลายเซ็นที่บันทึกไว้ใช่หรือไม่?\nเอกสารที่ออกหลังจากนี้จะไม่มีลายเซ็นของคุณฝังอัตโนมัติ', function () {
                google.script.run
                    .withSuccessHandler(function (res) {
                        if (!res || !res.success) { showToast('ลบไม่สำเร็จ', 'danger'); return; }
                        _mySigData = { loaded: true, dataUrl: '' };
                        renderUsSigPreview();
                        _signTaskRefreshSigBox();
                        showToast('ลบลายเซ็นแล้ว', 'success');
                    })
                    .withFailureHandler(function () { showToast('ลบไม่สำเร็จ', 'danger'); })
                    .deleteMySignature({ username: user.username });
            }, 'ลบลายเซ็น', 'btn btn-reject');
        }

         
        function handleSigImport(input) {
            var file = input && input.files && input.files[0];
            if (input) input.value = '';    
            if (!file) return;
            if (!/^image\//.test(file.type)) { showToast('กรุณาเลือกไฟล์รูปภาพ', 'danger'); return; }
            var reader = new FileReader();
            reader.onload = function (ev) {
                var img = new Image();
                img.onload = function () {
                    var dataUrl = _sigNormalizeImage(img);
                    if (!dataUrl) { showToast('ประมวลผลรูปไม่สำเร็จ ลองรูปอื่น', 'danger'); return; }
                    _saveSigDataUrl(dataUrl);
                };
                img.onerror = function () { showToast('เปิดรูปไม่สำเร็จ', 'danger'); };
                img.src = ev.target.result;
            };
            reader.readAsDataURL(file);
        }

         
         
        function _sigNormalizeImage(img) {
            var sizes = [[400, 160], [320, 128], [240, 96]];
            for (var s = 0; s < sizes.length; s++) {
                var W = sizes[s][0], H = sizes[s][1];
                var cv = document.createElement('canvas');
                cv.width = W; cv.height = H;
                var ctx = cv.getContext('2d');
                var scale = Math.min(W / img.width, H / img.height);
                var dw = img.width * scale, dh = img.height * scale;
                ctx.drawImage(img, (W - dw) / 2, (H - dh) / 2, dw, dh);
                try {
                    var px = ctx.getImageData(0, 0, W, H);
                    var d = px.data;
                    for (var i = 0; i < d.length; i += 4) {
                         
                        if (d[i] > 232 && d[i + 1] > 232 && d[i + 2] > 232) d[i + 3] = 0;
                    }
                    ctx.putImageData(px, 0, 0);
                } catch (e) {   }
                var out = cv.toDataURL('image/png');
                if (out.length <= 45000) return out;
            }
             
            var cv2 = document.createElement('canvas');
            cv2.width = 400; cv2.height = 160;
            var c2 = cv2.getContext('2d');
            c2.fillStyle = '#ffffff'; c2.fillRect(0, 0, 400, 160);
            var sc2 = Math.min(400 / img.width, 160 / img.height);
            c2.drawImage(img, (400 - img.width * sc2) / 2, (160 - img.height * sc2) / 2, img.width * sc2, img.height * sc2);
            var jp = cv2.toDataURL('image/jpeg', 0.8);
            return jp.length <= 45000 ? jp : '';
        }

         
        var _sigPadCfg = null;        
        var _sigPadInked = false;
        var _sigPadBound = false;

        function openSigPad(cfg) {
            _sigPadCfg = cfg || {};
            var bd = document.getElementById('sigPadBackdrop');
            if (bd) bd.style.display = 'flex';
            setTimeout(_sigPadInit, 60);    
        }
        function closeSigPad() {
            var bd = document.getElementById('sigPadBackdrop');
            if (bd) bd.style.display = 'none';
            _sigPadCfg = null;
        }
        function _sigPadInit() {
            var cv = document.getElementById('sigPadCanvas');
            if (!cv) return;
            var ratio = Math.max(window.devicePixelRatio || 1, 1);
            var w = cv.clientWidth, h = cv.clientHeight;
            if (!w) { setTimeout(_sigPadInit, 100); return; }
            cv.width = w * ratio; cv.height = h * ratio;
            var ctx = cv.getContext('2d');
            ctx.scale(ratio, ratio);
            ctx.lineWidth = 2.5; ctx.lineCap = 'round'; ctx.lineJoin = 'round';
            ctx.strokeStyle = '#1e2a5a';
            _sigPadClearState();

            if (_sigPadBound) return;    
            _sigPadBound = true;
            var drawing = false, last = null;
            function pos(ev) {
                var r = cv.getBoundingClientRect();
                return { x: ev.clientX - r.left, y: ev.clientY - r.top };
            }
            cv.addEventListener('pointerdown', function (ev) {
                ev.preventDefault();
                drawing = true; last = pos(ev);
                try { cv.setPointerCapture(ev.pointerId); } catch (e) {}
            });
            cv.addEventListener('pointermove', function (ev) {
                if (!drawing) return;
                ev.preventDefault();
                var c = cv.getContext('2d');
                var p = pos(ev);
                c.beginPath();
                c.moveTo(last.x, last.y);
                var mid = { x: (last.x + p.x) / 2, y: (last.y + p.y) / 2 };
                c.quadraticCurveTo(last.x, last.y, mid.x, mid.y);
                c.lineTo(p.x, p.y);
                c.stroke();
                last = p;
                if (!_sigPadInked) {
                    _sigPadInked = true;
                    var hint = document.getElementById('sigPadHint');
                    if (hint) hint.style.display = 'none';
                    var shell = document.getElementById('sigPadShell');
                    if (shell) shell.classList.add('inked');
                }
            });
            function up() { drawing = false; last = null; }
            cv.addEventListener('pointerup', up);
            cv.addEventListener('pointercancel', up);
            cv.addEventListener('pointerleave', up);
        }
        function _sigPadClearState() {
            var cv = document.getElementById('sigPadCanvas');
            if (!cv) return;
            var ctx = cv.getContext('2d');
            ctx.save(); ctx.setTransform(1, 0, 0, 1, 0, 0);
            ctx.clearRect(0, 0, cv.width, cv.height);
            ctx.restore();
            _sigPadInked = false;
            var hint = document.getElementById('sigPadHint');
            if (hint) hint.style.display = 'flex';
            var shell = document.getElementById('sigPadShell');
            if (shell) shell.classList.remove('inked');
        }
        function sigPadClear() { _sigPadClearState(); }
        function sigPadUse() {
            if (!_sigPadInked) { showToast('กรุณาวาดลายเซ็นก่อน', 'danger'); return; }
            var cv = document.getElementById('sigPadCanvas');
            var out = document.createElement('canvas');
            out.width = 400; out.height = 160;
            var ctx = out.getContext('2d');
             
            var scale = Math.min(400 / cv.width, 160 / cv.height);
            var dw = cv.width * scale, dh = cv.height * scale;
            ctx.drawImage(cv, 0, 0, cv.width, cv.height, (400 - dw) / 2, (160 - dh) / 2, dw, dh);
            var dataUrl = out.toDataURL('image/png');
            if (dataUrl.length > 45000) { showToast('ลายเซ็นซับซ้อนเกินไป ลองเซ็นแบบเรียบง่ายขึ้น', 'danger'); return; }
            var cb = _sigPadCfg && _sigPadCfg.onSave;
            closeSigPad();
            if (cb) cb(dataUrl);
        }

         
         
         
        var _signOpenUrl = '';
        var _signOpenAfter = null;    
        var _signOpenTimer = null;    
        var _signOpenReady = false;

         
         
        window.addEventListener('message', function (ev) {
            if (!ev || !ev.data || ev.data.connext !== 'sign-ready') return;
            _signOpenReady = true;
            if (_signOpenTimer) { clearTimeout(_signOpenTimer); _signOpenTimer = null; }
            var warn = document.getElementById('signOpenWarn');
            if (warn) warn.style.display = 'none';
        });

        function openSignWindow(url, subtitle, afterClose) {
            if (!url) { showToast('ยังไม่มีลิงก์เซ็นของรายการนี้', 'info'); return; }
            hapticTap();
            _signOpenUrl = url;
            _signOpenAfter = (typeof afterClose === 'function') ? afterClose : null;
            _signOpenReady = false;
            var sub = document.getElementById('signOpenSub');
            if (sub) sub.textContent = subtitle || '';
            var warn = document.getElementById('signOpenWarn');
            if (warn) warn.style.display = 'none';
            var fr = document.getElementById('signOpenFrame');
            if (fr) fr.src = url;
            var bd = document.getElementById('signOpenBackdrop');
            if (bd) bd.classList.add('open');
            if (_signOpenTimer) clearTimeout(_signOpenTimer);
            _signOpenTimer = setTimeout(function () {
                _signOpenTimer = null;
                if (!_signOpenReady && warn) warn.style.display = '';
            }, 5000);
        }
        function closeSignOpen() {
            if (_signOpenTimer) { clearTimeout(_signOpenTimer); _signOpenTimer = null; }
            var bd = document.getElementById('signOpenBackdrop');
            if (bd) bd.classList.remove('open');
            var fr = document.getElementById('signOpenFrame');
            if (fr) fr.src = 'about:blank';    
            _signOpenUrl = '';
            _signOpenReady = false;
            var cb = _signOpenAfter;
            _signOpenAfter = null;
            if (cb) { try { cb(); } catch (e) {} }    
        }
        function signOpenNewTab() {
            if (!_signOpenUrl) return;
            var w = null;
            try { w = window.open(_signOpenUrl, '_blank'); } catch (e) {}
            if (!w) {
                showInfoPopup('เปิดแท็บใหม่ไม่สำเร็จ',
                    'เบราว์เซอร์บล็อกการเปิดแท็บใหม่ — เซ็นในกรอบนี้ได้เลย หรือใช้ปุ่ม "คัดลอกลิงก์" แล้ววางในแท็บใหม่เอง', 'warning');
            }
        }

         
        var _ES_TH_MON = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
        function _esThDT(ms, withTime) {
            if (!ms) return '';
            var d = new Date(ms);
            var s = d.getDate() + ' ' + _ES_TH_MON[d.getMonth()] + ' ' + ((d.getFullYear() + 543) % 100);
            if (withTime !== false) s += ' ' + ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2) + ' น.';
            return s;
        }
        function _esMoney(n) {
            if (n === null || n === undefined || isNaN(n)) return '—';
            return Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
         
        function _esDaysText(days) {
            if (!days || days === 'ทั้งเดือน') return 'ทั้งเดือน';
            if (days.indexOf('งวด') === 0) return days;
            return 'วันที่ ' + days;
        }
         
        function _sgDocHalf(doc) {
            var lb = (doc && doc.days) || '';
            if (lb.indexOf('งวด') === 0) return lb.indexOf('16') !== -1 ? 2 : 1;
            if (!lb || lb === 'ทั้งเดือน') return 0;
            var nums = lb.split(',').map(function (x) { return parseInt(x, 10); }).filter(function (v) { return !isNaN(v); });
            if (nums.length && nums.every(function (n) { return n <= 15; })) return 1;
            if (nums.length && nums.every(function (n) { return n >= 16; })) return 2;
            return 0;
        }
         
        function _esUserPos(users, username) {
            if (!username) return '';
            var hit = (users || []).filter(function (u) { return (u.username || '').toLowerCase() === username.toLowerCase(); })[0];
            return (hit && hit.roleId) || '';
        }
        function _esSyncPosFromUser(users, username, posInputId) {
            var pos = _esUserPos(users, username);
            if (!pos) return;    
            var el = document.getElementById(posInputId);
            if (el) el.value = pos;
        }
         
        function sgUserPosSync(sel, posInputId) { _esSyncPosFromUser(_sgData && _sgData.users, sel.value, posInputId); }
        function seUserPosSync(sel, posInputId) { _esSyncPosFromUser(_seSignData && _seSignData.users, sel.value, posInputId); }

        function _esCopyText(txt, done) {
            function fallback() {
                try {
                    var ta = document.createElement('textarea');
                    ta.value = txt;
                    ta.style.cssText = 'position:fixed;left:-9999px;top:0;';
                    document.body.appendChild(ta);
                    ta.focus(); ta.select();
                    var ok = document.execCommand('copy');
                    document.body.removeChild(ta);
                    done(!!ok);
                } catch (e) { done(false); }
            }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(txt).then(function () { done(true); }, fallback);
            } else fallback();
        }

         
        var _sgData = null;              
        var _sgYm = '';
        var _sgPeriodFilter = 'all';     

         
        function deductModalTab(which) {
            var isSign = which === 'sign';
            var pe = document.getElementById('deductPanelExport');
            var ps = document.getElementById('deductPanelSign');
            if (pe) pe.style.display = isSign ? 'none' : '';
            if (ps) ps.style.display = isSign ? '' : 'none';
            var be = document.getElementById('deductTabBtnExport');
            var bs = document.getElementById('deductTabBtnSign');
            if (be) be.classList.toggle('on', !isSign);
            if (bs) bs.classList.toggle('on', isSign);
             
            var card = document.getElementById('deductModalCard');
            if (card) card.style.maxWidth = isSign ? '760px' : '560px';
            if (isSign) {
                 
                _sgYm = _deductExportYm;
                var half = _deductSelHalf();
                _sgPeriodFilter = half === 1 ? '1' : half === 2 ? '2' : 'all';
                var body = document.getElementById('signManageBody');
                if (body) body.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-spinner fa-spin"></i><span>กำลังโหลดข้อมูล...</span></div>';
                _sgReload();
            }
        }
         
        function openSignManage(ym) {
            openDeductExport(ym);
            deductModalTab('sign');
        }
        function closeSignManage() { closeDeductExport(); }
         
        function _sgReload(keepScroll, cb) {
            var body = document.getElementById('signManageBody');
            var scrollTop = (keepScroll && body) ? body.scrollTop : 0;
            google.script.run
                .withSuccessHandler(function (res) {
                    if (!res || !res.success) {
                        if (body) body.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-triangle-exclamation"></i><span>' +
                            escapeHtml((res && res.message) || 'โหลดข้อมูลไม่สำเร็จ') + '</span></div>';
                        if (cb) cb(false);
                        return;
                    }
                    _sgData = res;
                    _sgRender();
                    if (body && scrollTop) body.scrollTop = scrollTop;
                    if (cb) cb(true);
                })
                .withFailureHandler(function (err) {
                    if (body) body.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-triangle-exclamation"></i><span>' +
                        escapeHtml((err && err.message) || 'โหลดข้อมูลไม่สำเร็จ') + '</span></div>';
                    if (cb) cb(false);
                })
                .getDeductionSignData({ siteCode: getEffectiveSiteCode(), ym: _sgYm, username: user ? user.username : '' });
        }
         
        function _sgVisibleDocs() {
            var docs = (_sgData && _sgData.docs) || [];
            var out = [];
            docs.forEach(function (d, i) {
                if (_sgPeriodFilter === '1' && _sgDocHalf(d) !== 1) return;
                if (_sgPeriodFilter === '2' && _sgDocHalf(d) !== 2) return;
                out.push({ doc: d, idx: i });
            });
            return out;
        }
        function _sgSetPeriod(p) {
            _sgPeriodFilter = p;
            _sgRender();
        }
        function _sgUserName(username) {
            if (!_sgData || !username) return username || '';
            var hit = (_sgData.users || []).filter(function (u) { return (u.username || '').toLowerCase() === username.toLowerCase(); })[0];
            return hit ? hit.fullName : username;
        }
        function _sgUserOptions(selected) {
            var us = (_sgData && _sgData.users || []).slice().sort(function (a, b) {
                return (a.fullName || '').localeCompare(b.fullName || '', 'th');
            });
            var html = '<option value="">— เลือกผู้ลงนาม —</option>';
            us.forEach(function (u) {
                var sel = (selected && selected.toLowerCase() === u.username.toLowerCase()) ? ' selected' : '';
                html += '<option value="' + escapeHtml(u.username) + '"' + sel + '>' + escapeHtml(u.fullName) + '</option>';
            });
            return html;
        }
        function _sgChip(cls, icon, text) {
            return '<span class="esign-chip ' + cls + '"><i class="fa-solid ' + icon + '"></i> ' + escapeHtml(text) + '</span>';
        }
         
        function _sgLockChip(doc) {
            var rl = doc.rateLock;
            if (!rl || !rl.total) return '';    
            if (rl.state === 'full') {
                return _sgChip('signed', 'fa-lock', 'ล็อกราคาแล้ว' + (rl.unpriced ? ' (ยังไม่ตั้งราคา ' + rl.unpriced + ')' : ''));
            }
            if (rl.state === 'partial') {
                return _sgChip('auto', 'fa-lock-open', 'ล็อกบางส่วน ' + rl.frozen + '/' + rl.total);
            }
            if (rl.state === 'none') {
                return _sgChip('wait', 'fa-triangle-exclamation', 'ยังไม่ล็อกราคา');
            }
            return _sgChip('none', 'fa-tag', 'ยังไม่ตั้งราคา');
        }
         
        function _sgContractorSlot(doc, i) {
            var c = doc.contractor;
            var html = '<div class="esign-slot">';
            html += '<div class="esign-slot-head"><span class="esign-slot-title"><i class="fa-solid fa-helmet-safety" style="color:#d97706;"></i> ผู้รับเหมารับทราบ</span>';
            if (!c) {
                html += _sgChip('none', 'fa-link-slash', 'ยังไม่สร้างลิงก์') + '</div>';
                html += '<div class="esign-ctrl">' +
                    '<span class="esign-note">ครบกำหนด (วัน):</span>' +
                    '<input type="number" id="sgDl_' + i + '" min="0" max="60" step="1" value="3">' +
                    '<button type="button" class="esign-btn primary" onclick="sgCreateLink(' + i + ')"><i class="fa-solid fa-link"></i> สร้างลิงก์เซ็นรับทราบ</button>' +
                    '</div>' +
                    '<div class="esign-note">เกินกำหนดโดยไม่ตอบกลับ (นับจากเวลาที่กด “ส่งแล้ว”) = รับทราบโดยปริยาย · ใส่ 0 = ไม่กำหนด</div>';
            } else if (c.status === 'signed') {
                html += _sgChip('signed', 'fa-circle-check', 'ลงนามแล้ว') +
                    '<button type="button" class="esign-btn soft" onclick="sgViewSig(' + i + ', \'contractor\')"><i class="fa-solid fa-magnifying-glass"></i> ดูลายเซ็น</button></div>';
                html += '<div class="esign-note">โดย <b>' + escapeHtml(c.signerName || '-') + '</b> · ' + _esThDT(c.signedAt) + '</div>';
            } else if (c.status === 'auto') {
                html += _sgChip('auto', 'fa-clock', 'รับทราบโดยปริยาย') + '</div>';
                html += '<div class="esign-note">' + escapeHtml(c.note || ('เกินกำหนดตอบกลับ — ระบบบันทึกเป็นรับทราบโดยปริยาย')) + '</div>';
                 
                if (c.token) {
                    html += '<div class="esign-ctrl">' +
                        '<button type="button" class="esign-btn soft" onclick="sgOpenSignPage(' + i + ')"><i class="fa-solid fa-pen-nib"></i> เปิดหน้าเซ็น (เซ็นจริงทับได้)</button>' +
                        '<button type="button" class="esign-btn ghost" onclick="sgCopyLink(' + i + ')"><i class="fa-solid fa-copy"></i> คัดลอกลิงก์</button>' +
                        '</div>';
                }
            } else {
                var url = (_sgData.webAppUrl || '') + '?page=sign&token=' + (c.token || '');
                if (c.sentAt) {
                    var dlTxt = c.deadlineAt ? (Date.now() > c.deadlineAt ? 'เกินกำหนดแล้ว (รีเฟรชเพื่ออัปเดตสถานะ)' : 'ครบกำหนด ' + _esThDT(c.deadlineAt)) : 'ไม่กำหนดเวลา';
                    html += _sgChip('sent', 'fa-paper-plane', 'ส่งแล้ว · รอลงนาม') + '</div>';
                    html += '<div class="esign-note">ส่งเมื่อ ' + _esThDT(c.sentAt) + ' · ' + escapeHtml(dlTxt) + '</div>';
                } else {
                    html += _sgChip('wait', 'fa-hourglass-half', 'สร้างลิงก์แล้ว · ยังไม่กด “ส่งแล้ว”') + '</div>';
                }
                html += '<div class="esign-link-row">' +
                    '<input type="text" readonly id="sgLink_' + i + '" value="' + escapeHtml(url) + '" onclick="this.select()">' +
                    '<button type="button" class="esign-btn soft" onclick="sgCopyLink(' + i + ')"><i class="fa-solid fa-copy"></i> คัดลอก</button>' +
                    '<button type="button" class="esign-btn primary" onclick="sgOpenSignPage(' + i + ')" title="เปิดหน้าเซ็นให้ผู้รับเหมาเซ็นตรงนี้เลย"><i class="fa-solid fa-pen-nib"></i> เปิดหน้าเซ็น</button>' +
                    '</div>';
                html += '<div class="esign-ctrl">';
                if (!c.sentAt) {
                    html += '<button type="button" class="esign-btn primary" onclick="sgToggleSent(' + i + ', true)"><i class="fa-solid fa-paper-plane"></i> ส่งให้ผู้รับเหมาแล้ว — เริ่มนับเวลา</button>';
                } else {
                    html += '<button type="button" class="esign-btn ghost" onclick="sgToggleSent(' + i + ', false)"><i class="fa-solid fa-rotate-left"></i> ยกเลิกสถานะส่งแล้ว</button>';
                }
                html += '<span class="esign-note">ครบกำหนด (วัน):</span>' +
                    '<input type="number" id="sgDl_' + i + '" min="0" max="60" step="1" value="' + (c.deadlineDays || 0) + '">' +
                    '<button type="button" class="esign-btn ghost" onclick="sgCreateLink(' + i + ')"><i class="fa-solid fa-floppy-disk"></i> อัปเดตกำหนด</button>' +
                    '<button type="button" class="esign-btn danger" onclick="sgCancelRole(' + i + ', \'contractor\')"><i class="fa-solid fa-link-slash"></i> ยกเลิกลิงก์</button>';
                html += '</div>';
            }
            html += '</div>';
            return html;
        }
         
        function _sgRoleSlot(doc, i, role) {
            var isIns = role === 'inspector';
            var r = isIns ? doc.inspector : doc.approver;
            var title = isIns
                ? '<i class="fa-solid fa-user-check" style="color:#0369a1;"></i> ผู้ตรวจสอบ'
                : '<i class="fa-solid fa-user-shield" style="color:#6d28d9;"></i> ผู้อนุมัติ';
            var defPos = isIns ? 'PE / SSE' : 'PM';
            var uid = 'sg' + (isIns ? 'Ins' : 'Apv');
            var html = '<div class="esign-slot">';
            html += '<div class="esign-slot-head"><span class="esign-slot-title">' + title + '</span>';
            if (r && r.status === 'signed') {
                html += _sgChip('signed', 'fa-circle-check', 'ลงนามแล้ว') +
                    '<button type="button" class="esign-btn soft" onclick="sgViewSig(' + i + ', \'' + role + '\')"><i class="fa-solid fa-magnifying-glass"></i> ดูลายเซ็น</button></div>';
                html += '<div class="esign-note">โดย <b>' + escapeHtml(r.signerName || '-') + '</b>' +
                    (r.signerPos ? ' (' + escapeHtml(r.signerPos) + ')' : '') + ' · ' + _esThDT(r.signedAt) + '</div>';
                html += '</div>';
                return html;
            }
            if (r && r.status === 'pending') {
                html += _sgChip('sent', 'fa-hourglass-half', 'รอ ' + _sgUserName(r.assignee) + ' ลงนาม') + '</div>';
                html += '<div class="esign-note">ส่งคำขอเมื่อ ' + _esThDT(r.requestedAt) + ' — เลือกคนใหม่แล้วกดส่งอีกครั้ง = ย้ายคำขอ</div>';
            } else {
                html += _sgChip('none', 'fa-user-plus', 'ยังไม่ส่งคำขอ') + '</div>';
            }
            html += '<div class="esign-ctrl">' +
                '<select id="' + uid + 'User_' + i + '" onchange="sgUserPosSync(this, \'' + uid + 'Pos_' + i + '\')">' + _sgUserOptions(r ? r.assignee : '') + '</select>' +
                '<input type="text" id="' + uid + 'Pos_' + i + '" placeholder="ตำแหน่ง" value="' + escapeHtml((r && r.assigneePos) || defPos) + '" style="width:110px;">' +
                '<button type="button" class="esign-btn primary" onclick="sgSendRole(' + i + ', \'' + role + '\')"><i class="fa-solid fa-paper-plane"></i> ' + (r ? 'ส่งใหม่' : 'ส่งคำขอ') + '</button>' +
                (r ? '<button type="button" class="esign-btn danger" onclick="sgCancelRole(' + i + ', \'' + role + '\')"><i class="fa-solid fa-xmark"></i> ยกเลิก</button>' : '') +
                '</div>';
            html += '</div>';
            return html;
        }
        function _sgRender() {
            var body = document.getElementById('signManageBody');
            if (!body || !_sgData) return;
            var docs = _sgData.docs || [];
            var pend = _sgData.pendingSubs || { 1: [], 2: [] };
            var pendTotal = (pend[1] || []).length + (pend[2] || []).length;
            if (!docs.length && !pendTotal) {
                body.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-file-circle-question"></i>' +
                    '<span>เดือนนี้ยังไม่มีรายการหักเงินที่ออกเลขเอกสารได้<br>ติ๊ก “หักเงิน” รายการในหน้าตรวจสอบประจำวันก่อน แล้วกลับมาออกเลขเอกสารที่นี่</span></div>';
                return;
            }
            var html = '';

             
            var nAll = docs.length;
            var n1 = docs.filter(function (d) { return _sgDocHalf(d) === 1; }).length;
            var n2 = docs.filter(function (d) { return _sgDocHalf(d) === 2; }).length;
            var visible = _sgVisibleDocs();
            var scopeTxt = _sgPeriodFilter === '1' ? 'งวดที่ 1 (วันที่ 1–15)'
                         : _sgPeriodFilter === '2' ? 'งวดที่ 2 (วันที่ 16–สิ้นเดือน)' : 'ทุกใบในเดือนนี้';
             
            var nLockable = visible.filter(function (v) { return v.doc.rateLock && v.doc.rateLock.lockable > 0; }).length;

            html += '<div class="esign-pfilter">' +
                '<button type="button" class="' + (_sgPeriodFilter === 'all' ? 'on' : '') + '" onclick="_sgSetPeriod(\'all\')">ทั้งหมด (' + nAll + ')</button>' +
                '<button type="button" class="' + (_sgPeriodFilter === '1' ? 'on' : '') + '" onclick="_sgSetPeriod(\'1\')">งวดที่ 1 · วันที่ 1–15 (' + n1 + ')</button>' +
                '<button type="button" class="' + (_sgPeriodFilter === '2' ? 'on' : '') + '" onclick="_sgSetPeriod(\'2\')">งวดที่ 2 · วันที่ 16–สิ้นเดือน (' + n2 + ')</button>' +
                (visible.length
                    ? '<button type="button" class="esign-pfilter-export" style="margin-left:auto; border-color:#fecaca; background:#fee2e2; color:#b91c1c;" onclick="sgPdfAll()">' +
                      '<i class="fa-solid fa-file-pdf"></i> Export PDF ทั้งหมด (' + visible.length + ' ใบ · ไฟล์เดียว)</button>'
                    : '') +
                 
                (nLockable
                    ? '<button type="button" class="esign-pfilter-export" style="' + (visible.length ? '' : 'margin-left:auto; ') +
                      'border-color:#bfdbfe; background:#dbeafe; color:#1e40af;" onclick="sgFreezeRates()" ' +
                      'title="บันทึกราคาปัจจุบันผูกกับใบ — แก้ราคาภายหลังจะไม่กระทบยอดของใบเหล่านี้">' +
                      '<i class="fa-solid fa-lock"></i> ล็อกราคาทุกใบของ' + (_sgPeriodFilter === 'all' ? 'เดือนนี้' : 'งวดนี้') +
                      ' (' + nLockable + ' ใบ)</button>'
                    : '') +
                 
                (visible.length
                    ? '<button type="button" class="esign-pfilter-export" style="border-color:#c7d2fe; background:#e0e7ff; color:#3730a3;" ' +
                      'onclick="sgOpenAllLinks()" title="เห็นชื่อชุดชัดๆ ทุกใบ — กดเปิดหน้าเซ็นรับทราบ/คัดลอกลิงก์ได้ทันที (โครงเดียวกับหน้าสรุปงวดสแกนนิ้ว)">' +
                      '<i class="fa-solid fa-share-nodes"></i> รวมชุด · เปิดหน้าเซ็น (' + visible.length + ')</button>'
                    : '') +
            '</div>';

             
            [1, 2].forEach(function (h) {
                var list = pend[h] || [];
                if (!list.length) return;
                if (_sgPeriodFilter === '1' && h !== 1) return;
                if (_sgPeriodFilter === '2' && h !== 2) return;
                var labelH = h === 1 ? 'งวดที่ 1 (วันที่ 1–15)' : 'งวดที่ 2 (วันที่ 16–สิ้นเดือน)';
                var names = list.map(function (s) { return escapeHtml(s.name) + ' (' + s.count + ')'; }).join(', ');
                html += '<div class="esign-issue">' +
                    '<div class="esign-issue-txt"><i class="fa-solid fa-file-circle-plus"></i> <b>' + labelH + '</b> — ' + list.length +
                        ' ชุดมีรายการหักเงินแต่ยังไม่ได้ออกเลขเอกสาร:<br><span class="esign-note">' + names + '</span></div>' +
                    '<button type="button" class="esign-btn primary" onclick="sgIssueDocs(' + h + ')">' +
                        '<i class="fa-solid fa-hashtag"></i> ออกเลขเอกสาร ' + list.length + ' ใบ</button>' +
                '</div>';
            });

             
            if (docs.length) html += '<div class="esign-bulk">' +
                '<div class="esign-bulk-title"><i class="fa-solid fa-bolt"></i> ส่งคำขอลายเซ็นหลายใบพร้อมกัน — ' + scopeTxt + ' (เฉพาะใบที่ยังไม่ลงนาม)</div>' +
                '<div class="esign-ctrl">' +
                    '<select id="sgBulkRole" onchange="var p=document.getElementById(\'sgBulkPos\'); if(p && (p.value===\'PE / SSE\'||p.value===\'PM\')) p.value=this.value===\'approver\'?\'PM\':\'PE / SSE\';">' +
                        '<option value="inspector">ผู้ตรวจสอบ</option><option value="approver">ผู้อนุมัติ</option></select>' +
                    '<select id="sgBulkUser" onchange="sgUserPosSync(this, \'sgBulkPos\')">' + _sgUserOptions('') + '</select>' +
                    '<input type="text" id="sgBulkPos" placeholder="ตำแหน่ง" value="PE / SSE" style="width:110px;">' +
                    '<button type="button" class="esign-btn primary" onclick="sgBulkSend()"><i class="fa-solid fa-paper-plane"></i> ส่งทุกใบ</button>' +
                '</div></div>';

            if (!_sgData.hasMySignature) {
                html += '<div class="esign-note" style="background:#fffbeb; border:1px solid #fde68a; color:#92400e; border-radius:10px; padding:0.5rem 0.7rem; margin-bottom:0.7rem;">' +
                    '<i class="fa-solid fa-triangle-exclamation"></i> คุณยังไม่ได้ตั้งลายเซ็นของตัวเอง — ตั้งได้ที่ไอคอนโปรไฟล์มุมขวาบน (ตั้งค่าบัญชี) ' +
                    'เพื่อให้ช่อง “ผู้สรุปเอกสาร” มีลายเซ็นอัตโนมัติตอนออก PDF</div>';
            }

            if (!visible.length) {
                html += '<div class="qr-empty"><i class="fa-solid fa-filter-circle-xmark"></i><span>ไม่มีเอกสารใน' + scopeTxt + '</span></div>';
            }
            visible.forEach(function (v) {
                var doc = v.doc, i = v.idx;    
                html += '<div class="esign-doc">' +
                    '<div class="esign-doc-head">' +
                        '<span class="esign-doc-no">' + escapeHtml(doc.docNo) + '</span>' +
                        '<span class="esign-doc-meta">' + escapeHtml(doc.subName) + ' · ' + escapeHtml(_esDaysText(doc.days)) + ' · ' + doc.itemCount + ' รายการ</span>' +
                        _sgLockChip(doc) +
                        '<button type="button" class="esign-doc-act" onclick="sgViewDoc(' + i + ')"><i class="fa-solid fa-list"></i> ดูรายการ</button>' +
                        '<button type="button" class="esign-doc-act pdf" onclick="sgPdf(' + i + ')"><i class="fa-solid fa-file-pdf"></i> PDF</button>' +
                    '</div>' +
                    _sgContractorSlot(doc, i) +
                    _sgRoleSlot(doc, i, 'inspector') +
                    _sgRoleSlot(doc, i, 'approver') +
                '</div>';
            });
            body.innerHTML = html;
             
            var albd = document.getElementById('sgAllLinksBackdrop');
            if (albd && albd.classList.contains('open')) _sgRenderAllLinks();
        }
        function sgCreateLink(i) {
            var doc = _sgData && _sgData.docs[i];
            if (!doc) return;
            var dl = document.getElementById('sgDl_' + i);
            var days = dl ? parseFloat(dl.value) : 3;
            if (isNaN(days) || days < 0) days = 0;
            google.script.run
                .withSuccessHandler(function (res) {
                    if (!res || !res.success) { showToast((res && res.message) || 'สร้างลิงก์ไม่สำเร็จ', 'danger'); return; }
                    doc.contractor = res.contractor;
                    _sgRenderKeepScroll();
                    showToast('บันทึกลิงก์เซ็นรับทราบแล้ว', 'success');
                })
                .withFailureHandler(function (err) { showToast((err && err.message) || 'สร้างลิงก์ไม่สำเร็จ', 'danger'); })
                .createContractorLink({ docNo: doc.docNo, deadlineDays: days, username: user.username });
        }
         
        function sgOpenSignPage(i) {
            var doc = _sgData && _sgData.docs[i];
            if (!doc || !doc.contractor || !doc.contractor.token) { showToast('ยังไม่ได้สร้างลิงก์ของใบนี้', 'info'); return; }
            var url = (_sgData.webAppUrl || '') + '?page=sign&token=' + doc.contractor.token;
            openSignWindow(url, doc.subName + ' · ' + doc.docNo, function () { _sgReload(true); });
        }
        function sgCopyLink(i) {
            var doc = _sgData && _sgData.docs[i];
            if (!doc || !doc.contractor || !doc.contractor.token) return;
            var url = (_sgData.webAppUrl || '') + '?page=sign&token=' + doc.contractor.token;
            _esCopyText(url, function (ok) {
                if (ok) showToast('คัดลอกลิงก์แล้ว — ส่งให้ผู้รับเหมาได้เลย', 'success');
                else showInfoPopup('คัดลอกอัตโนมัติไม่สำเร็จ', 'กดค้างที่ช่องลิงก์เพื่อเลือกและคัดลอกเอง\n\n' + url, 'info');
            });
        }
         
         
         
        function _sgScopeText() {
            return _sgPeriodFilter === '1' ? 'งวดที่ 1 (วันที่ 1–15)'
                 : _sgPeriodFilter === '2' ? 'งวดที่ 2 (วันที่ 16–สิ้นเดือน)' : 'ทุกใบในเดือนนี้';
        }
        function _sgRenderAllLinks() {
            var body = document.getElementById('sgAllLinksBody');
            if (!body || !_sgData) return;
            var visible = _sgVisibleDocs();
            var pending = 0, signed = 0, none = 0;
            var html = visible.map(function (v) {
                var doc = v.doc, i = v.idx, c = doc.contractor;
                var url = (c && c.token) ? ((_sgData.webAppUrl || '') + '?page=sign&token=' + c.token) : '';
                var statusHtml, actHtml = '';
                var copyBtn = '<button type="button" class="btn btn-secondary" title="คัดลอกลิงก์นี้" onclick="sgCopyLink(' + i + ')"><i class="fa-solid fa-copy"></i></button>';
                if (c && c.status === 'signed') {
                    signed++;
                    statusHtml = '<span style="color:#16a34a; font-size:0.8rem;"><i class="fa-solid fa-circle-check"></i> เซ็นแล้ว' +
                        (c.signerName ? ' · ' + escapeHtml(c.signerName) : '') + '</span>';
                } else if (c && c.status === 'auto') {
                    signed++;
                    statusHtml = '<span style="color:#d97706; font-size:0.8rem;"><i class="fa-solid fa-clock"></i> รับทราบโดยปริยาย</span>';
                     
                    if (url) actHtml = '<div class="fs-link-url"><input type="text" readonly value="' + escapeHtml(url) + '" onclick="this.select()">' + copyBtn +
                        '<button type="button" class="btn btn-primary" title="เปิดหน้าเซ็น — เซ็นจริงทับสถานะปริยายได้" onclick="sgOpenSignPage(' + i + ')"><i class="fa-solid fa-pen-nib"></i> เซ็นทับ</button></div>';
                } else if (url) {
                    pending++;
                    statusHtml = '<span style="color:#2563eb; font-size:0.8rem;">' + (c.sentAt ? '<i class="fa-solid fa-paper-plane"></i> ส่งแล้ว·รอลงนาม' : '<i class="fa-solid fa-hourglass-half"></i> รอส่งลิงก์') + '</span>';
                    actHtml = '<div class="fs-link-url"><input type="text" readonly value="' + escapeHtml(url) + '" onclick="this.select()">' + copyBtn +
                        '<button type="button" class="btn btn-primary" title="เปิดหน้าเซ็นให้ผู้รับเหมาเซ็นตรงนี้เลย" onclick="sgOpenSignPage(' + i + ')"><i class="fa-solid fa-pen-nib"></i> เปิดหน้าเซ็น</button></div>';
                } else {
                    none++;
                    statusHtml = '<span style="color:#94a3b8; font-size:0.8rem;">ยังไม่สร้างลิงก์</span>';
                    actHtml = '<div class="fs-link-url"><button type="button" class="btn btn-secondary" style="flex:1;" onclick="sgCreateLink(' + i + ')" ' +
                        'title="ครบกำหนดรับทราบตามช่อง “ครบกำหนด (วัน)” ของใบ (ค่าเริ่มต้น 3 วัน)">' +
                        '<i class="fa-solid fa-link"></i> สร้างลิงก์เซ็นรับทราบ</button></div>';
                }
                return '<div class="fs-link-item"><div class="fs-link-head"><span class="fs-link-name">' + escapeHtml(doc.subName) + '</span>' +
                    '<span style="color:#94a3b8; font-size:0.72rem;">' + escapeHtml(doc.docNo) + '</span> ' + statusHtml + '</div>' + actHtml + '</div>';
            }).join('');
            body.innerHTML = html || '<div class="qr-empty"><i class="fa-solid fa-circle-info"></i><span>ไม่มีเอกสารในขอบเขตนี้</span></div>';
            var sub = document.getElementById('sgAllLinksSub');
            if (sub) sub.textContent = _dcThaiMonth(_sgYm) + ' · ' + _sgScopeText() + ' · มีลิงก์รอเซ็น ' + pending + ' ใบ' +
                (signed ? ' · เซ็น/รับทราบแล้ว ' + signed + ' ใบ' : '') + (none ? ' · ยังไม่สร้างลิงก์ ' + none + ' ใบ' : '');
            var copyAll = document.getElementById('sgAllLinksCopyAll');
            if (copyAll) copyAll.disabled = pending === 0;
        }
        function sgOpenAllLinks() {
            if (!_sgData || !_sgVisibleDocs().length) { showToast('ไม่มีเอกสารในขอบเขตที่กรองอยู่', 'info'); return; }
            _sgRenderAllLinks();
            var bd = document.getElementById('sgAllLinksBackdrop');
            if (bd) bd.classList.add('open');
        }
        function closeSgAllLinks() {
            var bd = document.getElementById('sgAllLinksBackdrop');
            if (bd) bd.classList.remove('open');
        }
        function sgCopyAllLinks() {
            if (!_sgData) return;
            var lines = [];
            _sgVisibleDocs().forEach(function (v) {
                var c = v.doc.contractor;
                if (!c || !c.token || c.status === 'signed' || c.status === 'auto') return;
                lines.push('• ' + v.doc.subName + ' — ' + v.doc.docNo + '\n' + (_sgData.webAppUrl || '') + '?page=sign&token=' + c.token);
            });
            if (!lines.length) { showToast('ไม่มีลิงก์ที่รอเซ็นให้คัดลอก', 'info'); return; }
            var txt = 'ลิงก์เซ็นรับทราบเอกสารหักเงิน ' + _dcThaiMonth(_sgYm) + ' — ' + _sgScopeText() +
                '\n(เปิดลิงก์บนมือถือเพื่อลงลายเซ็น)\n\n' + lines.join('\n\n');
            _esCopyText(txt, function (ok) {
                if (ok) { hapticSuccess(); showToast('คัดลอกลิงก์ทั้งหมด ' + lines.length + ' ใบแล้ว', 'success'); }
                else showToast('คัดลอกไม่สำเร็จ', 'danger');
            });
        }
        function sgToggleSent(i, sent) {
            var doc = _sgData && _sgData.docs[i];
            if (!doc) return;
            google.script.run
                .withSuccessHandler(function (res) {
                    if (!res || !res.success) { showToast((res && res.message) || 'บันทึกไม่สำเร็จ', 'danger'); return; }
                    doc.contractor = res.contractor;
                    _sgRenderKeepScroll();
                    showToast(sent ? 'บันทึกว่า “ส่งให้ผู้รับเหมาแล้ว” — เริ่มนับเวลาครบกำหนด' : 'ยกเลิกสถานะส่งแล้ว', 'success');
                })
                .withFailureHandler(function (err) { showToast((err && err.message) || 'บันทึกไม่สำเร็จ', 'danger'); })
                .setContractorSent({ docNo: doc.docNo, sent: sent, username: user.username });
        }
        function sgCancelRole(i, role) {
            var doc = _sgData && _sgData.docs[i];
            if (!doc) return;
            var label = role === 'contractor' ? 'ลิงก์ผู้รับเหมา' : (role === 'inspector' ? 'คำขอผู้ตรวจสอบ' : 'คำขอผู้อนุมัติ');
            showConfirmPopup('ยกเลิก' + label, 'ต้องการยกเลิก' + label + 'ของใบ ' + doc.docNo + ' ใช่หรือไม่?', function () {
                showLoadingPopup('กำลังยกเลิก' + label, 'ใบ ' + doc.docNo + '\nกรุณารอสักครู่...');
                google.script.run
                    .withSuccessHandler(function (res) {
                        closeAppPopup();
                        if (!res || !res.success) { showToast((res && res.message) || 'ยกเลิกไม่สำเร็จ', 'danger'); return; }
                        if (role === 'contractor') doc.contractor = null;
                        else if (role === 'inspector') doc.inspector = null;
                        else doc.approver = null;
                        _sgRenderKeepScroll();
                        showToast('ยกเลิกแล้ว', 'success');
                    })
                    .withFailureHandler(function (err) { closeAppPopup(); showToast((err && err.message) || 'ยกเลิกไม่สำเร็จ', 'danger'); })
                    .cancelSignRequest({ docNo: doc.docNo, role: role, username: user.username });
            }, 'ยกเลิก', 'btn btn-reject');
        }
        function sgSendRole(i, role) {
            var doc = _sgData && _sgData.docs[i];
            if (!doc) return;
            var uid = 'sg' + (role === 'inspector' ? 'Ins' : 'Apv');
            var userSel = document.getElementById(uid + 'User_' + i);
            var posInp = document.getElementById(uid + 'Pos_' + i);
            var assignee = userSel ? userSel.value : '';
            if (!assignee) { showToast('กรุณาเลือกผู้ลงนามก่อน', 'danger'); return; }
            var roleTxt = role === 'inspector' ? 'ผู้ตรวจสอบ' : 'ผู้อนุมัติ';
            showLoadingPopup('กำลังส่งคำขอลายเซ็น',
                'กำลังส่งคำขอ' + roleTxt + ' ใบ ' + doc.docNo + '\nไปยัง ' + _sgUserName(assignee) + ' — กรุณารอสักครู่...');
            google.script.run
                .withSuccessHandler(function (res) {
                    if (!res || !res.success) {
                        closeAppPopup();
                        showToast((res && res.message) || 'ส่งคำขอไม่สำเร็จ', 'danger');
                        return;
                    }
                     
                    _sgReload(true, function () {
                        closeAppPopup();
                        showToast('✅ ส่งคำขอลายเซ็นถึง ' + _sgUserName(assignee) + ' แล้ว', 'success');
                    });
                })
                .withFailureHandler(function (err) {
                    closeAppPopup();
                    showToast((err && err.message) || 'ส่งคำขอไม่สำเร็จ', 'danger');
                })
                .requestSignature({
                    docNos: [doc.docNo], role: role, assignee: assignee,
                    assigneePos: posInp ? posInp.value : '', username: user.username
                });
        }
        function sgBulkSend() {
            if (!_sgData) return;
            var role = (document.getElementById('sgBulkRole') || {}).value || 'inspector';
            var assignee = (document.getElementById('sgBulkUser') || {}).value || '';
            var pos = (document.getElementById('sgBulkPos') || {}).value || '';
            if (!assignee) { showToast('กรุณาเลือกผู้ลงนามก่อน', 'danger'); return; }
             
            var docNos = _sgVisibleDocs().filter(function (v) {
                var r = role === 'inspector' ? v.doc.inspector : v.doc.approver;
                return !(r && r.status === 'signed');
            }).map(function (v) { return v.doc.docNo; });
            var scopeTxt = _sgPeriodFilter === '1' ? 'งวดที่ 1 (วันที่ 1–15)'
                         : _sgPeriodFilter === '2' ? 'งวดที่ 2 (วันที่ 16–สิ้นเดือน)' : 'ทุกใบในเดือนนี้';
            if (!docNos.length) { showToast('ไม่มีใบที่ยังไม่ลงนามใน' + scopeTxt, 'info'); return; }
            var roleTxt = role === 'inspector' ? 'ผู้ตรวจสอบ' : 'ผู้อนุมัติ';
            showConfirmPopup('ส่งคำขอ ' + docNos.length + ' ใบ',
                'ส่งคำขอลายเซ็น' + roleTxt + ' — ' + scopeTxt + ' ทั้ง ' + docNos.length +
                ' ใบ ไปยัง ' + _sgUserName(assignee) + ' ใช่หรือไม่?', function () {
                showLoadingPopup('กำลังส่งคำขอลายเซ็น',
                    'กำลังส่งคำขอ' + roleTxt + ' ' + docNos.length + ' ใบ (' + scopeTxt + ')\n' +
                    'ไปยัง ' + _sgUserName(assignee) + ' — กรุณารอสักครู่ อย่าเพิ่งปิดหน้าจอ...');
                google.script.run
                    .withSuccessHandler(function (res) {
                        if (!res || !res.success) {
                            closeAppPopup();
                            showToast((res && res.message) || 'ส่งคำขอไม่สำเร็จ', 'danger');
                            return;
                        }
                        var nDone = (res.done || []).length;
                        var skipped = (res.skipped || []).length;
                         
                        _sgReload(true, function () {
                            closeAppPopup();
                            showInfoPopup('ส่งคำขอลายเซ็นแล้ว',
                                '✅ ส่งคำขอ' + roleTxt + 'สำเร็จ ' + nDone + ' ใบ ไปยัง ' + _sgUserName(assignee) +
                                (skipped ? '\n(ข้าม ' + skipped + ' ใบที่ลงนามไปแล้ว)' : ''), 'success');
                        });
                    })
                    .withFailureHandler(function (err) {
                        closeAppPopup();
                        showInfoPopup('ส่งคำขอไม่สำเร็จ', (err && err.message) || 'กรุณาลองใหม่', 'danger');
                    })
                    .requestSignature({ docNos: docNos, role: role, assignee: assignee, assigneePos: pos, username: user.username });
            }, 'ส่งคำขอ');
        }
        function _sgRenderKeepScroll() {
            var body = document.getElementById('signManageBody');
            var st = body ? body.scrollTop : 0;
            _sgRender();
            if (body) body.scrollTop = st;
        }
        function sgViewDoc(i) {
            var doc = _sgData && _sgData.docs[i];
            if (!doc) return;
            openSignDetail(doc.docNo, { subtitle: escapeHtml(doc.subName) + ' · ' + _dcThaiMonth(_sgYm) });
        }
        function sgPdf(i) {
            var doc = _sgData && _sgData.docs[i];
            if (!doc) return;
             
            var half = null, days = null;
            if (doc.days && doc.days.indexOf('งวด') === 0) {
                half = doc.days.indexOf('16') !== -1 ? 2 : 1;                
            } else if (doc.days && doc.days !== 'ทั้งเดือน') {
                days = doc.days.split(',').map(function (x) {                
                    var d = parseInt(x, 10);
                    return isNaN(d) ? null : _sgYm + '-' + ('0' + d).slice(-2);
                }).filter(Boolean);
                if (!days.length) days = null;
            }
            _deductRunExport({
                siteCode: getEffectiveSiteCode(),
                ym: _sgYm,
                subNames: [doc.subName],
                half: half,
                days: days,
                showSummarizer: true,
                summarizerName: _deductExporter(),
                summarizerDept: _deductExporterDept(),
                inspectorPos: 'PE / SSE',
                approverPos: 'PM',
                projectName: '',
                username: user ? user.username : ''
            });
        }

         
         
        function sgIssueDocs(half) {
            var pend = (_sgData && _sgData.pendingSubs) || {};
            var list = pend[half] || [];
            if (!list.length) return;
            var labelH = half === 1 ? 'งวดที่ 1 (วันที่ 1–15)' : 'งวดที่ 2 (วันที่ 16–สิ้นเดือน)';
            showConfirmPopup('ออกเลขเอกสาร ' + list.length + ' ใบ',
                'ออกเลขเอกสารหักเงิน ' + labelH + ' ให้ ' + list.length + ' ชุดที่ยังไม่มีใบ ใช่หรือไม่?\n' +
                '(ยังไม่สร้าง PDF — จัดการลายเซ็นได้ทันที แล้วค่อยกด PDF รายใบ หรือ Export ทั้งหมด ภายหลัง)', function () {
                showLoadingPopup('กำลังออกเลขเอกสาร',
                    'กำลังจองเลขที่เอกสาร ' + labelH + ' จำนวน ' + list.length + ' ใบ\nกรุณารอสักครู่...');
                google.script.run
                    .withSuccessHandler(function (res) {
                        if (!res || !res.success) {
                            closeAppPopup();
                            showToast((res && res.message) || 'ออกเลขเอกสารไม่สำเร็จ', 'danger');
                            return;
                        }
                         
                        _sgReload(true, function () {
                            closeAppPopup();
                            showToast('✅ ออกเลขเอกสารแล้ว ' + (res.created || []).length + ' ใบ (' + labelH + ')', 'success');
                        });
                    })
                    .withFailureHandler(function (err) {
                        closeAppPopup();
                        showToast((err && err.message) || 'ออกเลขเอกสารไม่สำเร็จ', 'danger');
                    })
                    .issueDeductionDocs({ siteCode: getEffectiveSiteCode(), ym: _sgYm, half: half, username: user ? user.username : '' });
            }, 'ออกเลขเอกสาร');
        }

         
         
        function sgPdfAll() {
            var visible = _sgVisibleDocs();
            if (!visible.length) { showToast('ไม่มีเอกสารในงวดที่เลือก', 'info'); return; }
            var docNos = visible.map(function (v) { return v.doc.docNo; });
            _deductRunExport({
                siteCode: getEffectiveSiteCode(),
                ym: _sgYm,
                docNos: docNos,
                showSummarizer: true,
                summarizerName: _deductExporter(),
                summarizerDept: _deductExporterDept(),
                inspectorPos: 'PE / SSE',
                approverPos: 'PM',
                projectName: '',
                username: user ? user.username : ''
            });
        }

         
        function sgFreezeRates() {
            var pend = _sgVisibleDocs().filter(function (v) { return v.doc.rateLock && v.doc.rateLock.lockable > 0; });
            if (!pend.length) { showToast('ทุกใบในขอบเขตที่เลือกล็อกราคาไว้แล้ว', 'info'); return; }
            var scope = _sgPeriodFilter === '1' ? 'งวดที่ 1 (วันที่ 1–15)'
                      : _sgPeriodFilter === '2' ? 'งวดที่ 2 (วันที่ 16–สิ้นเดือน)' : 'ทุกงวดในเดือนนี้';
            showConfirmPopup('ล็อกราคา ' + pend.length + ' ใบ',
                'ล็อกราคา/หน่วยของ ' + scope + ' จำนวน ' + pend.length + ' ใบ ด้วยราคาปัจจุบันใน Rate Card ใช่หรือไม่?\n\n' +
                '• หลังล็อกแล้ว การแก้ราคาที่เมนู "ตั้งราคาหักเงิน" จะไม่กระทบยอดของใบเหล่านี้อีก\n' +
                '• รายการที่ยังไม่ได้ตั้งราคาจะไม่ถูกล็อก — ตั้งราคาแล้วกดล็อกอีกครั้งได้\n' +
                '• ใบที่ล็อกไว้แล้วจะไม่ถูกเขียนทับ', function () {
                showLoadingPopup('กำลังล็อกราคา',
                    'กำลังบันทึกราคาปัจจุบันผูกกับใบ ' + pend.length + ' ใบ\nกรุณารอสักครู่...');
                google.script.run
                    .withSuccessHandler(function (res) {
                        if (!res || !res.success) {
                            closeAppPopup();
                            showToast((res && res.message) === 'no_permission' ? 'คุณไม่มีสิทธิ์ล็อกราคา'
                                : ((res && res.message) || 'ล็อกราคาไม่สำเร็จ'), 'danger');
                            return;
                        }
                        _sgReload(true, function () {
                            closeAppPopup();
                            showToast('🔒 ล็อกราคาแล้ว — บันทึกราคา ' + (res.rows || 0) + ' รายการ ใน ' + (res.docs || 0) + ' ใบ', 'success');
                        });
                    })
                    .withFailureHandler(function (err) {
                        closeAppPopup();
                        showToast((err && err.message) || 'ล็อกราคาไม่สำเร็จ', 'danger');
                    })
                    .freezeDeductionRatesNow({
                        siteCode: getEffectiveSiteCode(),
                        ym: _sgYm,
                        half: _sgPeriodFilter === '1' ? 1 : (_sgPeriodFilter === '2' ? 2 : null),
                        username: user ? user.username : ''
                    });
            });
        }

         
        function openSigViewFor(docNo, role, roleTxt, subtitle) {
            var bd = document.getElementById('sigViewBackdrop');
            if (bd) bd.classList.add('open');
            var t = document.getElementById('sigViewTitle');
            if (t) t.textContent = 'ลายเซ็น' + roleTxt;
            var s = document.getElementById('sigViewSub');
            if (s) s.textContent = subtitle || docNo;
            var body = document.getElementById('sigViewBody');
            if (body) body.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-spinner fa-spin"></i><span>กำลังโหลดลายเซ็น...</span></div>';
            google.script.run
                .withSuccessHandler(function (res) {
                    if (!body) return;
                    if (!res || !res.success) {
                        body.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-triangle-exclamation"></i><span>' +
                            escapeHtml((res && res.message) || 'โหลดลายเซ็นไม่สำเร็จ') + '</span></div>';
                        return;
                    }
                    var img = res.signatureData
                        ? '<div class="sig-use-box" style="margin-bottom:0.6rem;"><img src="' + res.signatureData + '" alt="ลายเซ็น"></div>'
                        : '<div class="esign-note" style="text-align:center; padding:0.8rem 0;">— ไม่มีภาพลายเซ็น (' +
                          (res.status === 'auto' ? 'รับทราบโดยปริยาย' : 'ไม่พบข้อมูล') + ') —</div>';
                    body.innerHTML = img +
                        '<div style="text-align:center;">' +
                            '<div style="font-weight:800; font-size:0.95rem;">( ' + escapeHtml(res.signerName || '-') + ' )</div>' +
                            (res.signerPos ? '<div class="esign-note">' + escapeHtml(res.signerPos) + '</div>' : '') +
                            (res.signedAt ? '<div class="esign-note">ลงนามเมื่อ ' + _esThDT(res.signedAt) + '</div>' : '') +
                            (res.note ? '<div class="esign-note" style="margin-top:0.4rem;">' + escapeHtml(res.note) + '</div>' : '') +
                        '</div>';
                })
                .withFailureHandler(function (err) {
                    if (body) body.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-triangle-exclamation"></i><span>' +
                        escapeHtml((err && err.message) || 'โหลดลายเซ็นไม่สำเร็จ') + '</span></div>';
                })
                .getSignatureView({ docNo: docNo, role: role, username: user ? user.username : '' });
        }
        function sgViewSig(i, role) {
            var doc = _sgData && _sgData.docs[i];
            if (!doc) return;
            var roleTxt = role === 'contractor' ? 'ผู้รับเหมารับทราบ' : role === 'inspector' ? 'ผู้ตรวจสอบ' : 'ผู้อนุมัติ';
            openSigViewFor(doc.docNo, role, roleTxt, doc.docNo + ' · ' + doc.subName + ' · ' + _esDaysText(doc.days));
        }
        function closeSigView() {
            var bd = document.getElementById('sigViewBackdrop');
            if (bd) bd.classList.remove('open');
        }

         
        var _signDetailOpts = null;
        function openSignDetail(docNo, opts) {
            _signDetailOpts = opts || {};
            var bd = document.getElementById('signDetailBackdrop');
            if (bd) bd.classList.add('open');
            var title = document.getElementById('signDetailTitle');
            if (title) title.textContent = docNo;
            var sub = document.getElementById('signDetailSub');
            if (sub) sub.innerHTML = _signDetailOpts.subtitle || '—';
            var body = document.getElementById('signDetailBody');
            if (body) body.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-spinner fa-spin"></i><span>กำลังโหลดรายการ...</span></div>';
            _signDetailRenderActions();
            google.script.run
                .withSuccessHandler(function (res) {
                    if (!res || !res.success) {
                        if (body) body.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-triangle-exclamation"></i><span>' +
                            escapeHtml((res && res.message) || 'โหลดรายการไม่สำเร็จ') + '</span></div>';
                        return;
                    }
                    if (body) body.innerHTML = _signDetailHtml(res.detail);
                    var sub2 = document.getElementById('signDetailSub');
                    if (sub2 && res.detail) {
                        sub2.textContent = res.detail.subName + ' · ' + res.detail.monthLabel + ' · ' + _esDaysText(res.detail.days);
                    }
                })
                .withFailureHandler(function (err) {
                    if (body) body.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-triangle-exclamation"></i><span>' +
                        escapeHtml((err && err.message) || 'โหลดรายการไม่สำเร็จ') + '</span></div>';
                })
                .getSignDocDetail({ docNo: docNo, username: user ? user.username : '' });
        }
        function closeSignDetail() {
            var bd = document.getElementById('signDetailBackdrop');
            if (bd) bd.classList.remove('open');
            _signDetailOpts = null;
        }
        function _signDetailRenderActions() {
            var act = document.getElementById('signDetailActions');
            if (!act) return;
            if (_signDetailOpts && _signDetailOpts.signTaskIdx !== undefined && _signDetailOpts.signTaskIdx !== null) {
                act.style.gridTemplateColumns = '1fr 1fr';
                act.innerHTML = '<button type="button" class="btn btn-secondary" onclick="closeSignDetail()">ปิด</button>' +
                    '<button type="button" class="btn btn-primary" onclick="var i=_signDetailOpts.signTaskIdx; closeSignDetail(); openSignTaskModal(i);"><i class="fa-solid fa-pen-nib"></i> ลงนามใบนี้</button>';
            } else {
                act.style.gridTemplateColumns = '1fr';
                act.innerHTML = '<button type="button" class="btn btn-secondary" onclick="closeSignDetail()">ปิด</button>';
            }
        }
        function _signDetailHtml(d) {
            if (!d) return '';
             
            if (d.noVat && d.seRows && d.seRows.length) {
                var cfg = d.seCfg || {};
                var fmtN = function (v) {
                    if (v === '' || v === null || v === undefined) return '';
                    var n = parseFloat(v);
                    return isNaN(n) ? escapeHtml(v) : n.toLocaleString('en-US');
                };
                var fmtM = function (v) {
                    if (v === '' || v === null || v === undefined) return '';
                    var n = parseFloat(v);
                    return isNaN(n) ? '' : n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                };
                var sumKeys = ['roomQty', 'roomAmt', 'elecUsed', 'elecOver', 'elecAmt', 'shopAmt',
                               'shopElecUnits', 'shopElecAmt', 'materialAmt', 'advanceAmt', 'safetyFine', 'faceScanFine', 'totalAmt'];
                var sums = {};
                sumKeys.forEach(function (k) { sums[k] = 0; });
                var rowsHtml = '';
                d.seRows.forEach(function (r) {
                    sumKeys.forEach(function (k) { var v = parseFloat(r[k]); if (!isNaN(v)) sums[k] += v; });
                    var noteTxt = (r.outsideStay ? 'พักข้างนอก' : '') + (r.note ? ((r.outsideStay ? ' · ' : '') + r.note) : '');
                    var tdR = function (v) { return '<td style="text-align:right; white-space:nowrap;">' + v + '</td>'; };
                    rowsHtml += '<tr' + (r.outsideStay ? ' style="background:#fbdcdc;"' : '') + '>' +
                        '<td style="text-align:center;">' + r.no + '</td>' +
                        '<td style="white-space:nowrap;">' + escapeHtml(r.subName) + '</td>' +
                        tdR(fmtN(r.roomQty)) + tdR(fmtM(r.roomAmt)) +
                        tdR(fmtN(r.elecUsed)) + tdR(fmtN(r.elecOver)) + tdR(fmtM(r.elecAmt)) +
                        tdR(fmtM(r.shopAmt)) +
                        tdR(fmtN(r.shopElecPrev)) + tdR(fmtN(r.shopElecCurr)) + tdR(fmtN(r.shopElecUnits)) + tdR(fmtM(r.shopElecAmt)) +
                        tdR(fmtM(r.materialAmt)) + tdR(fmtM(r.advanceAmt)) + tdR(fmtM(r.safetyFine)) + tdR(fmtM(r.faceScanFine)) +
                        '<td style="text-align:right; white-space:nowrap; font-weight:800; background:var(--warning-bg, #fef9c3);">' + fmtM(r.totalAmt) + '</td>' +
                        '<td style="min-width:90px;">' + escapeHtml(noteTxt) + '</td>' +
                    '</tr>';
                });
                var tdS = function (v, money) {
                    return '<td style="text-align:right; white-space:nowrap; font-weight:800;">' + (money ? fmtM(v) : fmtN(v)) + '</td>';
                };
                var totalRowHtml = '<tr style="background:#f3c218; color:#13294b;">' +
                    '<td colspan="2" style="text-align:center; font-weight:800;">รวมทั้งสิ้น</td>' +
                    tdS(sums.roomQty) + tdS(sums.roomAmt, 1) +
                    tdS(sums.elecUsed) + tdS(sums.elecOver) + tdS(sums.elecAmt, 1) +
                    tdS(sums.shopAmt, 1) +
                    '<td></td><td></td>' + tdS(sums.shopElecUnits) + tdS(sums.shopElecAmt, 1) +
                    tdS(sums.materialAmt, 1) + tdS(sums.advanceAmt, 1) + tdS(sums.safetyFine, 1) + tdS(sums.faceScanFine, 1) +
                    tdS(sums.totalAmt, 1) + '<td></td></tr>';
                return '<div class="esign-note" style="margin-bottom:0.4rem;">' +
                        'รายการหักคจช. รายชุด' + (d.periodLabel ? ' · งวด' + escapeHtml(d.periodLabel) : '') +
                        ' · อัตรา: ห้องพัก ' + escapeHtml(String(cfg.roomRate || 160)) + ' บ./งวด · ไฟเกิน ' + escapeHtml(String(cfg.elecFreeUnits || 30)) +
                        ' หน่วย (' + escapeHtml(String(cfg.elecRate || 6)) + ' บ./หน่วย) · ' + escapeHtml(cfg.shopHeader || 'ร้านค้า / เครื่องใช้') +
                        ' · มิเตอร์ไฟร้านค้า ' + escapeHtml(String(cfg.shopElecRate || 7)) + ' บ./หน่วย · แถวสีแดง = พักข้างนอก</div>' +
                    '<div style="overflow-x:auto;"><table class="sign-detail-tbl" style="min-width:1120px; font-size:0.72rem;">' +
                    '<thead><tr>' +
                        '<th>ลำดับ</th><th style="text-align:left;">ผู้รับเหมาชุด</th>' +
                        '<th>ห้องพัก<br>จำนวน</th><th>ห้องพัก<br>จำนวนเงิน</th>' +
                        '<th>ไฟ<br>ที่ใช้</th><th>ไฟ<br>ที่เกิน</th><th>ไฟ<br>จำนวนเงิน</th>' +
                        '<th>ร้านค้า/เครื่องใช้<br>จำนวนเงิน</th>' +
                        '<th>มิเตอร์<br>ครั้งก่อน</th><th>มิเตอร์<br>ปัจจุบัน</th><th>รวม<br>หน่วย</th><th>มิเตอร์<br>จำนวนเงิน</th>' +
                        '<th>วัสดุฯ</th><th>Advance</th><th>หักผิดกฏ<br>ความปลอดภัย</th><th>หักผิดกฏ<br>ไม่สแกนนิ้ว/หน้า</th>' +
                        '<th>รวม</th><th style="text-align:left;">หมายเหตุ</th>' +
                    '</tr></thead>' +
                    '<tbody>' + rowsHtml + totalRowHtml + '</tbody></table></div>';
            }
             
            if (d.noVat) {
                var seRows = '';
                (d.items || []).forEach(function (it) {
                    seRows += '<tr>' +
                        '<td style="text-align:center;">' + it.no + '</td>' +
                        '<td>' + escapeHtml(it.name) + '</td>' +
                        '<td style="text-align:right;">' + _esMoney(it.amount) + '</td>' +
                    '</tr>';
                });
                if (!seRows) seRows = '<tr><td colspan="3" style="text-align:center; color:var(--text-muted); padding:1rem;">— ไม่มีข้อมูล —</td></tr>';
                return '<div class="esign-note" style="margin-bottom:0.4rem;">สรุปยอดหักค่าใช้จ่ายรายชุด' + (d.periodLabel ? ' · งวด' + escapeHtml(d.periodLabel) : '') + '</div>' +
                    '<div style="overflow-x:auto;"><table class="sign-detail-tbl" style="min-width:380px;">' +
                    '<thead><tr><th>ลำดับ</th><th style="text-align:left;">ผู้รับเหมาชุด</th><th>รวม (บาท)</th></tr></thead>' +
                    '<tbody>' + seRows + '</tbody></table></div>' +
                    '<div class="sign-detail-totals" style="background:var(--warning-bg, #fef9c3); border-radius:8px; font-weight:800;"><span>รวมทั้งสิ้น</span><b>' + _esMoney(d.total) + '</b></div>';
            }
            var rows = '';
            (d.items || []).forEach(function (it) {
                rows += '<tr>' +
                    '<td style="text-align:center;">' + it.no + '</td>' +
                    '<td style="text-align:center; white-space:nowrap;">' + escapeHtml(it.dateTh) + '</td>' +
                    '<td style="text-align:center; white-space:nowrap;">' + escapeHtml(it.docId) + '</td>' +
                    '<td>' + escapeHtml(it.name) + '</td>' +
                    '<td style="text-align:center;">' + escapeHtml(it.unit || '-') + '</td>' +
                    '<td style="text-align:center;">' + escapeHtml(it.qty) + '</td>' +
                    '<td style="text-align:right;">' + _esMoney(it.price) + '</td>' +
                    '<td style="text-align:right;">' + _esMoney(it.amount) + '</td>' +
                '</tr>';
            });
            if (!rows) rows = '<tr><td colspan="8" style="text-align:center; color:var(--text-muted); padding:1rem;">— ไม่มีรายการ —</td></tr>';
            var mangoLine = (d.mangoCode || d.mangoName)
                ? '<div class="esign-note" style="margin-bottom:0.4rem;">Mango Vendor: <b>' + escapeHtml(d.mangoCode || '-') + '</b> · ' + escapeHtml(d.mangoName || '-') + '</div>'
                : '';
            return mangoLine +
                '<div style="overflow-x:auto;"><table class="sign-detail-tbl">' +
                '<thead><tr><th>ลำดับ</th><th>ว/ด/ป</th><th>เลขที่</th><th style="text-align:left;">รายการ</th><th>หน่วย</th><th>ปริมาณ</th><th>ราคา/หน่วย</th><th>จำนวนเงิน</th></tr></thead>' +
                '<tbody>' + rows + '</tbody></table></div>' +
                '<div class="sign-detail-totals" style="background:var(--warning-bg, #fef9c3); border-radius:8px; font-weight:800;"><span>รวมเงินหักทั้งสิ้น</span><b>' + _esMoney(d.total) + '</b></div>' +
                '<div class="esign-note" style="margin-top:0.4rem;">รายการที่ยังไม่ตั้งราคาแสดงเป็น “—” (ตั้งได้ที่แท็บ “ตั้งราคาหักเงิน”)</div>';
        }

         
        var signTasksData = [];
        var _signTaskCurrent = null;    
        var _signTaskFreshSig = '';     

        function loadMySignTasks() {
            if (!PORT_PHASE.signature) return;    
            if (!user || !user.username) return;
            google.script.run
                .withSuccessHandler(function (res) {
                    signTasksData = (res && res.success && res.tasks) ? res.tasks : [];
                    if (res && res.success) _mySigData.hasFromTasks = !!res.hasSignature;
                    renderSignTasks();
                    if (typeof updateNavBadges === 'function') updateNavBadges();
                })
                .withFailureHandler(function (err) { console.warn('loadMySignTasks failed:', err); })
                .getMySignTasks(user.username);
        }
        function renderSignTasks() {
            var section = document.getElementById('signTasksSection');
            var list = document.getElementById('signTasksList');
            var count = document.getElementById('signTasksCount');
            if (!section || !list) return;
            if (!signTasksData.length) {
                section.style.display = 'none';
                list.innerHTML = '';
                return;
            }
            section.style.display = '';
            if (count) count.textContent = signTasksData.length;
            list.innerHTML = signTasksData.map(function (t, i) {
                var roleChip = t.role === 'inspector'
                    ? '<span class="sign-task-role inspector"><i class="fa-solid fa-user-check"></i> ผู้ตรวจสอบ</span>'
                    : '<span class="sign-task-role approver"><i class="fa-solid fa-user-shield"></i> ผู้อนุมัติ</span>';
                var daysTxt = _esDaysText(t.days);
                return '<div class="sign-task-card">' +
                    '<div class="sign-task-top">' + roleChip +
                        '<span style="font-weight:800; font-size:0.85rem;">' + escapeHtml(t.docNo) + '</span>' +
                        '<span class="sign-task-meta">' + escapeHtml(t.subName) + '</span>' +
                    '</div>' +
                    '<div class="sign-task-meta">' + escapeHtml(t.monthLabel) + ' · ' + escapeHtml(daysTxt) +
                        ' · ขอโดย ' + escapeHtml(t.requestedByName || t.requestedBy || '-') +
                        (t.requestedAt ? ' · ' + _esThDT(t.requestedAt) : '') + '</div>' +
                    '<div class="sign-task-actions">' +
                        '<button type="button" class="esign-btn soft" onclick="signTaskDetail(' + i + ')"><i class="fa-solid fa-list"></i> ดูรายการ</button>' +
                        '<button type="button" class="esign-btn primary" onclick="openSignTaskModal(' + i + ')"><i class="fa-solid fa-pen-nib"></i> ลงนาม</button>' +
                    '</div>' +
                '</div>';
            }).join('');
        }
        function signTaskDetail(i) {
            var t = signTasksData[i];
            if (!t) return;
            openSignDetail(t.docNo, {
                subtitle: escapeHtml(t.subName) + ' · ' + escapeHtml(t.monthLabel),
                signTaskIdx: i
            });
        }
        function openSignTaskModal(i) {
            var t = signTasksData[i];
            if (!t) return;
            _signTaskCurrent = t;
            _signTaskFreshSig = '';
            var title = document.getElementById('signTaskTitle');
            if (title) title.textContent = 'ลงนาม' + (t.role === 'inspector' ? 'ผู้ตรวจสอบ' : 'ผู้อนุมัติ');
            var sub = document.getElementById('signTaskSub');
            if (sub) sub.textContent = t.docNo + ' · ' + t.subName + ' · ' + t.monthLabel;
            var pos = document.getElementById('signTaskPos');
             
            if (pos) pos.value = (user && user.roleId) || t.assigneePos || (t.role === 'inspector' ? 'PE / SSE' : 'PM');
            var saveWrap = document.getElementById('signTaskSaveWrap');
            if (saveWrap) saveWrap.style.display = 'none';
            if (!_mySigData.loaded) loadMySignature(_signTaskRefreshSigBox);
            _signTaskRefreshSigBox();
            var bd = document.getElementById('signTaskBackdrop');
            if (bd) bd.classList.add('open');
        }
        function closeSignTaskModal() {
            var bd = document.getElementById('signTaskBackdrop');
            if (bd) bd.classList.remove('open');
            _signTaskCurrent = null;
            _signTaskFreshSig = '';
        }
        function _signTaskRefreshSigBox() {
            var box = document.getElementById('signTaskSigBox');
            if (!box) return;
            var sig = _signTaskFreshSig || (_mySigData.loaded ? _mySigData.dataUrl : '');
            if (sig) {
                box.innerHTML = '<img src="' + sig + '" alt="ลายเซ็น">' +
                    (_signTaskFreshSig ? '' : '');
            } else if (!_mySigData.loaded) {
                box.innerHTML = '<span class="sig-preview-empty"><i class="fa-solid fa-spinner fa-spin"></i> กำลังโหลดลายเซ็น...</span>';
            } else {
                box.innerHTML = '<span class="sig-preview-empty"><i class="fa-solid fa-triangle-exclamation" style="color:#d97706;"></i> ยังไม่มีลายเซ็น — กด “วาดลายเซ็นใหม่” ด้านล่าง</span>';
            }
        }
        function signTaskDraw() {
            openSigPad({
                onSave: function (dataUrl) {
                    _signTaskFreshSig = dataUrl;
                    _signTaskRefreshSigBox();
                    var saveWrap = document.getElementById('signTaskSaveWrap');
                    if (saveWrap) saveWrap.style.display = '';
                }
            });
        }
        function submitSignTask() {
            var t = _signTaskCurrent;
            if (!t) return;
            var sig = _signTaskFreshSig || (_mySigData.loaded ? _mySigData.dataUrl : '');
            if (!sig) { showToast('กรุณาวาดลายเซ็น หรือตั้งลายเซ็นในตั้งค่าบัญชีก่อน', 'danger'); return; }
            var pos = (document.getElementById('signTaskPos') || {}).value || '';
            var saveToProfile = _signTaskFreshSig && (document.getElementById('signTaskSaveProfile') || {}).checked;
            var goBtn = document.getElementById('signTaskGo');
            if (goBtn) goBtn.disabled = true;
            showLoadingPopup('กำลังลงนาม', 'กำลังบันทึกลายเซ็นลงเอกสาร ' + t.docNo + '...');
            google.script.run
                .withSuccessHandler(function (res) {
                    closeAppPopup();
                    if (goBtn) goBtn.disabled = false;
                    if (!res || !res.success) {
                        var msg = (res && res.message) || 'ลงนามไม่สำเร็จ';
                        if (msg === 'no_signature') msg = 'ยังไม่มีลายเซ็น — วาดลายเซ็นก่อนลงนาม';
                        showInfoPopup('ลงนามไม่สำเร็จ', msg, 'danger');
                        return;
                    }
                    if (_signTaskFreshSig && saveToProfile) _mySigData = { loaded: true, dataUrl: _signTaskFreshSig };
                    closeSignTaskModal();
                    hapticSuccess();
                    showToast('✅ ลงนาม ' + t.docNo + ' เรียบร้อย', 'success');
                    loadMySignTasks();
                })
                .withFailureHandler(function (err) {
                    closeAppPopup();
                    if (goBtn) goBtn.disabled = false;
                    showInfoPopup('ลงนามไม่สำเร็จ', (err && err.message) || 'กรุณาลองใหม่', 'danger');
                })
                .submitSignature({
                    docNo: t.docNo, role: t.role, username: user.username,
                    dataUrl: _signTaskFreshSig || '', pos: pos, saveToProfile: !!saveToProfile
                });
        }

         
         
         
         
         
         
         
         
        var _seData = null;         
        var _seDirty = false;       
        var _seShopTargetTr = null;    

         
         
        var SE_FIELDS = ['roomQty', 'roomAmt', 'elecUsed', 'elecOver', 'elecAmt',
                         'shopElecPrev', 'shopElecCurr', 'shopElecUnits', 'shopElecAmt',
                         'materialAmt', 'advanceAmt', 'safetyFine', 'faceScanFine'];
         
        var SE_OUTSIDE_LOCK_FIELDS = ['roomQty', 'roomAmt', 'elecUsed', 'elecOver', 'elecAmt'];
         
        var SE_SUM_FIELDS = ['roomAmt', 'elecAmt', 'shopElecAmt',
                             'materialAmt', 'advanceAmt', 'safetyFine', 'faceScanFine'];
         
        var SE_FOOT_COLS = ['roomQty', 'roomAmt', 'elecUsed', 'elecOver', 'elecAmt',
                            'shopAmt', 'shopElecPrev', 'shopElecCurr', 'shopElecUnits', 'shopElecAmt',
                            'materialAmt', 'advanceAmt', 'safetyFine', 'faceScanFine'];

        function _seNumVal(v) {
            if (v === null || v === undefined || v === '') return '';
            var n = parseFloat(String(v).replace(/,/g, ''));
            return isNaN(n) ? '' : n;
        }
        function _seRound(n) { return Math.round(n * 100) / 100; }
        function _seFmt(n) {
            if (n === '' || n === null || n === undefined || isNaN(n)) return '';
            return Number(n).toLocaleString('en-US', { maximumFractionDigits: 2 });
        }
         
        function _seCfg() {
            var cfg = (_seData && _seData.config) || {
                roomRate: 160, elecRate: 6, elecFreeUnits: 30, shopElecRate: 7,
                shopItems: [{ label: 'ร้านค้า', price: 1000 }, { label: 'เครื่องซักผ้า', price: 500 }, { label: 'ตู้กดน้ำ', price: 300 }]
            };
            if (cfg.elecFreeUnits === undefined || cfg.elecFreeUnits === '') cfg.elecFreeUnits = 30;
            return cfg;
        }
         
        function _seShopParse(s) {
            if (!s) return [];
            try {
                var arr = JSON.parse(s);
                if (!Array.isArray(arr)) return [];
                return arr.filter(function (it) { return Array.isArray(it) && it.length >= 2 && (it[0] || '').toString().trim(); })
                          .map(function (it) {
                              var price = _seNumVal(it[1]); var qty = _seNumVal(it[2]);
                              return [it[0].toString(), price === '' ? 0 : price, (qty === '' || qty <= 0) ? 1 : qty];
                          });
            } catch (e) { return []; }
        }
        function _seShopAmt(arr) {
            var s = 0;
            arr.forEach(function (it) { s += (it[1] || 0) * (it[2] || 1); });
            return _seRound(s);
        }
        function _seShopBtnHtml(arr) {
            if (!arr.length) return '<span class="se-shop-none">— ไม่มี —</span>';
            var names = arr.map(function (it) { return it[0] + (it[2] > 1 ? '×' + it[2] : ''); }).join(' + ');
            return '<span class="se-shop-items">' + escapeHtml(names) + '</span><span class="se-shop-amt">' + _seFmt(_seShopAmt(arr)) + '</span>';
        }
         
        function _seDefaultPeriod() {
            var d = new Date();
            return {
                ym: d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2),
                half: d.getDate() <= 15 ? 1 : 2
            };
        }

        function loadSubExpensePage() {
            var body = document.getElementById('seBody');
            if (body) body.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-spinner fa-spin"></i><span>กำลังโหลดข้อมูล...</span></div>';
            var mEl = document.getElementById('seMonth'), hEl = document.getElementById('seHalf'), sEl = document.getElementById('seSiteSelect');
            var ym = (mEl && /^\d{4}-\d{2}$/.test(mEl.value || '')) ? mEl.value : '';
            if (!ym) {
                var dp = _seDefaultPeriod();
                ym = dp.ym;
                if (mEl) mEl.value = ym;
                if (hEl) hEl.value = String(dp.half);
            }
            var half = hEl ? (parseInt(hEl.value, 10) === 2 ? 2 : 1) : 1;
            var site = (sEl && sEl.value) ? sEl.value : getEffectiveSiteCode();
            google.script.run
                .withSuccessHandler(renderSubExpense)
                .withFailureHandler(function (err) {
                    if (body) body.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-triangle-exclamation"></i><span>โหลดไม่สำเร็จ: ' + escapeHtml((err && err.message) || String(err || '')) + '</span></div>';
                })
                .getSubExpenseData({ siteCode: site, ym: ym, half: half, username: user ? user.username : '' });
        }

         
        function onSePeriodChange() {
            if (_seDirty && !confirm('มีข้อมูลที่แก้ไขแล้วยังไม่ได้บันทึก — เปลี่ยนงวด/ไซต์แล้วการแก้ไขจะหายไป ดำเนินการต่อหรือไม่?')) {
                if (_seData) {    
                    var mEl = document.getElementById('seMonth'), hEl = document.getElementById('seHalf'), sEl = document.getElementById('seSiteSelect');
                    if (mEl) mEl.value = _seData.ym;
                    if (hEl) hEl.value = String(_seData.half);
                    if (sEl && _seData.siteCode) sEl.value = _seData.siteCode;
                }
                return;
            }
            _seDirty = false;
            loadSubExpensePage();
        }

        function renderSubExpense(resp) {
            var body = document.getElementById('seBody');
            if (!body) return;
            if (!resp || !resp.success) {
                var msg = (resp && resp.message === 'no_permission') ? 'คุณไม่มีสิทธิ์ใช้งานเมนูนี้ (เฉพาะเลขาประจำไซต์ สิทธิ์ SC)' : ((resp && resp.message) || 'โหลดไม่สำเร็จ');
                body.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-triangle-exclamation"></i><span>' + escapeHtml(msg) + '</span></div>';
                return;
            }
            _seData = resp;
            _seDirty = false;

             
            var sField = document.getElementById('seSiteField');
            var sEl = document.getElementById('seSiteSelect');
            if (sEl) {
                sEl.innerHTML = (resp.sites || []).map(function (s) {
                    return '<option value="' + escapeHtml(s.code) + '">' + escapeHtml(s.code + (s.name ? ' — ' + s.name : '')) + '</option>';
                }).join('');
                sEl.value = resp.siteCode;
            }
            if (sField) sField.style.display = resp.isAdmin ? '' : 'none';
            var mEl = document.getElementById('seMonth'), hEl = document.getElementById('seHalf');
            if (mEl) mEl.value = resp.ym;
            if (hEl) hEl.value = String(resp.half);
            var pl = document.getElementById('sePeriodLabel');
            if (pl) pl.textContent = 'งวด' + resp.periodLabel + ' · Site ' + resp.siteCode + (resp.siteName ? ' (' + resp.siteName + ')' : '');

             
            var byName = {};
            (resp.rows || []).forEach(function (r) { if (r && r.subName) byName[r.subName] = r; });
            var names = [];
            (resp.subs || []).forEach(function (n) { if (names.indexOf(n) === -1) names.push(n); });
            (resp.rows || []).forEach(function (r) { if (r && r.subName && names.indexOf(r.subName) === -1) names.push(r.subName); });

            var cfg = _seCfg();
            var html = '';
            html += '<div class="se-summary-bar">' +
                        '<span class="se-sum-chip">ผู้รับเหมา <b id="seSumRows">0</b> ชุดที่มีรายการหัก</span>' +
                        '<span class="se-sum-chip se-sum-grand">รวมทั้งสิ้น <b id="seSumGrand">0</b> บาท</span>' +
                        '<span class="se-legend"><span class="se-lg-prev"></span> ค่าจากงวดก่อน (แก้ได้) &nbsp;·&nbsp; <span class="se-lg-ro"></span> คำนวณ/ดึงอัตโนมัติ (แก้ไม่ได้: วัสดุฯ + ไม่สแกนหน้า)</span>' +
                        '<span class="se-dirty-hint" id="seDirtyHint"><i class="fa-solid fa-triangle-exclamation"></i> มีข้อมูลที่ยังไม่ได้บันทึก</span>' +
                    '</div>';
            if (!names.length) {
                html += '<div class="rate-alert"><i class="fa-solid fa-circle-info"></i><span>ไซต์นี้ยังไม่มีผู้รับเหมาที่เปิดใช้งาน — เปิดใช้งานได้ที่เมนู "ตั้งค่า" หรือกดปุ่ม "เพิ่มแถว" เพื่อพิมพ์ชื่อเอง</span></div>';
            }
            var shopHead = (cfg.shopItems && cfg.shopItems.length)
                ? cfg.shopItems.map(function (it) { return escapeHtml(it.label) + ' ' + _seFmt(it.price); }).join(', ')
                : 'ร้านค้า / เครื่องใช้';
            html += '<div class="se-wrap"><table class="se-table" id="seTable">';
            html += '<thead>' +
                '<tr>' +
                    '<th rowspan="2">ลำดับ</th>' +
                    '<th rowspan="2" style="min-width:140px;">ผู้รับเหมาชุด</th>' +
                    '<th rowspan="2" style="min-width:90px;">ชื่อใช้เบิก<br>Payment</th>' +
                    '<th colspan="2">ห้องพัก<br>(' + _seFmt(cfg.roomRate) + ' บ./งวด)</th>' +
                    '<th colspan="3">ค่าไฟส่วนที่ใช้เกิน ' + _seFmt(cfg.elecFreeUnits) + ' หน่วย<br>(' + _seFmt(cfg.elecRate) + ' บ./หน่วย)</th>' +
                    '<th rowspan="2" style="min-width:115px;">' + shopHead + '<br><span style="font-weight:400; opacity:0.75;">(คลิกช่องเพื่อติ๊ก)</span></th>' +
                    '<th colspan="4">มิเตอร์ไฟ ร้านค้า (' + _seFmt(cfg.shopElecRate) + ' บ./หน่วย)</th>' +
                    '<th rowspan="2">วัสดุฯ<br><span style="font-weight:400; opacity:0.75;">(จากตรวจสอบ<br>ประจำวัน)</span></th>' +
                    '<th rowspan="2">Advance</th>' +
                    '<th rowspan="2">หักผิดกฏ<br>ความ<br>ปลอดภัย</th>' +
                    '<th rowspan="2">หักผิดกฏ<br>ไม่สแกน<br>หน้า</th>' +
                    '<th rowspan="2" style="min-width:62px;">รวม</th>' +
                    '<th rowspan="2">พัก<br>ข้าง<br>นอก</th>' +
                    '<th rowspan="2" style="min-width:76px;">หมายเหตุ</th>' +
                    '<th rowspan="2"></th>' +
                '</tr>' +
                '<tr>' +
                    '<th class="se-th-sub">จำนวน</th><th class="se-th-sub">จำนวนเงิน</th>' +
                    '<th class="se-th-sub">ที่ใช้</th><th class="se-th-sub">ที่เกิน</th><th class="se-th-sub">จำนวนเงิน</th>' +
                    '<th class="se-th-sub">ครั้งก่อน</th><th class="se-th-sub">ปัจจุบัน</th><th class="se-th-sub">รวมหน่วย</th><th class="se-th-sub">จำนวนเงิน</th>' +
                '</tr>' +
            '</thead>';
            html += '<tbody id="seTbody">' + names.map(function (n, i) { return _seRowHtml(i, n, byName[n] || {}); }).join('') + '</tbody>';
            html += '<tfoot><tr>' +
                        '<td colspan="3" class="se-foot-lbl">รวมทั้งสิ้น</td>' +
                        SE_FOOT_COLS.map(function (f) { return '<td data-foot="' + f + '"></td>'; }).join('') +
                        '<td data-foot="total"></td><td></td><td></td><td></td>' +
                    '</tr></tfoot>';
            html += '</table></div>';
            body.innerHTML = html;

            var tbl = document.getElementById('seTable');
            if (tbl) tbl.addEventListener('input', _seOnInput);
            var saveBtn = document.getElementById('seSaveBtn');
            if (saveBtn) saveBtn.disabled = true;

             
             
            var prefilled = _sePrefillDefaults();
            _seRecalcTotals();
            if (prefilled) {
                _seMarkDirty();
                var hint = document.getElementById('seDirtyHint');
                if (hint) hint.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> เติมค่า default จากงวดก่อน (ห้องพัก/ร้านค้า/มิเตอร์) แล้ว · วัสดุฯ + หักไม่สแกนหน้า ดึงสดอัตโนมัติ (ล็อกแก้ไม่ได้) — กด "บันทึก" เพื่อเก็บ';
            }
        }

         
        function _sePayCellHtml(pay, payCode) {
            if (!pay && !payCode) return '<span class="se-pay-none">—</span>';
            return (pay ? escapeHtml(pay) : '') +
                   (payCode ? (pay ? '<br>' : '') + '<span class="se-pay-code">' + escapeHtml(payCode) + '</span>' : '');
        }

         
         
         
        var SE_READONLY_FIELDS = { roomAmt: 1, elecAmt: 1, shopElecUnits: 1, shopElecAmt: 1, materialAmt: 1, faceScanFine: 1 };

        function _seNumCell(f, val, extraClass, title, dis) {
            var v = (val === '' || val === null || val === undefined) ? '' : val;
            var ro = SE_READONLY_FIELDS[f] === 1;
            var tt = ro ? (title || 'โปรแกรมคำนวณให้อัตโนมัติ — แก้ไขไม่ได้') : (title || '');
            return '<td><input type="number" class="se-in' + (extraClass ? ' ' + extraClass : '') + (ro ? ' se-ro' : '') + '" data-f="' + f + '" step="any" min="0" inputmode="decimal" value="' + v + '"' + (tt ? ' title="' + escapeHtml(tt) + '"' : '') + (dis ? ' disabled' : '') + (ro ? ' readonly tabindex="-1"' : '') + '></td>';
        }
        function _seRowHtml(i, name, d) {
            d = d || {};
            var g = function (f) { return (d[f] === undefined || d[f] === null) ? '' : d[f]; };
            var pay = (_seData && _seData.paymentByName && _seData.paymentByName[name]) || '';
            var payCode = (_seData && _seData.paymentCodeByName && _seData.paymentCodeByName[name]) || '';
            var shopArr = _seShopParse(g('shopItems'));
            var outside = d.outsideStay === true;
             
            var matRec = (_seData && _seData.matCharge && _seData.matCharge[name]) || null;
            var matAmt = (matRec && matRec.amt > 0) ? matRec.amt : '';
            var matTitle = matRec ? ('ดึงจากตรวจสอบประจำวัน ' + matRec.items + ' รายการ' + (matRec.unpriced ? ' (ยังไม่ตั้งราคา ' + matRec.unpriced + ' รายการ)' : '') + ' — แก้ไขไม่ได้') : 'ดึงจากตรวจสอบประจำวันอัตโนมัติ — แก้ไขไม่ได้';
            var scanFine = (_seData && _seData.scanFineByName && _seData.scanFineByName[name] > 0) ? _seData.scanFineByName[name] : '';
            var scanTitle = 'ดึงจากหน้า "บันทึกสแกนนิ้ว" ของงวดนี้อัตโนมัติ — แก้ไขไม่ได้';
             
            return '<tr class="' + (outside ? 'se-row-outside' : '') + '" data-shop-items="' + escapeHtml(shopArr.length ? JSON.stringify(shopArr) : '') + '">' +
                '<td class="se-td-idx">' + (i + 1) + '</td>' +
                '<td><input type="text" class="se-in se-name" data-f="subName" value="' + escapeHtml(name || '') + '" placeholder="ชื่อผู้รับเหมาชุด"></td>' +
                '<td class="se-td-pay">' + _sePayCellHtml(pay, payCode) + '</td>' +
                _seNumCell('roomQty', outside ? '' : g('roomQty'), '', '', outside) +
                _seNumCell('roomAmt', outside ? '' : g('roomAmt'), 'se-auto', '', outside) +
                _seNumCell('elecUsed', outside ? '' : g('elecUsed'), '', '', outside) +
                _seNumCell('elecOver', outside ? '' : g('elecOver'), '', '', outside) +
                _seNumCell('elecAmt', outside ? '' : g('elecAmt'), 'se-auto', '', outside) +
                '<td><button type="button" class="se-shop-btn" onclick="openSeShop(this)" title="คลิกเพื่อติ๊กรายการร้านค้า/เครื่องใช้">' + _seShopBtnHtml(shopArr) + '</button></td>' +
                _seNumCell('shopElecPrev', g('shopElecPrev')) +
                _seNumCell('shopElecCurr', g('shopElecCurr')) +
                _seNumCell('shopElecUnits', g('shopElecUnits'), 'se-auto') +
                _seNumCell('shopElecAmt', g('shopElecAmt'), 'se-auto') +
                _seNumCell('materialAmt', matAmt, 'se-auto', matTitle) +
                _seNumCell('advanceAmt', g('advanceAmt')) +
                _seNumCell('safetyFine', g('safetyFine')) +
                _seNumCell('faceScanFine', scanFine, 'se-auto', scanTitle) +
                '<td class="se-td-total"></td>' +
                '<td class="se-td-out"><label class="se-out-chk" title="ติ๊กเมื่อชุดนี้พักข้างนอก (ไฮไลต์แดง + ระบุใน PDF)"><input type="checkbox" data-f="outsideStay"' + (outside ? ' checked' : '') + '></label></td>' +
                '<td><input type="text" class="se-in se-note" data-f="note" value="' + escapeHtml(g('note')) + '" placeholder="เช่น Holiday"></td>' +
                '<td style="text-align:center;"><button type="button" class="se-del-btn" onclick="seRemoveRow(this)" title="ลบแถว"><i class="fa-solid fa-trash"></i></button></td>' +
            '</tr>';
        }

         
         
         
         
         
         
        function _sePrefillDefaults(scopeTr) {
            var tbody = document.getElementById('seTbody');
            if (!tbody) return false;
            var prev = (_seData && _seData.prevMeterByName) || {};
            var prevRoom = (_seData && _seData.prevRoomByName) || {};
            var prevShop = (_seData && _seData.prevShopByName) || {};
            var filled = false;
             
            var rowList = scopeTr ? [scopeTr] : tbody.querySelectorAll('tr');
            Array.prototype.forEach.call(rowList, function (tr) {
                var nameEl = tr.querySelector('[data-f="subName"]');
                var nm = nameEl ? nameEl.value.trim() : '';
                if (!nm) return;
                var prevEl = tr.querySelector('[data-f="shopElecPrev"]');
                if (prevEl && prevEl.value === '' && prev[nm] !== undefined && prev[nm] !== '') {
                    prevEl.value = prev[nm];
                    prevEl.title = 'เลขมิเตอร์ "ปัจจุบัน" ของงวดก่อน';
                    prevEl.classList.add('se-prev');    
                    filled = true;
                }
                 
                var roomEl = tr.querySelector('[data-f="roomQty"]');
                if (roomEl && !roomEl.disabled && roomEl.value === '' &&
                    prevRoom[nm] !== undefined && prevRoom[nm] !== '') {
                    roomEl.value = prevRoom[nm];
                    roomEl.title = 'จำนวนห้องจากงวดก่อน';
                    roomEl.classList.add('se-prev');    
                    _seApplyAuto(tr, 'roomQty');    
                    filled = true;
                }
                 
                if (!tr.getAttribute('data-shop-items') && prevShop[nm]) {
                    var arr = _seShopParse(prevShop[nm]);
                    if (arr.length) {
                        tr.setAttribute('data-shop-items', JSON.stringify(arr));
                        var sbtn = tr.querySelector('.se-shop-btn');
                        if (sbtn) {
                            sbtn.innerHTML = _seShopBtnHtml(arr);
                            sbtn.title = 'รายการจากงวดก่อน — คลิกเพื่อแก้ไข';
                            sbtn.classList.add('se-prev');    
                        }
                        filled = true;
                    }
                }
            });
            return filled;
        }

         
        function seFillMaterials() {
            if (!_seData) { showToast('กรุณารอหน้าโหลดเสร็จก่อน', 'danger'); return; }
            var tbody = document.getElementById('seTbody');
            if (!tbody) return;
            if (!confirm('ดึงค่าวัสดุ (หักเงิน) จากตรวจสอบประจำวัน งวด' + _seData.periodLabel + '\nมาเขียนทับคอลัมน์ "วัสดุฯ" ทุกแถว ?')) return;
            var mat = _seData.matCharge || {};
            var hit = 0, unpriced = 0;
            Array.prototype.forEach.call(tbody.querySelectorAll('tr'), function (tr) {
                var nameEl = tr.querySelector('[data-f="subName"]');
                var matEl = tr.querySelector('[data-f="materialAmt"]');
                if (!matEl) return;
                var nm = nameEl ? nameEl.value.trim() : '';
                var m = nm ? mat[nm] : null;
                matEl.value = (m && m.amt > 0) ? m.amt : '';
                matEl.title = m ? ('ดึงจากตรวจสอบประจำวัน ' + m.items + ' รายการ' + (m.unpriced ? ' (ยังไม่ตั้งราคา ' + m.unpriced + ' รายการ)' : '')) : '';
                if (m) { hit++; unpriced += m.unpriced || 0; }
            });
            _seMarkDirty();
            _seRecalcTotals();
            showToast('ดึงค่าวัสดุแล้ว ' + hit + ' ชุด' + (unpriced ? ' · มี ' + unpriced + ' รายการยังไม่ตั้งราคา (เมนูตั้งราคาหักเงิน)' : ''), unpriced ? 'info' : 'success');
        }

         
        function _seGetF(tr, f) {
            var el = tr.querySelector('[data-f="' + f + '"]');
            return el ? _seNumVal(el.value) : '';
        }
        function _seSetF(tr, f, val) {
            var el = tr.querySelector('[data-f="' + f + '"]');
            if (el) el.value = (val === '' ? '' : val);
        }
        function _seRowShopAmt(tr) {
            var arr = _seShopParse(tr.getAttribute('data-shop-items'));
            return arr.length ? _seShopAmt(arr) : '';
        }

         
         
        function _seApplyAuto(tr, f) {
            var cfg = _seCfg();
            if (f === 'roomQty') {
                 
                var q = _seGetF(tr, 'roomQty');
                _seSetF(tr, 'roomAmt', q === '' ? '' : _seRound(q * (cfg.roomRate === '' ? 0 : cfg.roomRate)));
            }
            if (f === 'elecUsed') {
                 
                var used = _seGetF(tr, 'elecUsed');
                var free = cfg.elecFreeUnits === '' ? 0 : cfg.elecFreeUnits;
                var over = used === '' ? '' : Math.max(0, _seRound(used - free));
                _seSetF(tr, 'elecOver', over);
                _seSetF(tr, 'elecAmt', over === '' ? '' : _seRound(over * cfg.elecRate));
            }
            if (f === 'elecOver') {
                var ov = _seGetF(tr, 'elecOver');
                _seSetF(tr, 'elecAmt', ov === '' ? '' : _seRound(ov * cfg.elecRate));
            }
            if (f === 'shopElecPrev' || f === 'shopElecCurr') {
                var pv = _seGetF(tr, 'shopElecPrev'), cv = _seGetF(tr, 'shopElecCurr');
                if (cv !== '') {
                    var units = _seRound(cv - (pv === '' ? 0 : pv));
                    _seSetF(tr, 'shopElecUnits', units);
                    _seSetF(tr, 'shopElecAmt', _seRound(units * cfg.shopElecRate));
                } else if (pv === '') {
                    _seSetF(tr, 'shopElecUnits', '');
                    _seSetF(tr, 'shopElecAmt', '');
                }
            }
            if (f === 'shopElecUnits') {
                var un = _seGetF(tr, 'shopElecUnits');
                _seSetF(tr, 'shopElecAmt', un === '' ? '' : _seRound(un * cfg.shopElecRate));
            }
        }

         
        function _seApplyOutside(tr, on) {
            tr.classList.toggle('se-row-outside', on);
            SE_OUTSIDE_LOCK_FIELDS.forEach(function (f) {
                var el = tr.querySelector('[data-f="' + f + '"]');
                if (!el) return;
                if (on) el.value = '';
                el.disabled = on;
            });
        }

        function _seOnInput(e) {
            var el = e.target;
            if (!el || !el.getAttribute) return;
            var f = el.getAttribute('data-f');
            if (!f) return;
             
            if (el.classList) el.classList.remove('se-prev');
            var tr = el.closest('tr');
            if (tr) {
                if (f === 'outsideStay') {
                    _seApplyOutside(tr, el.checked);
                } else if (f === 'subName') {
                     
                    var payTd = tr.querySelector('.se-td-pay');
                    if (payTd) {
                        var nm2 = el.value.trim();
                        payTd.innerHTML = _sePayCellHtml(
                            (_seData && _seData.paymentByName && _seData.paymentByName[nm2]) || '',
                            (_seData && _seData.paymentCodeByName && _seData.paymentCodeByName[nm2]) || '');
                    }
                } else if (f !== 'note') {
                    _seApplyAuto(tr, f);
                }
            }
            _seMarkDirty();
            _seRecalcTotals();
        }

        function _seMarkDirty() {
            _seDirty = true;
            var saveBtn = document.getElementById('seSaveBtn');
            if (saveBtn) saveBtn.disabled = false;
            var hint = document.getElementById('seDirtyHint');
            if (hint) hint.classList.add('show');
        }

         
        function _seRecalcTotals() {
            var tbody = document.getElementById('seTbody');
            if (!tbody) return;
            var footSums = {}, grand = 0, activeRows = 0;
            SE_FOOT_COLS.forEach(function (f) { footSums[f] = { sum: 0, has: false }; });
            Array.prototype.forEach.call(tbody.querySelectorAll('tr'), function (tr) {
                var rowTotal = 0, rowHasAmt = false, rowHasAny = false;
                SE_FIELDS.forEach(function (f) {
                    var v = _seGetF(tr, f);
                    if (v !== '') {
                        if (footSums[f]) { footSums[f].sum += v; footSums[f].has = true; }
                        rowHasAny = true;
                        if (SE_SUM_FIELDS.indexOf(f) !== -1) { rowTotal += v; rowHasAmt = true; }
                    }
                });
                var shopAmt = _seRowShopAmt(tr);
                if (shopAmt !== '') {
                    footSums.shopAmt.sum += shopAmt;
                    footSums.shopAmt.has = true;
                    rowTotal += shopAmt;
                    rowHasAmt = true;
                    rowHasAny = true;
                }
                var noteEl = tr.querySelector('[data-f="note"]');
                if (noteEl && noteEl.value.trim() !== '') rowHasAny = true;
                var outEl = tr.querySelector('[data-f="outsideStay"]');
                if (outEl && outEl.checked) rowHasAny = true;
                var totalCell = tr.querySelector('.se-td-total');
                if (totalCell) totalCell.textContent = rowHasAmt ? _seFmt(_seRound(rowTotal)) : '';
                if (rowHasAmt) grand += rowTotal;
                if (rowHasAny) activeRows++;
            });
            SE_FOOT_COLS.forEach(function (f) {
                var cell = document.querySelector('#seTable tfoot [data-foot="' + f + '"]');
                if (cell) cell.textContent = footSums[f].has ? _seFmt(_seRound(footSums[f].sum)) : '';
            });
            var totCell = document.querySelector('#seTable tfoot [data-foot="total"]');
            if (totCell) totCell.textContent = _seFmt(_seRound(grand));
            var sr = document.getElementById('seSumRows');
            if (sr) sr.textContent = activeRows;
            var sg = document.getElementById('seSumGrand');
            if (sg) sg.textContent = _seFmt(_seRound(grand)) || '0';
        }

        function _seRenumber() {
            var tbody = document.getElementById('seTbody');
            if (!tbody) return;
            Array.prototype.forEach.call(tbody.querySelectorAll('tr'), function (tr, i) {
                var idxCell = tr.querySelector('.se-td-idx');
                if (idxCell) idxCell.textContent = i + 1;
            });
        }

         
         
        function seAddRow() {
            if (!_seData || !document.getElementById('seTbody')) { showToast('กรุณารอหน้าโหลดเสร็จก่อน', 'danger'); return; }
            var sc = document.getElementById('seAddRowSearch');
            if (sc) sc.value = '';
            _seAddRowRender();
            var bd = document.getElementById('seAddRowBackdrop');
            if (bd) bd.classList.add('open');
        }
        function closeSeAddRow() {
            var bd = document.getElementById('seAddRowBackdrop');
            if (bd) bd.classList.remove('open');
        }
        function _seAddRowRender() {
            var box = document.getElementById('seAddRowList');
            if (!box) return;
            var term = ((document.getElementById('seAddRowSearch') || {}).value || '').toString().trim().toLowerCase();
             
            var present = {};
            document.querySelectorAll('#seTbody [data-f="subName"]').forEach(function (el) {
                var nm = el.value.trim();
                if (nm) present[nm] = true;
            });
            var payMap = (_seData && _seData.paymentByName) || {};
            var avail = ((_seData && _seData.subs) || []).filter(function (n) {
                if (present[n]) return false;
                if (!term) return true;
                return (n + ' ' + (payMap[n] || '')).toLowerCase().indexOf(term) !== -1;
            });
            if (!avail.length) {
                box.innerHTML = '<div class="scfg-mp-note">' +
                    (term ? 'ไม่พบชุดที่ค้นหา' : 'ทุกชุดที่เปิดใช้งานอยู่ในตารางครบแล้ว — เปิดใช้งานชุดเพิ่มได้ที่เมนู "ตั้งค่า"') +
                    '</div>';
                return;
            }
            box.innerHTML = avail.map(function (n) {
                return '<button type="button" class="scfg-mp-item" data-name="' + escapeHtml(n) + '" onclick="_seAddRowPick(this)">' +
                    '<span class="mp-name">' + escapeHtml(n) + '</span>' +
                    (payMap[n] ? '<span class="mp-code">' + escapeHtml(payMap[n]) + '</span>' : '') +
                    '</button>';
            }).join('');
        }
        function _seAddRowPick(el) {
            var name = el.getAttribute('data-name') || '';
            var tbody = document.getElementById('seTbody');
            if (!name || !tbody) return;
            hapticTap();
            tbody.insertAdjacentHTML('beforeend', _seRowHtml(tbody.querySelectorAll('tr').length, name, {}));
            _seRenumber();
            _sePrefillDefaults(tbody.lastElementChild);    
            _seMarkDirty();
            _seRecalcTotals();
            _seAddRowRender();    
            showToast('เพิ่ม "' + name + '" แล้ว', 'success');
        }

        function seRemoveRow(btn) {
            var tr = btn && btn.closest ? btn.closest('tr') : null;
            if (!tr) return;
            var nameEl = tr.querySelector('[data-f="subName"]');
            var nm = nameEl ? nameEl.value.trim() : '';
            if (!confirm('ลบแถว' + (nm ? ' "' + nm + '"' : 'นี้') + ' ?  (มีผลเมื่อกดบันทึก)')) return;
            tr.remove();
            _seRenumber();
            _seMarkDirty();
            _seRecalcTotals();
        }

         
        function openSeShop(btn) {
            var tr = btn && btn.closest ? btn.closest('tr') : null;
            if (!tr) return;
            hapticTap();
            _seShopTargetTr = tr;
            var nameEl = tr.querySelector('[data-f="subName"]');
            var sub = document.getElementById('seShopSub');
            if (sub) sub.textContent = (nameEl && nameEl.value.trim()) || '(ยังไม่ระบุชื่อชุด)';
            var saved = _seShopParse(tr.getAttribute('data-shop-items'));
            var savedByLabel = {};
            saved.forEach(function (it) { savedByLabel[it[0]] = it; });
             
            var cfgItems = (_seCfg().shopItems || []).map(function (it) { return { label: it.label, price: it.price }; });
            var labels = {};
            cfgItems.forEach(function (it) { labels[it.label] = true; });
            saved.forEach(function (it) { if (!labels[it[0]]) cfgItems.push({ label: it[0], price: it[1], legacy: true }); });
            var list = document.getElementById('seShopList');
            if (list) {
                list.innerHTML = cfgItems.length ? cfgItems.map(function (it, i) {
                    var sv = savedByLabel[it.label];
                    var qty = sv ? sv[2] : 1;
                    return '<label class="se-shop-row">' +
                        '<input type="checkbox" class="se-shop-cb" data-label="' + escapeHtml(it.label) + '" data-price="' + (it.price || 0) + '"' + (sv ? ' checked' : '') + ' onchange="_seShopSync()">' +
                        '<span class="se-shop-lb">' + escapeHtml(it.label) + (it.legacy ? ' <span class="se-shop-legacy">(รายการเดิม)</span>' : '') + '</span>' +
                        '<span class="se-shop-pr">' + _seFmt(it.price || 0) + ' บ./งวด</span>' +
                        '<span class="se-shop-x">×</span>' +
                        '<input type="number" class="se-shop-qty" value="' + qty + '" min="1" step="1" inputmode="numeric" oninput="_seShopSync()">' +
                    '</label>';
                }).join('') : '<div class="scfg-mp-note">— ยังไม่มีรายการในตั้งค่าราคา — เพิ่มได้ที่ปุ่ม "ตั้งค่าราคา" —</div>';
            }
            _seShopSync();
            var bd = document.getElementById('seShopBackdrop');
            if (bd) bd.classList.add('open');
        }
        function closeSeShop() {
            var bd = document.getElementById('seShopBackdrop');
            if (bd) bd.classList.remove('open');
            _seShopTargetTr = null;
        }
        function _seShopCollect() {
            var arr = [];
            document.querySelectorAll('#seShopList .se-shop-row').forEach(function (row) {
                var cb = row.querySelector('.se-shop-cb');
                if (!cb || !cb.checked) return;
                var qtyEl = row.querySelector('.se-shop-qty');
                var qty = qtyEl ? parseInt(qtyEl.value, 10) : 1;
                if (isNaN(qty) || qty < 1) qty = 1;
                arr.push([cb.getAttribute('data-label') || '', parseFloat(cb.getAttribute('data-price')) || 0, qty]);
            });
            return arr;
        }
        function _seShopSync() {
            var arr = _seShopCollect();
            var el = document.getElementById('seShopTotal');
            if (el) el.textContent = arr.length ? (_seFmt(_seShopAmt(arr)) + ' บาท (' + arr.length + ' รายการ)') : '— ไม่มีรายการ —';
        }
        function seShopApply() {
            var tr = _seShopTargetTr;
            if (!tr) { closeSeShop(); return; }
            var arr = _seShopCollect();
            tr.setAttribute('data-shop-items', arr.length ? JSON.stringify(arr) : '');
            var btn = tr.querySelector('.se-shop-btn');
            if (btn) {
                btn.innerHTML = _seShopBtnHtml(arr);
                 
                btn.classList.remove('se-prev');
                btn.title = 'คลิกเพื่อติ๊กรายการร้านค้า/เครื่องใช้';
            }
            closeSeShop();
            _seMarkDirty();
            _seRecalcTotals();
            hapticTap();
        }

         
        function openSeCfg() {
            if (!_seData) { showToast('กรุณารอหน้าโหลดเสร็จก่อน', 'danger'); return; }
            if (_seDirty) {
                showInfoPopup('บันทึกข้อมูลก่อน', 'มีข้อมูลในตารางที่ยังไม่ได้บันทึก — กด "บันทึก" ก่อนแก้ตั้งค่าราคา (หลังบันทึกตั้งค่า หน้าจะโหลดใหม่เพื่อใช้ราคาล่าสุด)', 'info');
                return;
            }
            var cfg = _seCfg();
            var sub = document.getElementById('seCfgSub');
            if (sub) sub.textContent = 'Site ' + _seData.siteCode + (_seData.siteName ? ' (' + _seData.siteName + ')' : '') + ' — ใช้กับหน้าจอและหัวตารางใน PDF';
            var r1 = document.getElementById('seCfgRoomRate'); if (r1) r1.value = cfg.roomRate;
            var r2 = document.getElementById('seCfgElecRate'); if (r2) r2.value = cfg.elecRate;
            var r3 = document.getElementById('seCfgShopElecRate'); if (r3) r3.value = cfg.shopElecRate;
            var r4 = document.getElementById('seCfgElecFree'); if (r4) r4.value = cfg.elecFreeUnits;
            var list = document.getElementById('seCfgItems');
            if (list) {
                list.innerHTML = '';
                (cfg.shopItems || []).forEach(function (it) { _seCfgAppendItem(it.label, it.price); });
                if (!(cfg.shopItems || []).length) _seCfgAppendItem('', '');
            }
            var bd = document.getElementById('seCfgBackdrop');
            if (bd) bd.classList.add('open');
        }
        function closeSeCfg() {
            var bd = document.getElementById('seCfgBackdrop');
            if (bd) bd.classList.remove('open');
        }
        function _seCfgAppendItem(label, price) {
            var list = document.getElementById('seCfgItems');
            if (!list) return;
            var row = document.createElement('div');
            row.className = 'secfg-item-row';
            row.innerHTML =
                '<input type="text" class="form-control secfg-item-label" placeholder="ชื่อรายการ เช่น ตู้เย็น" value="' + escapeHtml(label || '') + '">' +
                '<input type="number" class="form-control secfg-item-price" placeholder="ราคา/งวด" step="any" min="0" inputmode="decimal" value="' + (price === '' || price === undefined ? '' : price) + '">' +
                '<button type="button" class="se-del-btn" onclick="this.parentNode.remove()" title="ลบรายการ"><i class="fa-solid fa-trash"></i></button>';
            list.appendChild(row);
        }
        function seCfgAddItem() { _seCfgAppendItem('', ''); }
        function submitSeCfg() {
            if (!_seData) return;
            var items = [];
            document.querySelectorAll('#seCfgItems .secfg-item-row').forEach(function (row) {
                var lb = row.querySelector('.secfg-item-label');
                var pr = row.querySelector('.secfg-item-price');
                var label = lb ? lb.value.trim() : '';
                if (!label) return;
                var price = _seNumVal(pr ? pr.value : '');
                items.push({ label: label, price: price === '' ? 0 : price });
            });
            var goBtn = document.getElementById('seCfgGo');
            if (goBtn) goBtn.disabled = true;
            showLoadingPopup('กำลังบันทึกตั้งค่าราคา', 'Site ' + _seData.siteCode + '\nกรุณารอสักครู่...');
            google.script.run
                .withSuccessHandler(function (res) {
                    closeAppPopup();
                    if (goBtn) goBtn.disabled = false;
                    if (!res || !res.success) {
                        showInfoPopup('บันทึกไม่สำเร็จ', (res && res.message) || 'ไม่สามารถบันทึกตั้งค่าได้', 'danger');
                        return;
                    }
                    closeSeCfg();
                    showToast('บันทึกตั้งค่าราคาแล้ว', 'success');
                    loadSubExpensePage();    
                })
                .withFailureHandler(function (err) {
                    closeAppPopup();
                    if (goBtn) goBtn.disabled = false;
                    showInfoPopup('บันทึกไม่สำเร็จ', (err && err.message) ? err.message : String(err || 'unknown'), 'danger');
                })
                .saveSubExpenseConfig({
                    siteCode: _seData.siteCode,
                    roomRate: (document.getElementById('seCfgRoomRate') || {}).value,
                    elecRate: (document.getElementById('seCfgElecRate') || {}).value,
                    elecFreeUnits: (document.getElementById('seCfgElecFree') || {}).value,
                    shopElecRate: (document.getElementById('seCfgShopElecRate') || {}).value,
                    shopItems: items,
                    username: user ? user.username : ''
                });
        }

         
        function _seCollectRows() {
            var tbody = document.getElementById('seTbody');
            if (!tbody) return null;
            var rows = [], noName = 0;
            Array.prototype.forEach.call(tbody.querySelectorAll('tr'), function (tr) {
                var nameEl = tr.querySelector('[data-f="subName"]');
                var noteEl = tr.querySelector('[data-f="note"]');
                var outEl = tr.querySelector('[data-f="outsideStay"]');
                var subName = nameEl ? nameEl.value.trim() : '';
                var row = {
                    subName: subName,
                    note: noteEl ? noteEl.value.trim() : '',
                    outsideStay: !!(outEl && outEl.checked)
                };
                var hasData = row.note !== '' || row.outsideStay, rowTotal = 0, hasAmt = false;
                SE_FIELDS.forEach(function (f) {
                    var v = _seGetF(tr, f);
                    row[f] = v;
                    if (v !== '') {
                        hasData = true;
                        if (SE_SUM_FIELDS.indexOf(f) !== -1) { rowTotal += v; hasAmt = true; }
                    }
                });
                 
                row.roomRate = row.roomQty !== '' ? (_seCfg().roomRate === '' ? '' : _seCfg().roomRate) : '';
                 
                var shopArr = _seShopParse(tr.getAttribute('data-shop-items'));
                row.shopItems = shopArr.length ? JSON.stringify(shopArr) : '';
                if (shopArr.length) {
                    var qtySum = 0;
                    shopArr.forEach(function (it) { qtySum += it[2] || 1; });
                    row.shopQty = qtySum;
                    row.shopRate = shopArr.length === 1 ? shopArr[0][1] : '';
                    row.shopAmt = _seShopAmt(shopArr);
                    rowTotal += row.shopAmt;
                    hasAmt = true;
                    hasData = true;
                } else {
                    row.shopQty = ''; row.shopRate = ''; row.shopAmt = '';
                }
                row.totalAmt = hasAmt ? _seRound(rowTotal) : '';
                if (!subName && hasData) { noName++; return; }
                if (subName) rows.push(row);    
            });
            if (noName > 0) {
                showInfoPopup('กรอกชื่อผู้รับเหมาไม่ครบ', 'มี ' + noName + ' แถวที่กรอกข้อมูลแล้วแต่ยังไม่ได้ใส่ชื่อผู้รับเหมาชุด กรุณาใส่ชื่อหรือลบแถวนั้นก่อนบันทึก', 'danger');
                return null;
            }
            return rows;
        }

        function saveSubExpense(afterSaved) {
            if (!_seData) { showToast('กรุณารอหน้าโหลดเสร็จก่อน', 'danger'); return; }
            var rows = _seCollectRows();
            if (rows === null) return;
            var saveBtn = document.getElementById('seSaveBtn');
            if (saveBtn) saveBtn.disabled = true;
            showLoadingPopup('กำลังบันทึกข้อมูล', 'งวด' + _seData.periodLabel + ' · Site ' + _seData.siteCode + '\nกรุณารอสักครู่...');
            google.script.run
                .withSuccessHandler(function (res) {
                    closeAppPopup();
                    if (!res || !res.success) {
                        if (saveBtn) saveBtn.disabled = false;
                        var msg = (res && res.message === 'no_permission') ? 'คุณไม่มีสิทธิ์บันทึกข้อมูลเมนูนี้' : ((res && res.message) || 'บันทึกไม่สำเร็จ');
                        showInfoPopup('บันทึกไม่สำเร็จ', msg, 'danger');
                        return;
                    }
                    _seDirty = false;
                    var hint = document.getElementById('seDirtyHint');
                    if (hint) hint.classList.remove('show');
                    showToast('บันทึกแล้ว ' + res.saved + ' รายการ (งวด' + _seData.periodLabel + ')', 'success');
                    if (typeof afterSaved === 'function') afterSaved();
                })
                .withFailureHandler(function (err) {
                    closeAppPopup();
                    if (saveBtn) saveBtn.disabled = false;
                    showInfoPopup('บันทึกไม่สำเร็จ', (err && err.message) ? err.message : String(err || 'unknown'), 'danger');
                })
                .saveSubExpenseData({
                    siteCode: _seData.siteCode,
                    ym: _seData.ym,
                    half: _seData.half,
                    rows: rows,
                    username: user ? user.username : ''
                });
        }

         
        function _seSignerCount() {
            var r = document.querySelector('input[name="seSignerCount"]:checked');
            return r ? parseInt(r.value, 10) : 2;
        }
         
        function _seSignerCountChanged() {
            var three = _seSignerCount() === 3;
            var box = document.getElementById('seInspectorBox');
            if (box) box.style.display = three ? '' : 'none';
            var t = document.getElementById('seApproverBoxTitle');
            if (t) t.textContent = 'ผู้ลงนามคนที่ ' + (three ? '3' : '2') + ' — ผู้อนุมัติ';
            if (three) {
                var ip = document.getElementById('seInspectorPos');
                if (ip && !ip.value) ip.value = 'PE / SSE';
            }
        }
        function openSeExport() {
            if (!_seData) { showToast('กรุณารอหน้าโหลดเสร็จก่อน', 'danger'); return; }
            var sub = document.getElementById('seExportSub');
            if (sub) sub.textContent = 'งวด' + _seData.periodLabel + ' · Site ' + _seData.siteCode + (_seData.siteName ? ' (' + _seData.siteName + ')' : '');
            var pj = document.getElementById('seProjectName');
            if (pj && !pj.value) pj.value = _seData.siteName || '';
             
            var n1 = document.getElementById('seSigner1Name');
            if (n1 && !n1.value) n1.value = (user && (user.fullName || user.name)) || '';
            var p1 = document.getElementById('seSigner1Pos');
            if (p1 && !p1.value) p1.value = (user && user.roleId) || '';
            var p2 = document.getElementById('seSigner2Pos');
            if (p2 && !p2.value) p2.value = 'APM';
            _seSignerCountChanged();    

             
            _seSignData = null;
            ['seInspectorName', 'seInspectorPos', 'seSigner2Name', 'seSigner2Pos'].forEach(function (id) {
                var el = document.getElementById(id);
                if (el) el.disabled = false;    
            });
            var loadHtml = '<div class="esign-note"><i class="fa-solid fa-spinner fa-spin"></i> กำลังโหลดสถานะลายเซ็นออนไลน์...</div>';
            var a1 = document.getElementById('seInsOnline'); if (a1) a1.innerHTML = loadHtml;
            var a2 = document.getElementById('seApvOnline'); if (a2) a2.innerHTML = loadHtml;
            var cbd = document.getElementById('seComboDeduct'); if (cbd) { cbd.checked = false; cbd.disabled = true; }
            _seExportComboRender();
            _seSignReload();
             
            var dl = document.getElementById('seUserNamesDl');
            if (dl) {
                var seen = {}, opts = [];
                Object.keys(globalUserMap || {}).forEach(function (k) {
                    var nm = globalUserMap[k];
                    if (nm && !seen[nm]) { seen[nm] = true; opts.push(nm); }
                });
                opts.sort(function (a, b) { return a.localeCompare(b, 'th'); });
                dl.innerHTML = opts.map(function (nm) { return '<option value="' + escapeHtml(nm) + '">'; }).join('');
            }
            var bd = document.getElementById('seExportBackdrop');
            if (bd) bd.classList.add('open');
        }
        function closeSeExport() {
            var bd = document.getElementById('seExportBackdrop');
            if (bd) bd.classList.remove('open');
        }
        function submitSeExport() {
            if (!_seData) return;
            var three = _seSignerCount() === 3;
            var payload = {
                siteCode: _seData.siteCode,
                ym: _seData.ym,
                half: _seData.half,
                projectName: (document.getElementById('seProjectName') || {}).value || '',
                preparerName: (document.getElementById('seSigner1Name') || {}).value || '',
                preparerPos: (document.getElementById('seSigner1Pos') || {}).value || '',
                useInspector: three,
                inspectorName: three ? ((document.getElementById('seInspectorName') || {}).value || '') : '',
                inspectorPos: three ? ((document.getElementById('seInspectorPos') || {}).value || '') : '',
                signer2Label: (document.getElementById('seSigner2Label') || {}).value || 'ผู้อนุมัติ',
                signer2Name: (document.getElementById('seSigner2Name') || {}).value || '',
                signer2Pos: (document.getElementById('seSigner2Pos') || {}).value || '',
                includeDeduction: !!((document.getElementById('seComboDeduct') || {}).checked),
                username: user ? user.username : ''
            };
            closeSeExport();
             
             
            saveSubExpense(function () { _seRunPdfExport(payload); });
        }
        function _seRunPdfExport(payload) {
            var goBtn = document.getElementById('seExportBtn');
            if (goBtn) goBtn.disabled = true;
            showLoadingPopup('กำลังสร้างเอกสาร PDF',
                'แบบฟอร์มรายการหักคจช. ผรม งวด' + (_seData ? _seData.periodLabel : '') +
                (payload.includeDeduction ? '\n+ รวมสรุปงวดสแกนนิ้ว และตารางหักเงิน ผรม. ของงวดนี้ต่อท้ายเป็นไฟล์เดียว (ใช้เวลานานขึ้น)' : '') +
                '\nกรุณารอสักครู่...');
            google.script.run
                .withSuccessHandler(function (res) {
                    closeAppPopup();
                    if (goBtn) goBtn.disabled = false;
                    if (!res || !res.success) {
                        showInfoPopup('สร้างเอกสารไม่สำเร็จ', (res && res.message) ? res.message : 'ไม่สามารถสร้างเอกสารได้', 'danger');
                        return;
                    }
                    if (res.dataUri) {
                        var a = document.createElement('a');
                        a.href = res.dataUri; a.download = res.fileName || 'subexpense.pdf'; a.style.display = 'none';
                        document.body.appendChild(a); a.click();
                        setTimeout(function () { try { document.body.removeChild(a); } catch (e) {} }, 300);
                        var attParts = [];
                        if (res.fsRendered) attParts.push('สรุปงวดสแกนนิ้ว');
                        if (res.dedRendered) attParts.push('ตารางหักเงิน ' + res.dedRendered + ' ใบ');
                        showToast(attParts.length
                            ? '✅ ดาวน์โหลดแล้ว: หักคจช. + ' + attParts.join(' + ') + ' (ไฟล์เดียว)'
                            : 'ดาวน์โหลดเอกสารหักคจช. แล้ว', 'success');
                         
                        if (payload.includeDeduction && (!res.fsRendered || !res.dedRendered)) {
                            var missParts = [];
                            if (!res.fsRendered) missParts.push('• สรุปงวดสแกนนิ้ว: ' + (res.fsNote || 'ไม่มีข้อมูลสแกนนิ้วของงวดนี้'));
                            if (!res.dedRendered) missParts.push('• ตารางหักเงิน: ' + (res.dedNote || 'ไม่มีรายการหักเงินของงวดนี้'));
                            showInfoPopup('พิมพ์รวมได้ไม่ครบทุกเอกสาร',
                                'เอกสารหักคจช. ถูกสร้างตามปกติ แต่เอกสารแนบบางส่วนรวมไม่สำเร็จ:\n' + missParts.join('\n'), 'info');
                        }
                    }
                })
                .withFailureHandler(function (err) {
                    closeAppPopup();
                    if (goBtn) goBtn.disabled = false;
                    showInfoPopup('สร้างเอกสารไม่สำเร็จ', (err && err.message) ? err.message : String(err || 'unknown'), 'danger');
                })
                .generateSubExpensePDF(payload);
        }

         
         
         
         
        var _seSignData = null;

        function _seSignReload(cb) {
            if (!_seData) { if (cb) cb(false); return; }
            google.script.run
                .withSuccessHandler(function (res) {
                    if (!res || !res.success) {
                        _seSignData = null;
                        var a1 = document.getElementById('seInsOnline'), a2 = document.getElementById('seApvOnline');
                        var msg = '<div class="esign-note" style="color:#b91c1c;"><i class="fa-solid fa-triangle-exclamation"></i> โหลดสถานะลายเซ็นออนไลน์ไม่สำเร็จ' +
                            ((res && res.message) ? ' — ' + escapeHtml(res.message) : '') + '</div>';
                        if (a1) a1.innerHTML = msg;
                        if (a2) a2.innerHTML = msg;
                        if (cb) cb(false);
                        return;
                    }
                    _seSignData = res;
                    _seExportOnlineRender();
                    if (cb) cb(true);
                })
                .withFailureHandler(function (err) {
                    var a1 = document.getElementById('seInsOnline'), a2 = document.getElementById('seApvOnline');
                    var msg = '<div class="esign-note" style="color:#b91c1c;"><i class="fa-solid fa-triangle-exclamation"></i> โหลดสถานะลายเซ็นออนไลน์ไม่สำเร็จ</div>';
                    if (a1) a1.innerHTML = msg;
                    if (a2) a2.innerHTML = msg;
                    if (cb) cb(false);
                })
                .getSubExpenseSignData({ siteCode: _seData.siteCode, ym: _seData.ym, half: _seData.half, username: user ? user.username : '' });
        }
        function _seSignUserName(username) {
            if (!_seSignData || !username) return username || '';
            var hit = (_seSignData.users || []).filter(function (u) { return (u.username || '').toLowerCase() === username.toLowerCase(); })[0];
            return hit ? hit.fullName : username;
        }
        function _seSignUserOptions(selected) {
            var us = (_seSignData && _seSignData.users || []).slice().sort(function (a, b) {
                return (a.fullName || '').localeCompare(b.fullName || '', 'th');
            });
            var html = '<option value="">— เลือกผู้ลงนาม —</option>';
            us.forEach(function (u) {
                var sel = (selected && selected.toLowerCase() === u.username.toLowerCase()) ? ' selected' : '';
                html += '<option value="' + escapeHtml(u.username) + '"' + sel + '>' + escapeHtml(u.fullName) + '</option>';
            });
            return html;
        }
         
        function _seExportOnlineRender() {
            if (!_seSignData) return;
             
            if (_seSignData.inspector && _seSignerCount() === 2) {
                var r3 = document.querySelector('input[name="seSignerCount"][value="3"]');
                if (r3) { r3.checked = true; _seSignerCountChanged(); }
            }
            _seExportRenderRole('inspector');
            _seExportRenderRole('approver');
            _seExportComboRender();
        }
         
        function _seExportRenderRole(role) {
            var isIns = role === 'inspector';
            var area = document.getElementById(isIns ? 'seInsOnline' : 'seApvOnline');
            var nameInp = document.getElementById(isIns ? 'seInspectorName' : 'seSigner2Name');
            var posInp = document.getElementById(isIns ? 'seInspectorPos' : 'seSigner2Pos');
            if (!area) return;
            var r = _seSignData ? (isIns ? _seSignData.inspector : _seSignData.approver) : null;
            var uid = isIns ? 'seSignIns' : 'seSignApv';
            var posId = isIns ? 'seInspectorPos' : 'seSigner2Pos';    
            var lock = false;
            var html = '';
            if (!_seSignData) {
                html = '<div class="esign-note"><i class="fa-solid fa-spinner fa-spin"></i> กำลังโหลดสถานะลายเซ็นออนไลน์...</div>';
            } else if (r && r.status === 'signed') {
                lock = true;
                html = '<div class="esign-slot-head">' + _sgChip('signed', 'fa-circle-check', 'ลงนามออนไลน์แล้ว') +
                    '<span class="esign-note">โดย <b>' + escapeHtml(r.signerName || '-') + '</b>' +
                    (r.signerPos ? ' (' + escapeHtml(r.signerPos) + ')' : '') + ' · ' + _esThDT(r.signedAt) + '</span>' +
                    '<button type="button" class="esign-btn soft" onclick="seSignView(\'' + role + '\')"><i class="fa-solid fa-magnifying-glass"></i> ดูลายเซ็น</button></div>' +
                    '<div class="esign-note">PDF จะใช้ชื่อ + ลายเซ็นจริงของผู้ลงนามอัตโนมัติ (ช่องชื่อด้านล่างถูกล็อก)</div>';
                if (nameInp) nameInp.value = r.signerName || '';
                if (posInp && r.signerPos) posInp.value = r.signerPos;
            } else if (r && r.status === 'pending') {
                lock = true;
                html = '<div class="esign-slot-head">' + _sgChip('sent', 'fa-hourglass-half', 'กำลังส่งขอลายเซ็น — รอ ' + _seSignUserName(r.assignee) + ' ลงนาม') +
                    '<span class="esign-note">ส่งเมื่อ ' + _esThDT(r.requestedAt) + '</span></div>' +
                    '<div class="esign-ctrl">' +
                        '<select id="' + uid + 'User" onchange="seUserPosSync(this, \'' + posId + '\')">' + _seSignUserOptions(r.assignee) + '</select>' +
                        '<button type="button" class="esign-btn primary" onclick="seSignSend(\'' + role + '\')"><i class="fa-solid fa-paper-plane"></i> ส่งใหม่/ย้ายคน</button>' +
                        '<button type="button" class="esign-btn danger" onclick="seSignCancel(\'' + role + '\')"><i class="fa-solid fa-xmark"></i> ยกเลิกคำขอ</button>' +
                    '</div>' +
                    '<div class="esign-note">ต้องการพิมพ์แบบ Hardcopy (กรอกชื่อเอง) — กด "ยกเลิกคำขอ" ก่อน ช่องชื่อจะปลดล็อก</div>';
                if (nameInp) nameInp.value = _seSignUserName(r.assignee);
                if (posInp && r.assigneePos) posInp.value = r.assigneePos;
            } else {
                html = '<div class="esign-ctrl">' +
                    '<span class="esign-note"><i class="fa-solid fa-signature" style="color:var(--primary);"></i> ขอลายเซ็นออนไลน์:</span>' +
                    '<select id="' + uid + 'User" onchange="seUserPosSync(this, \'' + posId + '\')">' + _seSignUserOptions('') + '</select>' +
                    '<button type="button" class="esign-btn soft" onclick="seSignSend(\'' + role + '\')"><i class="fa-solid fa-paper-plane"></i> ส่งคำขอ</button>' +
                    '</div>' +
                    (_seSignData.hasSavedData ? '' :
                        '<div class="esign-note" style="color:#92400e;"><i class="fa-solid fa-triangle-exclamation"></i> ต้องกด "บันทึก" ข้อมูลงวดนี้ก่อน จึงส่งคำขอได้</div>');
            }
            area.innerHTML = html;
            if (nameInp) nameInp.disabled = lock;
            if (posInp) posInp.disabled = lock;
        }
         
        function _seExportComboRender() {
            var st = document.getElementById('seComboStatus');
            var cb = document.getElementById('seComboDeduct');
            if (!st || !cb) return;
            if (!_seSignData) {
                st.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> กำลังโหลดสถานะ...';
                return;
            }
            var d = _seSignData.deduction || { total: 0, acked: 0, auto: 0, pending: 0 };
            var fs = _seSignData.fingerScan || { days: 0, subs: 0 };
            var fsTxt = fs.days
                ? '<i class="fa-solid fa-fingerprint"></i> สรุปงวดสแกนนิ้ว: บันทึกแล้ว <b>' + fs.days + ' วัน</b> · ' + fs.subs + ' ชุด'
                : '<i class="fa-solid fa-fingerprint"></i> สรุปงวดสแกนนิ้ว: <span style="color:#b45309;">ยังไม่มีข้อมูลของงวดนี้ — จะไม่ถูกแนบ</span>';
            var dedTxt;
            if (!d.total) {
                dedTxt = '<i class="fa-solid fa-file-invoice"></i> ตารางหักเงิน: <span style="color:#b45309;">ยังไม่มีเอกสารของงวดนี้ — ออกเลขเอกสารได้ที่หน้า "ตรวจสอบประจำวัน" ปุ่ม "ลายเซ็น"</span>';
            } else {
                var ackTxt = 'ผู้รับเหมาเซ็นรับทราบแล้ว <b>' + d.acked + '/' + d.total + ' ใบ</b>' +
                    (d.auto ? ' (รับทราบโดยปริยาย ' + d.auto + ')' : '');
                dedTxt = '<i class="fa-solid fa-file-invoice"></i> ตารางหักเงิน: ' + d.total + ' ใบ · ' + ackTxt +
                    (d.pending ? ' · <span style="color:#b45309;">ยังรอเซ็น ' + d.pending + ' ใบ</span>' : ' · <span style="color:#166534;">ครบทุกใบ ✅</span>');
            }
             
            if (!d.total && !fs.days) {
                cb.checked = false;
                cb.disabled = true;
            } else {
                cb.disabled = false;
            }
            st.innerHTML = fsTxt + '<br>' + dedTxt;
        }
        function seSignSend(role) {
            if (!_seSignData || !_seData) return;
            if (_seDirty) { showToast('กด "บันทึก" ข้อมูลก่อนส่งคำขอลายเซ็น', 'danger'); return; }
            if (!_seSignData.hasSavedData) { showToast('ยังไม่มีข้อมูลบันทึกของงวดนี้ — กรอกและกด "บันทึก" ก่อน', 'danger'); return; }
            var isIns = role === 'inspector';
            var uid = isIns ? 'seSignIns' : 'seSignApv';
            var assignee = (document.getElementById(uid + 'User') || {}).value || '';
             
            var pos = (document.getElementById(isIns ? 'seInspectorPos' : 'seSigner2Pos') || {}).value || (isIns ? 'PE / SSE' : 'APM');
            if (!assignee) { showToast('กรุณาเลือกผู้ลงนามก่อน', 'danger'); return; }
            var roleTxt = isIns ? 'ผู้ตรวจสอบ' : 'ผู้อนุมัติ';
            showLoadingPopup('กำลังส่งคำขอลายเซ็น',
                'กำลังส่งคำขอ' + roleTxt + ' เอกสารหักคจช. งวด' + _seData.periodLabel + '\nไปยัง ' + _seSignUserName(assignee) + ' — กรุณารอสักครู่...');
            google.script.run
                .withSuccessHandler(function (res) {
                    if (!res || !res.success) {
                        closeAppPopup();
                        showToast((res && res.message) || 'ส่งคำขอไม่สำเร็จ', 'danger');
                        return;
                    }
                    _seSignReload(function () {
                        closeAppPopup();
                        showToast('✅ ส่งคำขอลายเซ็นถึง ' + _seSignUserName(assignee) + ' แล้ว', 'success');
                    });
                })
                .withFailureHandler(function (err) {
                    closeAppPopup();
                    showToast((err && err.message) || 'ส่งคำขอไม่สำเร็จ', 'danger');
                })
                .requestSubExpenseSignature({
                    siteCode: _seData.siteCode, ym: _seData.ym, half: _seData.half,
                    role: role, assignee: assignee, assigneePos: pos,
                    username: user ? user.username : ''
                });
        }
        function seSignCancel(role) {
            if (!_seSignData || !_seData) return;
            var isIns = role === 'inspector';
            var roleTxt = isIns ? 'ผู้ตรวจสอบ' : 'ผู้อนุมัติ';
            showConfirmPopup('ยกเลิกคำขอ' + roleTxt,
                'ต้องการยกเลิกคำขอลายเซ็น' + roleTxt + 'ของงวดนี้ใช่หรือไม่?\nช่องชื่อจะปลดล็อกให้กรอกเองสำหรับพิมพ์ Hardcopy', function () {
                showLoadingPopup('กำลังยกเลิกคำขอ' + roleTxt, 'เอกสารหักคจช. งวด' + _seData.periodLabel + '\nกรุณารอสักครู่...');
                google.script.run
                    .withSuccessHandler(function (res) {
                        if (!res || !res.success) { closeAppPopup(); showToast((res && res.message) || 'ยกเลิกไม่สำเร็จ', 'danger'); return; }
                         
                        var nameInp = document.getElementById(isIns ? 'seInspectorName' : 'seSigner2Name');
                        var posInp = document.getElementById(isIns ? 'seInspectorPos' : 'seSigner2Pos');
                        if (nameInp) { nameInp.disabled = false; nameInp.value = ''; }
                        if (posInp) { posInp.disabled = false; posInp.value = isIns ? 'PE / SSE' : 'APM'; }
                         
                        _seSignReload(function () {
                            closeAppPopup();
                            showToast('ยกเลิกคำขอแล้ว — กรอกชื่อเองได้เลย', 'success');
                        });
                    })
                    .withFailureHandler(function (err) { closeAppPopup(); showToast((err && err.message) || 'ยกเลิกไม่สำเร็จ', 'danger'); })
                    .cancelSubExpenseSignRequest({
                        siteCode: _seData.siteCode, ym: _seData.ym, half: _seData.half,
                        role: role, username: user ? user.username : ''
                    });
            }, 'ยกเลิกคำขอ', 'btn btn-reject');
        }
        function seSignView(role) {
            if (!_seSignData) return;
            var roleTxt = role === 'inspector' ? 'ผู้ตรวจสอบ' : 'ผู้อนุมัติ';
            openSigViewFor(_seSignData.docNo, role, roleTxt,
                _seSignData.docNo + ' · หักคจช. งวด' + (_seSignData.periodLabel || ''));
        }

         
        function exportBalancePDF() {
            var site = getEffectiveSiteCode();
            if (!site) { var sf = document.getElementById('dashSiteFilter'); if (sf) site = sf.value || ''; }
            showLoadingPopup('กำลังสร้าง PDF ยอดคงเหลือ', 'กำลังรวบรวมข้อมูล Balance เรียงตาม MatCode\nกรุณารอสักครู่...');
            google.script.run
                .withSuccessHandler(function (res) {
                    closeAppPopup();
                    if (!res || !res.success) { showInfoPopup('สร้างไม่สำเร็จ', (res && res.message) || 'ไม่สามารถสร้างรายงานได้', 'danger'); return; }
                    if (res.dataUri) {
                        var a = document.createElement('a');
                        a.href = res.dataUri; a.download = res.fileName || 'balance.pdf'; a.style.display = 'none';
                        document.body.appendChild(a); a.click();
                        setTimeout(function () { try { document.body.removeChild(a); } catch (e) {} }, 300);
                        showToast('ดาวน์โหลด PDF ยอดคงเหลือแล้ว', 'success');
                    }
                })
                .withFailureHandler(function (err) {
                    closeAppPopup();
                    showInfoPopup('สร้างไม่สำเร็จ', (err && err.message) || String(err || ''), 'danger');
                })
                .generateBalancePDF(site, user ? user.username : '');
        }

        function loadHistory(opts) {
            var tbody = document.getElementById('historyTableBody');
            if (!tbody) return;
            var site = getEffectiveSiteCode();
            var userName = getEffectiveUserName();
            var roleLevel = user ? user.roleLevel : '';
            if (!connextCache.get('history', [site, userName, roleLevel])) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;"><i class="fa-solid fa-spinner fa-spin"></i> กำลังโหลดข้อมูล...</td></tr>';
            }
            connextCache.swr('history', [site, userName, roleLevel],
                function (done, fail) {
                    google.script.run.withSuccessHandler(done).withFailureHandler(fail)
                        .getRequisitionHistory(site, userName, roleLevel);
                },
                function (data) { historyData = data || []; filterHistoryTable(); },
                function (err) {
                    console.error('loadHistory error:', err);
                    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--danger);">โหลดข้อมูลล้มเหลว</td></tr>';
                },
                opts
            );
        }

         
         
         
         
        function normalizeStatus(status) {
            var raw = (status === null || status === undefined) ? '' : status.toString().trim();
            if (!raw) return '';
            var k = raw.toLowerCase().replace(/\s+/g, ' ');
            var aliases = {
                'awaiting approval':      'Awaiting approval',
                'awaiting for approval':  'Awaiting approval',
                'awaiting':               'Awaiting approval',
                'pending':                'Awaiting approval',
                'approved':               'Approved',
                'sent to gate':           'Approved',
                'rejected':               'Rejected',
                'denied':                 'Rejected',
                'closed':                 'Closed',
                'sent borrow':            'Sent Borrow',
                'borrowed':               'Borrowed',
                'sent return':            'Sent Return',
                'returned':               'Returned',
                'completed':              'Completed',
                'opened':                 'Opened',
                'scanned':                'Scanned',
                'confirmed':              'Confirmed',
                'sent inbound':           'Sent Inbound',
                'awaiting revision':      'Awaiting revision'   // [2026-10-06] ตีกลับให้แก้
            };
            return aliases[k] || raw;
        }

        function getStatusBadge(status) {
            var s = normalizeStatus(status);
            var map = {
                'Awaiting approval': { cls: 'badge-warning', label: 'รออนุมัติ' },
                'Awaiting revision': { cls: 'badge-warning', label: 'ตีกลับให้แก้' },   // [2026-10-06]
                'Approved':          { cls: 'badge-success', label: 'อนุมัติแล้ว' },
                'Rejected':          { cls: 'badge-danger',  label: 'ไม่อนุมัติ' },
                'Closed':            { cls: 'badge-success', label: 'นำจ่ายแล้ว' },
                'Sent Borrow':       { cls: 'badge-info',    label: 'ส่งยืม' },
                'Borrowed':          { cls: 'badge-warning', label: 'ยืมอยู่' },
                'Sent Return':       { cls: 'badge-info',    label: 'ส่งคืน' },
                'Returned':          { cls: 'badge-success', label: 'คืนแล้ว' },
                'Completed':         { cls: 'badge-success', label: 'สำเร็จ' },
                'Opened':            { cls: 'badge-info',    label: 'เปิดประตูแล้ว' },
                'Scanned':           { cls: 'badge-info',    label: 'สแกนแล้ว' },
                'Confirmed':         { cls: 'badge-success', label: 'ยืนยันแล้ว' },
                'Sent Inbound':      { cls: 'badge-info',    label: 'รอนำเข้า' }
            };
            var m = map[s] || { cls: 'badge-secondary', label: s || '-' };
            return '<span class="badge ' + m.cls + '" style="white-space:nowrap; display:inline-block;">' + m.label + '</span>';
        }

        function getTypeBadge(type) {
            var map = {
                'RD': { color: '#3b82f6', label: 'เบิกวัสดุหลัก' },
                'OD': { color: '#f59e0b', label: 'เบิกเบ็ดเตล็ด' },
                'BD': { color: '#8b5cf6', label: 'ยืมอุปกรณ์' },
                'IN': { color: '#10b981', label: 'รับเข้าคลัง' }
            };
            var m = map[type] || { color: '#6b7280', label: type };
            return '<span style="background:' + m.color + ';color:#fff;padding:0.25rem 0.6rem;border-radius:4px;font-size:0.8rem;white-space:nowrap;display:inline-block;box-shadow:0 1px 2px rgba(0,0,0,0.05);">' + m.label + '</span>';
        }

         
         
        var historySort = { key: 'timestamp', dir: 'desc' };

        function sortHistory(key) {
            if (historySort.key === key) {
                historySort.dir = (historySort.dir === 'asc') ? 'desc' : 'asc';
            } else {
                historySort.key = key;
                historySort.dir = 'asc';
            }
            filterHistoryTable();
        }

        function sortHistoryRows(rows) {
            var key = historySort.key;
            var factor = (historySort.dir === 'desc') ? -1 : 1;
            rows.sort(function (a, b) {
                if (key === 'timestamp') {
                    return ((a.timestamp || 0) - (b.timestamp || 0)) * factor;
                }
                var av = (key === 'status') ? normalizeStatus(a.status) : a[key];
                var bv = (key === 'status') ? normalizeStatus(b.status) : b[key];
                av = (av === null || av === undefined) ? '' : String(av);
                bv = (bv === null || bv === undefined) ? '' : String(bv);
                return av.localeCompare(bv, undefined, { numeric: true, sensitivity: 'base' }) * factor;
            });
        }

        function updateHistorySortIndicators() {
            var row = document.getElementById('historyHeadRow');
            if (!row) return;
            var ths = row.querySelectorAll('th[data-sort]');
            for (var i = 0; i < ths.length; i++) {
                var ind = ths[i].querySelector('.sort-ind');
                if (!ind) continue;
                ind.innerHTML = (ths[i].getAttribute('data-sort') === historySort.key)
                    ? (historySort.dir === 'asc' ? '▲' : '▼')
                    : '';
            }
        }

        function filterHistoryTable() {
            var tbody = document.getElementById('historyTableBody');
            if (!tbody) return;
            var typeFilter = document.getElementById('historyFilterType').value;
            var statusFilter = document.getElementById('historyFilterStatus').value;
            var searchFilter = (document.getElementById('historyFilterSearch').value || '').toLowerCase();

             
             
            var isInboundView = (typeFilter === 'IN');
            var rsHeader = document.getElementById('historyRsHeader');
            if (rsHeader) rsHeader.style.display = isInboundView ? '' : 'none';
            var colCount = isInboundView ? 7 : 6;
             
            if (!isInboundView && historySort.key === 'rs') {
                historySort.key = 'timestamp';
                historySort.dir = 'desc';
            }

            var filtered = historyData.filter(function (item) {
                if (typeFilter && item.type !== typeFilter) return false;
                if (statusFilter) {
                    var itemStatus = normalizeStatus(item.status);
                    if (itemStatus !== statusFilter) return false;
                }
                if (searchFilter) {
                    var haystack = (item.docId + ' ' + item.detail + ' ' + item.dateStr + ' ' + (item.rs || '')).toLowerCase();
                    if (haystack.indexOf(searchFilter) === -1) return false;
                }
                return true;
            });

            sortHistoryRows(filtered);
            updateHistorySortIndicators();

            tbody.innerHTML = '';
            if (filtered.length === 0) {
                tbody.innerHTML = '<tr><td colspan="' + colCount + '" style="text-align:center;">ไม่พบรายการ</td></tr>';
                return;
            }

            filtered.forEach(function (item) {
                var safeDoc = escapeHtml(item.docId);
                var safeType = escapeHtml(item.type);
                var tr = document.createElement('tr');
                var rsCell = isInboundView
                    ? '<td data-label="เลขที่ใบรับสินค้า" style="font-weight:600;">' + escapeHtml(item.rs || '-') + '</td>'
                    : '';
                tr.innerHTML = rsCell +
                    '<td data-label="เลขที่เอกสาร" style="font-weight:600;">' + safeDoc + '</td>' +
                    '<td data-label="วันที่" style="color:var(--text-muted); font-size:0.88rem;">' + escapeHtml(item.dateStr) + '</td>' +
                    '<td data-label="ประเภท">' + getTypeBadge(item.type) + '</td>' +
                    '<td data-label="รายละเอียด" style="font-size:0.88rem; color:var(--text-muted);">' + escapeHtml(item.detail) + '</td>' +
                    '<td data-label="สถานะ">' + getStatusBadge(item.status) + '</td>' +
                    '<td data-label="รายงาน"><button class="btn btn-primary" style="padding:0.35rem 0.75rem; font-size:0.85rem; width:auto;" onclick="downloadDocReport(\'' + safeDoc + '\',\'' + safeType + '\')"><i class="fa-solid fa-file-pdf"></i> PDF</button></td>';
                tbody.appendChild(tr);
            });
        }

        function downloadDocReport(docId, docType) {
            if (!docId || !docType) return;
            showLoadingPopup('กำลังสร้างรายงาน PDF', 'กำลังดึงข้อมูลและรูปภาพ\nเอกสาร: ' + docId + '\nกรุณารอสักครู่...');
            google.script.run
                .withSuccessHandler(function (res) {
                    closeAppPopup();
                    if (!res || !res.success) {
                        showInfoPopup('สร้างรายงานไม่สำเร็จ', (res && res.message) ? res.message : 'ไม่สามารถสร้างรายงานได้', 'danger');
                        return;
                    }
                    if (res.dataUri) {
                        var a = document.createElement('a');
                        a.href = res.dataUri;
                        a.download = docId + '_Report.pdf';
                        document.body.appendChild(a);
                        a.click();
                        document.body.removeChild(a);
                    } else if (res.url) {
                        window.open(res.url, '_blank');
                    }
                })
                .withFailureHandler(function (err) {
                    closeAppPopup();
                    console.error('downloadDocReport error:', err);
                    var msg = (err && err.message) ? err.message : String(err || 'unknown');
                    showInfoPopup('สร้างรายงานไม่สำเร็จ', msg, 'danger');
                })
                .generateDocReportPDF(docId, docType);
        }

        var approvalQueueData = [];
        var confirmDocsData = [];
        var qrPageData = [];
        var qrFilterMode = 'all';

        function escapeHtml(value) {
            return (value === null || value === undefined ? '' : String(value))
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function loadApprovalQueue(opts) {
            var container = document.getElementById('approvalQueue');
            if (!container) return;
            var roleLevel = user ? user.roleLevel : '';
            var site = getEffectiveSiteCode();
             
             
             
            var uname = getEffectiveUserName();
             
             
            var cacheKey = [roleLevel, site, uname];
            if (!connextCache.get('approvalRequests', cacheKey)) {
                container.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-spinner fa-spin"></i><span>กำลังโหลดข้อมูล...</span></div>';
            }
            connextCache.swr('approvalRequests', cacheKey,
                function (done, fail) {
                    google.script.run.withSuccessHandler(done).withFailureHandler(fail)
                        .getApprovalRequests(roleLevel, site, uname);
                },
                function (response) {
                    approvalQueueData = response && response.data ? response.data : [];
                    renderApprovalQueue();
                    updateNavBadges();
                },
                function (err) {
                    console.error('loadApprovalQueue error:', err);
                    container.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-triangle-exclamation"></i><span>โหลดข้อมูลการอนุมัติไม่สำเร็จ</span></div>';
                },
                opts
            );
        }

         
         
         
         
        function populateApproveReqFilter() {
            var sel = document.getElementById('approveReqSelect');
            if (!sel) return;
            var current = sel.value || '';
            var seen = {};
            var items = [];  
            (approvalQueueData || []).forEach(function (it) {
                var u = (it.reqName || '').toString().trim();
                if (!u || seen[u]) return;
                seen[u] = true;
                items.push({ username: u, fullName: userFullName(u) });
            });
            items.sort(function (a, b) { return (a.fullName || '').localeCompare(b.fullName || '', 'th'); });

            var opts = '<option value="">-- กรองผู้ขอเบิก --</option>';
            items.forEach(function (it) {
                opts += '<option value="' + escapeHtml(it.username) + '">' + escapeHtml(it.fullName) + '</option>';
            });
            sel.innerHTML = opts;
             
            if (current && seen[current]) sel.value = current;
        }

         
         
         
        function _approvalCanAct(item) {
            if (item && item.canAct !== undefined) return !!item.canAct;
            return !!(user && user.roleLevel !== 'R0');
        }
        function _approvalIsMine(item) {
            var me = getEffectiveUserName().toString().trim().toLowerCase();
            if (!me) return false;
            return (item && (item.reqName || '').toString().trim().toLowerCase() === me);
        }

         
         
        // [PHP port 2026-09-25 per-gate · มติ 51] ป้ายประตูของใบ (ท้ายเลขเอกสาร Gxx) — ผู้อนุมัติเห็นว่าของออกประตูไหน
        function _approvalGateChip(item) {
            var m = /G\d+$/i.exec(((item && item.id) || '').toString());
            if (!m) return '';
            return ' <span class="cw-gate-tag" title="ประตูที่ไปรับของ"><i class="fa-solid fa-door-open" style="font-size:0.7rem;"></i> ' + escapeHtml(m[0].toUpperCase()) + '</span>';
        }

        // [2026-10-06] ยอดพร้อมเบิกต่อรายการ ณ ประตูของใบ (server: onHand · pending = ใบอื่นที่อนุมัติจองแล้ว · avail · short)
        function _approvalStockLine(row) {
            if (!row || row.avail === undefined || row.avail === null) return '';
            var txt = 'พร้อมเบิก' + (row.stockGate ? 'ที่ ' + row.stockGate : '') + ' ' + formatBalanceValue(Math.max(0, row.avail)) +
                ' (ในคลัง ' + formatBalanceValue(row.onHand) + (row.pending > 0 ? ' · อนุมัติจองแล้ว ' + formatBalanceValue(row.pending) : '') + ')';
            return '<div style="font-size:0.78rem; margin-top:2px; color:' + (row.short ? 'var(--danger)' : 'var(--text-muted)') + ';">' +
                (row.short ? '<i class="fa-solid fa-triangle-exclamation"></i> ไม่พอ — ' : '<i class="fa-solid fa-boxes-stacked"></i> ') + escapeHtml(txt) + '</div>';
        }
        function _approvalReviseBanner(item) {
            var ri = item.reviseInfo || {};
            return '<div style="background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; border-radius:9px; padding:9px 11px; margin-bottom:0.75rem; font-size:0.86rem; line-height:1.55;">' +
                '<i class="fa-solid fa-rotate-left"></i> <b>ตีกลับให้แก้</b>' +
                (ri.by ? ' โดย ' + escapeHtml(userFullName(ri.by) || ri.by) : '') + (ri.at ? ' · ' + escapeHtml(ri.at) : '') +
                (ri.note ? '<br>เหตุผล: ' + escapeHtml(ri.note) : '') + '</div>';
        }
        function _approvalShortBanner(item, mine) {
            return '<div style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b; border-radius:9px; padding:9px 11px; margin-bottom:0.75rem; font-size:0.86rem; line-height:1.55;">' +
                '<i class="fa-solid fa-triangle-exclamation"></i> <b>ของที่ประตูไม่พอ — อนุมัติไม่ได้</b><br>' +
                (mine ? 'กด "แก้จำนวน" ลดให้ไม่เกินยอดพร้อมเบิก แล้วบันทึก — ผู้อนุมัติจึงจะอนุมัติได้'
                      : 'กด "ตีกลับให้แก้" ให้ผู้ขอลดจำนวน (ใบที่อนุมัติก่อนได้ของก่อน · ใบรออนุมัติไม่จองของ)') + '</div>';
        }

        // [2026-10-06 rev2] ผู้ขอแก้จำนวนใบของตัวเองได้ที่หน้านี้เลย (รออนุมัติ / ตีกลับ) — สถานะการแก้เก็บตามเลขใบ
        // ไม่หายเวลาหน้าโหลดรายการใหม่ (poll ทุก 6 วินาที)
        var _apEdit = {};   // docId → { open: bool, qty: { matCode: value }, note: '' }
        function _apEditState(id) { if (!_apEdit[id]) _apEdit[id] = { open: false, qty: {}, note: '' }; return _apEdit[id]; }
        function _apEditOpen(index) {
            var item = approvalQueueData[index];
            if (!item || !item.canEdit) return;
            _apEditState(item.id).open = true;
            renderApprovalQueue();
        }
        function _apEditClose(index) {
            var item = approvalQueueData[index];
            if (!item) return;
            delete _apEdit[item.id];
            renderApprovalQueue();
        }
        function _apEditSet(el) {
            var st = _apEditState(el.getAttribute('data-doc'));
            st.qty[el.getAttribute('data-mat')] = el.value;
        }
        function _apEditNote(el) { _apEditState(el.getAttribute('data-doc')).note = el.value; }

        function _renderApprovalCard(item, variant) {
            var dataIndex = approvalQueueData.indexOf(item);
            var editing = item.canEdit && (variant === 'revise' || !!(_apEdit[item.id] && _apEdit[item.id].open));
            var st = editing ? _apEditState(item.id) : null;
            var itemsHtml = (item.items || []).map(function (row) {
                if (editing) {
                    var max = parseFloat(row.qty) || 0;
                    var sug = row.short ? Math.max(0, Math.min(max, Math.floor(parseFloat(row.avail) || 0))) : max;
                    var val = (st.qty[row.matCode] !== undefined) ? st.qty[row.matCode] : sug;
                    return '<div style="display:flex; align-items:center; justify-content:space-between; gap:10px; margin:6px 0;">' +
                        '<div style="min-width:0;">' + escapeHtml(row.matCode || '-') + ' — ' + escapeHtml(row.matName || '-') +
                            '<div style="font-size:0.78rem; color:var(--text-muted);">ขอเดิม ' + escapeHtml(row.qty || 0) + '</div>' + _approvalStockLine(row) + '</div>' +
                        '<input type="number" class="form-control ap-rev-qty" data-doc="' + escapeHtml(item.id) + '" data-mat="' + escapeHtml(row.matCode || '') + '" data-max="' + max + '"' +
                            ' min="0" max="' + max + '" step="1" value="' + escapeHtml(val) + '" oninput="_apEditSet(this)"' +
                            ' style="width:92px; flex:0 0 auto; text-align:right;" aria-label="จำนวนใหม่ ' + escapeHtml(row.matCode || '') + '">' +
                    '</div>';
                }
                return '<div>' + escapeHtml(row.matCode || '-') + ' — ' + escapeHtml(row.matName || '-') +
                    ' <span style="float:right; font-weight:600;">' + escapeHtml(row.qty || 0) + '</span>' + _approvalStockLine(row) + '</div>';
            }).join('');

            var actionHtml;
            if (variant === 'action') {
                var short = !!item.stockShort;
                actionHtml = '<div class="card-actions">' +
                      '<button class="btn btn-approve" onclick="updateApprovalStatusClient(' + dataIndex + ', \'Approved\')"' +
                          (short ? ' disabled style="opacity:0.45; cursor:not-allowed;" title="ของที่ประตูไม่พอ — ตีกลับให้ผู้ขอแก้"' : '') +
                          '><i class="fa-solid fa-circle-check"></i> อนุมัติ</button>' +
                      '<button class="btn btn-secondary" onclick="updateApprovalStatusClient(' + dataIndex + ', \'Awaiting revision\')"><i class="fa-solid fa-rotate-left"></i> ตีกลับให้แก้</button>' +
                      '<button class="btn btn-reject" onclick="updateApprovalStatusClient(' + dataIndex + ', \'Rejected\')"><i class="fa-solid fa-circle-xmark"></i> ไม่อนุมัติ</button>' +
                  '</div>';
            } else if (editing) {
                var isRev = !!item.revise;
                actionHtml = '<div class="card-actions">' +
                      '<textarea class="form-control ap-rev-note" data-doc="' + escapeHtml(item.id) + '" oninput="_apEditNote(this)" rows="2" maxlength="255"' +
                          ' placeholder="หมายเหตุถึงผู้อนุมัติ (ถ้ามี)" style="margin-bottom:0.5rem;">' + escapeHtml(st.note || '') + '</textarea>' +
                      '<button class="btn btn-approve" onclick="approvalReviseSubmit(' + dataIndex + ')"><i class="fa-solid fa-' + (isRev ? 'paper-plane' : 'floppy-disk') + '"></i> ' + (isRev ? 'ส่งใหม่' : 'บันทึกการแก้ไข') + '</button>' +
                      (isRev ? '' : '<button class="btn btn-secondary" onclick="_apEditClose(' + dataIndex + ')"><i class="fa-solid fa-xmark"></i> ไม่แก้</button>') +
                      '<button class="btn btn-reject" onclick="approvalReviseCancel(' + dataIndex + ')"><i class="fa-solid fa-ban"></i> ยกเลิกใบ</button>' +
                  '</div>';
            } else {
                var waitTxt;
                if (item.revise) {
                    waitTxt = 'ตีกลับให้ผู้ขอแก้แล้ว — รอ ' + (userFullName(item.reqName) || item.reqName || 'ผู้ขอ') + ' ส่งใหม่';
                } else if (user && user.roleLevel === 'R0' && !item.canEdit) {
                    waitTxt = 'ดูเท่านั้น — R0 ไม่มีสิทธิ์อนุมัติ';
                } else if (item.approver) {
                    waitTxt = 'รอ ' + (userFullName(item.approver) || item.approver) + ' อนุมัติ';
                } else if (item.requiredRole) {
                    waitTxt = 'รอผู้มีสิทธิ์ R' + item.requiredRole + ' ขึ้นไปอนุมัติ';
                } else {
                    waitTxt = 'รอการอนุมัติ';
                }
                actionHtml = '<div class="card-actions">' +
                      '<div style="color:var(--text-muted); font-size:0.82rem; padding:0.5rem 0;"><i class="fa-solid fa-hourglass-half"></i> ' + escapeHtml(waitTxt) + '</div>' +
                      (item.canEdit
                        ? '<button class="btn btn-secondary" onclick="_apEditOpen(' + dataIndex + ')"><i class="fa-solid fa-pen-to-square"></i> แก้จำนวน</button>' +
                          '<button class="btn btn-reject" onclick="approvalReviseCancel(' + dataIndex + ')"><i class="fa-solid fa-ban"></i> ยกเลิกใบ</button>'
                        : '') +
                  '</div>';
            }

            var usageHtml = item.usageArea
                ? '<div style="margin-top:0.35rem; color:var(--text-muted); font-size:0.9rem;">พื้นที่ใช้งาน: ' + escapeHtml(item.usageArea) + '</div>'
                : '';
            var banner = item.revise ? _approvalReviseBanner(item)
                       : (item.stockShort && (variant === 'action' || item.canEdit) ? _approvalShortBanner(item, variant !== 'action') : '');

            return '<div class="approval-card" data-ap-idx="' + dataIndex + '">' +
                '<div class="card-header">' +
                    '<div>' +
                        '<div class="req-id">' + escapeHtml(item.id || '-') + _approvalGateChip(item) + '</div>' +
                        '<div style="color:var(--text-muted); font-size:0.85rem; margin-top:0.2rem;">' + escapeHtml(item.dateStr || '-') + ' | ผู้รับ: ' + escapeHtml(item.subName || '-') + '</div>' +
                    '</div>' +
                    '<div>' + getTypeBadge(item.type) + '</div>' +
                '</div>' +
                '<div class="card-body">' +
                    '<div>' +
                        banner +
                        '<div style="font-weight:600; margin-bottom:0.65rem;">รายการวัสดุ' + (editing ? ' <span style="font-weight:400; color:var(--text-muted); font-size:0.82rem;">— แก้จำนวน (ลดได้อย่างเดียว · 0 = ตัดรายการ)</span>' : '') + '</div>' +
                        '<div>' + itemsHtml + '</div>' +
                        '<div style="margin-top:0.75rem; color:var(--text-muted); font-size:0.9rem;">ผู้ขอ: ' + escapeHtml(userFullName(item.reqName) || '-') + '</div>' +
                        usageHtml +
                        '<div style="margin-top:0.35rem; color:var(--text-muted); font-size:0.9rem;">หมายเหตุ: ' + escapeHtml(item.notice || '-') + '</div>' +
                    '</div>' +
                    actionHtml +
                '</div>' +
            '</div>';
        }

        function renderApprovalQueue() {
            var container = document.getElementById('approvalQueue');
            if (!container) return;

             
             
             
            populateApproveReqFilter();

            var filterSub = (document.getElementById('approveSubSelect') || {}).value || '';
            var filterReq = (document.getElementById('approveReqSelect') || {}).value || '';
            var filtered = approvalQueueData.filter(function (item) {
                 
                if (filterSub && (item.subName || '') !== filterSub) return false;
                if (filterReq && (item.reqName || '') !== filterReq) return false;
                return true;
            });

            if (!filtered.length) {
                container.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-inbox"></i><span>ไม่มีรายการรออนุมัติ</span></div>';
                return;
            }

             
             
             
            var reviseMine = [], toApprove = [], myPending = [], others = [];   // [2026-10-06] + ใบที่ถูกตีกลับให้ฉันแก้
            filtered.forEach(function (item) {
                if (item.revise && item.canEdit) reviseMine.push(item);   // [rev2] canEdit = ผู้ขอคนเดียว
                else if (_approvalCanAct(item)) toApprove.push(item);
                else if (_approvalIsMine(item)) myPending.push(item);
                else others.push(item);
            });

            var html = '';
            function section(list, headClass, icon, title, sub, variant) {
                if (!list.length) return;
                html += '<div class="approval-section-head ' + headClass + '">' +
                    '<i class="fa-solid ' + icon + '"></i>' +
                    '<span>' + title + (sub ? ' <span class="approval-section-sub">' + sub + '</span>' : '') + '</span>' +
                    '<span class="sec-count">' + list.length + '</span>' +
                '</div>';
                html += list.map(function (item) { return _renderApprovalCard(item, variant); }).join('');
            }

            section(reviseMine, 'sec-revise', 'fa-rotate-left', 'ตีกลับให้ฉันแก้', 'ลดจำนวนแล้วส่งใหม่ หรือยกเลิกใบ', 'revise');
            section(toApprove, 'sec-action', 'fa-stamp', 'รอฉันอนุมัติ', 'อนุมัติ · ตีกลับให้แก้ · ไม่อนุมัติ', 'action');
            section(myPending, 'sec-mine', 'fa-paper-plane', 'คำขอของฉัน', 'ที่ส่งไปและกำลังรออนุมัติ — แก้จำนวน / ยกเลิกใบได้ก่อนอนุมัติ', 'mine');
            section(others, 'sec-other', 'fa-eye', 'รายการอื่นในไซต์', 'รอผู้อื่นอนุมัติ', 'other');

            container.innerHTML = html;
        }

        function updateApprovalStatusClient(index, newStatus) {
            var item = approvalQueueData[index];
            if (!item) return;

            var label = newStatus === 'Approved' ? 'อนุมัติ' : 'ปฏิเสธ';

            // [2026-10-06] ตีกลับให้แก้ — ต้องมีเหตุผล · ของไม่พอ = อนุมัติไม่ได้
            if (newStatus === 'Awaiting revision') { _approvalAskRevise(index); return; }
            if (newStatus === 'Approved' && item.stockShort) {
                showInfoPopup('ของที่ประตูไม่พอ — อนุมัติไม่ได้', (item.shortText || []).join('\n') + '\n\nกด "ตีกลับให้แก้" ให้ผู้ขอลดจำนวน', 'warning');
                return;
            }

            if (newStatus === 'Approved') {
                var itemSummary = (item.items || []).map(function (r) {
                    return r.matName + ' (x' + r.qty + ')';
                }).join(', ');
                showConfirmPopup(
                    'ยืนยันการอนุมัติ',
                    'อนุมัติเอกสาร <strong>' + escapeHtml(item.id) + '</strong><br>' +
                    'ผู้รับ: ' + escapeHtml(item.subName || '-') + '<br>' +
                    (item.usageArea ? 'พื้นที่: ' + escapeHtml(item.usageArea) + '<br>' : '') +
                    'รายการ: ' + escapeHtml(itemSummary),
                    function () { _doApprovalCall(index, newStatus, label); },
                    'ยืนยันอนุมัติ',
                    'btn btn-approve'
                );
                return;
            }

            _doApprovalCall(index, newStatus, label);
        }

        function _doApprovalCall(index, newStatus, label, note) {
            var item = approvalQueueData[index];
            if (!item) return;

            showLoadingPopup('กำลัง' + label + '...', 'กรุณารอสักครู่');

            google.script.run
                .withSuccessHandler(function (response) {
                    closeAppPopup();
                    if (response && response.success) {
                        hapticSuccess();
                        invalidateAfterWrite('approval');
                         
                         
                        var pos = approvalQueueData.indexOf(item);
                        if (pos !== -1) approvalQueueData.splice(pos, 1);
                        renderApprovalQueue();
                        updateNavBadges();
                        showToast(
                            (newStatus === 'Approved' ? '✅ อนุมัติ ' : (newStatus === 'Awaiting revision' ? '↩️ ตีกลับให้แก้ ' : '🚫 ปฏิเสธ ')) + escapeHtml(item.id) + ' แล้ว',
                            'success'
                        );
                        if (newStatus === 'Awaiting revision') loadApprovalQueue({ force: true });   // [2026-10-06] การ์ดกลับมาเป็น "รอผู้ขอแก้"
                         
                         
                    } else {
                        showInfoPopup('เกิดข้อผิดพลาด', (response && response.message) || 'ไม่สามารถอัปเดตสถานะได้', 'danger');
                        loadApprovalQueue({ force: true });   // [2026-10-06] ยอดพร้อมเบิกอาจเปลี่ยน — โหลดการ์ดใหม่
                    }
                })
                .withFailureHandler(function (error) {
                    closeAppPopup();
                    console.error('updateApprovalStatus error:', error);
                    showInfoPopup('เชื่อมต่อไม่สำเร็จ', 'เกิดข้อผิดพลาดในการเชื่อมต่อเซิร์ฟเวอร์', 'danger');
                })
                .updateApprovalStatus(item.id, newStatus, item.type, user ? user.username : '', user ? user.roleLevel : '', note || '');
        }

        // ---- [2026-10-06] ตีกลับให้แก้ (ผู้อนุมัติ) · แก้แล้วส่งใหม่ / ยกเลิกใบ (ผู้ขอ) — lib/doc_revise.php ----
        function _approvalAskRevise(index) {
            var item = approvalQueueData[index];
            if (!item) return;
            var pre = (item.shortText && item.shortText.length) ? 'ของที่ประตูไม่พอ: ' + item.shortText.join(' · ') : '';
            showAppPopup({
                type: 'warning',
                title: 'ตีกลับให้ผู้ขอแก้',
                message: 'ใบ <strong>' + escapeHtml(item.id) + '</strong> จะกลับไปให้ ' + escapeHtml(userFullName(item.reqName) || item.reqName || 'ผู้ขอ') +
                    ' ลดจำนวนแล้วส่งใหม่ (ระหว่างนี้ไม่จองของ)' +
                    '<textarea id="apReviseNote" class="form-control" rows="3" maxlength="255" style="margin-top:0.6rem; width:100%;" placeholder="เหตุผลถึงผู้ขอ (จำเป็น)">' + escapeHtml(pre) + '</textarea>',
                buttons: [{
                    text: 'ยกเลิก', className: 'btn btn-secondary', onClick: closeAppPopup
                }, {
                    text: 'ตีกลับให้แก้', className: 'btn btn-primary',
                    onClick: function () {
                        var el = document.getElementById('apReviseNote');
                        var note = el ? el.value.trim() : '';
                        if (note.length < 3) { showToast('ใส่เหตุผลถึงผู้ขออย่างน้อย 3 ตัวอักษร', 'warning'); if (el) el.focus(); return; }
                        closeAppPopup();
                        _doApprovalCall(index, 'Awaiting revision', 'ตีกลับ', note);
                    }
                }]
            });
        }

        function approvalReviseSubmit(index) {
            var item = approvalQueueData[index];
            var card = document.querySelector('.approval-card[data-ap-idx="' + index + '"]');
            if (!item || !card) return;
            var items = [], lines = [], bad = '', any = false;
            Array.prototype.forEach.call(card.querySelectorAll('.ap-rev-qty'), function (inp) {
                var q = parseFloat(inp.value), max = parseFloat(inp.getAttribute('data-max')) || 0, mc = inp.getAttribute('data-mat');
                if (isNaN(q) || q < 0) q = 0;
                if (q > max) bad = mc + ' ใส่ได้ไม่เกิน ' + max + ' (ลดได้อย่างเดียว)';
                if (q > 0) any = true;
                items.push({ matCode: mc, qty: q });
                lines.push(escapeHtml(mc) + ': ' + max + ' → <b>' + q + '</b>' + (q === 0 ? ' (ตัดรายการ)' : ''));
            });
            if (bad) { showInfoPopup('จำนวนไม่ถูกต้อง', bad, 'warning'); return; }
            if (!any) { showInfoPopup('ตัดทุกรายการแล้ว', 'ถ้าไม่ต้องการของแล้ว ให้กด "ยกเลิกใบ" แทน', 'warning'); return; }
            var noteEl = card.querySelector('.ap-rev-note');
            var note = noteEl ? noteEl.value.trim() : '';
            var isRev = !!item.revise;   // [rev2] ใบที่ถูกตีกลับ = ส่งใหม่ · ใบรออนุมัติ = บันทึกการแก้ไข
            showConfirmPopup(isRev ? 'ส่งใบกลับไปรออนุมัติ' : 'บันทึกการแก้ไขจำนวน', 'ใบ <strong>' + escapeHtml(item.id) + '</strong><br>' + lines.join('<br>'), function () {
                showLoadingPopup(isRev ? 'กำลังส่งใหม่...' : 'กำลังบันทึก...', 'ตรวจของที่ประตูอีกครั้ง');
                google.script.run
                    .withSuccessHandler(function (res) {
                        closeAppPopup();
                        if (res && res.success) {
                            hapticSuccess();
                            invalidateAfterWrite('approval');
                            delete _apEdit[item.id];
                            showToast(isRev ? '📨 ส่ง ' + escapeHtml(item.id) + ' กลับไปรออนุมัติแล้ว' : '✏️ แก้จำนวน ' + escapeHtml(item.id) + ' แล้ว (ยังรออนุมัติ)', 'success');
                        } else {
                            showInfoPopup(isRev ? 'ส่งใหม่ไม่ได้' : 'บันทึกไม่ได้', (res && res.message) || 'เกิดข้อผิดพลาด', 'danger');
                        }
                        loadApprovalQueue({ force: true });
                    })
                    .withFailureHandler(function () {
                        closeAppPopup();
                        showInfoPopup('เชื่อมต่อไม่สำเร็จ', 'เกิดข้อผิดพลาดในการเชื่อมต่อเซิร์ฟเวอร์', 'danger');
                    })
                    .reviseRequisition(item.id, items, note);
            }, isRev ? 'ส่งใหม่' : 'บันทึก', 'btn btn-approve');
        }

        function approvalReviseCancel(index) {
            var item = approvalQueueData[index];
            if (!item) return;
            showConfirmPopup('ยกเลิกใบ', 'ยกเลิกใบ <strong>' + escapeHtml(item.id) + '</strong> ทั้งใบ — ย้อนกลับไม่ได้', function () {
                showLoadingPopup('กำลังยกเลิก...', 'กรุณารอสักครู่');
                google.script.run
                    .withSuccessHandler(function (res) {
                        closeAppPopup();
                        if (res && res.success) {
                            hapticSuccess();
                            invalidateAfterWrite('approval');
                            delete _apEdit[item.id];
                            showToast('🗑️ ยกเลิก ' + escapeHtml(item.id) + ' แล้ว', 'success');
                        } else {
                            showInfoPopup('ยกเลิกไม่ได้', (res && res.message) || 'เกิดข้อผิดพลาด', 'danger');
                        }
                        loadApprovalQueue({ force: true });
                    })
                    .withFailureHandler(function () {
                        closeAppPopup();
                        showInfoPopup('เชื่อมต่อไม่สำเร็จ', 'เกิดข้อผิดพลาดในการเชื่อมต่อเซิร์ฟเวอร์', 'danger');
                    })
                    .cancelRequisition(item.id, item.type, getEffectiveUserName());
            }, 'ยกเลิกใบ', 'btn btn-danger');
        }

         
         
         
         
        var confirmWizardIndex  = 0;
        var confirmWizardPhotos = {};    
        var confirmWizardNotes  = {};    

         
         
         
        var confirmClosingDocs        = [];     
        var _confirmClosedSeen        = {};     
        var _confirmClosePollTimer    = null;
        var _confirmClosePollInFlight = false;

         
        var _confirmPollTimer     = null;
        var _confirmPollInFlight  = false;  
        var _confirmFetchInFlight = false;  
        var _confirmPollSeenIds   = {};     
        var _confirmImgBusy       = false;  

        function loadConfirmableDocuments(opts) {
            var wrap = document.getElementById('confirmWizard');
            var site = getEffectiveSiteCode();
            var userName = getEffectiveUserName();
            var roleName = user ? user.role : '';
            if (wrap && !connextCache.get('confirmableDocs', [site, userName, roleName])) {
                wrap.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-spinner fa-spin"></i><span>กำลังโหลดข้อมูล...</span></div>';
            }
            connextCache.swr('confirmableDocs', [site, userName, roleName],
                function (done, fail) {
                    google.script.run.withSuccessHandler(done).withFailureHandler(fail)
                        .getConfirmableDocuments(site, userName, roleName);
                },
                function (response) {
                    _applyConfirmableResponse(response);
                },
                function (err) {
                    console.error('loadConfirmableDocuments error:', err);
                    if (wrap) wrap.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-triangle-exclamation"></i><span>โหลดข้อมูลเอกสารยืนยันไม่สำเร็จ</span></div>';
                },
                opts
            );
        }

         
         
         
        function _applyConfirmableResponse(response) {
            var prevIds = {};
            (confirmDocsData || []).forEach(function (d) { prevIds[d.id] = true; });

            confirmDocsData = response && response.data ? response.data : [];

             
            var liveIds = {};
            confirmDocsData.forEach(function (d) { liveIds[d.id] = true; });
            Object.keys(confirmWizardPhotos).forEach(function (k) { if (!liveIds[k]) delete confirmWizardPhotos[k]; });
            Object.keys(confirmWizardNotes).forEach(function (k) { if (!liveIds[k]) delete confirmWizardNotes[k]; });

             
             
            confirmDocsData.forEach(function (d) { _confirmPollSeenIds[d.id] = true; });

             
             
             
             
             
            if (response && Array.isArray(response.pendingCloseDocs)) {
                var _hasNextGroup = (confirmDocsData || []).length > 0 || confirmClosingDocs.length > 0;
                if (_hasNextGroup) {
                    var _knownClosing = {};
                    confirmClosingDocs.forEach(function (c) { _knownClosing[c.id] = true; });
                    response.pendingCloseDocs.forEach(function (p) {
                        var pid = p && p.id ? p.id.toString().trim() : '';
                        if (!pid || _knownClosing[pid] || _confirmClosedSeen[pid]) return;
                        confirmClosingDocs.push({ id: pid, gate: (p.gate || _gateFromDocId(pid) || ''), pickingId: (p.pickingId || ''), status: 'Confirmed' });
                        _knownClosing[pid] = true;
                    });
                    _syncConfirmClosePoll();
                }
            }

             
            var _activeLen = _confirmActiveDocs().length;
            if (confirmWizardIndex >= _activeLen) confirmWizardIndex = Math.max(0, _activeLen - 1);

            renderPickSummary();
            renderConfirmWizard();
            updateNavBadges();

            var added = 0;
            confirmDocsData.forEach(function (d) { if (!prevIds[d.id]) added++; });
            return added;
        }

        function togglePickSummary() {
            var card = document.getElementById('pickSummaryCard');
            if (card) card.classList.toggle('collapsed');
        }

         
        function renderPickSummary() {
            var card = document.getElementById('pickSummaryCard');
            var body = document.getElementById('pickSummaryBody');
            var countEl = document.getElementById('pickSummaryCount');
            var pkEl = document.getElementById('pickSummaryPk');
            if (!card || !body) return;

            var activeDocs = _confirmActiveDocs();
            if (!activeDocs.length) { card.style.display = 'none'; return; }

             
            if (pkEl) {
                var _lbl = _confirmActiveGroupLabel();
                pkEl.textContent = _lbl ? ' · ชุด ' + _lbl : '';
            }

            var agg = {};  
            activeDocs.forEach(function (doc) {
                (doc.items || []).forEach(function (it) {
                    var code = (it.matCode || '').toString().trim();
                    if (!code) return;
                    if (!agg[code]) agg[code] = { matCode: code, matName: it.matName || code, unit: it.unit || '', qty: 0, docCount: 0, subgroup: (it.subgroup || '').toString().trim() };
                    agg[code].qty += (parseFloat(it.qty) || 0);
                    agg[code].docCount += 1;
                    if (!agg[code].unit && it.unit) agg[code].unit = it.unit;
                    if (!agg[code].subgroup && it.subgroup) agg[code].subgroup = it.subgroup.toString().trim();
                });
            });

            var rows = Object.keys(agg).map(function (k) { return agg[k]; });

             
            var groups = {};
            rows.forEach(function (r) {
                var g = r.subgroup || 'ไม่ระบุหมวด';
                if (!groups[g]) groups[g] = [];
                groups[g].push(r);
            });
            var groupNames = Object.keys(groups).sort(function (a, b) {
                if (a === 'ไม่ระบุหมวด') return 1;    
                if (b === 'ไม่ระบุหมวด') return -1;
                return a.localeCompare(b, 'th');
            });

            card.style.display = '';
            if (countEl) countEl.textContent = rows.length + ' ชนิด';

            function rowHtml(r) {
                var dup = r.docCount > 1 ? '<span class="dup">(รวม ' + r.docCount + ' ใบ)</span>' : '';
                return '<div class="pick-sum-row">' +
                    '<div class="pick-sum-name">' +
                        '<span class="pick-sum-code">' + escapeHtml(r.matCode) + '</span>' +
                        escapeHtml(r.matName || '-') +
                    '</div>' +
                    '<div class="pick-sum-qty">' + escapeHtml(formatBalanceValue(r.qty)) + (r.unit ? ' ' + escapeHtml(r.unit) : '') + dup + '</div>' +
                '</div>';
            }

            body.innerHTML = groupNames.map(function (g) {
                var list = groups[g].slice().sort(function (a, b) { return (a.matName || '').localeCompare(b.matName || '', 'th'); });
                return '<div class="pick-sum-group">' +
                        '<i class="fa-solid fa-layer-group"></i> ' + escapeHtml(g) +
                        '<span class="pick-sum-group-count">' + list.length + '</span>' +
                    '</div>' +
                    list.map(rowHtml).join('');
            }).join('');
        }

        function _confirmDocIsReturn(doc) {
            if (!doc) return false;
            if (doc.isReturn === true) return true;
            if (/RT$/.test(doc.id || '')) return true;
            return (doc.status || '').toString().toLowerCase().indexOf('sent return') !== -1;
        }

         
         
         
         
         
         
         
         
        function _confirmGroupKey(doc) {
            var gate = _gateFromDocId(doc ? doc.id : '') || '?';
            var pk = (doc && doc.pickingId || '').toString().trim();
            return gate + '|' + (pk || 'nopk');
        }
         
        function _docNeedsClose(doc) {
            var gs = (doc && doc.gateStatus || '').toString().toLowerCase();
            if (gs === 'opened') return true;     
            if (gs === 'scanned') return false;   
            var gate = _gateFromDocId(doc ? doc.id : '');
            return (!gate || gate === 'G01');     
        }
         
        function _confirmIsClosing() {
            return !!(confirmClosingDocs && confirmClosingDocs.length);
        }
         
         
        function _confirmActiveGroupKey() {
            var docs = confirmDocsData || [];
            if (!docs.length) return null;
            var groups = {};
            docs.forEach(function (d) {
                var k = _confirmGroupKey(d);
                if (!groups[k]) {
                    var gate = _gateFromDocId(d.id) || '';
                    var gm = gate.match(/(\d+)/);
                    groups[k] = { key: k, gateNum: gm ? parseInt(gm[1], 10) : 9999, openedAt: (d.openedAt || 0) };
                } else if ((d.openedAt || 0) < groups[k].openedAt) {
                    groups[k].openedAt = (d.openedAt || 0);
                }
            });
            var arr = Object.keys(groups).map(function (k) { return groups[k]; });
            arr.sort(function (a, b) {
                if (a.gateNum !== b.gateNum) return a.gateNum - b.gateNum;    
                return (a.openedAt || 0) - (b.openedAt || 0);                
            });
            return arr[0].key;
        }
         
        function _confirmActiveDocs() {
            if (_confirmIsClosing()) return [];
            var key = _confirmActiveGroupKey();
            if (key === null) return [];
            return (confirmDocsData || []).filter(function (d) { return _confirmGroupKey(d) === key; });
        }
         
        function _confirmLockedDocs() {
            if (_confirmIsClosing()) return (confirmDocsData || []).slice();
            var key = _confirmActiveGroupKey();
            if (key === null) return [];
            return (confirmDocsData || []).filter(function (d) { return _confirmGroupKey(d) !== key; });
        }
         
        function _confirmLockedGroupCount() {
            var seen = {}, n = 0;
            _confirmLockedDocs().forEach(function (d) {
                var k = _confirmGroupKey(d);
                if (!seen[k]) { seen[k] = true; n++; }
            });
            return n;
        }
         
        function _confirmActiveGroupLabel() {
            var docs = _confirmActiveDocs();
            if (!docs.length) return '';
            var pk = (docs[0].pickingId || '').toString().trim();
            var gate = _gateFromDocId(docs[0].id) || '';
            if (pk) return pk + (gate ? ' · ' + gate : '');
            return gate || 'ชุดนี้';
        }

         
        function _confirmStashNote() {
            var doc = _confirmActiveDocs()[confirmWizardIndex];
            if (!doc) return;
            var ta = document.getElementById('cwNotesInput');
            if (ta) confirmWizardNotes[doc.id] = ta.value || '';
        }

         
         
         
        function _renderConfirmClosingWait() {
            var closing    = confirmClosingDocs || [];
            var nextDocs   = _confirmLockedDocs().length;
            var nextGroups = _confirmLockedGroupCount();
            var pkSet = {}, gateSet = {};
            closing.forEach(function (c) {
                if (c.pickingId) pkSet[c.pickingId] = true;
                if (c.gate) gateSet[c.gate] = true;
            });
            var pks   = Object.keys(pkSet);
            var gates = Object.keys(gateSet).sort();
            var headText = pks.length ? ('ชุด ' + pks.join(', ')) : (gates.length ? gates.join(', ') : 'ประตูมีตัวล็อก');

            function _chip(ok, labelWait, labelDone) {
                return '<span class="cw-closing-chip ' + (ok ? 'done' : 'wait') + '">' +
                    '<i class="fa-solid ' + (ok ? 'fa-circle-check' : 'fa-hourglass-half') + '"></i> ' +
                    (ok ? labelDone : labelWait) + '</span>';
            }
            var rowsHtml = closing.map(function (c) {
                var g = (c.status || 'Confirmed').toString();
                var d = (c.docStatus || '').toString();
                var gDone = g.toLowerCase() === 'closed';                  
                var dDone = /completed|borrowed|returned/i.test(d);        
                var rowDone = gDone && dDone;
                return '<div class="cw-closing-row' + (rowDone ? ' done' : '') + '">' +
                        '<span class="cw-closing-id">' + escapeHtml(c.id) + '</span>' +
                        '<span class="cw-closing-chips">' +
                            _chip(gDone, 'รอปิดประตู', 'ปิดประตูแล้ว') +
                            _chip(dDone, 'รอปิดงาน', 'ปิดงานแล้ว') +
                        '</span>' +
                    '</div>';
            }).join('');

            var nextHtml = nextDocs > 0
                ? '<div class="cw-closing-next"><i class="fa-solid fa-lock"></i> ชุดถัดไปอีก ' + nextGroups + ' ชุด (' + nextDocs + ' ใบ) จะปลดล็อกอัตโนมัติเมื่อประตูปิด</div>'
                : '';

            return '<div class="cw-closing-wait">' +
                    '<div class="cw-closing-spinner"></div>' +
                    '<div class="cw-closing-title">บันทึกรูป ' + escapeHtml(headText) + ' แล้ว — กำลังรอปิดประตู</div>' +
                    '<div class="cw-closing-sub">ระบบกำลังรอให้ประตูถูกปิด (สถานะ Closed) ก่อน จึงจะให้ถ่ายรูปชุดถัดไปได้ ' +
                        'กรุณารอสักครู่ — หน้าจอจะไปต่อให้เองอัตโนมัติเมื่อประตูปิดเรียบร้อย</div>' +
                    '<div class="cw-closing-list">' + rowsHtml + '</div>' +
                    nextHtml +
                '</div>';
        }

        function renderConfirmWizard() {
            var wrap = document.getElementById('confirmWizard');
            if (!wrap) return;

             
             
            if (_confirmIsClosing()) {
                wrap.innerHTML = _renderConfirmClosingWait();
                return;
            }

            if (!confirmDocsData.length) {
                wrap.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-camera"></i><span>ไม่มีเอกสารรอถ่ายรูปยืนยันในขณะนี้</span>' +
                    '<div style="margin-top:0.5rem; font-size:0.85rem;">เอกสารจะปรากฏที่นี่หลังถูกสแกนผ่านประตู (สถานะ Opened/Scanned)</div></div>';
                return;
            }

             
            var activeDocs = _confirmActiveDocs();
            var lockedDocs = _confirmLockedDocs();
            var activeNeedsClose = activeDocs.length ? _docNeedsClose(activeDocs[0]) : false;

            if (confirmWizardIndex < 0) confirmWizardIndex = 0;
            if (confirmWizardIndex >= activeDocs.length) confirmWizardIndex = activeDocs.length - 1;
            var doc = activeDocs[confirmWizardIndex];
            var photos = confirmWizardPhotos[doc.id] || [];
            var total = activeDocs.length;
            var doneCount = activeDocs.filter(function (d) { return (confirmWizardPhotos[d.id] || []).length > 0; }).length;

             
            var _saveBtnStyle, _saveBtnDisabled, _saveHint, _saveReadyCls = '';
            if (doneCount === 0) {
                _saveBtnStyle = 'background:#cbd5e1; color:#64748b; box-shadow:none; cursor:not-allowed;';
                _saveBtnDisabled = ' disabled';
                _saveHint = '<div class="cw-save-hint muted"><i class="fa-solid fa-camera"></i> ยังไม่ได้ถ่ายรูป — ถ่ายอย่างน้อย 1 ใบจึงจะกดยืนยันได้</div>';
            } else if (doneCount >= total) {
                _saveBtnStyle = 'background:#16a34a; color:#fff;';
                _saveBtnDisabled = '';
                _saveReadyCls = ' cw-save-ready';
                _saveHint = '<div class="cw-save-hint"><i class="fa-solid fa-circle-check"></i> ถ่ายรูปครบทั้ง ' + total + ' ใบแล้ว — กดยืนยันได้เลย</div>';
            } else {
                _saveBtnStyle = 'background:#f59e0b; color:#fff;';
                _saveBtnDisabled = '';
                _saveHint = '<div class="cw-save-hint" style="color:#b45309;"><i class="fa-solid fa-triangle-exclamation"></i> ถ่ายแล้ว ' + doneCount + '/' + total + ' ใบ — กดยืนยันได้ (ใบที่ยังไม่ถ่ายจะค้างไว้)</div>';
            }
            var saveBarHtml = '<div class="cw-save-bar">' + _saveHint +
                '<button type="button" class="btn' + _saveReadyCls + '" id="cwSaveAllBtn" onclick="submitAllConfirmations()"' + _saveBtnDisabled + ' style="' + _saveBtnStyle + '">' +
                    '<i class="fa-solid fa-floppy-disk"></i> ยืนยันบันทึก (' + doneCount + '/' + total + ' ใบ)' +
                '</button>' +
            '</div>';

             
            var phaseLabel = '<span class="cw-phase-pill ' + (activeNeedsClose ? 'p1' : 'p2') + '">' +
                '<i class="fa-solid fa-layer-group"></i> ชุด ' + escapeHtml(_confirmActiveGroupLabel()) + '</span>';
            var lockedBanner = lockedDocs.length
                ? '<div class="cw-locked-banner"><i class="fa-solid fa-lock"></i> อีก <b>' + _confirmLockedGroupCount() + '</b> ชุด (' + lockedDocs.length + ' ใบ) ถูกล็อกอยู่ — ถ่ายชุดนี้ให้ครบและบันทึกก่อน จึงจะทำชุดถัดไปได้</div>'
                : '';

             
            var dotsHtml = activeDocs.map(function (d, i) {
                var cls = 'cw-dot';
                if ((confirmWizardPhotos[d.id] || []).length > 0) cls += ' done';
                if (i === confirmWizardIndex) cls += ' current';
                var inner = (confirmWizardPhotos[d.id] || []).length > 0 ? '<i class="fa-solid fa-check"></i>' : (i + 1);
                return '<button type="button" class="' + cls + '" title="' + escapeHtml(d.id) + '" onclick="confirmWizardGoto(' + i + ')">' + inner + '</button>';
            }).join('');

             
            var itemsHtml = (doc.items || []).map(function (it) {
                var qtyText = formatBalanceValue(it.qty) + (it.unit ? ' ' + escapeHtml(it.unit) : '');
                return '<div class="cw-item">' +
                    '<div class="cw-item-info">' +
                        '<div class="cw-item-code">' + escapeHtml(it.matCode || '-') + '</div>' +
                        '<div class="cw-item-name">' + escapeHtml(it.matName || '-') + '</div>' +
                    '</div>' +
                    '<div class="cw-item-qty">' + escapeHtml(qtyText) + '</div>' +
                '</div>';
            }).join('') || '<div style="color:var(--text-muted);">-</div>';

            var photosHtml = photos.map(function (img, i) {
                return '<div class="preview-img-container">' +
                    '<img src="' + img.dataUrl + '" alt="preview-' + i + '">' +
                    '<button type="button" class="remove-img" onclick="removeConfirmImage(' + i + ')">&times;</button>' +
                '</div>';
            }).join('');

            var photoFlag = photos.length
                ? '<span class="cw-photo-flag ok"><i class="fa-solid fa-circle-check"></i> ถ่ายแล้ว ' + photos.length + ' รูป</span>'
                : '<span class="cw-photo-flag none"><i class="fa-solid fa-camera"></i> ยังไม่ได้ถ่ายรูป</span>';

            var isReturn = _confirmDocIsReturn(doc);
            var recvLabel = doc.type === 'IN' ? 'RS' : (isReturn ? 'ผู้คืน' : 'ผู้รับ');
            var notesVal = confirmWizardNotes[doc.id] || '';

            var navHtml =
                '<button type="button" class="btn btn-secondary" onclick="confirmWizardPrev()"' + (confirmWizardIndex === 0 ? ' disabled' : '') + '><i class="fa-solid fa-chevron-left"></i> ก่อนหน้า</button>' +
                (confirmWizardIndex < total - 1
                    ? '<button type="button" class="btn btn-primary" onclick="confirmWizardNext()">ถัดไป <i class="fa-solid fa-chevron-right"></i></button>'
                    : '<span style="flex:1; text-align:center; color:var(--text-muted); font-size:0.85rem; align-self:center;">ใบสุดท้ายแล้ว</span>');

            wrap.innerHTML =
                '<div class="cw-progress">' +
                    '<span class="cw-progress-label">เอกสาร ' + (confirmWizardIndex + 1) + ' / ' + total + '</span>' +
                    phaseLabel +
                    '<div class="cw-dots">' + dotsHtml + '</div>' +
                    '<span style="margin-left:auto; font-size:0.82rem; color:var(--text-muted);">ถ่ายแล้ว ' + doneCount + '/' + total + ' ใบ</span>' +
                '</div>' +
                lockedBanner +
                '<div class="cw-card">' +
                    '<div class="cw-card-head">' +
                        '<div>' +
                            '<div class="cw-doc-id">' + escapeHtml(doc.id || '-') + '</div>' +
                            '<div class="cw-doc-meta">' + recvLabel + ': ' + escapeHtml(doc.subName || '-') + ' · ผู้ขอ: ' + escapeHtml(userFullName(doc.userName) || '-') + '</div>' +
                            '<div style="margin-top:0.4rem;">' + photoFlag + '</div>' +
                        '</div>' +
                        '<div>' + getTypeBadge(doc.type || '-') + '</div>' +
                    '</div>' +
                    '<div class="cw-section">' +
                        '<div class="cw-section-title"><i class="fa-solid fa-box-open" style="color:var(--primary);"></i> รายการที่ต้องหยิบ (ใบนี้)</div>' +
                        itemsHtml +
                    '</div>' +
                    '<div class="cw-section">' +
                        '<div class="cw-section-title">รูปยืนยัน <span style="font-weight:400; color:var(--text-muted); font-size:0.82rem;">(ของใบนี้)</span></div>' +
                        '<input type="file" id="confirmCameraInput" accept="image/*" capture="environment" style="display:none;" onchange="handleConfirmImageChange(this)">' +
                        '<input type="file" id="confirmImagesInput" accept="image/*" multiple style="display:none;" onchange="handleConfirmImageChange(this)">' +
                        '<div class="upload-area">' +
                            '<div class="upload-btn-row">' +
                                '<button type="button" class="upload-btn upload-btn-camera" onclick="document.getElementById(\'confirmCameraInput\').click()"><i class="fa-solid fa-camera"></i><span>ถ่ายรูป</span></button>' +
                                '<button type="button" class="upload-btn upload-btn-file" onclick="document.getElementById(\'confirmImagesInput\').click()"><i class="fa-solid fa-folder-open"></i><span>เลือกไฟล์</span></button>' +
                            '</div>' +
                            '<div class="upload-hint">รองรับ JPG, PNG · เพิ่มได้หลายรูป</div>' +
                        '</div>' +
                        '<div class="preview-images" id="cwPreviewGrid">' + photosHtml + '</div>' +
                    '</div>' +
                    '<div class="cw-section">' +
                        '<div class="cw-section-title">หมายเหตุ <span style="font-weight:400; color:var(--text-muted); font-size:0.82rem;">(ถ้ามี)</span></div>' +
                        '<textarea id="cwNotesInput" class="form-control" rows="2" placeholder="ระบุรายละเอียดเพิ่มเติม" oninput="_confirmStashNote()">' + escapeHtml(notesVal) + '</textarea>' +
                    '</div>' +
                    '<div class="cw-nav">' + navHtml + '</div>' +
                '</div>' +
                saveBarHtml;
        }

        function confirmWizardGoto(i) {
            _confirmStashNote();
            confirmWizardIndex = i;
            renderConfirmWizard();
            var wrap = document.getElementById('confirmWizard');
            if (wrap && window.innerWidth < 768) setTimeout(function () { wrap.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 60);
        }
        function confirmWizardPrev() { if (confirmWizardIndex > 0) confirmWizardGoto(confirmWizardIndex - 1); }
        function confirmWizardNext() { if (confirmWizardIndex < _confirmActiveDocs().length - 1) confirmWizardGoto(confirmWizardIndex + 1); }

        function handleConfirmImageChange(input) {
            var files = Array.prototype.slice.call((input && input.files) || []);
            if (!files.length) return;
            var doc = _confirmActiveDocs()[confirmWizardIndex];
            if (!doc) return;
            var hintEl = document.querySelector('#confirmWizard .upload-hint');
            var prevText = hintEl ? hintEl.textContent : '';
            if (hintEl) hintEl.textContent = '🔄 กำลังบีบอัดรูป...';
            _confirmImgBusy = true;  
            Promise.all(files.map(function (f) { return compressImage(f); }))
                .then(function (results) {
                    var arr = confirmWizardPhotos[doc.id] || (confirmWizardPhotos[doc.id] = []);
                    results.filter(function (r) { return r && r.dataUrl; }).forEach(function (r) { arr.push(r); });
                    input.value = '';
                    _confirmImgBusy = false;
                    _confirmStashNote();
                    renderConfirmWizard();
                })
                .catch(function (err) {
                    console.error('compress (confirm) failed:', err);
                    _confirmImgBusy = false;
                    if (hintEl) hintEl.textContent = prevText;
                });
        }

        function removeConfirmImage(index) {
            var doc = _confirmActiveDocs()[confirmWizardIndex];
            if (!doc) return;
            var arr = confirmWizardPhotos[doc.id] || [];
            arr.splice(index, 1);
            _confirmStashNote();
            renderConfirmWizard();
        }

         
         
        function submitAllConfirmations() {
            _confirmStashNote();
            var activeDocs = _confirmActiveDocs();
            var toSave = activeDocs.filter(function (d) { return (confirmWizardPhotos[d.id] || []).length > 0; });
            var missing = activeDocs.length - toSave.length;
            if (!toSave.length) {
                showInfoPopup('ยังไม่ได้ถ่ายรูป', 'กรุณาถ่ายรูปยืนยันอย่างน้อย 1 ใบก่อนบันทึก', 'warning');
                return;
            }

            var lockedCount      = _confirmLockedDocs().length;
            var lockedGroups     = _confirmLockedGroupCount();
            var activeNeedsClose = activeDocs.length ? _docNeedsClose(activeDocs[0]) : false;
            function _run() {
                _saveConfirmSeq(toSave, 0, 0);
            }

            if (missing > 0) {
                showConfirmPopup(
                    'ยังมีเอกสารที่ยังไม่ถ่ายรูป',
                    'มี ' + missing + ' ใบในชุดนี้ที่ยังไม่ได้ถ่ายรูป จะบันทึกเฉพาะ ' + toSave.length + ' ใบที่ถ่ายแล้วใช่หรือไม่?\n(ใบที่เหลือจะยังอยู่ในรายการรอถ่ายรูป)',
                    _run, 'บันทึก ' + toSave.length + ' ใบ', 'btn btn-success'
                );
            } else if (lockedCount > 0 && missing === 0 && activeNeedsClose) {
                 
                 
                showConfirmPopup(
                    'บันทึกชุดนี้ทั้งหมด',
                    'บันทึกการยืนยัน ' + toSave.length + ' ใบของชุดนี้ (ประตูมีตัวล็อก) ใช่หรือไม่?\nหลังบันทึก ระบบจะรอให้ประตูปิด (Closed) ก่อน แล้วจึงปลดล็อกชุดถัดไป (' + lockedGroups + ' ชุด / ' + lockedCount + ' ใบ) ให้ถ่ายต่อ',
                    _run, 'บันทึกแล้วรอปิดประตู', 'btn btn-success'
                );
            } else if (lockedCount > 0 && missing === 0) {
                 
                showConfirmPopup(
                    'บันทึกชุดนี้ทั้งหมด',
                    'บันทึกการยืนยัน ' + toSave.length + ' ใบของชุดนี้ใช่หรือไม่?\nหลังบันทึกจะไปชุดถัดไป (' + lockedGroups + ' ชุด / ' + lockedCount + ' ใบ) ให้ถ่ายต่อ',
                    _run, 'บันทึกและไปต่อ', 'btn btn-success'
                );
            } else {
                _run();
            }
        }

        function _saveConfirmSeq(list, i, okCount, saved) {
            saved = saved || [];
            if (i >= list.length) {
                closeAppPopup();
                hapticSuccess();
                invalidateAfterWrite('confirm');

                var savedSet = {};
                saved.forEach(function (id) { savedSet[id] = true; });

                 
                 
                 
                var savedNeedClose = (confirmDocsData || []).filter(function (d) { return savedSet[d.id] && _docNeedsClose(d); });
                var othersWaiting  = (confirmDocsData || []).filter(function (d) { return !savedSet[d.id]; });
                var enterClosing   = savedNeedClose.length > 0 && othersWaiting.length > 0;

                 
                saved.forEach(function (id) { delete confirmWizardPhotos[id]; delete confirmWizardNotes[id]; });
                confirmDocsData = (confirmDocsData || []).filter(function (d) { return !savedSet[d.id]; });
                confirmWizardIndex = 0;

                if (enterClosing) {
                     
                     
                    savedNeedClose.forEach(function (d) {
                        if (_confirmClosedSeen[d.id]) return;
                        if (confirmClosingDocs.some(function (c) { return c.id === d.id; })) return;
                        confirmClosingDocs.push({ id: d.id, gate: (_gateFromDocId(d.id) || ''), pickingId: (d.pickingId || ''), status: 'Confirmed' });
                    });
                    renderPickSummary();
                    renderConfirmWizard();
                    updateNavBadges();
                    showToast('✅ บันทึกรูปชุดนี้แล้ว — รอประตูปิด (Closed) ก่อนถ่ายชุดถัดไป', 'info');
                    _syncConfirmClosePoll();
                    return;
                }

                 
                renderPickSummary();
                renderConfirmWizard();
                updateNavBadges();
                showToast('✅ บันทึกยืนยัน ' + okCount + ' ใบสำเร็จ', 'success');
                return;
            }
            var doc = list[i];
            showLoadingPopup('กำลังบันทึก ' + (i + 1) + ' / ' + list.length, 'เอกสาร ' + doc.id);
            var payload = {
                docId: doc.id,
                actionType: _confirmDocIsReturn(doc) ? 'confirm2gate' : 'confirm',
                notes: confirmWizardNotes[doc.id] || '',
                images: (confirmWizardPhotos[doc.id] || []).map(function (img) { return img.dataUrl; }),
                username: user ? user.username : ''
            };
            google.script.run
                .withSuccessHandler(function (resp) {
                    if (resp && resp.success) saved.push(doc.id);
                    _saveConfirmSeq(list, i + 1, okCount + (resp && resp.success ? 1 : 0), saved);
                })
                .withFailureHandler(function (err) {
                    console.error('saveConfirmationData error for ' + doc.id + ':', err);
                    _saveConfirmSeq(list, i + 1, okCount, saved);
                })
                .saveConfirmationData(payload);
        }

         
         
         
        function loadGatesIntoMap(opts) {
            connextCache.swr('gates', [],
                function (done, fail) {
                    google.script.run.withSuccessHandler(done).withFailureHandler(fail).getGatesList();
                },
                function (rows) {
                    var map = {};
                    (rows || []).forEach(function (g) {
                        var id   = (g.GateID   || '').toString().trim();
                        var name = (g.GateName || '').toString().trim();
                        if (id) map[id] = name || id;
                    });
                    globalGateMap = map;
                     
                    if (document.getElementById('qrCardsGrid')) {
                        try { renderQRCards(); } catch (e) {}
                    }
                },
                function (err) { console.warn('loadGatesIntoMap failed:', err); },
                opts
            );
        }

        function loadQRPage(opts) {
            var grid = document.getElementById('qrCardsGrid');
            if (!grid) return;
            var site = getEffectiveSiteCode();
            if (!connextCache.get('approvedDocs', [site])) {
                grid.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-spinner fa-spin"></i><span>กำลังโหลด...</span></div>';
            }
             
            loadGatesIntoMap(opts);

            connextCache.swr('approvedDocs', [site],
                function (done, fail) {
                    google.script.run.withSuccessHandler(done).withFailureHandler(fail).getApprovedDocuments(site);
                },
                function (response) {
                    qrPageData = response && response.data ? response.data : [];
                    renderQRCards();
                    updateNavBadges();
                },
                function (err) {
                    console.error('loadQRPage error:', err);
                    grid.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-triangle-exclamation"></i><span>โหลดข้อมูล QR ไม่สำเร็จ</span></div>';
                },
                opts
            );
        }

         
         
         
         
         
         
        var _qrPagePollTimer = null;
        var _qrPagePollInFlight = false;

        function startQrPagePolling() {
            stopQrPagePolling();
            _qrPagePollTimer = setInterval(_qrPagePollTick, 5000);
        }
        function stopQrPagePolling() {
            if (_qrPagePollTimer) { clearInterval(_qrPagePollTimer); _qrPagePollTimer = null; }
            _qrPagePollInFlight = false;
        }

        function _qrPagePollTick() {
            if (_qrPagePollInFlight) return;
            var page = document.getElementById('qr-page');
            if (!page || !page.classList.contains('active-page')) return;        
            if (document.visibilityState && document.visibilityState !== 'visible') return;  
            if (!Array.isArray(qrPageData) || !qrPageData.length) return;         

            _qrPagePollInFlight = true;
            var site = getEffectiveSiteCode();
            google.script.run
                .withSuccessHandler(function (ids) {
                    _qrPagePollInFlight = false;
                    var awaiting = {};
                    (ids || []).forEach(function (id) { awaiting[id] = true; });
                     
                    var removed = qrPageData.filter(function (d) { return !awaiting[d.docId]; });
                    if (!removed.length) return;

                    removed.forEach(function (d) { _scannedDocIds[d.docId] = true; });
                    qrPageData = qrPageData.filter(function (d) { return awaiting[d.docId]; });
                    connextCache.set('approvedDocs', [site], qrPageData, CACHE_TTL.approvedDocs);
                    renderQRCards();
                    updateNavBadges();

                    if (removed.length === 1) {
                        showToast('📦 ' + removed[0].docId + ' ถูกสแกนที่ประตู — ย้ายไปหน้าถ่ายรูปแล้ว', 'info');
                    } else {
                        showToast('📦 ' + removed.length + ' เอกสารถูกสแกนที่ประตูแล้ว', 'info');
                    }

                     
                    connextCache.invalidate('confirmableDocs');
                    if (typeof loadConfirmableDocuments === 'function') loadConfirmableDocuments({ force: true });
                })
                .withFailureHandler(function (err) {
                    _qrPagePollInFlight = false;
                    console.warn('[QrPagePoll] failed:', err);
                })
                .getAwaitingGateDocIds(site);
        }

         
         
         
         
         
         
        function startConfirmPagePolling() {
            stopConfirmPagePolling();
             
            _confirmPollSeenIds = {};
            (confirmDocsData || []).forEach(function (d) { _confirmPollSeenIds[d.id] = true; });
            _confirmPollTimer = setInterval(_confirmPagePollTick, 5000);
        }
        function stopConfirmPagePolling() {
            if (_confirmPollTimer) { clearInterval(_confirmPollTimer); _confirmPollTimer = null; }
            _confirmPollInFlight = false;
            hideConfirmNewBanner();
        }

         
        function _confirmNotesFocused() {
            var a = document.activeElement;
            return !!(a && a.id === 'cwNotesInput');
        }

        function _confirmPagePollTick() {
            if (_confirmPollInFlight || _confirmFetchInFlight) return;
            var page = document.getElementById('confirm-page');
            if (!page || !page.classList.contains('active-page')) return;              
            if (document.visibilityState && document.visibilityState !== 'visible') return;  

            _confirmPollInFlight = true;
            var site = getEffectiveSiteCode();
            google.script.run
                .withSuccessHandler(function (ids) {
                    _confirmPollInFlight = false;
                    if (!Array.isArray(ids)) return;
                     
                    var newIds = ids.filter(function (id) { return id && !_confirmPollSeenIds[id]; });
                     
                    ids.forEach(function (id) { _confirmPollSeenIds[id] = true; });
                    if (!newIds.length) return;

                     
                    if (_confirmNotesFocused() || _confirmImgBusy) {
                        showConfirmNewBanner();
                        return;
                    }
                     
                    _confirmFetchAndApply(false);
                })
                .withFailureHandler(function (err) {
                    _confirmPollInFlight = false;
                    console.warn('[ConfirmPoll] failed:', err);
                })
                .getConfirmableGateSignature(site);
        }

         
         
        function _confirmFetchAndApply(viaBanner) {
            if (_confirmFetchInFlight) return;
            _confirmFetchInFlight = true;
            var site = getEffectiveSiteCode();
            var userName = getEffectiveUserName();
            var roleName = user ? user.role : '';
            google.script.run
                .withSuccessHandler(function (resp) {
                    _confirmFetchInFlight = false;
                    connextCache.set('confirmableDocs', [site, userName, roleName], resp, CACHE_TTL.confirmableDocs);
                    var added = _applyConfirmableResponse(resp);
                    hideConfirmNewBanner();
                    if (added > 0) {
                        showToast('📸 มีเอกสารใหม่ ' + added + ' ใบ เข้ามารอถ่ายรูปยืนยัน', 'info');
                    }
                })
                .withFailureHandler(function (err) {
                    _confirmFetchInFlight = false;
                    console.warn('[ConfirmFetch] failed:', err);
                    if (viaBanner) showToast('โหลดเอกสารใหม่ไม่สำเร็จ ลองอีกครั้ง', 'warning');
                })
                .getConfirmableDocuments(site, userName, roleName);
        }

        function showConfirmNewBanner() {
            var el = document.getElementById('confirmNewBanner');
            if (el) el.style.display = 'flex';
        }
        function hideConfirmNewBanner() {
            var el = document.getElementById('confirmNewBanner');
            if (el) el.style.display = 'none';
        }
         
        function loadConfirmNewDocs() {
            hapticTap();
            _confirmFetchAndApply(true);
        }

         
         
         
         
         
         
         
         
        function _syncConfirmClosePoll() {
            if (_confirmIsClosing()) {
                if (!_confirmClosePollTimer) _startConfirmClosePolling();
            } else {
                if (_confirmClosePollTimer) _stopConfirmClosePolling();
            }
        }
        function _startConfirmClosePolling() {
            _stopConfirmClosePolling();
            _confirmClosePollTimer = setInterval(_confirmClosePollTick, 3000);
        }
        function _stopConfirmClosePolling() {
            if (_confirmClosePollTimer) { clearInterval(_confirmClosePollTimer); _confirmClosePollTimer = null; }
            _confirmClosePollInFlight = false;
        }

        function _confirmClosePollTick() {
            if (_confirmClosePollInFlight) return;
            if (!_confirmIsClosing()) { _stopConfirmClosePolling(); return; }
             
            if (document.visibilityState && document.visibilityState !== 'visible') return;

            _confirmClosePollInFlight = true;
            var ids = confirmClosingDocs.map(function (c) { return c.id; });
            google.script.run
                .withSuccessHandler(function (stateMap) {
                    _confirmClosePollInFlight = false;
                    stateMap = stateMap || {};
                    var stillWaiting = [];
                    var changed = false;
                    confirmClosingDocs.forEach(function (c) {
                        var st = stateMap[c.id] || {};
                         
                         
                        if (st.done) {
                            _confirmClosedSeen[c.id] = true;    
                            changed = true;
                            return;
                        }
                        var g = (st.gate || '').toString().trim();
                        var d = (st.doc  || '').toString().trim();
                        if (g && g !== c.status)        { c.status = g;    changed = true; }   
                        if (d !== (c.docStatus || ''))  { c.docStatus = d; changed = true; }   
                        stillWaiting.push(c);
                    });
                    confirmClosingDocs = stillWaiting;

                    if (!_confirmIsClosing()) {
                         
                         
                        _stopConfirmClosePolling();
                        renderPickSummary();
                        renderConfirmWizard();
                        updateNavBadges();
                        showToast('🔓 ประตูปิด + ปิดงานเอกสารแล้ว — ถ่ายรูปชุดถัดไปได้เลย', 'success');
                        _confirmFetchAndApply(false);
                    } else if (changed) {
                        renderConfirmWizard();    
                    }
                })
                .withFailureHandler(function (err) {
                    _confirmClosePollInFlight = false;
                    console.warn('[ConfirmClosePoll] failed:', err);
                })
                .getDocsCloseState(ids);
        }

         
         
         
         
         
         
        var _approvePollTimer    = null;
        var _approvePollInFlight = false;

        function _approvalSignature(list) {
            return (list || []).map(function (it) {
                var qs = (it.items || []).map(function (r) { return (r.matCode || '') + '=' + (r.qty || 0) + '/' + (r.avail === undefined ? '' : r.avail); }).join(',');   // [2026-10-06 rev2]
                return (it.id || '') + ':' + (it.status || '') + ':' + (it.canAct ? 1 : 0) + ':' + (it.approver || '') + ':' + (it.stockShort ? 1 : 0) + ':' + qs;
            }).sort().join('|');
        }

        function startApprovePagePolling() {
            stopApprovePagePolling();
            _approvePollTimer = setInterval(_approvePagePollTick, 6000);
        }
        function stopApprovePagePolling() {
            if (_approvePollTimer) { clearInterval(_approvePollTimer); _approvePollTimer = null; }
            _approvePollInFlight = false;
        }

        function _approvePagePollTick() {
            if (_approvePollInFlight) return;
            var page = document.getElementById('approve-page');
            if (!page || !page.classList.contains('active-page')) return;              
            if (document.visibilityState && document.visibilityState !== 'visible') return;  

            _approvePollInFlight = true;
            var roleLevel = user ? user.roleLevel : '';
            var site  = getEffectiveSiteCode();
            var uname = getEffectiveUserName();
            google.script.run
                .withSuccessHandler(function (response) {
                    _approvePollInFlight = false;
                    var data = response && response.data ? response.data : [];
                    if (_approvalSignature(data) === _approvalSignature(approvalQueueData)) return;  
                    var prevCount = (approvalQueueData || []).length;
                    approvalQueueData = data;
                    try { if (CACHE_TTL && CACHE_TTL.approvalRequests) connextCache.set('approvalRequests', [roleLevel, site, uname], response, CACHE_TTL.approvalRequests); } catch (e) {}
                    renderApprovalQueue();
                    updateNavBadges();
                    var diff = data.length - prevCount;
                    if (diff > 0) showToast('🔔 มีคำขอรออนุมัติเพิ่ม ' + diff + ' รายการ', 'info');
                })
                .withFailureHandler(function (err) {
                    _approvePollInFlight = false;
                    console.warn('[ApprovePoll] failed:', err);
                })
                .getApprovalRequests(roleLevel, site, uname);
        }

        function setQRFilter(mode, btnElement) {
            qrFilterMode = mode || 'all';
            document.querySelectorAll('.qr-tab').forEach(function (btn) {
                btn.classList.remove('active');
            });
            if (btnElement) btnElement.classList.add('active');
            renderQRCards();
        }

        function openQRByIndex(index) {
            var doc = qrPageData[index];
            if (!doc) return;
            showQRModal(doc.docId, doc.type, doc.items || [], doc.receiver || '', doc.reqName || '');
        }

         
         
         
        function clearQRFilters() {
            hapticTap();
            qrFilterMode = 'all';
            document.querySelectorAll('.qr-tab').forEach(function (btn) {
                btn.classList.toggle('active', btn.textContent.trim().indexOf('ทั้งหมด') !== -1);
            });
            var reqSel = document.getElementById('qrReqSelect');
            if (reqSel) reqSel.value = '';
            var searchInput = document.getElementById('qrSearchInput');
            if (searchInput) searchInput.value = '';
            renderQRCards();
        }

         
         
        var _qrCollapsed = window._qrCollapsed || (window._qrCollapsed = {});
        function toggleQrGate(gateKey) {
            hapticTap();
            var k = 'gate:' + gateKey;
            _qrCollapsed[k] = !_qrCollapsed[k];
            var el = document.querySelector('.qr-gate-group[data-gate="' + CSS.escape(gateKey) + '"]');
            if (el) el.classList.toggle('collapsed', !!_qrCollapsed[k]);
        }
        function toggleQrType(gateKey, type) {
            hapticTap();
            var k = 'type:' + gateKey + '|' + type;
            _qrCollapsed[k] = !_qrCollapsed[k];
            var el = document.querySelector('.qr-type-section[data-gate="' + CSS.escape(gateKey) + '"][data-type="' + CSS.escape(type) + '"]');
            if (el) el.classList.toggle('collapsed', !!_qrCollapsed[k]);
        }

         
         
         
        function _gateFromDocId(docId) {
            if (!docId) return '';
            var clean = docId.toString().replace(/RT$/, '');
            var m = clean.match(/G(\d+)$/);
            return m ? 'G' + m[1] : '';
        }

         
        function _qrCardStatusHtml(docId) {
            if (docId && _scannedDocIds[docId]) {
                return '<span class="qrc-status st-scanned"><i class="fa-solid fa-circle-check"></i> สแกนแล้ว — ไปถ่ายรูปยืนยัน</span>';
            }
            if (docId && _qrViewedDocIds[docId]) {
                return '<span class="qrc-status st-viewed"><i class="fa-solid fa-qrcode"></i> เปิด QR แล้ว — รอสแกนที่ประตู</span>';
            }
            return '<span class="qrc-status st-wait"><i class="fa-solid fa-hourglass-half"></i> รอสแกนที่ประตู</span>';
        }

         
         
         
         
        function populateQrReqFilter(visibleDocs) {
            var sel = document.getElementById('qrReqSelect');
            if (!sel) return;
            var current = sel.value || '';
            var seen = {};
            var items = [];  
            (visibleDocs || []).forEach(function (doc) {
                var u = (doc.reqName || '').toString().trim();
                if (!u || seen[u]) return;
                seen[u] = true;
                items.push({ username: u, fullName: userFullName(u) });
            });
            items.sort(function (a, b) { return (a.fullName || '').localeCompare(b.fullName || '', 'th'); });
            var opts = '<option value="">-- กรองผู้ขอเบิก --</option>';
            items.forEach(function (it) {
                opts += '<option value="' + escapeHtml(it.username) + '">' + escapeHtml(it.fullName) + '</option>';
            });
            sel.innerHTML = opts;
            if (current && seen[current]) sel.value = current;
        }

        function renderQRCards() {
            var grid = document.getElementById('qrCardsGrid');
            if (!grid) return;
            var search = ((document.getElementById('qrSearchInput') || {}).value || '').toLowerCase();
            var reqFilter = ((document.getElementById('qrReqSelect') || {}).value || '');

            var currentUsername  = user ? (user.username  || '') : '';
            var currentSiteCode  = user ? (user.siteCode  || '') : '';
            var currentRoleLevel = user ? (user.roleLevel || '') : '';
            var currentRoleName  = user ? ((user.role || '')).toLowerCase() : '';
            var roleNum = parseInt((currentRoleLevel.match(/\d+/) || ['0'])[0]);
             
             
            var isSiteManager = roleNum >= 8 || currentRoleName.indexOf('store') !== -1 || (user && user.canReq === true);

             
             
            var visibleByPerm = qrPageData.filter(function (doc) {
                if (isSiteManager) {
                    if (currentSiteCode && (doc.siteCode || '') !== currentSiteCode) return false;
                } else {
                    if (currentUsername && (doc.reqName || '').toLowerCase() !== currentUsername.toLowerCase()) return false;
                }
                return true;
            });
            populateQrReqFilter(visibleByPerm);

             
            var filtered = visibleByPerm.filter(function (doc) {
                if (qrFilterMode !== 'all' && doc.type !== qrFilterMode) return false;
                if (reqFilter && (doc.reqName || '') !== reqFilter) return false;
                if (search) {
                    var inId = (doc.docId || '').toLowerCase().indexOf(search) !== -1;
                    var inMat = (doc.items || []).some(function (i) {
                        return (i.matCode || '').toLowerCase().indexOf(search) !== -1 || (i.matName || '').toLowerCase().indexOf(search) !== -1;
                    });
                    if (!inId && !inMat) return false;
                }
                return true;
            });

            var totalItems = qrPageData.reduce(function (sum, doc) {
                return sum + ((doc.items || []).length);
            }, 0);

            if (document.getElementById('qrSummaryTotal')) document.getElementById('qrSummaryTotal').textContent = qrPageData.length.toLocaleString();
            if (document.getElementById('qrSummaryItems')) document.getElementById('qrSummaryItems').textContent = totalItems.toLocaleString();
            if (document.getElementById('qrSummaryFiltered')) document.getElementById('qrSummaryFiltered').textContent = filtered.length.toLocaleString();

            if (!filtered.length) {
                grid.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-qrcode"></i><span>ไม่มีเอกสารที่รออนุมัติที่ประตูในขณะนี้</span></div>';
                return;
            }

             
            var groups = {};
            var gateOrder = [];
            filtered.forEach(function (doc) {
                var g = _gateFromDocId(doc.docId) || '—';
                if (!groups[g]) {
                    groups[g] = [];
                    gateOrder.push(g);
                }
                groups[g].push(doc);
            });
             
            gateOrder.sort(function (a, b) {
                if (a === '—') return 1;
                if (b === '—') return -1;
                var an = parseInt(a.replace(/\D/g, ''), 10) || 0;
                var bn = parseInt(b.replace(/\D/g, ''), 10) || 0;
                return an - bn;
            });

             
             
             
            var TYPE_ORDER = ['RD', 'OD', 'BD', 'TD', 'IN'];
            var TYPE_LABEL = {
                RD: { name: 'เบิกวัสดุหลัก',    icon: 'fa-boxes-stacked'      },
                OD: { name: 'เบิกเบ็ดเตล็ด',    icon: 'fa-screwdriver-wrench' },
                BD: { name: 'ยืม-คืน อุปกรณ์',  icon: 'fa-handshake'          },
                TD: { name: 'โอนย้ายข้ามไซต์',  icon: 'fa-truck-arrow-right'  },   // [2026-09-29] js/transfer.js
                IN: { name: 'รับเข้าคลัง',      icon: 'fa-truck-ramp-box'     }
            };

             
            grid.innerHTML = gateOrder.map(function (gateKey) {
                var docs = groups[gateKey];
                var gateName = (gateKey !== '—' && globalGateMap[gateKey]) ? globalGateMap[gateKey] : '';
                var gateLabel = gateKey === '—'
                    ? 'ไม่ระบุประตู'
                    : 'ประตู ' + gateKey + (gateName && gateName !== gateKey ? ' — ' + gateName : '');

                 
                 
                var byType = {};
                docs.forEach(function (d) {
                    var t = d.type || 'OTHER';
                    if (!byType[t]) byType[t] = [];
                    byType[t].push(d);
                });
                var typeOrder = TYPE_ORDER.filter(function (t) { return byType[t] && byType[t].length; });
                 
                Object.keys(byType).forEach(function (t) {
                    if (typeOrder.indexOf(t) === -1) typeOrder.push(t);
                });

                var renderOneCard = function (doc) {
                    var dataIndex = qrPageData.indexOf(doc);
                     
                     
                    var itemsHtml = (doc.items || []).map(function (it) {
                        var qtyText = (it.qty || 0) + (it.unit ? ' ' + escapeHtml(it.unit) : '');
                        return '<div class="qrc-item">' +
                                '<div class="qrc-item-info">' +
                                    '<div class="qrc-item-code">' + escapeHtml(it.matCode || '-') + '</div>' +
                                    '<div class="qrc-item-name">' + escapeHtml(it.matName || '-') + '</div>' +
                                '</div>' +
                                '<div class="qrc-item-qty">' + escapeHtml(qtyText) + '</div>' +
                            '</div>';
                    }).join('');

                     
                     
                     
                     
                     
                    var isOwner = currentUsername && (doc.reqName || '').toLowerCase() === currentUsername.toLowerCase();
                    var cancelBtn = (isOwner && !doc.isReturn)
                        ? '<button class="btn btn-danger" onclick="cancelMyRequisition(' + dataIndex + ')"><i class="fa-solid fa-ban"></i> ยกเลิก</button>'
                        : '';

                    var receiverLabel = doc.type === 'IN' ? 'RS' : (doc.isReturn ? 'ผู้คืน' : 'ผู้รับ');
                    var returnBadge = doc.isReturn
                        ? '<span class="qrc-return-badge"><i class="fa-solid fa-rotate-left"></i> คืน</span>'
                        : '';

                    return '<div class="qr-doc-card">' +
                         
                        '<div class="qrc-section qrc-header qrc-divider">' +
                            '<div>' +
                                '<div class="qrc-doc-id">' + escapeHtml(doc.docId || '-') + '</div>' +
                                '<div class="qrc-date">' + escapeHtml(doc.dateStr || '-') + '</div>' +
                                _qrCardStatusHtml(doc.docId) +
                            '</div>' +
                            '<div>' + returnBadge + getTypeBadge(doc.type || '-') + '</div>' +
                        '</div>' +
                         
                        '<div class="qrc-section qrc-divider">' +
                            '<div class="qrc-meta-row">' +
                                '<span class="qrc-label">' + receiverLabel + ':</span>' +
                                '<span class="qrc-value">' + escapeHtml(doc.type === 'IN' ? (doc.receiver || '-') : getReceiverDisplayName(doc.receiver || '')) + '</span>' +
                            '</div>' +
                            '<div class="qrc-meta-row">' +
                                '<span class="qrc-label">ผู้ขอ:</span>' +
                                '<span class="qrc-value">' + escapeHtml(userFullName(doc.reqName) || '-') + '</span>' +
                            '</div>' +
                        '</div>' +
                         
                        '<div class="qrc-section qrc-divider">' + itemsHtml + '</div>' +
                         
                        '<div class="qrc-section qrc-actions">' +
                            cancelBtn +
                            '<button class="btn btn-primary" onclick="openQRByIndex(' + dataIndex + ')"><i class="fa-solid fa-qrcode"></i> ดู QR</button>' +
                        '</div>' +
                    '</div>';
                };

                 
                 
                 
                var typeSectionsHtml = typeOrder.map(function (t) {
                    var meta = TYPE_LABEL[t] || { name: t, icon: 'fa-folder' };
                    var typeDocs = byType[t];
                    var cardsHtml = typeDocs.map(renderOneCard).join('');
                    var typeCollapsedClass = _qrCollapsed['type:' + gateKey + '|' + t] ? ' collapsed' : '';
                    return '<div class="qr-type-section' + typeCollapsedClass + '" data-gate="' + escapeHtml(gateKey) + '" data-type="' + escapeHtml(t) + '">' +
                        '<div class="qr-type-subheader" onclick="toggleQrType(\'' + gateKey.replace(/\'/g, '') + '\',\'' + t + '\')">' +
                            '<i class="fa-solid ' + meta.icon + '"></i>' +
                            '<span>' + escapeHtml(meta.name) + ' (' + t + ')</span>' +
                            '<span class="qr-type-count">' + typeDocs.length + ' รายการ</span>' +
                            '<i class="fa-solid fa-chevron-down qr-type-chevron"></i>' +
                        '</div>' +
                        '<div class="qr-cards-grid">' + cardsHtml + '</div>' +
                    '</div>';
                }).join('');

                 
                 
                var gateCollapsedClass = _qrCollapsed['gate:' + gateKey] ? ' collapsed' : '';
                return '<div class="qr-gate-group' + gateCollapsedClass + '" data-gate="' + escapeHtml(gateKey) + '">' +
                    '<div class="qr-gate-header collapsible" onclick="toggleQrGate(\'' + gateKey.replace(/\'/g, '') + '\')">' +
                        '<div class="qr-gate-icon"><i class="fa-solid fa-door-open"></i></div>' +
                        '<div>' +
                            '<div class="qr-gate-title">' + escapeHtml(gateLabel) + '</div>' +
                            '<div class="qr-gate-sub">' + docs.length + ' เอกสารรอที่ประตูนี้</div>' +
                        '</div>' +
                        '<div class="qr-gate-count">' + docs.length + '</div>' +
                        '<i class="fa-solid fa-chevron-down qr-gate-chevron"></i>' +
                    '</div>' +
                    typeSectionsHtml +
                '</div>';
            }).join('');
        }

         
         
        function cancelMyRequisition(index) {
            var doc = qrPageData[index];
            if (!doc) return;
             
            var me = user ? (user.username || '') : '';
            if (!me || (doc.reqName || '').toLowerCase() !== me.toLowerCase()) {
                showInfoPopup('ไม่อนุญาต', 'คุณยกเลิกได้เฉพาะเอกสารที่ตัวเองส่ง', 'warning');
                return;
            }
            showConfirmPopup(
                'ยืนยันยกเลิกเอกสาร',
                'ยกเลิกเอกสาร ' + (doc.docId || '') + '?\nสต๊อกที่ถูกตัดไปแล้วจะถูกคืนกลับ',
                function () {
                    showLoadingPopup('กำลังยกเลิก...', 'อัปเดตสต๊อกและสถานะ');
                    google.script.run
                        .withSuccessHandler(function (res) {
                            closeAppPopup();
                            if (res && res.success) {
                                hapticSuccess();
                                invalidateAfterWrite('approval');
                                showInfoPopup('✅ ยกเลิกสำเร็จ', res.message || 'ยกเลิกเอกสารเรียบร้อย', 'success', function () {
                                    loadQRPage({ force: true });
                                    loadDashboard({ force: true });
                                    loadApprovalQueue({ force: true });
                                    loadConfirmableDocuments({ force: true });
                                });
                            } else {
                                showInfoPopup('ยกเลิกไม่สำเร็จ', (res && res.message) || 'ไม่สามารถยกเลิกได้', 'danger');
                            }
                        })
                        .withFailureHandler(function (err) {
                            closeAppPopup();
                            showInfoPopup('เชื่อมต่อไม่สำเร็จ', (err && err.message) || String(err), 'danger');
                        })
                        .cancelRequisition(doc.docId, doc.type, me);
                },
                'ยืนยันยกเลิก',
                'btn btn-danger'
            );
        }

        var dashboardData = [];
        var dashboardFilterMode = 'all';
        var dashboardCurrentPage = 1;
        var dashboardPageSize = 25;
         
        var dashboardSelectedSubgroup = '';
         
        var dashboardSelectedSite = null;
        var dashboardSiteFilterInitialized = false;

         
        function canFilterDashboardSite() {
            if (!user) return false;
            if (user.roleLevel === 'R0') return true;
            return getRoleNumber(user.roleLevel) >= 8;
        }

         
         
        function getDashboardSiteFilter() {
            if (!user) return '';
            if (canFilterDashboardSite()) {
                if (dashboardSelectedSite !== null) return dashboardSelectedSite;
                 
                return user.roleLevel === 'R0' ? '' : (user.siteCode || '');
            }
            return getEffectiveSiteCode();
        }

        function initDashboardSiteFilter() {
            var wrap   = document.getElementById('dashSiteFilterWrap');
            var select = document.getElementById('dashSiteFilter');
            if (!wrap || !select) return;

            if (!canFilterDashboardSite()) {
                wrap.style.display = 'none';
                return;
            }

            wrap.style.display = '';
            if (dashboardSiteFilterInitialized) return;

             
            var defaultSite = user.roleLevel === 'R0' ? '' : (user.siteCode || '');
            dashboardSelectedSite = defaultSite;

            google.script.run
                .withSuccessHandler(function (sites) {
                    select.innerHTML = '<option value="">-- ทุก Site --</option>';
                    (sites || []).forEach(function (s) {
                        var opt = document.createElement('option');
                        opt.value = s.siteCode;
                        opt.textContent = s.siteName + ' (' + s.siteCode + ')';
                        select.appendChild(opt);
                    });
                    select.value = defaultSite;
                    dashboardSiteFilterInitialized = true;
                })
                .withFailureHandler(function (err) {
                    console.error('initDashboardSiteFilter error:', err);
                })
                .getAvailableSites();
        }

        function changeDashboardSite() {
            var select = document.getElementById('dashSiteFilter');
            if (!select) return;
            dashboardSelectedSite = select.value;
            dashboardCurrentPage = 1;
            loadDashboard();
        }

         
         
         
         
         
        function getRowBalance(row) {
            var onHand = row['OnHand'];
            if (onHand !== undefined && onHand !== null && onHand !== '') {
                return parseFloat(onHand) || 0;
            }
            var inVal = parseFloat(row['In']) || 0;
            var pendingVal = parseFloat(row['Pending']) || 0;
            var outVal = parseFloat(row['Out']) || 0;
            return inVal - pendingVal - outVal;
        }

        function loadDashboard(opts) {
            initDashboardSiteFilter();

             
             
            if (opts && opts.force) { mdGateRows = null; mdGateSite = null; }


            loadPendingDocs(opts);
            loadLegacyMangoNotice(opts);

            var tbody = document.getElementById('inventoryTableBody');
            var info  = document.getElementById('dashPaginationInfo');
            var siteFilter = getDashboardSiteFilter();

            if (!connextCache.get('balance', [siteFilter])) {
                if (tbody) tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;"><i class="fa-solid fa-spinner fa-spin"></i> กำลังโหลดข้อมูล...</td></tr>';
                if (info)  info.textContent = 'กำลังโหลดข้อมูล...';
            }

            connextCache.swr('balance', [siteFilter],
                function (done, fail) {
                    google.script.run.withSuccessHandler(done).withFailureHandler(fail).getBalanceList(siteFilter);
                },
                function (rows) {
                    dashboardData = rows || [];
                    populateDashboardSubgroupFilter();
                    renderDashboardMetrics();
                    renderDashboardTable();
                },
                function (err) {
                    console.error('loadDashboard error:', err);
                    if (tbody) tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;color:var(--danger);">โหลดข้อมูล Dashboard ไม่สำเร็จ</td></tr>';
                    if (info)  info.textContent = 'โหลดข้อมูลไม่สำเร็จ';
                },
                opts
            );
        }

         
         
         
         
         
         
        var pendingDocsData = [];

        function loadPendingDocs(opts) {
            var site = getDashboardSiteFilter();
            connextCache.swr('approvedDocs', [site],
                function (done, fail) {
                    google.script.run.withSuccessHandler(done).withFailureHandler(fail).getApprovedDocuments(site);
                },
                function (response) {
                    pendingDocsData = response && response.data ? response.data : [];
                    var el = document.getElementById('dashPendingDocs');
                    if (el) el.textContent = pendingDocsData.length.toLocaleString();
                     
                    var backdrop = document.getElementById('pendingDocsBackdrop');
                    if (backdrop && backdrop.classList.contains('open')) renderPendingDocsList();
                },
                function (err) { console.warn('loadPendingDocs error:', err); },
                opts
            );
        }

        // [PHP port 2026-09-23 · มติ 34] ตาราง/ฟอร์มแสดงเฉพาะรหัส IC แล้ว — ยอดที่ยังค้างบนรหัส Mango
        // (ยังไม่ผูก IC/ย้ายยอด) จึงขึ้นเป็นแถบเตือนเหนือตารางแทน ไม่ให้ของหายเงียบ
        function loadLegacyMangoNotice(opts) {
            var site = getDashboardSiteFilter();
            connextCache.swr('legacyMango', [site],
                function (done, fail) {
                    google.script.run.withSuccessHandler(done).withFailureHandler(fail).getLegacyMangoStock(site);
                },
                function (res) { renderLegacyMangoNotice(res); },
                function (err) { console.warn('loadLegacyMangoNotice error:', err); },
                opts
            );
        }

        function renderLegacyMangoNotice(res) {
            var box = document.getElementById('dashLegacyMango');
            if (!box) return;
            var items = (res && res.items) || [];
            if (!items.length) { box.style.display = 'none'; box.innerHTML = ''; return; }
            var allSites = getDashboardSiteFilter() === '';
            var list = items.map(function (it) {
                var onHand = parseFloat(it.OnHand) || 0;
                var pending = parseFloat(it.Pending) || 0;
                var unit = it.Unit ? ' ' + it.Unit : '';
                return '<li><span style="font-family:monospace;">' + escapeHtml(it.MatCode) + '</span> ' +
                    escapeHtml(it.Name || '') + ' — คงเหลือ <b>' + escapeHtml(formatBalanceValue(onHand) + unit) + '</b>' +
                    (pending ? ' · จอง ' + escapeHtml(formatBalanceValue(pending) + unit) : '') +
                    (allSites && it.SiteCode ? ' · ' + escapeHtml(it.SiteCode) : '') + '</li>';
            }).join('');
            var fix = (typeof _canSeeCnxAdmin === 'function' && _canSeeCnxAdmin())
                ? ' <a href="#" onclick="openSetupMasterFromDashboard(); return false;" style="color:inherit; text-decoration:underline;">เปิดหน้าจัดการรหัสวัสดุ</a>'
                : ' — แจ้งผู้ดูแลระบบให้ผูกรหัส IC แล้วย้ายยอด';
            box.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i><div>' +
                'ยังมี ' + items.length.toLocaleString() + ' รายการเป็นรหัส Mango ที่ยังไม่ย้ายเป็นรหัส IC — ไม่แสดงในตารางด้านล่างและยังเบิกไม่ได้' + fix +
                '<details style="margin-top:0.35rem;"><summary style="cursor:pointer;">ดูรายการ</summary>' +
                '<ul style="margin:0.35rem 0 0 1.2rem; font-weight:400;">' + list + '</ul></details></div>';
            box.style.display = 'flex';
        }

        function openSetupMasterFromDashboard() {
            if (typeof switchToPage === 'function') switchToPage('cnxadmin');
            var f = document.getElementById('cnxadminFrame');
            if (!f) return;
            f.setAttribute('data-loaded', '1');
            f.style.height = '';
            f.src = WEBAPP_URL.replace(/index\.php$/, '') + 'setup_master.php?embed=1';
        }

        function openPendingDocsModal() {
            hapticTap();
            renderPendingDocsList();
            document.getElementById('pendingDocsBackdrop').classList.add('open');
             
            loadPendingDocs({ force: true });
        }

        function closePendingDocsModal() {
            document.getElementById('pendingDocsBackdrop').classList.remove('open');
        }

        function renderPendingDocsList() {
            var wrap = document.getElementById('pendingDocsList');
            if (!wrap) return;
            var countEl = document.getElementById('pendingDocsCount');
            if (countEl) countEl.textContent = pendingDocsData.length.toLocaleString();

            if (!pendingDocsData.length) {
                wrap.innerHTML = '<div class="qr-empty" style="padding:1.5rem 1rem;"><i class="fa-solid fa-mug-hot"></i><span>ไม่มีเอกสารรอเบิกในขณะนี้</span></div>';
                return;
            }

            wrap.innerHTML = pendingDocsData.map(function (doc) {
                var items = (doc.items || []).map(function (it) {
                    var unit = it.unit || ((globalBalance[it.matCode] || {}).Unit) || '';
                    return escapeHtml(it.matName || it.matCode || '-') +
                        ' <b>x' + escapeHtml(String(it.qty || 0)) + (unit ? ' ' + escapeHtml(unit) : '') + '</b>';
                }).join(', ');
                var receiverLabel = doc.type === 'IN' ? 'RS' : 'ผู้รับ';
                var receiverVal = doc.type === 'IN'
                    ? (doc.receiver || '-')
                    : (getReceiverDisplayName(doc.receiver || '') || '-');
                var gate = _gateFromDocId(doc.docId) || '—';
                return '<div class="pd-doc">' +
                    '<div class="pd-doc-head">' +
                        '<span class="pd-doc-id">' + escapeHtml(doc.docId || '-') + '</span>' +
                        getTypeBadge(doc.type || '-') +
                    '</div>' +
                    '<div class="pd-doc-meta">' + escapeHtml(doc.dateStr || '-') + ' · ประตู ' + escapeHtml(gate) + '</div>' +
                    '<div class="pd-doc-meta">ผู้ขอ: <b>' + escapeHtml(userFullName(doc.reqName) || '-') + '</b> · ' + receiverLabel + ': ' + escapeHtml(receiverVal) + '</div>' +
                    '<div class="pd-doc-items">' + items + '</div>' +
                '</div>';
            }).join('');
        }

         
         
         
         
        function isLowStockRow(row) {
            var balance = getRowBalance(row);
            var charId = (row['CharID'] || '').toString().trim().toUpperCase();
            return (charId === 'CSB' || charId === 'NAR') && balance > 0 && balance <= 5;
        }

        function renderDashboardMetrics() {
            var totalItems = dashboardData.length;
            var outOfStock = 0;
            var lowStockC01 = 0;
            var lowStockNon = 0;
            var wmsItems = 0;

            dashboardData.forEach(function (row) {
                var balance = getRowBalance(row);
                var catId = (row['CatID'] || '').toString().trim();
                var charId = (row['CharID'] || '').toString().trim().toUpperCase();
                if (balance <= 0) outOfStock += 1;
                if (isLowStockRow(row)) {
                    if (catId === 'C01') lowStockC01 += 1;
                    else lowStockNon += 1;
                }
                if (charId === 'WMS') wmsItems += 1;
            });

             
             
            var totalEl = document.getElementById('dashTotalItems');
            var outEl = document.getElementById('dashOutOfStock');
            var lowTotalEl = document.getElementById('dashLowStockTotal');
            var lowC01El = document.getElementById('dashLowStockC01');
            var lowNonEl = document.getElementById('dashLowStockNon');
            var wmsEl = document.getElementById('dashWmsCount');
            if (totalEl) totalEl.textContent = totalItems.toLocaleString();
            if (outEl) outEl.textContent = outOfStock.toLocaleString();
            if (lowTotalEl) lowTotalEl.textContent = (lowStockC01 + lowStockNon).toLocaleString();
            if (lowC01El) lowC01El.textContent = lowStockC01.toLocaleString();
            if (lowNonEl) lowNonEl.textContent = lowStockNon.toLocaleString();
            if (wmsEl) wmsEl.textContent = wmsItems.toLocaleString();
        }

         
         
         
         
        // [PHP port 2026-09-25 per-gate · มติ 51] คอลัมน์ Gate ของ Dashboard = ยอดรายประตูจากคีย์ Gates ของ getBalanceList
        // (เดิมโชว์แค่ประตูตั้งต้นของวัสดุ ทั้งที่ของอาจอยู่หลายประตู)
        function _dashGatesWithStock(row) {
            return ((row && row['Gates']) || []).filter(function (g) {
                return (parseFloat(g.OnHand) || 0) > 0 || (parseFloat(g.Pending) || 0) > 0;
            });
        }
        function _dashGateCellHtml(row) {
            var gates = _dashGatesWithStock(row);
            var unit  = (row && row['Unit'] && row['Unit'] !== '-') ? row['Unit'] : '';
            if (!gates.length) {
                var d = (row && (row['GateName'] || row['GateID'])) || '';
                return d ? '<span class="dash-gate-chip is-default" title="ประตูตั้งต้นของวัสดุ (ยังไม่มีของในประตูใด)">' + escapeHtml(d) + '</span>' : '-';
            }
            return gates.map(function (g) {
                var on = parseFloat(g.OnHand) || 0, pen = parseFloat(g.Pending) || 0;
                var tip = (g.GateName ? g.GateID + ' · ' + g.GateName : g.GateID) + ' — ในคลัง ' + formatBalanceValue(on) + (unit ? ' ' + unit : '')
                        + (pen > 0 ? ' · จองไว้ ' + formatBalanceValue(pen) : '');
                return '<span class="dash-gate-chip' + (on > 0 ? '' : ' is-empty') + '" title="' + escapeHtml(tip) + '">'
                     + '<b>' + escapeHtml(g.GateID || '') + '</b>' + formatBalanceValue(on) + '</span>';
            }).join(' ');
        }
        function _dashGateSummaryText(row) {
            var gates = _dashGatesWithStock(row);
            if (!gates.length) return '';
            return gates.map(function (g) { return (g.GateID || '') + ' ' + formatBalanceValue(parseFloat(g.OnHand) || 0); }).join(' · ');
        }

        function openMaterialDetail(matCode, siteCode, _resynced) {
            if (!matCode) return;
            var row = null;
            for (var i = 0; i < (dashboardData || []).length; i++) {
                var d = dashboardData[i];
                if ((d['MatCode'] || '').toString() !== matCode.toString()) continue;
                 
                 
                if (siteCode && (d['SiteCode'] || '').toString() !== siteCode.toString()) continue;
                row = d;
                break;
            }
            if (!row) return;
            hapticTap();

            var inVal      = parseFloat(row['In']) || 0;
            var pendingVal = parseFloat(row['Pending']) || 0;
            var outVal     = parseFloat(row['Out']) || 0;
            var balance    = getRowBalance(row);
            var unit       = row['Unit'] || '-';
            var catId      = (row['CatID'] || '').toString().trim().toUpperCase();
            var gate       = row['GateName'] || row['GateID'] || '-';

             
            var banner = document.getElementById('mdStatusBanner');
            var title  = document.getElementById('mdStatusTitle');
            var sub    = document.getElementById('mdStatusSub');
            var icon   = banner.querySelector('i');
            banner.classList.remove('md-banner-ok', 'md-banner-warn', 'md-banner-out');
            if (balance <= 0) {
                banner.classList.add('md-banner-out');
                title.textContent = 'หมดสต๊อก';
                sub.textContent   = 'ต้องเติมสต๊อกก่อนใช้งาน';
                icon.className    = 'fa-solid fa-triangle-exclamation';
            } else if (balance <= 5) {
                banner.classList.add('md-banner-warn');
                title.textContent = 'ใกล้หมด';
                sub.textContent   = 'คงเหลือ ' + balance + ' ' + unit + ' — ควรเตรียมสั่งเพิ่ม';
                icon.className    = 'fa-solid fa-arrow-trend-down';
            } else {
                banner.classList.add('md-banner-ok');
                title.textContent = 'เพียงพอ';
                sub.textContent   = 'สต๊อกพร้อมใช้งาน';
                icon.className    = 'fa-solid fa-circle-check';
            }

            document.getElementById('mdMatCode').textContent = matCode;
            document.getElementById('mdMatName').textContent = row['Name'] || matCode;
            document.getElementById('mdGate').textContent    = _dashGateSummaryText(row) || gate;   // [มติ 51]
            document.getElementById('mdUnit').textContent    = unit;

            var pill = document.getElementById('mdCatPill');
            pill.classList.remove('cat-c01','cat-c02','cat-nar');
            pill.textContent = catId || 'อื่นๆ';
            if (catId === 'C01') pill.classList.add('cat-c01');
            else if (catId === 'C02') pill.classList.add('cat-c02');
            else pill.classList.add('cat-nar');

            document.getElementById('mdValIn').textContent       = inVal.toLocaleString();
            document.getElementById('mdValPending').textContent  = pendingVal.toLocaleString();
            document.getElementById('mdValOut').textContent      = outVal.toLocaleString();
            document.getElementById('mdValBalance').textContent  = balance.toLocaleString();

             
            // [2026-10-08] ยอดรวม (dashboardData) กับยอดรายประตูโหลดคนละเวลา — ผู้ใช้เปิดเอง = ล้างธงโหลดซ้ำ ·
            //   เปิดจากการโหลดซ้ำ (_resynced) = ดึงยอดรายประตูใหม่จาก server ด้วย (force)
            if (!_resynced) delete mdGateResynced[matCode + '|' + (row['SiteCode'] || '').toString()];
            openMdGateBreakdown(matCode, (row['SiteCode'] || '').toString(), unit, balance, !!_resynced);

            document.getElementById('mdModalBackdrop').classList.add('open');
        }

         
         
         
         
         
         
         
        var mdGateRows = null;    
        var mdGateSite = null;    
        var mdGateFor  = '';      
        var mdGateResynced = {};   // [2026-10-08] matCode|site → โหลดยอดล่าสุดแล้วรอบนี้ (กันวน)

        function openMdGateBreakdown(matCode, siteCode, unit, total, force) {
            var box  = document.getElementById('mdGateBox');
            var note = document.getElementById('mdGateNote');
            var cnt  = document.getElementById('mdGateCount');
            if (!box) return;

            mdGateFor = matCode + '|' + siteCode;
            var token = mdGateFor;
            if (cnt)  cnt.textContent = '';
            if (note) note.innerHTML  = '';
            box.innerHTML = '<div class="md-gate-empty"><i class="fa-solid fa-spinner fa-spin"></i> กำลังโหลดยอดรายประตู...</div>';

            var siteFilter = getDashboardSiteFilter();

             
             
             
            if (!force && mdGateRows && mdGateSite === siteFilter && connextCache.get('gatebalance', [siteFilter])) {
                renderMdGateBreakdown(token, matCode, siteCode, unit, total);
                return;
            }

            connextCache.swr('gatebalance', [siteFilter],
                function (done, fail) {
                    google.script.run.withSuccessHandler(done).withFailureHandler(fail).getGateBalanceList(siteFilter);
                },
                function (rows) {
                    mdGateRows = rows || [];
                    mdGateSite = siteFilter;
                    renderMdGateBreakdown(token, matCode, siteCode, unit, total);
                },
                function (err) {
                    console.warn('openMdGateBreakdown error:', err);
                    if (mdGateFor !== token) return;
                    box.innerHTML = '<div class="md-gate-empty" style="color:var(--danger);">'
                                  + '<i class="fa-solid fa-triangle-exclamation"></i> โหลดยอดรายประตูไม่สำเร็จ</div>';
                },
                force ? { force: true } : undefined
            );
        }

        function renderMdGateBreakdown(token, matCode, siteCode, unit, total) {
            if (mdGateFor !== token) return;    
            var box  = document.getElementById('mdGateBox');
            var note = document.getElementById('mdGateNote');
            var cnt  = document.getElementById('mdGateCount');
            if (!box) return;

            var rows = (mdGateRows || []).filter(function (r) {
                if ((r.MatCode || '').toString() !== matCode.toString()) return false;
                 
                if (siteCode && (r.SiteCode || '').toString() !== siteCode.toString()) return false;
                return true;
            }).sort(function (a, b) {
                return (a.GateID || '').toString().localeCompare((b.GateID || '').toString());
            });

            var unitTag = (unit && unit !== '-') ? unit : '';
            var unitTxt = unitTag ? ' ' + unitTag : '';
            var sum  = 0;
            var html = '';

            rows.forEach(function (r) {
                var onHand  = parseFloat(r.OnHand)  || 0;
                var pending = parseFloat(r.Pending) || 0;
                sum += onHand;
                var sub = pending > 0
                    ? 'จองไว้ ' + formatBalanceValue(pending) + unitTxt +
                      ' · พร้อมเบิก ' + formatBalanceValue(onHand - pending) + unitTxt
                    : 'พร้อมเบิกทั้งจำนวน';
                html += '<div class="md-gate-row' + (onHand > 0 ? '' : ' is-empty') + '">'
                      + '<div><div class="g-name">'
                      + escapeHtml((r.GateID || '') + (r.GateName ? ' · ' + r.GateName : ''))
                      + '</div><div class="g-sub">' + escapeHtml(sub) + '</div></div>'
                      + '<div class="g-num">' + formatBalanceValue(onHand)
                      + ' <span class="g-unit">' + escapeHtml(unitTag) + '</span></div>'
                      + '</div>';
            });

             
             
            var diff = (parseFloat(total) || 0) - sum;
            // [2026-10-08] ยอดรวมไม่เท่าผลรวมรายประตู — ส่วนใหญ่เพราะยอดรวมบนหน้านี้เป็นข้อมูลที่โหลดไว้ก่อน
            //   (เช่น ใบ IN / ใบเบิกเพิ่งปิดประตู) ส่วนยอดรายประตูเพิ่งโหลด → โหลดยอดล่าสุดทั้งสองชุด 1 ครั้งแล้วเปิดใหม่
            //   ยังไม่ตรงหลังโหลดใหม่ จึงขึ้นคำเตือน "ข้อมูลสองตารางไม่ตรงกัน" (เดิมเตือนทันที — IN08102601 · 8 ต.ค.)
            if (Math.abs(diff) > 0.001 && rows.length && !mdGateResynced[token]) {
                mdGateResynced[token] = true;
                if (note) note.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> กำลังโหลดยอดล่าสุด…';
                _mdResyncDashboard(matCode, siteCode, token);
            }
            if (diff > 0.001) {
                html += '<div class="md-gate-row is-unassigned">'
                      + '<div><div class="g-name">ยังไม่ระบุประตู</div>'
                      + '<div class="g-sub">ยอดที่เข้าระบบก่อนเปิดใช้การแยกรายประตู</div></div>'
                      + '<div class="g-num" style="color:#b45309;">' + formatBalanceValue(diff)
                      + ' <span class="g-unit">' + escapeHtml(unitTag) + '</span></div>'
                      + '</div>';
            }

            if (!html) {
                box.innerHTML = '<div class="md-gate-empty">ยังไม่มีข้อมูลรายประตูของวัสดุนี้</div>';
                if (cnt)  cnt.textContent = '';
                if (note) note.innerHTML  = '';
                return;
            }

            box.innerHTML = html;
            if (cnt) cnt.textContent = rows.length ? '(' + rows.length + ' ประตู)' : '';

            var msg;
            if (!rows.length) {
                msg = 'วัสดุนี้ยังไม่มียอดผูกกับประตูใดในไซต์นี้';
            } else if (diff > 0.001) {
                msg = 'มีบางส่วนยังไม่ผูกประตู — เกิดกับยอดที่เข้าระบบก่อนเปิดใช้การแยกรายประตู';
            } else {
                msg = 'ยอด Balance ด้านบนคือผลรวมทุกประตูของไซต์นี้';
            }
            if (diff < -0.001) {
                msg = '<span style="color:var(--danger);"><i class="fa-solid fa-triangle-exclamation"></i> '
                    + 'ผลรวมรายประตูมากกว่ายอดรวม ' + formatBalanceValue(-diff) + unitTxt
                    + ' — ข้อมูลสองตารางไม่ตรงกัน โปรดแจ้งผู้ดูแลระบบ</span>';
            }
            if (note && !(Math.abs(diff) > 0.001 && rows.length && mdGateResynced[token] === true && _mdResyncBusy[token])) note.innerHTML = msg;
        }

        // [2026-10-08] โหลดยอดรวมของ Dashboard ใหม่ (ข้ามแคช) แล้วเปิดหน้าต่างวัสดุเดิมอีกครั้งพร้อมยอดรายประตูใหม่
        var _mdResyncBusy = {};
        function _mdResyncDashboard(matCode, siteCode, token) {
            var siteFilter = getDashboardSiteFilter();
            _mdResyncBusy[token] = true;
            connextCache.swr('balance', [siteFilter],
                function (done, fail) {
                    google.script.run.withSuccessHandler(done).withFailureHandler(fail).getBalanceList(siteFilter);
                },
                function (rows) {
                    dashboardData = rows || [];
                    try { populateDashboardSubgroupFilter(); renderDashboardMetrics(); renderDashboardTable(); } catch (e) { console.warn(e); }
                    _mdResyncBusy[token] = false;
                    var bd = document.getElementById('mdModalBackdrop');
                    if (bd && bd.classList.contains('open') && mdGateFor === token) openMaterialDetail(matCode, siteCode, true);
                },
                function (err) {
                    console.warn('md resync error:', err);
                    _mdResyncBusy[token] = false;
                },
                { force: true }
            );
        }

        function closeMaterialDetail() {
            document.getElementById('mdModalBackdrop').classList.remove('open');
        }

        // [2026-10-08] ค้นหาแบบแยกคำ: ทุกคำต้องเจอในรหัส/ชื่อ/หมวด แต่ไม่ต้องติดกัน
        //   เช่น "เครื่องเจียร 4"" เจอ "เครื่องเจียร ขนาด 4"" · "นิ้ว" = " · อัญประกาศโค้ง/″ = " ตรง
        function _dashSearchNorm(v) {
            return (v == null ? '' : String(v)).toLowerCase()
                .replace(/[\u201C\u201D\u201E\u2033\u02BA]/g, '"').replace(/[\u2018\u2019\u2032\u02B9]/g, "'")
                .replace(/\s*นิ้ว/g, '"')
                .replace(/\s+/g, ' ').trim();
        }
        function _dashSearchTokens() {
            var el = document.getElementById('dashSearchInput');
            var q = _dashSearchNorm(el && el.value);
            return q ? q.split(' ') : [];
        }
        function _dashRowMatchesSearch(row, tokens) {
            if (!tokens || !tokens.length) return true;
            var hay = _dashSearchNorm((row['MatCode'] || '') + ' ' + (row['Name'] || '') + ' ' + (row['SUBGROUPNAME'] || ''));
            for (var i = 0; i < tokens.length; i++) { if (hay.indexOf(tokens[i]) === -1) return false; }
            return true;
        }
        var DASH_MODE_LABELS = {
            outofstock:  ['หมดสต๊อก (Out of Stock)', 'แสดงเฉพาะของที่คงเหลือ 0'],
            lowstock:    ['ใกล้หมด (Low Stock)', 'แสดงเฉพาะของที่ต่ำกว่าจุดสั่ง'],
            lowstockc01: ['ใกล้หมด · Critical', 'แสดงเฉพาะของใกล้หมดหมวด C01'],
            lowstocknon: ['ใกล้หมด · Non-Critical', 'แสดงเฉพาะของใกล้หมดนอกหมวด C01'],
            wms:         ['วัสดุ WMS', 'แสดงเฉพาะวัสดุ WMS']
        };
        function _dashRenderActiveFilter() {
            var box = document.getElementById('dashActiveFilter');
            var mode = dashboardFilterMode || 'all';
            document.querySelectorAll('[data-dash-mode]').forEach(function (el) {
                var m = el.getAttribute('data-dash-mode');
                var on = (m === mode) || (m === 'lowstock' && (mode === 'lowstockc01' || mode === 'lowstocknon'));
                el.classList.toggle('dash-mode-on', on);
            });
            if (!box) return;
            var lab = DASH_MODE_LABELS[mode];
            if (!lab) { box.style.display = 'none'; box.innerHTML = ''; return; }
            box.innerHTML = '<i class="fa-solid fa-filter"></i> <span>กำลังกรอง: <b>' + escapeHtml(lab[0]) + '</b> — ' + escapeHtml(lab[1]) + '</span>' +
                '<button type="button" class="dash-af-clear" onclick="filterDashboardBy(\'all\')"><i class="fa-solid fa-xmark"></i> ดูทั้งหมด</button>';
            box.style.display = '';
        }

        function getFilteredDashboardData(modeOverride) {
            var mode = modeOverride || dashboardFilterMode;
            var tokens = _dashSearchTokens();

            var filtered = dashboardData.filter(function (row) {
                var balance = getRowBalance(row);
                var catId = (row['CatID'] || '').toString().trim();
                var charId = (row['CharID'] || '').toString().trim().toUpperCase();
                var isLow = isLowStockRow(row);

                if (mode === 'outofstock' && balance > 0) return false;
                if (mode === 'lowstock' && !isLow) return false;
                if (mode === 'lowstockc01' && !(isLow && catId === 'C01')) return false;
                if (mode === 'lowstocknon' && !(isLow && catId !== 'C01')) return false;
                if (mode === 'wms' && charId !== 'WMS') return false;
                 
                if (dashboardSelectedSubgroup && (row['SUBGROUPNAME'] || '').toString().trim() !== dashboardSelectedSubgroup) return false;
                if (!_dashRowMatchesSearch(row, tokens)) return false;
                return true;
            });

             
             
             
            filtered.sort(function (a, b) {
                var sgA = (a['SUBGROUPNAME'] || '').toString().trim();
                var sgB = (b['SUBGROUPNAME'] || '').toString().trim();
                if (!sgA && sgB) return 1;
                if (sgA && !sgB) return -1;
                var cmp = sgA.localeCompare(sgB, 'th');
                if (cmp !== 0) return cmp;
                return (a['Name'] || '').toString().localeCompare((b['Name'] || '').toString(), 'th');
            });
            return filtered;
        }

        function renderDashboardTable() {
            var tbody = document.getElementById('inventoryTableBody');
            if (!tbody) return;
            tbody.innerHTML = '';

            var pageSizeSelect = document.getElementById('dashPageSize');
            dashboardPageSize = parseInt((pageSizeSelect && pageSizeSelect.value) || '25', 10) || 25;

            _dashRenderActiveFilter();
            var filteredData = getFilteredDashboardData();
            var totalItems = filteredData.length;
            var totalPages = Math.max(1, Math.ceil(totalItems / dashboardPageSize));
            if (dashboardCurrentPage > totalPages) dashboardCurrentPage = totalPages;
            if (dashboardCurrentPage < 1) dashboardCurrentPage = 1;

            if (totalItems === 0) {
                // [2026-10-08] บอกเหตุที่ไม่เจอ — ตัวกรองการ์ดซ่อนไว้ / คำค้นไม่ตรง
                var hint = '';
                var lab = DASH_MODE_LABELS[dashboardFilterMode];
                var nAll = lab ? getFilteredDashboardData('all').length : 0;
                if (lab && nAll > 0) {
                    hint = '<span class="dash-empty-hint">ไม่มีในตัวกรอง “' + escapeHtml(lab[0]) + '” — ถ้าดูทั้งหมดจะเจอ ' + nAll.toLocaleString() + ' รายการ' +
                           '<button type="button" onclick="filterDashboardBy(\'all\')">ดูทั้งหมด</button></span>';
                } else if (_dashSearchTokens().length) {
                    hint = '<span class="dash-empty-hint">ไม่พบคำค้นนี้ — ลองพิมพ์ให้สั้นลง (เช่น เฉพาะคำหลัก) หรือค้นด้วยรหัส IC</span>';
                }
                tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;">ไม่มีข้อมูล' + hint + '</td></tr>';
                updateDashboardPagination(0, 0, 0);
                return;
            }

            var startIndex = (dashboardCurrentPage - 1) * dashboardPageSize;
            var pageRows = filteredData.slice(startIndex, startIndex + dashboardPageSize);

             
             
            var groupCounts = {};
            filteredData.forEach(function (row) {
                var sg = (row['SUBGROUPNAME'] || '').toString().trim();
                groupCounts[sg] = (groupCounts[sg] || 0) + 1;
            });
             
            var groupKeys = Object.keys(groupCounts);
            var showSubgroupHeaders = !(groupKeys.length === 1 && groupKeys[0] === '');
            var lastSubgroup = null;

            pageRows.forEach(function (row, idx) {
                var subgroup = (row['SUBGROUPNAME'] || '').toString().trim();
                if (showSubgroupHeaders && subgroup !== lastSubgroup) {
                    lastSubgroup = subgroup;
                    var headTr = document.createElement('tr');
                    headTr.className = 'subgroup-row';
                    headTr.innerHTML = '<td colspan="9"><i class="fa-solid fa-layer-group"></i>' +
                        escapeHtml(subgroup || 'ไม่ระบุหมวด') +
                        '<span class="subgroup-count">' + groupCounts[subgroup].toLocaleString() + ' รายการ</span></td>';
                    tbody.appendChild(headTr);
                }

                var matCode = row['MatCode'] || '-';
                var name = row['Name'] || '-';
                var gate = _dashGateCellHtml(row);   // [PHP port 2026-09-25 per-gate · มติ 51] "G01 10 · G03 50" 
                var inVal = parseFloat(row['In']) || 0;
                var pendingVal = parseFloat(row['Pending']) || 0;
                var outVal = parseFloat(row['Out']) || 0;
                var balance = getRowBalance(row);
                var unit = row['Unit'] || '-';
                var statusBadge = '';

                if (balance <= 0) {
                    statusBadge = '<span style="background:#ef4444;color:#fff;padding:0.15rem 0.5rem;border-radius:4px;font-size:0.78rem;">หมดสต๊อก</span>';
                } else if (balance <= 5) {
                    statusBadge = '<span style="background:#f59e0b;color:#fff;padding:0.15rem 0.5rem;border-radius:4px;font-size:0.78rem;">ใกล้หมด</span>';
                } else {
                    statusBadge = '<span style="background:#10b981;color:#fff;padding:0.15rem 0.5rem;border-radius:4px;font-size:0.78rem;">เพียงพอ</span>';
                }

                var tr = document.createElement('tr');
                var rowSite = (row['SiteCode'] || '').toString();
                tr.setAttribute('data-matcode', matCode);
                tr.setAttribute('data-site', rowSite);
                tr.setAttribute('role', 'button');
                tr.setAttribute('tabindex', '0');
                tr.onclick = function () { openMaterialDetail(matCode, rowSite); };



                // [PHP port 2026-09-23] ทุกแถวเป็นรหัส IC แล้ว (มติ 34) — เลิกติดธง "IC" รายแถว
                tr.innerHTML = '<td data-label="รหัส IC" style="white-space:nowrap;">' + escapeHtml(matCode) + '</td>' +
                    '<td data-label="ชื่อวัสดุ">' + name + '</td>' +
                    '<td data-label="Gate">' + gate + '</td>' +
                    '<td class="text-right" data-label="In">' + inVal.toLocaleString() + '</td>' +
                    '<td class="text-right" data-label="Pending">' + pendingVal.toLocaleString() + '</td>' +
                    '<td class="text-right" data-label="Out">' + outVal.toLocaleString() + '</td>' +
                    '<td class="text-right" data-label="Balance" style="font-weight:600;">' + balance.toLocaleString() + '</td>' +
                    '<td data-label="หน่วย">' + unit + '</td>' +
                    '<td data-label="สถานะ">' + statusBadge + '</td>';
                tbody.appendChild(tr);
            });

            updateDashboardPagination(startIndex + 1, startIndex + pageRows.length, totalItems);
        }

        function updateDashboardPagination(fromItem, toItem, totalItems) {
            var info = document.getElementById('dashPaginationInfo');
            var prevBtn = document.getElementById('dashPrevBtn');
            var nextBtn = document.getElementById('dashNextBtn');
            var totalPages = Math.max(1, Math.ceil((totalItems || 0) / dashboardPageSize));

            if (info) {
                info.textContent = totalItems > 0
                    ? 'แสดง ' + fromItem.toLocaleString() + ' - ' + toItem.toLocaleString() + ' จาก ' + totalItems.toLocaleString() + ' รายการ'
                    : 'ไม่พบข้อมูล';
            }
            if (prevBtn) prevBtn.disabled = dashboardCurrentPage <= 1 || totalItems === 0;
            if (nextBtn) nextBtn.disabled = dashboardCurrentPage >= totalPages || totalItems === 0;
        }

        function filterDashboardTable() {
            dashboardCurrentPage = 1;
            renderDashboardTable();
        }

         
         
         
        function populateDashboardSubgroupFilter() {
            var select = document.getElementById('dashSubgroupFilter');
            if (!select) return;
            var $select = (typeof $ === 'function') ? $(select) : null;

             
            if ($select && $select.data('select2')) { try { $select.select2('destroy'); } catch (e) {} }

            var seen = {};
            var groups = [];
            dashboardData.forEach(function (row) {
                var sg = (row['SUBGROUPNAME'] || '').toString().trim();
                if (sg && !seen[sg]) { seen[sg] = true; groups.push(sg); }
            });
            groups.sort(function (a, b) { return a.localeCompare(b, 'th'); });

            var html = '<option value="">-- ทุกหมวด --</option>';
            groups.forEach(function (sg) {
                html += '<option value="' + escapeHtml(sg) + '">' + escapeHtml(sg) + '</option>';
            });
            select.innerHTML = html;

             
            if (!(dashboardSelectedSubgroup && seen[dashboardSelectedSubgroup])) {
                dashboardSelectedSubgroup = '';
            }
            select.value = dashboardSelectedSubgroup;

             
             
            if ($select && typeof $select.select2 === 'function') {
                try {
                    $select.select2({
                        placeholder: '🔍 ค้นหา / เลือกหมวด',
                        allowClear: true,
                        width: '100%',
                        language: { noResults: function () { return 'ไม่พบหมวดที่ค้นหา'; } }
                    });
                } catch (e) {   }
            }
        }

        function changeDashboardSubgroup() {
            var select = document.getElementById('dashSubgroupFilter');
            dashboardSelectedSubgroup = select ? select.value : '';
            dashboardCurrentPage = 1;
            renderDashboardTable();
        }

        function filterDashboardBy(mode) {
            dashboardFilterMode = mode || 'all';
            dashboardCurrentPage = 1;
            renderDashboardMetrics();
            renderDashboardTable();
             
            if (window.innerWidth <= 768) {
                var panel = document.getElementById('dashTablePanel');
                if (panel) setTimeout(function () { panel.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 80);
            }
        }

        function changeDashboardPage(direction) {
            dashboardCurrentPage += direction;
            renderDashboardTable();
        }

        function changeDashboardPageSize() {
            dashboardCurrentPage = 1;
            renderDashboardTable();
        }
        </script>

        <script>
        var user = null;
        var pageLoadState = {
            coreData: false,
            requisitionData: false,
            dashboard: false,
            confirm: false,
            qr: false,
            approve: false,
            dailycheck: false,
            subexpense: false,
            subsettings: false,
            fingerscan: false
        };

        var PERMISSIONS = {
             
             
             
            dashboard:   ['R0', 'R1', 'R2', 'R3', 'R4', 'R5', 'R6', 'R7', 'R8', 'R9', 'R10', 'R11', 'R12'],
             
             
             
            approve:     ['R0', 'R1', 'R2', 'R3', 'R4', 'R5', 'R6', 'R7', 'R8', 'R9', 'R10', 'R11', 'R12'],
             
            confirm:     ['R0', 'R1', 'R2', 'R3', 'R4', 'R5', 'R6', 'R7', 'R8', 'R9', 'R10', 'R11', 'R12'],
             
            qr:          ['R0', 'R1', 'R2', 'R3','R4', 'R5', 'R6', 'R7', 'R8', 'R9', 'R10', 'R11', 'R12'],
             
            requisition: ['R0', 'R1', 'R2', 'R3', 'R4', 'R5', 'R6', 'R7', 'R8', 'R9', 'R10', 'R11', 'R12'],
             
            history:     ['R0', 'R1', 'R2', 'R3', 'R4', 'R5', 'R6', 'R7', 'R8', 'R9', 'R10', 'R11', 'R12'],
             
            dailycheck:  ['R0', 'R1', 'R2', 'R3', 'R4', 'R5', 'R6', 'R7', 'R8', 'R9', 'R10', 'R11', 'R12'],
             
            subexpense:  ['R0', 'R1', 'R2', 'R3', 'R4', 'R5', 'R6', 'R7', 'R8', 'R9', 'R10', 'R11', 'R12'],
             
             
            subsettings: ['R0', 'R1', 'R2', 'R3', 'R4', 'R5', 'R6', 'R7', 'R8', 'R9', 'R10', 'R11', 'R12'],
             
             
            fingerscan:  ['R0', 'R1', 'R2', 'R3', 'R4', 'R5', 'R6', 'R7', 'R8', 'R9', 'R10', 'R11', 'R12']
        };

        var APP_STORAGE_KEYS = {
            currentPage: 'currentPage',
            requisitionTab: 'requisitionActiveTab'
        };

         
         
         
         
         
         
         
         
         
         
         
         
         
         
         
        var CACHE_VERSION = 'v7';
        var CACHE_TTL = {
             
            materialsList:        30 * 60,   
            materialsMain:        30 * 60,
            subcontracts:         30 * 60,
            gates:                30 * 60,
            availableSites:       24 * 3600,
            approversList:        30 * 60,   
            userDirectory:        30 * 60,   

             
            balance:                  120,   
            history:                  120,
            unreturned:               120,
            approvalRequests:          60,   
            confirmableDocs:           60,
            approvedDocs:             120,
            dailycheck:                60,   
            chargeDashboard:          180    
        };

        var connextCache = (function () {
            var PREFIX = 'connext.cache.' + CACHE_VERSION + '.';

            function userSlot() {
                return (user && user.username) ? user.username : 'anon';
            }
            function buildKey(name, args) {
                var argTag;
                try { argTag = JSON.stringify(args || []); }
                catch (e) { argTag = String(args); }
                return PREFIX + userSlot() + '.' + name + '.' + argTag;
            }

            function get(name, args) {
                try {
                    var raw = localStorage.getItem(buildKey(name, args));
                    if (!raw) return null;
                    var entry = JSON.parse(raw);
                    if (!entry || !entry.exp || entry.exp < Date.now()) return null;
                    return entry.data;
                } catch (e) { return null; }
            }

            function set(name, args, data, ttlSec) {
                try {
                    var entry = { exp: Date.now() + ((ttlSec || 600) * 1000), data: data };
                    localStorage.setItem(buildKey(name, args), JSON.stringify(entry));
                } catch (e) {
                     
                    try { invalidate(name); } catch (_) {}
                }
            }

             
            function invalidate(name) {
                try {
                    var marker = '.' + name + '.';
                    var toRemove = [];
                    for (var i = 0; i < localStorage.length; i++) {
                        var key = localStorage.key(i);
                        if (key && key.indexOf(PREFIX) === 0 && key.indexOf(marker) !== -1) {
                            toRemove.push(key);
                        }
                    }
                    toRemove.forEach(function (k) { localStorage.removeItem(k); });
                } catch (e) {}
            }

            function invalidateMany(names) {
                (names || []).forEach(invalidate);
            }

            function clearAll() {
                try {
                    var toRemove = [];
                    for (var i = 0; i < localStorage.length; i++) {
                        var key = localStorage.key(i);
                        if (key && key.indexOf('connext.cache.') === 0) toRemove.push(key);
                    }
                    toRemove.forEach(function (k) { localStorage.removeItem(k); });
                } catch (e) {}
            }

             
             
             
             
             
             
             
             
             
             
             
             
            function swr(name, args, fetcher, onData, onError, opts) {
                opts = opts || {};
                var ttl = CACHE_TTL[name] || 300;
                var cached = opts.force ? null : get(name, args);
                var cachedSerialized = null;
                if (cached !== null) {
                    try { cachedSerialized = JSON.stringify(cached); } catch (_) {}
                }

                if (cached !== null && typeof onData === 'function') {
                    try { onData(cached, false); } catch (e) { console.error(e); }
                }

                fetcher(function (fresh) {
                    if (fresh !== undefined && fresh !== null) {
                        set(name, args, fresh, ttl);
                    }
                     
                     
                    if (cachedSerialized !== null && fresh !== undefined && fresh !== null) {
                        try {
                            if (JSON.stringify(fresh) === cachedSerialized) return;
                        } catch (_) {}
                    }
                    if (typeof onData === 'function') {
                        try { onData(fresh, true); } catch (e) { console.error(e); }
                    }
                }, function (err) {
                    if (cached === null) {
                        if (typeof onError === 'function') {
                            try { onError(err); } catch (e) {}
                        }
                    } else {
                        console.warn('Background refresh failed for ' + name + ':', err);
                    }
                });
            }

            return {
                get: get,
                set: set,
                invalidate: invalidate,
                invalidateMany: invalidateMany,
                clearAll: clearAll,
                swr: swr
            };
        })();

         
         
         
         
         
         
        var APP_BUILD  = "<?= APP_BUILD ?>";
        var WEBAPP_URL = "<?= APP_BASE ?>/index.php";
        var _appVerTimer = null, _appVerInFlight = false;
        var _updateBannerShown = false, _updateSuppressUntil = 0;

        function startAppVersionWatch() {
            if (_appVerTimer || !APP_BUILD) return;
            _appVerTimer = setInterval(checkAppVersion, 3 * 60 * 1000);    
            document.addEventListener('visibilitychange', function () { if (!document.hidden) checkAppVersion(); });
            window.addEventListener('focus', checkAppVersion);
        }

        function checkAppVersion() {
            if (!APP_BUILD || _appVerInFlight || _updateBannerShown) return;
            if (document.hidden) return;
            if (Date.now() < _updateSuppressUntil) return;
            _appVerInFlight = true;
            google.script.run
                .withSuccessHandler(function (resp) {
                    _appVerInFlight = false;
                    if (resp && resp.success && resp.build && resp.build !== APP_BUILD
                        && Date.now() >= _updateSuppressUntil) {
                        showUpdateBanner();
                    }
                })
                .withFailureHandler(function () { _appVerInFlight = false; })
                .getAppBuild();
        }

        function _ensureUpdateBannerStyle() {
            if (document.getElementById('aubStyle')) return;
            var s = document.createElement('style');
            s.id = 'aubStyle';
            s.textContent =
                '#appUpdateBanner{position:fixed;left:50%;bottom:-140px;transform:translateX(-50%);z-index:100000;width:min(94vw,560px);transition:bottom .35s cubic-bezier(.16,1,.3,1);}' +
                '#appUpdateBanner.show{bottom:18px;}' +
                '#appUpdateBanner .aub-inner{display:flex;align-items:center;gap:12px;background:linear-gradient(135deg,#1e3a8a 0%,#3b82f6 100%);color:#fff;padding:12px 14px;border-radius:14px;box-shadow:0 12px 32px rgba(15,23,42,.35);}' +
                '#appUpdateBanner .aub-spin{font-size:1.25rem;animation:aubspin 2.2s linear infinite;opacity:.95;flex:none;}' +
                '@keyframes aubspin{to{transform:rotate(360deg);}}' +
                '#appUpdateBanner .aub-text{flex:1;min-width:0;}' +
                '#appUpdateBanner .aub-title{font-weight:700;font-size:.95rem;line-height:1.25;}' +
                '#appUpdateBanner .aub-sub{font-size:.78rem;opacity:.92;margin-top:2px;}' +
                '#appUpdateBanner .aub-btn{flex:none;background:#fff;color:#1e3a8a;border:none;border-radius:10px;padding:9px 14px;font-weight:700;font-size:.85rem;cursor:pointer;white-space:nowrap;display:inline-flex;align-items:center;gap:6px;}' +
                '#appUpdateBanner .aub-btn:hover{filter:brightness(.96);}' +
                '#appUpdateBanner .aub-btn:disabled{opacity:.7;cursor:default;}' +
                '#appUpdateBanner .aub-x{flex:none;background:transparent;color:#fff;border:none;opacity:.8;cursor:pointer;font-size:1rem;padding:4px;line-height:1;}' +
                '#appUpdateBanner .aub-x:hover{opacity:1;}' +
                '@media (max-width:480px){#appUpdateBanner .aub-inner{flex-wrap:wrap;}#appUpdateBanner .aub-btn{flex:1 1 100%;justify-content:center;}}';
            document.head.appendChild(s);
        }

        function showUpdateBanner() {
            if (_updateBannerShown) return;
            _updateBannerShown = true;
            _ensureUpdateBannerStyle();
            var el = document.getElementById('appUpdateBanner');
            if (!el) { el = document.createElement('div'); el.id = 'appUpdateBanner'; document.body.appendChild(el); }
            el.innerHTML =
                '<div class="aub-inner">' +
                    '<i class="fa-solid fa-rotate aub-spin"></i>' +
                    '<div class="aub-text">' +
                        '<div class="aub-title">มีเวอร์ชันใหม่พร้อมใช้งาน</div>' +
                        '<div class="aub-sub">กดอัปเดตเพื่อล้างแคชและโหลดเวอร์ชันล่าสุด</div>' +
                    '</div>' +
                    '<button type="button" class="aub-btn" onclick="applyAppUpdate(this)"><i class="fa-solid fa-arrows-rotate"></i> อัปเดต</button>' +
                    '<button type="button" class="aub-x" title="ภายหลัง" aria-label="ภายหลัง" onclick="dismissUpdateBanner()"><i class="fa-solid fa-xmark"></i></button>' +
                '</div>';
            void el.offsetWidth;    
            el.classList.add('show');
            try { hapticSuccess(); } catch (e) {}
        }

        function dismissUpdateBanner() {
            var el = document.getElementById('appUpdateBanner');
            if (el) el.classList.remove('show');
            _updateBannerShown = false;
            _updateSuppressUntil = Date.now() + 10 * 60 * 1000;    
        }

        function applyAppUpdate(btn) {
            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> กำลังอัปเดต...'; }
            try { connextCache.clearAll(); } catch (e) {}                  
            try {                                                           
                if (window.caches && caches.keys) {
                    caches.keys().then(function (keys) { keys.forEach(function (k) { try { caches.delete(k); } catch (_) {} }); });
                }
            } catch (e) {}
            var url = WEBAPP_URL ? (WEBAPP_URL + (WEBAPP_URL.indexOf('?') === -1 ? '?' : '&') + '_=' + Date.now()) : '';
            setTimeout(function () {                                        
                try {
                    if (url && typeof navigateTopFrame === 'function') navigateTopFrame(url, { message: 'กำลังอัปเดตเป็นเวอร์ชันล่าสุด' });
                    else window.location.reload();
                } catch (e) { try { window.location.reload(); } catch (_) {} }
            }, 200);
        }

         
         
         
         
        var CNX_FRAMES = {
            pobuffer: WEBAPP_URL.replace(/index\.php$/, '') + 'po.php?embed=1',
            cnxadmin: WEBAPP_URL.replace(/index\.php$/, '') + 'admin.php?embed=1'
        };

         
        function cnxLoadFrame(pageId, force) {
            var f = document.getElementById(pageId + 'Frame');
            var url = CNX_FRAMES[pageId];
            if (!f || !url) return;
            if (!force && f.getAttribute('data-loaded') === '1') return;
            f.setAttribute('data-loaded', '1');
            f.style.height = '';            
            f.src = url;
        }

         
         
         
        window.addEventListener('message', function (ev) {
            if (ev.origin !== location.origin) return;
            var d = ev.data;
            if (!d || typeof d.cnxFrameHeight !== 'number') return;
            var h = Math.ceil(d.cnxFrameHeight);
            if (!(h > 0) || h > 40000) return;         
            Object.keys(CNX_FRAMES).forEach(function (k) {
                var f = document.getElementById(k + 'Frame');
                if (f && f.contentWindow === ev.source) {
                    f.style.height = Math.max(240, h) + 'px';
                }
            });
        });

        var PAGE_LOADERS = {
            pobuffer: function () { cnxLoadFrame('pobuffer', true); },
            cnxadmin: function () { cnxLoadFrame('cnxadmin', true); },
            requisition: function () {
                if (typeof loadSubcontracts === 'function') loadSubcontracts({force:true});
                if (typeof loadBalance === 'function') loadBalance(function () {
                    if (typeof loadMaterials === 'function') loadMaterials({force:true});
                }, {force:true});
                if (typeof loadUnreturnedItems === 'function') loadUnreturnedItems({force:true});
            },
            dashboard: function () {
                if (typeof loadDashboard === 'function') loadDashboard({force:true});
            },
            approve: function () {
                if (typeof loadApprovalQueue === 'function') loadApprovalQueue({force:true});
                if (typeof loadSubcontracts === 'function') loadSubcontracts({force:true});
            },
            confirm: function () {
                if (typeof loadConfirmableDocuments === 'function') loadConfirmableDocuments({force:true});
            },
            fingerscan: function () {
                _fsData = null; _fsSum = null; _fsAlert = null; _fsDash = null;    
                if (typeof fsReloadSub === 'function') fsReloadSub(true);
            },
            qr: function () {
                if (typeof loadQRPage === 'function') loadQRPage({force:true});
            },
            history: function () {
                if (typeof loadHistory === 'function') loadHistory({force:true});
            },
            dailycheck: function () {
                if (typeof loadDailyCheck === 'function') loadDailyCheck({force:true});
                 
                var dash = document.getElementById('dc-tab-dash');
                if (dash && dash.style.display !== 'none' && typeof loadChargeDashboard === 'function') loadChargeDashboard({force:true});
            },
            subexpense: function () {
                if (typeof loadSubExpensePage !== 'function') return;
                 
                if (typeof _seDirty !== 'undefined' && _seDirty &&
                    !confirm('มีข้อมูลที่แก้ไขแล้วยังไม่ได้บันทึก — รีเฟรชแล้วการแก้ไขจะหายไป ดำเนินการต่อหรือไม่?')) return;
                _seDirty = false;
                loadSubExpensePage();
            },
            subsettings: function () {
                if (typeof loadScfg !== 'function') return;
                 
                if (typeof _scfgDirtyCount === 'function' && _scfgDirtyCount() > 0 &&
                    !confirm('มีการตั้งค่าที่ยังไม่ได้บันทึก — รีเฟรชแล้วการแก้ไขจะหายไป ดำเนินการต่อหรือไม่?')) return;
                loadScfg();
            }
        };

         
         
        function refreshCurrentPage() {
            hapticTap();
            var current = (document.querySelector('.page-container.active-page') || {}).id || '';
            current = current.replace(/-page$/, '');

            var groups = {
                requisition: ['materialsList', 'materialsMain', 'subcontracts', 'gates', 'balance', 'unreturned'],
                dashboard:   ['balance', 'gatebalance', 'materialsMain', 'availableSites', 'history'],
                approve:     ['approvalRequests', 'subcontracts', 'materialsMain'],
                confirm:     ['confirmableDocs', 'subcontracts', 'materialsMain'],
                qr:          ['approvedDocs', 'subcontracts'],
                history:     ['history', 'subcontracts', 'materialsMain'],
                dailycheck:  ['dailycheck', 'chargeDashboard', 'subcontracts', 'materialsMain']
            };
            connextCache.invalidateMany(groups[current] || []);

             
            var btn = document.querySelector('.page-container.active-page .page-refresh-btn');
            if (btn) {
                btn.classList.add('is-refreshing');
                setTimeout(function () { btn.classList.remove('is-refreshing'); }, 1500);
            }

            var loader = PAGE_LOADERS[current];
            if (typeof loader === 'function') {
                loader();
            } else if (typeof switchToPage === 'function' && current) {
                switchToPage(current);
            }
        }

         
         
        function invalidateAfterWrite(kind) {
            var groups = {
                requisition: ['balance', 'gatebalance', 'unreturned', 'history', 'approvalRequests', 'approvedDocs'],
                approval:    ['approvalRequests', 'approvedDocs', 'confirmableDocs', 'balance', 'gatebalance', 'history'],
                confirm:     ['confirmableDocs', 'approvedDocs', 'balance', 'gatebalance', 'history', 'dailycheck', 'chargeDashboard'],
                inbound:     ['balance', 'gatebalance', 'materialsMain', 'history', 'approvalRequests', 'approvedDocs'],
                dailycheck:  ['dailycheck', 'chargeDashboard']
            };
            connextCache.invalidateMany(groups[kind] || []);
        }

        function writeStoredValue(key, value) {
            try {
                sessionStorage.setItem(key, value);
            } catch (err) {}
            try {
                localStorage.setItem(key, value);
            } catch (err) {}
        }

        function readStoredValue(key) {
            var value = '';
            try {
                value = sessionStorage.getItem(key) || '';
            } catch (err) {}
            if (!value) {
                try {
                    value = localStorage.getItem(key) || '';
                } catch (err) {}
            }
            return value;
        }

        function removeStoredValue(key) {
            try {
                sessionStorage.removeItem(key);
            } catch (err) {}
            try {
                localStorage.removeItem(key);
            } catch (err) {}
        }

        function persistCurrentPage(pageId) {
            if (!pageId) return;
            writeStoredValue(APP_STORAGE_KEYS.currentPage, pageId);
        }

        function readStoredCurrentPage() {
            return readStoredValue(APP_STORAGE_KEYS.currentPage);
        }

        function persistCurrentRequisitionTab(tabId) {
            if (!tabId) return;
            writeStoredValue(APP_STORAGE_KEYS.requisitionTab, tabId);
        }

        function readStoredRequisitionTab() {
            return readStoredValue(APP_STORAGE_KEYS.requisitionTab);
        }

        function clearStoredViewState() {
            removeStoredValue(APP_STORAGE_KEYS.currentPage);
            removeStoredValue(APP_STORAGE_KEYS.requisitionTab);
        }

        function applyStoredRequisitionTab() {
            var container = document.querySelector('#requisition-page .tabs-container');
            if (!container) return;

            var tabId = readStoredRequisitionTab() || 'req-normal';
            var btnElement = null;
            container.querySelectorAll('.tab-btn').forEach(function (btn) {
                var inlineHandler = btn.getAttribute('onclick') || '';
                if (inlineHandler.indexOf("'" + tabId + "'") !== -1) {
                    btnElement = btn;
                }
            });

            switchRequisitionTab(tabId, btnElement);
        }

        function readStoredUserData() {
             
             
             
            if (window.__SERVER_USER) {
                try { return JSON.stringify(window.__SERVER_USER); } catch (err) {}
            }
            return '';
        }

        function persistUserData(userData) {
            var serialized = JSON.stringify(userData);
            var persisted = false;
            try {
                sessionStorage.setItem('userData', serialized);
                persisted = true;
            } catch (err) {}
            try {
                localStorage.setItem('userData', serialized);
                persisted = true;
            } catch (err) {}
            return persisted;
        }

        function clearUserData() {
            try {
                sessionStorage.removeItem('userData');
            } catch (err) {}
            try {
                localStorage.removeItem('userData');
            } catch (err) {}
        }

        function removeUserDataFromUrl() {
            try {
                var url = new URL(window.location.href);
                if (!url.searchParams.has('userData')) return;
                url.searchParams.delete('userData');
                window.history.replaceState({}, document.title, url.toString());
            } catch (err) {}
        }

        function setUserProfile() {
            var nameEl = document.getElementById('navUserName');
            var roleEl = document.getElementById('navUserRole');
            if (nameEl) {
                var displayName = (user && (user.fullName || user.name || user.username)) || 'Guest';
                var displayRole = user && user.roleId ? ' (' + user.roleId + ')' : '';
                nameEl.textContent = displayName + displayRole;
            }
            if (roleEl) {
                roleEl.textContent = (user && (user.siteCode || user.roleLevel || user.role)) || '-';
            }
        }

         
         
         
         
         
         
        var PORT_PHASE = {
            subsettings: true,
            fingerscan:  true,
            subexpense:  true,
            signature:   true,
            po_buffer:   true
        };

        document.addEventListener('DOMContentLoaded', function () {
            if (!PORT_PHASE.signature) {
                ['usSigCard', 'signTasksSection'].forEach(function (id) {
                    var el = document.getElementById(id);
                    if (el) { el.style.display = 'none'; }
                });
            }
        });

         
         
        function _canSeePoBuffer() {
            return PORT_PHASE.po_buffer && !!(user && (user.canReq === true || user.roleLevel === 'R0'));
        }
         
        function _canSeeCnxAdmin() {
            return PORT_PHASE.po_buffer && !!(user && user.roleLevel === 'R0');
        }

        function _canSeeDailyCheck() {
            return !!(user && (user.canDaily === true || user.canSC === true));
        }

         
         
        function _canSeeSubExpense() {
            return PORT_PHASE.subexpense && !!(user && (user.canSC === true || user.roleLevel === 'R0'));
        }

         
         
        function _canSeeBsPages() {
            return !!(user && (user.canBS === true || user.roleLevel === 'R0'));
        }
         
         
         
        var SUBSETTINGS_ENABLED = PORT_PHASE.subsettings;    
         
         
        function _canSeeSubSettings() {
            return SUBSETTINGS_ENABLED && (_canSeeBsPages() || (user && user.canSC === true));
        }
         
        function _canSeeFingerScan() {
            return PORT_PHASE.fingerscan && !!(user && (user.canSC === true || user.canBS === true || user.roleLevel === 'R0'));
        }

         
         
         
         
         
         
         
         
         
        function _isScRestricted() {
            if (user && user.canReq === true) return false;
            return !!(user && (user.canSC === true || user.canBS === true) && user.roleLevel !== 'R0');
        }
        function _restrictedPages() {
            var p = {};
            if (user && user.canSC === true) {
                p.dailycheck = 1; p.subexpense = 1; p.fingerscan = 1;
                if (SUBSETTINGS_ENABLED) p.subsettings = 1;    
            }
            if (user && user.canBS === true) { p.fingerscan = 1; if (SUBSETTINGS_ENABLED) p.subsettings = 1; }
            return p;
        }
         
        function _restrictedHomePage() {
            return (user && user.canSC === true) ? 'dailycheck' : 'fingerscan';
        }

         
         
         
         
         
         
         
         
         
        var _navReflowPending = false;
        var _navLastBarWidth = -1;

        function _navBarIsDesktop() { return window.innerWidth >= 769; }

         
         
        function _navContentWidth(bar) {
            var gap = parseFloat(getComputedStyle(bar).columnGap) || 0;
            var total = 0, count = 0;
            Array.prototype.forEach.call(bar.children, function (el) {
                if (el.hidden || el.style.display === 'none') return;
                total += el.getBoundingClientRect().width;
                count++;
            });
            return total + Math.max(0, count - 1) * gap;
        }

         
         
        function _syncNavMoreBadge() {
            var menu  = document.getElementById('navMoreMenu');
            var badge = document.getElementById('navMoreBadge');
            if (!menu || !badge) return;
            var n = 0;
            menu.querySelectorAll('.nav-link-badge').forEach(function (b) {
                if (b.getAttribute('data-hidden') === '1') return;
                var v = parseInt(b.textContent, 10);
                if (!isNaN(v)) n += v;
            });
            if (n <= 0) badge.setAttribute('data-hidden', '1');
            else {
                badge.removeAttribute('data-hidden');
                badge.textContent = n > 99 ? '99+' : String(n);
            }
        }

         
         
         
        function _navStampOrder(bar) {
            if (bar.getAttribute('data-order-stamped') === '1') return;
            var i = 0;
            bar.querySelectorAll('.nav-link').forEach(function (l) { l.setAttribute('data-nav-order', i++); });
            bar.setAttribute('data-order-stamped', '1');
        }

        function reflowNavOverflow() {
            var bar  = document.getElementById('navLinks');
            var more = document.getElementById('navMore');
            var menu = document.getElementById('navMoreMenu');
            if (!bar || !more || !menu) return;

            _navStampOrder(bar);

             
             
             
            if (more.classList.contains('open') && bar.clientWidth === _navLastBarWidth) {
                _syncNavMoreBadge();
                return;
            }

            closeNavMore();
             
            while (menu.firstChild) bar.insertBefore(menu.firstChild, more);
            Array.prototype.slice.call(bar.querySelectorAll('.nav-link'))
                .sort(function (a, b) {
                    return (+a.getAttribute('data-nav-order')) - (+b.getAttribute('data-nav-order'));
                })
                .forEach(function (l) { bar.insertBefore(l, more); });

            if (!_navBarIsDesktop()) { more.hidden = true; _navLastBarWidth = -1; _syncNavMoreBadge(); return; }

             
             
            _navLastBarWidth = bar.clientWidth;

            var links = [];
            bar.querySelectorAll('.nav-link').forEach(function (l) {
                if (l.style.display !== 'none') links.push(l);    
            });

            more.hidden = true;
            if (_navContentWidth(bar) <= bar.clientWidth) { _syncNavMoreBadge(); return; }

             
             
            more.hidden = false;
            var passive = [], active = [];
            links.forEach(function (l) {
                (l.classList.contains('active') ? active : passive).push(l);
            });
            var order = passive.reverse().concat(active);
            var moved = [];
            for (var i = 0; i < order.length; i++) {
                if (_navContentWidth(bar) <= bar.clientWidth) break;
                menu.appendChild(order[i]);
                moved.push(order[i]);
            }
             
            moved.sort(function (a, b) { return links.indexOf(a) - links.indexOf(b); })
                 .forEach(function (l) { menu.appendChild(l); });

            _syncNavMoreBadge();
        }

         
         
         
         
        function scheduleNavReflow() {
            if (_navReflowPending) return;
            _navReflowPending = true;
            var run = function () {
                _navReflowPending = false;
                try { reflowNavOverflow(); } catch (e) {}
            };
            if (typeof requestAnimationFrame === 'function' && !document.hidden) requestAnimationFrame(run);
            else setTimeout(run, 0);
        }

        function _positionNavMoreMenu() {
            var more = document.getElementById('navMore');
            var btn  = document.getElementById('navMoreBtn');
            var menu = document.getElementById('navMoreMenu');
            if (!more || !btn || !menu || !more.classList.contains('open')) return;
            var r = btn.getBoundingClientRect();
            var w = menu.offsetWidth;
            var left = Math.min(Math.max(8, r.right - w), window.innerWidth - w - 8);
            menu.style.top  = Math.round(r.bottom + 8) + 'px';
            menu.style.left = Math.round(left) + 'px';
        }

        function openNavMore() {
            var more = document.getElementById('navMore');
            var btn  = document.getElementById('navMoreBtn');
            if (!more || more.hidden) return;
            more.classList.add('open');
            if (btn) btn.setAttribute('aria-expanded', 'true');
            _positionNavMoreMenu();
        }

        function closeNavMore() {
            var more = document.getElementById('navMore');
            var btn  = document.getElementById('navMoreBtn');
            if (!more || !more.classList.contains('open')) return;
            more.classList.remove('open');
            if (btn) btn.setAttribute('aria-expanded', 'false');
        }

        function toggleNavMore(ev) {
            if (ev) ev.stopPropagation();
            var more = document.getElementById('navMore');
            if (!more) return;
            if (more.classList.contains('open')) closeNavMore();
            else openNavMore();
        }

         
        document.addEventListener('click', function (e) {
            var more = document.getElementById('navMore');
            if (more && more.classList.contains('open') && !more.contains(e.target)) closeNavMore();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeNavMore();
        });
        window.addEventListener('resize', scheduleNavReflow);
        window.addEventListener('load', scheduleNavReflow);
        document.addEventListener('visibilitychange', function () { if (!document.hidden) scheduleNavReflow(); });
         
        try { if (document.fonts && document.fonts.ready) document.fonts.ready.then(scheduleNavReflow); } catch (e) {}

        function refreshNavVisibility() {
            var restricted = _isScRestricted();
            var allowedPages = _restrictedPages();
            document.querySelectorAll('.nav-link').forEach(function (link) {
                var pageId = link.getAttribute('data-page');
                var isAllowed = !pageId || !PERMISSIONS[pageId] || (user && PERMISSIONS[pageId].includes(user.roleLevel));
                if (pageId === 'dailycheck') isAllowed = isAllowed && _canSeeDailyCheck();
                if (pageId === 'subexpense') isAllowed = isAllowed && _canSeeSubExpense();
                if (pageId === 'subsettings') isAllowed = isAllowed && _canSeeSubSettings();
                if (pageId === 'fingerscan') isAllowed = isAllowed && _canSeeFingerScan();
                if (pageId === 'pobuffer') isAllowed = isAllowed && _canSeePoBuffer();
                if (pageId === 'cnxadmin') isAllowed = isAllowed && _canSeeCnxAdmin();
                if (restricted && pageId && !allowedPages[pageId]) isAllowed = false;
                link.style.display = isAllowed ? 'inline-flex' : 'none';
            });
            refreshBottomNavVisibility();
            scheduleNavReflow();
        }

        function ensureCoreDataLoaded(callback) {
            if (pageLoadState.coreData) {
                if (typeof callback === 'function') callback();
                return;
            }

            if (typeof loadSubcontracts === 'function') loadSubcontracts();
            if (typeof loadApproversIntoSelect === 'function') loadApproversIntoSelect();

            if (typeof loadBalance === 'function') {
                loadBalance(function () {
                    if (typeof loadMaterials === 'function') loadMaterials();
                    pageLoadState.coreData = true;
                    if (typeof callback === 'function') callback();
                });
                return;
            }

            pageLoadState.coreData = true;
            if (typeof callback === 'function') callback();
        }

        function ensurePageDataLoaded(pageId) {
            if (pageId === 'requisition') {
                ensureCoreDataLoaded(function () {
                    if (!pageLoadState.requisitionData) {
                        if (typeof loadUnreturnedItems === 'function') loadUnreturnedItems();
                        pageLoadState.requisitionData = true;
                    }
                });
            }

            if (pageId === 'history' && !pageLoadState.history) {
                if (typeof loadHistory === 'function') loadHistory();
                pageLoadState.history = true;
            }

            if (pageId === 'dashboard' && !pageLoadState.dashboard) {
                if (typeof loadDashboard === 'function') loadDashboard();
                pageLoadState.dashboard = true;
            }

            if (pageId === 'confirm' && !pageLoadState.confirm) {
                if (typeof loadConfirmableDocuments === 'function') loadConfirmableDocuments();
                pageLoadState.confirm = true;
            }

            if (pageId === 'qr' && !pageLoadState.qr) {
                if (typeof loadQRPage === 'function') loadQRPage();
                pageLoadState.qr = true;
            }

            if (pageId === 'approve' && !pageLoadState.approve) {
                ensureCoreDataLoaded(function () {
                    if (typeof loadApprovalQueue === 'function') loadApprovalQueue();
                    pageLoadState.approve = true;
                });
            }
             
            if (pageId === 'approve' && typeof loadMySignTasks === 'function') {
                loadMySignTasks();
            }

            if (pageId === 'dailycheck' && !pageLoadState.dailycheck) {
                if (typeof loadDailyCheck === 'function') loadDailyCheck();
                pageLoadState.dailycheck = true;
            }

            if (pageId === 'subexpense' && !pageLoadState.subexpense) {
                if (typeof loadSubExpensePage === 'function') loadSubExpensePage();
                pageLoadState.subexpense = true;
            }

            if (pageId === 'subsettings' && !pageLoadState.subsettings) {
                if (typeof loadScfg === 'function') loadScfg();
                pageLoadState.subsettings = true;
            }

             
            if (CNX_FRAMES[pageId]) { cnxLoadFrame(pageId, false); }

            if (pageId === 'fingerscan' && !pageLoadState.fingerscan) {
                if (typeof fsReloadSub === 'function') fsReloadSub();
                pageLoadState.fingerscan = true;
            }
        }

        var PAGE_TITLES = {
            dashboard:   'CONNEXT | Dashboard',
            requisition: 'CONNEXT | เบิก / เบ็ดเตล็ด / ยืม',
            approve:     'CONNEXT | อนุมัติ',
            confirm:     'CONNEXT | ยืนยัน',
            qr:          'CONNEXT | QR Codes',
            history:     'CONNEXT | ประวัติเอกสาร',
            dailycheck:  'CONNEXT | ตรวจสอบประจำวัน',
            subexpense:  'CONNEXT | หักค่าใช้จ่ายผู้รับเหมา',
            subsettings: 'CONNEXT | ตั้งค่า',
            fingerscan:  'CONNEXT | บันทึกสแกนนิ้ว'
        };

        function setPageTitle(title) {
            try { document.title = title; } catch (e) {}
            try { window.top.document.title = title; } catch (e) {}
            try {
                var el = document.querySelector('title');
                if (el) el.textContent = title;
            } catch (e) {}
        }

        function switchToPage(pageId) {
            if (user && PERMISSIONS[pageId] && !PERMISSIONS[pageId].includes(user.roleLevel)) {
                showInfoPopup('คุณไม่มีสิทธิ์เข้าถึงหน้านี้', 'สิทธิ์ของคุณ: ' + user.roleLevel, 'danger');
                return;
            }
            if (pageId === 'dailycheck' && !_canSeeDailyCheck()) {
                showInfoPopup('คุณไม่มีสิทธิ์เข้าถึงหน้านี้', 'หน้านี้เฉพาะผู้มีสิทธิ์ขอเบิก (CanReq)', 'danger');
                return;
            }
            if (pageId === 'subexpense' && !_canSeeSubExpense()) {
                showInfoPopup('คุณไม่มีสิทธิ์เข้าถึงหน้านี้', 'หน้านี้เฉพาะเลขาประจำไซต์ (สิทธิ์ SC)', 'danger');
                return;
            }
            if (pageId === 'subsettings' && !_canSeeSubSettings()) {
                 
                if (!SUBSETTINGS_ENABLED) { pageId = _isScRestricted() ? _restrictedHomePage() : 'dashboard'; }
                else { showInfoPopup('คุณไม่มีสิทธิ์เข้าถึงหน้านี้', 'หน้านี้เฉพาะผู้ดูแลผู้รับเหมา (สิทธิ์ BS)', 'danger'); return; }
            }
            if (pageId === 'fingerscan' && !_canSeeFingerScan()) {
                showInfoPopup('คุณไม่มีสิทธิ์เข้าถึงหน้านี้', 'หน้านี้เฉพาะเลขาประจำไซต์ (SC) หรือผู้ดูแลผู้รับเหมา (BS)', 'danger');
                return;
            }
             
            if (_isScRestricted() && !_restrictedPages()[pageId]) {
                pageId = _restrictedHomePage();
            }

            setPageTitle(PAGE_TITLES[pageId] || 'CONNEXT | Inventory Control Module');

            document.querySelectorAll('.page-container').forEach(function (sec) {
                sec.classList.remove('active-page');
            });

            var targetPage = document.getElementById(pageId + '-page');
            if (targetPage) {
                targetPage.classList.add('active-page');
            }

            document.querySelectorAll('.nav-link').forEach(function (link) {
                link.classList.remove('active');
            });

            var targetLink = document.querySelector('.nav-link[data-page="' + pageId + '"]');
            if (targetLink) {
                targetLink.classList.add('active');
            }

             
             
            if (typeof closeNavMore === 'function') closeNavMore();
            if (typeof scheduleNavReflow === 'function') scheduleNavReflow();

            syncBottomNavActive(pageId);

            persistCurrentPage(pageId);

             
             
             
             
            try {
                var _u = new URL(window.location.href);
                _u.searchParams.set('page', pageId);
                window.history.replaceState({}, document.title, _u.toString());
            } catch (_err) {}

            if (pageId === 'requisition') {
                applyStoredRequisitionTab();
            }
            ensurePageDataLoaded(pageId);

             
            if (typeof stopQrPagePolling === 'function') stopQrPagePolling();
            if (pageId === 'qr' && typeof startQrPagePolling === 'function') startQrPagePolling();
             
            if (typeof stopConfirmPagePolling === 'function') stopConfirmPagePolling();
            if (typeof _stopConfirmClosePolling === 'function') _stopConfirmClosePolling();
            if (pageId === 'confirm' && typeof startConfirmPagePolling === 'function') startConfirmPagePolling();
             
            if (pageId === 'confirm' && typeof _syncConfirmClosePoll === 'function') _syncConfirmClosePoll();
             
            if (typeof stopApprovePagePolling === 'function') stopApprovePagePolling();
            if (pageId === 'approve' && typeof startApprovePagePolling === 'function') startApprovePagePolling();
        }

         
         
         
        function goToPage(pageId) {
            hapticTap();
            closeNavDrawer();
            if (typeof switchToPage === 'function') switchToPage(pageId);
        }

        function syncBottomNavActive(pageId) {
             
             
            var primaryPages = { requisition: 1, dashboard: 1, approve: 1, qr: 1, confirm: 1 };
             
            if (_isScRestricted()) primaryPages = _restrictedPages();
            document.querySelectorAll('.mobile-tab-item[data-page]').forEach(function (btn) {
                btn.classList.toggle('active', btn.getAttribute('data-page') === pageId);
            });
             
            var moreBtn = document.getElementById('mobileTabNavMore');
            if (moreBtn) {
                moreBtn.classList.toggle('active', !primaryPages[pageId] && pageId);
            }
            document.querySelectorAll('.nav-drawer-item[data-page]').forEach(function (btn) {
                btn.classList.toggle('active', btn.getAttribute('data-page') === pageId);
            });
        }

        function refreshBottomNavVisibility() {
            var restricted = _isScRestricted();
            var allowedPages = _restrictedPages();
            document.querySelectorAll('.mobile-tab-item[data-page], .nav-drawer-item[data-page]').forEach(function (btn) {
                var pageId = btn.getAttribute('data-page');
                var allowed = !pageId || !PERMISSIONS[pageId] || (user && PERMISSIONS[pageId].includes(user.roleLevel));
                if (pageId === 'dailycheck') allowed = allowed && _canSeeDailyCheck();
                if (pageId === 'subexpense') allowed = allowed && _canSeeSubExpense();
                if (pageId === 'subsettings') allowed = allowed && _canSeeSubSettings();
                if (pageId === 'fingerscan') allowed = allowed && _canSeeFingerScan();
                if (pageId === 'pobuffer') allowed = allowed && _canSeePoBuffer();
                if (pageId === 'cnxadmin') allowed = allowed && _canSeeCnxAdmin();
                if (restricted && pageId && !allowedPages[pageId]) allowed = false;
                 
                 
                 
                 
                if (btn.classList.contains('sc-only-tab') && !(restricted && user && user.canSC === true)) allowed = false;
                if (btn.classList.contains('bs-only-tab') && !(restricted && ((user && user.canBS === true) ||
                    ((pageId === 'fingerscan' || pageId === 'subsettings') && user && user.canSC === true)))) allowed = false;
                btn.hidden = !allowed;
            });
        }

        function openNavDrawer() {
            var d  = document.getElementById('navDrawer');
            var bd = document.getElementById('navDrawerBackdrop');
            if (d)  d.classList.add('open');
            if (bd) bd.classList.add('open');
        }
        function closeNavDrawer() {
            var d  = document.getElementById('navDrawer');
            var bd = document.getElementById('navDrawerBackdrop');
            if (d)  d.classList.remove('open');
            if (bd) bd.classList.remove('open');
        }

         
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeNavDrawer();
        });

         
         
        function alignMobileTabNav() {
            var nb  = document.querySelector('.navbar');
            var tab = document.getElementById('mobileTabNav');
            if (!nb || !tab) return;
            var h = nb.getBoundingClientRect().height;
            tab.style.top = Math.round(h) + 'px';
        }
        window.addEventListener('load', alignMobileTabNav);
        window.addEventListener('resize', alignMobileTabNav);

         
         
         
         
         
         
        function _vibrate(pattern) {
            try { if (navigator && typeof navigator.vibrate === 'function') navigator.vibrate(pattern); }
            catch (e) {}
        }
        function hapticTap()     { _vibrate(8);  }                   
        function hapticSuccess() { _vibrate([10, 40, 18]); }         
        function hapticWarn()    { _vibrate([15, 60, 15, 60, 15]);}  

         
         

         
         
         
        function _toggleScrollFab() {
            var fab = document.getElementById('fabScrollTop');
            if (!fab) return;
            if (window.scrollY > 300) fab.classList.add('show');
            else fab.classList.remove('show');
        }
        function scrollToTop() {
            hapticTap();
            try { window.scrollTo({ top: 0, behavior: 'smooth' }); }
            catch (e) { window.scrollTo(0, 0); }
        }
        window.addEventListener('scroll', _toggleScrollFab, { passive: true });
        window.addEventListener('load', _toggleScrollFab);

         
        function _buildTopNavForm(url) {
            var parts = url.split('?');
            var action = parts[0];
            var query  = parts[1] || '';
            var form = document.createElement('form');
            form.method = 'GET';
            form.action = action;
            form.target = '_top';
            form.style.display = 'none';
            if (query) {
                query.split('&').forEach(function (pair) {
                    if (!pair) return;
                    var eq = pair.indexOf('=');
                    var name  = eq === -1 ? pair : pair.substring(0, eq);
                    var value = eq === -1 ? ''   : pair.substring(eq + 1);
                    try { name  = decodeURIComponent(name);  } catch (_) {}
                    try { value = decodeURIComponent(value); } catch (_) {}
                    var input = document.createElement('input');
                    input.type  = 'hidden';
                    input.name  = name;
                    input.value = value;
                    form.appendChild(input);
                });
            }
            document.body.appendChild(form);
            return form;
        }

         
         
         
         
        function _showContinueOverlay(url, message) {
            var existing = document.getElementById('continueOverlay');
            if (existing) existing.remove();
            var el = document.createElement('div');
            el.id = 'continueOverlay';
            el.style.cssText = 'position:fixed; inset:0; background:rgba(15,23,42,0.6); display:flex; align-items:center; justify-content:center; z-index:99999; backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px);';
            el.innerHTML =
                '<div style="background:#fff; border-radius:18px; padding:28px 24px; width:min(90vw, 380px); text-align:center; box-shadow:0 20px 50px rgba(0,0,0,0.35);">' +
                    '<div style="font-size:2.2rem; margin-bottom:10px;">✅</div>' +
                    '<div style="font-weight:700; font-size:1.1rem; color:#1e3a8a; margin-bottom:6px;">' + (message || 'ดำเนินการต่อ') + '</div>' +
                    '<div style="color:#64748b; font-size:0.88rem; margin-bottom:18px;">เบราว์เซอร์ต้องการให้คุณแตะปุ่มเพื่อยืนยัน</div>' +
                    '<button id="continueOverlayBtn" type="button" class="btn btn-primary" style="width:100%; min-height:52px; font-size:1rem;">▶ ไปต่อ</button>' +
                '</div>';
            document.body.appendChild(el);
            document.getElementById('continueOverlayBtn').onclick = function () {
                try { _buildTopNavForm(url).submit(); }
                catch (e) {
                    try { window.top.location.href = url; }
                    catch (_) { window.location.href = url; }
                }
            };
        }

         
         
         
         
         
        function navigateTopFrame(url, opts) {
            opts = opts || {};
            try { _buildTopNavForm(url).submit(); }
            catch (err) { console.warn('navigateTopFrame submit failed:', err); }
            setTimeout(function () {
                _showContinueOverlay(url, opts.message || 'พร้อมไปต่อ');
            }, 1000);
        }

         
         
        function resetInMemoryState() {
            try { Object.keys(pageLoadState).forEach(function (k) { pageLoadState[k] = false; }); } catch (e) {}
             
            _fsData = null; _fsSum = null; _fsAlert = null; _fsDash = null;
            _fsSignRec = null; _fsSaveRows = null;
            _fsBadgeState = { record: 0, verify: 0, alert: 0, pendv: 0 };
            _fsAlertMonth = ''; _fsAlertHalf = ''; _fsAlertSub = ''; _fsAlertCollapsed = {};
            _fsSub = 'daily'; _fsDirty = false;
        }

        function logout() {
            showConfirmPopup('ยืนยันการออกจากระบบ', 'คุณต้องการออกจากระบบตอนนี้ใช่หรือไม่?', function () {
                 
                try { google.script.run.logoutServer(); } catch (e) {}
                try { window.__SERVER_USER = null; } catch (e) {}
                connextCache.clearAll();
                resetInMemoryState();    
                clearUserData();
                clearStoredViewState();
                user = null;
                 
                _mySigData = { loaded: false, dataUrl: '' };
                signTasksData = [];
                _signTaskFreshSig = '';
                try { renderUsSigPreview(); } catch (e) {}
                try { renderSignTasks(); } catch (e) {}
                try { updateNavBadges(); } catch (e) {}
                 
                closeAppPopup();
                var uEl = document.getElementById('ilUser');
                var pEl = document.getElementById('ilPass');
                var btn = document.getElementById('ilBtn');
                if (uEl) { uEl.value = ''; uEl.disabled = false; }
                if (pEl) { pEl.value = ''; pEl.disabled = false; }
                if (btn) { btn.disabled = false; btn.textContent = 'เข้าสู่ระบบ'; }
                _ilSetMsg('', '');
                showInlineLogin();
            }, 'ออกจากระบบ', 'btn btn-danger');
        }

         
         
         
         
         
        function showInlineLogin() {
            var ov = document.getElementById('inlineLogin');
            if (ov) ov.style.display = 'flex';
             
            try { document.body.style.overflow = 'hidden'; } catch (e) {}
            var u = document.getElementById('ilUser');
            if (u) { try { u.focus(); } catch (e) {} }
        }
        function hideInlineLogin() {
            var ov = document.getElementById('inlineLogin');
            if (ov) ov.style.display = 'none';
            try { document.body.style.overflow = ''; } catch (e) {}
        }
        function _ilSetMsg(text, cls) {
            var el = document.getElementById('ilMsg');
            if (el) { el.textContent = text; el.className = cls || ''; }
        }
        function performInlineLogin() {
            var uEl = document.getElementById('ilUser');
            var pEl = document.getElementById('ilPass');
            var btn = document.getElementById('ilBtn');
            var u = (uEl && uEl.value || '').trim();
            var p = (pEl && pEl.value || '');
            if (!u) { _ilSetMsg('⚠️ กรุณากรอกชื่อผู้ใช้', 'err'); if (uEl) uEl.focus(); return; }
            if (!p) { _ilSetMsg('⚠️ กรุณากรอกรหัสผ่าน', 'err'); if (pEl) pEl.focus(); return; }
            if (typeof google === 'undefined' || !google.script) { _ilSetMsg('❌ ไม่พบ GAS environment', 'err'); return; }

            if (btn) { btn.disabled = true; btn.textContent = 'กำลังเข้าสู่ระบบ...'; }
            if (uEl) uEl.disabled = true;
            if (pEl) pEl.disabled = true;
            _ilSetMsg('🔐 กำลังเข้าสู่ระบบ...', 'info');

            google.script.run
                .withSuccessHandler(function (res) {
                    if (res && res.success) {
                        _ilSetMsg('✅ ' + (res.message || 'เข้าสู่ระบบสำเร็จ'), 'ok');
                        try { persistUserData(res.userData); } catch (e) {}
                        user = res.userData;
                        if (pEl) pEl.value = '';
                         
                        hideInlineLogin();
                        bootApp();
                    } else {
                        if (btn) { btn.disabled = false; btn.textContent = 'เข้าสู่ระบบ'; }
                        if (uEl) uEl.disabled = false;
                        if (pEl) { pEl.disabled = false; pEl.value = ''; pEl.focus(); }
                        _ilSetMsg('❌ ' + (res && res.message ? res.message : 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง'), 'err');
                    }
                })
                .withFailureHandler(function (err) {
                    if (btn) { btn.disabled = false; btn.textContent = 'เข้าสู่ระบบ'; }
                    if (uEl) uEl.disabled = false;
                    if (pEl) pEl.disabled = false;
                    _ilSetMsg('⚠️ การเชื่อมต่อล้มเหลว: ' + ((err && err.message) || err), 'err');
                })
                .checkLogin(u, p);
        }
         
        (function () {
            document.addEventListener('keypress', function (e) {
                if (e.key !== 'Enter') return;
                var ov = document.getElementById('inlineLogin');
                if (!ov || ov.style.display === 'none') return;
                var t = e.target;
                if (t && (t.id === 'ilUser' || t.id === 'ilPass')) performInlineLogin();
            });
        })();

        function initPageShell() {
             
            setPageTitle('CONNEXT | Inventory Control Module');

            var userDataStr = readStoredUserData();
            var parsed = null;
            if (userDataStr) { try { parsed = JSON.parse(userDataStr); } catch (e) { parsed = null; } }

             
            if (!parsed) {
                clearUserData();
                showInlineLogin();
                return;
            }

            user = parsed;
            if (persistUserData(user)) removeUserDataFromUrl();
            bootApp();
        }

         
         
        var _appBooted = false;
        function bootApp() {
            hideInlineLogin();
             
             
            _mySigData = { loaded: false, dataUrl: '' };
            signTasksData = [];
            _signTaskFreshSig = '';
            try { renderUsSigPreview(); } catch (e) {}
            try { renderSignTasks(); } catch (e) {}
            setUserProfile();
            refreshNavVisibility();

             
             
            try {
                google.script.run
                    .withSuccessHandler(function (perm) {
                        if (!perm || !user) return;
                        var freshReq = perm.canReq === true;
                        var freshDaily = perm.canDaily === true;
                        var freshSC = perm.canSC === true;
                        var freshBS = perm.canBS === true;
                        var changed = false;
                        if (user.canReq !== freshReq) { user.canReq = freshReq; changed = true; }
                        if (user.canDaily !== freshDaily) { user.canDaily = freshDaily; changed = true; }
                        if (user.canSC !== freshSC) { user.canSC = freshSC; changed = true; }
                        if (user.canBS !== freshBS) { user.canBS = freshBS; changed = true; }
                        if (changed) {
                            persistUserData(user);
                             
                            try { renderQRCards(); } catch (e) {}
                            try { updateNavBadges(); } catch (e) {}
                            try { refreshNavVisibility(); } catch (e) {}
                            try { loadConfirmableDocuments({ force: true }); } catch (e) {}
                             
                            if (_isScRestricted()) {
                                var curPage = readStoredCurrentPage();
                                if (!_restrictedPages()[curPage]) { try { switchToPage(_restrictedHomePage()); } catch (e) {} }
                            }
                        }
                    })
                    .withFailureHandler(function (err) {
                        console.warn('getUserPermissions failed:', err);
                    })
                    .getUserPermissions(user.username);
            } catch (e) {}

             
             
            try {
                if (typeof _canSeeFingerScan === 'function' && _canSeeFingerScan()) {
                    google.script.run
                        .withSuccessHandler(function (b) {
                            if (!b || !b.success) return;
                            try { _fsUpdateVerifyBadge(b); } catch (e) {}
                            try { _fsUpdateAlertBadge(b.alertPending); } catch (e) {}
                        })
                        .withFailureHandler(function () {})
                        .getFingerScanBadge(user.username);
                }
            } catch (e) {}

             
            if (!_appBooted) {
                document.querySelectorAll('.nav-link').forEach(function (link) {
                    link.addEventListener('click', function () {
                        var page = link.getAttribute('data-page');
                        if (page) switchToPage(page);
                    });
                });
                startAppVersionWatch();    
                _appBooted = true;
            }

            var requestedPage = "<?= e($initialPage) ?>";
            if (_isScRestricted()) {
                 
                 
                var scStored = requestedPage || readStoredCurrentPage();
                switchToPage(_restrictedPages()[scStored] ? scStored : _restrictedHomePage());
            } else if (requestedPage && document.getElementById(requestedPage + '-page')) {
                switchToPage(requestedPage);
            } else if (readStoredCurrentPage() && document.getElementById(readStoredCurrentPage() + '-page')) {
                switchToPage(readStoredCurrentPage());
            } else if (user && user.firstPage && document.getElementById(user.firstPage + '-page')) {
                switchToPage(user.firstPage);
            } else {
                switchToPage('requisition');
            }

             
             
             
             
             
             
            setTimeout(function () {
                if (typeof loadApprovalQueue        === 'function') loadApprovalQueue();
                if (typeof loadQRPage               === 'function') loadQRPage();
                if (typeof loadConfirmableDocuments === 'function') loadConfirmableDocuments();
                if (typeof loadUserDirectory        === 'function') loadUserDirectory();
                if (typeof loadSubcontracts         === 'function') loadSubcontracts();
                if (typeof loadMySignTasks          === 'function') loadMySignTasks();    
            }, 600);
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initPageShell);
        } else {
            initPageShell();
        }

         
        function openUserSettings() {
            var modal = document.getElementById('userSettingsModal');
            if (!modal) return;

             
            renderUsSigPreview();
            if (!_mySigData.loaded) loadMySignature();

             
            ['usNewPass','usConfirmPass'].forEach(function(id) {
                var el = document.getElementById(id);
                if (el) {
                    el.value = '';
                    el.type  = 'password';  
                }
            });
             
            document.querySelectorAll('.password-toggle i').forEach(function (icon) {
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            });
             
            bindNoThaiOn('usNewPass');
            bindNoThaiOn('usConfirmPass');
            ['usPassMsg','usSiteMsg'].forEach(function(id) {
                var el = document.getElementById(id);
                if (el) { el.textContent = ''; el.style.color = ''; }
            });

             
            var idNameEl = document.getElementById('usIdentityName');
            var idRoleEl = document.getElementById('usIdentityRole');
            if (idNameEl) {
                idNameEl.textContent = (user && (user.fullName || user.name || user.username)) || 'ผู้ใช้';
            }
            if (idRoleEl) {
                var rb = [];
                if (user && user.role)      rb.push(user.role);        
                if (user && user.roleLevel) rb.push(user.roleLevel);   
                var roleLine = rb.join(' · ');
                if (user && user.username) roleLine += (roleLine ? '  ·  ' : '') + '@' + user.username;
                idRoleEl.textContent = roleLine || '-';
            }

             
            var currentSiteEl = document.getElementById('usCurrentSite');
            if (currentSiteEl) currentSiteEl.textContent = (user && user.siteCode) ? user.siteCode : '-';

             
            var _canChangeSite = !!(user && user.roleLevel === 'R0');
            var _csCtrls = document.getElementById('usChangeSiteControls');
            var _csLock  = document.getElementById('usSiteLockedNote');
            if (_csCtrls) _csCtrls.style.display = _canChangeSite ? '' : 'none';
            if (_csLock)  _csLock.style.display  = _canChangeSite ? 'none' : 'block';

             
            var siteSelect = document.getElementById('usSiteSelect');
            if (_canChangeSite && siteSelect) {
                 
                if ($(siteSelect).hasClass('select2-hidden-accessible')) {
                    $(siteSelect).select2('destroy');
                }
                siteSelect.innerHTML = '<option value="">⏳ กำลังโหลด...</option>';
                google.script.run
                    .withSuccessHandler(function(sites) {
                        siteSelect.innerHTML = '<option value="">-- เลือก Site --</option>';
                        (sites || []).forEach(function(s) {
                            var opt = document.createElement('option');
                            opt.value = s.siteCode;
                            opt.textContent = s.siteName;
                            if (user && user.siteCode === s.siteCode) opt.selected = true;
                            siteSelect.appendChild(opt);
                        });
                        if (siteSelect.options.length <= 1) {
                            siteSelect.innerHTML = '<option value="">ไม่พบข้อมูล Site</option>';
                        }
                         
                        $(siteSelect).select2({
                            placeholder: '🔍 ค้นหา Site...',
                            allowClear: true,
                            width: '100%',
                            language: { noResults: function() { return 'ไม่พบผลลัพธ์'; } },
                            dropdownParent: $(siteSelect).closest('div[id="userSettingsModal"] > div')
                        });
                    })
                    .withFailureHandler(function() {
                        siteSelect.innerHTML = '<option value="">โหลดล้มเหลว</option>';
                    })
                    .getAvailableSites();
            }

            modal.style.display = 'flex';
        }

        function closeUserSettings() {
            var modal = document.getElementById('userSettingsModal');
            if (modal) modal.style.display = 'none';
             
            var siteSelect = document.getElementById('usSiteSelect');
            if (siteSelect && $(siteSelect).hasClass('select2-hidden-accessible')) {
                $(siteSelect).select2('destroy');
            }
        }

         
        document.addEventListener('click', function(e) {
            var modal = document.getElementById('userSettingsModal');
            if (modal && e.target === modal) closeUserSettings();
        });

        function submitChangePassword() {
            var newPass = (document.getElementById('usNewPass') || {}).value || '';
            var confirmPass = (document.getElementById('usConfirmPass') || {}).value || '';
            var msgEl = document.getElementById('usPassMsg');

            function setPassMsg(text, ok) {
                if (msgEl) {
                    msgEl.textContent = text;
                    msgEl.style.color = ok ? '#10b981' : '#ef4444';
                }
            }

             
             
             
             
            if (!newPass) { setPassMsg('⚠️ กรุณากรอกรหัสผ่านใหม่', false); return; }
            if (/[฀-๿]/.test(newPass)) { setPassMsg('⚠️ รหัสผ่านห้ามมีตัวอักษรภาษาไทย', false); return; }
            if (/\s/.test(newPass)) { setPassMsg('⚠️ รหัสผ่านห้ามมีช่องว่าง', false); return; }
            if (newPass !== confirmPass) { setPassMsg('⚠️ รหัสผ่านใหม่ไม่ตรงกัน', false); return; }
            if (!user || !user.username) { setPassMsg('❌ ไม่พบข้อมูลผู้ใช้', false); return; }

            setPassMsg('🔄 กำลังบันทึก...', true);

            google.script.run
                .withSuccessHandler(function(res) {
                    if (res && res.success) {
                        setPassMsg('✅ ' + (res.message || 'เปลี่ยนรหัสผ่านสำเร็จ'), true);
                        setTimeout(function() {
                            document.getElementById('usNewPass').value = '';
                            document.getElementById('usConfirmPass').value = '';
                            if (msgEl) msgEl.textContent = '';
                        }, 2000);
                    } else {
                        setPassMsg('❌ ' + (res && res.message ? res.message : 'เปลี่ยนรหัสผ่านไม่สำเร็จ'), false);
                    }
                })
                .withFailureHandler(function(err) {
                    setPassMsg('⚠️ เกิดข้อผิดพลาด: ' + (err.message || err), false);
                })
                .changePassword(user.username, newPass);
        }

         
         
         
         
         
         
        function _setBadge(elId, count) {
            var el = document.getElementById(elId);
            if (!el) return;
            var n = parseInt(count, 10) || 0;
            if (n <= 0) {
                el.setAttribute('data-hidden', '1');
            } else {
                el.removeAttribute('data-hidden');
                el.textContent = n > 99 ? '99+' : String(n);
            }
        }
         
         
         
         
        function _visibleQRCount() {
            if (!Array.isArray(qrPageData) || !qrPageData.length) return 0;
            var uname  = user ? (user.username  || '') : '';
            var site   = user ? (user.siteCode  || '') : '';
            var roleLv = user ? (user.roleLevel || '') : '';
            var role   = user ? ((user.role || '')).toLowerCase() : '';
            var roleNum = parseInt((roleLv.match(/\d+/) || ['0'])[0], 10) || 0;
            var isSiteManager = roleNum >= 8 || role.indexOf('store') !== -1 || (user && user.canReq === true);

            return qrPageData.filter(function (doc) {
                if (isSiteManager) {
                    if (site && (doc.siteCode || '') !== site) return false;
                } else {
                    if (uname && (doc.reqName || '').toLowerCase() !== uname.toLowerCase()) return false;
                }
                return true;
            }).length;
        }

         
         
        function updateNavBadges() {
             
             
             
             
            var nA = 0;
            if (Array.isArray(approvalQueueData)) {
                approvalQueueData.forEach(function (it) {
                    if (_approvalCanAct(it) || _approvalIsMine(it)) nA++;
                });
            }
             
            if (Array.isArray(signTasksData)) nA += signTasksData.length;
            var nQ = _visibleQRCount();
            var nC = (Array.isArray(confirmDocsData)   ? confirmDocsData.length   : 0) +
                     (Array.isArray(confirmClosingDocs) ? confirmClosingDocs.length : 0);

             
            _setBadge('navBadgeApprove',     nA);
            _setBadge('navBadgeQR',          nQ);
            _setBadge('navBadgeConfirm',     nC);
             
            _setBadge('navLinkBadgeApprove', nA);
            _setBadge('navLinkBadgeQR',      nQ);
            _setBadge('navLinkBadgeConfirm', nC);
             
             
            if (typeof scheduleNavReflow === 'function') scheduleNavReflow();
        }

         
         
         
        function togglePasswordVisibility(inputId, btn) {
            var input = document.getElementById(inputId);
            if (!input) return;
            var showing = input.type === 'text';
            input.type = showing ? 'password' : 'text';
            if (btn) {
                var icon = btn.querySelector('i');
                if (icon) {
                    icon.classList.toggle('fa-eye', showing);
                    icon.classList.toggle('fa-eye-slash', !showing);
                }
            }
        }

         
         
         
         
        function _stripThai(el) {
            if (!el) return;
            var v = el.value;
            var clean = v.replace(/[฀-๿]/g, '');
            if (clean !== v) {
                var pos = Math.max(0, (el.selectionStart || 0) - (v.length - clean.length));
                el.value = clean;
                try { el.setSelectionRange(pos, pos); } catch (_) {}
            }
        }
        function bindNoThaiOn(elId) {
            var el = document.getElementById(elId);
            if (!el || el.dataset.noThaiBound === '1') return;
            el.dataset.noThaiBound = '1';
            el.addEventListener('input', function () { _stripThai(el); });
            el.addEventListener('compositionend', function () { _stripThai(el); });
        }

        function submitChangeSite() {
            var siteSelect = document.getElementById('usSiteSelect');
            var newSite = siteSelect ? ($(siteSelect).val() || siteSelect.value) : '';
            var msgEl = document.getElementById('usSiteMsg');

            function setSiteMsg(text, ok) {
                if (msgEl) {
                    msgEl.textContent = text;
                    msgEl.style.color = ok ? '#10b981' : '#ef4444';
                }
            }

             
            if (!user || user.roleLevel !== 'R0') { setSiteMsg('❌ เฉพาะผู้ดูแลระบบเท่านั้นที่เปลี่ยน Site ได้', false); return; }
            if (!newSite) { setSiteMsg('⚠️ กรุณาเลือก Site', false); return; }
            if (!user || !user.username) { setSiteMsg('❌ ไม่พบข้อมูลผู้ใช้', false); return; }
            if (newSite === user.siteCode) { setSiteMsg('ℹ️ Site นี้คือ Site ปัจจุบันอยู่แล้ว', false); return; }

            var fromSite = user.siteCode || '';
            var roleId   = user.roleId   || '';

            setSiteMsg('🔄 กำลังบันทึก...', true);

            google.script.run
                .withSuccessHandler(function(res) {
                    if (res && res.success) {
                         
                        user.siteCode = newSite;
                         
                        try { sessionStorage.setItem('userData', JSON.stringify(user)); } catch(e) {}
                        try { localStorage.setItem('userData', JSON.stringify(user)); } catch(e) {}
                         
                        var currentSiteEl = document.getElementById('usCurrentSite');
                        if (currentSiteEl) currentSiteEl.textContent = newSite;
                        setSiteMsg('✅ ' + (res.message || 'เปลี่ยน Site สำเร็จ'), true);
                    } else {
                        setSiteMsg('❌ ' + (res && res.message ? res.message : 'เปลี่ยน Site ไม่สำเร็จ'), false);
                    }
                })
                .withFailureHandler(function(err) {
                    setSiteMsg('⚠️ เกิดข้อผิดพลาด: ' + (err.message || err), false);
                })
                .changeSite(user.username, newSite, fromSite, roleId);
        }
        </script>
</body>

</html>