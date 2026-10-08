/*
 * CONNEXT — js/inventory-insights.js  [PHP port 2026-09-24 · มติ 49]
 * Dashboard คลัง (หน้า #dashboard-page):
 *   1) สลับมุมมอง "ภาพรวมสต๊อก | ตั้ง Min-Max"
 *   2) การ์ด "วัสดุยอดนิยม" — กราฟแท่งนอน (Chart.js) นับจำนวนใบ แยก เบิกหลัก/เบ็ดเตล็ด/ยืม · เลือกช่วง 30/90/180/ทั้งหมด
 *   3) การ์ด "Min-Max stock" — ต่ำกว่า Min / เกิน Max / ปกติ / ยังไม่ตั้ง + รายการที่ควรสั่งเติม
 *   4) มุมมอง "ตั้ง Min-Max" — กรอก Min/Max ทีละรหัส IC แบบเดียวกับ "ตั้งราคาหักเงิน" + ค่าแนะนำจากการเบิกจริง
 *   5) ต่อเข้ากับของเดิม: "ใกล้หมด" (isLowStockRow) · ป้ายสถานะในตาราง · หน้ารายละเอียดวัสดุ — ใช้ Min-Max เมื่อตั้งไว้
 * RPC ฝั่ง server: lib/inventory_insights.php · ห่อฟังก์ชันเดิมแบบ "เรียกของเดิมก่อนแล้วค่อยเติม" ไม่แก้ตรรกะใน index.php
 * ไม่มีใน GAS — ถ้า build index.php ใหม่ให้เติม <link>/<script defer> ไฟล์นี้กลับก่อน </head>
 */
