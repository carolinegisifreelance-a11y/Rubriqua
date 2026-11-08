<?php
// Copier ce fichier en config.php (jamais envoyé sur GitHub) et le remplir.
// Chaque valeur peut aussi être fournie par une variable d'environnement RUBRIQUA_<NOM>,
// par exemple RUBRIQUA_SMTP_PASS.
if (!defined('RUBRIQUA')) { http_response_code(404); exit; }
return [
    // Base de données MariaDB fournie par l'hébergement. Laisser vide pour utiliser SQLite.
    'db_dsn'  => 'mysql:host=HOTE;dbname=NOM_DE_LA_BASE;charset=utf8mb4',
    'db_user' => '',
    'db_pass' => '',

    // E-mail : adresse qui reçoit les demandes, et boîte IONOS qui les envoie.
    'mail_to'   => 'contact@rubriqua.com',
    'mail_from' => 'contact@rubriqua.com',
    'smtp_host' => 'smtp.ionos.fr',
    'smtp_port' => 465,
    'smtp_user' => 'contact@rubriqua.com',
    'smtp_pass' => '',

    // Mot de passe du tableau de bord, sous forme chiffrée (voir docs/mise-en-ligne.md).
    'dashboard_hash' => '',
];
