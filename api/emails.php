<?php
// Rubriqua, modèles des e-mails envoyés aux visiteurs (rapport d'audit, audit complet, relevé de citations).
if (!defined('RUBRIQUA')) { http_response_code(404); exit; }

const SITE_URL = 'https://rubriqua.com';
const LOGO_URL = SITE_URL . '/assets/images/logo/logo-rubriqua-agence-seo-geo-growth.png';
const RELEVE_JOURS = 5;

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

const MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
function date_fr(string $ymd): string { $d = new DateTime($ymd); return (int)$d->format('j') . ' ' . MOIS[(int)$d->format('n') - 1] . ' ' . $d->format('Y'); }
function date_fr_court(string $ymd): string { $d = new DateTime($ymd); return (int)$d->format('j') . ' ' . MOIS[(int)$d->format('n') - 1]; }

// Jours ouvrés : du lundi au vendredi
function business_days(int $n, ?DateTime $from = null): string {
    $d = $from ? clone $from : now_paris();
    while ($n > 0) { $d->modify('+1 day'); if ((int)$d->format('N') < 6) $n--; }
    return $d->format('Y-m-d');
}

function host_of(string $url): string { return preg_replace('/^www\./', '', strtolower((string)parse_url($url, PHP_URL_HOST))); }

// ---------- Mise en page commune ----------

const C_INK = '#1C1C1C'; const C_TEXT = '#3b3936'; const C_MUTED = '#77726b'; const C_RED = '#991915'; const C_BEIGE = '#F4F2EE'; const C_LINE = '#e4e0d9';
const STATUS = [
    'ok' => ['Validé', '#e8f1ea', '#2f6b3a'],
    'warn' => ['À améliorer', '#fbefe0', '#8a4b08'],
    'ko' => ['Bloquant', '#f6e8e7', '#991915'],
];

function tag(string $label, string $bg, string $fg): string {
    return '<span style="display:inline-block;font-size:12px;font-weight:bold;padding:3px 8px;border-radius:4px;background:' . $bg . ';color:' . $fg . ';white-space:nowrap">' . e($label) . '</span>';
}
function h_title(string $t, int $size = 17): string { return '<p style="margin:0 0 10px;font-size:' . $size . 'px;font-weight:bold;color:' . C_INK . '">' . e($t) . '</p>'; }
function para(string $html, string $style = ''): string { return '<p style="margin:0 0 16px;' . $style . '">' . $html . '</p>'; }
function button(string $label, string $url, bool $red = true): string {
    $st = $red ? 'background:' . C_RED . ';color:#ffffff;border:1px solid ' . C_RED : 'background:#ffffff;color:' . C_INK . ';border:1px solid ' . C_INK;
    return '<a href="' . e($url) . '" style="display:inline-block;' . $st . ';font-weight:bold;font-size:15px;padding:12px 18px;border-radius:6px;text-decoration:none">' . e($label) . '</a>';
}
function box(string $inner, string $bg = C_BEIGE): string {
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px"><tr><td style="background:' . $bg . ';border-radius:8px;padding:20px 22px">' . $inner . '</td></tr></table>';
}

function layout(string $inner, string $footer): string {
    return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#e9e6e0">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#e9e6e0"><tr><td align="center" style="padding:28px 12px">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px">'
        . '<tr><td style="background:#ffffff;border-radius:10px;padding:32px 30px 24px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:' . C_TEXT . '">'
        . '<img src="' . LOGO_URL . '" alt="Rubriqua" width="160" style="display:block;width:160px;height:auto;margin:0 0 26px;border:0">'
        . $inner
        . '<p style="margin:24px 0 0">Bonne journée,<br><b style="color:' . C_INK . '">L’équipe Rubriqua</b><br><span style="font-size:14px;color:' . C_MUTED . '">Agence SEO, GEO et growth · <a href="' . SITE_URL . '" style="color:' . C_MUTED . '">rubriqua.com</a></span></p>'
        . '</td></tr>'
        . '<tr><td style="padding:14px 10px;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.5;color:' . C_MUTED . '">' . $footer . '</td></tr>'
        . '</table></td></tr></table></body></html>';
}

