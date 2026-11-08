// Rubriqua, interactions du blog et des articles
(function () {
  'use strict';

  // Recherche dans la liste des articles
  var input = document.querySelector('[data-search]');
  var list = document.querySelector('[data-posts]');
  if (input && list) {
    var cards = list.querySelectorAll('.post-card');
    var feature = document.querySelector('.feature-post');
    var empty = document.querySelector('[data-empty]');
    var norm = function (s) { return s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); };
    input.addEventListener('input', function () {
      var q = norm(input.value.trim());
      var shown = 0;
      cards.forEach(function (c) {
        var ok = !q || norm(c.getAttribute('data-title')).indexOf(q) !== -1;
        c.hidden = !ok;
        if (ok) shown++;
      });
      if (feature) {
        var fok = !q || norm(feature.getAttribute('data-title')).indexOf(q) !== -1;
        feature.hidden = !fok;
        if (fok) shown++;
      }
      if (empty) empty.hidden = shown > 0;
    });
  }

  // Barre de progression de lecture
  var bar = document.querySelector('[data-progress]');
  var article = document.querySelector('.prose');
  if (bar && article) {
    var update = function () {
      var r = article.getBoundingClientRect();
      var total = r.height - window.innerHeight;
      var done = Math.min(Math.max(-r.top, 0), Math.max(total, 1));
      bar.style.width = (total > 0 ? done / total * 100 : 100) + '%';
    };
    window.addEventListener('scroll', update, { passive: true });
    update();
  }

  // Sommaire : met en avant la section en cours de lecture
  var links = document.querySelectorAll('[data-toc] a');
  if (links.length && 'IntersectionObserver' in window) {
    var map = {};
    links.forEach(function (a) { map[a.getAttribute('href').slice(1)] = a; });
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (e.isIntersecting) {
          links.forEach(function (a) { a.classList.remove('is-active'); });
          var a = map[e.target.id];
          if (a) a.classList.add('is-active');
        }
      });
    }, { rootMargin: '-20% 0px -70% 0px' });
    Object.keys(map).forEach(function (id) {
      var h = document.getElementById(id);
      if (h) io.observe(h);
    });
  }
})();
