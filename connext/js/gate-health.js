/*
 * CONNEXT — js/gate-health.js : Dashboard "สถานะตู้" (2 ต.ค. 2026 · GP-02 / GP-30–33 · AL-27)
 *
 *   ตู้ส่งสัญญาณชีพทุก 1 นาที (api/gate.php heartbeat) — การ์ดนี้แสดงทุกตู้ของไซต์:
 *   ออนไลน์ / ออฟไลน์ (เงียบเกิน 5 นาที + เหตุ เช่น โปรแกรมถูกปิดโดยใคร) / ยังไม่เคยส่ง (โปรแกรมตู้รุ่นเก่า) ·
 *   กล้อง / ตัวตรวจจับ / หัวอ่านที่เสีย · เห็นเฉพาะ R0 / R8 ขึ้นไป / สายสโตร์ (server กรองเอง) · รีเฟรชทุก 60 วินาทีขณะเปิด Dashboard
 */
(function () {
    'use strict';

    var H = { busy: false, timer: null };

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function wrapAfter(name, fn) {
        var orig = window[name];
        if (typeof orig !== 'function' || orig.__ghWrapped) return;
        var w = function () {
            var r = orig.apply(this, arguments);
            try { fn.apply(this, arguments); } catch (e) { console.warn('[gate-health] ' + name + ':', e); }
            return r;
        };
        w.__ghWrapped = true;
        window[name] = w;
    }
    function dashSite() {
        var sel = document.getElementById('dashboardSiteFilter') || document.querySelector('#dashboard-page select[id*="Site"]');
        return sel ? (sel.value || '') : '';
    }
    function cardEl() {
        var el = document.getElementById('ghCard');
        if (el) return el;
        var page = document.getElementById('dashboard-page');
        if (!page) return null;
        el = document.createElement('div');
        el.id = 'ghCard';
        el.hidden = true;
        var ov = document.getElementById('iiOverview');
        if (ov) ov.insertBefore(el, ov.firstChild);
        else {
            var head = page.querySelector('.page-header');
            if (head && head.parentNode) head.parentNode.insertBefore(el, head.nextSibling); else page.insertBefore(el, page.firstChild);
        }
        return el;
    }
    function ago(sec) {
        if (sec == null) return '';
        if (sec < 90) return 'เมื่อสักครู่';
        if (sec < 3600) return Math.round(sec / 60) + ' นาทีก่อน';
        if (sec < 86400) return Math.round(sec / 3600) + ' ชม.ก่อน';
        return Math.round(sec / 86400) + ' วันก่อน';
    }
    function devHtml(g) {
        var out = [];
        var names = { camera: 'กล้อง', detector: 'ตัวตรวจจับ', reader: 'หัวอ่าน' };
        Object.keys(names).forEach(function (k) {
            var v = g[k];
            if (!v) return;
            if (v === 'off') { if (k === 'camera') out.push('<span class="gh-dev off">ไม่มี CCTV</span>'); return; }
            out.push('<span class="gh-dev ' + (v === 'ok' ? 'ok' : 'bad') + '">' + names[k] + (v === 'ok' ? ' ✓' : ' ใช้ไม่ได้') + '</span>');
        });
        return out.join('');
    }
    function render(d) {
        var el = cardEl();
        if (!el) return;
        var list = (d && d.success && d.data) || [];
        if (!list.length) { el.hidden = true; el.innerHTML = ''; return; }
        var bad = list.filter(function (g) { return g.state === 'offline' || /fail/.test([g.camera, g.detector, g.reader].join(' ')); }).length;
        var html = '<div class="gh-card' + (bad ? ' has-bad' : '') + '">' +
            '<div class="gh-head"><i class="fa-solid fa-tower-broadcast"></i> สถานะตู้' +
            (bad ? ' <span class="gh-badge">ผิดปกติ ' + bad + '</span>' : ' <span class="gh-sub">ตู้ส่งสัญญาณชีพทุก 1 นาที · เงียบเกิน ' + (d.offlineMin || 5) + ' นาที = ออฟไลน์</span>') +
            '</div><div class="gh-list">';
        list.forEach(function (g) {
            var st = g.state === 'online' ? '<span class="gh-st on">ออนไลน์</span>'
                   : g.state === 'offline' ? '<span class="gh-st offl">ออฟไลน์</span>'
                   : '<span class="gh-st never">ยังไม่เคยส่งสัญญาณ</span>';
            var sub = g.state === 'never' ? 'โปรแกรมตู้รุ่นก่อน 2 ต.ค. ยังไม่ส่งสัญญาณชีพ'
                    : g.state === 'offline' ? 'เงียบตั้งแต่ ' + esc(g.lastSeen) + (g.why ? ' · ' + esc(g.why) : ' · ไฟ/เน็ต/โปรแกรมตู้ — ประตูไม่มีไฟ = ไม่ล็อก')
                    : 'ล่าสุด ' + esc(ago(g.ageSec)) + (g.pickingId ? ' · รอบ ' + esc(g.pickingId) : '') + (g.version ? ' · v' + esc(g.version) : '');
            html += '<div class="gh-row ' + esc(g.state) + '">' +
                '<div class="gh-gate"><b>' + esc(g.gate) + '</b>' + (g.name ? ' <span>' + esc(g.name) + '</span>' : '') +
                (list.some(function (x) { return x.site !== g.site; }) ? ' <span class="gh-site">' + esc(g.site) + '</span>' : '') + '</div>' +
                '<div class="gh-mid">' + st + devHtml(g) + '</div>' +
                '<div class="gh-sub2">' + sub + '</div></div>';
        });
        el.innerHTML = html + '</div></div>';
        el.hidden = false;
    }
    function load() {
        if (H.busy || !window.user) return;
        var page = document.getElementById('dashboard-page');
        if (page && !page.classList.contains('active') && page.offsetParent === null) return;
        H.busy = true;
        google.script.run
            .withSuccessHandler(function (d) { H.busy = false; render(d); })
            .withFailureHandler(function () { H.busy = false; })
            .getGateHealth(dashSite());
    }
    function boot() {
        wrapAfter('loadDashboard', function () { setTimeout(load, 500); });
        var sel = document.getElementById('dashboardSiteFilter');
        if (sel) sel.addEventListener('change', function () { setTimeout(load, 300); });
        H.timer = setInterval(function () { if (document.visibilityState !== 'hidden') load(); }, 60000);
        window.cnxGateHealth = { load: load };
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
