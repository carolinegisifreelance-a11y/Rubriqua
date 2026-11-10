<?php
// Rubriqua, réservation d'un créneau : créneaux libres, réservation, annulation, fichier agenda du client.
define('RUBRIQUA', true);
require __DIR__ . '/lib.php';
require __DIR__ . '/agenda_lib.php';
require __DIR__ . '/mail.php';
require __DIR__ . '/emails.php';

header('X-Robots-Tag: noindex, nofollow');
$pdo = db();
agenda_schema($pdo);
$s = agenda_get($pdo);

function rdv_by_token(PDO $pdo, string $j): ?array {
    if (!preg_match('/^[a-f0-9]{32}$/', $j)) return null;
    $st = $pdo->prepare('SELECT * FROM rdv WHERE jeton = ?'); $st->execute([$j]);
    return $st->fetch() ?: null;
}
function manage_url(string $j): string { return 'https://' . config()['site_host'] . '/rendez-vous/?r=' . $j; }
function ics_url(string $j): string { return 'https://' . config()['site_host'] . '/api/rdv.php?ics=' . $j; }

// Lien valable : personnel (jeton) ou public si le réglage est ouvert
function access(PDO $pdo, array $s, string $j): array {
    if ($j === '') {
        if (empty($s['public']) || !agenda_ouvert($s, 'manuel')) return [null, 'Ce lien de réservation n’est pas valable.'];
        return [['jeton' => '', 'origine' => 'public', 'nom' => '', 'email' => '', 'site' => '', 'contexte' => '', 'contact_id' => 0], ''];
    }
    $l = lien_get($pdo, $j);
    if (!$l) return [null, 'Ce lien de réservation n’est pas valable.'];
    if ($l['utilise']) return [null, 'Ce lien a déjà servi pour un rendez-vous. Le mail de confirmation contient le lien pour le déplacer.'];
    if ($l['expire'] < now_paris()->format('Y-m-d H:i:s')) return [null, 'Ce lien a expiré. Répondez au mail reçu et nous vous enverrons un nouveau lien.'];
    if (empty($s['actif'])) return [null, 'La réservation en ligne est fermée pour le moment. Répondez au mail reçu avec deux ou trois créneaux.'];
    return [$l, ''];
}

// Fichier .ics du rendez-vous, pour Outlook, Apple Calendrier ou tout autre agenda
if (isset($_GET['ics'])) {
    $r = rdv_by_token($pdo, (string)$_GET['ics']);
    if (!$r) { http_response_code(404); exit; }
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="rendez-vous-rubriqua.ics"');
    echo ics_wrap(ics_event($r, (string)$s['visio'], true));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    if (isset($_GET['r'])) {
        $r = rdv_by_token($pdo, (string)$_GET['r']);
        if (!$r) json_out(['ok' => false, 'message' => 'Rendez-vous introuvable.'], 404);
        $past = $r['debut'] < now_paris()->format('Y-m-d H:i');
        json_out(['ok' => true, 'rdv' => ['quand' => ucfirst(date_longue($r['debut'])), 'minutes' => rdv_minutes($r), 'statut' => $r['statut'], 'passe' => $past, 'visio' => $r['statut'] === 'confirme' ? (string)$s['visio'] : '']]);
    }
    [$l, $err] = access($pdo, $s, (string)($_GET['j'] ?? ''));
    if (!$l) json_out(['ok' => false, 'message' => $err]);
    $c = creneaux($pdo, $s);
    if (!$c['ok']) json_out(['ok' => false, 'message' => 'Les créneaux ne sont pas disponibles pour le moment. Réessayez un peu plus tard, ou répondez au mail reçu.']);
    json_out(['ok' => true, 'duree' => (int)$s['duree'], 'nom' => $l['nom'], 'email' => $l['email'], 'site' => $l['site'], 'contexte' => $l['contexte'], 'jours' => $c['jours']]);
}

// POST
if (!same_origin()) json_out(['ok' => false, 'message' => 'Requête refusée.'], 403);
$in = json_decode((string)file_get_contents('php://input'), true) ?: [];
$action = (string)($in['action'] ?? '');

if ($action === 'annuler') {
    $r = rdv_by_token($pdo, (string)($in['r'] ?? ''));
    if (!$r) json_out(['ok' => false, 'message' => 'Rendez-vous introuvable.'], 404);
    if ($r['statut'] !== 'confirme') json_out(['ok' => false, 'message' => 'Ce rendez-vous est déjà annulé.']);
    if ($r['debut'] < now_paris()->format('Y-m-d H:i')) json_out(['ok' => false, 'message' => 'Ce rendez-vous est passé.']);
    $now = now_paris()->format('Y-m-d H:i:s');
    $pdo->prepare("UPDATE rdv SET statut = 'annule', annule_le = ? WHERE id = ?")->execute([$now, $r['id']]);
    $r['statut'] = 'annule';
    // Le lien d'origine resservira pour choisir un autre créneau
    $relink = '';
    if ($r['lien'] !== '') {
        $pdo->prepare('UPDATE rdv_liens SET utilise = 0, expire = CASE WHEN expire < ? THEN ? ELSE expire END WHERE jeton = ?')
            ->execute([now_paris()->modify('+7 days')->format('Y-m-d H:i:s'), now_paris()->modify('+7 days')->format('Y-m-d H:i:s'), $r['lien']]);
        $relink = lien_url($r['lien']);
    } elseif (!empty($s['public'])) $relink = 'https://' . config()['site_host'] . '/rendez-vous/';
    $m = rdv_annule_mail($r, $relink);
    send_mail_to($r['email'], $m['subject'], $m['text'], $m['html']);
    $o = rdv_owner_mail($r, true);
    send_mail($o['subject'], $o['text'], $r['email'], $r['nom']);
    json_out(['ok' => true, 'message' => 'Le rendez-vous est annulé. Un e-mail de confirmation vient de partir.', 'relink' => $relink]);
}

