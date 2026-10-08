/*
 * CONNEXT — js/dispatch-rounds.js  [PHP port 2026-09-24 · มติ 50]
 * รอบจ่าย: "เบิกก่อน 08:00 จ่าย 09:00 · เบิก 08:00–09:59 จ่าย 11:00 …" ตั้งรายไซต์
 *   1) หน้าต่าง "ตั้งค่ารอบจ่าย" (ADM ทุกไซต์ · สายคลัง CanReq ไซต์ตัวเอง) — เรียกได้จากแถบหน้าเบิก และแท็บ "รอบจ่าย" บน Dashboard
 *   2) แถบบนหน้าเบิก-จ่าย: เบิกตอนนี้ได้รอบไหน · ปิดรับอีกกี่นาที · รอบของวันนี้
 *   3) การ์ดหน้า QR: ป้าย "รอบจ่าย 11:00" / "เลยรอบ 09:00" ต่อใบ (คิดจากเวลาออกใบ)
 * กติกาเดียวกับ lib/dispatch_rounds.php (drRoundFor) — ช่วงของรอบ = ตั้งแต่ cutoff รอบก่อน ถึง "ก่อน" cutoff รอบนี้ ·
 * หลัง cutoff สุดท้าย/วันไม่ทำงาน → รอบแรกของวันทำงานถัดไป · ห่อฟังก์ชันเดิม ไม่แก้ตรรกะใน index.php
 * ไม่มีใน GAS — ถ้า build index.php ใหม่ให้เติม <link>/<script defer> ไฟล์นี้กลับก่อน </head>
 */
