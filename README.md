# Wallet

Application web de gestion de portefeuille crypto (dépôts USDT TRC20 / BEP20,
retraits vers Mvola), écrite en PHP avec une base MySQL/MariaDB.

Ce dépôt contient l'application complète et exécutable, reconstruite à partir
de l'archive `wallet.zip` : le module `wallet/` fourni a été conservé tel quel,
et les fichiers de support manquants (configuration, e-mails, chiffrement,
schéma de base de données, contrôleur frontal) ont été réécrits.

## Téléchargements

- **APK Android** : [tango-wallet-v1.0.0.apk](https://github.com/rasonjonathan6-coder/tango-wallet/releases/download/v1.0.0/tango-wallet-v1.0.0.apk)
- **Application Android (source)** : dossier [`android-app/`](android-app/)

## Mise en production

Voir [PRODUCTION.md](PRODUCTION.md) pour la checklist complète, ou déployer sur
un VPS Debian/Ubuntu neuf avec un seul script :

```bash
bash deploy.sh wallet.mondomaine.com
```

## Fonctionnalités

- **Comptes utilisateurs** : inscription avec vérification par code e-mail,
  connexion, mot de passe oublié, connexion Google (optionnelle).
- **Portefeuille** : solde USDT, détection des dépôts TRC20 / BEP20,
  historique des transactions.
- **Retrait Mvola** : demande de retrait avec calcul des frais (grille Mvola
  officielle) et conversion USDT → Ariary.
- **Espace admin** : maintenance du site, montant configurable, publication
  d'APK, validation des demandes de retrait.
- **Paiement crypto** : factures Plisio, webhooks de confirmation.

## Prérequis

- PHP 8.1+ avec les extensions `pdo_mysql`, `curl`, `openssl`
- MySQL 8 ou MariaDB 10.4+

## Installation locale

```bash
# 1. Créer la base et importer le schéma
mysql -u root -p -e "CREATE DATABASE wallet CHARACTER SET utf8mb4;"
mysql -u root -p wallet < schema.sql

# 2. Configurer l'application
cp .env.example .env
# éditez .env (identifiants BDD, mot de passe admin, clés API...)

# 3. Charger les variables d'environnement puis lancer le serveur
export $(grep -v '^#' .env | xargs)
php -S localhost:8000
```

L'application est alors disponible sur <http://localhost:8000>.

## Installation avec Docker

```bash
docker compose up -d
```

- Application : <http://localhost:8080>
- La base est initialisée automatiquement avec `schema.sql`.

## Configuration

Toute la configuration passe par des variables d'environnement (voir
`.env.example`). Les principales :

| Variable | Rôle |
|---|---|
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` | Connexion à la base |
| `ADMIN_PASSWORD` | Mot de passe de l'espace admin (mode web) |
| `CRYPTO_KEY` | Clé de chiffrement des identifiants tiers |
| `PLISIO_SECRET_KEY` | Clé API Plisio (paiements crypto) |
| `TRON_API_KEY`, `MEGANODE_API_KEY`, `ANKR_API_KEY` | APIs blockchain |
| `MAIL_HOST`, `MAIL_USER`, `MAIL_PASS` | Envoi d'e-mails (SMTP) |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` | Connexion Google (optionnel) |

> ⚠️ **Sécurité** : aucun secret n'est présent dans le dépôt. Les clés API
> d'origine trouvées dans l'archive ont été retirées et doivent être fournies
> par variables d'environnement. Changez également `ADMIN_PASSWORD` et
> `CRYPTO_KEY` avant toute mise en production.

## Structure du projet

```
.
├── index.php                 # Contrôleur frontal (auth, admin, support)
├── verify.php                # Vérification du code d'inscription
├── go.php                    # Ouverture de l'application tierce
├── config.php                # Configuration + connexion PDO
├── crypto.php                # Chiffrement AES-256-GCM
├── mail.php                  # Envoi d'e-mails (SMTP / mail())
├── release_wallets.php       # Libération des wallets réservés
├── send_push.php             # Notifications push
├── schema.sql                # Schéma complet de la base
├── wallet/                   # Module wallet fourni (portefeuille, retraits, cron)
├── admin_style.css           # Feuille de style de l'application
└── Dockerfile, docker-compose.yml
```

## Scripts cron (optionnels)

- `wallet/check_wallet_deposits.php` : détection des dépôts blockchain.
- `wallet/cron_http.php?token=...` : déclenchement des crons via HTTP.
- `wallet/cron_update_taux.php` : mise à jour du taux USDT → MGA.

## Configuration Plisio (IP autorisée + Status URL)

Pour que les dépôts et les retraits fonctionnent, la boutique Plisio doit
autoriser l'IP du serveur et connaître l'URL du webhook.

1. **IP autorisée** — dans Plisio → Shop settings → *Allowed IP addresses*,
   ajoutez l'IP **sortante** du serveur. Pour la connaître :

   ```bash
   curl https://api.ipify.org
   ```

   ou ouvrez (avec le token de diagnostic) :

   ```
   https://VOTRE-DOMAINE/wallet/diag_plisio_config.php?token=VOTRE_TOKEN
   ```

2. **Status URL (webhook)** — renseignez l'URL qui reçoit les callbacks :

   ```
   https://VOTRE-DOMAINE/wallet/webhook_plisio.php
   ```

   Cette URL crédite le portefeuille à la confirmation d'un dépôt
   (`ipn_type = pay_in`). Pour les factures créées par `create_invoice.php`,
   le callback est `wallet/plisio_status.php`.

3. **White-label payment processing** — activez cette option dans Plisio pour
   obtenir des adresses de dépôt permanentes (`shops/deposit/new`).

4. **Variables d'environnement** — si l'instance en ligne n'est pas
   `deblocagetango.com`, surchargez les URLs :

   ```
   CALLBACK_BASE_URL=https://VOTRE-DOMAINE
   PLISIO_STATUS_URL=https://VOTRE-DOMAINE/wallet/webhook_plisio.php
   PLISIO_SUCCESS_URL=https://VOTRE-DOMAINE/success.php
   PLISIO_FAIL_URL=https://VOTRE-DOMAINE/failed.php
   ```

> Le webhook vérifie la signature HMAC (`verify_hash`) avec `PLISIO_SECRET_KEY`.
> Sans cette clé correctement configurée, tous les callbacks sont rejetés (422).
