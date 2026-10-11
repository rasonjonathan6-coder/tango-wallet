# Passage en production — Tango Wallet

Ce document liste tout ce qu'il reste à faire pour exploiter l'application
avec de l'argent réel. Les étapes sont classées par priorité.

---

## 0. Pré-requis techniques (fait / à vérifier)

- [x] Code durci : erreurs masquées en production (`APP_ENV=production`),
      `.htaccess` protège `config.php`, `.env`, scripts `diag_/fix_/cron_`.
- [x] Webhook Plisio signé (HMAC sha1) + idempotent (pas de double crédit).
- [x] Cron du taux USDT/MGA (Binance P2P) toutes les 30 minutes.
- [x] Connexion Google câblée (identifiant = e-mail Google vérifié).

---

## 1. BLOQUANT — Hébergement VPS avec HTTPS

L'application ne peut pas tourner sur un conteneur de développement pour de
l'argent réel. Il faut :

1. Un **VPS** (Debian/Ubuntu) avec Apache ou Nginx, PHP 8.2+ (extensions
   `pdo_mysql`, `curl`, `openssl`), MySQL/MariaDB.
2. Un **nom de domaine** (ex. `wallet-monapp.com`) pointant vers le VPS.
3. Un **certificat HTTPS** (Let's Encrypt / Certbot). Sans HTTPS :
   les cookies `session.cookie_secure` ne passent pas et Google OAuth refuse
   l'appel.
4. Copier le code dans `/var/www/wallet` (ou équivalent), pointer la racine
   web sur le dossier contenant `index.php`.

Commandes types :

```bash
sudo apt install apache2 php php-mysql php-curl mariadb-server certbot python3-certbot-apache
sudo certbot --apache -d wallet-monapp.com
```

---

## 2. BLOQUANT — Base de données de production

1. Créer la base et un utilisateur dédié (jamais `root`) :

```sql
CREATE DATABASE wallet_prod CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'wallet'@'localhost' IDENTIFIED BY 'MOT_DE_PASSE_LONG_ET_UNIQUE';
GRANT ALL PRIVILEGES ON wallet_prod.* TO 'wallet'@'localhost';
FLUSH PRIVILEGES;
```

2. Importer le schéma : `mysql wallet_prod < schema.sql`
3. Créer un fichier `.env` (hors git) avec les vraies valeurs
   (voir `.env.example`) et surtout :

```
APP_ENV=production
DB_HOST=127.0.0.1
DB_NAME=wallet_prod
DB_USER=wallet
DB_PASS=...
ADMIN_PASSWORD=...        # long et unique
CRYPTO_KEY=...            # chaîne aléatoire longue
```

---

## 3. BLOQUANT — Clés Plisio et autorisation IP

1. Renseigner `PLISIO_SECRET_KEY` (clé API de production).
2. **Autoriser l'IP sortante du VPS** dans Plisio (sinon les retraits échouent
   avec « Invalid IP address code 104 »). C'est l'étape qui débloque les
   retraits.
3. Configurer l'URL du webhook dans Plisio :
   `https://wallet-monapp.com/wallet/webhook_plisio.php`
   (Paramètres du shop → « Status URL » / IPN).
4. Vérifier avec `wallet/diag_plisio_config.php` (protégé par `DIAG_SECRET_TOKEN`)
   après avoir défini ce token.

---

## 4. BLOQUANT — Connexion Google

1. Google Cloud Console → API et services → Écran de consentement OAuth.
2. Créer un « ID client OAuth » de type **Application Web**.
3. URI de redirection autorisé : `https://wallet-monapp.com/?page=google_callback`
4. Renseigner dans `.env` :

```
GOOGLE_CLIENT_ID=....apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=...
GOOGLE_REDIRECT_URI=https://wallet-monapp.com/?page=google_callback
```

---

## 5. IMPORTANT — E-mails transactionnels

Le code de vérification d'inscription et les notifications de dépôt passent par
e-mail. Sans SMTP, ils tombent dans `/tmp/wallet-mails.log` (les utilisateurs ne
pourront pas s'inscrire). Configurer dans `.env` :

```
MAIL_HOST=smtp.votre-fournisseur.com
MAIL_PORT=587
MAIL_USER=...
MAIL_PASS=...
MAIL_FROM=no-reply@wallet-monapp.com
MAIL_FROM_NAME=Tango Wallet
```

---

## 6. IMPORTANT — Crons de production

À mettre dans `crontab -e` du VPS :

```cron
# Taux USDT/MGA toutes les 30 minutes
*/30 * * * * php /var/www/wallet/wallet/cron_update_taux.php >> /var/log/wallet-taux.log 2>&1

# Scan des dépôts blockchain (si activé) toutes les 5 minutes
*/5 * * * * php /var/www/wallet/wallet/check_wallet_deposits.php >> /var/log/wallet-depots.log 2>&1
```

---

## 7. IMPORTANT — Application Android (APK de production)

L'APK actuel est une version **debug**. Pour publier :

1. Créer un **keystore** et signer une version release :

```bash
keytool -genkey -v -keystore wallet-release.jks -keyalg RSA -keysize 2048 \
  -validity 10000 -alias wallet
```

2. Ajouter la signature dans `app/build.gradle` et générer
   `gradle :app:assembleRelease`.
3. Dans l'app, l'URL d'hébergement doit pointer sur le domaine HTTPS de
   production (écran « Changer d'hébergement »).
4. Publier l'APK sur le site (l'espace admin accepte une URL d'APK) ou sur
   le Play Store.

> Note : l'URL par défaut de l'APK est actuellement l'hébergement de test.
> Elle doit être changée avant diffusion aux clients.

---

## 8. Recommandé — Sauvegardes et supervision

- Sauvegarde quotidienne de la base : `mysqldump wallet_prod | gzip > ...`
- Sauvegarde du dossier `wallet/` (code + `.env`).
- Surveiller les logs : `wallet-mails.log`, `wallet-depots.log`, logs Apache.
- Activer le mode maintenance (`?page=admin`) pendant les migrations.

---

## 9. Recommandé — Vérifications avant ouverture

- [ ] Dépôt réel de bout en bout sur le domaine de production (petit montant).
- [ ] Retrait réel de bout en bout (petit montant) → confirme l'IP autorisée.
- [ ] Connexion Google → le compte créé voit bien **son** portefeuille.
- [ ] Inscription par e-mail : réception du code de vérification.
- [ ] Taux affiché cohérent (≈ Binance P2P − 200 Ar).
- [ ] Tester sur l'APK installé sur un vrai téléphone.

---

## Checklist express (les 5 bloquants)

| # | Élément | Qui |
|---|---------|-----|
| 1 | VPS + domaine + HTTPS | hébergeur |
| 2 | Base de production + `.env` | vous |
| 3 | IP autorisée dans Plisio + webhook | vous |
| 4 | Identifiants Google OAuth | vous |
| 5 | SMTP pour les e-mails | vous |
