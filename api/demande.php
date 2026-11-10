<?php
// Rubriqua, demandes faites depuis le résultat de l'audit GEO :
// rapport par e-mail (avec ou sans relevé de citations) et demande d'audit complet.
// L'audit est relancé ici, pour que le rapport corresponde à la page réellement lue.
define('RUBRIQUA', true);
require __DIR__ . '/lib.php';
require __DIR__ . '/audit_lib.php';
require __DIR__ . '/mail.php';
require __DIR__ . '/emails.php';

function out(bool $ok, string $msg, int $code = 200): void { json_out(['ok' => $ok, 'message' => $msg], $code); }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !same_origin()) out(false, 'Requête refusée.', 403);

// Pièges à robots : champ caché rempli, ou envoi trop rapide
$started = (int)($_POST['t'] ?? 0);
$elapsed = $started > 0 ? (int)(microtime(true) * 1000) - $started : 99999;
if (trim($_POST['societe_web'] ?? '') !== '' || $elapsed < 2000) out(true, 'C’est envoyé.');

$type = ($_POST['type'] ?? '') === 'complet' ? 'complet' : 'rapport';
$email = mb_strtolower(clean_line($_POST['email'] ?? '', 200));
$nom = clean_line($_POST['nom'] ?? '', 120);
$releve = $type === 'rapport' && !empty($_POST['releve']);
$produits = $releve ? clean_line($_POST['produits'] ?? '', 300) : '';
$concurrents = $releve ? clean_line($_POST['concurrents'] ?? '', 300) : '';
$message = $type === 'complet' ? mb_substr(trim(str_replace("\0", '', $_POST['message'] ?? '')), 0, 3000) : '';
$conseils = !empty($_POST['conseils']);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) out(false, 'Merci d’indiquer une adresse e-mail valide.', 422);
if ($type === 'complet' && $nom === '') out(false, 'Merci d’indiquer votre nom.', 422);
if ($releve && $produits === '') out(false, 'Indiquez vos produits ou services clés pour que nous posions les bonnes questions.', 422);

try {
    $pdo = db();
    if (rate_limited($pdo, 'demande', 5, 3600)) out(false, 'Trop de demandes en peu de temps. Merci de réessayer plus tard.', 429);
    $audit = run_audit((string)($_POST['url'] ?? ''));
} catch (AuditFail $e) {
    out(false, $e->getMessage(), 422);
} catch (Throwable $e) {
    error_log('Rubriqua demande : ' . $e->getMessage());
    out(false, 'L’envoi a échoué. Merci de réessayer dans quelques minutes.', 500);
}

$now = now_paris()->format('Y-m-d H:i:s');
$day = today();
$echeance = $releve ? business_days(RELEVE_JOURS) : ($type === 'complet' ? business_days(2) : '');
$kind = $type === 'complet' ? 'complet' : ($releve ? 'releve' : 'rapport');

try {
    // Contact : une ligne par adresse e-mail, mise à jour à chaque demande
    $st = $pdo->prepare('SELECT * FROM contacts WHERE email = ?');
    $st->execute([$email]);
    $contact = $st->fetch();
    if ($contact) {
        $pdo->prepare('UPDATE contacts SET maj_le = ?, site = ?, nom = CASE WHEN ? <> \'\' THEN ? ELSE nom END, conseils = CASE WHEN ? = 1 THEN 1 ELSE conseils END, conseils_le = CASE WHEN ? = 1 AND conseils = 0 THEN ? ELSE conseils_le END WHERE id = ?')
            ->execute([$now, $audit['url'], $nom, $nom, (int)$conseils, (int)$conseils, $now, $contact['id']]);
    } else {
        $pdo->prepare('INSERT INTO contacts (cree_le, maj_le, email, nom, site, conseils, conseils_le, jeton) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$now, $now, $email, $nom, $audit['url'], (int)$conseils, $conseils ? $now : '', bin2hex(random_bytes(16))]);
    }
    $st->execute([$email]);
    $contact = $st->fetch();

    $pdo->prepare('INSERT INTO demandes (cree_le, contact_id, type, site, score, audit, produits, concurrents, message, echeance) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$now, $contact['id'], $kind, $audit['url'], $audit['score'], json_encode($audit, JSON_UNESCAPED_UNICODE), $produits, $concurrents, $message, $echeance]);
    $demande = ['id' => (int)$pdo->lastInsertId(), 'produits' => $produits, 'concurrents' => $concurrents, 'message' => $message];

    // L'audit complet apparaît aussi dans les demandes de contact du tableau de bord
    if ($type === 'complet') {
        $pdo->prepare('INSERT INTO messages (cree_le, nom, email, site, message, page, envoye) VALUES (?, ?, ?, ?, ?, ?, 1)')
            ->execute([$now, $nom, $email, $audit['url'], 'Demande d’audit SEO et GEO complet, audit gratuit ' . $audit['score'] . "/100.\n\n" . $message, '/audit-geo-gratuit/']);
    }
} catch (Throwable $e) {
    error_log('Rubriqua demande, enregistrement : ' . $e->getMessage());
    out(false, 'L’envoi a échoué. Merci de réessayer dans quelques minutes.', 500);
}

// E-mail au visiteur
$m = $type === 'complet' ? complet_mail($audit, $contact, $day) : report_mail($audit, $contact, $releve, $echeance, $day);
$sent = send_mail_to($email, $m['subject'], $m['text'], $m['html']);
if ($sent) $pdo->prepare('UPDATE demandes SET mail_ok = 1' . ($kind === 'rapport' ? ', statut = \'envoye\', envoye_le = ?' : '') . ' WHERE id = ?')->execute($kind === 'rapport' ? [$now, $demande['id']] : [$demande['id']]);

// Alerte pour Rubriqua (pas pour un simple rapport)
if ($kind !== 'rapport') {
    $o = owner_mail($kind, $audit, $contact, $demande, $echeance);
    send_mail($o['subject'], $o['text'], $email, $nom !== '' ? $nom : $email);
}

if (!$sent) out(false, 'Votre demande est enregistrée, mais l’e-mail n’a pas pu partir. Nous revenons vers vous rapidement.');
if ($type === 'complet') out(true, 'Merci, votre demande est bien reçue. Nous revenons vers vous sous 48 heures. Un e-mail de confirmation vient de partir.');
out(true, $releve
    ? 'C’est envoyé. Le rapport arrive dans votre boîte e-mail d’ici quelques minutes. Votre relevé de citations suivra d’ici le ' . date_fr($echeance) . '.'
    : 'C’est envoyé. Le rapport arrive dans votre boîte e-mail d’ici quelques minutes.');
