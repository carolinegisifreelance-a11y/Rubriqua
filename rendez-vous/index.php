<?php
// Rubriqua, page de réservation d'un échange. Accessible par un lien personnel reçu par e-mail.
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Choisir un créneau - Rubriqua</title>
<link rel="icon" href="/favicon.ico" sizes="32x32"><link rel="icon" href="/favicon.svg" type="image/svg+xml">
<style>
@font-face{font-family:Manrope;src:url(/assets/fonts/manrope-latin-wght-normal.woff2) format("woff2");font-weight:200 800;font-display:swap}
:root{--red:#991915;--red-light:#EF8D86;--ink:#1C1C1C;--text:#3b3936;--muted:#77726b;--line:#e4e0d9;--beige:#F4F2EE}
*{box-sizing:border-box}
body{margin:0;font-family:Manrope,system-ui,-apple-system,"Segoe UI",sans-serif;background:var(--beige);color:var(--ink);font-size:16px;line-height:1.5}
.in{max-width:1100px;margin:0 auto;padding-left:max(16px,env(safe-area-inset-left,0px));padding-right:max(16px,env(safe-area-inset-right,0px))}
.bar{background:#161616;border-bottom:1px solid #2e2e2e}
.bar .in{display:flex;justify-content:space-between;align-items:center;gap:16px;padding-block:16px}
.bar img{display:block;height:28px;width:auto}
.bar span{color:#a9a49d;font-size:14px}
.hero{background-color:#161616;background-image:linear-gradient(#232323 1px,transparent 1px),linear-gradient(90deg,#232323 1px,transparent 1px);background-size:44px 44px;color:#fff}
.hero .in{padding-block:48px 52px;display:flex;flex-direction:column;gap:14px}
.ctx{font-size:14px;font-weight:700;color:var(--red-light)}
h1{margin:0;font-size:clamp(30px,4.2vw,44px);font-weight:800;letter-spacing:-.035em;line-height:1.08;max-width:760px;text-wrap:balance}
.hero p{margin:0;font-size:17px;line-height:1.6;color:#cfcbc4;max-width:680px}
.grid{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(0,1fr);gap:28px;align-items:start;padding-block:36px 64px}
.card{background:#fff;border-radius:12px;padding:28px;display:flex;flex-direction:column;gap:18px;min-width:0}
.card h2{margin:0;font-size:20px;font-weight:800;letter-spacing:-.01em}
.head{display:flex;justify-content:space-between;align-items:center;gap:12px}
.nav{display:flex;align-items:center;gap:6px;font-size:14px;color:var(--muted)}
.nav button{font:inherit;width:34px;height:34px;border-radius:6px;border:1px solid var(--line);background:#fff;color:var(--ink);cursor:pointer;font-size:18px;line-height:1}
.nav button:disabled{opacity:.35;cursor:default}
.days{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px}
.d{font:inherit;display:flex;flex-direction:column;align-items:center;gap:2px;padding:10px 0;border-radius:8px;font-size:13px;font-weight:700;color:var(--ink);border:1px solid var(--line);background:#fff;cursor:pointer}
.d span{font-size:20px;font-weight:800}
.d:disabled{color:#b0aba3;background:transparent;border-color:transparent;cursor:default}
.d[aria-pressed=true]{background:var(--ink);color:#fff;border-color:var(--ink)}
.hours{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
.s{font:inherit;border:1px solid var(--ink);border-radius:6px;padding:12px;text-align:center;font-weight:800;font-size:15px;color:var(--ink);background:#fff;cursor:pointer}
.s[aria-pressed=true]{background:var(--red);border-color:var(--red);color:#fff}
.note{font-size:13px;color:var(--muted);margin:0}
.recap{background:var(--beige);border-radius:8px;padding:12px 14px;font-size:15px;line-height:1.5}
.recap span{color:var(--muted)}
label{display:flex;flex-direction:column;gap:6px;font-size:13px;font-weight:700;color:#4a4a4a}
input,textarea{font:inherit;font-size:15px;color:var(--ink);background:#fff;border:1px solid #d9d4cc;border-radius:6px;padding:11px 12px;width:100%}
textarea{min-height:90px;resize:vertical}
input:focus,textarea:focus,.d:focus-visible,.s:focus-visible,.go:focus-visible{outline:2px solid var(--red);outline-offset:2px}
.go{font:inherit;background:var(--red);color:#fff;font-weight:800;font-size:16px;padding:14px;border-radius:6px;border:0;cursor:pointer;width:100%}
.go:disabled{opacity:.5;cursor:default}
.ghost{background:#fff;color:var(--ink);border:1px solid var(--ink)}
.err{color:var(--red);font-weight:700;font-size:14px;margin:0}
.hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}
.solo{max-width:640px;margin:36px auto 64px}
.acts{display:flex;flex-wrap:wrap;gap:10px}
.acts a{flex:1 1 200px;text-align:center;text-decoration:none;font-weight:800;font-size:15px;padding:12px 14px;border-radius:6px;background:var(--red);color:#fff}
.acts a.ghost{background:#fff;color:var(--ink);border:1px solid var(--ink)}
.big{font-size:20px;font-weight:800;margin:0}
[hidden]{display:none!important}
@media (max-width:860px){.grid{grid-template-columns:1fr}}
@media (max-width:520px){.card{padding:20px}.days{gap:6px}.d span{font-size:18px}.hours{grid-template-columns:repeat(2,minmax(0,1fr))}.bar span{display:none}.hero .in{padding-block:36px 40px}}
</style>
</head>
<body>
<div class="bar"><div class="in"><a href="/"><img src="/assets/images/logo/logo-rubriqua-agence-seo-geo-growth-fond-sombre.svg" alt="Rubriqua" width="131" height="28"></a><span>Échange gratuit, sans engagement</span></div></div>
<section class="hero"><div class="in">
  <span class="ctx" id="ctx" hidden></span>
  <h1 id="h1">Choisissez un créneau pour échanger avec notre expert SEO et GEO</h1>
  <p id="lead">Un échange gratuit en visio pour parler de votre site et voir comment améliorer sa visibilité sur Google et dans les IA.</p>
</div></section>

<div class="in">
  <div class="solo card" id="msg" hidden><p class="big" id="msg-t"></p><p id="msg-p" style="margin:0"></p><div class="acts" id="msg-a"></div></div>

  <div class="grid" id="book" hidden>
    <section class="card">
      <div class="head"><h2>1. Le jour</h2><div class="nav"><button type="button" id="prev" aria-label="Semaines précédentes">‹</button><span id="month"></span><button type="button" id="next" aria-label="Semaines suivantes">›</button></div></div>
      <div class="days" id="days"></div>
      <h2 id="hours-t" style="padding-top:6px">2. L’heure</h2>
      <div class="hours" id="hours"></div>
      <p class="note">Heure de Paris. Seuls les créneaux libres sont affichés.</p>
    </section>

    <form class="card" id="form" novalidate>
      <h2>3. Vos coordonnées</h2>
      <div class="recap" id="recap"><span>Choisissez un jour puis une heure.</span></div>
      <label>Nom et prénom<input name="nom" autocomplete="name" required></label>
      <label>E-mail<input name="email" type="email" autocomplete="email" required></label>
      <label>Téléphone, facultatif<input name="tel" type="tel" autocomplete="tel"></label>
      <label>Ce que vous aimeriez aborder, facultatif<textarea name="sujet"></textarea></label>
      <div class="hp" aria-hidden="true"><label>Site web<input name="site_web" tabindex="-1" autocomplete="off"></label></div>
      <p class="err" id="err" hidden></p>
      <button class="go" type="submit" id="go" disabled>Confirmer le rendez-vous</button>
      <p class="note">Vous recevez une confirmation par e-mail, avec un lien pour ajouter le rendez-vous à votre agenda, le déplacer ou l’annuler. Vos coordonnées servent uniquement à organiser cet échange.</p>
    </form>
  </div>
</div>

<script>
(function () {
  var MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
  var JOURS = {'lun.': 'lundi', 'mar.': 'mardi', 'mer.': 'mercredi', 'jeu.': 'jeudi', 'ven.': 'vendredi', 'sam.': 'samedi', 'dim.': 'dimanche'};
  var qs = new URLSearchParams(location.search), J = qs.get('j') || '', R = qs.get('r') || '';
  var $ = function (id) { return document.getElementById(id); };
  var t0 = Date.now(), data = null, page = 0, day = null, hour = null, PER = 10;
  function hfr(h) { var p = h.split(':'); return parseInt(p[0], 10) + ' h ' + p[1]; }
  function msg(title, text, actions) {
    $('book').hidden = true; $('msg').hidden = false; $('msg-t').textContent = title; $('msg-p').textContent = text || '';
    $('msg-a').innerHTML = '';
    (actions || []).forEach(function (a) { var el = document.createElement('a'); el.href = a[1]; el.textContent = a[0]; if (a[2]) el.className = 'ghost'; if (/^https:\/\/calendar\.google/.test(a[1])) { el.target = '_blank'; el.rel = 'noopener'; } $('msg-a').appendChild(el); });
  }
  function get(url) { return fetch(url, {credentials: 'same-origin'}).then(function (r) { return r.json(); }); }
  function post(body) { return fetch('/api/rdv.php', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)}).then(function (r) { return r.json(); }); }

  // Gestion d'un rendez-vous déjà pris
  if (R) {
    $('h1').textContent = 'Votre rendez-vous avec Rubriqua';
    $('lead').textContent = 'Déplacez ou annulez votre échange en un clic.';
    get('/api/rdv.php?r=' + encodeURIComponent(R)).then(function (r) {
      if (!r.ok) return msg('Rendez-vous introuvable', r.message);
      var x = r.rdv;
      if (x.statut !== 'confirme') return msg('Ce rendez-vous est annulé', x.quand + '.');
      if (x.passe) return msg('Ce rendez-vous est passé', x.quand + '.');
      msg(x.quand, x.minutes + ' minutes en visio, heure de Paris.' + (x.visio ? ' Lien de la visio : ' + x.visio : ''));
      var b = document.createElement('button'); b.className = 'go'; b.type = 'button'; b.textContent = 'Annuler ce rendez-vous';
      var b2 = document.createElement('p'); b2.className = 'note'; b2.textContent = 'Pour le déplacer, annulez-le : vous pourrez choisir un autre créneau juste après.';
      $('msg-a').appendChild(b); $('msg').appendChild(b2);
      b.addEventListener('click', function () {
        b.disabled = true;
        post({action: 'annuler', r: R}).then(function (a) {
          b2.remove();
          if (!a.ok) { b.disabled = false; return msg('Annulation impossible', a.message); }
          msg('Rendez-vous annulé', a.message, a.relink ? [['Choisir un autre créneau', a.relink]] : []);
        });
      });
    }).catch(function () { msg('Page indisponible', 'Réessayez dans quelques minutes.'); });
    return;
  }

  function load() {
    return get('/api/rdv.php' + (J ? '?j=' + encodeURIComponent(J) : '')).then(function (r) {
      if (!r.ok) return msg('Réservation indisponible', r.message);
      data = r; data.jours = r.jours;
      if (r.contexte) { $('ctx').textContent = r.contexte; $('ctx').hidden = false; }
      $('lead').textContent = 'Un échange gratuit de ' + r.duree + ' minutes en visio pour parler de votre site et voir comment améliorer sa visibilité sur Google et dans les IA.';
      var f = $('form');
      if (r.nom && !f.nom.value) f.nom.value = r.nom;
      if (r.email && !f.email.value) f.email.value = r.email;
      $('book').hidden = false; $('msg').hidden = true;
      if (!r.jours.some(function (d) { return d.heures.length; })) return msg('Aucun créneau libre pour le moment', 'Répondez au mail reçu avec deux ou trois créneaux qui vous conviennent, nous nous adaptons.');
      if (!day) { var first = r.jours.findIndex(function (d) { return d.heures.length; }); page = Math.floor(first / PER); }
      render();
    }).catch(function () { msg('Page indisponible', 'Réessayez dans quelques minutes.'); });
  }
  function render() {
    var list = data.jours.slice(page * PER, page * PER + PER);
    $('prev').disabled = page === 0; $('next').disabled = (page + 1) * PER >= data.jours.length;
    var ms = []; list.forEach(function (d) { var m = MOIS[d.mois - 1]; if (ms.indexOf(m) < 0) ms.push(m); });
    $('month').textContent = ms.map(function (m, i) { return i ? m : m.charAt(0).toUpperCase() + m.slice(1); }).join(', ');
    $('days').innerHTML = '';
    list.forEach(function (d) {
      var b = document.createElement('button'); b.type = 'button'; b.className = 'd'; b.innerHTML = d.sem + '<span>' + d.n + '</span>';
      b.disabled = !d.heures.length; b.setAttribute('aria-pressed', day && day.jour === d.jour ? 'true' : 'false');
      b.setAttribute('aria-label', JOURS[d.sem] + ' ' + d.n + ' ' + MOIS[d.mois - 1] + (d.heures.length ? '' : ', complet'));
      b.addEventListener('click', function () { day = d; hour = null; render(); });
      $('days').appendChild(b);
    });
    $('hours').innerHTML = '';
    if (!day) { $('hours-t').textContent = '2. L’heure'; $('hours').innerHTML = '<p class="note">Choisissez d’abord un jour.</p>'; }
    else {
      $('hours-t').textContent = '2. L’heure, ' + JOURS[day.sem] + ' ' + day.n + ' ' + MOIS[day.mois - 1];
      day.heures.forEach(function (h) {
        var b = document.createElement('button'); b.type = 'button'; b.className = 's'; b.textContent = hfr(h); b.setAttribute('aria-pressed', hour === h ? 'true' : 'false');
        b.addEventListener('click', function () { hour = h; render(); });
        $('hours').appendChild(b);
      });
    }
    $('recap').innerHTML = day && hour ? '<b>' + (JOURS[day.sem].charAt(0).toUpperCase() + JOURS[day.sem].slice(1)) + ' ' + day.n + ' ' + MOIS[day.mois - 1] + ', ' + hfr(hour) + '</b><br><span>' + data.duree + ' minutes · visio, lien envoyé par e-mail</span>' : '<span>Choisissez un jour puis une heure.</span>';
    $('go').disabled = !(day && hour);
  }
  $('prev').addEventListener('click', function () { if (page > 0) { page--; render(); } });
  $('next').addEventListener('click', function () { page++; render(); });
  $('form').addEventListener('submit', function (e) {
    e.preventDefault();
    var f = e.target; $('err').hidden = true;
    if (!f.nom.value.trim()) { $('err').textContent = 'Indiquez votre nom.'; $('err').hidden = false; return f.nom.focus(); }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(f.email.value.trim())) { $('err').textContent = 'Vérifiez votre adresse e-mail.'; $('err').hidden = false; return f.email.focus(); }
    $('go').disabled = true; $('go').textContent = 'Réservation…';
    post({action: 'reserver', j: J, jour: day.jour, heure: hour, nom: f.nom.value, email: f.email.value, tel: f.tel.value, sujet: f.sujet.value, site_web: f.site_web.value, t: Date.now() - t0}).then(function (r) {
      $('go').textContent = 'Confirmer le rendez-vous';
      if (!r.ok) { $('err').textContent = r.message; $('err').hidden = false; if (r.recharger) { day = null; hour = null; load(); } else $('go').disabled = false; return; }
      $('h1').textContent = 'Votre rendez-vous est confirmé';
      $('lead').textContent = 'À bientôt pour en parler.';
      msg(r.quand, r.minutes + ' minutes en visio, heure de Paris. ' + (r.visio ? 'Lien de la visio : ' + r.visio + '. ' : '') + r.message,
        [['Ajouter à Google Agenda', r.gcal], ['Autre agenda (.ics)', r.ics, true]]);
      window.scrollTo(0, 0);
    }).catch(function () { $('go').disabled = false; $('go').textContent = 'Confirmer le rendez-vous'; $('err').textContent = 'La réservation n’est pas passée. Réessayez dans quelques minutes.'; $('err').hidden = false; });
  });
  load();
})();
</script>
</body>
</html>
