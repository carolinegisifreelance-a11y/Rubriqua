<?php
// Rubriqua, tableau de bord privé : audience du site et demandes de contact.
define('RUBRIQUA', true);
require __DIR__ . '/../api/lib.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

$https = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('rb_admin');
session_set_cookie_params(['lifetime' => 0, 'path' => '/pilotage-rb7k2q/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function nf($n): string { return number_format((float)$n, 0, ',', "\u{202F}"); }

$hash = config()['dashboard_hash'];
$plain = config()['dashboard_password'];
function check_password(string $given, string $hash, string $plain): bool {
    if ($hash !== '') return password_verify($given, $hash);
    return $plain !== '' && strlen($plain) >= 12 && hash_equals($plain, $given);
}
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) { $error = 'Session expirée, merci de réessayer.'; }
    elseif (isset($_POST['logout'])) { $_SESSION = []; session_destroy(); header('Location: ./', true, 303); exit; }
    elseif (isset($_POST['password'])) {
        try { $limited = rate_limited(db(), 'login', 5, 900); } catch (Throwable $e) { $limited = false; }
        if ($limited) { $error = 'Trop de tentatives. Réessayez dans 15 minutes.'; }
        elseif (check_password((string)$_POST['password'], $hash, $plain)) {
            session_regenerate_id(true);
            $_SESSION['ok'] = true;
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
            header('Location: ./', true, 303); exit;
        } else { usleep(600000); $error = 'Mot de passe incorrect.'; }
    }
}

