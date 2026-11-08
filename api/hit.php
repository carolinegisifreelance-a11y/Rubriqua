<?php
// Rubriqua, mesure d'audience sans cookie.
// Enregistre une page vue : page, source, type d'appareil, identifiant anonyme du jour.
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
if (str_starts_with($page, '/tableau-de-bord') || str_starts_with($page, '/api')) { http_response_code(204); exit; }

$refHost = strtolower((string)parse_url((string)($in['r'] ?? ''), PHP_URL_HOST));
$refHost = preg_replace('/^www\./', '', $refHost);
$utm = strtolower(clean_line((string)($in['u'] ?? ''), 60));
$width = (int)($in['w'] ?? 0);

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
    $pdo->prepare('INSERT INTO visites (jour, heure, page, source, referent, appareil, visiteur) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$now->format('Y-m-d'), $now->format('H:i'), $page, $source, $referent, $device, anon_id($pdo)]);
    purge($pdo);
} catch (Throwable $e) {
    error_log('Rubriqua stats : ' . $e->getMessage());
}
http_response_code(204);
