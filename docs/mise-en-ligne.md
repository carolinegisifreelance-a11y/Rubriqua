# Mise en ligne du site

## Ce que contient le site

- Les pages en HTML, aux mêmes URL que le site WordPress.
- `api/contact.php` : reçoit les formulaires, envoie l'e-mail par la boîte IONOS et garde une copie du message.
- `api/hit.php` : mesure d'audience sans cookie.
- `pilotage-rb7k2q/` : statistiques et messages reçus, protégé par mot de passe. Adresse : rubriqua.com/pilotage-rb7k2q/
- `api/config.php` : réglages secrets (mot de passe de la boîte e-mail, de la base, du tableau de bord). Ce fichier n'est jamais envoyé sur GitHub.

Le site a besoin de PHP. Il faut donc un hébergement PHP : l'offre PHP de Deploy Now, ou un hébergement web IONOS classique.

## Option 1, IONOS Deploy Now (mise à jour automatique depuis GitHub)

1. Dans IONOS, ouvrir Deploy Now, connecter GitHub et choisir le dépôt Rubriqua, offre PHP, centre de données en Allemagne (pour rester dans l'Union européenne).
2. Créer la base de données proposée par Deploy Now et noter ses accès.
3. Ajouter les réglages secrets dans GitHub, onglet Settings, puis Secrets and variables, puis Actions :
   - RUBRIQUA_DB_DSN : `mysql:host=HOTE;dbname=BASE;charset=utf8mb4`
   - RUBRIQUA_DB_USER et RUBRIQUA_DB_PASS
   - RUBRIQUA_MAIL_TO : contact@rubriqua.com
   - RUBRIQUA_SMTP_USER : contact@rubriqua.com
   - RUBRIQUA_SMTP_PASS : mot de passe de la boîte e-mail
   - RUBRIQUA_DASHBOARD_PASSWORD : mot de passe du tableau de bord, 12 caractères minimum
4. Faire écrire ces secrets dans `api/config.php` par le fichier de déploiement que Deploy Now crée dans `.github/workflows/`. Étape à faire ensemble une fois le projet créé, car le fichier dépend de Deploy Now.
5. Tester sur l'adresse provisoire : formulaire, tableau de bord, sitemap.
6. Relier rubriqua.com au projet. Ne toucher qu'aux enregistrements du site, jamais aux enregistrements MX de la messagerie.

## Option 2, hébergement web IONOS classique (envoi par SFTP)

1. Créer une base MariaDB dans l'espace IONOS.
2. Copier `api/config.example.php` en `api/config.php` et le remplir.
3. Envoyer tous les fichiers par SFTP à la racine du site.
4. Le dossier `donnees/` sert seulement si aucune base n'est configurée. Il est protégé par son fichier `.htaccess`.

## Après la mise en ligne

1. Envoyer un message test avec le formulaire et vérifier sa réception.
2. Se connecter au tableau de bord et cliquer sur Ne pas compter mes visites, sur chaque appareil utilisé (ordinateur, téléphone).
3. Déclarer le sitemap dans Search Console.
4. Suivre les erreurs 404 dans Search Console pendant plusieurs semaines.
5. Résilier l'hébergement WordPress seulement quand tout fonctionne, en gardant la sauvegarde.

## Ce que mesure le tableau de bord

- Visiteurs : un appareil sur une journée. La même personne revenue trois jours compte pour trois.
- Visiteurs venus des IA : ChatGPT, Perplexity, Copilot, Gemini, Claude et Mistral, repérés par le site d'origine ou par le paramètre utm_source que ChatGPT ajoute à ses liens.
- Les robots et les navigateurs automatiques ne sont pas comptés.
- Pas de durée de visite ni de taux de rebond : il faudrait suivre le visiteur, ce que la mesure sans cookie évite volontairement.

## Choix retenu, l'hébergement actuel (Hébergement pour WordPress Expand)

Le contrat WordPress actuel comprend un espace web de 49 Go, une base MariaDB, l'accès SFTP et SSH et PHP 8.3. Le nouveau site s'installe dans un dossier séparé de cet espace, sans rien acheter.

1. Créer un compte SFTP et SSH dans IONOS (Projets Web, Rubriqua, Paramètres avancés).
2. Créer le sous-domaine refonte.rubriqua.com et le relier au dossier du nouveau site.
3. Renseigner les secrets dans GitHub (liste en tête du fichier .github/workflows/deploy.yml).
4. Lancer la mise en ligne depuis l'onglet Actions de GitHub, bouton Run workflow.
5. Tester sur refonte.rubriqua.com, puis faire pointer rubriqua.com vers le même dossier.
6. Garder WordPress jusqu'aux vérifications finales, puis le désinstaller.

Le premier envoi ne supprime rien sur le serveur. Les suivants suppriment seulement, dans le dossier du site, les fichiers retirés du dépôt.

## Adresse du tableau de bord

https://rubriqua.com/pilotage-rb7k2q/ (absente du sitemap et du robots.txt, protégée par mot de passe).

## Double authentification du tableau de bord

La connexion demande le mot de passe, puis un code à 6 chiffres affiché par une application d'authentification sur le téléphone (Google Authenticator, Microsoft Authenticator, 1Password, Bitwarden...).

- Première connexion : après le mot de passe, un QR code s'affiche. Le scanner avec l'application, puis saisir le code pour activer la protection.
- Connexions suivantes : mot de passe, puis code de l'application. Un code ne sert qu'une fois.
- La session se ferme après 2 heures d'inactivité, et au plus tard après 12 heures.
- 5 essais de mot de passe et 5 essais de code par quart d'heure, au-delà l'accès est bloqué 15 minutes.
- Téléphone perdu ou changé : dans GitHub, onglet Actions, lancer Mise en ligne avec l'action reinitialiser-2fa. À la connexion suivante, un nouveau QR code s'affiche.

## Statistiques du tableau de bord

- Visiteurs déjà venus : appareils passés sur le site dans les 13 derniers mois, d'après une date de première visite gardée dans le navigateur (sans identifiant, mesure exemptée de consentement par la CNIL et décrite sur la page Cookies, avec un bouton pour la refuser).
- Pages d'entrée et parcours : une visite regroupe les pages vues par un même visiteur sans pause de plus de 30 minutes.
- Clics : liens et boutons cliqués, avec leur texte et leur destination.
- Remise à zéro : dans GitHub, onglet Actions, lancer Mise en ligne avec l'action vider-statistiques. Les visites et les clics sont effacés à la prochaine ouverture du tableau de bord. Les demandes de contact sont gardées.
