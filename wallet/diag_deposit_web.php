<?php
/**
 * diag_deposit_web.php
 *
 * Version accessible par navigateur de diag_deposit.php, pour les
 * hébergements sans accès SSH/terminal.
 *
 * A METTRE DANS LE DOSSIER wallet/ (à côté de webhook_plisio.php, etc.)
 *
 * Diagnostic pour le cas : "dépôt confirmé (Plisio ou explorateur), mais
 * solde jamais crédité", QUAND ce dépôt passe par une adresse permanente
 * (BEP20/TRC20) et NON par create_invoice.php.
 *
 * Rappel important sur ton système :
 *   -> Le crédit du solde pour les adresses permanentes est fait par
 *      check_wallet_deposits.php (appelé via check_deposits.php, cron),
 *      qui interroge DIRECTEMENT Etherscan (BEP20) et Trongrid (TRC20).
 *   -> webhook_plisio.php (ipn_type=pay_in) n'est utile QUE si tu as
 *      configuré une "Status URL" chez Plisio pour ces adresses.
 *   -> Donc "confirmé dans Plisio" ne veut PAS dire "vu par ton script",
 *      sauf si Plisio a bien pu appeler ton webhook_plisio.php.
 *
 * IMPORTANT :
 *  1. Change IMPÉRATIVEMENT le token ci-dessous (mets une chaîne longue et
 *     aléatoire, pas "changemoi").
 *  2. SUPPRIME ce fichier du serveur une fois le diagnostic terminé.
 *
 * Usage :
 *   https://tondomaine.com/wallet/diag_deposit_web.php?token=TON_TOKEN&user_id=123
 *   https://tondomaine.com/wallet/diag_deposit_web.php?token=TON_TOKEN&user_id=123&txid=abc...
 */

define("DIAG_SECRET_TOKEN", getenv('DIAG_SECRET_TOKEN') ?: '');

$tokenRecu = $_GET['token'] ?? '';

if(!hash_equals(DIAG_SECRET_TOKEN, $tokenRecu)){
    http_response_code(403);
    exit("forbidden");
}

header("Content-Type: text/plain; charset=utf-8");

// Chemins corrigés : ce fichier est dans wallet/, donc config.php est un
// niveau au-dessus, et blockchain_config.php est dans le même dossier.
require_once __DIR__ . "/../config.php";       // doit fournir $pdo
require_once __DIR__ . "/blockchain_config.php";

$userId = $_GET['user_id'] ?? null;
$txidCherche = $_GET['txid'] ?? null;

if(!$userId){
    echo "Usage : ?token=TON_TOKEN&user_id=123[&txid=...]\n";
    exit;
}

echo "=== Diagnostic dépôt pour user #$userId ===\n\n";

// 1) Le wallet existe-t-il et est-il actif ?
$stmt = $pdo->prepare("SELECT * FROM user_wallets WHERE user_id = ?");
$stmt->execute([$userId]);
$wallet = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$wallet){
    echo "Aucune ligne dans user_wallets pour user_id=$userId. Rien ne peut être crédité.\n";
    exit;
}

echo "Solde actuel : {$wallet['solde']} USDT\n";
echo "Statut wallet : {$wallet['statut']}";
if($wallet['statut'] !== 'actif'){
    echo "  ATTENTION : check_wallet_deposits.php ne traite QUE les wallets statut='actif'. Ce wallet sera ignoré par le cron !";
}
echo "\n\n";

// 2) Adresses BEP20 enregistrées pour ce user
$stmtBep = $pdo->prepare("SELECT slot, adresse FROM user_wallet_bep20 WHERE user_id = ?");
$stmtBep->execute([$userId]);
$adressesBep20 = $stmtBep->fetchAll(PDO::FETCH_ASSOC);

echo "Adresses BEP20 enregistrées (" . count($adressesBep20) . ") :\n";
foreach($adressesBep20 as $a){
    echo "  - slot {$a['slot']} : {$a['adresse']}\n";
}
if(empty($adressesBep20)){
    echo "  (aucune)\n";
}

echo "\nAdresse TRC20 enregistrée : " . ($wallet['wallet_trc20'] ?: '(aucune)') . "\n\n";

