# AGENTS.md

MÃĐmoire projet pour les agents. Application **Wallet** (PHP + MySQL) de gestion
de portefeuille crypto.

## Lancer l'application

```bash
# Base de donnÃĐes (MariaDB/MySQL) puis schÃĐma
mysql -u root -e "CREATE DATABASE IF NOT EXISTS wallet CHARACTER SET utf8mb4;"
mysql -u root wallet < schema.sql

# Variables d'environnement
cp .env.example .env      # renseigner DB_*, ADMIN_PASSWORD, CRYPTO_KEY...

# Serveur de dÃĐveloppement
export $(grep -v '^#' .env | xargs)
php -S localhost:8000
```

Ou : `docker compose up -d` (application sur http://localhost:8080).

## VÃĐrifications rapides

```bash
# Syntaxe de tous les fichiers PHP
find . -name "*.php" -print0 | xargs -0 -n1 php -l
```

## Points d'architecture

- `index.php` (racine) est le contrÃīleur frontal : authentification, inscription,
  espace admin, support. Il dÃĐlÃĻgue les pages lourdes au module `wallet/`.
- Le verrou admin global (ÃĐcran ÂŦ AccÃĻs restreint Âŧ) a ÃĐtÃĐ **retirÃĐ** le
  2026-10-11 : la racine `/` affiche la connexion utilisateur. L'espace admin
  reste protÃĐgÃĐ par une page de connexion dÃĐdiÃĐe sur `/?page=admin`.
- `wallet/` est le module d'origine (portefeuille, retrait Mvola, crons,
  diagnostics). Ses fichiers se chargent entre eux par leur nom grÃĒce au
  `set_include_path()` dÃĐfini dans `index.php`.
- `wallet/config.php` et `plisio_config.php` (racine) sont des shims pour les
  `require` relatifs du module.
- Les vues du module (`portefeuille.php`, `retrait_mvola.php`) ÃĐmettent leur
  propre document HTML complet ; elles ne doivent pas ÃŠtre incluses dans le
  gabarit de `index.php`.

## Conventions de sÃĐcuritÃĐ

- **Aucun secret en dur.** Toutes les clÃĐs (Plisio, Tron, MegaNode, Ankr, tokens
  cron/diag) et identifiants passent par des variables d'environnement
  (`getenv()`), documentÃĐes dans `.env.example`.
- Ne jamais committer `.env`, ni les fichiers `*.log`.

## Mise a jour 2026-10-11 : UI, Google, taux, Android

### Hebergement
- Port 12000 : base `wallet` (historique).
- Port 12001 : base `wallet_android` (dediee Android) â env dans `/tmp/wallet_android_env.sh` (hors git).
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
  eu-central-1) â gratuite et permanente (le Postgres Render gratuit expire en
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

### TRC20 retire (2026-10-11)
- Decision client : le reseau **TRC20 (TRON) est retire de l'application**
  (frais Plisio jugees trop eleves). Reseau unique : **BEP20 (BNB Smart Chain)**.
- Code : generation auto TRC20 supprimee (`portefeuille.php`), UI depots/retraits
  TRC20 supprimee, `retrait_mvola.php` force `network=BEP20` (PHP + JS), scan
  TRC20 (TronGrid/TronScan) retire de `check_wallet_deposits.php`,
  `create_invoice.php` facture en `USDT_BSC`, `ADMIN_ADRESSE_BINANCE_TRC20` et
  `TRON_API_KEY` supprimees de `blockchain_config.php`.
- Base : `user_wallets.wallet_trc20` remis a NULL ; aucun depot TRC20 en base.
- Page admin `wallet/admin_wallets.php` (`?page=admin_wallets`) : genere les 10
  adresses BEP20 d'un client / de tous les clients via Plisio.
- **Distribution automatique (progressive)** : a la creation de compte
  (verification e-mail `verify.php`, 1re connexion Google `index.php`), la
  fonction `provisionnerAdressesDepot()` cree la ligne `user_wallets` et genere
  **1 seule** adresse BEP20 Plisio (reponse rapide). Les 9 suivantes sont
  ajoutees **progressivement, 1 par ouverture du portefeuille**
  (`portefeuille.php` -> `completerAdressesDepotProgressive()`), jusqu'a 10.
  - `ensureUserBep20Addresses($pdo,$uid,$nombre,$maxNouvelles)` : le 4e
    parametre plafonne les creations d'un appel (evite 10 appels Plisio d'un
    coup sur une requete web).
  - Teste reellement : inscription -> 1 adresse + `wallet_bep20` renseigne ;
    9 visites -> 10 adresses distinctes.
  - Idempotent, ne fait jamais echouer une inscription (echecs Plisio journalises).
  - Generation forcee des 10 d'un coup : page admin `?page=admin_wallets`.