$logged = !empty($_SESSION['ok']);
$css = <<<CSS
@font-face{font-family:Manrope;src:url(/assets/fonts/manrope-latin-wght-normal.woff2) format("woff2");font-weight:200 800;font-display:swap}
:root{--red:#991915;--red-light:#EF8D86;--ink:#1C1C1C;--text:#3b3936;--muted:#77726b;--line:#e4e0d9;--beige:#F4F2EE;--white:#fff;--ok:#2f6b3a}
*{box-sizing:border-box}
body{margin:0;font-family:Manrope,system-ui,-apple-system,"Segoe UI",sans-serif;background:var(--beige);color:var(--text);font-size:15px;line-height:1.5}
a{color:var(--red)}
.wrap{max-width:1200px;margin:0 auto;padding-block:32px 64px;padding-left:max(16px,env(safe-area-inset-left,0px));padding-right:max(16px,env(safe-area-inset-right,0px));display:flex;flex-direction:column;gap:24px}
.top{display:flex;flex-wrap:wrap;gap:16px;justify-content:space-between;align-items:center}
.brand{display:flex;flex-direction:column;gap:2px}
.brand b{font-size:24px;color:var(--ink);letter-spacing:-.02em}
.brand span{color:var(--muted);font-size:14px}
.tools{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.seg{display:inline-flex;background:var(--white);border:1px solid var(--line);border-radius:6px;padding:3px}
.seg a{padding:7px 12px;border-radius:4px;text-decoration:none;color:var(--text);font-weight:700;font-size:13px}
.seg a[aria-current]{background:var(--ink);color:var(--white)}
button,.btn{font:inherit;font-weight:700;font-size:13px;border:1px solid var(--line);background:var(--white);color:var(--ink);border-radius:6px;padding:8px 12px;cursor:pointer}
.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px}
.card{background:var(--white);border:1px solid var(--line);border-radius:10px;padding:20px;min-width:0}
.kpi{display:flex;flex-direction:column;gap:4px}
.kpi span{font-size:13px;color:var(--muted);font-weight:600}
.kpi b{font-size:32px;color:var(--ink);letter-spacing:-.03em;line-height:1.1}
.kpi small{font-size:13px;font-weight:700}
.up{color:var(--ok)}.down{color:var(--red)}.flat{color:var(--muted)}
h2{font-size:17px;color:var(--ink);margin:0 0 14px;letter-spacing:-.01em}
.grid2{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:12px}
table{width:100%;border-collapse:collapse;font-size:14px}
th{text-align:left;color:var(--muted);font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.06em;padding:0 0 8px}
td{padding:8px 0;border-top:1px solid var(--line);vertical-align:top}
td.n,th.n{text-align:right;white-space:nowrap;padding-left:12px}
td.p{word-break:break-all}
.bar{height:6px;background:var(--beige);border-radius:3px;margin-top:6px;overflow:hidden}
.bar i{display:block;height:100%;background:var(--ink);border-radius:3px}
.ia .bar i{background:var(--red)}
.tag{display:inline-block;font-size:11px;font-weight:800;color:var(--red);border:1px solid var(--red-light);border-radius:4px;padding:0 5px;margin-left:6px;vertical-align:1px}
.chart{overflow-x:auto}
.chart svg{display:block;width:100%;height:auto;min-width:520px}
.legend{display:flex;gap:16px;font-size:13px;color:var(--muted);margin-bottom:10px}
.legend i{display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:6px;vertical-align:-1px}
.msg{display:flex;flex-direction:column;gap:6px;padding:14px 0;border-top:1px solid var(--line)}
.msg:first-of-type{border-top:0;padding-top:0}
.msg-head{display:flex;flex-wrap:wrap;gap:6px 14px;align-items:baseline}
.msg-head b{color:var(--ink)}
.msg-head span{color:var(--muted);font-size:13px}
.msg p{margin:0;white-space:pre-wrap;word-break:break-word}
.warn{color:var(--red);font-size:12px;font-weight:700}
.empty{color:var(--muted)}
.login{min-height:100vh;display:grid;place-items:center;padding-left:max(16px,env(safe-area-inset-left,0px));padding-right:max(16px,env(safe-area-inset-right,0px))}
.login form{width:100%;max-width:360px;display:flex;flex-direction:column;gap:14px}
.login input{font:inherit;padding:12px 14px;border:1px solid var(--line);border-radius:6px;width:100%}
.login button{background:var(--red);color:var(--white);border-color:var(--red);padding:12px}
.err{color:var(--red);font-weight:700;margin:0}
.note{font-size:13px;color:var(--muted)}
CSS;

function page_start(string $title, string $css): void {
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<meta name="robots" content="noindex, nofollow"><title>' . h($title) . '</title><style>' . $css . '</style></head><body>';
}

if (!$logged) {
    page_start('Tableau de bord Rubriqua', $css);
    echo '<main class="login"><form method="post" class="card"><div class="brand"><b>Tableau de bord</b><span>Accès réservé à Rubriqua</span></div>';
    if (!$hash && strlen($plain) < 12) echo '<p class="err">Mot de passe non configuré. Voir docs/mise-en-ligne.md.</p>';
    if ($error) echo '<p class="err">' . h($error) . '</p>';
    echo '<input type="hidden" name="csrf" value="' . h($csrf) . '"><label class="note">Mot de passe<input type="password" name="password" autocomplete="current-password" required autofocus></label>'
       . '<button type="submit">Se connecter</button></form></main></body></html>';
    exit;
}

// Période
$periods = [7 => '7 jours', 30 => '30 jours', 90 => '90 jours', 365 => '12 mois'];
$days = (int)($_GET['p'] ?? 30);
if (!isset($periods[$days])) $days = 30;
$end = now_paris();
$start = (clone $end)->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
$prevStart = (clone $end)->modify('-' . (2 * $days - 1) . ' days')->format('Y-m-d');
$prevEnd = (clone $end)->modify('-' . $days . ' days')->format('Y-m-d');

$pdo = db();
$one = function (string $sql, array $args) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($args); return (int)$st->fetchColumn(); };
$all = function (string $sql, array $args) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($args); return $st->fetchAll(); };

$kpi = function (string $from, string $to) use ($one) {
    $views = $one('SELECT COUNT(*) FROM visites WHERE jour BETWEEN ? AND ?', [$from, $to]);
    $visitors = $one('SELECT COUNT(*) FROM (SELECT DISTINCT jour, visiteur FROM visites WHERE jour BETWEEN ? AND ?) t', [$from, $to]);
    $ai = $one("SELECT COUNT(*) FROM (SELECT DISTINCT jour, visiteur FROM visites WHERE jour BETWEEN ? AND ? AND source IN ('ChatGPT','Perplexity','Copilot','Gemini','Claude','Mistral')) t", [$from, $to]);
    $leads = $one('SELECT COUNT(*) FROM messages WHERE cree_le BETWEEN ? AND ?', [$from . ' 00:00:00', $to . ' 23:59:59']);
    return ['views' => $views, 'visitors' => $visitors, 'ai' => $ai, 'leads' => $leads, 'rate' => $visitors ? $leads / $visitors * 100 : 0];
};
$cur = $kpi($start, $end->format('Y-m-d'));
$prev = $kpi($prevStart, $prevEnd);