function footer_note(string $why, array $contact): string {
    $f = e($why);
    if (!empty($contact['conseils'])) {
        $f .= ' Vous êtes inscrit à nos conseils SEO et GEO, un e-mail par mois. <a href="' . SITE_URL . '/api/desinscription.php?j=' . e($contact['jeton']) . '" style="color:' . C_MUTED . '">Se désinscrire</a>.';
    } else {
        $f .= ' Nous ne vous enverrons pas d’autre message sans votre accord.';
    }
    return $f;
}

function priorities(array $audit): array {
    $todo = [];
    foreach ($audit['groups'] as $g) foreach ($g['items'] as $i) if ($i['status'] !== 'ok') $todo[] = $i;
    usort($todo, fn($a, $b) => ($a['status'] === 'ko' ? 0 : 1) <=> ($b['status'] === 'ko' ? 0 : 1));
    return array_slice($todo, 0, 5);
}

// ---------- Rapport d'audit GEO ----------

function report_mail(array $audit, array $contact, bool $releve, string $echeance, string $day): array {
    $host = host_of($audit['url']);
    $color = $audit['score'] >= 80 ? '#2f6b3a' : ($audit['score'] >= 50 ? '#8a4b08' : C_RED);
    $c = $audit['counts'];
    $in = para('Bonjour,') . para('Voici le résultat de l’audit GEO de <b style="color:' . C_INK . '">' . e($audit['url']) . '</b>, réalisé le ' . e(date_fr($day)) . '.');
    $in .= box('<table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="padding-right:18px;font-size:44px;font-weight:bold;color:' . $color . ';line-height:1;white-space:nowrap">' . (int)$audit['score'] . '<span style="font-size:15px;color:' . C_MUTED . '">/100</span></td>'
        . '<td><b style="color:' . C_INK . '">' . e($audit['verdict']) . '</b><br><span style="font-size:13px;color:' . C_MUTED . '">' . (int)$c['ok'] . ' validés sur ' . (int)$audit['total'] . ', ' . (int)$c['warn'] . ' à améliorer, ' . (int)$c['ko'] . ($c['ko'] > 1 ? ' bloquants' : ' bloquant') . '</span></td></tr></table>');

    $prio = priorities($audit);
    if ($prio) {
        $in .= h_title('Vos priorités');
        $in .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 22px">';
        foreach ($prio as $n => $i) {
            [$l, $bg, $fg] = STATUS[$i['status']];
            $in .= '<tr><td style="padding:10px 0;border-top:1px solid ' . C_LINE . ';width:28px;font-weight:bold;color:' . C_INK . '">' . ($n + 1) . '.</td><td style="padding:10px 8px;border-top:1px solid ' . C_LINE . '"><b style="color:' . C_INK . '">' . e($i['name']) . '</b><br><span style="font-size:14px">' . e($i['note']) . '</span></td><td style="padding:10px 0;border-top:1px solid ' . C_LINE . ';text-align:right;vertical-align:top">' . tag($l, $bg, $fg) . '</td></tr>';
        }
        $in .= '</table>';
    } else {
        $in .= para(e($audit['conclusion']));
    }

    $in .= h_title('Le détail des ' . (int)$audit['total'] . ' critères');
    foreach ($audit['groups'] as $g) {
        $ok = count(array_filter($g['items'], fn($i) => $i['status'] === 'ok'));
        $in .= '<p style="margin:14px 0 4px;font-weight:bold;color:' . C_INK . '">' . e($g['name']) . ' <span style="color:' . C_MUTED . ';font-weight:normal">' . $ok . '/' . count($g['items']) . '</span></p>';
        $in .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px">';
        foreach ($g['items'] as $i) {
            [$l, $bg, $fg] = STATUS[$i['status']];
            $in .= '<tr><td style="padding:8px 8px 8px 0;border-top:1px solid ' . C_LINE . '"><b style="color:' . C_INK . '">' . e($i['name']) . '</b><br>' . e($i['note']) . '</td><td style="padding:8px 0;border-top:1px solid ' . C_LINE . ';text-align:right;vertical-align:top">' . tag($l, $bg, $fg) . '</td></tr>';
        }
        $in .= '</table>';
    }
    $in .= '<div style="height:18px"></div>';
    $in .= para('Ce test vérifie que votre page est prête à être citée. Il ne dit pas si les IA vous citent vraiment.', 'font-size:14px;color:' . C_MUTED);

    if ($releve) {
        $in .= box(h_title('Votre relevé de citations arrive') . '<p style="margin:0">Nous posons vos questions à ChatGPT, à Perplexity et aux Aperçus IA de Google, et nous comparons les réponses avec vos concurrents. Vous recevez le relevé d’ici le ' . e(date_fr($echeance)) . '.</p>');
    } else {
        $in .= box(h_title('Aller au-delà de cette page') . '<p style="margin:0 0 14px">Un audit SEO et GEO complet couvre tout le site, vos citations réelles dans les IA et un plan d’action.</p>'
            . button('Demander un audit SEO et GEO complet', SITE_URL . '/audit-geo-gratuit/?url=' . rawurlencode($audit['url']) . '&complet=1'));
    }

    $text = "Bonjour,\n\nVoici le résultat de l’audit GEO de {$audit['url']}, réalisé le " . date_fr($day) . ".\n\n"
        . "Score : {$audit['score']}/100\n{$audit['verdict']}\n\n";
    if ($prio) { $text .= "Vos priorités\n"; foreach ($prio as $n => $i) $text .= ($n + 1) . '. ' . $i['name'] . ' (' . STATUS[$i['status']][0] . ') : ' . $i['note'] . "\n"; $text .= "\n"; }
    foreach ($audit['groups'] as $g) { $text .= $g['name'] . "\n"; foreach ($g['items'] as $i) $text .= '- ' . $i['name'] . ' : ' . STATUS[$i['status']][0] . "\n"; $text .= "\n"; }
    $text .= $releve ? "Votre relevé de citations arrive d’ici le " . date_fr($echeance) . ".\n\n" : "Demander un audit SEO et GEO complet : " . SITE_URL . "/audit-geo-gratuit/?url=" . rawurlencode($audit['url']) . "&complet=1\n\n";
    $text .= "L’équipe Rubriqua\nrubriqua.com\n";

    $why = 'Vous recevez ce mail parce que vous avez demandé ce rapport sur rubriqua.com le ' . date_fr($day) . '.';
    return ['subject' => 'Votre audit GEO de ' . $host . ', score ' . (int)$audit['score'] . ' sur 100', 'text' => $text, 'html' => layout($in, footer_note($why, $contact))];
}