- â ïļ Le webhook Plisio accepte toujours les anciens `deposit_uid` numeriques
  (utile si une ancienne adresse TRC20 est encore utilisee) : rien a changer,
  le solde est credite par `deposit_uid` -> user_id.

## Durcissement / nettoyage prod (2026-10-11)

- **Faute corrigee** : `wallet/test_plisio.php` etait accessible publiquement
  (`/wallet/test_plisio.php`) et affichait TOUT l'historique Plisio (montants,
  adresses, retraits). `.htaccess` le bloque desormais, ainsi que
  `check_deposits.php` / `check_bep20.php` / `check_trc20.php`, `get_solde.php`,
  `admin_retraits_1mvola.php`, `retrait_mvola1.php` et tous les fichiers
  ÂŦ nom (1).php Âŧ.
- Fichiers supprimes (doublons / obsolÃĻtes, aucun n'etait route) :
  `wallet/check_wallet_deposits(1).php`, `historique(1).php`,
  `view_webhook_log(1).php`, `webhook_plisio_diag(1).php`,
  `diag_deposit .php` (espace), `retrait_mvola 20.php` (espace),
  `check_trc20.php` (vide), `check_bep20.php` (vide), `check_deposits.php`,
  `retrait_mvola1.php`, `admin_retraits_1mvola.php`.
- `cron_http.php` pointe directement sur `check_wallet_deposits.php`.
- Le blocage `.htaccess` n'affecte PAS les `require` serveur : le cron
  fonctionne toujours (verifie en live : ÂŦ Termine Âŧ).

### Cles blockchain (configurees 2026-10-11)
- `MEGANODE_API_KEY` (NodeReal), `ANKR_API_KEY`, `BSC_API_KEY` recuperees depuis
  le `wallet.zip` d'origine et configurees sur Render. Testees : NodeReal
  `eth_blockNumber`/`nr_getAssetTransfers` OK, Ankr OK.
- `TRON_API_KEY` n'est plus utilisee (TRC20 retire).
- â ïļ Ces cles provenaient d'un depot public : a regenerer cote fournisseurs si
  elles ont pu fuiter (Plisio, NodeReal, Ankr).

### Crons planifies a creer (cron-job.org)
- `https://tango-wallet.onrender.com/wallet/cron_http.php?token=<CRON_SECRET_TOKEN>` (toutes les 2-5 min)
- `https://tango-wallet.onrender.com/wallet/cron_update_taux.php?token=<CRON_SECRET_TOKEN>` (toutes les 30 min)
- `https://tango-wallet.onrender.com/wallet/log_taux_historique.php?secret=<CRON_SECRET>` (toutes les 30-60 min)

## Design system de l'interface (theme clair « Fintech », 2026)
- Feuille maitresse : `admin_style.css` (racine), chargee par `index.php`,
  `verify.php`, `go.php` et les pages `wallet/admin_*.php`.
- Theme CLAIR : canvas `#f2f5fa`, cartes blanches `#ffffff`, bordures `#e2e8f2`,
  encre `#0e1729`. Halos doux teal/indigo en fond + trame de points masquee.
- Jetons CSS `:root` : `--bg`, `--surface`, `--surface-2/3`, `--border`,
  `--border-strong`, `--text`, `--muted`, `--primary` (#0ea5a4), `--primary-2`
  (#22d3ee), `--accent` (#6366f1), `--ok`, `--err`, `--warn`, `--radius`,
  `--radius-sm`, `--shadow`, `--shadow-soft`, `--ring`, `--grad`, `--grad-accent`.
- Composants : `.topbar`/`.brand`, `.container`, `.card` (+ `.center`), `.form`/
  `.field`, `input/textarea/select` (focus anneau teal), `.btn` (+ `.secondary`/
  `.danger`/`.accent`/`.block`, reflet au survol), `.btn-google`, `.alert`
  (+ `.ok`/`.err`), `.grid`/`.tile`, `.sep`, `.check`, `.footer`, `.lock-*`,
  `.admin-*` (`.admin-nav` en pilules), `.hero-*`, `.eyebrow`.
- Barre de navigation mobile `.tabbar` : visible <= 640px, affichee dans
  `index.php` quand `$_SESSION['user_id']` est defini (classe `has-tabbar`).
- `wallet/portefeuille.php` : CSS inline (glassmorphism d'origine) + un bloc
  final « Theme clair Fintech » qui convertit tout le portefeuille en clair
  (fond, carte blanche sans bordure animee, balance teal lumineux, actions,
  cours USDT, reseaux/adresses, bottom-nav, modales historique, selecteur de
  langue). `<meta name="theme-color">` passe a `#f2f5fa`.
- Palette : teal/cyan pour les actions, indigo en accent, vert pour les valeurs
  positives et les depots, rouge pour les erreurs. Plus de rose `#ff2ea6`.

## Endpoints AJAX du portefeuille (pieges connus)
- `wallet/get_solde.php` : rafraichissement du solde (appele toutes les 5 s par
  `portefeuille.php` et `retrait_mvola.php`). Etait VIDE dans le depot d'origine
  et bloque par `.htaccess` -> reconstruit (solde brut - 2,5 %, arrondi bas) et
  retire de la liste des fichiers bloques. Ne pas le re-bloquer.
- `wallet/get_taux_historique.php` : courbe du cours (voir piege INTERVAL).
- `wallet/index.php` (8011 lignes) : ancien monolithe NON route (le routeur est
  `index.php` racine). Joignable en direct sur `/wallet/index.php` et fait
  appel a des fichiers inexistants (`check_manual_payment.php`, `mvola/...`) :
  ne pas le confondre avec l'app active, il n'est jamais inclus.

## Pieges de portabilite SQL (MySQL -> PostgreSQL)
- La couche `wallet/db.php` traduit seulement `INSERT IGNORE`,
  `ON DUPLICATE KEY UPDATE` et neutralise les `CREATE TABLE`. Elle ne traduit
  PAS `INTERVAL` ni `NOW()`.
- ⚠️ `INTERVAL ? HOUR` (avec parametre lie) est INVALIDE en PostgreSQL :
  erreur `42601 syntax error at or near "$1"`. Utiliser
  `NOW() - make_interval(hours => ?)` sous pgsql. C'etait la cause du
  graphique du portefeuille reste vide (endpoint `get_taux_historique.php`).
- Regle : eviter les intervalles parametres MySQL. Toujours choisir la variante
  selon `$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)`.
- Base de prod : projet Supabase `tango-wallet` (eu-central-1, ref
  `bwnnfemkyakgnjbnakja`), atteignable en lecture via l'API de gestion
  Supabase (`SUPABASE_ACCESS_TOKEN`).

## Deploiement Render
- Service : `tango-wallet` — id `srv-db5l3rbbc2fs7399arlg` (region Frankfurt,
  runtime Docker, branche `main`, autoDeploy actif).
- Declencher un build via l'API (token Render `rnd_...`, a garder hors depot) :
  ```bash
  curl -X POST -H "Authorization: Bearer $RENDER_API_KEY" \
    -H "Content-Type: application/json" \
    https://api.render.com/v1/services/srv-db5l3rbbc2fs7399arlg/deploys \
    -d '{"clearCache":"do_not_clear"}'
  ```
  Puis suivre `.../deploys/<id>` jusqu'a `status=live`.
- Rappel : le service gratuit s'endort apres 15 min d'inactivite (1er appel lent).