(function () {
    'use strict';

    var DAY_TH = ['', 'จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.', 'อา.'];                 // ISO 1..7
    var MON_TH = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    var SAMPLE = [{ cutoff: '08:00', dispatch: '09:00' }, { cutoff: '10:00', dispatch: '11:00' },
                  { cutoff: '13:00', dispatch: '14:00' }, { cutoff: '15:00', dispatch: '16:00' }];

    var S = { cfg: null, offset: 0, timer: null, editSite: '', onSaved: null };

    // ------------------------------------------------------------------ ตัวช่วย
    function $(sel, root) { return (root || document).querySelector(sel); }
    function $all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
    function esc(v) {
        if (typeof window.escapeHtml === 'function') return window.escapeHtml(v == null ? '' : String(v));
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
    }
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
    function hm(d) { return pad(d.getHours()) + ':' + pad(d.getMinutes()); }
    function now() { return new Date(Date.now() + S.offset); }
    function toast(m, t) { if (typeof window.showToast === 'function') window.showToast(m, t || 'info'); }
    function isoDay(d) { var x = d.getDay(); return x === 0 ? 7 : x; }
    function el(tag, cls, html) { var e = document.createElement(tag); if (cls) e.className = cls; if (html != null) e.innerHTML = html; return e; }
    function after(name, fn) {
        var orig = window[name];
        if (typeof orig !== 'function' || orig.__drWrapped) return;
        var w = function () {
            var r = orig.apply(this, arguments);
            try { fn.apply(this, arguments); } catch (e) { console.warn('[dispatch-rounds] ' + name + ':', e); }
            return r;
        };
        w.__drWrapped = true;
        window[name] = w;
    }

    /** รอบของเวลาที่เบิก (Date) → {date, cutoff, dispatch, idx, at, dayOffset} หรือ null — เหมือน drRoundFor ฝั่ง PHP */
    function roundFor(cfg, dt) {
        if (!cfg || !cfg.enabled || !cfg.rounds || !cfg.rounds.length || !cfg.workDays || !cfg.workDays.length) return null;
        var d = new Date(dt.getFullYear(), dt.getMonth(), dt.getDate());
        var t = hm(dt);
        for (var i = 0; i < 15; i++) {
            if (cfg.workDays.indexOf(isoDay(d)) !== -1) {
                for (var k = 0; k < cfg.rounds.length; k++) {
                    if (i > 0 || t < cfg.rounds[k].cutoff) {
                        return { date: ymd(d), cutoff: cfg.rounds[k].cutoff, dispatch: cfg.rounds[k].dispatch, idx: k,
                                 at: ymd(d) + ' ' + cfg.rounds[k].dispatch, dayOffset: i, day: new Date(d.getTime()) };
                    }
                }
            }
            d.setDate(d.getDate() + 1);
        }
        return null;
    }

    /** "วันนี้" / "พรุ่งนี้" / "ศ. 26 ก.ย." เทียบกับวันนี้ */
    function dayLabel(dateStr) {
        var n = now(), today = ymd(n);
        if (dateStr === today) return 'วันนี้';
        var t = new Date(n.getFullYear(), n.getMonth(), n.getDate() + 1);
        if (dateStr === ymd(t)) return 'พรุ่งนี้';
        var p = dateStr.split('-');
        var d = new Date(+p[0], +p[1] - 1, +p[2]);
        return DAY_TH[isoDay(d)] + ' ' + d.getDate() + ' ' + MON_TH[d.getMonth()];
    }
    function minusMinute(t) {
        var p = t.split(':'), m = (+p[0]) * 60 + (+p[1]) - 1;
        if (m < 0) m = 0;
        return pad(Math.floor(m / 60)) + ':' + pad(m % 60);
    }
    function untilText(ms) {
        if (ms > 0 && ms < 60000) return 'อีกไม่ถึง 1 นาที';
        var min = Math.max(0, Math.round(ms / 60000));
        if (min < 60) return 'อีก ' + min + ' นาที';
        return 'อีก ' + Math.floor(min / 60) + ' ชม.' + (min % 60 ? ' ' + (min % 60) + ' นาที' : '');
    }

    // ------------------------------------------------------------------ โหลดค่าตั้ง
    function load(force, cb) {
        if (!window.user || !window.google || !google.script) { if (cb) cb(null); return; }
        var done = function (d) {
            if (d && d.success) {
                S.cfg = d;
                if (d.serverNow) {
                    var m = d.serverNow.match(/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})/);
                    if (m) S.offset = new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +m[6]).getTime() - Date.now();
                }
            }
            renderStrip();
            decorateQr();
            if (cb) cb(d);
        };
        var fail = function (e) { console.warn('getDispatchRounds', e); if (cb) cb(null); };
        if (window.connextCache && connextCache.swr && !force) {
            connextCache.swr('drRounds', [''], function (ok, bad) {
                google.script.run.withSuccessHandler(ok).withFailureHandler(bad).getDispatchRounds('');
            }, done, fail);
        } else {
            google.script.run.withSuccessHandler(done).withFailureHandler(fail).getDispatchRounds('');
        }
    }

    // ------------------------------------------------------------------ แถบบนหน้าเบิก-จ่าย
    function renderStrip() {
        var page = document.getElementById('requisition-page');
        if (!page) return;
        var strip = document.getElementById('drStrip');
        if (!strip) {
            strip = el('div', 'dr-strip');
            strip.id = 'drStrip';
            strip.hidden = true;
            var head = page.querySelector('.page-header');
            if (head && head.parentNode) head.parentNode.insertBefore(strip, head.nextSibling); else page.insertBefore(strip, page.firstChild);
            strip.addEventListener('click', function (e) {
                if (e.target.closest('.dr-gear')) openSettings(S.cfg ? S.cfg.siteCode : '', null);
            });
        }
        var c = S.cfg;
        if (!c) { strip.hidden = true; return; }
        var gear = c.canEdit ? '<button type="button" class="dr-gear" title="ตั้งค่ารอบจ่าย" aria-label="ตั้งค่ารอบจ่าย"><i class="fa-solid fa-gear" aria-hidden="true"></i></button>' : '';
        if (!c.enabled) {
            if (!c.canEdit) { strip.hidden = true; return; }
            strip.hidden = false;
            strip.className = 'dr-strip dr-off';
            strip.innerHTML = '<i class="fa-solid fa-truck-fast dr-ico" aria-hidden="true"></i><div class="dr-main"><div class="dr-now">ยังไม่ได้ตั้งรอบจ่ายของไซต์ ' + esc(c.siteCode) +
                '</div><div class="dr-sub">ตั้งแล้วผู้เบิกจะเห็นว่าของจะจ่ายรอบไหน เช่น เบิกก่อน 08:00 จ่าย 09:00</div></div>' +
                '<button type="button" class="dr-gear dr-gear-txt"><i class="fa-solid fa-gear" aria-hidden="true"></i> ตั้งค่ารอบจ่าย</button>';
            return;
        }
        var n = now();
        var r = roundFor(c, n);
        if (!r) { strip.hidden = true; return; }
        var todayWork = c.workDays.indexOf(isoDay(n)) !== -1;
        var cutAt = new Date(r.day.getFullYear(), r.day.getMonth(), r.day.getDate(), +r.cutoff.split(':')[0], +r.cutoff.split(':')[1]);
        var sub;
        if (r.dayOffset === 0) {
            var left = cutAt.getTime() - n.getTime();
            sub = 'ปิดรับรอบนี้ ' + r.cutoff + ' · ' + untilText(left);
            strip.className = 'dr-strip' + (left < 15 * 60000 ? ' dr-soon' : '');
        } else {
            sub = todayWork ? 'รอบของวันนี้ปิดรับแล้ว (รอบสุดท้ายปิด ' + c.rounds[c.rounds.length - 1].cutoff + ')' : 'วันนี้ไม่มีรอบจ่าย';
            strip.className = 'dr-strip dr-next';
        }
        var chips = todayWork ? c.rounds.map(function (x, i) {
            var st = hm(n) >= x.dispatch ? 'done' : (r.dayOffset === 0 && i === r.idx ? 'cur' : (hm(n) >= x.cutoff ? 'closed' : ''));
            var from = i > 0 ? c.rounds[i - 1].cutoff : '';
            var tip = (from ? 'เบิก ' + from + '–' + minusMinute(x.cutoff) : 'เบิกก่อน ' + x.cutoff) + ' → จ่าย ' + x.dispatch;
            return '<span class="dr-rc ' + st + '" title="' + esc(tip) + '">' + (st === 'done' ? '<i class="fa-solid fa-check" aria-hidden="true"></i> ' : '') + esc(x.dispatch) + '</span>';
        }).join('') : '';
        strip.hidden = false;
        strip.innerHTML = '<i class="fa-solid fa-truck-fast dr-ico" aria-hidden="true"></i>' +
            '<div class="dr-main"><div class="dr-now">เบิกตอนนี้ → จ่าย<b> ' + (r.dayOffset === 0 ? 'รอบ ' + r.dispatch : dayLabel(r.date) + ' ' + r.dispatch) + '</b></div>' +
                '<div class="dr-sub">' + esc(sub) + '</div></div>' +
            (chips ? '<div class="dr-rcs" aria-label="รอบจ่ายวันนี้">' + chips + '</div>' : '') + gear;
    }

    // ------------------------------------------------------------------ ป้ายรอบบนการ์ดหน้า QR
    function parseDmy(s) {   // 'd/m/Y H:i' (lib/gate_api.php _gateFmtDate)
        var m = String(s || '').match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})\s+(\d{1,2}):(\d{2})/);
        return m ? new Date(+m[3], +m[2] - 1, +m[1], +m[4], +m[5]) : null;
    }
    function decorateQr() {
        var grid = document.getElementById('qrCardsGrid');
        if (!grid) return;
        $all('.dr-qchip', grid).forEach(function (x) { x.parentNode.removeChild(x); });
        var c = S.cfg;
        if (!c || !c.enabled || !window.qrPageData) return;
        var byId = {};
        (window.qrPageData || []).forEach(function (d) { byId[d.docId] = d; });
        var n = now(), nowStr = ymd(n) + ' ' + hm(n);
        $all('.qr-doc-card', grid).forEach(function (card) {
            var idEl = card.querySelector('.qrc-doc-id');
            var doc = idEl ? byId[idEl.textContent.trim()] : null;
            if (!doc || doc.type === 'IN') return;                 // รับเข้าไม่ใช่การจ่าย
            var ts = parseDmy(doc.dateStr);
            var r = ts ? roundFor(c, ts) : null;
            if (!r) return;
            var late = r.at <= nowStr;
            var chip = el('span', 'dr-qchip' + (late ? ' late' : ''),
                '<i class="fa-solid ' + (late ? 'fa-hourglass-half' : 'fa-truck-fast') + '" aria-hidden="true"></i> ' +
                (late ? 'เลยรอบ ' : 'รอบจ่าย ') + esc((r.date === ymd(n) ? '' : dayLabel(r.date) + ' ') + r.dispatch));
            chip.title = 'เบิก ' + doc.dateStr + ' → รอบจ่าย ' + r.date + ' ' + r.dispatch;
            var host = card.querySelector('.qrc-header > div') || card;
            host.appendChild(chip);
        });
    }

    // ------------------------------------------------------------------ หน้าต่างตั้งค่า
    function buildModal() {
        if (document.getElementById('drBackdrop')) return;
        var days = [1, 2, 3, 4, 5, 6, 7].map(function (d) {
            return '<button type="button" class="dr-day" data-day="' + d + '" aria-pressed="false">' + DAY_TH[d] + '</button>';
        }).join('');
        var b = el('div', 'dr-backdrop',
            '<div class="dr-modal" role="dialog" aria-modal="true" aria-labelledby="drTitle">' +
                '<div class="dr-mhead"><h3 id="drTitle"><i class="fa-solid fa-truck-fast" aria-hidden="true"></i> ตั้งค่ารอบจ่าย</h3>' +
                    '<span class="dr-msite" id="drMSite"></span><button type="button" class="dr-x" aria-label="ปิด"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>' +
                '<div class="dr-mbody">' +
                    '<label class="dr-switch"><input type="checkbox" id="drEnabled"><span>เปิดใช้รอบจ่าย</span></label>' +
                    '<div class="dr-sec"><div class="dr-lbl">วันที่มีรอบจ่าย</div><div class="dr-days" id="drDays">' + days + '</div></div>' +
                    '<div class="dr-sec"><div class="dr-lbl">รอบจ่าย <span class="dr-hint">เบิก<b>ก่อน</b>เวลาไหน → จ่ายเวลาไหน</span></div>' +
                        '<div id="drRows"></div>' +
                        '<div class="dr-rowbtns"><button type="button" class="ii-link-btn" id="drAdd"><i class="fa-solid fa-plus" aria-hidden="true"></i> เพิ่มรอบ</button>' +
                        '<button type="button" class="ii-link-btn" id="drSample"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> ใช้ตัวอย่าง 4 รอบ</button></div></div>' +
                    '<div class="dr-preview" id="drPreview"></div>' +
                    '<div class="dr-err" id="drErr" hidden></div>' +
                '</div>' +
                '<div class="dr-mfoot"><button type="button" class="btn btn-secondary" id="drCancel">ยกเลิก</button>' +
                    '<button type="button" class="btn btn-primary" id="drSave"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> บันทึกรอบจ่าย</button></div>' +
            '</div>');
        b.id = 'drBackdrop';
        document.body.appendChild(b);
        b.addEventListener('click', function (e) {
            if (e.target === b || e.target.closest('.dr-x') || e.target.closest('#drCancel')) { closeSettings(); return; }
            var day = e.target.closest('.dr-day');
            if (day) { day.classList.toggle('on'); day.setAttribute('aria-pressed', day.classList.contains('on') ? 'true' : 'false'); validate(); return; }
            if (e.target.closest('#drAdd')) { addRow('', ''); validate(); return; }
            if (e.target.closest('#drSample')) { setRows(SAMPLE); validate(); return; }
            var del = e.target.closest('.dr-del');
            if (del) { var row = del.closest('.dr-row'); row.parentNode.removeChild(row); numberRows(); validate(); return; }
            if (e.target.closest('#drSave')) { save(); }
        });
        b.addEventListener('input', validate);
        b.addEventListener('change', validate);
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && b.classList.contains('open')) closeSettings(); });
    }

    function addRow(cut, dis) {
        var box = document.getElementById('drRows');
        var row = el('div', 'dr-row',
            '<span class="dr-no"></span>' +
            '<label class="dr-tl"><span>เบิกก่อน</span><input type="time" class="form-control dr-cut" step="300" value="' + esc(cut) + '"></label>' +
            '<i class="fa-solid fa-arrow-right dr-arrow" aria-hidden="true"></i>' +
            '<label class="dr-tl"><span>จ่าย</span><input type="time" class="form-control dr-dis" step="300" value="' + esc(dis) + '"></label>' +
            '<button type="button" class="dr-del" aria-label="ลบรอบนี้"><i class="fa-solid fa-trash-can" aria-hidden="true"></i></button>');
        box.appendChild(row);
        numberRows();
    }
    function setRows(list) {
        document.getElementById('drRows').innerHTML = '';
        (list || []).forEach(function (r) { addRow(r.cutoff, r.dispatch); });
        if (!list || !list.length) addRow('', '');
    }
    function numberRows() { $all('#drRows .dr-row').forEach(function (r, i) { r.querySelector('.dr-no').textContent = i + 1; }); }
    function readRows() {
        return $all('#drRows .dr-row').map(function (r) {
            return { cutoff: r.querySelector('.dr-cut').value.trim(), dispatch: r.querySelector('.dr-dis').value.trim(), el: r };
        });
    }

    /** ตรวจแบบเดียวกับ server + เขียนตัวอย่างการจัดรอบ — คืนรายการรอบที่ใช้ได้ (เรียงแล้ว) หรือ null */
    function validate() {
        var rows = readRows(), errs = [], list = [];
        rows.forEach(function (r, i) {
            r.el.classList.remove('bad');
            if (!r.cutoff && !r.dispatch) return;
            if (!/^\d{2}:\d{2}$/.test(r.cutoff) || !/^\d{2}:\d{2}$/.test(r.dispatch)) { errs.push('รอบที่ ' + (i + 1) + ': ใส่เวลาให้ครบ'); r.el.classList.add('bad'); return; }
            if (r.dispatch <= r.cutoff) { errs.push('รอบที่ ' + (i + 1) + ': เวลาจ่ายต้องหลังเวลาเบิกก่อน'); r.el.classList.add('bad'); return; }
            list.push(r);
        });
        list.sort(function (a, b) { return a.cutoff < b.cutoff ? -1 : a.cutoff > b.cutoff ? 1 : 0; });
        for (var i = 1; i < list.length; i++) {
            if (list[i].cutoff === list[i - 1].cutoff) { errs.push('เวลาเบิกก่อน ' + list[i].cutoff + ' ซ้ำกัน'); list[i].el.classList.add('bad'); }
            else if (list[i].dispatch <= list[i - 1].dispatch) { errs.push('รอบ "เบิกก่อน ' + list[i].cutoff + '" ต้องจ่ายหลังรอบก่อนหน้า (' + list[i - 1].dispatch + ')'); list[i].el.classList.add('bad'); }
        }
        var enabled = document.getElementById('drEnabled').checked;
        var days = $all('#drDays .dr-day.on').map(function (b) { return +b.getAttribute('data-day'); });
        if (enabled && !list.length) errs.push('เปิดใช้รอบจ่ายต้องมีอย่างน้อย 1 รอบ');
        if (enabled && !days.length) errs.push('เลือกวันที่มีรอบจ่ายอย่างน้อย 1 วัน');
        if (list.length > 12) errs.push('ตั้งได้ไม่เกิน 12 รอบ');

        var pv = document.getElementById('drPreview');
        if (list.length) {
            var lines = list.map(function (r, k) {
                return '<li>' + (k === 0 ? 'เบิก<b>ก่อน ' + r.cutoff + '</b>' : 'เบิก <b>' + list[k - 1].cutoff + '–' + minusMinute(r.cutoff) + '</b>') +
                    ' <i class="fa-solid fa-arrow-right" aria-hidden="true"></i> จ่าย <b>' + r.dispatch + '</b></li>';
            });
            lines.push('<li class="dr-pv-next">เบิกตั้งแต่ <b>' + list[list.length - 1].cutoff + '</b> หรือวันที่ไม่มีรอบ <i class="fa-solid fa-arrow-right" aria-hidden="true"></i> จ่ายรอบแรกของวันทำงานถัดไป (<b>' + list[0].dispatch + '</b>)</li>');
            pv.innerHTML = '<div class="dr-lbl">ผลที่ได้</div><ul>' + lines.join('') + '</ul>' +
                (days.length ? '<div class="dr-hint">มีรอบจ่ายวัน ' + days.map(function (d) { return DAY_TH[d]; }).join(' ') + '</div>' : '');
        } else {
            pv.innerHTML = '<div class="dr-hint">ใส่รอบอย่างน้อย 1 รอบ — กด "ใช้ตัวอย่าง 4 รอบ" เพื่อเริ่มเร็ว ๆ</div>';
        }
        var er = document.getElementById('drErr');
        er.hidden = !errs.length;
        er.innerHTML = errs.map(function (x) { return '<div><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i> ' + esc(x) + '</div>'; }).join('');
        document.getElementById('drSave').disabled = !!errs.length;
        return errs.length ? null : { rounds: list.map(function (r) { return { cutoff: r.cutoff, dispatch: r.dispatch }; }), days: days, enabled: enabled };
    }

    function fill(cfg) {
        document.getElementById('drMSite').textContent = 'ไซต์ ' + cfg.siteCode + (cfg.siteName ? ' · ' + cfg.siteName : '');
        document.getElementById('drEnabled').checked = cfg.saved ? !!cfg.enabled : true;   // ตั้งครั้งแรก = เปิดไว้ให้
        var days = cfg.workDays && cfg.workDays.length ? cfg.workDays : [1, 2, 3, 4, 5, 6];
        $all('#drDays .dr-day').forEach(function (b) {
            var on = days.indexOf(+b.getAttribute('data-day')) !== -1;
            b.classList.toggle('on', on);
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        setRows(cfg.rounds && cfg.rounds.length ? cfg.rounds : []);
        validate();
    }

    function openSettings(siteCode, onSaved) {
        buildModal();
        S.onSaved = onSaved || null;
        var b = document.getElementById('drBackdrop');
        document.getElementById('drRows').innerHTML = '<div class="dr-hint"><i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> กำลังโหลด...</div>';
        b.classList.add('open');
        document.documentElement.classList.add('dr-noscroll');
        google.script.run.withSuccessHandler(function (d) {
            if (!d || !d.success) { closeSettings(); toast((d && d.message) || 'โหลดรอบจ่ายไม่สำเร็จ', 'danger'); return; }
            if (!d.canEdit) { closeSettings(); toast('ตั้งรอบจ่ายได้เฉพาะ ADM หรือสายคลังของไซต์ ' + d.siteCode, 'danger'); return; }
            S.editSite = d.siteCode;
            fill(d);
        }).withFailureHandler(function (e) { closeSettings(); toast('โหลดรอบจ่ายไม่สำเร็จ', 'danger'); console.warn(e); })
          .getDispatchRounds(siteCode || '');
    }
    function closeSettings() {
        var b = document.getElementById('drBackdrop');
        if (b) b.classList.remove('open');
        document.documentElement.classList.remove('dr-noscroll');
    }

    function save() {
        var v = validate();
        if (!v) return;
        var btn = document.getElementById('drSave');
        btn.disabled = true;
        google.script.run.withSuccessHandler(function (res) {
            btn.disabled = false;
            if (!res || !res.success) {
                var er = document.getElementById('drErr');
                er.hidden = false;
                er.innerHTML = esc((res && res.message) || 'บันทึกไม่สำเร็จ').replace(/\n/g, '<br>');
                return;
            }
            closeSettings();
            toast(res.enabled ? 'บันทึกรอบจ่ายแล้ว ' + res.rounds.length + ' รอบ' : 'บันทึกแล้ว — ปิดใช้รอบจ่าย', 'success');
            try { if (window.connextCache && connextCache.invalidateMany) connextCache.invalidateMany(['drRounds', 'drBoard']); } catch (e) {}
            load(true);
            try { document.dispatchEvent(new CustomEvent('connext:dispatch-rounds-saved', { detail: res })); } catch (e) {}
            if (typeof S.onSaved === 'function') S.onSaved(res);
        }).withFailureHandler(function (e) {
            btn.disabled = false;
            toast('บันทึกไม่สำเร็จ: ' + ((e && e.message) || e), 'danger');
        }).saveDispatchRounds({ siteCode: S.editSite, enabled: v.enabled, workDays: v.days, rounds: v.rounds });
    }

    // ------------------------------------------------------------------ แจ้งรอบจ่ายตอนยืนยันเบิก (ผู้ใช้ขอ 2026-09-24)
    // ห่อ submitRequisition / submitOddsRequisition / submitBorrowDraft ให้รู้ว่า showConfirmPopup ที่เปิดระหว่างนั้น
    // คือหน้าต่างยืนยันส่งใบ → เติมกล่องรอบจ่าย · กดยืนยันแล้วจำรอบ ณ วินาทีที่กด (เวลาออกใบจริงของ server ห่างไม่กี่ร้อย ms)
    // → popup สำเร็จอันถัดไปเติม "จ่ายรอบ …" · ข้อความ popup เป็น white-space: pre-wrap จึงต่อ HTML แบบไม่มีขึ้นบรรทัด
    var SUBMITS = { submitRequisition: { kind: 'RD', form: 'req' }, submitOddsRequisition: { kind: 'OD', form: '' },
                    submitBorrowDraft: { kind: 'BD', form: 'borrow' } };
    var ctx = null;       // ระหว่างเรียกฟังก์ชันส่งใบ (synchronous)
    var pending = null;   // กดยืนยันแล้ว รอผลจาก server

    function cutoffDate(r) {
        var p = r.cutoff.split(':');
        return new Date(r.day.getFullYear(), r.day.getMonth(), r.day.getDate(), +p[0], +p[1]);
    }
    function roundText(r) { return r.dayOffset === 0 ? r.dispatch + ' (วันนี้)' : dayLabel(r.date) + ' ' + r.dispatch; }
    function needsApproval(meta) {
        if (!meta.form) return false;                                    // เบ็ดเตล็ด = ไม่ต้องอนุมัติ
        try { return typeof window._shouldShowPickerFor === 'function' ? !!window._shouldShowPickerFor(meta.form) : true; }
        catch (e) { return true; }
    }
    function confirmHtml(meta) {
        var n = now(), r = roundFor(S.cfg, n);
        if (!r) return null;
        var lines = [], soon = false;
        if (r.dayOffset === 0) {
            var cut = cutoffDate(r), left = cut.getTime() - n.getTime();
            var nx = roundFor(S.cfg, new Date(cut.getTime() + 1000));
            soon = left < 5 * 60000;
            lines.push('ปิดรับรอบนี้ ' + r.cutoff + ' · ' + untilText(left) +
                (soon && nx ? ' — ถ้าส่งหลัง ' + r.cutoff + ' จะไปรอบ ' + roundText(nx) : ''));
        } else {
            lines.push(S.cfg.workDays.indexOf(isoDay(n)) !== -1 ? 'รอบของวันนี้ปิดรับแล้ว' : 'วันนี้ไม่มีรอบจ่าย');
        }
        var appr = needsApproval(meta);
        if (appr) lines.push('ใบนี้ต้องรออนุมัติ — อนุมัติหลัง ' + r.dispatch + ' จะจ่ายรอบถัดไป');
        return { appr: appr, html: '<div class="dr-confirm' + (soon ? ' soon' : '') + '"><i class="fa-solid fa-truck-fast" aria-hidden="true"></i><div>' +
            '<div class="dr-confirm-t">รอบจ่าย: <b>' + esc(roundText(r)) + '</b></div>' +
            lines.map(function (x) { return '<div class="dr-confirm-s">' + esc(x) + '</div>'; }).join('') + '</div></div>' };
    }
    function successHtml(p) {
        var r = p.round;
        return '<div class="dr-confirm ok"><i class="fa-solid fa-truck-fast" aria-hidden="true"></i><div>' +
            '<div class="dr-confirm-t">จ่ายของรอบ <b>' + esc(roundText(r)) + '</b></div>' +
            '<div class="dr-confirm-s">มารับของที่ประตูตามเวลารอบ · ดูสถานะ/QR ได้ที่หน้า QR</div>' +
            (p.appr ? '<div class="dr-confirm-s">ต้องอนุมัติก่อน ' + esc(r.dispatch) + ' — ถ้าอนุมัติช้ากว่านั้นจะจ่ายรอบถัดไป</div>' : '') +
            '</div></div>';
    }

    function setupSubmitNotice() {
        Object.keys(SUBMITS).forEach(function (name) {
            var orig = window[name];
            if (typeof orig !== 'function' || orig.__drSubmit) return;
            var w = function () {
                ctx = SUBMITS[name];
                try { return orig.apply(this, arguments); } finally { ctx = null; }
            };
            w.__drSubmit = true;
            window[name] = w;
        });

        var origConfirm = window.showConfirmPopup;
        if (typeof origConfirm === 'function' && !origConfirm.__drWrapped) {
            var wc = function (title, message, onConfirm, confirmText, confirmClassName) {
                var meta = ctx;
                if (meta && S.cfg && S.cfg.enabled) {
                    try {
                        var info = confirmHtml(meta);
                        if (info) {
                            message = (message || '') + info.html;
                            var inner = onConfirm;
                            onConfirm = function () {
                                var r = roundFor(S.cfg, now());          // รอบ ณ วินาทีที่กดยืนยันจริง
                                pending = r ? { round: r, appr: info.appr, t: Date.now() } : null;
                                if (typeof inner === 'function') inner();
                            };
                        }
                    } catch (e) { console.warn('[dispatch-rounds] confirm notice:', e); }
                }
                return origConfirm.call(this, title, message, onConfirm, confirmText, confirmClassName);
            };
            wc.__drWrapped = true;
            window.showConfirmPopup = wc;
        }

        var origInfo = window.showInfoPopup;
        if (typeof origInfo === 'function' && !origInfo.__drWrapped) {
            var wi = function (title, message, type, onClose) {
                if (pending) {
                    var p = pending;
                    pending = null;                                      // popup ถัดไปหลังกดยืนยัน = ผลการส่ง (สำเร็จหรือพลาด)
                    if (type === 'success' && Date.now() - p.t < 3 * 60000) {
                        try { message = (message || '') + successHtml(p); } catch (e) {}
                    }
                }
                return origInfo.call(this, title, message, type, onClose);
            };
            wi.__drWrapped = true;
            window.showInfoPopup = wi;
        }
    }

    // ------------------------------------------------------------------ เริ่มทำงาน
    function init() {
        after('renderQRCards', decorateQr);
        setupSubmitNotice();
        // โหลดค่าตั้งเมื่อผู้ใช้เข้าสู่ระบบแล้ว และทุกครั้งที่เปิดหน้าเบิก/QR (แคช 5 นาที)
        var watch = ['requisition-page', 'qr-page'];
        var kick = function () { if (window.user) load(false); };
        if ('MutationObserver' in window) {
            var mo = new MutationObserver(function (list) {
                list.forEach(function (m) { if (m.target.classList.contains('active-page')) kick(); });
            });
            watch.forEach(function (id) { var e = document.getElementById(id); if (e) mo.observe(e, { attributes: true, attributeFilter: ['class'] }); });
        }
        if (window.user) kick();
        else {
            var tries = 0, iv = setInterval(function () { if (window.user || ++tries > 60) { clearInterval(iv); if (window.user) kick(); } }, 2000);
        }
        // นับถอยหลังบนแถบหน้าเบิก
        S.timer = setInterval(function () {
            var p = document.getElementById('requisition-page');
            if (S.cfg && p && p.classList.contains('active-page') && !document.hidden) renderStrip();
        }, 30000);
        window.DispatchRounds = {
            openSettings: openSettings,
            reload: function () { load(true); },
            roundFor: function (dt) { return roundFor(S.cfg, dt); },
            get config() { return S.cfg; },
            _setConfig: function (cfg) { S.cfg = cfg; renderStrip(); decorateQr(); },   // ไว้ตรวจหน้าจอด้วยค่าที่เตรียมเอง
            _openWith: function (cfg) {                                                // เปิดหน้าต่างตั้งค่าโดยไม่เรียก server
                buildModal(); S.editSite = cfg.siteCode;
                document.getElementById('drBackdrop').classList.add('open'); fill(cfg);
            }
        };
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
