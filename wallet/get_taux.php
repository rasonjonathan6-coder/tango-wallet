<?php

// Endpoint AJAX : renvoie le taux actuel en JSON (pour le calcul en direct
// dans le formulaire de retrait, côté client). Ne sert QUE à l'affichage :
// le vrai calcul est refait côté serveur dans retrait_mvola.php avant de
// débiter le solde.

session_start();

if(!isset($_SESSION['user_id'])){
    http_response_code(403);
    echo json_encode(["error"=>"non connecté"]);
    exit;
}

require_once "../config.php";
require_once "taux.php";

$taux = getTauxActuel($pdo);

if(!$taux){
    http_response_code(503);
    echo json_encode(["error"=>"taux indisponible pour le moment"]);
    exit;
}

header("Content-Type: application/json");
echo json_encode([
    "taux_client" => round((float)$taux['taux_client'], 2)
]);
