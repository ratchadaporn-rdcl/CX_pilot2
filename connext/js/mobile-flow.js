/*
 * CONNEXT — js/mobile-flow.js  [PHP port 2026-09-23 mobile-first]
 * คู่กับ css/mobile-flow.css — เติม element ที่หน้าจอมือถือต้องใช้ โดยไม่แก้ตรรกะเดิมของ index.php:
 *   1) แท็บเบิก 4 ชนิดมีชื่อสั้น (เบิกหลัก / เบ็ดเตล็ด / ยืม-คืน / รับเข้า)
 *   2) แถบลอย "รายการเตรียมเบิก N รายการ" → แตะแล้วเลื่อนไปรายการ + ปุ่มส่ง (ขึ้นเฉพาะตอนรายการอยู่นอกจอ)
 *   3) หน้า QR: ค้นหา/กรองผู้ขอเบิก ซ่อนหลังปุ่ม 🔍 (มีจุดส้มเมื่อกำลังกรองอยู่)
 *   4) หน้าต่าง QR: ปุ่มปิด ✕ · สถานะ "รอสแกนที่ประตู" · กันจอดับ (Wake Lock) ระหว่างเปิด QR · ล็อกไม่ให้หน้าหลังเลื่อน
 * ห่อฟังก์ชันเดิมแบบ "เรียกของเดิมก่อน แล้วค่อยเติม" — ถ้าส่วนเติมพัง ของเดิมยังทำงานครบ
 * PHP port: ไฟล์นี้ไม่มีใน GAS — ถ้า build index.php ใหม่ ให้เติม <script defer> ไฟล์นี้กลับก่อน </head>
 */
