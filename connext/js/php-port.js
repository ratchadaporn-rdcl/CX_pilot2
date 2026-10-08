





(function () {
  'use strict';

   
  window.addEventListener('connext:session-expired', function () {
    try { window.__SERVER_USER = null; } catch (e) {}
    try { if (typeof clearUserData === 'function') clearUserData(); } catch (e) {}
    try { if (typeof user !== 'undefined') user = null; } catch (e) {}
    try { if (typeof showInlineLogin === 'function') showInlineLogin(); } catch (e) {}
  });

   
   
  window._localQrImgTag = function (data) {
    try {
      var qr = window.qrcode(0, 'M');  
      qr.addData(String(data));
      qr.make();
      var src = qr.createDataURL(4, 8);
      return '<img src="' + src + '" width="220" height="220" alt="QR Code" style="image-rendering:pixelated;background:#fff;">';
    } catch (e) {
      console.error('local QR failed:', e);
      return '<div style="width:220px;height:220px;display:flex;align-items:center;justify-content:center;color:#ef4444;font-size:.85rem;">สร้าง QR ไม่สำเร็จ</div>';
    }
  };

   
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register((window.APP_BASE || '') + '/sw.js', { updateViaCache: 'none' }).catch(function () {});
    });
    var hadController = !!navigator.serviceWorker.controller;
    var reloaded = false;
    navigator.serviceWorker.addEventListener('controllerchange', function () {
      if (!hadController || reloaded) return;
      reloaded = true;
      location.reload();
    });
  }
})();
