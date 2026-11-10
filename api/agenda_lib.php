<?php
// Rubriqua, agenda : créneaux ouverts, heures occupées de l'agenda Google, liens de réservation et rendez-vous.
// Ce fichier n'est jamais appelé directement.
if (!defined('RUBRIQUA')) { http_response_code(404); exit; }

const JOURS = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche'];
const JOURS_COURTS = [1 => 'lun.', 2 => 'mar.', 3 => 'mer.', 4 => 'jeu.', 5 => 'ven.', 6 => 'sam.', 7 => 'dim.'];
const LIEN_JOURS = 30;

function agenda_schema(PDO $pdo): void {
    $id = is_sqlite($pdo) ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $pdo->exec("CREATE TABLE IF NOT EXISTS rdv_liens (
        jeton CHAR(32) PRIMARY KEY, cree_le CHAR(19) NOT NULL, expire CHAR(19) NOT NULL, contact_id INT NOT NULL DEFAULT 0,
        email VARCHAR(200) NOT NULL DEFAULT '', nom VARCHAR(120) NOT NULL DEFAULT '', site VARCHAR(300) NOT NULL DEFAULT '',
        origine VARCHAR(10) NOT NULL, contexte VARCHAR(200) NOT NULL DEFAULT '', utilise TINYINT NOT NULL DEFAULT 0)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS rdv (
        id $id, cree_le CHAR(19) NOT NULL, debut CHAR(16) NOT NULL, fin CHAR(16) NOT NULL,
        nom VARCHAR(120) NOT NULL, email VARCHAR(200) NOT NULL, tel VARCHAR(40) NOT NULL DEFAULT '', sujet TEXT NOT NULL,
        site VARCHAR(300) NOT NULL DEFAULT '', contact_id INT NOT NULL DEFAULT 0, lien VARCHAR(32) NOT NULL DEFAULT '',
        origine VARCHAR(10) NOT NULL DEFAULT '', statut VARCHAR(10) NOT NULL DEFAULT 'confirme', jeton CHAR(32) NOT NULL, annule_le CHAR(19) NOT NULL DEFAULT '')");
}

// ---------- Réglages ----------

function agenda_defaults(): array {
    return [
        'plages' => [1 => [], 2 => [['12:00', '13:30'], ['18:00', '19:30']], 3 => [['12:00', '13:30']], 4 => [['12:00', '13:30'], ['18:00', '19:30']], 5 => [['09:00', '12:00']], 6 => [], 7 => []],
        'duree' => 30, 'pause' => 15, 'delai' => 24, 'horizon' => 3, 'max_jour' => 2, 'visio' => '',
        'ical' => '', 'releve' => 1, 'devis' => 1, 'public' => 0, 'actif' => 0,
    ];
}
function agenda_get(PDO $pdo): array {
    $s = json_decode((string)(setting_get($pdo, 'agenda') ?? ''), true);
    $d = agenda_defaults();
    if (!is_array($s)) return $d;
    $s = array_merge($d, $s);
    $p = [];
    for ($i = 1; $i <= 7; $i++) $p[$i] = array_values(array_filter((array)($s['plages'][$i] ?? $s['plages'][(string)$i] ?? []), 'is_array'));
    $s['plages'] = $p;
    return $s;
}
function agenda_set(PDO $pdo, array $s): void { setting_set($pdo, 'agenda', json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); }

// Les mails peuvent proposer la réservation : agenda enregistré au moins une fois et au moins une plage ouverte
function agenda_ouvert(array $s, string $origine): bool {
    if (empty($s['actif'])) return false;
    $any = false; foreach ($s['plages'] as $p) if ($p) $any = true;
    if (!$any) return false;
    return $origine === 'manuel' || !empty($s[$origine === 'complet' ? 'devis' : $origine]);
}

function hm(string $t): int { [$h, $m] = array_map('intval', explode(':', $t) + [0, 0]); return $h * 60 + $m; }
function heure_fr(string $hm): string { [$h, $m] = explode(':', $hm); return (int)$h . ' h ' . $m; }
function tz(): DateTimeZone { static $z = null; return $z ??= new DateTimeZone('Europe/Paris'); }

// ---------- Agenda Google (adresse secrète iCal) : seules les heures occupées sont lues ----------

