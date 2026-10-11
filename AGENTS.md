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
  espace admin, support. Il délègue les pages lourdes au module `wallet/`.
- Le verrou admin global (écran « Accès restreint ») a été **retiré** le
  2026-10-11 : la racine `/` affiche la connexion utilisateur. L'espace admin
  reste protégé par une page de connexion dédiée sur `/?page=admin`.
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
- Redirect URI Render : `https://tango-wallet.onrender.com/google_callback`
  (route propre via `.htaccess` ; l'ancienne forme `?page=google_callback` marche aussi).

### Taux USDT/MGA
- `wallet/taux.php` : Binance P2P (tradeType SELL) moins marge 200 Ar ; cache 30 min.
- `wallet/cron_update_taux.php` : cron `*/30 * * * *`.
- Repli conteneur sans crontab : `wallet/run_taux_loop.sh` (boucle 30 min).

### Verification depots
- Webhook `wallet/webhook_plisio.php` : HMAC sha1 (ksort + serialize des POST), idempotent (txid), credite `user_wallets.solde`.

### Depot GitHub
- Depot : `rasonjonathan6-coder/tango-wallet` (public depuis 2026-10-11, branche `main`).
- Release `v1.0.0` contient l'APK `tango-wallet-v1.0.0.apk`.
- Code source Android dans `android-app/` (package `com.tango.wallet`).
- `deploy.sh` : installation VPS automatique (Apache, PHP 8.2, MariaDB, HTTPS, crons).

## Mise a jour 2026-10-11 : portage PostgreSQL + Render

L'app est ecrite pour MySQL ; Render ne propose pas de MySQL manage gratuit.
Une couche de compatibilite permet de tourner sur PostgreSQL sans modifier les
200+ requetes du code.

### Portabilite base de donnees
- `wallet/db.php` : classe `WalletPDO` (sous-classe de PDO) qui, en mode pgsql,
  traduit `INSERT IGNORE` -> `ON CONFLICT DO NOTHING`, `ON DUPLICATE KEY UPDATE`
  -> `ON CONFLICT (<1re colonne>) DO UPDATE SET ... EXCLUDED`, et neutralise les
  `CREATE TABLE` MySQL (le schema vient de `schema_pg.sql`).
- `schema_pg.sql` : 19 tables, genere depuis `schema.sql` par
  `tools/make_pg_schema.py`. Importe automatiquement au 1er demarrage si vide.
- `config.php` : accepte `DB_DRIVER` (mysql|pgsql) et `DB_URL` (chaine complete,
  prioritaire, deduit le pilote). Defaut inchange = MySQL, zero regression.
- Tests de non-regression MySQL OK, tests PostgreSQL OK (`tools/test_pg.php`).

### Render
- Dockerfile : PHP 8.2 + Apache, extensions `pdo_mysql`, `pdo_pgsql` (necessite
  `libpq-dev`), `curl`, `mbstring`.
- Service web : `tango-wallet` -> https://tango-wallet.onrender.com
  (id `srv-db5l3rbbc2fs7399arlg`, plan free, region frankfurt, Docker).
- Base : projet Supabase dedie `tango-wallet` (ref `bwnnfemkyakgnjbnakja`,
  eu-central-1) — gratuite et permanente (le Postgres Render gratuit expire en
  30 j). Chaine de connexion dans la variable `DB_URL` (pooler port 5432).
- Variables Render : `APP_ENV=production`, `DB_DRIVER=pgsql`, `DB_URL`,
  `ADMIN_PASSWORD`, `CRYPTO_KEY`, `CALLBACK_BASE_URL`.
- Le depot GitHub `tango-wallet` est passe en **public** pour que Render puisse
  le deployer (Render ne voit pas les depots prives sans autorisation de l'app).
- Guide : `RENDER.md`.

## Mise a jour 2026-10-11 (b) : acces web public + Google OAuth

- Verrou admin global retire : la racine `/` renvoie vers la connexion
  utilisateur (`?page=dashboard`). L'espace admin est sur `/?page=admin`
  (mot de passe `ADMIN_PASSWORD`), protege par la session.
- Route propre `/google_callback` (regle `.htaccess` mod_rewrite) -> index.php.
- Google OAuth configure sur Render (`GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`,
  `GOOGLE_REDIRECT_URI`). URI de redirection a enregistrer dans Google Cloud :
  `https://tango-wallet.onrender.com/google_callback`.
- Render n'auto-deploie pas toujours apres un changement de variables : forcer
  via `POST /v1/services/<id>/deploys` si le commit live ne bouge pas.

## Mise a jour 2026-10-11 (c) : Plisio operationnel

- `PLISIO_SECRET_KEY` configuree sur Render. **Format** : la vraie cle fait
  64 caracteres, de la forme `<32 hex>_<16 alphanum>` (ex:
  `fl49mtHMQz9lChQeuvEVBabE5DriJ3JHUE6fW6uV42MmcE4_BtVdLeyEMfEUi0ru`).
  La tronquer aux 32 premiers caracteres -> API Plisio repond **401
  invalid credentials** (le webhook, lui, valide alors des signatures fausses).
- Verifie en prod : `/balances/USDT_TRX`, `/balances/USDT_BSC`, `/operations`,
  `/shops/deposit/new` (white-label, renvoie `hash` = adresse) -> `success`.
- Webhook signe (HMAC-SHA1 sur `serialize()` du POST trie) valide en ligne :
  bonne cle -> 200, mauvaise cle -> 422.
- Cote Plisio (dashboard) : Status URL =
  `https://tango-wallet.onrender.com/wallet/webhook_plisio.php` ; laisser le
  champ **Request IP vide** (les IP sortantes Render gratuites sont partagees
  et variables ; une IP fixe Render coute ~100 $/mois).

## Mise a jour 2026-10-11 (d) : verification retrait / crons / check depot

### Check depot (`check_wallet_deposits.php`)
- Detecte les depots **TRC20** via TronScan (API publique, sans cle) : teste OK,
  12 depots reels detectes et signales. Repli BEP20 via RPC publics
  (`bsc-rpc.publicnode.com`, `bsc-dataseed.binance.org`) : OK.
- Le check **ne credite pas** le solde (anti double-credit) : il detecte, ecrit
  dans `wallet_deposits_diag_vus` et envoie une alerte admin. Le credit vient de
  `webhook_plisio.php` / `plisio_status.php`.
- Execution complete en ~3-16 s, sans erreur, sur PostgreSQL.

### Retrait (Mvola -> envoi USDT)
- `retrait_mvola.php` (client) : demande, calcul des frais, tout est coherent.
- `admin_retraits_mvola.php` (admin) : confirmation -> `plisioWithdraw()` vers
  l'adresse Binance admin, puis code USSD Mvola affiche. La fonction gere le
  cas ambigu (timeout) pour eviter les doubles envois.

### Crons
- `cron_update_taux.php` : OK (taux Binance 4317 -> client 4117 enregistre).
  Protege par jeton en acces web (CLI inchange).
- `cron_http.php` : execute `check_deposits.php` (verrou anti-concurrence).
- **Probleme corrige** : la regle `.htaccess` `^cron_` bloquait `cron_http.php`
  (403) ; retiree. `cron_http.php` et `log_taux_historique.php` redeviennent
  joignables, proteges par `CRON_SECRET_TOKEN` / `CRON_SECRET`.
- **Faille corrigee** : `hash_equals('', '')` renvoie TRUE, donc un jeton non
  configure laissait passer un jeton vide. Tous les scripts a jeton sont
  desormais fail-closed (verification `TOKEN === ''`).
- `log_taux_historique.php` etait accessible publiquement : fail-closed now.
- Cote Plisio : `plisio_status.php` est appele uniquement cote serveur (pas par
  Plisio) -> bloque en acces web.

### Cles blockchain manquantes (facultatif)
- `TRON_API_KEY`, `MEGANODE_API_KEY`, `ANKR_API_KEY` ne sont pas configurees.
  Le service fonctionne sans (repli TronScan + RPC publics), mais les clefs
  apportent des limites de debit plus elevees.

### Crons planifies a creer (cron-job.org)
- `https://tango-wallet.onrender.com/wallet/cron_http.php?token=<CRON_SECRET_TOKEN>` (toutes les 2-5 min)
- `https://tango-wallet.onrender.com/wallet/cron_update_taux.php?token=<CRON_SECRET_TOKEN>` (toutes les 30 min)
- `https://tango-wallet.onrender.com/wallet/log_taux_historique.php?secret=<CRON_SECRET>` (toutes les 30-60 min)

