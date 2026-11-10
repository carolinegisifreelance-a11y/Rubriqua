<?php
// Tableau de bord, onglet Relevés : préparer et envoyer le relevé de citations offert.
// Caro pose les questions aux IA et colle les réponses. L'outil repère le client et ses concurrents,
// propose des opportunités et prépare le mail, qui part depuis le site.
if (!defined('RB_ADMIN')) { http_response_code(404); exit; }
require __DIR__ . '/../api/mail.php';
require __DIR__ . '/../api/emails.php';

function load_demande(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT d.*, c.email, c.nom, c.conseils, c.jeton, c.id AS cid FROM demandes d JOIN contacts c ON c.id = d.contact_id WHERE d.id = ? AND d.type = 'releve'");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}


function split_list(string $s): array { return array_values(array_filter(array_map('trim', preg_split('/[,;\n]+/', $s)))); }
function lcfirst_fr(string $s): string { return mb_strtolower(mb_substr($s, 0, 1)) . mb_substr($s, 1); }

// Questions de secours, neutres, quand l'IA n'est pas disponible
function fallback_questions(array $cur): array {
    $p = array_map('lcfirst_fr', split_list($cur['produits'])); $p1 = $p[0] ?? 'ce sujet'; $p2 = $p[1] ?? $p1;
    $c = split_list($cur['concurrents']);
    $q5 = count($c) >= 2 ? $c[0] . ' ou ' . $c[1] . ', que choisir ?' : (count($c) === 1 ? 'Quelles alternatives à ' . $c[0] . ' ?' : 'Quelles solutions existent pour ' . $p2 . ' ?');
    return ['Que me conseilles-tu pour ' . $p1 . ' ?', 'Quels sont les meilleurs sites pour ' . $p2 . ' ?', 'Où trouver ' . $p1 . ' en France ?', 'Comment bien démarrer avec ' . $p1 . ' ?', $q5];
}

// Questions rédigées par Claude à partir de la page testée et de ce que le client a donné
function ai_questions(array $cur, array $audit): ?array {
    $key = config()['anthropic_key'];
    if ($key === '' || !function_exists('curl_init')) return null;
    $pg = $audit['page'] ?? [];
    $ctx = "Adresse : " . $cur['site'] . "\nTitre de la page : " . ($pg['titre'] ?? '') . "\nDescription : " . ($pg['description'] ?? '')
         . "\nTitre principal : " . ($pg['h1'] ?? '') . "\nIntertitres : " . implode(' | ', $pg['intertitres'] ?? [])
         . "\nProduits, services ou sujets indiqués par le client : " . $cur['produits'] . "\nConcurrents indiqués : " . ($cur['concurrents'] !== '' ? $cur['concurrents'] : 'aucun');
    $prompt = "Tu aides une agence SEO et GEO à vérifier si un site est cité par les assistants IA (ChatGPT, Perplexity, Aperçus IA de Google).\n\n"
        . $ctx . "\n\nComprends d'abord ce qu'est ce site : boutique, entreprise de services, application, jeu, média, site de contenu, association, etc. "
        . "Puis rédige exactement 5 questions qu'un internaute francophone poserait réellement à un assistant IA, et pour lesquelles ce site mériterait d'être recommandé ou cité. "
        . "Mélange : deux questions de recommandation adaptées au type de site (quel site, quelle appli, quel fournisseur, quelle marque selon le cas), deux questions pratiques sur les sujets du site, "
        . "et une question qui compare un concurrent indiqué ou cherche une alternative à lui, ou à défaut une troisième question de recommandation. "
        . "Questions courtes et naturelles, comme on les tape vraiment, en français, sans jamais nommer le site testé. "
        . "Réponds uniquement par un tableau JSON de 5 chaînes, sans aucun autre texte.";
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_HTTPHEADER => ['content-type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'],
        CURLOPT_POSTFIELDS => json_encode(['model' => 'claude-haiku-5-5', 'max_tokens' => 800, 'messages' => [['role' => 'user', 'content' => $prompt]]], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
    ]);
    $res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $cerr = curl_error($ch); curl_close($ch);
    $fail = function (string $why) { $GLOBALS['ia_err'] = $why; error_log('Rubriqua questions IA : ' . $why); return null; };
    if (!$res) return $fail('pas de réponse (' . $cerr . ')');
    $j = json_decode($res, true);
    if ($code !== 200) return $fail('HTTP ' . $code . ', ' . ($j['error']['message'] ?? substr((string)$res, 0, 200)));
    $txt = implode("\n", array_map(fn($b) => ($b['type'] ?? '') === 'text' ? (string)$b['text'] : '', $j['content'] ?? []));
    if (!preg_match('/\[.*\]/s', $txt, $m)) return $fail('réponse sans liste : ' . mb_substr($txt, 0, 160));
    $qs = json_decode($m[0], true);
    if (!is_array($qs)) return $fail('liste illisible : ' . mb_substr($m[0], 0, 160));
    $qs = array_values(array_filter(array_map(fn($q) => is_string($q) ? clean_line($q, 200) : (is_array($q) ? clean_line((string)($q['question'] ?? reset($q)), 200) : ''), $qs)));
    return count($qs) >= 5 ? array_slice($qs, 0, 5) : $fail(count($qs) . ' questions seulement');
}
function suggest_questions(array $cur, array $audit): array {
    $ai = ai_questions($cur, $audit);
    return ['questions' => $ai ?? fallback_questions($cur), 'source' => $ai ? 'ia' : 'secours', 'erreur' => $ai ? '' : ($GLOBALS['ia_err'] ?? 'clé absente')];
}

