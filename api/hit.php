<?php
// Rubriqua, mesure d'audience sans cookie.
// Enregistre une page vue (page, source, type d'appareil, identifiant anonyme du jour, nouveau ou déjà venu)
// ou un clic sur un lien ou un bouton.
// Aucune adresse IP, aucun cookie, aucune donnée transmise à un tiers.
define('RUBRIQUA', true);
require __DIR__ . '/lib.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !same_origin()) { http_response_code(204); exit; }

$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
if ($ua === '' || preg_match('/bot|crawl|spider|slurp|preview|headless|lighthouse|pingdom|monitor|curl|wget|python|scrapy|httpclient|facebookexternalhit/i', $ua)) {
    http_response_code(204); exit;
}

$in = json_decode(file_get_contents('php://input', false, null, 0, 4000) ?: '', true);
if (!is_array($in)) { http_response_code(204); exit; }

$page = (string)($in['p'] ?? '');
if ($page === '' || $page[0] !== '/' || strlen($page) > 300) { http_response_code(204); exit; }
$page = preg_replace('/[?#].*$/', '', $page);
if (str_starts_with($page, '/pilotage-rb7k2q') || str_starts_with($page, '/api')) { http_response_code(204); exit; }

// Clic sur un lien ou un bouton
if (($in['t'] ?? '') === 'clic') {
    $cible = clean_line((string)($in['c'] ?? ''), 300);
    $libelle = clean_line((string)($in['l'] ?? ''), 80);
    if ($cible === '') { http_response_code(204); exit; }
    try {
        $pdo = db();
        if (rate_limited($pdo, 'clic', 120, 600)) { http_response_code(204); exit; }
        $now = now_paris();
        $pdo->prepare('INSERT INTO clics (jour, heure, page, cible, libelle, visiteur) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$now->format('Y-m-d'), $now->format('H:i'), $page, $cible, $libelle, anon_id($pdo)]);
    } catch (Throwable $e) { error_log('Rubriqua clics : ' . $e->getMessage()); }
    http_response_code(204); exit;
}

$refHost = strtolower((string)parse_url((string)($in['r'] ?? ''), PHP_URL_HOST));
$refHost = preg_replace('/^www\./', '', $refHost);
$utm = strtolower(clean_line((string)($in['u'] ?? ''), 60));
$width = (int)($in['w'] ?? 0);
$nouveau = isset($in['n']) ? ((int)$in['n'] ? 1 : 0) : null;

function source_of(string $host, string $utm): string {
    $site = config()['site_host'];
    $map = [
        'ChatGPT'    => '/(^|\.)(chatgpt\.com|chat\.openai\.com|openai\.com)$|^chatgpt/',
        'Perplexity' => '/(^|\.)perplexity\.ai$|^perplexity/',
        'Copilot'    => '/(^|\.)copilot\.microsoft\.com$|^copilot/',
        'Gemini'     => '/(^|\.)gemini\.google\.com$|^gemini/',
        'Claude'     => '/(^|\.)claude\.ai$|^claude/',
        'Mistral'    => '/(^|\.)chat\.mistral\.ai$|^mistral/',
        'Google'     => '/(^|\.)google\.[a-z.]+$/',
        'Bing'       => '/(^|\.)bing\.com$/',
        'LinkedIn'   => '/(^|\.)(linkedin\.com|lnkd\.in)$|^linkedin/',
        'Facebook'   => '/(^|\.)(facebook\.com|fb\.com|fb\.me)$|^facebook|^fb$/',
        'Instagram'  => '/(^|\.)instagram\.com$|^instagram/',
        'Autres moteurs' => '/(^|\.)(duckduckgo\.com|qwant\.com|ecosia\.org|yahoo\.com|yandex\.[a-z]+|brave\.com)$/',
    ];
    foreach ([$utm, $host] as $candidate) {
        if ($candidate === '') continue;
        foreach ($map as $name => $re) if (preg_match($re, $candidate)) return $name;
    }
    if ($host === '' ) return $utm !== '' ? 'Campagne' : 'Accès direct';
    if ($host === $site || str_ends_with($host, '.' . $site)) return 'Interne';
    return 'Autres sites';
}

$source = source_of($refHost, $utm);
$device = $width === 0 ? 'Inconnu' : ($width < 768 ? 'Mobile' : ($width < 1100 ? 'Tablette' : 'Ordinateur'));
$referent = in_array($source, ['Autres sites', 'Autres moteurs'], true) ? mb_substr($refHost, 0, 120) : '';

try {
    $pdo = db();
    if (rate_limited($pdo, 'hit', 120, 600)) { http_response_code(204); exit; }
    $now = now_paris();
    $pdo->prepare('INSERT INTO visites (jour, heure, page, source, referent, appareil, visiteur, nouveau) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$now->format('Y-m-d'), $now->format('H:i'), $page, $source, $referent, $device, anon_id($pdo), $nouveau]);
    purge($pdo);
} catch (Throwable $e) {
    error_log('Rubriqua stats : ' . $e->getMessage());
}
http_response_code(204);