// ---------- Confirmation d'une demande d'audit complet ----------

function complet_mail(array $audit, array $contact, string $day, string $rdvUrl = ''): array {
    $host = host_of($audit['url']);
    $hello = $contact['nom'] !== '' ? 'Bonjour ' . $contact['nom'] . ',' : 'Bonjour,';
    $in = para(e($hello))
        . para('Merci pour votre demande d’audit SEO et GEO complet de <b style="color:' . C_INK . '">' . e($host) . '</b>. Nous l’avons bien reçue, avec le résultat de votre audit GEO gratuit (' . (int)$audit['score'] . '/100).')
        . para('Nous revenons vers vous sous 48 heures avec nos premières remarques sur votre site, et une proposition d’échange de 30 minutes pour cerner votre besoin.')
        . ($rdvUrl !== ''
            ? box(h_title('Pour gagner du temps') . '<p style="margin:0 0 14px">Réservez dès maintenant un échange gratuit de 30 minutes en visio avec notre expert SEO et GEO. Vous pouvez y noter ce que vous aimeriez améliorer en priorité.</p>' . button('Choisir un créneau', $rdvUrl))
            : box(h_title('Pour gagner du temps') . '<p style="margin:0">Répondez simplement à ce mail avec deux ou trois créneaux qui vous conviennent, et ce que vous aimeriez améliorer en priorité.</p>'));
    $text = "$hello\n\nMerci pour votre demande d’audit SEO et GEO complet de $host. Nous l’avons bien reçue, avec le résultat de votre audit GEO gratuit ({$audit['score']}/100).\n\n"
        . "Nous revenons vers vous sous 48 heures avec nos premières remarques sur votre site, et une proposition d’échange de 30 minutes pour cerner votre besoin.\n\n"
        . ($rdvUrl !== '' ? "Pour gagner du temps, réservez un échange gratuit de 30 minutes en visio avec notre expert SEO et GEO : $rdvUrl" : "Pour gagner du temps, répondez simplement à ce mail avec deux ou trois créneaux qui vous conviennent.") . "\n\nL’équipe Rubriqua\nrubriqua.com\n";
    $why = 'Vous recevez ce mail parce que vous avez demandé un audit complet sur rubriqua.com le ' . date_fr($day) . '.';
    return ['subject' => 'Votre demande d’audit SEO et GEO complet', 'text' => $text, 'html' => layout($in, footer_note($why, $contact))];
}

