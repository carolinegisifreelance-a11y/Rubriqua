<?php
// Tableau de bord, onglet Contacts : les personnes qui ont laissé leur e-mail depuis l'audit GEO.
if (!defined('RB_ADMIN')) { http_response_code(404); exit; }

$flash = '';
// Suppression d'un contact (demande d'effacement), avec ses demandes et ses relevés
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['supprimer']) && hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
    $id = (int)$_POST['supprimer'];
    $pdo->prepare('DELETE FROM releves WHERE demande_id IN (SELECT id FROM demandes WHERE contact_id = ?)')->execute([$id]);
    $pdo->prepare('DELETE FROM demandes WHERE contact_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM contacts WHERE id = ?')->execute([$id]);
    header('Location: ./?v=contacts&supprime=1', true, 303); exit;
}
// Étiquettes d'une fiche
const ETIQ = ['À faire', 'Mail envoyé', 'Relancé', 'Rendez-vous', 'Client', 'Pas intéressé', 'Test'];
function tags_of(string $s): array { return array_values(array_filter(array_map('trim', explode(',', $s)))); }
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['etiq_id']) && hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
    $t = array_map(fn($x) => clean_line((string)$x, 30), (array)($_POST['etiq'] ?? []));
    foreach (tags_of(str_replace(';', ',', (string)($_POST['etiq_new'] ?? ''))) as $n) $t[] = clean_line($n, 30);
    $t = array_values(array_unique(array_filter(array_map(fn($x) => str_replace(',', ' ', $x), $t))));
    $pdo->prepare('UPDATE contacts SET etiquettes = ? WHERE id = ?')->execute([mb_substr(implode(',', $t), 0, 300), (int)$_POST['etiq_id']]);
    header('Location: ./?v=contacts&c=' . (int)$_POST['etiq_id'] . '&etiq=1', true, 303); exit;
}
if (isset($_GET['etiq'])) $flash = 'Étiquettes enregistrées.';
if (isset($_GET['supprime'])) $flash = 'Le contact et toutes ses données ont été supprimés.';

// Export des inscrits aux conseils, seuls contacts utilisables pour l'e-mailing
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="rubriqua-inscrits-conseils-' . today() . '.csv"');
    $f = fopen('php://output', 'w');
    fwrite($f, "\xEF\xBB\xBF");
    fputcsv($f, ['email', 'nom', 'site', 'inscrit_le'], ';');
    foreach ($pdo->query('SELECT email, nom, site, conseils_le FROM contacts WHERE conseils = 1 ORDER BY conseils_le DESC') as $r) fputcsv($f, array_values($r), ';');
    exit;
}

$since = now_paris()->modify('-29 days')->format('Y-m-d');
$q1 = function (string $sql, array $a = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return (int)$st->fetchColumn(); };
$notTest = "contact_id NOT IN (SELECT id FROM contacts WHERE ',' || etiquettes || ',' LIKE '%,Test,%')";
$audits = $q1('SELECT COALESCE(SUM(n), 0) FROM compteurs WHERE cle = ? AND jour >= ?', ['audit', $since]);
$rapports = $q1("SELECT COUNT(*) FROM demandes WHERE type IN ('rapport', 'releve') AND cree_le >= ? AND $notTest", [$since]);
$releves = $q1("SELECT COUNT(*) FROM demandes WHERE type = 'releve' AND cree_le >= ? AND $notTest", [$since]);
$complets = $q1("SELECT COUNT(*) FROM demandes WHERE type = 'complet' AND cree_le >= ? AND $notTest", [$since]);
$inscrits = $q1("SELECT COUNT(*) FROM contacts WHERE conseils = 1 AND ',' || etiquettes || ',' NOT LIKE '%,Test,%'");
$pct = fn($a, $b) => $b ? number_format($a / $b * 100, 0, ',', '') . '%' : '';

$filters = ['tous' => 'Tous', 'rapport' => 'Rapport seul', 'releve' => 'Relevé', 'complet' => 'Audit complet', 'conseils' => 'Inscrits aux conseils'];
$f = $_GET['f'] ?? 'tous'; if (!isset($filters[$f])) $f = 'tous';
$search = trim((string)($_GET['q'] ?? ''));
$tag = trim((string)($_GET['e'] ?? ''));

