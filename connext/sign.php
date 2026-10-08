<?php
/**
 * CONNEXT — sign.php : หน้าลงนามรับทราบของผู้รับเหมา (port จาก GAS SignPage.html
 * โดย tools/build_index.py) — เข้าผ่านลิงก์ token ไม่ต้องล็อกอิน
 * ห้ามแก้ไฟล์นี้ตรง ๆ — แก้ transform ใน tools/build_index.py แล้ว build ใหม่
 */
require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';   // ใช้แค่ csrfToken() — หน้านี้ไม่บังคับ login

$token = isset($_GET['token']) ? (string)$_GET['token'] : '';
if (!preg_match('/^[a-f0-9]{40}$/i', $token)) { $token = ''; }
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>CONNEXT | ลงนามรับทราบเอกสารหักเงิน</title>
    
    <link href="<?= assetHref('css/sarabun.css') ?>" rel="stylesheet">
    <meta name="csrf-token" content="<?= e(csrfToken()) ?>">
    <script>
        window.APP_BASE = "<?= APP_BASE ?>";
        window.__SERVER_USER = null;
    </script>
    <script src="<?= assetHref('js/gas-shim.js') ?>"></script>
    <style>
:root{--navy: #13294b;--navy-2: #1e3a8a;--gold: #f3c218;--gold-soft: #fdecc8;--bg: #f1f5f9;--card: #ffffff;--line: #e2e8f0;--text: #1e293b;--muted: #64748b;--green: #16a34a;--amber: #d97706;--red: #dc2626;--blue: #2563eb}*{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}html,body{background:var(--bg);font-family:"Sarabun",system-ui,sans-serif;color:var(--text)}body{min-height:100vh;padding-bottom:48px}.topbar{background:var(--navy);color:#fff;padding:14px 18px;display:flex;align-items:center;gap:11px}.topbar .logo{width:38px;height:38px;border-radius:9px;background:rgba(255,255,255,0.12);display:flex;align-items:center;justify-content:center;font-size:1.15rem}.topbar h1{font-size:1.02rem;font-weight:800;letter-spacing:0.4px;line-height:1.2}.topbar p{font-size:0.72rem;opacity:0.75}.wrap{max-width:760px;margin:0 auto;padding:14px 12px 30px}.state-box{text-align:center;padding:60px 20px;color:var(--muted)}.state-box .big{font-size:2.4rem;margin-bottom:12px}.spinner{width:34px;height:34px;border:3px solid var(--line);border-top-color:var(--navy-2);border-radius:50%;margin:0 auto 14px;animation:spin 0.8s linear infinite}@keyframes spin{to{transform:rotate(360deg)}}.card{background:var(--card);border:1px solid var(--line);border-radius:14px;overflow:hidden;box-shadow:0 1px 3px rgba(15,23,42,0.06);margin-bottom:13px}.doc-title{background:var(--navy);color:#fff;text-align:center;font-weight:700;font-size:0.82rem;padding:8px 10px;letter-spacing:0.2px}.doc-sub{background:var(--gold-soft);color:var(--navy);text-align:center;font-weight:800;font-size:1.12rem;padding:9px 10px}.doc-mango{background:#fff8e0;color:var(--navy);text-align:center;font-weight:600;font-size:0.83rem;padding:6px 10px;border-top:1px solid #f3e3ae}.doc-meta{display:grid;grid-template-columns:1fr 1fr}.doc-meta>div{padding:8px 12px;border-top:1px solid var(--line);font-size:0.85rem}.doc-meta .k{color:var(--muted);font-size:0.72rem;display:block}.doc-meta .v{font-weight:700}.banner{border-radius:12px;padding:11px 14px;font-size:0.86rem;font-weight:600;display:flex;gap:10px;align-items:flex-start;margin-bottom:13px;line-height:1.45}.banner.wait{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af}.banner.due{background:#fffbeb;border:1px solid #fde68a;color:#92400e}.banner.ok{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534}.banner.auto{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412}.banner .ic{font-size:1.05rem;line-height:1.3}.tbl-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch}table{border-collapse:collapse;width:100%;min-width:560px;font-size:0.8rem}thead th{background:var(--navy);color:#fff;font-weight:600;padding:7px 8px;white-space:nowrap}tbody td{padding:6px 8px;border-bottom:1px solid var(--line);vertical-align:top}tbody tr:nth-child(even){background:#f8fafc}.num{text-align:right;white-space:nowrap}.ctr{text-align:center;white-space:nowrap}.totals{padding:4px 0 2px}.totals .trow{display:flex;justify-content:flex-end;gap:14px;padding:5px 14px;font-size:0.88rem}.totals .trow .lb{color:var(--muted)}.totals .trow .vl{min-width:110px;text-align:right;font-weight:700}.totals .trow.net{background:var(--gold);margin-top:3px;padding:8px 14px;font-weight:800}.totals .trow.net .lb{color:var(--navy)}.sign-head{font-weight:800;font-size:0.95rem;padding:12px 14px 2px;color:var(--navy)}.sign-body{padding:10px 14px 16px}.field-lb{font-size:0.78rem;color:var(--muted);font-weight:600;margin:8px 0 4px;display:block}.inp{width:100%;border:1.5px solid var(--line);border-radius:10px;padding:10px 12px;font-family:inherit;font-size:0.95rem;background:#fff}.inp:focus{outline:none;border-color:var(--navy-2)}.pad-shell{position:relative;border:1.8px dashed #94a3b8;border-radius:12px;background:#fff}.pad-shell.inked{border-style:solid;border-color:var(--navy-2)}canvas#sigPad{display:block;width:100%;height:170px;border-radius:12px;touch-action:none}.pad-hint{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#b6c2d4;font-size:0.9rem;pointer-events:none;font-weight:600}.pad-tools{display:flex;justify-content:flex-end;margin-top:7px}.bound-box{border:1.8px solid #86efac;background:#f0fdf4;border-radius:12px;padding:12px;margin-bottom:12px}.bound-title{font-size:0.82rem;font-weight:700;color:#166534;display:flex;align-items:center;gap:6px}.bound-sig{text-align:center;background:#fff;border:1px solid #bbf7d0;border-radius:10px;padding:8px;margin:9px 0}.bound-sig img{max-width:240px;max-height:92px}.btn-bound{width:100%;background:#16a34a;color:#fff;font-size:1rem;padding:13px;display:flex;align-items:center;justify-content:center;gap:8px}.btn-bound:disabled{opacity:0.55}.bound-or{text-align:center;color:var(--muted);font-size:0.78rem;font-weight:700;margin:12px 0 4px}.bound-hint{font-size:0.72rem;color:#3f6212;margin-top:7px;line-height:1.45}.btn{border:none;border-radius:11px;font-family:inherit;font-weight:700;cursor:pointer}.btn-clear{background:#f1f5f9;color:var(--muted);font-size:0.8rem;padding:7px 14px}.btn-submit{width:100%;background:var(--navy-2);color:#fff;font-size:1.02rem;padding:14px;margin-top:14px;display:flex;align-items:center;justify-content:center;gap:9px}.btn-submit:disabled{opacity:0.55}.sig-show{text-align:center;padding:14px}.sig-show img{max-width:240px;max-height:96px}.sig-show .nm{font-weight:700;margin-top:4px}.sig-show .dt{color:var(--muted);font-size:0.8rem;margin-top:2px}.foot{text-align:center;color:#94a3b8;font-size:0.72rem;margin-top:18px;line-height:1.5}.note-price{font-size:0.72rem;color:var(--muted);padding:7px 12px;background:#f8fafc;border-top:1px solid var(--line)}





























































































</style>
</head>
<body>
    <div class="topbar">
        <div class="logo">🖋️</div>
        <div>
            <h1>CONNEXT — ลงนามรับทราบเอกสารหักเงิน</h1>
            <p>Inventory Control Module · เอกสารแนบประกอบการหักเงินค่าวัสดุ</p>
        </div>
    </div>

    <div class="wrap">
        <div id="stLoading" class="state-box">
            <div class="spinner"></div>
            กำลังโหลดข้อมูลเอกสาร...
        </div>
        <div id="stError" class="state-box" style="display:none;">
            <div class="big">⚠️</div>
            <div id="stErrorMsg" style="font-weight:600;">ไม่พบลิงก์นี้</div>
            <div style="font-size:0.82rem; margin-top:8px;">ลิงก์อาจถูกยกเลิกหรือพิมพ์ไม่ครบ กรุณาติดต่อผู้ส่งเอกสาร</div>
        </div>

        <div id="content" style="display:none;">
            <div id="statusBanner"></div>

            <div class="card">
                <div class="doc-title" id="docTitle">CONNEXT — รายการหักเงิน : ผู้รับเหมาชุด (แนบประกอบการหักเงินค่าวัสดุ)</div>
                <div class="doc-sub" id="dSub">—</div>
                <div class="doc-mango" id="dMango" style="display:none;"></div>
                <div class="doc-meta">
                    <div><span class="k">ประจำเดือน</span><span class="v" id="dMonth">—</span></div>
                    <div><span class="k">วันที่ (ตามใบ)</span><span class="v" id="dDays">—</span></div>
                    <div><span class="k">Site</span><span class="v" id="dSite">—</span></div>
                    <div><span class="k">เลขที่เอกสาร</span><span class="v" id="dDocNo">—</span></div>
                </div>
            </div>

            <div class="card">
                <div class="tbl-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>ลำดับ</th><th>ว/ด/ป</th><th>เลขที่</th><th style="text-align:left;">รายการ</th>
                                <th>หน่วย</th><th>ปริมาณ</th><th>ราคา/หน่วย</th><th>จำนวนเงิน (บาท)</th>
                            </tr>
                        </thead>
                        <tbody id="itemRows"></tbody>
                    </table>
                </div>
                <div class="totals">
                    <div class="trow net"><span class="lb" id="tNetLb">รวมเงินหักทั้งสิ้น</span><span class="vl" id="tNet">0.00</span></div>
                </div>
                <div class="note-price" id="notePrice">หมายเหตุ: รายการที่ยังไม่ได้ตั้งราคาจะแสดงราคา/จำนวนเงินเป็น “—” · ราคาอ้างอิงจาก Rate Card ของ Site</div>
            </div>

            <div class="card" id="signCard">
                <div class="sign-head">🖊️ ลงนามรับทราบรายการหักเงิน</div>
                <div class="sign-body">
                    <label class="field-lb">ชื่อผู้ลงนาม (ผู้รับเหมา)</label>
                    <input type="text" id="signerName" class="inp" placeholder="ชื่อ-สกุล ผู้ลงนาม">

                    
                    <div class="bound-box" id="boundBox" style="display:none; margin-top:12px;">
                        <div class="bound-title">🖋️ ลายเซ็นที่ผูกไว้กับชุดนี้</div>
                        <div class="bound-sig"><img id="boundSigImg" alt="ลายเซ็นผู้รับเหมา"></div>
                        <button type="button" class="btn btn-bound" id="btnBound" onclick="submitSign(true)">
                            ✅ ยืนยันรับทราบด้วยลายเซ็นนี้
                        </button>
                        <div class="bound-hint">กดปุ่มนี้ = รับทราบรายการข้างต้นและลงนามด้วยลายเซ็นที่ผูกไว้ในระบบ — ไม่ต้องเซ็นใหม่</div>
                    </div>
                    <div class="bound-or" id="boundOr" style="display:none;">— หรือเซ็นสดด้านล่าง —</div>

                    <label class="field-lb">ลายเซ็น — เซ็นในกรอบด้านล่าง</label>
                    <div class="pad-shell" id="padShell">
                        <canvas id="sigPad"></canvas>
                        <div class="pad-hint" id="padHint">✍️ เซ็นชื่อที่นี่</div>
                    </div>
                    <div class="pad-tools">
                        <button type="button" class="btn btn-clear" onclick="clearPad()">ล้างลายเซ็น</button>
                    </div>
                    <button type="button" class="btn btn-submit" id="btnSubmit" onclick="submitSign()">
                        ✅ ยืนยันลงนามรับทราบ
                    </button>
                </div>
            </div>

            <div class="card" id="signedCard" style="display:none;">
                <div class="sign-head" style="color:var(--green);">✅ ลงนามรับทราบแล้ว</div>
                <div class="sig-show">
                    <img id="signedImg" alt="ลายเซ็น" style="display:none;">
                    <div class="nm" id="signedName">—</div>
                    <div class="dt" id="signedAt">—</div>
                </div>
            </div>

            <div class="foot">
                เอกสารนี้สร้างจากระบบ CONNEXT — Inventory Control Module<br>
                การลงนามผ่านลิงก์นี้ถือเป็นการรับทราบรายการหักเงินตามที่แสดงข้างต้น
            </div>
        </div>
    </div>

    <script>
        var TOKEN = <?= json_encode($token) ?>;
        var pageData = null;

         
         
        try { if (window.parent && window.parent !== window) window.parent.postMessage({ connext: 'sign-ready' }, '*'); } catch (e) {}

         
        function $(id) { return document.getElementById(id); }
        function esc(s) {
            return (s == null ? '' : String(s)).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }
        function money(n) {
            if (n === null || n === undefined || isNaN(n)) return '—';
            return Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        var TH_MON = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
        function thDateTime(ms) {
            if (!ms) return '';
            var d = new Date(ms);
            var hh = ('0' + d.getHours()).slice(-2), mm = ('0' + d.getMinutes()).slice(-2);
            return d.getDate() + ' ' + TH_MON[d.getMonth()] + ' ' + ((d.getFullYear() + 543) % 100) + ' เวลา ' + hh + ':' + mm + ' น.';
        }

         
        function loadPage() {
            if (!TOKEN) { showError('ลิงก์ไม่ถูกต้อง (ไม่มีรหัสเอกสาร)'); return; }
            google.script.run
                .withSuccessHandler(render)
                .withFailureHandler(function (err) { showError(err && err.message ? err.message : 'โหลดข้อมูลไม่สำเร็จ'); })
                .getSignPageData(TOKEN);
        }
        function showError(msg) {
            $('stLoading').style.display = 'none';
            $('content').style.display = 'none';
            $('stError').style.display = 'block';
            $('stErrorMsg').textContent = msg || 'ไม่พบลิงก์นี้';
        }

        function render(res) {
            if (!res || !res.success) { showError(res && res.message); return; }
            pageData = res;
            var d = res.detail || {};
            $('stLoading').style.display = 'none';
            $('content').style.display = 'block';

             
            if (d.fsDoc) {
                $('docTitle').textContent = 'CONNEXT — สรุปค่าปรับการแสกนนิ้วแรงงานผู้รับเหมา';
                $('tNetLb').textContent = 'รวมค่าปรับทั้งสิ้น';
                $('notePrice').textContent = 'หมายเหตุ: ค่าปรับ = จำนวนแรงงานที่ไม่แสกนนิ้ว × อัตราของไซต์ · วันที่ "ไม่แจ้งยอด" คิดจากจำนวนคนที่มาแสกน';
            }
            $('dSub').textContent = 'ผู้รับเหมาชุด : ' + (d.subName || '—');
            if (d.mangoCode || d.mangoName) {
                $('dMango').style.display = 'block';
                $('dMango').textContent = 'Mango Vendor : ' + (d.mangoCode || '-') + ' · ' + (d.mangoName || '-');
            }
            $('dMonth').textContent = d.monthLabel || '—';
             
            $('dDays').textContent = (!d.days || d.days === 'ทั้งเดือน') ? 'ทั้งเดือน'
                : (d.days.indexOf('งวด') === 0 ? d.days : 'วันที่ ' + d.days);
            $('dSite').textContent = (d.siteCode || '—') + (d.siteName && d.siteName !== '-' ? ' · ' + d.siteName : '');
            $('dDocNo').textContent = d.docNo || '—';

            var rows = '';
            (d.items || []).forEach(function (it) {
                rows += '<tr>' +
                    '<td class="ctr">' + it.no + '</td>' +
                    '<td class="ctr">' + esc(it.dateTh) + '</td>' +
                    '<td class="ctr">' + esc(it.docId) + '</td>' +
                    '<td>' + esc(it.name) + '</td>' +
                    '<td class="ctr">' + esc(it.unit || '-') + '</td>' +
                    '<td class="ctr">' + esc(it.qty) + '</td>' +
                    '<td class="num">' + money(it.price) + '</td>' +
                    '<td class="num">' + money(it.amount) + '</td>' +
                '</tr>';
            });
            if (!rows) rows = '<tr><td colspan="8" style="text-align:center; color:#94a3b8; padding:16px;">— ไม่มีรายการ —</td></tr>';
            $('itemRows').innerHTML = rows;
            $('tNet').textContent = money(d.total || 0);

            $('signerName').value = '';
            $('signerName').placeholder = d.subName ? ('เช่น ' + d.subName) : 'ชื่อ-สกุล ผู้ลงนาม';

             
            if (res.boundSig) {
                $('boundSigImg').src = res.boundSig;
                $('boundBox').style.display = 'block';
                $('boundOr').style.display = 'block';
            } else {
                $('boundBox').style.display = 'none';
                $('boundOr').style.display = 'none';
            }

            renderStatus(res);
            if (res.status !== 'signed') initPad();
        }

        function renderStatus(res) {
            var b = $('statusBanner');
            if (res.status === 'signed') {
                b.innerHTML = '<div class="banner ok"><span class="ic">✅</span><span>ลงนามรับทราบเรียบร้อยแล้ว โดย <b>' +
                    esc(res.signerName || '-') + '</b>' + (res.signedAt ? ' · ' + thDateTime(res.signedAt) : '') + '</span></div>';
                $('signCard').style.display = 'none';
                $('signedCard').style.display = 'block';
                $('signedName').textContent = '( ' + (res.signerName || '-') + ' )';
                $('signedAt').textContent = res.signedAt ? 'ลงนามเมื่อ ' + thDateTime(res.signedAt) : '';
                if (res.signatureData) {
                    $('signedImg').src = res.signatureData;
                    $('signedImg').style.display = 'inline-block';
                }
            } else if (res.status === 'auto') {
                b.innerHTML = '<div class="banner auto"><span class="ic">⏰</span><span>เอกสารนี้เลยกำหนดตอบกลับแล้ว ระบบบันทึกเป็น ' +
                    '<b>“รับทราบโดยปริยาย”</b>' + (res.signedAt ? ' (ครบกำหนด ' + thDateTime(res.signedAt) + ')' : '') +
                    '<br>คุณยังสามารถลงนามจริงด้านล่างได้ — ลายเซ็นจริงจะแทนที่สถานะปริยาย</span></div>';
            } else {
                var dl = res.deadlineAt
                    ? '<div class="banner due"><span class="ic">⏳</span><span>กรุณาตรวจสอบรายการและลงนามภายใน <b>' +
                      thDateTime(res.deadlineAt) + '</b> — หากเลยกำหนด ระบบจะถือว่า “รับทราบโดยปริยาย”</span></div>'
                    : '<div class="banner wait"><span class="ic">📋</span><span>กรุณาตรวจสอบรายการหักเงินด้านล่าง แล้วลงนามรับทราบ</span></div>';
                b.innerHTML = dl;
            }
        }

         
        var padCtx = null, padInked = false, padCanvas = null;
        function initPad() {
            padCanvas = $('sigPad');
            var ratio = Math.max(window.devicePixelRatio || 1, 1);
            var w = padCanvas.clientWidth, h = padCanvas.clientHeight;
            if (!w) { setTimeout(initPad, 120); return; }
            padCanvas.width = w * ratio;
            padCanvas.height = h * ratio;
            padCtx = padCanvas.getContext('2d');
            padCtx.scale(ratio, ratio);
            padCtx.lineWidth = 2.4;
            padCtx.lineCap = 'round';
            padCtx.lineJoin = 'round';
            padCtx.strokeStyle = '#1e2a5a';

            var drawing = false, last = null;
            function pos(ev) {
                var rect = padCanvas.getBoundingClientRect();
                return { x: ev.clientX - rect.left, y: ev.clientY - rect.top };
            }
            padCanvas.addEventListener('pointerdown', function (ev) {
                ev.preventDefault();
                drawing = true; last = pos(ev);
                padCanvas.setPointerCapture(ev.pointerId);
            });
            padCanvas.addEventListener('pointermove', function (ev) {
                if (!drawing) return;
                ev.preventDefault();
                var p = pos(ev);
                padCtx.beginPath();
                padCtx.moveTo(last.x, last.y);
                var mid = { x: (last.x + p.x) / 2, y: (last.y + p.y) / 2 };
                padCtx.quadraticCurveTo(last.x, last.y, mid.x, mid.y);
                padCtx.lineTo(p.x, p.y);
                padCtx.stroke();
                last = p;
                if (!padInked) { padInked = true; $('padHint').style.display = 'none'; $('padShell').classList.add('inked'); }
            });
            function up() { drawing = false; last = null; }
            padCanvas.addEventListener('pointerup', up);
            padCanvas.addEventListener('pointercancel', up);
            padCanvas.addEventListener('pointerleave', up);
        }
        function clearPad() {
            if (!padCtx || !padCanvas) return;
            padCtx.save();
            padCtx.setTransform(1, 0, 0, 1, 0, 0);
            padCtx.clearRect(0, 0, padCanvas.width, padCanvas.height);
            padCtx.restore();
            padInked = false;
            $('padHint').style.display = 'flex';
            $('padShell').classList.remove('inked');
        }
         
        function padDataUrl() {
            if (!padInked || !padCanvas) return '';
            var out = document.createElement('canvas');
            out.width = 400; out.height = 160;
            var ctx = out.getContext('2d');
            ctx.drawImage(padCanvas, 0, 0, padCanvas.width, padCanvas.height, 0, 0, 400, 160);
            return out.toDataURL('image/png');
        }

         
         
        function submitSign(useBound) {
            var dataUrl = '';
            if (!useBound) {
                if (!padInked) { alert('กรุณาเซ็นลายเซ็นในกรอบก่อนกดยืนยัน'); return; }
                dataUrl = padDataUrl();
                if (!dataUrl || dataUrl.length > 45000) { alert('ลายเซ็นมีขนาดใหญ่เกินไป กรุณากด "ล้างลายเซ็น" แล้วเซ็นใหม่แบบเรียบง่ายขึ้น'); return; }
            }
            var btn = useBound ? $('btnBound') : $('btnSubmit');
            var label = useBound ? '✅ ยืนยันรับทราบด้วยลายเซ็นนี้' : '✅ ยืนยันลงนามรับทราบ';
            var other = useBound ? $('btnSubmit') : $('btnBound');
            btn.disabled = true;
            btn.innerHTML = '⏳ กำลังบันทึกลายเซ็น...';
            if (other) other.disabled = true;
            var restore = function () {
                btn.disabled = false;
                btn.innerHTML = label;
                if (other) other.disabled = false;
            };
            google.script.run
                .withSuccessHandler(function (res) {
                    if (!res || !res.success) {
                        restore();
                        if (res && res.already) { loadFresh(); return; }
                        alert((res && res.message) ? res.message : 'บันทึกไม่สำเร็จ กรุณาลองใหม่');
                        return;
                    }
                    loadFresh();
                })
                .withFailureHandler(function (err) {
                    restore();
                    alert('บันทึกไม่สำเร็จ: ' + (err && err.message ? err.message : 'กรุณาลองใหม่'));
                })
                .submitContractorSignature({
                    token: TOKEN,
                    signerName: ($('signerName').value || '').trim(),
                    dataUrl: dataUrl,
                    useBound: useBound === true
                });
        }
        function loadFresh() {
            $('content').style.display = 'none';
            $('stLoading').style.display = 'block';
            loadPage();
        }

        loadPage();
    </script>
</body>
</html>
