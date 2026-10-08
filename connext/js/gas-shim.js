








(function () {
  'use strict';

  var APP_BASE = (typeof window.APP_BASE === 'string') ? window.APP_BASE : '';

  function getCsrf() {
    var m = document.querySelector('meta[name="csrf-token"]');
    return m ? m.getAttribute('content') : '';
  }

  // [2026-10-02] token ใน meta ใช้ไม่ได้เมื่อ session ฝั่ง server เปลี่ยน (ออกจากระบบแล้วล็อกอินใหม่โดยไม่รีโหลดหน้า ·
  // session หมดอายุ) — server ตอบ 403 code 'csrf' พร้อม token ปัจจุบัน → เก็บลง meta แล้วส่งคำขอเดิมซ้ำ 1 ครั้ง
  // (คำขอแรกถูกปฏิเสธก่อนทำงานใด ๆ ใน api/rpc.php จึงส่งซ้ำได้ไม่ซ้ำซ้อน)
  function setCsrf(token) {
    var m = document.querySelector('meta[name="csrf-token"]');
    if (m && token) m.setAttribute('content', token);
  }

  function call(fn, args, state, retried) {
    fetch(APP_BASE + '/api/rpc.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': getCsrf()
      },
      credentials: 'same-origin',
      body: JSON.stringify({ fn: fn, args: args })
    })
      .then(function (res) { return res.json().then(function (d) { return { http: res.status, data: d }; }); })
      .then(function (r) {
        var d = r.data || {};
        if (d.ok) {
          if (state.success) state.success(d.result, state.userObject);
          return;
        }
        if (d.code === 'csrf' && d.csrf && !retried) {
          setCsrf(d.csrf);
          call(fn, args, state, true);
          return;
        }
        if (d.code === 'auth' || r.http === 401) {
          try { window.dispatchEvent(new CustomEvent('connext:session-expired')); } catch (e) {}
        }
        var err = new Error(d.error || 'Server error');
        if (state.failure) state.failure(err, state.userObject);
        else console.error('[gas-shim] ' + fn + ':', err);
      })
      .catch(function (err) {
        if (state.failure) state.failure(err, state.userObject);
        else console.error('[gas-shim] ' + fn + ':', err);
      });
  }

  function makeRunner(state) {
    state = state || { success: null, failure: null, userObject: undefined };

    var base = {
      withSuccessHandler: function (fn) {
        return makeRunner({ success: fn, failure: state.failure, userObject: state.userObject });
      },
      withFailureHandler: function (fn) {
        return makeRunner({ success: state.success, failure: fn, userObject: state.userObject });
      },
      withUserObject: function (obj) {
        return makeRunner({ success: state.success, failure: state.failure, userObject: obj });
      }
    };

    return new Proxy(base, {
      get: function (target, prop) {
        if (prop in target) return target[prop];
        if (typeof prop !== 'string') return undefined;
         
        return function () {
          call(prop, Array.prototype.slice.call(arguments), state, false);
        };
      }
    });
  }

  window.google = window.google || {};
  window.google.script = window.google.script || {};
  window.google.script.run = makeRunner(null);

   
  window.google.script.host = window.google.script.host || {
    close: function () {}, setHeight: function () {}, setWidth: function () {},
    origin: location.origin
  };
  window.google.script.url = window.google.script.url || {
    getLocation: function (cb) {
      if (typeof cb === 'function') {
        cb({ hash: location.hash.replace(/^#/, ''), parameter: paramMap(), parameters: {} });
      }
    }
  };
  window.google.script.history = window.google.script.history || {
    push: function () {}, replace: function () {}, setChangeHandler: function () {}
  };

  function paramMap() {
    var out = {};
    try {
      var q = new URLSearchParams(location.search);
      q.forEach(function (v, k) { out[k] = v; });
    } catch (e) {}
    return out;
  }
})();
