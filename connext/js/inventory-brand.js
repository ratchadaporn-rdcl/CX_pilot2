/* Progressive presentation enhancements; original application owns all stock data. */
(function () {
  'use strict';
  function init() {
    var hero = document.querySelector('.cnx-hero');
    if (!hero) return;
    var toggle = hero.querySelector('.cnx-display-toggle');
    function compact(value) {
      hero.classList.toggle('cnx-compact', value);
      toggle.setAttribute('aria-pressed', String(value));
      toggle.textContent = value ? 'แสดงภาพ' : 'ย่อภาพ';
    }
    try { compact(localStorage.getItem('connext.compactInventory') === '1'); } catch (_) { compact(false); }
    toggle.addEventListener('click', function () {
      var value = !hero.classList.contains('cnx-compact');
      compact(value);
      try { localStorage.setItem('connext.compactInventory', value ? '1' : '0'); } catch (_) {}
    });
    hero.querySelector('[data-inventory-search]').addEventListener('click', function () {
      var input = document.getElementById('dashSearchInput');
      if (input) { input.scrollIntoView({block:'center', behavior:'auto'}); input.focus({preventScroll:true}); }
    });
    hero.querySelector('[data-inventory-low]').addEventListener('click', function () {
      if (typeof window.filterDashboardBy === 'function') window.filterDashboardBy('lowstock');
      var panel = document.getElementById('dashTablePanel');
      if (panel) panel.scrollIntoView({block:'start', behavior:'auto'});
    });
    document.querySelectorAll('#dashboard-page .metric-card[onclick]').forEach(function (card) {
      // Cards with nested buttons retain their controls; expose a separate keyboard target.
      var target = card.querySelector('.metric-info h3');
      if (!target) return;
      target.tabIndex = 0;
      target.setAttribute('role', 'button');
      target.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); card.click(); }
      });
    });
    if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      document.querySelectorAll('#dashboard-page .metric-value').forEach(function (value) {
        new MutationObserver(function () { value.classList.remove('cnx-value-updated'); void value.offsetWidth; value.classList.add('cnx-value-updated'); }).observe(value, {childList:true,characterData:true,subtree:true});
      });
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