(function () {
    'use strict';

    var MQ = window.matchMedia ? window.matchMedia('(max-width: 768px)') : null;
    function isMobile() { return !!(MQ && MQ.matches); }
    function onMQChange(fn) {
        if (!MQ) return;
        if (MQ.addEventListener) MQ.addEventListener('change', fn); else if (MQ.addListener) MQ.addListener(fn);
    }
    function $(sel, root) { return (root || document).querySelector(sel); }
    function $all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

    /** ห่อฟังก์ชัน global: เรียกของเดิม แล้วค่อยรัน fn (ข้อผิดพลาดของ fn ไม่ลามไปของเดิม) */
    function after(name, fn) {
        var orig = window[name];
        if (typeof orig !== 'function' || orig.__mfWrapped) return;
        var wrapped = function () {
            var result = orig.apply(this, arguments);
            try { fn.apply(this, arguments); } catch (e) { console.warn('[mobile-flow] ' + name + ':', e); }
            return result;
        };
        wrapped.__mfWrapped = true;
        window[name] = wrapped;
    }

    // ---------------------------------------------------------------------
    // 1) แท็บเบิก — ชื่อสั้นสำหรับจอเล็ก (ข้อความเต็มยังอยู่ใน .mf-tab-long ให้จอใหญ่)
    // ---------------------------------------------------------------------
    var TAB_SHORT = { 'req-normal': 'เบิกหลัก', 'req-odds': 'เบ็ดเตล็ด', 'req-borrow': 'ยืม-คืน', 'req-inbound': 'รับเข้า' };
    function setupReqTabs() {
        $all('#requisition-page .tab-btn').forEach(function (btn) {
            if (btn.querySelector('.mf-tab-short')) return;
            var m = (btn.getAttribute('onclick') || '').match(/'(req-[a-z]+)'/);
            if (!m || !TAB_SHORT[m[1]]) return;
            var long = document.createElement('span');
            long.className = 'mf-tab-long';
            Array.prototype.slice.call(btn.childNodes).forEach(function (n) {
                if (n.nodeType === 3) {
                    if (n.textContent.trim()) long.appendChild(document.createTextNode(n.textContent.trim()));
                    btn.removeChild(n);
                }
            });
            var short = document.createElement('span');
            short.className = 'mf-tab-short';
            short.textContent = TAB_SHORT[m[1]];
            btn.appendChild(document.createTextNode(' '));
            btn.appendChild(long);
            btn.appendChild(short);
        });
    }

    // ---------------------------------------------------------------------
    // 2) แถบลอยรายการเตรียมเบิก
    // ---------------------------------------------------------------------
    var DRAFT_BODY = {
        'req-normal':  'draftTableBody',
        'req-odds':    'oddsDraftTableBody',
        'req-borrow':  'borrowDraftTableBody',
        'req-inbound': 'inboundDraftTableBody'
    };
    var SUBMIT_FN = ['submitBorrowDraft', 'submitInboundDraft'];   // สองแท็บนี้ปุ่มส่งยังไม่มีคลาส draft-actions
    var lastCount = {};
    var bar = null;

    function activeReqTab() {
        var el = $('#requisition-page .tab-content.active');
        return el ? el.id : '';
    }
    function draftCount(tabId) {
        var tb = document.getElementById(DRAFT_BODY[tabId] || '');
        return tb ? tb.querySelectorAll('tr:not(.draft-empty-row)').length : 0;
    }
    /** ส่วนรายการ + ปุ่มส่งของแท็บ (คอลัมน์ขวาของ grid-layout) */
    function draftSection(tabId) {
        var tb = document.getElementById(DRAFT_BODY[tabId] || '');
        if (!tb) return null;
        var p = tb.closest('.grid-layout > div');
        return p || tb.closest('table');
    }
    function stickyTop() {
        var bottom = 0;
        ['.navbar', '#mobileTabNav'].forEach(function (sel) {
            var el = $(sel);
            if (!el) return;
            var cs = window.getComputedStyle(el);
            if ((cs.position === 'sticky' || cs.position === 'fixed') && cs.display !== 'none') {
                bottom = Math.max(bottom, el.getBoundingClientRect().bottom);
            }
        });
        return bottom;
    }

    function buildBar() {
        if (bar) return bar;
        bar = document.createElement('div');
        bar.className = 'mf-draft-bar';
        bar.setAttribute('role', 'status');
        bar.innerHTML =
            '<div class="mf-db-text"><i class="fa-solid fa-clipboard-list" aria-hidden="true"></i>' +
                '<span>เตรียมไว้ <span class="mf-db-count">0</span> รายการ · ยังไม่ส่ง</span></div>' +
            '<button type="button" class="mf-db-go">ดู / ส่ง <i class="fa-solid fa-chevron-down" aria-hidden="true"></i></button>';
        bar.querySelector('.mf-db-go').addEventListener('click', function () {
            var sec = draftSection(activeReqTab());
            if (!sec) return;
            var y = sec.getBoundingClientRect().top + window.pageYOffset - stickyTop() - 8;
            try { window.scrollTo({ top: y, behavior: 'smooth' }); } catch (e) { window.scrollTo(0, y); }
            if (typeof hapticTap === 'function') { try { hapticTap(); } catch (e) {} }
            setTimeout(updateDraftBar, 450);
            setTimeout(updateDraftBar, 900);
        });
        document.body.appendChild(bar);
        return bar;
    }

    /** รายการ/ปุ่มส่งของแท็บนั้นโผล่ในจอแล้วหรือยัง (ใต้แถบเมนูที่ติดขอบบน · เหนือขอบล่าง 40px) */
    function draftInView(tabId) {
        var sec = draftSection(tabId);
        if (!sec) return false;
        var r = sec.getBoundingClientRect();
        var vh = window.innerHeight || document.documentElement.clientHeight;
        return r.height > 0 && r.bottom > stickyTop() && r.top < vh - 40;
    }

    function updateDraftBar() {
        var b = buildBar();
        var page = document.getElementById('requisition-page');
        var tab = activeReqTab();
        var n = tab ? draftCount(tab) : 0;
        var show = isMobile() && page && page.classList.contains('active-page') && n > 0 && !draftInView(tab);
        var cnt = b.querySelector('.mf-db-count');
        if (cnt.textContent !== String(n)) {
            cnt.textContent = String(n);
            if ((lastCount[tab] || 0) < n) {
                cnt.classList.remove('bump');
                void cnt.offsetWidth;            // เริ่มอนิเมชันใหม่
                cnt.classList.add('bump');
            }
        }
        lastCount[tab] = n;
        b.classList.toggle('show', !!show);
        document.body.classList.toggle('mf-has-draftbar', !!show);
    }

    function setupDraftBar() {
        // ปุ่มส่งของ "ยืม" / "รับเข้า" → คลาส draft-actions ให้ติดขอบล่างบนมือถือเหมือนอีกสองแท็บ
        SUBMIT_FN.forEach(function (fn) {
            var btn = $('#requisition-page button[onclick^="' + fn + '"]');
            if (btn && btn.parentElement && !btn.parentElement.classList.contains('draft-actions')) {
                btn.parentElement.classList.add('draft-actions');
            }
        });

        // เลื่อนจอ/หมุนจอ → เช็กใหม่ (หน่วง 80ms กันยิงถี่)
        var t = null;
        var later = function () { if (t) return; t = setTimeout(function () { t = null; updateDraftBar(); }, 80); };
        window.addEventListener('scroll', later, { passive: true });
        window.addEventListener('resize', later);

        // รายการเพิ่ม/ลด · สลับแท็บ · สลับหน้า → อัปเดตแถบ
        if ('MutationObserver' in window) {
            var mo = new MutationObserver(function () { updateDraftBar(); });
            Object.keys(DRAFT_BODY).forEach(function (tabId) {
                var tb = document.getElementById(DRAFT_BODY[tabId]);
                if (tb) mo.observe(tb, { childList: true });
            });
            $all('#requisition-page .tab-content').forEach(function (el) { mo.observe(el, { attributes: true, attributeFilter: ['class'] }); });
            var page = document.getElementById('requisition-page');
            if (page) mo.observe(page, { attributes: true, attributeFilter: ['class'] });
        }
        onMQChange(updateDraftBar);
        updateDraftBar();
    }

    // ---------------------------------------------------------------------
    // 3) หน้า QR — ปุ่ม 🔍 เปิด/ปิดช่องค้นหา + กรองผู้ขอเบิก
    // ---------------------------------------------------------------------
    function qrFilterActive() {
        var s = document.getElementById('qrSearchInput');
        var r = document.getElementById('qrReqSelect');
        return !!((s && s.value.trim()) || (r && r.value));
    }
    function refreshQrToggle() {
        var t = $('#qr-page .mf-qr-more');
        if (t) t.classList.toggle('has-filter', qrFilterActive());
    }
    function setupQrFilter() {
        var barEl = $('#qr-page .qr-filter-bar');
        var row = barEl && barEl.querySelector('.qr-tab-row');
        if (!row || row.querySelector('.mf-qr-more')) return;
        var t = document.createElement('button');
        t.type = 'button';
        t.className = 'qr-tab mf-qr-more';
        t.setAttribute('aria-label', 'ค้นหา / กรองผู้ขอเบิก');
        t.setAttribute('aria-expanded', 'false');
        t.innerHTML = '<i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>';
        t.addEventListener('click', function () {
            var open = !barEl.classList.contains('mf-open');
            barEl.classList.toggle('mf-open', open);
            t.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) { var s = document.getElementById('qrSearchInput'); if (s) s.focus(); }
        });
        row.insertBefore(t, row.firstChild);
        barEl.classList.add('mf-ready');
        if (qrFilterActive()) barEl.classList.add('mf-open');   // มีค่าค้างอยู่ — เปิดให้เห็น
        refreshQrToggle();
        after('renderQRCards', refreshQrToggle);
    }

    // ---------------------------------------------------------------------
    // 4) หน้าต่าง QR
    // ---------------------------------------------------------------------
    var wakeLock = null;
    function qrOpen() {
        var m = document.getElementById('qrModal');
        return !!(m && m.classList.contains('open'));
    }
    function lockScreenOn() {
        if (wakeLock || !window.isSecureContext || !navigator.wakeLock || !navigator.wakeLock.request) return;
        navigator.wakeLock.request('screen').then(function (l) {
            wakeLock = l;
            l.addEventListener('release', function () { if (wakeLock === l) wakeLock = null; });
        }).catch(function () { /* แบตต่ำ/ไม่อนุญาต — ไม่เป็นไร */ });
    }
    function lockScreenOff() {
        if (!wakeLock) return;
        try { wakeLock.release(); } catch (e) {}
        wakeLock = null;
    }
    function setupQrModal() {
        var box = $('#qrModal .qr-modal-box');
        if (!box || box.querySelector('.mf-qr-x')) return;

        var x = document.createElement('button');
        x.type = 'button';
        x.className = 'mf-qr-x';
        x.setAttribute('aria-label', 'ปิด');
        x.innerHTML = '<i class="fa-solid fa-xmark" aria-hidden="true"></i>';
        x.addEventListener('click', function () { if (typeof window.closeQRModal === 'function') window.closeQRModal(); });
        box.insertBefore(x, box.firstChild);

        var canvas = document.getElementById('qrCanvas');
        if (canvas) {
            var st = document.createElement('div');
            st.className = 'mf-qr-status';
            st.innerHTML = '<span class="mf-pulse" aria-hidden="true"></span><span>รอสแกนที่ประตู — ยื่นหน้าจอนี้ให้เครื่องสแกน</span>';
            canvas.parentNode.insertBefore(st, canvas.nextSibling);
            var hint = document.createElement('div');
            hint.className = 'mf-qr-hint';
            hint.textContent = 'เครื่องอ่านไม่ติด? เพิ่มความสว่างหน้าจอ แล้วถือให้นิ่ง ห่างประมาณหนึ่งฝ่ามือ · สแกนผ่านแล้วหน้านี้จะปิดเอง';
            st.parentNode.insertBefore(hint, st.nextSibling);
        }

        after('showQRModal', function () {
            if (!isMobile()) return;
            document.documentElement.classList.add('mf-noscroll');
            lockScreenOn();
        });
        after('closeQRModal', function () {
            document.documentElement.classList.remove('mf-noscroll');
            lockScreenOff();
        });
        // Wake Lock หลุดเองเมื่อสลับแอป — กลับมาแล้ว QR ยังเปิดอยู่ให้ขอใหม่
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible' && qrOpen() && isMobile()) lockScreenOn();
        });
    }

    // ---------------------------------------------------------------------
    // 5) ช่องค้นวัสดุ (Select2) หน้าเบิก — เปิดแล้วเลื่อนช่องขึ้นไปชิดใต้เมนู ให้รายการมีที่ลงก่อนคีย์บอร์ดเด้ง
    //    (Select2 วางรายการใต้ช่องเสมอ ช่องอยู่กลางจอ = รายการล้นขอบล่าง/โดนคีย์บอร์ดบัง)
    // ---------------------------------------------------------------------
    function setupSelect2Scroll() {
        var jq = window.jQuery;
        if (!jq) return;
        jq(document).on('select2:open', function (e) {
            if (!isMobile()) return;
            var sel = e.target;
            if (!sel || !sel.closest || !sel.closest('#requisition-page')) return;
            var box = sel.nextElementSibling && sel.nextElementSibling.classList.contains('select2') ? sel.nextElementSibling : sel;
            var delta = box.getBoundingClientRect().top - (stickyTop() + 8);
            if (delta > 4) window.scrollBy(0, delta);   // Select2 ขยับรายการตามเองเมื่อหน้าเลื่อน
        });
    }

    function init() {
        var steps = [setupReqTabs, setupDraftBar, setupQrFilter, setupQrModal, setupSelect2Scroll];
        steps.forEach(function (fn) {
            try { fn(); } catch (e) { console.warn('[mobile-flow] ' + (fn.name || 'init') + ':', e); }
        });
        window.MobileFlow = { update: updateDraftBar };   // ไว้เรียกเช็กแถบใหม่จาก console/ทดสอบ
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
