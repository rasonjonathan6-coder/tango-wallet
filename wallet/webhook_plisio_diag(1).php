<?php

/**
 * webhook_plisio_diag.php
 *
 * Version DIAGNOSTIC de webhook_plisio.php.
 * Fait EXACTEMENT la même chose que l'original (même logique de crédit),
 * mais journalise en plus, à chaque appel, tout ce qui est reçu et calculé,
 * dans un fichier log lisible via view_webhook_log.php.
 *
 * UTILISATION :
 *  1. Dépose ce fichier ET view_webhook_log.php dans le dossier wallet/
 *     (à côté de webhook_plisio.php).
 *  2. Change temporairement la "Status URL" sur ton dashboard Plisio pour
 *     qu'elle pointe vers webhook_plisio_diag.php au lieu de webhook_plisio.php.
 *  3. Attends le prochain callback (ou renvoie un dépôt de test si Plisio
 *     propose une option "resend webhook").
 *  4. Consulte le résultat via view_webhook_log.php (protégé par token).
 *  5. Une fois le problème identifié, remets la Status URL sur
 *     webhook_plisio.php et SUPPRIME ces 2 fichiers diagnostic du serveur.
 *
 * IMPORTANT : change les tokens ci-dessous ET dans view_webhook_log.php
 * avant de mettre ce fichier en ligne.
 */

require_once "../config.php";
require_once "plisio_config.php";

define("WEBHOOK_DIAG_LOG", __DIR__ . "/webhook_plisio_diag.log");


function extraireUserIdReel($valeurBrute){
    if(preg_match('/^(\d+)(?:-bep20-\d+)?$/', (string)$valeurBrute, $m)){
        return (int)$m[1];
    }
    return null;
}


/**
 * Version instrumentée : renvoie non seulement true/false, mais aussi
 * le détail du calcul pour pouvoir le journaliser.
 */
function verifyPlisioCallbackDiag($post, $secretKey){

    $debug = [
        "a_verify_hash" => isset($post['verify_hash']),
        "champs_recus"  => array_keys($post),
    ];

    if(!isset($post['verify_hash'])){
        $debug["resultat"] = "rejeté : pas de verify_hash dans le payload";
        return [false, $debug];
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

    $match = hash_equals($checkKey, $verifyHash);

    $debug["hash_recu"]    = $verifyHash;
    $debug["hash_calcule"] = $checkKey;
    $debug["match"]        = $match;
    $debug["post_string_serialise"] = $postString;

    return [$match, $debug];

}


function logDiag($entry){
    $ligne = "===== " . date('Y-m-d H:i:s') . " =====\n"
        . json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        . "\n\n";
    @file_put_contents(WEBHOOK_DIAG_LOG, $ligne, FILE_APPEND | LOCK_EX);
}


// ---- Capture du payload brut, quel que soit le format ----
$raw = file_get_contents("php://input");
$json = json_decode($raw, true);

$formatDetecte = (is_array($json) && !empty($json)) ? "json" : "form-data (\$_POST)";
$data = (is_array($json) && !empty($json)) ? $json : $_POST;

$entryBase = [
    "ip_source"        => $_SERVER['REMOTE_ADDR'] ?? 'inconnue',
    "methode"          => $_SERVER['REQUEST_METHOD'] ?? 'inconnue',
    "content_type"     => $_SERVER['CONTENT_TYPE'] ?? ($_SERVER['HTTP_CONTENT_TYPE'] ?? 'non fourni'),
    "format_detecte"   => $formatDetecte,
    "raw_payload"      => $raw,
    "post_brut"        => $_POST,
    "json_decode_brut" => $json,
];

// ---- Vérification instrumentée ----
[$signatureValide, $debugSignature] = verifyPlisioCallbackDiag($data, PLISIO_SECRET_KEY);

$entryBase["diagnostic_signature"] = $debugSignature;


if(!$signatureValide){
    $entryBase["resultat_final"] = "REJETÉ : signature invalide";
    logDiag($entryBase);
    http_response_code(422);
    exit("invalid signature");
}


// ---- À partir d'ici, la logique est IDENTIQUE à webhook_plisio.php ----

$status = $data['status'] ?? '';

if(($data['ipn_type'] ?? '') === 'pay_in'){

    if($status !== 'completed'){
        $entryBase["resultat_final"] = "OK reçu, statut='$status' (en attente de confirmations)";
        logDiag($entryBase);
        http_response_code(200);
        exit("ok - waiting confirmations");
    }

    $depositUid = $data['deposit_uid'] ?? null;
    $txn_id     = $data['txn_id'] ?? null;
    $amount     = isset($data['amount']) ? (float)$data['amount'] : 0;
    $network    = $data['psys_cid'] ?? ($data['currency'] ?? '');

    if(!$depositUid || !$txn_id || $amount <= 0){
        $entryBase["resultat_final"] = "REJETÉ : champs manquants (deposit_uid/txn_id/amount)";
        logDiag($entryBase);
        http_response_code(400);
        exit("missing fields");
    }

    $user_id = extraireUserIdReel($depositUid);

    if($user_id === null){
        $entryBase["resultat_final"] = "REJETÉ : deposit_uid illisible ($depositUid)";
        logDiag($entryBase);
        http_response_code(400);
        exit("invalid deposit_uid");
    }

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

        $entryBase["resultat_final"] = $nouveauDepot
            ? "CRÉDITÉ : user_id=$user_id, montant=$amount, txid=$txn_id"
            : "IGNORÉ (déjà crédité précédemment) : txid=$txn_id";

    } catch(Exception $e){
        $pdo->rollBack();
        $entryBase["resultat_final"] = "ERREUR SQL : " . $e->getMessage();
        logDiag($entryBase);
        http_response_code(500);
        exit("error");
    }

    logDiag($entryBase);

    if($nouveauDepot){
        if(!function_exists('sendMail')){
            require_once __DIR__ . "/../mail.php";
        }
        $stmtEmailDepot = $pdo->prepare("SELECT email FROM users WHERE id=?");
        $stmtEmailDepot->execute([$user_id]);
        $emailClientDepot = $stmtEmailDepot->fetchColumn();

        if($emailClientDepot){
            $messageDepot = "\nBonjour,\n\nVous avez reçu ".number_format($amount,2)." USDT sur votre compte (réseau ".$network.").\n\nMerci de votre confiance.\n";
            try{
                sendMail($emailClientDepot, "✅ Vous avez reçu ".number_format($amount,2)." USDT", $messageDepot);
            } catch(Throwable $e){
                error_log("Erreur envoi mail dépôt (webhook_plisio_diag) user_id=".$user_id." : ".$e->getMessage());
            }
        }
    }

    http_response_code(200);
    exit("ok");

}

$entryBase["resultat_final"] = "Callback invoice (non utilisé) - ignoré";
logDiag($entryBase);

http_response_code(200);
exit("ok");
