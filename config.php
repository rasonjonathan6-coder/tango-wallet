<?php

/**
 * Configuration centrale de l'application Wallet.
 *
 * Les identifiants de connexion sont lus depuis l'environnement lorsqu'ils
 * sont présents (utile pour Docker / hébergement), avec des valeurs par défaut
 * adaptées à un environnement de développement local.
 *
 * ⚠️ En production, définissez impérativement ces variables d'environnement
 * (DB_HOST, DB_NAME, DB_USER, DB_PASS, ADMIN_PASSWORD, CRYPTO_KEY, ...) et
 * changez les secrets par défaut ci-dessous.
 */

// ---------------------------------------------------------------------
// Base de données
// ---------------------------------------------------------------------
// Pilote : "mysql" (historique) ou "pgsql" (PostgreSQL). Les deux sont gérés
// par wallet/db.php : aucune requête du code n'a besoin d'être modifiée.
$db_driver = getenv('DB_DRIVER') ?: 'mysql';
$db_host = getenv('DB_HOST') ?: '127.0.0.1';
$db_name = getenv('DB_NAME') ?: 'wallet';
$db_user = getenv('DB_USER') ?: 'wallet';
$db_pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'wallet';
$db_port = getenv('DB_PORT') ?: '';

// Une URL de connexion complète (DB_URL) est également acceptée, par exemple
// celle fournie par Supabase, Render ou Neon. Elle a la priorité et permet de
// déduire automatiquement le pilote.
$db_url = getenv('DB_URL');
if ($db_url) {
    $parts = parse_url($db_url);
    if ($parts && !empty($parts['scheme'])) {
        $scheme = strtolower($parts['scheme']);
        $db_driver = in_array($scheme, ['postgres', 'postgresql', 'pgsql'], true) ? 'pgsql' : 'mysql';
        $db_host = $parts['host'] ?? $db_host;
        $db_name = isset($parts['path']) ? ltrim($parts['path'], '/') : $db_name;
        $db_user = isset($parts['user']) ? urldecode($parts['user']) : $db_user;
        $db_pass = isset($parts['pass']) ? urldecode($parts['pass']) : $db_pass;
        $db_port = isset($parts['port']) ? (string) $parts['port'] : $db_port;
    }
}

// Port par défaut selon le pilote.
if ($db_port === '') {
    $db_port = $db_driver === 'pgsql' ? '5432' : '3306';
}

// ---------------------------------------------------------------------
// Sécurité application
// ---------------------------------------------------------------------
// Mot de passe protégeant l'espace admin (mode web).
$mot_de_passe_admin = getenv('ADMIN_PASSWORD') ?: 'admin';

// Clé utilisée par crypto.php pour chiffrer les mots de passe des comptes
// tiers stockés dans la table `demandes`.
$cle_cryptage = getenv('CRYPTO_KEY') ?: 'change-moi-cle-de-chiffrement-wallet';

// ---------------------------------------------------------------------
// Connexion Google OAuth (optionnelle : laisser vide pour la désactiver)
// ---------------------------------------------------------------------
$google_client_id     = getenv('GOOGLE_CLIENT_ID') ?: '';
$google_client_secret = getenv('GOOGLE_CLIENT_SECRET') ?: '';
$google_redirect_uri  = getenv('GOOGLE_REDIRECT_URI') ?: '';

// ---------------------------------------------------------------------
// Connexion PDO
// ---------------------------------------------------------------------
require_once __DIR__ . '/wallet/db.php';

try {
    $pdo = wallet_db_connect($db_driver, $db_host, $db_port, $db_name, $db_user, $db_pass);
} catch (PDOException $e) {
    http_response_code(500);
    exit("Connexion à la base de données impossible : " . htmlspecialchars($e->getMessage()));
}
