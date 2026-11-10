<?php
// Rubriqua, abonnement aux rendez-vous pris sur le site, à ajouter dans Google Agenda (Autre agenda, À partir de l'URL).
define('RUBRIQUA', true);
require __DIR__ . '/lib.php';
require __DIR__ . '/agenda_lib.php';
header('X-Robots-Tag: noindex, nofollow');
$pdo = db();
agenda_schema($pdo);
if (!hash_equals(flux_cle(), (string)($_GET['cle'] ?? ''))) { http_response_code(404); exit; }
$s = agenda_get($pdo);
$st = $pdo->prepare('SELECT * FROM rdv WHERE debut >= ? ORDER BY debut');
$st->execute([now_paris()->modify('-60 days')->format('Y-m-d') . ' 00:00']);
$ev = array_map(fn($r) => ics_event($r, (string)$s['visio'], false), $st->fetchAll());
header('Content-Type: text/calendar; charset=utf-8');
header('Cache-Control: no-store');
echo ics_wrap(implode("\r\n", $ev), 'Rendez-vous Rubriqua');
