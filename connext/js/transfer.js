/*
 * CONNEXT — js/transfer.js  [PHP port 2026-09-29 · TD ใบเบิกโอนย้ายข้ามไซต์]
 *
 * 1) หน้า "ระบบเบิก-จ่ายวัสดุ": แท็บ "โอนย้ายข้ามไซต์ (TD)" — ลำดับเดียวกับแท็บเบิก: วัสดุ → ประตูที่เบิกออก → จำนวน → เพิ่ม
 *    · วัสดุ = รหัส IC ทุกชนิด (CSB/NAR/BRB/WMS — WMS โอนได้เฉพาะทาง TD) ที่มียอดพร้อมเบิก > 0 ที่ประตูใดประตูหนึ่ง
 *    · ประตู = ทุกประตูที่มีวัสดุนั้น (หมด = เทา) · มีของประตูเดียว = เลือกให้เอง · จำนวน = หน้าต่าง +/− เดียวกับแท็บเบิก (จำนวนเต็ม ·
 *      เพดาน = พร้อมเบิกที่ประตูนั้น − ที่อยู่ในรายการแล้ว) · ไซต์ปลายทาง/ผู้รับ/หมายเหตุ/PM กรอกฝั่งรายการตอนส่งใบ (แตกใบตามประตู)
 *    ผู้อนุมัติ = PM ของไซต์นี้เท่านั้น (ผู้ส่งเป็น PM = อนุมัติทันที) · ต้นทางตัดสต๊อกเมื่อปิดประตู
 * 2) รายการ "ใบโอนของไซต์นี้" (สถานะ · หยิบจริง · ยกเลิก · PDF) + "ของที่ไซต์อื่นโอนมา" (อ้างอิง — ปลายทางคีย์ใบรับเข้า IN เอง)
 * 3) ป้ายชนิดเอกสาร TD / SC · หัว QR ของใบโอน · ปุ่มกรอง TD หน้า QR
 * RPC ฝั่ง server: lib/transfer.php (getTransferFormData · processTransferSubmission · getTransferList)
 *                  lib/approval.php (cancelRequisition · PM อนุมัติ) · lib/pdf_api.php (generateDocReportPDF)
 * ไม่มีใน GAS — ถ้า build index.php ใหม่ให้เติม <link>/<script defer> ไฟล์นี้กลับก่อน </head>
 *
 * [2026-10-02] แท็บเปลี่ยนชื่อเป็น "โอนย้าย" + ตัวเลือก ภายนอก (ข้ามไซต์ · TD) / ภายใน site (ย้าย Gate · TG)
 *   โหมดภายในอยู่ใน js/gate-move.js (window.cnxGateMove) — ไฟล์นี้ทำแค่ปุ่มสลับ/จำโหมด · ป้าย TG · ปุ่มกรอง TG · หัว QR ของ TG
 */
