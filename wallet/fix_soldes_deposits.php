<?php
/**
 * Script de réconciliation des soldes user_wallets vs wallet_deposits.
 *
 * Objectif : détecter les dépôts déjà confirmés par Plisio mais dont le
 * montant n'a jamais été crédité dans user_wallets.solde (cas typique :
 * dépôt reçu sur une adresse BEP20, dont le deposit_uid renvoyé par
 * Plisio est du type "123-bep20-5" au lieu de "123").
 *
 * MODE PAR DEFAUT = DRY RUN (aucune écriture en base).
 * Passe $dryRun = false pour appliquer réellement la correction,
 * UNE FOIS que tu as vérifié le rapport affiché.
 *
 * Usage : php fix_soldes_deposits.php
 */

require_once __DIR__ . "/config.php"; // doit fournir $pdo (PDO connecté)

$dryRun = true; // <-- passe à false pour appliquer réellement la correction


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
$totalDeposeParUser = [];   // user_id => somme des montants déposés (table wallet_deposits)
$lignesACorrigerUserId = []; // lignes dont le user_id stocké est composite, à normaliser

foreach($deposits as $d){
    $realUserId = extraireUserIdReel($d['user_id']);

    if($realUserId === null){
        echo "⚠️  Ligne id={$d['id']} : user_id illisible (\"{$d['user_id']}\"), ignorée.\n";
        continue;
    }

    $totalDeposeParUser[$realUserId] = ($totalDeposeParUser[$realUserId] ?? 0) + (float)$d['montant'];

    if((string)$realUserId !== (string)$d['user_id']){
        $lignesACorrigerUserId[] = ['id' => $d['id'], 'ancien' => $d['user_id'], 'nouveau' => $realUserId];
    }
}

// 3) Comparer au solde actuel de chaque wallet
echo "=== Rapport de réconciliation ===\n\n";

$manquesParUser = []; // user_id => montant manquant à créditer

foreach($totalDeposeParUser as $userId => $totalDepose){

    $stmtWallet = $pdo->prepare("SELECT solde FROM user_wallets WHERE user_id = ?");
    $stmtWallet->execute([$userId]);
    $wallet = $stmtWallet->fetch(PDO::FETCH_ASSOC);

    if(!$wallet){
        echo "❌ User #$userId : aucun portefeuille trouvé (total déposé = $totalDepose USDT) — à vérifier manuellement.\n";
        continue;
    }

    $soldeActuel = (float)$wallet['solde'];
    $ecart = round($totalDepose - $soldeActuel, 8);

    if($ecart > 0.00000001){
        echo "🔴 User #$userId : solde actuel = $soldeActuel USDT, total dépôts confirmés = $totalDepose USDT => manque $ecart USDT\n";
        $manquesParUser[$userId] = $ecart;
    } else {
        echo "✅ User #$userId : solde cohérent ($soldeActuel USDT).\n";
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
    echo "\nRien à corriger. ✅\n";
    exit;
}

if($dryRun){
    echo "\n[DRY RUN] Aucune modification appliquée.\n";
    echo "Vérifie le rapport ci-dessus, puis passe \$dryRun = false dans ce script et relance-le pour appliquer la correction.\n";
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
    echo "\n✅ Correction appliquée avec succès.\n";

} catch (Exception $e) {
    $pdo->rollBack();
    echo "\n❌ Erreur, rollback effectué : " . $e->getMessage() . "\n";
}
