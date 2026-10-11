<?php

ini_set('display_errors',1);
error_reporting(E_ALL);

require_once "../config.php";
require_once "blockchain_config.php";

if(!function_exists('sendMail')){
    require_once "../mail.php";
}

$debutExecution = new DateTime();
echo "===== Exécution check_deposits : ".$debutExecution->format('Y-m-d H:i:s')." =====\n";

$compteurDepots = 0;
$compteurErreurs = 0;

// Comme logErreurEtEcho() seul n'est pas toujours consultable facilement (accès
// serveur limité), cette fonction affiche AUSSI le message dans la sortie
// standard du script — donc visible directement dans le log du cron / la
// sortie que tu peux copier-coller, sans avoir à chercher error_log sur
// le serveur.
function logErreurEtEcho($message){
    error_log($message);
    echo "[ERREUR] ".$message."\n";
}


// Contrats des tokens
$usdtBep20Contract = "0x55d398326f99059ff775485246999027b3197955"; // USDT BEP20 - 18 décimales
$usdtTrc20Contract = "TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t";         // USDT TRC20 - 6 décimales


// récupérer les portefeuilles actifs

$stmt = $pdo->query("
    SELECT *
    FROM user_wallets
    WHERE statut='actif'
");

$wallets = $stmt->fetchAll(PDO::FETCH_ASSOC);


// récupérer toutes les adresses BEP20 (jusqu'à 10 par utilisateur)
// regroupées par user_id

$stmtBep20 = $pdo->query("
    SELECT user_id, slot, adresse
    FROM user_wallet_bep20
");

$bep20ParUser = [];
foreach($stmtBep20->fetchAll(PDO::FETCH_ASSOC) as $row){
    $bep20ParUser[$row['user_id']][] = $row['adresse'];
}


// Table de suivi du dernier bloc scanné, par adresse BEP20. Utilisée
// uniquement par le repli eth_getLogs (voir plus bas) : plutôt que de
// rescanner ~100 000 blocs à CHAQUE exécution du cron (ce qui multiplie les
// appels et déclenche du rate-limit sur les RPC publics gratuits), on ne
// rescanne que depuis le dernier bloc déjà traité pour cette adresse.
$pdo->query("
CREATE TABLE IF NOT EXISTS bep20_scan_state (
    adresse VARCHAR(64) PRIMARY KEY,
    dernier_bloc_traite BIGINT UNSIGNED NOT NULL,
    updated_at DATETIME NOT NULL
)
");

function recupererDernierBlocTraite($pdo, $adresse){
    $stmt = $pdo->prepare("SELECT dernier_bloc_traite FROM bep20_scan_state WHERE adresse = ?");
    $stmt->execute([$adresse]);
    $val = $stmt->fetchColumn();
    return $val !== false ? (int)$val : null;
}

function enregistrerDernierBlocTraite($pdo, $adresse, $bloc){
    $pdo->prepare("
        INSERT INTO bep20_scan_state (adresse, dernier_bloc_traite, updated_at)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            dernier_bloc_traite = VALUES(dernier_bloc_traite),
            updated_at = VALUES(updated_at)
    ")->execute([$adresse, $bloc]);
}


function deposantDejaEnregistre($pdo, $txid){

    $check = $pdo->prepare("
        SELECT id
        FROM wallet_deposits
        WHERE txid=?
    ");

    $check->execute([$txid]);

    return (bool)$check->fetch();

}


// ---------------------------------------------------------------------
// CORRECTIF DOUBLE-CREDIT : ce script ne modifiant plus wallet_deposits,
// on a besoin d'une table à soi pour se souvenir des hash on-chain déjà
// vus/signalés, sinon on renverrait la même alerte diagnostic à chaque
// exécution du cron pour un dépôt déjà repéré.
// ---------------------------------------------------------------------
$pdo->query("
CREATE TABLE IF NOT EXISTS wallet_deposits_diag_vus (
    txid VARCHAR(191) PRIMARY KEY,
    user_id INT NOT NULL,
    network VARCHAR(20) NOT NULL,
    montant DECIMAL(30,8) NOT NULL,
    vu_at DATETIME NOT NULL
)
");

function diagDejaSignale($pdo, $txid){
    $check = $pdo->prepare("SELECT txid FROM wallet_deposits_diag_vus WHERE txid=?");
    $check->execute([$txid]);
    return (bool)$check->fetch();
}

function marquerDiagSignale($pdo, $txid, $user_id, $network, $amount){
    $pdo->prepare("
        INSERT IGNORE INTO wallet_deposits_diag_vus (txid, user_id, network, montant, vu_at)
        VALUES (?,?,?,?,NOW())
    ")->execute([$txid, $user_id, $network, $amount]);
}


// ==========================================================================
// CORRECTIF DOUBLE-CREDIT (voir échange du 26/07/2026) :
// Ce script (cron blockchain) NE CRÉDITE PLUS le solde et N'INSÈRE PLUS
// dans wallet_deposits. Il ne fait que DÉTECTER un dépôt et le SIGNALER.
//
// Raison : webhook_plisio.php crédite déjà chaque dépôt en temps réel, en
// utilisant le txn_id interne de Plisio. Ce script-ci détecte le MÊME
// dépôt en scannant directement la blockchain, mais avec le hash on-chain
// réel (une valeur différente du txn_id Plisio). Comme la protection
// anti-doublon compare des txid qui ne correspondent jamais entre les deux
// scripts, les deux crédits passaient -> solde doublé.
//
// webhook_plisio.php reste donc la SEULE source de crédit. Ce script sert
// uniquement de filet de diagnostic (alerte si un dépôt détecté on-chain
// ne semble jamais avoir été crédité par le webhook, à vérifier manuellement).
// ==========================================================================
function enregistrerDepot($pdo, $user_id, $txid, $network, $amount){

    // Déjà repéré et signalé lors d'une exécution précédente : on ne
    // ré-alerte pas indéfiniment pour le même dépôt.
    if(diagDejaSignale($pdo, $txid)){
        return;
    }

    global $compteurDepots;
    $compteurDepots++;

    marquerDiagSignale($pdo, $txid, $user_id, $network, $amount);

    echo "DEPOT DETECTE SUR LA BLOCKCHAIN (non crédité ici, voir webhook_plisio.php) (".$network.") user ".$user_id." : ".$amount." USDT - hash on-chain: ".$txid."\n";

    // Alerte admin uniquement (pas de mail client ici : c'est le webhook
    // qui a déjà dû envoyer la confirmation de crédit au client). Ceci sert
    // juste à repérer un dépôt vu sur la blockchain mais jamais confirmé
    // par le webhook Plisio, pour investigation manuelle.
    if(defined('ADMIN_ALERT_EMAIL')){

        $messageDiag = "
Dépôt détecté par le scan blockchain (cron check_wallet_deposits.php) :

user_id : ".$user_id."
réseau  : ".$network."
montant : ".$amount." USDT
hash    : ".$txid."

Ce script ne crédite plus le solde (voir correctif double-crédit).
Vérifier dans wallet_deposits / logs que webhook_plisio.php a bien crédité
ce dépôt. Si absent après quelques minutes, investiguer manuellement.
";

        sendMail(
            ADMIN_ALERT_EMAIL,
            "ℹ️ Dépôt blockchain détecté (diagnostic) - user ".$user_id,
            $messageDiag
        );

    }

}


// Appelle un JSON-RPC BSC en essayant chaque endpoint de BSC_RPC_ENDPOINTS
// dans l'ordre (failover) : si un endpoint échoue (timeout, erreur réseau,
// ou pas de champ "result" dans la réponse), on passe au suivant.
// N'est utilisable que pour les méthodes JSON-RPC standards (ex:
// eth_blockNumber) — pas pour nr_getAssetTransfers qui est propre à NodeReal.
function appelRpcBscAvecFailover($payload){

    $endpoints = defined('BSC_RPC_ENDPOINTS') ? BSC_RPC_ENDPOINTS : [];

    foreach($endpoints as $endpoint){

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $reponse = curl_exec($ch);
        curl_close($ch);

        if($reponse === false){
            logErreurEtEcho("check_wallet_deposits: échec RPC (".$endpoint."), passage au suivant");
            continue;
        }

        $data = json_decode($reponse, true);

        if(isset($data['result'])){
            return $reponse;
        }

        logErreurEtEcho("check_wallet_deposits: réponse sans 'result' depuis ".$endpoint.", passage au suivant");
    }

    return false;
}


// ---------- Repli "eth_getLogs" pour la détection des dépôts BEP20 ----------
// nr_getAssetTransfers (méthode principale, voir plus bas) est propre à
// NodeReal. Si NodeReal échoue (panne, quota CU épuisé...), on ne peut plus
// l'utiliser sur Ankr / RPC public BNB Chain. On se rabat alors sur
// eth_getLogs, une méthode JSON-RPC standard supportée par tous les
// fournisseurs, qui lit les évènements "Transfer" du contrat USDT BEP20.
// Les RPC gratuits limitent la taille d'une plage de blocs par appel : on
// découpe donc la fenêtre en tranches.

// Signature de l'évènement Transfer(address,address,uint256) en Keccak-256
define("BEP20_TOPIC_TRANSFER", "0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef");

function recupererLogsEthGetLogs($endpoint, $fromBlockHex, $toBlockHex, $contractAddress, $addressBep20, &$raisonEchec = null){

    $topicTo = "0x000000000000000000000000".substr($addressBep20, 2);

    $payload = json_encode([
        "jsonrpc" => "2.0",
        "method"  => "eth_getLogs",
        "params"  => [[
            "fromBlock" => $fromBlockHex,
            "toBlock"   => $toBlockHex,
            "address"   => $contractAddress,
            "topics"    => [BEP20_TOPIC_TRANSFER, null, $topicTo],
        ]],
        "id" => 1,
    ]);

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $reponse = curl_exec($ch);
    $curlErreur = curl_error($ch);
    curl_close($ch);

    if($reponse === false){
        $raisonEchec = "échec curl : ".$curlErreur;
        return false;
    }

    $data = json_decode($reponse, true);

    if(!isset($data['result']) || !is_array($data['result'])){
        $raisonEchec = "réponse : ".$reponse;
        return false;
    }

    return $data['result'];
}

// Essaie chaque endpoint fourni dans l'ordre. Pour chaque endpoint, découpe
// la fenêtre [$blocBep20Recent, $blocBep20Actuel] en tranches de blocs et
// appelle eth_getLogs sur chacune. Si une tranche échoue sur un endpoint, on
// abandonne cet endpoint entièrement et on passe au suivant (pour ne pas
// mélanger des résultats partiels de deux fournisseurs différents).
// Retourne un tableau de transferts normalisés (même format que
// nr_getAssetTransfers : hash/to/value/decimal), ou false si tous les
// endpoints ont échoué.
function recupererTransfersBep20AvecFailover($endpoints, $addressBep20, $blocBep20Recent, $blocBep20Actuel, $usdtBep20Contract){

    $tailleChunk = 900; // Ankr a rejeté 4500 ("Block range is too large") ; on descend prudemment

    $fromDec = hexdec($blocBep20Recent);
    $toDec   = hexdec($blocBep20Actuel);

    foreach($endpoints as $endpoint){

        $transfersEndpoint = [];
        $echecEndpoint = false;
        $curBlock = $fromDec;

        while($curBlock <= $toDec){

            $finChunk = min($curBlock + $tailleChunk, $toDec);

            $raisonEchec = null;

            $logs = recupererLogsEthGetLogs(
                $endpoint,
                "0x".dechex($curBlock),
                "0x".dechex($finChunk),
                $usdtBep20Contract,
                $addressBep20,
                $raisonEchec
            );

            if($logs === false){
                logErreurEtEcho("check_wallet_deposits: détail échec eth_getLogs sur ".$endpoint." : ".$raisonEchec);
                $echecEndpoint = true;
                break;
            }

            foreach($logs as $log){
                $transfersEndpoint[] = [
                    "hash"    => $log['transactionHash'] ?? null,
                    "to"      => $addressBep20,
                    "value"   => $log['data'] ?? '0x0',
                    "decimal" => 18,
                ];
            }

            $curBlock = $finChunk + 1;
        }

        if(!$echecEndpoint){
            return $transfersEndpoint; // succès sur ce fournisseur (tableau vide = pas de dépôt, pas une erreur)
        }

        logErreurEtEcho("check_wallet_deposits: eth_getLogs échoué sur ".$endpoint.", passage au RPC suivant pour ".$addressBep20);

    }

    return false;
}

// ---------- Repli "TronScan" pour la détection des dépôts TRC20 ----------
// TronGrid (méthode principale) reste utilisée en premier. Si elle échoue
// (panne, quota, clé invalide...), on se rabat sur l'API publique de
// TronScan, qui ne nécessite pas de clé API. Les deux fonctions retournent
// le même format normalisé : transaction_id / to / value / token_info.decimals.

function recupererTransfersTrc20TronGrid($addressTrc20, $usdtTrc20Contract){

    $url = "https://api.trongrid.io/v1/accounts/".$addressTrc20
        ."/transactions/trc20?limit=20&contract_address=".$usdtTrc20Contract;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["TRON-PRO-API-KEY: ".TRON_API_KEY]);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $reponse = curl_exec($ch);
    curl_close($ch);

    if($reponse === false){
        return null;
    }

    $data = json_decode($reponse, true);

    if(!empty($data['success']) && isset($data['data'])){
        return $data['data']; // déjà au format transaction_id/to/value/token_info
    }

    return null;
}

function recupererTransfersTrc20TronScan($addressTrc20, $usdtTrc20Contract){

    $url = "https://apilist.tronscanapi.com/api/token_trc20/transfers"
        ."?limit=20&start=0&sort=-timestamp&count=true"
        ."&relatedAddress=".$addressTrc20
        ."&contract_address=".$usdtTrc20Contract;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $reponse = curl_exec($ch);
    curl_close($ch);

    if($reponse === false){
        return null;
    }

    $data = json_decode($reponse, true);

    if(!isset($data['token_transfers']) || !is_array($data['token_transfers'])){
        return null;
    }

    // Normalisation au même format que TronGrid, pour réutiliser telle
    // quelle la boucle de traitement existante plus bas.
    $normalise = [];
    foreach($data['token_transfers'] as $t){
        $normalise[] = [
            "transaction_id" => $t['transaction_id'] ?? null,
            "to"             => $t['to_address'] ?? null,
            "value"          => $t['quant'] ?? '0',
            "token_info"     => [
                "decimals" => $t['tokenInfo']['tokenDecimal'] ?? 6,
            ],
        ];
    }

    return $normalise;
}


// pour calculer manuellement fromBlock. NodeReal exige fromBlock dès que
// toBlock est fourni : passer toBlock="latest" seul ne suffit pas malgré
// ce qu'indique la doc générale de nr_getAssetTransfers.
$blocBep20Recent = null;
$blocBep20Actuel = null;

if(defined('MEGANODE_API_KEY')){

    // eth_blockNumber est une méthode JSON-RPC standard : elle peut donc
    // basculer sur Ankr / RPC public BNB Chain si NodeReal est indisponible
    // ou a atteint son quota (voir BSC_RPC_ENDPOINTS dans blockchain_config.php).
    $repBlockNumber = appelRpcBscAvecFailover(json_encode([
        "jsonrpc" => "2.0",
        "method"  => "eth_blockNumber",
        "params"  => [],
        "id"      => 1,
    ]));

    $dataBlockNumber = $repBlockNumber ? json_decode($repBlockNumber, true) : null;

    if(isset($dataBlockNumber['result'])){
        $dernierBloc = hexdec($dataBlockNumber['result']);
        // Fenêtre de ~100 000 blocs (~3-4 jours sur BSC) : max autorisé par
        // nr_getAssetTransfers quand fromBlock et toBlock sont tous deux fournis.
        // toBlock est fixé au même bloc "actuel" plutôt qu'au mot-clé "latest" :
        // NodeReal compare mal un fromBlock numérique à un toBlock="latest".
        $blocBep20Recent = "0x" . dechex(max(0, $dernierBloc - 100000));
        $blocBep20Actuel = $dataBlockNumber['result']; // déjà en hex (ex: "0x2f1a4c3")
    } else {
        logErreurEtEcho("check_wallet_deposits: échec eth_blockNumber (MegaNode) : ".$repBlockNumber);
    }

}


foreach($wallets as $wallet){

    $user_id = $wallet['user_id'];


    // ---------- BEP20 (BSC) - jusqu'à 10 adresses par utilisateur ----------

    $adressesBep20 = $bep20ParUser[$user_id] ?? [];

    foreach($adressesBep20 as $adresseBrute){

        $addressBep20 = strtolower(trim($adresseBrute));

        if(!$addressBep20){
            continue;
        }

        echo "Vérification BEP20 user : ".$user_id." (".$addressBep20.")\n";

        if($blocBep20Recent === null){
            $compteurErreurs++;
            logErreurEtEcho("check_wallet_deposits: pas de fromBlock disponible (eth_blockNumber a échoué), BEP20 ignoré pour user ".$user_id);
            continue;
        }

        // BSCTrace / MegaNode (JSON-RPC) : remplace Etherscan API V2, dont
        // l'accès gratuit à BSC a été supprimé (payant depuis juillet 2026).
        // Doc : https://docs.nodereal.io/reference/nr_getassettransfers
        $urlMegaNode = "https://bsc-mainnet.nodereal.io/v1/".MEGANODE_API_KEY;

        $payloadMegaNode = json_encode([
            "jsonrpc" => "2.0",
            "method"  => "nr_getAssetTransfers",
            "params"  => [[
                "category"          => ["20"],
                "fromBlock"         => $blocBep20Recent,
                "toBlock"           => $blocBep20Actuel,
                "contractAddresses" => [$usdtBep20Contract],
                "toAddress"         => $addressBep20,
                "order"             => "desc",
                "excludeZeroValue"  => true,
                "maxCount"          => "0x3e8", // 1000
            ]],
            "id" => 1,
        ]);

        $chBep20 = curl_init($urlMegaNode);
        curl_setopt($chBep20, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chBep20, CURLOPT_POST, true);
        curl_setopt($chBep20, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
        curl_setopt($chBep20, CURLOPT_POSTFIELDS, $payloadMegaNode);
        // Timeout explicite (5s connexion + 10s total) pour éviter qu'un appel
        // qui ne répond jamais ne bloque tout le script indéfiniment.
        curl_setopt($chBep20, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($chBep20, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($chBep20);
        curl_close($chBep20);

        if($response === false){
            $compteurErreurs++;
            logErreurEtEcho("check_wallet_deposits: échec appel API BEP20 (MegaNode) pour user ".$user_id." (".$addressBep20.")");
        }

        // Transferts détectés (peu importe la méthode) : tableau normalisé
        // hash/to/value/decimal, comme retourné par nr_getAssetTransfers.
        $transfersBep20 = null;

        if($response){

            $data = json_decode($response, true);

            if(isset($data['result']['transfers'])){
                $transfersBep20 = $data['result']['transfers'];
            } else {
                logErreurEtEcho("check_wallet_deposits BEP20 (MegaNode) user ".$user_id." : réponse inattendue : ".$response);
            }

        }

        // Repli automatique : NodeReal indisponible ou en échec (panne, quota
        // CU épuisé comme dans l'alerte MegaNode...). On rejoue la détection
        // via eth_getLogs (méthode standard) sur les RPC de secours
        // (Ankr puis RPC public BNB Chain), pour ne jamais bloquer la
        // détection des dépôts pendant que NodeReal est indisponible.
        if($transfersBep20 === null){

            logErreurEtEcho("check_wallet_deposits: repli sur eth_getLogs (Ankr / RPC public) pour user ".$user_id." (".$addressBep20.")");

            $endpointsSecours = array_slice(
                defined('BSC_RPC_ENDPOINTS') ? BSC_RPC_ENDPOINTS : [],
                1 // on saute NodeReal, déjà tenté ci-dessus via nr_getAssetTransfers
            );

            // On ne rescanne que depuis le dernier bloc déjà traité pour
            // cette adresse (s'il existe), pour limiter le nombre d'appels
            // eth_getLogs et éviter le rate-limit sur les RPC publics
            // gratuits.
            // S'il n'existe pas encore (première fois en repli), on se
            // limite à une fenêtre RÉCENTE et courte (quelques milliers de
            // blocs, ~1-2h) au lieu des ~100 000 blocs (~4 jours) utilisés
            // par NodeReal : les RPC gratuits (Ankr, publicnode, RPC public
            // BNB Chain) refusent net les requêtes sur un historique aussi
            // profond ("block range too large", "archive requests require
            // a personal token", "limit exceeded"). Le cron tournant
            // fréquemment, cette fenêtre courte suffit largement pour ne
            // rien manquer une fois que bep20_scan_state est alimenté.
            $tailleFenetreRepliInitiale = 2000;

            $dernierBlocTraite = recupererDernierBlocTraite($pdo, $addressBep20);
            $blocActuelDec = hexdec($blocBep20Actuel);

            if($dernierBlocTraite !== null){
                $fromBlockRepli = "0x" . dechex(max($dernierBlocTraite + 1, hexdec($blocBep20Recent)));
            } else {
                $fromBlockRepli = "0x" . dechex(max($blocActuelDec - $tailleFenetreRepliInitiale, hexdec($blocBep20Recent)));
            }

            $transfersBep20 = recupererTransfersBep20AvecFailover(
                $endpointsSecours,
                $addressBep20,
                $fromBlockRepli,
                $blocBep20Actuel,
                $usdtBep20Contract
            );

        }

        if($transfersBep20 === false || $transfersBep20 === null){

            $compteurErreurs++;
            logErreurEtEcho("check_wallet_deposits: échec de tous les RPC BEP20 (NodeReal + secours) pour user ".$user_id." (".$addressBep20.")");

        } else {

            // Scan réussi (NodeReal ou repli) jusqu'à $blocBep20Actuel : on
            // mémorise ce point pour ne pas rescanner cette plage la
            // prochaine fois que le repli sera nécessaire.
            enregistrerDernierBlocTraite($pdo, $addressBep20, hexdec($blocBep20Actuel));

            foreach($transfersBep20 as $tx){

                $txid = $tx['hash'] ?? null;

                if(!$txid || deposantDejaEnregistre($pdo, $txid)){
                    continue;
                }

                if(strtolower($tx['to'] ?? '') != $addressBep20){
                    continue;
                }

                // USDT BEP20 (BSC) = 18 décimales. "value" est une chaîne
                // hexadécimale brute (0x...) : on la convertit en montant
                // décimal via bcmath/gmp pour éviter toute perte de
                // précision sur les gros nombres (au-delà de PHP_INT_MAX).
                $decimals = isset($tx['decimal']) ? (int)$tx['decimal'] : 18;
                $valeurBrute = $tx['value'] ?? '0x0';

                if(function_exists('bcdiv')){
                    $valeurDecimale = function_exists('gmp_init')
                        ? gmp_strval(gmp_init($valeurBrute, 0))
                        : (string) hexdec($valeurBrute);
                    $amount = (float) bcdiv($valeurDecimale, bcpow('10', (string)$decimals), 8);
                } else {
                    $amount = hexdec($valeurBrute) / (10 ** $decimals);
                }

                if($amount <= 0){
                    continue;
                }

                enregistrerDepot($pdo, $user_id, $txid, "BEP20", $amount);

            }

        }

    }


    // ---------- TRC20 (TRON) ----------

    $addressTrc20 = trim($wallet['wallet_trc20'] ?? '');

    if($addressTrc20){

        echo "Vérification TRC20 user : ".$user_id."\n";

        $transfersTrc20 = recupererTransfersTrc20TronGrid($addressTrc20, $usdtTrc20Contract);

        // Repli automatique : TronGrid indisponible ou en échec (panne,
        // quota, clé invalide...). On rejoue la détection via l'API
        // publique TronScan (pas de clé nécessaire), pour ne jamais
        // bloquer la détection des dépôts TRC20.
        if($transfersTrc20 === null){

            logErreurEtEcho("check_wallet_deposits: repli sur TronScan pour user ".$user_id." (".$addressTrc20.")");

            $transfersTrc20 = recupererTransfersTrc20TronScan($addressTrc20, $usdtTrc20Contract);

        }

        if($transfersTrc20 === null){

            $compteurErreurs++;
            logErreurEtEcho("check_wallet_deposits: échec de toutes les API TRC20 (TronGrid + TronScan) pour user ".$user_id." (".$addressTrc20.")");

        } else {

            foreach($transfersTrc20 as $tx){

                $txid = $tx['transaction_id'] ?? null;

                if(!$txid || deposantDejaEnregistre($pdo, $txid)){
                    continue;
                }

                if(($tx['to'] ?? null) !== $addressTrc20){
                    continue;
                }

                $decimals = $tx['token_info']['decimals'] ?? 6;
                $amount = $tx['value'] / (10 ** $decimals);

                if($amount <= 0){
                    continue;
                }

                enregistrerDepot($pdo, $user_id, $txid, "TRC20", $amount);

            }

        }

    }

}


$finExecution = new DateTime();
$dureeSecondes = $finExecution->getTimestamp() - $debutExecution->getTimestamp();

echo "===== Fin d'exécution : ".$finExecution->format('Y-m-d H:i:s')
    ." | Dépôts crédités : ".$compteurDepots
    ." | Erreurs : ".$compteurErreurs
    ." | Durée : ".$dureeSecondes."s =====\n";
echo "Terminé";


// ---------- Alerte admin en cas d'erreurs répétées ----------
// Si trop d'erreurs se sont accumulées pendant l'exécution (ex: NodeReal ET
// les RPC de secours en panne, API TRC20 indisponible...), on prévient
// automatiquement l'admin par email plutôt que d'attendre qu'un client
// signale que son solde n'est pas arrivé.
// Anti-spam : un seul email au maximum par heure, via un fichier local qui
// garde la trace du dernier envoi (n'alerte pas à chaque exécution du cron
// si le problème persiste).
$seuilAlerteErreurs = 3;
$intervalleMinAlerteSecondes = 3600;

if($compteurErreurs >= $seuilAlerteErreurs && defined('ADMIN_ALERT_EMAIL')){

    $fichierDernierAlerte = __DIR__.'/dernier_alerte_deposits.txt';
    $dernierAlerteTimestamp = file_exists($fichierDernierAlerte)
        ? (int) file_get_contents($fichierDernierAlerte)
        : 0;

    if(time() - $dernierAlerteTimestamp >= $intervalleMinAlerteSecondes){

        $messageAlerte = "
Alerte automatique - check_wallet_deposits.php

".$compteurErreurs." erreur(s) rencontrée(s) lors de l'exécution du ".$finExecution->format('Y-m-d H:i:s').".

Dépôts crédités durant cette exécution : ".$compteurDepots."

Vérifie les logs du serveur (error_log) pour le détail : cela peut venir
d'une panne NodeReal + RPC de secours (Ankr / BNB Chain public), ou de
l'API TRC20 (Tron), ou d'un autre problème réseau/serveur.
";

        sendMail(
            ADMIN_ALERT_EMAIL,
            "⚠️ check_wallet_deposits : ".$compteurErreurs." erreur(s) détectée(s)",
            $messageAlerte
        );

        file_put_contents($fichierDernierAlerte, time());

    }

}