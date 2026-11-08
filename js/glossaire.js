// Rubriqua, glossaire : recherche, filtre par thématique et simulateur de ROAS.
// La recherche et les filtres ne modifient jamais l'adresse de la page.
(function () {
  'use strict';
  var norm = function (s) { return (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); };

  var list = document.querySelector('[data-gl-list]');
  if (list) {
    var input = document.querySelector('[data-gl-search]');
    var buttons = document.querySelectorAll('[data-gl-theme]');
    var rows = list.querySelectorAll('[data-gl-row]');
    var groups = list.querySelectorAll('[data-gl-letter]');
    var letters = document.querySelectorAll('[data-gl-key]');
    var count = document.querySelector('[data-gl-count]');
    var empty = document.querySelector('[data-gl-empty]');
    var theme = '';

    var apply = function () {
      var q = norm(input ? input.value.trim() : '');
      var shown = 0, active = {};
      rows.forEach(function (r) {
        var ok = (!theme || r.getAttribute('data-theme') === theme) && (!q || r.getAttribute('data-text').indexOf(q) !== -1);
        r.hidden = !ok;
        if (ok) { shown++; active[r.getAttribute('data-key')] = true; }
      });
      groups.forEach(function (g) { g.hidden = !active[g.getAttribute('data-gl-letter')]; });
      letters.forEach(function (l) {
        var on = !!active[l.getAttribute('data-gl-key')];
        var link = l.querySelector('a'), off = l.querySelector('span');
        if (link) link.hidden = !on;
        if (off) off.hidden = on;
      });
      if (count) count.textContent = shown + (shown > 1 ? ' termes' : ' terme') + (theme ? ' en ' + theme : '');
      if (empty) empty.hidden = shown > 0;
    };
    if (input) input.addEventListener('input', apply);
    buttons.forEach(function (b) {
      b.addEventListener('click', function () {
        theme = b.getAttribute('data-gl-theme');
        buttons.forEach(function (o) { o.setAttribute('aria-pressed', o === b ? 'true' : 'false'); });
        apply();
      });
    });
    apply();
  }

  // Simulateur de ROAS
  var form = document.querySelector('[data-roas]');
  if (form) {
    var out = function (k) { return document.querySelector('[data-roas-out="' + k + '"]'); };
    var num = function (v) { var n = parseFloat(String(v).replace(/\s/g, '').replace(',', '.')); return isFinite(n) ? n : NaN; };
    var fr = function (n, d) { return n.toLocaleString('fr-FR', { minimumFractionDigits: 0, maximumFractionDigits: d }); };
    var calc = function () {
      var dep = num(form.depenses.value), ca = num(form.ca.value), marge = num(form.marge.value) / 100;
      var verdict = out('verdict');
      if (!(dep > 0) || !(ca >= 0)) {
        ['roas', 'pct', 'gain'].forEach(function (k) { out(k).textContent = '-'; });
        out('seuil').textContent = marge > 0 && marge <= 1 ? fr(1 / marge, 2) : '-';
        verdict.className = 'roas-verdict neutre';
        verdict.textContent = 'Indiquez des dépenses supérieures à zéro pour calculer le ROAS.';
        return;
      }
      var roas = ca / dep;
      out('roas').textContent = fr(roas, 2);
      out('pct').textContent = fr(roas * 100, 0) + '%';
      if (!(marge > 0) || marge > 1) {
        out('seuil').textContent = '-'; out('gain').textContent = '-';
        verdict.className = 'roas-verdict neutre';
        verdict.textContent = 'Indiquez un taux de marge entre 1 et 100% pour connaître votre seuil.';
        return;
      }
      var seuil = 1 / marge, gain = ca * marge - dep, ecart = roas - seuil;
      out('seuil').textContent = fr(seuil, 2);
      out('gain').textContent = fr(Math.round(gain), 0) + ' €';
      if (ecart >= 0) {
        verdict.className = 'roas-verdict ok';
        verdict.textContent = 'Campagne rentable : votre ROAS dépasse votre seuil de ' + fr(ecart, 2) + ' point' + (ecart >= 2 ? 's' : '') + '.';
      } else {
        verdict.className = 'roas-verdict ko';
        verdict.textContent = 'Campagne non rentable : il manque ' + fr(-ecart, 2) + ' point' + (-ecart >= 2 ? 's' : '') + ' de ROAS pour couvrir le coût des produits.';
      }
    };
    form.addEventListener('input', calc);
    calc();
  }
})();
