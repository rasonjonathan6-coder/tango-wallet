<?php

/**
 * A appeler toutes les 30 minutes via cron (pas dans le navigateur).
 * Force une actualisation du taux USDT/MGA, même si le cache de 30 min
 * n'est pas encore expiré - ça garantit un taux toujours frais sans
 * dépendre du passage d'un client sur le site.
 *
 * Exemple de crontab (crontab -e sur le VPS) :
 * 0,30 * * * * php /chemin/vers/wallet/cron_update_taux.php >> /chemin/vers/logs/taux.log 2>&1
 */

require_once "../config.php";
require_once "taux.php";

$tauxBinance = fetchTauxBinanceP2P();

if($tauxBinance === null){
    echo date('Y-m-d H:i:s')." - échec récupération taux Binance P2P\n";
    exit(1);
}

$tauxClient = $tauxBinance - TAUX_MARGE_MGA;

$pdo->prepare("
    INSERT INTO taux_change (paire, taux_binance, marge, taux_client)
    VALUES ('USDT_MGA', ?, ?, ?)
")->execute([$tauxBinance, TAUX_MARGE_MGA, $tauxClient]);

echo date('Y-m-d H:i:s')." - taux mis à jour : ".$tauxBinance." (client : ".$tauxClient.")\n";
