#!/usr/bin/env bash
# ============================================================================
# Point d'entree conteneur (Render / Docker).
#   - adapte Apache au port fourni par Render ($PORT, defaut 10000)
#   - active AllowOverride pour que .htaccess soit pris en compte
#   - expose les variables d'environnement a PHP
# ============================================================================
set -u

PORT="${PORT:-10000}"
echo "Listen ${PORT}" > /etc/apache2/ports.conf

# Le vhost par defaut ecoute sur 80 : on le bascule sur le port de Render.
if [ -f /etc/apache2/sites-available/000-default.conf ]; then
    sed -ri "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" \
        /etc/apache2/sites-available/000-default.conf
fi

cat > /etc/apache2/conf-available/render.conf <<'APACHE'
<Directory /var/www/html>
    AllowOverride All
    Require all granted
</Directory>
APACHE
a2enconf render >/dev/null 2>&1

exec "$@"
