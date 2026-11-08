// Rubriqua, interactions de la page d'accueil
(function () {
  'use strict';

  // Menu mobile
  var toggle = document.querySelector('.menu-toggle');
  var menu = document.getElementById('menu');
  if (toggle && menu) {
    toggle.addEventListener('click', function () {
      var open = menu.classList.toggle('is-open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  // Apparition au défilement, et animation de la frise des étapes
  var watched = document.querySelectorAll('.reveal, [data-steps]');
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-in');
          io.unobserve(entry.target);
        }
      });
    }, { threshold: 0.18 });
    watched.forEach(function (el) { io.observe(el); });
  } else {
    watched.forEach(function (el) { el.classList.add('is-in'); });
  }

  // Jauge de score : se remplit quand elle arrive à l'écran
  var rings = document.querySelectorAll('[data-score]');
  rings.forEach(function (ring) {
    var fill = function () {
      var r = ring.r.baseVal.value;
      var len = 2 * Math.PI * r;
      ring.style.strokeDasharray = len;
      ring.style.strokeDashoffset = len * (1 - Number(ring.getAttribute('data-score')) / 100);
    };
    if ('IntersectionObserver' in window) {
      var ro = new IntersectionObserver(function (entries) {
        if (entries[0].isIntersecting) { fill(); ro.disconnect(); }
      }, { threshold: 0.4 });
      ro.observe(ring);
    } else { fill(); }
  });

  // Page de résultats : lien entre arguments et zones
  var serp = document.querySelector('.serp');
  if (serp) {
    var zones = serp.querySelectorAll('[data-zone]');
    var args = document.querySelectorAll('.arg[data-target]');

    var light = function (target) {
      if (target === 'ads' && serp.getAttribute('data-ads') === 'off') { setAds('on'); }
      serp.classList.add('is-focus');
      zones.forEach(function (z) { z.classList.toggle('is-lit', z.getAttribute('data-zone') === target); });
      args.forEach(function (a) { a.classList.toggle('is-lit', a.getAttribute('data-target') === target); });
    };
    var clear = function () {
      serp.classList.remove('is-focus');
      zones.forEach(function (z) { z.classList.remove('is-lit'); });
      args.forEach(function (a) { a.classList.remove('is-lit'); });
    };

    args.forEach(function (a) {
      var t = a.getAttribute('data-target');
      a.addEventListener('mouseenter', function () { light(t); });
      a.addEventListener('focus', function () { light(t); });
      a.addEventListener('mouseleave', clear);
      a.addEventListener('blur', clear);
    });
    zones.forEach(function (z) {
      var t = z.getAttribute('data-zone');
      z.addEventListener('mouseenter', function () { light(t); });
      z.addEventListener('mouseleave', clear);
    });

    // Avec ou sans annonces
    var btns = serp.querySelectorAll('[data-ads-btn]');
    var setAds = function (val) {
      serp.setAttribute('data-ads', val);
      btns.forEach(function (b) { b.classList.toggle('is-on', b.getAttribute('data-ads-btn') === val); });
    };
    btns.forEach(function (b) {
      b.addEventListener('click', function () { setAds(b.getAttribute('data-ads-btn')); });
    });
  }

  // Carrousel des outils : on double la liste pour une boucle sans à-coup
  document.querySelectorAll('[data-marquee]').forEach(function (track) {
    Array.prototype.slice.call(track.children).forEach(function (item) {
      var clone = item.cloneNode(true);
      clone.setAttribute('aria-hidden', 'true');
      clone.classList.add('is-clone');
      track.appendChild(clone);
    });
  });

  // FAQ : une seule réponse ouverte à la fois
  var faq = document.querySelector('[data-faq]');
  if (faq) {
    var items = faq.querySelectorAll('.faq-item');
    items.forEach(function (item) {
      var btn = item.querySelector('.faq-q');
      btn.addEventListener('click', function () {
        var wasOpen = item.classList.contains('is-open');
        items.forEach(function (other) {
          other.classList.remove('is-open');
          other.querySelector('.faq-q').setAttribute('aria-expanded', 'false');
        });
        if (!wasOpen) {
          item.classList.add('is-open');
          btn.setAttribute('aria-expanded', 'true');
        }
      });
    });
  }
})();

// Formulaires de contact : envoi sans recharger la page
(function () {
  'use strict';
  var forms = document.querySelectorAll('form[data-contact]');
  forms.forEach(function (form) {
    var t = form.querySelector('input[name="t"]');
    var page = form.querySelector('input[name="page"]');
    if (t) t.value = Date.now();
    if (page) page.value = location.pathname;
    var status = form.querySelector('.form-status');
    var btn = form.querySelector('button[type="submit"]');
    var show = function (ok, msg) {
      status.hidden = false;
      status.textContent = msg;
      status.className = 'form-status full ' + (ok ? 'is-ok' : 'is-error');
    };
    if (/[?&]envoi=ok/.test(location.search) && status) show(true, 'Merci, votre message a bien été envoyé. Nous vous répondons sous 48 h.');
    if (/[?&]envoi=erreur/.test(location.search) && status) show(false, 'L’envoi a échoué. Merci de réessayer dans quelques minutes.');
    if (!window.fetch || !window.FormData) return;
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      btn.disabled = true;
      var label = btn.textContent;
      btn.textContent = 'Envoi en cours';
      fetch(form.action, { method: 'POST', body: new FormData(form), headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'L’envoi a échoué. Merci de réessayer dans quelques minutes.' }; }); })
        .then(function (d) {
          show(d.ok, d.message);
          if (d.ok) { form.reset(); if (t) t.value = Date.now(); if (page) page.value = location.pathname; }
        })
        .catch(function () { show(false, 'Connexion impossible. Vérifiez votre connexion et réessayez.'); })
        .then(function () { btn.disabled = false; btn.textContent = label; });
    });
  });
})();

// Mesure d'audience sans cookie (voir /politique-de-confidentialite/)
(function () {
  'use strict';
  if (location.protocol === 'file:') return;
  try { if (localStorage.getItem('rb_ignore') === '1') return; } catch (e) {}
  var utm = (location.search.match(/[?&]utm_source=([^&]+)/) || [])[1] || '';
  var data = JSON.stringify({
    p: location.pathname,
    r: document.referrer,
    u: decodeURIComponent(utm),
    w: window.innerWidth || 0
  });
  var url = '/api/hit.php';
  if (navigator.sendBeacon) navigator.sendBeacon(url, new Blob([data], { type: 'text/plain' }));
  else if (window.fetch) fetch(url, { method: 'POST', body: data, keepalive: true, headers: { 'Content-Type': 'text/plain' } });
})();
