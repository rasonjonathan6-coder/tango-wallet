# Wallet

Application web de gestion de portefeuille crypto (dépôts USDT TRC20 / BEP20,
retraits vers Mvola), écrite en PHP avec une base MySQL/MariaDB.

Ce dépôt contient l'application complète et exécutable, reconstruite à partir
de l'archive `wallet.zip` : le module `wallet/` fourni a été conservé tel quel,
et les fichiers de support manquants (configuration, e-mails, chiffrement,
schéma de base de données, contrôleur frontal) ont été réécrits.

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
