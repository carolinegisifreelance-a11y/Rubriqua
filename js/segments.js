// Rubriqua, pages par type de client : sélecteur de situations.
// Toutes les réponses sont dans le HTML. Sur ordinateur, une seule réponse est affichée à droite.
// Sur mobile, chaque réponse s'ouvre et se ferme sous son bouton, indépendamment des autres.
(function () {
  'use strict';
  var mobile = window.matchMedia('(max-width: 760px)');
  document.querySelectorAll('[data-sg]').forEach(function (root) {
    var tabs = Array.prototype.slice.call(root.querySelectorAll('[data-sg-tab]'));
    var panelOf = function (t) { return document.getElementById(t.getAttribute('aria-controls')); };

    var setOpen = function (t, on) {
      var panel = panelOf(t);
      t.setAttribute('aria-pressed', on ? 'true' : 'false');
      t.setAttribute('aria-expanded', on ? 'true' : 'false');
      if (!panel) return;
      panel.classList.toggle('is-open', on);
      if (!mobile.matches) panel.hidden = !on;
    };

    // Ordinateur : une réponse à la fois
    var showOnly = function (i, focus) {
      tabs.forEach(function (t, k) { setOpen(t, k === i); });
      if (focus) tabs[i].focus();
    };

    // Passage d'un mode à l'autre : sur mobile toutes les réponses restent dans le flux pour l'animation
    var sync = function () {
      if (mobile.matches) {
        tabs.forEach(function (t) {
          var panel = panelOf(t);
          if (panel) panel.hidden = false;
          setOpen(t, t.getAttribute('aria-pressed') === 'true');
        });
      } else {
        var cur = tabs.findIndex(function (t) { return t.getAttribute('aria-pressed') === 'true'; });
        showOnly(cur < 0 ? 0 : cur);
      }
    };

    tabs.forEach(function (t, k) {
      t.addEventListener('click', function () {
        if (mobile.matches) setOpen(t, t.getAttribute('aria-pressed') !== 'true');
        else showOnly(k);
      });
    });
    root.querySelectorAll('[data-sg-next]').forEach(function (b) {
      b.addEventListener('click', function () {
        var cur = tabs.findIndex(function (t) { return t.getAttribute('aria-pressed') === 'true'; });
        showOnly((cur + 1) % tabs.length, true);
      });
    });
    if (mobile.addEventListener) mobile.addEventListener('change', sync); else mobile.addListener(sync);
    sync();
  });
})();
