// Rubriqua, recherche et filtre du glossaire
(function () {
  'use strict';
  var input = document.querySelector('[data-gl-search]');
  var chips = document.querySelectorAll('[data-gl-cat]');
  var terms = document.querySelectorAll('.gl-term');
  var letters = document.querySelectorAll('.gl-letter');
  var empty = document.querySelector('[data-gl-empty]');
  var cat = '';
  var norm = function (s) { return s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); };
  function apply() {
    var q = input ? norm(input.value.trim()) : '';
    var shown = 0;
    terms.forEach(function (t) {
      var ok = (!cat || t.getAttribute('data-cat') === cat) && (!q || t.getAttribute('data-text').indexOf(q) !== -1);
      t.hidden = !ok;
      if (ok) shown++;
    });
    letters.forEach(function (l) { l.hidden = !l.querySelector('.gl-term:not([hidden])'); });
    if (empty) empty.hidden = shown > 0;
  }
  if (input) input.addEventListener('input', apply);
  chips.forEach(function (c) {
    c.addEventListener('click', function () {
      cat = c.getAttribute('data-gl-cat');
      chips.forEach(function (o) { o.classList.toggle('is-on', o === c); });
      apply();
    });
  });
})();
