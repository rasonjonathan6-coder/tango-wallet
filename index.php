<?php
/**
 * Contrôleur frontal de l'application Wallet.
 *
 * Point d'entrée unique : gère l'authentification, l'inscription, l'espace
 * admin, le support et délègue les pages « lourdes » au module wallet/ fourni
 * (portefeuille, retrait Mvola, validation admin des retraits).
 */

ob_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    ini_set('session.cookie_httponly', 1);
    if ($https) {
        ini_set('session.cookie_secure', 1);
    }
    ini_set('session.cookie_samesite', 'Lax');

    if (isset($_POST['remember_me'])) {
        ini_set('session.cookie_lifetime', 60 * 60 * 24 * 30);
        ini_set('session.gc_maxlifetime', 60 * 60 * 24 * 30);
    }

    session_start();
}

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');

// Permet aux fichiers du module wallet/ de se charger entre eux par leur nom.
set_include_path(
    get_include_path()
    . PATH_SEPARATOR . __DIR__
    . PATH_SEPARATOR . __DIR__ . '/wallet'
);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/release_wallets.php';

// ================= DÉTECTION PLATEFORME =================
$ua_initial = $_SERVER['HTTP_USER_AGENT'] ?? '';
$est_webview_app = (strpos($ua_initial, 'MonAppAndroid') !== false)
    || (strpos($ua_initial, '; wv)') !== false);

$page = $_GET['page'] ?? ($est_webview_app ? 'dashboard' : 'admin');
$type = $_GET['type'] ?? '';

// ================= OUTILS =================
function setting_get(PDO $pdo, string $key, ?string $default = null): ?string
{
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return $val === false ? $default : $val;
}