// ---------- Alerte pour Rubriqua ----------

function owner_mail(string $type, array $audit, array $contact, array $d, string $echeance): array {
    $host = host_of($audit['url']);
    $labels = ['rapport' => 'Rapport d’audit envoyé', 'releve' => 'Nouvelle demande de relevé', 'complet' => 'Demande d’audit complet'];
    $prio = priorities($audit);
    $t = $labels[$type] . ', ' . $host . "\n\n"
       . 'Contact : ' . ($contact['nom'] !== '' ? $contact['nom'] . ', ' : '') . $contact['email'] . "\n"
       . 'Page testée : ' . $audit['url'] . "\n";
    if ($d['produits'] !== '') $t .= 'Produits ou services : ' . $d['produits'] . "\n";
    if ($type === 'releve') $t .= 'Concurrents : ' . ($d['concurrents'] !== '' ? $d['concurrents'] : 'non renseignés, à repérer dans les réponses') . "\n";
    $t .= 'Conseils par e-mail : ' . (!empty($contact['conseils']) ? 'oui' : 'non') . "\n\n";
    $t .= 'Audit : ' . $audit['score'] . '/100, ' . $audit['counts']['ok'] . ' validés, ' . $audit['counts']['warn'] . ' à améliorer, ' . $audit['counts']['ko'] . " bloquant(s)\n";
    foreach ($prio as $i) $t .= '- ' . $i['name'] . ' (' . STATUS[$i['status']][0] . ")\n";
    if ($d['message'] !== '') $t .= "\nMessage :\n" . $d['message'] . "\n";
    if ($type === 'releve') $t .= "\nÀ envoyer avant le " . date_fr($echeance) . ".\n";
    if ($type === 'complet') $t .= "\nRéponse promise sous 48 heures.\n";
    $t .= "\nOuvrir dans le tableau de bord : " . SITE_URL . '/pilotage-rb7k2q/?v=' . ($type === 'releve' ? 'releves&d=' . $d['id'] : 'contacts&c=' . $contact['id']) . "\n";
    return ['subject' => $labels[$type] . ', ' . $host, 'text' => $t];
}

// ---------- Relevé de citations ----------
// $r : données préparées par l'outil du tableau de bord (questions, statuts, comptes, opportunités, commentaire)

const CELL = [
    'cite' => ['Source', '#e8f1ea', '#2f6b3a'],
    'nomme' => ['Sans lien', '#fbefe0', '#8a4b08'],
    'absent' => ['Absent', '#f6e8e7', '#991915'],
    'sans' => ['Sans Aperçu IA', '#efede9', '#5c5853'],
];
const OPP = [
    'place' => ['Place à prendre', '#efede9', '#5c5853'],
    'devant' => ['Concurrent devant', '#fbefe0', '#8a4b08'],
    'presque' => ['Presque cité', '#e8f1ea', '#2f6b3a'],
    'tierce' => ['Source tierce', '#efede9', '#5c5853'],
];

