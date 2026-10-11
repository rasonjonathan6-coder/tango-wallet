#!/usr/bin/env bash
# ============================================================
# Superviseur de l'application Tango Wallet (mode hebergement).
#
# Relance automatiquement :
#   - le serveur PHP de l'app Android  (port 12001, base wallet_android)
#   - le serveur PHP historique        (port 12000, base wallet)
#   - la boucle de mise a jour du taux USDT/MGA (toutes les 30 min)
#
# Usage :  nohup bash /workspace/project/run_servers.sh > /tmp/supervisor.log 2>&1 &
# ============================================================

APP_DIR="/workspace/project"
ENV_FILE="/tmp/wallet_android_env.sh"

start_php() {
    local port="$1"
    local envfile="$2"
    if pgrep -f "php -S 0.0.0.0:${port}" >/dev/null 2>&1; then
        return
    fi
    if [ -n "$envfile" ] && [ -f "$envfile" ]; then
        nohup bash -c "source '$envfile'; exec php -S 0.0.0.0:${port} -t '$APP_DIR'" \
            > "/tmp/php${port}.log" 2>&1 &
    else
        nohup php -S "0.0.0.0:${port}" -t "$APP_DIR" > "/tmp/php${port}.log" 2>&1 &
    fi
    echo "$(date '+%F %T') - serveur PHP demarre sur ${port}"
}

while true; do
    start_php 12001 "$ENV_FILE"
    start_php 12000 ""

    if ! pgrep -f "run_taux_loop.sh" >/dev/null 2>&1; then
        nohup bash "$APP_DIR/wallet/run_taux_loop.sh" > /tmp/taux_cron.log 2>&1 &
        echo "$(date '+%F %T') - boucle taux relancee"
    fi

    sleep 30
done
