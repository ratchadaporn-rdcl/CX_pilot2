/*
 * CONNEXT — js/scenario05.js  [PHP port 2026-09-28 · เอกสาร 05 Scenario การทำงานของระบบ]
 *
 * 1) หน้า "ถ่ายรูปยืนยัน" (แทน renderConfirmWizard เดิม — ข้อมูล/poll ของหน้ายังเป็นของ index.php):
 *    - รูปผูกกับรายการ (รหัส IC) อย่างน้อยรายการละ 1 รูป · รายการที่หยิบจริง 0 ไม่ต้องถ่าย (กติกาข้อ 21)
 *    - ช่อง "หยิบจริง" ต่อรายการ (ใบเบิก/ยืมขาออก): ลดได้ถึง 0 พร้อมเหตุผล · ห้ามเกินจำนวนที่ขอ (⑦ ฉบับแก้ 2026-09-29
 *      — ต้องการมากกว่าที่ขอให้ออกใบใหม่ · เดิม +3) · ขาคืนของใบยืม: ช่อง "คืนจริง" ลดได้ถึง 0 พร้อมเหตุผล (③ ขั้น 4)
 *    - บันทึกทีละใบได้ทันที ไม่ต้องรอใบอื่นในรอบ · ไม่มีการล็อกชุดถัดไปแล้ว
 *    - แถบสถานะรอบ: "ยืนยันแล้ว x/y ใบ" → เข้าโหมด "รอปิดประตู" เฉพาะเมื่อทุกใบของรอบยืนยันครบ → "ปิดประตูแล้ว" (① ขั้น 6 · ⑥)
 * 2) ตรวจสอบประจำวัน: จำนวน = หยิบจริง พร้อม "(ขอ N)" เมื่อต่างกัน · ป้าย bypass
 * 3) Dashboard: การ์ด "ต้องตรวจสอบ" — ของไม่พอตอนหยิบ (ควรตรวจนับที่ G) + รอบที่เวลาเกินเพดาน (⑦ ⑧)
 * RPC ฝั่ง server: lib/gate_api.php (getConfirmableDocuments · saveConfirmationData · getPickRoundState) · lib/pick_alerts.php
 * ไม่มีใน GAS — ถ้า build index.php ใหม่ให้เติม <link>/<script defer> ไฟล์นี้กลับก่อน </head>
 */
