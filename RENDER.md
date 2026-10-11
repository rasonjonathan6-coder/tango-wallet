# Déployer Tango Wallet sur Render (100 % gratuit)

Render héberge le **code** gratuitement (via Docker) et fournit une base
**PostgreSQL** gratuite. L'application est écrite pour MySQL, mais une couche
de compatibilité (`wallet/db.php`) traduit les requêtes automatiquement : le
code n'a pas été modifié.

> **Service déjà créé** : <https://tango-wallet.onrender.com>
> (base PostgreSQL dédiée hébergée chez Supabase, gratuite et permanente).

## Ce qui est déjà prêt dans le dépôt

- `Dockerfile` — image PHP 8.2 + Apache (extensions `pdo_mysql` et `pdo_pgsql`).
- `docker-entrypoint.sh` — adapte Apache au `$PORT` de Render.
- `wallet/db.php` — couche de compatibilité MySQL → PostgreSQL.
- `schema_pg.sql` — schéma PostgreSQL (19 tables), importé **automatiquement**
  au premier démarrage : aucune étape manuelle d'import de base.
- `render.yaml` — blueprint (déploiement en un clic).

## Option A — Blueprint (recommandé)

1. Va sur **https://dashboard.render.com** → connecte-toi avec GitHub.
2. **New +** → **Blueprint** → sélectionne le dépôt **`tango-wallet`**.
3. Render lit `render.yaml` et crée la base PostgreSQL + le service web.
4. Renseigne les variables marquées `sync: false` :

| Variable | Valeur |
|---|---|
| `ADMIN_PASSWORD` | ton mot de passe admin |
| `CALLBACK_BASE_URL` | `https://tango-wallet.onrender.com` |
| `GOOGLE_CLIENT_ID` | ton identifiant Google OAuth |
| `GOOGLE_CLIENT_SECRET` | ton secret Google OAuth |
| `GOOGLE_REDIRECT_URI` | `https://tango-wallet.onrender.com/google_callback` |
| `PLISIO_SECRET_KEY` | ta clé secrète Plisio |

## Option B — Utiliser une base PostgreSQL existante (Supabase, Neon…)

1. Crée un projet PostgreSQL gratuit (Supabase, Neon, Render…).
2. Dans Render → ton service → **Environment**, ajoute :
   - `DB_DRIVER` = `pgsql`
   - `DB_URL` = la chaîne de connexion complète
     (`postgresql://user:motdepasse@hote:5432/base`)
3. Redéploie. Le schéma est importé automatiquement au premier accès.

## Variables d'environnement reconnues

| Variable | Rôle | Défaut |
|---|---|---|
| `DB_DRIVER` | `mysql` ou `pgsql` | `mysql` |
| `DB_URL` | chaîne de connexion complète (prioritaire) | — |
| `DB_HOST` / `DB_PORT` / `DB_NAME` / `DB_USER` / `DB_PASS` | connexion détaillée | `127.0.0.1` / `3306` ou `5432` |
| `APP_ENV` | `production` masque les erreurs PHP | `dev` |
| `ADMIN_PASSWORD` | mot de passe du verrou admin | `admin` |
| `CRYPTO_KEY` | clé de chiffrement des mots de passe | — |
| `CALLBACK_BASE_URL` | URL publique (webhooks Plisio, Google OAuth) | — |

## Notes importantes

- **Base MySQL locale** : le code d'origine continue de fonctionner à
  l'identique. Si `DB_DRIVER` n'est pas défini, l'application utilise MySQL
  et aucun import automatique n'est déclenché.
- **Offre gratuite Render** : le service web s'endort après 15 min
  d'inactivité (premier accès ≈ 30 s). La base PostgreSQL gratuite de Render
  **expire au bout de 30 jours** : préférez Supabase ou Neon pour la
  production, ou vérifie la base avant expiration.
- **Dépôt** : `tango-wallet` doit être accessible par Render (public, ou
  connexion GitHub accordée à l'app Render). Ce dépôt est actuellement
  **public**.
