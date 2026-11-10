<?php
// Rubriqua, audit GEO gratuit : lit une page comme un robot IA et vérifie 17 critères.
// L'adresse analysée n'est jamais enregistrée. Seul un compteur anonyme limite le nombre de tests.
define('RUBRIQUA', true);
require __DIR__ . '/lib.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

const AUDIT_UA = 'Mozilla/5.0 (compatible; RubriquaAuditGEO/1.0; +https://rubriqua.com/audit-geo-gratuit/)';
const AUDIT_MAX_BYTES = 2500000;

function fail(string $msg, int $code = 200): void { json_out(['ok' => false, 'message' => $msg], $code); }

// ---------- Lecture d'une adresse, sans jamais joindre un réseau privé ----------

function public_ip(string $host): ?string {
    if (filter_var($host, FILTER_VALIDATE_IP)) $ips = [$host];
    else {
        $ips = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $r) $ips[] = $r['ip'] ?? ($r['ipv6'] ?? '');
        if (!$ips) $ips = @gethostbynamel($host) ?: [];
    }
    $ips = array_values(array_filter($ips));
    if (!$ips) return null;
    // Tests locaux uniquement : jamais défini sur le serveur
    if (getenv('RUBRIQUA_AUDIT_TEST') === '1' && $ips === ['127.0.0.1']) return '127.0.0.1';
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return null;
    }
    foreach ($ips as $ip) if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return $ip;
    return $ips[0];
}

