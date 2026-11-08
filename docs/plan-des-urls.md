# Plan des URL de la refonte

Règle de base : chaque page actuelle garde exactement le même slug, barre oblique finale comprise. Aucune URL ne disparaît sans redirection 301 vers la page la plus proche.

## URL relevées sur le site actuel

Liste vérifiée le 8 novembre 2026 avec le sitemap Yoast du site (22 URL : 13 pages et 9 articles). Les 22 URL existent dans la refonte, au même slug. Les catégories du blog ne figurent pas dans les sitemaps de pages et d'articles : à vérifier dans le sitemap des catégories et dans Search Console.

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
| Blog | blog/index.html | /blog/ |
| Catégories | category/geo, seo, sea, analytics/index.html | /category/.../ |
| Articles (9) | <slug-de-l-article>/index.html | /<slug-de-l-article>/ |
| Contact | contact/index.html | /contact/ |
| Politique de confidentialité | politique-de-confidentialite/index.html | /politique-de-confidentialite/ |
| Cookies | cookies/index.html | /cookies/ |

Chaque page est rangée dans un dossier qui porte son slug, avec un index.html dedans, pour que l'URL reste identique une fois en ligne.

## Pages à créer plus tard

- Page mère IA : regroupe tous les services autour de l'IA (GEO, IA Ads, automatisation des tâches, prompts et skills IA). Les pages /seogeo/, /pubs-ia-chatgpt/ et /automation/ deviennent ses pages filles, avec un lien vers la page mère et entre elles. Slug à définir. Sur l'accueil, le bloc Les services IA pointera vers cette page.

## Avant la mise en ligne

1. Crawler l'ancien site et exporter toutes les URL indexables.
2. Crawler le nouveau site en local et comparer les deux listes.
3. Écrire une redirection 301 pour chaque URL absente du nouveau site.
4. Vérifier les balises canoniques, le sitemap (sitemap.xml, 22 URL) et le fichier robots.txt. Mettre à jour les dates lastmod du sitemap au jour de la mise en ligne, et y ajouter les catégories du blog si elles restent indexables. Les lignes wp-admin du robots.txt ne servent plus hors WordPress et peuvent être retirées.
5. Déclarer le sitemap dans Search Console.
6. Garder les mêmes titres SEO et méta descriptions, sauf choix volontaire.
7. Après la mise en ligne, suivre les erreurs 404 dans Search Console pendant plusieurs semaines.

## Maillage des articles à reprendre

Les textes des articles ont été récupérés sans leurs liens. Les liens internes vers des pages existantes ont été remis automatiquement. Restent à reprendre depuis WordPress :
- tous les liens externes vers les sources (Google Search Central, Search Engine Journal, PPC Land, Abondance, W3Techs, CERT-FR...) ;
- les liens vers les guides pas encore publiés, à activer à leur sortie : mots-clés industriels, fiches techniques en PDF, catalogues de pièces techniques, salons professionnels et référencement.
