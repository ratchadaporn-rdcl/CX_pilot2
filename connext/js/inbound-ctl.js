/*
 * CONNEXT — js/inbound-ctl.js : แท็บ "รับเข้าคลัง" (ใบ IN) ตามผลพิจารณา GP-10 / OP-70 (2 ต.ค. 2026)
 *
 *   - ออกใบได้เฉพาะสายสโตร์ของไซต์ (user.canReq — server ตรวจซ้ำจาก roles) → บัญชีอื่นไม่เห็นแท็บ
 *   - ที่มาของของ (บังคับเลือก):
 *       มีใบส่งของ (supplier / PO)  → เลขที่ RS / PO / ใบส่งของ (ช่องเดิม) + รูปใบส่งของ ≥ 1
 *       ไม่มีใบส่งของ              → เลขอ้างอิงใบ TD / BD หรือเหตุผล + รูปของที่รับเข้า ≥ 1
 *   - ฟอร์มเดิมใน index.php เรียก window.inboundCtl.rsOptional() / check() / meta() / reset()
 *     แล้วส่ง meta เป็นอาร์กิวเมนต์ที่ 2 ของ processInboundBatch (lib/inbound_ctl.php)
 */
(function () {
    'use strict';

    var MAX_PHOTOS = 6;
    var REASONS = ['ของคืนจากหน้างาน', 'ของโอนมาโดยไม่มีใบ TD', 'ของเดิมที่ยังไม่ได้ลงระบบ', 'ของแถม / ตัวอย่างจากผู้ขาย'];
    var S = { source: '', photos: [], busy: 0, who: null };

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function el(id) { return document.getElementById(id); }
    function val(id) { var e = el(id); return e ? String(e.value || '').trim() : ''; }
    function isStore() { return !!(window.user && window.user.canReq === true); }
    function toast(msg, kind) {
        if (typeof window.showToast === 'function') { window.showToast(msg, kind || 'info'); }
    }

    // ------------------------------------------------------------------ สิทธิ์: ซ่อนแท็บสำหรับบัญชีที่ไม่ใช่สายสโตร์
    function tabBtn() { return document.querySelector('#requisition-page .tab-btn[onclick*="req-inbound"]'); }
    function applyAccess() {
        var b = tabBtn();
        var pane = el('req-inbound');
        if (!b || !pane) return;
        var ok = isStore();
        b.style.display = ok ? '' : 'none';
        if (!ok && pane.classList.contains('active')) {
            var first = document.querySelector('#requisition-page .tab-btn[onclick*="req-normal"]');
            if (first && typeof window.switchRequisitionTab === 'function') window.switchRequisitionTab('req-normal', first);
        }
        var note = el('inCtlLocked');
        if (note) note.hidden = ok;
    }

    // ------------------------------------------------------------------ UI ที่มาของของ + รูป
    function build() {
        var pane = el('req-inbound');
        if (!pane) return false;
        if (el('inCtlBox')) return true;
        var sec = pane.querySelector('.form-section');
        var firstGroup = sec && sec.querySelector('.form-group');
        if (!sec || !firstGroup) return false;

        var info = sec.querySelector('h3 + div span');
        if (info) {
            info.innerHTML = 'รับเข้าคลัง<b>ไม่ต้องขออนุมัติ</b> แต่ออกใบได้เฉพาะ<b>สายสโตร์ของไซต์</b> และต้องบอก<b>ที่มาของของ + แนบรูป</b> — ' +
                'บันทึกเสร็จได้ QR ทันที (เลขใบไม่มีรหัส G) จากนั้นไปสแกนที่ตู้ G ไหน<b>ของไซต์นี้</b>ก็ได้ — ยอดขึ้นที่ G ที่สแกน แล้ว<b>ถ่ายรูปยืนยันรับเข้า</b>';   // [2026-10-06]
        }

        var locked = document.createElement('div');
        locked.id = 'inCtlLocked';
        locked.className = 'in-ctl-locked';
        locked.hidden = true;
        locked.innerHTML = '<i class="fa-solid fa-lock"></i><span>ออกใบรับเข้าคลังได้เฉพาะ<b>สายสโตร์ของไซต์</b> (AST / ST1 / ST2 / SST)</span>';
        sec.insertBefore(locked, firstGroup);

        var box = document.createElement('div');
        box.id = 'inCtlBox';
        box.className = 'form-group in-ctl';
        box.innerHTML =
            '<label>ที่มาของของ <span style="color:var(--danger);">*</span></label>' +
            '<div class="in-src" role="radiogroup" aria-label="ที่มาของของ">' +
                '<button type="button" class="in-src-btn" role="radio" aria-checked="false" data-in-src="supplier">' +
                    '<i class="fa-solid fa-file-invoice"></i><span><b>มีใบส่งของ</b><small>ของจาก supplier / PO</small></span></button>' +
                '<button type="button" class="in-src-btn" role="radio" aria-checked="false" data-in-src="nonote">' +
                    '<i class="fa-solid fa-box-open"></i><span><b>ไม่มีใบส่งของ</b><small>อ้างอิงใบ TD / BD หรือเหตุผล</small></span></button>' +
            '</div>' +
            '<div class="in-nonote" id="inCtlNoNote" hidden>' +
                '<input type="text" id="inCtlRef" class="form-control" autocomplete="off" maxlength="30" ' +
                    'placeholder="เลขที่ใบ TD / BD ที่อ้างอิง (ถ้ามี) เช่น TD021026001G01">' +
                '<input type="text" id="inCtlReason" class="form-control" maxlength="200" placeholder="หรือเหตุผลที่ไม่มีใบส่งของ">' +
                '<div class="in-chips">' + REASONS.map(function (r) {
                    return '<button type="button" class="in-chip" data-in-reason="' + esc(r) + '">' + esc(r) + '</button>';
                }).join('') + '</div>' +
            '</div>' +
            '<div class="in-photos" id="inCtlPhotoBox" hidden>' +
                '<div class="in-photo-lbl" id="inCtlPhotoLbl"></div>' +
                '<div class="in-photo-list" id="inCtlPhotos"></div>' +
                '<label class="in-photo-add" id="inCtlAdd"><input type="file" id="inCtlFile" accept="image/*" multiple hidden>' +
                    '<i class="fa-solid fa-camera"></i> <span>ถ่าย / เลือกรูป</span></label>' +
            '</div>';
        sec.insertBefore(box, firstGroup);

        box.addEventListener('click', function (ev) {
            var b = ev.target.closest('[data-in-src]');
            if (b) { S.source = b.getAttribute('data-in-src'); render(); return; }
            var c = ev.target.closest('[data-in-reason]');
            if (c) { var r = el('inCtlReason'); if (r) r.value = c.getAttribute('data-in-reason'); render(); return; }
            var x = ev.target.closest('[data-in-rm]');
            if (x) { S.photos.splice(parseInt(x.getAttribute('data-in-rm'), 10), 1); render(); }
        });
        box.addEventListener('input', function (ev) { if (ev.target && ev.target.id === 'inCtlReason') render(); });
        el('inCtlFile').addEventListener('change', function () { addFiles(this); });
        render();
        return true;
    }

    function addFiles(input) {
        var files = Array.prototype.slice.call((input && input.files) || []);
        input.value = '';
        var room = MAX_PHOTOS - S.photos.length;
        if (files.length > room) {
            toast('แนบรูปได้สูงสุด ' + MAX_PHOTOS + ' รูป', 'warning');
            files = files.slice(0, Math.max(room, 0));
        }
        if (!files.length || typeof window.compressImage !== 'function') return;
        S.busy++;
        render();
        // ใบส่งของมีตัวหนังสือ → ย่อน้อยกว่ารูปยืนยันปกติ (1600px · คุณภาพ 0.75)
        Promise.all(files.map(function (f) { return window.compressImage(f, { maxDim: 1600, quality: 0.75 }); }))
            .then(function (res) {
                res.forEach(function (r) {
                    if (r && r.dataUrl && r.dataUrl.indexOf('data:image/') === 0) S.photos.push(r.dataUrl);
                    else toast('อ่านรูปไม่ได้ — ใช้ไฟล์รูป JPEG / PNG / WebP', 'warning');
                });
            })
            .catch(function () { toast('บีบอัดรูปไม่สำเร็จ ลองใหม่อีกครั้ง', 'warning'); })
            .then(function () { S.busy--; render(); });
    }

    function render() {
        if (!el('inCtlBox')) return;
        Array.prototype.forEach.call(document.querySelectorAll('#inCtlBox [data-in-src]'), function (b) {
            var on = b.getAttribute('data-in-src') === S.source;
            b.classList.toggle('on', on);
            b.setAttribute('aria-checked', on ? 'true' : 'false');
        });
        var nonote = S.source === 'nonote';
        el('inCtlNoNote').hidden = !nonote;
        el('inCtlPhotoBox').hidden = !S.source;
        var reason = val('inCtlReason');
        Array.prototype.forEach.call(document.querySelectorAll('#inCtlBox [data-in-reason]'), function (c) {
            c.classList.toggle('on', c.getAttribute('data-in-reason') === reason);
        });
        el('inCtlPhotoLbl').innerHTML = (nonote ? 'รูปของที่รับเข้า' : 'รูปใบส่งของ') +
            ' <span style="color:var(--danger);">*</span> <small>อย่างน้อย 1 รูป (สูงสุด ' + MAX_PHOTOS + ')</small>';
        el('inCtlPhotos').innerHTML = S.photos.map(function (u, i) {
            return '<div class="in-thumb"><img src="' + u + '" alt="รูป ' + (i + 1) + '">' +
                '<button type="button" data-in-rm="' + i + '" title="ลบรูปนี้" aria-label="ลบรูปนี้"><i class="fa-solid fa-xmark"></i></button></div>';
        }).join('');
        var add = el('inCtlAdd');
        add.classList.toggle('busy', S.busy > 0);
        add.hidden = S.photos.length >= MAX_PHOTOS;
        // ช่องเลขที่ RS เดิม: บังคับเฉพาะ "มีใบส่งของ"
        var rsLbl = el('inboundRSInput') && el('inboundRSInput').closest('.form-group').querySelector('label');
        if (rsLbl) {
            rsLbl.innerHTML = nonote ? 'เลขที่ RS / PO (ถ้ามี)'
                : 'เลขที่ใบรับสินค้า (RS) / PO / ใบส่งของ <span style="color:var(--danger);">*</span>';
        }
    }

    // ------------------------------------------------------------------ API ให้ฟอร์มเดิมเรียก
    function rsOptional() { return S.source === 'nonote'; }
    function check() {
        if (!isStore()) return 'ออกใบรับเข้าคลังได้เฉพาะสายสโตร์ของไซต์ (AST / ST1 / ST2 / SST)';
        if (!S.source) return 'เลือกที่มาของของก่อน: "มีใบส่งของ" หรือ "ไม่มีใบส่งของ"';
        if (S.busy) return 'กำลังเตรียมรูป — รอสักครู่แล้วกดอีกครั้ง';
        if (S.source === 'nonote') {
            var ref = val('inCtlRef').replace(/\s+/g, '').toUpperCase();
            if (!ref && val('inCtlReason').length < 3) return 'ไม่มีใบส่งของ: ใส่เลขที่ใบ TD / BD ที่อ้างอิง หรือเหตุผล';
            if (ref && !/^(TD|BD)[0-9A-Z]{4,}$/.test(ref)) return 'เลขอ้างอิงต้องเป็นเลขใบ TD (โอนย้าย) หรือ BD (ยืม)';
            if (!S.photos.length) return 'แนบรูปของที่รับเข้าอย่างน้อย 1 รูป';
        } else if (!S.photos.length) {
            return 'แนบรูปใบส่งของอย่างน้อย 1 รูป';
        }
        return '';
    }
    function meta() {
        return {
            source: S.source,
            ref: S.source === 'nonote' ? val('inCtlRef').replace(/\s+/g, '').toUpperCase() : '',
            reason: S.source === 'nonote' ? val('inCtlReason') : '',
            photos: S.photos.slice()
        };
    }
    function reset() {
        S.source = '';
        S.photos = [];
        ['inCtlRef', 'inCtlReason'].forEach(function (id) { var e = el(id); if (e) e.value = ''; });
        render();
    }

    // ------------------------------------------------------------------ boot
    function wrapAfter(name, fn) {
        var orig = window[name];
        if (typeof orig !== 'function' || orig.__inWrapped) return;
        var w = function () {
            var r = orig.apply(this, arguments);
            try { fn.apply(this, arguments); } catch (e) { console.warn('[inbound-ctl] ' + name + ':', e); }
            return r;
        };
        w.__inWrapped = true;
        window[name] = w;
    }
    function tick() {
        var who = window.user ? (String(window.user.username || '') + '|' + (window.user.canReq === true)) : '';
        if (who !== S.who) { S.who = who; applyAccess(); }
    }
    function boot() {
        try { build(); } catch (e) { console.warn('[inbound-ctl] build', e); }
        wrapAfter('switchRequisitionTab', function (tabId) { if (tabId === 'req-inbound') applyAccess(); });
        tick();
        setInterval(tick, 1500);   // ล็อกอิน / สลับบัญชี → ซ่อน-แสดงแท็บใหม่
        window.inboundCtl = { rsOptional: rsOptional, check: check, meta: meta, reset: reset, state: S, applyAccess: applyAccess };
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