function delta($a, $b, bool $points = false): string {
    if ($points) {
        $d = $a - $b;
        if (abs($d) < 0.05) return '<small class="flat">stable</small>';
        return '<small class="' . ($d > 0 ? 'up' : 'down') . '">' . ($d > 0 ? '+' : '') . number_format($d, 1, ',', '') . ' pt</small>';
    }
    if ($b == 0) return $a > 0 ? '<small class="up">nouveau</small>' : '<small class="flat">aucune donnée avant</small>';
    $d = ($a - $b) / $b * 100;
    if (abs($d) < 0.5) return '<small class="flat">stable</small>';
    return '<small class="' . ($d > 0 ? 'up' : 'down') . '">' . ($d > 0 ? '+' : '') . number_format($d, 0, ',', '') . '% sur la période précédente</small>';
}

// Série par jour, ou par mois sur 12 mois
$byMonth = $days > 90;
$rows = $all('SELECT jour, COUNT(*) AS vues, COUNT(DISTINCT visiteur) AS visiteurs FROM visites WHERE jour BETWEEN ? AND ? GROUP BY jour', [$start, $end->format('Y-m-d')]);
$series = [];
$cursor = new DateTime($start, new DateTimeZone('Europe/Paris'));
while ($cursor <= $end) {
    $k = $byMonth ? $cursor->format('Y-m') : $cursor->format('Y-m-d');
    $series[$k] ??= ['vues' => 0, 'visiteurs' => 0];
    $cursor->modify('+1 day');
}
foreach ($rows as $r) {
    $k = $byMonth ? substr($r['jour'], 0, 7) : $r['jour'];
    if (isset($series[$k])) { $series[$k]['vues'] += $r['vues']; $series[$k]['visiteurs'] += $r['visiteurs']; }
}

$months = ['01'=>'janv.','02'=>'févr.','03'=>'mars','04'=>'avr.','05'=>'mai','06'=>'juin','07'=>'juil.','08'=>'août','09'=>'sept.','10'=>'oct.','11'=>'nov.','12'=>'déc.'];
function chart(array $series, bool $byMonth, array $months): string {
    $w = 1000; $hgt = 240; $pl = 36; $pb = 26; $pt = 10;
    $max = max(1, max(array_map(fn($s) => $s['vues'], $series)));
    $nice = (int)ceil($max / 4) * 4 ?: 4;
    $n = count($series); $slot = ($w - $pl) / max(1, $n); $bw = max(2, $slot * 0.62);
    $out = '<svg viewBox="0 0 ' . $w . ' ' . $hgt . '" role="img" aria-label="Pages vues et visiteurs par ' . ($byMonth ? 'mois' : 'jour') . '">';
    for ($i = 0; $i <= 4; $i++) {
        $y = $pt + ($hgt - $pt - $pb) * (1 - $i / 4);
        $out .= '<line x1="' . $pl . '" x2="' . $w . '" y1="' . $y . '" y2="' . $y . '" stroke="#e4e0d9"/>'
              . '<text x="' . ($pl - 8) . '" y="' . ($y + 4) . '" text-anchor="end" font-size="11" fill="#77726b">' . nf($nice * $i / 4) . '</text>';
    }
    $i = 0; $every = max(1, (int)ceil($n / 10));
    foreach ($series as $k => $s) {
        $x = $pl + $i * $slot + ($slot - $bw) / 2;
        $hv = ($hgt - $pt - $pb) * $s['vues'] / $nice;
        $hu = ($hgt - $pt - $pb) * $s['visiteurs'] / $nice;
        $base = $hgt - $pb;
        $label = $byMonth ? $months[substr($k, 5, 2)] : (int)substr($k, 8, 2) . ' ' . $months[substr($k, 5, 2)];
        $out .= '<g><title>' . h($label) . ' : ' . nf($s['visiteurs']) . ' visiteurs, ' . nf($s['vues']) . ' pages vues</title>'
              . '<rect x="' . round($x, 1) . '" y="' . round($base - $hv, 1) . '" width="' . round($bw, 1) . '" height="' . round($hv, 1) . '" rx="2" fill="#EF8D86" opacity=".45"/>'
              . '<rect x="' . round($x, 1) . '" y="' . round($base - $hu, 1) . '" width="' . round($bw, 1) . '" height="' . round($hu, 1) . '" rx="2" fill="#991915"/></g>';
        if ($i % $every === 0) $out .= '<text x="' . round($x + $bw / 2, 1) . '" y="' . ($hgt - 8) . '" text-anchor="middle" font-size="11" fill="#77726b">' . h($label) . '</text>';
        $i++;
    }
    return $out . '</svg>';
}

