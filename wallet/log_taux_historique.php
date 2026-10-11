<?php

// Script CRON (à appeler périodiquement, ex. toutes les 30-60 min via
// cron-job.org, comme le reste des tâches cron du projet) : enregistre
// un instantané du taux USDT/Ariary courant dans taux_historique, pour
// pouvoir tracer la courbe dans portefeuille.php.
//
// Ce script ne sert PAS à rafraîchir le taux lui-même (ça, c'est déjà
// fait par le cron existant) : il se contente de lire le taux courant
// via getTauxActuel() et d'en garder une trace dans le temps.

require_once "../config.php";
require_once "taux.php";

// Securite : fail-closed. Definir CRON_SECRET (env CRON_SECRET) puis appeler
// ce script avec ?secret=... . Sans secret configure, l'acces est refuse.
$secretAttendu = getenv('CRON_SECRET') ?: '';
if ($secretAttendu === '' || !isset($_GET['secret']) || !hash_equals($secretAttendu, $_GET['secret'])) {
    http_response_code(403);
    echo "Accès refusé.";
    exit;
}

$taux = getTauxActuel($pdo);

if (!$taux || !isset($taux['taux_client'])) {
    http_response_code(503);
    echo "Taux indisponible, rien à enregistrer.";
    exit;
}

$tauxClient = round((float)$taux['taux_client'], 2);

// Évite d'empiler des lignes identiques si le taux n'a pas bougé depuis
// le dernier passage : on ne réenregistre que si la valeur a changé,
// ou si aucun point n'a encore été enregistré aujourd'hui (pour garder
// au moins un point par jour même si le taux est stable).
$stmt = $pdo->prepare("
    SELECT taux_client, enregistre_le
    FROM taux_historique
    ORDER BY enregistre_le DESC
    LIMIT 1
");
$stmt->execute();
$dernier = $stmt->fetch(PDO::FETCH_ASSOC);

$doitEnregistrer = true;
if ($dernier) {
    $memeValeur   = abs((float)$dernier['taux_client'] - $tauxClient) < 0.005;
    $memeJournee  = substr($dernier['enregistre_le'], 0, 10) === date('Y-m-d');
    if ($memeValeur && $memeJournee) {
        $doitEnregistrer = false;
    }
}

if ($doitEnregistrer) {
    $stmt = $pdo->prepare("
        INSERT INTO taux_historique (taux_client, enregistre_le)
        VALUES (?, NOW())
    ");
    $stmt->execute([$tauxClient]);
    echo "Enregistré : " . $tauxClient . " Ar";
} else {
    echo "Inchangé, pas d'enregistrement (" . $tauxClient . " Ar)";
}