function ical_busy(PDO $pdo, array $s, bool $force = false): array {
    $url = trim((string)$s['ical']);
    if ($url === '') return ['ok' => true, 'busy' => [], 'quand' => '', 'erreur' => ''];
    $key = 'agenda_busy_' . substr(hash('sha256', $url), 0, 12);
    $c = json_decode((string)(setting_get($pdo, $key) ?? ''), true);
    if (!$force && is_array($c) && time() - (int)$c['t'] < 600) return ['ok' => true, 'busy' => $c['busy'], 'quand' => date('c', (int)$c['t']), 'erreur' => ''];
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
    $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    if (!$raw || $code !== 200 || !str_contains((string)$raw, 'BEGIN:VCALENDAR')) {
        $err = $code === 404 || $code === 403 ? 'adresse refusée par Google (' . $code . ')' : 'agenda injoignable (' . ($code ?: 'pas de réponse') . ')';
        // En cas d'échec, la dernière lecture réussie sert encore 24 heures. Au-delà, aucun créneau n'est proposé.
        if (is_array($c) && time() - (int)$c['t'] < 86400) return ['ok' => true, 'busy' => $c['busy'], 'quand' => date('c', (int)$c['t']), 'erreur' => $err];
        return ['ok' => false, 'busy' => [], 'quand' => '', 'erreur' => $err];
    }
    $from = time() - 86400; $to = time() + 86400 * (7 * max(1, (int)$s['horizon']) + 2);
    $busy = ical_parse((string)$raw, $from, $to);
    setting_set($pdo, $key, json_encode(['t' => time(), 'busy' => $busy]));
    return ['ok' => true, 'busy' => $busy, 'quand' => date('c'), 'erreur' => ''];
}

function ical_time(string $prop, string $val): array {
    // Renvoie [timestamp, journée entière]
    $allDay = str_contains($prop, 'VALUE=DATE') && !str_contains($prop, 'VALUE=DATE-TIME') || preg_match('/^\d{8}$/', $val);
    if ($allDay) { $d = DateTime::createFromFormat('!Ymd', substr($val, 0, 8), tz()); return [$d ? $d->getTimestamp() : 0, true]; }
    $zone = tz();
    if (preg_match('/TZID=("?)([^;:"]+)\1/', $prop, $m)) { try { $zone = new DateTimeZone($m[2]); } catch (Throwable $e) { $zone = tz(); } }
    if (str_ends_with($val, 'Z')) { $zone = new DateTimeZone('UTC'); $val = substr($val, 0, -1); }
    $d = DateTime::createFromFormat('Ymd\THis', substr($val, 0, 15), $zone);
    return [$d ? $d->getTimestamp() : 0, false];
}

function ical_duration(string $v): int {
    if (!preg_match('/^([+-])?P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', $v, $m)) return 0;
    return ((int)($m[2] ?? 0) * 7 * 86400) + ((int)($m[3] ?? 0) * 86400) + ((int)($m[4] ?? 0) * 3600) + ((int)($m[5] ?? 0) * 60) + (int)($m[6] ?? 0);
}

function ical_parse(string $raw, int $from, int $to): array {
    $raw = preg_replace("/\r?\n[ \t]/", '', str_replace("\r\n", "\n", $raw));
    preg_match_all('/BEGIN:VEVENT\n(.*?)\nEND:VEVENT/s', $raw, $m);
    $events = []; $overrides = [];
    foreach ($m[1] as $block) {
        $e = ['ex' => []];
        foreach (explode("\n", $block) as $line) {
            $p = strpos($line, ':'); if ($p === false) continue;
            $prop = substr($line, 0, $p); $val = trim(substr($line, $p + 1)); $name = strtoupper(explode(';', $prop)[0]);
            if ($name === 'DTSTART') { [$e['s'], $e['all']] = ical_time($prop, $val); $e['sprop'] = $prop; }
            elseif ($name === 'DTEND') [$e['e']] = ical_time($prop, $val);
            elseif ($name === 'DURATION') $e['dur'] = ical_duration($val);
            elseif ($name === 'RRULE') $e['rrule'] = $val;
            elseif ($name === 'EXDATE') foreach (explode(',', $val) as $x) $e['ex'][] = ical_time($prop, $x)[0];
            elseif ($name === 'RECURRENCE-ID') $e['rid'] = ical_time($prop, $val)[0];
            elseif ($name === 'UID') $e['uid'] = $val;
            elseif ($name === 'TRANSP') $e['transp'] = strtoupper($val);
            elseif ($name === 'STATUS') $e['status'] = strtoupper($val);
        }
        if (empty($e['s'])) continue;
        if (!isset($e['e'])) $e['e'] = $e['s'] + ($e['dur'] ?? ($e['all'] ? 86400 : 0));
        if (isset($e['rid'])) $overrides[($e['uid'] ?? '') . '|' . $e['rid']] = true;
        $events[] = $e;
    }
    $busy = [];
    foreach ($events as $e) {
        $len = max(0, $e['e'] - $e['s']);
        $skip = ($e['transp'] ?? '') === 'TRANSPARENT' || ($e['status'] ?? '') === 'CANCELLED';
        $starts = empty($e['rrule']) || isset($e['rid']) ? [$e['s']] : rrule_expand($e, $to);
        foreach ($starts as $st) {
            if (!isset($e['rid']) && !empty($e['rrule']) && (in_array($st, $e['ex'], true) || isset($overrides[($e['uid'] ?? '') . '|' . $st]))) continue;
            if ($skip || $len === 0) continue;
            if ($st + $len < $from || $st > $to) continue;
            $busy[] = [$st, $st + $len];
        }
    }
    usort($busy, fn($a, $b) => $a[0] <=> $b[0]);
    return $busy;
}