(function () {
    'use strict';

    var T = { form: null, mats: {}, matOrder: [], cart: [], lists: null, loading: false, submitting: false, loadedOnce: false };
    var FORM_KEY = 'td';   // หน้าต่างจำนวนของแท็บเบิกหาประตูจาก <formKey>GateSelect → tdGateSelect

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
        if (typeof orig !== 'function' || orig.__tdWrapped) return;
        var w = function () {
            var r = orig.apply(this, arguments);
            try { fn.apply(this, arguments); } catch (e) { console.warn('[transfer] ' + name + ':', e); }
            return r;
        };
        w.__tdWrapped = true;
        window[name] = w;
    }
    function rpc(fn, args, ok, fail) {
        var r = google.script.run.withSuccessHandler(ok).withFailureHandler(function (err) {
            var msg = (err && err.message) ? err.message : String(err || 'เชื่อมต่อไม่สำเร็จ');
            if (fail) fail(msg); else info('ผิดพลาด', esc(msg), 'danger');
        });
        r[fn].apply(r, args || []);
    }

    // ------------------------------------------------------------------ แท็บในหน้าเบิก
    function inject() {
        var cont = $('#requisition-page .tabs-container');
        var header = cont && cont.querySelector('.tabs-header');
        if (!header || document.getElementById('req-transfer')) return;
        var btn = document.createElement('button');
        btn.className = 'tab-btn';
        btn.setAttribute('onclick', "switchRequisitionTab('req-transfer', this)");
        // โครงเดียวกับแท็บเดิมหลัง js/mobile-flow.js จัด (ชื่อเต็มจอใหญ่ · ชื่อสั้นจอมือถือ)
        btn.innerHTML = '<i class="fa-solid fa-truck-arrow-right"></i> <span class="mf-tab-long">โอนย้าย (ข้ามไซต์ / ย้าย Gate)</span><span class="mf-tab-short">โอนย้าย</span>';
        header.appendChild(btn);

        var pane = document.createElement('div');
        pane.id = 'req-transfer';
        pane.className = 'tab-content';
        pane.innerHTML =
            // [2026-10-02] เลือกชนิดการโอนย้าย — ภายนอก = ข้ามไซต์ (TD) · ภายใน = ย้าย Gate ในไซต์ (TG · js/gate-move.js)
            '<div class="tf-mode" role="tablist" aria-label="ชนิดการโอนย้าย">' +
                '<button type="button" class="tf-mode-btn" data-tf-mode="ext" role="tab"><i class="fa-solid fa-truck-arrow-right"></i>' +
                    '<span><b>ภายนอก — ข้ามไซต์</b><small>โอนของไปไซต์อื่น (TD) · PM อนุมัติ</small></span></button>' +
                '<button type="button" class="tf-mode-btn" data-tf-mode="int" role="tab"><i class="fa-solid fa-right-left"></i>' +
                    '<span><b>ภายใน site — ย้าย Gate</b><small>ย้ายของจาก G หนึ่งไปอีก G (TG) · สายสโตร์</small></span></button>' +
            '</div>' +
            '<div id="tdPane" class="tf-pane">' +
            '<div class="td-intro"><i class="fa-solid fa-circle-info"></i><div>' +
                '<b>ใบเบิกโอนย้ายข้ามไซต์ (TD)</b> — เบิกของออกจากประตูของไซต์นี้เพื่อส่งไปอีกไซต์ · ' +
                '<b>PM ของไซต์นี้</b>เป็นผู้อนุมัติ · สแกน QR + แตะบัตร + ถ่ายรูปยืนยันเหมือนใบเบิก · ' +
                'ปิดประตูแล้วตัดสต๊อกที่ประตูต้นทาง — <b>ไซต์ปลายทางคีย์ใบรับเข้า (IN) เอง</b>เมื่อของไปถึง' +
            '</div></div>' +
            '<div class="grid-layout td-grid">' +
                '<div class="form-section">' +
                    '<h3 style="margin-bottom:1rem;">แบบฟอร์มโอนย้าย</h3>' +
                    '<div class="form-group"><label>วัสดุที่จะโอน <span class="td-req">*</span></label>' +
                        '<select id="tdMatSelect" class="form-control"><option value="">กำลังโหลด...</option></select>' +
                        '<div id="tdMatHint" class="balance-hint">เลือกวัสดุเพื่อดูยอดพร้อมโอนแยกตามประตู</div></div>' +
                    '<div class="form-group"><label>ประตูที่เบิกของออก <span class="td-req">*</span></label>' +
                        '<select id="tdGateSelect" class="form-control"><option value="" disabled selected>-- เลือกวัสดุก่อน --</option></select>' +
                        '<div id="tdGateHint" class="balance-hint">วัสดุชนิดเดียวกันอยู่ได้หลายประตู — เลือกประตูที่จะเบิกของออก</div></div>' +
                    '<div class="form-group"><label>จำนวน <span class="td-req">*</span></label>' +
                        '<input type="text" id="tdQtyInput" class="form-control qty-trigger" placeholder="แตะเพื่อระบุจำนวน" readonly></div>' +
                    '<button type="button" class="btn btn-secondary td-add-btn" id="tdAddBtn"><i class="fa-solid fa-plus"></i> เพิ่มลงรายการโอน</button>' +
                '</div>' +
                '<div class="form-section">' +
                    '<h3 style="margin-bottom:1rem;">รายการที่จะโอน <span class="td-count" id="tdCartCount">0</span></h3>' +
                    '<div id="tdCart" class="td-cart"></div>' +
                    '<div class="form-group" style="margin-top:0.9rem;"><label>ไซต์ปลายทาง <span class="td-req">*</span></label>' +
                        '<select id="tdDestSelect" class="form-control"><option value="">กำลังโหลด...</option></select></div>' +
                    '<div class="form-group"><label>ผู้รับของที่ไซต์ปลายทาง <span class="td-req">*</span></label>' +
                        '<input type="text" id="tdContactInput" class="form-control" maxlength="150" placeholder="ชื่อ / เบอร์โทร ผู้รับที่ปลายทาง"></div>' +
                    '<div class="form-group"><label>หมายเหตุ</label>' +
                        '<textarea id="tdNoteInput" class="form-control" rows="2" maxlength="1000" placeholder="เช่น เหตุผลที่โอน / รถที่ขน"></textarea></div>' +
                    '<div class="form-group" id="tdApproverGroup"><label>PM ผู้อนุมัติ (ไซต์นี้) <span class="td-req">*</span></label>' +
                        '<select id="tdApproverSelect" class="form-control"></select>' +
                        '<div id="tdApproverHint" class="balance-hint"></div></div>' +
                    '<button type="button" class="btn btn-primary td-submit-btn" id="tdSubmitBtn"><i class="fa-solid fa-paper-plane"></i> ส่งใบโอนย้าย</button>' +
                '</div>' +
            '</div>' +
            '<div class="td-lists">' +
                '<div class="td-list-head"><h3><i class="fa-solid fa-truck-arrow-right"></i> ใบโอนของไซต์นี้ <span class="td-sub">(120 วันล่าสุด)</span></h3>' +
                    '<button type="button" class="page-refresh-btn td-refresh" id="tdListRefresh" title="โหลดใหม่"><i class="fa-solid fa-arrows-rotate"></i></button></div>' +
                '<div id="tdOutList" class="td-doc-list"><div class="qr-empty"><i class="fa-solid fa-spinner fa-spin"></i><span>กำลังโหลด...</span></div></div>' +
                '<div class="td-list-head"><h3><i class="fa-solid fa-warehouse"></i> ของที่ไซต์อื่นโอนมา <span class="td-sub">(อ้างอิง — คีย์ใบรับเข้า IN เองเมื่อของมาถึง)</span></h3></div>' +
                '<div id="tdInList" class="td-doc-list"></div>' +
            '</div>' +
            '</div>' +
            '<div id="tgPane" class="tf-pane" hidden></div>';
        cont.appendChild(pane);
        Array.prototype.forEach.call(pane.querySelectorAll('.tf-mode-btn'), function (b) {
            b.addEventListener('click', function () { setMode(b.getAttribute('data-tf-mode')); });
        });
        showMode(getMode());

        $('#tdMatSelect').addEventListener('change', onMatChange);
        $('#tdGateSelect').addEventListener('change', function () { renderGateHint(); resetQty(); });
        $('#tdQtyInput').addEventListener('click', openQty);
        $('#tdAddBtn').addEventListener('click', addToCart);
        $('#tdSubmitBtn').addEventListener('click', submit);
        $('#tdListRefresh').addEventListener('click', function () { loadLists(true); });
        renderCart();
    }

    // ------------------------------------------------------------------ โหมด ภายนอก (TD) / ภายใน (TG) — 2026-10-02
    var MODE_KEY = 'cnx.transfer.mode';
    function getMode() {
        try { return window.localStorage.getItem(MODE_KEY) === 'int' ? 'int' : 'ext'; } catch (e) { return 'ext'; }
    }
    function showMode(m) {
        var pane = document.getElementById('req-transfer');
        if (!pane) return;
        Array.prototype.forEach.call(pane.querySelectorAll('.tf-mode-btn'), function (b) {
            var on = b.getAttribute('data-tf-mode') === m;
            b.classList.toggle('active', on);
            b.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        var td = document.getElementById('tdPane');
        var tg = document.getElementById('tgPane');
        if (td) td.hidden = m !== 'ext';
        if (tg) tg.hidden = m !== 'int';
    }
    /** โหลดข้อมูลของโหมดที่แสดงอยู่ (ตอนเปิดแท็บ/สลับโหมด) */
    function activate(force) {
        if (getMode() === 'int') {
            if (window.cnxGateMove && typeof window.cnxGateMove.activate === 'function') window.cnxGateMove.activate(!!force);
        } else {
            load(!!force);
        }
    }
    function setMode(m) {
        m = m === 'int' ? 'int' : 'ext';
        try { window.localStorage.setItem(MODE_KEY, m); } catch (e) {}
        if (typeof window.hapticTap === 'function') window.hapticTap();
        showMode(m);
        activate(false);
    }

    // ------------------------------------------------------------------ โหลดข้อมูล
    function load(force) {
        if (T.loading) return;
        if (T.loadedOnce && !force) { loadLists(false); return; }
        T.loading = true;
        rpc('getTransferFormData', [], function (res) {
            T.loading = false;
            if (!res || !res.success) {
                $('#tdDestSelect').innerHTML = '<option value="">โหลดไม่สำเร็จ</option>';
                info('โหลดฟอร์มโอนย้ายไม่สำเร็จ', esc((res && res.message) || '-'), 'danger');
                return;
            }
            T.form = res;
            T.loadedOnce = true;
            renderForm();
            loadLists(false);
        }, function (msg) {
            T.loading = false;
            toast('โหลดฟอร์มโอนย้ายไม่สำเร็จ: ' + msg, 'danger');
        });
    }

    function renderForm() {
        var f = T.form || {};
        var dest = $('#tdDestSelect');
        var cur = dest.value;
        dest.innerHTML = '<option value="">-- เลือกไซต์ปลายทาง --</option>' + (f.sites || []).map(function (s) {
            return '<option value="' + esc(s.code) + '">' + esc(s.code) + ' — ' + esc(s.name) + '</option>';
        }).join('');
        if (cur) dest.value = cur;

        indexStock();
        renderMatOptions();

        var apg = $('#tdApproverGroup');
        var aps = $('#tdApproverSelect');
        var hint = $('#tdApproverHint');
        var pms = f.pms || [];
        if (f.isPm) {
            apg.style.display = '';
            aps.innerHTML = '<option value="">คุณเป็น PM ของไซต์นี้</option>';
            aps.disabled = true;
            hint.textContent = 'ส่งแล้วอนุมัติทันที — ไปสแกน QR ที่ประตูได้เลย';
        } else if (!pms.length) {
            aps.innerHTML = '<option value="">ไซต์นี้ยังไม่มี PM ในระบบ</option>';
            aps.disabled = true;
            hint.innerHTML = '<span class="td-warn">ส่งใบโอนไม่ได้จนกว่าจะมี PM ของไซต์ในระบบ — ติดต่อผู้ดูแลระบบ</span>';
        } else {
            aps.disabled = pms.length === 1;
            aps.innerHTML = (pms.length > 1 ? '<option value="">-- เลือก PM --</option>' : '') + pms.map(function (p) {
                return '<option value="' + esc(p.username) + '">' + esc(p.fullName) + ' (' + esc(p.username) + ')</option>';
            }).join('');
            hint.textContent = 'ใบโอนย้ายต้องให้ผู้จัดการโครงการ (PM) ของไซต์ต้นทางอนุมัติเท่านั้น';
        }
        var canCreate = f.canCreate !== false;
        $('#tdSubmitBtn').disabled = !canCreate;
        $('#tdAddBtn').disabled = !canCreate;
        if (!canCreate) hint.innerHTML = '<span class="td-warn">บัญชีนี้ส่งใบโอนย้ายไม่ได้ (เฉพาะบัญชีพนักงานที่ผูกไซต์)</span>';
    }

    // ---- ยอดรายประตูจาก server → ดัชนีต่อวัสดุ (ลำดับตามชื่อ) ----
    function indexStock() {
        var mats = {};
        var order = [];
        ((T.form && T.form.stock) || []).forEach(function (s) {
            if (!mats[s.matCode]) {
                mats[s.matCode] = { matCode: s.matCode, name: s.name, unit: s.unit || '', char: s.char || '', rows: [] };
                order.push(s.matCode);
            }
            mats[s.matCode].rows.push(s);
        });
        order.forEach(function (k) { mats[k].rows.sort(function (a, b) { return a.gate.localeCompare(b.gate); }); });
        order.sort(function (a, b) { return mats[a].name.localeCompare(mats[b].name, 'th') || a.localeCompare(b); });
        T.mats = mats;
        T.matOrder = order;
    }
    function stockRow(mat, gate) {
        var m = T.mats[mat];
        if (!m) return null;
        for (var i = 0; i < m.rows.length; i++) { if (m.rows[i].gate === gate) return m.rows[i]; }
        return null;
    }
    function matAvail(mat) {   // พร้อมโอนรวมทุกประตู (เฉพาะประตูที่ยอดเป็นบวก)
        var m = T.mats[mat];
        var t = 0;
        (m ? m.rows : []).forEach(function (r) { if (r.avail > 0) t += r.avail; });
        return t;
    }
    function inCart(gate, mat) {
        var q = 0;
        T.cart.forEach(function (c) { if ((gate === null || c.gate === gate) && c.matCode === mat) q += c.qty; });
        return q;
    }
    function gateName(code) {
        var n = '';
        ((T.form && T.form.gates) || []).forEach(function (g) { if (g.code === code) n = g.name || ''; });
        return n && n !== code ? n : '';
    }
    function selMat() { return $('#tdMatSelect').value || ''; }
    function selGate() { return $('#tdGateSelect').value || ''; }
    function hasSelect2(el) { return !!(window.jQuery && window.jQuery.fn && window.jQuery.fn.select2 && window.jQuery(el).data('select2')); }

    // ---- วัสดุ: เฉพาะที่พร้อมโอน (ยอดพร้อมเบิก > 0 ที่ประตูใดประตูหนึ่ง) · ทุกชนิดรวม WMS ----
    function renderMatOptions() {
        var sel = $('#tdMatSelect');
        var keep = selMat();
        var list = T.matOrder.filter(function (k) { return matAvail(k) > 0; });
        if (hasSelect2(sel)) { try { window.jQuery(sel).select2('destroy'); } catch (e) {} }
        sel.innerHTML = list.length
            ? '<option value=""></option>' + list.map(function (k) {
                var m = T.mats[k];
                return '<option value="' + esc(k) + '" data-unit="' + esc(m.unit) + '" data-char="' + esc(m.char) + '">' + esc(m.name) + '</option>';
            }).join('')
            : '<option value="">ไม่มีวัสดุที่พร้อมโอนในไซต์นี้</option>';
        if (keep && T.mats[keep] && matAvail(keep) > 0) sel.value = keep;
        if (window.jQuery && window.jQuery.fn && window.jQuery.fn.select2 && list.length) {
            try {
                var $s = window.jQuery(sel);
                $s.select2({
                    width: '100%',
                    placeholder: '-- เลือกวัสดุ (' + list.length + ' รายการพร้อมโอน) --',
                    allowClear: true,
                    language: { noResults: function () { return 'ไม่พบวัสดุ'; } },
                    matcher: typeof window.matCodeMatcher === 'function' ? window.matCodeMatcher : undefined,
                    templateResult: matResult
                });
                // select2 ยิง change ของ jQuery — ส่งต่อให้ตัวจัดการเดียวกับ change ปกติ
                $s.off('change.td').on('change.td', function (e) { if (!e.originalEvent) onMatChange(); });
            } catch (e) {}
        }
        renderGateOptions(selGate());
        renderMatHint();
    }
    function matResult(d) {
        if (!d.id || !window.jQuery) return d.text;
        var m = T.mats[d.id];
        if (!m) return d.text;
        var gates = m.rows.filter(function (r) { return r.avail > 0; }).map(function (r) { return r.gate; }).join(' · ');
        return window.jQuery('<div class="td-opt"><div class="td-opt-name">' + esc(m.name) + (m.char ? ' <span class="td-opt-char">' + esc(m.char) + '</span>' : '') + '</div>' +
            '<div class="td-opt-sub">' + esc(m.matCode) + ' — พร้อมโอน ' + num(matAvail(m.matCode)) + (m.unit ? ' ' + esc(m.unit) : '') + (gates ? ' · ' + esc(gates) : '') + '</div></div>');
    }

    function onMatChange() {
        renderGateOptions('');
        renderMatHint();
        resetQty();
    }

    // ---- ประตู: ทุกประตูที่มีวัสดุนี้ (หมด = เทา) · มีของประตูเดียว = เลือกให้เอง — ล้อ refreshGateSelectForForm ของแท็บเบิก ----
    function renderGateOptions(keepGate) {
        var sel = $('#tdGateSelect');
        var mat = selMat();
        var m = T.mats[mat];
        if (!mat || !m) {
            sel.innerHTML = '<option value="" disabled selected>-- เลือกวัสดุก่อน --</option>';
            renderGateHint();
            return;
        }
        var u = m.unit ? ' ' + m.unit : '';
        var withStock = m.rows.filter(function (r) { return r.avail > 0; });
        sel.innerHTML = '<option value="" disabled' + (withStock.length === 1 ? '' : ' selected') + '>-- เลือกประตู --</option>' +
            m.rows.map(function (r) {
                var ok = r.avail > 0;
                var gn = gateName(r.gate);
                return '<option value="' + esc(r.gate) + '"' + (ok ? '' : ' disabled') + '>' + esc(r.gate) + (gn ? ' · ' + esc(gn) : '') +
                       ' — คงเหลือ ' + esc(num(Math.max(0, r.avail))) + esc(u) + (ok ? '' : ' (หมด)') + '</option>';
            }).join('');
        var want = '';
        if (keepGate && stockRow(mat, keepGate) && stockRow(mat, keepGate).avail > 0) want = keepGate;
        else if (withStock.length === 1) want = withStock[0].gate;
        if (want) sel.value = want;
        renderGateHint();
    }

    function renderGateHint() {
        var hint = $('#tdGateHint');
        var mat = selMat();
        var gate = selGate();
        var m = T.mats[mat];
        if (!m) { hint.innerHTML = 'วัสดุชนิดเดียวกันอยู่ได้หลายประตู — เลือกประตูที่จะเบิกของออก'; return; }
        if (!gate) { hint.innerHTML = 'วัสดุนี้มีของอยู่ ' + m.rows.length + ' ประตู — เลือกประตูที่จะเบิกของออก'; return; }
        var r = stockRow(mat, gate);
        if (!r) { hint.innerHTML = ''; return; }
        var u = m.unit ? ' ' + esc(m.unit) : '';
        var used = inCart(gate, mat);
        hint.innerHTML = '<strong>คงเหลือที่ประตูนี้:</strong> ' + num(Math.max(0, r.avail)) + u +
            (r.pending > 0 ? ' <span style="color:var(--text-muted);">(ในคลัง ' + num(r.onHand) + ' · จองไว้ ' + num(r.pending) + ')</span>' : '') +
            (used > 0 ? '<br><strong>อยู่ในรายการโอนแล้ว:</strong> ' + num(used) + u + ' — เพิ่มได้อีก ' + num(Math.max(0, r.avail - used)) + u : '') +
            (gateName(gate) ? '<br><strong>ประตู:</strong> ' + esc(gate + ' · ' + gateName(gate)) : '');
    }

    function renderMatHint() {
        var hint = $('#tdMatHint');
        var mat = selMat();
        var m = T.mats[mat];
        if (!m) { hint.innerHTML = 'เลือกวัสดุเพื่อดูยอดพร้อมโอนแยกตามประตู'; return; }
        var u = m.unit ? ' ' + esc(m.unit) : '';
        var parts = m.rows.map(function (r) {
            return '<span class="cw-gate-tag">' + esc(r.gate) + '</span> ' + num(Math.max(0, r.avail)) + u +
                   (r.pending > 0 ? ' <span style="color:var(--text-muted);">(จองไว้ ' + num(r.pending) + ')</span>' : '');
        });
        hint.innerHTML = '<strong>พร้อมโอนรวม:</strong> ' + num(matAvail(mat)) + u +
            (m.char === 'WMS' ? ' <span class="td-wms">WMS — โอนได้เฉพาะทางใบโอนย้าย</span>' : '') +
            '<br><strong>วัสดุ:</strong> ' + esc(m.name) + ' <span style="color:var(--text-muted);">' + esc(m.matCode) + '</span>' +
            '<br><strong>แยกตามประตู:</strong> ' + parts.join(' · ');
    }

    // ---- จำนวน: หน้าต่าง +/− ตัวเดียวกับแท็บเบิก (openQtyModal · formKey 'td') ----
    function resetQty() {
        var el = $('#tdQtyInput');
        if (!el) return;
        el.value = '';
        el.dataset.qty = '';
        el.dataset.hasValue = '';
        el.removeAttribute('data-has-value');
    }
    function openQty() {
        var mat = selMat();
        var gate = selGate();
        var m = T.mats[mat];
        if (!m) { info('ยังไม่ได้เลือกวัสดุ', 'กรุณาเลือกวัสดุก่อนระบุจำนวน', 'warning'); return; }
        if (!gate) { info('ยังไม่ได้เลือกประตู', 'เลือกประตูที่จะเบิกของออกก่อนระบุจำนวน', 'warning'); return; }
        var r = stockRow(mat, gate);
        var left = (r ? r.avail : 0) - inCart(gate, mat);
        if (left <= 0) {
            info('ประตูนี้ไม่มีของเหลือให้โอน', esc(m.name) + ' ที่ ' + esc(gate) + ' พร้อมโอน ' + num(Math.max(0, r ? r.avail : 0)) +
                 (inCart(gate, mat) > 0 ? ' — อยู่ในรายการโอนครบแล้ว' : '') + (matAvail(mat) > 0 ? '<br>ลองเลือกประตูอื่น' : ''), 'warning');
            return;
        }
        if (typeof window.openQtyModal !== 'function') {
            var v = parseInt(window.prompt('จำนวน (สูงสุด ' + left + ')', '1') || '', 10);
            if (v > 0) { var el = $('#tdQtyInput'); el.value = v + (m.unit ? ' ' + m.unit : ''); el.dataset.qty = v; }
            return;
        }
        seedGateBalance(mat, gate, r);
        window.openQtyModal('tdQtyInput', 'tdMatSelect', FORM_KEY);
    }
    /** หน้าต่างจำนวนอ่านยอดรายประตูจาก globalGateBalance (โหลดพร้อมหน้าเบิก) — ยังไม่มีแถวนี้ (โหลดไม่ทัน) ให้ใช้ยอดของฟอร์ม TD ไปก่อน
     *  (หน้าต่างดึงยอดล่าสุดจาก server ซ้ำเองทุกครั้งที่เปิด) */
    function seedGateBalance(mat, gate, r) {
        try {
            var gb = window.globalGateBalance;
            if (!gb || typeof gb !== 'object' || !r) return;
            var rows = gb[mat] || (gb[mat] = []);
            for (var i = 0; i < rows.length; i++) { if ((rows[i].GateID || '') === gate) return; }
            rows.push({ MatCode: mat, GateID: gate, GateName: gateName(gate), OnHand: r.onHand, Pending: r.pending });
        } catch (e) {}
    }
    /** ยอด "อยู่ในรายการแล้ว" ของหน้าต่างจำนวน — ของ TD ต้องนับจากรายการโอน (ตัวเดิมอ่านตะกร้าใบเบิกเมื่อไม่รู้จัก formKey) */
    function patchDraftCounters() {
        var o1 = window.getDraftedQtyAtGate;
        if (typeof o1 === 'function' && !o1.__tdWrapped) {
            var w1 = function (matCode, formKey, gateId) {
                if (formKey === FORM_KEY) return inCart(gateId || '', matCode);
                return o1.apply(this, arguments);
            };
            w1.__tdWrapped = true;
            window.getDraftedQtyAtGate = w1;
        }
        var o2 = window.getDraftedQty;
        if (typeof o2 === 'function' && !o2.__tdWrapped) {
            var w2 = function (matCode, formKey) {
                if (formKey === FORM_KEY) return inCart(null, matCode);
                return o2.apply(this, arguments);
            };
            w2.__tdWrapped = true;
            window.getDraftedQty = w2;
        }
    }

    function addToCart() {
        var mat = selMat();
        var gate = selGate();
        var qtyEl = $('#tdQtyInput');
        var qty = parseInt(qtyEl.dataset.qty || '', 10);
        var m = T.mats[mat];
        if (!m) { toast('เลือกวัสดุ', 'warning'); return; }
        if (!gate) { toast('เลือกประตูที่เบิกของออก', 'warning'); return; }
        if (!(qty > 0)) { toast('แตะช่องจำนวนเพื่อระบุจำนวน', 'warning'); return; }
        var r = stockRow(mat, gate);
        if (!r) { toast('ไม่พบยอดของวัสดุนี้ที่ประตู ' + gate, 'danger'); return; }
        var used = inCart(gate, mat);
        if (qty + used > r.avail + 0.0005) {
            info('จำนวนเกินยอดพร้อมโอน', esc(m.name) + '<br>พร้อมโอนที่ ' + esc(gate) + ' ' + num(Math.max(0, r.avail)) + (used > 0 ? ' (อยู่ในรายการแล้ว ' + num(used) + ')' : ''), 'warning');
            return;
        }
        var found = null;
        T.cart.forEach(function (c) { if (c.gate === gate && c.matCode === mat) found = c; });
        if (found) {
            found.qty = found.qty + qty;
            toast('รวมจำนวนกับรายการเดิมแล้ว', 'info');
        } else {
            T.cart.push({ gate: gate, matCode: mat, name: m.name, unit: m.unit, qty: qty });
        }
        if (typeof window.hapticTap === 'function') window.hapticTap();
        resetQty();
        renderCart();
        renderMatHint();
        renderGateOptions(gate);   // คงวัสดุ/ประตูเดิมไว้ (เหมือนแท็บเบิก) · อัปเดต "อยู่ในรายการแล้ว"
    }

    function renderCart() {
        var box = $('#tdCart');
        if (!box) return;
        $('#tdCartCount').textContent = T.cart.length;
        if (!T.cart.length) {
            box.innerHTML = '<div class="td-empty"><i class="fa-solid fa-cart-flatbed"></i> ยังไม่มีรายการ — เลือกวัสดุ ประตู จำนวน แล้วกด "เพิ่มลงรายการโอน"</div>';
            return;
        }
        var gates = {};
        T.cart.forEach(function (c) { gates[c.gate] = true; });
        var nGate = Object.keys(gates).length;
        box.innerHTML = (nGate > 1 ? '<div class="td-split-note"><i class="fa-solid fa-code-branch"></i> ของจาก ' + nGate + ' ประตู — ระบบแยกเป็น ' + nGate + ' ใบ (ใบละประตู) เลขรันเดียวกัน</div>' : '') +
            T.cart.map(function (c, i) {
                return '<div class="td-line">' +
                    '<span class="td-gate">' + esc(c.gate) + '</span>' +
                    '<div class="td-line-main"><div class="td-line-name">' + esc(c.name) + '</div><div class="td-line-code">' + esc(c.matCode) + '</div></div>' +
                    '<div class="td-line-qty">' + num(c.qty) + (c.unit ? ' <small>' + esc(c.unit) + '</small>' : '') + '</div>' +
                    '<button type="button" class="td-line-del" data-i="' + i + '" title="ลบ"><i class="fa-solid fa-xmark"></i></button>' +
                '</div>';
            }).join('');
        Array.prototype.forEach.call(box.querySelectorAll('.td-line-del'), function (b) {
            b.addEventListener('click', function () {
                T.cart.splice(Number(b.getAttribute('data-i')), 1);
                renderCart();
                renderMatHint();
                renderGateHint();
            });
        });
    }

    function submit() {
        if (T.submitting) return;
        var f = T.form || {};
        var dest = $('#tdDestSelect').value;
        var contact = $('#tdContactInput').value.trim();
        var note = $('#tdNoteInput').value.trim();
        var approver = $('#tdApproverSelect').value;
        if (!dest) { toast('เลือกไซต์ปลายทาง', 'warning'); $('#tdDestSelect').focus(); return; }
        if (!contact) { toast('ระบุผู้รับของที่ไซต์ปลายทาง', 'warning'); $('#tdContactInput').focus(); return; }
        if (!T.cart.length) { toast('ยังไม่มีรายการที่จะโอน', 'warning'); return; }
        if (!f.isPm && (f.pms || []).length > 1 && !approver) { toast('เลือก PM ผู้อนุมัติ', 'warning'); return; }
        var destName = '';
        (f.sites || []).forEach(function (s) { if (s.code === dest) destName = s.name; });
        var lines = T.cart.map(function (c) { return '• ' + esc(c.gate) + ' · ' + esc(c.name) + ' <b>' + num(c.qty) + (c.unit ? ' ' + esc(c.unit) : '') + '</b>'; }).join('<br>');
        var msg = 'โอนจาก <b>' + esc((f.site && f.site.code) || '') + '</b> ไป <b>' + esc(dest) + '</b>' + (destName ? ' (' + esc(destName) + ')' : '') +
                  '<br>ผู้รับปลายทาง: ' + esc(contact) + '<br><br>' + lines + '<br><br>' +
                  (f.isPm ? 'คุณเป็น PM ของไซต์นี้ — ใบจะอนุมัติทันที' : 'ใบจะรอ PM ของไซต์นี้อนุมัติ') +
                  '<br><span style="color:var(--text-muted);font-size:0.85rem;">ของจะถูกตัดสต๊อกที่ประตูต้นทางเมื่อปิดประตู · ไซต์ปลายทางคีย์ใบรับเข้า (IN) เอง</span>';
        var go = function () {
            T.submitting = true;
            var btn = $('#tdSubmitBtn');
            btn.disabled = true;
            if (typeof window.showLoadingPopup === 'function') window.showLoadingPopup('กำลังส่งใบโอนย้าย', 'กรุณารอสักครู่...');
            rpc('processTransferSubmission', [{
                destSite: dest, contact: contact, note: note, approver: approver,
                items: T.cart.map(function (c) { return { MatCode: c.matCode, Qty: c.qty, GateID: c.gate }; })
            }], function (res) {
                T.submitting = false;
                btn.disabled = false;
                if (typeof window.closeAppPopup === 'function') window.closeAppPopup();
                if (!res || !res.success) {
                    info('ส่งใบโอนย้ายไม่สำเร็จ', esc((res && res.message) || '-').replace(/\n/g, '<br>'), 'danger');
                    return;
                }
                T.cart = [];
                $('#tdNoteInput').value = '';
                renderCart();
                invalidate();
                if (typeof window.hapticSuccess === 'function') window.hapticSuccess();
                info(res.approved ? 'ออกใบโอนย้ายแล้ว' : 'ส่งใบโอนย้ายแล้ว', esc(res.message || '') , 'success');
                load(true);
            }, function (m) {
                T.submitting = false;
                btn.disabled = false;
                if (typeof window.closeAppPopup === 'function') window.closeAppPopup();
                info('ส่งใบโอนย้ายไม่สำเร็จ', esc(m), 'danger');
            });
        };
        if (typeof window.showConfirmPopup === 'function') window.showConfirmPopup('ยืนยันส่งใบโอนย้ายข้ามไซต์', msg, go, 'ส่งใบโอน');
        else if (confirm('ยืนยันส่งใบโอนย้าย?')) go();
    }

    // ------------------------------------------------------------------ รายการใบโอน
    function loadLists(force) {
        var out = $('#tdOutList');
        if (!out) return;
        if (force) out.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-spinner fa-spin"></i><span>กำลังโหลด...</span></div>';
        rpc('getTransferList', [], function (res) {
            if (!res || !res.success) {
                out.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-triangle-exclamation"></i><span>' + esc((res && res.message) || 'โหลดไม่สำเร็จ') + '</span></div>';
                return;
            }
            T.lists = res;
            renderLists();
        }, function (m) {
            out.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-triangle-exclamation"></i><span>' + esc(m) + '</span></div>';
        });
    }

    function stateClass(d) {
        var s = String(d.status || '').toLowerCase();
        if (s.indexOf('cancel') !== -1 || s.indexOf('reject') !== -1) return 'dead';
        if (d.done) return 'done';
        if (s.indexOf('awaiting') !== -1) return 'wait';
        return 'live';
    }

    function docCard(d, incoming) {
        var items = (d.items || []).map(function (it) {
            var act = it.qtyActual != null && Math.abs(it.qtyActual - it.qty) > 0.0005
                ? ' <span class="td-act">หยิบจริง ' + num(it.qtyActual) + '</span>' : '';
            return '<div class="td-it"><span class="td-it-name">' + esc(it.name || it.matCode) + ' <small>' + esc(it.matCode) + '</small></span>' +
                   '<span class="td-it-qty">' + num(it.qtyActual != null ? it.qtyActual : it.qty) + (it.unit ? ' ' + esc(it.unit) : '') + act + '</span></div>';
        }).join('');
        var route = incoming
            ? '<b>' + esc(d.srcSite) + '</b> <i class="fa-solid fa-arrow-right"></i> ไซต์นี้'
            : 'ไซต์นี้ <i class="fa-solid fa-arrow-right"></i> <b>' + esc(d.destSite) + '</b>' + (d.destName ? ' <span class="td-sub">' + esc(d.destName) + '</span>' : '');
        var actions = '';
        if (!incoming && d.canCancel) {
            actions += '<button type="button" class="btn btn-danger td-cancel" data-doc="' + esc(d.docId) + '"><i class="fa-solid fa-ban"></i> ยกเลิก</button>';
        }
        actions += '<button type="button" class="btn btn-secondary td-pdf" data-doc="' + esc(d.docId) + '"><i class="fa-solid fa-file-pdf"></i> PDF</button>';
        var inHint = incoming && d.done
            ? '<div class="td-in-hint"><i class="fa-solid fa-truck-ramp-box"></i> ต้นทางตัดสต๊อกแล้ว — เมื่อของมาถึงให้คีย์ใบรับเข้า (IN) ที่แท็บ "รับเข้าคลัง"</div>' : '';
        return '<div class="td-doc ' + stateClass(d) + '">' +
            '<div class="td-doc-head"><div><div class="td-doc-id">' + esc(d.docId) + '</div><div class="td-doc-route">' + route + '</div></div>' +
                '<span class="td-state">' + esc(d.statusThai || d.status) + '</span></div>' +
            '<div class="td-doc-meta">' + esc(d.dateStr) + ' · ผู้ส่ง ' + esc(fullName(d.reqName)) +
                (d.approver ? ' · PM ' + esc(fullName(d.approver)) : '') + (d.contact ? ' · ผู้รับปลายทาง ' + esc(d.contact) : '') + '</div>' +
            '<div class="td-its">' + items + '</div>' +
            (d.note ? '<div class="td-doc-note">หมายเหตุ: ' + esc(d.note) + '</div>' : '') + inHint +
            '<div class="td-doc-actions">' + actions + '</div>' +
        '</div>';
    }

    function renderLists() {
        var L = T.lists || { outgoing: [], incoming: [] };
        var out = $('#tdOutList');
        var inn = $('#tdInList');
        out.innerHTML = (L.outgoing || []).length ? L.outgoing.map(function (d) { return docCard(d, false); }).join('')
            : '<div class="td-empty"><i class="fa-solid fa-inbox"></i> ยังไม่มีใบโอนของไซต์นี้</div>';
        inn.innerHTML = (L.incoming || []).length ? L.incoming.map(function (d) { return docCard(d, true); }).join('')
            : '<div class="td-empty"><i class="fa-solid fa-inbox"></i> ไม่มีของที่ไซต์อื่นโอนมา</div>';
        Array.prototype.forEach.call(document.querySelectorAll('#req-transfer .td-cancel'), function (b) {
            b.addEventListener('click', function () { cancelDoc(b.getAttribute('data-doc')); });
        });
        Array.prototype.forEach.call(document.querySelectorAll('#req-transfer .td-pdf'), function (b) {
            b.addEventListener('click', function () {
                if (typeof window.downloadDocReport === 'function') window.downloadDocReport(b.getAttribute('data-doc'), 'TD');
            });
        });
    }

    function cancelDoc(docId) {
        var go = function () {
            rpc('cancelRequisition', [docId, 'TD', me()], function (res) {
                if (!res || !res.success) { info('ยกเลิกไม่สำเร็จ', esc((res && res.message) || '-').replace(/\n/g, '<br>'), 'danger'); return; }
                toast(res.message || 'ยกเลิกแล้ว', 'success');
                invalidate();
                load(true);
            });
        };
        if (typeof window.showConfirmPopup === 'function') {
            window.showConfirmPopup('ยกเลิกใบโอนย้าย', 'ยกเลิกใบ <b>' + esc(docId) + '</b> ? ยอดที่จองไว้จะคืนกลับ', go, 'ยกเลิกใบ', 'btn btn-danger');
        } else if (confirm('ยกเลิกใบ ' + docId + ' ?')) go();
    }

    // ------------------------------------------------------------------ ป้ายชนิด / QR / ปุ่มกรอง
    function patchTypeBadge() {
        var orig = window.getTypeBadge;
        if (typeof orig !== 'function' || orig.__tdWrapped) return;
        var extra = {
            TD: { color: '#0e7490', label: 'โอนย้ายข้ามไซต์' },
            TG: { color: '#7c3aed', label: 'ย้าย Gate' },   // 2026-10-02
            SC: { color: '#475569', label: 'นับสต๊อก' }
        };
        var w = function (type) {
            var m = extra[type];
            if (!m) return orig.apply(this, arguments);
            return '<span style="background:' + m.color + ';color:#fff;padding:0.25rem 0.6rem;border-radius:4px;font-size:0.8rem;white-space:nowrap;display:inline-block;box-shadow:0 1px 2px rgba(0,0,0,0.05);">' + m.label + '</span>';
        };
        w.__tdWrapped = true;
        window.getTypeBadge = w;
    }

    function patchQrFilter() {
        var inBtn = document.querySelector('.qr-tab[onclick*="\'IN\'"]');
        if (!inBtn || document.querySelector('.qr-tab[onclick*="\'TD\'"]')) return;
        var b = document.createElement('button');
        b.className = 'qr-tab';
        b.setAttribute('onclick', "setQRFilter('TD', this)");
        b.innerHTML = '<i class="fa-solid fa-truck-arrow-right"></i> TD';
        inBtn.parentNode.insertBefore(b, inBtn.nextSibling);
        if (!document.querySelector('.qr-tab[onclick*="\'TG\'"]')) {   // 2026-10-02 ย้าย Gate
            var g = document.createElement('button');
            g.className = 'qr-tab';
            g.setAttribute('onclick', "setQRFilter('TG', this)");
            g.innerHTML = '<i class="fa-solid fa-right-left"></i> ย้าย G';
            b.parentNode.insertBefore(g, b.nextSibling);
        }
    }

    function init() {
        inject();
        patchDraftCounters();
        patchTypeBadge();
        patchQrFilter();
        wrapAfter('switchRequisitionTab', function (tabId) { if (tabId === 'req-transfer') activate(false); });
        wrapAfter('showQRModal', function (docId, docType) {
            var t = document.getElementById('qrModalTitle');
            if (!t) return;
            if (docType === 'TD') t.textContent = '🚚 โอนย้ายข้ามไซต์ — พร้อมนำจ่าย';
            if (docType === 'TG') {   // 2026-10-02 ย้าย Gate: ขานำเข้า = เลขใบ + G ปลายทาง
                var m = /^(TG\d+(G\d+))(G\d+)$/i.exec(String(docId || ''));
                var g = /(G\d+)$/i.exec(String(docId || ''));
                t.textContent = m ? '📥 ย้าย Gate — QR นำเข้า ' + m[3].toUpperCase() + ' (จาก ' + m[2].toUpperCase() + ')'
                                  : '📤 ย้าย Gate — QR เบิกออก ' + (g ? g[1].toUpperCase() : '');
            }
        });
        // แท็บถูกเลือกค้างไว้ก่อนสคริปต์นี้ทำงาน → โหลดเมื่อหน้าเบิกแสดง
        var pane = document.getElementById('req-transfer');
        if (pane && pane.classList.contains('active')) activate(false);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();

    window.cnxTransfer = { reload: function () { load(true); }, mode: getMode, setMode: setMode, activate: activate };
})();
