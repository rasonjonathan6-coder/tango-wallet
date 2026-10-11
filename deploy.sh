#!/usr/bin/env bash
# ============================================================
# Installation automatique de Tango Wallet sur un VPS Debian/Ubuntu.
#
# Usage (en root, sur un VPS neuf) :
#   bash deploy.sh wallet.mondomaine.com
#
# Installe : Apache + PHP 8.2 + MariaDB + HTTPS (Let's Encrypt),
# crée la base, importe le schéma, configure les crons.
# ============================================================
set -u

DOMAIN="${1:-}"
if [ -z "$DOMAIN" ]; then
    echo "Usage : bash deploy.sh <domaine>   (ex: bash deploy.sh wallet.mondomaine.com)"
    exit 1
fi

WEB_ROOT="/var/www/wallet"
DB_NAME="wallet_prod"
DB_USER="wallet"
DB_PASS="$(openssl rand -base64 24 | tr -d '/+=' | cut -c1-24)"
ADMIN_PASS="$(openssl rand -base64 18 | tr -d '/+=' | cut -c1-18)"
CRYPTO_KEY="$(openssl rand -hex 32)"

echo ">>> 1/7 Installation des paquets"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq apache2 php php-mysql php-curl php-mbstring \
    mariadb-server certbot python3-certbot-apache unzip git

echo ">>> 2/7 Activation des modules Apache"
a2enmod rewrite headers ssl >/dev/null 2>&1

echo ">>> 3/7 Création de la base de données"
mysql <<SQL
CREATE DATABASE IF NOT EXISTS ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

echo ">>> 4/7 Déploiement du code dans ${WEB_ROOT}"
mkdir -p "$WEB_ROOT"
# Si le script est lancé depuis le dossier du projet, on copie ; sinon on clone.
if [ -f "./index.php" ]; then
    cp -r ./* "$WEB_ROOT"/ 2>/dev/null
else
    echo "    Copiez le code de l'application dans ${WEB_ROOT} puis relancez l'etape 5."
fi

echo ">>> 5/7 Import du schéma"
if [ -f "${WEB_ROOT}/schema.sql" ]; then
    mysql "${DB_NAME}" < "${WEB_ROOT}/schema.sql"
fi

echo ">>> 6/7 Fichier de configuration .env"
cat > "${WEB_ROOT}/.env" <<ENV
APP_ENV=production
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASS=${DB_PASS}
ADMIN_PASSWORD=${ADMIN_PASS}
CRYPTO_KEY=${CRYPTO_KEY}
CALLBACK_BASE_URL=https://${DOMAIN}
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=https://${DOMAIN}/?page=google_callback
MAIL_HOST=
MAIL_PORT=587
MAIL_USER=
MAIL_PASS=
MAIL_FROM=no-reply@${DOMAIN}
MAIL_FROM_NAME=Tango Wallet
ENV
chmod 600 "${WEB_ROOT}/.env"
chown -R www-data:www-data "$WEB_ROOT"

echo ">>> 7/7 VirtualHost Apache + HTTPS"
cat > /etc/apache2/sites-available/wallet.conf <<VHOST
<VirtualHost *:80>
    ServerName ${DOMAIN}
    DocumentRoot ${WEB_ROOT}
    <Directory ${WEB_ROOT}>
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog \${APACHE_LOG_DIR}/wallet-error.log
    CustomLog \${APACHE_LOG_DIR}/wallet-access.log combined
</VirtualHost>
VHOST
a2ensite wallet >/dev/null 2>&1
a2dissite 000-default >/dev/null 2>&1
systemctl reload apache2
certbot --apache -d "${DOMAIN}" --non-interactive --agree-tos \
    --register-unsafely-without-email --redirect || \
    echo "    (Certbot a echoue : verifiez que le domaine pointe bien vers ce VPS)"

echo ">>> Crons de production"
( crontab -l 2>/dev/null | grep -v "cron_update_taux\|check_wallet_deposits" ; \
  echo "*/30 * * * * php ${WEB_ROOT}/wallet/cron_update_taux.php >> /var/log/wallet-taux.log 2>&1" ; \
  echo "*/5 * * * * php ${WEB_ROOT}/wallet/check_wallet_deposits.php >> /var/log/wallet-depots.log 2>&1" \
) | crontab -

echo ""
echo "============================================================"
echo " Installation terminee."
echo " URL        : https://${DOMAIN}"
echo " Base       : ${DB_NAME} (utilisateur ${DB_USER})"
echo " Admin      : mot de passe dans ${WEB_ROOT}/.env (ADMIN_PASSWORD)"
echo ""
echo " A FAIRE ENSUITE :"
echo "  1. Plisio : autoriser l'IP de ce VPS + URL webhook"
echo "     https://${DOMAIN}/wallet/webhook_plisio.php"
echo "  2. Google : ajouter l'URI de redirection"
echo "     https://${DOMAIN}/?page=google_callback"
echo "  3. SMTP   : renseigner MAIL_* dans ${WEB_ROOT}/.env"
echo "============================================================"