// ---------- Actions de l'outil (enregistrer, aperçu, envoi) ----------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
    if (!hash_equals($csrf, (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) json_out(['ok' => false, 'message' => 'Session expirée, rechargez la page.'], 403);
    $d = load_demande($pdo, (int)($in['d'] ?? 0));
    if (!$d) json_out(['ok' => false, 'message' => 'Demande introuvable.'], 404);
    if (($in['action'] ?? '') === 'questions') { $sg = suggest_questions($d, json_decode((string)$d['audit'], true) ?: []); json_out(['ok' => true] + $sg); }
    $now = now_paris()->format('Y-m-d H:i:s');
    $etat = json_encode($in['etat'] ?? [], JSON_UNESCAPED_UNICODE);
    if (strlen($etat) > 900000) json_out(['ok' => false, 'message' => 'Les réponses collées sont trop longues.'], 413);
    $up = $pdo->prepare('UPDATE releves SET maj_le = ?, donnees = ? WHERE demande_id = ?');
    $up->execute([$now, $etat, $d['id']]);
    if (!$up->rowCount()) $pdo->prepare('INSERT INTO releves (demande_id, maj_le, donnees) VALUES (?, ?, ?)')->execute([$d['id'], $now, $etat]);
    $action = $in['action'] ?? '';
    if ($action === 'enregistrer') json_out(['ok' => true]);

    $r = is_array($in['r'] ?? null) ? $in['r'] : [];
    $r['questions'] = array_values(array_map(fn($q) => clean_line((string)$q, 300), (array)($r['questions'] ?? [])));
    $contact = ['nom' => $d['nom'], 'conseils' => (int)$d['conseils'], 'jeton' => $d['jeton']];
    $m = releve_mail($r, $contact, $d, today());
    if ($action === 'apercu') json_out(['ok' => true, 'subject' => $m['subject'], 'html' => $m['html']]);
    if ($action === 'envoyer') {
        if ($d['statut'] === 'envoye') json_out(['ok' => false, 'message' => 'Ce relevé a déjà été envoyé.']);
        if (!send_mail_to($d['email'], $m['subject'], $m['text'], $m['html'])) json_out(['ok' => false, 'message' => 'L’e-mail n’est pas parti. Réessayez dans quelques minutes.']);
        if (config()['mail_to']) send_mail_to(config()['mail_to'], 'Copie, ' . $m['subject'], $m['text'], $m['html']);
        $pdo->prepare("UPDATE demandes SET statut = 'envoye', envoye_le = ? WHERE id = ?")->execute([$now, $d['id']]);
        $pdo->prepare('UPDATE contacts SET maj_le = ? WHERE id = ?')->execute([$now, $d['cid']]);
        json_out(['ok' => true, 'message' => 'Relevé envoyé à ' . $d['email'] . '. Une copie vous a été adressée.']);
    }
    json_out(['ok' => false, 'message' => 'Action inconnue.'], 400);
}

// ---------- Liste et demande ouverte ----------
$list = $pdo->query("SELECT d.id, d.cree_le, d.site, d.score, d.statut, d.echeance, d.envoye_le, d.concurrents, c.email FROM demandes d JOIN contacts c ON c.id = d.contact_id WHERE d.type = 'releve' ORDER BY CASE WHEN d.statut = 'envoye' THEN 1 ELSE 0 END, d.echeance, d.cree_le DESC LIMIT 60")->fetchAll();
$openId = (int)($_GET['d'] ?? 0);
if (!$openId && $list && $list[0]['statut'] !== 'envoye') $openId = (int)$list[0]['id'];
$cur = $openId ? load_demande($pdo, $openId) : null;

function days_left(string $ymd): array {
    $today = new DateTime(today()); $end = new DateTime($ymd);
    $n = 0; $d = clone $today; $sign = $end >= $today ? 1 : -1;
    while ($d->format('Y-m-d') !== $end->format('Y-m-d')) { $d->modify($sign > 0 ? '+1 day' : '-1 day'); if ((int)$d->format('N') < 6) $n++; }
    if ($sign < 0) return ['En retard', 'st-ko'];
    if ($n === 0) return ['Aujourd’hui', 'st-ko'];
    if ($n === 1) return ['Demain', 'st-ko'];
    return ['J-' . $n, $n <= 2 ? 'st-warn' : 'st-grey'];
}
$state = null; $audit = [];
if ($cur) {
    $audit = json_decode((string)$cur['audit'], true) ?: [];
    $saved = $pdo->prepare('SELECT donnees FROM releves WHERE demande_id = ?'); $saved->execute([$cur['id']]);
    $state = json_decode((string)$saved->fetchColumn(), true);
    if (!$state) {
        $sg = suggest_questions($cur, $audit);
        $c = split_list($cur['concurrents']);
        $host = host_of($cur['site']);
        $stem = preg_replace('/\.[a-z]{2,}$/', '', $host);
        $brand = implode(' ', array_map(fn($w) => mb_strtoupper(mb_substr($w, 0, 1)) . mb_substr($w, 1), preg_split('/[-_.]+/', $stem)));
        $state = [
            'questions' => $sg['questions'],
            'source' => $sg['source'],
            'client' => ['noms' => $brand, 'domaine' => $host],
            'concurrents' => array_map(fn($x) => ['nom' => $x], $c),
            'reponses' => array_fill(0, 5, ['', '', '']),
            'sansGoogle' => array_fill(0, 5, false),
            'opp' => new stdClass(),
            'commentaire' => '',
            'rappelBloquant' => true,
        ];
        // Enregistré tout de suite, pour ne pas redemander les questions à chaque ouverture
        $pdo->prepare('INSERT INTO releves (demande_id, maj_le, donnees) VALUES (?, ?, ?)')->execute([$cur['id'], now_paris()->format('Y-m-d H:i:s'), json_encode($state, JSON_UNESCAPED_UNICODE)]);
    }
}
$ko = array_values(array_filter(array_merge(...array_map(fn($g) => $g['items'], $audit['groups'] ?? [['items' => []]])), fn($i) => $i['status'] === 'ko'));
$warn = array_values(array_filter(array_merge(...array_map(fn($g) => $g['items'], $audit['groups'] ?? [['items' => []]])), fn($i) => $i['status'] === 'warn'));

page_start('Relevés, tableau de bord Rubriqua', $css . '
.rl{display:grid;grid-template-columns:290px minmax(0,1fr);gap:20px;align-items:start}
.reqs{padding:0;overflow:hidden}.reqs h2{padding:16px 16px 0}
.req{display:flex;flex-direction:column;gap:4px;padding:12px 16px;border-top:1px solid var(--line);text-decoration:none;color:var(--text)}
.req b{color:var(--ink)}.req[aria-current]{background:#f6f3ee;box-shadow:inset 3px 0 0 var(--ink)}.req.done{opacity:.6}
.rq{display:flex;justify-content:space-between;gap:8px;align-items:center}
.main{display:flex;flex-direction:column;gap:20px;min-width:0}
.info{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,1fr);gap:24px}
.info>div+div{border-left:1px solid var(--line);padding-left:24px}
.dl{display:grid;grid-template-columns:150px minmax(0,1fr);gap:6px 14px;margin:0;font-size:14px}.dl dt{color:var(--muted);font-weight:700}.dl dd{margin:0;color:var(--ink);word-break:break-word}
.qs{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px 20px}
.qrow{display:grid;grid-template-columns:28px minmax(0,1fr);gap:10px;align-items:center}
.qn{width:28px;height:28px;border-radius:50%;background:var(--ink);color:#fff;font-weight:800;font-size:13px;display:flex;align-items:center;justify-content:center}
.ents{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}
.ent{display:flex;flex-direction:column;gap:6px;border:1px solid var(--line);border-radius:8px;padding:10px 12px}
.ent .dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px}
.grid3{display:grid;grid-template-columns:190px repeat(3,minmax(0,1fr));gap:10px;align-items:stretch}
.cell{display:flex;flex-direction:column;gap:6px;min-width:0}
.cell textarea{min-height:92px;font-size:13px}
.res{display:flex;flex-wrap:wrap;gap:4px;align-items:center;min-height:22px}
.qh{display:grid;grid-template-columns:28px minmax(0,1fr);gap:8px;align-items:start;font-size:13px;font-weight:700;color:var(--ink)}
.cmp{display:grid;grid-template-columns:170px minmax(0,1fr) 54px;gap:10px;align-items:center;font-size:14px}
.cmp i{display:block;height:12px;border-radius:3px;background:#5c5853}
.opp{display:grid;grid-template-columns:22px 150px minmax(0,1fr);gap:10px 12px;align-items:start;border-top:1px solid var(--line);padding:12px 0}
.opp input[type=text]{font-weight:700}
.acts{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.preview{width:100%;height:900px;border:1px solid var(--line);border-radius:8px;background:#e9e6e0}
.sticky{position:sticky;bottom:0;background:var(--beige);padding:12px 0;border-top:1px solid var(--line)}
@media (max-width:980px){.rl,.info{grid-template-columns:1fr}.info>div+div{border-left:0;padding-left:0}.qs{grid-template-columns:1fr}.grid3{grid-template-columns:1fr}.grid3 .hd{display:none}.opp{grid-template-columns:22px minmax(0,1fr)}.opp .st{grid-column:2}.opp>div{grid-column:2}}
');
?>
<main class="wrap">
  <header class="top">
    <div class="brand"><b>Tableau de bord</b><span>Relevés de citations offerts, à envoyer sous 5 jours ouvrés</span></div>
    <div class="tools">
      <?= tabs($view, $todo) ?>
      <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><button name="logout" value="1">Se déconnecter</button></form>
    </div>
  </header>

  <div class="rl">
    <aside class="card reqs">
      <h2>Demandes</h2>
      <?php if (!$list): ?><p class="empty" style="padding:0 16px 16px">Aucune demande de relevé pour le moment.</p><?php endif; ?>
      <?php foreach ($list as $l): [$lab, $cls] = $l['statut'] === 'envoye' ? ['Envoyé', 'st-ok'] : days_left($l['echeance']); ?>
        <a class="req<?= $l['statut'] === 'envoye' ? ' done' : '' ?>" href="./?v=releves&amp;d=<?= (int)$l['id'] ?>"<?= (int)$l['id'] === $openId ? ' aria-current="page"' : '' ?>>
          <span class="rq"><b><?= h(host_of($l['site'])) ?></b><span class="st <?= $cls ?>"><?= h($lab) ?></span></span>
          <span class="note"><?= $l['statut'] === 'envoye' ? 'Envoyé le ' . h((new DateTime($l['envoye_le']))->format('d/m')) : 'Demandé le ' . h((new DateTime($l['cree_le']))->format('d/m')) . ' · avant le ' . h((new DateTime($l['echeance']))->format('d/m')) ?><br>Audit <?= (int)$l['score'] ?> · <?= $l['concurrents'] !== '' ? count(split_list($l['concurrents'])) . ' concurrent(s) donné(s)' : 'sans concurrent' ?></span>
        </a>
      <?php endforeach; ?>
    </aside>

    <div class="main">
    <?php if (!$cur): ?>
      <section class="card"><p class="empty">Choisissez une demande dans la liste.</p></section>
    <?php else: ?>
      <section class="card info">
        <div style="display:flex;flex-direction:column;gap:10px;min-width:0">
          <span class="lbl">Ce que le client nous a donné</span>
          <h2 style="margin:0;font-size:22px"><?= h(host_of($cur['site'])) ?></h2>
          <dl class="dl">
            <dt>Contact</dt><dd><?= h($cur['email']) ?><?= $cur['nom'] !== '' ? ', ' . h($cur['nom']) : '' ?></dd>
            <dt>Page testée</dt><dd><?= h($cur['site']) ?></dd>
            <dt>Produits ou services</dt><dd><?= h($cur['produits']) ?></dd>
            <dt>Concurrents</dt><dd><?= $cur['concurrents'] !== '' ? h($cur['concurrents']) : '<span class="note">Non renseignés, à repérer dans les réponses</span>' ?></dd>
            <dt>Conseils par e-mail</dt><dd><?= $cur['conseils'] ? 'Oui' : 'Non' ?></dd>
          </dl>
        </div>
        <div style="display:flex;flex-direction:column;gap:10px;min-width:0">
          <span class="lbl">Son audit du <?= h(date_fr_court(substr($cur['cree_le'], 0, 10))) ?></span>
          <div style="display:flex;align-items:baseline;gap:10px"><b style="font-size:30px;color:var(--ink);letter-spacing:-.03em"><?= (int)$cur['score'] ?></b><span class="note">sur 100 · <?= (int)($audit['counts']['ok'] ?? 0) ?> validés, <?= (int)($audit['counts']['warn'] ?? 0) ?> à améliorer, <?= (int)($audit['counts']['ko'] ?? 0) ?> bloquant(s)</span></div>
          <div style="display:flex;flex-direction:column;gap:6px;font-size:14px;color:var(--ink)">
            <?php foreach (array_slice(array_merge($ko, $warn), 0, 4) as $i): ?><span><span class="st <?= $i['status'] === 'ko' ? 'st-ko' : 'st-warn' ?>"><?= $i['status'] === 'ko' ? 'Bloquant' : 'À améliorer' ?></span> <?= h($i['name']) ?></span><?php endforeach; ?>
          </div>
          <span class="note"><?= $cur['statut'] === 'envoye' ? 'Relevé envoyé le ' . h((new DateTime($cur['envoye_le']))->format('d/m/Y à H:i')) . '.' : 'À envoyer avant le ' . h(date_fr($cur['echeance'])) . '.' ?></span>
        </div>
      </section>

      <section class="card" style="display:flex;flex-direction:column;gap:16px">
        <div class="rq" style="flex-wrap:wrap"><h2 style="margin:0">1. Les questions à poser</h2><span class="note" id="qsrc"></span></div>
        <div class="qs" id="qs"></div>
        <div><button type="button" id="regen">Proposer d’autres questions</button></div>
        <div style="display:flex;flex-direction:column;gap:8px"><span class="lbl">Repérés dans les réponses</span><div class="ents" id="ents"></div><div><button type="button" id="addc">Ajouter un concurrent</button></div></div>
      </section>

      <section class="card" style="display:flex;flex-direction:column;gap:14px">
        <div class="rq" style="flex-wrap:wrap"><h2 style="margin:0">2. Les réponses des IA</h2><span class="note">Posez chaque question dans une conversation neuve, sans être connectée si possible, puis collez la réponse avec ses sources.</span></div>
        <div class="grid3" id="grid"></div>
        <span class="note" id="progress"></span>
      </section>

      <section class="card" style="display:flex;flex-direction:column;gap:14px">
        <h2 style="margin:0">3. Les opportunités</h2>
        <div id="cmp" style="display:flex;flex-direction:column;gap:8px"></div>
        <div id="opps"></div>
        <?php if ($ko): ?><label style="display:flex;gap:10px;align-items:flex-start;font-size:14px"><input type="checkbox" id="rappel" style="margin-top:3px"> <span>Rappeler le point bloquant de l’audit : <?= h($ko[0]['name']) ?></span></label><?php endif; ?>
        <label style="display:flex;flex-direction:column;gap:6px"><span class="lbl">Votre commentaire, facultatif</span><textarea id="comment" rows="3" placeholder="Exemple : votre page sur les joints alimentaires est déjà citée par Perplexity, c’est une bonne base."></textarea></label>
      </section>

      <div class="sticky acts">
        <button type="button" class="btn-dark" id="preview">Voir l’aperçu du mail</button>
        <button type="button" class="btn-red" id="send"<?= $cur['statut'] === 'envoye' ? ' disabled' : '' ?>>Envoyer le relevé</button>
        <span class="note" id="status"><?= $cur['statut'] === 'envoye' ? 'Déjà envoyé.' : 'Enregistrement automatique.' ?></span>
      </div>
      <section class="card" id="pv" hidden style="display:flex;flex-direction:column;gap:10px"><b id="pvs" style="color:var(--ink)"></b><iframe class="preview" id="pvf" title="Aperçu du mail" sandbox></iframe></section>
    <?php endif; ?>
    </div>
  </div>
</main>
<?php if ($cur): ?>
<script>
(function () {
  'use strict';
  var D = <?= (int)$cur['id'] ?>, CSRF = <?= json_encode($csrf) ?>, SENT = <?= $cur['statut'] === 'envoye' ? 'true' : 'false' ?>;
  var S = <?= json_encode($state, JSON_UNESCAPED_UNICODE) ?>;
  var BLOQUANT = <?= json_encode($ko ? $ko[0]['note'] : '', JSON_UNESCAPED_UNICODE) ?>;
  var IAS = ['ChatGPT', 'Perplexity', 'Aperçus IA'];
  var COLORS = ['#991915', '#5c5853', '#b0aba3', '#8a4b08', '#2f6b3a'];
  var $ = function (id) { return document.getElementById(id); };
  var esc = function (s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var norm = function (s) { return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); };
  if (S.opp && Array.isArray(S.opp)) S.opp = {};
  if (S.rappelBloquant === undefined) S.rappelBloquant = true;

  // ---------- Lecture d'une réponse ----------
  var DOM_RE = /\b((?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:com|fr|net|org|io|eu|be|ch|ca|de|co|info|biz|pro|shop|store|app|dev|ai|uk|es|it|nl|lu|tech|site|online|gouv\.fr))\b/gi;
  function domains(text) {
    var seen = [], m; DOM_RE.lastIndex = 0;
    while ((m = DOM_RE.exec(text))) { var d = m[1].toLowerCase().replace(/^www\./, ''); if (seen.indexOf(d) < 0) seen.push(d); }
    return seen;
  }
  function entDomain(e) { var v = (e.domaine || e.nom || '').toLowerCase().trim(); var m = v.match(/([a-z0-9-]+\.)+[a-z]{2,}/); return m ? m[0].replace(/^www\./, '') : ''; }
  function entNames(e) { return String(e.noms !== undefined ? e.noms : e.nom || '').split(',').map(function (x) { return x.trim(); }).filter(function (x) { return x && !/\.[a-z]{2,}$/i.test(x); }); }
  function find(ent, text, doms) {
    var d = entDomain(ent);
    if (d) for (var i = 0; i < doms.length; i++) if (doms[i] === d || doms[i].slice(-d.length - 1) === '.' + d) return { cite: true, pos: i + 1 };
    var t = norm(text), names = entNames(ent);
    if (d) names.push(d.replace(/\.[a-z]{2,}$/, '').replace(/-/g, ' '));
    for (var j = 0; j < names.length; j++) if (names[j].length > 2 && t.indexOf(norm(names[j])) >= 0) return { cite: false };
    return null;
  }
  function analyse() {
    var A = { cells: [], filled: 0, total: 0, comp: S.concurrents.map(function () { return 0; }), client: 0, nommes: 0, others: {} };
    var known = [entDomain(S.client)].concat(S.concurrents.map(entDomain)).filter(Boolean);
    for (var q = 0; q < 5; q++) {
      A.cells[q] = [];
      for (var a = 0; a < 3; a++) {
        if (a === 2 && S.sansGoogle[q]) { A.cells[q][a] = { etat: 'sans' }; continue; }
        var txt = S.reponses[q][a] || '';
        if (!txt.trim()) { A.cells[q][a] = null; continue; }
        A.filled++; A.total++;
        var doms = domains(txt), c = find(S.client, txt, doms), comps = [];
        S.concurrents.forEach(function (e, i) { var r = find(e, txt, doms); if (r) { A.comp[i]++; comps.push({ i: i, r: r }); } });
        doms.forEach(function (d) { if (known.some(function (k) { return d === k || d.slice(-k.length - 1) === '.' + k; })) return; A.others[d] = (A.others[d] || 0) + 1; });
        var cell = c ? (c.cite ? { etat: 'cite', pos: c.pos } : { etat: 'nomme' }) : { etat: 'absent' };
        if (c && c.cite) A.client++; else if (c) A.nommes++;
        cell.comps = comps; cell.doms = doms.length;
        A.cells[q][a] = cell;
      }
    }
    return A;
  }
  function opportunities(A) {
    var list = [];
    var short = function (q) { return S.questions[q].replace(/\s*\?$/, ''); };
    for (var q = 0; q < 5; q++) {
      var row = A.cells[q].filter(Boolean).filter(function (c) { return c.etat !== 'sans'; });
      if (row.length < 2) continue;
      var cite = row.filter(function (c) { return c.etat === 'cite'; }).length, nom = row.filter(function (c) { return c.etat === 'nomme'; }).length;
      var compCount = {}; row.forEach(function (c) { (c.comps || []).forEach(function (x) { if (x.r.cite || true) compCount[x.i] = (compCount[x.i] || 0) + 1; }); });
      var best = Object.keys(compCount).sort(function (x, y) { return compCount[y] - compCount[x]; })[0];
      var iasNomme = []; A.cells[q].forEach(function (c, a) { if (c && c.etat === 'nomme') iasNomme.push(IAS[a]); });
      if (!cite && best !== undefined) list.push({ id: 'devant' + q, type: 'devant', titre: short(q), texte: (S.concurrents[best].nom || 'Un concurrent') + ' est cité par ' + compCount[best] + ' IA sur cette question, votre site non. Une page qui répond précisément à cette question peut changer la donne.' });
      else if (!cite && !nom) list.push({ id: 'place' + q, type: 'place', titre: short(q), texte: 'Ni vous ni vos concurrents n’êtes cités sur cette question. Les IA s’appuient sur d’autres sources. Une page claire sur ce sujet peut devenir leur source.' });
      else if (!cite && nom) list.push({ id: 'presque' + q, type: 'presque', titre: short(q), texte: iasNomme.join(' et ') + (iasNomme.length > 1 ? ' vous nomment' : ' vous nomme') + ' sans lien vers votre site. Une page plus précise sur ce sujet peut en faire une source citée.' });
    }
    Object.keys(A.others).filter(function (d) { return A.others[d] >= 3; }).sort(function (x, y) { return A.others[y] - A.others[x]; }).slice(0, 2).forEach(function (d) {
      list.push({ id: 'tierce' + d, type: 'tierce', titre: 'Être présent sur ' + d, texte: d + ' est cité dans ' + A.others[d] + ' réponses. Les IA s’en servent comme source sur vos sujets : y figurer augmente vos chances d’être repris.' });
    });
    var rank = { place: 0, devant: 1, presque: 2, tierce: 3 };
    list.sort(function (x, y) { return rank[x.type] - rank[y.type]; });
    var kept = 0;
    list.forEach(function (o) {
      var s = S.opp[o.id];
      if (s) { o.titre = s.titre; o.texte = s.texte; o.garder = s.garder; }
      else { o.garder = kept < 3; }
      if (o.garder) kept++;
    });
    return list;
  }

  // ---------- Affichage ----------
  var TAGS = { cite: ['st-ok', 'Client, source '], nomme: ['st-warn', 'Nommé sans lien'], absent: ['st-ko', 'Client absent'], sans: ['st-grey', 'Sans Aperçu IA'] };
  var OPPL = { place: ['st-grey', 'Place à prendre'], devant: ['st-warn', 'Concurrent devant'], presque: ['st-ok', 'Presque cité'], tierce: ['st-grey', 'Source tierce'] };

  function drawQuestions() {
    $('qs').innerHTML = S.questions.map(function (q, i) { return '<label class="qrow"><span class="qn">' + (i + 1) + '</span><input type="text" data-q="' + i + '" value="' + esc(q) + '"></label>'; }).join('');
  }
  function drawEnts() {
    var o = '<div class="ent"><b><span class="dot" style="background:' + COLORS[0] + '"></span>Le client</b><input type="text" data-cl="noms" value="' + esc(S.client.noms) + '" placeholder="Noms de la marque, séparés par une virgule"><input type="text" data-cl="domaine" value="' + esc(S.client.domaine) + '" placeholder="domaine.fr"></div>';
    S.concurrents.forEach(function (c, i) {
      o += '<div class="ent"><b><span class="dot" style="background:' + COLORS[(i + 1) % COLORS.length] + '"></span>Concurrent ' + (i + 1) + '</b><input type="text" data-co="' + i + '" value="' + esc(c.nom) + '" placeholder="Nom ou site"><button type="button" data-rm="' + i + '">Retirer</button></div>';
    });
    $('ents').innerHTML = o;
  }
  function drawGrid() {
    var o = '<span class="hd"></span>' + IAS.map(function (n) { return '<span class="lbl hd">' + n + '</span>'; }).join('');
    for (var q = 0; q < 5; q++) {
      o += '<div class="qh"><span class="qn">' + (q + 1) + '</span><span data-qt="' + q + '">' + esc(S.questions[q]) + '</span></div>';
      for (var a = 0; a < 3; a++) {
        o += '<div class="cell"><span class="lbl" style="display:none">' + IAS[a] + '</span>'
          + '<textarea data-r="' + q + '-' + a + '" placeholder="Collez la réponse de ' + IAS[a] + ' avec ses sources"' + (a === 2 && S.sansGoogle[q] ? ' disabled' : '') + '>' + esc(S.reponses[q][a] || '') + '</textarea>'
          + (a === 2 ? '<label class="note" style="display:flex;gap:6px;align-items:center"><input type="checkbox" data-sg="' + q + '"' + (S.sansGoogle[q] ? ' checked' : '') + '> Pas d’Aperçu IA affiché</label>' : '')
          + '<div class="res" data-res="' + q + '-' + a + '"></div></div>';
      }
    }
    $('grid').innerHTML = o;
    if (matchMedia('(max-width:980px)').matches) document.querySelectorAll('.cell .lbl').forEach(function (l) { l.style.display = ''; });
  }
  var A, OPPS = [];
  function refresh() {
    A = analyse();
    for (var q = 0; q < 5; q++) for (var a = 0; a < 3; a++) {
      var c = A.cells[q][a], el = document.querySelector('[data-res="' + q + '-' + a + '"]');
      if (!el) continue;
      if (!c) { el.innerHTML = ''; continue; }
      var t = TAGS[c.etat], h = '<span class="st ' + t[0] + '">' + t[1] + (c.etat === 'cite' ? c.pos : '') + '</span>';
      (c.comps || []).forEach(function (x) { h += ' <span class="note">' + esc(S.concurrents[x.i].nom) + (x.r.cite ? ' source ' + x.r.pos : ' nommé') + '</span>'; });
      if (c.etat !== 'sans' && !(c.comps || []).length && c.doms === 0) h += ' <span class="note">Aucune source repérée</span>';
      el.innerHTML = h;
    }
    var expected = 15 - S.sansGoogle.filter(Boolean).length;
    $('progress').textContent = 'Avancement : ' + A.filled + ' réponses sur ' + expected + '.';
    var max = Math.max(1, A.total);
    var rows = [{ nom: 'Le client', n: A.client, client: true, col: COLORS[0] }].concat(S.concurrents.map(function (c, i) { return { nom: c.nom, n: A.comp[i], col: COLORS[(i + 1) % COLORS.length] }; })).filter(function (r) { return r.nom; });
    rows.sort(function (x, y) { return y.n - x.n; });
    $('cmp').innerHTML = '<span class="lbl">Qui les IA citent, sur ' + A.total + ' réponses</span>' + rows.map(function (r) {
      return '<div class="cmp"><span' + (r.client ? ' style="color:#991915;font-weight:800"' : '') + '>' + esc(r.nom) + '</span><span><i style="width:' + Math.max(2, Math.round(r.n / max * 100)) + '%;background:' + r.col + '"></i></span><b>' + r.n + '/' + A.total + '</b></div>';
    }).join('') + (A.nommes ? '<span class="note">Le client est aussi nommé sans lien dans ' + A.nommes + ' réponse(s).</span>' : '');
    OPPS = opportunities(A);
    $('opps').innerHTML = OPPS.length ? OPPS.map(function (o, i) {
      var l = OPPL[o.type];
      return '<div class="opp"><input type="checkbox" data-og="' + i + '"' + (o.garder ? ' checked' : '') + ' aria-label="Garder dans le mail"><span class="st ' + l[0] + '">' + l[1] + '</span><div style="display:flex;flex-direction:column;gap:6px"><input type="text" data-ot="' + i + '" value="' + esc(o.titre) + '"><textarea data-ox="' + i + '" rows="2">' + esc(o.texte) + '</textarea></div></div>';
    }).join('') : '<p class="note">Les opportunités apparaissent quand au moins deux réponses par question sont collées.</p>';
    $('send').disabled = SENT || A.filled < expected;
  }

  // ---------- Enregistrement ----------
  var timer;
  function payload() {
    var cmp = [{ nom: 'Votre site', n: A.client, client: true }].concat(S.concurrents.map(function (c, i) { return { nom: c.nom, n: A.comp[i] }; }).filter(function (c) { return c.nom; }));
    cmp.sort(function (x, y) { return y.n - x.n; });
    return {
      questions: S.questions, total: A.total, nommes: A.nommes, comparaison: cmp,
      cellules: A.cells.map(function (r) { return r.map(function (c) { return c ? { etat: c.etat, pos: c.pos || null } : { etat: 'absent' }; }); }),
      opportunites: OPPS.map(function (o) { return { type: o.type, titre: o.titre, texte: o.texte, garder: !!o.garder }; }),
      commentaire: S.commentaire, bloquant: (S.rappelBloquant && BLOQUANT) ? BLOQUANT : ''
    };
  }
  function call(action) {
    return fetch('./?v=releves', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': CSRF }, body: JSON.stringify({ action: action, d: D, etat: S, r: payload() }) })
      .then(function (r) { return r.json(); }).catch(function () { return { ok: false, message: 'Connexion perdue. Vos dernières modifications ne sont peut-être pas enregistrées.' }; });
  }
  function save() { clearTimeout(timer); timer = setTimeout(function () { call('enregistrer').then(function (r) { if (!SENT) $('status').textContent = r.ok ? 'Enregistré.' : r.message; }); }, 700); if (!SENT) $('status').textContent = 'Enregistrement…'; }

  document.addEventListener('input', function (e) {
    var t = e.target, d = t.dataset;
    if (d.q !== undefined) { S.questions[+d.q] = t.value; var qt = document.querySelector('[data-qt="' + d.q + '"]'); if (qt) qt.textContent = t.value; }
    else if (d.cl) S.client[d.cl] = t.value;
    else if (d.co !== undefined) S.concurrents[+d.co].nom = t.value;
    else if (d.r) { var p = d.r.split('-'); S.reponses[+p[0]][+p[1]] = t.value; }
    else if (d.ot !== undefined) { var o = OPPS[+d.ot]; o.titre = t.value; S.opp[o.id] = { titre: o.titre, texte: o.texte, garder: o.garder }; save(); return; }
    else if (d.ox !== undefined) { var o2 = OPPS[+d.ox]; o2.texte = t.value; S.opp[o2.id] = { titre: o2.titre, texte: o2.texte, garder: o2.garder }; save(); return; }
    else if (t.id === 'comment') { S.commentaire = t.value; save(); return; }
    else return;
    refresh(); save();
  });
  document.addEventListener('change', function (e) {
    var t = e.target, d = t.dataset;
    if (d.sg !== undefined) { S.sansGoogle[+d.sg] = t.checked; var ta = document.querySelector('[data-r="' + d.sg + '-2"]'); ta.disabled = t.checked; refresh(); save(); }
    else if (d.og !== undefined) { var o = OPPS[+d.og]; o.garder = t.checked; S.opp[o.id] = { titre: o.titre, texte: o.texte, garder: o.garder }; save(); }
    else if (t.id === 'rappel') { S.rappelBloquant = t.checked; save(); }
  });
  document.addEventListener('click', function (e) {
    var t = e.target;
    if (t.dataset && t.dataset.rm !== undefined) { S.concurrents.splice(+t.dataset.rm, 1); drawEnts(); refresh(); save(); }
  });
  $('addc').addEventListener('click', function () { S.concurrents.push({ nom: '' }); drawEnts(); var ins = document.querySelectorAll('[data-co]'); ins[ins.length - 1].focus(); });
  $('preview').addEventListener('click', function () {
    call('apercu').then(function (r) {
      if (!r.ok) { $('status').textContent = r.message; return; }
      $('pv').hidden = false; $('pvs').textContent = 'Objet : ' + r.subject; $('pvf').srcdoc = r.html; $('pv').scrollIntoView({ behavior: 'smooth' });
    });
  });
  $('send').addEventListener('click', function () {
    if (!confirm('Envoyer le relevé à <?= h($cur['email']) ?> ? Le mail part tout de suite.')) return;
    $('send').disabled = true; $('status').textContent = 'Envoi…';
    call('envoyer').then(function (r) { $('status').textContent = r.message; if (r.ok) { SENT = true; } else { $('send').disabled = false; } });
  });

  function srcNote() { $('qsrc').textContent = S.source === 'ia' ? 'Rédigées par l’IA à partir de la page testée et de ce que le client a donné. Relisez-les avant de les poser.' : (S.source === 'secours' ? 'Questions génériques : la rédaction par l’IA n’est pas disponible. Adaptez-les à l’activité du site.' : 'Modifiez-les avant de les poser.'); }
  $('regen').addEventListener('click', function () {
    var b = $('regen'); b.disabled = true; b.textContent = 'Rédaction…';
    fetch('./?v=releves', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': CSRF }, body: JSON.stringify({ action: 'questions', d: D }) })
      .then(function (r) { return r.json(); }).catch(function () { return { ok: false }; })
      .then(function (r) {
        b.disabled = false; b.textContent = 'Proposer d’autres questions';
        if (!r.ok) { $('status').textContent = 'Impossible de proposer d’autres questions pour le moment.'; return; }
        S.questions = r.questions; S.source = r.source; drawQuestions(); srcNote(); if (r.erreur) $('qsrc').textContent += ' Détail : ' + r.erreur;
        for (var q = 0; q < 5; q++) { var qt = document.querySelector('[data-qt="' + q + '"]'); if (qt) qt.textContent = S.questions[q]; }
        refresh(); save();
      });
  });
  $('comment').value = S.commentaire || '';
  if ($('rappel')) $('rappel').checked = S.rappelBloquant !== false;
  drawQuestions(); srcNote(); drawEnts(); drawGrid(); refresh();
})();
</script>
<?php endif; ?>
</body></html>
