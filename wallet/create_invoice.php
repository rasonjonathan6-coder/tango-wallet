<?php

session_start();

require_once "../config.php";
require_once "../plisio_config.php";


if(!isset($_SESSION['user_id'])){
    header("Location: ?page=dashboard");
    exit;
}


$user_id = $_SESSION['user_id'];


// Montant du dépôt
if(!isset($_POST['montant'])){
    exit("Montant manquant");
}

$montant = floatval($_POST['montant']);

if($montant <= 0){
    exit("Montant invalide");
}


// Récupérer email utilisateur

$stmt = $pdo->prepare("
SELECT email 
FROM users 
WHERE id=?
");

$stmt->execute([$user_id]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);


$email = $user['email'];


// Génère l'order_number et l'enregistre AVANT d'appeler Plisio,
// pour pouvoir relier user_id <-> order_number quand le callback arrive.

$orderNumber = uniqid("DEP");

$pdo->prepare("
    INSERT INTO wallet_invoices (order_number, user_id, montant, statut)
    VALUES (?, ?, ?, 'en_attente')
")->execute([$orderNumber, $user_id, $montant]);


// Création facture Plisio

$url = "https://api.plisio.net/api/v1/invoices/new";


$params = [

    "api_key" => PLISIO_SECRET_KEY,

    "order_name" => "Dépôt USDT",

    "order_number" => $orderNumber,

    "source_currency" => "USD",

    "source_amount" => $montant,

    "currency" => "USDT_BSC",

    "email" => $email,

    // URLs de retour Plisio. Par défaut on se base sur le domaine de production
    // (CALLBACK_BASE_URL), surchargeable par variables d'environnement pour
    // pointer vers l'instance réellement en ligne (ex: IP ou nouveau domaine).
    "callback_url" => getenv('PLISIO_STATUS_URL')
        ?: rtrim(getenv('CALLBACK_BASE_URL') ?: 'https://deblocagetango.com', '/') . "/wallet/plisio_status.php",

    "success_callback_url" => getenv('PLISIO_SUCCESS_URL')
        ?: rtrim(getenv('CALLBACK_BASE_URL') ?: 'https://deblocagetango.com', '/') . "/success.php",

    "fail_callback_url" => getenv('PLISIO_FAIL_URL')
        ?: rtrim(getenv('CALLBACK_BASE_URL') ?: 'https://deblocagetango.com', '/') . "/failed.php"

];



$ch = curl_init();


curl_setopt_array($ch,[

    CURLOPT_URL=>$url,

    CURLOPT_POST=>true,

    CURLOPT_POSTFIELDS=>http_build_query($params),

    CURLOPT_RETURNTRANSFER=>true

]);


$response = curl_exec($ch);


curl_close($ch);



$result=json_decode($response,true);


if(isset($result['data']['invoice_url'])){

    header(
    "Location: ".$result['data']['invoice_url']
    );

    exit;

}


// En cas d'échec, on log le détail au lieu de l'afficher (ça cassait le header() plus haut)
error_log("Plisio create_invoice échec user_id=".$user_id." order_number=".$orderNumber." : ".json_encode($result));

// La facture Plisio n'a pas pu être créée : on nettoie l'entrée en_attente
// pour ne pas laisser une facture fantôme dans wallet_invoices.
$pdo->prepare("DELETE FROM wallet_invoices WHERE order_number=?")->execute([$orderNumber]);

exit("Impossible de créer la facture. Réessayez plus tard.");

?>