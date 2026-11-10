<?php
// Tableau de bord, onglet Agenda : créneaux ouverts, lien avec l'agenda Google, rendez-vous pris et liens de réservation.
if (!defined('RB_ADMIN')) { http_response_code(404); exit; }
require __DIR__ . '/../api/mail.php';
require __DIR__ . '/../api/emails.php';

$s = agenda_get($pdo);
$flash = ''; $flashErr = false; $newLink = '';
$post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && hash_equals($csrf, (string)($_POST['csrf'] ?? ''));

if ($post && isset($_POST['enregistrer'])) {
    $pl = [];
    for ($i = 1; $i <= 7; $i++) {
        $pl[$i] = [];
        foreach ((array)($_POST['p'][$i] ?? []) as $r) {
            $a = (string)($r['a'] ?? ''); $b = (string)($r['b'] ?? '');
            if (!preg_match('/^\d{2}:\d{2}$/', $a) || !preg_match('/^\d{2}:\d{2}$/', $b) || hm($b) <= hm($a)) continue;
            $pl[$i][] = [$a, $b];
        }
        usort($pl[$i], fn($x, $y) => hm($x[0]) <=> hm($y[0]));
    }
    $ical = trim((string)($_POST['ical'] ?? ''));
    if ($ical !== '' && !preg_match('#^https://calendar\.google\.com/calendar/ical/[^\s]+\.ics$#', $ical)) { $flash = 'L’adresse iCal doit commencer par https://calendar.google.com/calendar/ical/ et finir par .ics. Les autres réglages sont enregistrés.'; $flashErr = true; $ical = $s['ical']; }
    $visio = trim((string)($_POST['visio'] ?? ''));
    if ($visio !== '' && !preg_match('#^https://#', $visio)) $visio = 'https://' . $visio;
    $s = array_merge($s, [
        'plages' => $pl,
        'duree' => max(15, min(120, (int)($_POST['duree'] ?? 30))), 'pause' => max(0, min(60, (int)($_POST['pause'] ?? 15))),
        'delai' => max(0, min(168, (int)($_POST['delai'] ?? 24))), 'horizon' => max(1, min(8, (int)($_POST['horizon'] ?? 3))),
        'max_jour' => max(0, min(10, (int)($_POST['max_jour'] ?? 2))), 'visio' => mb_substr($visio, 0, 200), 'ical' => $ical,
        'releve' => isset($_POST['releve']) ? 1 : 0, 'devis' => isset($_POST['devis']) ? 1 : 0, 'public' => isset($_POST['public']) ? 1 : 0, 'actif' => 1,
    ]);
    agenda_set($pdo, $s);
    if (!$flashErr) { header('Location: ./?v=agenda&ok=1', true, 303); exit; }
}
if ($post && isset($_POST['annuler'])) {
    $st = $pdo->prepare("SELECT * FROM rdv WHERE id = ? AND statut = 'confirme'"); $st->execute([(int)$_POST['annuler']]);
    if ($r = $st->fetch()) {
        $pdo->prepare("UPDATE rdv SET statut = 'annule', annule_le = ? WHERE id = ?")->execute([now_paris()->format('Y-m-d H:i:s'), $r['id']]);
        $r['statut'] = 'annule';
        $relink = '';
        if ($r['lien'] !== '') {
            $exp = now_paris()->modify('+14 days')->format('Y-m-d H:i:s');
            $pdo->prepare('UPDATE rdv_liens SET utilise = 0, expire = CASE WHEN expire < ? THEN ? ELSE expire END WHERE jeton = ?')->execute([$exp, $exp, $r['lien']]);
            $relink = lien_url($r['lien']);
        }
        if (isset($_POST['prevenir'])) { $m = rdv_annule_mail($r, $relink); send_mail_to($r['email'], $m['subject'], $m['text'], $m['html']); }
    }
    header('Location: ./?v=agenda&annule=1', true, 303); exit;
}
if ($post && isset($_POST['lien'])) {
    $email = strtolower(clean_line((string)($_POST['email'] ?? ''), 200));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $flash = 'Adresse e-mail invalide.'; $flashErr = true; }
    else {
        $st = $pdo->prepare('SELECT * FROM contacts WHERE email = ?'); $st->execute([$email]);
        $c = $st->fetch() ?: [];
        $c = ['id' => $c['id'] ?? 0, 'email' => $email, 'nom' => clean_line((string)($_POST['nom'] ?? ''), 120) ?: ($c['nom'] ?? ''), 'site' => clean_line((string)($_POST['site'] ?? ''), 300) ?: ($c['site'] ?? '')];
        $newLink = lien_url(lien_creer($pdo, 'manuel', $c, $c['site'] !== '' ? 'Échange avec Rubriqua · ' . host_of(str_contains($c['site'], '://') ? $c['site'] : 'https://' . $c['site']) : ''));
        $flash = 'Lien créé pour ' . $email . ', valable ' . LIEN_JOURS . ' jours et pour un seul rendez-vous.';
    }
}
if (isset($_GET['ok'])) $flash = 'Agenda enregistré.';
if (isset($_GET['annule'])) $flash = 'Rendez-vous annulé.';

