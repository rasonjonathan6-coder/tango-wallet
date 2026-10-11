<?php
/**
 * Historique combiné (dépôts USDT reçus + retraits Mvola) pour l'utilisateur connecté.
 * Appelé en arrière-plan (fetch) depuis portefeuille.php pour remplir la box flottante
 * d'historique, sans recharger la page.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

require_once __DIR__ . "/../config.php";

$user_id = $_SESSION['user_id'];

// --- Dépôts USDT reçus (BEP20) ---
$stmtDepots = $pdo->prepare("
    SELECT *
    FROM wallet_deposits
    WHERE user_id = ?
    ORDER BY id DESC
    LIMIT 50
");
$stmtDepots->execute([$user_id]);
$depots = $stmtDepots->fetchAll(PDO::FETCH_ASSOC);

// --- Retraits vers Mvola ---
$stmtRetraits = $pdo->prepare("
    SELECT montant_usdt, montant_mga, network, numero_mvola, statut, created_at
    FROM retraits_mvola
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 50
");
$stmtRetraits->execute([$user_id]);
$retraits = $stmtRetraits->fetchAll(PDO::FETCH_ASSOC);

$historique = [];

// Même calcul que get_solde.php / retrait_mvola.php / webhook_plisio.php :
// solde réel moins 2,5% de frais forfaitaires.
function soldeAfficheDepuisBrut(float $montantBrut): float {
    return $montantBrut * (1 - 0.025);
}

foreach ($depots as $d) {
    // Montant affiché dans l'historique = montant réel du dépôt moins le
    // frais BEP20, pour être cohérent avec le montant
    // affiché ailleurs (email de confirmation, solde, plafond de retrait —
    // voir portefeuille.php et retrait_mvola.php).
    $montantDepotBrut = (float)($d['montant'] ?? 0);

    $historique[] = [
        'type'         => 'depot',
        // Arrondi vers le BAS (floor), pas au plus proche : sinon ce montant
        // peut afficher plus que le vrai plafond utilisé par retrait_mvola.php.
        'montant_usdt' => floor(soldeAfficheDepuisBrut($montantDepotBrut) * 100) / 100,
        'network'      => $d['network'] ?? '',
        'statut'       => 'confirme',
        'date'         => $d['created_at'] ?? null,
        'reference'    => $d['txid'] ?? null,
    ];
}

foreach ($retraits as $r) {
    $historique[] = [
        'type'         => 'retrait',
        'montant_usdt' => (float)($r['montant_usdt'] ?? 0),
        'montant_mga'  => (float)($r['montant_mga'] ?? 0),
        'network'      => $r['network'] ?? '',
        'numero_mvola' => $r['numero_mvola'] ?? '',
        'statut'       => $r['statut'] ?? 'en_attente',
        'date'         => $r['created_at'] ?? null,
    ];
}

// Tri du plus récent au plus ancien. Les lignes sans date connue (colonne
// absente côté dépôts) sont reléguées à la fin plutôt que de casser le tri.
usort($historique, function ($a, $b) {
    return strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? ''));
});

$historique = array_slice($historique, 0, 60);

echo json_encode(['historique' => $historique]);