function releve_mail(array $r, array $contact, array $demande, string $day, string $rdvUrl = ''): array {
    $host = host_of($demande['site']);
    $ias = ['ChatGPT', 'Perplexity', 'Aperçus IA'];
    $cmp = $r['comparaison'] ?? [];
    $opps = array_values(array_filter($r['opportunites'] ?? [], fn($o) => !empty($o['garder'])));
    $produits = trim($demande['produits']);
    if ($produits !== '') $produits = mb_strtolower(mb_substr($produits, 0, 1)) . mb_substr($produits, 1);
    $conc = array_values(array_filter(array_map(fn($c) => $c['nom'], array_filter($cmp, fn($c) => empty($c['client'])))));

    $intro = 'Voici votre relevé offert, réalisé le ' . e(date_fr($day)) . '. Nous avons posé ' . count($r['questions']) . ' questions'
        . ($produits !== '' ? ' sur ' . e($produits) : ' de vos clients') . ' à ChatGPT, à Perplexity et aux Aperçus IA de Google.';
    if ($conc) $intro .= ' Nous avons comparé les réponses avec ' . e(implode(' et ', count($conc) > 1 ? [implode(', ', array_slice($conc, 0, -1)), end($conc)] : $conc)) . '.';
    $in = para('Bonjour' . ($contact['nom'] !== '' ? ' ' . e($contact['nom']) : '') . ',') . para($intro);

    // Comparaison
    if ($cmp) {
        $max = max(1, (int)$r['total']);
        $rows = '';
        foreach ($cmp as $c) {
            $w = max(2, (int)round($c['n'] / $max * 100));
            $col = !empty($c['client']) ? C_RED : '#5c5853';
            $name = !empty($c['client']) ? '<b style="color:' . C_RED . '">Votre site</b>' : e($c['nom']);
            $rows .= '<tr><td style="padding:5px 10px 5px 0;font-size:14px;white-space:nowrap">' . $name . '</td>'
                . '<td style="padding:5px 0;width:100%"><table role="presentation" width="' . $w . '%" cellpadding="0" cellspacing="0"><tr><td style="background:' . $col . ';height:12px;border-radius:3px;font-size:0;line-height:0">&nbsp;</td></tr></table></td>'
                . '<td style="padding:5px 0 5px 10px;font-weight:bold;white-space:nowrap;color:' . (!empty($c['client']) ? C_RED : C_INK) . '">' . (int)$c['n'] . '/' . $max . '</td></tr>';
        }
        $sub = (int)$r['nommes'] > 0 ? '<p style="margin:10px 0 0;font-size:13px;color:' . C_MUTED . '">Votre site est aussi nommé sans lien dans ' . (int)$r['nommes'] . ' réponse' . ($r['nommes'] > 1 ? 's' : '') . '.</p>' : '';
        $in .= box(h_title('Qui les IA citent sur vos sujets') . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $rows . '</table>' . $sub);
    }

    // Opportunités
    if ($opps) {
        $in .= h_title(count($opps) > 1 ? 'Vos ' . count($opps) . ' opportunités' : 'Votre opportunité', 19);
        foreach ($opps as $o) {
            [$l, $bg, $fg] = OPP[$o['type']] ?? OPP['place'];
            $in .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 10px"><tr><td style="border:1px solid ' . C_LINE . ';border-radius:8px;padding:14px 16px">'
                . tag($l, $bg, $fg) . '<p style="margin:8px 0 4px;font-weight:bold;color:' . C_INK . '">' . e($o['titre']) . '</p><p style="margin:0">' . nl2br(e($o['texte'])) . '</p></td></tr></table>';
        }
    }
    if (!empty($r['bloquant'])) {
        $in .= para('Votre audit GEO a aussi relevé un point bloquant : ' . e($r['bloquant']) . ' <a href="' . SITE_URL . '/audit-geo-gratuit/?url=' . rawurlencode($demande['site']) . '" style="color:' . C_RED . ';font-weight:bold">Revoir votre audit</a>', 'font-size:14px;margin-top:6px');
    }

    // Détail
    $in .= '<div style="height:8px"></div>' . h_title('Le détail question par question');
    $in .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;margin:0 0 20px"><tr><td style="padding:6px 6px 6px 0;font-size:11px;font-weight:bold;color:' . C_MUTED . ';border-bottom:1px solid ' . C_LINE . '">QUESTION POSÉE</td>';
    foreach ($ias as $ia) $in .= '<td style="padding:6px;font-size:11px;font-weight:bold;color:' . C_MUTED . ';border-bottom:1px solid ' . C_LINE . '">' . e(mb_strtoupper($ia)) . '</td>';
    $in .= '</tr>';
    foreach ($r['questions'] as $qi => $q) {
        $in .= '<tr><td style="padding:9px 6px 9px 0;border-bottom:1px solid #efece7;vertical-align:top">' . e($q) . '</td>';
        for ($ai = 0; $ai < 3; $ai++) {
            $cell = $r['cellules'][$qi][$ai] ?? ['etat' => 'absent'];
            [$l, $bg, $fg] = CELL[$cell['etat']] ?? CELL['absent'];
            if ($cell['etat'] === 'cite' && !empty($cell['pos'])) $l = 'Source ' . (int)$cell['pos'];
            $in .= '<td style="padding:9px 6px;border-bottom:1px solid #efece7;vertical-align:top">' . tag($l, $bg, $fg) . '</td>';
        }
        $in .= '</tr>';
    }
    $in .= '</table>';

    if (trim($r['commentaire'] ?? '') !== '') {
        $in .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px"><tr><td style="border-left:3px solid ' . C_INK . ';padding:4px 0 4px 16px;font-style:italic;color:' . C_INK . '">' . nl2br(e(trim($r['commentaire']))) . '</td></tr></table>';
    }
    $in .= para('Les réponses des IA changent selon le moment, la formulation et la personne. Ce relevé montre la situation au ' . e(date_fr($day)) . '.', 'font-size:13px;color:' . C_MUTED);

    $n = count($r['questions']);
    $in .= box(h_title('Échanger avec notre expert SEO et GEO') . '<p style="margin:0 0 14px">Nous vous proposons un échange gratuit de 30 minutes en visio avec notre expert. Nous revenons ensemble sur ce relevé, nous répondons à vos questions et nous voyons comment améliorer la visibilité de votre site sur Google et dans les IA.</p>'
        . ($rdvUrl !== '' ? button('Choisir un créneau', $rdvUrl) : '<p style="margin:0">Répondez simplement à ce mail avec deux ou trois créneaux qui vous conviennent.</p>'));

    // Version texte
    $t = 'Bonjour' . ($contact['nom'] !== '' ? ' ' . $contact['nom'] : '') . ",\n\n" . html_entity_decode(strip_tags($intro), ENT_QUOTES, 'UTF-8') . "\n\n";
    if ($cmp) { $t .= "Qui les IA citent sur vos sujets\n"; foreach ($cmp as $c) $t .= (!empty($c['client']) ? 'Votre site' : $c['nom']) . ' : ' . $c['n'] . '/' . $r['total'] . "\n"; $t .= "\n"; }
    if ($opps) { $t .= "Vos opportunités\n"; foreach ($opps as $o) $t .= '- ' . $o['titre'] . ' (' . (OPP[$o['type']][0] ?? '') . ') : ' . $o['texte'] . "\n"; $t .= "\n"; }
    $t .= "Le détail question par question\n";
    foreach ($r['questions'] as $qi => $q) {
        $t .= $q . "\n";
        foreach ($ias as $ai => $ia) { $cell = $r['cellules'][$qi][$ai] ?? ['etat' => 'absent']; $t .= '  ' . $ia . ' : ' . ($cell['etat'] === 'cite' ? 'cité, source ' . ($cell['pos'] ?? '') : CELL[$cell['etat']][0] ?? 'Absent') . "\n"; }
    }
    if (trim($r['commentaire'] ?? '') !== '') $t .= "\n" . trim($r['commentaire']) . "\n";
    $t .= "\nCe relevé montre la situation au " . date_fr($day) . ".\n\nÉchanger avec notre expert SEO et GEO\nNous vous proposons un échange gratuit de 30 minutes en visio avec notre expert. Nous revenons ensemble sur ce relevé, nous répondons à vos questions et nous voyons comment améliorer la visibilité de votre site sur Google et dans les IA.\n" . ($rdvUrl !== '' ? "Choisir un créneau : $rdvUrl" : "Répondez à ce mail avec deux ou trois créneaux qui vous conviennent.") . "\n\nL’équipe Rubriqua\nrubriqua.com\n";

    $why = 'Vous recevez ce mail parce que vous avez demandé un relevé sur rubriqua.com le ' . date_fr(substr($demande['cree_le'], 0, 10)) . '.';
    $subject = $opps ? count($opps) . ' opportunité' . (count($opps) > 1 ? 's' : '') . ' pour ' . $host . ' dans les IA' : 'Votre relevé de citations dans les IA, ' . $host;
    return ['subject' => $subject, 'text' => $t, 'html' => layout($in, footer_note($why, $contact))];
}