(function () {
    'use strict';

    var TYPES = {
        RD: { label: 'เบิกหลัก', color: '#2563eb' },
        OD: { label: 'เบ็ดเตล็ด', color: '#f59e0b' },
        BD: { label: 'ยืม-คืน', color: '#14b8a6' }
    };
    var STATUS = {
        low:   { label: 'ต่ำกว่า Min', icon: 'fa-arrow-trend-down' },
        over:  { label: 'เกิน Max', icon: 'fa-arrow-trend-up' },
        ok:    { label: 'ปกติ', icon: 'fa-circle-check' },
        unset: { label: 'ยังไม่ตั้ง', icon: 'fa-circle-minus' }
    };
    var LS_VIEW = 'cnx.dash.view';
    var VIEWS = { overview: 'iiOverview', minmax: 'iiMinMax', dispatch: 'iiDispatch', errors: 'iiErrors' };
    // ประเภท Error log (key ตรงกับ lib/site_errors.php) — สี/ไอคอนฝั่งหน้าจอ
    var ECAT = {
        card:        { color: '#ef4444', icon: 'fa-id-card' },
        early_close: { color: '#f59e0b', icon: 'fa-door-closed' },
        not_locked:  { color: '#f97316', icon: 'fa-lock-open' },
        unknown_doc: { color: '#8b5cf6', icon: 'fa-file-circle-question' },
        network:     { color: '#0ea5e9', icon: 'fa-wifi' },
        selfcheck:   { color: '#14b8a6', icon: 'fa-video' },
        stock:       { color: '#be123c', icon: 'fa-arrow-down-short-wide' },
        // Scenario 05 (2026-09-28) — ประเภทใหม่จาก lib/site_errors.php
        over_cap:    { color: '#dc2626', icon: 'fa-hourglass-end' },
        pick_time:   { color: '#d97706', icon: 'fa-stopwatch' },
        close_late:  { color: '#ea580c', icon: 'fa-door-open' },
        intruder:    { color: '#7c3aed', icon: 'fa-person-walking' },
        wrong_gate:  { color: '#0891b2', icon: 'fa-shuffle' },
        skipped_doc: { color: '#6366f1', icon: 'fa-file-circle-xmark' },
        late_confirm:{ color: '#a16207', icon: 'fa-clock-rotate-left' },
        // Scenario 05 ③ (2026-09-29) — ใบยืมเกินกำหนดคืน (งานตรวจรายวัน lib/borrow.php)
        borrow_overdue: { color: '#e11d48', icon: 'fa-calendar-xmark' },
        // 2026-09-29 — Bypass ประตู (หน้า Bypass: เลือกเลขเอกสาร · เปิด/ปิดรอบแทนตู้)
        gate_bypass: { color: '#c2410c', icon: 'fa-person-walking-arrow-right' },
        // 2026-09-30 — ALARM 4 ค้างจนสายสโตร์ปิดสัญญาณที่ตู้ (บัตร + ปุ่มยืนยัน)
        alarm_kill:  { color: '#5b21b6', icon: 'fa-bell-slash' },
        // 2026-09-30 — หยิบจริง 0 ทุกรายการ = ยกเลิกใบทางอ้อมหลังแตะบัตร (ช่องโหว่ GP-05)
        zero_pick:   { color: '#b91c1c', icon: 'fa-ban' },
        other:     { color: '#64748b', icon: 'fa-circle-question' },
        test:        { color: '#94a3b8', icon: 'fa-flask' }
    };

    var S = {
        built: false,
        view: 'overview',
        pop: { days: 90, type: '', data: null, chart: null },
        mm: { site: '', data: null, dirty: {}, filter: '', params: null, loading: false },
        er: { site: '', days: 90, data: null, hideTest: true, cat: '', gate: '', limit: 200, chart: null, allowed: null },
        dr: { site: '', date: '', data: null, allowed: null },
        setMap: {},          // 'SITE|MAT' → {min,max}
        setSig: ''
    };

    // ------------------------------------------------------------------ ตัวช่วย
    function $(sel, root) { return (root || document).querySelector(sel); }
    function $all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
    function esc(v) {
        if (typeof window.escapeHtml === 'function') return window.escapeHtml(v == null ? '' : String(v));
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function num(v, dec) {
        var n = parseFloat(v);
        if (!isFinite(n)) return '—';
        return n.toLocaleString('en-US', { maximumFractionDigits: dec == null ? 2 : dec });
    }
    function me() { return window.user || null; }
    function toast(msg, type) { if (typeof window.showToast === 'function') window.showToast(msg, type || 'info'); }
    function call(fn, args, ok, fail) {
        var r = google.script.run.withSuccessHandler(ok).withFailureHandler(fail || function (e) { console.warn('[inventory-insights] ' + fn, e); });
        r[fn].apply(r, args || []);
    }
    /** ดึงข้อมูลผ่านแคชของแอป (โชว์ของเดิมก่อนแล้วดึงใหม่) — ไม่มีแคชก็เรียกตรง */
    function swr(cacheName, fn, args, onData, onError, opts) {
        if (window.connextCache && typeof window.connextCache.swr === 'function') {
            window.connextCache.swr(cacheName, args, function (done, fail) {
                var r = google.script.run.withSuccessHandler(done).withFailureHandler(fail);
                r[fn].apply(r, args);
            }, onData, onError, opts);
        } else {
            call(fn, args, onData, onError);
        }
    }
    function invalidate(names) {
        try { if (window.connextCache && window.connextCache.invalidateMany) window.connextCache.invalidateMany(names); } catch (e) {}
    }
    function dashSite() {
        try { if (typeof window.getDashboardSiteFilter === 'function') return window.getDashboardSiteFilter() || ''; } catch (e) {}
        return '';
    }
    function mmSite() {
        return S.mm.site || dashSite() || ((me() && me().siteCode) || '');
    }
    function lsGet(k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } }
    function lsSet(k, v) { try { window.localStorage.setItem(k, v); } catch (e) {} }
    function parseQty(s) {
        s = (s == null ? '' : String(s)).replace(/,/g, '').trim();
        if (s === '') return null;
        var n = Number(s);
        if (!isFinite(n) || n < 0) return false;
        return Math.round(n * 1000) / 1000;
    }
    function statusOf(it, min, max) {
        if (min == null && max == null) return 'unset';
        if (min != null && (min > 0 ? it.available <= min : it.available < 0)) return 'low';
        if (max != null && it.onHand > max) return 'over';
        return 'ok';
    }
    function orderQty(it, min, max) {
        var cyc = (S.mm.data && S.mm.data.params) ? S.mm.data.params.cycleDays : 14;
        var target = max != null ? max : (min + (it.adu || 0) * cyc);
        return Math.max(0, Math.ceil(target - it.available - 1e-9));
    }
    function el(tag, cls, html) { var e = document.createElement(tag); if (cls) e.className = cls; if (html != null) e.innerHTML = html; return e; }

    /** ห่อฟังก์ชัน global: เรียกของเดิมก่อน แล้วค่อยรัน fn */
    function after(name, fn) {
        var orig = window[name];
        if (typeof orig !== 'function' || orig.__iiWrapped) return;
        var w = function () {
            var r = orig.apply(this, arguments);
            try { fn.apply(this, arguments); } catch (e) { console.warn('[inventory-insights] ' + name + ':', e); }
            return r;
        };
        w.__iiWrapped = true;
        window[name] = w;
    }

    // ------------------------------------------------------------------ โครงหน้า
    function build() {
        var page = document.getElementById('dashboard-page');
        var header = page && page.querySelector('.page-header');
        if (!page || !header || S.built) return false;

        var tabs = el('div', 'ii-viewtabs',
            '<button type="button" class="ii-vt active" data-view="overview"><i class="fa-solid fa-chart-line" aria-hidden="true"></i> ' +
                '<span class="ii-vt-long">ภาพรวมสต๊อก</span><span class="ii-vt-short">ภาพรวม</span></button>' +
            '<button type="button" class="ii-vt" data-view="minmax"><i class="fa-solid fa-arrows-up-down" aria-hidden="true"></i> ' +
                '<span class="ii-vt-long">ตั้ง Min-Max</span><span class="ii-vt-short">Min-Max</span>' +
                ' <span class="ii-vt-badge" id="iiVtBadge" hidden title="ต่ำกว่า Min"></span></button>' +
            // Error log — ซ่อนไว้จนกว่า server ยืนยันว่าผู้ใช้นี้ดูได้
            // รอบจ่าย (มติ 50) — ซ่อนไว้จนกว่า server ยืนยันว่าดูกระดานได้
            '<button type="button" class="ii-vt" data-view="dispatch" id="iiVtDispatch" hidden><i class="fa-solid fa-truck-fast" aria-hidden="true"></i> ' +
                '<span class="ii-vt-long">รอบจ่าย</span><span class="ii-vt-short">รอบจ่าย</span>' +
                ' <span class="ii-vt-badge" id="iiDrBadge" hidden title="ใบที่เลยรอบจ่ายแล้วยังไม่ได้จ่าย"></span></button>' +
            '<button type="button" class="ii-vt" data-view="errors" id="iiVtErrors" hidden><i class="fa-solid fa-bug" aria-hidden="true"></i> ' +
                '<span class="ii-vt-long">Error log</span><span class="ii-vt-short">Error</span>' +
                ' <span class="ii-vt-badge" id="iiErBadge" hidden title="error 7 วันล่าสุด (ไม่นับทดสอบ)"></span></button>');
        tabs.setAttribute('role', 'tablist');
        header.parentNode.insertBefore(tabs, header.nextSibling);

        var ov = el('div');  ov.id = 'iiOverview';
        var mm = el('div');  mm.id = 'iiMinMax'; mm.hidden = true;
        var dr = el('div');  dr.id = 'iiDispatch'; dr.hidden = true;
        var er = el('div');  er.id = 'iiErrors'; er.hidden = true;
        var n = tabs.nextSibling;
        while (n) { var nx = n.nextSibling; ov.appendChild(n); n = nx; }
        page.appendChild(ov);
        page.appendChild(mm);
        page.appendChild(dr);
        page.appendChild(er);

        // การ์ดวัสดุยอดนิยม + Min-Max อยู่บนสุดของภาพรวม (เหนือตัวเลขสรุป) — ผู้ใช้ขอ 2026-09-24
        var row = el('div', 'ii-row', popCardHtml() + mmCardHtml());
        ov.insertBefore(row, ov.firstChild);

        mm.innerHTML = mmViewHtml();
        dr.innerHTML = drViewHtml();
        er.innerHTML = erViewHtml();
        wire(page);
        S.built = true;
        var saved = lsGet(LS_VIEW);
        if (saved === 'minmax' || saved === 'errors' || saved === 'dispatch') setView(saved, true);
        // ตั้งรอบจ่ายจากหน้าต่างของ js/dispatch-rounds.js แล้ว → กระดานโหลดใหม่
        document.addEventListener('connext:dispatch-rounds-saved', function () { invalidate(['drBoard']); if (window.user) loadBoard({ force: true }); });
        return true;
    }

    function popCardHtml() {
        var days = [[30, '30 วัน'], [90, '90 วัน'], [180, '180 วัน'], [0, 'ทั้งหมด']].map(function (d) {
            return '<button type="button" class="ii-chip' + (d[0] === S.pop.days ? ' active' : '') + '" data-days="' + d[0] + '">' + d[1] + '</button>';
        }).join('');
        var types = [['', 'เบิก + เบ็ดเตล็ด'], ['RD', 'เบิกหลัก'], ['OD', 'เบ็ดเตล็ด'], ['BD', 'ยืม']].map(function (t) {
            return '<button type="button" class="ii-chip' + (t[0] === S.pop.type ? ' active' : '') + '" data-type="' + t[0] + '">' + t[1] + '</button>';
        }).join('');
        return '<section class="ii-card" id="iiPopCard">' +
            '<div class="ii-head"><h3><i class="fa-solid fa-fire-flame-curved" aria-hidden="true"></i> วัสดุยอดนิยม</h3>' +
                '<span class="ii-site" id="iiPopSite"></span><div class="ii-end ii-chips" id="iiPopDays">' + days + '</div></div>' +
            '<div class="ii-sub"><div class="ii-chips" id="iiPopType">' + types + '</div>' +
                '<span class="ii-note" id="iiPopNote">นับจำนวนใบที่มีวัสดุนั้น · ไม่รวมใบยกเลิก</span></div>' +
            '<div class="ii-chart" id="iiPopBox" style="height:220px;"><canvas id="iiPopChart" aria-label="กราฟวัสดุยอดนิยม" role="img"></canvas></div>' +
            '<div class="ii-empty" id="iiPopEmpty" hidden></div>' +
        '</section>';
    }

    function mmCardHtml() {
        return '<section class="ii-card" id="iiMmCard">' +
            '<div class="ii-head"><h3><i class="fa-solid fa-arrows-up-down" aria-hidden="true"></i> Min-Max stock</h3>' +
                '<span class="ii-site" id="iiMmSite"></span>' +
                '<div class="ii-end"><button type="button" class="ii-link-btn" data-go="minmax"><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i> ตั้ง Min-Max</button></div></div>' +
            '<div id="iiMmCardBody"><div class="ii-empty"><i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i>กำลังคำนวณ...</div></div>' +
        '</section>';
    }

    function mmViewHtml() {
        return '<div class="ii-mm-wrap">' +
            '<div class="rate-toolbar ii-mm-toolbar">' +
                '<div class="rate-site-box"><span class="rate-site-label"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> Site</span>' +
                    '<select id="iiMmSiteSel" class="form-control"></select></div>' +
                '<div class="rate-search-box"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>' +
                    '<input type="text" id="iiMmSearch" class="form-control" placeholder="ค้นหา รหัส IC / ชื่อวัสดุ..."></div>' +
                '<select id="iiMmFilter" class="form-control" style="width:auto;" aria-label="แสดงเฉพาะ">' +
                    '<option value="">แสดงทั้งหมด</option>' +
                    '<option value="need">เฉพาะที่ต้องเติม</option>' +
                    '<option value="unset">เฉพาะที่ยังไม่ตั้ง</option>' +
                    '<option value="set">เฉพาะที่ตั้งแล้ว</option>' +
                    '<option value="over">เฉพาะเกิน Max</option>' +
                    '<option value="sug">เฉพาะที่มีค่าแนะนำ</option>' +
                '</select>' +
                '<button type="button" class="btn btn-secondary ii-edit-only" id="iiMmApplyAll" style="width:auto;"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> ใช้ค่าแนะนำ (ที่ยังไม่ตั้ง)</button>' +
                '<button type="button" class="btn btn-primary ii-edit-only" id="iiMmSave" style="width:auto;" disabled><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> บันทึก Min-Max</button>' +
            '</div>' +
            '<details class="ii-params" id="iiMmParams">' +
                '<summary><i class="fa-solid fa-calculator" aria-hidden="true"></i> ค่าแนะนำคำนวณจากการเบิกจริง <span class="ii-sum-note" id="iiPNote"></span>' +
                    '<i class="fa-solid fa-chevron-down" aria-hidden="true"></i></summary>' +
                '<div class="ii-params-body">' +
                    '<div class="ii-params-grid">' +
                        '<div><label for="iiPLT">Lead time — รอของ (วัน)</label><input type="number" id="iiPLT" class="form-control" min="1" max="180" step="0.5" inputmode="decimal"></div>' +
                        '<div><label for="iiPCY">รอบสั่งซื้อ (วัน)</label><input type="number" id="iiPCY" class="form-control" min="1" max="180" step="0.5" inputmode="decimal"></div>' +
                        '<div><label for="iiPSL">ระดับบริการ (กันของขาด)</label><select id="iiPSL" class="form-control">' +
                            '<option value="90">90%</option><option value="95">95%</option><option value="98">98%</option><option value="99">99%</option></select></div>' +
                        '<div><label for="iiPLB">ดูย้อนหลัง (วัน)</label><input type="number" id="iiPLB" class="form-control" min="14" max="730" step="1" inputmode="numeric"></div>' +
                    '</div>' +
                    '<div class="ii-formula"><b>Min</b> = ใช้เฉลี่ย/วัน × Lead time + Safety · <b>Safety</b> = Z × σ × √Lead time · ' +
                        '<b>Max</b> = Min + ใช้เฉลี่ย/วัน × รอบสั่งซื้อ · <b>ควรสั่ง</b> = Max − พร้อมเบิก (เมื่อพร้อมเบิกถึง Min)<br>' +
                        'ใช้เฉลี่ย/วัน และ σ มาจากใบเบิกหลัก + เบ็ดเตล็ดที่ไม่ยกเลิก หารด้วย<b>วันที่ไซต์มีบันทึกในระบบ</b> <span id="iiPWindow"></span> ' +
                        '— ช่วงที่ยังไม่ได้ใช้ระบบไม่ถ่วงค่าเฉลี่ยลง · ต้องมีการเบิกอย่างน้อย 2 วันจึงแนะนำ · พร้อมเบิก = คงเหลือ − จองแล้ว</div>' +
                    '<div class="ii-params-actions">' +
                        '<button type="button" class="btn btn-secondary" id="iiPRecalc"><i class="fa-solid fa-rotate" aria-hidden="true"></i> คำนวณใหม่ (ยังไม่บันทึก)</button>' +
                        '<button type="button" class="btn btn-primary ii-edit-only" id="iiPSave"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> บันทึกเป็นค่าตั้งต้นของไซต์</button>' +
                    '</div>' +
                '</div>' +
            '</details>' +
            '<div id="iiMmBody"><div class="qr-empty"><i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i><span>กำลังโหลด...</span></div></div>' +
            '<div class="ii-mm-savebar ii-edit-only"><button type="button" class="btn btn-primary" id="iiMmSave2" disabled><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> บันทึก Min-Max</button></div>' +
        '</div>';
    }

    function wire(page) {
        page.addEventListener('click', function (e) {
            var t = e.target.closest ? e.target : null;
            if (!t) return;
            var vt = t.closest('.ii-vt');
            if (vt) { setView(vt.getAttribute('data-view')); return; }
            var go = t.closest('[data-go]');
            if (go) {
                if (go.hasAttribute('data-filter')) setFilter(go.getAttribute('data-filter'));
                setView(go.getAttribute('data-go'));
                return;
            }
            var d = t.closest('#iiPopDays .ii-chip');
            if (d) { S.pop.days = parseInt(d.getAttribute('data-days'), 10) || 0; chipActive('#iiPopDays', d); loadPopular(); return; }
            var ty = t.closest('#iiPopType .ii-chip');
            if (ty) { S.pop.type = ty.getAttribute('data-type') || ''; chipActive('#iiPopType', ty); loadPopular(); return; }
            var li = t.closest('.ii-li[data-mat]');
            if (li) { openDetail(li.getAttribute('data-mat'), li.getAttribute('data-site')); return; }
            if (t.closest('#iiMmApplyAll')) { applySuggestions(); return; }
            if (t.closest('#iiMmSave') || t.closest('#iiMmSave2')) { saveMinMax(); return; }
            if (t.closest('#iiPRecalc')) { loadMinMax({ force: true, params: readParams() }); return; }
            if (t.closest('#iiPSave')) { saveParams(); return; }
            var use = t.closest('.ii-use-btn');
            if (use) { useSuggestion(use.closest('tr')); return; }
            var grp = t.closest('#iiMmBody .rate-grp-row');
            if (grp) { var tb = grp.closest('.rate-group'); if (tb) { tb.classList.toggle('collapsed'); filterRows(); } return; }
            // Error log
            var ed = t.closest('#iiErDays .ii-chip');
            if (ed) { S.er.days = parseInt(ed.getAttribute('data-days'), 10) || 0; chipActive('#iiErDays', ed); loadErrors(); return; }
            var ec = t.closest('.ii-ecat[data-cat]');
            if (ec) { var k = ec.getAttribute('data-cat'); setErrCat(S.er.cat === k ? '' : k); return; }
            if (t.closest('#iiErMore')) { S.er.limit += 300; renderErrRows(); return; }
            if (t.closest('#iiErCsv')) { exportErrorsCsv(); return; }
            // รอบจ่าย
            var dd = t.closest('#iiDrDays .ii-chip');
            if (dd) { S.dr.date = dd.getAttribute('data-date') || ''; loadBoard(); return; }
            if (t.closest('.ii-dr-gear')) {
                if (window.DispatchRounds) window.DispatchRounds.openSettings(S.dr.data ? S.dr.data.siteCode : drSite(), function () { loadBoard({ force: true }); });
                return;
            }
            if (t.closest('#iiDrRefresh')) { loadBoard({ force: true }); return; }
        });
        document.getElementById('iiDrSiteSel').addEventListener('change', function () { S.dr.site = this.value; S.dr.data = null; loadBoard({ force: true }); });
        document.getElementById('iiDrDate').addEventListener('change', function () { S.dr.date = this.value || ''; loadBoard(); });
        document.getElementById('iiErHideTest').addEventListener('change', function () {
            S.er.hideTest = this.checked;
            if (S.er.hideTest && S.er.cat === 'test') S.er.cat = '';
            renderErrors();
        });
        document.getElementById('iiErSiteSel').addEventListener('change', function () { S.er.site = this.value; loadErrors({ force: true }); });
        var erBody = document.getElementById('iiErBody');
        erBody.addEventListener('input', function (e) { if (e.target.id === 'iiErSearch') { S.er.limit = 200; renderErrRows(); } });
        erBody.addEventListener('change', function (e) {
            if (e.target.id === 'iiErCat') setErrCat(e.target.value);
            if (e.target.id === 'iiErGate') { S.er.gate = e.target.value; S.er.limit = 200; renderErrRows(); }
        });
        var body = document.getElementById('iiMmBody');
        body.addEventListener('input', function (e) {
            if (e.target.classList && e.target.classList.contains('ii-mm-input')) onEdit(e.target.closest('tr'));
        });
        var search = document.getElementById('iiMmSearch');
        search.addEventListener('input', filterRows);
        document.getElementById('iiMmFilter').addEventListener('change', function () { S.mm.filter = this.value; filterRows(); });
        document.getElementById('iiMmSiteSel').addEventListener('change', function () {
            var v = this.value;
            if (dirtyCount() && !window.confirm('มี Min-Max ที่ยังไม่ได้บันทึก — เปลี่ยน Site แล้วจะไม่บันทึก ดำเนินการต่อหรือไม่?')) {
                this.value = S.mm.data ? S.mm.data.siteCode : v;
                return;
            }
            S.mm.site = v;
            S.mm.dirty = {};
            loadMinMax({ force: true });
        });
    }

    function chipActive(groupSel, btn) {
        $all(groupSel + ' .ii-chip').forEach(function (b) { b.classList.toggle('active', b === btn); });
    }

    function setView(v, silent) {
        if (!VIEWS[v]) v = 'overview';
        if (S.view === 'minmax' && v !== 'minmax' && dirtyCount()) {
            if (!window.confirm('มี Min-Max ที่ยังไม่ได้บันทึก ' + dirtyCount() + ' รายการ — ออกจากหน้านี้แล้วค่าที่แก้จะหาย ดำเนินการต่อหรือไม่?')) return;
            S.mm.dirty = {};
            renderTable();
        }
        S.view = v;
        $all('.ii-vt').forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-view') === v); });
        Object.keys(VIEWS).forEach(function (k) {
            var box = document.getElementById(VIEWS[k]);
            if (box) box.hidden = (k !== v);
        });
        lsSet(LS_VIEW, v);
        if (v === 'minmax') {
            if (S.mm.data) renderTable();
            else if (window.user) loadMinMax();      // ยังไม่ล็อกอิน — รอ loadDashboard เรียก loadAll
        } else if (v === 'errors') {
            if (S.er.data) renderErrors();
            else if (window.user) loadErrors();
        } else if (v === 'dispatch') {
            if (S.dr.data) renderBoard();
            if (window.user) loadBoard();          // เวลาเดินตลอด — เปิดแท็บทีไรโหลดใหม่ (แคชโชว์ก่อน)
        } else if (S.pop.chart) {
            try { S.pop.chart.resize(); } catch (e) {}
        }
        if (!silent && typeof window.hapticTap === 'function') { try { window.hapticTap(); } catch (e) {} }
    }

    function setFilter(f) {
        S.mm.filter = f || '';
        var sel = document.getElementById('iiMmFilter');
        if (sel) sel.value = S.mm.filter;
        filterRows();
    }

    // ------------------------------------------------------------------ วัสดุยอดนิยม
    function loadPopular(opts) {
        var site = dashSite();
        swr('iiPopular', 'getPopularMaterials', [site, S.pop.days, S.pop.type], renderPopular, function (e) {
            console.warn('getPopularMaterials', e);
            showPopEmpty('<i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>โหลดวัสดุยอดนิยมไม่สำเร็จ');
        }, opts);
    }

    function showPopEmpty(html) {
        var box = document.getElementById('iiPopBox'), em = document.getElementById('iiPopEmpty');
        if (box) box.style.display = 'none';
        if (em) { em.hidden = false; em.innerHTML = html; }
        if (S.pop.chart) { try { S.pop.chart.destroy(); } catch (e) {} S.pop.chart = null; }
    }

    function renderPopular(d) {
        S.pop.data = d;
        var siteEl = document.getElementById('iiPopSite');
        if (siteEl) siteEl.textContent = d && d.siteCode ? 'ไซต์ ' + d.siteCode : 'ทุกไซต์';
        var note = document.getElementById('iiPopNote');
        if (!d || !d.success) { showPopEmpty('<i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>' + esc((d && d.message) || 'โหลดไม่สำเร็จ')); return; }
        var items = (d.items || []).slice(0, 10);
        if (note) {
            note.textContent = d.totalDocs
                ? d.totalDocs.toLocaleString() + ' ใบ · ' + d.totalMaterials.toLocaleString() + ' รหัส' + (d.firstDate ? ' · ' + thDate(d.firstDate) + ' – ' + thDate(d.lastDate) : '') + ' · ไม่รวมใบยกเลิก'
                : 'นับจำนวนใบที่มีวัสดุนั้น · ไม่รวมใบยกเลิก';
        }
        if (!items.length) {
            showPopEmpty('<i class="fa-solid fa-chart-bar" aria-hidden="true"></i>' +
                (d.days ? 'ช่วง ' + d.days + ' วันล่าสุดยังไม่มีการเบิก — ลองเลือก "ทั้งหมด"' : 'ยังไม่มีการเบิกในไซต์นี้'));
            return;
        }
        var box = document.getElementById('iiPopBox'), em = document.getElementById('iiPopEmpty');
        em.hidden = true;
        box.style.display = '';
        box.style.height = Math.max(180, items.length * 34 + 44) + 'px';
        if (typeof window.Chart !== 'function') { showPopEmpty('ไม่พบไลบรารีกราฟ'); return; }

        var narrow = box.clientWidth && box.clientWidth < 460;
        var maxLen = narrow ? 16 : 30;
        var labels = items.map(function (it) { var n = it.name || it.matCode; return n.length > maxLen ? n.slice(0, maxLen - 1) + '…' : n; });
        var keys = S.pop.type ? [S.pop.type] : ['RD', 'OD'];
        var datasets = keys.map(function (k) {
            return {
                label: TYPES[k].label, backgroundColor: TYPES[k].color, borderRadius: 4, maxBarThickness: 22,
                data: items.map(function (it) { return it[k.toLowerCase()] || 0; })
            };
        });
        if (S.pop.chart) { try { S.pop.chart.destroy(); } catch (e) {} }
        S.pop.chart = new window.Chart(document.getElementById('iiPopChart').getContext('2d'), {
            type: 'bar',
            data: { labels: labels, datasets: datasets },
            options: {
                indexAxis: 'y', responsive: true, maintainAspectRatio: false, animation: { duration: 250 },
                scales: {
                    x: { stacked: true, beginAtZero: true, ticks: { precision: 0 }, title: { display: !narrow, text: 'จำนวนใบ' }, grid: { color: '#eef2f7' } },
                    y: { stacked: true, ticks: { autoSkip: false, font: { size: narrow ? 11 : 12 } }, grid: { display: false } }
                },
                plugins: {
                    legend: { display: keys.length > 1, position: 'bottom', labels: { boxWidth: 12, boxHeight: 12 } },
                    tooltip: {
                        callbacks: {
                            title: function (ctx) { var it = items[ctx[0].dataIndex]; return it ? it.name : ''; },
                            afterTitle: function (ctx) { var it = items[ctx[0].dataIndex]; return it ? it.matCode : ''; },
                            label: function (ctx) { return ' ' + ctx.dataset.label + ': ' + ctx.parsed.x + ' ใบ'; },
                            footer: function (ctx) {
                                var it = items[ctx[0].dataIndex];
                                return it ? 'รวม ' + it.docs + ' ใบ · ' + num(it.qty) + ' ' + (it.unit || '') + ' · ล่าสุด ' + thDate(it.lastDate) : '';
                            }
                        }
                    }
                },
                onClick: function (evt, els) {
                    if (!els || !els.length) return;
                    var it = items[els[0].index];
                    if (it) openDetail(it.matCode, d.siteCode || '');
                },
                onHover: function (evt, els) { var c = evt.native && evt.native.target; if (c) c.style.cursor = els && els.length ? 'pointer' : 'default'; }
            }
        });
    }

    function thDate(ymd) {
        if (!ymd) return '';
        var p = String(ymd).split('-');
        if (p.length !== 3) return ymd;
        var m = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'][parseInt(p[1], 10) - 1] || p[1];
        return parseInt(p[2], 10) + ' ' + m + ' ' + String((parseInt(p[0], 10) + 543) % 100);
    }

    // ------------------------------------------------------------------ Min-Max: ข้อมูล
    function loadMinMax(opts) {
        opts = opts || {};
        var site = mmSite();
        var done = function (d) {
            S.mm.loading = false;
            if (d && d.success) {
                S.mm.data = d;
                if (!S.mm.site) S.mm.site = d.siteCode;
                fillParams(d.params, d.window);
            } else {
                S.mm.data = d && d.success === false ? d : null;
            }
            renderCard();
            if (S.view === 'minmax') renderTable();
        };
        var fail = function (e) {
            S.mm.loading = false;
            console.warn('getMinMaxData', e);
            var b = document.getElementById('iiMmCardBody');
            if (b) b.innerHTML = '<div class="ii-empty"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>โหลดข้อมูล Min-Max ไม่สำเร็จ</div>';
        };
        S.mm.loading = true;
        if (opts.params) {   // ลองค่าใหม่ — ไม่ผ่านแคช
            var mb = document.getElementById('iiMmBody');
            if (mb) mb.style.opacity = '0.55';
            call('getMinMaxData', [site, opts.params], function (d) { if (mb) mb.style.opacity = ''; done(d); if (d && d.success) toast('คำนวณค่าแนะนำใหม่แล้ว (ยังไม่ได้บันทึกค่าตั้งต้น)', 'info'); },
                function (e) { if (mb) mb.style.opacity = ''; fail(e); });
            return;
        }
        swr('iiMinMax', 'getMinMaxData', [site], done, fail, opts);
    }

    function loadSettings(opts) {
        swr('iiMinMaxSet', 'getMinMaxSettings', [dashSite()], function (d) {
            var map = {};
            ((d && d.items) || []).forEach(function (r) { map[r.siteCode + '|' + r.matCode] = { min: r.min, max: r.max }; });
            var sig = JSON.stringify(map);
            if (sig === S.setSig) return;
            S.setMap = map;
            S.setSig = sig;
            refreshDashboard();
        }, function (e) { console.warn('getMinMaxSettings', e); }, opts);
    }

    function refreshDashboard() {
        try {
            if (window.dashboardData && window.dashboardData.length) {
                if (typeof window.renderDashboardMetrics === 'function') window.renderDashboardMetrics();
                if (typeof window.renderDashboardTable === 'function') window.renderDashboardTable();
            }
        } catch (e) { console.warn('[inventory-insights] refreshDashboard', e); }
    }

    function settingFor(row) {
        if (!row) return null;
        var s = S.setMap[(row.SiteCode || '') + '|' + (row.MatCode || '')];
        return (s && (s.min != null || s.max != null)) ? s : null;
    }

    function fillParams(p, w) {
        if (!p) return;
        S.mm.params = p;
        var set = function (id, v) { var e = document.getElementById(id); if (e && document.activeElement !== e) e.value = v; };
        set('iiPLT', p.leadTimeDays);
        set('iiPCY', p.cycleDays);
        set('iiPSL', String(Math.round(p.serviceLevel)));
        set('iiPLB', p.lookbackDays);
        var note = document.getElementById('iiPNote');
        if (note) note.textContent = '· Lead time ' + num(p.leadTimeDays, 1) + ' วัน · รอบสั่ง ' + num(p.cycleDays, 1) + ' วัน · ' + Math.round(p.serviceLevel) + '%' + (p.saved ? '' : ' (ค่าตั้งต้นระบบ)');
        var wEl = document.getElementById('iiPWindow');
        if (wEl && w) wEl.textContent = '(' + w.activeDays + ' วัน' + (w.from ? ' ระหว่าง ' + thDate(w.from) + ' – ' + thDate(w.to) : '') + ' จากช่วง ' + p.lookbackDays + ' วันล่าสุด)';
    }

    function readParams() {
        var v = function (id) { var e = document.getElementById(id); return e ? e.value : ''; };
        return { leadTimeDays: v('iiPLT'), cycleDays: v('iiPCY'), serviceLevel: v('iiPSL'), lookbackDays: v('iiPLB') };
    }

    // ------------------------------------------------------------------ Min-Max: การ์ดบนภาพรวม
    function renderCard() {
        var b = document.getElementById('iiMmCardBody');
        var siteEl = document.getElementById('iiMmSite');
        var badge = document.getElementById('iiVtBadge');
        var d = S.mm.data;
        if (!b) return;
        if (!d || !d.success) {
            b.innerHTML = '<div class="ii-empty"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>' + esc((d && d.message) || 'โหลดข้อมูล Min-Max ไม่สำเร็จ') + '</div>';
            return;
        }
        if (siteEl) siteEl.textContent = 'ไซต์ ' + d.siteCode;
        var s = d.summary;
        if (badge) { badge.hidden = !s.low; badge.textContent = s.low || ''; }
        var p = d.params, w = d.window;
        var foot = '<div class="ii-foot"><span class="ii-note">คำนวณจากการเบิก ' + w.activeDays + ' วันที่มีบันทึก · Lead time ' + num(p.leadTimeDays, 1) +
            ' วัน · ' + Math.round(p.serviceLevel) + '%</span>' +
            '<button type="button" class="ii-link-btn" data-go="minmax"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i> ' +
            (s.set ? 'ดู / แก้ Min-Max' : 'เริ่มตั้ง Min-Max') + '</button></div>';

        if (!s.set) {
            var sugLow = d.items.filter(function (it) { return it.sugStatus === 'low'; })
                .sort(function (a, b) { return (a.available / Math.max(1, a.sugMin)) - (b.available / Math.max(1, b.sugMin)); });
            b.innerHTML =
                '<div class="ii-hint"><i class="fa-solid fa-lightbulb" aria-hidden="true"></i><div>ยังไม่ได้ตั้ง Min-Max ของไซต์นี้ — ระบบคำนวณค่าแนะนำจากการเบิกจริงให้แล้ว <b>' +
                    s.suggested.toLocaleString() + '</b> รหัส' + (s.sugLow ? '<br>ถ้าตั้งตามคำแนะนำตอนนี้ จะมี <b>' + s.sugLow + '</b> รายการที่ถึงจุดสั่งซื้อ (พร้อมเบิก ≤ Min)' : '') + '</div></div>' +
                (sugLow.length ? '<div class="ii-list">' + sugLow.slice(0, 5).map(function (it) {
                    return liHtml(it, d.siteCode, it.sugMin, it.sugMax, 'แนะนำ ');
                }).join('') + '</div>' : '') + foot;
            return;
        }
        var stats = ['low', 'over', 'ok', 'unset'].map(function (k) {
            var f = k === 'low' ? 'need' : k;
            return '<button type="button" class="ii-stat ' + k + '" data-go="minmax" data-filter="' + f + '"><div class="v">' + (s[k] || 0).toLocaleString() +
                '</div><div class="l">' + STATUS[k].label + '</div></button>';
        }).join('');
        var lows = d.items.filter(function (it) { return it.status === 'low'; })
            .sort(function (a, b) { return (a.available / Math.max(1, a.min || 1)) - (b.available / Math.max(1, b.min || 1)); });
        b.innerHTML = '<div class="ii-stats">' + stats + '</div>' +
            (lows.length
                ? '<div class="ii-list">' + lows.slice(0, 6).map(function (it) { return liHtml(it, d.siteCode, it.min, it.max, ''); }).join('') + '</div>' +
                  (lows.length > 6 ? '<div class="ii-note" style="margin-top:6px;">และอีก ' + (lows.length - 6) + ' รายการ</div>' : '')
                : '<div class="ii-empty"><i class="fa-solid fa-circle-check" aria-hidden="true" style="color:#16a34a;"></i>ไม่มีรายการที่ต่ำกว่า Min</div>') +
            foot;
    }

    function liHtml(it, site, min, max, prefix) {
        var ratio = min > 0 ? Math.max(0, Math.min(1, it.available / min)) : 0;
        var q = orderQty(it, min, max);
        return '<button type="button" class="ii-li" data-mat="' + esc(it.matCode) + '" data-site="' + esc(site) + '">' +
            '<span class="n">' + esc(it.name) + '</span>' +
            '<span class="q">' + (q > 0 ? 'ควรสั่ง<b>' + num(q) + ' ' + esc(it.unit) + '</b>' : '') + '</span>' +
            '<span class="c">' + esc(it.matCode) + ' · พร้อมเบิก ' + num(it.available) + ' / ' + prefix + 'Min ' + num(min) + '</span>' +
            '<span class="bar"><span style="width:' + Math.round(ratio * 100) + '%"></span></span>' +
        '</button>';
    }

    // ------------------------------------------------------------------ Min-Max: ตารางกรอก
    function renderTable() {
        var body = document.getElementById('iiMmBody');
        var d = S.mm.data;
        if (!body) return;
        if (!d) { body.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i><span>กำลังโหลด...</span></div>'; return; }
        if (!d.success) { body.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><span>' + esc(d.message || 'โหลดไม่สำเร็จ') + '</span></div>'; return; }

        var sel = document.getElementById('iiMmSiteSel');
        if (sel) {
            var sites = (d.sites && d.sites.length) ? d.sites : [{ code: d.siteCode, name: d.siteName }];
            sel.innerHTML = sites.map(function (s) {
                return '<option value="' + esc(s.code) + '"' + (s.code === d.siteCode ? ' selected' : '') + '>' + esc(s.code + (s.name ? ' · ' + s.name : '')) + '</option>';
            }).join('');
            sel.disabled = !(d.sites && d.sites.length > 1);
        }
        $all('#iiMinMax .ii-edit-only').forEach(function (e) { e.style.display = d.canEdit ? '' : 'none'; });

        var s = d.summary;
        var head = '<div class="rate-summary">' +
            '<span><i class="fa-solid fa-location-dot" style="color:var(--primary);" aria-hidden="true"></i> <b>' + esc(d.siteCode) + '</b>' + (d.siteName ? ' · ' + esc(d.siteName) : '') + '</span>' +
            '<span class="rate-summary-chip"><i class="fa-solid fa-list" aria-hidden="true"></i> ' + s.total + ' รหัส IC</span>' +
            '<span class="rate-summary-chip ok"><i class="fa-solid fa-sliders" aria-hidden="true"></i> ตั้งแล้ว ' + s.set + '</span>' +
            (s.unset ? '<span class="rate-summary-chip warn"><i class="fa-solid fa-circle-minus" aria-hidden="true"></i> ยังไม่ตั้ง ' + s.unset + '</span>' : '') +
            (s.low ? '<span class="rate-summary-chip low"><i class="fa-solid fa-arrow-trend-down" aria-hidden="true"></i> ต่ำกว่า Min ' + s.low + '</span>' : '') +
            (s.over ? '<span class="rate-summary-chip over"><i class="fa-solid fa-arrow-trend-up" aria-hidden="true"></i> เกิน Max ' + s.over + '</span>' : '') +
        '</div>';
        var ro = d.canEdit ? '' : '<div class="ii-readonly"><i class="fa-solid fa-eye" aria-hidden="true"></i> ดูอย่างเดียว — ตั้ง Min-Max ได้เฉพาะ ADM หรือสายคลังของไซต์นี้</div>';
        var alertBox = (d.canEdit && s.unset && s.suggested)
            ? '<div class="rate-alert" style="background:#eff6ff; border-color:#bfdbfe; color:#1e40af;"><i class="fa-solid fa-wand-magic-sparkles" style="color:#2563eb;" aria-hidden="true"></i><div>มีค่าแนะนำจากการเบิกจริง <b>' +
                s.suggested + '</b> รหัส — กด "ใช้" ทีละรายการ หรือ "ใช้ค่าแนะนำ (ที่ยังไม่ตั้ง)" แล้วตรวจก่อนกดบันทึก' +
                '<span class="rate-alert-sub" style="color:#1d4ed8;">Min = จุดสั่งซื้อ (พร้อมเบิกถึง Min แล้วต้องสั่ง) · Max = เติมขึ้นไปถึง · เว้นว่างทั้งคู่ = ไม่ตั้ง</span></div></div>'
            : '';

        if (!d.items.length) {
            body.innerHTML = ro + head + '<div class="qr-empty"><i class="fa-solid fa-boxes-stacked" aria-hidden="true"></i><span>ไซต์นี้ยังไม่มีรหัส IC ในสต๊อก</span></div>';
            updateSaveBtn();
            return;
        }

        var NO_SG = '— ไม่ระบุหมวด —';
        var map = {};
        d.items.forEach(function (it) { var g = (it.subgroup || '').trim() || NO_SG; (map[g] = map[g] || []).push(it); });
        var groups = Object.keys(map).sort(function (a, b) {
            var ax = a === NO_SG ? 1 : 0, bx = b === NO_SG ? 1 : 0;
            return ax !== bx ? ax - bx : a.localeCompare(b, 'th');
        });
        var dis = d.canEdit ? '' : ' disabled';
        var tb = groups.map(function (g) {
            var list = map[g].sort(function (a, b) { return a.name.localeCompare(b.name, 'th'); });
            var nSet = list.filter(function (it) { return it.min != null || it.max != null; }).length;
            var nLow = list.filter(function (it) { return it.status === 'low'; }).length;
            var gh = '<tr class="rate-grp-row"><td colspan="8"><i class="fa-solid fa-chevron-down rate-grp-chevron" aria-hidden="true"></i>' +
                '<i class="fa-solid fa-layer-group rate-grp-icon" aria-hidden="true"></i><b>' + esc(g) + '</b>' +
                '<span class="rate-grp-count">' + list.length + ' รหัส · ตั้งแล้ว ' + nSet +
                (nLow ? ' · <span style="color:#b91c1c;font-weight:600;">ต่ำกว่า Min ' + nLow + '</span>' : '') + '</span></td></tr>';
            return '<tbody class="rate-group">' + gh + list.map(function (it) { return rowHtml(it, dis); }).join('') + '</tbody>';
        }).join('');

        body.innerHTML = ro + alertBox + head +
            '<div class="rate-table-wrap ii-mm-tw"><table class="rate-table ii-mm-table">' +
            '<thead><tr><th>รหัส IC</th><th>ชื่อวัสดุ · หน่วย</th><th class="num">คงเหลือ</th><th class="num">ใช้เฉลี่ย/วัน</th>' +
            '<th>แนะนำ Min – Max</th><th class="mm">Min</th><th class="mm">Max</th><th>สถานะ</th></tr></thead>' + tb + '</table></div>' +
            '<div class="rate-empty-search" id="iiMmNoMatch" style="display:none;"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> ไม่พบรายการตามเงื่อนไข</div>';
        // แก้ค้างไว้ก่อนโหลดใหม่ (เช่น กดคำนวณใหม่) — ใส่ค่าที่พิมพ์ไว้กลับ
        Object.keys(S.mm.dirty).forEach(function (code) {
            var v = S.mm.dirty[code], tr = rowByCode(code);
            if (!tr || v === true) return;
            tr.querySelector('[data-k="min"]').value = v.min;
            tr.querySelector('[data-k="max"]').value = v.max;
            onEdit(tr);
        });
        filterRows();
        updateSaveBtn();
    }

    function rowHtml(it, dis) {
        var st = it.status;
        var pend = it.pending > 0 ? '<span class="ii-small">พร้อมเบิก ' + num(it.available) + '</span>' : '';
        var use = it.adu != null
            ? num(it.adu, it.adu < 10 ? 2 : 1) + '<span class="ii-small">เบิก ' + it.useDays + ' วัน</span>'
            : '<span class="ii-small">ไม่มีการเบิก</span>';
        var sug = it.sugMin != null
            ? '<span class="ii-sug"><b>' + num(it.sugMin) + '</b> – <b>' + num(it.sugMax) + '</b></span>' + (dis ? '' : '<button type="button" class="ii-use-btn" title="ใส่ค่าแนะนำลงช่อง Min/Max">ใช้</button>')
            : '<span class="ii-small">' + (it.useDays === 1 ? 'เบิกแค่ 1 วัน — ข้อมูลไม่พอ' : 'ข้อมูลไม่พอ') + '</span>';
        return '<tr class="rate-row' + (st === 'low' ? ' ii-row-low' : st === 'over' ? ' ii-row-over' : '') + '" data-code="' + esc(it.matCode) + '"' +
            ' data-q="' + esc((it.matCode + ' ' + it.name).toLowerCase()) + '">' +
            '<td class="rate-td-code">' + esc(it.matCode) + '</td>' +
            '<td class="rate-td-name ii-td-name">' + esc(it.name) + '<span class="rate-unit">' + esc(it.unit || '-') + '</span></td>' +
            '<td class="num" data-label="คงเหลือ">' + num(it.onHand) + pend + '</td>' +
            '<td class="num" data-label="ใช้เฉลี่ย/วัน">' + use + '</td>' +
            '<td class="ii-td-sug" data-label="แนะนำ Min – Max">' + sug + '</td>' +
            '<td class="mm" data-label="Min"><input type="number" inputmode="decimal" min="0" step="any" class="form-control ii-mm-input" data-k="min" value="' +
                (it.min == null ? '' : it.min) + '" placeholder="-" aria-label="Min ' + esc(it.name) + '"' + dis + '></td>' +
            '<td class="mm" data-label="Max"><input type="number" inputmode="decimal" min="0" step="any" class="form-control ii-mm-input" data-k="max" value="' +
                (it.max == null ? '' : it.max) + '" placeholder="-" aria-label="Max ' + esc(it.name) + '"' + dis + '></td>' +
            '<td class="ii-td-status">' + statusHtml(it, it.min, it.max) + '</td>' +
        '</tr>';
    }

    function statusHtml(it, min, max) {
        var st = statusOf(it, min, max);
        var h = '<span class="ii-pill ' + st + '">' + STATUS[st].label + '</span>';
        if (st === 'low') {
            var q = orderQty(it, min, max);
            if (q > 0) h += '<span class="ii-order">ควรสั่ง ' + num(q) + ' ' + esc(it.unit || '') + '</span>';
        } else if (st === 'unset' && it.sugStatus === 'low') {
            h += '<span class="ii-order">ตามค่าแนะนำ: ถึงจุดสั่งซื้อ</span>';
        }
        return h;
    }

    function itemByCode(code) {
        var d = S.mm.data;
        if (!d || !d.items) return null;
        for (var i = 0; i < d.items.length; i++) { if (d.items[i].matCode === code) return d.items[i]; }
        return null;
    }
    function rowByCode(code) {
        var rows = $all('#iiMmBody tr.rate-row');
        for (var i = 0; i < rows.length; i++) { if (rows[i].getAttribute('data-code') === code) return rows[i]; }
        return null;
    }

    function onEdit(tr) {
        if (!tr) return;
        var code = tr.getAttribute('data-code');
        var it = itemByCode(code);
        var iMin = tr.querySelector('[data-k="min"]'), iMax = tr.querySelector('[data-k="max"]');
        var min = parseQty(iMin.value), max = parseQty(iMax.value);
        var minBad = min === false, maxBad = max === false;
        var orderBad = !minBad && !maxBad && min != null && max != null && max < min;
        var bad = minBad || maxBad || orderBad;
        iMin.classList.toggle('bad', minBad || orderBad);
        iMax.classList.toggle('bad', maxBad || orderBad);
        var same = !bad && it && sameVal(it.min, min) && sameVal(it.max, max);
        if (same) delete S.mm.dirty[code]; else S.mm.dirty[code] = { min: iMin.value, max: iMax.value };
        tr.classList.toggle('rate-dirty', !same);
        tr.classList.toggle('ii-bad', !!bad);
        if (it && !bad) {
            var st = statusOf(it, min, max);
            tr.classList.toggle('ii-row-low', st === 'low');
            tr.classList.toggle('ii-row-over', st === 'over');
            tr.querySelector('.ii-td-status').innerHTML = statusHtml(it, min, max);
        } else if (bad) {
            tr.querySelector('.ii-td-status').innerHTML = '<span class="ii-pill low">' + (orderBad ? 'Max ต้อง ≥ Min' : 'ตัวเลข ≥ 0 เท่านั้น') + '</span>';
        }
        updateSaveBtn();
    }
    function sameVal(a, b) { return (a == null && b == null) || (a != null && b != null && Math.abs(a - b) < 0.0005); }

    function dirtyCount() { return Object.keys(S.mm.dirty).length; }
    function badCount() { return $all('#iiMmBody tr.rate-row.ii-bad').length; }
    function updateSaveBtn() {
        var n = dirtyCount(), bad = badCount();
        ['iiMmSave', 'iiMmSave2'].forEach(function (id) {
            var b = document.getElementById(id);
            if (!b) return;
            b.disabled = n === 0;
            b.innerHTML = '<i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> บันทึก Min-Max' + (n ? ' (' + n + ')' : '') + (bad ? ' · แก้ ' + bad + ' รายการก่อน' : '');
        });
    }

    function useSuggestion(tr) {
        if (!tr) return;
        var it = itemByCode(tr.getAttribute('data-code'));
        if (!it || it.sugMin == null) return;
        tr.querySelector('[data-k="min"]').value = it.sugMin;
        tr.querySelector('[data-k="max"]').value = it.sugMax;
        onEdit(tr);
    }

    function applySuggestions() {
        var n = 0;
        $all('#iiMmBody tr.rate-row').forEach(function (tr) {
            if (tr.style.display === 'none') return;
            var it = itemByCode(tr.getAttribute('data-code'));
            if (!it || it.sugMin == null) return;
            var iMin = tr.querySelector('[data-k="min"]'), iMax = tr.querySelector('[data-k="max"]');
            if (iMin.value.trim() !== '' || iMax.value.trim() !== '') return;   // ตั้งไว้/พิมพ์ไว้แล้ว — ไม่ทับ
            iMin.value = it.sugMin;
            iMax.value = it.sugMax;
            onEdit(tr);
            n++;
        });
        if (n) toast('ใส่ค่าแนะนำแล้ว ' + n + ' รายการ — ตรวจแล้วกด "บันทึก Min-Max"', 'success');
        else toast('ไม่มีรายการที่ยังไม่ตั้งและมีค่าแนะนำ (ในรายการที่แสดงอยู่)', 'info');
    }

    function filterRows() {
        var q = ((document.getElementById('iiMmSearch') || {}).value || '').trim().toLowerCase();
        var f = S.mm.filter;
        var expand = !!q || !!f;
        var shown = 0;
        $all('#iiMmBody .rate-group').forEach(function (g) {
            var collapsed = g.classList.contains('collapsed');
            var n = 0;
            $all('tr.rate-row', g).forEach(function (tr) {
                var it = itemByCode(tr.getAttribute('data-code'));
                var iMin = tr.querySelector('[data-k="min"]'), iMax = tr.querySelector('[data-k="max"]');
                var min = parseQty(iMin.value), max = parseQty(iMax.value);
                var isSet = (min != null && min !== false) || (max != null && max !== false);
                var st = it ? statusOf(it, min === false ? null : min, max === false ? null : max) : 'unset';
                var ok = !q || (tr.getAttribute('data-q') || '').indexOf(q) !== -1;
                if (ok && f === 'need')  ok = st === 'low' || (!isSet && it && it.sugStatus === 'low');
                if (ok && f === 'unset') ok = !isSet;
                if (ok && f === 'set')   ok = isSet;
                if (ok && f === 'over')  ok = st === 'over';
                if (ok && f === 'sug')   ok = !!(it && it.sugMin != null);
                tr.style.display = (ok && (!collapsed || expand)) ? '' : 'none';
                if (ok) n++;
            });
            g.style.display = n ? '' : 'none';
            shown += n;
        });
        var no = document.getElementById('iiMmNoMatch');
        if (no) no.style.display = shown ? 'none' : '';
    }

    // ------------------------------------------------------------------ บันทึก
    function saveMinMax() {
        var d = S.mm.data;
        if (!d || !d.canEdit) return;
        if (badCount()) {
            if (typeof window.showInfoPopup === 'function') window.showInfoPopup('ค่าไม่ถูกต้อง', 'มี ' + badCount() + ' รายการที่ Max น้อยกว่า Min หรือเป็นค่าติดลบ — แก้ช่องที่ขึ้นกรอบแดงก่อนบันทึก', 'warning');
            return;
        }
        var items = [], rows = [];
        Object.keys(S.mm.dirty).forEach(function (code) {
            var tr = rowByCode(code), it = itemByCode(code);
            if (!tr || !it) return;
            var vMin = tr.querySelector('[data-k="min"]').value.trim(), vMax = tr.querySelector('[data-k="max"]').value.trim();
            items.push({ matCode: code, min: vMin, max: vMax });
            var fmtOld = function (v) { return v == null ? '<span class="rcfm-none">—</span>' : num(v); };
            var fmtNew = function (s) { return s === '' ? '<span class="rcfm-clear">ล้าง</span>' : '<b>' + num(s) + '</b>'; };
            rows.push('<div class="rcfm-row"><div class="rcfm-name">' + esc(it.name) + ' <span class="rcfm-unit">/ ' + esc(it.unit || '') + '</span></div>' +
                '<div class="rcfm-price">Min ' + fmtOld(it.min) + ' <i class="fa-solid fa-arrow-right-long rcfm-arrow"></i> ' + fmtNew(vMin) +
                ' · Max ' + fmtOld(it.max) + ' <i class="fa-solid fa-arrow-right-long rcfm-arrow"></i> ' + fmtNew(vMax) + '</div></div>');
        });
        if (!items.length) { toast('ยังไม่มีการแก้ไข Min-Max', 'danger'); return; }
        var msg = '<div style="text-align:left;"><div class="rcfm-head"><i class="fa-solid fa-location-dot"></i> ' + esc(d.siteCode + (d.siteName ? ' · ' + d.siteName : '')) +
            ' · แก้ไข <b>' + items.length + '</b> รายการ</div><div class="rcfm-list">' + rows.join('') + '</div></div>';
        var go = function () {
            if (typeof window.showLoadingPopup === 'function') window.showLoadingPopup('กำลังบันทึก Min-Max', 'กำลังบันทึก ' + items.length + ' รายการ\nกรุณารอสักครู่...');
            call('saveMinMax', [{ siteCode: d.siteCode, items: items }], function (res) {
                if (typeof window.closeAppPopup === 'function') window.closeAppPopup();
                if (!res || !res.success) {
                    if (typeof window.showInfoPopup === 'function') window.showInfoPopup('บันทึกไม่สำเร็จ', (res && res.message) || 'บันทึก Min-Max ไม่สำเร็จ', 'danger');
                    return;
                }
                if (typeof window.hapticSuccess === 'function') { try { window.hapticSuccess(); } catch (e) {} }
                S.mm.dirty = {};
                invalidate(['iiMinMax', 'iiMinMaxSet']);
                toast('บันทึก Min-Max แล้ว ' + (res.saved || 0) + ' รายการ' + (res.cleared ? ' · ล้าง ' + res.cleared : ''), 'success');
                loadMinMax({ force: true });
                loadSettings({ force: true });
            }, function (err) {
                if (typeof window.closeAppPopup === 'function') window.closeAppPopup();
                if (typeof window.showInfoPopup === 'function') window.showInfoPopup('บันทึกไม่สำเร็จ', (err && err.message) || String(err || 'unknown'), 'danger');
            });
        };
        if (typeof window.showAppPopup === 'function') {
            window.showAppPopup({
                type: 'warning', title: 'ตรวจสอบ Min-Max ก่อนบันทึก', message: msg,
                buttons: [
                    { text: 'ยกเลิก', className: 'btn btn-secondary', onClick: window.closeAppPopup },
                    { text: 'ยืนยันบันทึก', className: 'btn btn-primary', onClick: go }
                ]
            });
        } else if (window.confirm('บันทึก Min-Max ' + items.length + ' รายการ?')) {
            go();
        }
    }

    function saveParams() {
        var d = S.mm.data;
        if (!d || !d.canEdit) return;
        var p = readParams();
        p.siteCode = d.siteCode;
        call('saveMinMaxParams', [p], function (res) {
            if (!res || !res.success) {
                if (typeof window.showInfoPopup === 'function') window.showInfoPopup('บันทึกไม่สำเร็จ', (res && res.message) || 'บันทึกค่าตั้งต้นไม่สำเร็จ', 'danger');
                return;
            }
            invalidate(['iiMinMax']);
            toast('บันทึกค่าตั้งต้นของไซต์ ' + d.siteCode + ' แล้ว — ค่าแนะนำคำนวณใหม่ตามค่านี้', 'success');
            loadMinMax({ force: true });
        });
    }

    // ------------------------------------------------------------------ กระดานรอบจ่าย (มติ 50 · lib/dispatch_rounds.php)
    function drViewHtml() {
        return '<div class="ii-mm-wrap">' +
            '<div class="rate-toolbar ii-mm-toolbar">' +
                '<div class="rate-site-box"><span class="rate-site-label"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> Site</span>' +
                    '<select id="iiDrSiteSel" class="form-control"></select></div>' +
                '<div class="ii-chips" id="iiDrDays"></div>' +
                '<input type="date" id="iiDrDate" class="form-control" style="width:auto;" aria-label="เลือกวัน">' +
                '<button type="button" class="btn btn-secondary" id="iiDrRefresh" style="width:auto;"><i class="fa-solid fa-rotate" aria-hidden="true"></i> โหลดใหม่</button>' +
                '<button type="button" class="btn btn-primary ii-dr-gear" id="iiDrGear" style="width:auto;" hidden><i class="fa-solid fa-gear" aria-hidden="true"></i> ตั้งค่ารอบจ่าย</button>' +
            '</div>' +
            '<div id="iiDrBody"><div class="qr-empty"><i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i><span>กำลังโหลด...</span></div></div>' +
        '</div>';
    }

    function drSite() { return S.dr.site || dashSite() || ((me() && me().siteCode) || ''); }

    function loadBoard(opts) {
        swr('drBoard', 'getDispatchBoard', [drSite(), S.dr.date], function (d) {
            var tab = document.getElementById('iiVtDispatch');
            if (d && d.success === false && d.message === 'no_permission') {
                S.dr.allowed = false;
                if (tab) tab.hidden = true;
                if (S.view === 'dispatch') setView('overview', true);
                return;
            }
            S.dr.allowed = true;
            if (tab) tab.hidden = false;
            S.dr.data = d;
            if (d && d.success && !S.dr.site) S.dr.site = d.siteCode;
            var badge = document.getElementById('iiDrBadge');
            if (badge && d && d.success && d.date === d.today) {
                var n = (d.overdue || []).length;
                badge.hidden = !n;
                badge.textContent = n || '';
            }
            if (S.view === 'dispatch') renderBoard();
        }, function (e) {
            console.warn('getDispatchBoard', e);
            var b = document.getElementById('iiDrBody');
            if (b && S.view === 'dispatch') b.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><span>โหลดกระดานรอบจ่ายไม่สำเร็จ</span></div>';
        }, opts);
    }

    function drAddDays(ymdStr, n) {
        var p = ymdStr.split('-');
        var d = new Date(+p[0], +p[1] - 1, +p[2] + n);
        return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
    }

    function renderBoard() {
        var body = document.getElementById('iiDrBody');
        var d = S.dr.data;
        if (!body) return;
        if (!d) { body.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i><span>กำลังโหลด...</span></div>'; return; }
        if (!d.success) { body.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><span>' + esc(d.message || 'โหลดไม่สำเร็จ') + '</span></div>'; return; }

        var sel = document.getElementById('iiDrSiteSel');
        if (sel) {
            var sites = (d.sites && d.sites.length) ? d.sites : [{ code: d.siteCode, name: d.siteName }];
            sel.innerHTML = sites.map(function (s) {
                return '<option value="' + esc(s.code) + '"' + (s.code === d.siteCode ? ' selected' : '') + '>' + esc(s.code + (s.name ? ' · ' + s.name : '')) + '</option>';
            }).join('');
            sel.disabled = !(d.sites && d.sites.length > 1);
        }
        var tomorrow = drAddDays(d.today, 1);
        var chips = [[d.today, 'วันนี้'], [tomorrow, 'พรุ่งนี้']].map(function (x) {
            return '<button type="button" class="ii-chip' + (d.date === x[0] ? ' active' : '') + '" data-date="' + x[0] + '">' + x[1] + '</button>';
        }).join('');
        document.getElementById('iiDrDays').innerHTML = chips;
        var dateIn = document.getElementById('iiDrDate');
        if (dateIn && document.activeElement !== dateIn) dateIn.value = d.date;
        var gear = document.getElementById('iiDrGear');
        if (gear) gear.hidden = !d.canEdit;

        var siteHead = '<span><i class="fa-solid fa-location-dot" style="color:var(--primary);" aria-hidden="true"></i> <b>' + esc(d.siteCode) + '</b>' + (d.siteName ? ' · ' + esc(d.siteName) : '') + '</span>';
        if (!d.enabled) {
            body.innerHTML = '<div class="rate-summary">' + siteHead + '</div>' +
                '<div class="qr-empty"><i class="fa-solid fa-truck-fast" aria-hidden="true"></i><span>' + (d.saved ? 'รอบจ่ายของไซต์นี้ปิดอยู่' : 'ยังไม่ได้ตั้งรอบจ่ายของไซต์นี้') + '</span>' +
                '<div style="margin-top:0.5rem; font-size:0.86rem;">ตั้งแล้วระบบจะจัดใบเบิกเข้ารอบตามเวลาที่เบิก เช่น เบิกก่อน 08:00 จ่าย 09:00 · เบิก 08:00–09:59 จ่าย 11:00</div>' +
                (d.canEdit ? '<button type="button" class="btn btn-primary ii-dr-gear" style="width:auto; margin-top:0.8rem;"><i class="fa-solid fa-gear" aria-hidden="true"></i> ตั้งค่ารอบจ่าย</button>'
                           : '<div style="margin-top:0.5rem; font-size:0.84rem;">ให้ ADM หรือสายคลังของไซต์เป็นคนตั้ง</div>') + '</div>';
            return;
        }

        var total = 0, wait = 0;
        d.rounds.forEach(function (r) { total += r.docs.length; wait += r.awaiting; });
        var head = '<div class="rate-summary">' + siteHead +
            '<span class="rate-summary-chip"><i class="fa-solid fa-calendar-day" aria-hidden="true"></i> ' + esc(thDate(d.date)) + (d.date === d.today ? ' (วันนี้)' : '') + '</span>' +
            '<span class="rate-summary-chip ok"><i class="fa-solid fa-file-lines" aria-hidden="true"></i> ' + total + ' ใบใน ' + d.rounds.length + ' รอบ</span>' +
            (wait ? '<span class="rate-summary-chip warn"><i class="fa-solid fa-hourglass-half" aria-hidden="true"></i> รออนุมัติ ' + wait + '</span>' : '') +
            ((d.overdue || []).length ? '<span class="rate-summary-chip warn"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i> เลยรอบ ' + d.overdue.length + '</span>' : '') +
            (d.later ? '<span class="ii-note">อีก ' + d.later + ' ใบไปรอบวันถัดไป</span>' : '') +
        '</div>';

        var typeLbl = { RD: 'เบิกหลัก', OD: 'เบ็ดเตล็ด', BD: 'ยืม', TD: 'โอนย้ายข้ามไซต์' };   // TD 2026-09-29
        var docHtml = function (x, showRound) {
            var its = (x.items || []).map(function (it) { return esc(it.name || it.matCode) + ' <b>' + num(it.qty) + (it.unit ? ' ' + esc(it.unit) : '') + '</b>'; }).join(' · ');
            return '<div class="dr-doc"><span class="id">' + esc(x.docId) + '</span>' +
                '<span class="dr-pill ' + (x.awaiting ? 'wait' : 'past') + '">' + esc(typeLbl[x.type] || x.type) + (x.awaiting ? ' · รออนุมัติ' : '') + '</span>' +
                '<span class="meta">เบิก ' + esc(thDateTime(x.requestedAt)) + (showRound ? ' · รอบ ' + esc(thDateTime(x.roundAt)) : '') +
                    ' · ' + esc((typeof window.userFullName === 'function' ? window.userFullName(x.reqName) : '') || x.reqName) + (x.gate ? ' · ' + esc(x.gate) : '') + '</span>' +
                (its ? '<span class="its">' + its + '</span>' : '') + '</div>';
        };
        var overdue = (d.overdue || []).length
            ? '<div class="dr-overdue"><div class="dr-sub-h"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i> เลยรอบจ่ายแล้วยังไม่ได้จ่าย ' + d.overdue.length + ' ใบ' +
                  ' <span class="dr-hint">(เก่าสุดก่อน — ใบรออนุมัตินานเกินรอบก็ขึ้นที่นี่)</span></div>' + d.overdue.map(function (x) { return docHtml(x, true); }).join('') + '</div>'
            : '';
        var STL = { open: 'เปิดรับ', closed: 'ปิดรับแล้ว · รอจ่าย', past: 'เลยเวลาจ่ายแล้ว', upcoming: 'ยังไม่ถึงวัน' };
        var firstOpen = -1;
        d.rounds.forEach(function (r, i) { if (firstOpen < 0 && (r.state === 'open' || r.state === 'closed' || r.state === 'upcoming')) firstOpen = i; });
        var rounds = d.rounds.map(function (r, i) {
            var win = r.from ? 'เบิก ' + r.from + '–' + drMinus(r.cutoff) : 'เบิกก่อน ' + r.cutoff;
            var open = r.docs.length > 0 && (i === firstOpen || r.state === 'closed');
            var items = r.items.length
                ? r.items.map(function (it) {
                    return '<div class="dr-item"><span>' + esc(it.name || it.matCode) + '<span class="c">' + esc(it.matCode) + (it.docs > 1 ? ' · ' + it.docs + ' ใบ' : '') + '</span></span>' +
                        '<b>' + num(it.qty) + (it.unit ? ' ' + esc(it.unit) : '') + '</b></div>';
                }).join('')
                : '<div class="dr-empty">' + (r.docs.length ? 'ทุกใบยังรออนุมัติ' : '—') + '</div>';
            return '<details class="dr-board-round"' + (open ? ' open' : '') + '>' +
                '<summary><span class="dr-bt">รอบ ' + esc(r.dispatch) + '</span><span class="dr-bw">' + esc(win) + '</span>' +
                    '<span class="dr-bs"><span class="dr-pill ' + r.state + '">' + (STL[r.state] || r.state) + '</span>' +
                    (r.docs.length ? '<span class="dr-pill count">' + r.docs.length + ' ใบ</span>' : '<span class="dr-pill past">ไม่มีใบ</span>') +
                    (r.awaiting ? '<span class="dr-pill wait">รออนุมัติ ' + r.awaiting + '</span>' : '') + '</span></summary>' +
                (r.docs.length ? '<div class="dr-board-body"><div><div class="dr-sub-h">ใบเบิกในรอบนี้</div>' + r.docs.map(function (x) { return docHtml(x, false); }).join('') + '</div>' +
                    '<div><div class="dr-sub-h"><i class="fa-solid fa-dolly" aria-hidden="true"></i> ของที่ต้องเตรียม <span class="dr-hint">(รวมใบที่อนุมัติแล้ว)</span></div>' + items + '</div></div>'
                               : '<div class="dr-board-body"><div class="dr-empty">ไม่มีใบเบิกเข้ารอบนี้</div></div>') +
            '</details>';
        }).join('');
        body.innerHTML = head + overdue + (rounds || '<div class="qr-empty"><span>วันนี้ไม่มีรอบจ่าย</span></div>') +
            '<div class="ii-note" style="margin-top:0.4rem;">จัดรอบจากเวลาที่ออกใบ · ใบที่จ่ายแล้ว/ยกเลิกไม่ขึ้น · ' +
            (d.workDays && d.workDays.length ? 'มีรอบวัน ' + d.workDays.map(function (x) { return ['', 'จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.', 'อา.'][x]; }).join(' ') : '') + '</div>';
    }
    function drMinus(t) {
        var p = t.split(':'), m = (+p[0]) * 60 + (+p[1]) - 1;
        if (m < 0) m = 0;
        return ('0' + Math.floor(m / 60)).slice(-2) + ':' + ('0' + (m % 60)).slice(-2);
    }

    // ------------------------------------------------------------------ Error log ของไซต์
    function erViewHtml() {
        var days = [[7, '7 วัน'], [30, '30 วัน'], [90, '90 วัน'], [0, 'ทั้งหมด']].map(function (d) {
            return '<button type="button" class="ii-chip' + (d[0] === S.er.days ? ' active' : '') + '" data-days="' + d[0] + '">' + d[1] + '</button>';
        }).join('');
        return '<div class="ii-mm-wrap">' +
            '<div class="rate-toolbar ii-mm-toolbar">' +
                '<div class="rate-site-box"><span class="rate-site-label"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> Site</span>' +
                    '<select id="iiErSiteSel" class="form-control"></select></div>' +
                '<div class="ii-chips" id="iiErDays">' + days + '</div>' +
                '<label class="ii-check"><input type="checkbox" id="iiErHideTest" checked> ซ่อนรายการทดสอบ</label>' +
                '<button type="button" class="btn btn-secondary" id="iiErCsv" style="width:auto;"><i class="fa-solid fa-file-csv" aria-hidden="true"></i> ส่งออก CSV</button>' +
            '</div>' +
            '<div id="iiErBody"><div class="qr-empty"><i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i><span>กำลังโหลด...</span></div></div>' +
        '</div>';
    }

    function erSite() { return S.er.site || dashSite() || ((me() && me().siteCode) || ''); }

    function loadErrors(opts) {
        swr('iiErrors', 'getSiteErrorLog', [erSite(), S.er.days], function (d) {
            var tab = document.getElementById('iiVtErrors');
            if (d && d.success === false && d.message === 'no_permission') {
                S.er.allowed = false;
                if (tab) tab.hidden = true;
                if (S.view === 'errors') setView('overview', true);
                return;
            }
            S.er.allowed = true;
            if (tab) tab.hidden = false;
            S.er.data = d;
            if (d && d.success && !S.er.site) S.er.site = d.siteCode;
            var badge = document.getElementById('iiErBadge');
            if (badge && d && d.success) { badge.hidden = !d.last7; badge.textContent = d.last7 || ''; }
            if (S.view === 'errors') renderErrors();
        }, function (e) {
            console.warn('getSiteErrorLog', e);
            var b = document.getElementById('iiErBody');
            if (b && S.view === 'errors') b.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><span>โหลด Error log ไม่สำเร็จ</span></div>';
        }, opts);
    }

    function thDateTime(s) {
        if (!s) return '';
        var p = String(s).split(' ');
        return thDate(p[0]) + (p[1] ? ' ' + p[1].slice(0, 5) : '');
    }
    function erCatMeta(d, key) {
        var list = (d && d.categories) || [];
        for (var i = 0; i < list.length; i++) { if (list[i].key === key) return list[i]; }
        return { key: key, label: key, advice: '' };
    }
    function erChip(d, key) {
        var c = ECAT[key] || ECAT.other, m = erCatMeta(d, key);
        return '<span class="ii-echip" style="--ec:' + c.color + '"><i class="fa-solid ' + c.icon + '" aria-hidden="true"></i> ' + esc(m.label) + '</span>';
    }
    function erVisibleRows() {
        var d = S.er.data;
        if (!d || !d.rows) return [];
        var q = ((document.getElementById('iiErSearch') || {}).value || '').trim().toLowerCase();
        return d.rows.filter(function (r) {
            if (S.er.hideTest && r.cat === 'test') return false;
            if (S.er.cat && r.cat !== S.er.cat) return false;
            if (S.er.gate && r.gate !== S.er.gate) return false;
            if (q && (r.message + ' ' + r.gate + ' ' + (r.detail.matName || '')).toLowerCase().indexOf(q) === -1) return false;
            return true;
        });
    }

    function renderErrors() {
        var body = document.getElementById('iiErBody');
        var d = S.er.data;
        if (!body) return;
        if (!d) { body.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i><span>กำลังโหลด...</span></div>'; return; }
        if (!d.success) { body.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><span>' + esc(d.message || 'โหลดไม่สำเร็จ') + '</span></div>'; return; }

        var sel = document.getElementById('iiErSiteSel');
        if (sel) {
            var sites = (d.sites && d.sites.length) ? d.sites : [{ code: d.siteCode, name: d.siteName }];
            sel.innerHTML = sites.map(function (s) {
                return '<option value="' + esc(s.code) + '"' + (s.code === d.siteCode ? ' selected' : '') + '>' +
                    esc(s.code + (s.name ? ' · ' + s.name : '') + (s.count != null ? ' (' + s.count + ')' : '')) + '</option>';
            }).join('');
            sel.disabled = !(d.sites && d.sites.length > 1);
        }

        var testN = 0, real = 0, last = '';
        d.rows.forEach(function (r) {
            if (r.cat === 'test') testN++; else { real++; if (!last || r.at > last) last = r.at; }
        });
        var cats = d.categories.filter(function (c) { return c.count > 0 && !(S.er.hideTest && c.key === 'test'); });
        var head = '<div class="rate-summary">' +
            '<span><i class="fa-solid fa-location-dot" style="color:var(--primary);" aria-hidden="true"></i> <b>' + esc(d.siteCode) + '</b>' + (d.siteName ? ' · ' + esc(d.siteName) : '') + '</span>' +
            '<span class="rate-summary-chip' + (real ? ' warn' : ' ok') + '"><i class="fa-solid fa-bug" aria-hidden="true"></i> ' + real + ' รายการ' + (d.days ? ' ใน ' + d.days + ' วัน' : '') + '</span>' +
            '<span class="rate-summary-chip"><i class="fa-solid fa-calendar-week" aria-hidden="true"></i> 7 วันล่าสุด ' + (d.last7 || 0) + '</span>' +
            (testN ? '<span class="rate-summary-chip">' + (S.er.hideTest ? 'ซ่อน' : '') + 'ทดสอบ ' + testN + '</span>' : '') +
            (last ? '<span class="ii-note">ล่าสุด ' + esc(thDateTime(last)) + '</span>' : '') +
            (d.truncated ? '<span class="ii-note">แสดง 3,000 รายการล่าสุด</span>' : '') +
        '</div>';

        if (!cats.length) {
            body.innerHTML = head + '<div class="qr-empty"><i class="fa-solid fa-circle-check" aria-hidden="true" style="color:#16a34a;"></i><span>ไม่มี error ของไซต์นี้ในช่วงที่เลือก</span></div>';
            if (S.er.chart) { try { S.er.chart.destroy(); } catch (e) {} S.er.chart = null; }
            return;
        }
        var grid = '<div class="ii-ecats">' + cats.map(function (c) {
            var m = ECAT[c.key] || ECAT.other;
            var tops = (c.top || []).map(function (t) {
                return '<span class="ii-etop">' + esc(t.value) + ' <b>×' + t.count + '</b></span>';
            }).join('');
            return '<button type="button" class="ii-ecat' + (S.er.cat === c.key ? ' active' : '') + '" data-cat="' + esc(c.key) + '" style="--ec:' + m.color + '">' +
                '<span class="ii-ecat-head"><i class="fa-solid ' + m.icon + '" aria-hidden="true"></i><span class="lbl">' + esc(c.label) + '</span>' +
                    '<span class="cnt">' + c.count + '</span></span>' +
                '<span class="ii-ecat-last">ล่าสุด ' + esc(thDateTime(c.lastAt)) + (c.distinct > 1 ? ' · ' + c.distinct + ' ' + (c.key === 'card' ? 'บัตร' : 'ราย') : '') + '</span>' +
                (tops ? '<span class="ii-etops">' + tops + '</span>' : '') +
                '<span class="ii-ecat-advice">' + esc(c.advice) + '</span>' +
            '</button>';
        }).join('') + '</div>';

        var catOpts = '<option value="">ทุกประเภท</option>' + cats.map(function (c) {
            return '<option value="' + esc(c.key) + '"' + (S.er.cat === c.key ? ' selected' : '') + '>' + esc(c.label) + ' (' + c.count + ')</option>';
        }).join('');
        var gateOpts = '<option value="">ทุกประตู</option>' + (d.gates || []).map(function (g) {
            return '<option value="' + esc(g) + '"' + (S.er.gate === g ? ' selected' : '') + '>' + esc(g) + '</option>';
        }).join('');
        var q = ((document.getElementById('iiErSearch') || {}).value || '');

        body.innerHTML = head + grid +
            '<div class="ii-card ii-er-chartcard"><div class="ii-head"><h3><i class="fa-solid fa-chart-column" aria-hidden="true"></i> จำนวน error รายวัน</h3>' +
                '<span class="ii-note">แยกสีตามประเภท · แตะการ์ดด้านบนเพื่อกรอง</span></div>' +
                '<div class="ii-chart" style="height:200px;"><canvas id="iiErChart" role="img" aria-label="กราฟ error รายวัน"></canvas></div></div>' +
            '<div class="rate-toolbar ii-mm-toolbar ii-er-listbar">' +
                '<div class="rate-search-box"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>' +
                    '<input type="text" id="iiErSearch" class="form-control" placeholder="ค้นหา รหัสบัตร / ชุดหยิบ / ข้อความ..." value="' + esc(q) + '"></div>' +
                '<select id="iiErCat" class="form-control" style="width:auto;" aria-label="ประเภท">' + catOpts + '</select>' +
                '<select id="iiErGate" class="form-control" style="width:auto;" aria-label="ประตู">' + gateOpts + '</select>' +
            '</div>' +
            '<div id="iiErRows"></div>';
        renderErrChart();
        renderErrRows();
    }

    function renderErrChart() {
        var d = S.er.data;
        var cv = document.getElementById('iiErChart');
        if (!cv || !d || typeof window.Chart !== 'function') return;
        if (S.er.chart) { try { S.er.chart.destroy(); } catch (e) {} S.er.chart = null; }
        var days = d.daily.filter(function (x) {
            return Object.keys(x.counts).some(function (k) { return !(S.er.hideTest && k === 'test'); });
        });
        var keys = d.categories.filter(function (c) { return c.count > 0 && !(S.er.hideTest && c.key === 'test'); }).map(function (c) { return c.key; });
        S.er.chart = new window.Chart(cv.getContext('2d'), {
            type: 'bar',
            data: {
                labels: days.map(function (x) { return thDate(x.date); }),
                datasets: keys.map(function (k) {
                    return { label: erCatMeta(d, k).label, backgroundColor: (ECAT[k] || ECAT.other).color, borderRadius: 3, maxBarThickness: 26,
                             data: days.map(function (x) { return x.counts[k] || 0; }) };
                })
            },
            options: {
                responsive: true, maintainAspectRatio: false, animation: { duration: 200 },
                scales: { x: { stacked: true, grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true } },
                          y: { stacked: true, beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#eef2f7' } } },
                plugins: { legend: { display: false } }
            }
        });
    }

    function renderErrRows() {
        var box = document.getElementById('iiErRows');
        var d = S.er.data;
        if (!box || !d) return;
        var rows = erVisibleRows();
        if (!rows.length) { box.innerHTML = '<div class="rate-empty-search"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> ไม่พบรายการตามเงื่อนไข</div>'; return; }
        var shown = rows.slice(0, S.er.limit);
        var det = function (r) {
            var x = r.detail || {}, a = [];
            if (x.card)    a.push('<span class="ii-etag"><i class="fa-solid fa-id-card"></i> ' + esc(x.card) + '</span>');
            if (x.picking) a.push('<span class="ii-etag"><i class="fa-solid fa-layer-group"></i> ' + esc(x.picking) + '</span>');
            if (x.holder)  a.push('<span class="ii-etag"><i class="fa-solid fa-user"></i> ' + esc(x.holder) + '</span>');
            if (x.matCode) a.push('<span class="ii-etag"><i class="fa-solid fa-box"></i> ' + esc(x.matCode + ' ' + (x.matName || '')) + '</span>');
            if (x.doc)     a.push('<span class="ii-etag"><i class="fa-solid fa-file-lines"></i> ' + esc(x.doc) + '</span>');   // 2026-09-30
            if (x.by)      a.push('<span class="ii-etag"><i class="fa-solid fa-user-pen"></i> ' + esc(x.by) + '</span>');
            return a.join('');
        };
        box.innerHTML = '<div class="rate-table-wrap ii-er-tw"><table class="rate-table ii-er-table">' +
            '<thead><tr><th>เวลา</th><th>ประตู</th><th>ประเภท</th><th>รายละเอียด</th></tr></thead><tbody>' +
            shown.map(function (r) {
                return '<tr class="rate-row">' +
                    '<td class="ii-er-time">' + esc(thDateTime(r.at)) + '</td>' +
                    '<td class="ii-er-gate">' + esc(r.gate || '—') + '</td>' +
                    '<td class="ii-er-cat">' + erChip(d, r.cat) + '</td>' +
                    '<td class="ii-er-msg">' + esc(r.message) + (det(r) ? '<div class="ii-etags">' + det(r) + '</div>' : '') + '</td>' +
                '</tr>';
            }).join('') + '</tbody></table></div>' +
            '<div class="ii-er-foot"><span class="ii-note">แสดง ' + shown.length.toLocaleString() + ' จาก ' + rows.length.toLocaleString() + ' รายการ</span>' +
            (rows.length > shown.length ? '<button type="button" class="ii-link-btn" id="iiErMore">แสดงเพิ่ม</button>' : '') + '</div>';
    }

    function setErrCat(k) {
        S.er.cat = k || '';
        S.er.limit = 200;
        $all('.ii-ecat').forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-cat') === S.er.cat); });
        var sel = document.getElementById('iiErCat');
        if (sel) sel.value = S.er.cat;
        renderErrRows();
    }

    function exportErrorsCsv() {
        var d = S.er.data;
        if (!d || !d.success) return;
        var rows = erVisibleRows();
        var q = function (v) { v = v == null ? '' : String(v); return /[",\r\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; };
        var lines = [['เวลา', 'ไซต์', 'ประตู', 'ประเภท', 'ข้อความ', 'บัตร', 'ชุดหยิบ', 'ผู้ถือบัตร', 'วัสดุ'].join(',')];
        rows.forEach(function (r) {
            var x = r.detail || {};
            lines.push([r.at, d.siteCode, r.gate, erCatMeta(d, r.cat).label, r.message, x.card || '', x.picking || '', x.holder || '',
                        x.matCode ? x.matCode + ' ' + (x.matName || '') : ''].map(q).join(','));
        });
        var now = new Date();
        var fname = 'connext-errors-' + d.siteCode + '-' + now.getFullYear() + ('0' + (now.getMonth() + 1)).slice(-2) + ('0' + now.getDate()).slice(-2) + '.csv';
        try {
            var blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url; a.download = fname; a.style.display = 'none';
            document.body.appendChild(a); a.click();
            setTimeout(function () { try { document.body.removeChild(a); URL.revokeObjectURL(url); } catch (e) {} }, 300);
            toast('ส่งออก ' + rows.length + ' รายการแล้ว', 'success');
        } catch (e) {
            toast('ส่งออกไม่สำเร็จบนอุปกรณ์นี้', 'danger');
        }
    }

    // ------------------------------------------------------------------ ต่อกับ Dashboard เดิม
    function patchDashboard() {
        // "ใกล้หมด" — ตั้ง Min ไว้: พร้อมเบิก ≤ Min (ยังมีของ) · ไม่ได้ตั้ง: เกณฑ์เดิมของระบบ
        var origLow = window.isLowStockRow;
        if (typeof origLow === 'function' && !origLow.__iiWrapped) {
            var low = function (row) {
                var s = settingFor(row);
                if (s && s.min != null) {
                    var bal = typeof window.getRowBalance === 'function' ? window.getRowBalance(row) : (parseFloat(row.OnHand) || 0);
                    var avail = bal - (parseFloat(row.Pending) || 0);
                    return bal > 0 && (s.min > 0 ? avail <= s.min : avail < 0);
                }
                return origLow(row);
            };
            low.__iiWrapped = true;
            window.isLowStockRow = low;
        }

        // ป้ายสถานะในตารางคงคลัง
        after('renderDashboardTable', function () {
            if (!Object.keys(S.setMap).length) return;
            $all('#inventoryTableBody tr[data-matcode]').forEach(function (tr) {
                var s = S.setMap[(tr.getAttribute('data-site') || '') + '|' + tr.getAttribute('data-matcode')];
                if (!s || (s.min == null && s.max == null)) return;
                var row = null, data = window.dashboardData || [];
                for (var i = 0; i < data.length; i++) {
                    if (data[i].MatCode === tr.getAttribute('data-matcode') && (data[i].SiteCode || '') === (tr.getAttribute('data-site') || '')) { row = data[i]; break; }
                }
                if (!row) return;
                var bal = typeof window.getRowBalance === 'function' ? window.getRowBalance(row) : (parseFloat(row.OnHand) || 0);
                if (bal <= 0) return;    // หมดสต๊อก — ป้ายเดิม
                var st = statusOf({ available: bal - (parseFloat(row.Pending) || 0), onHand: bal }, s.min, s.max);
                var cell = tr.lastElementChild;
                if (!cell) return;
                var label = st === 'low' ? 'ต่ำกว่า Min' : st === 'over' ? 'เกิน Max' : 'เพียงพอ';
                cell.innerHTML = '<span class="ii-badge ' + st + '">' + label + '</span>' +
                    '<span class="ii-badge-sub">Min ' + (s.min == null ? '—' : num(s.min)) + ' · Max ' + (s.max == null ? '—' : num(s.max)) + '</span>';
            });
        });

        // หน้ารายละเอียดวัสดุ — กล่อง Min-Max + แบนเนอร์ตาม Min
        after('openMaterialDetail', function (matCode, siteCode) { mdAugment(matCode, siteCode); });

        // โหลดของเราทุกครั้งที่ Dashboard โหลด/รีเฟรช
        after('loadDashboard', function (opts) { loadAll(opts); });
    }

    function mdAugment(matCode, siteCode) {
        var modal = document.querySelector('#mdModalBackdrop .md-modal');
        if (!modal) return;
        var old = document.getElementById('iiMdBox');
        if (old) old.parentNode.removeChild(old);
        var data = window.dashboardData || [], row = null;
        for (var i = 0; i < data.length; i++) {
            if (data[i].MatCode === matCode && (!siteCode || data[i].SiteCode === siteCode)) { row = data[i]; break; }
        }
        if (!row) return;
        var s = settingFor(row);
        var it = (S.mm.data && S.mm.data.success && S.mm.data.siteCode === row.SiteCode) ? itemByCode(matCode) : null;
        if (!s && !(it && it.sugMin != null)) return;

        var bal = typeof window.getRowBalance === 'function' ? window.getRowBalance(row) : (parseFloat(row.OnHand) || 0);
        var avail = bal - (parseFloat(row.Pending) || 0);
        var unit = row.Unit || '';
        var info = { available: avail, onHand: bal, adu: it ? it.adu : null };
        var html;
        if (s) {
            var st = statusOf(info, s.min, s.max);
            var q = st === 'low' ? orderQty(info, s.min, s.max) : 0;
            html = '<div class="ii-md-grid"><div><div class="l">Min</div><div class="v">' + (s.min == null ? '—' : num(s.min)) + '</div></div>' +
                '<div><div class="l">Max</div><div class="v">' + (s.max == null ? '—' : num(s.max)) + '</div></div>' +
                '<div><div class="l">พร้อมเบิก</div><div class="v">' + num(avail) + '</div></div></div>' +
                '<div class="ii-md-note">สถานะ: <span class="ii-pill ' + st + '">' + STATUS[st].label + '</span>' +
                (q ? ' · <b>ควรสั่งเติม ' + num(q) + ' ' + esc(unit) + '</b> (ให้ถึง Max)' : '') +
                (it && it.adu ? ' · ใช้เฉลี่ย ' + num(it.adu, 2) + ' ' + esc(unit) + '/วัน · พอใช้อีกราว ' + num(it.daysCover, 0) + ' วัน' : '') + '</div>';
            // แบนเนอร์บนสุดให้ตรงกับ Min ที่ตั้ง (หมดสต๊อกคงของเดิม)
            if (bal > 0) {
                var banner = document.getElementById('mdStatusBanner'), title = document.getElementById('mdStatusTitle'), sub = document.getElementById('mdStatusSub');
                if (banner && title && sub) {
                    var icon = banner.querySelector('i');
                    banner.classList.remove('md-banner-ok', 'md-banner-warn', 'md-banner-out');
                    if (st === 'low') {
                        banner.classList.add('md-banner-warn');
                        title.textContent = 'ถึงจุดสั่งซื้อ (ต่ำกว่า Min)';
                        sub.textContent = 'พร้อมเบิก ' + num(avail) + ' ' + unit + ' · Min ' + num(s.min) + (q ? ' — ควรสั่งเติม ' + num(q) + ' ' + unit : '');
                        if (icon) icon.className = 'fa-solid fa-arrow-trend-down';
                    } else {
                        banner.classList.add('md-banner-ok');
                        title.textContent = st === 'over' ? 'เกิน Max' : 'เพียงพอ';
                        sub.textContent = st === 'over' ? 'คงเหลือ ' + num(bal) + ' ' + unit + ' มากกว่า Max ' + num(s.max) : 'พร้อมเบิกสูงกว่า Min ที่ตั้งไว้';
                        if (icon) icon.className = st === 'over' ? 'fa-solid fa-arrow-trend-up' : 'fa-solid fa-circle-check';
                    }
                }
            }
        } else {
            html = '<div class="ii-md-note" style="margin-top:0;">ยังไม่ได้ตั้ง Min-Max · ค่าแนะนำจากการเบิกจริง: <b>Min ' + num(it.sugMin) + ' – Max ' + num(it.sugMax) + '</b> ' + esc(unit) +
                (it.adu ? ' (ใช้เฉลี่ย ' + num(it.adu, 2) + '/วัน)' : '') + ' — ตั้งได้ที่แท็บ "ตั้ง Min-Max"</div>';
        }
        var box = el('div', 'ii-md-box', html);
        box.id = 'iiMdBox';
        var title2 = el('div', 'md-section-title', 'Min-Max stock');
        title2.id = 'iiMdTitle';
        var grid = modal.querySelector('.md-stock-grid');
        var oldT = document.getElementById('iiMdTitle');
        if (oldT) oldT.parentNode.removeChild(oldT);
        if (grid && grid.parentNode) {
            grid.parentNode.insertBefore(box, grid.nextSibling);
            grid.parentNode.insertBefore(title2, box);
        }
    }

    function openDetail(matCode, site) {
        if (typeof window.openMaterialDetail !== 'function') return;
        var data = window.dashboardData || [];
        for (var i = 0; i < data.length; i++) {
            if (data[i].MatCode === matCode && (!site || data[i].SiteCode === site)) { window.openMaterialDetail(matCode, data[i].SiteCode || ''); return; }
        }
        toast('ไม่พบ ' + matCode + ' ในรายการคงคลังของไซต์ที่แสดงอยู่', 'info');
    }

    function loadAll(opts) {
        if (!S.built || !window.user) return;
        loadPopular(opts);
        // ตารางกรอกตามไซต์ที่ Dashboard เลือก — เว้นแต่มีค่าที่พิมพ์ค้างอยู่ (ไม่เปลี่ยนไซต์ใต้มือคนกรอก)
        var ds = dashSite();
        if (ds && ds !== S.mm.site && !dirtyCount()) { S.mm.site = ds; S.mm.data = null; }
        loadMinMax(opts);
        loadSettings(opts);
        if (ds && ds !== S.er.site) { S.er.site = ds; S.er.data = null; }
        loadErrors(opts);          // ได้ทั้งสิทธิ์ดู (โชว์/ซ่อนแท็บ) และตัวเลข 7 วันบนแท็บ
        if (ds && ds !== S.dr.site) { S.dr.site = ds; S.dr.data = null; }
        loadBoard(opts);           // สิทธิ์ดูกระดานรอบจ่าย + ตัวเลขใบค้างรอบบนแท็บ
    }

    function init() {
        if (!build()) return;
        patchDashboard();
        // Dashboard อาจโหลดไปแล้วก่อนไฟล์นี้ทำงาน — โหลดตามถ้าเปิดอยู่
        var page = document.getElementById('dashboard-page');
        if (page && page.classList.contains('active-page') && window.user) loadAll();
        else if ('MutationObserver' in window && page) {
            var mo = new MutationObserver(function () {
                if (page.classList.contains('active-page') && window.user) { mo.disconnect(); loadAll(); }
            });
            mo.observe(page, { attributes: true, attributeFilter: ['class'] });
        }
        window.InventoryInsights = {
            reload: function () { loadAll({ force: true }); },
            state: S,
            // ไว้ตรวจหน้าจอด้วยข้อมูลที่เตรียมเอง (ไม่เรียก server)
            _render: function (pop, mm, er, dr) {
                if (dr) {
                    S.dr.data = dr; S.dr.site = dr.siteCode; S.dr.allowed = true;
                    var t = document.getElementById('iiVtDispatch'); if (t) t.hidden = false;
                    var bd = document.getElementById('iiDrBadge'); if (bd) { bd.hidden = !(dr.overdue || []).length; bd.textContent = (dr.overdue || []).length || ''; }
                    if (S.view === 'dispatch') renderBoard();
                }
                if (pop) renderPopular(pop);
                if (mm) { S.mm.data = mm; S.mm.site = mm.siteCode; fillParams(mm.params, mm.window); renderCard(); if (S.view === 'minmax') renderTable(); }
                if (er) {
                    S.er.data = er; S.er.site = er.siteCode; S.er.allowed = true;
                    var tab = document.getElementById('iiVtErrors'); if (tab) tab.hidden = false;
                    var b = document.getElementById('iiErBadge'); if (b) { b.hidden = !er.last7; b.textContent = er.last7 || ''; }
                    if (S.view === 'errors') renderErrors();
                }
            }
        };
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
