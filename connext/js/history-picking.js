/**
 * CONNEXT — js/history-picking.js : หน้า "ประวัติเอกสาร" → มุมมอง "ราย Picking list" (2026-10-08)
 *
 * สวิตช์ "ราย เอกสาร | ราย Picking list" เหนือตัวกรอง (จำค่าในเครื่อง)
 *   ราย Picking list = 1 การ์ดต่อ 1 รอบที่ตู้ (PK…): ประตู · เวลาเริ่ม–ปิด · เวลาที่ใช้ · ขอเวลาเพิ่ม · เกินเพดาน
 *   · ผู้แตะบัตร · ผู้ยืนยันรูป · ใบในรอบ · จำนวนรายการ · ALARM — กดการ์ดดูรายการของแต่ละใบ (ขอ / หยิบจริง / คืน · เหตุผล)
 * ใช้ตัวกรองเดิมของหน้าเดียวกัน (ประเภท · สถานะ · ค้นหา · วันที่ · รหัส IC · ผู้นำจ่าย = ผู้แตะบัตร)
 * ราย เอกสาร: เติมป้าย PK ใต้เลขเอกสาร → กดแล้วไปดูรอบนั้น
 * ข้อมูลจาก RPC getPickingHistory (lib/picking_history.php) · ห่อ loadHistory / filterHistoryTable (js/history-report.js)
 * ไม่มีใน GAS — ถ้า build index.php ใหม่ให้เติม <link>/<script defer> ไฟล์นี้กลับก่อน </head> (หลัง history-report.js)
 */
