<?php
/**
 * Contrôleur frontal de l'application Wallet.
 *
 * Point d'entrée unique : gère l'authentification, l'inscription, l'espace
 * admin, le support et délègue les pages « lourdes » au module wallet/ fourni
 * (portefeuille, retrait Mvola, validation admin des retraits).
 */

ob_start();

// Les erreurs ne s'affichent qu'en dehors de la production (APP_ENV=production).
$app_env = getenv('APP_ENV') ?: 'dev';
if ($app_env === 'production') {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    ini_set('log_errors', 1);
} else {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
}

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

$page = $_GET['page'] ?? 'dashboard';
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

// ================= CONNEXION AVEC GOOGLE =================
// L'adresse e-mail Google (vérifiée par Google) devient l'identifiant du compte :
// elle sert à savoir à qui appartient chaque portefeuille/adresse crypto.
if ($page === 'google_callback') {
    if (!isset($_GET['code']) || $google_client_id === '' || $google_client_secret === '') {
        $error_login = "Connexion Google indisponible (configuration manquante).";
        $page = 'dashboard';
    } else {
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'code'          => $_GET['code'],
            'client_id'     => $google_client_id,
            'client_secret' => $google_client_secret,
            'redirect_uri'  => $google_redirect_uri,
            'grant_type'    => 'authorization_code',
        ]));
        $token_data = json_decode(curl_exec($ch), true);
        curl_close($ch);

        $google_user = null;
        if (!empty($token_data['access_token'])) {
            $ch = curl_init('https://www.googleapis.com/oauth2/v3/userinfo');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token_data['access_token']]);
            $google_user = json_decode(curl_exec($ch), true);
            curl_close($ch);
        }

        if (empty($google_user['email'])) {
            $error_login = "Échec de l'authentification Google. Réessayez.";
            $page = 'dashboard';
        } else {
            $google_id    = $google_user['sub'];
            $google_email = $google_user['email'];

            $stmt = $pdo->prepare("SELECT * FROM users WHERE google_id = ?");
            $stmt->execute([$google_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
                $stmt->execute([$google_email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user) {
                    $pdo->prepare("UPDATE users SET verified = 1, google_id = ? WHERE id = ?")
                        ->execute([$google_id, $user['id']]);
                } else {
                    $insert = $pdo->prepare("INSERT INTO users (email, password, verified, google_id, created_at) VALUES (?, ?, 1, ?, ?)");
                    $insert->execute([$google_email, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), $google_id, date('Y-m-d H:i:s')]);
                    $nouvel_id = (int) $pdo->lastInsertId();

                    $pdo->prepare("INSERT INTO user_wallets (user_id, solde, statut, created_at) VALUES (?,0,'actif',NOW())")
                        ->execute([$nouvel_id]);

                    // Distribution automatique des adresses de depot Plisio (BEP20).
                    if (file_exists(__DIR__ . '/wallet/wallet_functions.php')) {
                        require_once __DIR__ . '/wallet/wallet_functions.php';
                    }
                    if (function_exists('provisionnerAdressesDepot')) {
                        provisionnerAdressesDepot($pdo, $nouvel_id);
                    }

                    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                    $stmt->execute([$nouvel_id]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                }
            }

            if ($user) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int) $user['id'];
                $_SESSION['pending_identifiant'] = $user['email'];
                $_SESSION['connexion_google'] = true;
                unset($_SESSION['pending_mdp_crypte']);
                redirect('?page=compte');
            }

            $error_login = "Impossible de créer le compte Google.";
            $page = 'dashboard';
        }
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
if (in_array($page, ['portefeuille', 'retrait_mvola', 'admin_retraits_mvola', 'admin_wallets'], true)) {
    if (in_array($page, ['admin_retraits_mvola', 'admin_wallets'], true) && !isset($_SESSION['admin'])) {
        redirect('?page=dashboard');
    }
    if (!in_array($page, ['admin_retraits_mvola', 'admin_wallets'], true) && !isset($_SESSION['user_id'])) {
        redirect('?page=dashboard');
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    require __DIR__ . '/wallet/' . $page . '.php';
    exit;
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
<body class="app-body<?= isset($_SESSION['user_id']) ? ' has-tabbar' : '' ?>">

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
        <div class="hero-brand">
            <div class="hero-logo">💼</div>
            <div class="hero-title">Tango Wallet</div>
            <div class="hero-sub">Portefeuille crypto · Madagascar</div>
        </div>
        <h2>Connexion</h2>
        <form method="POST" class="form">
            <div class="field">
                <label for="identifiant">Adresse e-mail</label>
                <input id="identifiant" type="text" name="identifiant" placeholder="vous@exemple.com" required autocomplete="username">
            </div>
            <div class="field">
                <label for="mdp">Mot de passe</label>
                <input id="mdp" type="password" name="mdp" placeholder="••••••••" required autocomplete="current-password">
            </div>
            <label class="check"><input type="checkbox" name="remember_me"> Se souvenir de moi</label>
            <button type="submit" name="ajouter" class="btn block">Se connecter</button>
        </form>

        <?php if ($google_client_id !== ''): ?>
            <div class="sep">ou</div>
            <?php
                $google_auth_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
                    'client_id'     => $google_client_id,
                    'redirect_uri'  => $google_redirect_uri,
                    'response_type' => 'code',
                    'scope'         => 'openid email profile',
                    'prompt'        => 'select_account',
                ]);
            ?>
            <a class="btn-google" href="<?= e($google_auth_url) ?>">
                <svg viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9 3.6l6.7-6.7C35.6 2.6 30.2 0 24 0 14.6 0 6.5 5.4 2.6 13.2l7.8 6.1C12.3 13.3 17.6 9.5 24 9.5z"/><path fill="#4285F4" d="M46.5 24.5c0-1.6-.1-3.1-.4-4.5H24v9h12.7c-.5 3-2.2 5.5-4.7 7.2l7.3 5.7c4.3-3.9 6.8-9.7 6.8-16.4z"/><path fill="#FBBC05" d="M10.4 28.7c-.5-1.4-.8-2.9-.8-4.7s.3-3.3.8-4.7l-7.8-6.1C.9 16.6 0 20.2 0 24s.9 7.4 2.6 10.8l7.8-6.1z"/><path fill="#34A853" d="M24 48c6.2 0 11.5-2 15.3-5.6l-7.3-5.7c-2 1.4-4.7 2.3-8 2.3-6.4 0-11.7-3.8-13.6-9.1l-7.8 6.1C6.5 42.6 14.6 48 24 48z"/></svg>
                Continuer avec Google
            </a>
        <?php endif; ?>

        <p class="muted">
            <a href="?page=inscription">Créer un compte</a> ·
            <a href="?page=forgot_password">Mot de passe oublié ?</a>
        </p>
    </div>

