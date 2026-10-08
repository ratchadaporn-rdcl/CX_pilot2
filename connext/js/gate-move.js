/*
 * CONNEXT — js/gate-move.js  [2026-10-02 · TG ใบย้าย Gate (ภายในไซต์)]
 *
 * 1) แท็บ "โอนย้าย" โหมด "ภายใน site — ย้าย Gate" (#tgPane ที่ js/transfer.js สร้างไว้):
 *    ฟอร์ม วัสดุ → ประตูต้นทาง → จำนวน (หน้าต่าง +/− ตัวเดียวกับแท็บเบิก · formKey 'tg') → เพิ่ม ·
 *    ฝั่งรายการ: ประตูปลายทาง · เกณฑ์อนุมัติตามหมวด IC (มี C01 → R6+ · C02 → R4+ · NAR ล้วน → อนุมัติทันที) · ผู้อนุมัติ · หมายเหตุ
 *    ออกใบได้เฉพาะสายสโตร์ (server ตรวจซ้ำ) · แตกใบตามประตูต้นทาง (เลขรันเดียวกัน)
 * 2) รายการ "ใบย้าย Gate ของไซต์นี้": สถานะ 2 ขา · ขอ / เบิกออก / นำเข้า · ปุ่ม QR ของขาที่รอสแกน · ยกเลิก · PDF
 * 3) หน้า QR: หัวกลุ่ม "ย้าย Gate (TG)" · ป้าย เบิกออก/นำเข้า บนการ์ด · การ์ดขานำเข้าไม่มีปุ่มยกเลิก
 *    หน้าถ่ายรูปยืนยัน: คำอธิบายของขานำเข้า (จำนวนเท่าที่เบิกออก แก้ไม่ได้) · หน้าประวัติ: ตัวกรองชนิด TG
 * RPC: lib/gatemove.php (getGateMoveFormData · processGateMoveSubmission · getGateMoveList) · cancelRequisition · generateDocReportPDF
 * ไม่มีใน GAS — ถ้า build index.php ใหม่ให้เติม <link>/<script defer> ไฟล์นี้กลับก่อน </head> (หลัง js/transfer.js)
 */