if ($action !== 'reserver') json_out(['ok' => false, 'message' => 'Action inconnue.'], 400);
if (trim((string)($in['site_web'] ?? '')) !== '' || (int)($in['t'] ?? 0) < 2000) json_out(['ok' => false, 'message' => 'Merci de réessayer.'], 400);
try { if (rate_limited($pdo, 'rdv', 5, 3600)) json_out(['ok' => false, 'message' => 'Trop de tentatives. Réessayez dans une heure.'], 429); } catch (Throwable $e) {}

[$l, $err] = access($pdo, $s, (string)($in['j'] ?? ''));
if (!$l) json_out(['ok' => false, 'message' => $err]);
$jour = (string)($in['jour'] ?? ''); $heure = (string)($in['heure'] ?? '');
$nom = clean_line((string)($in['nom'] ?? ''), 120);
$email = strtolower(clean_line((string)($in['email'] ?? ''), 200));
$tel = clean_line((string)($in['tel'] ?? ''), 40);
$sujet = mb_substr(trim(str_replace("\r", '', (string)($in['sujet'] ?? ''))), 0, 2000);
if ($nom === '') json_out(['ok' => false, 'message' => 'Indiquez votre nom.']);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_out(['ok' => false, 'message' => 'Vérifiez votre adresse e-mail.']);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $jour) || !preg_match('/^\d{2}:\d{2}$/', $heure)) json_out(['ok' => false, 'message' => 'Choisissez un jour et une heure.']);

// Le créneau est revérifié au dernier moment, agenda Google relu
$c = creneaux($pdo, $s, ical_busy($pdo, $s, true));
$free = false;
foreach ($c['jours'] as $d) if ($d['jour'] === $jour && in_array($heure, $d['heures'], true)) $free = true;
if (!$free) json_out(['ok' => false, 'message' => 'Ce créneau vient d’être pris. Choisissez-en un autre.', 'recharger' => true]);

$debut = $jour . ' ' . $heure;
$fin = DateTime::createFromFormat('Y-m-d H:i', $debut, tz())->modify('+' . (int)$s['duree'] . ' minutes')->format('Y-m-d H:i');
$now = now_paris()->format('Y-m-d H:i:s');
$jeton = bin2hex(random_bytes(16));
$site = $l['site'];
$pdo->beginTransaction();
$dup = $pdo->prepare("SELECT COUNT(*) FROM rdv WHERE statut = 'confirme' AND debut < ? AND fin > ?"); $dup->execute([$fin, $debut]);
if ((int)$dup->fetchColumn() > 0) { $pdo->rollBack(); json_out(['ok' => false, 'message' => 'Ce créneau vient d’être pris. Choisissez-en un autre.', 'recharger' => true]); }
$pdo->prepare('INSERT INTO rdv (cree_le, debut, fin, nom, email, tel, sujet, site, contact_id, lien, origine, jeton) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
    ->execute([$now, $debut, $fin, $nom, $email, $tel, $sujet, $site, (int)$l['contact_id'], $l['jeton'], $l['origine'], $jeton]);
if ($l['jeton'] !== '') $pdo->prepare('UPDATE rdv_liens SET utilise = 1 WHERE jeton = ?')->execute([$l['jeton']]);
if ((int)$l['contact_id'] > 0) $pdo->prepare('UPDATE contacts SET maj_le = ? WHERE id = ?')->execute([$now, (int)$l['contact_id']]);
$pdo->commit();

$r = ['debut' => $debut, 'fin' => $fin, 'nom' => $nom, 'email' => $email, 'tel' => $tel, 'sujet' => $sujet, 'site' => $site, 'origine' => $l['origine'], 'jeton' => $jeton, 'statut' => 'confirme'];
$m = rdv_client_mail($r, (string)$s['visio'], manage_url($jeton), ics_url($jeton));
$sent = send_mail_to($email, $m['subject'], $m['text'], $m['html']);
$o = rdv_owner_mail($r, false);
send_mail($o['subject'], $o['text'], $email, $nom);
json_out(['ok' => true, 'quand' => ucfirst(date_longue($debut)), 'minutes' => (int)$s['duree'], 'visio' => (string)$s['visio'], 'gcal' => google_add_url($r, (string)$s['visio']), 'ics' => ics_url($jeton), 'gerer' => manage_url($jeton),
    'message' => $sent ? 'Un e-mail de confirmation vient de partir à ' . $email . '.' : 'Le rendez-vous est enregistré, mais l’e-mail de confirmation n’a pas pu partir.']);