<?php elseif ($page === 'inscription'): ?>
    <div class="card center">
        <h2>Créer un compte</h2>
        <p class="muted" style="margin:-10px 0 16px">Recevez votre adresse de dépôt crypto immédiatement.</p>
        <form method="POST" class="form">
            <div class="field">
                <label for="email_inscription">Adresse e-mail</label>
                <input id="email_inscription" type="email" name="email_inscription" placeholder="vous@exemple.com" required autocomplete="email">
            </div>
            <div class="field">
                <label for="mdp_inscription">Mot de passe</label>
                <input id="mdp_inscription" type="password" name="mdp_inscription" placeholder="••••••••" required autocomplete="new-password">
            </div>
            <button type="submit" name="inscrire" class="btn block">S'inscrire</button>
        </form>
        <p class="muted">Déjà inscrit ? <a href="?page=dashboard">Se connecter</a></p>
    </div>

<?php elseif ($page === 'forgot_password'): ?>
    <div class="card center">
        <h2>Mot de passe oublié</h2>
        <p class="muted" style="margin:-10px 0 16px">Nous vous enverrons un code de vérification.</p>
        <form method="POST" class="form">
            <div class="field">
                <label for="email_reset">Adresse e-mail</label>
                <input id="email_reset" type="email" name="email_reset" placeholder="vous@exemple.com" required autocomplete="email">
            </div>
            <button type="submit" name="send_otp" class="btn block">Envoyer le code</button>
        </form>
    </div>

<?php elseif ($page === 'verify_reset'): ?>
    <div class="card center">
        <h2>Réinitialisation</h2>
        <form method="POST" class="form">
            <div class="field">
                <label for="otp">Code de vérification</label>
                <input id="otp" type="text" name="otp" placeholder="Code à 6 chiffres" inputmode="numeric" required>
            </div>
            <div class="field">
                <label for="new_pass">Nouveau mot de passe</label>
                <input id="new_pass" type="password" name="new_pass" placeholder="••••••••" required autocomplete="new-password">
            </div>
            <button type="submit" name="reset_password" class="btn block">Réinitialiser</button>
        </form>
    </div>