(function () {
    'use strict';

    var G = { form: null, mats: {}, matOrder: [], cart: [], list: null, loading: false, submitting: false, loadedOnce: false, built: false };
    var FORM_KEY = 'tg';   // หน้าต่างจำนวนหา <formKey>GateSelect → tgGateSelect
    var IN_LEG_RE = /^(TG\d+(G\d+))(G\d+)$/i;
    var OUT_LEG_RE = /^TG\d+(G\d+)$/i;

    // ------------------------------------------------------------------ ตัวช่วย
    function $(sel, root) { return (root || document).querySelector(sel); }
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
    function toast(m, t) { if (typeof window.showToast === 'function') window.showToast(m, t || 'info'); }
    function info(title, msg, type, onClose) {
        if (typeof window.showInfoPopup === 'function') window.showInfoPopup(title, msg, type || 'info', onClose);
        else alert(title + '\n' + msg);
    }
    function fullName(u) {
        var n = typeof window.userFullName === 'function' ? window.userFullName(u) : '';
        return n || u || '-';
    }
    function me() { return (window.user && window.user.username) || ''; }
    function invalidate() {
        try { if (window.connextCache) window.connextCache.invalidateMany(['approvedDocs', 'approvalRequests', 'balance', 'gatebalance', 'history']); } catch (e) {}
        try { if (typeof window.updateNavBadges === 'function') window.updateNavBadges(); } catch (e) {}
    }
    function wrapAfter(name, fn) {
        var orig = window[name];
        if (typeof orig !== 'function' || orig.__tgWrapped) return;
        var w = function () {
            var r = orig.apply(this, arguments);
            try { fn.apply(this, arguments); } catch (e) { console.warn('[gate-move] ' + name + ':', e); }
            return r;
        };
        w.__tgWrapped = true;
        window[name] = w;
    }
    function rpc(fn, args, ok, fail) {
        var r = google.script.run.withSuccessHandler(ok).withFailureHandler(function (err) {
            var msg = (err && err.message) ? err.message : String(err || 'เชื่อมต่อไม่สำเร็จ');
            if (fail) fail(msg); else info('ผิดพลาด', esc(msg), 'danger');
        });
        r[fn].apply(r, args || []);
    }
    function clsChip(c) {
        return '<span class="tg-cls tg-cls-' + esc(String(c || 'C02').toLowerCase()) + '">' + esc(c || 'C02') + '</span>';
    }
    function reqLevelOf(classes) {
        var lvl = 0;
        for (var i = 0; i < classes.length; i++) {
            if (classes[i] === 'C01') return 6;
            if (classes[i] === 'C02') lvl = 4;
        }
        return lvl;
    }
    function reqText(lvl) {
        if (lvl >= 6) return 'ต้องผู้อนุมัติ <b>R6 ขึ้นไป</b> (มีวัสดุ C01)';
        if (lvl >= 4) return 'ต้องผู้อนุมัติ <b>R4 ขึ้นไป</b> (วัสดุ C02)';
        return '<b>NAR ล้วน — อนุมัติทันที</b> ได้ QR เบิกออกเลย';
    }

    // ------------------------------------------------------------------ โครงหน้า
    function build() {
        var pane = document.getElementById('tgPane');
        if (!pane || G.built) return !!pane;
        G.built = true;
        pane.innerHTML =
            '<div class="td-intro tg-intro"><i class="fa-solid fa-circle-info"></i><div>' +
                '<b>ใบย้าย Gate (TG)</b> — ย้ายของจาก G หนึ่งไปอีก G ภายในไซต์ · <b>เฉพาะเจ้าหน้าที่สโตร์</b> (ออกใบ · แตะบัตรที่ตู้ · ถ่ายรูปยืนยัน) · ' +
                'อนุมัติตามหมวด IC: มี <b>C01</b> → R6 ขึ้นไป · <b>C02</b> → R4 ขึ้นไป · <b>NAR</b> ล้วน → อนุมัติทันที<br>' +
                '<span class="tg-steps"><b>① QR เบิกออก</b> สแกนที่ G ต้นทาง → ถ่ายรูป → ปิดประตู = ตัดยอด G ต้นทาง ' +
                '<i class="fa-solid fa-arrow-right"></i> <b>② QR นำเข้า</b> สแกนที่ G ปลายทาง → ถ่ายรูป (จำนวนเท่าที่เบิกออก) → ปิดประตู = เพิ่มยอด G ปลายทาง</span>' +
            '</div></div>' +
            '<div id="tgNotStore" class="tg-notstore" hidden><i class="fa-solid fa-lock"></i> บัญชีนี้ออกใบย้าย Gate ไม่ได้ — ทำได้เฉพาะเจ้าหน้าที่สโตร์ (AST / ST1 / ST2 / SST) · ดูรายการใบได้ด้านล่าง</div>' +
            '<div class="grid-layout td-grid" id="tgFormGrid">' +
                '<div class="form-section">' +
                    '<h3 style="margin-bottom:1rem;">แบบฟอร์มย้าย Gate</h3>' +
                    '<div class="form-group"><label>วัสดุที่จะย้าย <span class="td-req">*</span></label>' +
                        '<select id="tgMatSelect" class="form-control"><option value="">กำลังโหลด...</option></select>' +
                        '<div id="tgMatHint" class="balance-hint">เลือกวัสดุเพื่อดูยอดแยกตามประตู</div></div>' +
                    '<div class="form-group"><label>ประตูต้นทาง (เบิกของออก) <span class="td-req">*</span></label>' +
                        '<select id="tgGateSelect" class="form-control"><option value="" disabled selected>-- เลือกวัสดุก่อน --</option></select>' +
                        '<div id="tgGateHint" class="balance-hint">วัสดุชนิดเดียวกันอยู่ได้หลายประตู — เลือกประตูที่จะเบิกของออก</div></div>' +
                    '<div class="form-group"><label>จำนวน <span class="td-req">*</span></label>' +
                        '<input type="text" id="tgQtyInput" class="form-control qty-trigger" placeholder="แตะเพื่อระบุจำนวน" readonly></div>' +
                    '<button type="button" class="btn btn-secondary td-add-btn" id="tgAddBtn"><i class="fa-solid fa-plus"></i> เพิ่มลงรายการย้าย</button>' +
                '</div>' +
                '<div class="form-section">' +
                    '<h3 style="margin-bottom:1rem;">รายการที่จะย้าย <span class="td-count tg-count" id="tgCartCount">0</span></h3>' +
                    '<div id="tgCart" class="td-cart"></div>' +
                    '<div class="form-group" style="margin-top:0.9rem;"><label>ประตูปลายทาง (นำของเข้า) <span class="td-req">*</span></label>' +
                        '<select id="tgDestSelect" class="form-control"><option value="">กำลังโหลด...</option></select>' +
                        '<div id="tgDestHint" class="balance-hint"></div></div>' +
                    '<div id="tgReqBox" class="tg-req-box"></div>' +
                    '<div class="form-group" id="tgApproverGroup"><label>ผู้อนุมัติ <span class="td-req">*</span></label>' +
                        '<select id="tgApproverSelect" class="form-control"></select>' +
                        '<div id="tgApproverHint" class="balance-hint"></div></div>' +
                    '<div class="form-group"><label>หมายเหตุ</label>' +
                        '<textarea id="tgNoteInput" class="form-control" rows="2" maxlength="1000" placeholder="เช่น เหตุผลที่ย้าย / จัดพื้นที่ใหม่"></textarea></div>' +
                    '<button type="button" class="btn btn-primary td-submit-btn tg-submit-btn" id="tgSubmitBtn"><i class="fa-solid fa-paper-plane"></i> ส่งใบย้าย Gate</button>' +
                '</div>' +
            '</div>' +
            '<div class="td-lists">' +
                '<div class="td-list-head"><h3><i class="fa-solid fa-right-left"></i> ใบย้าย Gate ของไซต์นี้ <span class="td-sub">(120 วันล่าสุด)</span></h3>' +
                    '<button type="button" class="page-refresh-btn td-refresh" id="tgListRefresh" title="โหลดใหม่"><i class="fa-solid fa-arrows-rotate"></i></button></div>' +
                '<div id="tgList" class="td-doc-list"><div class="qr-empty"><i class="fa-solid fa-spinner fa-spin"></i><span>กำลังโหลด...</span></div></div>' +
            '</div>';

        $('#tgMatSelect').addEventListener('change', onMatChange);
        $('#tgGateSelect').addEventListener('change', function () { renderGateHint(); resetQty(); });
        $('#tgQtyInput').addEventListener('click', openQty);
        $('#tgAddBtn').addEventListener('click', addToCart);
        $('#tgDestSelect').addEventListener('change', function () { renderDestHint(); renderCart(); });
        $('#tgSubmitBtn').addEventListener('click', submit);
        $('#tgListRefresh').addEventListener('click', function () { loadList(true); });
        renderCart();
        return true;
    }

    // ------------------------------------------------------------------ โหลด
    function activate(force) {
        if (!build()) return;
        if (G.loadedOnce && !force) { loadList(false); return; }
        loadForm();
    }

    function loadForm() {
        if (G.loading) return;
        G.loading = true;
        rpc('getGateMoveFormData', [], function (res) {
            G.loading = false;
            if (!res || !res.success) {
                info('โหลดฟอร์มย้าย Gate ไม่สำเร็จ', esc((res && res.message) || '-'), 'danger');
                return;
            }
            G.form = res;
            G.loadedOnce = true;
            renderForm();
            loadList(false);
        }, function (msg) {
            G.loading = false;
            toast('โหลดฟอร์มย้าย Gate ไม่สำเร็จ: ' + msg, 'danger');
        });
    }

    function renderForm() {
        var f = G.form || {};
        var can = f.canCreate === true;
        $('#tgNotStore').hidden = can;
        $('#tgFormGrid').classList.toggle('tg-disabled', !can);
        ['#tgAddBtn', '#tgSubmitBtn', '#tgDestSelect', '#tgQtyInput', '#tgNoteInput'].forEach(function (s) { var el = $(s); if (el) el.disabled = !can; });
        var dest = $('#tgDestSelect');
        var cur = dest.value;
        dest.innerHTML = '<option value="">-- เลือกประตูปลายทาง --</option>' + (f.gates || []).map(function (g) {
            return '<option value="' + esc(g.code) + '">' + esc(g.code) + (g.name && g.name !== g.code ? ' · ' + esc(g.name) : '') + '</option>';
        }).join('');
        if (cur) dest.value = cur;
        indexStock();
        renderDestHint();
        if ((f.gates || []).length < 2) {
            $('#tgDestHint').innerHTML = '<span class="td-warn">ไซต์นี้มีประตูใช้งานไม่ถึง 2 ประตู — ย้าย Gate ไม่ได้</span>';
        }
        renderMatOptions();
        renderCart();
    }

    function indexStock() {
        var mats = {};
        var order = [];
        ((G.form && G.form.stock) || []).forEach(function (s) {
            if (!mats[s.matCode]) {
                mats[s.matCode] = { matCode: s.matCode, name: s.name, unit: s.unit || '', char: s.char || '', cls: s.cls || 'C02', rows: [] };
                order.push(s.matCode);
            }
            mats[s.matCode].rows.push(s);
        });
        order.forEach(function (k) { mats[k].rows.sort(function (a, b) { return a.gate.localeCompare(b.gate); }); });
        order.sort(function (a, b) { return mats[a].name.localeCompare(mats[b].name, 'th') || a.localeCompare(b); });
        G.mats = mats;
        G.matOrder = order;
    }
    function stockRow(mat, gate) {
        var m = G.mats[mat];
        if (!m) return null;
        for (var i = 0; i < m.rows.length; i++) { if (m.rows[i].gate === gate) return m.rows[i]; }
        return null;
    }
    function matAvail(mat) {
        var m = G.mats[mat];
        var t = 0;
        (m ? m.rows : []).forEach(function (r) { if (r.avail > 0) t += r.avail; });
        return t;
    }
    function inCart(gate, mat) {
        var q = 0;
        G.cart.forEach(function (c) { if ((gate === null || c.gate === gate) && c.matCode === mat) q += c.qty; });
        return q;
    }
    function gateName(code) {
        var n = '';
        ((G.form && G.form.gates) || []).forEach(function (g) { if (g.code === code) n = g.name || ''; });
        return n && n !== code ? n : '';
    }
    function selMat() { return $('#tgMatSelect').value || ''; }
    function selGate() { return $('#tgGateSelect').value || ''; }
    function selDest() { return $('#tgDestSelect').value || ''; }
    function hasSelect2(el) { return !!(window.jQuery && window.jQuery.fn && window.jQuery.fn.select2 && window.jQuery(el).data('select2')); }

    function renderMatOptions() {
        var sel = $('#tgMatSelect');
        var keep = selMat();
        var list = G.matOrder.filter(function (k) { return matAvail(k) > 0; });
        if (hasSelect2(sel)) { try { window.jQuery(sel).select2('destroy'); } catch (e) {} }
        sel.innerHTML = list.length
            ? '<option value=""></option>' + list.map(function (k) {
                var m = G.mats[k];
                return '<option value="' + esc(k) + '" data-unit="' + esc(m.unit) + '" data-char="' + esc(m.char) + '">' + esc(m.name) + '</option>';
            }).join('')
            : '<option value="">ไม่มีวัสดุที่มียอดในประตูของไซต์นี้</option>';
        if (keep && G.mats[keep] && matAvail(keep) > 0) sel.value = keep;
        if (window.jQuery && window.jQuery.fn && window.jQuery.fn.select2 && list.length) {
            try {
                var $s = window.jQuery(sel);
                $s.select2({
                    width: '100%',
                    placeholder: '-- เลือกวัสดุ (' + list.length + ' รายการที่มียอด) --',
                    allowClear: true,
                    language: { noResults: function () { return 'ไม่พบวัสดุ'; } },
                    matcher: typeof window.matCodeMatcher === 'function' ? window.matCodeMatcher : undefined,
                    templateResult: matResult
                });
                $s.off('change.tg').on('change.tg', function (e) { if (!e.originalEvent) onMatChange(); });
            } catch (e) {}
        }
        renderGateOptions(selGate());
        renderMatHint();
    }
    function matResult(d) {
        if (!d.id || !window.jQuery) return d.text;
        var m = G.mats[d.id];
        if (!m) return d.text;
        var gates = m.rows.filter(function (r) { return r.avail > 0; }).map(function (r) { return r.gate; }).join(' · ');
        return window.jQuery('<div class="td-opt"><div class="td-opt-name">' + esc(m.name) + ' ' + clsChip(m.cls) +
            (m.char && m.char !== m.cls ? ' <span class="td-opt-char">' + esc(m.char) + '</span>' : '') + '</div>' +
            '<div class="td-opt-sub">' + esc(m.matCode) + ' — ย้ายได้ ' + num(matAvail(m.matCode)) + (m.unit ? ' ' + esc(m.unit) : '') + (gates ? ' · ' + esc(gates) : '') + '</div></div>');
    }
    function onMatChange() {
        renderGateOptions('');
        renderMatHint();
        resetQty();
    }
    function renderGateOptions(keepGate) {
        var sel = $('#tgGateSelect');
        var mat = selMat();
        var m = G.mats[mat];
        if (!mat || !m) {
            sel.innerHTML = '<option value="" disabled selected>-- เลือกวัสดุก่อน --</option>';
            renderGateHint();
            return;
        }
        var u = m.unit ? ' ' + m.unit : '';
        var dest = selDest();
        var ok = function (r) { return r.avail > 0 && r.gate !== dest; };
        var usable = m.rows.filter(ok);
        sel.innerHTML = '<option value="" disabled' + (usable.length === 1 ? '' : ' selected') + '>-- เลือกประตูต้นทาง --</option>' +
            m.rows.map(function (r) {
                var gn = gateName(r.gate);
                var why = r.avail <= 0 ? ' (หมด)' : (r.gate === dest ? ' (= ปลายทาง)' : '');
                return '<option value="' + esc(r.gate) + '"' + (ok(r) ? '' : ' disabled') + '>' + esc(r.gate) + (gn ? ' · ' + esc(gn) : '') +
                       ' — คงเหลือ ' + esc(num(Math.max(0, r.avail))) + esc(u) + why + '</option>';
            }).join('');
        var want = '';
        var kr = keepGate ? stockRow(mat, keepGate) : null;
        if (kr && ok(kr)) want = keepGate;
        else if (usable.length === 1) want = usable[0].gate;
        if (want) sel.value = want;
        renderGateHint();
    }
    function renderGateHint() {
        var hint = $('#tgGateHint');
        var m = G.mats[selMat()];
        var gate = selGate();
        if (!m) { hint.innerHTML = 'วัสดุชนิดเดียวกันอยู่ได้หลายประตู — เลือกประตูที่จะเบิกของออก'; return; }
        if (!gate) { hint.innerHTML = 'วัสดุนี้มีของอยู่ ' + m.rows.length + ' ประตู — เลือกประตูต้นทาง'; return; }
        var r = stockRow(m.matCode, gate);
        if (!r) { hint.innerHTML = ''; return; }
        var u = m.unit ? ' ' + esc(m.unit) : '';
        var used = inCart(gate, m.matCode);
        hint.innerHTML = '<strong>ย้ายได้จากประตูนี้:</strong> ' + num(Math.max(0, r.avail)) + u +
            (r.pending > 0 ? ' <span style="color:var(--text-muted);">(ในคลัง ' + num(r.onHand) + ' · จองไว้ ' + num(r.pending) + ')</span>' : '') +
            (used > 0 ? '<br><strong>อยู่ในรายการแล้ว:</strong> ' + num(used) + u + ' — เพิ่มได้อีก ' + num(Math.max(0, r.avail - used)) + u : '');
    }
    function renderMatHint() {
        var hint = $('#tgMatHint');
        var m = G.mats[selMat()];
        if (!m) { hint.innerHTML = 'เลือกวัสดุเพื่อดูยอดแยกตามประตู'; return; }
        var u = m.unit ? ' ' + esc(m.unit) : '';
        var parts = m.rows.map(function (r) {
            return '<span class="cw-gate-tag">' + esc(r.gate) + '</span> ' + num(Math.max(0, r.avail)) + u +
                   (r.pending > 0 ? ' <span style="color:var(--text-muted);">(จองไว้ ' + num(r.pending) + ')</span>' : '');
        });
        hint.innerHTML = '<strong>หมวด:</strong> ' + clsChip(m.cls) + ' <span style="color:var(--text-muted);">' +
            (m.cls === 'C01' ? 'ต้อง R6 ขึ้นไปอนุมัติ' : (m.cls === 'NAR' ? 'ไม่ต้องอนุมัติ' : 'ต้อง R4 ขึ้นไปอนุมัติ')) + '</span>' +
            '<br><strong>วัสดุ:</strong> ' + esc(m.name) + ' <span style="color:var(--text-muted);">' + esc(m.matCode) + '</span>' +
            '<br><strong>แยกตามประตู:</strong> ' + parts.join(' · ');
    }

    // ---- จำนวน: หน้าต่าง +/− ของแท็บเบิก (formKey 'tg') ----
    function resetQty() {
        var el = $('#tgQtyInput');
        if (!el) return;
        el.value = '';
        el.dataset.qty = '';
        el.dataset.hasValue = '';
        el.removeAttribute('data-has-value');
    }
    function openQty() {
        if (G.form && G.form.canCreate !== true) return;
        var mat = selMat();
        var gate = selGate();
        var m = G.mats[mat];
        if (!m) { info('ยังไม่ได้เลือกวัสดุ', 'กรุณาเลือกวัสดุก่อนระบุจำนวน', 'warning'); return; }
        if (!gate) { info('ยังไม่ได้เลือกประตูต้นทาง', 'เลือกประตูที่จะเบิกของออกก่อนระบุจำนวน', 'warning'); return; }
        var r = stockRow(mat, gate);
        var left = (r ? r.avail : 0) - inCart(gate, mat);
        if (left <= 0) {
            info('ประตูนี้ไม่มีของเหลือให้ย้าย', esc(m.name) + ' ที่ ' + esc(gate) + ' ย้ายได้ ' + num(Math.max(0, r ? r.avail : 0)) +
                 (inCart(gate, mat) > 0 ? ' — อยู่ในรายการครบแล้ว' : ''), 'warning');
            return;
        }
        if (typeof window.openQtyModal !== 'function') {
            var v = parseInt(window.prompt('จำนวน (สูงสุด ' + left + ')', '1') || '', 10);
            if (v > 0) { var el = $('#tgQtyInput'); el.value = v + (m.unit ? ' ' + m.unit : ''); el.dataset.qty = v; }
            return;
        }
        seedGateBalance(mat, gate, r);
        window.openQtyModal('tgQtyInput', 'tgMatSelect', FORM_KEY);
    }
    function seedGateBalance(mat, gate, r) {
        try {
            var gb = window.globalGateBalance;
            if (!gb || typeof gb !== 'object' || !r) return;
            var rows = gb[mat] || (gb[mat] = []);
            for (var i = 0; i < rows.length; i++) { if ((rows[i].GateID || '') === gate) return; }
            rows.push({ MatCode: mat, GateID: gate, GateName: gateName(gate), OnHand: r.onHand, Pending: r.pending });
        } catch (e) {}
    }
    /** หน้าต่างจำนวน: "อยู่ในรายการแล้ว" ของ formKey 'tg' นับจากรายการย้าย */
    function patchDraftCounters() {
        var o1 = window.getDraftedQtyAtGate;
        if (typeof o1 === 'function' && !o1.__tgWrapped) {
            var w1 = function (matCode, formKey, gateId) {
                if (formKey === FORM_KEY) return inCart(gateId || '', matCode);
                return o1.apply(this, arguments);
            };
            w1.__tgWrapped = true;
            window.getDraftedQtyAtGate = w1;
        }
        var o2 = window.getDraftedQty;
        if (typeof o2 === 'function' && !o2.__tgWrapped) {
            var w2 = function (matCode, formKey) {
                if (formKey === FORM_KEY) return inCart(null, matCode);
                return o2.apply(this, arguments);
            };
            w2.__tgWrapped = true;
            window.getDraftedQty = w2;
        }
    }

    // ---- ตะกร้า ----
    function addToCart() {
        if (G.form && G.form.canCreate !== true) return;
        var mat = selMat();
        var gate = selGate();
        var qty = parseInt($('#tgQtyInput').dataset.qty || '', 10);
        var m = G.mats[mat];
        if (!m) { toast('เลือกวัสดุ', 'warning'); return; }
        if (!gate) { toast('เลือกประตูต้นทาง', 'warning'); return; }
        if (gate === selDest()) { info('ประตูซ้ำกัน', 'ประตูต้นทางต้องไม่ใช่ประตูปลายทาง (' + esc(gate) + ')', 'warning'); return; }
        if (!(qty > 0)) { toast('แตะช่องจำนวนเพื่อระบุจำนวน', 'warning'); return; }
        var r = stockRow(mat, gate);
        if (!r) { toast('ไม่พบยอดของวัสดุนี้ที่ประตู ' + gate, 'danger'); return; }
        var used = inCart(gate, mat);
        if (qty + used > r.avail + 0.0005) {
            info('จำนวนเกินยอดที่ย้ายได้', esc(m.name) + '<br>ย้ายได้จาก ' + esc(gate) + ' ' + num(Math.max(0, r.avail)) + (used > 0 ? ' (อยู่ในรายการแล้ว ' + num(used) + ')' : ''), 'warning');
            return;
        }
        if (G.cart.length >= ((G.form && G.form.maxLines) || 60)) { info('รายการเต็ม', 'ใบหนึ่งไม่เกิน ' + ((G.form && G.form.maxLines) || 60) + ' บรรทัด — ส่งใบนี้ก่อน', 'warning'); return; }
        var found = null;
        G.cart.forEach(function (c) { if (c.gate === gate && c.matCode === mat) found = c; });
        if (found) { found.qty = found.qty + qty; toast('รวมจำนวนกับรายการเดิมแล้ว', 'info'); }
        else G.cart.push({ gate: gate, matCode: mat, name: m.name, unit: m.unit, cls: m.cls, qty: qty });
        if (typeof window.hapticTap === 'function') window.hapticTap();
        resetQty();
        renderCart();
        renderMatHint();
        renderGateOptions(gate);
    }

    function cartLevel() { return reqLevelOf(G.cart.map(function (c) { return c.cls; })); }

    function renderCart() {
        var box = $('#tgCart');
        if (!box) return;
        $('#tgCartCount').textContent = G.cart.length;
        var dest = selDest();
        if (!G.cart.length) {
            box.innerHTML = '<div class="td-empty"><i class="fa-solid fa-dolly"></i> ยังไม่มีรายการ — เลือกวัสดุ ประตูต้นทาง จำนวน แล้วกด "เพิ่มลงรายการย้าย"</div>';
        } else {
            var gates = {};
            G.cart.forEach(function (c) { gates[c.gate] = true; });
            var nGate = Object.keys(gates).length;
            box.innerHTML = (nGate > 1 ? '<div class="td-split-note tg-split-note"><i class="fa-solid fa-code-branch"></i> ของจาก ' + nGate + ' ประตู — ระบบแยกเป็น ' + nGate + ' ใบ (ใบละประตูต้นทาง) เลขรันเดียวกัน</div>' : '') +
                G.cart.map(function (c, i) {
                    var clash = dest && c.gate === dest;
                    return '<div class="td-line' + (clash ? ' tg-clash' : '') + '">' +
                        '<span class="td-gate tg-gate">' + esc(c.gate) + (dest ? ' <i class="fa-solid fa-arrow-right"></i> ' + esc(dest) : '') + '</span>' +
                        '<div class="td-line-main"><div class="td-line-name">' + esc(c.name) + ' ' + clsChip(c.cls) + '</div><div class="td-line-code">' + esc(c.matCode) +
                            (clash ? ' · <b class="td-warn">ต้นทาง = ปลายทาง</b>' : '') + '</div></div>' +
                        '<div class="td-line-qty">' + num(c.qty) + (c.unit ? ' <small>' + esc(c.unit) + '</small>' : '') + '</div>' +
                        '<button type="button" class="td-line-del" data-i="' + i + '" title="ลบ"><i class="fa-solid fa-xmark"></i></button>' +
                    '</div>';
                }).join('');
            Array.prototype.forEach.call(box.querySelectorAll('.td-line-del'), function (b) {
                b.addEventListener('click', function () {
                    G.cart.splice(Number(b.getAttribute('data-i')), 1);
                    renderCart();
                    renderMatHint();
                    renderGateHint();
                });
            });
        }
        renderApproval();
    }

    function renderDestHint() {
        var dest = selDest();
        var hint = $('#tgDestHint');
        if (!hint) return;
        if (!dest) { hint.innerHTML = 'G ที่จะนำของเข้า — QR นำเข้าต้องสแกนที่ตู้ของประตูนี้'; return; }
        hint.innerHTML = 'QR นำเข้าสแกนที่ตู้ <b>' + esc(dest) + '</b>' + (gateName(dest) ? ' · ' + esc(gateName(dest)) : '') + ' — ได้หลังปิดประตูต้นทางแล้ว';
        renderGateOptions(selGate());
    }

    function renderApproval() {
        var box = $('#tgReqBox');
        var grp = $('#tgApproverGroup');
        var sel = $('#tgApproverSelect');
        var hint = $('#tgApproverHint');
        if (!box || !sel) return;
        if (!G.cart.length) { box.innerHTML = ''; grp.style.display = 'none'; return; }
        var lvl = cartLevel();
        box.innerHTML = '<i class="fa-solid fa-stamp"></i> เกณฑ์อนุมัติของใบนี้: ' + reqText(lvl);
        box.className = 'tg-req-box lvl' + lvl;
        if (lvl === 0) { grp.style.display = 'none'; sel.innerHTML = ''; return; }
        grp.style.display = '';
        var keep = sel.value;
        var cands = ((G.form && G.form.approvers) || []).filter(function (a) { return a.level >= lvl; });
        if (!cands.length) {
            sel.innerHTML = '<option value="">ไม่มีผู้อนุมัติ R' + lvl + ' ขึ้นไปในไซต์นี้</option>';
            sel.disabled = true;
            hint.innerHTML = '<span class="td-warn">ส่งใบไม่ได้จนกว่าจะมีผู้อนุมัติระดับ R' + lvl + ' ขึ้นไปในระบบ</span>';
            return;
        }
        sel.disabled = !(G.form && G.form.canCreate === true) || cands.length === 1;
        sel.innerHTML = (cands.length > 1 ? '<option value="">-- เลือกผู้อนุมัติ (R' + lvl + ' ขึ้นไป) --</option>' : '') + cands.map(function (a) {
            return '<option value="' + esc(a.username) + '">' + esc(a.fullName) + ' (' + esc(a.role) + ' · R' + a.level + ')</option>';
        }).join('');
        if (keep && cands.some(function (a) { return a.username === keep; })) sel.value = keep;
        hint.textContent = cands.length === 1 ? 'มีผู้อนุมัติที่ถึงเกณฑ์คนเดียว — กำหนดให้อัตโนมัติ' : 'เลือกผู้อนุมัติที่ระดับถึงเกณฑ์ของหมวดวัสดุในใบ';
    }

    function submit() {
        if (G.submitting) return;
        var f = G.form || {};
        if (f.canCreate !== true) { info('ไม่มีสิทธิ์', 'ใบย้าย Gate ออกได้เฉพาะเจ้าหน้าที่สโตร์', 'warning'); return; }
        var dest = selDest();
        var note = $('#tgNoteInput').value.trim();
        var approver = $('#tgApproverSelect').value;
        if (!G.cart.length) { toast('ยังไม่มีรายการที่จะย้าย', 'warning'); return; }
        if (!dest) { toast('เลือกประตูปลายทาง', 'warning'); $('#tgDestSelect').focus(); return; }
        var clash = G.cart.filter(function (c) { return c.gate === dest; });
        if (clash.length) { info('ประตูซ้ำกัน', 'มีรายการที่ต้นทางเป็น ' + esc(dest) + ' (= ปลายทาง) — ลบรายการนั้นหรือเปลี่ยนประตูปลายทาง', 'warning'); return; }
        var lvl = cartLevel();
        var cands = (f.approvers || []).filter(function (a) { return a.level >= lvl; });
        if (lvl > 0 && !cands.length) { info('ไม่มีผู้อนุมัติ', 'ไซต์นี้ยังไม่มีผู้อนุมัติระดับ R' + lvl + ' ขึ้นไป', 'warning'); return; }
        if (lvl > 0 && cands.length > 1 && !approver) { toast('เลือกผู้อนุมัติ', 'warning'); return; }
        var lines = G.cart.map(function (c) {
            return '• ' + esc(c.gate) + ' → ' + esc(dest) + ' · ' + esc(c.name) + ' ' + clsChip(c.cls) + ' <b>' + num(c.qty) + (c.unit ? ' ' + esc(c.unit) : '') + '</b>';
        }).join('<br>');
        var who = lvl === 0 ? 'NAR ล้วน — อนุมัติทันที ได้ QR เบิกออกเลย'
                : 'รอ ' + esc(fullName(approver || (cands[0] && cands[0].username) || '')) + ' อนุมัติ (R' + lvl + ' ขึ้นไป)';
        var msg = 'ย้ายของภายในไซต์ <b>' + esc((f.site && f.site.code) || '') + '</b> ไปประตู <b>' + esc(dest) + '</b><br><br>' + lines +
                  '<br><br>' + who +
                  '<br><span style="color:var(--text-muted);font-size:0.85rem;">① สแกน QR เบิกออกที่ประตูต้นทาง (บัตรสายสโตร์) → ถ่ายรูป → ปิดประตู = ตัดยอดต้นทาง · ' +
                  '② สแกน QR นำเข้าที่ ' + esc(dest) + ' → ถ่ายรูป → ปิดประตู = เพิ่มยอด ' + esc(dest) + '</span>';
        var go = function () {
            G.submitting = true;
            var btn = $('#tgSubmitBtn');
            btn.disabled = true;
            if (typeof window.showLoadingPopup === 'function') window.showLoadingPopup('กำลังส่งใบย้าย Gate', 'กรุณารอสักครู่...');
            rpc('processGateMoveSubmission', [{
                destGate: dest, note: note, approver: approver,
                items: G.cart.map(function (c) { return { MatCode: c.matCode, Qty: c.qty, GateID: c.gate }; })
            }], function (res) {
                G.submitting = false;
                btn.disabled = false;
                if (typeof window.closeAppPopup === 'function') window.closeAppPopup();
                if (!res || !res.success) {
                    info('ส่งใบย้าย Gate ไม่สำเร็จ', esc((res && res.message) || '-').replace(/\n/g, '<br>'), 'danger');
                    return;
                }
                G.cart = [];
                $('#tgNoteInput').value = '';
                renderCart();
                invalidate();
                if (typeof window.hapticSuccess === 'function') window.hapticSuccess();
                info(res.approved ? 'ออกใบย้าย Gate แล้ว' : 'ส่งใบย้าย Gate แล้ว', esc(res.message || ''), 'success');
                loadForm();
            }, function (m) {
                G.submitting = false;
                btn.disabled = false;
                if (typeof window.closeAppPopup === 'function') window.closeAppPopup();
                info('ส่งใบย้าย Gate ไม่สำเร็จ', esc(m), 'danger');
            });
        };
        if (typeof window.showConfirmPopup === 'function') window.showConfirmPopup('ยืนยันส่งใบย้าย Gate', msg, go, 'ส่งใบย้าย');
        else if (confirm('ยืนยันส่งใบย้าย Gate?')) go();
    }

    // ------------------------------------------------------------------ รายการใบย้าย
    function loadList(force) {
        var box = $('#tgList');
        if (!box) return;
        if (force) box.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-spinner fa-spin"></i><span>กำลังโหลด...</span></div>';
        rpc('getGateMoveList', [], function (res) {
            if (!res || !res.success) {
                box.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-triangle-exclamation"></i><span>' + esc((res && res.message) || 'โหลดไม่สำเร็จ') + '</span></div>';
                return;
            }
            G.list = res.docs || [];
            renderList();
        }, function (m) {
            box.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-triangle-exclamation"></i><span>' + esc(m) + '</span></div>';
        });
    }

    function stateClass(d) {
        var s = String(d.status || '').toLowerCase();
        if (s.indexOf('cancel') !== -1 || s.indexOf('reject') !== -1) return 'dead';
        if (d.done) return 'done';
        if (s.indexOf('awaiting') !== -1) return 'wait';
        return d.transit ? 'live tg-transit' : 'live';
    }

    function legPill(label, st, active) {
        var s = String(st || '').toLowerCase();
        var cls = s === 'closed' || (s === 'confirmed' && !active) ? 'ok' : (s === 'awaiting' ? 'wait' : (s ? 'run' : 'none'));
        var txt = s === '' ? '—' : (s === 'awaiting' ? 'รอสแกน' : (s === 'opened' || s === 'scanned' ? 'แตะบัตรแล้ว' : (s === 'confirmed' ? 'ยืนยันแล้ว' : (s === 'closed' ? 'ปิดแล้ว' : (s === 'cancelled' ? 'ยกเลิก' : st)))));
        return '<span class="tg-leg ' + cls + '"><b>' + label + '</b> ' + esc(txt) + '</span>';
    }

    function docCard(d, i) {
        var items = (d.items || []).map(function (it) {
            var parts = [];
            if (it.qtyOut != null) parts.push('เบิกออก ' + num(it.qtyOut));
            if (it.qtyIn != null) parts.push('นำเข้า ' + num(it.qtyIn));
            return '<div class="td-it"><span class="td-it-name">' + esc(it.name || it.matCode) + ' ' + clsChip(it.cls) + ' <small>' + esc(it.matCode) + '</small></span>' +
                   '<span class="td-it-qty">' + num(it.qty) + (it.unit ? ' ' + esc(it.unit) : '') +
                   (parts.length ? '<span class="td-act tg-act">' + esc(parts.join(' · ')) + '</span>' : '') + '</span></div>';
        }).join('');
        var actions = '';
        if (d.qrNo) {
            actions += '<button type="button" class="btn btn-primary tg-qr" data-i="' + i + '"><i class="fa-solid fa-qrcode"></i> ' +
                (d.qrLeg === 'in' ? 'QR นำเข้า ' + esc(d.destGate) : 'QR เบิกออก ' + esc(d.srcGate)) + '</button>';
        }
        if (d.canCancel) actions += '<button type="button" class="btn btn-danger tg-cancel" data-doc="' + esc(d.docId) + '"><i class="fa-solid fa-ban"></i> ยกเลิก</button>';
        actions += '<button type="button" class="btn btn-secondary tg-pdf" data-doc="' + esc(d.docId) + '"><i class="fa-solid fa-file-pdf"></i> PDF</button>';
        return '<div class="td-doc tg-doc ' + stateClass(d) + '">' +
            '<div class="td-doc-head"><div><div class="td-doc-id">' + esc(d.docId) + '</div>' +
                '<div class="td-doc-route tg-route"><span class="tg-g">' + esc(d.srcGate || '-') + '</span> <i class="fa-solid fa-arrow-right"></i> <span class="tg-g">' + esc(d.destGate || '-') + '</span></div></div>' +
                '<span class="td-state">' + esc(d.statusThai || d.status) + '</span></div>' +
            '<div class="tg-legs">' + legPill('① เบิกออก ' + (d.srcGate || ''), d.outGate, !d.transit && !d.done) +
                legPill('② นำเข้า ' + (d.destGate || ''), d.inGate, d.transit) + '</div>' +
            '<div class="td-doc-meta">' + esc(d.dateStr) + ' · ผู้ส่ง ' + esc(fullName(d.reqName)) +
                (d.approver && d.approver !== d.reqName ? ' · ผู้อนุมัติ ' + esc(fullName(d.approver)) : '') +
                (d.inDate ? ' · นำเข้า ' + esc(d.inDate) : '') + '</div>' +
            '<div class="td-its">' + items + '</div>' +
            (d.note ? '<div class="td-doc-note">หมายเหตุ: ' + esc(d.note) + '</div>' : '') +
            '<div class="td-doc-actions">' + actions + '</div>' +
        '</div>';
    }

    function renderList() {
        var box = $('#tgList');
        if (!box) return;
        var L = G.list || [];
        box.innerHTML = L.length ? L.map(docCard).join('')
            : '<div class="td-empty"><i class="fa-solid fa-inbox"></i> ยังไม่มีใบย้าย Gate ของไซต์นี้</div>';
        Array.prototype.forEach.call(box.querySelectorAll('.tg-qr'), function (b) {
            b.addEventListener('click', function () { openLegQr(L[Number(b.getAttribute('data-i'))]); });
        });
        Array.prototype.forEach.call(box.querySelectorAll('.tg-cancel'), function (b) {
            b.addEventListener('click', function () { cancelDoc(b.getAttribute('data-doc')); });
        });
        Array.prototype.forEach.call(box.querySelectorAll('.tg-pdf'), function (b) {
            b.addEventListener('click', function () {
                if (typeof window.downloadDocReport === 'function') window.downloadDocReport(b.getAttribute('data-doc'), 'TG');
            });
        });
    }

    function openLegQr(d) {
        if (!d || !d.qrNo || typeof window.showQRModal !== 'function') return;
        var isIn = d.qrLeg === 'in';
        var items = (d.items || []).map(function (it) {
            var q = isIn ? (it.qtyOut != null ? it.qtyOut : it.qty) : it.qty;
            return { matCode: it.matCode, matName: it.name, qty: q, unit: it.unit };
        }).filter(function (it) { return Number(it.qty) > 0; });
        var recv = isIn ? 'นำเข้า ' + d.destGate + ' (จาก ' + d.srcGate + ')' : 'ย้ายไป ' + d.destGate;
        window.showQRModal(d.qrNo, 'TG', items, recv, d.reqName || '');
    }

    function cancelDoc(docId) {
        var go = function () {
            rpc('cancelRequisition', [docId, 'TG', me()], function (res) {
                if (!res || !res.success) { info('ยกเลิกไม่สำเร็จ', esc((res && res.message) || '-').replace(/\n/g, '<br>'), 'danger'); return; }
                toast(res.message || 'ยกเลิกแล้ว', 'success');
                invalidate();
                loadForm();
            });
        };
        if (typeof window.showConfirmPopup === 'function') {
            window.showConfirmPopup('ยกเลิกใบย้าย Gate', 'ยกเลิกใบ <b>' + esc(docId) + '</b> ? ยอดที่จองไว้ที่ประตูต้นทางจะคืนกลับ', go, 'ยกเลิกใบ', 'btn btn-danger');
        } else if (confirm('ยกเลิกใบ ' + docId + ' ?')) go();
    }

    // ------------------------------------------------------------------ หน้า QR · หน้าถ่ายรูปยืนยัน · ประวัติ
    /** หลัง renderQRCards: หัวกลุ่ม TG · ป้าย เบิกออก/นำเข้า · การ์ดขานำเข้าไม่มีปุ่มยกเลิก (ยกเลิกไม่ได้หลังเบิกออก) */
    function decorateQrCards() {
        var grid = document.getElementById('qrCardsGrid');
        if (!grid) return;
        Array.prototype.forEach.call(grid.querySelectorAll('.qr-type-section[data-type="TG"] .qr-type-subheader'), function (h) {
            if (h.getAttribute('data-tg')) return;
            h.setAttribute('data-tg', '1');
            var ic = h.querySelector('i.fa-solid');
            if (ic) ic.className = 'fa-solid fa-right-left';
            var sp = h.querySelector('span');
            if (sp) sp.textContent = 'ย้าย Gate (TG)';
        });
        Array.prototype.forEach.call(grid.querySelectorAll('.qr-doc-card'), function (card) {
            var idEl = card.querySelector('.qrc-doc-id');
            var id = idEl ? idEl.textContent.trim() : '';
            var mi = IN_LEG_RE.exec(id);
            var mo = !mi && OUT_LEG_RE.exec(id);
            if (!mi && !mo) return;
            if (card.getAttribute('data-tg')) return;
            card.setAttribute('data-tg', mi ? 'in' : 'out');
            var head = card.querySelector('.qrc-header > div:last-child');
            if (head) {
                var b = document.createElement('span');
                b.className = 'qrc-move-badge ' + (mi ? 'in' : 'out');
                b.innerHTML = mi ? '<i class="fa-solid fa-arrow-right-to-bracket"></i> นำเข้า ' + esc(mi[3].toUpperCase())
                                 : '<i class="fa-solid fa-arrow-right-from-bracket"></i> เบิกออก';
                head.insertBefore(b, head.firstChild);
            }
            if (mi) {
                var cancel = card.querySelector('.qrc-actions .btn-danger');
                if (cancel) cancel.remove();
            }
        });
    }

    /** หน้าถ่ายรูปยืนยัน (js/scenario05.js render) — ขานำเข้า: จำนวนเท่าที่เบิกออก แก้ไม่ได้ */
    function decorateConfirm() {
        var wrap = document.getElementById('confirmWizard');
        if (!wrap) return;
        var card = wrap.querySelector('.s5-card[data-doc]');
        if (!card) return;
        var id = card.getAttribute('data-doc') || '';
        var mi = IN_LEG_RE.exec(id);
        var mo = !mi && OUT_LEG_RE.exec(id);
        if (!mi && !mo) return;
        var hint = card.querySelector('.s5-hint');
        var row = card.querySelector('.s5-chips-row');
        if (mi) {
            if (hint) hint.innerHTML = '<b>ใบย้าย Gate — ขานำเข้า ' + esc(mi[3].toUpperCase()) + '</b> · จำนวน = เท่าที่เบิกออกจาก ' + esc(mi[2].toUpperCase()) +
                ' (แก้ไม่ได้) · ถ่ายรูป<b>อย่างน้อยรายการละ 1 รูป</b> · ปิดประตูแล้วระบบเพิ่มยอดที่ ' + esc(mi[3].toUpperCase());
        } else if (hint && hint.innerHTML.indexOf('ใบย้าย Gate') === -1) {
            hint.innerHTML = '<b>ใบย้าย Gate — ขาเบิกออก ' + esc(mo[1].toUpperCase()) + '</b> · ' + hint.innerHTML;
        }
        if (!card.querySelector('.tg-chip')) {
            var chip = '<span class="s5-chip-i tg-chip"><i class="fa-solid fa-right-left"></i> ' +
                (mi ? 'นำเข้า ' + esc(mi[3].toUpperCase()) + ' (จาก ' + esc(mi[2].toUpperCase()) + ')' : 'เบิกออก ' + esc(mo[1].toUpperCase())) + ' · สายสโตร์เท่านั้น</span>';
            if (row) row.insertAdjacentHTML('beforeend', chip);
            else {
                var meta = card.querySelector('.cw-doc-meta');
                if (meta) meta.insertAdjacentHTML('afterend', '<div class="s5-chips-row">' + chip + '</div>');
            }
        }
    }

    function watchConfirm() {
        var wrap = document.getElementById('confirmWizard');
        if (!wrap || wrap.__tgObs || typeof MutationObserver !== 'function') return;
        wrap.__tgObs = new MutationObserver(function () { try { decorateConfirm(); } catch (e) {} });
        wrap.__tgObs.observe(wrap, { childList: true });
        decorateConfirm();
    }

    function patchHistoryFilter() {
        var sel = document.getElementById('historyFilterType');
        if (!sel || sel.querySelector('option[value="TG"]')) return;
        var o = document.createElement('option');
        o.value = 'TG';
        o.textContent = 'ย้าย Gate ภายในไซต์ (TG)';
        sel.appendChild(o);
    }

    function init() {
        patchDraftCounters();
        wrapAfter('renderQRCards', decorateQrCards);
        decorateQrCards();
        watchConfirm();
        patchHistoryFilter();
        // หน้าเบิกเปิดค้างที่แท็บโอนย้าย โหมดภายใน ตอนโหลดหน้า
        var pane = document.getElementById('req-transfer');
        var mode = window.cnxTransfer && typeof window.cnxTransfer.mode === 'function' ? window.cnxTransfer.mode() : 'ext';
        if (pane && pane.classList.contains('active') && mode === 'int') activate(false);
        else build();
    }

    window.cnxGateMove = { activate: activate, reload: function () { activate(true); } };

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
