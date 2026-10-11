<?php

/**
 * Reçoit les callbacks Plisio (Status URL).
 * Concerne 2 cas :
 *  - ipn_type = "pay_in"  -> dépôt reçu sur une adresse permanente (portefeuille)
 *  - pas de ipn_type (ou "invoice") -> callback d'une facture créée via create_invoice.php
 *
 * A configurer comme "Status URL" dans Shop settings sur Plisio.
 */

require_once "../config.php";
require_once "plisio_config.php";


/**
 * Extrait le user_id "réel" à partir de la valeur deposit_uid renvoyée par Plisio,
 * qu'elle soit :
 *  - déjà numérique (ex: "123")        -> dépôt TRC20 (uid = user_id brut)
 *  - composite (ex: "123-bep20-5")     -> dépôt BEP20 (uid = user_id-bep20-slot)
 */
function extraireUserIdReel($valeurBrute){
    if(preg_match('/^(\d+)(?:-bep20-\d+)?$/', (string)$valeurBrute, $m)){
        return (int)$m[1];
    }
    return null;
}


function verifyPlisioCallback($post, $secretKey){

    if(!isset($post['verify_hash'])){
        return false;
    }

    $verifyHash = $post['verify_hash'];
    unset($post['verify_hash']);

    ksort($post);

    if(isset($post['expire_utc'])){
        $post['expire_utc'] = (string)$post['expire_utc'];
    }

    if(isset($post['tx_urls'])){
        $post['tx_urls'] = html_entity_decode($post['tx_urls']);
    }

    $postString = serialize($post);
    $checkKey = hash_hmac('sha1', $postString, $secretKey);

    return hash_equals($checkKey, $verifyHash);

}


// Le payload peut arriver en form-data (défaut) ou en JSON (si ?json=true)
$raw = file_get_contents("php://input");
$json = json_decode($raw, true);

$data = (is_array($json) && !empty($json)) ? $json : $_POST;


if(!verifyPlisioCallback($data, PLISIO_SECRET_KEY)){
    http_response_code(422);
    error_log("Plisio webhook : signature invalide - ".json_encode($data));
    exit("invalid signature");
}


$status = $data['status'] ?? '';


// ---- Cas 1 : dépôt sur adresse permanente (portefeuille) ----
if(($data['ipn_type'] ?? '') === 'pay_in'){

    if($status !== 'completed'){
        // pas encore confirmé, on ignore pour l'instant
        http_response_code(200);
        exit("ok - waiting confirmations");
    }

    $depositUid = $data['deposit_uid'] ?? null; // = uid fourni lors de shops/deposit/new (ex: "123" ou "123-bep20-5")
    $txn_id     = $data['txn_id'] ?? null;
    $amount     = isset($data['amount']) ? (float)$data['amount'] : 0;
    $network    = $data['psys_cid'] ?? ($data['currency'] ?? '');

    if(!$depositUid || !$txn_id || $amount <= 0){
        http_response_code(400);
        exit("missing fields");
    }

    // IMPORTANT : pour les adresses BEP20 (multi-slots), deposit_uid est composite
    // (ex: "123-bep20-5") et ne correspond PAS directement à user_wallets.user_id.
    // Il faut en extraire le user_id numérique réel avant tout crédit.
    $user_id = extraireUserIdReel($depositUid);

    if($user_id === null){
        http_response_code(400);
        error_log("Plisio webhook : deposit_uid illisible - ".json_encode($data));
        exit("invalid deposit_uid");
    }

    // éviter double crédit + garantir que INSERT et UPDATE passent ensemble ou pas du tout
    $nouveauDepot = false;

    try {

        $pdo->beginTransaction();

        $check = $pdo->prepare("SELECT id FROM wallet_deposits WHERE txid=? FOR UPDATE");
        $check->execute([$txn_id]);

        if(!$check->fetch()){

            $pdo->prepare("
                INSERT INTO wallet_deposits (user_id, txid, network, montant)
                VALUES (?,?,?,?)
            ")->execute([$user_id, $txn_id, $network, $amount]);

            $pdo->prepare("
                UPDATE user_wallets SET solde = solde + ? WHERE user_id=?
            ")->execute([$amount, $user_id]);

            $nouveauDepot = true;

        }

        $pdo->commit();

    } catch(Exception $e){
        $pdo->rollBack();
        error_log("Plisio webhook : erreur crédit user_id=".$user_id." txn_id=".$txn_id." : ".$e->getMessage());
        http_response_code(500);
        exit("error");
    }

    // Email de confirmation au client : "vous avez reçu X USDT sur votre compte"
    // Uniquement si ce dépôt vient d'être réellement crédité (pas un webhook en double)
    if($nouveauDepot){

        if(!function_exists('sendMail')){
            require_once __DIR__ . "/../mail.php";
        }

        $stmtEmailDepot = $pdo->prepare("SELECT email FROM users WHERE id=?");
        $stmtEmailDepot->execute([$user_id]);
        $emailClientDepot = $stmtEmailDepot->fetchColumn();

        if($emailClientDepot){

            // Montant affiché au client = montant brut moins 2% (frais retenus),
            // cohérent avec le solde affiché dans portefeuille.php / get_solde.php /
            // historique.php / retrait_mvola.php. Le solde brut en base n'est pas
            // modifié : $amount reste le montant réel crédité, seul l'affichage
            // du mail est concerné.
            $amountAffiche = floor($amount * 0.98 * 100) / 100;

            $messageDepot = "
Bonjour,

Vous avez reçu ".number_format($amountAffiche,2)." USDT sur votre compte (réseau ".$network.").

Merci de votre confiance.
";

            try{
                sendMail(
                    $emailClientDepot,
                    "✅ Vous avez reçu ".number_format($amountAffiche,2)." USDT",
                    $messageDepot
                );
            } catch(Throwable $e){
                error_log("Erreur envoi mail dépôt (webhook_plisio) user_id=".$user_id." : ".$e->getMessage());
            }

        }

    }

    http_response_code(200);
    exit("ok");

}


// ---- Cas 2 : callback d'une facture (create_invoice.php) ----
// order_number a été généré via uniqid("DEP") dans create_invoice.php,
// à relier à ton propre suivi de facture si besoin.
error_log("Plisio webhook invoice : ".json_encode($data));

http_response_code(200);
exit("ok");