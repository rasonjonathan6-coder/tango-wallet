<?php

/**
 * view_webhook_log.php
 *
 * Affiche le contenu de webhook_plisio_diag.log, protégé par token.
 *
 * IMPORTANT :
 *  1. Change IMPÉRATIVEMENT le token ci-dessous (mets une chaîne longue et
 *     aléatoire, pas "changemoi").
 *  2. SUPPRIME ce fichier (et webhook_plisio_diag.php + le .log) du serveur
 *     une fois le diagnostic terminé.
 *
 * Usage :
 *   https://tondomaine.com/wallet/view_webhook_log.php?token=TON_TOKEN
 *   https://tondomaine.com/wallet/view_webhook_log.php?token=TON_TOKEN&clear=1   (vide le log)
 */

define("VIEW_LOG_SECRET_TOKEN", getenv('VIEW_LOG_SECRET_TOKEN') ?: '');

$tokenRecu = $_GET['token'] ?? '';

if (VIEW_LOG_SECRET_TOKEN === '' || !hash_equals(VIEW_LOG_SECRET_TOKEN, $tokenRecu)) {
    http_response_code(403);
    exit("forbidden");
}

header("Content-Type: text/plain; charset=utf-8");

$logFile = __DIR__ . "/webhook_plisio_diag.log";

if(isset($_GET['clear']) && $_GET['clear'] === '1'){
    @unlink($logFile);
    echo "Log vidé.\n";
    exit;
}

if(!file_exists($logFile)){
    echo "Aucun log pour le moment. En attente d'un callback Plisio sur webhook_plisio_diag.php...\n";
    exit;
}

echo file_get_contents($logFile);
