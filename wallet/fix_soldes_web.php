<?php
/**
 * fix_soldes_web.php
 *
 * Version accessible par navigateur de fix_soldes_deposits.php, pour les
 * hébergements sans accès SSH/terminal.
 *
 * A METTRE DANS LE DOSSIER wallet/ (à côté de webhook_plisio.php, etc.)
 *
 * IMPORTANT :
 *  1. Change IMPÉRATIVEMENT le token ci-dessous (mets une chaîne longue et
 *     aléatoire, pas "changemoi").
 *  2. Ouvre d'abord SANS "&apply=1" pour voir le rapport (dry run).
 *  3. Une fois le rapport vérifié, rajoute "&apply=1" dans l'URL pour
 *     appliquer réellement la correction. Une seule fois.
 *  4. SUPPRIME ce fichier du serveur une fois la correction faite.
 *
 * Usage :
 *   https://tondomaine.com/wallet/fix_soldes_web.php?token=TON_TOKEN_SECRET
 *   https://tondomaine.com/wallet/fix_soldes_web.php?token=TON_TOKEN_SECRET&apply=1
 */

define("FIX_SECRET_TOKEN", getenv('FIX_SECRET_TOKEN') ?: '');

$tokenRecu = $_GET['token'] ?? '';

if(!hash_equals(FIX_SECRET_TOKEN, $tokenRecu)){
    http_response_code(403);
    exit("forbidden");
}

header("Content-Type: text/plain; charset=utf-8");

require_once __DIR__ . "/../config.php"; // doit fournir $pdo (PDO connecté)

$dryRun = !isset($_GET['apply']) || $_GET['apply'] !== '1';


/**
 * Extrait le user_id "réel" à partir de la valeur stockée dans
 * wallet_deposits.user_id, qu'elle soit:
 *  - déjà numérique (ex: "123")            -> dépôt TRC20 ou déjà correct
 *  - composite (ex: "123-bep20-5")         -> dépôt BEP20 mal formé
 */
function extraireUserIdReel($valeurBrute){
    if(preg_match('/^(\d+)(?:-bep20-\d+)?$/', (string)$valeurBrute, $m)){
        return (int)$m[1];
    }
    return null;
}


echo $dryRun
    ? "===== MODE DRY RUN (rien n'est modifié) =====\n\n"
    : "===== MODE APPLICATION REELLE =====\n\n";


// 1) Récupérer tous les dépôts enregistrés
$stmt = $pdo->query("
    SELECT id, user_id, txid, network, montant
    FROM wallet_deposits
    ORDER BY id ASC
");
$deposits = $stmt->fetchAll(PDO::FETCH_ASSOC);

if(empty($deposits)){
    echo "Aucun dépôt trouvé dans wallet_deposits.\n";
    exit;
}

// 2) Regrouper le total des dépôts par vrai user_id
$totalDeposeParUser = [];
$lignesACorrigerUserId = [];

foreach($deposits as $d){
    $realUserId = extraireUserIdReel($d['user_id']);

    if($realUserId === null){
        echo "ATTENTION : ligne id={$d['id']} : user_id illisible (\"{$d['user_id']}\"), ignorée.\n";
        continue;
    }

    $totalDeposeParUser[$realUserId] = ($totalDeposeParUser[$realUserId] ?? 0) + (float)$d['montant'];

    if((string)$realUserId !== (string)$d['user_id']){
        $lignesACorrigerUserId[] = ['id' => $d['id'], 'ancien' => $d['user_id'], 'nouveau' => $realUserId];
    }
}

// 3) Comparer au solde actuel de chaque wallet
echo "=== Rapport de réconciliation ===\n\n";

$manquesParUser = [];

foreach($totalDeposeParUser as $userId => $totalDepose){

    $stmtWallet = $pdo->prepare("SELECT solde FROM user_wallets WHERE user_id = ?");
    $stmtWallet->execute([$userId]);
    $wallet = $stmtWallet->fetch(PDO::FETCH_ASSOC);

    if(!$wallet){
        echo "ERREUR : User #$userId : aucun portefeuille trouvé (total déposé = $totalDepose USDT) - à vérifier manuellement.\n";
        continue;
    }

    $soldeActuel = (float)$wallet['solde'];
    $ecart = round($totalDepose - $soldeActuel, 8);

    if($ecart > 0.00000001){
        echo "MANQUANT : User #$userId : solde actuel = $soldeActuel USDT, total dépôts confirmés = $totalDepose USDT => manque $ecart USDT\n";
        $manquesParUser[$userId] = $ecart;
    } else {
        echo "OK : User #$userId : solde cohérent ($soldeActuel USDT).\n";
    }
}

echo "\n=== Lignes wallet_deposits avec user_id composite à normaliser ===\n";
if(empty($lignesACorrigerUserId)){
    echo "Aucune.\n";
} else {
    foreach($lignesACorrigerUserId as $l){
        echo "id={$l['id']} : \"{$l['ancien']}\" => {$l['nouveau']}\n";
    }
}

if(empty($manquesParUser) && empty($lignesACorrigerUserId)){
    echo "\nRien à corriger.\n";
    exit;
}

if($dryRun){
    echo "\n[DRY RUN] Aucune modification appliquée.\n";
    echo "Vérifie le rapport ci-dessus, puis rouvre cette page en ajoutant &apply=1 dans l'URL pour appliquer réellement la correction.\n";
    exit;
}

// 4) Application réelle de la correction
$pdo->beginTransaction();

try {

    foreach($manquesParUser as $userId => $montantManquant){
        $upd = $pdo->prepare("UPDATE user_wallets SET solde = solde + ? WHERE user_id = ?");
        $upd->execute([$montantManquant, $userId]);

        if($upd->rowCount() === 0){
            throw new Exception("Aucun portefeuille mis à jour pour user_id=$userId (vérifie que ce compte existe).");
        }

        echo "Crédité +$montantManquant USDT pour user #$userId\n";
    }

    foreach($lignesACorrigerUserId as $l){
        $pdo->prepare("UPDATE wallet_deposits SET user_id = ? WHERE id = ?")
            ->execute([$l['nouveau'], $l['id']]);
    }

    $pdo->commit();
    echo "\nCorrection appliquée avec succès.\n";

} catch (Exception $e) {
    $pdo->rollBack();
    echo "\nErreur, rollback effectué : " . $e->getMessage() . "\n";
}
