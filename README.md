# Rubriqua

Refonte du site rubriqua.com, page par page.

## Voir la page d'accueil en local

1. Sur GitHub, bouton Code puis Download ZIP, et décompresser le dossier.
2. Ouvrir `index.html` dans le navigateur (double-clic).

Les liens internes pointent déjà vers les vrais slugs du site (/seogeo/, /contact/...). En local ils mènent à une erreur, c'est normal : ils fonctionneront une fois le site en ligne.

## Contenu du dépôt

- `index.html`, `css/style.css`, `js/main.js` : page d'accueil de la refonte
- `assets/images/web/` : versions allégées (WebP) des photos utilisées sur le site

- `assets/images/logo/` : logo Rubriqua (dragon et R)
- `assets/images/clients/` : logos clients (Ventouses-Soufflets, Babily, Jouets Menus Enfants, Kermesse Fêtes, Storkeo, Ombralis). Ombralis existe en SVG d'origine et en PNG, car le SVG contient une image intégrée.
- `assets/images/outils/` : logos des outils (Google Ads, Semrush, Ahrefs, Screaming Frog, HubSpot, ChatGPT, Claude, Zapier, Make, n8n)
- `assets/images/plateformes/` : logos des plateformes (WordPress, WooCommerce, Shopify, PrestaShop, Webflow)
- `assets/images/agence/` : logos des entreprises accompagnées en agence, nommés client-activité-industrie
- `assets/images/blog/` : images des articles, nommées d'après le slug de l'article
- `assets/images/decoratives/` : photos d'illustration. Section « Un SEO adapté à votre structure » : `tpe-startup-equipe.jpg`, `pme-salle-reunion.jpg`, `pmi-industrie-soudure.jpg`, `ecommerce-clavier.jpg`. Les autres (ads, social ads, automatisation, analytics, bureaux) servent aux pages services.
- `maquettes/` : sources des maquettes de travail (accueil, article, page service GEO). Ces fichiers servent de référence de design et ne s'ouvrent pas tels quels dans un navigateur.

## Éléments manquants

- Logos outils : Tag Manager, Meta, Brevo, Hotjar
- Images des articles « AI Max, Performance Max, AI Mode » et « Comment faire un audit SEO technique en 12 étapes »
- Version à fond transparent du logo Storkeo

## Charte

- Rouge #991915, gris #333333, noir #161616, beige #F4F2EE
- Titres et texte en Manrope, nom de marque en UnifrakturCook

## Formulaires et statistiques

En ouvrant les fichiers directement sur ton ordinateur, les formulaires et le tableau de bord ne fonctionnent pas : ils ont besoin de PHP, donc d'un hébergement. Voir docs/mise-en-ligne.md.
