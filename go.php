<?php
/**
 * Ouverture de l'application tierce après validation du paiement / essai gratuit.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$deeplink_app = getenv('APP_DEEPLINK') ?: 'bnc://app.binance.com';
$fallback     = getenv('APP_FALLBACK_URL') ?: 'https://www.binance.com/fr';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ouverture de l'application</title>
    <link rel="stylesheet" href="admin_style.css">
</head>
<body class="app-body">
<main class="container">
    <div class="card center">
        <h2>Ouverture de l'application…</h2>
        <p class="muted">Si rien ne se passe, utilisez le bouton ci-dessous.</p>
        <a class="btn" href="<?= htmlspecialchars($deeplink_app) ?>">Ouvrir l'application</a>
        <p class="muted"><a href="<?= htmlspecialchars($fallback) ?>">Ouvrir dans le navigateur</a></p>
    </div>
</main>
<script>
    setTimeout(function () {
        window.location.href = <?= json_encode($deeplink_app) ?>;
    }, 300);
</script>
</body>
</html>
