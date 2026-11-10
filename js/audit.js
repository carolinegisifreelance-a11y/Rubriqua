// Rubriqua, Audit GEO gratuit : envoie l'adresse à /api/audit.php et affiche le résultat.
// L'adresse peut aussi arriver depuis un autre formulaire du site (?url=...), le test se lance alors tout seul.
(function () {
  'use strict';
  var form = document.querySelector('[data-audit-form]');
  if (!form) return;
  var input = form.querySelector('input[name="url"]');
  var errorBox = document.querySelector('[data-audit-error]');
  var loading = document.querySelector('[data-audit-loading]');
  var stepsBox = document.querySelector('[data-audit-steps]');
  var result = document.querySelector('[data-audit-result]');
  var $ = function (sel) { return result.querySelector(sel); };
  var esc = function (s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var ICONS = {
    ok: '<svg viewBox="0 0 24 24" stroke="#2f6b3a" role="img" aria-label="Validé"><circle cx="12" cy="12" r="10"/><path d="M8 12.5l2.5 2.5L16 9.5"/></svg>',
    warn: '<svg viewBox="0 0 24 24" stroke="#b45309" role="img" aria-label="À améliorer"><circle cx="12" cy="12" r="10"/><path d="M12 7v6M12 16.5v.5"/></svg>',
    ko: '<svg viewBox="0 0 24 24" stroke="#991915" role="img" aria-label="Bloquant"><circle cx="12" cy="12" r="10"/><path d="M9 9l6 6M15 9l-6 6"/></svg>'
  };
  var LABELS = { ok: 'Validé', warn: 'À améliorer', ko: 'Bloquant' };
  var STEPS = ['Lecture de la page sans JavaScript', 'Vérification du robots.txt pour les robots de ChatGPT, Perplexity, Claude, Copilot et Google', 'Recherche des données structurées', 'Analyse des signaux de confiance et du contenu'];
  var busy = false, timers = [], current = '', opened = 0;

  var hostOf = function (u) { return u.replace(/^https?:\/\//i, '').replace(/\/$/, ''); };
  var showError = function (msg) { errorBox.textContent = msg; errorBox.hidden = false; };

  var countUp = function (el, target) {
    var start = null;
    var step = function (t) {
      if (!start) start = t;
      var k = Math.min(1, (t - start) / 1400);
      el.textContent = Math.round(target * (1 - Math.pow(1 - k, 3)));
      if (k < 1) requestAnimationFrame(step);
    };
    requestAnimationFrame(step);
  };

  var render = function (d) {
    result.querySelectorAll('[data-audit-host]').forEach(function (el) { el.textContent = d.host; });
    $('[data-audit-verdict]').textContent = d.verdict;
    // Priorités : d'abord les points bloquants, puis ceux à améliorer
    var todo = [];
    d.groups.forEach(function (g) { g.items.forEach(function (i) { if (i.status !== 'ok') todo.push(i); }); });
    todo.sort(function (a, b) { return (a.status === 'ko' ? 0 : 1) - (b.status === 'ko' ? 0 : 1); });
    todo = todo.slice(0, 5);
    var prio = $('[data-audit-prio]');
    prio.innerHTML = todo.map(function (i, n) {
      return '<li><span class="au-prio-n">' + (n + 1) + '</span><b>' + esc(i.name) + '</b><span class="au-st au-st-' + i.status + '">' + LABELS[i.status] + '</span></li>';
    }).join('');
    prio.hidden = !todo.length;
    var concl = $('[data-audit-conclusion]');
    concl.textContent = d.conclusion;
    concl.hidden = todo.length > 0;
    $('[data-audit-counts]').innerHTML =
      '<span class="au-st au-st-ok">' + d.counts.ok + ' validés sur ' + d.total + '</span>' +
      '<span class="au-st au-st-warn">' + d.counts.warn + ' à améliorer</span>' +
      '<span class="au-st au-st-ko">' + d.counts.ko + (d.counts.ko > 1 ? ' bloquants' : ' bloquant') + '</span>';
    var k = 0;
    $('[data-audit-groups]').innerHTML = d.groups.map(function (g, gi) {
      var ok = g.items.filter(function (i) { return i.status === 'ok'; }).length;
      return '<div class="au-group" style="animation-delay:' + (0.15 * gi).toFixed(2) + 's"><div class="au-group-head"><h3>' + esc(g.name) + '</h3><span>' + ok + '/' + g.items.length + '</span></div>' +
        g.items.map(function (i) {
          return '<div class="au-row" style="animation-delay:' + (0.25 + 0.05 * (k++)).toFixed(2) + 's">' + ICONS[i.status] +
            '<div><b>' + esc(i.name) + '</b><p>' + esc(i.note) + '</p></div><span class="au-st au-st-' + i.status + '">' + LABELS[i.status] + '</span></div>';
        }).join('') + '</div>';
    }).join('');
    current = d.url; opened = Date.now();
    result.querySelectorAll('[data-demande] .form-status').forEach(function (el) { el.hidden = true; });
    var ring = $('[data-audit-score]');
    ring.style.setProperty('--au-c', d.score >= 80 ? '#5fae73' : (d.score >= 50 ? '#e0973a' : '#EF8D86'));
    ring.style.setProperty('--au-p', 0);
    result.hidden = false;
    requestAnimationFrame(function () { requestAnimationFrame(function () { ring.style.setProperty('--au-p', d.score); }); });
    countUp($('[data-audit-score-num]'), d.score);
    result.focus({ preventScroll: true });
    result.scrollIntoView({ behavior: 'smooth', block: 'start' });
  };

  var run = function (url) {
    if (busy) return;
    url = url.trim();
    errorBox.hidden = true;
    if (!url) { showError('Indiquez l’adresse de la page à analyser.'); input.focus(); return; }
    busy = true;
    form.querySelector('button').disabled = true;
    result.hidden = true;
    loading.querySelectorAll('[data-audit-host]').forEach(function (el) { el.textContent = hostOf(url); });
    stepsBox.innerHTML = '';
    loading.hidden = false;
    timers.forEach(clearTimeout); timers = [];
    STEPS.forEach(function (s, i) { timers.push(setTimeout(function () { var li = document.createElement('li'); li.textContent = s; stepsBox.appendChild(li); }, 400 * (i + 1))); });
    var minWait = new Promise(function (r) { setTimeout(r, 1900); });
    var body = new URLSearchParams(); body.set('url', url);
    var req = fetch('/api/audit.php', { method: 'POST', body: body, headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .catch(function () { return { ok: false, message: 'Connexion impossible. Vérifiez votre connexion et réessayez.' }; });
    Promise.all([req, minWait]).then(function (v) {
      var d = v[0];
      loading.hidden = true;
      busy = false;
      form.querySelector('button').disabled = false;
      if (!d.ok) { showError(d.message || 'L’analyse n’a pas pu aboutir.'); input.focus(); return; }
      render(d);
      if (wantComplet) { wantComplet = false; openComplet(true); }
    });
  };

  form.addEventListener('submit', function (e) { e.preventDefault(); run(input.value); });
  var again = document.querySelector('[data-audit-again]');
  if (again) again.addEventListener('click', function () {
    result.hidden = true; input.value = ''; window.scrollTo({ top: 0, behavior: 'smooth' }); input.focus({ preventScroll: true });
  });

  // Demande d'audit complet : le formulaire s'ouvre sous les priorités
  var complet = result.querySelector('#au-complet'), opener = result.querySelector('[data-complet-open]');
  var openComplet = function (scroll) {
    complet.hidden = false; opener.setAttribute('aria-expanded', 'true');
    if (scroll) complet.scrollIntoView({ behavior: 'smooth', block: 'center' });
    complet.querySelector('input[name="nom"]').focus({ preventScroll: true });
  };
  opener.addEventListener('click', function () { complet.hidden ? openComplet(false) : (complet.hidden = true, opener.setAttribute('aria-expanded', 'false')); });

  // Relevé de citations : deux champs apparaissent quand la case est cochée
  var tog = result.querySelector('[data-releve-toggle]'), fields = result.querySelector('[data-releve-fields]');
  tog.addEventListener('change', function () { fields.hidden = !tog.checked; fields.querySelector('input[name="produits"]').required = tog.checked; if (tog.checked) fields.querySelector('input').focus(); });

  // Envoi du rapport par e-mail ou de la demande d'audit complet
  result.querySelectorAll('form[data-demande]').forEach(function (f) {
    var status = f.querySelector('.form-status'), btn = f.querySelector('button[type="submit"]');
    var say = function (msg, err) { status.textContent = msg; status.hidden = false; status.classList.toggle('is-error', !!err); };
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      var email = f.querySelector('input[name="email"]');
      if (!email.value.trim() || !email.checkValidity()) { say('Merci d’indiquer une adresse e-mail valide.', true); email.focus(); return; }
      var nom = f.querySelector('input[name="nom"]');
      if (nom && !nom.value.trim()) { say('Merci d’indiquer votre nom.', true); nom.focus(); return; }
      var prod = f.querySelector('input[name="produits"]');
      if (prod && prod.required && !prod.value.trim()) { say('Indiquez vos produits, services ou thèmes clés pour que nous posions les bonnes questions.', true); prod.focus(); return; }
      var body = new URLSearchParams(new FormData(f));
      body.set('type', f.getAttribute('data-demande')); body.set('url', current); body.set('t', String(opened));
      btn.disabled = true; say('Envoi en cours…');
      fetch('/api/demande.php', { method: 'POST', body: body, headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .catch(function () { return { ok: false, message: 'Connexion impossible. Vérifiez votre connexion et réessayez.' }; })
        .then(function (d) {
          btn.disabled = false;
          say(d.message || (d.ok ? 'C’est envoyé.' : 'L’envoi a échoué.'), !d.ok);
          if (d.ok) { f.querySelectorAll('input[type="text"], input[type="email"], textarea').forEach(function (i) { if (i.name !== 'societe_web') i.value = ''; }); }
        });
    });
  });

  // Adresse transmise depuis un encart du site ou depuis le mail du rapport (&complet=1)
  var params = new URLSearchParams(location.search), wantComplet = params.get('complet') === '1';
  var given = params.get('url');
  if (given) { input.value = given; run(given); }
})();

// Encarts Audit GEO sur les autres pages : ouvrent l'outil avec l'adresse saisie
(function () {
  'use strict';
  document.querySelectorAll('form[data-audit-link]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      var v = (f.querySelector('input').value || '').trim();
      location.href = '/audit-geo-gratuit/' + (v ? '?url=' + encodeURIComponent(v) : '');
    });
  });
})();
