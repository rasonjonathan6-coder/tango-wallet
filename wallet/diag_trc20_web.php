<?php
/**
 * diag_trc20_web.php
 *
 * Diagnostic accessible par navigateur (pas besoin de SSH) : teste
 * TronGrid (principal) puis TronScan (repli) pour une adresse TRC20
 * donnée, et affiche le résultat brut de chaque appel.
 *
 * A METTRE DANS LE DOSSIER wallet/ (à côté de blockchain_config.php)
 *
 * IMPORTANT :
 *  1. Change IMPÉRATIVEMENT le token ci-dessous.
 *  2. SUPPRIME ce fichier du serveur une fois le diagnostic terminé.
 *
 * Usage :
 *   https://tondomaine.com/wallet/diag_trc20_web.php?token=TON_TOKEN&adresse=T...
 *   (si &adresse= est omis, prend la 1ère adresse TRC20 trouvée en base)
 */

define("DIAG_TRC20_SECRET_TOKEN", getenv('DIAG_SECRET_TOKEN') ?: '');

$tokenRecu = $_GET['token'] ?? '';

if(!hash_equals(DIAG_TRC20_SECRET_TOKEN, $tokenRecu)){
    http_response_code(403);
    exit("forbidden");
}

header("Content-Type: text/plain; charset=utf-8");

require_once __DIR__ . "/../config.php";       // doit fournir $pdo
require_once __DIR__ . "/blockchain_config.php";

$usdtTrc20Contract = "TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t";

$addressTrc20 = trim($_GET['adresse'] ?? '');

if(!$addressTrc20){
    $stmt = $pdo->query("SELECT wallet_trc20 FROM user_wallets WHERE wallet_trc20 IS NOT NULL AND wallet_trc20 != '' LIMIT 1");
    $addressTrc20 = trim($stmt->fetchColumn() ?: '');
}

if(!$addressTrc20){
    echo "Aucune adresse TRC20 trouvée en base et aucune fournie via ?adresse=...\n";
    exit;
}

echo "=== Diagnostic TRC20 pour l'adresse : $addressTrc20 ===\n\n";

// ---------- 1) TronGrid (principal) ----------
echo "1. TronGrid (api.trongrid.io)\n";

$urlTronGrid = "https://api.trongrid.io/v1/accounts/".$addressTrc20
    ."/transactions/trc20?limit=20&contract_address=".$usdtTrc20Contract;

$ch = curl_init($urlTronGrid);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["TRON-PRO-API-KEY: ".TRON_API_KEY]);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$debut = microtime(true);
$reponse = curl_exec($ch);
$dureeMs = round((microtime(true) - $debut) * 1000);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErreur = curl_error($ch);
curl_close($ch);

if($reponse === false){
    echo "   ECHEC CURL : $curlErreur\n\n";
} else {
    echo "   HTTP $httpCode | {$dureeMs}ms\n";
    echo "   Réponse brute : ".$reponse."\n\n";

    $data = json_decode($reponse, true);
    if(!empty($data['success'])){
        echo "   -> OK, ".count($data['data'] ?? [])." transfert(s) trouvé(s)\n\n";
    } else {
        echo "   -> ECHEC (success=false ou absent)\n\n";
    }
}

// ---------- 2) TronScan (repli) ----------
echo "2. TronScan (apilist.tronscanapi.com)\n";

$urlTronScan = "https://apilist.tronscanapi.com/api/token_trc20/transfers"
    ."?limit=20&start=0&sort=-timestamp&count=true"
    ."&relatedAddress=".$addressTrc20
    ."&contract_address=".$usdtTrc20Contract;

$ch = curl_init($urlTronScan);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$debut = microtime(true);
$reponse = curl_exec($ch);
$dureeMs = round((microtime(true) - $debut) * 1000);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErreur = curl_error($ch);
curl_close($ch);

if($reponse === false){
    echo "   ECHEC CURL : $curlErreur\n\n";
} else {
    echo "   HTTP $httpCode | {$dureeMs}ms\n";
    echo "   Réponse brute : ".$reponse."\n\n";

    $data = json_decode($reponse, true);
    if(isset($data['token_transfers']) && is_array($data['token_transfers'])){
        echo "   -> OK, ".count($data['token_transfers'])." transfert(s) trouvé(s)\n";
        if(!empty($data['token_transfers'][0])){
            echo "   -> Champs du 1er transfert : ".implode(", ", array_keys($data['token_transfers'][0]))."\n";
        }
        echo "\n";
    } else {
        echo "   -> ECHEC ou format inattendu (pas de champ 'token_transfers')\n\n";
    }
}

echo "=== Fin du diagnostic ===\n";
