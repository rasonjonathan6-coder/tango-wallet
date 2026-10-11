# Déployer Tango Wallet sur Render (100 % gratuit)

Render héberge le **code** gratuitement (via Docker). En revanche, Render ne
fournit **pas** de base MySQL sur l'offre gratuite : on utilise une base MySQL
gratuite externe (db4free.net). Les deux sont gratuits.

## Ce qui est déjà prêt dans le dépôt

- `Dockerfile` — image PHP 8.2 + Apache, écoute sur le port fourni par Render.
- `docker-entrypoint.sh` — adapte Apache au `$PORT` de Render.
- `render.yaml` — blueprint : tout le déploiement est décrit dedans.

## Étape 1 — Créer la base MySQL gratuite (5 min)

1. Va sur **https://www.db4free.net/signup.php**.
2. Crée une base, par exemple :
   - Database name : `tangowallet`
   - Username : `tangowallet`
   - Password : (choisis-en un solide)
3. Note les identifiants. L'hôte est toujours **`db4free.net`** (port `3306`).
4. Ouvre **https://www.db4free.net/phpMyAdmin/** → connecte-toi → onglet
   **Importer** → envoie le fichier `schema.sql` du dépôt.

## Étape 2 — Déployer sur Render (5 min)

1. Va sur **https://dashboard.render.com** → connecte-toi avec GitHub.
2. **New +** → **Blueprint**.
3. Sélectionne le dépôt **`tango-wallet`** → Render lit `render.yaml`.
4. Renseigne les variables demandées :

| Variable | Valeur |
|---|---|
| `DB_HOST` | `db4free.net` |
| `DB_NAME` | `tangowallet` |
| `DB_USER` | `tangowallet` |
| `DB_PASS` | ton mot de passe db4free |
| `ADMIN_PASSWORD` | ton mot de passe admin |
| `CALLBACK_BASE_URL` | `https://tango-wallet.onrender.com` |
| `GOOGLE_CLIENT_ID` | `965848382443-hrrbclfksfbts8qok2olgu1sn6on50df.apps.googleusercontent.com` |
| `GOOGLE_CLIENT_SECRET` | ton secret Google |
| `GOOGLE_REDIRECT_URI` | `https://tango-wallet.onrender.com/?page=google_callback` |
| `PLISIO_SECRET_KEY` | ta clé Plisio |

5. **Apply** → Render construit l'image et démarre l'app (~5 min).
6. Ton URL : **`https://tango-wallet.onrender.com`** (HTTPS inclus, permanent).

## Étape 3 — Google et Plisio

- **Google** → Identifiants → client OAuth → URI de redirection :
  `https://tango-wallet.onrender.com/?page=google_callback`
- **Plisio** → URL webhook :
  `https://tango-wallet.onrender.com/wallet/webhook_plisio.php`

## Limites honnêtes de l'offre gratuite

| Point | Conséquence |
|---|---|
| Mise en veille après 15 min sans visite | La 1re visite suivante prend ~30 s à répondre. |
| Pas de cron sur le plan gratuit | Le taux USDT/MGA ne se met pas à jour tout seul ; la page le recalcule à la demande, sinon utilise un service externe (cron-job.org) qui appelle l'URL toutes les 30 min. |
| db4free.net | Base gratuite, sans garantie de disponibilité. |
| Ressources limitées | Ok pour tester avec de vrais utilisateurs, pas pour un gros volume. |

**Pour un wallet avec de l'argent réel, un VPS reste recommandé** (voir
`deploy.sh` et `PRODUCTION.md`). Render gratuit est parfait pour démarrer et
tester sans rien payer.
