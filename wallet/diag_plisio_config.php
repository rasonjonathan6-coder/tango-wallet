<?php

/**
 * Diagnostic Plisio : IP sortante du serveur + URLs de callback webhook.
 *
 * Aide à (re)configurer Plisio :
 *  - la liste d'IP autorisées (whitelist) doit contenir l'IP affichée ici ;
 *  - la « Status URL » de la boutique doit pointer vers le webhook de cette
 *    application (webhook_plisio.php).
 *
 * Accès protégé par token (DIAG_PLISIO_BALANCE_TOKEN ou DIAG_SECRET_TOKEN).
 *
 * Usage :
 *   https://VOTRE-DOMAINE/wallet/diag_plisio_config.php?token=VOTRE_TOKEN
 */

define("DIAG_PLISIO_CONFIG_TOKEN", getenv('DIAG_SECRET_TOKEN') ?: '');

$tokenRecu = $_GET['token'] ?? '';

if (DIAG_PLISIO_CONFIG_TOKEN === '' || !hash_equals(DIAG_PLISIO_CONFIG_TOKEN, $tokenRecu)) {
    http_response_code(403);
    exit("forbidden");
}

header('Content-Type: text/plain; charset=utf-8');

$base = rtrim(getenv('CALLBACK_BASE_URL') ?: 'https://deblocagetango.com', '/');

echo "=== Configuration Plisio ===\n\n";

echo "IP sortante du serveur (à autoriser dans Plisio) :\n";
$ip = @file_get_contents('https://api.ipify.org');
echo "  " . ($ip ?: 'indéterminée (pas d\'accès Internet sortant)') . "\n\n";

echo "URLs de callback à déclarer dans Plisio :\n";
echo "  Status URL (webhook)  : " . (getenv('PLISIO_STATUS_URL') ?: $base . "/wallet/webhook_plisio.php") . "\n";
echo "  Success URL           : " . (getenv('PLISIO_SUCCESS_URL') ?: $base . "/success.php") . "\n";
echo "  Fail URL              : " . (getenv('PLISIO_FAIL_URL') ?: $base . "/failed.php") . "\n\n";

echo "Clé API Plisio configurée : " . (getenv('PLISIO_SECRET_KEY') ? 'oui' : 'NON (PLISIO_SECRET_KEY vide)') . "\n";
echo "White-label deposit : " . (function_exists('createPlisioWallet') ? 'fonction disponible' : 'plisio_api.php non chargé') . "\n\n";

echo "Rappel : côté Plisio, activez la « White-label payment processing »\n";
echo "et renseignez la Status URL ci-dessus pour les dépôts sur adresse permanente.\n";
