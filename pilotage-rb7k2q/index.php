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
function logout(): void { $_SESSION = []; session_destroy(); header('Location: ./', true, 303); exit; }
$error = '';
$pdo = db();

// Réinitialisation de la double authentification, demandée depuis GitHub (action reinitialiser-2fa)
$resetFlag = dirname(config()['sqlite_path']) . '/.reinitialiser-2fa';
if (is_file($resetFlag)) { setting_delete($pdo, 'totp_secret'); setting_delete($pdo, 'totp_last'); @unlink($resetFlag); }
$secret = setting_get($pdo, 'totp_secret');
// Remise à zéro des statistiques de visite, demandée depuis GitHub (action vider-statistiques). Les demandes de contact sont conservées.
$wipeFlag = dirname(config()['sqlite_path']) . '/.vider-statistiques';
if (is_file($wipeFlag)) { $pdo->exec('DELETE FROM visites'); $pdo->exec('DELETE FROM clics'); $pdo->exec('DELETE FROM compteurs'); @unlink($wipeFlag); }

// Session : 2 heures d'inactivité ou 12 heures au total, puis nouvelle connexion
if (!empty($_SESSION['ok']) && (time() - ($_SESSION['t'] ?? 0) > 7200 || time() - ($_SESSION['since'] ?? 0) > 43200)) logout();
if (!empty($_SESSION['ok'])) $_SESSION['t'] = time();
// Étape 2 : 5 minutes pour saisir le code après le mot de passe
$step2 = !empty($_SESSION['pw']) && time() - $_SESSION['pw'] < 300;
if (!$step2) unset($_SESSION['pw'], $_SESSION['totp_pending']);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) { $error = 'Session expirée, merci de réessayer.'; }
    elseif (isset($_POST['logout'])) { logout(); }
    elseif (isset($_POST['password']) && !$step2) {
        try { $limited = rate_limited($pdo, 'login', 5, 900); } catch (Throwable $e) { $limited = false; }
        if ($limited) { $error = 'Trop de tentatives. Réessayez dans 15 minutes.'; }
        elseif (check_password((string)$_POST['password'], $hash, $plain)) {
            session_regenerate_id(true);
            $_SESSION['pw'] = time();
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
            header('Location: ./', true, 303); exit;
        } else { usleep(600000); $error = 'Mot de passe incorrect.'; }
    }
    elseif (isset($_POST['code']) && $step2) {
        try { $limited = rate_limited($pdo, 'totp', 5, 900); } catch (Throwable $e) { $limited = true; }
        $key = $secret ?? ($_SESSION['totp_pending'] ?? '');
        $step = ($limited || $key === '') ? 0 : totp_verify($key, (string)$_POST['code'], (int)(setting_get($pdo, 'totp_last') ?? 0));
        if ($limited) { $error = 'Trop de tentatives. Réessayez dans 15 minutes.'; }
        elseif ($step) {
            if ($secret === null) setting_set($pdo, 'totp_secret', $key);
            setting_set($pdo, 'totp_last', (string)$step);
            session_regenerate_id(true);
            unset($_SESSION['pw'], $_SESSION['totp_pending']);
            $_SESSION['ok'] = true; $_SESSION['since'] = time(); $_SESSION['t'] = time();
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
            $next = (string)($_SESSION['next'] ?? ''); unset($_SESSION['next']);
            header('Location: ./' . ($next !== '' ? '?' . $next : ''), true, 303); exit;
        } else { usleep(600000); $error = 'Code incorrect ou expiré.'; }
    }
}

