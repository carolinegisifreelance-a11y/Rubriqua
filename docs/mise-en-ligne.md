# Mise en ligne du site

## Ce que contient le site

- Les pages en HTML, aux mêmes URL que le site WordPress.
- `api/contact.php` : reçoit les formulaires, envoie l'e-mail par la boîte IONOS et garde une copie du message.
- `api/hit.php` : mesure d'audience sans cookie.
- `tableau-de-bord/` : statistiques et messages reçus, protégé par mot de passe. Adresse : rubriqua.com/tableau-de-bord/
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