function setting_set(PDO $pdo, string $key, string $value): void
{
    $pdo->prepare("
        INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ")->execute([$key, $value]);
}

function e($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

$maintenance_active = (setting_get($pdo, 'maintenance', '0') === '1');
$montant_config     = (int) (setting_get($pdo, 'montant', '25000'));
$essai_gratuit_actif = (setting_get($pdo, 'essai_gratuit_actif', '1') === '1');
$apk_version_serveur = setting_get($pdo, 'apk_version', '1.2');
$apk_url             = setting_get($pdo, 'apk_url', '');

$error = $error_login = $error_inscription = $error_reset = $error_contact = null;
$succes = $succes_admin = null;

// ================= DÉCONNEXION =================
if (isset($_GET['logout'])) {
    session_destroy();
    redirect('?page=dashboard');
}

// ================= VERROU GLOBAL ADMIN (MODE WEB) =================
if (isset($_POST['login_admin'])) {
    if (hash_equals((string) $mot_de_passe_admin, (string) ($_POST['admin_pwd'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        redirect('?page=admin');
    }
    $error = 'Mot de passe incorrect';
}

// ================= REDIRECTION UTILISATEUR CONNECTÉ =================
if ($page === 'dashboard' && isset($_SESSION['user_id']) && !isset($_GET['logout'])
    && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect('?page=compte');
}

// ================= ACTIONS ADMIN =================
if (isset($_SESSION['admin'])) {

    if (isset($_POST['toggle_maintenance'])) {
        setting_set($pdo, 'maintenance', $maintenance_active ? '0' : '1');
        redirect('?page=admin&maint_ok=1');
    }

    if (isset($_POST['update_montant'])) {
        $nouveau = (int) ($_POST['nouveau_montant'] ?? 0);
        if ($nouveau > 0) {
            setting_set($pdo, 'montant', (string) $nouveau);
            redirect('?page=admin&montant_ok=1');
        }
    }

    if (isset($_POST['update_apk'])) {
        $v = trim($_POST['apk_version'] ?? '');
        $l = trim($_POST['apk_url'] ?? '');
        if ($v === '' || !preg_match('/^[0-9]+\.[0-9]+$/', $v)) {
            $succes_admin = "❌ Format de version invalide (attendu X.Y).";
        } elseif ($l === '' || !filter_var($l, FILTER_VALIDATE_URL)) {
            $succes_admin = "❌ Lien de téléchargement invalide.";
        } else {
            setting_set($pdo, 'apk_version', $v);
            setting_set($pdo, 'apk_url', $l);
            redirect('?page=admin&apk_ok=1');
        }
    }

    if (isset($_POST['toggle_essai_gratuit'])) {
        setting_set($pdo, 'essai_gratuit_actif', $essai_gratuit_actif ? '0' : '1');
        redirect('?page=admin&eg_ok=' . ($essai_gratuit_actif ? '0' : '1'));
    }

    if (isset($_POST['delete_push_subscriptions'])) {
        $pdo->query("DELETE FROM push_subscriptions");
        redirect('?page=admin&push_deleted=1');
    }
}

// ================= INSCRIPTION =================
if (isset($_POST['inscrire'])) {
    $email = trim($_POST['email_inscription'] ?? '');
    $mdp   = trim($_POST['mdp_inscription'] ?? '');

    if (!preg_match('/^[a-zA-Z0-9._%+-]+@gmail\.com$/i', $email)) {
        $error_inscription = "❌ Veuillez utiliser une adresse Gmail (@gmail.com).";
    } else {
        $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $check->execute([$email]);

        if ($check->fetch()) {
            $error_inscription = "Email déjà utilisé.";
        } else {
            $code   = (string) rand(100000, 999999);
            $expire = date('Y-m-d H:i:s', time() + 600);

            $_SESSION['register_email']    = $email;
            $_SESSION['register_password'] = password_hash($mdp, PASSWORD_DEFAULT);
            $_SESSION['verify_code']       = $code;
            $_SESSION['code_expire']       = $expire;

            sendMail($email, "Code de vérification", "Votre code est : $code");
            redirect('verify.php');
        }
    }
}

// ================= CONNEXION =================
if (isset($_POST['ajouter'])) {
    $email = trim($_POST['identifiant'] ?? '');
    $mdp   = trim($_POST['mdp'] ?? '');

    $check = $pdo->prepare("SELECT * FROM users WHERE email = ? AND verified = 1");
    $check->execute([$email]);
    $user = $check->fetch(PDO::FETCH_ASSOC);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_login = "Email invalide.";
    } elseif (!$user) {
        $error_login = "❌ Cet e-mail n'est pas inscrit ou n'a pas été vérifié.";
    } elseif (!password_verify($mdp, $user['password'])) {
        $error_login = "❌ Mot de passe incorrect.";
    } else {
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['pending_identifiant'] = $email;
        $_SESSION['pending_mdp_crypte']  = crypter($mdp, $cle_cryptage);
        unset($_SESSION['connexion_google']);
        redirect('?page=compte');
    }
}

// ================= MOT DE PASSE OUBLIÉ =================
if (isset($_POST['send_otp'])) {
    $email = trim($_POST['email_reset'] ?? '');
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user) {
        $otp    = (string) rand(100000, 999999);
        $expire = date('Y-m-d H:i:s', time() + 600);
        $pdo->prepare("UPDATE users SET verify_code = ?, code_expire = ? WHERE email = ?")
            ->execute([$otp, $expire, $email]);
        sendMail($email, "Code de réinitialisation", "Votre code est : $otp");
        $_SESSION['reset_email'] = $email;
        redirect('?page=verify_reset');
    }
    $error_reset = "Email introuvable.";
}

if (isset($_POST['reset_password'])) {
    $otp  = trim($_POST['otp'] ?? '');
    $pass = trim($_POST['new_pass'] ?? '');
    $email = $_SESSION['reset_email'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND verify_code = ? AND code_expire > NOW()");
    $stmt->execute([$email, $otp]);
    $user = $stmt->fetch();

    if (!$user) {
        $error_reset = "Code invalide ou expiré.";
    } elseif (strlen($pass) < 6) {
        $error_reset = "Mot de passe trop court (6 caractères minimum).";
    } else {
        $pdo->prepare("UPDATE users SET password = ?, verify_code = NULL WHERE email = ?")
            ->execute([password_hash($pass, PASSWORD_DEFAULT), $email]);
        unset($_SESSION['reset_email']);
        $succes = "Mot de passe réinitialisé. Vous pouvez vous connecter.";
    }
}

// ================= CONTACT =================
if (isset($_POST['envoyer_message'])) {
    $email_saisi = trim($_POST['email_saisi'] ?? '');
    $message     = trim($_POST['message_content'] ?? '');

    if ($email_saisi === '' || $message === '') {
        $error_contact = "Tous les champs sont obligatoires.";
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email_saisi]);
        $u = $stmt->fetch();

        if (!$u) {
            $error_contact = "Cet e-mail n'est pas inscrit sur la plateforme.";
        } else {
            $pdo->prepare("INSERT INTO messages_contact (user_id, email, message) VALUES (?,?,?)")
                ->execute([$u['id'], $email_saisi, $message]);

            sendMail(
                "customerservicefor032@gmail.com",
                "Nouveau message Contact",
                "Email: $email_saisi\n\n$message"
            );
            $succes = "Message envoyé. Nous vous répondrons rapidement.";
        }
    }
}

// ================= COMMENTAIRES =================
if (isset($_POST['envoyer_commentaire']) && isset($_SESSION['user_id'])) {
    $msg = trim($_POST['commentaire_message'] ?? '');
    if ($msg !== '') {
        $pdo->prepare("INSERT INTO commentaires (user_id, email, message, visible) VALUES (?,?,?,1)")
            ->execute([$_SESSION['user_id'], $_SESSION['pending_identifiant'] ?? '', $msg]);
        $succes = "Merci pour votre avis !";
    }
}

// ================= AJAX : ÉTAT MAINTENANCE =================
if (isset($_GET['check_maintenance'])) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    echo json_encode(['maintenance' => (setting_get($pdo, 'maintenance', '0') === '1')]);
    exit;
}

// ================= PAGES LOURDES DÉLÉGUÉES AU MODULE wallet/ =================
if (in_array($page, ['portefeuille', 'retrait_mvola', 'admin_retraits_mvola'], true)) {
    if ($page === 'admin_retraits_mvola' && !isset($_SESSION['admin'])) {
        redirect('?page=dashboard');
    }
    if ($page !== 'admin_retraits_mvola' && !isset($_SESSION['user_id'])) {
        redirect('?page=dashboard');
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    require __DIR__ . '/wallet/' . $page . '.php';
    exit;
}

// ================= VERROU ADMIN (AFFICHAGE) =================
if (!$est_webview_app && !isset($_SESSION['admin'])) {
    render_lock($error);
    exit;
}

function render_lock(?string $error): void
{
    ?>
    <!DOCTYPE html>
    <html lang="fr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Accès restreint</title>
        <link rel="stylesheet" href="admin_style.css">
    </head>
    <body class="lock-body">
        <div class="lock-card">
            <h3>🔐 Accès restreint</h3>
            <form method="POST">
                <input type="password" name="admin_pwd" placeholder="Mot de passe admin" required autofocus>
                <button type="submit" name="login_admin">Connexion</button>
            </form>
            <?php if ($error): ?>
                <p class="lock-error"><?= e($error) ?></p>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
}

// ================= AFFICHAGE =================
$titles = [
    'dashboard'      => 'Accueil',
    'compte'         => 'Mon compte',
    'support'        => 'Support',
    'contact'        => 'Contact',
    'reclamation'    => 'Réclamation',
    'notifications'  => 'Notifications',
    'admin'          => 'Espace Admin',
    'inscription'    => 'Inscription',
    'forgot_password'=> 'Mot de passe oublié',
    'verify_reset'   => 'Réinitialisation',
    'paiement'       => 'Paiement',
];
$title = $titles[$page] ?? 'Wallet';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title) ?> — Wallet</title>
    <link rel="stylesheet" href="admin_style.css">
</head>
<body class="app-body">

<header class="topbar">
    <span class="brand">💼 Wallet</span>
    <nav>
        <?php if (isset($_SESSION['user_id'])): ?>
            <a href="?page=compte">Mon compte</a>
            <a href="?page=portefeuille">Portefeuille</a>
            <a href="?page=retrait_mvola">Retrait Mvola</a>
            <a href="?logout=user">Déconnexion</a>
        <?php endif; ?>
        <?php if (isset($_SESSION['admin'])): ?>
            <a href="?page=admin">Admin</a>
            <a href="?page=admin_retraits_mvola">Retraits</a>
            <a href="?page=admin&logout=1">Quitter admin</a>
        <?php endif; ?>
    </nav>
</header>

<main class="container">

<?php if ($maintenance_active && !isset($_SESSION['admin']) && !in_array($page, ['admin', 'contact'], true)): ?>
    <div class="card center">
        <div class="big-emoji">🛠️</div>
        <h2 class="warn">Site en maintenance</h2>
        <p class="muted">Nous effectuons une mise à jour. Merci de revenir dans quelques instants.</p>
    </div>
<?php else: ?>

<?php if ($succes): ?><div class="alert ok"><?= e($succes) ?></div><?php endif; ?>
<?php if ($succes_admin): ?><div class="alert ok"><?= e($succes_admin) ?></div><?php endif; ?>
<?php if ($error_login): ?><div class="alert err"><?= e($error_login) ?></div><?php endif; ?>
<?php if ($error_inscription): ?><div class="alert err"><?= e($error_inscription) ?></div><?php endif; ?>
<?php if ($error_reset): ?><div class="alert err"><?= e($error_reset) ?></div><?php endif; ?>
<?php if ($error_contact): ?><div class="alert err"><?= e($error_contact) ?></div><?php endif; ?>

<?php if ($page === 'dashboard'): ?>
    <div class="card center">
        <h2>Connexion</h2>
        <form method="POST" class="form">
            <input type="text" name="identifiant" placeholder="Email" required autocomplete="off">
            <input type="password" name="mdp" placeholder="Mot de passe" required autocomplete="current-password">
            <label class="check"><input type="checkbox" name="remember_me"> Se souvenir de moi</label>
            <button type="submit" name="ajouter" class="btn">Se connecter</button>
        </form>
        <p class="muted">
            <a href="?page=inscription">Créer un compte</a> ·
            <a href="?page=forgot_password">Mot de passe oublié ?</a>
        </p>
    </div>

<?php elseif ($page === 'inscription'): ?>
    <div class="card center">
        <h2>Inscription</h2>
        <form method="POST" class="form">
            <input type="email" name="email_inscription" placeholder="Adresse Gmail" required>
            <input type="password" name="mdp_inscription" placeholder="Mot de passe" required>
            <button type="submit" name="inscrire" class="btn">S'inscrire</button>
        </form>
        <p class="muted">Déjà inscrit ? <a href="?page=dashboard">Se connecter</a></p>
    </div>

<?php elseif ($page === 'forgot_password'): ?>
    <div class="card center">
        <h2>Mot de passe oublié</h2>
        <form method="POST" class="form">
            <input type="email" name="email_reset" placeholder="Votre email" required>
            <button type="submit" name="send_otp" class="btn">Envoyer le code</button>
        </form>
    </div>

<?php elseif ($page === 'verify_reset'): ?>
    <div class="card center">
        <h2>Réinitialisation</h2>
        <form method="POST" class="form">
            <input type="text" name="otp" placeholder="Code à 6 chiffres" required>
            <input type="password" name="new_pass" placeholder="Nouveau mot de passe" required>
            <button type="submit" name="reset_password" class="btn">Réinitialiser</button>
        </form>
    </div>

<?php elseif ($page === 'compte' && isset($_SESSION['user_id'])): ?>
    <div class="card">
        <h2>Mon compte</h2>
        <p class="muted">Connecté en tant que <b><?= e($_SESSION['pending_identifiant'] ?? '') ?></b></p>
        <div class="grid">
            <a class="tile" href="?page=portefeuille">💼 Mon portefeuille</a>
            <a class="tile" href="?page=retrait_mvola">📲 Retrait Mvola</a>
            <a class="tile" href="?page=support">🆘 Support</a>
            <a class="tile" href="?page=contact">📬 Contact</a>
        </div>
    </div>

<?php elseif ($page === 'support'): ?>
    <div class="card">
        <h2>Support</h2>
        <p class="muted">Ouvrez une discussion pour un problème de compte.</p>
        <form method="POST" class="form">
            <input type="text" name="site" placeholder="Site / application concernée" required>
            <button type="submit" name="ouvrir_discussion" class="btn">Ouvrir une discussion</button>
        </form>
    </div>

<?php elseif ($page === 'contact'): ?>
    <div class="card">
        <h2>Contactez-nous</h2>
        <form method="POST" class="form">
            <input type="email" name="email_saisi" placeholder="Votre email d'inscription" required>
            <textarea name="message_content" rows="5" placeholder="Votre message..." required></textarea>
            <button type="submit" name="envoyer_message" class="btn">Envoyer</button>
        </form>
    </div>

<?php elseif ($page === 'reclamation'): ?>
    <div class="card">
        <h2>Réclamation</h2>
        <p class="muted">Décrivez votre problème, notre équipe vous répondra.</p>
        <form method="POST" class="form">
            <textarea name="message_content" rows="5" placeholder="Votre réclamation..." required></textarea>
            <button type="submit" name="envoyer_message" class="btn">Envoyer</button>
        </form>
    </div>

<?php elseif ($page === 'notifications'): ?>
    <div class="card">
        <h2>Notifications</h2>
        <p class="muted">Activez les notifications pour suivre vos dépôts et retraits.</p>
        <button class="btn" onclick="activerNotifications()">Activer les notifications</button>
        <p class="muted" id="notifStatus"></p>
    </div>
    <script>
    function activerNotifications(){
        if(!('serviceWorker' in navigator)){ document.getElementById('notifStatus').textContent = 'Non supporté.'; return; }
        Notification.requestPermission().then(function(p){
            document.getElementById('notifStatus').textContent = 'Permission : ' + p;
        });
    }
    </script>

<?php elseif ($page === 'admin' && isset($_SESSION['admin'])): ?>
    <div class="card">
        <h2>Espace Admin</h2>
        <p class="muted">Montant configuré : <b><?= (int) $montant_config ?> Ar</b></p>

        <div class="admin-row">
            <form method="POST">
                <input type="number" name="nouveau_montant" value="<?= (int) $montant_config ?>" min="1">
                <button type="submit" name="update_montant" class="btn">Mettre à jour le montant</button>
            </form>
        </div>

        <div class="admin-row">
            <form method="POST">
                <button type="submit" name="toggle_maintenance" class="btn secondary">
                    <?= $maintenance_active ? 'Désactiver la maintenance' : 'Activer la maintenance' ?>
                </button>
            </form>
            <form method="POST">
                <button type="submit" name="toggle_essai_gratuit" class="btn secondary">
                    Essai gratuit : <?= $essai_gratuit_actif ? 'ACTIF' : 'INACTIF' ?>
                </button>
            </form>
        </div>

        <div class="admin-row">
            <form method="POST" class="inline">
                <input type="text" name="apk_version" value="<?= e($apk_version_serveur) ?>" placeholder="1.2">
                <input type="url" name="apk_url" value="<?= e($apk_url) ?>" placeholder="https://.../app.apk">
                <button type="submit" name="update_apk" class="btn">Publier l'APK</button>
            </form>
        </div>

        <div class="admin-row">
            <form method="POST">
                <button type="submit" name="delete_push_subscriptions" class="btn danger">Supprimer les abonnements push</button>
            </form>
            <a class="btn" href="?page=admin_retraits_mvola">Gérer les retraits Mvola</a>
        </div>
    </div>

<?php elseif ($page === 'success'): ?>
    <div class="card center">
        <h2>✅ Paiement confirmé</h2>
        <p>Vous pouvez continuer vers votre application.</p>
        <a class="btn" href="go.php">Continuer</a>
    </div>

<?php elseif ($page === 'failed'): ?>
    <div class="card center">
        <h2>❌ Paiement échoué</h2>
        <p>La transaction n'a pas abouti. Vous pouvez réessayer.</p>
        <a class="btn" href="?page=dashboard">Retour</a>
    </div>

<?php elseif ($page === 'paiement'): ?>
    <div class="card center">
        <h2>Paiement</h2>
        <p class="muted">Montant à payer : <b><?= (int) $montant_config ?> Ar</b></p>
        <a class="btn" href="go.php">Procéder au paiement</a>
    </div>

<?php else: ?>
    <div class="card center">
        <h2>Page introuvable</h2>
        <a class="btn" href="?page=dashboard">Retour à l'accueil</a>
    </div>
<?php endif; ?>

<?php endif; ?>

</main>

<footer class="footer muted">Wallet — application de gestion de portefeuille crypto</footer>
</body>
</html>
