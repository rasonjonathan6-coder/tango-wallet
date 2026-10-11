<?php
/**
 * cron_http.php
 *
 * Alternative à une vraie tâche cron serveur, pour les cas où tu n'as pas
 * accès à `crontab` (hébergement mutualisé, accès SSH limité, etc.).
 *
 * Un service externe (ex: cron-job.org, EasyCron) appelle cette URL toutes
 * les 2-5 minutes en HTTP. Le token secret empêche n'importe qui d'autre
 * de déclencher le script en devinant l'URL.
 *
 * IMPORTANT :
 *  1. Change IMPÉRATIVEMENT la valeur de CRON_SECRET_TOKEN ci-dessous
 *     (mets une chaîne longue et aléatoire, pas "changemoi").
 *  2. Ne mets JAMAIS ce token dans un lien public, un email, etc.
 *  3. Place idéalement ce fichier dans un dossier, pas à la racine du site,
 *     pour limiter le "bruit" des scans automatiques.
 */

define("CRON_SECRET_TOKEN", getenv('CRON_SECRET_TOKEN') ?: '');

$tokenRecu = $_GET['token'] ?? '';

if(!hash_equals(CRON_SECRET_TOKEN, $tokenRecu)){
    http_response_code(403);
    exit("forbidden");
}

// Empêche l'exécution simultanée de deux appels (si le service webcron
// relance avant que le précédent soit terminé).
// Sécurité supplémentaire : si le fichier de verrou date de plus de 2
// minutes, on considère qu'il est "périmé" (process précédent mort sans
// libérer le verrou proprement, ce qui arrive sur certains hébergements)
// et on le supprime avant de continuer.
$lockFile = __DIR__ . "/cron_http.lock";

if(file_exists($lockFile) && (time() - filemtime($lockFile)) > 120){
    @unlink($lockFile);
}

$lock = fopen($lockFile, "c");

if(!flock($lock, LOCK_EX | LOCK_NB)){
    http_response_code(429);
    exit("deja en cours d'execution");
}

touch($lockFile);

header("Content-Type: text/plain; charset=utf-8");

require_once __DIR__ . "/check_deposits.php";

flock($lock, LOCK_UN);
fclose($lock);