$endDay = $end->format('Y-m-d');
$cat = is_sqlite($pdo) ? 'jour || visiteur' : 'CONCAT(jour, visiteur)';
$pages = $all('SELECT page, COUNT(*) AS vues, COUNT(DISTINCT ' . $cat . ') AS visiteurs FROM visites WHERE jour BETWEEN ? AND ? GROUP BY page ORDER BY vues DESC LIMIT 15', [$start, $endDay]);
$sources = $all("SELECT source, COUNT(*) AS visiteurs FROM (SELECT DISTINCT jour, visiteur, source FROM visites WHERE jour BETWEEN ? AND ? AND source <> 'Interne') t GROUP BY source ORDER BY visiteurs DESC", [$start, $endDay]);
$devices = $all('SELECT appareil, COUNT(*) AS visiteurs FROM (SELECT DISTINCT jour, visiteur, appareil FROM visites WHERE jour BETWEEN ? AND ?) t GROUP BY appareil ORDER BY visiteurs DESC', [$start, $endDay]);
$refs = $all("SELECT referent, COUNT(*) AS visiteurs FROM (SELECT DISTINCT jour, visiteur, referent FROM visites WHERE jour BETWEEN ? AND ? AND referent <> '') t GROUP BY referent ORDER BY visiteurs DESC LIMIT 10", [$start, $endDay]);
$leadPages = $all("SELECT page, COUNT(*) AS n FROM messages WHERE cree_le BETWEEN ? AND ? AND page <> '' GROUP BY page ORDER BY n DESC LIMIT 5", [$start . ' 00:00:00', $endDay . ' 23:59:59']);
$messages = $all('SELECT * FROM messages ORDER BY cree_le DESC LIMIT 30', []);
$aiNames = ['ChatGPT','Perplexity','Copilot','Gemini','Claude','Mistral'];

function table(array $rows, string $labelKey, string $labelHead, string $valKey, string $valHead, array $ia = [], bool $path = false): string {
    if (!$rows) return '<p class="empty">Pas encore de données sur cette période.</p>';
    $max = max(array_map(fn($r) => (int)$r[$valKey], $rows)) ?: 1;
    $o = '<table><thead><tr><th>' . h($labelHead) . '</th><th class="n">' . h($valHead) . '</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $isIa = in_array($r[$labelKey], $ia, true);
        $o .= '<tr' . ($isIa ? ' class="ia"' : '') . '><td' . ($path ? ' class="p"' : '') . '>' . h($r[$labelKey]) . ($isIa ? '<span class="tag">IA</span>' : '')
            . '<div class="bar"><i style="width:' . round($r[$valKey] / $max * 100) . '%"></i></div></td><td class="n">' . nf($r[$valKey]) . '</td></tr>';
    }
    return $o . '</tbody></table>';
}

