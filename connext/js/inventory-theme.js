/* Runs in <head> before paint; shared by the app and PHP module frames. */
(function () {
  'use strict';
  var key = 'connext.colorTheme';
  var root = document.documentElement;
  var system = window.matchMedia('(prefers-color-scheme: dark)');
  var explicit = null;
  var chartsReady = false;
  function chartColors(chart) {
    var dark = root.dataset.theme === 'dark';
    var ink = dark ? '#b5c4d2' : '#526579';
    // Darker gold on white surfaces keeps thin chart lines distinguishable.
    var palette = [dark ? '#ffda4d' : '#866600', dark ? '#819bb3' : '#102f50', dark ? '#ff8a91' : '#b82029'];
    var scales = chart.options.scales || {};
    Object.keys(scales).forEach(function (name) {
      var scale = scales[name];
      if (scale.ticks) scale.ticks.color = ink;
      if (scale.title) scale.title.color = ink;
      if (scale.grid) scale.grid.color = dark ? '#35516a' : '#d6dfe7';
    });
    var legend = chart.options.plugins && chart.options.plugins.legend;
    if (legend && legend.labels) legend.labels.color = ink;
    chart.data.datasets.forEach(function (dataset, index) {
      var color = palette[index % palette.length];
      dataset.backgroundColor = Array.isArray(dataset.backgroundColor) ? dataset.backgroundColor.map(function (_, i) { return palette[i % palette.length]; }) : color;
      dataset.borderColor = Array.isArray(dataset.backgroundColor) ? dataset.backgroundColor.slice() : color;
    });
  }
  function valid(value) { return value === 'dark' || value === 'light'; }
  try { explicit = localStorage.getItem(key); } catch (_) {}
  function apply(theme) {
    root.dataset.theme = theme;
    root.style.colorScheme = theme;
    var meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.content = theme === 'dark' ? '#091a2c' : '#f4f6f8';
    document.querySelectorAll('.cnx-theme-toggle').forEach(function (button) {
      button.setAttribute('aria-checked', String(theme === 'dark'));
      button.title = theme === 'dark' ? 'เปลี่ยนเป็นโหมดสว่าง' : 'เปลี่ยนเป็นโหมดมืด';
      button.querySelector('.cnx-theme-label').textContent = theme === 'dark' ? 'Dark' : 'Light';
    });
    if (chartsReady && window.Chart && window.Chart.instances) {
      Object.keys(window.Chart.instances).forEach(function (id) { window.Chart.instances[id].update('none'); });
    }
  }
  apply(valid(explicit) ? explicit : (system.matches ? 'dark' : 'light'));
  window.addEventListener('storage', function (event) {
    if (event.key !== key && event.key !== null) return;
    explicit = valid(event.newValue) ? event.newValue : null;
    apply(explicit || (system.matches ? 'dark' : 'light'));
  });
  function systemChanged(event) { if (!valid(explicit)) apply(event.matches ? 'dark' : 'light'); }
  if (system.addEventListener) system.addEventListener('change', systemChanged);
  else if (system.addListener) system.addListener(systemChanged);
  function mount(host, placement) {
    if (!host) return;
    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'cnx-theme-toggle ' + placement;
    button.setAttribute('role', 'switch');
    button.setAttribute('aria-label', 'โหมดมืด / Dark mode');
    button.innerHTML = '<svg class="cnx-theme-sun" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.4 1.4m11.2 11.2L19 19M5 19l1.4-1.4M17.6 6.4L19 5"/></svg><svg class="cnx-theme-moon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M20 15.5A8.5 8.5 0 0 1 8.5 4 8.5 8.5 0 1 0 20 15.5Z"/></svg><span class="cnx-theme-label"></span>';
    button.addEventListener('click', function () {
      explicit = root.dataset.theme === 'dark' ? 'light' : 'dark';
      try { localStorage.setItem(key, explicit); } catch (_) {}
      apply(explicit);
      // Also sync same-origin frames when storage is unavailable.
      document.querySelectorAll('iframe').forEach(function (frame) {
        try { frame.contentWindow.postMessage({type:'connext-theme', theme:explicit}, location.origin); } catch (_) {}
      });
    });
    host.prepend(button);
  }
  window.addEventListener('message', function (event) {
    if (event.origin !== location.origin || event.source !== window.parent || window.parent === window) return;
    if (event.data && event.data.type === 'connext-theme' && valid(event.data.theme)) { explicit = event.data.theme; apply(explicit); }
  });
  function init() {
    if (window.Chart && typeof window.Chart.register === 'function') {
      window.Chart.register({id:'connextTheme', beforeUpdate:chartColors});
      chartsReady = true;
    }
    mount(document.querySelector('.navbar .user-profile'), 'cnx-theme-app');
    mount(document.querySelector('.cnx-login-layout .il-card'), 'cnx-theme-login');
    mount(document.querySelector('.appbar .topbar'), 'cnx-theme-module');
    apply(root.dataset.theme);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