// Répétitions courantes de Google Agenda : quotidienne, hebdomadaire (jours choisis), mensuelle, annuelle
function rrule_expand(array $e, int $to): array {
    $r = [];
    foreach (explode(';', $e['rrule']) as $kv) { [$k, $v] = array_pad(explode('=', $kv, 2), 2, ''); $r[strtoupper($k)] = $v; }
    $freq = $r['FREQ'] ?? ''; $int = max(1, (int)($r['INTERVAL'] ?? 1)); $count = isset($r['COUNT']) ? (int)$r['COUNT'] : 0;
    $until = isset($r['UNTIL']) ? ical_time(strlen($r['UNTIL']) === 8 ? 'VALUE=DATE' : '', $r['UNTIL'])[0] + (strlen($r['UNTIL']) === 8 ? 86399 : 0) : PHP_INT_MAX;
    $zone = tz();
    if (preg_match('/TZID=("?)([^;:"]+)\1/', $e['sprop'] ?? '', $m)) { try { $zone = new DateTimeZone($m[2]); } catch (Throwable $x) {} }
    $d0 = (new DateTime('@' . $e['s']))->setTimezone($zone);
    $map = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];
    $byday = [];
    foreach (array_filter(explode(',', $r['BYDAY'] ?? '')) as $b) if (preg_match('/^([+-]?\d+)?([A-Z]{2})$/', $b, $bm) && isset($map[$bm[2]])) $byday[] = [(int)($bm[1] ?? 0), $map[$bm[2]]];
    $out = []; $n = 0; $guard = 0;
    $time = $d0->format('H:i:s');
    if ($freq === 'WEEKLY') {
        $days = $byday ? array_map(fn($b) => $b[1], $byday) : [(int)$d0->format('N')];
        sort($days);
        $week = (clone $d0)->modify('-' . ((int)$d0->format('N') - 1) . ' days');
        while ($guard++ < 2000) {
            foreach ($days as $wd) {
                $d = (clone $week)->modify('+' . ($wd - 1) . ' days'); $d->modify($time);
                $t = $d->getTimestamp();
                if ($t < $e['s']) continue;
                if ($t > $until || $t > $to || ($count && $n >= $count)) return $out;
                $out[] = $t; $n++;
            }
            $week->modify('+' . (7 * $int) . ' days');
        }
        return $out;
    }
    $d = clone $d0;
    while ($guard++ < 3000) {
        $cands = [];
        if ($freq === 'MONTHLY' && $byday) {
            foreach ($byday as [$ord, $wd]) {
                $first = (clone $d)->modify('first day of this month')->modify($time);
                $list = [];
                for ($x = clone $first; $x->format('m') === $first->format('m'); $x->modify('+1 day')) if ((int)$x->format('N') === $wd) $list[] = $x->getTimestamp();
                if ($ord > 0 && isset($list[$ord - 1])) $cands[] = $list[$ord - 1];
                elseif ($ord < 0 && isset($list[count($list) + $ord])) $cands[] = $list[count($list) + $ord];
                elseif ($ord === 0) $cands = array_merge($cands, $list);
            }
            sort($cands);
        } else {
            $cands = [$d->getTimestamp()];
        }
        foreach ($cands as $t) {
            if ($t < $e['s']) continue;
            if ($t > $until || $t > $to || ($count && $n >= $count)) return $out;
            $out[] = $t; $n++;
        }
        if ($freq === 'DAILY') $d->modify('+' . $int . ' days');
        elseif ($freq === 'MONTHLY') { $day = (int)$d0->format('j'); $d->modify('first day of this month')->modify('+' . $int . ' months'); if (!$byday) { if ($day > (int)$d->format('t')) { continue; } $d->setDate((int)$d->format('Y'), (int)$d->format('n'), $day); } }
        elseif ($freq === 'YEARLY') $d->modify('+' . $int . ' years');
        else return $out;
        if ($d->getTimestamp() > $to && !$byday) return $out;
        if ($byday && (clone $d)->modify('first day of this month')->getTimestamp() > $to) return $out;
    }
    return $out;
}

