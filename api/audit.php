<?php
// Rubriqua, audit GEO gratuit : affiche le résultat à l'écran.
// L'adresse analysée n'est jamais enregistrée. Seul un compteur anonyme limite le nombre de tests.
define('RUBRIQUA', true);
require __DIR__ . '/lib.php';
require __DIR__ . '/audit_lib.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !same_origin()) json_out(['ok' => false, 'message' => 'Requête refusée.'], 403);
try {
    if (rate_limited(db(), 'audit', 10, 3600)) json_out(['ok' => false, 'message' => 'Vous avez lancé beaucoup de tests. Réessayez dans une heure.'], 429);
} catch (Throwable $e) { error_log('Rubriqua audit : ' . $e->getMessage()); }
try {
    $r = run_audit((string)($_POST['url'] ?? ''));
    count_audit();
    json_out($r);
} catch (AuditFail $e) {
    json_out(['ok' => false, 'message' => $e->getMessage()]);
}
