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
$db_host = getenv('DB_HOST') ?: '127.0.0.1';
$db_name = getenv('DB_NAME') ?: 'wallet';
$db_user = getenv('DB_USER') ?: 'wallet';
$db_pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'wallet';
$db_port = getenv('DB_PORT') ?: '3306';

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
try {
    $pdo = new PDO(
        "mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    exit("Connexion à la base de données impossible : " . htmlspecialchars($e->getMessage()));
}
