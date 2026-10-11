<?php
/**
 * diag_plisio_balance_web.php
 *
 * Diagnostic accessible par navigateur : interroge l'API officielle Plisio
 * (GET /balances/{psys_cid}) pour connaître le VRAI solde disponible côté
 * Plisio, en USDT_BSC et USDT_TRX. Utile car le dashboard Plisio ("My
 * wallets") peut afficher autre chose que le solde réel des adresses de
 * dépôt "white-label" (créées via createPlisioWallet).
 *
 * A METTRE DANS LE DOSSIER wallet/ (à côté de plisio_api.php)
 *
 * IMPORTANT :
 *  1. Change IMPÉRATIVEMENT le token ci-dessous.
 *  2. SUPPRIME ce fichier du serveur une fois le diagnostic terminé.
 *
 * Usage :
 *   https://tondomaine.com/wallet/diag_plisio_balance_web.php?token=TON_TOKEN
 */

define("DIAG_PLISIO_BALANCE_TOKEN", getenv('DIAG_PLISIO_BALANCE_TOKEN') ?: '');

$tokenRecu = $_GET['token'] ?? '';

if (DIAG_PLISIO_BALANCE_TOKEN === '' || !hash_equals(DIAG_PLISIO_BALANCE_TOKEN, $tokenRecu)) {
    http_response_code(403);
    exit("forbidden");
}

header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . "/plisio_config.php";
require_once __DIR__ . "/plisio_api.php";

echo "===== Solde réel Plisio (via API officielle /balances) =====\n\n";

foreach(["USDT_BSC", "USDT_TRX"] as $devise){

    echo "--- $devise ---\n";

    $result = getPlisioBalance($devise);

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

    if(isset($result['data']['balance'])){
        echo "=> Solde disponible : " . $result['data']['balance'] . " $devise\n\n";
    } elseif(isset($result['data']['message'])){
        echo "=> Erreur API : " . $result['data']['message'] . "\n\n";
    }

}

echo "===== Fin du diagnostic =====\n";
