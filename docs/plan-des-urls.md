# Plan des URL de la refonte

Règle de base : chaque page actuelle garde exactement le même slug, barre oblique finale comprise. Aucune URL ne disparaît sans redirection 301 vers la page la plus proche.

## URL relevées sur le site actuel

Liste tirée des liens de la page d'accueil le 8 novembre 2026. Elle doit être complétée par un crawl complet (Screaming Frog) ou par le sitemap du site avant la mise en ligne, pour ne rien oublier.

### Pages principales

| Page | URL |
|---|---|
| Accueil | / |
| À propos | /a-propos/ |
| Services | /services/ |
| SEO et GEO | /seogeo/ |
| SEA et Google Ads | /sea-googleads/ |
| Analytics et GA4 | /web-analytics/ |
| Publicité réseaux sociaux | /social-ads/ |
| Automatisation | /automation/ |
| IA Ads | /pubs-ia-chatgpt/ |
| Blog | /blog/ |
| Contact | /contact/ |
| Politique de confidentialité | /politique-de-confidentialite/ |
| Cookies | /cookies/ |

### Catégories du blog

| Catégorie | URL |
|---|---|
| GEO | /category/geo/ |
| SEO | /category/seo/ |
| Analytics | /category/analytics/ |
| SEA | /category/sea/ |

### Articles

| Article | URL |
|---|---|
| Actualité SEO et IA de fin octobre 2026 | /actualite-seo-ia-fin-octobre-2026/ |
| Être recommandé par ChatGPT quand un acheteur cherche un fournisseur industriel | /etre-recommande-chatgpt-industrie/ |
| Référencement d'un fabricant industriel | /referencement-fabricant-industriel/ |
| Faille PHP2Shell | /faille-php2shell-securite-referencement/ |
| Comment suivre sa visibilité dans les aperçus IA de Google | /comment-suivre-sa-visibilite-apercus-ia-google/ |
| Microsoft-Decision-1 | /microsoft-decision-1-change-pour-bing-et-copilot/ |
| Suivre les leads d'un cycle de vente long avec GA4 et GTM | /suivre-leads-cycle-vente-long-ga4-gtm/ |
| AI Max, Performance Max, AI Mode | /google-ads-ia-ai-max/ |
| Comment faire un audit SEO technique en 12 étapes | /comment-faire-un-audit-seo/ |

### URL techniques WordPress à vérifier

Pages d'auteur, d'archives par date, de pagination du blog (/blog/page/2/), de tags et de médias (pièces jointes). Si le crawl en trouve d'indexées, elles reçoivent une redirection 301 vers la page parente.

## Maillage interne de l'accueil

Chaque lien de la maquette doit pointer vers l'URL réelle ci-dessus.

| Élément de l'accueil | Destination |
|---|---|
| Menu Services et bouton Explorez nos services | /services/ |
| Carte SEO et GEO | /seogeo/ |
| Carte SEA | /sea-googleads/ |
| Carte Growth marketing | à définir, pas de page dédiée aujourd'hui |
| Carte Emailing | à définir, pas de page dédiée aujourd'hui |
| Carte Social ads et management | /social-ads/ |
| Bande IA Ads | /pubs-ia-chatgpt/ |
| Bouton Voir tous nos services | /services/ |
| Cartes du carrousel blog | URL de chaque article |
| Boutons Contact | /contact/ ou ancre du formulaire |
| Pied de page | mêmes URL que le menu, plus les pages légales |

Points ouverts :
- Growth marketing et Emailing apparaissent sur l'accueil mais n'ont pas de page. Soit on crée les pages, soit les cartes ne sont pas cliquables.
- Analytics et Automatisation ont une page mais ne sont pas sur l'accueil. Il faut au moins un lien depuis l'accueil ou la page Services pour qu'elles ne restent pas orphelines.
- Le pied de page actuel envoie SEO et GEO vers la même URL (/seogeo/). C'est à garder tel quel, ou à séparer si on crée une page GEO dédiée plus tard (avec redirection si le slug change).

## Fichiers de la refonte

| Page | Fichier | URL finale |
|---|---|---|
| Accueil | index.html | / |
| Services | services/index.html | /services/ |
| SEO et GEO | seogeo/index.html | /seogeo/ |
| SEA et Google Ads | sea-googleads/index.html | /sea-googleads/ |
| Analytics et GA4 | web-analytics/index.html | /web-analytics/ |
| Social ads | social-ads/index.html | /social-ads/ |
| Automatisation | automation/index.html | /automation/ |
| IA Ads | pubs-ia-chatgpt/index.html | /pubs-ia-chatgpt/ |
| À propos | a-propos/index.html | /a-propos/ |

Chaque page est rangée dans un dossier qui porte son slug, avec un index.html dedans, pour que l'URL reste identique une fois en ligne.

## Pages à créer plus tard

- Page mère IA : regroupe tous les services autour de l'IA (GEO, IA Ads, automatisation des tâches, prompts et skills IA). Les pages /seogeo/, /pubs-ia-chatgpt/ et /automation/ deviennent ses pages filles, avec un lien vers la page mère et entre elles. Slug à définir. Sur l'accueil, le bloc Les services IA pointera vers cette page.

## Avant la mise en ligne

1. Crawler l'ancien site et exporter toutes les URL indexables.
2. Crawler le nouveau site en local et comparer les deux listes.
3. Écrire une redirection 301 pour chaque URL absente du nouveau site.
4. Vérifier les balises canoniques, le sitemap et le fichier robots.txt.
5. Garder les mêmes titres SEO et méta descriptions, sauf choix volontaire.
6. Après la mise en ligne, suivre les erreurs 404 dans Search Console pendant plusieurs semaines.