<?php elseif ($page === 'compte' && isset($_SESSION['user_id'])): ?>
    <div class="card">
        <div class="hero-brand" style="margin-bottom:14px">
            <?php
                $emailCompte = (string) ($_SESSION['pending_identifiant'] ?? '');
                $initiale = strtoupper(substr($emailCompte, 0, 1) ?: 'U');
            ?>
            <div class="hero-logo"><?= e($initiale) ?></div>
            <div class="hero-title">Bonjour</div>
            <div class="hero-sub"><?= e($emailCompte) ?></div>
        </div>
        <div class="grid">
            <a class="tile" href="?page=portefeuille">💼<br>Mon portefeuille</a>
            <a class="tile" href="?page=retrait_mvola">📲<br>Retrait Mvola</a>
            <a class="tile" href="?page=support">🆘<br>Support</a>
            <a class="tile" href="?page=contact">📬<br>Contact</a>
        </div>
    </div>

<?php elseif ($page === 'support'): ?>
    <div class="card">
        <h2>Support</h2>
        <p class="muted">Ouvrez une discussion pour un problème de compte.</p>
        <form method="POST" class="form">
            <div class="field">
                <label for="site">Site / application concernée</label>
                <input id="site" type="text" name="site" placeholder="Ex. Tango Wallet" required>
            </div>
            <button type="submit" name="ouvrir_discussion" class="btn block">Ouvrir une discussion</button>
        </form>
    </div>

<?php elseif ($page === 'contact'): ?>
    <div class="card">
        <h2>Contactez-nous</h2>
        <p class="muted" style="margin:-10px 0 16px">Notre équipe vous répond dans les plus brefs délais.</p>
        <form method="POST" class="form">
            <div class="field">
                <label for="email_saisi">Votre e-mail d'inscription</label>
                <input id="email_saisi" type="email" name="email_saisi" placeholder="vous@exemple.com" required autocomplete="email">
            </div>
            <div class="field">
                <label for="message_content_contact">Votre message</label>
                <textarea id="message_content_contact" name="message_content" rows="5" placeholder="Décrivez votre demande…" required></textarea>
            </div>
            <button type="submit" name="envoyer_message" class="btn block">Envoyer</button>
        </form>
    </div>

<?php elseif ($page === 'reclamation'): ?>
    <div class="card">
        <h2>Réclamation</h2>
        <p class="muted">Décrivez votre problème, notre équipe vous répondra.</p>
        <form method="POST" class="form">
            <div class="field">
                <label for="message_content_reclamation">Votre réclamation</label>
                <textarea id="message_content_reclamation" name="message_content" rows="5" placeholder="Décrivez votre réclamation…" required></textarea>
            </div>
            <button type="submit" name="envoyer_message" class="btn block">Envoyer</button>
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

<?php elseif ($page === 'admin' && !isset($_SESSION['admin'])): ?>
    <div class="card center">
        <h2>🔐 Espace admin</h2>
        <form method="POST" class="form">
            <input type="password" name="admin_pwd" placeholder="Mot de passe admin" required autofocus>
            <button type="submit" name="login_admin" class="btn">Connexion</button>
        </form>
        <?php if ($error): ?><div class="alert err"><?= e($error) ?></div><?php endif; ?>
    </div>

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
            <a class="btn" href="?page=admin_wallets">Gérer les adresses de dépôt</a>
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

<?php if (isset($_SESSION['user_id'])): ?>
<nav class="tabbar" aria-label="Navigation principale">
    <a href="?page=compte" class="<?= $page === 'compte' ? 'active' : '' ?>"><span class="ic">🏠</span>Compte</a>
    <a href="?page=portefeuille" class="<?= $page === 'portefeuille' ? 'active' : '' ?>"><span class="ic">💼</span>Portefeuille</a>
    <a href="?page=retrait_mvola" class="<?= $page === 'retrait_mvola' ? 'active' : '' ?>"><span class="ic">📲</span>Retrait</a>
    <a href="?page=support" class="<?= $page === 'support' ? 'active' : '' ?>"><span class="ic">🆘</span>Support</a>
</nav>
<?php endif; ?>

<footer class="footer muted">Wallet — application de gestion de portefeuille crypto</footer>
</body>
</html>
