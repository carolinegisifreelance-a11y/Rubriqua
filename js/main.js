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
  var track = document.querySelector('[data-marquee]');
  if (track) {
    Array.prototype.slice.call(track.children).forEach(function (item) {
      var clone = item.cloneNode(true);
      clone.setAttribute('aria-hidden', 'true');
      track.appendChild(clone);
    });
  }

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
