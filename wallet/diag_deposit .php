<?php
/**
 * diag_deposit.php
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
 * Usage : php diag_deposit.php <user_id> [txid]
 */

require_once __DIR__ . "/../config.php";       // doit fournir $pdo
require_once __DIR__ . "/blockchain_config.php";

$userId = $argv[1] ?? null;
$txidCherche = $argv[2] ?? null;

if(!$userId){
    echo "Usage : php diag_deposit.php <user_id> [txid]\n";
    exit(1);
}

echo "=== Diagnostic dépôt pour user #$userId ===\n\n";

// 1) Le wallet existe-t-il et est-il actif ?
$stmt = $pdo->prepare("SELECT * FROM user_wallets WHERE user_id = ?");
$stmt->execute([$userId]);
$wallet = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$wallet){
    echo "❌ Aucune ligne dans user_wallets pour user_id=$userId. Rien ne peut être crédité.\n";
    exit;
}

echo "Solde actuel : {$wallet['solde']} USDT\n";
echo "Statut wallet : {$wallet['statut']}";
if($wallet['statut'] !== 'actif'){
    echo "  ⚠️  ATTENTION : check_wallet_deposits.php ne traite QUE les wallets statut='actif'. Ce wallet sera ignoré par le cron !";
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
        echo "🔎 txid trouvé dans wallet_deposits (id={$row['id']}, user_id stocké=\"{$row['user_id']}\", montant={$row['montant']}, network={$row['network']}).\n";
        echo "   -> Le dépôt A ÉTÉ vu par ton script. Si le solde ne reflète pas ce montant,\n";
        echo "      lance fix_soldes_deposits.php (dry run) pour voir l'écart exact.\n";
        if((string)$row['user_id'] !== (string)$userId){
            echo "   ⚠️  user_id stocké (\"{$row['user_id']}\") différent du user_id demandé ($userId) : à vérifier.\n";
        }
    } else {
        echo "🔎 txid absent de wallet_deposits : ton script ne l'a JAMAIS traité.\n";
        echo "   Causes possibles :\n";
        echo "   - le cron check_deposits.php ne tourne pas (vérifie : crontab -l)\n";
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
if($testAddrBep20){
    $url = "https://api.etherscan.io/v2/api?chainid=56&module=account&action=tokentx"
        . "&contractaddress=0x55d398326f99059ff775485246999027b3197955"
        . "&address=" . strtolower($testAddrBep20)
        . "&page=1&offset=5&sort=desc&apikey=" . BSC_API_KEY;

    $resp = @file_get_contents($url);
    $data = json_decode($resp, true);

    echo "Etherscan (BEP20) : ";
    if(($data['status'] ?? '') === '1'){
        echo "✅ OK, " . count($data['result']) . " tx récentes trouvées.\n";
    } else {
        echo "❌ ERREUR -> " . ($data['message'] ?? 'réponse invalide') . " | " . json_encode($data['result'] ?? $resp) . "\n";
    }
} else {
    echo "Etherscan (BEP20) : pas d'adresse à tester pour ce user.\n";
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
        echo "✅ OK, " . count($data['data'] ?? []) . " tx récentes trouvées.\n";
    } else {
        echo "❌ ERREUR -> " . json_encode($data) . "\n";
    }
} else {
    echo "Trongrid (TRC20) : pas d'adresse TRC20 pour ce user.\n";
}

echo "\n=== Fin du diagnostic ===\n";
