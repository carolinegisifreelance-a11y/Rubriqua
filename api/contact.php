<?php
// Rubriqua, réception du formulaire de contact.
define('RUBRIQUA', true);
require __DIR__ . '/lib.php';
require __DIR__ . '/mail.php';

$wantsJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

function reply(bool $ok, string $msg, bool $json, int $code = 200): void {
    if ($json) json_out(['ok' => $ok, 'message' => $msg], $code);
    $back = '/contact/?envoi=' . ($ok ? 'ok' : 'erreur') . '#formulaire';
    header('Location: ' . $back, true, 303);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') reply(false, 'Méthode non autorisée.', $wantsJson, 405);
if (!same_origin()) reply(false, 'Requête refusée.', $wantsJson, 403);

// Pièges à robots : champ caché rempli, ou envoi trop rapide après l'ouverture de la page.
$honeypot = trim($_POST['societe_web'] ?? '');
$started  = (int)($_POST['t'] ?? 0);
$elapsed  = $started > 0 ? (int)(microtime(true) * 1000) - $started : 99999;
if ($honeypot !== '' || $elapsed < 2500) reply(true, 'Merci, votre message a bien été envoyé.', $wantsJson);

$nom     = clean_line($_POST['nom'] ?? '', 120);
$email   = clean_line($_POST['email'] ?? '', 200);
$site    = clean_line($_POST['site'] ?? '', 300);
$message = mb_substr(trim(str_replace("\0", '', $_POST['message'] ?? '')), 0, 5000);
$page    = clean_line($_POST['page'] ?? '', 300);

if ($nom === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    reply(false, 'Merci d’indiquer votre nom et une adresse e-mail valide.', $wantsJson, 422);
}
if ($site !== '' && !preg_match('#^https?://#i', $site)) $site = 'https://' . $site;
if ($site !== '' && !filter_var($site, FILTER_VALIDATE_URL)) $site = '';

try {
    $pdo = db();
    if (rate_limited($pdo, 'contact', 5, 3600)) {
        reply(false, 'Trop d’envois en peu de temps. Merci de réessayer plus tard.', $wantsJson, 429);
    }
    $created = now_paris()->format('Y-m-d H:i:s');
    $pdo->prepare('INSERT INTO messages (cree_le, nom, email, site, message, page) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$created, $nom, $email, $site, $message, $page]);
    $id = $pdo->lastInsertId();
} catch (Throwable $e) {
    error_log('Rubriqua contact : ' . $e->getMessage());
    $pdo = null; $id = null;
}

$body = "Nouvelle demande depuis rubriqua.com\n\n"
      . "Nom : $nom\nE-mail : $email\nSite : " . ($site ?: 'non renseigné') . "\n"
      . "Page : " . ($page ?: 'non renseignée') . "\n\nMessage :\n" . ($message ?: '(vide)') . "\n";
$sent = send_mail('Nouvelle demande de ' . $nom, $body, $email, $nom);

if ($pdo && $id && $sent) {
    $pdo->prepare('UPDATE messages SET envoye = 1 WHERE id = ?')->execute([$id]);
}

// Le message est enregistré même si l'e-mail échoue : il reste visible dans le tableau de bord.
if ($sent || $id) reply(true, 'Merci, votre message a bien été envoyé. Nous vous répondons sous 48 h.', $wantsJson);
reply(false, 'L’envoi a échoué. Merci de réessayer dans quelques minutes.', $wantsJson, 500);