function fetch(string $url, int $hops = 4): array {
    for ($i = 0; $i <= $hops; $i++) {
        $p = parse_url($url);
        $scheme = strtolower($p['scheme'] ?? '');
        $host = strtolower($p['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') return ['error' => 'Adresse invalide.'];
        $port = (int)($p['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (!in_array($port, [80, 443], true) && getenv('RUBRIQUA_AUDIT_TEST') !== '1') return ['error' => 'Seuls les ports web standard sont acceptés.'];
        $ip = public_ip($host);
        if ($ip === null) return ['error' => 'Ce site est introuvable ou n’est pas accessible publiquement.'];
        $body = ''; $headers = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE => [$host . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_USERAGENT => AUDIT_UA,
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.5', 'Accept-Language: fr-FR,fr;q=0.9,en;q=0.6'],
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$headers) {
                $kv = explode(':', $line, 2);
                if (count($kv) === 2) $headers[strtolower(trim($kv[0]))] = trim($kv[1]);
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($c, $chunk) use (&$body) {
                $body .= $chunk;
                return strlen($body) > AUDIT_MAX_BYTES ? 0 : strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $time = (float)curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME);
        $err = curl_errno($ch);
        curl_close($ch);
        if (!$ok && $err && $err !== CURLE_WRITE_ERROR) {
            return ['error' => $err === CURLE_OPERATION_TIMEDOUT ? 'Le site met trop de temps à répondre.' : 'Impossible de joindre ce site.'];
        }
        if ($status >= 300 && $status < 400 && !empty($headers['location'])) {
            $url = absolute_url($headers['location'], $url);
            continue;
        }
        return ['url' => $url, 'status' => $status, 'headers' => $headers, 'body' => $body, 'time' => $time];
    }
    return ['error' => 'Trop de redirections.'];
}

function absolute_url(string $href, string $base): string {
    if (preg_match('#^https?://#i', $href)) return $href;
    $b = parse_url($base);
    $root = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
    if (str_starts_with($href, '//')) return $b['scheme'] . ':' . $href;
    if (str_starts_with($href, '/')) return $root . $href;
    $dir = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/');
    return $root . $dir . $href;
}

// ---------- robots.txt ----------

function robots_groups(string $txt): array {
    $groups = []; $cur = null; $lastWasAgent = false;
    foreach (preg_split('/\r\n|\r|\n/', $txt) as $line) {
        $line = trim(preg_replace('/#.*$/', '', $line));
        if ($line === '' || !str_contains($line, ':')) continue;
        [$k, $v] = array_map('trim', explode(':', $line, 2));
        $k = strtolower($k);
        if ($k === 'user-agent') {
            if (!$lastWasAgent) { $groups[] = ['agents' => [], 'rules' => []]; $cur = count($groups) - 1; }
            $groups[$cur]['agents'][] = strtolower($v);
            $lastWasAgent = true;
        } elseif (in_array($k, ['allow', 'disallow'], true) && $cur !== null) {
            $groups[$cur]['rules'][] = [$k, $v];
            $lastWasAgent = false;
        } else { $lastWasAgent = false; }
    }
    return $groups;
}

function robots_allows(array $groups, string $agent, string $path): bool {
    $agent = strtolower($agent);
    $rules = []; $found = false;
    foreach ($groups as $g) if (in_array($agent, $g['agents'], true)) { $rules = array_merge($rules, $g['rules']); $found = true; }
    if (!$found) foreach ($groups as $g) if (in_array('*', $g['agents'], true)) $rules = array_merge($rules, $g['rules']);
    $best = -1; $allow = true;
    foreach ($rules as [$type, $pattern]) {
        if ($pattern === '') { if ($type === 'disallow') continue; }
        $re = '#^' . str_replace(['\*', '\$'], ['.*', '$'], preg_quote($pattern, '#')) . '#';
        if (preg_match($re, $path)) {
            $len = strlen($pattern);
            if ($len > $best || ($len === $best && $type === 'allow')) { $best = $len; $allow = $type === 'allow'; }
        }
    }
    return $allow;
}

// ---------- Analyse ----------

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !same_origin()) fail('Requête refusée.', 403);
$raw = trim((string)($_POST['url'] ?? ''));
if ($raw === '') fail('Indiquez l’adresse de la page à analyser.');
if (!preg_match('#^https?://#i', $raw)) $raw = 'https://' . $raw;
if (!filter_var($raw, FILTER_VALIDATE_URL) || strlen($raw) > 500) fail('Cette adresse ne semble pas valide.');

try {
    if (rate_limited(db(), 'audit', 10, 3600)) fail('Vous avez lancé beaucoup de tests. Réessayez dans une heure.', 429);
} catch (Throwable $e) { error_log('Rubriqua audit : ' . $e->getMessage()); }

$page = fetch($raw);
if (isset($page['error']) && str_starts_with($raw, 'https://')) {
    $retry = fetch('http://' . substr($raw, 8));
    if (!isset($retry['error'])) $page = $retry;
}
if (isset($page['error'])) fail($page['error']);
if ($page['status'] >= 400) fail('La page répond avec une erreur ' . $page['status'] . '. Vérifiez l’adresse, ou le site bloque peut-être les outils d’analyse.');
$ctype = strtolower($page['headers']['content-type'] ?? 'text/html');
if (!str_contains($ctype, 'html')) fail('Cette adresse ne renvoie pas une page web.');

$final = $page['url'];
$u = parse_url($final);
$origin = $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
$path = ($u['path'] ?? '/') . (isset($u['query']) ? '?' . $u['query'] : '');
$siteHost = preg_replace('/^www\./', '', strtolower($u['host']));

// Document
libxml_use_internal_errors(true);
$dom = new DOMDocument();
$html = $page['body'];
if (!preg_match('/<meta[^>]+charset/i', substr($html, 0, 3000))) $html = '<meta charset="utf-8">' . $html;
$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
$xp = new DOMXPath($dom);
$q = fn(string $x) => $xp->query($x);
$attr = function (string $x, string $a) use ($xp) { $n = $xp->query($x)->item(0); return $n ? trim($n->getAttribute($a)) : ''; };

// Données structurées
$ld = [];
foreach ($q('//script[@type="application/ld+json"]') as $s) {
    $j = json_decode(trim($s->textContent), true);
    if (is_array($j)) $ld[] = $j;
}
$types = []; $ldText = json_encode($ld, JSON_UNESCAPED_UNICODE) ?: '';
array_walk_recursive($ld, function ($v, $k) use (&$types) { if ($k === '@type') $types[] = is_array($v) ? implode(',', $v) : (string)$v; });
$types = array_map('strtolower', $types);
$hasType = fn(array $names) => (bool)array_intersect($names, $types) || (bool)array_filter($types, fn($t) => (bool)array_filter($names, fn($n) => str_contains($t, $n)));
$microdata = $q('//*[@itemtype]')->length > 0;

// Texte visible tel qu'un robot sans JavaScript le lit
$title = trim($q('//title')->item(0)?->textContent ?? '');
$desc = $attr('//meta[translate(@name,"DESCRIPTION","description")="description"]', 'content');
$robotsMeta = strtolower($attr('//meta[translate(@name,"ROBOTS","robots")="robots"]', 'content'));
$canonical = $attr('//link[translate(@rel,"CANONICAL","canonical")="canonical"]', 'href');
foreach ($q('//script|//style|//noscript|//template|//svg|//iframe') as $n) $n->parentNode->removeChild($n);
$bodyNode = $q('//body')->item(0);
$text = $bodyNode ? preg_replace('/\s+/u', ' ', $bodyNode->textContent) : '';
$words = preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\'’-]*/u', $text);
$h1 = $q('//h1')->length;
$h2 = $q('//h2')->length;
$headings = [];
foreach ($q('//h2|//h3') as $h) $headings[] = trim($h->textContent);

// Page vide ou domaine non utilisé (parking, page d'attente de l'hébergeur, site en construction)
$parking = '/(domain (is )?(parked|for sale|reserved)|this domain|ce domaine|nom de domaine (est )?(réservé|à vendre)|domaine réservé|parked free|parkingcrew|sedo|bodis|godaddy|ionos|hostinger|o2switch|ovhcloud|site en construction|en cours de construction|under construction|coming soon|bientôt disponible|page par défaut|default (web )?page|it works!|welcome to nginx|index of \/)/iu';
if ($words < 40 || ($words < 250 && preg_match($parking, $title . ' ' . $text))) {
    fail('Cette adresse ne présente presque aucun contenu : page en construction, page d’attente de l’hébergeur ou domaine non utilisé. Il n’y a rien à analyser. Vérifiez l’adresse de votre site.');
}

// Liens
$links = [];
foreach ($q('//a[@href]') as $a) $links[] = ['href' => trim($a->getAttribute('href')), 'text' => mb_strtolower(trim($a->textContent))];
$linkMatch = function (string $re) use ($links) { foreach ($links as $l) if (preg_match($re, mb_strtolower($l['href'])) || preg_match($re, $l['text'])) return true; return false; };
$social = '/(facebook|instagram|linkedin|twitter|x\.com|youtube|tiktok|pinterest|google\.com\/maps|wa\.me|whatsapp)/i';
$external = 0;
foreach ($links as $l) {
    if (!preg_match('#^https?://#i', $l['href'])) continue;
    $h = preg_replace('/^www\./', '', strtolower((string)parse_url($l['href'], PHP_URL_HOST)));
    if ($h && $h !== $siteHost && !str_ends_with($h, '.' . $siteHost) && !preg_match($social, $h)) $external++;
}

// robots.txt
$robots = fetch($origin . '/robots.txt', 2);
$robotsTxt = (!isset($robots['error']) && $robots['status'] === 200 && !str_contains(strtolower($robots['headers']['content-type'] ?? 'text/plain'), 'html')) ? $robots['body'] : '';
$robotsDown = !isset($robots['error']) && $robots['status'] >= 500;
$groups = robots_groups($robotsTxt);
$searchBots = ['OAI-SearchBot' => 'ChatGPT', 'ChatGPT-User' => 'ChatGPT', 'PerplexityBot' => 'Perplexity', 'Claude-SearchBot' => 'Claude', 'Bingbot' => 'Copilot et Bing', 'Googlebot' => 'Google et ses Aperçus IA'];
$trainBots = ['GPTBot', 'ClaudeBot', 'Google-Extended', 'CCBot'];
$blocked = []; $blockedTrain = [];
foreach ($searchBots as $bot => $label) if (!robots_allows($groups, $bot, $path)) $blocked[$bot] = $label;
foreach ($trainBots as $bot) if (!robots_allows($groups, $bot, $path)) $blockedTrain[] = $bot;

// ---------- Critères ----------
$it = fn(string $name, string $st, string $note) => ['name' => $name, 'status' => $st, 'note' => $note];
$out = [];

// Accès des IA
$a = [];
$https = str_starts_with($final, 'https://');
$a[] = $https ? $it('Page accessible en HTTPS', 'ok', 'La page répond correctement, en connexion sécurisée.')
              : $it('Page accessible en HTTPS', 'ko', 'La page n’est pas servie en HTTPS. Les moteurs et les visiteurs la jugent moins fiable.');
if ($robotsDown) $a[] = $it('Robots des IA autorisés', 'warn', 'Le fichier robots.txt renvoie une erreur serveur. Certains robots s’abstiennent alors de lire le site.');
elseif ($blocked) $a[] = $it('Robots des IA autorisés', 'ko', 'Le fichier robots.txt bloque ' . implode(', ', array_keys($blocked)) . '. Cette page ne peut pas apparaître dans les réponses de ' . implode(', ', array_unique($blocked)) . '.');
else $a[] = $it('Robots des IA autorisés', 'ok', 'Les robots de recherche de ChatGPT, Perplexity, Claude, Copilot et Google peuvent lire la page.' . ($blockedTrain ? ' Les robots d’entraînement (' . implode(', ', $blockedTrain) . ') sont bloqués, sans effet sur les citations.' : ''));
$xRobots = strtolower($page['headers']['x-robots-tag'] ?? '');
$noindex = str_contains($robotsMeta, 'noindex') || str_contains($xRobots, 'noindex');
$a[] = $noindex ? $it('Page indexable', 'ko', 'Une consigne noindex demande aux moteurs de ne pas indexer la page. Elle ne peut pas être citée.')
                : $it('Page indexable', 'ok', 'Aucune consigne noindex ne bloque la page.');
if ($words >= 300) $a[] = $it('Texte lisible sans JavaScript', 'ok', 'Le texte est présent dans le code de la page, lisible par les robots IA.');
elseif ($words >= 120) $a[] = $it('Texte lisible sans JavaScript', 'warn', 'Peu de texte dans le code de la page (' . $words . ' mots). Une partie est peut-être chargée en JavaScript, que la plupart des robots IA n’exécutent pas.');
else $a[] = $it('Texte lisible sans JavaScript', 'ko', 'Presque aucun texte dans le code de la page (' . $words . ' mots). Le contenu semble chargé en JavaScript, invisible pour la plupart des robots IA.');
$t = $page['time'];
$ts = number_format(max(0.1, $t), 1, ',', '');
$a[] = $t < 1 ? $it('Temps de réponse', 'ok', 'Le serveur répond en ' . $ts . ' seconde.')
    : ($t < 2.5 ? $it('Temps de réponse', 'warn', 'Le serveur répond en ' . $ts . ' secondes. Un site lent est moins souvent lu en entier.')
                : $it('Temps de réponse', 'ko', 'Le serveur répond en ' . $ts . ' secondes. Les robots risquent d’abandonner la lecture.'));
$out[] = ['name' => 'Accès des IA', 'items' => $a];

// Structure
$s = [];
$tl = mb_strlen($title); $dl = mb_strlen($desc);
if ($title !== '' && $desc !== '') $s[] = $it('Titre et méta description', ($tl > 70 || $dl > 170 || $dl < 50) ? 'warn' : 'ok', ($tl > 70 || $dl > 170 || $dl < 50) ? 'Présents, mais de longueur peu adaptée (titre de ' . $tl . ' caractères, description de ' . $dl . ').' : 'Présents et de longueur adaptée.');
elseif ($title !== '') $s[] = $it('Titre et méta description', 'warn', 'Le titre est présent, la méta description manque.');
else $s[] = $it('Titre et méta description', 'ko', 'La page n’a pas de titre.');
if ($h1 === 1) $s[] = $it('Titres hiérarchisés', $h2 > 0 ? 'ok' : 'warn', $h2 > 0 ? 'Un titre H1 et des sous-titres H2 organisent la page.' : 'Un titre H1, mais aucun sous-titre H2 pour organiser la page.');
elseif ($h1 === 0) $s[] = $it('Titres hiérarchisés', 'ko', 'Aucun titre H1. Le sujet principal de la page est difficile à identifier.');
else $s[] = $it('Titres hiérarchisés', 'warn', $h1 . ' titres H1 sur la page. Un seul H1 aide à identifier le sujet principal.');
if ($ld) $s[] = $it('Données structurées', 'ok', 'Données schema.org présentes' . ($types ? ' (' . implode(', ', array_slice(array_unique($types), 0, 4)) . ')' : '') . '.');
elseif ($microdata) $s[] = $it('Données structurées', 'warn', 'Des microdonnées sont présentes. Le format JSON-LD est plus simple à maintenir et à lire.');
else $s[] = $it('Données structurées', 'ko', 'Aucune donnée schema.org. Les moteurs identifient moins bien votre entreprise et vos services.');
if ($canonical === '') $s[] = $it('Balise canonique', 'warn', 'Aucune balise canonique. Les moteurs peuvent hésiter entre plusieurs adresses du même contenu.');
else {
    $ch = preg_replace('/^www\./', '', strtolower((string)parse_url(absolute_url($canonical, $final), PHP_URL_HOST)));
    $s[] = $ch === $siteHost ? $it('Balise canonique', 'ok', 'Elle est présente et pointe vers ce site.') : $it('Balise canonique', 'warn', 'Elle pointe vers un autre domaine (' . $ch . ').');
}
$hasSitemap = (bool)preg_match('/^\s*sitemap\s*:/im', $robotsTxt);
if (!$hasSitemap) { $sm = fetch($origin . '/sitemap.xml', 2); $hasSitemap = !isset($sm['error']) && $sm['status'] === 200; }
$s[] = $hasSitemap ? $it('Plan du site déclaré', 'ok', 'Un plan du site (sitemap) est disponible.') : $it('Plan du site déclaré', 'warn', 'Aucun plan du site trouvé dans le robots.txt ni à l’adresse /sitemap.xml.');
$out[] = ['name' => 'Structure', 'items' => $s];

// Confiance
$c = [];
$org = $hasType(['organization', 'localbusiness', 'corporation', 'store']);
$contactInfo = preg_match('/(siret|siren|rcs|capital social|\+33|0[1-9](?:[ .]?\d{2}){4}|mentions légales)/iu', $text) || $linkMatch('/(mentions-legales|mentions légales|legal|impressum|^tel:)/');
$c[] = ($org || $contactInfo) ? $it('Entreprise identifiée', 'ok', $org ? 'L’entreprise est décrite dans les données structurées.' : 'Des informations sur l’entreprise sont présentes (coordonnées ou mentions légales).')
                              : $it('Entreprise identifiée', 'warn', 'Rien n’indique clairement qui publie ce site : ni données Organization, ni coordonnées, ni mentions légales.');
$about = $linkMatch('#(a-propos|apropos|about|qui-sommes|notre-histoire|equipe|qui sommes|à propos)#');
$contact = $linkMatch('#(contact|nous-contacter|mailto:)#');
$c[] = ($about && $contact) ? $it('Pages À propos et Contact', 'ok', 'Accessibles depuis la page.')
     : (($about || $contact) ? $it('Pages À propos et Contact', 'warn', 'Seule la page ' . ($about ? 'À propos' : 'Contact') . ' est accessible depuis cette page.')
                             : $it('Pages À propos et Contact', 'ko', 'Aucun lien vers une page À propos ni Contact.'));
$author = $hasType(['person']) || str_contains($ldText, '"author"') || $attr('//meta[@name="author"]', 'content') !== '' || $q('//*[@rel="author"]|//*[contains(@class,"author") or contains(@class,"auteur")]')->length > 0;
$c[] = $author ? $it('Auteur ou expert identifié', 'ok', 'Un auteur ou un expert est identifié.') : $it('Auteur ou expert identifié', 'warn', 'Aucun auteur ni expert mis en avant. Les IA privilégient les contenus signés.');
$dated = str_contains($ldText, 'dateModified') || str_contains($ldText, 'datePublished') || $q('//time|//meta[@property="article:modified_time" or @property="article:published_time"]')->length > 0
      || preg_match('/(mis à jour|mise à jour|publié) le/iu', $text);
$c[] = $dated ? $it('Date de mise à jour', 'ok', 'Une date de publication ou de mise à jour est indiquée.') : $it('Date de mise à jour', 'warn', 'Aucune date de publication ou de mise à jour. Les IA favorisent les informations récentes.');
$out[] = ['name' => 'Confiance (E-E-A-T)', 'items' => $c];

// Contenu
$k = [];
$k[] = $words >= 500 ? $it('Volume de texte', 'ok', $words . ' mots, de quoi traiter le sujet.')
     : ($words >= 250 ? $it('Volume de texte', 'warn', $words . ' mots. Un texte plus complet donne plus de réponses à reprendre.')
                      : $it('Volume de texte', 'ko', $words . ' mots. Trop peu de texte pour qu’une IA en extraie une réponse.'));
$questions = count(array_filter($headings, fn($h) => str_ends_with(rtrim($h), '?')));
$faq = $hasType(['faqpage']) || $q('//details')->length > 0;
$k[] = ($questions || $faq) ? $it('Réponses aux questions', 'ok', 'La page répond à des questions formulées clairement' . ($faq ? ', avec une FAQ' : '') . '.')
                            : $it('Réponses aux questions', 'warn', 'Aucune question dans les titres ni FAQ. Les IA reprennent plus facilement une réponse courte à une question précise.');
$k[] = $external > 0 ? $it('Sources citées', 'ok', $external . ' lien' . ($external > 1 ? 's' : '') . ' vers des sites externes appuie' . ($external > 1 ? 'nt' : '') . ' le contenu.')
                     : $it('Sources citées', 'warn', 'Aucun lien vers une source externe pour appuyer les chiffres et les affirmations.');
$out[] = ['name' => 'Contenu', 'items' => $k];

// ---------- Score et conclusion ----------
$all = array_merge(...array_map(fn($g) => $g['items'], $out));
$count = ['ok' => 0, 'warn' => 0, 'ko' => 0];
foreach ($all as $i) $count[$i['status']]++;
$score = (int)round(($count['ok'] + 0.5 * $count['warn']) / count($all) * 100);
$ko = array_values(array_filter($all, fn($i) => $i['status'] === 'ko'));
$warn = array_values(array_filter($all, fn($i) => $i['status'] === 'warn'));
$lc = fn($i) => mb_strtolower(mb_substr($i['name'], 0, 1)) . mb_substr($i['name'], 1);
if ($ko) {
    $verdict = count($ko) === 1 ? 'Un point bloque la reprise de votre page par les IA' : count($ko) . ' points bloquent la reprise de votre page par les IA';
    $conclusion = 'À corriger en priorité : ' . implode(', ', array_map($lc, $ko)) . '.';
    if ($warn) $conclusion .= ' Ensuite, travaillez ces points : ' . implode(', ', array_map($lc, array_slice($warn, 0, 3))) . '.';
} elseif ($warn) {
    $verdict = 'Votre page est lisible par les IA, avec des points à améliorer';
    $conclusion = 'Rien ne bloque la lecture de la page. Pour la rendre plus facile à citer, travaillez ces points : ' . implode(', ', array_map($lc, array_slice($warn, 0, 4))) . '.';
} else {
    $verdict = 'Votre page coche les 17 critères';
    $conclusion = 'Rien n’empêche les IA de lire et de citer cette page. La suite se joue sur la qualité des réponses, la notoriété de la marque et la concurrence.';
}

json_out([
    'ok' => true, 'url' => $final, 'host' => $u['host'], 'score' => $score,
    'counts' => $count, 'total' => count($all), 'verdict' => $verdict, 'conclusion' => $conclusion, 'groups' => $out,
]);
