<?php
// Rubriqua, aperçu privé : affiche le nouveau site sur ce navigateur seulement.
// /refonte-site/apercu.php active l'aperçu, /refonte-site/apercu.php?fin=1 le désactive.
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
$fin = isset($_GET['fin']);
setcookie('rb_apercu', $fin ? '' : '1', [
    'expires' => $fin ? time() - 3600 : time() + 30 * 86400,
    'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
]);
header('Location: /', true, 302);