$contacts = $pdo->query('SELECT * FROM contacts ORDER BY maj_le DESC')->fetchAll();
$byContact = [];
foreach ($pdo->query('SELECT id, cree_le, contact_id, type, site, score, produits, concurrents, message, statut, echeance, envoye_le, mail_ok FROM demandes ORDER BY cree_le DESC') as $d) $byContact[$d['contact_id']][] = $d;
$rows = array_filter($contacts, function ($c) use ($f, $search, $tag, $byContact) {
    $types = array_column($byContact[$c['id']] ?? [], 'type');
    if ($f === 'conseils' && !$c['conseils']) return false;
    if ($f === 'rapport' && $types !== array_fill(0, count($types), 'rapport')) return false;
    if (in_array($f, ['releve', 'complet'], true) && !in_array($f, $types, true)) return false;
    if ($tag !== '' && !in_array($tag, tags_of($c['etiquettes']), true)) return false;
    if ($search !== '' && stripos($c['email'] . ' ' . $c['nom'] . ' ' . $c['site'], $search) === false) return false;
    return true;
});

$allTags = []; foreach ($contacts as $c) foreach (tags_of($c['etiquettes']) as $t) $allTags[$t] = ($allTags[$t] ?? 0) + 1;
function tag_chip(string $t): string { $cls = ['À faire' => 'st-warn', 'Mail envoyé' => 'st-ok', 'Client' => 'st-dark', 'Pas intéressé' => 'st-ko', 'Test' => 'st-grey']; return '<span class="st ' . ($cls[$t] ?? 'st-tag') . '">' . h($t) . '</span>'; }
function d_fr(string $dt): string { return (new DateTime($dt))->format('d/m/Y'); }
function journey(array $ds): string {
    $o = [];
    foreach (array_reverse($ds) as $d) {
        if ($d['type'] === 'rapport') $o[] = '<span class="st st-grey">Rapport</span>';
        if ($d['type'] === 'releve') $o[] = '<span class="st st-grey">Rapport</span>' . ($d['statut'] === 'envoye' ? '<span class="st st-ok">Relevé envoyé</span>' : '<span class="st st-warn">Relevé à faire</span>');
        if ($d['type'] === 'complet') $o[] = '<span class="st st-dark">Audit complet</span>';
    }
    return implode(' ', array_unique($o));
}

$detail = null;
if (isset($_GET['c'])) { foreach ($contacts as $c) if ((int)$c['id'] === (int)$_GET['c']) $detail = $c; }

