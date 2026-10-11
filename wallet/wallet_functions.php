<?php

if(!isset($_SESSION)){
    session_start();
}

require_once __DIR__ . "/plisio_api.php";


/**
 * Récupère le portefeuille utilisateur
 */
function getUserWallet($pdo,$user_id){

    $stmt = $pdo->prepare("
        SELECT *
        FROM user_wallets
        WHERE user_id = ?
        LIMIT 1
    ");

    $stmt->execute([$user_id]);

    return $stmt->fetch(PDO::FETCH_ASSOC);

}


/**
 * Création portefeuille vide
 */
function createUserWallet($pdo,$user_id,$identifiant){

    $stmt=$pdo->prepare("
        INSERT INTO user_wallets
        (
            user_id,
            identifiant,
            solde,
            statut,
            created_at
        )
        VALUES
        (?,?,0,'actif',NOW())
    ");

    $stmt->execute([
        $user_id,
        $identifiant
    ]);

    return true;

}


/**
 * Historique transactions
 */
function getWalletHistory($pdo,$wallet_id){

    $stmt=$pdo->prepare("
        SELECT *
        FROM wallet_transactions
        WHERE wallet_id=?
        ORDER BY id DESC
        LIMIT 50
    ");

    $stmt->execute([$wallet_id]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);

}


/**
 * Calcule le montant maximum réellement envoyable via Plisio pour une devise
 * donnée, en se basant sur le VRAI solde Plisio (getPlisioBalance) et non sur
 * le montant observé sur la blockchain (BscScan/TronScan), qui est TOUJOURS
 * supérieur au solde Plisio puisque Plisio prélève sa propre commission dès
 * la réception des fonds.
 *
 * @param string $currency       ID Plisio (ex: "USDT_BSC")
 * @param float  $margeSecurite  Marge de sécurité supplémentaire (0.0005 = 0,05%)
 *                                pour absorber les micro-écarts d'arrondi flottant.
 *                                Le vrai coussin contre la commission Plisio (~1,5%)
 *                                est désormais assuré côté affichage/plafond client
 *                                (voir portefeuille.php / retrait_mvola.php : 0.98
 *                                au lieu de 0.984), pas ici.
 * @return float|null            Montant max envoyable, ou null si le solde n'a
 *                                pas pu être vérifié (ne JAMAIS tenter l'envoi
 *                                dans ce cas : mieux vaut réessayer plus tard
 *                                qu'échouer/perdre la trace de l'opération).
 */
function montantMaxEnvoyablePlisio($currency, float $margeSecurite = 0.0005){

    $result = getPlisioBalance($currency);

    if(($result['status'] ?? '') !== 'success'){
        error_log("montantMaxEnvoyablePlisio: impossible de vérifier le solde Plisio (".$currency.") : ".json_encode($result));
        return null;
    }

    $solde = (float)($result['data']['balance'] ?? 0);

    return round($solde * (1 - $margeSecurite), 8);

}


/**
 * Enveloppe sécurisée autour de plisioWithdraw() : vérifie D'ABORD le solde
 * réel Plisio, et ne tente l'envoi QUE s'il est suffisant. Sinon, renvoie une
 * erreur explicite au lieu de laisser l'API Plisio répondre
 * "Insufficient funds on balance" (ce qui, mal géré côté appelant, peut faire
 * croire à tort qu'un envoi a eu lieu ou créer une incohérence de solde).
 *
 * @param string $currency
 * @param string $to
 * @param float  $amount
 * @return array  Même format que plisioWithdraw(), avec un statut "error"
 *                explicite (data.message = "solde_insuffisant_plisio") si le
 *                solde réel ne couvre pas le montant demandé.
 */
function envoyerPlisioSiSoldeSuffisant($currency, $to, float $amount){

    $maxEnvoyable = montantMaxEnvoyablePlisio($currency);

    if($maxEnvoyable === null){
        return [
            "status" => "error",
            "data" => ["message" => "solde_insuffisant_plisio", "detail" => "Impossible de vérifier le solde Plisio avant l'envoi."]
        ];
    }

    if($amount > $maxEnvoyable){
        error_log(
            "envoyerPlisioSiSoldeSuffisant: envoi bloqué (".$currency.") - demandé=".$amount.
            " disponible_reel=".$maxEnvoyable
        );
        return [
            "status" => "error",
            "data" => [
                "message" => "solde_insuffisant_plisio",
                "detail" => "Montant demandé (".$amount.") > solde Plisio réellement disponible (".$maxEnvoyable.").",
                "montant_max_disponible" => $maxEnvoyable
            ]
        ];
    }

    return plisioWithdraw($currency, $to, $amount);

}


/**
 * Récupère les adresses BEP20 déjà enregistrées pour un utilisateur
 * (jusqu'à 10, triées par slot)
 */
function getUserBep20Addresses($pdo, $user_id){

    $stmt = $pdo->prepare("
        SELECT slot, adresse
        FROM user_wallet_bep20
        WHERE user_id = ?
        ORDER BY slot ASC
    ");

    $stmt->execute([$user_id]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);

}


/**
 * S'assure que l'utilisateur possède bien $nombre adresses BEP20
 * distinctes (10 par défaut). Génère celles qui manquent via Plisio,
 * en utilisant un "uid" différent par slot (user_id-bep20-N) pour que
 * Plisio renvoie une adresse différente à chaque fois.
 */
function ensureUserBep20Addresses($pdo, $user_id, $nombre = 10){

    $existantes = getUserBep20Addresses($pdo, $user_id);
    $slotsExistants = array_column($existantes, 'slot');

    for($slot = 1; $slot <= $nombre; $slot++){

        if(in_array($slot, $slotsExistants)){
            continue;
        }

        $uidPlisio = $user_id . "-bep20-" . $slot;

        $result = createPlisioWallet("USDT_BSC", $uidPlisio);

        if(isset($result['data']['hash'])){

            $stmt = $pdo->prepare("
                INSERT IGNORE INTO user_wallet_bep20
                (user_id, slot, adresse, created_at)
                VALUES (?,?,?,NOW())
            ");
            $stmt->execute([$user_id, $slot, $result['data']['hash']]);

        } elseif(isset($result['data']['message'])){
            error_log("Plisio createPlisioWallet BEP20 slot ".$slot." échec user_id=".$user_id." : ".$result['data']['message']);
        }

    }

    return getUserBep20Addresses($pdo, $user_id);

}

?>