// ---------- Rendez-vous ----------

function rdv_minutes(array $r): int { return (int)round((DateTime::createFromFormat('Y-m-d H:i', $r['fin'])->getTimestamp() - DateTime::createFromFormat('Y-m-d H:i', $r['debut'])->getTimestamp()) / 60); }

function rdv_client_mail(array $r, string $visio, string $manageUrl, string $icsUrl): array {
    $quand = date_longue($r['debut']); $min = rdv_minutes($r);
    $hello = $r['nom'] !== '' ? 'Bonjour ' . $r['nom'] . ',' : 'Bonjour,';
    $in = para(e($hello)) . para('Votre échange avec notre expert SEO et GEO est confirmé.')
        . box('<p style="margin:0 0 4px;font-size:18px;font-weight:bold;color:' . C_INK . '">' . e(ucfirst($quand)) . '</p><p style="margin:0">' . $min . ' minutes en visio, heure de Paris'
            . ($visio !== '' ? '<br>Lien de la visio : <a href="' . e($visio) . '" style="color:' . C_RED . ';font-weight:bold">' . e(preg_replace('#^https?://#', '', $visio)) . '</a>' : '<br>Le lien de la visio vous sera envoyé avant le rendez-vous.') . '</p>')
        . '<p style="margin:0 0 20px">' . button('Ajouter à Google Agenda', google_add_url($r, $visio)) . ' &nbsp; ' . button('Autre agenda (.ics)', $icsUrl, false) . '</p>'
        . para('Un empêchement ? <a href="' . e($manageUrl) . '" style="color:' . C_RED . ';font-weight:bold">Déplacer ou annuler le rendez-vous</a>.', 'font-size:14px');
    $t = "$hello\n\nVotre échange avec notre expert SEO et GEO est confirmé.\n\n" . ucfirst($quand) . "\n$min minutes en visio, heure de Paris\n"
        . ($visio !== '' ? "Lien de la visio : $visio\n" : "Le lien de la visio vous sera envoyé avant le rendez-vous.\n")
        . "\nAjouter à votre agenda : $icsUrl\nDéplacer ou annuler : $manageUrl\n\nL’équipe Rubriqua\nrubriqua.com\n";
    return ['subject' => 'Rendez-vous confirmé, ' . $quand, 'text' => $t, 'html' => layout($in, e('Vous recevez ce mail parce que vous avez réservé un créneau sur rubriqua.com.'))];
}