(function () {
    'use strict';
    var KEY = 'connext.historyView';
    var PAGE = 60;
    var P = { mode: 'docs', rounds: null, canSeeAlarms: false, loading: false, err: '', site: null,
              open: {}, limit: PAGE, quick: '', sort: 'start', byDoc: {}, ui: false, reqSeq: 0 };

    var TYPE = {
        RD: ['เบิกวัสดุหลัก', 'rd'], OD: ['เบิกเบ็ดเตล็ด', 'od'], BD: ['ยืม', 'bd'], BDRT: ['คืน', 'rt'],
        IN: ['รับเข้า', 'in'], TD: ['โอนข้ามไซต์', 'td'], TG: ['ย้าย Gate', 'tg'], TGRT: ['ย้าย Gate', 'tg'], SC: ['นับสต๊อก', 'sc']
    };
    var STATUS = {
        open:      ['กำลังหยิบ', 'info', 'fa-door-open'],
        confirmed: ['ยืนยันแล้ว · ยังไม่ปิดรอบ', 'warn', 'fa-hourglass-half'],
        closed:    ['ปิดรอบแล้ว', 'ok', 'fa-circle-check'],
        cancelled: ['ยกเลิก', 'muted', 'fa-ban']
    };

    var GATE_ST = { Awaiting: 'รอสแกน', Scanned: 'สแกนแล้ว', Opened: 'เปิดประตูแล้ว', Confirmed: 'ยืนยันรูปแล้ว', Closed: 'ปิดแล้ว', Cancelled: 'ยกเลิก' };

    function $(id) { return document.getElementById(id); }
    function esc(v) {
        return (v === null || v === undefined ? '' : String(v))
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function val(id) { var el = $(id); return el ? String(el.value || '').trim() : ''; }
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function ymd(ms) { var d = new Date(ms); return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
    function hm(ms) { var d = new Date(ms); return pad(d.getHours()) + ':' + pad(d.getMinutes()); }
    function dur(sec) {
        if (sec === null || sec === undefined || sec < 0) return '-';
        sec = Math.round(sec);
        var h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60;
        if (h >= 24) return Math.floor(h / 24) + ' วัน ' + (h % 24) + ' ชม.';
        return h ? h + ' ชม. ' + m + ' นาที' : m + ':' + pad(s) + ' นาที';
    }
    function num(n) {
        n = Number(n) || 0;
        return Math.abs(n - Math.round(n)) < 1e-9 ? String(Math.round(n)) : String(Math.round(n * 1000) / 1000);
    }
    function norm(s) { return (typeof window.normalizeStatus === 'function') ? window.normalizeStatus(s) : String(s || ''); }
    function site() { return (typeof window.getEffectiveSiteCode === 'function') ? window.getEffectiveSiteCode() : ''; }
    function store(k, v) { try { if (v === undefined) return localStorage.getItem(k); localStorage.setItem(k, v); } catch (e) {} return null; }
    function typeTag(t) {
        var m = TYPE[t] || [t || '?', 'x'];
        return '<span class="hp-type hp-type-' + m[1] + '">' + esc(t === 'BDRT' ? 'BD คืน' : t) + '<small>' + esc(m[0]) + '</small></span>';
    }

    // ------------------------------------------------------------------ โหลด
    function load(force) {
        var s = site();
        if (!force && P.rounds && P.site === s) { render(); return; }
        if (!window.google || !google.script || !google.script.run) return;
        var seq = ++P.reqSeq;
        P.loading = true; P.err = '';
        render();
        google.script.run
            .withSuccessHandler(function (res) {
                if (seq !== P.reqSeq) return;
                P.loading = false;
                if (!res || res.success === false) { P.err = (res && res.message) || 'โหลดไม่สำเร็จ'; P.rounds = P.rounds || []; }
                else { P.rounds = res.rounds || []; P.canSeeAlarms = !!res.canSeeAlarms; P.site = s; }
                indexDocs();
                render();
                if (P.mode === 'docs') tagDocRows();
            })
            .withFailureHandler(function (e) {
                if (seq !== P.reqSeq) return;
                P.loading = false;
                P.err = (e && e.message) || String(e || 'โหลดไม่สำเร็จ');
                render();
            })
            .getPickingHistory(s, 0);
    }
    function indexDocs() {
        P.byDoc = {};
        (P.rounds || []).forEach(function (r) {
            r.docs.forEach(function (d) {
                var k = d.docId || d.docNo;
                (P.byDoc[k] = P.byDoc[k] || []);
                if (P.byDoc[k].indexOf(r.pk) === -1) P.byDoc[k].push(r.pk);
            });
        });
    }

    // ------------------------------------------------------------------ UI
    function buildUi() {
        if (P.ui) return true;
        var page = $('history-page');
        var table = $('historyTable');
        if (!page || !table) return false;
        var header = page.querySelector('.page-header');
        var sw = document.createElement('div');
        sw.className = 'hp-switch';
        sw.id = 'hpSwitch';
        sw.setAttribute('role', 'tablist');
        sw.innerHTML =
            '<button type="button" role="tab" data-mode="docs"><i class="fa-solid fa-file-lines"></i> ราย เอกสาร</button>' +
            '<button type="button" role="tab" data-mode="pk"><i class="fa-solid fa-layer-group"></i> ราย Picking list</button>';
        if (header && header.nextSibling) header.parentNode.insertBefore(sw, header.nextSibling);
        else page.insertBefore(sw, page.firstChild);
        sw.addEventListener('click', function (e) {
            var b = e.target.closest('button[data-mode]');
            if (b) setMode(b.getAttribute('data-mode'), true);
        });

        var wrap = document.createElement('div');
        wrap.id = 'hpWrap';
        wrap.className = 'hp-wrap';
        wrap.hidden = true;
        var tc = table.closest('.table-container') || table;
        tc.parentNode.insertBefore(wrap, tc.nextSibling);
        wrap.addEventListener('click', onWrapClick);
        wrap.addEventListener('keydown', function (e) {
            if ((e.key === 'Enter' || e.key === ' ') && e.target.classList && e.target.classList.contains('hp-head')) {
                e.preventDefault();
                e.target.click();
            }
        });
        wrap.addEventListener('change', function (e) {
            if (e.target && e.target.id === 'hpSort') { P.sort = e.target.value; P.limit = PAGE; render(); }
        });

        // ตัวกรองของหน้า (บางช่อง history-report.js ผูก listener ไว้ก่อนสคริปต์นี้) → วาดการ์ดใหม่
        var t = null;
        var again = function () { if (P.mode !== 'pk') return; clearTimeout(t); t = setTimeout(function () { P.limit = PAGE; render(); }, 120); };
        page.addEventListener('input', function (e) { if (!wrap.contains(e.target)) again(); }, true);
        page.addEventListener('change', function (e) { if (!wrap.contains(e.target)) again(); }, true);
        page.addEventListener('click', function (e) { if (e.target.closest && e.target.closest('#hrClear')) { P.quick = ''; again(); } }, true);

        // ป้าย PK ในตารางราย เอกสาร
        $('historyTableBody').addEventListener('click', function (e) {
            var a = e.target.closest && e.target.closest('.hp-pktag');
            if (!a) return;
            e.preventDefault();
            e.stopPropagation();
            showRound(a.getAttribute('data-pk'));
        });
        P.ui = true;
        return true;
    }
    function setMode(m, user) {
        P.mode = m === 'pk' ? 'pk' : 'docs';
        if (user) store(KEY, P.mode);
        var page = $('history-page');
        if (page) page.classList.toggle('hp-mode-pk', P.mode === 'pk');
        var sw = $('hpSwitch');
        if (sw) sw.querySelectorAll('button').forEach(function (b) {
            var on = b.getAttribute('data-mode') === P.mode;
            b.classList.toggle('on', on);
            b.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        var wrap = $('hpWrap');
        if (wrap) wrap.hidden = P.mode !== 'pk';
        var search = $('historyFilterSearch');
        if (search) search.placeholder = P.mode === 'pk' ? 'พิมพ์เลข PK เลขเอกสาร ชื่อผู้แตะบัตร หรือรหัส IC...' : 'พิมพ์เลขเอกสาร หรือ รายการ...';
        if (P.mode === 'pk') load(false);
        else tagDocRows();
    }
    function showRound(pk) {
        var s = $('historyFilterSearch');
        if (s) s.value = pk;
        P.open = {}; P.open[pk] = true; P.quick = '';
        setMode('pk', true);
        render();
        var w = $('hpWrap');
        if (w && w.scrollIntoView) w.scrollIntoView({ block: 'start', behavior: 'smooth' });
    }
    function showDoc(docId) {
        var s = $('historyFilterSearch');
        if (s) s.value = docId;
        setMode('docs', true);
        if (typeof window.filterHistoryTable === 'function') window.filterHistoryTable();
    }
    function onWrapClick(e) {
        var t = e.target;
        var q = t.closest('[data-quick]');
        if (q) { P.quick = q.getAttribute('data-quick'); P.limit = PAGE; render(); return; }
        if (t.closest('#hpMore')) { P.limit += PAGE; render(); return; }
        if (t.closest('#hpRetry')) { load(true); return; }
        var d = t.closest('[data-doc]');
        if (d) { e.stopPropagation(); showDoc(d.getAttribute('data-doc')); return; }
        if (t.closest('#hpExpandAll')) {
            var all = currentRows().slice(0, P.limit), anyClosed = all.some(function (r) { return !P.open[r.pk]; });
            all.forEach(function (r) { if (anyClosed) P.open[r.pk] = true; else delete P.open[r.pk]; });
            render();
            return;
        }
        var h = t.closest('.hp-head');
        if (h) {
            var pk = h.getAttribute('data-pk');
            if (P.open[pk]) delete P.open[pk]; else P.open[pk] = true;
            var card = h.closest('.hp-card');
            if (card) {
                card.classList.toggle('open', !!P.open[pk]);
                h.setAttribute('aria-expanded', P.open[pk] ? 'true' : 'false');
                var body = card.querySelector('.hp-body');
                if (P.open[pk] && !body) {
                    var r = (P.rounds || []).filter(function (x) { return x.pk === pk; })[0];
                    if (r) card.insertAdjacentHTML('beforeend', bodyHtml(r));
                } else if (body) {
                    body.hidden = !P.open[pk];
                }
            }
        }
    }

    // ------------------------------------------------------------------ กรอง
    function filters() {
        return {
            type: val('historyFilterType'), status: val('historyFilterStatus'), q: val('historyFilterSearch').toLowerCase(),
            from: val('hrFrom'), to: val('hrTo'), ic: val('hrIc').toUpperCase(), payer: $('hrPayer') ? $('hrPayer').value : ''
        };
    }
    function hay(r) {
        if (r._hay) return r._hay;
        var a = [r.pk, r.gate, r.site].concat(r.holders, r.confirmers);
        r.docs.forEach(function (d) {
            a.push(d.docNo, d.docId, d.requester, d.receiver, d.holder, d.confirmedBy);
            d.items.forEach(function (it) { a.push(it.code, it.name); });
        });
        r._hay = a.join(' ').toLowerCase();
        return r._hay;
    }
    function matches(r, f) {
        if (f.type && !r.docs.some(function (d) { return d.type === f.type; })) return false;
        if (f.status && !r.docs.some(function (d) { return norm(d.status) === f.status; })) return false;
        if ((f.from || f.to) && r.start) {
            var d = ymd(r.start);
            if ((f.from && d < f.from) || (f.to && d > f.to)) return false;
        } else if ((f.from || f.to) && !r.start) return false;
        if (f.ic && !r.docs.some(function (d) { return d.items.some(function (it) { return String(it.code || '').toUpperCase().indexOf(f.ic) !== -1; }); })) return false;
        if (f.payer === '__none__' ? r.holders.length > 0 : (f.payer && r.holders.indexOf(f.payer) === -1 && !r.docs.some(function (d) { return d.holder === f.payer; }))) return false;
        if (f.q && hay(r).indexOf(f.q) === -1) return false;
        return true;
    }
    function quickOk(r, k) {
        if (!k) return true;
        if (k === 'open') return r.status === 'open' || r.status === 'confirmed';
        if (k === 'extend') return r.overCap || r.pickExtends > 0 || r.closeExtends > 0 || r.overrunSec > 0;
        if (k === 'alarm') return r.alarms.length > 0;
        if (k === 'zero') return r.docs.some(function (d) { return d.zeroPick || d.items.some(function (it) { return it.actual !== null && it.actual !== undefined && Number(it.actual) < Number(it.qty); }); });
        if (k === 'bypass') return !!r.bypass;
        return true;
    }
    function baseRows() {
        var f = filters();
        return (P.rounds || []).filter(function (r) { return matches(r, f); });
    }
    function currentRows() {
        var rows = baseRows().filter(function (r) { return quickOk(r, P.quick); });
        if (P.sort === 'used') rows.sort(function (a, b) { return (b.usedSec || 0) - (a.usedSec || 0); });
        else if (P.sort === 'items') rows.sort(function (a, b) { return b.itemCount - a.itemCount; });
        else if (P.sort === 'old') rows.sort(function (a, b) { return (a.start || 0) - (b.start || 0); });
        return rows;
    }

    // ------------------------------------------------------------------ วาด
    function render() {
        var wrap = $('hpWrap');
        if (!wrap || P.mode !== 'pk') return;
        if (P.loading && !P.rounds) {
            wrap.innerHTML = '<div class="hp-empty"><i class="fa-solid fa-spinner fa-spin"></i> กำลังโหลดรายการ Picking list...</div>';
            return;
        }
        if (P.err && !(P.rounds && P.rounds.length)) {
            wrap.innerHTML = '<div class="hp-empty hp-err"><i class="fa-solid fa-triangle-exclamation"></i> ' + esc(P.err) +
                ' <button type="button" class="btn btn-secondary" id="hpRetry">ลองใหม่</button></div>';
            return;
        }
        var base = baseRows();
        var rows = currentRows();
        var cnt = { open: 0, extend: 0, alarm: 0, zero: 0, bypass: 0 };
        base.forEach(function (r) { Object.keys(cnt).forEach(function (k) { if (quickOk(r, k)) cnt[k]++; }); });
        var docs = 0, items = 0, used = 0, usedN = 0;
        // เวลาเฉลี่ย: เฉพาะรอบที่ตู้บันทึกเวลาเอง (round_end) — รอบเก่า/Bypass คิดจากเวลาสแกนถึงปิดซึ่งอาจค้างข้ามวัน
        rows.forEach(function (r) { docs += r.docs.length; items += r.itemCount; if (r.usedExact && r.usedSec !== null) { used += r.usedSec; usedN++; } });

        function chip(k, label, n, cls) {
            return '<button type="button" class="hp-q' + (P.quick === k ? ' on' : '') + (cls ? ' ' + cls : '') + '" data-quick="' + k + '"' +
                (k && !n ? ' disabled' : '') + '>' + label + (k ? ' <b>' + n + '</b>' : '') + '</button>';
        }
        var html =
            '<div class="hp-bar">' +
                '<div class="hp-sum"><b>' + rows.length + '</b> รอบ · ' + docs + ' ใบ · ' + items + ' รายการ' +
                    (usedN ? ' · ใช้เวลาเฉลี่ย <b>' + dur(used / usedN) + '</b> ต่อรอบ' : '') +
                    (P.loading ? ' <i class="fa-solid fa-spinner fa-spin" title="กำลังโหลดใหม่"></i>' : '') + '</div>' +
                '<div class="hp-tools">' +
                    '<select id="hpSort" class="form-control" aria-label="เรียงตาม">' +
                        '<option value="start"' + (P.sort === 'start' ? ' selected' : '') + '>ล่าสุดก่อน</option>' +
                        '<option value="old"' + (P.sort === 'old' ? ' selected' : '') + '>เก่าสุดก่อน</option>' +
                        '<option value="used"' + (P.sort === 'used' ? ' selected' : '') + '>ใช้เวลานานสุด</option>' +
                        '<option value="items"' + (P.sort === 'items' ? ' selected' : '') + '>รายการมากสุด</option>' +
                    '</select>' +
                    '<button type="button" class="btn btn-secondary hp-expand" id="hpExpandAll"><i class="fa-solid fa-up-down"></i> ขยาย/ย่อทั้งหมด</button>' +
                '</div>' +
            '</div>' +
            '<div class="hp-quick">' +
                chip('', 'ทั้งหมด', 0) +
                chip('open', '<i class="fa-solid fa-door-open"></i> ยังไม่ปิดรอบ', cnt.open) +
                chip('extend', '<i class="fa-solid fa-stopwatch"></i> ขอเวลาเพิ่ม / เกินเวลา', cnt.extend, 'warn') +
                chip('zero', '<i class="fa-solid fa-arrow-down-short-wide"></i> หยิบน้อยกว่าที่ขอ', cnt.zero, 'warn') +
                (P.canSeeAlarms ? chip('alarm', '<i class="fa-solid fa-bell"></i> มี ALARM', cnt.alarm, 'bad') : '') +
                (cnt.bypass ? chip('bypass', '<i class="fa-solid fa-person-walking-arrow-right"></i> Bypass', cnt.bypass, 'warn') : '') +
            '</div>';
        if (!rows.length) {
            html += '<div class="hp-empty">' + ((P.rounds || []).length ? 'ไม่พบรอบที่ตรงกับตัวกรอง' : 'ยังไม่มีรอบหยิบของจากตู้ประตู') + '</div>';
        } else {
            html += '<div class="hp-list">' + rows.slice(0, P.limit).map(cardHtml).join('') + '</div>';
            if (rows.length > P.limit) {
                html += '<button type="button" class="btn btn-secondary hp-more" id="hpMore">แสดงเพิ่ม (' + (rows.length - P.limit) + ' รอบ)</button>';
            }
        }
        wrap.innerHTML = html;
    }

    function cardHtml(r) {
        var st = STATUS[r.status] || STATUS.closed;
        var open = !!P.open[r.pk];
        var when = r.start ? ymd(r.start).split('-').reverse().join('/') + ' ' + hm(r.start) + (r.end ? '–' + hm(r.end) : '') : '-';
        var flags = [];
        if (r.overCap) flags.push('<span class="hp-flag bad"><i class="fa-solid fa-gauge-high"></i> เกินเพดาน</span>');
        if (r.pickExtends) flags.push('<span class="hp-flag warn"><i class="fa-solid fa-stopwatch"></i> ขอเวลาเพิ่ม ' + r.pickExtends + ' ครั้ง</span>');
        if (r.closeExtends) flags.push('<span class="hp-flag warn"><i class="fa-solid fa-door-closed"></i> เลื่อนปิดประตู ' + r.closeExtends + ' ครั้ง</span>');
        if (r.overrunSec > 0) flags.push('<span class="hp-flag warn">เกินเวลา ' + dur(r.overrunSec) + '</span>');
        if (r.bypass) flags.push('<span class="hp-flag warn"><i class="fa-solid fa-person-walking-arrow-right"></i> Bypass</span>');
        if (r.alarms.length) flags.push('<span class="hp-flag bad"><i class="fa-solid fa-bell"></i> ALARM ' + r.alarms.length + '</span>');
        var zero = r.docs.filter(function (d) { return d.zeroPick; }).length;
        if (zero) flags.push('<span class="hp-flag bad">หยิบ 0 ทั้งใบ ' + zero + ' ใบ</span>');

        var docs = r.docs.map(function (d) {
            var t = d.leg === 'return' ? 'BDRT' : d.type;
            return '<button type="button" class="hp-doc hp-doc-' + ((TYPE[t] || [0, 'x'])[1]) + '" data-doc="' + esc(d.docId) + '" title="ดูใบนี้ในราย เอกสาร">' +
                esc(d.docNo) + '</button>';
        }).join('') + (r.hiddenDocs ? '<span class="hp-hidden">+ ใบของคนอื่น ' + r.hiddenDocs + ' ใบ</span>' : '');

        var who = r.holders.length ? esc(r.holders.join(', ')) : '<span class="hp-muted">-</span>';
        var conf = r.confirmers.length ? esc(r.confirmers.join(', ')) : '<span class="hp-muted">-</span>';
        return '<article class="hp-card st-' + esc(r.status) + (open ? ' open' : '') + '">' +
            '<div class="hp-head" role="button" tabindex="0" aria-expanded="' + (open ? 'true' : 'false') + '" data-pk="' + esc(r.pk) + '">' +
                '<div class="hp-id">' +
                    '<span class="hp-pk">' + esc(r.pk) + '</span>' +
                    (r.gate ? '<span class="hp-gate">' + esc(r.gate) + '</span>' : '') +
                    (r.site && !site() ? '<span class="hp-gate hp-site">' + esc(r.site) + '</span>' : '') +
                    '<span class="hp-st ' + st[1] + '"><i class="fa-solid ' + st[2] + '"></i> ' + st[0] + '</span>' +
                    '<i class="fa-solid fa-chevron-down hp-caret" aria-hidden="true"></i>' +
                '</div>' +
                '<div class="hp-grid">' +
                    '<div><span class="hp-k">เวลา</span><span class="hp-v">' + esc(when) + '</span></div>' +
                    '<div><span class="hp-k">ใช้เวลา</span><span class="hp-v hp-dur">' + (r.usedExact ? '' : (r.usedSec !== null && r.usedSec !== undefined ? '~' : '')) + dur(r.usedSec) + '</span></div>' +
                    '<div><span class="hp-k">ผู้แตะบัตร</span><span class="hp-v">' + who + '</span></div>' +
                    '<div><span class="hp-k">ผู้ยืนยันรูป</span><span class="hp-v">' + conf + '</span></div>' +
                    '<div><span class="hp-k">รายการ</span><span class="hp-v">' + r.itemCount + ' รายการ · หยิบ/คืน ' + num(r.qtyActual) +
                        (Math.abs(r.qtyActual - r.qtyReq) > 1e-9 ? ' <span class="hp-muted">จากที่ขอ ' + num(r.qtyReq) + '</span>' : '') + '</span></div>' +
                '</div>' +
                '<div class="hp-docs">' + docs + '</div>' +
                (flags.length ? '<div class="hp-flags">' + flags.join('') + '</div>' : '') +
            '</div>' +
            (open ? bodyHtml(r) : '') +
        '</article>';
    }

    function bodyHtml(r) {
        var html = '<div class="hp-body">';
        r.docs.forEach(function (d) {
            var t = d.leg === 'return' ? 'BDRT' : d.type;
            var ret = d.leg === 'return';
            var sb = (typeof window.getStatusBadge === 'function') ? window.getStatusBadge(d.status) : esc(d.status);
            var meta = [];
            if (d.requester) meta.push('ผู้เบิก <b>' + esc(d.requester) + '</b>');
            if (d.receiver) meta.push('ผู้รับ <b>' + esc(d.receiver) + '</b>');
            if (d.holder) meta.push('แตะบัตร <b>' + esc(d.holder) + '</b>');
            if (d.confirmedBy) meta.push('ยืนยันรูป <b>' + esc(d.confirmedBy) + '</b>' + (d.confirmedAt ? ' ' + esc(String(d.confirmedAt).substr(11, 5)) : ''));
            if (d.gateStatus) meta.push('ที่ประตู ' + esc(GATE_ST[d.gateStatus] || d.gateStatus));
            html += '<section class="hp-docbox">' +
                '<header>' + typeTag(t) +
                    '<button type="button" class="hp-docno" data-doc="' + esc(d.docId) + '" title="ดูใบนี้ในราย เอกสาร">' + esc(d.docNo) + '</button>' +
                    (d.bypass ? '<span class="hp-flag warn">bypass</span>' : '') +
                    (d.zeroPick ? '<span class="hp-flag bad">หยิบจริง 0 ทุกรายการ' + (d.zeroReasons.length ? ' — ' + esc(d.zeroReasons.join(' / ')) : '') + '</span>' : '') +
                    '<span class="hp-docst">' + sb + '</span>' +
                '</header>' +
                (meta.length ? '<div class="hp-meta">' + meta.join(' · ') + '</div>' : '');
            if (d.items.length) {
                html += '<div class="hp-tbl-wrap"><table class="hp-items"><thead><tr>' +
                    '<th>รหัส IC</th><th>รายการ</th><th class="n">' + (ret ? 'ยืม' : 'ขอ') + '</th><th class="n">' + (ret ? 'คืนแล้ว' : 'หยิบจริง') + '</th><th>เหตุผล</th>' +
                    '</tr></thead><tbody>' +
                    d.items.map(function (it) {
                        var got = ret ? it.returned : (it.actual === null || it.actual === undefined ? it.qty : it.actual);
                        var less = Number(got) < Number(it.qty) - 1e-9;
                        return '<tr' + (less ? ' class="less"' : '') + '>' +
                            '<td data-label="รหัส IC" class="code"><span>' + esc(it.code) + '</span></td>' +
                            '<td data-label="รายการ"><span>' + esc(it.name) + '</span></td>' +
                            '<td data-label="' + (ret ? 'ยืม' : 'ขอ') + '" class="n"><span>' + num(it.qty) + (it.unit ? ' <small>' + esc(it.unit) + '</small>' : '') + '</span></td>' +
                            '<td data-label="' + (ret ? 'คืนแล้ว' : 'หยิบจริง') + '" class="n"><span><b>' + num(got) + '</b></span></td>' +
                            '<td data-label="เหตุผล" class="why"><span>' + (it.reason ? esc(it.reason) : '<span class="hp-muted">-</span>') + '</span></td>' +
                            '</tr>';
                    }).join('') +
                    '</tbody></table></div>';
            } else {
                html += '<div class="hp-meta hp-muted">ไม่มีรายการ</div>';
            }
            html += '</section>';
        });
        if (r.hiddenDocs) html += '<div class="hp-meta hp-muted">รอบนี้มีใบของผู้อื่นอีก ' + r.hiddenDocs + ' ใบ (ไม่แสดงตามสิทธิ์)</div>';
        if (r.alarms.length) {
            html += '<section class="hp-alarms"><header><i class="fa-solid fa-bell"></i> ALARM / เหตุการณ์ของรอบนี้</header><ul>' +
                r.alarms.map(function (a) {
                    return '<li><span class="hp-at">' + esc(String(a.at).substr(11, 5)) + '</span>' +
                        '<span class="hp-flag ' + (a.cat === 'pick_time' ? 'warn' : 'bad') + '">' + esc(a.label) + '</span>' +
                        '<span class="hp-msg">' + esc(a.message) + '</span></li>';
                }).join('') + '</ul></section>';
        }
        return html + '</div>';
    }

    // ------------------------------------------------------------------ ราย เอกสาร: ป้าย PK
    function tagDocRows() {
        if (P.mode !== 'docs' || !P.rounds) return;
        var tb = $('historyTableBody');
        if (!tb) return;
        tb.querySelectorAll('input.hr-sel[data-doc]').forEach(function (cb) {
            var doc = cb.getAttribute('data-doc');
            var pks = P.byDoc[doc];
            var tr = cb.closest('tr');
            if (!pks || !tr || tr.querySelector('.hp-pktags')) return;
            var td = tr.querySelector('td[data-label="เลขที่เอกสาร"]');
            if (!td) return;
            td.insertAdjacentHTML('beforeend', '<div class="hp-pktags">' + pks.map(function (pk) {
                return '<a href="#" class="hp-pktag" data-pk="' + esc(pk) + '" title="ดูรอบ Picking list นี้"><i class="fa-solid fa-layer-group"></i> ' + esc(pk) + '</a>';
            }).join('') + '</div>');
        });
    }

    // ------------------------------------------------------------------ boot
    function wrap(name, after) {
        var orig = window[name];
        if (typeof orig !== 'function' || orig.__hpWrapped) return;
        var w = function () {
            var r = orig.apply(this, arguments);
            try { after.apply(this, arguments); } catch (e) { console.warn('[history-picking] ' + name, e); }
            return r;
        };
        w.__hpWrapped = true;
        window[name] = w;
    }
    function boot() {
        if (!buildUi()) return;
        wrap('filterHistoryTable', function () {
            if (P.mode === 'pk') render(); else tagDocRows();
        });
        // เปิดหน้า / ปุ่มโหลดใหม่ / รีเฟรช → โหลดรอบใหม่เสมอเมื่ออยู่ราย Picking list (ราย เอกสาร โหลดครั้งแรกไว้ทำป้าย PK)
        wrap('loadHistory', function (opts) {
            if (P.mode === 'pk' || (opts && opts.force) || !P.rounds || P.site !== site()) load(true);
        });
        window.HistoryPicking = { state: P, load: load, render: render, setMode: setMode, showRound: showRound };
        setMode(store(KEY) === 'pk' ? 'pk' : 'docs', false);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
