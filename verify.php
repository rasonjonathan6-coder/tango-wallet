<?php
/**
 * Vérification du code reçu par e-mail lors de l'inscription.
 * Crée le compte utilisateur une fois le code validé.
 */
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config.php';

header('Content-Type: text/html; charset=utf-8');

$erreur = null;

if (isset($_POST['verifier'])) {
    $code = trim($_POST['code'] ?? '');
    $email = $_SESSION['register_email'] ?? '';
    $hash  = $_SESSION['register_password'] ?? '';

    if ($email === '' || $hash === '') {
        $erreur = "Session expirée. Veuillez recommencer l'inscription.";
    } elseif (!hash_equals((string) ($_SESSION['verify_code'] ?? ''), $code)) {
        $erreur = "Code incorrect.";
    } elseif (($_SESSION['code_expire'] ?? '') < date('Y-m-d H:i:s')) {
        $erreur = "Code expiré. Veuillez recommencer l'inscription.";
    } else {
        $pdo->prepare("INSERT INTO users (email, password, verified, created_at) VALUES (?,?,1,NOW())")
            ->execute([$email, $hash]);

        $user_id = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO user_wallets (user_id, solde, statut, created_at) VALUES (?,0,'actif',NOW())")
            ->execute([$user_id]);

        unset($_SESSION['register_email'], $_SESSION['register_password'], $_SESSION['verify_code'], $_SESSION['code_expire']);

        $_SESSION['user_id'] = $user_id;
        $_SESSION['pending_identifiant'] = $email;

        header('Location: ?page=compte');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vérification — Wallet</title>
    <link rel="stylesheet" href="admin_style.css">
</head>
<body class="app-body">
<main class="container">
    <div class="card center">
        <h2>Vérification de l'e-mail</h2>
        <p class="muted">Saisissez le code à 6 chiffres reçu par e-mail.</p>
        <?php if ($erreur): ?><div class="alert err"><?= htmlspecialchars($erreur) ?></div><?php endif; ?>
        <form method="POST" class="form">
            <input type="text" name="code" inputmode="numeric" maxlength="6" placeholder="123456" required autofocus>
            <button type="submit" name="verifier" class="btn">Vérifier</button>
        </form>
    </div>
</main>
</body>
</html>
