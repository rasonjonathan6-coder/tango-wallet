<?php

/**
 * Callback Plisio pour les factures créées via create_invoice.php
 * (callback_url configuré dans create_invoice.php).
 *
 * Reçoit le statut de paiement d'une facture identifiée par order_number,
 * retrouve le user_id correspondant dans wallet_invoices, et crédite
 * le portefeuille si le paiement est confirmé.
 */

require_once "../config.php";
require_once "plisio_config.php";

if(!function_exists('sendMail')){
    require_once "../mail.php";
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


// Le payload peut arriver en form-data (défaut) ou en JSON
$raw = file_get_contents("php://input");
$json = json_decode($raw, true);

$data = (is_array($json) && !empty($json)) ? $json : $_POST;


if(!verifyPlisioCallback($data, PLISIO_SECRET_KEY)){
    http_response_code(422);
    error_log("Plisio status : signature invalide - ".json_encode($data));
    exit("invalid signature");
}


$orderNumber = $data['order_number'] ?? null;
$status      = $data['status'] ?? '';
$txnId       = $data['txn_id'] ?? ($data['tx_id'] ?? null);
$network     = $data['psys_cid'] ?? ($data['currency'] ?? '');

if(!$orderNumber){
    http_response_code(400);
    error_log("Plisio status : order_number manquant - ".json_encode($data));
    exit("missing order_number");
}


// Retrouver la facture correspondante
$stmt = $pdo->prepare("
    SELECT *
    FROM wallet_invoices
    WHERE order_number = ?
    LIMIT 1
");
$stmt->execute([$orderNumber]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$invoice){
    http_response_code(404);
    error_log("Plisio status : facture inconnue order_number=".$orderNumber);
    exit("unknown order_number");
}


// Statuts d'échec/expiration : on marque juste la facture, pas de crédit
if(in_array($status, ['expired', 'cancelled', 'error'])){

    $pdo->prepare("
        UPDATE wallet_invoices SET statut='failed' WHERE order_number=?
    ")->execute([$orderNumber]);

    http_response_code(200);
    exit("ok - marked failed");

}


// Seuls les statuts "completed" déclenchent le crédit
if($status !== 'completed'){
    http_response_code(200);
    exit("ok - waiting confirmations");
}


// Déjà traitée ? On évite tout double crédit.
if($invoice['statut'] === 'completed'){
    http_response_code(200);
    exit("ok - already processed");
}


$user_id = $invoice['user_id'];
$montant = (float)$invoice['montant'];

// txn_id : si Plisio n'en fournit pas sur ce callback, on retombe sur
// order_number pour garder une clé unique dans wallet_deposits.
$txid = $txnId ?: $orderNumber;


try {

    $pdo->beginTransaction();

    // Anti double-crédit au niveau transaction également
    $check = $pdo->prepare("SELECT id FROM wallet_deposits WHERE txid=?");
    $check->execute([$txid]);

    if(!$check->fetch()){

        $pdo->prepare("
            INSERT INTO wallet_deposits (user_id, txid, network, montant)
            VALUES (?,?,?,?)
        ")->execute([$user_id, $txid, $network ?: 'PLISIO', $montant]);

        $pdo->prepare("
            UPDATE user_wallets SET solde = solde + ? WHERE user_id=?
        ")->execute([$montant, $user_id]);

    }

    $pdo->prepare("
        UPDATE wallet_invoices SET statut='completed' WHERE order_number=?
    ")->execute([$orderNumber]);

    $pdo->commit();

} catch(Exception $e){

    $pdo->rollBack();
    error_log("Plisio status : erreur crédit order_number=".$orderNumber." : ".$e->getMessage());
    http_response_code(500);
    exit("error");

}


// Email de confirmation au client
$stmtEmail = $pdo->prepare("SELECT email FROM users WHERE id=?");
$stmtEmail->execute([$user_id]);
$emailClient = $stmtEmail->fetchColumn();

if($emailClient){

    // Montant affiché au client = montant brut moins 2% (frais retenus),
    // cohérent avec le solde affiché ailleurs (portefeuille, retrait, historique)
    // et avec le mail de dépôt de webhook_plisio.php. Le solde brut en base
    // n'est pas modifié.
    $montantAffiche = $montant * 0.98;

    $message = "
Bonjour,

Ton dépôt de ".number_format($montantAffiche,2)." USDT a bien été crédité sur ton portefeuille.

Merci de ta confiance.
";

    sendMail(
        $emailClient,
        "✅ Dépôt USDT confirmé",
        $message
    );

}


http_response_code(200);
exit("ok");