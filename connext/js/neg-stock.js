/*
 * CONNEXT — js/neg-stock.js : ยอดติดลบ / ยอดไม่พอ = สีแดง + คำเตือน (GP-42 / OP-74 · 2 ต.ค. 2026)
 *
 *   server เลิกปัดยอดติดลบเป็น 0 แล้ว (lib/stock.php) — หน้าจอต้องเห็นยอดจริงจนกว่าจะมีคนแก้ (ใบนับ SC / รับเข้า IN)
 *   - Dashboard: ช่อง Balance ติดลบ = ตัวแดง + ป้าย "ติดลบ" · ชิปประตูที่ยอดติดลบขึ้นด้วย (เดิมถูกกรองทิ้ง) เป็นสีแดง
 *   - หน้ารายละเอียดวัสดุ: แถบสถานะ "ยอดติดลบ" · ยอดรายประตูที่ติดลบเป็นสีแดง
 *   - ฟอร์มเบิก / เบ็ดเตล็ด / ยืม: ประตูที่ยอดไม่พอ/ติดลบบอกชัด + คำเตือนสีแดงใต้ช่องประตู
 *   (หน้าถ่ายรูปยืนยันเตือนตอนหยิบอยู่ใน js/scenario05.js)
 */
(function () {
    'use strict';

    function num(v) {
        var n = parseFloat(String(v == null ? '' : v).replace(/[,\s]/g, '').replace(/−/g, '-'));
        return isFinite(n) ? n : 0;
    }
    function fmt(v) { return typeof window.formatBalanceValue === 'function' ? window.formatBalanceValue(v) : String(v); }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function wrap(name, after) {
        var orig = window[name];
        if (typeof orig !== 'function' || orig.__nsWrapped) return;
        var w = function () {
            var r = orig.apply(this, arguments);
            try {
                var r2 = after.call(this, r, arguments);
                if (r2 !== undefined) return r2;
            } catch (e) { console.warn('[neg-stock] ' + name + ':', e); }
            return r;
        };
        w.__nsWrapped = true;
        window[name] = w;
    }

    // ------------------------------------------------------------------ Dashboard
    function patchDashboard() {
        // ชิปประตู: เดิมแสดงเฉพาะประตูที่มีของ (> 0) หรือมียอดจอง — ประตูที่ติดลบหายไปจากจอ
        if (typeof window._dashGatesWithStock === 'function' && !window._dashGatesWithStock.__nsWrapped) {
            var g = function (row) {
                return ((row && row['Gates']) || []).filter(function (x) {
                    return Math.abs(num(x.OnHand)) > 0.0005 || num(x.Pending) > 0;
                });
            };
            g.__nsWrapped = true;
            window._dashGatesWithStock = g;
        }
        wrap('_dashGateCellHtml', function (html) {
            if (typeof html !== 'string') return undefined;
            return html.replace(/<span class="dash-gate-chip( is-empty)?"([^>]*)><b>([^<]*)<\/b>(-[0-9.,]+)<\/span>/g,
                '<span class="dash-gate-chip is-neg"$2><b>$3</b>$4</span>');
        });
        wrap('renderDashboardTable', function () {
            Array.prototype.forEach.call(document.querySelectorAll('#inventoryTableBody tr[data-matcode]'), function (tr) {
                var bal = tr.querySelector('td[data-label="Balance"]');
                if (!bal || num(bal.textContent) >= 0) return;
                bal.classList.add('cnx-neg');
                var st = tr.querySelector('td[data-label="สถานะ"]');
                if (st) {
                    st.innerHTML = '<span class="cnx-neg-badge" title="ยอดติดลบ — ตัดหรือจองเกินของที่มี · แก้ด้วยใบนับสต๊อก (SC) หรือรับเข้า (IN)">ติดลบ</span>';
                }
            });
        });
    }

    // ------------------------------------------------------------------ หน้ารายละเอียดวัสดุ
    function patchDetail() {
        wrap('openMaterialDetail', function () {
            var balEl = document.getElementById('mdValBalance');
            if (!balEl) return;
            var v = num(balEl.textContent);
            balEl.classList.toggle('cnx-neg', v < 0);
            if (v >= 0) return;
            var title = document.getElementById('mdStatusTitle');
            var sub = document.getElementById('mdStatusSub');
            if (title) title.textContent = 'ยอดติดลบ';
            if (sub) sub.textContent = 'ระบบตัดหรือจองเกินของที่มี ' + fmt(-v) + ' — แก้ด้วยใบนับสต๊อก (SC) หรือรับเข้า (IN) · แสดงสีแดงจนกว่าจะมีคนแก้';
        });
        wrap('renderMdGateBreakdown', function () {
            Array.prototype.forEach.call(document.querySelectorAll('#mdGateBox .md-gate-row'), function (r) {
                var n = r.querySelector('.g-num');
                if (n && num(n.textContent) < 0) r.classList.add('is-neg');
            });
        });
    }

    // ------------------------------------------------------------------ ฟอร์มเบิก (ช่องประตู)
    function gateWarnings(matCode, onlyGate) {
        var rows = typeof window.getGateRows === 'function' ? (window.getGateRows(matCode) || []) : [];
        var out = [];
        rows.forEach(function (r) {
            var gid = String(r.GateID || '');
            if (onlyGate && gid !== onlyGate) return;
            var on = num(r.OnHand), pen = num(r.Pending);
            if (on < -0.0005) {
                out.push('<b>' + esc(gid) + '</b> ยอดติดลบ <b class="cnx-neg">' + esc(fmt(on)) + '</b> (ตัดเกินของที่มี) — แจ้งสายสโตร์ตรวจนับ');
            } else if (on - pen < -0.0005) {
                out.push('<b>' + esc(gid) + '</b> ยอดไม่พอ: ในคลัง ' + esc(fmt(on)) + ' · จองไว้ ' + esc(fmt(pen)) +
                         ' → ขาด <b class="cnx-neg">' + esc(fmt(pen - on)) + '</b> (ใบที่อนุมัติแล้วจองเกินของที่มี)');
            }
        });
        return out;
    }
    function addWarn(hint, lines) {
        if (!hint || !lines.length) return;
        var box = document.createElement('div');
        box.className = 'cnx-stock-warn';
        box.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i><div>' + lines.join('<br>') + '</div>';
        hint.appendChild(box);
    }
    function matOf(formKey) {
        var sel = document.getElementById(formKey === 'odds' ? 'oddsMaterialSelect' : formKey === 'borrow' ? 'borrowMaterialSelect' : 'reqMaterialSelect');
        return sel ? (sel.value || '') : '';
    }
    function patchForms() {
        wrap('refreshGateSelectForForm', function (r, args) {
            var formKey = args[0];
            if (formKey === 'inbound') return;
            var sel = document.getElementById(formKey + 'GateSelect');
            if (!sel) return;
            Array.prototype.forEach.call(sel.options, function (o) {
                if (o.value && /คงเหลือ -/.test(o.textContent)) o.textContent = o.textContent.replace('(หมด)', '(ยอดไม่พอ)');
            });
            // ยังไม่เลือกประตู → เตือนทุกประตูของวัสดุนี้ (เลือกประตูแล้ว renderGateHint เตือนเฉพาะประตูนั้น)
            if (!sel.value) addWarn(document.getElementById(formKey + 'GateHint'), gateWarnings(matOf(formKey), ''));
        });
        wrap('renderGateHint', function (r, args) {
            var formKey = args[0], matCode = args[1];
            if (formKey === 'inbound' || !matCode) return;
            var sel = document.getElementById(formKey + 'GateSelect');
            if (!sel || !sel.value) return;
            addWarn(document.getElementById(formKey + 'GateHint'), gateWarnings(matCode, sel.value));
        });
    }

    function boot() {
        try { patchDashboard(); } catch (e) { console.warn('[neg-stock] dashboard', e); }
        try { patchDetail(); } catch (e) { console.warn('[neg-stock] detail', e); }
        try { patchForms(); } catch (e) { console.warn('[neg-stock] forms', e); }
        window.cnxNegStock = { gateWarnings: gateWarnings };
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
