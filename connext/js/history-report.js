/**
 * CONNEXT — js/history-report.js : หน้า "ประวัติเอกสาร" → กรองแล้ว Export รายงานพร้อมรูป (2026-09-30)
 *
 * แทนปุ่ม PDF ทีละใบ: ตัวกรองเดิม (ประเภท · สถานะ · ค้นหา) + ใหม่ (ช่วงวันที่ · รหัส IC · ผู้นำจ่าย)
 * ตาราง: แทน filterHistoryTable เดิมของ index.php — เพิ่มคอลัมน์ "ผู้นำจ่าย" + ช่องติ๊ก "เลือก"
 *   ทุกใบที่ผ่านตัวกรองถูกเลือกไว้ก่อน (เอาติ๊กออกเฉพาะใบที่ไม่ต้องการ) · หัวคอลัมน์ติ๊ก/เอาออกทั้งหน้า
 * Export → POST api/history_report.php (ไฟล์ PDF ตรง) — ตัวกรอง IC ทำให้รายงานแสดงเฉพาะรายการ/รูปของรหัสนั้น
 * ข้อมูลแถวจาก getRequisitionHistory (+ mats / payer / photos / photoLinks — lib/history_stats.php 2026-09-30)
 */
(function () {
    'use strict';
    var MAX_DOCS = 100;                       // ตรงกับ HR_MAX_DOCS ใน lib/history_report.php
    var H = { excluded: {}, rows: [], ui: false, busy: false };

    function $(id) { return document.getElementById(id); }
    function esc(v) {
        return (v === null || v === undefined ? '' : String(v))
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function ymd(ts) {
        var d = new Date(Number(ts) || 0);
        return isNaN(d.getTime()) || !ts ? '' : d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    }
    function val(id) { var el = $(id); return el ? String(el.value || '').trim() : ''; }
    function data() { return Array.isArray(window.historyData) ? window.historyData : []; }
    function matsOf(item) { return Array.isArray(item.mats) ? item.mats : []; }
    function norm(s) { return (typeof window.normalizeStatus === 'function') ? window.normalizeStatus(s) : String(s || ''); }

    // ------------------------------------------------------------------ UI
    function buildUi() {
        if (H.ui) return true;
        var page = $('history-page'), typeSel = $('historyFilterType'), head = $('historyHeadRow');
        if (!page || !typeSel || !head) return false;
        if (!typeSel.querySelector('option[value="TD"]')) {
            var o = document.createElement('option');
            o.value = 'TD'; o.textContent = 'เบิกโอนย้ายข้ามไซต์ (TD)';
            typeSel.appendChild(o);
        }
        var row1 = typeSel.closest('.form-group') ? typeSel.closest('.form-group').parentNode : null;
        if (!row1) return false;
        var bar = document.createElement('div');
        bar.id = 'hrFilters';
        bar.className = 'hr-filters';
        bar.innerHTML =
            '<div class="form-group hr-date"><label>วันที่ตั้งแต่</label><input type="date" id="hrFrom" class="form-control"></div>' +
            '<div class="form-group hr-date"><label>ถึง</label><input type="date" id="hrTo" class="form-control"></div>' +
            '<div class="form-group hr-ic"><label>รหัส IC</label>' +
                '<input type="text" id="hrIc" class="form-control" list="hrIcList" placeholder="พิมพ์หรือเลือกรหัส IC" autocomplete="off" spellcheck="false">' +
                '<datalist id="hrIcList"></datalist></div>' +
            '<div class="form-group hr-payer"><label>ผู้นำจ่าย</label><select id="hrPayer" class="form-control"><option value="">ทั้งหมด</option></select></div>' +
            '<button type="button" class="btn btn-secondary hr-clear" id="hrClear" title="ล้างตัวกรองทั้งหมด"><i class="fa-solid fa-filter-circle-xmark"></i> ล้างตัวกรอง</button>';
        row1.parentNode.insertBefore(bar, row1.nextSibling);
        var act = document.createElement('div');
        act.id = 'hrActions';
        act.className = 'hr-actions';
        act.innerHTML =
            '<div class="hr-count" id="hrCount"></div>' +
            '<button type="button" class="btn btn-primary hr-export" id="hrExport"><i class="fa-solid fa-file-pdf"></i> <span>Export รายงาน</span></button>';
        bar.parentNode.insertBefore(act, bar.nextSibling);

        // หัวตาราง: "ผู้นำจ่าย" ก่อนสถานะ · "รายงาน" → ช่องติ๊กเลือกทั้งหมด
        var ths = head.querySelectorAll('th');
        var stTh = head.querySelector('th[data-sort="status"]');
        if (stTh && !head.querySelector('th[data-sort="payer"]')) {
            var p = document.createElement('th');
            p.setAttribute('data-sort', 'payer');
            p.style.cssText = 'cursor:pointer; white-space:nowrap; user-select:none;';
            p.innerHTML = 'ผู้นำจ่าย <span class="sort-ind" style="color:var(--primary); font-size:0.8em;"></span>';
            p.onclick = function () { if (typeof window.sortHistory === 'function') window.sortHistory('payer'); };
            head.insertBefore(p, stTh);
        }
        var last = ths[ths.length - 1];
        if (last && !$('hrAll')) {
            last.className = 'hr-sel-th';
            last.innerHTML = '<label class="hr-all" title="เลือก / เอาออก ทุกใบที่แสดง"><input type="checkbox" id="hrAll"> เลือก</label>';
        }
        var sub = page.querySelector('.page-subtitle');
        if (sub) sub.textContent = 'ดูประวัติการเบิก-จ่าย ยืม-คืน · กรอง IC / วันที่ / ผู้นำจ่าย แล้วติ๊กเลือกใบ → Export รายงาน PDF พร้อมรูปการเบิกจ่าย';

        ['hrFrom', 'hrTo', 'hrPayer'].forEach(function (id) { $(id).addEventListener('change', window.filterHistoryTable); });
        $('hrIc').addEventListener('input', debounce(window.filterHistoryTable, 200));
        $('hrClear').addEventListener('click', clearFilters);
        $('hrExport').addEventListener('click', exportReport);
        $('hrAll').addEventListener('change', function () {
            var on = this.checked;
            H.rows.forEach(function (it) { if (on) delete H.excluded[it.docId]; else H.excluded[it.docId] = true; });
            window.filterHistoryTable();
        });
        $('historyTableBody').addEventListener('change', function (e) {
            var t = e.target;
            if (!t || !t.classList || !t.classList.contains('hr-sel')) return;
            var id = t.getAttribute('data-doc');
            if (t.checked) delete H.excluded[id]; else H.excluded[id] = true;
            var tr = t.closest('tr');
            if (tr) tr.classList.toggle('hr-off', !t.checked);
            updateCount();
        });
        H.ui = true;
        return true;
    }
    function debounce(fn, ms) {
        var t = null;
        return function () { clearTimeout(t); t = setTimeout(fn, ms); };
    }
    function clearFilters() {
        ['historyFilterType', 'historyFilterStatus', 'historyFilterSearch', 'hrFrom', 'hrTo', 'hrIc', 'hrPayer'].forEach(function (id) {
            var el = $(id); if (el) el.value = '';
        });
        H.excluded = {};
        window.filterHistoryTable();
    }
    // ตัวเลือก รหัส IC / ผู้นำจ่าย จากข้อมูลที่โหลด (เก็บค่าที่เลือกไว้)
    function refreshOptions() {
        var codes = {}, payers = {};
        data().forEach(function (it) {
            matsOf(it).forEach(function (m) { if (m && m[0] && !codes[m[0]]) codes[m[0]] = m[1] || ''; });
            if (it.payer) payers[it.payer] = (payers[it.payer] || 0) + 1;
        });
        var dl = $('hrIcList');
        if (dl) {
            var keys = Object.keys(codes).sort();
            if (dl.getAttribute('data-n') !== String(keys.length)) {
                dl.innerHTML = keys.map(function (c) { return '<option value="' + esc(c) + '">' + esc(codes[c]) + '</option>'; }).join('');
                dl.setAttribute('data-n', String(keys.length));
            }
        }
        var sel = $('hrPayer');
        if (sel) {
            var cur = sel.value;
            var names = Object.keys(payers).sort(function (a, b) { return a.localeCompare(b, 'th'); });
            var sig = names.join('|');
            if (sel.getAttribute('data-sig') !== sig) {
                sel.innerHTML = '<option value="">ทั้งหมด</option><option value="__none__">(ยังไม่มีผู้นำจ่าย)</option>' +
                    names.map(function (n) { return '<option value="' + esc(n) + '">' + esc(n) + ' (' + payers[n] + ')</option>'; }).join('');
                sel.setAttribute('data-sig', sig);
                sel.value = cur;
                if (sel.value !== cur) sel.value = '';
            }
        }
    }

    // ------------------------------------------------------------------ ตาราง (แทน filterHistoryTable เดิม)
    function currentFilters() {
        return {
            type: val('historyFilterType'), status: val('historyFilterStatus'), q: val('historyFilterSearch').toLowerCase(),
            from: val('hrFrom'), to: val('hrTo'), ic: val('hrIc').toUpperCase(), payer: $('hrPayer') ? $('hrPayer').value : ''
        };
    }
    function matches(item, f) {
        if (f.type && item.type !== f.type) return false;
        if (f.status && norm(item.status) !== f.status) return false;
        if (f.from || f.to) {
            var d = ymd(item.timestamp);
            if (!d || (f.from && d < f.from) || (f.to && d > f.to)) return false;
        }
        if (f.ic && !matsOf(item).some(function (m) { return String(m[0] || '').toUpperCase().indexOf(f.ic) !== -1; })) return false;
        if (f.payer === '__none__' ? !!item.payer : (f.payer && item.payer !== f.payer)) return false;
        if (f.q) {
            var hay = (item.docId + ' ' + item.detail + ' ' + item.dateStr + ' ' + (item.rs || '') + ' ' + (item.payer || '') + ' ' +
                       matsOf(item).map(function (m) { return m[0]; }).join(' ')).toLowerCase();
            if (hay.indexOf(f.q) === -1) return false;
        }
        return true;
    }
    function photoCell(item) {
        var a = [];
        if (item.photos > 0) a.push('<span class="hr-ph" title="รูปการเบิกจ่ายในระบบ"><i class="fa-solid fa-camera"></i> ' + item.photos + '</span>');
        if (item.photoLinks > 0) a.push('<span class="hr-ph hr-ph-link" title="รูปจากระบบเดิม (Google Drive) — ในรายงานเป็นลิงก์"><i class="fa-brands fa-google-drive"></i> ' + item.photoLinks + '</span>');
        return a.join(' ');
    }
    function filterTable() {
        var tbody = $('historyTableBody');
        if (!tbody) return;
        buildUi();
        refreshOptions();
        var f = currentFilters();
        var isIn = f.type === 'IN';
        var rsHeader = $('historyRsHeader');
        if (rsHeader) rsHeader.style.display = isIn ? '' : 'none';
        var hs = window.historySort;
        if (hs && !isIn && hs.key === 'rs') { hs.key = 'timestamp'; hs.dir = 'desc'; }
        var colCount = ($('historyHeadRow') ? $('historyHeadRow').querySelectorAll('th').length : 8) - (isIn ? 0 : 1);

        var rows = data().filter(function (it) { return matches(it, f); });
        if (typeof window.sortHistoryRows === 'function') window.sortHistoryRows(rows);
        if (typeof window.updateHistorySortIndicators === 'function') window.updateHistorySortIndicators();
        H.rows = rows;
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="' + colCount + '" style="text-align:center;">ไม่พบรายการ</td></tr>';
            updateCount();
            return;
        }
        var icHi = f.ic;
        var tb = (typeof window.getTypeBadge === 'function') ? window.getTypeBadge : function (t) { return esc(t); };
        var sb = (typeof window.getStatusBadge === 'function') ? window.getStatusBadge : function (s) { return esc(s); };
        tbody.innerHTML = rows.map(function (it) {
            var on = !H.excluded[it.docId];
            var detail = esc(it.detail);
            if (icHi) {
                var hit = matsOf(it).filter(function (m) { return String(m[0] || '').toUpperCase().indexOf(icHi) !== -1; })
                    .map(function (m) { return esc(m[0]); });
                if (hit.length) detail += '<div class="hr-ichit"><i class="fa-solid fa-barcode"></i> ' + hit.join(', ') + '</div>';
            }
            return '<tr class="' + (on ? '' : 'hr-off') + '">' +
                (isIn ? '<td data-label="เลขที่ใบรับสินค้า" style="font-weight:600;">' + esc(it.rs || '-') + '</td>' : '') +
                '<td data-label="เลขที่เอกสาร" style="font-weight:600;">' + esc(it.docId) + '</td>' +
                '<td data-label="วันที่" style="color:var(--text-muted); font-size:0.88rem;">' + esc(it.dateStr) + '</td>' +
                '<td data-label="ประเภท">' + tb(it.type) + '</td>' +
                '<td data-label="รายละเอียด" style="font-size:0.88rem; color:var(--text-muted);">' + detail + '</td>' +
                '<td data-label="ผู้นำจ่าย" class="hr-payer-cell">' + (it.payer ? esc(it.payer) : '<span class="hr-muted">-</span>') +
                    (photoCell(it) ? '<div class="hr-phs">' + photoCell(it) + '</div>' : '') + '</td>' +
                '<td data-label="สถานะ">' + sb(it.status) + '</td>' +
                '<td data-label="เลือก" class="hr-sel-td"><input type="checkbox" class="hr-sel" data-doc="' + esc(it.docId) + '"' + (on ? ' checked' : '') +
                    ' aria-label="เลือก ' + esc(it.docId) + '"></td>' +
                '</tr>';
        }).join('');
        updateCount();
    }
    function selected() { return H.rows.filter(function (it) { return !H.excluded[it.docId]; }); }
    function updateCount() {
        var n = selected().length, total = H.rows.length;
        var el = $('hrCount'), btn = $('hrExport'), all = $('hrAll');
        if (el) {
            var ph = 0;
            selected().forEach(function (it) { ph += (it.photos || 0); });
            el.innerHTML = 'เลือก <b>' + n + '</b> ใบ จากที่กรอง ' + total + ' ใบ' + (n ? ' · รูปในระบบ ' + ph + ' รูป' : '') +
                (n > MAX_DOCS ? ' <span class="hr-warn">เกิน ' + MAX_DOCS + ' ใบต่อรายงาน — กรองให้แคบลงหรือเอาติ๊กออก</span>' : '');
        }
        if (btn) {
            btn.disabled = !n || n > MAX_DOCS || H.busy;
            btn.querySelector('span').textContent = n ? 'Export รายงาน (' + n + ' ใบ)' : 'Export รายงาน';
        }
        if (all) {
            all.checked = total > 0 && n === total;
            all.indeterminate = n > 0 && n < total;
        }
    }

    // ------------------------------------------------------------------ Export
    function exportReport() {
        var list = selected();
        if (!list.length || list.length > MAX_DOCS || H.busy) return;
        var f = currentFilters();
        var payerSel = $('hrPayer');
        var payload = {
            site: (typeof window.getEffectiveSiteCode === 'function') ? window.getEffectiveSiteCode() : '',
            docNos: list.map(function (it) { return it.docId; }),
            ic: f.ic, from: f.from, to: f.to, type: f.type,
            status: $('historyFilterStatus') && $('historyFilterStatus').value ? $('historyFilterStatus').selectedOptions[0].textContent : '',
            payer: payerSel && payerSel.value ? (payerSel.value === '__none__' ? '(ยังไม่มีผู้นำจ่าย)' : payerSel.value) : '',
            q: val('historyFilterSearch')
        };
        H.busy = true;
        updateCount();
        if (typeof window.showLoadingPopup === 'function') {
            window.showLoadingPopup('กำลังสร้างรายงาน', list.length + ' ใบ · ย่อรูปและจัดหน้า — ใบเยอะอาจใช้เวลาเกือบนาที');
        }
        var meta = document.querySelector('meta[name="csrf-token"]');
        var base = (typeof window.APP_BASE === 'string') ? window.APP_BASE : '';
        var done = function () { H.busy = false; updateCount(); if (typeof window.closeAppPopup === 'function') window.closeAppPopup(); };
        var fail = function (msg) {
            done();
            if (typeof window.showInfoPopup === 'function') window.showInfoPopup('สร้างรายงานไม่สำเร็จ', esc(msg), 'danger');
            else alert(msg);
        };
        fetch(base + '/api/history_report.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': meta ? meta.getAttribute('content') : '' },
            body: JSON.stringify(payload)
        }).then(function (res) {
            var ct = res.headers.get('Content-Type') || '';
            if (res.ok && ct.indexOf('application/pdf') !== -1) {
                var name = 'History_Report.pdf';
                var cd = res.headers.get('Content-Disposition') || '';
                var m = cd.match(/filename\*=UTF-8''([^;]+)/i) || cd.match(/filename="([^"]+)"/i);
                if (m) { try { name = decodeURIComponent(m[1]); } catch (e) { name = m[1]; } }
                var skipped = parseInt(res.headers.get('X-Report-Skipped') || '0', 10) || 0;
                var docs = parseInt(res.headers.get('X-Report-Docs') || '0', 10) || 0;
                return res.blob().then(function (b) {
                    var url = URL.createObjectURL(b);
                    var a = document.createElement('a');
                    a.href = url; a.download = name; a.style.display = 'none';
                    document.body.appendChild(a); a.click();
                    setTimeout(function () { try { document.body.removeChild(a); URL.revokeObjectURL(url); } catch (e) {} }, 1500);
                    done();
                    if (typeof window.showToast === 'function') {
                        window.showToast('Export รายงาน ' + docs + ' ใบแล้ว' + (skipped ? ' (ข้าม ' + skipped + ' ใบ)' : ''), skipped ? 'warning' : 'success');
                    }
                });
            }
            return res.json().then(function (j) {
                if (res.status === 401 || (j && j.code === 'auth')) {
                    try { window.dispatchEvent(new CustomEvent('connext:session-expired')); } catch (e) {}
                }
                fail((j && (j.error || j.message)) || ('HTTP ' + res.status));
            }, function () { fail('HTTP ' + res.status); });
        }).catch(function (e) { fail(e && e.message ? e.message : String(e)); });
    }

    // ------------------------------------------------------------------ boot
    function boot() {
        window.filterHistoryTable = filterTable;
        window.HistoryReport = { state: H, filter: filterTable, exportReport: exportReport };
        if (buildUi() && data().length) filterTable();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
