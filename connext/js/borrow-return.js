/*
 * CONNEXT — js/borrow-return.js  [PHP port 2026-09-29 · เอกสาร 05 Scenario ③ ใบยืม (ฉบับแก้)]
 *
 * 1) ฟอร์มยืม: ช่อง "กำหนดวันคืน *" (บังคับ · ตั้งแต่วันนี้) → ส่งไปกับทุกรายการ (DueDate) ของ processBorrowBatch
 * 2) "รายการอุปกรณ์ที่ยังไม่ส่งคืน": กำหนดคืน + ป้ายแดง "เกินกำหนด N วัน" · สถานะ (ยืมอยู่ / แจ้งคืนแล้วรอสแกน /
 *    กำลังคืน / คืนไม่ครบ — รอตีชำรุด/สูญหาย) · ปุ่ม คืน (เดิม) · "ตีเป็นชำรุด/สูญหาย" (สายสโตร์) · รายงาน PDF
 * 3) หน้าต่างตีเป็นชำรุด/สูญหาย: เลือกรายการ + จำนวน + ชำรุด/สูญหาย + เหตุผล + รูป (ถ้ามี) + มูลค่าอ้างอิงถ้ายังไม่ตั้งราคา
 * 4) การ์ด QR (ใบยืม/ขาคืน): กำหนดคืน · หน้าการอนุมัติ: กำหนดคืน + ใบยืมเกินกำหนดที่ผู้ยืมค้างอยู่
 * 5) Dashboard: การ์ด "อุปกรณ์ยืมค้างคืน" (เกินกำหนด · ใกล้ครบกำหนด · คืนไม่ครบรอตี)
 * 6) แจ้งเตือนในแอป (user_notices): ผู้เบิก / ผู้อนุมัติของใบ / ผู้จัดการโครงการ เมื่ออุปกรณ์ถูกตีเป็นชำรุด/สูญหาย
 * RPC ฝั่ง server: lib/borrow.php · lib/documents.php (getUnreturnedItems) · lib/gate_api.php · lib/approval.php
 * ไม่มีใน GAS — ถ้า build index.php ใหม่ให้เติม <link>/<script defer> ไฟล์นี้กลับก่อน </head>
 */
