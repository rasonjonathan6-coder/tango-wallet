<?php
/**
 * diag_rpc_web.php
 *
 * Diagnostic accessible par navigateur (pas besoin de SSH) : teste
 * eth_blockNumber sur chacun des 3 RPC BSC configurés dans
 * BSC_RPC_ENDPOINTS (blockchain_config.php), un par un, et affiche le
 * résultat brut de chaque appel.
 *
 * A METTRE DANS LE DOSSIER wallet/ (à côté de blockchain_config.php)
 *
 * IMPORTANT :
 *  1. Change IMPÉRATIVEMENT le token ci-dessous.
 *  2. SUPPRIME ce fichier du serveur une fois le diagnostic terminé.
 *
 * Usage :
 *   https://tondomaine.com/wallet/diag_rpc_web.php?token=TON_TOKEN
 */

define("DIAG_RPC_SECRET_TOKEN", getenv('DIAG_SECRET_TOKEN') ?: '');

$tokenRecu = $_GET['token'] ?? '';

if (DIAG_RPC_SECRET_TOKEN === '' || !hash_equals(DIAG_RPC_SECRET_TOKEN, $tokenRecu)) {
    http_response_code(403);
    exit("forbidden");
}

header("Content-Type: text/plain; charset=utf-8");

require_once __DIR__ . "/blockchain_config.php";

echo "=== Diagnostic RPC BSC (eth_blockNumber) ===\n\n";

if(!defined('BSC_RPC_ENDPOINTS') || empty(BSC_RPC_ENDPOINTS)){
    echo "BSC_RPC_ENDPOINTS n'est pas défini dans blockchain_config.php.\n";
    exit;
}

$payload = json_encode([
    "jsonrpc" => "2.0",
    "method"  => "eth_blockNumber",
    "params"  => [],
    "id"      => 1,
]);

foreach(BSC_RPC_ENDPOINTS as $i => $endpoint){

    echo ($i + 1).". ".$endpoint."\n";

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_VERBOSE, false);

    $debut = microtime(true);
    $reponse = curl_exec($ch);
    $dureeMs = round((microtime(true) - $debut) * 1000);

    $curlErrno = curl_errno($ch);
    $curlErreur = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if($reponse === false){
        echo "   ECHEC CURL (errno $curlErrno) : $curlErreur\n";
        echo "   Durée avant échec : {$dureeMs}ms\n\n";
        continue;
    }

    echo "   HTTP $httpCode | {$dureeMs}ms\n";
    echo "   Réponse brute : ".$reponse."\n";

    $data = json_decode($reponse, true);
    if(isset($data['result'])){
        $blocDec = hexdec($data['result']);
        echo "   -> OK, dernier bloc = $blocDec\n\n";
    } else {
        echo "   -> Pas de champ 'result' (voir réponse brute ci-dessus)\n\n";
    }

}

echo "=== Fin du diagnostic ===\n";