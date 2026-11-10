<?php
// Rubriqua, désinscription des conseils SEO et GEO, en un clic depuis le lien de l'e-mail.
define('RUBRIQUA', true);
require __DIR__ . '/lib.php';

header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');
header('Content-Type: text/html; charset=utf-8');

$j = (string)($_GET['j'] ?? '');
$ok = false;
if (preg_match('/^[a-f0-9]{32}$/', $j)) {
    try {
        $st = db()->prepare('UPDATE contacts SET conseils = 0, conseils_le = ?, maj_le = maj_le WHERE jeton = ?');
        $st->execute([now_paris()->format('Y-m-d H:i:s'), $j]);
        $ok = $st->rowCount() > 0;
    } catch (Throwable $e) { error_log('Rubriqua désinscription : ' . $e->getMessage()); }
}
$title = $ok ? 'Vous êtes désinscrit' : 'Lien non reconnu';
$text = $ok ? 'Vous ne recevrez plus nos conseils SEO et GEO. Les rapports et relevés que vous demanderez vous seront toujours envoyés.'
            : 'Ce lien de désinscription n’est pas valide ou a déjà servi. Pour toute question, répondez à l’un de nos e-mails.';
?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title><?= $title ?> - Rubriqua</title>
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<style>body{margin:0;font-family:Manrope,system-ui,-apple-system,"Segoe UI",sans-serif;background:#F4F2EE;color:#3b3936;display:grid;place-items:center;min-height:100vh;padding:0 16px}main{max-width:460px;background:#fff;border:1px solid #e4e0d9;border-radius:10px;padding:32px;display:flex;flex-direction:column;gap:14px}h1{margin:0;font-size:24px;color:#1C1C1C}p{margin:0;line-height:1.6}a{color:#991915;font-weight:700}img{height:30px;width:auto;align-self:flex-start}</style></head>
<body><main><img src="/assets/images/logo/logo-rubriqua-agence-seo-geo-growth.svg" alt="Rubriqua"><h1><?= $title ?></h1><p><?= $text ?></p><p><a href="/">Retour sur rubriqua.com</a></p></main></body></html>
