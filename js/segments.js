// Rubriqua, pages par type de client : sélecteur de situations.
// Toutes les réponses sont dans le HTML, le script n'en affiche qu'une à la fois.
(function () {
  'use strict';
  document.querySelectorAll('[data-sg]').forEach(function (root) {
    var tabs = root.querySelectorAll('[data-sg-tab]');
    var show = function (i, focus) {
      tabs.forEach(function (t, k) {
        var on = k === i;
        t.setAttribute('aria-pressed', on ? 'true' : 'false');
        var panel = document.getElementById(t.getAttribute('aria-controls'));
        if (panel) panel.hidden = !on;
      });
      if (focus) tabs[i].focus();
    };
    tabs.forEach(function (t, k) {
      t.addEventListener('click', function () {
        show(k);
        if (window.matchMedia('(max-width: 760px)').matches) {
          t.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      });
    });
    root.querySelectorAll('[data-sg-next]').forEach(function (b) {
      b.addEventListener('click', function () {
        var cur = 0;
        tabs.forEach(function (t, k) { if (t.getAttribute('aria-pressed') === 'true') cur = k; });
        show((cur + 1) % tabs.length, true);
      });
    });
  });
})();
