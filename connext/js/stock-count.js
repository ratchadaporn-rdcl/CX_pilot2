/*
 * CONNEXT — js/stock-count.js  [PHP port 2026-09-29 · SC ใบนับสต๊อก — QR เข้า gate จากหน้าตรวจสอบประจำวัน]
 *
 * 1) หน้า "ตรวจสอบประจำวัน": แท็บ "นับสต๊อก" — KPI Stock Accuracy 30 วัน · ประตูของไซต์ (จำนวนรายการ · นับล่าสุด) ·
 *    สร้างใบนับ (รายการ = ทุกรายการที่มียอดใน G นั้น) → QR → สแกนที่ตู้ + แตะบัตร (รอบนับต้องแยกจากใบอื่น)
 * 2) ประตูเปิด → หน้ากรอกผลนับแบบไม่เห็นยอดในระบบ (blind) · ร่างเก็บในเครื่อง · ของที่พบเพิ่ม · บันทึก = ยืนยัน
 *    (ประตูไม่มีตู้ = ปิดงานทันที · ประตูมีตู้ = ปิดประตูแล้วจบรอบ) — ไม่ตัด/ไม่เพิ่มสต๊อก
 * 3) ผลการนับ: ในระบบ · นับได้ · ผลต่าง · สถานะปรับยอด — ADM / R8 ขึ้นไป อนุมัติ (ยอดที่ G ขยับเท่าผลต่าง) / ไม่อนุมัติ (ต้องมีเหตุผล)
 * 4) หน้า "การอนุมัติ": กล่อง "อนุมัติปรับยอดสต๊อก (จากใบนับ)" สำหรับ ADM / R8+
 * RPC ฝั่ง server: lib/stockcount.php · api/gate.php (รอบนับแยก) · lib/approval.php (cancelRequisition SC)
 * ไม่มีใน GAS — ถ้า build index.php ใหม่ให้เติม <link>/<script defer> ไฟล์นี้กลับก่อน </head>
 */