$logged = !empty($_SESSION['ok']);
// Lien reçu par e-mail (une demande, un contact) : la page demandée s'ouvre après la connexion
if (!$logged && !empty($_GET['v']) && preg_match('/^[a-z]+$/', (string)$_GET['v'])) $_SESSION['next'] = http_build_query(array_intersect_key($_GET, ['v' => 1, 'd' => 1, 'c' => 1]));
if ($logged) {
    require __DIR__ . '/../api/audit_lib.php';
    // Autorise le test des pages de Rubriqua dans l'audit GEO, depuis ce navigateur
    if (!is_owner()) owner_cookie_set($https);
}
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
.login button.link{background:none;border:0;color:var(--muted);padding:4px;text-decoration:underline}
.qr{width:200px;max-width:100%;align-self:center;background:#fff}
.qr svg{display:block;width:100%;height:auto}
.key{font-family:ui-monospace,monospace;font-size:14px;color:var(--ink);word-break:break-all}
.err{color:var(--red);font-weight:700;margin:0}
.note{font-size:13px;color:var(--muted)}
.menu{position:sticky;top:0;z-index:20;background:#161616;border-bottom:1px solid #2e2e2e}
.menu-in{max-width:1200px;margin:0 auto;padding-left:max(16px,env(safe-area-inset-left,0px));padding-right:max(16px,env(safe-area-inset-right,0px));display:flex;align-items:center;gap:8px 24px;min-height:60px;flex-wrap:wrap}
.menu-brand{display:flex;align-items:center;gap:12px;text-decoration:none;padding-block:10px}
.menu-brand img{display:block;height:24px;width:auto}
.menu-brand span{color:#a9a49d;font-size:13px;font-weight:700;border-left:1px solid #3a3a3a;padding-left:12px}
.menu-tabs{display:flex;gap:4px;flex:1;min-width:0;overflow-x:auto;scrollbar-width:none}
.menu-tabs::-webkit-scrollbar{display:none}
.menu-tabs a{color:#cfcbc4;text-decoration:none;font-weight:700;font-size:14px;padding:19px 12px 17px;border-bottom:3px solid transparent;white-space:nowrap}
.menu-tabs a:hover{color:#fff}
.menu-tabs a[aria-current]{color:#fff;border-bottom-color:var(--red-light)}
.menu-tabs .count{display:inline-block;background:var(--red);color:#fff;border-radius:9px;padding:0 6px;font-size:11px;margin-left:4px;vertical-align:1px}
.menu-out button{background:transparent;color:#cfcbc4;border-color:#3a3a3a}
@media (max-width:760px){.menu-in{gap:0 12px}.menu-tabs{order:3;flex-basis:100%;margin:0 -12px}.menu-tabs a{padding:12px 12px 11px}.menu-out{margin-left:auto}.menu-brand span{display:none}}
.st{display:inline-block;font-size:12px;font-weight:800;padding:2px 8px;border-radius:4px;white-space:nowrap}
.st-ok{background:#e8f1ea;color:#2f6b3a}.st-warn{background:#fbefe0;color:#8a4b08}.st-ko{background:#f6e8e7;color:#991915}.st-grey{background:#efede9;color:#5c5853}.st-dark{background:var(--ink);color:#fff}
.lbl{font-size:12px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--muted)}
input[type=text],input[type=email],input[type=search],textarea{font:inherit;font-size:14px;color:var(--ink);background:#fff;border:1px solid #d9d4cc;border-radius:6px;padding:9px 11px;width:100%}
textarea{resize:vertical}
.btn-dark{background:var(--ink);color:#fff;border-color:var(--ink)}.btn-red{background:var(--red);color:#fff;border-color:var(--red)}
button:disabled{opacity:.45;cursor:not-allowed}
.flash{background:#e8f1ea;color:#2f6b3a;font-weight:700;border-radius:8px;padding:10px 14px;margin:0}
.flash.err{background:#f6e8e7;color:var(--red)}
CSS;

function page_start(string $title, string $css): void {
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<meta name="robots" content="noindex, nofollow"><link rel="icon" href="/favicon.ico?v=202611131642" sizes="32x32"><link rel="icon" href="/favicon.svg?v=202611131642" type="image/svg+xml"><link rel="apple-touch-icon" href="/assets/images/logo/icone-rubriqua-agence-seo-geo-growth-180.png?v=202611131642"><title>' . h($title) . '</title><style>' . $css . '</style></head><body>' . ($GLOBALS['topbar'] ?? '');
}

if (!$logged) {
    page_start('Tableau de bord Rubriqua', $css);
    $field = function (string $label, string $name, string $attrs) use ($csrf) {
        return '<input type="hidden" name="csrf" value="' . h($csrf) . '"><label class="note">' . $label . '<input name="' . $name . '" ' . $attrs . ' required autofocus></label>';
    };
    echo '<main class="login"><form method="post" class="card"><div class="brand"><b>Tableau de bord</b><span>Accès réservé à Rubriqua</span></div>';
    if ($error) echo '<p class="err">' . h($error) . '</p>';
    if (!$step2) {
        if (!$hash && strlen($plain) < 12) echo '<p class="err">Mot de passe non configuré. Voir docs/mise-en-ligne.md.</p>';
        echo $field('Mot de passe', 'password', 'type="password" autocomplete="current-password"') . '<button type="submit">Continuer</button>';
    } elseif ($secret !== null) {
        echo '<p class="note">Saisissez le code à 6 chiffres affiché dans votre application d\'authentification.</p>'
           . $field('Code de vérification', 'code', 'type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7"')
           . '<button type="submit">Se connecter</button>';
    } else {
        if (empty($_SESSION['totp_pending'])) $_SESSION['totp_pending'] = totp_new_secret();
        $pending = $_SESSION['totp_pending'];
        $uri = 'otpauth://totp/Rubriqua:Tableau%20de%20bord?secret=' . $pending . '&issuer=Rubriqua&digits=6&period=30';
        echo '<p class="note"><b>Activer la double authentification.</b> Scannez ce QR code avec une application d\'authentification sur votre téléphone (Google Authenticator, Microsoft Authenticator, 1Password...), puis saisissez le code affiché.</p>'
           . '<div id="qr" class="qr" data-uri="' . h($uri) . '"></div>'
           . '<p class="note">Sans appareil photo, saisissez cette clé dans l\'application&nbsp;: <code class="key">' . h(trim(chunk_split($pending, 4, ' '))) . '</code></p>'
           . $field('Code de vérification', 'code', 'type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7"')
           . '<button type="submit">Activer et se connecter</button>'
           . '<script src="qrcode.js"></script><script>(function(){var el=document.getElementById("qr");var q=qrcode(0,"M");q.addData(el.getAttribute("data-uri"));q.make();el.innerHTML=q.createSvgTag({cellSize:5,margin:2,scalable:true});})();</script>';
    }
    if ($step2) echo '<button type="submit" name="logout" value="1" formnovalidate class="link">Annuler</button>';
    echo '</form></main></body></html>';
    exit;
}

// Onglets
$view = in_array($_GET['v'] ?? '', ['contacts', 'releves', 'agenda'], true) ? $_GET['v'] : 'stats';
// Menu du haut, commun à tous les onglets
function tabs(string $view, int $todo, int $rdv = 0): string {
    $t = ['stats' => 'Statistiques', 'contacts' => 'Contacts', 'releves' => 'Relevés', 'agenda' => 'Agenda'];
    $o = '<nav class="menu-tabs" aria-label="Rubriques">';
    foreach ($t as $k => $l) {
        $n = $k === 'releves' ? $todo : ($k === 'agenda' ? $rdv : 0);
        $o .= '<a href="./' . ($k === 'stats' ? '' : '?v=' . $k) . '"' . ($k === $view ? ' aria-current="page"' : '') . '>' . h($l) . ($n ? ' <span class="count">' . $n . '</span>' : '') . '</a>';
    }
    return $o . '</nav>';
}
function topbar(string $view, int $todo, int $rdv, string $csrf): string {
    return '<header class="menu"><div class="menu-in"><a class="menu-brand" href="./"><img src="/assets/images/logo/logo-rubriqua-agence-seo-geo-growth-fond-sombre.svg?v=202611131642" alt="Rubriqua" width="112" height="24"><span>Tableau de bord</span></a>'
        . tabs($view, $todo, $rdv)
        . '<form method="post" class="menu-out"><input type="hidden" name="csrf" value="' . h($csrf) . '"><button name="logout" value="1">Se déconnecter</button></form></div></header>';
}
$todo = (int)$pdo->query("SELECT COUNT(*) FROM demandes WHERE type = 'releve' AND statut <> 'envoye'")->fetchColumn();
require __DIR__ . '/../api/agenda_lib.php';
agenda_schema($pdo);
$rdvCount = (int)$pdo->query("SELECT COUNT(*) FROM rdv WHERE statut = 'confirme' AND debut >= '" . now_paris()->format('Y-m-d H:i') . "'")->fetchColumn();
$GLOBALS['topbar'] = topbar($view, $todo, $rdvCount, $csrf);
if ($view !== 'stats') {
    define('RB_ADMIN', true);
    require __DIR__ . '/vue-' . $view . '.php';
    exit;
}

// Adresses IP exclues des statistiques
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['ips']) && hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
    $list = array_filter(array_map('trim', preg_split('/[\s,;]+/', (string)$_POST['ips'])), fn($x) => filter_var($x, FILTER_VALIDATE_IP));
    if (isset($_POST['moi'])) $list[] = client_ip();
    setting_set($pdo, 'ip_exclues', implode(', ', array_values(array_unique($list))));
    header('Location: ./?ip=1#ip', true, 303); exit;
}
$ips = ip_exclues($pdo);

// Période
$periods = [7 => '7 jours', 30 => '30 jours', 90 => '90 jours', 365 => '12 mois'];
$days = (int)($_GET['p'] ?? 30);
if (!isset($periods[$days])) $days = 30;
$end = now_paris();
$start = (clone $end)->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
$prevStart = (clone $end)->modify('-' . (2 * $days - 1) . ' days')->format('Y-m-d');
$prevEnd = (clone $end)->modify('-' . $days . ' days')->format('Y-m-d');

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

// Visites : une visite regroupe les pages vues par un même visiteur sans pause de plus de 30 minutes
$hits = $all('SELECT jour, visiteur, heure, page, nouveau FROM visites WHERE jour BETWEEN ? AND ? ORDER BY jour, visiteur, id LIMIT 300000', [$start, $endDay]);
$entries = []; $paths = []; $sessions = 0; $oneShot = 0; $isNew = []; $key = null; $path = []; $last = -1;
$close = function () use (&$path, &$entries, &$paths, &$sessions, &$oneShot) {
    if (!$path) return;
    $sessions++;
    $entries[$path[0]] = ($entries[$path[0]] ?? 0) + 1;
    if (count($path) === 1) $oneShot++;
    else { $k = implode(' → ', array_slice($path, 0, 4)) . (count($path) > 4 ? ' → …' : ''); $paths[$k] = ($paths[$k] ?? 0) + 1; }
    $path = [];
};
foreach ($hits as $r) {
    $vk = $r['jour'] . $r['visiteur'];
    [$hh, $mm] = array_map('intval', explode(':', $r['heure']));
    $t = $hh * 60 + $mm;
    if ($vk !== $key || $t - $last > 30) { $close(); $key = $vk; }
    if (!$path || end($path) !== $r['page']) $path[] = $r['page'];
    $last = $t;
    if ($r['nouveau'] !== null) $isNew[$vk] = max($isNew[$vk] ?? 0, (int)$r['nouveau']);
}
$close();
arsort($entries); arsort($paths);
$entryRows = array_map(fn($p, $n) => ['page' => $p, 'n' => $n], array_keys(array_slice($entries, 0, 15, true)), array_slice($entries, 0, 15, true));
$pathRows = array_map(fn($p, $n) => ['parcours' => $p, 'n' => $n], array_keys(array_slice($paths, 0, 12, true)), array_slice($paths, 0, 12, true));
$known = count($isNew); $returning = count(array_filter($isNew, fn($v) => $v === 0));
$bounce = $sessions ? $oneShot / $sessions * 100 : 0;
$clicks = $all('SELECT libelle, cible, COUNT(*) AS n FROM clics WHERE jour BETWEEN ? AND ? GROUP BY libelle, cible ORDER BY n DESC LIMIT 15', [$start, $endDay]);
foreach ($clicks as &$c) $c['label'] = ($c['libelle'] !== '' ? $c['libelle'] . '  ·  ' : '') . $c['cible'];
unset($c);
$clickTotal = $one('SELECT COUNT(*) FROM clics WHERE jour BETWEEN ? AND ?', [$start, $endDay]);

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
    <div class="brand"><b>Statistiques</b><span>rubriqua.com, du <?= h((new DateTime($start))->format('d/m/Y')) ?> au <?= h($end->format('d/m/Y')) ?></span></div>
    <div class="tools">
      <nav class="seg" aria-label="Période"><?php foreach ($periods as $d => $l): ?><a href="?p=<?= $d ?>"<?= $d === $days ? ' aria-current="page"' : '' ?>><?= h($l) ?></a><?php endforeach; ?></nav>
      <button type="button" id="ignore">Ne pas compter mes visites</button>
    </div>
  </header>

  <section class="kpis">
    <div class="card kpi"><span>Visiteurs</span><b><?= nf($cur['visitors']) ?></b><?= delta($cur['visitors'], $prev['visitors']) ?></div>
    <div class="card kpi"><span>Pages vues</span><b><?= nf($cur['views']) ?></b><?= delta($cur['views'], $prev['views']) ?></div>
    <div class="card kpi"><span>Visiteurs déjà venus</span><b><?= nf($returning) ?></b><small class="flat"><?= $known ? number_format($returning / $known * 100, 0, ',', '') . '% des visiteurs' : 'mesure en cours' ?></small></div>
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
    <section class="card"><h2>Pages d’entrée</h2><p class="note">Première page vue à chaque visite. <?= nf($sessions) ?> visites, dont <?= number_format($bounce, 0, ',', '') ?>% sur une seule page.</p><?= table($entryRows, 'page', 'Page', 'n', 'Entrées', [], true) ?></section>
    <section class="card"><h2>Pages les plus vues</h2><?= table($pages, 'page', 'Page', 'vues', 'Vues', [], true) ?></section>
    <section class="card"><h2>Sources de trafic</h2><?= table($sources, 'source', 'Source', 'visiteurs', 'Visiteurs', $aiNames) ?></section>
    <section class="card"><h2>Appareils</h2><?= table($devices, 'appareil', 'Appareil', 'visiteurs', 'Visiteurs') ?></section>
    <section class="card"><h2>Autres sites qui vous envoient du trafic</h2><?= table($refs, 'referent', 'Site', 'visiteurs', 'Visiteurs', [], true) ?></section>
    <section class="card"><h2>Clics</h2><p class="note"><?= nf($clickTotal) ?> clics sur des liens et des boutons.</p><?= table($clicks, 'label', 'Lien ou bouton', 'n', 'Clics', [], true) ?></section>
    <section class="card"><h2>Pages d’où partent les demandes</h2><?= table($leadPages, 'page', 'Page', 'n', 'Demandes', [], true) ?></section>
  </div>

  <section class="card">
    <h2>Parcours des visiteurs</h2>
    <p class="note">Enchaînement des pages pendant une visite, quatre pages au maximum. Les visites d’une seule page ne figurent pas ici.</p>
    <?= table($pathRows, 'parcours', 'Parcours', 'n', 'Visites', [], true) ?>
  </section>

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

  <section class="card" id="ip">
    <h2>Adresses IP exclues</h2>
    <?php if (isset($_GET['ip'])): ?><p class="flash" style="margin:0 0 12px">Liste enregistrée.</p><?php endif; ?>
    <form method="post" style="display:flex;flex-direction:column;gap:10px">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="text" name="ips" value="<?= h(implode(', ', $ips)) ?>" placeholder="Adresses séparées par des virgules">
      <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center"><button class="btn-dark">Enregistrer</button><?php if (!in_array(client_ip(), $ips, true)): ?><button name="moi" value="1">Exclure mon adresse actuelle, <?= h(client_ip()) ?></button><?php else: ?><span class="note">Votre adresse actuelle, <?= h(client_ip()) ?>, est exclue.</span><?php endif; ?></div>
    </form>
    <p class="note" style="margin:10px 0 0">Les visites et les audits GEO lancés depuis ces adresses ne sont pas comptés. Une box internet change parfois d’adresse : revenez ici si vos visites réapparaissent.</p>
  </section>

  <p class="note">Mesure sans cookie. Un visiteur correspond à un appareil sur une journée : la même personne revenue trois jours compte pour trois. Un visiteur déjà venu est un appareil passé sur le site dans les 13 derniers mois, d’après une date gardée dans son navigateur, sans identifiant. Les robots et vos propres visites, une fois exclues avec le bouton du haut, ne sont pas comptés. Données conservées 25 mois.</p>
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