$busy = ical_busy($pdo, $s, isset($_GET['relire']));
$slots = creneaux($pdo, $s, $busy);
$upcoming = $pdo->query("SELECT * FROM rdv WHERE debut >= '" . now_paris()->format('Y-m-d') . " 00:00' ORDER BY debut LIMIT 40")->fetchAll();
$week = (int)$pdo->query("SELECT COUNT(*) FROM rdv WHERE statut = 'confirme' AND debut >= '" . now_paris()->format('Y-m-d H:i') . "' AND debut <= '" . now_paris()->modify('+7 days')->format('Y-m-d') . " 23:59'")->fetchColumn();
$https = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$feed = ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? config()['site_host']) . '/api/agenda-ics.php?cle=' . flux_cle();
$origins = ['releve' => 'relevé', 'complet' => 'audit complet', 'manuel' => 'lien manuel', 'public' => 'lien public'];
$sel = function (string $name, array $opts, $cur) { $o = '<select name="' . $name . '">'; foreach ($opts as $v => $l) $o .= '<option value="' . $v . '"' . ((string)$v === (string)$cur ? ' selected' : '') . '>' . h($l) . '</option>'; return $o . '</select>'; };
$timeSel = function (string $name, string $cur) { $o = '<select name="' . $name . '" aria-label="Heure">'; for ($m = 6 * 60; $m <= 22 * 60; $m += 15) { $v = sprintf('%02d:%02d', intdiv($m, 60), $m % 60); $o .= '<option value="' . $v . '"' . ($v === $cur ? ' selected' : '') . '>' . heure_fr($v) . '</option>'; } return $o . '</select>'; };
const MOIS_COURTS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
$nSlots = 0; foreach ($slots['jours'] as $d) $nSlots += count($d['heures']);

