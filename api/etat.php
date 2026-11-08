<?php
// Rubriqua, vérification technique après mise en ligne (aucune donnée sensible).
define('RUBRIQUA', true);
require __DIR__ . '/lib.php';
$db = 'ok';
try { db()->query('SELECT COUNT(*) FROM visites')->fetchColumn(); } catch (Throwable $e) { $db = 'erreur'; error_log('Rubriqua état : ' . $e->getMessage()); }
$c = config();
json_out([
    'php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
    'base' => $db,
    'moteur' => $c['db_dsn'] ? 'mysql' : (extension_loaded('pdo_sqlite') ? 'sqlite' : 'sqlite absent'),
    'email_configure' => $c['mail_to'] !== '' && $c['smtp_user'] !== '' && $c['smtp_pass'] !== '',
    'tableau_de_bord_configure' => $c['dashboard_hash'] !== '' || strlen($c['dashboard_password']) >= 12,
]);
