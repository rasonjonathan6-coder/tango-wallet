#!/usr/bin/env bash
# Actualise le taux USDT/MGA toutes les 30 minutes.
# Sur un VPS avec cron, préférer la crontab :
#   */30 * * * * bash -c 'source /chemin/wallet_android.env.sh; php /chemin/wallet/cron_update_taux.php' >> /var/log/taux.log 2>&1
# Ce script est un repli pour les environnements sans crontab (conteneurs).
set -u
ENV_FILE="${ENV_FILE:-/workspace/project/wallet_android.env.sh}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
CRON_SCRIPT="${CRON_SCRIPT:-/workspace/project/wallet/cron_update_taux.php}"

while true; do
  # shellcheck disable=SC1090
  source "$ENV_FILE"
  "$PHP_BIN" "$CRON_SCRIPT" >> /tmp/taux_cron.log 2>&1
  sleep 1800
done