(function () {
    'use strict';

    var DUE_ID = 'borrowDueDateInput';
    var TH_MON = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    var STATE_TXT = {
        borrowed:         { t: 'ยืมอยู่', c: '' },
        return_pending:   { t: 'แจ้งคืนแล้ว — รอสแกน QR คืน', c: 'info' },
        return_open:      { t: 'กำลังคืนที่ประตู', c: 'info' },
        writeoff_pending: { t: 'คืนแล้วบางส่วน — ทยอยคืนต่อ หรือตีชำรุด/สูญหาย', c: 'warn' }   // [2026-10-08] ข้อมูลเก่า (ขาคืนจบแล้ว)
    };
    var B = { submitDue: '', inSubmit: false, approveCtx: null, rows: [], busyAlerts: false };

    // ------------------------------------------------------------------ ตัวช่วย
    function $(sel, root) { return (root || document).querySelector(sel); }
    function esc(v) {
        if (typeof window.escapeHtml === 'function') return window.escapeHtml(v == null ? '' : String(v));
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function fmt(v) {
        if (typeof window.formatBalanceValue === 'function') return window.formatBalanceValue(v);
        var n = Math.round(Number(v) * 1000) / 1000;
        return isFinite(n) ? String(n) : '0';
    }
    function money(v) { return Number(v || 0).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function toast(m, t) { if (typeof window.showToast === 'function') window.showToast(m, t || 'info'); }
    function info(title, msg, type) { if (typeof window.showInfoPopup === 'function') window.showInfoPopup(title, msg, type || 'info'); else alert(title + '\n' + msg); }
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
    function today() { return ymd(new Date()); }
    function addDays(s, n) { var p = s.split('-'); var d = new Date(+p[0], +p[1] - 1, +p[2] + n); return ymd(d); }
    function daysBetween(a, b) {   // b − a (วัน)
        var pa = a.split('-'), pb = b.split('-');
        return Math.round((Date.UTC(+pb[0], +pb[1] - 1, +pb[2]) - Date.UTC(+pa[0], +pa[1] - 1, +pa[2])) / 86400000);
    }
    /** '2026-10-05' → '5 ต.ค. 69' */
    function thDate(s) {
        var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(s || ''));
        if (!m) return '-';
        return (+m[3]) + ' ' + TH_MON[+m[2] - 1] + ' ' + String((+m[1] + 543) % 100);
    }
    function roleNum() {
        var u = window.user || {};
        if (u.role === 'Subcontractor') return 1;
        var m = String(u.roleLevel || '').match(/\d+/);
        return m ? parseInt(m[0], 10) : 99;
    }
    function isStore() {
        var u = window.user || {};
        return u.canReq === true || String(u.role || '').toLowerCase().indexOf('store') !== -1;
    }
    function seesSite() { var n = roleNum(); return n === 0 || n === 4 || n === 6 || (n >= 8 && n < 99) || isStore(); }
    function wrapAfter(name, fn) {
        var orig = window[name];
        if (typeof orig !== 'function' || orig.__brWrapped) return;
        var w = function () {
            var r = orig.apply(this, arguments);
            try { fn.apply(this, arguments); } catch (e) { console.warn('[borrow-return] ' + name + ':', e); }
            return r;
        };
        w.__brWrapped = true;
        window[name] = w;
    }
    /** ป้ายกำหนดคืน: เกินกำหนด (แดง) · วันนี้/ใกล้ (ส้ม) · ปกติ */
    function dueBadge(due, overdueDays) {
        if (!due) return '<span class="br-due none">ไม่มีกำหนดคืน</span>';
        var od = overdueDays != null ? Number(overdueDays) : Math.max(0, daysBetween(due, today()));
        if (od > 0) return '<span class="br-due over"><i class="fa-solid fa-triangle-exclamation"></i> เกินกำหนด ' + od + ' วัน</span>';
        var left = daysBetween(today(), due);
        if (left === 0) return '<span class="br-due soon">ครบกำหนดวันนี้</span>';
        if (left <= 2) return '<span class="br-due soon">อีก ' + left + ' วัน</span>';
        return '<span class="br-due ok">อีก ' + left + ' วัน</span>';
    }
    function downloadDataUri(uri, name) {
        var a = document.createElement('a');
        a.href = uri;
        a.download = name;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    }
    function openLossReport(docNo) {
        if (typeof window.showLoadingPopup === 'function') window.showLoadingPopup('กำลังสร้างรายงาน PDF', 'รายงานอุปกรณ์ชำรุด/สูญหาย\nใบยืม ' + docNo);
        google.script.run
            .withSuccessHandler(function (r) {
                if (typeof window.closeAppPopup === 'function') window.closeAppPopup();
                if (!r || !r.success || !r.dataUri) { info('สร้างรายงานไม่สำเร็จ', (r && r.message) || 'ไม่สามารถสร้างรายงานได้', 'danger'); return; }
                downloadDataUri(r.dataUri, r.fileName || (docNo + '_ชำรุด-สูญหาย.pdf'));
            })
            .withFailureHandler(function (e) {
                if (typeof window.closeAppPopup === 'function') window.closeAppPopup();
                info('สร้างรายงานไม่สำเร็จ', esc((e && e.message) || String(e)), 'danger');
            })
            .generateBorrowLossPDF(docNo);
    }

    // ================================================================== 1) ฟอร์มยืม: กำหนดวันคืน
    function dueValue() { var el = document.getElementById(DUE_ID); return el ? String(el.value || '').trim() : ''; }
    function dueError(v) {
        if (!v) return 'กรุณาเลือกกำหนดวันคืน (บังคับทุกใบยืม)';
        if (!/^\d{4}-\d{2}-\d{2}$/.test(v)) return 'กำหนดวันคืนไม่ถูกต้อง';
        if (v < today()) return 'กำหนดวันคืนต้องเป็นวันนี้หรือหลังจากนี้';
        if (v > addDays(today(), 366)) return 'กำหนดวันคืนไกลเกินไป (ไม่เกิน 1 ปี)';
        return '';
    }
    function dueText(v) {
        if (!v) return '';
        var left = daysBetween(today(), v);
        return thDate(v) + (left === 0 ? ' (วันนี้)' : left > 0 ? ' (อีก ' + left + ' วัน)' : '');
    }
    function refreshDueUi() {
        var v = dueValue(), err = v ? dueError(v) : '';
        var el = document.getElementById(DUE_ID);
        if (el) {
            el.min = today();
            el.classList.toggle('br-invalid', !!err);
        }
        var sum = document.getElementById('brDueSummary');
        if (sum) {
            sum.innerHTML = !v ? '<i class="fa-regular fa-calendar"></i> ยังไม่ได้เลือกกำหนดวันคืน — เลือกที่ช่อง "กำหนดวันคืน" ในฟอร์มก่อนยืนยัน'
                : err ? '<i class="fa-solid fa-triangle-exclamation"></i> ' + esc(err)
                : '<i class="fa-regular fa-calendar-check"></i> กำหนดคืนทุกรายการในชุดนี้: <b>' + esc(dueText(v)) + '</b>';
            sum.className = 'br-due-summary' + (!v || err ? ' warn' : ' ok');
        }
        var hint = document.getElementById('brDueErr');
        if (hint) hint.textContent = err;
    }
    function injectDueField() {
        if (document.getElementById(DUE_ID)) return true;
        var anchor = document.getElementById('borrowApproverGroup');
        var sec = anchor ? anchor.parentNode : $('#req-borrow .form-section');
        if (!sec) return false;
        var g = document.createElement('div');
        g.className = 'form-group br-due-group';
        g.innerHTML =
            '<label for="' + DUE_ID + '">กำหนดวันคืน <span style="color:#dc2626;">*</span></label>' +
            '<input type="date" id="' + DUE_ID + '" class="form-control" required min="' + today() + '">' +
            '<div class="br-due-quick">' +
                [[1, 'พรุ่งนี้'], [3, '3 วัน'], [7, '7 วัน'], [14, '14 วัน']].map(function (q) {
                    return '<button type="button" class="br-chip" data-br-days="' + q[0] + '">' + q[1] + '</button>';
                }).join('') +
            '</div>' +
            '<div class="br-due-err" id="brDueErr" role="alert"></div>' +
            '<div class="br-due-hint">ใช้กับทุกรายการที่ส่งครั้งนี้ · เลยกำหนดแล้วยังไม่คืน ระบบขึ้นป้ายแดงและแจ้งเตือนทุกวันจนกว่าจะคืน</div>';
        if (anchor) sec.insertBefore(g, anchor); else sec.appendChild(g);
        var inp = document.getElementById(DUE_ID);
        inp.addEventListener('change', refreshDueUi);
        inp.addEventListener('input', refreshDueUi);
        g.addEventListener('click', function (ev) {
            var b = ev.target.closest('[data-br-days]');
            if (!b) return;
            inp.value = addDays(today(), parseInt(b.getAttribute('data-br-days'), 10) || 0);
            refreshDueUi();
            if (typeof window.hapticTap === 'function') window.hapticTap();
        });
        // สรุปกำหนดคืนเหนือปุ่ม "ยืนยันบันทึกการยืม"
        var tb = document.getElementById('borrowDraftTableBody');
        var host = tb ? tb.closest('table') : null;
        if (host && !document.getElementById('brDueSummary')) {
            var s = document.createElement('div');
            s.id = 'brDueSummary';
            s.className = 'br-due-summary warn';
            host.parentNode.insertBefore(s, host.nextSibling);
        }
        refreshDueUi();
        return true;
    }
    // [2026-10-02 · GP-13] คำเตือน delay: ผู้ส่งมีใบยืมเกินกำหนดค้าง — เตือนที่ฟอร์มและในหน้าต่างยืนยัน (ไม่บล็อกการยืม)
    function overdueText(o) {
        var nos = (o.docs || []).slice(0, 4).map(function (d) { return d.docNo; }).join(', ');
        return 'คุณมีใบยืมเกินกำหนดคืน ' + o.count + ' ใบ' + (o.maxDays ? ' (เกินสูงสุด ' + o.maxDays + ' วัน)' : '') +
            (nos ? ': ' + nos + ((o.docs || []).length > 4 ? ' …' : '') : '') + ' — ยืมใหม่ได้ แต่ควรคืนของที่เกินกำหนดก่อน';
    }
    function renderOverdueWarn() {
        var o = B.myOverdue;
        var el = document.getElementById('brOverdueWarn');
        var grp = document.querySelector('.br-due-group');
        if (!el && grp) {
            el = document.createElement('div');
            el.id = 'brOverdueWarn';
            el.className = 'br-overdue-warn';
            el.setAttribute('role', 'alert');
            grp.parentNode.insertBefore(el, grp);
        }
        if (!el) return;
        if (!o || !o.count) { el.hidden = true; el.innerHTML = ''; return; }
        el.hidden = false;
        el.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i><span>' + esc(overdueText(o)) + '</span>';
    }
    function loadMyOverdue() {
        if (!window.user || B.busyOverdue) return;
        B.busyOverdue = true;
        google.script.run
            .withSuccessHandler(function (r) { B.busyOverdue = false; B.myOverdue = (r && r.success) ? r : null; renderOverdueWarn(); })
            .withFailureHandler(function () { B.busyOverdue = false; })
            .getMyBorrowOverdue();
    }
    function installBorrowForm() {
        injectDueField();
        loadMyOverdue();
        // ส่งใบ: ต้องมีกำหนดวันคืนที่ถูกต้องก่อน (ไม่มีรายการ = ให้ของเดิมแจ้ง "ยังไม่มีรายการ")
        var orig = window.submitBorrowDraft;
        if (typeof orig === 'function' && !orig.__brWrapped) {
            var w = function () {
                var items = Array.isArray(window.borrowDraftItems) ? window.borrowDraftItems : [];
                if (items.length) {
                    injectDueField();
                    var v = dueValue(), err = dueError(v);
                    if (err) {
                        info('ยังไม่ได้ระบุกำหนดวันคืน', err + '\nเลือกวันที่ช่อง "กำหนดวันคืน" ในฟอร์มยืม', 'warning');
                        var el = document.getElementById(DUE_ID);
                        if (el) { try { el.scrollIntoView({ behavior: 'smooth', block: 'center' }); el.focus(); } catch (e) {} }
                        refreshDueUi();
                        return;
                    }
                    B.submitDue = v;
                }
                B.inSubmit = true;
                try { return orig.apply(this, arguments); } finally { B.inSubmit = false; }
            };
            w.__brWrapped = true;
            window.submitBorrowDraft = w;
        }
        // ล้างช่องหลังส่งสำเร็จ (ของเดิมเรียก resetDraftForm('borrow'))
        wrapAfter('resetDraftForm', function (formKey) {
            if (formKey !== 'borrow') return;
            var el = document.getElementById(DUE_ID);
            if (el) el.value = '';
            B.submitDue = '';
            refreshDueUi();
            loadMyOverdue();
        });
    }

    // หน้าต่างยืนยัน: เติมกำหนดคืน (ส่งใบยืม) · กำหนดคืน + ใบเกินกำหนดของผู้ยืม (อนุมัติใบยืม)
    // ข้อความ popup เป็น white-space: pre-wrap → HTML ต่อแบบไม่มีขึ้นบรรทัด
    function installConfirmNotes() {
        var orig = window.showConfirmPopup;
        if (typeof orig !== 'function' || orig.__brWrapped) return;
        var w = function (title, message, onConfirm, confirmText, confirmClass) {
            try {
                if (B.inSubmit && B.submitDue) {
                    message = (message || '') + '<div class="br-confirm"><i class="fa-regular fa-calendar-check"></i><span>กำหนดคืน: <b>' + esc(dueText(B.submitDue)) + '</b></span></div>';
                    if (B.myOverdue && B.myOverdue.count) {   // [2026-10-02 · GP-13]
                        message += '<div class="br-confirm warn"><i class="fa-solid fa-triangle-exclamation"></i><span>' + esc(overdueText(B.myOverdue)) + '</span></div>';
                    }
                } else if (B.approveCtx && B.approveCtx.type === 'BD') {
                    message = (message || '') + approvalExtraHtml(B.approveCtx, true);
                }
            } catch (e) { console.warn('[borrow-return] confirm note:', e); }
            return orig.call(this, title, message, onConfirm, confirmText, confirmClass);
        };
        w.__brWrapped = true;
        window.showConfirmPopup = w;
    }

    // RPC: เติม DueDate ให้ทุกรายการของใบยืม (หน้าส่งรายการด้วยคีย์ตายตัว — ต่อท้ายที่ชั้น google.script.run)
    function installRpcHook() {
        var gs = window.google && window.google.script;
        // ธงอยู่ที่ google.script (ไม่ใช่ที่ run — Proxy ของ gas-shim คืนฟังก์ชันให้ทุกชื่อ property จึงอ่านธงจาก run ไม่ได้)
        if (!gs || !gs.run || gs.__brRunWrapped) return;
        gs.__brRunWrapped = true;
        var addDue = function (it, due) {
            return (it && typeof it === 'object' && !it.DueDate) ? Object.assign({}, it, { DueDate: due }) : it;
        };
        var wrap = function (runner) {
            return new Proxy(runner, {
                get: function (t, p) {
                    var v = t[p];
                    if (p === 'withSuccessHandler' || p === 'withFailureHandler' || p === 'withUserObject') {
                        return function () { return wrap(v.apply(t, arguments)); };
                    }
                    if ((p === 'processBorrowBatch' || p === 'processBorrowSubmission') && typeof v === 'function') {
                        return function (payload) {
                            var due = B.submitDue || dueValue();
                            if (due) {
                                payload = Array.isArray(payload) ? payload.map(function (it) { return addDue(it, due); }) : addDue(payload, due);
                            }
                            return v.call(t, payload);
                        };
                    }
                    return v;
                }
            });
        };
        gs.run = wrap(gs.run);
    }

    // ================================================================== 2) รายการอุปกรณ์ที่ยังไม่ส่งคืน
    function ensureUnreturnedHead() {
        var table = document.getElementById('unreturnedTable');
        if (!table || table.__br) return;
        table.__br = true;
        table.classList.add('br-unret');
        var head = table.querySelector('thead tr');
        if (head) {
            head.innerHTML = '<th style="width:150px;">ใบยืม</th><th>ผู้ยืม / ผู้รับ</th><th>รายการ</th>' +
                '<th style="width:150px;">กำหนดคืน</th><th style="width:170px;"></th>';
        }
    }
    function docGroups(rows) {
        var by = {}, order = [];
        (rows || []).forEach(function (r) {
            // แคชรุ่นก่อน (ยังไม่มีฟิลด์ใหม่) — ถือว่ายืมอยู่ทั้งจำนวน จนกว่าข้อมูลสดจะมา
            if (r.outstanding == null) { r = Object.assign({}, r, { outstanding: r.qty, borrowed: r.qty, state: r.state || 'borrowed' }); }
            if (!by[r.borrowId]) {
                by[r.borrowId] = { id: r.borrowId, rows: [], due: r.dueDate || '', od: Number(r.overdueDays || 0), state: r.state || 'borrowed',
                                   gate: r.gate || '', borrower: r.borrower || '', borrowerName: r.borrowerName || r.borrower || '',
                                   sub: r.subId || '', date: r.date || '', hasWriteoff: !!r.hasWriteoff, canReturn: r.canReturn !== false,
                                   canWriteoff: !!r.canWriteoff, rtStatus: r.rtStatus || '' };
                order.push(r.borrowId);
            }
            by[r.borrowId].rows.push(r);
        });
        var list = order.map(function (k) { return by[k]; });
        var rank = function (g) { return g.od > 0 ? 0 : (g.state === 'writeoff_pending' ? 1 : 2); };
        list.sort(function (a, b) {
            var d = rank(a) - rank(b);
            if (d) return d;
            if (a.od !== b.od) return b.od - a.od;
            if ((a.due || '9999') !== (b.due || '9999')) return (a.due || '9999') < (b.due || '9999') ? -1 : 1;
            return a.id < b.id ? -1 : 1;
        });
        return list;
    }
    function itemLine(r) {
        var unit = r.unit ? ' ' + esc(r.unit) : '';
        var parts = [];
        if (r.returned != null) parts.push('คืน ' + esc(fmt(r.returned)));
        if (Number(r.writtenOff) > 0) parts.push('ชำรุด/สูญหาย ' + esc(fmt(r.writtenOff)));
        var left = Number(r.outstanding);
        var main = parts.length
            ? '<b>ค้าง ' + esc(fmt(left)) + unit + '</b> <span class="br-sub">(ยืม ' + esc(fmt(r.borrowed)) + ' · ' + parts.join(' · ') + ')</span>'
            : '<b>x' + esc(fmt(left)) + unit + '</b>';
        return '<div class="br-it' + (left <= 0.0005 ? ' done' : '') + '"><span class="br-code">' + esc(r.matCode) + '</span> ' + esc(r.matName) + ' — ' + main +
            (r.returnReason ? '<div class="br-why">เหตุผลที่คืนไม่ครบ: ' + esc(r.returnReason) + '</div>' : '') + '</div>';
    }
    function renderUnreturned(data) {
        var tbody = document.getElementById('unreturnedTableBody');
        if (!tbody) return;
        ensureUnreturnedHead();
        B.rows = Array.isArray(data) ? data : [];
        var groups = docGroups(B.rows);
        var legacy = {};
        if (!groups.length) {
            tbody.innerHTML = '<tr class="draft-empty-row"><td colspan="5">ไม่มีรายการที่ยังไม่ส่งคืน</td></tr>';
            window.unreturnedData = legacy;
            return;
        }
        tbody.innerHTML = groups.map(function (g) {
            var open = g.rows.filter(function (r) { return Number(r.outstanding) > 0.0005; });
            // requestReturnConfirm (index.php) อ่าน unreturnedData[borrowId] = {rowItems:[{matName,qty}], borrowerInfo}
            legacy[g.id] = { rowItems: open.map(function (r) { return { matCode: r.matCode, matName: r.matName, qty: r.outstanding }; }),
                             borrowerInfo: g.borrower + ' / ' + g.sub };
            var st = STATE_TXT[g.state] || { t: g.state, c: '' };
            var chips = '';
            if (g.state !== 'borrowed') chips += '<span class="br-state ' + st.c + '">' + esc(st.t) + (g.state === 'return_pending' && g.gate ? ' ที่ ' + esc(g.gate) : '') + '</span>';
            // [2026-10-08] ทยอยคืน: ใบที่คืนไปบางส่วน (กลับเป็นยืมอยู่) — คืนเพิ่มรอบถัดไป หรือสายสโตร์ตีชำรุด/สูญหาย
            var partial = g.rows.some(function (r) { return Number(r.returned || 0) > 0.0005 && Number(r.outstanding) > 0.0005; });
            if (partial && g.state === 'borrowed') chips += '<span class="br-state warn">คืนแล้วบางส่วน — ทยอยคืนต่อ หรือตีชำรุด/สูญหาย</span>';
            if (g.hasWriteoff) chips += '<span class="br-state bad"><i class="fa-solid fa-heart-crack"></i> มีรายการชำรุด/สูญหาย</span>';
            var acts = '';
            if (g.canReturn && (g.state === 'borrowed' || g.state === 'writeoff_pending')) {
                acts += '<button class="btn btn-primary br-btn" data-br="return" data-doc="' + esc(g.id) + '"><i class="fa-solid fa-rotate-left"></i> ' +
                        (partial || g.state === 'writeoff_pending' ? 'คืนเพิ่ม (ทยอยคืน)' : 'คืน') + '</button>';
            } else if (g.canReturn && g.state === 'return_pending') {
                acts += '<button class="btn btn-secondary br-btn" data-br="return" data-doc="' + esc(g.id) + '"><i class="fa-solid fa-qrcode"></i> สร้าง QR คืน</button>';
            }
            if (g.canWriteoff) {
                acts += '<button class="btn br-btn br-btn-wo" data-br="writeoff" data-doc="' + esc(g.id) + '"><i class="fa-solid fa-heart-crack"></i> ตีเป็นชำรุด/สูญหาย</button>';
            }
            if (g.hasWriteoff) {
                acts += '<button class="btn btn-secondary br-btn" data-br="report" data-doc="' + esc(g.id) + '"><i class="fa-solid fa-file-pdf"></i> รายงาน</button>';
            }
            return '<tr class="br-row' + (g.od > 0 ? ' overdue' : '') + '">' +
                '<td data-label="ใบยืม"><div class="br-doc">' + esc(g.id) + '</div>' +
                    '<div class="br-sub">' + esc(g.date) + (g.gate ? ' · <span class="cw-gate-tag">' + esc(g.gate) + '</span>' : '') + '</div></td>' +
                '<td data-label="ผู้ยืม / ผู้รับ"><div>' + esc(g.borrowerName) + '</div><div class="br-sub">' + esc(g.sub) + '</div></td>' +
                '<td data-label="รายการ">' + g.rows.map(itemLine).join('') + (chips ? '<div class="br-chips">' + chips + '</div>' : '') + '</td>' +
                '<td data-label="กำหนดคืน"><div class="br-date"><span>' + (g.due ? esc(thDate(g.due)) : '-') + '</span> ' + dueBadge(g.due, g.od) + '</div></td>' +
                '<td class="br-acts">' + (acts || '<span class="br-sub">—</span>') + '</td>' +
            '</tr>';
        }).join('');
        window.unreturnedData = legacy;
    }
    function loadUnreturned(opts) {
        var tbody = document.getElementById('unreturnedTableBody');
        if (!tbody) return;
        ensureUnreturnedHead();
        var u = window.user || {};
        var roleLevel = u.roleLevel || '', roleName = u.role || '';
        var userName = typeof window.getEffectiveUserName === 'function' ? window.getEffectiveUserName() : (u.username || '');
        var site = typeof window.getEffectiveSiteCode === 'function' ? window.getEffectiveSiteCode() : (u.siteCode || '');
        var key = [roleLevel, userName, site, roleName];
        var cache = window.connextCache;
        if (!cache || !cache.get || !cache.get('unreturned', key)) {
            tbody.innerHTML = '<tr class="draft-empty-row"><td colspan="5"><i class="fa-solid fa-spinner fa-spin"></i> กำลังโหลด...</td></tr>';
        }
        var fetcher = function (done, fail) {
            google.script.run.withSuccessHandler(done).withFailureHandler(fail).getUnreturnedItems(roleLevel, userName, site, roleName);
        };
        var onErr = function (err) {
            console.error('[borrow-return] unreturned:', err);
            tbody.innerHTML = '<tr class="draft-empty-row"><td colspan="5" style="color:var(--danger);">โหลดข้อมูลล้มเหลว</td></tr>';
        };
        if (cache && typeof cache.swr === 'function') cache.swr('unreturned', key, fetcher, renderUnreturned, onErr, opts);
        else fetcher(renderUnreturned, onErr);
    }
    function reloadAfterWrite() {
        try { if (window.connextCache && window.connextCache.invalidateMany) window.connextCache.invalidateMany(['unreturned', 'approvedDocs', 'history']); } catch (e) {}
        loadUnreturned({ force: true });
        loadAlerts();
    }
    function installUnreturned() {
        if (typeof window.loadUnreturnedItems !== 'function') return;
        window.loadUnreturnedItems = loadUnreturned;
        var table = document.getElementById('unreturnedTable');
        if (table && !table.__brClick) {
            table.__brClick = true;
            table.addEventListener('click', function (ev) {
                var b = ev.target.closest('[data-br]');
                if (!b) return;
                var doc = b.getAttribute('data-doc');
                var act = b.getAttribute('data-br');
                if (act === 'return' && typeof window.requestReturnConfirm === 'function') window.requestReturnConfirm(doc);
                else if (act === 'writeoff') openWriteoff(doc);
                else if (act === 'report') openLossReport(doc);
            });
        }
        if (document.getElementById('unreturnedTableBody') && window.user) loadUnreturned({});
    }

    // ================================================================== 3) หน้าต่างตีเป็นชำรุด/สูญหาย
    var W = { doc: null, items: [], photos: [], busy: false };
    function modalEl() {
        var m = document.getElementById('brWoModal');
        if (m) return m;
        m = document.createElement('div');
        m.id = 'brWoModal';
        m.className = 'br-modal';
        m.setAttribute('role', 'dialog');
        m.setAttribute('aria-modal', 'true');
        m.setAttribute('aria-labelledby', 'brWoTitle');
        m.innerHTML = '<div class="br-modal-card"><div class="br-modal-head"><div id="brWoTitle" class="br-modal-title"></div>' +
            '<button type="button" class="br-x" data-wo="close" aria-label="ปิด">&times;</button></div>' +
            '<div class="br-modal-body" id="brWoBody"></div><div class="br-modal-foot" id="brWoFoot"></div></div>';
        document.body.appendChild(m);
        m.addEventListener('click', onWoClick);
        m.addEventListener('input', onWoInput);
        m.addEventListener('change', onWoChange);
        document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && m.classList.contains('open')) closeWo(); });
        return m;
    }
    function closeWo() {
        var m = document.getElementById('brWoModal');
        if (m) m.classList.remove('open');
        document.body.classList.remove('br-noscroll');
        W.doc = null; W.items = []; W.photos = [];
    }
    function openWriteoff(docNo) {
        if (typeof window.showLoadingPopup === 'function') window.showLoadingPopup('กำลังโหลด', 'ใบยืม ' + docNo);
        google.script.run
            .withSuccessHandler(function (r) {
                if (typeof window.closeAppPopup === 'function') window.closeAppPopup();
                if (!r || !r.success) { info('เปิดไม่ได้', esc((r && r.message) || 'โหลดข้อมูลไม่สำเร็จ'), 'danger'); return; }
                if (!r.canWriteoff) { info('ตีเป็นชำรุด/สูญหายไม่ได้', esc(r.blocked || 'ไม่มีสิทธิ์'), 'warning'); return; }
                W.doc = r.doc;
                W.items = (r.items || []).filter(function (it) { return Number(it.outstanding) > 0.0005; }).map(function (it) {
                    return Object.assign({}, it, { on: false, qty: String(fmt(it.outstanding)), kind: 'lost', price: '' });
                });
                W.history = r.history || [];
                W.photos = [];
                W.reason = '';
                if (W.items.length === 1) W.items[0].on = true;
                // หลังขาคืนที่คืนไม่ครบ: เหตุผลตั้งต้นจากตอนคืน
                var rr = (r.items || []).map(function (it) { return it.returnReason; }).filter(Boolean);
                if (rr.length) W.reason = rr[0];
                var m = modalEl();   // สร้างโครงหน้าต่างก่อน render
                renderWo();
                m.classList.add('open');
                document.body.classList.add('br-noscroll');
            })
            .withFailureHandler(function (e) {
                if (typeof window.closeAppPopup === 'function') window.closeAppPopup();
                info('เปิดไม่ได้', esc((e && e.message) || String(e)), 'danger');
            })
            .getBorrowWriteoffInfo(docNo);
    }
    function woCheck() {
        var sel = W.items.filter(function (it) { return it.on; });
        var errs = [];
        var total = 0, noPrice = 0;
        sel.forEach(function (it) {
            var q = Number(String(it.qty).replace(/,/g, ''));
            if (!isFinite(q) || q <= 0) errs.push(it.matCode + ': ใส่จำนวนที่ตี (มากกว่า 0)');
            else if (q > Number(it.outstanding) + 0.0005) errs.push(it.matCode + ': ตีได้ไม่เกินยอดค้าง ' + fmt(it.outstanding));
            var price = it.refPrice != null ? Number(it.refPrice) : (String(it.price).trim() !== '' ? Number(it.price) : null);
            if (price != null && (!isFinite(price) || price < 0)) errs.push(it.matCode + ': มูลค่าอ้างอิงต้องเป็นตัวเลข');
            if (price != null && isFinite(price) && isFinite(q)) total += price * q; else noPrice++;
        });
        if (!sel.length) errs.unshift('เลือกรายการที่จะตีอย่างน้อย 1 รายการ');
        if (String(W.reason || '').trim().length < 3) errs.push('ใส่เหตุผล (อย่างน้อย 3 ตัวอักษร)');
        return { sel: sel, errs: errs, total: total, noPrice: noPrice };
    }
    function renderWo() {
        var d = W.doc;
        $('#brWoTitle').innerHTML = '<i class="fa-solid fa-heart-crack"></i> ตีเป็นชำรุด/สูญหาย — ' + esc(d.docNo);
        var hist = (W.history || []).length
            ? '<div class="br-wo-hist"><div class="br-wo-sec">ตีไปแล้ว</div>' + W.history.map(function (h) {
                  return '<div class="br-wo-hrow">' + esc(h.at) + ' · <b>' + esc(h.matCode) + '</b> ' + esc(fmt(h.qty)) + ' ' + esc(h.unit || '') +
                      ' (' + (h.kind === 'damaged' ? 'ชำรุด' : 'สูญหาย') + ') · ' + esc(h.reason) + ' · ' + esc(h.by) + '</div>';
              }).join('') + '</div>' : '';
        var items = W.items.map(function (it, i) {
            var priceHtml = it.refPrice != null
                ? '<div class="br-wo-price">มูลค่าอ้างอิง <b>' + esc(money(it.refPrice)) + '</b> บาท/' + esc(it.unit || 'หน่วย') + ' (rate card)</div>'
                : '<label class="br-wo-price">มูลค่าอ้างอิง/หน่วย <span class="br-sub">(ยังไม่ตั้งราคาใน rate card — ไม่บังคับ)</span>' +
                  '<input type="number" inputmode="decimal" min="0" step="any" data-wo="price" data-i="' + i + '" value="' + esc(it.price) + '" placeholder="บาท"></label>';
            return '<div class="br-wo-item' + (it.on ? ' on' : '') + '">' +
                '<label class="br-wo-pick"><input type="checkbox" data-wo="on" data-i="' + i + '"' + (it.on ? ' checked' : '') + '>' +
                    '<span><span class="br-code">' + esc(it.matCode) + '</span> ' + esc(it.matName) + '</span></label>' +
                '<div class="br-sub">ยืม ' + esc(fmt(it.borrowed)) + (it.returned != null ? ' · คืน ' + esc(fmt(it.returned)) : '') +
                    (Number(it.writtenOff) > 0 ? ' · ตีแล้ว ' + esc(fmt(it.writtenOff)) : '') + ' · <b>ค้าง ' + esc(fmt(it.outstanding)) + ' ' + esc(it.unit || '') + '</b></div>' +
                '<div class="br-wo-fields"' + (it.on ? '' : ' hidden') + '>' +
                    '<label>จำนวนที่ตี <input type="number" inputmode="decimal" min="0" step="any" data-wo="qty" data-i="' + i + '" value="' + esc(it.qty) + '"></label>' +
                    '<div class="br-seg" role="radiogroup" aria-label="ประเภท">' +
                        '<button type="button" data-wo="kind" data-k="lost" data-i="' + i + '" class="' + (it.kind === 'lost' ? 'on' : '') + '">สูญหาย</button>' +
                        '<button type="button" data-wo="kind" data-k="damaged" data-i="' + i + '" class="' + (it.kind === 'damaged' ? 'on' : '') + '">ชำรุด</button>' +
                    '</div>' + priceHtml +
                '</div>' +
            '</div>';
        }).join('');
        var thumbs = W.photos.map(function (p, i) {
            return '<div class="s5-thumb"><img src="' + p + '" alt="รูป ' + (i + 1) + '"><button type="button" class="s5-thumb-x" data-wo="rm-photo" data-i="' + i + '" aria-label="ลบรูป">&times;</button></div>';
        }).join('');
        $('#brWoBody').innerHTML =
            '<div class="br-wo-info">ผู้ยืม <b>' + esc(d.borrowerName) + '</b> · ' + esc(d.sub) + '<br>กำหนดคืน ' + esc(d.dueDate ? thDate(d.dueDate) : '-') + ' ' + dueBadge(d.dueDate, d.overdueDays) +
                ' · ' + esc((STATE_TXT[d.state] || { t: d.status }).t) + '</div>' +
            '<div class="br-wo-note"><i class="fa-solid fa-circle-info"></i> ของที่ตีจะออกจากสต๊อกถาวร (ไม่คืนยอดเข้า G) · ระบบออกรายงานให้พิจารณา <b>ไม่หักเงินอัตโนมัติ</b> — ' +
                'แจ้งผู้เบิก ผู้อนุมัติของใบ และผู้จัดการโครงการ</div>' +
            '<div class="br-wo-sec">รายการที่ค้างคืน</div>' + (items || '<div class="br-sub">ไม่มีรายการค้าง</div>') +
            '<div class="br-wo-sec">เหตุผล <span style="color:#dc2626">*</span></div>' +
            '<textarea class="form-control" rows="2" maxlength="250" data-wo="reason" placeholder="เช่น หายระหว่างใช้งานที่ชั้น 3 / ตกจากที่สูงเสียหาย">' + esc(W.reason || '') + '</textarea>' +
            '<div class="s5-chips">' + ['หายระหว่างใช้งาน', 'ชำรุดจนใช้งานไม่ได้', 'ผู้ยืมไม่นำมาคืน', 'ถูกขโมย'].map(function (t) {
                return '<button type="button" class="s5-chip" data-wo="reason-chip" data-v="' + esc(t) + '">' + esc(t) + '</button>';
            }).join('') + '</div>' +
            '<div class="br-wo-sec">รูปประกอบ <span class="br-sub">(ถ้ามี · สูงสุด 3 รูป)</span></div>' +
            '<div class="s5-photos">' + thumbs + (W.photos.length < 3
                ? '<label class="s5-add cam"><i class="fa-solid fa-camera"></i><span>ถ่ายรูป</span><input type="file" accept="image/*" capture="environment" data-wo="photo"></label>' +
                  '<label class="s5-add"><i class="fa-solid fa-images"></i><span>เลือกไฟล์</span><input type="file" accept="image/*" multiple data-wo="photo"></label>' : '') + '</div>' +
            hist;
        renderWoFoot();
    }
    function renderWoFoot() {
        var c = woCheck();
        var foot = $('#brWoFoot');
        if (!foot) return;
        foot.innerHTML = '<div class="br-wo-sum ' + (c.errs.length ? 'wait' : 'ready') + '">' +
                (c.errs.length ? '<i class="fa-solid fa-triangle-exclamation"></i> ' + esc(c.errs[0]) + (c.errs.length > 1 ? ' (+' + (c.errs.length - 1) + ')' : '')
                    : '<i class="fa-solid fa-circle-check"></i> ตี ' + c.sel.length + ' รายการ · มูลค่าอ้างอิงรวม ' + esc(money(c.total)) + ' บาท' +
                      (c.noPrice ? ' (ยังไม่มีราคา ' + c.noPrice + ' รายการ)' : '')) + '</div>' +
            '<button type="button" class="btn btn-secondary" data-wo="close">ยกเลิก</button>' +
            '<button type="button" class="btn btn-danger" data-wo="save"' + (c.errs.length || W.busy ? ' disabled' : '') + '><i class="fa-solid fa-heart-crack"></i> ยืนยันตี</button>';
    }
    function onWoInput(ev) {
        var t = ev.target, role = t.getAttribute && t.getAttribute('data-wo');
        if (!role) return;
        var i = parseInt(t.getAttribute('data-i'), 10);
        if (role === 'qty' && W.items[i]) W.items[i].qty = t.value;
        else if (role === 'price' && W.items[i]) W.items[i].price = t.value;
        else if (role === 'reason') W.reason = t.value;
        renderWoFoot();
    }
    function onWoChange(ev) {
        var t = ev.target, role = t.getAttribute && t.getAttribute('data-wo');
        if (role === 'on') {
            var i = parseInt(t.getAttribute('data-i'), 10);
            if (W.items[i]) { W.items[i].on = t.checked; }
            var box = t.closest('.br-wo-item');
            if (box) {
                box.classList.toggle('on', t.checked);
                var f = box.querySelector('.br-wo-fields');
                if (f) f.hidden = !t.checked;
            }
            renderWoFoot();
        } else if (role === 'photo') {
            var files = Array.prototype.slice.call(t.files || []).slice(0, 3 - W.photos.length);
            if (!files.length || typeof window.compressImage !== 'function') return;
            Promise.all(files.map(function (f) { return window.compressImage(f); })).then(function (res) {
                res.forEach(function (r) { if (r && r.dataUrl && W.photos.length < 3) W.photos.push(r.dataUrl); });
                renderWo();
            }).catch(function () { toast('บีบอัดรูปไม่สำเร็จ ลองใหม่อีกครั้ง', 'warning'); });
        }
    }
    function onWoClick(ev) {
        var m = document.getElementById('brWoModal');
        if (ev.target === m) { closeWo(); return; }
        var b = ev.target.closest('[data-wo]');
        if (!b || b.tagName === 'INPUT' || b.tagName === 'TEXTAREA') return;
        var role = b.getAttribute('data-wo');
        var i = parseInt(b.getAttribute('data-i'), 10);
        if (role === 'close') { closeWo(); return; }
        if (role === 'kind' && W.items[i]) {
            W.items[i].kind = b.getAttribute('data-k');
            Array.prototype.forEach.call(b.parentNode.querySelectorAll('button'), function (x) { x.classList.toggle('on', x === b); });
        } else if (role === 'reason-chip') {
            W.reason = b.getAttribute('data-v') || '';
            var ta = $('#brWoBody textarea[data-wo="reason"]');
            if (ta) ta.value = W.reason;
        } else if (role === 'rm-photo') {
            W.photos.splice(i, 1);
            renderWo();
            return;
        } else if (role === 'save') {
            saveWo();
            return;
        }
        renderWoFoot();
    }
    function saveWo() {
        var c = woCheck();
        if (c.errs.length || W.busy || !W.doc) return;
        var docNo = W.doc.docNo;
        var lines = c.sel.map(function (it) {
            return '• ' + esc(it.matCode) + ' ' + esc(fmt(it.qty)) + ' ' + esc(it.unit || '') + ' — ' + (it.kind === 'damaged' ? 'ชำรุด' : 'สูญหาย');
        }).join('<br>');
        var run = function () {
            W.busy = true;
            renderWoFoot();
            var payload = {
                docNo: docNo,
                reason: String(W.reason || '').trim(),
                items: c.sel.map(function (it) {
                    return { itemId: it.itemId, qty: String(it.qty).replace(/,/g, ''), kind: it.kind,
                             price: it.refPrice != null ? null : (String(it.price).trim() !== '' ? String(it.price).trim() : null) };
                }),
                photos: W.photos.slice()
            };
            if (typeof window.showLoadingPopup === 'function') window.showLoadingPopup('กำลังบันทึก', 'ตีเป็นชำรุด/สูญหาย — ' + docNo);
            google.script.run
                .withSuccessHandler(function (r) {
                    W.busy = false;
                    if (typeof window.closeAppPopup === 'function') window.closeAppPopup();
                    if (!r || !r.success) { info('บันทึกไม่สำเร็จ', esc((r && r.message) || 'บันทึกไม่สำเร็จ').replace(/\n/g, '<br>'), 'danger'); renderWoFoot(); return; }
                    closeWo();
                    if (typeof window.hapticSuccess === 'function') window.hapticSuccess();
                    reloadAfterWrite();
                    var msg = esc(r.message || '') + (r.notified ? '<br>แจ้งผู้เกี่ยวข้องแล้ว ' + r.notified + ' คน' : '') +
                        '<br>มูลค่าอ้างอิงรวม ' + esc(money(r.totalValue)) + ' บาท' + (r.noPrice ? ' (ยังไม่มีราคา ' + r.noPrice + ' รายการ)' : '');
                    if (typeof window.showAppPopup === 'function') {
                        window.showAppPopup({ type: 'success', title: 'บันทึกแล้ว — ' + docNo, message: msg, buttons: [
                            { text: 'ปิด', className: 'btn btn-secondary', onClick: window.closeAppPopup },
                            { text: 'ดาวน์โหลดรายงาน PDF', className: 'btn btn-primary', onClick: function () { window.closeAppPopup(); openLossReport(docNo); } }
                        ] });
                    } else {
                        toast('บันทึกแล้ว — ' + docNo, 'success');
                    }
                })
                .withFailureHandler(function (e) {
                    W.busy = false;
                    if (typeof window.closeAppPopup === 'function') window.closeAppPopup();
                    info('เชื่อมต่อไม่สำเร็จ', esc((e && e.message) || String(e)), 'danger');
                    renderWoFoot();
                })
                .writeOffBorrowItems(payload);
        };
        if (typeof window.showConfirmPopup === 'function') {
            window.showConfirmPopup('ยืนยันตีเป็นชำรุด/สูญหาย', '<b>' + esc(docNo) + '</b><br>' + lines +
                '<br><br>เหตุผล: ' + esc(W.reason) + '<br><span style="color:var(--text-muted)">บันทึกแล้วแก้ไม่ได้ · ของออกจากสต๊อกถาวร · ระบบไม่หักเงินอัตโนมัติ</span>',
                run, 'ยืนยันตี', 'btn btn-danger');
        } else {
            run();
        }
    }

    // ================================================================== 4) การ์ด QR + หน้าการอนุมัติ
    function decorateQr() {
        var data = Array.isArray(window.qrPageData) ? window.qrPageData : [];
        if (!data.length) return;
        var by = {};
        data.forEach(function (d) { by[d.docId] = d; });
        Array.prototype.forEach.call(document.querySelectorAll('#qrCardsGrid .qr-doc-card'), function (card) {
            if (card.__br) return;
            var idEl = card.querySelector('.qrc-doc-id');
            var d = idEl ? by[String(idEl.textContent || '').trim()] : null;
            if (!d || d.type !== 'BD') return;
            card.__br = true;
            var sec = card.querySelectorAll('.qrc-section')[1];
            if (!sec) return;
            var row = document.createElement('div');
            row.className = 'qrc-meta-row br-qr-due';
            row.innerHTML = '<span class="qrc-label">' + (d.isReturn ? 'กำหนดคืน:' : 'ต้องคืนภายใน:') + '</span>' +
                '<span class="qrc-value">' + (d.dueDate ? esc(thDate(d.dueDate)) + ' ' + dueBadge(d.dueDate, d.overdueDays) : '-') + '</span>';
            sec.appendChild(row);
        });
    }
    function approvalExtraHtml(item, inline) {
        var h = '';
        if (item.dueDate) {
            h += '<div class="br-ap"><i class="fa-regular fa-calendar"></i> กำหนดคืน <b>' + esc(thDate(item.dueDate)) + '</b> ' + dueBadge(item.dueDate) + '</div>';
        } else if (!inline) {
            h += '<div class="br-ap muted"><i class="fa-regular fa-calendar"></i> ไม่มีกำหนดคืน (ใบรุ่นเก่า)</div>';
        }
        var n = Number(item.borrowerOverdue || 0);
        if (n > 0) {
            var docs = (item.borrowerOverdueDocs || []).map(function (x) { return esc(x.docNo) + ' (' + x.days + ' วัน)'; }).join(', ');
            h += '<div class="br-ap bad"><i class="fa-solid fa-triangle-exclamation"></i> ผู้ยืมมีใบยืมเกินกำหนดที่ยังไม่คืน <b>' + n + ' ใบ</b>' + (docs ? ': ' + docs : '') + '</div>';
        } else if (!inline) {
            h += '<div class="br-ap ok"><i class="fa-solid fa-circle-check"></i> ผู้ยืมไม่มีใบยืมเกินกำหนดค้าง</div>';
        }
        return h;
    }
    function installApproval() {
        var orig = window._renderApprovalCard;
        if (typeof orig === 'function' && !orig.__brWrapped) {
            var w = function (item, variant) {
                var html = orig.apply(this, arguments);
                try {
                    if (item && item.type === 'BD' && typeof html === 'string') {
                        var extra = approvalExtraHtml(item, false);
                        var k = html.indexOf('<div class="card-actions">');
                        html = k >= 0 ? html.slice(0, k) + '<div class="br-ap-wrap">' + extra + '</div>' + html.slice(k) : html + extra;
                    }
                } catch (e) { console.warn('[borrow-return] approval card:', e); }
                return html;
            };
            w.__brWrapped = true;
            window._renderApprovalCard = w;
        }
        var origUp = window.updateApprovalStatusClient;
        if (typeof origUp === 'function' && !origUp.__brWrapped) {
            var wu = function (index, newStatus) {
                var list = Array.isArray(window.approvalQueueData) ? window.approvalQueueData : [];
                B.approveCtx = newStatus === 'Approved' ? (list[index] || null) : null;
                try { return origUp.apply(this, arguments); } finally { B.approveCtx = null; }
            };
            wu.__brWrapped = true;
            window.updateApprovalStatusClient = wu;
        }
    }

    // ================================================================== 5) Dashboard: อุปกรณ์ยืมค้างคืน
    function alertsEl() {
        var el = document.getElementById('brAlerts');
        if (el) return el;
        var page = document.getElementById('dashboard-page');
        if (!page) return null;
        el = document.createElement('div');
        el.id = 'brAlerts';
        el.hidden = true;
        var s5 = document.getElementById('s5Alerts');
        var ov = document.getElementById('iiOverview');
        if (s5 && s5.parentNode) s5.parentNode.insertBefore(el, s5.nextSibling);
        else if (ov) ov.insertBefore(el, ov.firstChild);
        else {
            var head = page.querySelector('.page-header');
            if (head && head.parentNode) head.parentNode.insertBefore(el, head.nextSibling); else page.insertBefore(el, page.firstChild);
        }
        return el;
    }
    function loadAlerts() {
        if (B.busyAlerts || !window.user || !seesSite()) return;
        if (!document.getElementById('dashboard-page')) return;
        B.busyAlerts = true;
        var site = '';
        try { if (typeof window.getDashboardSiteFilter === 'function') site = window.getDashboardSiteFilter() || ''; } catch (e) {}
        google.script.run
            .withSuccessHandler(function (d) { B.busyAlerts = false; renderAlerts(d); })
            .withFailureHandler(function () { B.busyAlerts = false; })
            .getBorrowAlerts(site);
    }
    function alertRow(r, right) {
        return '<div class="s5-alert-row">' +
            '<span class="s5-g">' + esc(r.gate || '-') + '</span>' +
            '<span class="s5-mat"><b>' + esc(r.docNo) + '</b> ' + esc(r.borrowerName) + ' · ' + esc(r.sub) + '<br><span class="br-sub">' + esc((r.items || []).join(', ')) + '</span></span>' +
            '<span class="s5-short">' + right + '</span>' +
            '<span class="s5-when">' + (r.dueDate ? 'กำหนด ' + esc(thDate(r.dueDate)) : '') + '</span>' +
        '</div>';
    }
    function renderAlerts(d) {
        var el = alertsEl();
        if (!el) return;
        if (!d || !d.success) { el.hidden = true; el.innerHTML = ''; return; }
        var od = d.overdue || [], wp = d.writeoffPending || [], soon = d.dueSoon || [];
        if (!od.length && !wp.length && !soon.length && !d.noDueDate) { el.hidden = true; el.innerHTML = ''; return; }
        var html = '<div class="s5-alert-card br-alert-card">' +
            '<div class="s5-alert-head"><i class="fa-solid fa-hand-holding-hand"></i> อุปกรณ์ยืมค้างคืน' +
            '<span class="s5-alert-site">' + esc(d.siteCode || '') + ' · ค้าง ' + (d.open || 0) + ' ใบ</span></div>';
        var ob = d.overdueBorrowers || [];   // [2026-10-02 · GP-13] ผู้ยืมที่มีของเกินกำหนด (สรุปรายคน)
        if (ob.length) {
            html += '<div class="s5-alert-sec"><div class="s5-alert-title"><i class="fa-solid fa-user-clock"></i> ผู้ยืมที่มีของเกินกำหนด — ยืมใหม่ได้แต่ขึ้นคำเตือน <span class="s5-cnt">' + ob.length + '</span></div>' +
                '<div class="s5-alert-list">' + ob.slice(0, 10).map(function (b) {
                    return '<div class="s5-alert-row"><span class="s5-mat"><b>' + esc(b.borrowerName || b.borrower) + '</b> · ' + b.docs + ' ใบ' +
                        '<br><span class="br-sub">' + esc((b.docNos || []).join(', ')) + '</span></span>' +
                        '<span class="s5-short">เกินสูงสุด ' + b.maxDays + ' วัน</span></div>';
                }).join('') + '</div></div>';
        }
        if (od.length) {
            html += '<div class="s5-alert-sec"><div class="s5-alert-title"><i class="fa-solid fa-calendar-xmark"></i> เกินกำหนดคืน <span class="s5-cnt">' + od.length + '</span></div>' +
                '<div class="s5-alert-list">' + od.slice(0, 12).map(function (r) { return alertRow(r, 'เกิน ' + r.days + ' วัน'); }).join('') + '</div></div>';
        }
        if (wp.length) {
            html += '<div class="s5-alert-sec"><div class="s5-alert-title warn"><i class="fa-solid fa-heart-crack"></i> คืนไม่ครบ — รอทยอยคืน / ตีชำรุด/สูญหาย <span class="s5-cnt">' + wp.length + '</span></div>' +
                '<div class="s5-alert-list">' + wp.slice(0, 10).map(function (r) { return alertRow(r, 'ค้าง'); }).join('') + '</div></div>';
        }
        if (soon.length) {
            html += '<div class="s5-alert-sec"><div class="s5-alert-title info"><i class="fa-regular fa-calendar"></i> ใกล้ครบกำหนด (ภายใน 2 วัน) <span class="s5-cnt info">' + soon.length + '</span></div>' +
                '<div class="s5-alert-list">' + soon.slice(0, 10).map(function (r) {
                    var left = daysBetween(today(), r.dueDate);
                    return alertRow(r, left <= 0 ? 'วันนี้' : 'อีก ' + left + ' วัน');
                }).join('') + '</div></div>';
        }
        if (d.noDueDate) html += '<div class="s5-alert-note">ใบยืมรุ่นก่อนมีกำหนดคืน (ไม่มีกำหนด) ค้างอยู่ ' + d.noDueDate + ' ใบ — ไม่ถูกนับเกินกำหนด</div>';
        html += '<div class="br-alert-go"><button type="button" class="btn btn-secondary" data-br-go="1"><i class="fa-solid fa-list"></i> ไปที่รายการค้างคืน</button></div></div>';
        el.innerHTML = html;
        el.hidden = false;
        var go = el.querySelector('[data-br-go]');
        if (go) go.addEventListener('click', gotoUnreturned);
    }
    function gotoUnreturned() {
        try {
            if (typeof window.switchToPage === 'function') window.switchToPage('requisition');
            var btn = document.querySelector('#requisition-page .tab-btn[onclick*="req-borrow"]');
            if (btn && typeof window.switchRequisitionTab === 'function') window.switchRequisitionTab('req-borrow', btn);
            setTimeout(function () {
                var t = document.getElementById('unreturnedTable');
                if (t) t.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, 250);
        } catch (e) { console.warn('[borrow-return] goto:', e); }
    }
    function installDashboard() {
        wrapAfter('loadDashboard', function () { setTimeout(loadAlerts, 400); });
        var sel = document.getElementById('dashboardSiteFilter') || document.querySelector('#dashboard-page select[id*="Site"]');
        if (sel && !sel.__br) { sel.__br = true; sel.addEventListener('change', function () { setTimeout(loadAlerts, 400); }); }
    }

    // ================================================================== 6) แจ้งเตือนในแอป
    var N = { busy: false, timer: null, started: false };
    function noticesEl() {
        var el = document.getElementById('brNotices');
        if (el) return el;
        el = document.createElement('div');
        el.id = 'brNotices';
        el.setAttribute('role', 'region');
        el.setAttribute('aria-label', 'แจ้งเตือน');
        document.body.appendChild(el);
        el.addEventListener('click', function (ev) {
            var b = ev.target.closest('[data-bn]');
            if (!b) return;
            var act = b.getAttribute('data-bn'), id = b.getAttribute('data-id');
            if (act === 'report') { openLossReport(b.getAttribute('data-doc')); return; }
            if (act === 'ack' || act === 'ack-all') {
                b.disabled = true;
                google.script.run
                    .withSuccessHandler(function () { pollNotices(); })
                    .withFailureHandler(function () { b.disabled = false; })
                    .ackNotices(act === 'ack-all' ? 'all' : [parseInt(id, 10)]);
            }
        });
        return el;
    }
    function renderNotices(r) {
        var el = noticesEl();
        var list = (r && r.success && r.data) || [];
        if (!list.length) { el.innerHTML = ''; el.hidden = true; return; }
        var more = Math.max(0, (r.unread || list.length) - 2);
        el.innerHTML = list.slice(0, 2).map(function (n) {
            return '<div class="br-notice" role="status">' +
                '<div class="br-notice-t"><i class="fa-solid fa-bell"></i> ' + esc(n.title) + '</div>' +
                '<div class="br-notice-b">' + esc(n.body) + '</div>' +
                '<div class="br-notice-m">' + esc(n.at) + (n.by ? ' · โดย ' + esc(n.by) : '') + '</div>' +
                '<div class="br-notice-a">' +
                    (n.kind === 'borrow_writeoff' && n.refDoc ? '<button type="button" class="btn btn-secondary" data-bn="report" data-doc="' + esc(n.refDoc) + '"><i class="fa-solid fa-file-pdf"></i> รายงาน</button>' : '') +
                    '<button type="button" class="btn btn-primary" data-bn="ack" data-id="' + n.id + '">รับทราบ</button>' +
                '</div></div>';
        }).join('') + (more ? '<div class="br-notice-more">อีก ' + more + ' รายการ · <button type="button" data-bn="ack-all">รับทราบทั้งหมด</button></div>' : '');
        el.hidden = false;
    }
    function pollNotices() {
        if (N.busy || !window.user) return;
        N.busy = true;
        google.script.run
            .withSuccessHandler(function (r) { N.busy = false; renderNotices(r); })
            .withFailureHandler(function () { N.busy = false; })
            .getMyNotices();
    }
    function installNotices() {
        if (N.started) return;
        N.started = true;
        var waitUser = setInterval(function () {
            if (!window.user) return;
            clearInterval(waitUser);
            pollNotices();
            N.timer = setInterval(function () { if (document.visibilityState !== 'hidden') pollNotices(); }, 5 * 60 * 1000);
        }, 3000);
        document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') pollNotices(); });
    }

    // ------------------------------------------------------------------ boot
    function boot() {
        try { installRpcHook(); } catch (e) { console.warn('[borrow-return] rpc', e); }
        try { installBorrowForm(); } catch (e) { console.warn('[borrow-return] form', e); }
        try { installConfirmNotes(); } catch (e) { console.warn('[borrow-return] confirm', e); }
        try { installUnreturned(); } catch (e) { console.warn('[borrow-return] unreturned', e); }
        try { wrapAfter('renderQRCards', decorateQr); } catch (e) { console.warn('[borrow-return] qr', e); }
        try { installApproval(); } catch (e) { console.warn('[borrow-return] approval', e); }
        try { installDashboard(); } catch (e) { console.warn('[borrow-return] dashboard', e); }
        try { installNotices(); } catch (e) { console.warn('[borrow-return] notices', e); }
        window.BorrowReturn = { state: B, loadUnreturned: loadUnreturned, openWriteoff: openWriteoff, loadAlerts: loadAlerts, pollNotices: pollNotices };
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
