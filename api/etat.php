<?php
// Rubriqua, vérification technique après mise en ligne (aucune donnée sensible).
define('RUBRIQUA', true);
require __DIR__ . '/lib.php';
$db = 'ok';
try { db()->query('SELECT COUNT(*) FROM visites')->fetchColumn(); } catch (Throwable $e) { $db = 'erreur'; error_log('Rubriqua état : ' . $e->getMessage()); }
$c = config();
// Clé de l'API Claude : présente, acceptée, et modèle des relevés disponible (vérifié au plus 6 fois par heure)
function ia_state(string $key): string {
    if ($key === '') return 'absente';
    try { if (rate_limited(db(), 'etat-ia', 6, 3600)) return 'configuree'; } catch (Throwable $e) { return 'configuree'; }
    $ch = curl_init('https://api.anthropic.com/v1/models?limit=100');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => ['x-api-key: ' . $key, 'anthropic-version: 2023-06-01']]);
    $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    if ($code === 401 || $code === 403) return 'refusee';
    if ($code !== 200) return 'injoignable (' . $code . ')';
    $ids = array_column(json_decode((string)$r, true)['data'] ?? [], 'id');
    return in_array('claude-haiku-5-5', $ids, true) ? 'ok' : 'ok, modele absent : ' . implode(', ', array_slice($ids, 0, 6));
}
json_out([
    'php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
    'base' => $db,
    'moteur' => $c['db_dsn'] ? 'mysql' : (extension_loaded('pdo_sqlite') ? 'sqlite' : 'sqlite absent'),
    'email_configure' => $c['mail_to'] !== '' && $c['smtp_user'] !== '' && $c['smtp_pass'] !== '',
    'tableau_de_bord_configure' => $c['dashboard_hash'] !== '' || strlen($c['dashboard_password']) >= 12,
    'ia' => ia_state($c['anthropic_key']),
]);