(function () {
    'use strict';

    var S = { data: null, sheet: null, loading: false, pollTimer: null, pollBusy: false, adjQueue: null, saving: false };
    var DRAFT = 'cnx.sc.draft.';
    var STAGE_CLS = { awaiting: 'wait', counting: 'live', counted: 'live', done: 'done', cancelled: 'dead' };

    // ------------------------------------------------------------------ ตัวช่วย
    function $(sel, root) { return (root || document).querySelector(sel); }
    function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
    function esc(v) {
        if (typeof window.escapeHtml === 'function') return window.escapeHtml(v == null ? '' : String(v));
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function num(v) {
        var n = Math.round(Number(v) * 1000) / 1000;
        return isFinite(n) ? n.toLocaleString('th-TH', { maximumFractionDigits: 3 }) : '0';
    }
    function signed(v) { var n = Math.round(Number(v) * 1000) / 1000; return (n > 0 ? '+' : n < 0 ? '−' : '') + num(Math.abs(n)); }
    function toast(m, t) { if (typeof window.showToast === 'function') window.showToast(m, t || 'info'); }
    function info(title, msg, type, onClose) {
        if (typeof window.showInfoPopup === 'function') window.showInfoPopup(title, msg, type || 'info', onClose);
        else { alert(title + '\n' + msg); if (onClose) onClose(); }
    }
    function fullName(u) {
        var n = typeof window.userFullName === 'function' ? window.userFullName(u) : '';
        return n || u || '-';
    }
    function me() { return (window.user && window.user.username) || ''; }
    function roleNum() {
        var m = String((window.user && window.user.roleLevel) || '').match(/\d+/);
        return m ? parseInt(m[0], 10) : 99;
    }
    function maybeDecider() {   // ตัวกรองฝั่งหน้าจอ — สิทธิ์จริงตัดสินที่ server (ADM หรือ R8+)
        var u = window.user || {};
        return String(u.roleId || '').toUpperCase() === 'ADM' || (roleNum() >= 8 && roleNum() < 99) || roleNum() === 0;
    }
    function rpc(fn, args, ok, fail) {
        var r = google.script.run.withSuccessHandler(ok).withFailureHandler(function (err) {
            var msg = (err && err.message) ? err.message : String(err || 'เชื่อมต่อไม่สำเร็จ');
            if (fail) fail(msg); else info('ผิดพลาด', esc(msg), 'danger');
        });
        r[fn].apply(r, args || []);
    }
    function storeGet(k) { try { return JSON.parse(window.localStorage.getItem(k) || 'null'); } catch (e) { return null; } }
    function storeSet(k, v) { try { window.localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
    function storeDel(k) { try { window.localStorage.removeItem(k); } catch (e) {} }
    function wrapAfter(name, fn) {
        var orig = window[name];
        if (typeof orig !== 'function' || orig.__scWrapped) return;
        var w = function () {
            var r = orig.apply(this, arguments);
            try { fn.apply(this, arguments); } catch (e) { console.warn('[stock-count] ' + name + ':', e); }
            return r;
        };
        w.__scWrapped = true;
        window[name] = w;
    }
    function isScDoc(id) { return /^SC\d/i.test(String(id || '')); }

    // ------------------------------------------------------------------ แท็บ "นับสต๊อก" ในหน้าตรวจสอบประจำวัน
    function injectTab() {
        var page = document.getElementById('dailycheck-page');
        var cont = page && page.querySelector('.tabs-container');
        var header = cont && cont.querySelector('.tabs-header');
        if (!header || document.getElementById('dc-tab-count')) return;
        var btn = document.createElement('button');
        btn.className = 'tab-btn';
        btn.id = 'dcTabCountBtn';
        btn.setAttribute('onclick', "switchDailyCheckTab('count', this)");
        btn.innerHTML = '<i class="fa-solid fa-clipboard-list"></i> นับสต๊อก';
        header.appendChild(btn);
        var pane = document.createElement('div');
        pane.id = 'dc-tab-count';
        pane.style.display = 'none';
        pane.innerHTML = '<div id="scBody"><div class="qr-empty"><i class="fa-solid fa-spinner fa-spin"></i><span>กำลังโหลด...</span></div></div>';
        cont.appendChild(pane);
    }

    function tabVisible() {
        var pane = document.getElementById('dc-tab-count');
        var page = document.getElementById('dailycheck-page');
        return !!(pane && pane.style.display !== 'none' && page && page.classList.contains('active-page'));
    }

    function loadTab(quiet) {
        var body = $('#scBody');
        if (!body || S.loading) return;
        if (!quiet && !S.data) body.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-spinner fa-spin"></i><span>กำลังโหลด...</span></div>';
        S.loading = true;
        rpc('getStockCountData', [], function (res) {
            S.loading = false;
            if (!res || !res.success) {
                var m = (res && res.message) === 'no_permission' ? 'บัญชีนี้ไม่มีสิทธิ์นับสต๊อก (ต้องมีสิทธิ์ตรวจสอบประจำวัน) หรืออนุมัติปรับยอด (ADM / R8 ขึ้นไป)' : ((res && res.message) || 'โหลดไม่สำเร็จ');
                body.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-lock"></i><span>' + esc(m) + '</span></div>';
                return;
            }
            var prev = S.data;
            S.data = res;
            renderTab();
            notifyStageChanges(prev, res);
            schedulePoll();
        }, function (m) {
            S.loading = false;
            if (!quiet) body.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-triangle-exclamation"></i><span>' + esc(m) + '</span></div>';
        });
    }

    function notifyStageChanges(prev, cur) {
        if (!prev) return;
        var before = {};
        (prev.docs || []).forEach(function (d) { before[d.docId] = d.stage; });
        (cur.docs || []).forEach(function (d) {
            if (before[d.docId] === 'awaiting' && d.stage === 'counting') {
                toast('🔓 ' + d.docId + ' ประตูเปิดแล้ว — กด "กรอกผลนับ"', 'success');
                if (typeof window.hapticSuccess === 'function') window.hapticSuccess();
            }
        });
    }

    function schedulePoll() {
        if (S.pollTimer) { clearTimeout(S.pollTimer); S.pollTimer = null; }
        var waiting = ((S.data && S.data.docs) || []).some(function (d) { return d.stage === 'awaiting'; });
        if (!waiting) return;
        S.pollTimer = setTimeout(function () {
            S.pollTimer = null;
            if (tabVisible() && document.visibilityState !== 'hidden' && !$('#scSheetModal.open')) loadTab(true);
            else schedulePoll();
        }, 8000);
    }

    function kpiHtml(k, canDecide) {
        var acc = k.accuracy30 == null ? '-' : (k.accuracy30 + '%');
        var accCls = k.accuracy30 == null ? '' : (k.accuracy30 >= 98 ? 'good' : (k.accuracy30 >= 90 ? 'mid' : 'bad'));
        return '<div class="sc-kpis">' +
            '<div class="sc-kpi ' + accCls + '"><div class="sc-kpi-v">' + esc(acc) + '</div><div class="sc-kpi-l">Stock Accuracy 30 วัน</div>' +
                '<div class="sc-kpi-s">นับตรงยอด ' + num(k.matched30 || 0) + ' / ' + num(k.counted30 || 0) + ' รายการ</div></div>' +
            '<div class="sc-kpi"><div class="sc-kpi-v">' + num(k.docs30 || 0) + '</div><div class="sc-kpi-l">ใบนับที่ปิดงาน 30 วัน</div></div>' +
            '<div class="sc-kpi ' + ((k.pendingAdjust || 0) > 0 ? 'warn' : '') + '"><div class="sc-kpi-v">' + num(k.pendingAdjust || 0) + '</div>' +
                '<div class="sc-kpi-l">รายการรออนุมัติปรับยอด</div><div class="sc-kpi-s">' + (canDecide ? 'คุณอนุมัติได้' : 'ADM / R8 ขึ้นไป อนุมัติ') + '</div></div>' +
        '</div>';
    }

    function renderTab() {
        var d = S.data || {};
        var body = $('#scBody');
        var gates = d.gates || [];
        var gatesHtml = gates.length ? gates.map(function (g) {
            var act = '';
            if (g.openDoc) {
                var st = g.openStage;
                if (st === 'awaiting') act = '<button type="button" class="btn btn-primary sc-act" data-act="qr" data-doc="' + esc(g.openDoc) + '"><i class="fa-solid fa-qrcode"></i> ดู QR</button>';
                else if (st === 'counting') act = '<button type="button" class="btn btn-primary sc-act" data-act="sheet" data-doc="' + esc(g.openDoc) + '"><i class="fa-solid fa-pen-to-square"></i> กรอกผลนับ</button>';
                else act = '<button type="button" class="btn btn-secondary sc-act" data-act="sheet" data-doc="' + esc(g.openDoc) + '"><i class="fa-solid fa-list-check"></i> ดูผลนับ</button>';
            } else if (d.canCount) {
                act = '<button type="button" class="btn btn-primary sc-act" data-act="create" data-gate="' + esc(g.code) + '"' + (g.itemCount ? '' : ' disabled') + '><i class="fa-solid fa-clipboard-list"></i> สร้างใบนับ + QR</button>';
            }
            var last = g.lastDoc
                ? 'นับล่าสุด ' + esc(g.lastAt || '-') + (g.lastAccuracy != null ? ' · ตรง ' + esc(g.lastAccuracy) + '%' : '')
                : 'ยังไม่เคยนับ';
            var stageTxt = g.openDoc ? '<div class="sc-gate-open">' + esc(g.openDoc) + ' — ' + esc(stageThai(g.openStage)) + '</div>' : '';
            return '<div class="sc-gate">' +
                '<div class="sc-gate-head"><div class="sc-gate-code"><i class="fa-solid fa-door-open"></i> ' + esc(g.code) + '</div>' +
                    '<div class="sc-gate-name">' + esc(g.name && g.name !== g.code ? g.name : '') + (g.scanFlow ? ' <span class="sc-tag">ไม่มีตู้</span>' : '') + '</div></div>' +
                '<div class="sc-gate-meta">' + num(g.itemCount) + ' รายการที่มียอด · ' + last + '</div>' + stageTxt +
                '<div class="sc-gate-act">' + act + '</div>' +
            '</div>';
        }).join('') : '<div class="td-empty">ไซต์นี้ยังไม่มีประตู</div>';

        var docs = d.docs || [];
        var docsHtml = docs.length ? docs.map(function (x) {
            var s = x.summary || {};
            var sum = x.stage === 'awaiting' || x.stage === 'counting' || x.stage === 'cancelled'
                ? num(s.items || 0) + ' รายการ'
                : 'นับ ' + num(s.counted || 0) + ' · ตรง ' + num(s.match || 0) + ' · ต่าง ' + num(s.diff || 0) +
                  (s.accuracy != null ? ' (' + esc(s.accuracy) + '%)' : '') +
                  (s.pending ? ' · <b class="sc-pend">รออนุมัติ ' + num(s.pending) + '</b>' : '') +
                  (s.approved ? ' · ปรับแล้ว ' + num(s.approved) : '') + (s.rejected ? ' · ไม่ปรับ ' + num(s.rejected) : '');
            var btns = '';
            if (x.stage === 'awaiting') btns += '<button type="button" class="btn btn-primary sc-act" data-act="qr" data-doc="' + esc(x.docId) + '"><i class="fa-solid fa-qrcode"></i> QR</button>';
            if (x.stage === 'counting') btns += '<button type="button" class="btn btn-primary sc-act" data-act="sheet" data-doc="' + esc(x.docId) + '"><i class="fa-solid fa-pen-to-square"></i> กรอกผลนับ</button>';
            if (x.stage === 'counted' || x.stage === 'done') btns += '<button type="button" class="btn btn-secondary sc-act" data-act="sheet" data-doc="' + esc(x.docId) + '"><i class="fa-solid fa-list-check"></i> ผลนับ</button>';
            if (x.stage === 'counted' || x.stage === 'done') btns += '<button type="button" class="btn btn-secondary sc-act" data-act="pdf" data-doc="' + esc(x.docId) + '"><i class="fa-solid fa-file-pdf"></i></button>';
            if (x.canCancel) btns += '<button type="button" class="btn btn-danger sc-act" data-act="cancel" data-doc="' + esc(x.docId) + '"><i class="fa-solid fa-ban"></i></button>';
            return '<div class="sc-doc ' + (STAGE_CLS[x.stage] || '') + '">' +
                '<div class="sc-doc-main"><div class="sc-doc-id">' + esc(x.docId) + ' <span class="sc-gate-chip">' + esc(x.gate) + '</span></div>' +
                    '<div class="sc-doc-meta">สร้าง ' + esc(x.createdAt) + ' โดย ' + esc(fullName(x.createdBy)) +
                    (x.countedBy ? ' · นับโดย ' + esc(fullName(x.countedBy)) + ' ' + esc(x.countedAt) : '') + '</div>' +
                    '<div class="sc-doc-sum">' + sum + '</div></div>' +
                '<div class="sc-doc-side"><span class="sc-stage">' + esc(x.stageThai) + '</span><div class="sc-doc-btns">' + btns + '</div></div>' +
            '</div>';
        }).join('') : '<div class="td-empty"><i class="fa-solid fa-inbox"></i> ยังไม่มีใบนับ</div>';

        body.innerHTML = kpiHtml(d.kpi || {}, d.canDecide) +
            '<div class="sc-how"><i class="fa-solid fa-circle-info"></i> สร้างใบนับของประตู → สแกน QR ที่ตู้ประตูนั้นแล้วแตะบัตร (เปิดประตูแยกรอบ ไม่รวมกับใบเบิก) → ' +
                'นับของจริงแล้วกรอก (ระบบไม่แสดงยอดในระบบระหว่างนับ) → บันทึก → ปิดประตู · ผลต่างรอ ADM / R8 ขึ้นไปอนุมัติปรับยอด</div>' +
            '<div class="sc-sec-h"><h3><i class="fa-solid fa-door-open"></i> ประตูของไซต์</h3>' +
                '<button type="button" class="page-refresh-btn sc-refresh" id="scRefresh" title="โหลดใหม่"><i class="fa-solid fa-arrows-rotate"></i></button></div>' +
            '<div class="sc-gates">' + gatesHtml + '</div>' +
            '<div class="sc-sec-h"><h3><i class="fa-solid fa-clipboard-list"></i> ใบนับสต๊อก <span class="td-sub">(60 วันล่าสุด + ที่ยังไม่จบ)</span></h3></div>' +
            '<div class="sc-docs">' + docsHtml + '</div>';

        $('#scRefresh').addEventListener('click', function () { loadTab(false); });
        $$('#scBody .sc-act').forEach(function (b) {
            b.addEventListener('click', function () {
                var act = b.getAttribute('data-act');
                var doc = b.getAttribute('data-doc');
                if (act === 'create') createCount(b.getAttribute('data-gate'), b);
                else if (act === 'qr') showQr(doc);
                else if (act === 'sheet') openSheet(doc);
                else if (act === 'pdf' && typeof window.downloadDocReport === 'function') window.downloadDocReport(doc, 'SC');
                else if (act === 'cancel') cancelCount(doc);
            });
        });
    }

    function stageThai(st) {
        return { awaiting: 'รอสแกน QR ที่ประตู', counting: 'ประตูเปิดแล้ว — รอกรอกผลนับ', counted: 'บันทึกผลนับแล้ว — รอปิดประตู',
                 done: 'ปิดงานแล้ว', cancelled: 'ยกเลิกแล้ว' }[st] || st || '-';
    }

    function docById(id) {
        var list = (S.data && S.data.docs) || [];
        for (var i = 0; i < list.length; i++) { if (list[i].docId === id) return list[i]; }
        return null;
    }

    function createCount(gate, btn) {
        var g = null;
        ((S.data && S.data.gates) || []).forEach(function (x) { if (x.code === gate) g = x; });
        var go = function () {
            if (btn) btn.disabled = true;
            rpc('createStockCount', [gate], function (res) {
                if (btn) btn.disabled = false;
                if (!res || !res.success) { info('สร้างใบนับไม่สำเร็จ', esc((res && res.message) || '-').replace(/\n/g, '<br>'), 'danger'); return; }
                var after = function () {
                    loadTab(true);
                    if (res.stage === 'awaiting' || !res.stage) showQr(res.docId, res.gate, res.itemCount);
                    else openSheet(res.docId);
                };
                if (res.warning) info('สร้างใบนับ ' + res.docId + ' แล้ว — มีข้อควรระวัง', esc(res.warning), 'warning', after);
                else { toast(res.message || ('สร้างใบนับ ' + res.docId), 'success'); after(); }
            }, function (m) { if (btn) btn.disabled = false; info('สร้างใบนับไม่สำเร็จ', esc(m), 'danger'); });
        };
        var msg = 'สร้างใบนับสต๊อกของประตู <b>' + esc(gate) + '</b>' + (g ? ' (' + num(g.itemCount) + ' รายการที่มียอด)' : '') +
                  '<br><br>จากนั้นสแกน QR ที่ตู้ประตู ' + esc(gate) + ' แล้วแตะบัตรเพื่อเปิดประตู — ระหว่างนับระบบจะไม่แสดงยอดในระบบ';
        if (typeof window.showConfirmPopup === 'function') window.showConfirmPopup('สร้างใบนับสต๊อก', msg, go, 'สร้างใบนับ');
        else go();
    }

    function showQr(docId, gate, itemCount) {
        var d = docById(docId) || {};
        gate = gate || d.gate || ((/G\d+$/i.exec(docId) || [''])[0]);
        var n = itemCount != null ? itemCount : ((d.summary && d.summary.items) || 0);
        if (typeof window.showQRModal !== 'function') { info('แสดง QR ไม่ได้', 'หน้าเว็บรุ่นนี้ไม่มีหน้าต่าง QR', 'danger'); return; }
        window.showQRModal(docId, 'SC', [{ matCode: '', matName: 'นับสต๊อกทุกรายการในประตู ' + gate, qty: n, unit: 'รายการ' }],
                           'นับสต๊อก ' + gate, me());
    }

    function cancelCount(docId) {
        var go = function () {
            rpc('cancelRequisition', [docId, 'SC', me()], function (res) {
                if (!res || !res.success) { info('ยกเลิกไม่สำเร็จ', esc((res && res.message) || '-').replace(/\n/g, '<br>'), 'danger'); return; }
                storeDel(DRAFT + docId);
                toast('ยกเลิกใบนับ ' + docId + ' แล้ว', 'success');
                loadTab(true);
            });
        };
        if (typeof window.showConfirmPopup === 'function') window.showConfirmPopup('ยกเลิกใบนับ', 'ยกเลิกใบนับ <b>' + esc(docId) + '</b> ?', go, 'ยกเลิกใบนับ', 'btn btn-danger');
        else go();
    }

    // ------------------------------------------------------------------ หน้าต่างใบนับ (กรอกผล / ผลนับ / อนุมัติปรับยอด)
    function modalEl() {
        var m = document.getElementById('scSheetModal');
        if (m) return m;
        m = document.createElement('div');
        m.id = 'scSheetModal';
        m.className = 'sc-modal';
        m.innerHTML = '<div class="sc-modal-box" role="dialog" aria-label="ใบนับสต๊อก">' +
            '<div class="sc-modal-head"><div><div class="sc-modal-title" id="scSheetTitle">ใบนับสต๊อก</div><div class="sc-modal-sub" id="scSheetSub"></div></div>' +
                '<button type="button" class="sc-modal-x" id="scSheetClose" aria-label="ปิด">&times;</button></div>' +
            '<div class="sc-modal-body" id="scSheetBody"></div>' +
            '<div class="sc-modal-foot" id="scSheetFoot"></div>' +
        '</div>';
        document.body.appendChild(m);
        $('#scSheetClose').addEventListener('click', closeSheet);
        m.addEventListener('click', function (e) { if (e.target === m) closeSheet(); });
        return m;
    }
    function closeSheet() {
        var m = document.getElementById('scSheetModal');
        if (m) m.classList.remove('open');
        document.body.style.overflow = '';
        S.sheet = null;
    }

    function openSheet(docId) {
        var m = modalEl();
        $('#scSheetTitle').textContent = 'ใบนับ ' + docId;
        $('#scSheetSub').textContent = 'กำลังโหลด...';
        $('#scSheetBody').innerHTML = '<div class="qr-empty"><i class="fa-solid fa-spinner fa-spin"></i><span>กำลังโหลด...</span></div>';
        $('#scSheetFoot').innerHTML = '';
        m.classList.add('open');
        document.body.style.overflow = 'hidden';
        rpc('getStockCountSheet', [docId], function (res) {
            if (!res || !res.success) {
                $('#scSheetBody').innerHTML = '<div class="qr-empty"><i class="fa-solid fa-triangle-exclamation"></i><span>' + esc((res && res.message) || 'โหลดไม่สำเร็จ') + '</span></div>';
                return;
            }
            S.sheet = res;
            if (res.blind) renderCountForm(); else renderResults();
        }, function (msg) {
            $('#scSheetBody').innerHTML = '<div class="qr-empty"><i class="fa-solid fa-triangle-exclamation"></i><span>' + esc(msg) + '</span></div>';
        });
    }

    // ---- กรอกผลนับ (blind) ----
    function draftOf(docId) {
        var d = storeGet(DRAFT + docId);
        return d && typeof d === 'object' ? d : { counts: {}, extra: [], note: '' };
    }
    function saveDraft() {
        var sh = S.sheet;
        if (!sh || !sh.blind) return;
        var counts = {};
        $$('#scSheetBody .sc-cnt').forEach(function (inp) { if (inp.value !== '') counts[inp.getAttribute('data-id')] = inp.value; });
        var extra = [];
        $$('#scSheetBody .sc-extra-row').forEach(function (r) {
            var c = $('.sc-extra-code', r).value.trim(), q = $('.sc-extra-qty', r).value;
            if (c || q) extra.push({ matCode: c, counted: q });
        });
        storeSet(DRAFT + sh.doc.docId, { counts: counts, extra: extra, note: ($('#scNote') || {}).value || '' });
        updateProgress();
    }
    function updateProgress() {
        var all = $$('#scSheetBody .sc-cnt');
        var done = all.filter(function (i) { return i.value !== ''; }).length;
        var p = $('#scProgress');
        if (p) p.innerHTML = 'กรอกแล้ว <b>' + done + '</b> / ' + all.length + ' รายการ' + (done < all.length ? ' — ของที่ไม่พบให้กรอก 0' : ' — ครบแล้ว');
        var btn = $('#scSaveBtn');
        if (btn) btn.classList.toggle('ready', done === all.length && all.length > 0);
    }

    function renderCountForm() {
        var sh = S.sheet;
        var doc = sh.doc;
        var dr = draftOf(doc.docId);
        $('#scSheetSub').textContent = 'ประตู ' + doc.gate + ' · ' + doc.stageThai;
        if (doc.stage === 'awaiting') {
            $('#scSheetBody').innerHTML = '<div class="sc-blind-note warn"><i class="fa-solid fa-qrcode"></i> ยังไม่ได้แตะบัตรที่ประตู — สแกน QR ที่ตู้ประตู ' + esc(doc.gate) +
                ' แล้วแตะบัตรเพื่อเปิดประตูก่อน จึงจะบันทึกผลนับได้</div>' + itemsPreview(sh.items);
            $('#scSheetFoot').innerHTML = '<button type="button" class="btn btn-secondary" id="scFootClose">ปิด</button>' +
                '<button type="button" class="btn btn-primary" id="scFootQr"><i class="fa-solid fa-qrcode"></i> แสดง QR</button>';
            $('#scFootClose').addEventListener('click', closeSheet);
            $('#scFootQr').addEventListener('click', function () { closeSheet(); showQr(doc.docId, doc.gate, sh.items.length); });
            return;
        }
        var rows = sh.items.map(function (it) {
            var v = dr.counts[it.itemId] != null ? dr.counts[it.itemId] : '';
            return '<div class="sc-row" data-q="' + esc((it.matCode + ' ' + it.name + ' ' + (it.subgroup || '')).toLowerCase()) + '">' +
                '<div class="sc-row-main"><div class="sc-row-name">' + esc(it.name) + '</div>' +
                    '<div class="sc-row-code">' + esc(it.matCode) + (it.subgroup ? ' · ' + esc(it.subgroup) : '') + '</div></div>' +
                '<div class="sc-row-in"><input type="number" class="form-control sc-cnt" data-id="' + it.itemId + '" min="0" step="any" inputmode="decimal" placeholder="นับได้" value="' + esc(v) + '">' +
                    '<span class="sc-unit">' + esc(it.unit || '') + '</span></div>' +
            '</div>';
        }).join('');
        var extraRows = (dr.extra || []).map(extraRowHtml).join('');
        $('#scSheetBody').innerHTML =
            '<div class="sc-blind-note"><i class="fa-solid fa-eye-slash"></i> นับแบบไม่เห็นยอดในระบบ — นับของจริงในประตู ' + esc(doc.gate) +
                ' แล้วกรอกทุกรายการ (ของที่ไม่พบกรอก 0) · ผลที่กรอกเก็บในเครื่องนี้ระหว่างนับ</div>' +
            '<div class="sc-tools"><input type="search" class="form-control" id="scSearch" placeholder="ค้นหารหัส / ชื่อ / หมวด">' +
                '<label class="sc-only"><input type="checkbox" id="scOnlyEmpty"> เฉพาะที่ยังไม่กรอก</label></div>' +
            '<div class="sc-progress" id="scProgress"></div>' +
            '<div class="sc-rows">' + rows + '</div>' +
            '<div class="sc-extra"><div class="sc-extra-h"><b>ของที่พบเพิ่มในประตูนี้</b> <span class="td-sub">(รหัส IC ที่ไม่อยู่ในรายการ)</span>' +
                '<button type="button" class="btn btn-secondary sc-extra-add" id="scExtraAdd"><i class="fa-solid fa-plus"></i> เพิ่ม</button></div>' +
                '<div id="scExtraRows">' + extraRows + '</div></div>' +
            '<div class="form-group" style="margin-top:0.8rem;"><label>หมายเหตุการนับ</label>' +
                '<textarea class="form-control" id="scNote" rows="2" maxlength="500" placeholder="เช่น ของบางส่วนวางนอกชั้น">' + esc(dr.note || '') + '</textarea></div>';
        $('#scSheetFoot').innerHTML = '<button type="button" class="btn btn-secondary" id="scFootClose">ปิด (เก็บร่างไว้)</button>' +
            '<button type="button" class="btn btn-primary sc-save" id="scSaveBtn"' + (sh.canCount ? '' : ' disabled') + '><i class="fa-solid fa-floppy-disk"></i> บันทึกผลนับ</button>';
        $('#scFootClose').addEventListener('click', closeSheet);
        $('#scSaveBtn').addEventListener('click', saveCount);
        $('#scExtraAdd').addEventListener('click', function () {
            var box = $('#scExtraRows');
            box.insertAdjacentHTML('beforeend', extraRowHtml({ matCode: '', counted: '' }));
            bindExtra();
            var rowsEl = $$('.sc-extra-row', box);
            var last = rowsEl[rowsEl.length - 1];
            if (last) $('.sc-extra-code', last).focus();
        });
        $('#scSearch').addEventListener('input', filterRows);
        $('#scOnlyEmpty').addEventListener('change', filterRows);
        $$('#scSheetBody .sc-cnt').forEach(function (inp) {
            inp.addEventListener('input', saveDraft);
            inp.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter') return;
                e.preventDefault();
                var list = $$('#scSheetBody .sc-row:not(.hide) .sc-cnt');
                var i = list.indexOf(inp);
                if (i >= 0 && list[i + 1]) list[i + 1].focus();
            });
        });
        $('#scNote').addEventListener('input', saveDraft);
        bindExtra();
        updateProgress();
    }
    function itemsPreview(items) {
        return '<div class="sc-preview"><div class="sc-extra-h"><b>รายการที่ต้องนับ ' + items.length + ' รายการ</b></div>' +
            items.slice(0, 200).map(function (it) {
                return '<div class="sc-pre-row">' + esc(it.name) + ' <small>' + esc(it.matCode) + '</small></div>';
            }).join('') + '</div>';
    }
    function extraRowHtml(x) {
        return '<div class="sc-extra-row"><input type="text" class="form-control sc-extra-code" placeholder="รหัส IC" value="' + esc(x.matCode || '') + '">' +
            '<input type="number" class="form-control sc-extra-qty" min="0" step="any" inputmode="decimal" placeholder="นับได้" value="' + esc(x.counted != null ? x.counted : '') + '">' +
            '<button type="button" class="td-line-del sc-extra-del" title="ลบ"><i class="fa-solid fa-xmark"></i></button></div>';
    }
    function bindExtra() {
        $$('#scExtraRows .sc-extra-row').forEach(function (r) {
            if (r.__bound) return;
            r.__bound = true;
            $('.sc-extra-code', r).addEventListener('input', saveDraft);
            $('.sc-extra-qty', r).addEventListener('input', saveDraft);
            $('.sc-extra-del', r).addEventListener('click', function () { r.parentNode.removeChild(r); saveDraft(); });
        });
    }
    function filterRows() {
        var q = ($('#scSearch').value || '').trim().toLowerCase();
        var onlyEmpty = $('#scOnlyEmpty').checked;
        $$('#scSheetBody .sc-row').forEach(function (r) {
            var inp = $('.sc-cnt', r);
            var hit = (!q || r.getAttribute('data-q').indexOf(q) !== -1) && (!onlyEmpty || inp.value === '');
            r.classList.toggle('hide', !hit);
        });
    }

    function saveCount() {
        if (S.saving) return;
        var sh = S.sheet;
        if (!sh) return;
        var items = [];
        var missing = 0;
        var bad = 0;
        $$('#scSheetBody .sc-cnt').forEach(function (inp) {
            var v = inp.value;
            if (v === '') { missing++; inp.classList.add('sc-miss'); return; }
            if (!(Number(v) >= 0)) { bad++; inp.classList.add('sc-miss'); return; }
            inp.classList.remove('sc-miss');
            items.push({ itemId: Number(inp.getAttribute('data-id')), counted: Number(v) });
        });
        if (missing || bad) {
            var only = $('#scOnlyEmpty');
            if (only && missing) { only.checked = true; filterRows(); }
            info('ยังบันทึกไม่ได้', missing ? 'ยังไม่ได้กรอก ' + missing + ' รายการ — ของที่ไม่พบให้กรอก 0' : 'ผลนับต้องเป็นตัวเลขตั้งแต่ 0 ขึ้นไป', 'warning');
            return;
        }
        var extra = [];
        var extraBad = false;
        $$('#scSheetBody .sc-extra-row').forEach(function (r) {
            var c = $('.sc-extra-code', r).value.trim(), q = $('.sc-extra-qty', r).value;
            if (!c && q === '') return;
            if (!c || !(Number(q) >= 0) || q === '') { extraBad = true; return; }
            extra.push({ matCode: c, counted: Number(q) });
        });
        if (extraBad) { info('ของที่พบเพิ่มไม่ครบ', 'ใส่ทั้งรหัส IC และจำนวนที่นับได้ หรือลบแถวที่ไม่ใช้', 'warning'); return; }
        var go = function () {
            S.saving = true;
            var btn = $('#scSaveBtn');
            if (btn) btn.disabled = true;
            if (typeof window.showLoadingPopup === 'function') window.showLoadingPopup('กำลังบันทึกผลนับ', 'กรุณารอสักครู่...');
            rpc('saveStockCount', [{ docNo: sh.doc.docId, items: items, extra: extra, note: ($('#scNote') || {}).value || '' }], function (res) {
                S.saving = false;
                if (btn) btn.disabled = false;
                if (typeof window.closeAppPopup === 'function') window.closeAppPopup();
                if (!res || !res.success) {
                    info('บันทึกผลนับไม่สำเร็จ', esc((res && res.message) || '-').replace(/\n/g, '<br>'), 'danger');
                    return;
                }
                storeDel(DRAFT + sh.doc.docId);
                if (typeof window.hapticSuccess === 'function') window.hapticSuccess();
                var s = res.summary || {};
                var msg = 'นับ ' + num(s.counted || 0) + ' รายการ · ตรงยอด ' + num(s.match || 0) + ' · ต่าง ' + num(s.diff || 0) +
                          (s.accuracy != null ? ' (ความแม่นยำ ' + esc(s.accuracy) + '%)' : '') +
                          (res.finalized ? '<br>ปิดงานใบนับแล้ว' : '<br><b>ปิดประตูให้สนิทเพื่อจบรอบ</b>') +
                          (s.diff ? '<br>ผลต่างรอ ADM / R8 ขึ้นไปอนุมัติปรับยอด' : '') +
                          (res.warning ? '<br><br><span style="color:#b45309;">' + esc(res.warning) + '</span>' : '');
                info('บันทึกผลนับ ' + esc(sh.doc.docId) + ' แล้ว', msg, res.warning ? 'warning' : 'success', function () {
                    openSheet(sh.doc.docId);
                });
                loadTab(true);
            }, function (m) {
                S.saving = false;
                if (btn) btn.disabled = false;
                if (typeof window.closeAppPopup === 'function') window.closeAppPopup();
                info('บันทึกผลนับไม่สำเร็จ', esc(m), 'danger');
            });
        };
        var msg = 'บันทึกผลนับ <b>' + items.length + '</b> รายการ' + (extra.length ? ' + ของที่พบเพิ่ม ' + extra.length + ' รายการ' : '') +
                  '<br>บันทึกแล้วแก้ไม่ได้ — ระบบจะเทียบกับยอดในระบบของประตู ' + esc(sh.doc.gate) + ' ณ เวลาบันทึก';
        if (typeof window.showConfirmPopup === 'function') window.showConfirmPopup('ยืนยันบันทึกผลนับ', msg, go, 'บันทึกผลนับ');
        else go();
    }

    // ---- ผลนับ + อนุมัติปรับยอด ----
    function renderResults() {
        var sh = S.sheet;
        var doc = sh.doc;
        $('#scSheetSub').textContent = 'ประตู ' + doc.gate + ' · ' + doc.stageThai + (doc.countedBy ? ' · นับโดย ' + fullName(doc.countedBy) + ' ' + doc.countedAt : '');
        var items = sh.items || [];
        var diffs = items.filter(function (it) { return it.diff != null && Math.abs(it.diff) >= 0.0005; });
        var pend = diffs.filter(function (it) { return it.adj === 'pending'; });
        var match = items.filter(function (it) { return it.diff != null && Math.abs(it.diff) < 0.0005; }).length;
        var canDecide = !!sh.canDecide && pend.length > 0;
        var adjTxt = { pending: 'รออนุมัติ', approved: 'ปรับยอดแล้ว', rejected: 'ไม่ปรับยอด' };
        var rowHtml = function (it) {
            var d = it.diff;
            var isDiff = d != null && Math.abs(d) >= 0.0005;
            var dec = '';
            if (isDiff && it.adj === 'pending' && canDecide) {
                dec = '<div class="sc-dec" data-id="' + it.itemId + '">' +
                    '<label class="sc-dec-opt ok"><input type="radio" name="scd' + it.itemId + '" value="approve"> ปรับยอด</label>' +
                    '<label class="sc-dec-opt no"><input type="radio" name="scd' + it.itemId + '" value="reject"> ไม่ปรับ</label>' +
                    '<input type="text" class="form-control sc-dec-note" maxlength="255" placeholder="เหตุผล (บังคับเมื่อไม่ปรับ)"></div>';
            } else if (isDiff) {
                dec = '<div class="sc-adj ' + esc(it.adj) + '">' + esc(adjTxt[it.adj] || it.adj) +
                      (it.adjBy ? ' · ' + esc(fullName(it.adjBy)) + ' ' + esc(it.adjAt) : '') + (it.adjNote ? ' · ' + esc(it.adjNote) : '') + '</div>';
            }
            var stuck = it.stuckQty != null
                ? '<div class="sc-stuck"><i class="fa-solid fa-triangle-exclamation"></i> มีใบค้างยังไม่ตัดสต๊อก ' + esc((it.stuckDocs || []).join(', ')) + ' (' + signed(it.stuckQty) + ')</div>' : '';
            return '<div class="sc-res' + (isDiff ? ' diff' : '') + '" data-diff="' + (isDiff ? 1 : 0) + '">' +
                '<div class="sc-row-main"><div class="sc-row-name">' + esc(it.name) + '</div><div class="sc-row-code">' + esc(it.matCode) + '</div>' + stuck + '</div>' +
                '<div class="sc-res-nums"><span><small>ในระบบ</small>' + num(it.system) + '</span><span><small>นับได้</small><b>' + (it.counted == null ? '-' : num(it.counted)) + '</b></span>' +
                    '<span class="sc-d ' + (d > 0 ? 'up' : d < 0 ? 'down' : '') + '"><small>ผลต่าง</small>' + (d == null ? '-' : signed(d)) + '</span>' +
                    '<span class="sc-u">' + esc(it.unit || '') + '</span></div>' + dec +
            '</div>';
        };
        $('#scSheetBody').innerHTML =
            '<div class="sc-res-sum"><span class="sc-chip">' + items.length + ' รายการ</span><span class="sc-chip ok">ตรงยอด ' + match + '</span>' +
                '<span class="sc-chip ' + (diffs.length ? 'warn' : '') + '">ต่าง ' + diffs.length + '</span>' +
                (pend.length ? '<span class="sc-chip warn">รออนุมัติ ' + pend.length + '</span>' : '') + '</div>' +
            (sh.warning ? '<div class="sc-blind-note warn"><i class="fa-solid fa-triangle-exclamation"></i> ' + esc(sh.warning) + '</div>' : '') +
            (sh.ownCount ? '<div class="sc-blind-note warn"><i class="fa-solid fa-user-lock"></i> ผลนับของคุณเอง — อนุมัติปรับยอดเองไม่ได้ ให้ ADM / R8 ขึ้นไปคนอื่นอนุมัติ (ยกเว้น PM ของไซต์)</div>' : '') +
            (doc.note ? '<div class="sc-note">' + esc(doc.note) + '</div>' : '') +
            (canDecide ? '<div class="sc-dec-bar"><span>อนุมัติปรับยอด = ยอดที่ประตู ' + esc(doc.gate) + ' ขยับเท่าผลต่าง · ไม่ปรับต้องใส่เหตุผล</span>' +
                '<button type="button" class="btn btn-secondary" id="scAllApprove"><i class="fa-solid fa-check-double"></i> เลือกปรับยอดทั้งหมด</button></div>' : '') +
            '<div class="sc-tools"><label class="sc-only"><input type="checkbox" id="scOnlyDiff"' + (diffs.length ? ' checked' : '') + '> เฉพาะรายการที่ต่าง</label></div>' +
            '<div class="sc-rows">' + items.map(rowHtml).join('') + '</div>';
        var foot = '<button type="button" class="btn btn-secondary" id="scFootClose">ปิด</button>' +
                   '<button type="button" class="btn btn-secondary" id="scFootPdf"><i class="fa-solid fa-file-pdf"></i> PDF</button>';
        if (canDecide) foot += '<button type="button" class="btn btn-primary" id="scDecideBtn"><i class="fa-solid fa-stamp"></i> บันทึกการอนุมัติ</button>';
        $('#scSheetFoot').innerHTML = foot;
        $('#scFootClose').addEventListener('click', closeSheet);
        $('#scFootPdf').addEventListener('click', function () { if (typeof window.downloadDocReport === 'function') window.downloadDocReport(doc.docId, 'SC'); });
        var only = $('#scOnlyDiff');
        var applyOnly = function () {
            $$('#scSheetBody .sc-res').forEach(function (r) { r.classList.toggle('hide', only.checked && r.getAttribute('data-diff') !== '1'); });
        };
        only.addEventListener('change', applyOnly);
        applyOnly();
        if (canDecide) {
            $('#scAllApprove').addEventListener('click', function () {
                $$('#scSheetBody .sc-dec input[value="approve"]').forEach(function (r) { r.checked = true; });
            });
            $('#scDecideBtn').addEventListener('click', decide);
        }
    }

    function decide() {
        var sh = S.sheet;
        var decisions = [];
        var noteMissing = 0;
        $$('#scSheetBody .sc-dec').forEach(function (box) {
            var pick = $('input[type=radio]:checked', box);
            if (!pick) return;
            var note = $('.sc-dec-note', box).value.trim();
            if (pick.value === 'reject' && !note) { noteMissing++; $('.sc-dec-note', box).classList.add('sc-miss'); return; }
            decisions.push({ itemId: Number(box.getAttribute('data-id')), action: pick.value, note: note });
        });
        if (noteMissing) { info('ใส่เหตุผล', 'รายการที่ไม่ปรับยอดต้องใส่เหตุผล (' + noteMissing + ' รายการ)', 'warning'); return; }
        if (!decisions.length) { toast('ยังไม่ได้เลือกรายการ', 'warning'); return; }
        var nA = decisions.filter(function (x) { return x.action === 'approve'; }).length;
        var go = function () {
            rpc('decideStockAdjust', [{ docNo: sh.doc.docId, decisions: decisions }], function (res) {
                if (!res || !res.success) { info('บันทึกไม่สำเร็จ', esc((res && res.message) || '-'), 'danger'); return; }
                toast(res.message || 'บันทึกแล้ว', 'success');
                try { if (window.connextCache) window.connextCache.invalidateMany(['balance', 'gatebalance']); } catch (e) {}
                openSheet(sh.doc.docId);
                if (tabVisible()) loadTab(true);
                loadAdjustQueue();
            });
        };
        var msg = 'ปรับยอด <b>' + nA + '</b> รายการ · ไม่ปรับ <b>' + (decisions.length - nA) + '</b> รายการ' +
                  '<br>รายการที่ปรับ: ยอดที่ประตู ' + esc(sh.doc.gate) + ' ขยับเท่าผลต่างทันที (ย้อนกลับไม่ได้)';
        if (typeof window.showConfirmPopup === 'function') window.showConfirmPopup('ยืนยันการอนุมัติปรับยอด', msg, go, 'บันทึก');
        else go();
    }

    // ------------------------------------------------------------------ หน้าการอนุมัติ: กล่องอนุมัติปรับยอด
    function injectApproveSection() {
        var q = document.getElementById('approvalQueue');
        if (!q || document.getElementById('scAdjustSection')) return;
        var sec = document.createElement('div');
        sec.id = 'scAdjustSection';
        sec.className = 'sc-adj-section';
        sec.style.display = 'none';
        q.parentNode.insertBefore(sec, q);
    }
    function loadAdjustQueue() {
        var sec = document.getElementById('scAdjustSection');
        if (!sec || !maybeDecider()) return;
        rpc('getStockAdjustQueue', [], function (res) {
            if (!res || !res.success || !res.canDecide || !(res.docs || []).length) { sec.style.display = 'none'; sec.innerHTML = ''; return; }
            S.adjQueue = res.docs;
            var total = 0;
            res.docs.forEach(function (d) { total += d.items.length; });
            sec.innerHTML = '<div class="sc-adj-head"><i class="fa-solid fa-scale-balanced"></i> อนุมัติปรับยอดสต๊อก — จากใบนับ <span class="sign-tasks-count">' + total + '</span></div>' +
                res.docs.map(function (d) {
                    var plus = 0, minus = 0;
                    d.items.forEach(function (it) { if (it.diff > 0) plus += it.diff; else minus += -it.diff; });
                    return '<div class="sc-adj-card"><div><div class="sc-doc-id">' + esc(d.docId) + ' <span class="sc-gate-chip">' + esc(d.gate) + '</span></div>' +
                        '<div class="sc-doc-meta">นับโดย ' + esc(fullName(d.countedBy)) + ' ' + esc(d.countedAt) + ' · ' + d.items.length + ' รายการต่างจากระบบ' +
                        ' (เกิน ' + num(plus) + ' · ขาด ' + num(minus) + ')</div>' +
                        (d.warning ? '<div class="sc-stuck"><i class="fa-solid fa-triangle-exclamation"></i> มีใบค้างที่ประตูนี้ — ดูรายละเอียดก่อนอนุมัติ</div>' : '') +
                        (d.ownCount ? '<div class="sc-stuck"><i class="fa-solid fa-user-lock"></i> ผลนับของคุณเอง — ให้ ADM / R8 ขึ้นไปคนอื่นอนุมัติ (ยกเว้น PM)</div>' : '') + '</div>' +
                        '<button type="button" class="btn btn-primary sc-adj-open" data-doc="' + esc(d.docId) + '"><i class="fa-solid fa-magnifying-glass"></i> ตรวจ / อนุมัติ</button></div>';
                }).join('');
            sec.style.display = '';
            $$('.sc-adj-open', sec).forEach(function (b) { b.addEventListener('click', function () { openSheet(b.getAttribute('data-doc')); }); });
        }, function () { sec.style.display = 'none'; });
    }

    // ------------------------------------------------------------------ เชื่อมกับหน้าเดิม
    function hookDailyTabs() {
        wrapAfter('switchDailyCheckTab', function (which) {
            var pane = document.getElementById('dc-tab-count');
            var btn = document.getElementById('dcTabCountBtn');
            if (pane) pane.style.display = which === 'count' ? '' : 'none';
            if (btn) btn.classList.toggle('active', which === 'count');
            if (which === 'count') loadTab(false);
        });
    }

    function hookGateScan() {
        var orig = window._onDocScannedAtGate;
        if (typeof orig !== 'function' || orig.__scWrapped) return;
        var w = function (docId) {
            if (!isScDoc(docId)) return orig.apply(this, arguments);
            if (typeof window.hapticSuccess === 'function') window.hapticSuccess();
            var open = function () {
                if (typeof window.switchToPage === 'function') {
                    try { window.switchToPage('dailycheck'); } catch (e) {}
                }
                var btn = document.getElementById('dcTabCountBtn');
                if (typeof window.switchDailyCheckTab === 'function' && btn) window.switchDailyCheckTab('count', btn);
                openSheet(docId);
            };
            info('🔓 ประตูเปิดแล้ว — เริ่มนับ', 'ใบนับ ' + esc(docId) + ' แตะบัตรที่ประตูแล้ว<br>นับของจริงแล้วกรอกผลนับให้ครบทุกรายการ จากนั้นบันทึกและปิดประตู', 'success', open);
        };
        w.__scWrapped = true;
        window._onDocScannedAtGate = w;
    }

    function hookQrTitle() {
        wrapAfter('showQRModal', function (docId, docType) {
            if (docType !== 'SC' && !isScDoc(docId)) return;
            var t = document.getElementById('qrModalTitle');
            var g = (/G\d+$/i.exec(String(docId || '')) || [''])[0];
            if (t) t.textContent = '📋 นับสต๊อก — สแกนที่ตู้ประตู ' + g + ' แล้วแตะบัตร';
        });
    }

    function init() {
        injectTab();
        injectApproveSection();
        hookDailyTabs();
        hookGateScan();
        hookQrTitle();
        wrapAfter('loadApprovalQueue', function () { loadAdjustQueue(); });
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible' && tabVisible()) loadTab(true);
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();

    window.cnxStockCount = { reload: function () { loadTab(false); }, open: openSheet, adjust: loadAdjustQueue };
})();
