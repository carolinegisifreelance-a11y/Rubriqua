<?php
// Rubriqua, fonctions communes : configuration, base de données, utilitaires.
// Ce fichier n'est jamais appelé directement.

if (!defined('RUBRIQUA')) { http_response_code(404); exit; }

function config(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $defaults = [
        'db_dsn'        => '',            // ex. mysql:host=...;dbname=...;charset=utf8mb4
        'db_user'       => '',
        'db_pass'       => '',
        'sqlite_path'   => __DIR__ . '/../donnees/rubriqua.sqlite',
        'mail_to'       => '',            // adresse qui reçoit les demandes
        'mail_from'     => '',            // adresse d'envoi (boîte IONOS)
        'smtp_host'     => 'smtp.ionos.fr',
        'smtp_port'     => 465,
        'smtp_user'     => '',
        'smtp_pass'     => '',
        'dashboard_hash'=> '',            // password_hash() du mot de passe du tableau de bord
        'dashboard_password' => '',       // ou le mot de passe lui-même, s'il est fourni par un secret
        'site_host'     => 'rubriqua.com',
    ];
    $file = __DIR__ . '/config.php';
    $local = is_file($file) ? (require $file) : [];
    // Les variables d'environnement RUBRIQUA_* remplacent le fichier (utile pour Deploy Now).
    foreach ($defaults as $k => $v) {
        $env = getenv('RUBRIQUA_' . strtoupper($k));
        if ($env !== false && $env !== '') $local[$k] = $env;
    }
    $cfg = array_merge($defaults, is_array($local) ? $local : []);
    return $cfg;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $c = config();
    if ($c['db_dsn']) {
        $pdo = new PDO($c['db_dsn'], $c['db_user'], $c['db_pass']);
    } else {
        $dir = dirname($c['sqlite_path']);
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        $pdo = new PDO('sqlite:' . $c['sqlite_path']);
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    schema($pdo);
    return $pdo;
}

function is_sqlite(PDO $pdo): bool { return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'; }

function schema(PDO $pdo): void {
    $id = is_sqlite($pdo) ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $pdo->exec("CREATE TABLE IF NOT EXISTS visites (
        id $id, jour CHAR(10) NOT NULL, heure CHAR(5) NOT NULL, page VARCHAR(300) NOT NULL,
        source VARCHAR(40) NOT NULL, referent VARCHAR(120) NOT NULL DEFAULT '',
        appareil VARCHAR(12) NOT NULL, visiteur CHAR(16) NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS messages (
        id $id, cree_le CHAR(19) NOT NULL, nom VARCHAR(120) NOT NULL, email VARCHAR(200) NOT NULL,
        site VARCHAR(300) NOT NULL DEFAULT '', message TEXT NOT NULL, page VARCHAR(300) NOT NULL DEFAULT '',
        envoye TINYINT NOT NULL DEFAULT 0)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sels (jour CHAR(10) PRIMARY KEY, sel CHAR(64) NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS limites (cle VARCHAR(80) NOT NULL, quand INT NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS reglages (cle VARCHAR(40) PRIMARY KEY, valeur TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS clics (
        id $id, jour CHAR(10) NOT NULL, heure CHAR(5) NOT NULL, page VARCHAR(300) NOT NULL,
        cible VARCHAR(300) NOT NULL, libelle VARCHAR(80) NOT NULL DEFAULT '', visiteur CHAR(16) NOT NULL)");
    // Nouveau visiteur (1) ou appareil déjà venu (0), ajouté en novembre 2026
    $cols = is_sqlite($pdo)
        ? array_column($pdo->query("PRAGMA table_info(visites)")->fetchAll(PDO::FETCH_ASSOC), 'name')
        : array_column($pdo->query("SHOW COLUMNS FROM visites")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('nouveau', $cols, true)) $pdo->exec("ALTER TABLE visites ADD COLUMN nouveau TINYINT NULL");
    if (is_sqlite($pdo)) {
        $pdo->exec("CREATE INDEX IF NOT EXISTS i_visites_jour ON visites(jour)");
    }
}

function today(): string { return (new DateTime('now', new DateTimeZone('Europe/Paris')))->format('Y-m-d'); }
function now_paris(): DateTime { return new DateTime('now', new DateTimeZone('Europe/Paris')); }

// Sel du jour : renouvelé chaque jour, l'ancien est supprimé.
// Le même visiteur n'est donc reconnaissable que le temps d'une journée.
function daily_salt(PDO $pdo): string {
    $day = today();
    $st = $pdo->prepare('SELECT sel FROM sels WHERE jour = ?');
    $st->execute([$day]);
    $salt = $st->fetchColumn();
    if (!$salt) {
        $salt = bin2hex(random_bytes(32));
        try { $pdo->prepare('INSERT INTO sels (jour, sel) VALUES (?, ?)')->execute([$day, $salt]); }
        catch (PDOException $e) { $st->execute([$day]); $salt = $st->fetchColumn(); }
        $pdo->prepare('DELETE FROM sels WHERE jour <> ?')->execute([$day]);
    }
    return $salt;
}

function client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }

// Identifiant anonyme du jour : l'adresse IP n'est jamais enregistrée.
function anon_id(PDO $pdo, string $extra = ''): string {
    return substr(hash('sha256', daily_salt($pdo) . client_ip() . ($_SERVER['HTTP_USER_AGENT'] ?? '') . $extra), 0, 16);
}

// Limite le nombre d'actions par visiteur sur une période donnée.
function rate_limited(PDO $pdo, string $action, int $max, int $seconds): bool {
    $key = $action . ':' . anon_id($pdo);
    $now = time();
    $pdo->prepare('DELETE FROM limites WHERE quand < ?')->execute([$now - 86400]);
    $st = $pdo->prepare('SELECT COUNT(*) FROM limites WHERE cle = ? AND quand > ?');
    $st->execute([$key, $now - $seconds]);
    if ((int)$st->fetchColumn() >= $max) return true;
    $pdo->prepare('INSERT INTO limites (cle, quand) VALUES (?, ?)')->execute([$key, $now]);
    return false;
}

function same_origin(): bool {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
    if ($origin === '') return true; // certains navigateurs n'envoient rien
    $host = parse_url($origin, PHP_URL_HOST);
    $self = parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
    return $host && ($host === $self || $host === config()['site_host'] || $host === 'www.' . config()['site_host']);
}

function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function clean_line(string $s, int $max): string {
    $s = trim(preg_replace('/[\r\n\t\x00-\x1F\x7F]+/u', ' ', $s));
    return mb_substr($s, 0, $max);
}

// Nettoyage des données anciennes (visites 25 mois, messages 3 ans).
function purge(PDO $pdo): void {
    if (random_int(1, 50) !== 1) return;
    $pdo->prepare('DELETE FROM visites WHERE jour < ?')->execute([now_paris()->modify('-25 months')->format('Y-m-d')]);
    $pdo->prepare('DELETE FROM clics WHERE jour < ?')->execute([now_paris()->modify('-25 months')->format('Y-m-d')]);
    $pdo->prepare('DELETE FROM messages WHERE cree_le < ?')->execute([now_paris()->modify('-3 years')->format('Y-m-d H:i:s')]);
}

// Réglages enregistrés en base (clé, valeur)
function setting_get(PDO $pdo, string $key): ?string {
    $st = $pdo->prepare('SELECT valeur FROM reglages WHERE cle = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}
function setting_set(PDO $pdo, string $key, string $value): void {
    $pdo->prepare('DELETE FROM reglages WHERE cle = ?')->execute([$key]);
    $pdo->prepare('INSERT INTO reglages (cle, valeur) VALUES (?, ?)')->execute([$key, $value]);
}
function setting_delete(PDO $pdo, string $key): void {
    $pdo->prepare('DELETE FROM reglages WHERE cle = ?')->execute([$key]);
}

// Double authentification par application (TOTP, RFC 6238) : codes à 6 chiffres renouvelés toutes les 30 secondes.
// Compatible Google Authenticator, Microsoft Authenticator, 1Password, Bitwarden, etc.
function base32_encode(string $bin): string {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $chunk) $out .= $alpha[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}
function base32_decode(string $b32): string {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32))) as $c) $bits .= str_pad(decbin(strpos($alpha, $c)), 5, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
    return $out;
}
function totp_new_secret(): string { return base32_encode(random_bytes(20)); }
function totp_at(string $secret, int $step): string {
    $hmac = hash_hmac('sha1', pack('N2', 0, $step), base32_decode($secret), true);
    $o = ord($hmac[19]) & 0x0f;
    $n = ((ord($hmac[$o]) & 0x7f) << 24) | (ord($hmac[$o + 1]) << 16) | (ord($hmac[$o + 2]) << 8) | ord($hmac[$o + 3]);
    return str_pad((string)($n % 1000000), 6, '0', STR_PAD_LEFT);
}
// Vérifie le code avec une tolérance de 30 secondes, et refuse un code déjà utilisé.
function totp_verify(string $secret, string $code, int $lastStep = 0): int {
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) return 0;
    $now = intdiv(time(), 30);
    for ($d = -1; $d <= 1; $d++) {
        $step = $now + $d;
        if ($step > $lastStep && hash_equals(totp_at($secret, $step), $code)) return $step;
    }
    return 0;
}