page_start('Agenda, tableau de bord Rubriqua', $css . '
.ag{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:20px;align-items:start}
.col{display:flex;flex-direction:column;gap:20px;min-width:0}
.card h2{display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap}
.card h2 .note{font-weight:600}
.day{display:grid;grid-template-columns:96px minmax(0,1fr);gap:12px;align-items:center;padding:10px 0;border-top:1px solid #efece7}
.day b{color:var(--ink)}
.ranges{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.rg{display:inline-flex;gap:6px;align-items:center;background:#f6f3ee;border:1px solid var(--line);border-radius:6px;padding:4px 6px 4px 8px;font-size:13px;font-weight:700;color:var(--ink)}
.rg select{font:inherit;font-weight:700;border:0;background:transparent;padding:2px 0;width:auto;color:var(--ink);cursor:pointer}
.rg button{border:0;background:none;padding:2px 4px;color:var(--muted);font-size:16px;line-height:1}
.add{border:1px dashed #cfc9bf;background:none;color:var(--muted);font-size:13px;padding:5px 10px}
.rules{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.rules label,.fld{display:flex;flex-direction:column;gap:6px;min-width:0}
select,input[type=url]{font:inherit;font-size:14px;color:var(--ink);background:#fff;border:1px solid #d9d4cc;border-radius:6px;padding:9px 11px;width:100%}
.sw{display:flex;gap:12px;align-items:center;font-size:14px;color:var(--ink);cursor:pointer}
.sw input{appearance:none;width:34px;height:20px;border-radius:10px;background:#d9d4cc;position:relative;flex:none;margin:0;cursor:pointer}
.sw input::after{content:"";position:absolute;left:3px;top:3px;width:14px;height:14px;border-radius:50%;background:#fff;transition:left .15s}
.sw input:checked{background:var(--ink)}.sw input:checked::after{left:17px}
.copy{display:flex;gap:8px}.copy input{flex:1;min-width:0;color:var(--muted)}
.rdv{display:grid;grid-template-columns:100px minmax(0,1fr) auto;gap:14px;align-items:start;padding:12px 0;border-top:1px solid #efece7}
.rdv:first-of-type{border-top:0}
.rdv .when{color:var(--ink);font-weight:800}.rdv .when span{display:block;font-weight:600;color:var(--muted)}
.rdv .note{overflow-wrap:anywhere}
.rdv p{margin:2px 0 0;white-space:pre-wrap;word-break:break-word}
.rdv .act{display:flex;flex-direction:column;gap:8px;align-items:flex-end}
.rdv form{display:flex;flex-direction:column;gap:6px;align-items:flex-end}
.rdv details summary{cursor:pointer;color:var(--red);font-weight:700;font-size:13px;list-style:none}
.prev{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px}
.prev div{border:1px solid var(--line);border-radius:8px;padding:10px 12px;font-size:13px}
.prev b{display:block;color:var(--ink);margin-bottom:4px}
.prev span{color:var(--muted)}
.state{display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-size:13px}
.save{position:sticky;bottom:0;background:var(--beige);padding:12px 0;border-top:1px solid var(--line);display:flex;gap:12px;align-items:center;flex-wrap:wrap}
@media (max-width:980px){.ag{grid-template-columns:1fr}}
@media (max-width:560px){.rules{grid-template-columns:1fr}.day{grid-template-columns:1fr;gap:6px}.rdv{grid-template-columns:1fr}.rdv .act,.rdv form{align-items:flex-start}}
');
?>
<main class="wrap">
  <header class="top">
    <div class="brand"><b>Agenda</b><span>Les clients réservent un échange sur vos créneaux libres, heure de Paris</span></div>
  </header>
  <?php if ($flash): ?><p class="flash<?= $flashErr ? ' err' : '' ?>"><?= h($flash) ?></p><?php endif; ?>
  <?php if (empty($s['actif'])): ?><p class="flash err">L’agenda n’est pas encore activé. Vérifiez vos créneaux et vos règles, puis enregistrez : les mails des relevés et des demandes d’audit complet proposeront alors la réservation.</p><?php endif; ?>

  <div class="ag">
    <form method="post" class="col" id="agenda-form">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <section class="card">
        <h2>Mes créneaux de la semaine <span class="note">Heure de Paris</span></h2>
        <?php foreach (JOURS as $i => $jn): ?>
        <div class="day"><b><?= h($jn) ?></b><div class="ranges" data-day="<?= $i ?>">
          <?php foreach ($s['plages'][$i] as $k => [$a, $b]): ?>
          <span class="rg"><?= $timeSel("p[$i][$k][a]", $a) ?> à <?= $timeSel("p[$i][$k][b]", $b) ?><button type="button" class="rm" aria-label="Retirer la plage">×</button></span>
          <?php endforeach; ?>
          <button type="button" class="add">+ plage</button>
        </div></div>
        <?php endforeach; ?>
        <p class="note" style="margin:10px 0 0">Un jour sans plage est fermé. Pour bloquer une date précise, ajoutez un événement dans votre agenda Google.</p>
      </section>

      <section class="card">
        <h2>Règles</h2>
        <div class="rules">
          <label><span class="lbl">Durée</span><?= $sel('duree', [20 => '20 minutes', 30 => '30 minutes', 45 => '45 minutes', 60 => '1 heure'], $s['duree']) ?></label>
          <label><span class="lbl">Pause entre deux</span><?= $sel('pause', [0 => 'Aucune', 10 => '10 minutes', 15 => '15 minutes', 30 => '30 minutes'], $s['pause']) ?></label>
          <label><span class="lbl">Réservable au plus tôt</span><?= $sel('delai', [4 => '4 heures avant', 12 => '12 heures avant', 24 => '24 heures avant', 48 => '48 heures avant', 72 => '3 jours avant'], $s['delai']) ?></label>
          <label><span class="lbl">Réservable jusqu’à</span><?= $sel('horizon', [1 => '1 semaine', 2 => '2 semaines', 3 => '3 semaines', 4 => '4 semaines', 6 => '6 semaines'], $s['horizon']) ?></label>
          <label><span class="lbl">Maximum par jour</span><?= $sel('max_jour', [1 => '1 rendez-vous', 2 => '2 rendez-vous', 3 => '3 rendez-vous', 4 => '4 rendez-vous', 0 => 'Sans limite'], $s['max_jour']) ?></label>
          <label><span class="lbl">Lien de visio</span><input type="url" name="visio" value="<?= h($s['visio']) ?>" placeholder="https://meet.google.com/…"></label>
        </div>
        <p class="note" style="margin:12px 0 0">Le lien de visio est envoyé dans chaque confirmation. Une salle Google Meet permanente fait l’affaire.</p>
      </section>

      <section class="card">
        <h2>Lien avec mon agenda Google</h2>
        <label class="fld"><span class="lbl">Adresse secrète au format iCal</span><input type="url" name="ical" value="<?= h($s['ical']) ?>" placeholder="https://calendar.google.com/calendar/ical/…/basic.ics" autocomplete="off"></label>
        <div class="state" style="margin-top:10px">
          <?php if ($s['ical'] === ''): ?><span class="st st-grey">Non relié</span><span class="note">Sans agenda relié, tous vos créneaux sont proposés.</span>
          <?php elseif (!$busy['ok']): ?><span class="st st-ko">Erreur</span><span class="note"><?= h(ucfirst($busy['erreur'])) ?>. Aucun créneau n’est proposé tant que l’agenda n’est pas lu.</span>
          <?php else: ?><span class="st st-ok">Relié</span><span class="note"><?= count($busy['busy']) ?> plage<?= count($busy['busy']) > 1 ? 's' : '' ?> occupée<?= count($busy['busy']) > 1 ? 's' : '' ?> lue<?= count($busy['busy']) > 1 ? 's' : '' ?><?= $busy['erreur'] ? ', ' . h($busy['erreur']) . ', dernière lecture conservée' : '' ?>.</span> <a class="note" href="./?v=agenda&relire=1">Relire maintenant</a><?php endif; ?>
        </div>
        <p class="note" style="margin:10px 0 0">Dans Google Agenda, Paramètres, puis votre agenda, section Intégrer l’agenda : copiez l’adresse secrète au format iCal. Le site lit seulement les heures occupées, jamais le contenu des événements. Faites-le pour chaque agenda qui doit bloquer des créneaux, en commençant par le principal.</p>
        <div class="fld" style="margin-top:14px;padding-top:14px;border-top:1px solid #efece7"><span class="lbl">Abonnement à mes rendez-vous Rubriqua</span>
          <div class="copy"><input type="text" readonly value="<?= h($feed) ?>" id="feed"><button type="button" data-copy="feed">Copier</button></div>
          <span class="note">À ajouter dans Google Agenda, Autres agendas, À partir de l’URL. Google le met à jour toutes les quelques heures. Chaque réservation vous est aussi envoyée par e-mail.</span></div>
      </section>

      <section class="card">
        <h2>Qui peut réserver</h2>
        <div style="display:flex;flex-direction:column;gap:12px">
          <label class="sw"><input type="checkbox" name="releve" <?= $s['releve'] ? 'checked' : '' ?>>Les clients qui reçoivent un relevé</label>
          <label class="sw"><input type="checkbox" name="devis" <?= $s['devis'] ? 'checked' : '' ?>>Les demandes d’audit complet, dans le mail de confirmation</label>
          <label class="sw"><input type="checkbox" name="public" <?= $s['public'] ? 'checked' : '' ?>>Lien public, rubriqua.com/rendez-vous/</label>
        </div>
        <p class="note" style="margin:12px 0 0">Chaque mail contient un lien personnel, valable <?= LIEN_JOURS ?> jours et pour un seul rendez-vous. La page n’est pas indexée et n’apparaît nulle part sur le site.</p>
      </section>

      <div class="save"><button class="btn-dark" name="enregistrer" value="1">Enregistrer l’agenda</button><span class="note">Les créneaux et les règles s’appliquent tout de suite.</span></div>
    </form>

    <div class="col">
      <section class="card">
        <h2>Rendez-vous à venir <span class="note"><?= $week ?> dans les 7 jours</span></h2>
        <?php if (!$upcoming): ?><p class="empty" style="margin:0">Aucun rendez-vous pour le moment.</p><?php endif; ?>
        <?php foreach ($upcoming as $r): $dt = DateTime::createFromFormat('Y-m-d H:i', $r['debut'], tz()); ?>
        <div class="rdv">
          <div class="when"><?= h(JOURS_COURTS[(int)$dt->format('N')] . ' ' . (int)$dt->format('j') . ' ' . MOIS_COURTS[(int)$dt->format('n') - 1]) ?><span><?= h(heure_fr($dt->format('H:i'))) ?></span></div>
          <div><b style="color:var(--ink)"><?= h($r['site'] !== '' ? host_of(str_contains($r['site'], '://') ? $r['site'] : 'https://' . $r['site']) : $r['nom']) ?></b>
            <div class="note"><?= h($r['nom']) ?> · <a href="mailto:<?= h($r['email']) ?>"><?= h($r['email']) ?></a><?= $r['tel'] !== '' ? ' · ' . h($r['tel']) : '' ?> · <?= h($origins[$r['origine']] ?? $r['origine']) ?><?php if ($r['contact_id']): ?> · <a href="./?v=contacts&c=<?= (int)$r['contact_id'] ?>">fiche</a><?php endif; ?></div>
            <?php if ($r['sujet'] !== ''): ?><p><?= h($r['sujet']) ?></p><?php endif; ?></div>
          <div class="act"><?php if ($r['statut'] === 'confirme'): ?><span class="st st-ok">Confirmé</span>
            <details><summary>Annuler</summary><form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><label class="note"><input type="checkbox" name="prevenir" checked> Prévenir par e-mail</label><button class="btn-red" name="annuler" value="<?= (int)$r['id'] ?>">Confirmer l’annulation</button></form></details>
          <?php else: ?><span class="st st-grey">Annulé</span><?php endif; ?></div>
        </div>
        <?php endforeach; ?>
      </section>

      <section class="card">
        <h2>Créneaux proposés <span class="note"><?= $nSlots ?> sur <?= (int)$s['horizon'] ?> semaine<?= $s['horizon'] > 1 ? 's' : '' ?></span></h2>
        <?php if (!$slots['ok']): ?><p class="empty" style="margin:0">Aucun créneau proposé : l’agenda Google n’a pas pu être lu.</p>
        <?php else: $shown = array_slice(array_values(array_filter($slots['jours'], fn($d) => $d['heures'])), 0, 9); ?>
        <?php if (!$shown): ?><p class="empty" style="margin:0">Aucun créneau libre. Ajoutez des plages ou élargissez la période.</p><?php endif; ?>
        <div class="prev"><?php foreach ($shown as $d): ?><div><b><?= h($d['sem'] . ' ' . $d['n'] . ' ' . MOIS[$d['mois'] - 1]) ?></b><span><?= h(implode(', ', array_map('heure_fr', $d['heures']))) ?></span></div><?php endforeach; ?></div>
        <?php endif; ?>
        <p class="note" style="margin:12px 0 0">Ce que voient vos clients, après retrait des heures occupées et des rendez-vous déjà pris.</p>
      </section>

      <section class="card">
        <h2>Créer un lien pour un contact</h2>
        <form method="post" style="display:flex;flex-direction:column;gap:10px">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="email" name="email" placeholder="E-mail du contact" required>
          <div class="rules"><input type="text" name="nom" placeholder="Nom, facultatif"><input type="text" name="site" placeholder="Site, facultatif"></div>
          <div><button class="btn-dark" name="lien" value="1">Créer le lien</button></div>
        </form>
        <?php if ($newLink): ?><div class="copy" style="margin-top:12px"><input type="text" readonly value="<?= h($newLink) ?>" id="newlink"><button type="button" data-copy="newlink">Copier</button></div><?php endif; ?>
        <p class="note" style="margin:10px 0 0">Pour un prospect venu par LinkedIn ou par téléphone. Le lien n’est pas envoyé : collez-le dans votre message.</p>
      </section>
    </div>
  </div>
</main>
<template id="rg-tpl"><span class="rg"><?= $timeSel('a', '09:00') ?> à <?= $timeSel('b', '12:00') ?><button type="button" class="rm" aria-label="Retirer la plage">×</button></span></template>
<script>
(function () {
  var form = document.getElementById('agenda-form');
  function renumber(box) {
    var d = box.getAttribute('data-day');
    box.querySelectorAll('.rg').forEach(function (rg, k) { var t = rg.querySelectorAll('select'); t[0].name = 'p[' + d + '][' + k + '][a]'; t[1].name = 'p[' + d + '][' + k + '][b]'; });
  }
  form.addEventListener('click', function (e) {
    var t = e.target;
    if (t.classList.contains('rm')) { var box = t.closest('.ranges'); t.closest('.rg').remove(); renumber(box); }
    if (t.classList.contains('add')) { var box = t.closest('.ranges'); box.insertBefore(document.getElementById('rg-tpl').content.firstElementChild.cloneNode(true), t); renumber(box); }
  });
  document.querySelectorAll('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var el = document.getElementById(b.getAttribute('data-copy'));
      var done = function () { b.textContent = 'Copié'; setTimeout(function () { b.textContent = 'Copier'; }, 1500); };
      if (navigator.clipboard) navigator.clipboard.writeText(el.value).then(done, function () { el.select(); });
      else { el.select(); document.execCommand('copy'); done(); }
    });
  });
})();
</script>
</body></html>