page_start('Contacts, tableau de bord Rubriqua', $css . '
.cgrid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:0;padding:0}
.cgrid .kpi{padding:18px 20px;border-left:1px solid var(--line)}.cgrid .kpi:first-child{border-left:0}
.bar2{display:flex;flex-wrap:wrap;gap:12px;justify-content:space-between;align-items:center}
.bar2 form{display:flex;gap:8px}.bar2 input{min-width:220px}
.ct td{padding:12px 10px 12px 0}.ct td:last-child{padding-right:0}
.ct a.name{font-weight:800;color:var(--ink);text-decoration:none}
.chips{display:flex;flex-wrap:wrap;gap:4px}
.dl{display:grid;grid-template-columns:160px minmax(0,1fr);gap:6px 14px;margin:0}.dl dt{color:var(--muted);font-weight:700}.dl dd{margin:0;color:var(--ink);word-break:break-word}
.st-tag{background:#efe9f6;color:#4b3a6b}
.etq{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.etq label{display:inline-flex;gap:6px;align-items:center;border:1px solid var(--line);border-radius:6px;padding:6px 10px;font-size:13px;font-weight:700;color:var(--ink);cursor:pointer}
.etq label:has(input:checked){background:var(--ink);color:#fff;border-color:var(--ink)}
.etq input[type=checkbox]{margin:0}
.etq input[type=text]{width:auto;min-width:180px}
.dem{border-top:1px solid var(--line);padding:14px 0;display:flex;flex-direction:column;gap:6px}
@media (max-width:860px){.cgrid{grid-template-columns:repeat(2,minmax(0,1fr))}.cgrid .kpi{border-left:0;border-top:1px solid var(--line)}.dl{grid-template-columns:1fr}.ct{min-width:760px}}
');
?>
<main class="wrap">
  <header class="top">
    <div class="brand"><b>Contacts</b><span>Contacts venus de l’audit GEO</span></div>
    <div class="tools">
    </div>
  </header>
  <?php if ($flash): ?><p class="flash"><?= h($flash) ?></p><?php endif; ?>

<?php if ($detail): $ds = $byContact[$detail['id']] ?? []; ?>
  <p><a href="./?v=contacts">← Tous les contacts</a></p>
  <section class="card" style="display:flex;flex-direction:column;gap:14px">
    <div class="bar2"><div><span class="lbl">Contact</span><h2 style="margin:4px 0 0;font-size:22px"><?= h($detail['nom'] !== '' ? $detail['nom'] : $detail['email']) ?></h2></div>
      <form method="post" onsubmit="return confirm('Supprimer ce contact, ses demandes et ses relevés ? Cette action est définitive.')"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><button name="supprimer" value="<?= (int)$detail['id'] ?>">Supprimer ce contact</button></form></div>
    <dl class="dl">
      <dt>E-mail</dt><dd><?= h($detail['email']) ?></dd>
      <dt>Site</dt><dd><?= h($detail['site']) ?></dd>
      <dt>Conseils par e-mail</dt><dd><?= $detail['conseils'] ? 'Oui, depuis le ' . h(d_fr($detail['conseils_le'])) : ($detail['conseils_le'] !== '' ? 'Désinscrit le ' . h(d_fr($detail['conseils_le'])) : 'Non') ?></dd>
      <dt>Premier contact</dt><dd><?= h(d_fr($detail['cree_le'])) ?></dd>
    </dl>
    <form method="post" style="display:flex;flex-direction:column;gap:8px;border-top:1px solid var(--line);padding-top:14px">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="etiq_id" value="<?= (int)$detail['id'] ?>">
      <span class="lbl">Étiquettes</span>
      <div class="etq">
        <?php $mine = tags_of($detail['etiquettes']); foreach (array_unique(array_merge(ETIQ, array_keys($allTags), $mine)) as $t): ?><label><input type="checkbox" name="etiq[]" value="<?= h($t) ?>"<?= in_array($t, $mine, true) ? ' checked' : '' ?>><?= h($t) ?></label><?php endforeach; ?>
        <input type="text" name="etiq_new" placeholder="Nouvelle étiquette" maxlength="30">
        <button class="btn-dark">Enregistrer</button>
      </div>
      <span class="note">Les fiches marquées Test ne comptent pas dans les compteurs.</span>
    </form>
  </section>
  <section class="card">
    <h2>Historique</h2>
    <?php foreach ($ds as $d): $a = $pdo->query('SELECT audit FROM demandes WHERE id = ' . (int)$d['id'])->fetchColumn(); $au = json_decode((string)$a, true) ?: []; ?>
      <div class="dem">
        <div class="bar2"><b style="color:var(--ink)"><?= ['rapport' => 'Rapport d’audit', 'releve' => 'Rapport et relevé de citations', 'complet' => 'Demande d’audit complet'][$d['type']] ?? h($d['type']) ?></b><span class="note"><?= h((new DateTime($d['cree_le']))->format('d/m/Y à H:i')) ?></span></div>
        <span><?= h($d['site']) ?> · audit <?= (int)$d['score'] ?>/100<?= !empty($au['counts']) ? ', ' . (int)$au['counts']['ko'] . ' bloquant(s), ' . (int)$au['counts']['warn'] . ' à améliorer' : '' ?></span>
        <?php if ($d['produits'] !== ''): ?><span class="note">Produits : <?= h($d['produits']) ?></span><?php endif; ?>
        <?php if ($d['concurrents'] !== ''): ?><span class="note">Concurrents : <?= h($d['concurrents']) ?></span><?php endif; ?>
        <?php if ($d['message'] !== ''): ?><p style="margin:0;white-space:pre-wrap"><?= h($d['message']) ?></p><?php endif; ?>
        <?php if ($d['type'] === 'releve'): ?><span><?= $d['statut'] === 'envoye' ? '<span class="st st-ok">Relevé envoyé le ' . h(d_fr($d['envoye_le'])) . '</span>' : '<span class="st st-warn">À envoyer avant le ' . h(d_fr($d['echeance'])) . '</span>' ?> <a href="./?v=releves&amp;d=<?= (int)$d['id'] ?>">Ouvrir le relevé</a></span><?php endif; ?>
        <?php if (!$d['mail_ok']): ?><span class="warn">L’e-mail au contact n’est pas parti</span><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </section>
<?php else: ?>
  <section class="card cgrid">
    <div class="kpi"><span>Audits lancés, 30 jours</span><b><?= nf($audits) ?></b><small class="flat">compte anonyme</small></div>
    <div class="kpi"><span>Rapports envoyés</span><b><?= nf($rapports) ?></b><small class="flat"><?= $pct($rapports, $audits) ? $pct($rapports, $audits) . ' des audits' : '&nbsp;' ?></small></div>
    <div class="kpi"><span>Relevés demandés</span><b><?= nf($releves) ?></b><small class="flat"><?= $pct($releves, $rapports) ? $pct($releves, $rapports) . ' des rapports' : '&nbsp;' ?></small></div>
    <div class="kpi"><span>Audits complets demandés</span><b><?= nf($complets) ?></b><small class="flat">30 jours</small></div>
    <div class="kpi"><span>Inscrits aux conseils</span><b><?= nf($inscrits) ?></b><small class="flat">liste e-mailing</small></div>
  </section>
  <div class="bar2">
    <div style="display:flex;flex-direction:column;gap:8px">
    <nav class="seg" aria-label="Filtre"><?php foreach ($filters as $k => $l): ?><a href="./?v=contacts&amp;f=<?= $k ?>"<?= $k === $f && $tag === '' ? ' aria-current="page"' : '' ?>><?= h($l) ?></a><?php endforeach; ?></nav>
    <?php if ($allTags): ?><nav class="seg" aria-label="Étiquettes"><?php foreach ($allTags as $t => $n): ?><a href="./?v=contacts&amp;e=<?= urlencode($t) ?>"<?= $t === $tag ? ' aria-current="page"' : '' ?>><?= h($t) ?> <span class="note"><?= $n ?></span></a><?php endforeach; ?></nav><?php endif; ?>
    </div>
    <form method="get"><input type="hidden" name="v" value="contacts"><input type="hidden" name="f" value="<?= h($f) ?>"><input type="search" name="q" value="<?= h($search) ?>" placeholder="Rechercher un site ou un e-mail"><a class="btn btn-dark" href="./?v=contacts&amp;export=1" style="text-decoration:none;white-space:nowrap">Exporter les inscrits (CSV)</a></form>
  </div>
  <section class="card" style="overflow-x:auto">
    <?php if (!$rows): ?><p class="empty">Aucun contact pour le moment.</p><?php else: ?>
    <table class="ct"><thead><tr><th>Contact</th><th>Site et audit</th><th>Produits, concurrents</th><th>Parcours</th><th>Étiquettes</th><th>Conseils</th><th class="n">Dernière action</th></tr></thead><tbody>
    <?php foreach ($rows as $c): $ds = $byContact[$c['id']] ?? []; $last = $ds[0] ?? null; $rel = null; foreach ($ds as $d) if ($d['produits'] !== '') { $rel = $d; break; } ?>
      <tr>
        <td><a class="name" href="./?v=contacts&amp;c=<?= (int)$c['id'] ?>"><?= h($c['nom'] !== '' ? $c['nom'] : 'Non renseigné') ?></a><br><span class="note"><?= h($c['email']) ?></span></td>
        <td class="p"><?= h(preg_replace('#^https?://(www\.)?#', '', rtrim($c['site'], '/'))) ?><br><span class="note"><?= $last ? (int)$last['score'] . '/100' : '' ?></span></td>
        <td><span class="note"><?= $rel ? h($rel['produits']) . '<br>' . h($rel['concurrents'] !== '' ? $rel['concurrents'] : 'Concurrents repérés par les IA') : '' ?></span></td>
        <td><div class="chips"><?= journey($ds) ?></div></td>
        <td><div class="chips"><?= implode(' ', array_map('tag_chip', tags_of($c['etiquettes']))) ?></div></td>
        <td><?= $c['conseils'] ? '<span class="st st-ok">Oui</span>' : '<span class="st st-grey">Non</span>' ?></td>
        <td class="n"><span class="note"><?= h(d_fr($c['maj_le'])) ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
  </section>
  <p class="note">Un contact correspond à une adresse e-mail. Seuls les inscrits aux conseils peuvent recevoir l’e-mailing ou être ajoutés à une liste Google Ads. Les contacts sans échange depuis 3 ans sont supprimés automatiquement. Les audits faits sans e-mail ne sont jamais conservés, seul leur nombre est compté.</p>
<?php endif; ?>
</main>
</body></html>
