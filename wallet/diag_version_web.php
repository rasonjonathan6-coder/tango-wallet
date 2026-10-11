<?php
/**
 * diag_version_web.php
 *
 * Diagnostic accessible par navigateur (pas besoin de SSH) : vérifie
 * quelle version de check_wallet_deposits.php est actuellement en place
 * sur le serveur (ancienne ou nouvelle, avec repli multi-RPC).
 *
 * A METTRE DANS LE DOSSIER wallet/ (à côté de check_wallet_deposits.php)
 *
 * IMPORTANT :
 *  1. Change IMPÉRATIVEMENT le token ci-dessous.
 *  2. SUPPRIME ce fichier du serveur une fois le diagnostic terminé.
 *
 * Usage :
 *   https://tondomaine.com/wallet/diag_version_web.php?token=TON_TOKEN
 */

define("DIAG_VERSION_SECRET_TOKEN", getenv('DIAG_SECRET_TOKEN') ?: '');

$tokenRecu = $_GET['token'] ?? '';

if(!hash_equals(DIAG_VERSION_SECRET_TOKEN, $tokenRecu)){
    http_response_code(403);
    exit("forbidden");
}

header("Content-Type: text/plain; charset=utf-8");

$fichier = __DIR__ . "/check_wallet_deposits.php";

if(!file_exists($fichier)){
    echo "Fichier introuvable : $fichier\n";
    exit;
}

$contenu = file_get_contents($fichier);
$nbLignes = substr_count($contenu, "\n") + 1;
$dateModif = date("Y-m-d H:i:s", filemtime($fichier));

echo "=== Diagnostic version : check_wallet_deposits.php ===\n\n";
echo "Chemin         : $fichier\n";
echo "Taille         : ".strlen($contenu)." octets\n";
echo "Nombre lignes  : $nbLignes\n";
echo "Dernière modif : $dateModif\n\n";

$marqueurs = [
    "bep20_scan_state"              => "table de suivi du dernier bloc scanné (BEP20)",
    "recupererTransfersTrc20TronScan" => "repli TRC20 via TronScan",
    "ANKR_API_KEY"                   => "référence à la clé Ankr",
    "recupererTransfersBep20AvecFailover" => "repli BEP20 eth_getLogs multi-RPC",
];

echo "=== Présence des éléments de la version à jour ===\n\n";

$tousPresents = true;

foreach($marqueurs as $motif => $description){
    $nb = substr_count($contenu, $motif);
    $ok = $nb > 0;
    if(!$ok){
        $tousPresents = false;
    }
    echo ($ok ? "✅ TROUVÉ" : "❌ ABSENT")." (".$nb.") : $description\n";
    echo "   (recherché : \"$motif\")\n\n";
}

echo "=== Conclusion ===\n";
if($tousPresents){
    echo "La version à jour (avec repli multi-RPC BEP20/TRC20) est bien en place.\n";
} else {
    echo "La version en place est INCOMPLÈTE ou ANCIENNE : au moins un élément attendu est absent.\n";
    echo "Il faut re-uploader le fichier check_wallet_deposits.php à jour à cet emplacement exact :\n";
    echo "$fichier\n";
}