// ---------- Créneaux disponibles ----------

function rdv_confirmes(PDO $pdo, string $fromDay, string $toDay): array {
    $st = $pdo->prepare("SELECT * FROM rdv WHERE statut = 'confirme' AND debut >= ? AND debut <= ? ORDER BY debut");
    $st->execute([$fromDay . ' 00:00', $toDay . ' 23:59']);
    return $st->fetchAll();
}

function creneaux(PDO $pdo, array $s, ?array $busyRes = null): array {
    $busyRes ??= ical_busy($pdo, $s);
    if (!$busyRes['ok']) return ['ok' => false, 'jours' => []];
    $now = now_paris();
    $min = time() + 3600 * max(0, (int)$s['delai']);
    $days = 7 * max(1, min(8, (int)$s['horizon']));
    $last = (clone $now)->modify('+' . $days . ' days');
    $taken = rdv_confirmes($pdo, $now->format('Y-m-d'), $last->format('Y-m-d'));
    $pause = max(0, (int)$s['pause']) * 60; $dur = max(10, (int)$s['duree']) * 60;
    $block = $busyRes['busy'];
    $perDay = [];
    foreach ($taken as $t) {
        $a = DateTime::createFromFormat('Y-m-d H:i', $t['debut'], tz())->getTimestamp();
        $b = DateTime::createFromFormat('Y-m-d H:i', $t['fin'], tz())->getTimestamp();
        $block[] = [$a - $pause, $b + $pause];
        $perDay[substr($t['debut'], 0, 10)] = ($perDay[substr($t['debut'], 0, 10)] ?? 0) + 1;
    }
    $out = [];
    for ($i = 0; $i <= $days; $i++) {
        $d = (clone $now)->setTime(0, 0)->modify('+' . $i . ' days');
        $ymd = $d->format('Y-m-d'); $wd = (int)$d->format('N');
        $list = [];
        $full = (int)$s['max_jour'] > 0 && ($perDay[$ymd] ?? 0) >= (int)$s['max_jour'];
        if (!$full) foreach ($s['plages'][$wd] ?? [] as [$a, $b]) {
            for ($m = hm($a); $m + $dur / 60 <= hm($b); $m += ($dur + $pause) / 60) {
                $st = (clone $d)->setTime(intdiv($m, 60), $m % 60)->getTimestamp(); $en = $st + $dur;
                if ($st < $min) continue;
                $free = true;
                foreach ($block as [$x, $y]) if ($x < $en && $y > $st) { $free = false; break; }
                if ($free) $list[] = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
            }
        }
        if ($wd < 6 || $list) $out[] = ['jour' => $ymd, 'sem' => JOURS_COURTS[$wd], 'n' => (int)$d->format('j'), 'mois' => (int)$d->format('n'), 'heures' => $list];
    }
    return ['ok' => true, 'jours' => $out];
}

// ---------- Liens de réservation ----------

