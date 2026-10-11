# AGENTS.md

Mémoire projet pour les agents. Application **Wallet** (PHP + MySQL) de gestion
de portefeuille crypto.

## Lancer l'application

```bash
# Base de données (MariaDB/MySQL) puis schéma
mysql -u root -e "CREATE DATABASE IF NOT EXISTS wallet CHARACTER SET utf8mb4;"
mysql -u root wallet < schema.sql

# Variables d'environnement
cp .env.example .env      # renseigner DB_*, ADMIN_PASSWORD, CRYPTO_KEY...

# Serveur de développement
export $(grep -v '^#' .env | xargs)
php -S localhost:8000
```

Ou : `docker compose up -d` (application sur http://localhost:8080).

## Vérifications rapides

```bash
# Syntaxe de tous les fichiers PHP
find . -name "*.php" -print0 | xargs -0 -n1 php -l
```

## Points d'architecture

- `index.php` (racine) est le contrôleur frontal : authentification, inscription,
  espace admin, support, verrou admin en mode web. Il délègue les pages lourdes
  au module `wallet/`.
- `wallet/` est le module d'origine (portefeuille, retrait Mvola, crons,
  diagnostics). Ses fichiers se chargent entre eux par leur nom grâce au
  `set_include_path()` défini dans `index.php`.
- `wallet/config.php` et `plisio_config.php` (racine) sont des shims pour les
  `require` relatifs du module.
- Les vues du module (`portefeuille.php`, `retrait_mvola.php`) émettent leur
  propre document HTML complet ; elles ne doivent pas être incluses dans le
  gabarit de `index.php`.

## Conventions de sécurité

- **Aucun secret en dur.** Toutes les clés (Plisio, Tron, MegaNode, Ankr, tokens
  cron/diag) et identifiants passent par des variables d'environnement
  (`getenv()`), documentées dans `.env.example`.
- Ne jamais committer `.env`, ni les fichiers `*.log`.

## Mise a jour 2026-10-11 : UI, Google, taux, Android

### Hebergement
- Port 12000 : base `wallet` (historique).
- Port 12001 : base `wallet_android` (dediee Android) — env dans `/tmp/wallet_android_env.sh` (hors git).
- Utilisateur MariaDB `walletapp` / base `wallet_android`, accessible en TCP.

### APK Android (WebView)
- Projet : `/workspace/android-wallet-app` (package `com.tango.wallet`).
- MainActivity charge directement `<base>/wallet/portefeuille.php` ; User-Agent suffixe `MonAppAndroid/1.0`.
- SettingsActivity permet de changer l'URL d'hebergement (`wallet_prefs/base_url`).
- Build : `gradle :app:assembleDebug` (JDK 21, SDK `/home/openhands/android-sdk`).
- Conflit Kotlin resolu par `implementation platform('org.jetbrains.kotlin:kotlin-bom:1.8.22')`.

### Connexion Google
- Cablee dans `index.php` (`?page=google_callback`) : e-mail Google verifie = proprietaire du portefeuille.
- Requiert GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET / GOOGLE_REDIRECT_URI (sinon bouton masque).
- Redirect URI : `https://<domaine>/?page=google_callback`.

### Taux USDT/MGA
- `wallet/taux.php` : Binance P2P (tradeType SELL) moins marge 200 Ar ; cache 30 min.
- `wallet/cron_update_taux.php` : cron `*/30 * * * *`.
- Repli conteneur sans crontab : `wallet/run_taux_loop.sh` (boucle 30 min).

### Verification depots
- Webhook `wallet/webhook_plisio.php` : HMAC sha1 (ksort + serialize des POST), idempotent (txid), credite `user_wallets.solde`.

### Depot GitHub
- Depot : `rasonjonathan6-coder/tango-wallet` (prive, branche `main`).
- Release `v1.0.0` contient l'APK `tango-wallet-v1.0.0.apk`.
- Code source Android dans `android-app/` (package `com.tango.wallet`).
- `deploy.sh` : installation VPS automatique (Apache, PHP 8.2, MariaDB, HTTPS, crons).
