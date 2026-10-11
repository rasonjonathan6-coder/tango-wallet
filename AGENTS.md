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
