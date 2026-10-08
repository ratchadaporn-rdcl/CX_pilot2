/*
 * CONNEXT — js/qr-sign.js : QR รูปแบบใหม่ = {Doc, Req, Receiver, Site, Chk} (GP-17 / GP-21 / OP-73 · 2 ต.ค. 2026)
 *
 *   ทุก QR ของแอปเปิดผ่าน window.showQRModal (RD / OD / BD / ขาคืน …RT / IN / TD / SC / TG) — ตัวนี้ห่อ showQRModal:
 *   วาด QR เดิมแล้ว ขอรหัสตรวจสอบจากเว็บ (getQrPayload — lib/qr_sign.php) แล้ววาดใหม่พร้อม Site + Chk
 *   ตู้รุ่นเก่าอ่าน Doc อย่างเดียว (คีย์อื่นไม่สนใจ) · ตู้รุ่นใหม่ตรวจ Site ตรงกับตู้ + ส่ง Chk ให้เว็บตรวจ
 *   ขอรหัสไม่ได้ (ออฟไลน์/ไม่มีสิทธิ์) → QR เดิม + ข้อความเตือน (ตู้ที่บังคับรหัสแล้วจะไม่รับ)
 */
(function () {
    'use strict';

    var seq = 0;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function canvas() { return document.getElementById('qrCanvas'); }
    function draw(json) {
        var c = canvas();
        if (c && typeof window._localQrImgTag === 'function') c.innerHTML = window._localQrImgTag(json);
    }
    function basePayload(docId) {
        try {
            var p = window._qrPayload && window._qrPayload.jsonStr ? JSON.parse(window._qrPayload.jsonStr) : null;
            if (p && p.Doc) return p;
        } catch (e) { /* QR เดิมไม่ใช่ JSON */ }
        return { Doc: docId };
    }
    function fallback(base, msg) {
        draw(JSON.stringify(base));
        var c = canvas();
        if (c) {
            c.insertAdjacentHTML('beforeend', '<div class="qs-note"><i class="fa-solid fa-triangle-exclamation"></i> QR ยังไม่มีรหัสตรวจสอบ' +
                (msg ? ' (' + esc(msg) + ')' : '') + ' — ตู้ที่บังคับรหัสแล้วจะไม่รับ · ปิดแล้วเปิด QR ใหม่</div>');
        }
    }
    function sign(docId) {
        if (!docId) return;
        var my = ++seq;
        var base = basePayload(docId);
        var c = canvas();
        if (c) c.innerHTML = '<div class="qs-wait"><i class="fa-solid fa-spinner fa-spin"></i> กำลังสร้าง QR …</div>';
        google.script.run
            .withSuccessHandler(function (r) {
                if (my !== seq) return;
                if (r && r.success && r.chk) {
                    // Site + Chk ต่อจาก Doc ทันที (ก่อนชื่อผู้เบิก/ผู้รับภาษาไทย) — หัวอ่านที่ตู้ส่ง QR ยาวมาเป็น 2 ช่วง
                    // ตู้เก็บ Doc/Site/Chk ได้จากช่วงแรก (เดิม Chk อยู่ท้ายสุด = หลุดไปช่วงหลัง → ตู้ขึ้น "ไม่มีรหัสตรวจสอบ")
                    var signed = { Doc: base.Doc, Site: r.site, Chk: r.chk };
                    Object.keys(base).forEach(function (k) { if (!(k in signed)) signed[k] = base[k]; });
                    var json = JSON.stringify(signed);
                    if (window._qrPayload) window._qrPayload.jsonStr = json;
                    draw(json);
                } else {
                    fallback(base, (r && r.message) || '');
                }
            })
            .withFailureHandler(function (e) {
                if (my !== seq) return;
                fallback(base, e && e.message ? e.message : 'เชื่อมต่อไม่ได้');
            })
            .getQrPayload(docId);
    }

    function wrap() {
        var orig = window.showQRModal;
        if (typeof orig !== 'function' || orig.__qsWrapped) return;
        var w = function (docId) {
            var r = orig.apply(this, arguments);
            try { sign(String(docId || '')); } catch (e) { console.warn('[qr-sign]', e); }
            return r;
        };
        w.__qsWrapped = true;
        window.showQRModal = w;
    }
    function boot() {
        var st = document.createElement('style');
        st.textContent = '.qs-wait{width:220px;height:220px;display:flex;align-items:center;justify-content:center;gap:8px;color:#64748b;font-size:.85rem}' +
            '.qs-note{margin-top:6px;max-width:260px;font-size:.76rem;line-height:1.45;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:6px 8px}';
        document.head.appendChild(st);
        wrap();
        window.cnxQrSign = { sign: sign };
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
