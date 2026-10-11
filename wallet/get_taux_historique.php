<?php

// Endpoint AJAX : renvoie les derniers points d'historique du taux
// USDT/Ariary, pour tracer la mini-courbe dans portefeuille.php.

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(["error" => "non connecté"]);
    exit;
}

require_once "../config.php";

// Fenêtre en heures à renvoyer (défaut 48h), configurable via ?heures=
$heures = isset($_GET['heures']) ? max(1, min(168, (int)$_GET['heures'])) : 48;

// Nombre max de points renvoyés au client (pour ne pas surcharger le tracé
// si les taux sont enregistrés très fréquemment sur une longue fenêtre).
$maxPoints = isset($_GET['max_points']) ? max(2, min(300, (int)$_GET['max_points'])) : 100;

$stmt = $pdo->prepare("
    SELECT taux_client, enregistre_le
    FROM taux_historique
    WHERE enregistre_le >= (NOW() - INTERVAL ? HOUR)
    ORDER BY enregistre_le ASC
");
$stmt->bindValue(1, $heures, PDO::PARAM_INT);
$stmt->execute();
$lignes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Downsampling : si on a plus de points que maxPoints, on garde un point
// tous les N pour rester lisible côté client (toujours en gardant le tout
// premier et le tout dernier point de la fenêtre).
$total = count($lignes);
if ($total > $maxPoints) {
    $pas = $total / $maxPoints;
    $selection = [];
    for ($i = 0; $i < $maxPoints; $i++) {
        $selection[] = $lignes[(int) round($i * $pas)];
    }
    $selection[] = $lignes[$total - 1];
    $lignes = array_values(array_unique($selection, SORT_REGULAR));
}

$points = array_map(function ($l) {
    return [
        "taux_client" => (float)$l['taux_client'],
        "date"        => $l['enregistre_le'],
    ];
}, $lignes);

header("Content-Type: application/json");
echo json_encode(["points" => $points]);