function lien_creer(PDO $pdo, string $origine, array $c, string $contexte = ''): string {
    agenda_schema($pdo);
    $j = bin2hex(random_bytes(16));
    $now = now_paris();
    $pdo->prepare('INSERT INTO rdv_liens (jeton, cree_le, expire, contact_id, email, nom, site, origine, contexte) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$j, $now->format('Y-m-d H:i:s'), (clone $now)->modify('+' . LIEN_JOURS . ' days')->format('Y-m-d H:i:s'), (int)($c['id'] ?? 0),
                   (string)($c['email'] ?? ''), (string)($c['nom'] ?? ''), (string)($c['site'] ?? ''), $origine, mb_substr($contexte, 0, 200)]);
    return $j;
}
function lien_url(string $j): string { return 'https://' . config()['site_host'] . '/rendez-vous/?j=' . $j; }
function lien_get(PDO $pdo, string $j): ?array {
    if (!preg_match('/^[a-f0-9]{32}$/', $j)) return null;
    $st = $pdo->prepare('SELECT * FROM rdv_liens WHERE jeton = ?'); $st->execute([$j]);
    return $st->fetch() ?: null;
}

// Clé de l'abonnement aux rendez-vous (flux iCal pour Google Agenda)
function flux_cle(): string { return substr(hash_hmac('sha256', 'agenda-flux', config()['dashboard_hash'] . '|' . config()['dashboard_password'] . '|' . (setting_get(db(), 'totp_secret') ?? '')), 0, 32); }

function date_longue(string $ymdhm): string {
    $d = DateTime::createFromFormat('Y-m-d H:i', $ymdhm, tz());
    $mois = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    return mb_strtolower(JOURS[(int)$d->format('N')]) . ' ' . (int)$d->format('j') . ' ' . $mois[(int)$d->format('n') - 1] . ', ' . heure_fr($d->format('H:i'));
}

function ics_event(array $r, string $visio, bool $forClient): string {
    $f = fn($s) => DateTime::createFromFormat('Y-m-d H:i', $s, tz())->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
    $esc = fn($s) => str_replace(["\\", ";", ",", "\n"], ["\\\\", "\\;", "\\,", "\\n"], (string)$s);
    $title = $forClient ? 'Échange avec Rubriqua' : 'Rubriqua, ' . ($r['site'] !== '' ? preg_replace('/^www\./', '', (string)parse_url($r['site'], PHP_URL_HOST) ?: $r['site']) : $r['nom']);
    $min = (int)round((DateTime::createFromFormat('Y-m-d H:i', $r['fin'], tz())->getTimestamp() - DateTime::createFromFormat('Y-m-d H:i', $r['debut'], tz())->getTimestamp()) / 60);
    $desc = $forClient ? 'Échange de ' . $min . ' minutes avec un spécialiste SEO et GEO.' . ($visio !== '' ? "\nVisio : " . $visio : '')
                       : $r['nom'] . ' · ' . $r['email'] . ($r['tel'] !== '' ? ' · ' . $r['tel'] : '') . ($r['site'] !== '' ? "\nSite : " . $r['site'] : '') . ($r['sujet'] !== '' ? "\n" . $r['sujet'] : '') . ($visio !== '' ? "\nVisio : " . $visio : '');
    $l = ['BEGIN:VEVENT', 'UID:rdv-' . $r['jeton'] . '@rubriqua.com', 'DTSTAMP:' . gmdate('Ymd\THis\Z'), 'DTSTART:' . $f($r['debut']), 'DTEND:' . $f($r['fin']),
          'SUMMARY:' . $esc($title), 'DESCRIPTION:' . $esc($desc)];
    if ($visio !== '') $l[] = 'LOCATION:' . $esc($visio);
    $l[] = 'STATUS:' . ($r['statut'] === 'annule' ? 'CANCELLED' : 'CONFIRMED');
    $l[] = 'END:VEVENT';
    return implode("\r\n", $l);
}
function ics_wrap(string $events, string $name = 'Rubriqua'): string {
    return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Rubriqua//Agenda//FR\r\nCALSCALE:GREGORIAN\r\nMETHOD:PUBLISH\r\nX-WR-CALNAME:" . $name . "\r\n" . ($events !== '' ? $events . "\r\n" : '') . "END:VCALENDAR\r\n";
}
function google_add_url(array $r, string $visio): string {
    $f = fn($s) => DateTime::createFromFormat('Y-m-d H:i', $s, tz())->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
    return 'https://calendar.google.com/calendar/render?action=TEMPLATE&text=' . rawurlencode('Échange avec Rubriqua') . '&dates=' . $f($r['debut']) . '/' . $f($r['fin'])
        . '&details=' . rawurlencode('Échange avec un spécialiste SEO et GEO.' . ($visio !== '' ? "\nVisio : " . $visio : '')) . ($visio !== '' ? '&location=' . rawurlencode($visio) : '');
}
