<?php

/**
 * AUDIT DOUBLONS DE DEPOTS - lecture seule, ne modifie AUCUN solde.
 *
 * Repère dans wallet_deposits les paires de dépôts probablement dupliquées
 * (même user, même montant, réseaux équivalents BEP20/USDT_BSC, à quelques
 * minutes d'écart, txid différents) — signature typique du bug où le même
 * dépôt était crédité à la fois par webhook_plisio.php (txn_id Plisio) et
 * par check_wallet_deposits.php (hash blockchain réel).
 *
 * Pour chaque doublon trouvé, indique aussi si l'utilisateur a déjà
 * effectué un retrait après le doublon (cas prioritaire : argent réel
 * déjà sorti sur la base d'un solde gonflé).
 *
 * Usage : php audit_doublons_depots.php   (en ligne de commande sur le VPS)
 * ou en HTTP (protéger l'accès - fichier de diagnostic admin uniquement).
 */

require_once "../config.php";

// Fenêtre de temps entre deux dépôts pour les considérer comme un doublon
// probable du même événement blockchain (webhook vs cron arrivent
// généralement à quelques secondes/minutes d'écart).
$FENETRE_DOUBLON_MINUTES = 15;

echo "===== AUDIT DOUBLONS DE DEPOTS (lecture seule) =====\n\n";

// "BEP20" et "USDT_BSC" désignent le même réseau selon que l'entrée vient
// du webhook (psys_cid Plisio) ou du cron (scan direct BscScan) — donc à
// traiter comme équivalents pour la détection.
function reseauNormalise($network){
    $n = strtoupper(trim((string)$network));
    if(in_array($n, ["BEP20", "USDT_BSC", "BSC"])) return "BEP20/BSC";
    if(in_array($n, ["TRC20", "USDT_TRX", "TRON"])) return "TRC20";
    return $n;
}

$stmt = $pdo->query("
    SELECT id, user_id, txid, network, montant, created_at
    FROM wallet_deposits
    ORDER BY user_id ASC, created_at ASC
");

$depots = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Regroupe par utilisateur pour comparer chaque dépôt aux suivants du même
// utilisateur (montant identique + réseau équivalent + proche dans le temps).
$parUser = [];
foreach($depots as $d){
    $parUser[$d['user_id']][] = $d;
}

$doublonsTrouves = [];

foreach($parUser as $user_id => $listeDepots){

    $n = count($listeDepots);

    for($i = 0; $i < $n; $i++){
        for($j = $i + 1; $j < $n; $j++){

            $a = $listeDepots[$i];
            $b = $listeDepots[$j];

            if($a['txid'] === $b['txid']) continue; // vrai doublon exact impossible normalement (contrainte/dédup), on ignore ici

            $memeMontant = abs((float)$a['montant'] - (float)$b['montant']) < 0.000001;
            $memeReseau  = reseauNormalise($a['network']) === reseauNormalise($b['network']);

            if(!$memeMontant || !$memeReseau) continue;

            $tA = strtotime($a['created_at']);
            $tB = strtotime($b['created_at']);
            $ecartMinutes = abs($tB - $tA) / 60;

            if($ecartMinutes <= $FENETRE_DOUBLON_MINUTES){
                $doublonsTrouves[] = [$a, $b, $ecartMinutes];
            }
        }
    }
}

if(empty($doublonsTrouves)){
    echo "Aucun doublon probable détecté. \n";
    exit;
}

echo count($doublonsTrouves)." doublon(s) probable(s) détecté(s) :\n\n";

$totalGonfle = 0;

foreach($doublonsTrouves as [$a, $b, $ecart]){

    $user_id = $a['user_id'];
    $montantDouble = (float)$a['montant'];
    $totalGonfle += $montantDouble;

    echo "--------------------------------------------------\n";
    echo "user_id       : ".$user_id."\n";
    echo "montant       : ".$montantDouble." USDT (compté 2x -> ".($montantDouble*2)." USDT crédités au lieu de ".$montantDouble." USDT)\n";
    echo "écart temps   : ".round($ecart,1)." min\n";
    echo "  dépôt 1 : id=".$a['id']." txid=".$a['txid']." réseau=".$a['network']." le ".$a['created_at']."\n";
    echo "  dépôt 2 : id=".$b['id']." txid=".$b['txid']." réseau=".$b['network']." le ".$b['created_at']."\n";

    // Solde actuel du compte
    $stmtSolde = $pdo->prepare("SELECT solde FROM user_wallets WHERE user_id=?");
    $stmtSolde->execute([$user_id]);
    $soldeActuel = (float)$stmtSolde->fetchColumn();
    echo "solde actuel  : ".$soldeActuel." USDT\n";

    // Retraits effectués APRÈS le doublon (= argent réel déjà sorti sur la
    // base d'un solde gonflé -> cas prioritaire à traiter).
    $dateDoublon = max($a['created_at'], $b['created_at']);
    $stmtRetraits = $pdo->prepare("
        SELECT id, montant_usdt, created_at
        FROM retraits_mvola
        WHERE user_id = ? AND created_at >= ?
        ORDER BY created_at ASC
    ");
    $stmtRetraits->execute([$user_id, $dateDoublon]);
    $retraitsApres = $stmtRetraits->fetchAll(PDO::FETCH_ASSOC);

    if(!empty($retraitsApres)){
        echo "⚠️  RETRAIT(S) DEJA EFFECTUE(S) APRES CE DOUBLON :\n";
        foreach($retraitsApres as $r){
            echo "     retrait id=".$r['id']." montant=".$r['montant_usdt']." USDT le ".$r['created_at']."\n";
        }
        echo "     -> à vérifier en priorité : l'utilisateur a peut-être retiré plus que ce qu'il a réellement déposé.\n";
    } else {
        echo "Aucun retrait après ce doublon : correction du solde sans risque de déficit déjà sorti.\n";
    }

    echo "\n";
}

echo "--------------------------------------------------\n";
echo "TOTAL estimé gonflé à tort : ".$totalGonfle." USDT sur ".count($doublonsTrouves)." doublon(s)\n";
echo "\nRAPPEL : ce script ne modifie RIEN. Pour corriger un doublon confirmé,\n";
echo "il faut décider manuellement quelle ligne garder (généralement la ligne\n";
echo "du webhook Plisio, la plus fiable), supprimer l'autre ligne, et\n";
echo "soustraire le montant en double de user_wallets.solde - en tenant\n";
echo "compte des retraits déjà effectués le cas échéant.\n";