page_start('Tableau de bord Rubriqua', $css);
?>
<main class="wrap">
  <header class="top">
    <div class="brand"><b>Tableau de bord</b><span>rubriqua.com, du <?= h((new DateTime($start))->format('d/m/Y')) ?> au <?= h($end->format('d/m/Y')) ?></span></div>
    <div class="tools">
      <nav class="seg" aria-label="Période"><?php foreach ($periods as $d => $l): ?><a href="?p=<?= $d ?>"<?= $d === $days ? ' aria-current="page"' : '' ?>><?= h($l) ?></a><?php endforeach; ?></nav>
      <button type="button" id="ignore">Ne pas compter mes visites</button>
      <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><button name="logout" value="1">Se déconnecter</button></form>
    </div>
  </header>

  <section class="kpis">
    <div class="card kpi"><span>Visiteurs</span><b><?= nf($cur['visitors']) ?></b><?= delta($cur['visitors'], $prev['visitors']) ?></div>
    <div class="card kpi"><span>Pages vues</span><b><?= nf($cur['views']) ?></b><?= delta($cur['views'], $prev['views']) ?></div>
    <div class="card kpi"><span>Visiteurs venus des IA</span><b><?= nf($cur['ai']) ?></b><?= delta($cur['ai'], $prev['ai']) ?></div>
    <div class="card kpi"><span>Demandes de contact</span><b><?= nf($cur['leads']) ?></b><?= delta($cur['leads'], $prev['leads']) ?></div>
    <div class="card kpi"><span>Taux de contact</span><b><?= number_format($cur['rate'], 1, ',', '') ?>%</b><?= delta($cur['rate'], $prev['rate'], true) ?></div>
  </section>

  <section class="card">
    <h2>Fréquentation par <?= $byMonth ? 'mois' : 'jour' ?></h2>
    <div class="legend"><span><i style="background:#991915"></i>Visiteurs</span><span><i style="background:#EF8D86;opacity:.45"></i>Pages vues</span></div>
    <div class="chart"><?= chart($series, $byMonth, $months) ?></div>
  </section>

  <div class="grid2">
    <section class="card"><h2>Pages les plus vues</h2><?= table($pages, 'page', 'Page', 'vues', 'Vues', [], true) ?></section>
    <section class="card"><h2>Sources de trafic</h2><?= table($sources, 'source', 'Source', 'visiteurs', 'Visiteurs', $aiNames) ?></section>
    <section class="card"><h2>Appareils</h2><?= table($devices, 'appareil', 'Appareil', 'visiteurs', 'Visiteurs') ?></section>
    <section class="card"><h2>Autres sites qui vous envoient du trafic</h2><?= table($refs, 'referent', 'Site', 'visiteurs', 'Visiteurs', [], true) ?></section>
    <section class="card"><h2>Pages d’où partent les demandes</h2><?= table($leadPages, 'page', 'Page', 'n', 'Demandes', [], true) ?></section>
  </div>

  <section class="card">
    <h2>Dernières demandes de contact</h2>
    <?php if (!$messages): ?><p class="empty">Aucune demande pour le moment.</p><?php endif; ?>
    <?php foreach ($messages as $m): ?>
      <article class="msg">
        <div class="msg-head"><b><?= h($m['nom']) ?></b><span><?= h($m['email']) ?></span><?php if ($m['site']): ?><span><?= h($m['site']) ?></span><?php endif; ?><span><?= h((new DateTime($m['cree_le']))->format('d/m/Y à H:i')) ?></span><?php if (!$m['envoye']): ?><span class="warn">E-mail non envoyé</span><?php endif; ?></div>
        <?php if ($m['message'] !== ''): ?><p><?= h($m['message']) ?></p><?php endif; ?>
      </article>
    <?php endforeach; ?>
  </section>

  <p class="note">Mesure sans cookie. Un visiteur correspond à un appareil sur une journée : la même personne revenue trois jours compte pour trois. Les robots et vos propres visites, une fois exclues avec le bouton du haut, ne sont pas comptés. Données conservées 25 mois.</p>
</main>
<script>
(function () {
  var b = document.getElementById('ignore');
  function on() { try { return localStorage.getItem('rb_ignore') === '1'; } catch (e) { return false; } }
  function paint() { b.textContent = on() ? 'Mes visites sont exclues' : 'Ne pas compter mes visites'; }
  b.addEventListener('click', function () {
    try { on() ? localStorage.removeItem('rb_ignore') : localStorage.setItem('rb_ignore', '1'); } catch (e) {}
    paint();
  });
  paint();
})();
</script>
</body></html>
