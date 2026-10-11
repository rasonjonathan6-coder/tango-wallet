<?php

require_once "plisio_config.php";


/**
 * Génère (ou récupère) une adresse de dépôt permanente pour un utilisateur.
 *
 * Endpoint officiel Plisio : GET /shops/deposit/new
 * -> nécessite que "White-label payment processing" soit ACTIVÉ
 *    dans les paramètres de la boutique Plisio, sinon l'API renvoie :
 *    {"status":"error","data":{"message":"White-label payment processing is disabled..."}}
 *
 * @param string $currency  ID Plisio de la crypto (ex: "USDT_TRX", "USDT_BSC")
 * @param string $uid       Identifiant interne unique de l'utilisateur (ex: user_id)
 */
function createPlisioWallet($currency, $uid){

    $url = "https://plisio.net/api/v1/shops/deposit/new";

    $params = [
        "api_key"  => PLISIO_SECRET_KEY,
        "psys_cid" => $currency,
        "uid"      => (string)$uid
    ];

    $ch = curl_init($url . "?" . http_build_query($params));

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $response = curl_exec($ch);

    if(curl_errno($ch)){
        $error = curl_error($ch);
        curl_close($ch);
        return ["error" => $error];
    }

    curl_close($ch);

    return json_decode($response, true);

}


/**
 * Récupère le solde RÉELLEMENT disponible chez Plisio pour une devise donnée.
 *
 * Endpoint officiel Plisio : GET /balances/{psys_cid}
 * Doc : https://plisio.net/documentation/endpoints/balance
 *
 * C'est CE solde (net des commissions déjà prélevées par Plisio à la réception
 * des fonds), et non le montant vu sur BscScan/TronScan, qui doit servir de
 * référence avant de déclencher un envoi automatique (plisioWithdraw).
 *
 * @param string $currency  ID Plisio de la crypto (ex: "USDT_TRX", "USDT_BSC")
 * @return array            ["status"=>"success","data"=>["balance"=>"4.93..."]] ou ["status"=>"error",...]
 */
function getPlisioBalance($currency){

    $url = "https://api.plisio.net/api/v1/balances/" . urlencode($currency);

    $params = [
        "api_key" => PLISIO_SECRET_KEY,
    ];

    $ch = curl_init($url . "?" . http_build_query($params));

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $response = curl_exec($ch);

    if(curl_errno($ch)){
        $error = curl_error($ch);
        curl_close($ch);
        return ["status" => "error", "data" => ["message" => "Erreur réseau : ".$error]];
    }

    curl_close($ch);

    $decoded = json_decode($response, true);

    if(!is_array($decoded)){
        return ["status" => "error", "data" => ["message" => "Réponse Plisio invalide : ".$response]];
    }

    return $decoded;

}


/**
 * Envoie automatiquement de la crypto depuis le solde Plisio vers une adresse externe.
 *
 * Endpoint officiel Plisio : GET /operations/withdraw
 * Doc : https://plisio.net/documentation/endpoints/withdrawal-mass-withdrawal
 *
 * IMPORTANT :
 *  - Nécessite que le compte Plisio dispose du solde suffisant dans la devise demandée
 *    ET que les retraits API soient autorisés (parfois une whitelist d'IP est exigée
 *    côté Plisio -> "Request IP" dans les paramètres du shop).
 *  - Ne jamais considérer un retrait comme "réussi" sur simple absence d'erreur réseau :
 *    toujours vérifier $result['status'] === 'success'.
 *
 * @param string $currency  ID Plisio de la crypto (ex: "USDT_TRX", "USDT_BSC")
 * @param string $to        Adresse crypto de destination
 * @param float  $amount    Montant à envoyer (dans l'unité de la devise, pas en centimes)
 * @return array            Réponse décodée de l'API Plisio (ou ["error" => ...] si échec réseau)
 */
function plisioWithdraw($currency, $to, $amount){

    $url = "https://api.plisio.net/api/v1/operations/withdraw";

    $params = [
        "currency" => $currency,
        "type"     => "cash_out",
        "to"       => $to,
        "amount"   => (string)$amount,
        "api_key"  => PLISIO_SECRET_KEY,
    ];

    $ch = curl_init($url . "?" . http_build_query($params));

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

    $response = curl_exec($ch);

    if(curl_errno($ch)){

        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        // CORRECTIF DOUBLE-ENVOI : un timeout (ou une connexion coupée) ne
        // prouve PAS que Plisio n'a rien fait. Notre requête peut très bien
        // être arrivée et avoir été traitée côté Plisio, seule la réponse
        // HTTP n'est jamais revenue à temps. Traiter ça comme "rien n'a été
        // envoyé" (et donc autoriser un nouveau clic sur "Confirmer") peut
        // faire envoyer l'argent une seconde fois pour de vrai.
        // On distingue donc ce cas précis ("ambigu", à vérifier manuellement
        // sur le dashboard Plisio avant tout nouvel essai) d'une erreur
        // réseau franche (ex: DNS, connexion refusée avant tout envoi).
        $estAmbigu = in_array($errno, [
            CURLE_OPERATION_TIMEDOUT, // 28 : timeout - requête peut être arrivée chez Plisio
            CURLE_GOT_NOTHING,        // 52 : connexion coupée après envoi, réponse jamais reçue
            CURLE_RECV_ERROR,         // 56 : erreur pendant la réception de la réponse
        ]);

        return [
            "status" => "error",
            "data" => [
                "message" => $estAmbigu ? "timeout_ambigu_plisio" : "erreur_reseau_avant_envoi",
                "detail"  => "Erreur réseau (curl #".$errno.") : ".$error,
            ],
        ];
    }

    curl_close($ch);

    $decoded = json_decode($response, true);

    if(!is_array($decoded)){
        return ["status" => "error", "data" => ["message" => "Réponse Plisio invalide : ".$response]];
    }

    return $decoded;

}

?>