// 3) Le txid est-il déjà dans wallet_deposits (dépôt détecté mais pas forcément crédité) ?
if($txidCherche){
    $stmt = $pdo->prepare("SELECT * FROM wallet_deposits WHERE txid = ?");
    $stmt->execute([$txidCherche]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if($row){
        echo "txid trouvé dans wallet_deposits (id={$row['id']}, user_id stocké=\"{$row['user_id']}\", montant={$row['montant']}, network={$row['network']}).\n";
        echo "   -> Le dépôt A ÉTÉ vu par ton script. Si le solde ne reflète pas ce montant,\n";
        echo "      lance fix_soldes_web.php (dry run) pour voir l'écart exact.\n";
        if((string)$row['user_id'] !== (string)$userId){
            echo "   ATTENTION : user_id stocké (\"{$row['user_id']}\") différent du user_id demandé ($userId) : à vérifier.\n";
        }
    } else {
        echo "txid absent de wallet_deposits : ton script ne l'a JAMAIS traité.\n";
        echo "   Causes possibles :\n";
        echo "   - le cron check_deposits.php ne tourne pas (vérifie : crontab -l, ou ton service webcron)\n";
        echo "   - le wallet n'est pas statut='actif' (voir plus haut)\n";
        echo "   - la clé API (Etherscan/Trongrid) est invalide ou a atteint son quota\n";
        echo "   - l'adresse de dépôt utilisée par le client ne correspond à AUCUNE adresse\n";
        echo "     enregistrée dans user_wallet_bep20 / user_wallets.wallet_trc20 ci-dessus\n";
        echo "   - la transaction est plus vieille que les 10 dernières (offset=10 dans le code)\n";
    }
    echo "\n";
}

// 4) Test rapide des clés API
echo "=== Test des clés API ===\n";

$testAddrBep20 = $adressesBep20[0]['adresse'] ?? null;
if($testAddrBep20 && defined('MEGANODE_API_KEY')){

    // Récupère d'abord le dernier bloc pour construire une fenêtre valide
    // (nr_getAssetTransfers exige fromBlock < toBlock, tous deux explicites).
    $chBlk = curl_init("https://bsc-mainnet.nodereal.io/v1/" . MEGANODE_API_KEY);
    curl_setopt($chBlk, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($chBlk, CURLOPT_POST, true);
    curl_setopt($chBlk, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($chBlk, CURLOPT_POSTFIELDS, json_encode([
        "jsonrpc" => "2.0", "method" => "eth_blockNumber", "params" => [], "id" => 1,
    ]));
    curl_setopt($chBlk, CURLOPT_TIMEOUT, 10);
    $respBlk = curl_exec($chBlk);
    curl_close($chBlk);
    $dataBlk = json_decode($respBlk, true);

    echo "MegaNode/BSCTrace (BEP20) : ";

    if(isset($dataBlk['result'])){

        $dernierBloc = hexdec($dataBlk['result']);
        $fromBlock = "0x" . dechex(max(0, $dernierBloc - 100000));
        $toBlock = $dataBlk['result'];

        $chTest = curl_init("https://bsc-mainnet.nodereal.io/v1/" . MEGANODE_API_KEY);
        curl_setopt($chTest, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chTest, CURLOPT_POST, true);
        curl_setopt($chTest, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
        curl_setopt($chTest, CURLOPT_POSTFIELDS, json_encode([
            "jsonrpc" => "2.0",
            "method"  => "nr_getAssetTransfers",
            "params"  => [[
                "category"          => ["20"],
                "fromBlock"         => $fromBlock,
                "toBlock"           => $toBlock,
                "contractAddresses" => ["0x55d398326f99059ff775485246999027b3197955"],
                "toAddress"         => strtolower($testAddrBep20),
                "order"             => "desc",
                "excludeZeroValue"  => true,
                "maxCount"          => "0x3e8",
            ]],
            "id" => 1,
        ]));
        curl_setopt($chTest, CURLOPT_TIMEOUT, 10);
        $resp = curl_exec($chTest);
        curl_close($chTest);
        $data = json_decode($resp, true);

        if(isset($data['result']['transfers'])){
            echo "OK, " . count($data['result']['transfers']) . " tx récentes trouvées (fenêtre ~100 000 blocs).\n";
        } else {
            echo "ERREUR -> " . $resp . "\n";
        }

    } else {
        echo "ERREUR -> impossible de récupérer le dernier bloc : " . $respBlk . "\n";
    }

} elseif($testAddrBep20){
    echo "MegaNode/BSCTrace (BEP20) : MEGANODE_API_KEY non définie dans blockchain_config.php.\n";
} else {
    echo "MegaNode/BSCTrace (BEP20) : pas d'adresse à tester pour ce user.\n";
}

$addressTrc20 = trim($wallet['wallet_trc20'] ?? '');
if($addressTrc20){
    $ch = curl_init("https://api.trongrid.io/v1/accounts/{$addressTrc20}/transactions/trc20?limit=5&contract_address=TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["TRON-PRO-API-KEY: " . TRON_API_KEY]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp, true);

    echo "Trongrid (TRC20) : ";
    if(!empty($data['success'])){
        echo "OK, " . count($data['data'] ?? []) . " tx récentes trouvées.\n";
    } else {
        echo "ERREUR -> " . json_encode($data) . "\n";
    }
} else {
    echo "Trongrid (TRC20) : pas d'adresse TRC20 pour ce user.\n";
}

echo "\n=== Fin du diagnostic ===\n";