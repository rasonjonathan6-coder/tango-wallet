<?php

// Endpoint AJAX : renvoie le solde courant en JSON pour le rafraîchissement
// automatique de portefeuille.php / retrait_mvola.php (détection d'un dépôt
// en temps réel). Le solde renvoyé est le solde AFFICHÉ (solde brut moins
// 2,5 % de frais), arrondi vers le bas, identique à portefeuille.php,
// retrait_mvola.php et historique.php.

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(["error" => "non connecté"]);
    exit;
}

require_once "config.php";
require_once "taux.php";

$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT solde FROM user_wallets WHERE user_id = ? LIMIT 1");
$stmt->execute([$user_id]);
$soldeBrut = (float)($stmt->fetchColumn() ?: 0);

$soldeNet = floor($soldeBrut * (1 - 0.025) * 100) / 100;

$taux = getTauxActuel($pdo);
$tauxAffiche = $taux ? (float)$taux['taux_client'] : 0;

header("Content-Type: application/json");
echo json_encode([
    "solde_usdt" => $soldeNet,
    "solde_ar"   => $tauxAffiche > 0 ? $soldeNet * $tauxAffiche : null,
]);