function rdv_annule_mail(array $r, string $relink): array {
    $quand = date_longue($r['debut']);
    $hello = $r['nom'] !== '' ? 'Bonjour ' . $r['nom'] . ',' : 'Bonjour,';
    $in = para(e($hello)) . para('Votre rendez-vous du ' . e($quand) . ' est bien annulé.')
        . ($relink !== '' ? para('Vous pouvez choisir un autre créneau quand vous le souhaitez.') . '<p style="margin:0 0 8px">' . button('Choisir un autre créneau', $relink) . '</p>' : '');
    $t = "$hello\n\nVotre rendez-vous du $quand est bien annulé.\n" . ($relink !== '' ? "\nChoisir un autre créneau : $relink\n" : '') . "\nL’équipe Rubriqua\nrubriqua.com\n";
    return ['subject' => 'Rendez-vous annulé, ' . $quand, 'text' => $t, 'html' => layout($in, e('Vous recevez ce mail parce que vous avez annulé un rendez-vous sur rubriqua.com.'))];
}

function rdv_owner_mail(array $r, bool $annule): array {
    $quand = date_longue($r['debut']);
    $t = ($annule ? 'Rendez-vous annulé' : 'Nouveau rendez-vous') . ', ' . $quand . "\n\n"
       . 'Contact : ' . ($r['nom'] !== '' ? $r['nom'] . ', ' : '') . $r['email'] . ($r['tel'] !== '' ? ', ' . $r['tel'] : '') . "\n"
       . ($r['site'] !== '' ? 'Site : ' . $r['site'] . "\n" : '')
       . 'Origine : ' . (['releve' => 'mail du relevé', 'complet' => 'demande d’audit complet', 'manuel' => 'lien créé à la main', 'public' => 'lien public'][$r['origine']] ?? $r['origine']) . "\n"
       . ($r['sujet'] !== '' ? "\nÀ aborder :\n" . $r['sujet'] . "\n" : '')
       . "\nTableau de bord : " . SITE_URL . "/pilotage-rb7k2q/?v=agenda\n";
    return ['subject' => ($annule ? 'Annulé, ' : 'Rendez-vous, ') . $quand . ($r['site'] !== '' ? ', ' . host_of(str_contains($r['site'], '://') ? $r['site'] : 'https://' . $r['site']) : ''), 'text' => $t];
}