(function () {
    'use strict';

    // ⑦ ฉบับแก้ 2026-09-29: หยิบจริงห้ามเกินจำนวนที่ขอ (เพดาน = maxQty จาก server = ที่ขอ · ขาคืน = ยอดค้าง)
    // [2026-10-02 · GP-05] ขาออก: เหตุผลเลือกจากรายการเท่านั้น (ตรงกับ S05_PICK_REASONS ใน lib/s05.php) — Dashboard นับ "ของไม่พอ" จากค่านี้
    //   "สแกนผิด" = ใบที่สแกนผิดหลังแตะบัตร ลดหยิบจริงเป็น 0 แทนการคืนด้วยใบ IN · ขาคืนของใบยืมยังพิมพ์เหตุผลเองได้
    var PICK_REASONS = ['ของไม่พอ', 'สแกนผิด', 'ไม่ต้องการแล้ว'];
    var RET_REASONS = ['ยังใช้งานอยู่ — ทยอยคืน', 'ของหาย', 'ชำรุดจนใช้ไม่ได้', 'ผู้ยืมไม่ได้นำมาคืน'];   // [2026-10-08] + ทยอยคืน

    var C = {
        idx: 0,
        photos: {},     // docId → itemId → [{dataUrl}]
        actual: {},     // docId → itemId → ค่าที่พิมพ์ (string)
        reason: {},     // docId → itemId → string
        notes: {},      // docId → string
        rounds: {},     // pickingId → สรุปจาก server
        watch: {},      // pickingId → {gate, since, doneAt}
        pollTimer: null,
        pollBusy: false,
        saving: false
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
    function fmt(v) {
        if (typeof window.formatBalanceValue === 'function') return window.formatBalanceValue(v);
        var n = Math.round(Number(v) * 1000) / 1000;
        return isFinite(n) ? String(n) : '0';
    }
    function toast(m, t) { if (typeof window.showToast === 'function') window.showToast(m, t || 'info'); }
    function docs() { return Array.isArray(window.confirmDocsData) ? window.confirmDocsData : []; }
    function round3(n) { return Math.round(n * 1000) / 1000; }
    function num(v) {
        if (v == null) return NaN;
        var s = String(v).trim().replace(/,/g, '');
        if (s === '') return NaN;
        return Number(s);
    }
    function isReturnDoc(d) {
        return !!(d && (d.isReturn === true || /RT$/.test(d.id || '')));
    }
    function wrapBefore(name, fn) {
        var orig = window[name];
        if (typeof orig !== 'function' || orig.__s5Wrapped) return;
        var w = function () {
            try { fn.apply(this, arguments); } catch (e) { console.warn('[scenario05] ' + name + ':', e); }
            return orig.apply(this, arguments);
        };
        w.__s5Wrapped = true;
        window[name] = w;
    }
    function wrapAfter(name, fn) {
        var orig = window[name];
        if (typeof orig !== 'function' || orig.__s5Wrapped) return;
        var w = function () {
            var r = orig.apply(this, arguments);
            try { fn.apply(this, arguments); } catch (e) { console.warn('[scenario05] ' + name + ':', e); }
            return r;
        };
        w.__s5Wrapped = true;
        window[name] = w;
    }
    function nl2br(s) { return esc(s).replace(/\n/g, '<br>'); }

    // ================================================================== 1) ถ่ายรูปยืนยัน
    function stateOf(map, docId) { return map[docId] || (map[docId] = {}); }

    function itemKey(it) { return String(it.itemId != null ? it.itemId : it.matCode); }

    /** ค่าหยิบจริงของรายการ (ยังไม่แก้ = จำนวนที่ขอ) */
    function actualOf(doc, it) {
        var raw = stateOf(C.actual, doc.id)[itemKey(it)];
        if (!doc.editable || raw == null || String(raw).trim() === '') return Number(it.reqQty != null ? it.reqQty : it.qty) || 0;
        return num(raw);
    }
    function reqOf(it) { return Number(it.reqQty != null ? it.reqQty : it.qty) || 0; }
    function maxOf(doc, it) {
        var req = reqOf(it);
        if (it.maxQty != null && isFinite(Number(it.maxQty))) return Math.min(req, Number(it.maxQty));
        return req;
    }
    function retMode(doc) { return !!(doc && (doc.returnMode === true || isReturnDoc(doc))); }

    /** ตรวจรายการเดียว → {ok, msg, needPhoto, needReason} */
    function checkItem(doc, it) {
        var req = reqOf(it), a = actualOf(doc, it);
        var photos = (stateOf(C.photos, doc.id)[itemKey(it)] || []).length;
        var r = { ok: true, msg: '', needPhoto: false, needReason: false, actual: a, req: req };
        var ret = retMode(doc);
        if (doc.editable) {
            if (!isFinite(a)) { r.ok = false; r.msg = ret ? 'ใส่จำนวนคืนจริงเป็นตัวเลข' : 'ใส่จำนวนหยิบจริงเป็นตัวเลข'; return r; }
            if (a < 0) { r.ok = false; r.msg = (ret ? 'คืนจริง' : 'หยิบจริง') + 'ต่ำสุด 0'; return r; }
            if (a > maxOf(doc, it) + 0.0005) {
                r.ok = false;
                r.msg = ret ? 'คืนได้ไม่เกินที่ยืมอยู่ (' + fmt(maxOf(doc, it)) + ')'
                            : 'ได้ไม่เกินจำนวนที่ขอ (' + fmt(maxOf(doc, it)) + ') — ต้องการมากกว่านี้ให้ออกใบเบิกใหม่';
                return r;
            }
            var rsn = String(stateOf(C.reason, doc.id)[itemKey(it)] || '').trim();
            if (a < req - 0.0005 && (ret ? !rsn : PICK_REASONS.indexOf(rsn) < 0)) {
                r.ok = false; r.needReason = true; r.msg = ret ? 'คืนน้อยกว่าที่ยืม — ใส่เหตุผล' : 'หยิบน้อยกว่าที่ขอ — เลือกเหตุผล';
            }
        }
        if (a > 0.0005 && photos < 1) { r.ok = false; r.needPhoto = true; r.msg = r.msg || 'ถ่ายรูปรายการนี้อย่างน้อย 1 รูป'; }
        return r;
    }
    function checkDoc(doc) {
        var res = { ok: true, errors: [], photos: 0, need: 0, done: 0, changed: [] };
        (doc.items || []).forEach(function (it) {
            var c = checkItem(doc, it);
            var n = (stateOf(C.photos, doc.id)[itemKey(it)] || []).length;
            res.photos += n;
            if (c.actual > 0.0005) { res.need++; if (n > 0) res.done++; }
            if (!c.ok) { res.ok = false; res.errors.push((it.matCode || '-') + ': ' + c.msg); }
            if (doc.editable && isFinite(c.actual) && Math.abs(c.actual - c.req) > 0.0005) {
                res.changed.push({ it: it, req: c.req, actual: c.actual, reason: String(stateOf(C.reason, doc.id)[itemKey(it)] || '').trim() });
            }
        });
        return res;
    }

    function currentDoc() {
        var list = docs();
        if (!list.length) return null;
        if (C.idx >= list.length) C.idx = list.length - 1;
        if (C.idx < 0) C.idx = 0;
        return list[C.idx];
    }

    // ---- แถบสถานะรอบ (อยู่นอกการ์ด — อัปเดตได้โดยไม่รบกวนช่องที่กำลังพิมพ์) ----
    function roundBarEl() {
        var bar = document.getElementById('s5RoundBar');
        if (!bar) {
            var wiz = document.getElementById('confirmWizard');
            if (!wiz || !wiz.parentNode) return null;
            bar = document.createElement('div');
            bar.id = 's5RoundBar';
            bar.setAttribute('aria-live', 'polite');
            wiz.parentNode.insertBefore(bar, wiz);
        }
        return bar;
    }
    function renderRoundBar() {
        var bar = roundBarEl();
        if (!bar) return;
        var pks = {};
        docs().forEach(function (d) { if (d.pickingId) pks[d.pickingId] = true; });
        Object.keys(C.watch).forEach(function (pk) { pks[pk] = true; });
        var html = Object.keys(pks).sort().map(function (pk) {
            var r = C.rounds[pk];
            if (!r) return '';
            var gate = r.gate ? ' · ' + esc(r.gate) : '';
            var head = '<span class="s5-rb-pk"><i class="fa-solid fa-layer-group"></i> รอบ ' + esc(pk) + gate + '</span>';
            if (r.closed || (r.scanFlow && r.allConfirmed)) {
                return '<div class="s5-rb done">' + head +
                    '<span class="s5-rb-msg"><i class="fa-solid fa-circle-check"></i> ' +
                    (r.scanFlow ? 'ยืนยันครบ — ปิดงาน/ตัดสต๊อกแล้ว' : 'ปิดประตูแล้ว — ตัดสต๊อกสำเร็จ') + '</span></div>';
            }
            if (r.allConfirmed) {
                return '<div class="s5-rb closing">' + head +
                    '<span class="s5-rb-msg"><span class="s5-spin"></span> ยืนยันครบทุกใบแล้ว (' + r.confirmed + '/' + r.total +
                    ') — <b>รอปิดประตูที่ตู้' + (r.gate ? ' ' + esc(r.gate) : '') + '</b> เพื่อตัดสต๊อก</span></div>';
            }
            var mine = {};
            docs().forEach(function (d) { mine[d.id] = true; });
            var others = (r.pending || []).filter(function (id) { return !mine[id]; });
            return '<div class="s5-rb open">' + head +
                '<span class="s5-rb-msg">ยืนยันแล้ว <b>' + r.confirmed + '/' + r.total + '</b> ใบ' +
                (others.length ? ' · รอใบอื่นในรอบ: ' + others.map(esc).join(', ') : '') +
                ' — ประตูจะให้ปิดเมื่อยืนยันครบทุกใบ</span></div>';
        }).join('');
        bar.innerHTML = html;
        bar.style.display = html ? '' : 'none';
    }

    // ---- การ์ดใบ ----
    function itemHtml(doc, it) {
        var k = itemKey(it);
        var req = reqOf(it);
        var a = actualOf(doc, it);
        var photos = stateOf(C.photos, doc.id)[k] || [];
        var c = checkItem(doc, it);
        var zero = isFinite(a) && a <= 0.0005;
        var unit = it.unit ? ' ' + esc(it.unit) : '';
        var flag = zero ? '<span class="s5-flag zero"><i class="fa-solid fa-ban"></i> ไม่ได้หยิบ — ไม่ต้องถ่ายรูป</span>'
                 : (photos.length ? '<span class="s5-flag ok"><i class="fa-solid fa-circle-check"></i> รูป ' + photos.length + '</span>'
                                  : '<span class="s5-flag need"><i class="fa-solid fa-camera"></i> ต้องถ่ายรูป</span>');
        var qtyHtml;
        var ret = retMode(doc);
        // [2026-10-02 · GP-42 / OP-74] ยอดในระบบที่ประตูของรอบ — ไม่พอ/ติดลบ = เตือนตอนหยิบ (ยอดสีแดง) · หยิบได้ตามของจริง
        var stockHtml = '';
        if (doc.editable && !ret && it.gateOnHand != null && isFinite(Number(it.gateOnHand))) {
            var oh = Number(it.gateOnHand);
            var short = oh < req - 0.0005;
            stockHtml = '<div class="s5-stock' + (short ? ' short' : '') + '">' +
                (short ? '<i class="fa-solid fa-triangle-exclamation"></i> ' : '') +
                'ยอดในระบบที่ ' + esc(doc.gateCode || 'ประตูนี้') + ' <b class="' + (oh < 0 ? 'neg' : '') + '">' + esc(fmt(oh)) + '</b>' + unit +
                (short ? ' — ไม่พอสำหรับใบนี้ (ขอ ' + esc(fmt(req)) + unit + ') · หยิบตามของที่มีจริง ถ้าของจริงมีมากกว่ายอดในระบบ ' +
                         'ระบบจะตัดจนยอดติดลบ (สีแดง) — แจ้งสายสโตร์ตรวจนับ' : '') +
                '</div>';
        }
        if (doc.editable) {
            var max = maxOf(doc, it);
            var rawVal = stateOf(C.actual, doc.id)[k];
            var val = rawVal == null ? fmt(req) : String(rawVal);
            var wo = Number(it.writtenOff || 0);
            qtyHtml =
                '<div class="s5-qty">' +
                    '<div class="s5-req">' + (ret ? 'ต้องคืน' : 'ขอ') + ' <b>' + esc(fmt(req)) + '</b>' + unit +
                        // [2026-10-08] ทยอยคืน: ต้องคืน = ยอดค้าง · บอกที่คืนไปแล้วรอบก่อน
                        (ret && (wo > 0.0005 || Number(it.returnedBefore || 0) > 0.0005)
                            ? ' <span class="s5-range">(ยืม ' + esc(fmt(it.borrowed)) +
                              (Number(it.returnedBefore || 0) > 0.0005 ? ' · คืนแล้ว ' + esc(fmt(it.returnedBefore)) : '') +
                              (wo > 0.0005 ? ' · ตีชำรุด/สูญหายแล้ว ' + esc(fmt(wo)) : '') + ')</span>' : '') + '</div>' +
                    stockHtml +
                    '<div class="s5-act">' +
                        '<span class="s5-act-lbl">' + (ret ? 'คืนจริง' : 'หยิบจริง') + '</span>' +
                        '<button type="button" class="s5-step" data-s5="dec" aria-label="ลด">−</button>' +
                        '<input type="number" inputmode="decimal" min="0" max="' + esc(fmt(max)) + '" step="any" class="s5-act-in" data-s5="actual" value="' + esc(val) + '" aria-label="' + (ret ? 'จำนวนคืนจริง ' : 'จำนวนหยิบจริง ') + esc(it.matCode) + '">' +
                        '<button type="button" class="s5-step" data-s5="inc" aria-label="เพิ่ม">+</button>' +
                        '<span class="s5-act-unit">' + esc(it.unit || '') + '</span>' +
                    '</div>' +
                    '<div class="s5-range">ได้ 0 – ' + esc(fmt(max)) + (ret ? ' · คืนไม่ครบ = ส่วนที่เหลือยังค้างยืม — ทยอยคืนรอบถัดไป หรือสายสโตร์ตีเป็นชำรุด/สูญหาย' : ' · ไม่เกินจำนวนที่ขอ') + '</div>' +
                '</div>';
        } else {
            qtyHtml = '<div class="s5-qty"><div class="s5-req">' + (isReturnDoc(doc) ? 'คืนตามที่ยืม' : (doc.type === 'IN' ? 'รับเข้าตามใบ' : 'จำนวน')) +
                ' <b>' + esc(fmt(req)) + '</b>' + unit + '</div></div>';
        }
        var diff = isFinite(a) ? round3(a - req) : 0;
        var over = '';   // ⑦ ฉบับแก้: เพิ่มเกินที่ขอไม่ได้แล้ว — ค่าเกินขึ้นเป็นข้อความผิดพลาดของรายการ
        var reasonVal = stateOf(C.reason, doc.id)[k] || '';
        var reasonHtml = !doc.editable ? '' : (ret
            ? '<div class="s5-reason"' + (diff < -0.0005 ? '' : ' hidden') + '>' +
                '<input type="text" maxlength="200" data-s5="reason" placeholder="เหตุผลที่คืนไม่ครบ (จำเป็น)" value="' + esc(reasonVal) + '">' +
                '<div class="s5-chips">' + RET_REASONS.map(function (r) {
                    return '<button type="button" class="s5-chip" data-s5="reason-chip" data-v="' + esc(r) + '">' + esc(r) + '</button>';
                }).join('') + '</div>' +
              '</div>'
            : '<div class="s5-reason s5-reason-pick"' + (diff < -0.0005 ? '' : ' hidden') + '>' +
                '<div class="s5-reason-lbl">เหตุผลที่หยิบน้อยกว่าที่ขอ <span>(เลือก 1)</span></div>' +
                '<div class="s5-chips" role="radiogroup">' + PICK_REASONS.map(function (r) {
                    return '<button type="button" class="s5-chip' + (reasonVal === r ? ' on' : '') + '" role="radio" aria-checked="' + (reasonVal === r) + '"' +
                        ' data-s5="reason-chip" data-v="' + esc(r) + '">' + esc(r) + '</button>';
                }).join('') + '</div>' +
              '</div>');
        var thumbs = photos.map(function (p, i) {
            return '<div class="s5-thumb"><img src="' + p.dataUrl + '" alt="รูป ' + (i + 1) + '">' +
                '<button type="button" class="s5-thumb-x" data-s5="rm-photo" data-i="' + i + '" aria-label="ลบรูป">&times;</button></div>';
        }).join('');
        var photoHtml =
            '<div class="s5-photos"' + (zero ? ' hidden' : '') + '>' + thumbs +
                '<label class="s5-add cam"><i class="fa-solid fa-camera"></i><span>ถ่ายรูป</span>' +
                    '<input type="file" accept="image/*" capture="environment" data-s5="photo"></label>' +
                '<label class="s5-add"><i class="fa-solid fa-images"></i><span>เลือกไฟล์</span>' +
                    '<input type="file" accept="image/*" multiple data-s5="photo"></label>' +
            '</div>';
        return '<div class="s5-item' + (c.ok ? ' ok' : ' bad') + (zero ? ' zero' : '') + '" data-doc="' + esc(doc.id) + '" data-item="' + esc(k) + '">' +
                '<div class="s5-item-top">' +
                    '<div class="s5-item-name"><span class="s5-code">' + esc(it.matCode || '-') + '</span>' + esc(it.matName || '-') + '</div>' +
                    flag +
                '</div>' +
                qtyHtml + over + reasonHtml + photoHtml +
                '<div class="s5-err" role="alert">' + (c.ok ? '' : esc(c.msg)) + '</div>' +
            '</div>';
    }

    function saveBarHtml(doc) {
        var chk = checkDoc(doc);
        var cls = chk.ok ? 'ready' : 'wait';
        var hint = chk.ok
            ? '<i class="fa-solid fa-circle-check"></i> พร้อมบันทึก — รูปครบ ' + chk.done + '/' + chk.need + ' รายการ' +
              (chk.changed.length ? ' · แก้จำนวน ' + chk.changed.length + ' รายการ' : '')
            : '<i class="fa-solid fa-triangle-exclamation"></i> ยังบันทึกไม่ได้ — ' + esc(chk.errors[0] || '') +
              (chk.errors.length > 1 ? ' (+' + (chk.errors.length - 1) + ')' : '');
        return '<div class="s5-save ' + cls + '">' +
                '<div class="s5-save-hint">' + hint + '</div>' +
                '<button type="button" class="btn ' + (chk.ok ? 'btn-success' : 'btn-secondary') + '" data-s5="save"' + (chk.ok && !C.saving ? '' : ' disabled') + '>' +
                    '<i class="fa-solid fa-floppy-disk"></i> บันทึกยืนยันใบนี้</button>' +
            '</div>';
    }

    function render() {
        var wrap = document.getElementById('confirmWizard');
        if (!wrap) return;
        renderRoundBar();
        var list = docs();
        if (!list.length) {
            wrap.innerHTML = '<div class="qr-empty"><i class="fa-solid fa-camera"></i><span>ไม่มีเอกสารรอถ่ายรูปยืนยันในขณะนี้</span>' +
                '<div style="margin-top:0.5rem; font-size:0.85rem;">เอกสารจะปรากฏที่นี่หลังแตะบัตรที่ตู้ประตู (สถานะ Opened)</div></div>';
            return;
        }
        var doc = currentDoc();
        var total = list.length;
        var dots = list.map(function (d, i) {
            var ok = checkDoc(d).ok;
            return '<button type="button" class="cw-dot' + (ok ? ' done' : '') + (i === C.idx ? ' current' : '') + '" title="' + esc(d.id) + '" data-s5="goto" data-i="' + i + '">' +
                (ok ? '<i class="fa-solid fa-check"></i>' : (i + 1)) + '</button>';
        }).join('');
        var ret = isReturnDoc(doc);
        var recvLabel = doc.type === 'IN' ? 'RS' : (ret ? 'ผู้คืน' : 'ผู้รับ');
        var fullName = typeof window.userFullName === 'function' ? (window.userFullName(doc.userName) || doc.userName) : doc.userName;
        var chips = '';
        if (doc.pickingId) chips += '<span class="s5-chip-i"><i class="fa-solid fa-layer-group"></i> รอบ ' + esc(doc.pickingId) + (doc.gateCode ? ' · ' + esc(doc.gateCode) : '') + '</span>';
        if (doc.cardholder) chips += '<span class="s5-chip-i"><i class="fa-solid fa-id-card"></i> ผู้นำจ่าย: ' + esc(doc.cardholder) + '</span>';
        if (ret) chips += '<span class="s5-chip-i warn"><i class="fa-solid fa-rotate-left"></i> คืนอุปกรณ์</span>';
        if (doc.type === 'IN') chips += '<span class="s5-chip-i info"><i class="fa-solid fa-arrow-right-to-bracket"></i> รับเข้า — ยอดตามใบ</span>';
        var typeBadge = typeof window.getTypeBadge === 'function' ? window.getTypeBadge(doc.type || '-') : esc(doc.type || '');
        var hint = doc.editable
            ? (ret
                ? 'ถ่ายรูปของที่คืน<b>อย่างน้อยรายการละ 1 รูป</b> · ของหาย/ชำรุดจนใช้ไม่ได้ ลดช่อง “คืนจริง” เป็นเท่าที่คืนจริงหรือ 0 พร้อมเหตุผล · ' +
                  'ปิดประตูแล้วระบบรับคืนเข้า G เดิมตามคืนจริง ส่วนที่ขาดค้างให้สายสโตร์ตีเป็นชำรุด/สูญหาย'
                : 'ถ่ายรูป<b>อย่างน้อยรายการละ 1 รูป</b> · หยิบได้น้อยกว่าใบ ลดช่อง “หยิบจริง” ได้ถึง 0 พร้อมเหตุผล (<b>ห้ามเกินจำนวนที่ขอ</b> — ต้องการเพิ่มให้ออกใบเบิกใหม่) · ' +
                  'ระบบตัดสต๊อกตามหยิบจริงเมื่อปิดประตู')
            : 'ถ่ายรูป<b>อย่างน้อยรายการละ 1 รูป</b> · ' + (ret ? 'คืนทั้งใบตามจำนวนที่ยืมจริง' : 'ใบรับเข้าใช้ยอดตามใบ');
        wrap.innerHTML =
            '<div class="cw-progress">' +
                '<span class="cw-progress-label">เอกสาร ' + (C.idx + 1) + ' / ' + total + '</span>' +
                '<div class="cw-dots">' + dots + '</div>' +
            '</div>' +
            '<div class="cw-card s5-card" data-doc="' + esc(doc.id) + '">' +
                '<div class="cw-card-head">' +
                    '<div>' +
                        '<div class="cw-doc-id">' + esc(doc.id || '-') + '</div>' +
                        '<div class="cw-doc-meta">' + recvLabel + ': ' + esc(doc.subName || '-') + ' · ผู้ขอ: ' + esc(fullName || '-') + '</div>' +
                        (chips ? '<div class="s5-chips-row">' + chips + '</div>' : '') +
                    '</div>' +
                    '<div>' + typeBadge + '</div>' +
                '</div>' +
                '<div class="cw-section">' +
                    '<div class="cw-section-title"><i class="fa-solid fa-box-open" style="color:var(--primary);"></i> รายการ — ถ่ายรูปยืนยันรายรายการ</div>' +
                    '<div class="s5-hint">' + hint + '</div>' +
                    (doc.items || []).map(function (it) { return itemHtml(doc, it); }).join('') +
                '</div>' +
                '<div class="cw-section">' +
                    '<div class="cw-section-title">หมายเหตุ <span style="font-weight:400; color:var(--text-muted); font-size:0.82rem;">(ถ้ามี)</span></div>' +
                    '<textarea class="form-control" rows="2" data-s5="notes" placeholder="ระบุรายละเอียดเพิ่มเติม">' + esc(C.notes[doc.id] || '') + '</textarea>' +
                '</div>' +
                '<div class="cw-nav">' +
                    '<button type="button" class="btn btn-secondary" data-s5="prev"' + (C.idx === 0 ? ' disabled' : '') + '><i class="fa-solid fa-chevron-left"></i> ก่อนหน้า</button>' +
                    (C.idx < total - 1
                        ? '<button type="button" class="btn btn-primary" data-s5="next">ถัดไป <i class="fa-solid fa-chevron-right"></i></button>'
                        : '<span style="flex:1; text-align:center; color:var(--text-muted); font-size:0.85rem; align-self:center;">ใบสุดท้ายแล้ว</span>') +
                '</div>' +
            '</div>' +
            '<div class="s5-save-wrap">' + saveBarHtml(doc) + '</div>';
    }

    /** อัปเดตเฉพาะส่วนที่ขึ้นกับค่าในช่อง (ไม่ render ใหม่ทั้งการ์ด — โฟกัส/คีย์บอร์ดมือถือไม่หลุด) */
    function refreshItemUi(doc, it, itemEl) {
        var c = checkItem(doc, it);
        var req = c.req, a = c.actual, zero = isFinite(a) && a <= 0.0005;
        var photos = (stateOf(C.photos, doc.id)[itemKey(it)] || []).length;
        itemEl.classList.toggle('ok', c.ok);
        itemEl.classList.toggle('bad', !c.ok);
        itemEl.classList.toggle('zero', zero);
        var flag = $('.s5-flag', itemEl);
        if (flag) {
            flag.className = 's5-flag ' + (zero ? 'zero' : (photos ? 'ok' : 'need'));
            flag.innerHTML = zero ? '<i class="fa-solid fa-ban"></i> ไม่ได้หยิบ — ไม่ต้องถ่ายรูป'
                : (photos ? '<i class="fa-solid fa-circle-check"></i> รูป ' + photos : '<i class="fa-solid fa-camera"></i> ต้องถ่ายรูป');
        }
        var reason = $('.s5-reason', itemEl);
        if (reason) reason.hidden = !(isFinite(a) && a < req - 0.0005);
        var ph = $('.s5-photos', itemEl);
        if (ph) ph.hidden = zero;
        var overEl = $('.s5-over', itemEl);   // ⑦ ฉบับแก้: ไม่มี "หยิบเกินที่ขอ" แล้ว (ค่าเกิน = ข้อความผิดพลาด)
        if (overEl) overEl.remove();
        var err = $('.s5-err', itemEl);
        if (err) err.textContent = c.ok ? '' : c.msg;
        refreshSaveBar(doc);
    }
    function refreshSaveBar(doc) {
        var sw = $('#confirmWizard .s5-save-wrap');
        if (sw) sw.innerHTML = saveBarHtml(doc);
        var list = docs();
        var i = list.indexOf(doc);
        var dot = $all('#confirmWizard .cw-dot')[i];
        if (dot) {
            var ok = checkDoc(doc).ok;
            dot.classList.toggle('done', ok);
            dot.innerHTML = ok ? '<i class="fa-solid fa-check"></i>' : String(i + 1);
        }
    }

    function findDocItem(el) {
        var itemEl = el.closest('.s5-item');
        var card = el.closest('.s5-card');
        var docId = (itemEl || card || {}).getAttribute ? (itemEl || card).getAttribute('data-doc') : '';
        var doc = docs().filter(function (d) { return d.id === docId; })[0] || null;
        var it = null;
        if (doc && itemEl) {
            var k = itemEl.getAttribute('data-item');
            it = (doc.items || []).filter(function (x) { return itemKey(x) === k; })[0] || null;
        }
        return { doc: doc, it: it, itemEl: itemEl };
    }

    function addPhotos(doc, it, input) {
        var files = Array.prototype.slice.call((input && input.files) || []);
        if (!files.length || typeof window.compressImage !== 'function') return;
        window._confirmImgBusy = true;
        var itemEl = input.closest('.s5-item');
        var label = input.closest('.s5-add');
        if (label) label.classList.add('busy');
        Promise.all(files.map(function (f) { return window.compressImage(f); }))
            .then(function (res) {
                var arr = stateOf(C.photos, doc.id)[itemKey(it)] || (stateOf(C.photos, doc.id)[itemKey(it)] = []);
                res.filter(function (r) { return r && r.dataUrl; }).forEach(function (r) { arr.push({ dataUrl: r.dataUrl }); });
                window._confirmImgBusy = false;
                rerenderItem(doc, it, itemEl);
            })
            .catch(function (e) {
                console.warn('[scenario05] compress failed', e);
                window._confirmImgBusy = false;
                if (label) label.classList.remove('busy');
                toast('บีบอัดรูปไม่สำเร็จ ลองใหม่อีกครั้ง', 'warning');
            });
    }
    function rerenderItem(doc, it, itemEl) {
        if (!itemEl || !itemEl.parentNode) { render(); return; }
        var tmp = document.createElement('div');
        tmp.innerHTML = itemHtml(doc, it);
        itemEl.parentNode.replaceChild(tmp.firstChild, itemEl);
        refreshSaveBar(doc);
    }

    function onInput(ev) {
        var t = ev.target;
        var role = t.getAttribute && t.getAttribute('data-s5');
        if (!role) return;
        var f = findDocItem(t);
        if (role === 'notes') {
            var card = t.closest('.s5-card');
            if (card) C.notes[card.getAttribute('data-doc')] = t.value;
            return;
        }
        if (!f.doc || !f.it) return;
        if (role === 'actual') {
            stateOf(C.actual, f.doc.id)[itemKey(f.it)] = t.value;
            refreshItemUi(f.doc, f.it, f.itemEl);
        } else if (role === 'reason') {
            stateOf(C.reason, f.doc.id)[itemKey(f.it)] = t.value;
            refreshItemUi(f.doc, f.it, f.itemEl);
        }
    }
    function onChange(ev) {
        var t = ev.target;
        if (t.getAttribute && t.getAttribute('data-s5') === 'photo') {
            var f = findDocItem(t);
            if (f.doc && f.it) addPhotos(f.doc, f.it, t);
        }
    }
    function onClick(ev) {
        var b = ev.target.closest('[data-s5]');
        if (!b || !document.getElementById('confirmWizard').contains(b)) return;
        var role = b.getAttribute('data-s5');
        var f = findDocItem(b);
        if (role === 'goto') { go(parseInt(b.getAttribute('data-i'), 10) || 0); return; }
        if (role === 'prev') { go(C.idx - 1); return; }
        if (role === 'next') { go(C.idx + 1); return; }
        if (role === 'save') { var d = currentDoc(); if (d) saveDoc(d); return; }
        if (!f.doc || !f.it) return;
        if (role === 'dec' || role === 'inc') {
            var cur = actualOf(f.doc, f.it);
            if (!isFinite(cur)) cur = reqOf(f.it);
            var step = Math.abs(cur - Math.round(cur)) > 0.0005 ? 0.5 : 1;
            var next = role === 'dec' ? Math.max(0, cur - step) : Math.min(maxOf(f.doc, f.it), cur + step);
            next = round3(next);
            stateOf(C.actual, f.doc.id)[itemKey(f.it)] = String(next);
            var inp = $('input[data-s5="actual"]', f.itemEl);
            if (inp) inp.value = String(next);
            refreshItemUi(f.doc, f.it, f.itemEl);
            if (typeof window.hapticTap === 'function') window.hapticTap();
        } else if (role === 'reason-chip') {
            var v = b.getAttribute('data-v') || '';
            stateOf(C.reason, f.doc.id)[itemKey(f.it)] = v;
            var ri = $('input[data-s5="reason"]', f.itemEl);
            if (ri) ri.value = v;
            Array.prototype.forEach.call(f.itemEl.querySelectorAll('.s5-reason-pick .s5-chip'), function (x) {
                var on = x.getAttribute('data-v') === v;
                x.classList.toggle('on', on);
                x.setAttribute('aria-checked', String(on));
            });
            refreshItemUi(f.doc, f.it, f.itemEl);
        } else if (role === 'rm-photo') {
            var arr = stateOf(C.photos, f.doc.id)[itemKey(f.it)] || [];
            arr.splice(parseInt(b.getAttribute('data-i'), 10) || 0, 1);
            rerenderItem(f.doc, f.it, f.itemEl);
        }
    }
    function go(i) {
        var n = docs().length;
        if (!n) return;
        C.idx = Math.max(0, Math.min(n - 1, i));
        render();
        var wrap = document.getElementById('confirmWizard');
        if (wrap && window.innerWidth < 768) setTimeout(function () { wrap.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 60);
    }

    function saveDoc(doc) {
        if (C.saving) return;
        var chk = checkDoc(doc);
        if (!chk.ok) {
            if (typeof window.showInfoPopup === 'function') {
                window.showInfoPopup('ยังบันทึกไม่ได้', chk.errors.map(function (e) { return '• ' + esc(e); }).join('<br>'), 'warning');
            }
            return;
        }
        var ret = retMode(doc);
        var lines = chk.changed.map(function (c) {
            var d = round3(c.actual - c.req);
            return '• ' + esc(c.it.matCode) + (ret ? ' ต้องคืน ' : ' ขอ ') + esc(fmt(c.req)) + (ret ? ' → คืนจริง <b>' : ' → หยิบจริง <b>') + esc(fmt(c.actual)) + '</b> (' + (d > 0 ? '+' : '') + esc(fmt(d)) + ')' +
                (c.reason ? ' — ' + esc(c.reason) : '');
        });
        var msg = 'บันทึกยืนยัน <b>' + esc(doc.id) + '</b> · รูป ' + chk.photos + ' รูป (' + chk.done + '/' + chk.need + ' รายการ)' +
            (lines.length
                ? (ret
                    ? '<br><br><b>คืนไม่ครบ</b><br>' + lines.join('<br>') + '<br><span style="color:var(--text-muted)">รับคืนเข้า G เดิมตามคืนจริง · ส่วนที่เหลือยังค้างยืม — ทยอยคืนรอบถัดไป หรือสายสโตร์ตีเป็นชำรุด/สูญหาย</span>'
                    : '<br><br><b>จำนวนหยิบจริงต่างจากที่ขอ</b><br>' + lines.join('<br>') + '<br><span style="color:var(--text-muted)">ระบบตัดสต๊อกตามหยิบจริง · PDF ใบแสดง ขอ / หยิบจริง / ผลต่าง</span>')
                : '') +
            '<br><br>บันทึกแล้วแก้ไขไม่ได้';
        // [2026-09-30 · GP-05] หยิบจริง 0 ทุกรายการ = ยกเลิกใบทางอ้อมหลังแตะบัตร → ระบบบันทึกเป็น ALARM (server: lib/gate_api.php)
        var items = doc.items || [];
        var allZero = !ret && doc.editable && items.length > 0 &&
            items.some(function (it) { return reqOf(it) > 0.0005; }) &&
            items.every(function (it) { return actualOf(doc, it) <= 0.0005; });
        if (allZero) {
            msg = '<div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:8px;padding:0.6rem 0.75rem;margin-bottom:0.6rem;">' +
                '<b><i class="fa-solid fa-triangle-exclamation"></i> หยิบจริง 0 ทุกรายการ = ยกเลิกใบทางอ้อมหลังแตะบัตร</b><br>' +
                'ระบบจะบันทึกเป็น <b>ALARM</b> ให้ ADM / สายสโตร์ตรวจ · ใบที่สแกนผิด (ไม่ได้หยิบของ) ให้เลือกเหตุผล "สแกนผิด" — ไม่ต้องออกใบ IN คืน · ' +
                'Dashboard นับ "ของไม่พอ" เฉพาะรายการที่เลือกเหตุผล "ของไม่พอ"</div>' + msg;
        }
        var run = function () { doSave(doc); };
        if (typeof window.showConfirmPopup === 'function') {
            window.showConfirmPopup(allZero ? 'ยืนยันบันทึก — หยิบจริง 0 ทุกรายการ' : 'ยืนยันบันทึกใบนี้', msg, run,
                allZero ? 'บันทึก (ALARM)' : 'บันทึก', allZero ? 'btn btn-danger' : 'btn btn-success');
        } else {
            run();
        }
    }
    function doSave(doc) {
        C.saving = true;
        var payload = {
            docId: doc.id,
            notes: C.notes[doc.id] || '',
            actionType: isReturnDoc(doc) ? 'confirm2gate' : 'confirm',
            username: window.user ? window.user.username : '',
            items: (doc.items || []).map(function (it) {
                var k = itemKey(it);
                return {
                    itemId: it.itemId,
                    matCode: it.matCode,
                    actualQty: doc.editable ? actualOf(doc, it) : null,
                    reason: (doc.editable && actualOf(doc, it) < reqOf(it) - 0.0005) ? String(stateOf(C.reason, doc.id)[k] || '').trim() : '',
                    images: (stateOf(C.photos, doc.id)[k] || []).map(function (p) { return p.dataUrl; })
                };
            })
        };
        if (typeof window.showLoadingPopup === 'function') window.showLoadingPopup('กำลังบันทึก', 'เอกสาร ' + doc.id);
        google.script.run
            .withSuccessHandler(function (resp) {
                C.saving = false;
                if (typeof window.closeAppPopup === 'function') window.closeAppPopup();
                if (!resp || !resp.success) {
                    if (typeof window.showInfoPopup === 'function') {
                        window.showInfoPopup('บันทึกไม่สำเร็จ', nl2br((resp && resp.message) || 'บันทึกไม่สำเร็จ'), 'danger');
                    }
                    refreshSaveBar(doc);
                    return;
                }
                if (typeof window.hapticSuccess === 'function') window.hapticSuccess();
                if (typeof window.invalidateAfterWrite === 'function') window.invalidateAfterWrite('confirm');
                delete C.photos[doc.id]; delete C.actual[doc.id]; delete C.reason[doc.id]; delete C.notes[doc.id];
                window.confirmDocsData = docs().filter(function (d) { return d.id !== doc.id; });
                if (C.idx >= window.confirmDocsData.length) C.idx = Math.max(0, window.confirmDocsData.length - 1);
                var r = resp.round;
                if (r && r.pickingId) {
                    C.rounds[r.pickingId] = r;
                    C.watch[r.pickingId] = C.watch[r.pickingId] || { since: Date.now() };
                }
                var m = '✅ บันทึก ' + doc.id + ' แล้ว';
                if (r && r.pickingId) {
                    if (r.closed || resp.finalized) m += ' — ปิดงาน/ตัดสต๊อกแล้ว';
                    else if (r.allConfirmed) m += ' — ครบทุกใบในรอบ รอปิดประตู';
                    else m += ' — รอใบอื่นในรอบอีก ' + (r.total - r.confirmed) + ' ใบ';
                }
                if (resp.zeroPick) m += ' · บันทึกเป็น ALARM: หยิบจริง 0 ทุกรายการ';   // 2026-09-30
                toast(m, resp.zeroPick ? 'warning' : 'success');
                if (typeof window.renderPickSummary === 'function') window.renderPickSummary();
                render();
                if (typeof window.updateNavBadges === 'function') window.updateNavBadges();
                syncWatchPoll();
            })
            .withFailureHandler(function (err) {
                C.saving = false;
                if (typeof window.closeAppPopup === 'function') window.closeAppPopup();
                if (typeof window.showInfoPopup === 'function') {
                    window.showInfoPopup('เชื่อมต่อไม่สำเร็จ', esc((err && err.message) || String(err)), 'danger');
                }
            })
            .saveConfirmationData(payload);
    }

    // ---- poll สถานะรอบที่รอ (ยืนยันแล้ว → รอปิดประตู → ปิดแล้ว) ----
    function syncWatchPoll() {
        var need = Object.keys(C.watch).length > 0;
        if (need && !C.pollTimer) C.pollTimer = setInterval(pollTick, 3000);
        if (!need && C.pollTimer) { clearInterval(C.pollTimer); C.pollTimer = null; }
    }
    function pollTick() {
        if (C.pollBusy) return;
        var page = document.getElementById('confirm-page');
        if (!page || !page.classList.contains('active-page')) return;
        if (document.visibilityState && document.visibilityState !== 'visible') return;
        var pks = Object.keys(C.watch);
        if (!pks.length) { syncWatchPoll(); return; }
        C.pollBusy = true;
        google.script.run
            .withSuccessHandler(function (resp) {
                C.pollBusy = false;
                var rounds = (resp && resp.rounds) || {};
                var now = Date.now();
                pks.forEach(function (pk) {
                    var r = rounds[pk];
                    var w = C.watch[pk];
                    if (!r) { if (now - w.since > 15000) delete C.watch[pk]; return; }
                    var prev = C.rounds[pk];
                    C.rounds[pk] = r;
                    var done = r.closed || (r.scanFlow && r.allConfirmed);
                    if (done && !w.doneAt) {
                        w.doneAt = now;
                        if (!r.scanFlow) toast('🔒 ' + pk + (r.gate ? ' · ' + r.gate : '') + ' ปิดประตูแล้ว — ตัดสต๊อกสำเร็จ', 'success');
                    }
                    if (w.doneAt && now - w.doneAt > 12000) delete C.watch[pk];
                    if (prev && !prev.allConfirmed && r.allConfirmed && !r.closed && !r.scanFlow) {
                        toast('ทุกใบในรอบ ' + pk + ' ยืนยันครบแล้ว — ปิดประตูที่ตู้เพื่อตัดสต๊อก', 'info');
                    }
                });
                renderRoundBar();
                syncWatchPoll();
            })
            .withFailureHandler(function () { C.pollBusy = false; })
            .getPickRoundState(pks);
    }

    function installConfirm() {
        if (typeof window.renderConfirmWizard !== 'function') return false;
        window.renderConfirmWizard = render;
        // ไม่มีการล็อกชุดถัดไป/โหมดรอปิดประตูแบบบล็อกทั้งหน้าแล้ว — ทุกใบที่แตะบัตรแล้วถ่ายได้ทันที
        window._confirmActiveDocs = function () { return docs().slice(); };
        window._confirmLockedDocs = function () { return []; };
        window._confirmLockedGroupCount = function () { return 0; };
        window._confirmIsClosing = function () { return false; };
        window._confirmActiveGroupLabel = function () {
            var pks = {};
            docs().forEach(function (d) { if (d.pickingId) pks[d.pickingId + (d.gateCode ? ' · ' + d.gateCode : '')] = true; });
            var k = Object.keys(pks);
            return k.length === 1 ? k[0] : (k.length ? k.length + ' รอบ' : '');
        };
        window._syncConfirmClosePoll = syncWatchPoll;
        window._confirmStashNote = function () {};
        window._confirmNotesFocused = function () {
            var a = document.activeElement;
            return !!(a && a.closest && a.closest('#confirmWizard') && /^(INPUT|TEXTAREA)$/.test(a.tagName));
        };
        window.submitAllConfirmations = function () { var d = currentDoc(); if (d) saveDoc(d); };
        window.confirmWizardGoto = go;
        window.confirmWizardPrev = function () { go(C.idx - 1); };
        window.confirmWizardNext = function () { go(C.idx + 1); };
        try { window.confirmClosingDocs = []; } catch (e) {}

        // ข้อมูลรอบจาก getConfirmableDocuments + ล้างสถานะของใบที่ไม่อยู่แล้ว (ก่อนเรียกของเดิมซึ่ง render ต่อ)
        wrapBefore('_applyConfirmableResponse', function (response) {
            var r = (response && response.rounds) || {};
            Object.keys(r).forEach(function (pk) { C.rounds[pk] = r[pk]; });
            var live = {};
            ((response && response.data) || []).forEach(function (d) { live[d.id] = true; });
            [C.photos, C.actual, C.reason, C.notes].forEach(function (m) {
                Object.keys(m).forEach(function (k) { if (!live[k]) delete m[k]; });
            });
            try { window.confirmClosingDocs = []; } catch (e) {}
        });

        var wiz = document.getElementById('confirmWizard');
        if (wiz && !wiz.__s5) {
            wiz.__s5 = true;
            wiz.addEventListener('input', onInput);
            wiz.addEventListener('change', onChange);
            wiz.addEventListener('click', onClick);
        }
        var sub = document.querySelector('#confirm-page .page-subtitle');
        if (sub) sub.textContent = 'แตะบัตรที่ตู้แล้ว หยิบของตามรายการ ถ่ายรูปยืนยันรายรายการ (อย่างน้อยรายการละ 1 รูป) แล้วบันทึกทีละใบ — ประตูให้ปิดเมื่อทุกใบในรอบยืนยันครบ';
        if (document.getElementById('confirmWizard') && docs().length) render();
        return true;
    }

    // ================================================================== 2) ตรวจสอบประจำวัน
    function dcQtyText(it) {
        if (it && it.qtyReq != null && Math.abs(Number(it.qtyReq) - Number(it.qty)) > 0.0005) {
            return fmt(it.qty) + ' (ขอ ' + fmt(it.qtyReq) + ')';
        }
        return null;
    }
    function dcDecorate(html, it) {
        if (it && it.bypass && typeof html === 'string') {
            var cell = '<td class="dc-td-doc">' + esc(it.docId || '-') + '</td>';
            html = html.replace(cell, '<td class="dc-td-doc">' + esc(it.docId || '-') + ' <span class="s5-bypass">bypass</span></td>');
        }
        return html;
    }
    function installDailyCheck() {
        var origRow = window._dcItemRow;
        if (typeof origRow === 'function' && !origRow.__s5Wrapped) {
            var w = function (it, dayKey) {
                var t = dcQtyText(it);
                var arg = t ? Object.assign({}, it, { qty: t }) : it;
                var html = origRow.call(this, arg, dayKey);
                if (t && it.actualReason) {
                    html = html.replace('<td class="dc-td-qty"><b>' + esc(t) + '</b></td>',
                        '<td class="dc-td-qty"><b>' + esc(t) + '</b><div class="s5-dc-reason">' + esc(it.actualReason) + '</div></td>');
                }
                return dcDecorate(html, it);
            };
            w.__s5Wrapped = true;
            window._dcItemRow = w;
        }
        var origBad = window._dcBadDcBox;
        if (typeof origBad === 'function' && !origBad.__s5Wrapped) {
            var wb = function (day, list) {
                var mapped = (list || []).map(function (it) { var t = dcQtyText(it); return t ? Object.assign({}, it, { qty: t }) : it; });
                return origBad.call(this, day, mapped);
            };
            wb.__s5Wrapped = true;
            window._dcBadDcBox = wb;
        }
    }

    // ================================================================== 3) Dashboard: การ์ดต้องตรวจสอบ
    var A = { busy: false, site: null };
    function dashSite() {
        try { if (typeof window.getDashboardSiteFilter === 'function') return window.getDashboardSiteFilter() || ''; } catch (e) {}
        return '';
    }
    function alertsEl() {
        var el = document.getElementById('s5Alerts');
        if (el) return el;
        var page = document.getElementById('dashboard-page');
        if (!page) return null;
        el = document.createElement('div');
        el.id = 's5Alerts';
        el.hidden = true;
        var ov = document.getElementById('iiOverview');
        if (ov) ov.insertBefore(el, ov.firstChild);
        else {
            var head = page.querySelector('.page-header');
            if (head && head.parentNode) head.parentNode.insertBefore(el, head.nextSibling); else page.insertBefore(el, page.firstChild);
        }
        return el;
    }
    function loadAlerts() {
        if (A.busy || !window.user) return;
        var u = window.user;
        var lvl = parseInt(((u.roleLevel || '').match(/\d+/) || ['99'])[0], 10);
        if (!(u.roleLevel === 'R0' || lvl >= 8 || u.canReq === true)) return;
        A.busy = true;
        var site = dashSite();
        google.script.run
            .withSuccessHandler(function (d) { A.busy = false; renderAlerts(d); })
            .withFailureHandler(function () { A.busy = false; })
            .getPickAlerts(site);
    }
    function renderAlerts(d) {
        var el = alertsEl();
        if (!el) return;
        if (!d || !d.success) { el.hidden = true; el.innerHTML = ''; return; }
        var shortage = (d.shortfalls || []).filter(function (s) { return s.shortage; });
        var otherShort = (d.shortfalls || []).length - shortage.length;
        var over = d.overCap || [];
        var a4 = d.alarm4 || [];   // 2026-09-30: ประตูที่ ALARM 4 ยังค้าง (ตู้หยุดรับงาน)
        var zp = d.zeroPick || [];  // 2026-09-30: ใบที่หยิบจริง 0 ทุกรายการ (ยกเลิกทางอ้อม · GP-05)
        if (!shortage.length && !over.length && !otherShort && !a4.length && !zp.length) { el.hidden = true; el.innerHTML = ''; return; }
        var html = '<div class="s5-alert-card">' +
            '<div class="s5-alert-head"><i class="fa-solid fa-clipboard-check"></i> ต้องตรวจสอบจากหน้าประตู' +
            '<span class="s5-alert-site">' + esc(d.siteCode || '') + '</span></div>';
        if (a4.length) {
            html += '<div class="s5-alert-sec"><div class="s5-alert-title"><i class="fa-solid fa-bell"></i> ' +
                'ALARM 4 ค้างอยู่ — ตู้หยุดรับงานจนกว่าสายสโตร์จะแตะบัตรแล้วกด "ยืนยันปิดสัญญาณ" ที่ตู้ <span class="s5-cnt">' + a4.length + '</span></div>' +
                '<div class="s5-alert-list">' + a4.map(function (a) {
                    return '<div class="s5-alert-row">' +
                        '<span class="s5-g">' + esc(a.gate || '-') + '</span>' +
                        '<span class="s5-mat"><b>พบบุคคลในโซนโดยไม่ได้สแกน</b></span>' +
                        '<span class="s5-short">ตั้งแต่ ' + esc((a.since || '').replace('T', ' ')) + '</span>' +
                    '</div>';
                }).join('') + '</div>' +
                '<div class="s5-alert-note">ปิดสัญญาณได้ที่ตู้เท่านั้น · รูปหลักฐานอยู่ในเครื่องตู้ (ดู Error log → คนเข้าโซนโดยไม่สแกน)</div></div>';
        }
        if (zp.length) {
            html += '<div class="s5-alert-sec"><div class="s5-alert-title"><i class="fa-solid fa-ban"></i> ' +
                'ALARM: ลดทุกรายการเป็น 0 — ยกเลิกใบทางอ้อมหลังแตะบัตร (30 วัน) <span class="s5-cnt">' + zp.length + '</span></div>' +
                '<div class="s5-alert-list">' + zp.slice(0, 12).map(function (z) {
                    var who = [z.by ? 'บันทึก: ' + z.by : '', z.holder ? 'แตะบัตร: ' + z.holder : ''].filter(Boolean).join(' · ');
                    return '<div class="s5-alert-row">' +
                        '<span class="s5-g">' + esc(z.gate || '-') + '</span>' +
                        '<span class="s5-mat"><b>' + esc(z.docId) + '</b> ' + esc(z.type || '') + ' · ' + esc(String(z.items)) + ' รายการ</span>' +
                        '<span class="s5-short">หยิบจริง 0 ทั้งใบ</span>' +
                        '<span class="s5-onhand">' + esc(who || '-') + '</span>' +
                        '<span class="s5-when">' + esc((z.at || '').slice(0, 10)) + '</span>' +
                        (z.reasons ? '<span class="s5-why">' + esc(z.reasons) + '</span>' : '') +
                    '</div>';
                }).join('') + '</div>' +
                '<div class="s5-alert-note">เหตุผล "สแกนผิด" = ไม่ได้หยิบของ (ไม่ต้องคืนด้วยใบ IN) · "ของไม่พอ" นับในหัวข้อของไม่พอด้วย — ตรวจกับผู้บันทึก/ผู้แตะบัตร ถ้าสงสัยว่าเอาของไปจริงให้นับสต๊อก (SC) ที่ประตูนั้น</div></div>';
        }
        if (shortage.length) {
            html += '<div class="s5-alert-sec"><div class="s5-alert-title"><i class="fa-solid fa-scale-unbalanced"></i> ' +
                'หยิบได้น้อยกว่าที่ขอเพราะของไม่พอ — ยอดในระบบอาจเพี้ยน ควรตรวจนับที่ประตู <span class="s5-cnt">' + shortage.length + '</span></div>' +
                '<div class="s5-alert-list">' + shortage.slice(0, 12).map(function (s) {
                    return '<div class="s5-alert-row">' +
                        '<span class="s5-g">' + esc(s.gate || '-') + '</span>' +
                        '<span class="s5-mat"><b>' + esc(s.matCode) + '</b> ' + esc(s.name || '') + '</span>' +
                        '<span class="s5-short">ขาด ' + esc(fmt(s.shortQty)) + (s.unit ? ' ' + esc(s.unit) : '') + ' · ' + s.lines + ' ครั้ง</span>' +
                        '<span class="s5-onhand">ในระบบ ' + (s.onHand == null ? '-' : esc(fmt(s.onHand))) + '</span>' +
                        '<span class="s5-when">' + esc((s.lastAt || '').slice(0, 10)) + '</span>' +
                        (s.reasons && s.reasons.length ? '<span class="s5-why">' + esc(s.reasons.join(' · ')) + '</span>' : '') +
                    '</div>';
                }).join('') + '</div>' +
                (otherShort > 0 ? '<div class="s5-alert-note">ลดจำนวนด้วยเหตุผลอื่นอีก ' + otherShort + ' รายการ (30 วัน)</div>' : '') +
            '</div>';
        } else if (otherShort > 0) {
            html += '<div class="s5-alert-note">หยิบน้อยกว่าที่ขอ ' + otherShort + ' รายการใน 30 วัน (ไม่ได้ระบุว่าของไม่พอ)</div>';
        }
        if (over.length) {
            html += '<div class="s5-alert-sec"><div class="s5-alert-title warn"><i class="fa-solid fa-hourglass-end"></i> ' +
                'รอบเบิกที่เวลาเกินเพดาน ' + esc(String(d.capMin || 120)) + ' นาที (14 วัน) <span class="s5-cnt">' + over.length + '</span></div>' +
                '<div class="s5-alert-list">' + over.slice(0, 10).map(function (o) {
                    return '<div class="s5-alert-row">' +
                        '<span class="s5-g">' + esc(o.gate || '-') + '</span>' +
                        '<span class="s5-mat"><b>' + esc(o.pickingId || '-') + '</b>' + (o.items != null ? ' · ' + o.items + ' รายการ' : '') + '</span>' +
                        '<span class="s5-short">' + (o.totalMin != null ? 'รวม ' + esc(fmt(o.totalMin)) + ' นาที' : 'เกินเพดาน') + '</span>' +
                        '<span class="s5-onhand">' + esc((o.cardholders || []).join(', ') || '-') + '</span>' +
                        '<span class="s5-when">' + esc((o.at || '').replace('T', ' ')) + '</span>' +
                    '</div>';
                }).join('') + '</div></div>';
        }
        html += '</div>';
        el.innerHTML = html;
        el.hidden = false;
    }
    function installDashboard() {
        wrapAfter('loadDashboard', function () { setTimeout(loadAlerts, 300); });
        var sel = document.getElementById('dashboardSiteFilter') || document.querySelector('#dashboard-page select[id*="Site"]');
        if (sel && !sel.__s5) { sel.__s5 = true; sel.addEventListener('change', function () { setTimeout(loadAlerts, 300); }); }
    }

    // ------------------------------------------------------------------ boot
    function boot() {
        try { installConfirm(); } catch (e) { console.warn('[scenario05] confirm', e); }
        try { installDailyCheck(); } catch (e) { console.warn('[scenario05] dailycheck', e); }
        try { installDashboard(); } catch (e) { console.warn('[scenario05] dashboard', e); }
        window.Scenario05 = { state: C, render: render, loadAlerts: loadAlerts };
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
