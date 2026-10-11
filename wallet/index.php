<?php
    ob_start();
    header('Content-Type: text/html; charset=utf-8');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 🔒 Configuration stricte de la sécurité des cookies de session
ini_set('session.cookie_httponly', 1);  // Empêche le JavaScript de lire le cookie (Bloque les failles XSS)
ini_set('session.cookie_secure', 1);    // Force le cookie à ne passer QUE par une connexion HTTPS chiffrée
ini_set('session.cookie_samesite', 'Lax'); // 'Strict' casse la session après la redirection OAuth Google (chaîne cross-site)

// 🕐 "Se souvenir de moi" : si la case est cochée au moment de la connexion,
// le cookie de session dure 30 jours au lieu d'expirer à la fermeture du navigateur.
if(isset($_POST['remember_me'])){
    ini_set('session.cookie_lifetime', 60 * 60 * 24 * 30);
    ini_set('session.gc_maxlifetime', 60 * 60 * 24 * 30);
}

session_start();

// 👁️ ANCRE DE DISCRÉTION : Dit aux moteurs de recherche de ne JAMAIS indexer ou copier ce site
header("X-Robots-Tag: noindex, nofollow, noarchive", true);

// 🛡️ En-têtes HTTP pour forcer la WebView d'Android à se protéger
header("X-Frame-Options: DENY");              // Anti-Clickjacking (empêche d'imbriquer votre site ailleurs)
header("X-XSS-Protection: 1; mode=block");    // Force le blocage en cas de tentative d'injection de script
header("X-Content-Type-Options: nosniff");    // Empêche l'exécution de faux fichiers mime suspects
// 🧭 Page d'accueil par défaut selon la plateforme :
// - Dans l'appli Android (WebView) : le Dashboard reste l'interface principale.
// - Sur un navigateur web classique : l'Espace Admin devient l'interface principale.
// Détection basée sur le User-Agent envoyé par le WebView (même marqueur "MonAppAndroid"
// utilisé plus bas pour la mise à jour APK), avec un repli sur le marqueur générique
// "; wv)" que l'OS Android ajoute lui-même aux WebView (variable selon la version).
$ua_initial = $_SERVER['HTTP_USER_AGENT'] ?? '';
$est_webview_app = (strpos($ua_initial, 'MonAppAndroid') !== false)
    || (strpos($ua_initial, '; wv)') !== false);

$page_par_defaut = $est_webview_app ? 'dashboard' : 'admin';

$page = $_GET['page'] ?? $page_par_defaut;
$type = $_GET['type'] ?? '';

// 🔐 Un utilisateur déjà connecté ne doit plus voir l'écran de connexion (Accueil) :
// on le redirige automatiquement vers "Mon compte" (on exclut les requêtes POST
// pour ne jamais interrompre le traitement du formulaire de connexion, et on
// exclut ?logout=... pour laisser la déconnexion s'exécuter avant toute redirection).
if($page === 'dashboard' && isset($_SESSION['user_id']) && !isset($_GET['logout']) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST'){
    header("Location: ?page=compte");
    exit;
}

// ================= CONFIG & DB =================

require_once 'config.php';
require_once 'mail.php';
require_once "crypto.php";
require_once "release_wallets.php";

// Traitement de l'oubli de mot de passe
if(isset($_POST['send_otp'])){
    $email = trim($_POST['email_reset']);
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if($user){
        $otp = rand(100000, 999999);
        $expire = date("Y-m-d H:i:s", time() + 600); // 10 min
        
        $update = $pdo->prepare("UPDATE users SET verify_code = ?, code_expire = ? WHERE email = ?");
        $update->execute([$otp, $expire, $email]);
        
        sendMail($email, "Code de réinitialisation", "Votre code est : $otp");
        $_SESSION['reset_email'] = $email;
        header("Location: ?page=verify_reset");
        exit;
    } else {
        $error_reset = "Email introuvable.";
    }
}

// Le reste de votre code PHP et l'affichage de vos pages continuent ici...

   $pdo->query("
CREATE TABLE IF NOT EXISTS demandes(

id INT AUTO_INCREMENT PRIMARY KEY,

user_id INT,

site VARCHAR(255),

identifiant VARCHAR(255),

mot_de_passe_crypte TEXT,

payment_id VARCHAR(100),

statut VARCHAR(50) DEFAULT 'en_attente',

expiration_time DATETIME,

montant DECIMAL(10,2),
montant_usdt DECIMAL(10,2),
created_at DATETIME,

wallet_address VARCHAR(255),

txid VARCHAR(255)

)
");

$pdo->query("
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) UNIQUE,
    password TEXT,
    verify_code VARCHAR(255),
    code_expire DATETIME,
    verified TINYINT(1) DEFAULT 0,
    attempts INT DEFAULT 0,
    created_at DATETIME
)
");

// 🆕 Colonne mémorisant si le bouton flottant "Essai gratuit" a déjà été affiché
// à l'utilisateur (pour ne le montrer qu'une seule fois). On l'ajoute en toute
// sécurité : si elle existe déjà, l'erreur est simplement ignorée.
try {
    $pdo->query("ALTER TABLE users ADD COLUMN essai_gratuit_vu TINYINT(1) DEFAULT 0");
} catch (Exception $e) {
    // La colonne existe déjà, rien à faire.
}

// 🎁 Colonne mémorisant qu'un essai gratuit a été offert manuellement par l'admin
// à cet utilisateur (bypass la règle "aucune demande" pour les comptes déjà actifs).
try {
    $pdo->query("ALTER TABLE users ADD COLUMN essai_gratuit_offert TINYINT(1) DEFAULT 0");
} catch (Exception $e) {
    // La colonne existe déjà, rien à faire.
}

try {
    $pdo->query("ALTER TABLE users ADD COLUMN google_id VARCHAR(255)");
} catch (Exception $e) {
    // La colonne existe déjà, rien à faire.
}

$pdo->query("
CREATE TABLE IF NOT EXISTS commentaires (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    email VARCHAR(255),
    message TEXT,
    visible TINYINT(1) DEFAULT 1,
    created_at DATETIME
)
");

// --- TRAITEMENT ANNULATION / LIBÉRATION DEMANDE (SI AJAX) ---
if(isset($_POST['action']) && $_POST['action'] === 'release_wallet' && isset($_POST['payment_id'])){
    $payment_id = $_POST['payment_id'];
    releaseWallet($pdo, $payment_id);

    $stmt = $pdo->prepare("DELETE FROM demandes WHERE payment_id = ?");
    $stmt->execute([$payment_id]);

    header('Content-Type: application/json');
    echo json_encode(["success" => true]);
    exit;
}

if(isset($_POST['login_admin'])){
    if($_POST['admin_pwd'] === $mot_de_passe_admin){
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
    } else {
        $error = "Mot de passe incorrect";
    }
}

// 🔒 VERROU GLOBAL — MODE WEB UNIQUEMENT (n'affecte jamais la WebView Android) :
// tant que le mot de passe admin n'a pas été saisi, AUCUNE page du site n'est
// accessible depuis un navigateur classique (peu importe le paramètre ?page=...).
// La WebView de l'appli (détectée via $est_webview_app) continue de fonctionner
// exactement comme avant (dashboard, compte, etc. sans mot de passe admin).
if(!$est_webview_app && !isset($_SESSION['admin'])){
    ?>
    <!DOCTYPE html>
    <html lang="fr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Accès restreint</title>
        <style>
            body{
                margin:0;
                min-height:100vh;
                display:flex;
                align-items:center;
                justify-content:center;
                background:#05050f;
                font-family:Arial, sans-serif;
                padding:20px;
                box-sizing:border-box;
            }
            .lock-card{
                background:#0f0f22;
                border:1px solid rgba(255,255,255,.12);
                border-radius:18px;
                padding:35px 28px;
                width:100%;
                max-width:360px;
                text-align:center;
                box-shadow:0 15px 40px rgba(0,0,0,.6);
            }
            .lock-card h3{
                color:#fff;
                margin:0 0 20px;
            }
            .lock-card input[type="password"]{
                width:100%;
                padding:12px 14px;
                border-radius:10px;
                border:1px solid rgba(255,255,255,.15);
                background:#181830;
                color:#fff;
                box-sizing:border-box;
                margin-bottom:15px;
                font-size:14px;
            }
            .lock-card button{
                width:100%;
                padding:12px;
                border:none;
                border-radius:10px;
                background:#00c96b;
                color:#fff;
                font-weight:bold;
                font-size:14px;
                cursor:pointer;
            }
            .lock-error{
                color:#ff6b6b;
                font-size:13px;
                margin-top:14px;
            }
        </style>
    </head>
    <body>
        <div class="lock-card">
            <h3>🔐 Accès restreint</h3>
            <form method="POST">
                <input type="password" name="admin_pwd" placeholder="Mot de passe admin" required autofocus>
                <button type="submit" name="login_admin">Connexion</button>
            </form>
            <?php if(isset($error)): ?>
                <p class="lock-error"><?= htmlspecialchars($error) ?></p>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ⚙️ Table des paramètres (utilisée pour le mode maintenance)
$pdo->query("
CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value VARCHAR(255)
)
");

$stmt = $pdo->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('maintenance', '0')");
$stmt->execute();

// Valeur par défaut si jamais absente
$stmt = $pdo->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('montant', '25000')");
$stmt->execute();

// 📱 Réglages de la mise à jour APK (version courante + lien de téléchargement)
$stmt = $pdo->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('apk_version', '1.2')");
$stmt->execute();
$stmt = $pdo->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('apk_url', '')");
$stmt->execute();

// Valeur par défaut pour le bouton flottant "Essai gratuit" (actif par défaut)
$stmt = $pdo->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('essai_gratuit_actif', '1')");
$stmt->execute();

// 🎁 Activation / désactivation du bouton "Essai gratuit" par l'admin
if(isset($_POST['toggle_essai_gratuit']) && isset($_SESSION['admin'])){

    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'essai_gratuit_actif'");
    $stmt->execute();
    $etat_actuel_eg = $stmt->fetchColumn();

    $nouvel_etat_eg = ($etat_actuel_eg == '1') ? '0' : '1';

    $stmt = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'essai_gratuit_actif'");
    $stmt->execute([$nouvel_etat_eg]);

    if(isset($_POST['ajax'])){
        header("Content-Type: application/json");
        echo json_encode([
            "success" => true,
            "essai_gratuit_actif" => ($nouvel_etat_eg === '1')
        ]);
        exit;
    }

    header("Location: ?page=admin&eg_ok=" . $nouvel_etat_eg);
    exit;
}

// Lecture de l'état du bouton "Essai gratuit" (utilisé dans l'espace "Mon compte")
$stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'essai_gratuit_actif'");
$stmt->execute();
$essai_gratuit_actif = ($stmt->fetchColumn() == '1');

// 🔕 Suppression de tous les abonnements aux notifications push par l'admin
// (Schéma identique à celui créé par subscribe.php, pour garantir que la table
// existe même si aucun abonnement n'a encore été enregistré.)
$pdo->query("
CREATE TABLE IF NOT EXISTS push_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    endpoint_hash CHAR(64) UNIQUE,
    endpoint TEXT,
    public_key TEXT,
    auth_token TEXT,
    created_at DATETIME,
    updated_at DATETIME
)
");

if(isset($_POST['delete_push_subscriptions']) && isset($_SESSION['admin'])){

    $stmt = $pdo->query("SELECT COUNT(*) FROM push_subscriptions");
    $nb_supprimees = (int) $stmt->fetchColumn();

    $pdo->query("DELETE FROM push_subscriptions");

    if(isset($_POST['ajax'])){
        header("Content-Type: application/json");
        echo json_encode([
            "success" => true,
            "nb_supprimees" => $nb_supprimees
        ]);
        exit;
    }

    header("Location: ?page=admin&push_deleted=" . $nb_supprimees);
    exit;
}

// 🎁 Offrir manuellement un essai gratuit à un utilisateur (par email), depuis l'admin
if(isset($_POST['offrir_essai_gratuit']) && isset($_SESSION['admin'])){

    $email_offre = trim($_POST['email_essai_gratuit'] ?? '');

    $reponse = ["success" => false, "message" => "Email invalide."];

    if($email_offre !== '' && filter_var($email_offre, FILTER_VALIDATE_EMAIL)){

        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email_offre]);
        $user_cible = $stmt->fetch(PDO::FETCH_ASSOC);

        if($user_cible){
            $pdo->prepare("UPDATE users SET essai_gratuit_vu = 0, essai_gratuit_offert = 1 WHERE id = ?")
                ->execute([$user_cible['id']]);

            $reponse = [
                "success" => true,
                "message" => "Essai gratuit offert à " . $email_offre . "."
            ];
        } else {
            $reponse = ["success" => false, "message" => "Aucun utilisateur trouvé avec cet email."];
        }
    }

    if(isset($_POST['ajax'])){
        header("Content-Type: application/json");
        echo json_encode($reponse);
        exit;
    }

    header("Location: ?page=admin");
    exit;
}

// ⚙️ Modification du montant par l'admin (manuel, automatique et USDT utilisent tous cette même valeur)
if(isset($_POST['update_montant']) && isset($_SESSION['admin'])){

    $nouveau_montant = (int) preg_replace('/[^0-9]/', '', $_POST['nouveau_montant']);

    if($nouveau_montant > 0){
        $stmt = $pdo->prepare("
            UPDATE settings
            SET setting_value = ?
            WHERE setting_key = 'montant'
        ");
        $stmt->execute([$nouveau_montant]);
        $succes_montant = "✅ Montant mis à jour avec succès : " . number_format($nouveau_montant,0,',',' ') . " Ar";
    } else {
        $error_montant = "❌ Montant invalide.";
    }
}

// Lecture du montant courant (utilisé partout dans le fichier : manuel / automatique / USDT)
$stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'montant'");
$stmt->execute();
$montant_config = (int) ($stmt->fetchColumn() ?: 25000);

// 📱 Publication d'une nouvelle version APK par l'admin
if(isset($_POST['update_apk']) && isset($_SESSION['admin'])){

    $nouvelle_version = trim($_POST['apk_version']);
    $nouveau_lien = trim($_POST['apk_url']);

    if($nouvelle_version === '' || !preg_match('/^[0-9]+\.[0-9]+$/', $nouvelle_version)){
        $error_apk = "❌ Format de version invalide. Utilisez le format X.Y (ex: 1.2).";
    } elseif($nouveau_lien === '' || !filter_var($nouveau_lien, FILTER_VALIDATE_URL)){
        $error_apk = "❌ Lien de téléchargement invalide.";
    } else {
        $pdo->prepare("UPDATE settings SET setting_value=? WHERE setting_key='apk_version'")
            ->execute([$nouvelle_version]);
        $pdo->prepare("UPDATE settings SET setting_value=? WHERE setting_key='apk_url'")
            ->execute([$nouveau_lien]);
        $succes_apk = "✅ Version APK $nouvelle_version publiée.";
    }
}

// Lecture de la version APK publiée et du lien de téléchargement
$stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'apk_version'");
$stmt->execute();
$apk_version_serveur = $stmt->fetchColumn() ?: '1.2';

$stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'apk_url'");
$stmt->execute();
$apk_url = $stmt->fetchColumn() ?: '';

// Détection de la version installée sur le téléphone via le User-Agent envoyé par le WebView
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$apk_version_client = null;
if(preg_match('/MonAppAndroid\/([0-9]+\.[0-9]+)/', $ua, $m)){
    // App récente (>=1.2) : elle envoie sa vraie version
    $apk_version_client = $m[1];
} elseif(isset($_SESSION['user_id'])){
    // Android a retiré le marqueur "; wv" des WebView récentes (depuis Chrome ~90),
    // donc une vieille version de l'app est indiscernable d'un navigateur classique
    // au niveau du User-Agent. On se base plutôt sur la session utilisateur :
    // un client connecté sans tag de version = ancienne app, considérée obsolète.
    $apk_version_client = '0.0';
}

// On affiche le bouton seulement si on est dans l'appli ET qu'une version plus récente existe
$afficher_maj = $apk_version_client !== null
    && $apk_url !== ''
    && version_compare($apk_version_client, $apk_version_serveur, '<');

// 🔒 Bloque la sidebar (bouton menu + menu latéral) sur la page Espace Admin
// (uniquement en mode web, pas l'appli), sur le Portefeuille, et sur
// "Mon compte" (qui a son propre en-tête "Bonjour, ..." sans menu latéral).
$bloquer_sidebar = ($page === 'admin' && !$est_webview_app && !isset($_SESSION['admin']))
    || $page === 'portefeuille'
    || $page === 'compte';

// 🚧 Activation / désactivation du mode maintenance par l'admin
if(isset($_POST['toggle_maintenance']) && isset($_SESSION['admin'])){

    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'maintenance'");
    $stmt->execute();
    $etat_actuel = $stmt->fetchColumn();

    $nouvel_etat = ($etat_actuel == '1') ? '0' : '1';

    $stmt = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'maintenance'");
    $stmt->execute([$nouvel_etat]);

    if(isset($_POST['ajax'])){
        header("Content-Type: application/json");
        echo json_encode([
            "success" => true,
            "maintenance_active" => ($nouvel_etat === '1')
        ]);
        exit;
    }

    header("Location: ?page=admin&maint_ok=" . $nouvel_etat);
    exit;
}

// Lecture de l'état de maintenance
$stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'maintenance'");
$stmt->execute();
$maintenance_active = ($stmt->fetchColumn() == '1');

// 📡 Petit point de contrôle public : permet aux pages déjà ouvertes de détecter
// un changement de mode maintenance sans recharger manuellement.
if(isset($_GET['check_maintenance'])){
    header("Content-Type: application/json");
    echo json_encode(["maintenance" => $maintenance_active]);
    exit;
}


if(isset($_GET['logout'])){
    if($_GET['logout'] === 'user'){
        unset($_SESSION['user_id']);
        unset($_SESSION['pending_site']);
        unset($_SESSION['pending_identifiant']);
        unset($_SESSION['pending_mdp_crypte']);
        unset($_SESSION['connexion_google']);
    } else {
        unset($_SESSION['admin']);
    }
    header("Location: ?page=dashboard");
    exit;
}
if(isset($_GET['delete']) && isset($_SESSION['admin'])) {
    $pdo->prepare("DELETE FROM demandes WHERE id=?")->execute([$_GET['delete']]);
    header("Location: ?page=admin");
    exit;
}
if(isset($_GET['delete_user']) && isset($_SESSION['admin'])){

    $email = $_GET['delete_user'];

    try {

        $pdo->beginTransaction();

        // Supprime toutes les demandes de cet utilisateur
        $stmt = $pdo->prepare("
            DELETE FROM demandes 
            WHERE identifiant = ?
        ");
        $stmt->execute([$email]);


        // Supprime le compte utilisateur
        $stmt = $pdo->prepare("
            DELETE FROM users 
            WHERE email = ?
        ");
        $stmt->execute([$email]);


        $pdo->commit();

    } catch(Exception $e){

        $pdo->rollBack();
        die("Erreur suppression : ".$e->getMessage());

    }


    header("Location:?page=admin");
    exit;
}
$sent = false;
// ================= INSCRIPTION =================
if(isset($_POST['ajouter'])){

    $email = trim($_POST['identifiant']);
    $mdp   = trim($_POST['mdp']);

    // Vérifier que l'email existe et que le compte est vérifié
    $check = $pdo->prepare("SELECT * FROM users WHERE email = ? AND verified = 1");
    $check->execute([$email]);
    $user = $check->fetch(PDO::FETCH_ASSOC);

  if(!filter_var($email, FILTER_VALIDATE_EMAIL)){

    $error_email = "Email invalide";

}
elseif(!$user){

    $error_login = "❌ Cet e-mail n'est pas inscrit ou n'a pas été vérifié. Veuillez d'abord créer un compte.";

}
elseif(!password_verify($mdp, $user['password'])){

    $error_login = "❌ Mot de passe incorrect.";

}
else{

    // Facultatif mais recommandé
    $_SESSION['user_id'] = $user['id'];

    // On mémorise les identifiants pour l'espace "Mon compte".
    // Le problème du compte est choisi ensuite, dans "Ouvrir une discussion support".
    $_SESSION['pending_identifiant'] = $email;
    $_SESSION['pending_mdp_crypte'] = crypter($mdp, $cle_cryptage);
    unset($_SESSION['connexion_google']); // connexion classique : on n'est pas dans le cas Google

    header("Location: ?page=compte");
    exit;

}

}

// ================= CONNEXION AVEC GOOGLE (retour d'authentification) =================
if($page === 'google_callback'){

    if(isset($_GET['error']) || !isset($_GET['code'])){
        echo "<pre>DEBUG ETAPE 0 (avant même l'échange avec Google) :\n";
        echo "GET reçus : " . htmlspecialchars(print_r($_GET, true)) . "\n";
        echo "</pre>";
        exit;
    }

    $ch = curl_init("https://oauth2.googleapis.com/token");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array(
        "code" => $_GET['code'],
        "client_id" => $google_client_id,
        "client_secret" => $google_client_secret,
        "redirect_uri" => $google_redirect_uri,
        "grant_type" => "authorization_code"
    )));
    $token_response = curl_exec($ch);
    $curl_err_1 = curl_error($ch);
    curl_close($ch);
    $token_data = json_decode($token_response, true);

    if(!isset($token_data['access_token'])){
        echo "<pre>DEBUG ETAPE 1 (echange du code contre un token) :\n";
        echo "Erreur cURL : " . htmlspecialchars($curl_err_1) . "\n";
        echo "Réponse brute de Google : " . htmlspecialchars($token_response) . "\n";
        echo "</pre>";
        exit;
    }

    $ch = curl_init("https://www.googleapis.com/oauth2/v3/userinfo");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array("Authorization: Bearer " . $token_data['access_token']));
    $userinfo_response = curl_exec($ch);
    $curl_err_2 = curl_error($ch);
    curl_close($ch);
    $google_user = json_decode($userinfo_response, true);

    if(!isset($google_user['email'])){
        echo "<pre>DEBUG ETAPE 2 (récupération des infos du compte Google) :\n";
        echo "Erreur cURL : " . htmlspecialchars($curl_err_2) . "\n";
        echo "Réponse brute de Google : " . htmlspecialchars($userinfo_response) . "\n";
        echo "</pre>";
        exit;
    }

    $google_id = $google_user['sub'];
    $google_email = $google_user['email'];

    $stmt = $pdo->prepare("SELECT * FROM users WHERE google_id = ?");
    $stmt->execute(array($google_id));
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if(!$user){
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute(array($google_email));
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if($user){
            $update = $pdo->prepare("UPDATE users SET verified = 1 WHERE id = ?");
            $update->execute(array($user['id']));
            try {
                $pdo->prepare("UPDATE users SET google_id = ? WHERE id = ?")->execute(array($google_id, $user['id']));
            } catch (Exception $e) {}
        } else {
            $random_password = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
            $insert = $pdo->prepare("INSERT INTO users (email, password, verified, created_at) VALUES (?, ?, 1, ?)");
            $insert->execute(array($google_email, $random_password, date("Y-m-d H:i:s")));
            $user_id = $pdo->lastInsertId();
            try {
                $pdo->prepare("UPDATE users SET google_id = ? WHERE id = ?")->execute(array($google_id, $user_id));
            } catch (Exception $e) {}
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute(array($user_id));
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }

    if(!$user){
        echo "<pre>DEBUG ETAPE 3 : impossible de créer/retrouver l'utilisateur en base.</pre>";
        exit;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['pending_identifiant'] = $user['email'];
    $_SESSION['connexion_google'] = true; // connexion via Google : pas de mot de passe local à demander

    header("Location: ?page=compte");
    exit;
}

// ================= ESPACE COMPTE : OUVERTURE D'UNE DEMANDE DE SUPPORT =================
if(isset($_POST['ouvrir_discussion']) && isset($_SESSION['user_id'])){

    $site = trim($_POST['site'] ?? '');
    $email = $_SESSION['pending_identifiant'] ?? '';
    $mdp_crypte = $_SESSION['pending_mdp_crypte'] ?? '';

    // Si connexion via Google, on ne demande pas pending_mdp_crypte (aucun mot de passe
    // local n'a été saisi dans ce cas).
    $via_google = !empty($_SESSION['connexion_google']);

    if($site === '' || $email === '' || (!$via_google && $mdp_crypte === '')){
        header("Location: ?page=support&erreur_ouvrir=1");
        exit;
    }

    // 🎁 Essai gratuit : si l'option est activée par l'admin et que l'utilisateur
    // ne l'a jamais utilisée, on saute entièrement le paiement et on l'envoie
    // directement vers l'application. Éligible si c'est un nouveau compte
    // (aucune demande passée) OU si l'admin lui a offert un essai manuellement.
    $stmt_eg_vu = $pdo->prepare("SELECT essai_gratuit_vu, essai_gratuit_offert FROM users WHERE id = ?");
    $stmt_eg_vu->execute([$_SESSION['user_id']]);
    $eg_user_row = $stmt_eg_vu->fetch(PDO::FETCH_ASSOC);
    $eg_deja_utilise = (bool) $eg_user_row['essai_gratuit_vu'];
    $eg_offert_admin = (bool) $eg_user_row['essai_gratuit_offert'];

    $stmt_eg_demandes = $pdo->prepare("SELECT COUNT(*) FROM demandes WHERE user_id = ?");
    $stmt_eg_demandes->execute([$_SESSION['user_id']]);
    $eg_aucune_demande = ((int) $stmt_eg_demandes->fetchColumn() === 0);

    if($essai_gratuit_actif && !$eg_deja_utilise && ($eg_aucune_demande || $eg_offert_admin)){

        $pdo->prepare("UPDATE users SET essai_gratuit_vu = 1, essai_gratuit_offert = 0 WHERE id = ?")
            ->execute([$_SESSION['user_id']]);

        header("Location: go.php");
        exit;
    }

    // Vérifier si l'utilisateur possède déjà un paiement en attente
    $stmt = $pdo->prepare("
    SELECT payment_id
    FROM demandes
    WHERE user_id=?
    AND statut='en_attente'
    ");

    $stmt->execute([$_SESSION['user_id']]);

    while($old = $stmt->fetch(PDO::FETCH_ASSOC)){

        $pdo->prepare("
            UPDATE wallets
            SET
                status='free',
                reserved_payment_id=NULL,
                reserved_at=NULL
            WHERE reserved_payment_id=?
        ")->execute([$old["payment_id"]]);

    }

    $pdo->prepare("
    UPDATE demandes
    SET statut='expire'
    WHERE user_id=?
    AND statut='en_attente'
    ")->execute([$_SESSION['user_id']]);

    $payment_id = bin2hex(random_bytes(16));

    $montant = $montant_config; // Ariary MVola (modifiable depuis l'espace admin)

    $taux_usdt = 4000; // exemple : 1 USDT = 4000 Ar

    $montant_usdt = round($montant / $taux_usdt, 2);

    $expiration = date('Y-m-d H:i:s', time() + 600);

    $created_at = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare("
        SELECT * FROM wallets
        WHERE status='free'
        ORDER BY last_used_at ASC
        LIMIT 1
    ");
    $stmt->execute();
    $wallet = $stmt->fetch(PDO::FETCH_ASSOC);

    if(!$wallet){
        die("Aucun wallet disponible");
    }

    $wallet_address = $wallet['address'];

    // on réserve le wallet immédiatement
    try {

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            UPDATE wallets
            SET status='reserved',
                reserved_payment_id=?,
                reserved_at=NOW(),
                last_used_at=NOW()
            WHERE id=? AND status='free'
        ");

        $stmt->execute([
            $payment_id,
            $wallet['id']
        ]);

        if ($stmt->rowCount() == 0) {
            throw new Exception("Wallet déjà réservé");
        }


        $stmt = $pdo->prepare("
        INSERT INTO demandes(
            user_id,
            site,
            identifiant,
            mot_de_passe_crypte,
            payment_id,
            expiration_time,
            montant,
            montant_usdt,
            created_at,
            wallet_address
        ) VALUES (?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $_SESSION['user_id'],
            $site,
            htmlspecialchars($email),
            $mdp_crypte,
            $payment_id,
            $expiration,
            $montant,
            $montant_usdt,
            $created_at,
            $wallet_address
        ]);

        $pdo->commit();

    } catch (Exception $e) {
        $pdo->rollBack();
        die("Erreur paiement : " . $e->getMessage());
    }

    // Remarque : on garde volontairement pending_mdp_crypte en session (il est
    // nécessaire à chaque nouvelle "Ouvrir une discussion", par ex. après une
    // annulation de paiement). Le supprimer ici cassait le bouton "Confirmer"
    // dès la 2e tentative (redirection silencieuse vers Mon compte).

    header("Location: ?page=paiement&type=".urlencode($site)."&pid=".$payment_id);
    exit;

}
// ================= INSCRIPTION =================
if(isset($_POST['inscrire'])){

    $email = trim($_POST['email_inscription']);
    $mdp   = trim($_POST['mdp_inscription']);

    // Vérifie que c'est une adresse Gmail
    if(!preg_match('/^[a-zA-Z0-9._%+-]+@gmail\.com$/i', $email)){

        $error_inscription = "❌ Veuillez utiliser une adresse Gmail (@gmail.com).";

    } else {

        $check = $pdo->prepare("SELECT * FROM users WHERE email=?");
        $check->execute([$email]);

        if($check->rowCount() > 0){

            $error_inscription = "Email déjà utilisé";

        } else {

            $code = rand(100000, 999999);
            $expire = date("Y-m-d H:i:s", time() + 600);

            $_SESSION['register_email'] = $email;
$_SESSION['register_password'] = password_hash($mdp, PASSWORD_DEFAULT);
$_SESSION['verify_code'] = $code;
$_SESSION['code_expire'] = $expire;

sendMail($email, "Code de vérification", "Votre code est : $code");

header("Location: verify.php");
exit;
        }
    }
}

// ================= CONTACT =================

if(isset($_POST['envoyer_message'])){

    $email_saisi = trim($_POST['email_saisi']);
    $message = trim($_POST['message_content']);

    if(empty($email_saisi) || empty($message)){

        $erreur_contact = "Tous les champs sont obligatoires.";

    }else{

        $stmt = $pdo->prepare("SELECT id FROM users WHERE email=?");
        $stmt->execute([$email_saisi]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if(!$user){

            $erreur_contact = "Cet e-mail n'est pas inscrit sur la plateforme.";

        }else{
$stmt = $pdo->prepare("
INSERT INTO messages_contact(user_id,email,message) 
VALUES(?,?,?)
");

$stmt->execute([
    $user['id'],
    $email_saisi,
    $message
]);


// Envoi email à l'administration

$message_email = "
Nouveau message reçu depuis TANGO

Email utilisateur :
$email_saisi

Message :
$message
";

sendMail(
    "customerservicefor032@gmail.com",
    "Nouveau message Contact TANGO",
    $message_email
);


$succes_contact = "Votre message a été envoyé avec succès.";

        }

    }

}

// Si le message a été envoyé depuis la fenêtre flottante (AJAX), on répond en JSON
// sans re-générer toute la page HTML, pour ne jamais recharger l'écran.
if(isset($_POST['envoyer_message']) && isset($_POST['ajax_contact'])){
    header('Content-Type: application/json');
    if(isset($erreur_contact)){
        echo json_encode(["status" => "error", "message" => $erreur_contact]);
    } else {
        echo json_encode(["status" => "success", "message" => $succes_contact]);
    }
    exit;
}

// ================= FAIRE UN COMMENTAIRE =================

if(isset($_POST['envoyer_commentaire']) && isset($_SESSION['user_id'])){

    $commentaire_msg = trim($_POST['commentaire_message'] ?? '');

    if(empty($commentaire_msg)){

        $erreur_commentaire = "Veuillez écrire un message avant d'envoyer.";

    } else {

        $stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user_email = $stmt->fetchColumn();

        $stmt = $pdo->prepare("
            INSERT INTO commentaires(user_id, email, message, visible, created_at)
            VALUES (?, ?, ?, 1, ?)
        ");
        $stmt->execute([
            $_SESSION['user_id'],
            $user_email,
            $commentaire_msg,
            date('Y-m-d H:i:s')
        ]);

        $succes_commentaire = "Merci pour votre commentaire !";

    }

}
if(isset($_POST["confirm_manual_payment"]) && isset($_SESSION["admin"])){

    $id = (int)$_POST["id"];

    $stmt = $pdo->prepare("
       UPDATE demandes
       SET
          statut='paye',
          txid='MANUEL',
          manual_payment=1,
          manual_status=3,
          notification_active=0
       WHERE id=?
    ");

    $stmt->execute([$id]);

    echo json_encode([
        "success"=>true
    ]);

    exit;
}
if(isset($_POST["reject_manual_payment"]) && isset($_SESSION["admin"])){

    $id = (int)$_POST["id"];

    $stmt = $pdo->prepare("
        UPDATE demandes
        SET
            manual_payment = 0,
            sender_phone = NULL,
            notification_active = 0
        WHERE id = ?
    ");

    $stmt->execute([$id]);

    echo json_encode([
        "success" => true
    ]);

    exit;
}
// ================= STATISTIQUES ADMIN =================

$totalPayes = $pdo->query("
SELECT COUNT(*)
FROM demandes
WHERE statut='paye'
")->fetchColumn();

$totalAttente = $pdo->query("
SELECT COUNT(*)
FROM demandes
WHERE statut='en_attente'
")->fetchColumn();

$totalManuel = $pdo->query("
SELECT COUNT(*)
FROM demandes
WHERE manual_payment=1
AND statut!='paye'
")->fetchColumn();

// 📲 Retraits Mvola en attente de confirmation (table créée par le module portefeuille)
try {
    $totalRetraitsAttente = $pdo->query("
        SELECT COUNT(*)
        FROM retraits_mvola
        WHERE statut='en_attente'
    ")->fetchColumn();
} catch (Exception $e) {
    $totalRetraitsAttente = 0;
}

$totalManuelAttente = $pdo->query("
SELECT COUNT(*)
FROM demandes
WHERE manual_payment=1
AND statut!='paye'
")->fetchColumn();

$totalExpires = $pdo->query("
SELECT COUNT(*)
FROM demandes
WHERE expiration_time < NOW()
AND statut!='paye'
")->fetchColumn();

$totalUsers = $pdo->query("
SELECT COUNT(*)
FROM users
")->fetchColumn();
$data = $pdo->query("SELECT * FROM demandes ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#00c96b">

    <script>
    // 🌐 Sélecteur de langue FR (par défaut) / EN. Traduit les éléments
    // marqués data-fr/data-en (et data-fr-placeholder/data-en-placeholder
    // pour les champs de formulaire). Tourne sur CHAQUE page puisque
    // <head> est commun à tout le site ; la préférence est mémorisée en
    // localStorage et réappliquée à chaque chargement.
    function setLang(lang){
        try { localStorage.setItem('siteLang', lang); } catch(e) {}
        document.querySelectorAll('[data-fr]').forEach(function(el){
            var texte = lang === 'en' ? el.getAttribute('data-en') : el.getAttribute('data-fr');
            if (texte !== null) el.textContent = texte;
        });
        document.querySelectorAll('[data-fr-placeholder]').forEach(function(el){
            var texte = lang === 'en' ? el.getAttribute('data-en-placeholder') : el.getAttribute('data-fr-placeholder');
            if (texte !== null) el.placeholder = texte;
        });
        document.querySelectorAll('.lang-switch .lang-opt').forEach(function(opt){
            opt.classList.toggle('active', opt.getAttribute('data-lang') === lang);
        });
    }
    document.addEventListener('DOMContentLoaded', function(){
        var lang = 'fr';
        try { lang = localStorage.getItem('siteLang') || 'fr'; } catch(e) {}
        setLang(lang);
    });
    </script>

    <script>
    // 🩹 IMPORTANT : cette fonction doit rester dans un bloc <script> commun
    // à toutes les pages (comme celui-ci, dans <head>). Elle était auparavant
    // définie dans un bloc PHP affiché uniquement pour ?page=paiement : sur
    // ?page=compte (où se trouve la carte "Ouvrir une discussion support"),
    // la fonction n'existait donc pas -> erreur JS silencieuse au clic ->
    // le navigateur suivait le lien directement, sans jouer l'animation.
    function handleCardOuvrirClick(e, el){
        // Sur mobile/WebView, l'appui déclenche l':hover/CSS trop tard car la
        // navigation démarre instantanément. On bloque le clic, on affiche
        // l'animation (classe .is-pressed), puis on navigue nous-mêmes.
        e.preventDefault();
        var href = el.getAttribute("href");
        el.classList.add("is-pressed");

        var overlay = document.getElementById("supportWelcomeOverlay");
        if(!overlay){
            // Filet de sécurité : si l'overlay n'existe pas sur cette page,
            // on garde l'ancien comportement (petit effet pressé puis navigation).
            requestAnimationFrame(function(){
                requestAnimationFrame(function(){
                    setTimeout(function(){ window.location.href = href; }, 180);
                });
            });
            return false;
        }

        var loaderBar = overlay.querySelector(".loader-bar");

        // 1. Afficher l'animation de bienvenue support
        overlay.classList.add("active");

        // 2. Lancer la barre de progression
        setTimeout(function(){
            if(loaderBar) loaderBar.style.width = "100%";
        }, 50);

        // 3. Rediriger après 3 secondes (durée de l'animation)
        setTimeout(function(){
            window.location.href = href;
        }, 3000);

        return false;
    }
    </script>


    <script>
    /* ⚠️ Fiabilise la hauteur réelle de l'écran en WebView : "100vh" et
       "height:100%" sont mal calculés par certaines WebView Android
       (notamment sur les pages courtes comme "Support"), ce qui laisse
       une bande blanche sans fond en bas de l'écran. window.innerHeight
       est en revanche toujours correct : on l'expose donc via la variable
       CSS --app-vh, utilisée pour la hauteur de html/body. */
    (function(){
        function setAppVH(){
            var vh = window.innerHeight * 0.01;
            document.documentElement.style.setProperty('--app-vh', vh + 'px');
        }
        setAppVH();
        window.addEventListener('resize', setAppVH);
        window.addEventListener('orientationchange', setAppVH);
    })();
    </script>
    
    <!-- 🟢 1. Empêche la WebView Android d\'inverser les couleurs -->
    <meta name="color-scheme" content="dark light">
    <meta name="supported-color-schemes" content="dark light">

    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/icon-192.png">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Rubik+Wet+Paint&family=Poppins:wght@600;700;800&display=swap" rel="stylesheet">

    <title>Déblocage Compte Tango</title>

    <style>
    /* 🟢 2. Bloque le Force-Dark d\'Android sur la racine */
    :root{
    color-scheme: dark light;
}

*{
    box-sizing:border-box;
}

html,
body{
    margin:0;
    padding:0;
    width:100%;
    /* ⚠️ "100vh" et même "height:100%" restent mal calculés par certaines
       WebView (surtout Android, sur les pages à peu de contenu comme
       "Support") : le fond ne remplit alors que la hauteur du contenu, pas
       celle de l'écran réel. On utilise donc une variable CSS --app-vh,
       calculée en JS à partir de window.innerHeight (fiable partout, y
       compris en WebView), avec 100% en repli si le JS n'a pas encore
       tourné. */
    height:100%;
    height:calc(var(--app-vh, 1vh) * 100);
    min-height:100%;
    min-height:calc(var(--app-vh, 1vh) * 100);
    font-family:Arial,Helvetica,sans-serif;
    overflow-x:hidden;
    background-image:  url("https://deblocagetango.com/tango3.jpg");
   
    background-position: center;
    background-size: cover;
    background-repeat: no-repeat;
    background-attachment: fixed;
    -webkit-text-size-adjust:100%;
    -webkit-tap-highlight-color:transparent;
    overscroll-behavior-y:contain;
}

@media (max-width: 768px){
    html, body{ background-attachment: scroll; }
}

body{
    display:flex;
    position:relative;
}

/* Tous les éléments passent au-dessus du fond */
body > *{
    position:relative;
    z-index:1;
}

.card{
    position:relative;
    z-index:2;

    background:rgba(30,20,60,.78);

    border:1px solid rgba(255,255,255,.08);
    border-radius:24px;
    padding:34px;

    box-shadow:
        0 12px 35px rgba(0,0,0,.35),
        0 0 30px rgba(255,0,120,.15);

    overflow:hidden;
    transition:.3s;
    /* ⚠️ .card est un enfant flex de .main-content (flex-direction:column).
       Avec overflow:hidden, un enfant flex reçoit un min-height automatique
       de 0 au lieu de "auto" : dès que le contenu (liste d'actions, paiement
       manuel, tableau admin...) dépasse la hauteur visible, le flex-item se
       comprime SOUS la taille de son contenu et overflow:hidden rogne le
       surplus au lieu de laisser scroller. flex-shrink:0 empêche cette
       compression et restaure le scroll normal de la page. */
    flex-shrink:0;
}

/* Blur uniquement si réellement supporté */
@supports ((-webkit-backdrop-filter:blur(12px)) or (backdrop-filter:blur(12px))){

.card{

background:rgba(30,20,60,.38);

-webkit-backdrop-filter:blur(12px);
backdrop-filter:blur(12px);

}

}

/* 🔽 Réduction générale de la taille des titres, textes, boutons et champs
   à l'intérieur des cartes, pour un rendu plus compact sur WebView. */
.card h2 { font-size: 21px; margin-bottom: 12px; line-height: 1.3; }
.card > p { font-size: 14px; line-height: 1.5; }
.card input,
.card select,
.card textarea { font-size: 14px !important; padding: 12px 13px; }

a,
button,
input[type=button],
input[type=submit],
.nav-item,
.wallet-btn,
.wallet-btn-network,
.wallet-btn-copy,
.nav-circle,
.floating-success{
    touch-action:manipulation;
    -webkit-user-select:none;
    user-select:none;
}

img{
    -webkit-touch-callout:none;
}

input,
textarea,
select{
    user-select:text;
}

    /* === MENU ET BARRE LATERALE === */
    .sidebar-toggle {
        position: fixed;
        top: 20px;
        left: 20px;
        z-index: 1000;
        background: rgba(255, 255, 255, 0.1);
        border: 1px solid rgba(255, 255, 255, 0.2);
        color: white;
        border-radius: 9px;
        padding: 8px 12px;
        font-size: 18px;
        cursor: pointer;
        backdrop-filter: blur(10px);
        transition: background 0.3s, transform 0.2s;
    }
    .sidebar-toggle:hover { background: rgba(255, 255, 255, 0.2); }
    .sidebar-toggle:active { transform: scale(0.95); }

    .sidebar {
        position: fixed;
        top: 0;
        left: 0;
        bottom: 0;
        width: 260px;
        background: rgba(0, 0, 0, 0.5);
        backdrop-filter: blur(25px);
        -webkit-backdrop-filter: blur(25px);
        border-right: 1px solid rgba(255, 255, 255, 0.1);
        padding: 90px 20px 30px 20px;
        display: flex;
        flex-direction: column;
        z-index: 999;
        transform: translateX(0);
        transition: transform .25s ease;
    }
    .sidebar.hidden { transform: translateX(-100%); }
    .sidebar h1 { color: white; text-align: center; margin-bottom: 30px; font-size: 23px; letter-spacing: 1px; margin-top: 0; }
    .sidebar-brand-logo{ display:block; margin:0 auto 30px; height:64px; width:auto; filter:drop-shadow(0 4px 12px rgba(0,0,0,.4)); }
    .sidebar a {
        color: white; text-decoration: none; padding: 12px 16px; margin-bottom: 8px;
        border-radius: 11px; background: rgba(255, 255, 255, 0.05); 
        transition: transform .3s, opacity .3s, background .3s;
        font-weight: 600; font-size: 14px; border: 1px solid transparent; position: relative; overflow: hidden;
    }
    .sidebar a:hover, .sidebar a.active { background: rgba(255, 255, 255, 0.15); border: 1px solid rgba(255, 255, 255, 0.2); }
    .sidebar a::before {
        content: ""; position: absolute; left: 0; top: 0; width: 4px; height: 100%;
        background: #ff2ea6; transform: scaleY(0); transition: .3s;
    }
    .sidebar a:hover::before, .sidebar a.active::before { transform: scaleY(1); }

    /* === CONTENU PRINCIPAL === */
    .main-content {
        flex: 1;
        display: flex;
        flex-direction: column;
        /* ⚠️ "justify-content:center" seul, combiné à un contenu plus haut que
           l'écran, empêche de scroller jusqu'au tout début du contenu dans
           beaucoup de navigateurs/WebView (bug connu du centrage flex avec
           dépassement) : la page semblait "ne pas scroller". On garde le
           centrage vertical des pages courtes via des marges auto sur des
           pseudo-éléments avant/après, qui se rétractent automatiquement
           dès que le contenu dépasse l'écran, ce qui réactive un scroll
           normal (haut → bas) sur les pages à fort contenu. */
        justify-content: flex-start;
        align-items: center;
        position: relative;
        padding: 20px;
        padding-left: 280px;
        width: 100%;
        min-height: 100%;
        box-sizing: border-box;
        transition: padding-left 0.4s cubic-bezier(0.25, 0.8, 0.25, 1);
    }
    .main-content::before,
    .main-content::after {
        content: "";
        margin: auto 0;
    }

    /* 📱 Adaptation spéciale pour Mobile / WebView */
    @media (max-width: 768px) {
        .main-content {
            padding-left: 14px;
            padding-right: 14px;
            padding-top: 64px;
            padding-bottom: 14px;
        }
    }

    /* === BOTTOM NAVIGATION LUMINEUSE === */
    @keyframes navGlowBorder {
        0%, 100% { background-position: 0% 50%; }
        50% { background-position: 100% 50%; }
    }
    @keyframes navPulse {
        0%, 100% { box-shadow: 0 0 20px rgba(255,46,166,.55), 0 0 38px rgba(160,0,255,.4), 0 0 4px rgba(255,255,255,.6) inset; }
        50% { box-shadow: 0 0 32px rgba(255,46,166,.85), 0 0 55px rgba(160,0,255,.6), 0 0 6px rgba(255,255,255,.8) inset; }
    }
    @keyframes iconFlicker {
        0%, 100% { filter: drop-shadow(0 0 6px currentColor); }
        50% { filter: drop-shadow(0 0 10px currentColor); }
    }
    .bottom-nav {
        position: fixed;
        left: 50%;
        bottom: 0;
        transform: translateX(-50%);
        width: 100%;
        max-width: 480px;
        background: linear-gradient(180deg, rgba(18,12,40,.98) 0%, rgba(8,6,20,.99) 100%);
        border: 1px solid transparent;
        border-bottom: none;
        border-radius: 18px 18px 0 0;
        display: flex;
        justify-content: space-around;
        align-items: flex-end;
        padding: 6px 6px calc(8px + env(safe-area-inset-bottom));
        z-index: 500;
        box-shadow: 0 -10px 40px rgba(0,0,0,.55);
        overflow: visible;
    }
    .bottom-nav::before {
        content: "";
        position: absolute;
        top: -1px; left: 8%; right: 8%;
        height: 2px;
        border-radius: 2px;
        background: linear-gradient(90deg, transparent, #ff2ea6, #a000ff, #2ecbff, #ff2ea6, transparent);
        background-size: 300% 100%;
        animation: navGlowBorder 4s linear infinite;
        filter: drop-shadow(0 0 6px rgba(255,46,166,.8));
    }
    .bottom-nav .nav-item {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 3px;
        text-decoration: none;
        color: rgba(255,255,255,.55);
        font-size: 10.5px;
        font-weight: 600;
        flex: 1;
        transition: .25s;
        position: relative;
    }
    .bottom-nav .nav-item svg { width: 19px; height: 19px; stroke: rgba(255,255,255,.55); transition: .25s; }
    .bottom-nav .nav-item:hover svg,
    .bottom-nav .nav-item:focus-visible svg {
        stroke: #ff9ed8;
        filter: drop-shadow(0 0 6px rgba(255,46,166,.8));
    }
    .bottom-nav .nav-item.active { color: #ff2ea6; text-shadow: 0 0 10px rgba(255,46,166,.8); }
    .bottom-nav .nav-item.active svg {
        stroke: #ff2ea6;
        animation: iconFlicker 1.8s ease-in-out infinite;
    }
    .bottom-nav .nav-item.active::after {
        content: "";
        position: absolute;
        bottom: -8px;
        width: 5px; height: 5px;
        border-radius: 50%;
        background: #ff2ea6;
        box-shadow: 0 0 8px 2px rgba(255,46,166,.9);
    }
    .bottom-nav .nav-center { margin-top: -24px; }
    .bottom-nav .nav-center .nav-circle {
        width: 46px; height: 46px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        background: radial-gradient(circle at 50% 35%, rgba(255,46,166,.95) 0%, rgba(160,0,255,.55) 65%, rgba(11,16,32,0) 100%);
        box-shadow: 0 0 20px rgba(255,46,166,.55), 0 0 35px rgba(160,0,255,.35);
        border: 1px solid rgba(255,255,255,.25);
        margin-bottom: 3px;
        animation: navPulse 2.4s ease-in-out infinite;
    }
    .bottom-nav .nav-center .nav-circle svg { width: 21px; height: 21px; stroke: #fff; filter: drop-shadow(0 0 4px rgba(255,255,255,.8)); }
    .bottom-nav .nav-center.active span { color: #ff2ea6; text-shadow: 0 0 10px rgba(255,46,166,.8); }
    .bottom-nav .nav-center span { color: rgba(255,255,255,.75); font-weight: 700; }
    .nav-badge {
        position: absolute; top: -4px; right: 14px;
        background: #ff2ea6; color: #fff; font-size: 10px; font-weight: 700;
        min-width: 16px; height: 16px; border-radius: 50%; padding: 0 3px;
        display: flex; align-items: center; justify-content: center;
        box-shadow: 0 0 8px rgba(255,46,166,.7), 0 0 16px rgba(255,46,166,.4);
    }
    @media (prefers-reduced-motion: reduce) {
        .bottom-nav::before,
        .bottom-nav .nav-center .nav-circle,
        .bottom-nav .nav-item.active svg {
            animation: none;
        }
    }
    /* ⚠️ certaines WebView (notamment Android selon la version) ne supportent
       pas env(). Quand une fonction CSS n'est pas supportée, ce n'est pas
       juste ignorée : TOUTE la déclaration devient invalide et est rejetée,
       et padding-bottom retombe à 0 → plus aucune marge de sécurité → la
       barre de nav fixe recouvre le dernier élément de la carte (le bouton
       "Se déconnecter", sur dashboard ET compte). On pose donc d'abord une
       valeur de repli fixe (sans env()), que ces WebView garderont puisque
       la ligne suivante sera invalide chez elles et donc ignorée ; les
       navigateurs à jour appliquent normalement la 2e ligne, plus précise. */
    body.has-bottom-nav .main-content { padding-bottom: 115px; }
    body.has-bottom-nav .main-content { padding-bottom: calc(85px + env(safe-area-inset-bottom)); }
    /* ⚠️ Sur le dashboard, .has-bottom-nav ET .has-comments-ticker sont actifs
       en même temps (le bandeau flotte juste au-dessus de la barre de nav).
       Les deux règles ".main-content" ci-dessous/ci-dessus ont la MÊME
       spécificité (2 classes) : celle définie en dernier dans le fichier
       écrasait silencieusement l'autre et perdait au passage la marge
       env(safe-area-inset-bottom). En WebView (barre de gestes Android,
       encoche iOS...) cette marge peut valoir 30-50px : sans elle, le dernier
       élément de la carte (le bouton "Se déconnecter") se retrouvait caché
       derrière la barre de navigation. Règle à 3 classes = plus spécifique,
       elle gagne toujours et restaure la marge de sécurité. */
    body.has-bottom-nav.has-comments-ticker .main-content {
        padding-bottom: 220px;
    }
    body.has-bottom-nav.has-comments-ticker .main-content {
        padding-bottom: calc(190px + env(safe-area-inset-bottom));
    }
/* === ÉTAT "CONNEXION REQUISE" (Mon compte) === */
.auth-required { padding: 10px 5px 5px; }
.auth-required .auth-icon {
    width: 68px; height: 68px; margin: 0 auto 22px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    background: radial-gradient(circle at 50% 35%, rgba(255,46,166,.25) 0%, rgba(160,0,255,.12) 60%, rgba(11,16,32,0) 100%);
    border: 1px solid rgba(255,46,166,.35);
    box-shadow: 0 0 30px rgba(255,46,166,.25);
}
.auth-required .auth-icon svg { width: 28px; height: 28px; stroke: #ff2ea6; }
.auth-required h2 {
    color: #fff;
    font-size: 21px;
    font-weight: 700;
    letter-spacing: .2px;
    margin-bottom: 10px;
}
.auth-required p {
    color: rgba(255,255,255,.6);
    font-size: 14.5px;
    line-height: 1.6;
    max-width: 320px;
    margin: 0 auto 28px;
}
.btn-connect-pro {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    width: 100%;
    max-width: 280px;
    padding: 15px 20px;
    border-radius: 14px;
    background: linear-gradient(90deg, #ff0055 0%, #b000cd 100%);
    color: #fff;
    font-size: 15px;
    font-weight: 700;
    letter-spacing: .3px;
    text-decoration: none;
    box-shadow: 0 10px 28px rgba(255,0,130,.35);
    transition: transform .3s ease, box-shadow .3s ease;
}
.btn-connect-pro svg { width: 18px; height: 18px; stroke: #fff; }
.btn-connect-pro:hover { transform: translateY(-3px); box-shadow: 0 15px 35px rgba(255,0,130,.5); }

/* === EN-TÊTE "MON COMPTE" (logo bagué + statut) === */
.logo-box.logo-box-ring { margin-bottom: 8px; }
.logo-box.logo-box-ring img {
    width: 64px;
    height: 64px;
    padding: 5px;
    border: 3px solid #fff;
    box-shadow: 0 0 0 2px rgba(255,46,166,.25), 0 0 30px rgba(255,46,166,.55);
}
.status-card {
    display: flex;
    align-items: center;
    gap: 11px;
    text-align: left;
    background: linear-gradient(135deg, rgba(255,255,255,.05), rgba(255,255,255,.02));
    border: 1px solid rgba(255,255,255,.12);
    border-radius: 16px;
    padding: 11px 13px;
    margin-bottom: 16px;
    text-decoration: none;
    cursor: default;
    transition: .25s;
    box-shadow: 0 4px 14px rgba(0,0,0,.2);
}
.status-card:hover { background: rgba(255,255,255,.07); border-color: rgba(255,46,166,.3); box-shadow: 0 8px 22px rgba(0,0,0,.3); }
.status-card .status-icon {
    flex-shrink: 0;
    width: 38px; height: 38px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    background: radial-gradient(circle at 40% 30%, #ff8fd6 0%, #ff2ea6 45%, #7a00c9 100%);
    box-shadow: 0 0 18px rgba(255,46,166,.5);
}
.status-card .status-icon svg { width: 19px; height: 19px; stroke: #fff; }
.status-card .status-body { flex: 1; min-width: 0; }
.status-card .status-title {
    display: flex; align-items: center; gap: 7px;
    color: #fff; font-weight: 700; font-size: 13px; margin-bottom: 2px;
}
.status-pill {
    display: inline-block;
    font-size: 10px; font-weight: 700;
    color: #00ff99;
    background: rgba(0,255,153,.12);
    border: 1px solid rgba(0,255,153,.35);
    padding: 2px 9px;
    border-radius: 999px;
}
.status-card .status-sub { color: rgba(255,255,255,.55); font-size: 11.5px; }
.status-card .status-chevron { flex-shrink: 0; color: rgba(255,255,255,.4); }
.status-card .status-chevron svg { width: 16px; height: 16px; }
.section-title {
    text-align: left;
    color: #fff;
    font-size: 13.5px;
    font-weight: 700;
    margin: 0 0 8px;
}

/* === GRILLE D\'ACTIONS (Mes actions) === */
.actions-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 18px;
}
@media (max-width: 480px) {
    /* 📱 Beaucoup de téléphones Android (WebView de l'appli) dépassent
       souvent 360-400px de large : on garde donc les 2 colonnes ici aussi
       (comme sur navigateur) et on se contente de resserrer un peu plus
       l'espacement et la taille pour que tout reste lisible. Le repli en
       1 colonne ne se fait plus que sur des écrans très étroits (< 360px). */
    .actions-grid { gap: 6px; }
    .action-card { padding: 8px; gap: 6px; }
    .action-card .action-icon { width: 26px; height: 26px; }
    .action-card .action-icon svg { width: 12px; height: 12px; }
    .action-card .action-title { font-size: 10.5px; }
    .action-card .action-sub { font-size: 9px; }
}
@media (max-width: 300px) {
    /* Seuls les écrans vraiment minuscules repassent en 1 colonne */
    .actions-grid { grid-template-columns: 1fr; }
}
/* 📱 Dans le WebView Android de l'appli, on force TOUJOURS 2 colonnes sur
   "Mes actions", exactement comme sur un navigateur mobile classique — même
   si la WebView calcule/rapporte une largeur de viewport différente. */
.is-webview .actions-grid,
.is-webview .compte-page .actions-grid {
    grid-template-columns: 1fr 1fr !important;
}
/* 📱 Menu latéral : dans le WebView, on force le comportement "mobile"
   (masqué par défaut, affiché via .show-mobile) même si window.innerWidth
   ne descend pas sous 768px dans la WebView. */
.is-webview .sidebar { transform: translateX(-100%); }
.is-webview .sidebar.show-mobile { transform: translateX(0); }
.is-webview .main-content { padding-left: 20px !important; }
.action-card {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    text-align: left;
    background: rgba(255,255,255,.03);
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 14px;
    padding: 11px;
    text-decoration: none;
    cursor: pointer;
    transition: .25s;
    box-shadow: 0 3px 10px rgba(0,0,0,.18);
}
.action-card:hover { background: rgba(255,255,255,.06); border-color: rgba(255,255,255,.2); transform: translateY(-2px); box-shadow: 0 10px 24px rgba(0,0,0,.3); }
.action-card:active,
.action-card.is-pressed { background: rgba(255,255,255,.08); border-color: rgba(255,255,255,.25); transform: translateY(-2px) scale(.98); box-shadow: 0 10px 24px rgba(0,0,0,.3); transition: .08s; }
.action-card .action-icon {
    flex-shrink: 0;
    width: 36px; height: 36px; border-radius: 11px;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 6px 16px rgba(0,0,0,.35);
}
.action-card .action-icon svg { width: 17px; height: 17px; stroke: #fff; }
.action-icon.pink   { background: linear-gradient(135deg, #e864e0, #7c1fd6); }
.action-icon.blue   { background: linear-gradient(135deg, #3b9bff, #1657c4); }
.action-icon.green  { background: linear-gradient(135deg, #22d98a, #128a55); }
.action-icon.orange { background: linear-gradient(135deg, #ffb03b, #e0731a); }
.action-icon.purple { background: linear-gradient(135deg, #9b5cff, #6a1fd6); }
.action-icon.teal   { background: linear-gradient(135deg, #1fd6c8, #0d8f8a); }
.action-icon.gold   { background: linear-gradient(135deg, #f5c542, #c98a0c); }

/* === PANNEAU "MON PORTEFEUILLE" (style pro) === */
.wallet-pro-logo { display: flex; justify-content: center; margin-bottom: 13px; }
.wallet-pro-logo img { height: 40px; width: auto; }
.wallet-pro-balance {
    text-align: center;
    background: linear-gradient(135deg, rgba(0,201,107,.16), rgba(0,150,80,.05));
    border: 1px solid rgba(0,201,107,.3);
    border-radius: 14px;
    padding: 15px;
    margin-bottom: 15px;
}
.wallet-pro-balance .wp-label { color: rgba(255,255,255,.55); font-size: 10.5px; text-transform: uppercase; letter-spacing: .6px; margin-bottom: 4px; }
.wallet-pro-balance .wp-amount { color: #00e08a; font-size: 24px; font-weight: 800; }
.wallet-pro-addr { margin-bottom: 12px; }
.wallet-pro-addr .network-badge {
    display: inline-block; font-size: 9.5px; font-weight: 700;
    padding: 3px 8px; border-radius: 7px; margin-bottom: 6px; letter-spacing: .3px;
}
.network-badge.trc20 { background: rgba(255,0,80,.15); color: #ff2e6f; border: 1px solid rgba(255,0,80,.3); }
.network-badge.bep20 { background: rgba(240,185,11,.15); color: #f0b90b; border: 1px solid rgba(240,185,11,.3); }
.wallet-pro-addr-row {
    display: flex; align-items: center; gap: 7px;
    background: rgba(11,16,32,.7); border: 1px solid rgba(255,255,255,.12);
    border-radius: 11px; padding: 10px 12px;
}
.wallet-pro-addr-row input {
    flex: 1; min-width: 0; background: transparent; border: none; color: #fff;
    font-size: 11.5px; font-family: monospace; outline: none; overflow: hidden; text-overflow: ellipsis;
}
.wallet-pro-addr-row button {
    flex-shrink: 0; background: linear-gradient(135deg,#ff007f,#8b5cf6); border: none; color: #fff;
    padding: 7px 12px; border-radius: 8px; cursor: pointer; font-size: 11px; font-weight: 700; transition: .25s;
}
.wallet-pro-addr-row button:hover { transform: translateY(-1px); box-shadow: 0 6px 14px rgba(255,0,150,.35); }
.wallet-pro-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 9px; margin-top: 5px; }
.wallet-pro-actions button {
    display: flex; flex-direction: column; align-items: center; gap: 5px;
    padding: 12px; border-radius: 13px; border: none; cursor: pointer;
    font-weight: 700; font-size: 12px; color: #fff; transition: .25s;
    box-shadow: 0 6px 16px rgba(0,0,0,.3);
}
.wallet-pro-actions .btn-deposit  { background: linear-gradient(135deg, #22d98a, #128a55); }
.wallet-pro-actions .btn-withdraw { background: linear-gradient(135deg, #3b9bff, #1657c4); }
.wallet-pro-actions button:hover { transform: translateY(-2px); filter: brightness(1.08); }
.wallet-pro-subform {
    margin-top: 14px; padding: 14px; border-radius: 13px;
    background: rgba(255,255,255,.03); border: 1px solid rgba(255,255,255,.1);
}
.wallet-pro-subform h4 { color: #fff; font-size: 12px; margin: 0 0 10px; }
@media (max-width: 480px) {
    .wallet-pro-balance .wp-amount { font-size: 23px; }
    .wallet-pro-actions button { font-size: 12px; padding: 12px; }
}
.action-card .action-body { flex: 1; min-width: 0; }
.action-card .action-title {
    display: flex; align-items: center; justify-content: space-between; gap: 5px;
    color: #fff; font-weight: 700; font-size: 13px; margin-bottom: 3px;
}
.action-card .action-title svg { width: 14px; height: 14px; stroke: rgba(255,255,255,.4); flex-shrink: 0; }
.action-card .action-sub { color: rgba(255,255,255,.5); font-size: 11.5px; line-height: 1.4; }

/* Carte "Ouvrir une discussion support" dépliable */
.action-card.expandable { flex-direction: column; align-items: stretch; }
.action-card.expandable .action-head { display: flex; align-items: flex-start; gap: 12px; width: 100%; }

/* === LISTE DE NOTIFICATIONS DÉFILANTE (glisser pour voir plus) === */
.notif-scroll {
    display: flex;
    flex-direction: column;
    gap: 12px;
    max-height: 60vh;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin;
    scrollbar-color: rgba(255,46,166,.45) transparent;
    padding-right: 4px;
}
.notif-scroll::-webkit-scrollbar { width: 5px; }
.notif-scroll::-webkit-scrollbar-track { background: transparent; }
.notif-scroll::-webkit-scrollbar-thumb { background: rgba(255,46,166,.45); border-radius: 10px; }

/* === FENÊTRES FLOTTANTES (modales) — remplace l\'ancien dépliage inline === */
.modal-overlay {
    display: none;
    position: fixed;
    top: 0; right: 0; bottom: 0; left: 0;
    width: 100%;
    height: 100%;
    background: rgba(5,2,15,.5);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    z-index: 3000;
    align-items: center;
    justify-content: center;
    padding: 20px;
    box-sizing: border-box;
}
.modal-overlay.open {
    display: flex;
    animation: modalFadeIn .2s ease;
}
.modal-box {
    /* Fond suffisamment opaque pour flotter visiblement même sans flou */
    background: rgb(28,16,46);
    border: 1px solid rgba(255,255,255,.18);
    border-radius: 20px;
    padding: 24px;
    width: 100%;
    max-width: 440px;
    /* ⚠️ "vh" est mal calculé par certains WebView Android (parfois ~0px avant
       stabilisation de la page), ce qui écrasait la hauteur de la carte à
       presque rien. On utilise un % du conteneur plein écran (fiable, basé
       sur top/bottom en dur) au lieu de vh, avec un plafond en px. */
    max-height: 90%;
    overflow-y: auto;
    box-shadow: 0 25px 70px rgba(0,0,0,.55), 0 0 0 1px rgba(255,46,166,.08);
    text-align: left;
    animation: modalPopIn .28s cubic-bezier(.34,1.56,.64,1);
    -webkit-overflow-scrolling: touch;
}
/* Flou uniquement si réellement supporté (sinon la carte reste opaque ci-dessus) */
@supports ((-webkit-backdrop-filter:blur(26px)) or (backdrop-filter:blur(26px))){
.modal-box {
    background: linear-gradient(180deg, rgba(40,20,65,.55), rgba(15,8,28,.6));
    -webkit-backdrop-filter: blur(26px) saturate(150%);
    backdrop-filter: blur(26px) saturate(150%);
}
}
@keyframes modalFadeIn { from { opacity: 0; } to { opacity: 1; } }
@keyframes modalPopIn {
    from { opacity: 0; transform: scale(.9) translateY(14px); }
    to   { opacity: 1; transform: scale(1) translateY(0); }
}
.modal-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 18px;
}
.modal-head h3 {
    color: #fff;
    font-size: 16.5px;
    font-weight: 800;
    margin: 0;
}
.modal-close {
    flex-shrink: 0;
    width: 32px; height: 32px;
    border-radius: 50%;
    background: rgba(255,255,255,.08);
    border: 1px solid rgba(255,255,255,.15);
    color: #fff;
    font-size: 15px;
    line-height: 1;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: .2s;
}
.modal-close:hover { background: rgba(255,0,85,.25); border-color: rgba(255,0,85,.4); }
body.modal-open-lock { overflow: hidden; }

.action-expand-panel select {
    width: 100%;
    padding: 13px 14px;
    border-radius: 12px;
    background: rgba(11,16,32,.7);
    border: 1px solid rgba(255,255,255,.15);
    color: #fff;
    font-size: 14px;
    margin-bottom: 14px;
}

/* Liste déroulante à l'intérieur d'une fenêtre modale (ex. "Problème compte") :
   même gabarit compact que .action-expand-panel select, pour qu'elle ne
   déborde jamais du cadre du modal sur WebView. */
.modal-box select {
    width: 100%;
    padding: 13px 14px;
    border-radius: 12px;
    background: rgba(11,16,32,.7);
    border: 1px solid rgba(255,255,255,.15);
    color: #fff;
    font-size: 14px;
    margin-bottom: 14px;
    background-position: right 14px center;
    background-size: 15px;
}
.btn-confirm-pro {
    width: 100%;
    padding: 12px;
    border: none;
    border-radius: 11px;
    background: linear-gradient(90deg, #ff0055 0%, #b000cd 100%);
    color: #fff;
    font-weight: 700;
    font-size: 13.5px;
    cursor: pointer;
    box-shadow: 0 8px 22px rgba(255,0,130,.35);
    transition: transform .25s ease;
}
.btn-confirm-pro:hover { transform: translateY(-2px); }

/* Boutons internes de la carte "Ouvrir une discussion support Tango" */
.action-subbuttons {
    display: none;
    flex-direction: column;
    gap: 10px;
    margin-top: 4px;
}
.action-subbuttons.show { display: flex; }
.btn-sub-action {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    border: 1px solid rgba(255,255,255,.12);
    background: linear-gradient(135deg, rgba(255,255,255,.08), rgba(255,255,255,.02));
    color: #fff;
    font-size: 13px;
    font-weight: 700;
    text-align: left;
    padding: 12px 14px;
    border-radius: 13px;
    cursor: pointer;
    box-shadow: 0 6px 18px rgba(0,0,0,.25);
    transition: transform .22s ease, box-shadow .22s ease, border-color .22s ease;
}
.btn-sub-action .btn-sub-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    flex-shrink: 0;
    border-radius: 9px;
    font-size: 15px;
}
.btn-sub-action.pink .btn-sub-icon { background: linear-gradient(135deg, #e864e0, #7c1fd6); }
.btn-sub-action.purple .btn-sub-icon { background: linear-gradient(135deg, #9b5cff, #6a1fd6); }
.btn-sub-action .btn-sub-arrow { margin-left: auto; opacity: .5; flex-shrink: 0; }
.btn-sub-action .btn-sub-arrow svg { width: 16px; height: 16px; stroke: #fff; display: block; }
.btn-sub-action:hover {
    transform: translateY(-2px);
    border-color: rgba(255,255,255,.28);
    box-shadow: 0 10px 26px rgba(0,0,0,.35);
}

/* Bannière offre spéciale */
.offer-banner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 11px;
    background: linear-gradient(120deg, rgba(255,46,166,.18) 0%, rgba(160,0,255,.12) 100%);
    border: 1px solid rgba(255,46,166,.35);
    border-radius: 15px;
    padding: 14px;
    margin-bottom: 14px;
    text-align: left;
}
.offer-banner .offer-left { display: flex; align-items: flex-start; gap: 10px; }
.offer-banner .offer-emoji { font-size: 22px; line-height: 1; }
.offer-banner .offer-title { color: #ff2ea6; font-weight: 800; font-size: 13px; margin-bottom: 3px; }
.offer-banner .offer-sub { color: rgba(255,255,255,.6); font-size: 11px; line-height: 1.4; max-width: 220px; }
.btn-offer {
    flex-shrink: 0;
    display: inline-flex; align-items: center; gap: 5px;
    padding: 8px 13px;
    border-radius: 11px;
    background: linear-gradient(90deg, #ff0055, #b000cd);
    color: #fff; font-weight: 700; font-size: 11.5px;
    text-decoration: none;
    box-shadow: 0 8px 20px rgba(255,0,130,.35);
    white-space: nowrap;
}
.btn-offer svg { width: 13px; height: 13px; stroke: #fff; }

/* === BADGE "ESSAI GRATUIT" (nouveaux utilisateurs, après le bouton Confirmer) === */
.trial-inline-btn {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    margin-top: 12px;
    background: linear-gradient(120deg, #ff0055 0%, #b000cd 100%);
    border: 1px solid rgba(255,255,255,.2);
    border-radius: 16px;
    padding: 12px 16px;
    box-shadow: 0 10px 30px rgba(255,0,130,.45);
    box-sizing: border-box;
    animation: trialInlinePulse 2.4s ease-in-out infinite;
}
.trial-inline-btn .trial-inline-emoji { font-size: 24px; line-height: 1; flex-shrink: 0; }
.trial-inline-btn .trial-inline-text { display: flex; flex-direction: column; }
.trial-inline-btn .trial-inline-title { color: #fff; font-weight: 800; font-size: 14.5px; }
.trial-inline-btn .trial-inline-sub { color: rgba(255,255,255,.85); font-size: 12px; }
@keyframes trialInlinePulse {
    0%, 100% { box-shadow: 0 10px 30px rgba(255,0,130,.45); }
    50% { box-shadow: 0 10px 40px rgba(255,0,130,.75); }
}
@media (max-width: 480px) {
    .trial-inline-btn { padding: 10px 14px; }
    .trial-inline-btn .trial-inline-emoji { font-size: 20px; }
    .trial-inline-btn .trial-inline-title { font-size: 13px; }
    .trial-inline-btn .trial-inline-sub { font-size: 11px; }
}

/* Carte pleine largeur (Informations du compte) */
.action-card.full { flex-direction: row; align-items: center; margin-bottom: 18px; }
.action-card.full .action-icon.circle { border-radius: 50%; }

/* Se déconnecter — carte pleine largeur */
.logout-card {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    padding: 13px;
    border-radius: 14px;
    background: linear-gradient(180deg, rgba(255,0,85,.12), rgba(255,0,85,.04));
    border: 1px solid rgba(255,0,85,.3);
    color: #ff2ea6;
    font-weight: 700;
    font-size: 13px;
    text-decoration: none;
    transition: .25s;
}
.logout-card:hover { background: rgba(255,0,85,.18); }
.logout-card svg { width: 16px; height: 16px; stroke: #ff2ea6; }

/* === BANDEAU FLOTTANT DE COMMENTAIRES (Accueil) === */
.comments-ticker {
    position: fixed;
    left: 50%;
    bottom: 100px;
    transform: translateX(-50%);
    width: calc(100% - 30px);
    max-width: 440px;
    background: linear-gradient(135deg, rgba(255,46,166,.18), rgba(122,0,201,.24) 55%, rgba(13,7,26,.95));
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    border: 1px solid rgba(255,46,166,.25);
    border-radius: 16px;
    padding: 12px 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    z-index: 400;
    box-shadow: 0 12px 30px rgba(0,0,0,.4), 0 0 25px rgba(255,46,166,.12);
    opacity: 0;
    transition: opacity .5s ease;
}
.comments-ticker.visible { opacity: 1; }
.comments-ticker .ticker-quote {
    flex-shrink: 0;
    width: 34px; height: 34px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    background: linear-gradient(135deg, #ff2ea6, #7a00c9);
    box-shadow: 0 0 14px rgba(255,46,166,.5);
}
.comments-ticker .ticker-quote svg { width: 16px; height: 16px; stroke: #fff; }
.comments-ticker .ticker-body { flex: 1; min-width: 0; }
.comments-ticker .ticker-message {
    color: #fff; font-size: 13px; line-height: 1.4;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
}
.comments-ticker .ticker-author { color: rgba(255,255,255,.5); font-size: 11px; margin-top: 3px; }
body.has-comments-ticker .main-content { padding-bottom: 190px; }

.bg-glow-1 { position: absolute; width: 12px; height: 12px; background: #64748b; border-radius: 50%; filter: blur(4px); top: 58%; left: 16%; opacity: 0.6; animation: floatGlow 7s ease-in-out infinite; }
.bg-glow-2 { position: absolute; width: 8px; height: 8px; background: #3b82f6; border-radius: 50%; filter: blur(3px); top: 66%; left: 9%; opacity: 0.5; animation: floatGlow 7s ease-in-out infinite; }
.bg-glow-3 { position: absolute; width: 14px; height: 14px; background: #64748b; border-radius: 50%; filter: blur(5px); top: 45%; right: 15%; opacity: 0.6; animation: floatGlow 7s ease-in-out infinite; }
.bg-glow-4 { position: absolute; width: 10px; height: 10px; background: #ffaa00; border-radius: 50%; filter: blur(4px); bottom: 25%; right: 14%; opacity: 0.5; animation: floatGlow 7s ease-in-out infinite; }

/* === CARTE PRINCIPALE (PREMIUM UI) === */
.card {
    background: rgba(255,255,255,0.03);
    backdrop-filter: blur(25px);
    -webkit-backdrop-filter: blur(25px);
    border: 1px solid rgba(255,255,255,0.12);
    border-radius: 30px;
    width: 100%;
    max-width: 520px;
    padding: 50px 45px;
    text-align: center;
    position: relative;
    overflow: hidden;
    transition: .35s;
    animation: zoomIn .6s ease;
}.card::before{
    content:"";
    position:absolute;
    inset:-1px;
    border-radius:30px;
    padding:4px;
filter: blur(1px);
    
    
    background:
        linear-gradient(90deg,
        transparent 0%,
        transparent 42%,
        #ffffff 48%,
        #ff2ea6 50%,
        #00d4ff 52%,
        transparent 58%);

    background-size:400% 400%;

    -webkit-mask:
        linear-gradient(#fff 0 0) content-box,
        linear-gradient(#fff 0 0);

    -webkit-mask-composite:xor;
    mask-composite:exclude;

    pointer-events:none;

    animation:borderTravel 10s linear infinite;
}
   @keyframes borderTravel{

0%{
background-position:0% 0%;
}

25%{
background-position:100% 0%;
}

50%{
background-position:100% 100%;
}

75%{
background-position:0% 100%;
}

100%{
background-position:0% 0%;
}

    }
.card:hover { transform: translateY(-6px); box-shadow: 0 10px 25px rgba(0,0,0,.35), 0 0 60px rgba(255,0,140,.25); }

/* === TITRES === */
.title-top { font-size: 46px; font-weight: 800; margin: 0 auto 5px auto; color: #ffffff; letter-spacing: -0.5px; line-height: 1.1; }
.title-top span { background: linear-gradient(to right, #ff71cd, #ff007f); -webkit-background-clip: text; -webkit-text-fill-color: transparent; display: inline-block; }
.title-bottom { font-size: 46px; font-weight: 800; margin: 0 auto 40px auto; color: #ffffff; letter-spacing: -0.5px; line-height: 1.1; }
.title-bottom span { background: linear-gradient(135deg, #ff007f, #e000ff); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }

/* === FORMULAIRES & INPUTS === */
.input-group { position: relative; margin-bottom: 16px; width: 100%; }
select, input, textarea {
    width: 100%; padding: 16px 20px; border-radius: 12px; border: 1px solid rgba(255, 255, 255, 0.15);
    background: rgba(30, 15, 50, 0.4); color: #ffffff; font-size: 15px; transition:
transform .3s,
opacity .3s,
background .3s;
}
input::placeholder { color: rgba(255, 255, 255, 0.4); }
input:hover, select:hover, textarea:hover { border-color: #ff2ea6; }
input:focus, select:focus, textarea:focus { outline: none; box-shadow: 0 0 0 3px rgba(255,0,130,.15), 0 0 25px rgba(255,0,130,.25); }

select {
    appearance: none; -webkit-appearance: none; cursor: pointer;
    background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='rgba(255,255,255,0.5)' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><polyline points='6 9 12 15 18 9'></polyline></svg>");
    background-repeat: no-repeat; background-position: right 20px center; background-size: 16px;
}
input[type="text"] {
    background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='rgba(255,255,255,0.4)' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z'></path><polyline points='22,6 12,13 2,6'></polyline></svg>");
    background-repeat: no-repeat; background-position: left 20px center; background-size: 18px; padding-left: 52px;
}
input[type="password"] {
    background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='rgba(255,255,255,0.4)' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><rect x='3' y='11' width='18' height='11' rx='2' ry='2'></rect><path d='M7 11V7a5 5 0 0 1 10 0v4'></path></svg>");
    background-repeat: no-repeat; background-position: left 20px center; background-size: 18px; padding-left: 52px;
}
.password-toggle { position: absolute; right: 20px; top: 50%; transform: translateY(-50%); cursor: pointer; opacity: 0.5; }

/* === BOUTONS === */
.btn-login, .btn-register, .btn-back { position: relative; overflow: hidden; transition: .35s; }
button.btn-login {
    width: 100%; padding: 13px; border: none; border-radius: 11px; background: linear-gradient(90deg, #ff0055 0%, #b000cd 100%);
    color: white; font-size: 15px; font-weight: 600; cursor: pointer; margin-top: 8px;
}
.btn-register {
    display: block; width: 100%; margin-top: 12px; padding: 13px; border-radius: 11px;
    background: rgba(255,255,255,0.10); color: white; text-decoration: none; font-size: 15px; font-weight: bold; text-align: center;
}
.btn-back{
    display:block;
    width:100%;
    margin-top:16px;
    padding:13px;
    text-align:center;
    text-decoration:none;
    color:white;
    font-weight:bold;
    font-size:14px;
    border-radius:13px;
    background:linear-gradient(135deg,#ff4d4d,#c60000);
    transition:.3s;
}

.btn-back:hover{
    transform:translateY(-3px);
    box-shadow:0 15px 35px rgba(255,0,0,.35);
}

.btn-login:hover, .btn-register:hover, .btn-back:hover { transform: translateY(-3px); box-shadow: 0 15px 35px rgba(255,0,130,.45); }
.btn-login::before, .btn-register::before {
    content: ""; position: absolute; top: 0; left: -120%; width: 70%; height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,.4), transparent);
    transform: skewX(-25deg); transition: .8s;
}
.btn-login:hover::before, .btn-register:hover::before { left: 150%; }

/* === LOGO & QR === */
.logo-box { display: flex; justify-content: center; align-items: center; gap: 12px; margin-bottom: 16px; }
.logo-box img{

width:96px;

height:96px;

border-radius:50%;

background:white;

padding:8px;

box-shadow:

0 0 40px rgba(255,0,120,.45);

animation:logoPulse 5s infinite;

}
.logo-box-dual{
display:flex;
justify-content:center;
align-items:flex-start;
gap:26px;
}
.logo-box-dual .logo-item{
display:flex;
flex-direction:column;
align-items:center;
gap:10px;
}
.logo-box-dual .logo-item img{
width:150px;
height:150px;
border-radius:50%;
background:white;
padding:10px;
box-shadow:0 0 40px rgba(255,0,120,.45);
animation:logoPulse 5s infinite;
}
.logo-box-dual .logo-caption{
color:#fff;
font-size:13.5px;
font-weight:600;
text-align:center;
line-height:1.3;
max-width:150px;
margin:0;
}
.payment-logo{
display:flex;
justify-content:center;
margin-bottom:25px;
}

.payment-logo-ring{

width:120px;
height:120px;
border-radius:50%;

padding:5px;

background:linear-gradient(
135deg,
#ff007f,
#ff4db8,
#8b5cf6,
#00d4ff
);

display:flex;
justify-content:center;
align-items:center;

animation:logoRotate 8s linear infinite;

box-shadow:
0 0 20px rgba(255,0,150,.35),
0 0 45px rgba(139,92,246,.25);
}

.payment-logo-ring img{

width:105px;
height:105px;

border-radius:50%;

background:white;

padding:12px;

object-fit:contain;

animation:logoPulse 3s ease-in-out infinite;
}
                               @keyframes logoRotate{

0%{
transform:rotate(0deg);
}

100%{
transform:rotate(360deg);
}

}


.qr-new:hover { transform: scale(1.05); box-shadow: 0 0 40px rgba(255,255,255,.35); }

/* === SECTION PAIEMENT === */

    .network-pro{
    background:rgba(255,255,255,.04);
    padding:14px;
    border-radius:18px;
}
.payment-box h2 { font-size: 28px; margin: 12px 0 18px; color: #fff; font-weight: 700; text-align: center; }
.payment-box p { text-align: center; line-height: 1.8; }
.payment-status { background: linear-gradient(90deg,#00c853,#00e676); color: #fff; padding: 12px; border-radius: 14px; text-align: center; font-weight: 700; margin-bottom: 16px; box-shadow: 0 10px 25px rgba(0,200,100,.25); }
.payment-section { background: rgba(255,255,255,.05); border: 1px solid rgba(255,255,255,.06); border-radius: 18px; padding: 16px; margin: 12px 0; transition: .25s; }
.payment-section:hover { transform: translateY(-5px); border-color: #ff2ea6; box-shadow: 0 20px 40px rgba(255,0,130,.18); }
.payment-section h3 { margin: 0 0 10px; font-size: 14px; color: #9aa4bd; }

.network { display: inline-block; padding: 12px 18px; background: linear-gradient(90deg,#ff0057,#9d00ff); border-radius: 50px; color: #fff; font-weight: bold; margin-bottom: 20px; }
.network-pro { display: flex; justify-content: center; align-items: center; gap: 15px; }
.network-pro img { width: 50px; height: 50px; }
.network-pro strong { color: white; font-size: 20px; }
.network-pro span { color: #ffd34f; }

@media(max-width:768px){
.wallet-box{

display:flex;

gap:12px;

align-items:center;

margin-top:15px;
}
    }
.card::after{
    content:"";
    position:absolute;

    top:20px;
    left:20px;

    width:120px;
    height:120px;

    border-radius:50%;

    background:radial-gradient(
        circle,
        rgba(255,255,255,.95) 0%,
        rgba(255,0,150,.55) 35%,
        rgba(0,212,255,.25) 60%,
        transparent 75%
    );

    filter:blur(18px);
    opacity:.9;

    pointer-events:none;
    z-index:1;

    animation:orbFloat 12s ease-in-out infinite;
}

.card > *{
    position:relative;
    z-index:2;
}
    @keyframes orbFloat{

0%{
transform:translate(30px,30px);
}

25%{
transform:translate(320px,50px);
}

50%{
transform:translate(280px,420px);
}

75%{
transform:translate(40px,360px);
}

100%{
transform:translate(30px,30px);
}

}
.wallet-box input{

flex:1;

}
  .security-box{

margin-top:20px;

padding:18px;

border-radius:16px;

background:rgba(255,255,255,.05);

border:1px solid rgba(255,255,255,.08);

text-align:left;

}
   
.payment-status{

animation:pulseStatus 2s infinite;

}
    @keyframes pulseStatus{

0%{
box-shadow:0 0 0 rgba(0,255,120,.3);
}

50%{
box-shadow:0 0 25px rgba(0,255,120,.7);
}

100%{
box-shadow:0 0 0 rgba(0,255,120,.3);
}

}
.wallet-box button{

white-space:nowrap;

}
   

@media(max-width:768px){

.wallet-box{

flex-direction:column;

}

.wallet-box button{

width:100%;

}
    }

}

.amount-box{

display:flex;

align-items:center;

justify-content:center;

gap:18px;

padding:22px;

margin-top:20px;

border-radius:22px;

background:linear-gradient(
135deg,
rgba(255,255,255,.06),
rgba(255,255,255,.02)
);

border:1px solid rgba(255,255,255,.08);

box-shadow:
0 10px 35px rgba(0,0,0,.25);

}

.amount-box img{

width:72px;
height:72px;

padding:10px;

border-radius:18px;

background:rgba(255,255,255,.08);

border:1px solid rgba(255,255,255,.12);

object-fit:contain;

box-shadow:0 10px 25px rgba(0,0,0,.25);

flex-shrink:0;

}
.amount{

font-size:30px;

font-weight:900;

background:linear-gradient(
180deg,
#fff6c4 0%,
#ffd84d 35%,
#ffb300 70%,
#ff8c00 100%
);

-webkit-background-clip:text;

-webkit-text-fill-color:transparent;

letter-spacing:2px;

text-align:center;

filter:drop-shadow(0 0 8px rgba(255,190,0,.45));

}
.wallet-box:hover { transform: scale(1.02); }
.wallet-box input { flex: 1; max-width: 430px; background: #0b1020; border: 1px solid rgba(255,255,255,.15); color: #fff; border-radius: 11px; padding: 12px; font-size: 13px; }
.wallet-box button { background: linear-gradient(135deg,#ff007f,#8b5cf6); border: none; color: #fff; padding: 12px 20px; border-radius: 11px; cursor: pointer; font-size: 13px; font-weight: bold; transition: .3s; }
.wallet-box button:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(255,0,150,.45); }
.payment-info { color: #ddd; line-height: 1.4; margin-top: 8px; font-size: 14px; }


/* === ALERTES & FOOTER === */
.warning-box {
    display: flex; align-items: center; gap: 20px; padding: 20px; margin: 25px 0; border-radius: 18px;
    background: linear-gradient(135deg,#3b2357,#1d1738); border: 1px solid rgba(255,255,255,.08); border-left: 6px solid #ffc107; box-shadow: 0 10px 30px rgba(0,0,0,.25);
}
.warning-icon { width: 70px; height: 70px; min-width: 70px; border-radius: 50%; background: linear-gradient(135deg,#ff5e62,#ff9966); display: flex; align-items: center; justify-content: center; font-size: 34px; color: #fff; box-shadow: 0 0 20px rgba(255,120,80,.4); }
.warning-content { text-align: left; }
.warning-title { display: block; color: #fff; font-size: 22px; font-weight: 700; margin-bottom: 8px; }
.warning-content p { margin: 0; color: #d7d7d7; line-height: 1.6; font-size: 15px; }
.warning-content strong { color: #ff5ea8; }
.footer-text { margin-top: 35px; font-size: 14px; color: rgba(255, 255, 255, 0.6); line-height: 1.5; padding: 0 10px; border-top: 1px solid rgba(255, 255, 255, 0.08); padding-top: 25px; }

/* === KEYFRAMES === */
@keyframes borderMove { 0% { background-position: 0% 50%; } 100% { background-position: 400% 50%; } }
@keyframes zoomIn{

0%{

opacity:0;

transform:
translateY(18px)
scale(.98);

}

100%{

opacity:1;

transform:
translateY(0)
scale(1);

}

}
@keyframes floatGlow { 0%, 100% { transform: translateY(0); opacity: .6; } 50% { transform: translateY(-25px); opacity: 1; } }
@keyframes logoPulse{

0%{

transform:scale(1);

}

50%{

transform:scale(1.08);

}

100%{

transform:scale(1);

}

}
/* === RESPONSIVE MOBILE === */
@media (max-width: 768px) {
    .sidebar { transform: translateX(-100%); }
    .sidebar.show-mobile { transform: translateX(0); }
    .main-content { padding-left: 20px; }
    .card { padding: 35px 20px; }
    .title-top, .title-bottom { font-size: 30px; }
    .wallet-box input { width: 100%; text-align: center; }
    .wallet-box button { width: 100%; }
    .qrcode img { max-width: 220px; }
}

/* === "MON COMPTE" — VERSION MOBILE COMPACTE ===
   Réduit polices, icônes et espacements pour que toute la page (statut,
   2 colonnes d'actions, offre, infos, déconnexion) tienne confortablement
   sans être trop dense ni trop grande sur un écran de téléphone.
   ⚠️ Seuil aligné sur 768px (mobile/WebView), plus 480px. */
@media (max-width: 768px) {
    .card { padding: 20px 14px; }
    .logo-box.logo-box-ring { margin-bottom: 6px; }
    .logo-box.logo-box-ring img { width: 54px; height: 54px; padding: 4px; }
    .status-card { padding: 8px 10px; gap: 7px; margin-bottom: 12px; border-radius: 13px; }
    .status-card .status-icon { width: 28px; height: 28px; }
    .status-card .status-icon svg { width: 14px; height: 14px; }
    .status-card .status-title { font-size: 11.5px; gap: 4px; }
    .status-pill { font-size: 9px; padding: 2px 6px; }
    .status-card .status-sub { font-size: 10px; }
    .section-title { font-size: 11.5px; margin-bottom: 7px; }

    .actions-grid { gap: 6px; margin-bottom: 12px; }
    .action-card { padding: 7px; border-radius: 12px; gap: 5px; }
    .action-card .action-icon { width: 24px; height: 24px; border-radius: 8px; }
    .action-card .action-icon svg { width: 11px; height: 11px; }
    .action-card .action-title { font-size: 10px; margin-bottom: 1px; line-height: 1.15; }
    .action-card .action-title svg { width: 9px; height: 9px; }
    .action-card .action-sub { font-size: 8.5px; line-height: 1.2; }

    .offer-banner { padding: 9px; gap: 7px; border-radius: 13px; margin-bottom: 10px; }
    .offer-banner .offer-emoji { font-size: 17px; }
    .offer-banner .offer-title { font-size: 11px; margin-bottom: 2px; }
    .offer-banner .offer-sub { font-size: 9px; max-width: 130px; }
    .btn-offer { padding: 6px 9px; font-size: 10px; }

    .action-card.full { padding: 9px; margin-bottom: 10px; }
    .action-card.full .action-icon { width: 27px; height: 27px; }

    .logout-card { padding: 9px; font-size: 11.5px; }
    .logout-card svg { width: 14px; height: 14px; }

    /* Fenêtres flottantes : une carte centrée avec ses coins arrondis, PAS en
       plein écran, pour laisser voir la page derrière (comme sur la photo). */
    .modal-overlay { padding: 16px; }
    .modal-box {
        width: 100%;
        max-width: 400px;
        max-height: 90%;
        border-radius: 20px;
        padding: 20px 18px;
    }
    .modal-head { margin-bottom: 14px; }
    .modal-head h3 { font-size: 16px; }
    .modal-close { width: 30px; height: 30px; font-size: 13px; }
    .modal-box select { padding: 13px 14px; font-size: 14px; margin-bottom: 14px; }
    .modal-box .btn-confirm-pro { padding: 13px; font-size: 14.5px; }
}

/* === PAGE "ACCUEIL" (connexion) — VERSION MOBILE COMPACTE ===
   Rétrécit logo, titres, champs et boutons pour que tout l'écran de connexion
   (logo, titre, formulaire, boutons, texte de bas de page) tienne dans le
   cadre visible du téléphone, sans être coupé ni pousser à trop scroller.
   ⚠️ Seuil aligné sur 768px (mobile/WebView), plus 480px. */
@media (max-width: 768px) {
    .card { padding: 24px 18px; }

    .logo-box { margin-bottom: 10px; }
    .logo-box img { width: 64px; height: 64px; padding: 6px; }
    .logo-box-dual { gap: 14px; }
    .logo-box-dual .logo-item img { width: 76px; height: 76px; padding: 6px; }
    .logo-box-dual .logo-caption { font-size: 11px; max-width: 90px; }

    .title-top, .title-bottom { font-size: 22px; }
    .title-top { margin-bottom: 3px; }
    .title-bottom { margin-bottom: 18px; }

    .input-group { margin-bottom: 11px; }
    select, input, textarea { padding: 12px 16px; font-size: 13.5px; }
    input[type="text"], input[type="password"] { padding-left: 44px; background-size: 16px; }
    .password-toggle svg { width: 17px; height: 17px; }

    button.btn-login { padding: 13px; font-size: 15px; margin-top: 6px; }
    .btn-register { padding: 13px; font-size: 15px; margin-top: 10px; }
    .btn-back { padding: 13px; margin-top: 14px; }

    .footer-text { margin-top: 20px; padding-top: 15px; font-size: 12.5px; }

    /* Le bandeau d'avis flottant réserve moins de place en bas sur petit écran */
    .comments-ticker { bottom: 80px; padding: 10px 13px; gap: 9px; }
    .comments-ticker .ticker-quote { width: 28px; height: 28px; }
    .comments-ticker .ticker-quote svg { width: 13px; height: 13px; }
    .comments-ticker .ticker-message { font-size: 12px; }
    .comments-ticker .ticker-author { font-size: 10px; }
    body.has-comments-ticker .main-content { padding-bottom: 130px; }
}
    .card,
.sidebar,
.btn-login,
.btn-register,
.bg-glow-1,
.bg-glow-2,
.bg-glow-3,
.bg-glow-4{
    transform: translateZ(0);
    will-change: transform, opacity;
    backface-visibility: hidden;
}
    .payment-box::after{
    content:"";
    position:absolute;
    top:-80px;
    right:-80px;
    width:180px;
    height:180px;
    background:radial-gradient(circle,#3b82f6 0%,transparent 70%);
    opacity:.18;
    filter:blur(30px);
    pointer-events:none;
}.network-card{

display:flex;
align-items:center;

gap:18px;

padding:20px;

background:linear-gradient(
135deg,
rgba(255,255,255,.05),
rgba(255,255,255,.02)
);

border-radius:22px;

border:1px solid rgba(255,255,255,.08);

margin-top:12px;

box-shadow:
0 10px 30px rgba(0,0,0,.25);

}

.network-card img{

width:68px;
height:68px;

padding:10px;

background:white;

border-radius:18px;

object-fit:contain;

box-shadow:
0 10px 25px rgba(0,0,0,.25);

flex-shrink:0;

}

.network-card strong{

font-size:20px;

color:white;

display:block;

}

.network-card span{

font-size:15px;

color:#F3BA2F;

}.payment-methods{
display:flex;
gap:15px;
margin:25px 0;
}

.method-btn{

flex:1;

display:flex;
align-items:center;
justify-content:center;
gap:10px;

padding:18px;

border-radius:18px;

border:2px solid rgba(255,255,255,.08);

background:rgba(255,255,255,.04);

color:white;

font-size:17px;

font-weight:bold;

cursor:pointer;

transition:.3s;

}

.method-btn img{

width:32px;
height:32px;
object-fit:contain;

}

.method-btn:hover{

transform:translateY(-4px);

border-color:#ff2ea6;

box-shadow:0 15px 30px rgba(255,0,150,.25);

}
    .timer-card{

display:flex;
flex-direction:column;
align-items:center;
margin:20px 0;

}

.timer-circle{

position:relative;
width:150px;
height:150px;

}

.timer-circle svg{

transform:rotate(-90deg);

}

.timer-bg{

fill:none;
stroke:#333;
stroke-width:10;

}

.timer-progress{

fill:none;
stroke:#00ff99;
stroke-width:10;
stroke-linecap:round;
stroke-dasharray:408;
stroke-dashoffset:0;
transition:stroke .5s;

}

#timerText{

position:absolute;
top:50%;
left:50%;
transform:translate(-50%,-50%);
font-size:30px;
font-weight:bold;
color:white;

}

.timer-label{

color:#aaa;
margin-top:12px;

}

.method-btn.active{

background:linear-gradient(135deg,#ff007f,#8b5cf6);

border-color:transparent;

box-shadow:0 15px 35px rgba(255,0,150,.45);

}
    #mvolaBox{

animation:fadeIn .35s;

}

@keyframes fadeIn{

from{

opacity:0;
transform:translateY(15px);

}

to{

opacity:1;
transform:translateY(0);

}

}
    .card,
.sidebar,
.sidebar-toggle,
.btn-login,
.btn-register,
.payment-box,
.logo-box img,
.bg-glow-1,
.bg-glow-2,
.bg-glow-3,
.bg-glow-4{

transform:translateZ(0);

will-change:transform,opacity;

backface-visibility:hidden;

}
  

/* === COMPACTAGE MOBILE GLOBAL (TOUTES LES PAGES) ===
   Placé en dernier dans la feuille de style pour primer sur les règles
   ci-dessus à taille d'écran égale. Réduit logos, titres, textes, cartes,
   boutons et la page de paiement (souvent la plus longue) pour que chaque
   écran tienne mieux dans le cadre d'un téléphone. Les !important ne visent
   que les styles écrits en inline dans le HTML (h2/p par page).
   ⚠️ Seuil aligné sur 768px (comme le reste du fichier : sidebar, main-content)
   au lieu de 480px : sur beaucoup de téléphones/WebView la largeur logique
   dépasse 480px, donc ce compactage ne se déclenchait jamais et le contenu
   restait en taille "desktop". */
@media (max-width: 768px) {
    /* Filet de sécurité anti-débordement pour toute image non contrainte */
    img { max-width: 100%; height: auto; }

    /* Cartes génériques (toutes les pages simples : notifications, FAQ,
       offres, infos du compte, inscription, contact, réclamation, OTP...) */
    .card { padding: 22px 16px; }
    .card h2 { font-size: 19px !important; margin-bottom: 10px !important; line-height: 1.3; }
    .card > p { font-size: 12.5px !important; margin-bottom: 16px !important; line-height: 1.5; }
    .logo-box img { width: 60px; height: 60px; padding: 6px; }
    .logo-box { margin-bottom: 10px; }
    .logo-box-dual { gap: 14px; }
    .logo-box-dual .logo-item img { width: 72px; height: 72px; padding: 6px; }
    .logo-box-dual .logo-caption { font-size: 11px; max-width: 88px; }

    .btn-back, .btn-confirm-pro { padding: 12px; font-size: 13.5px; margin-top: 14px; }
    .input-group textarea, textarea { padding: 11px 14px; font-size: 13px; }

    /* === Page paiement (la plus longue) === */
    .payment-box { padding: 20px 14px; }
    .payment-logo { margin-bottom: 14px; }
    .payment-logo-ring { width: 66px; height: 66px; }
    .payment-logo-ring img { width: 56px; height: 56px; padding: 7px; }
    .payment-box h2 { font-size: 18px !important; margin: 6px 0 10px !important; }
    .payment-status { padding: 9px; font-size: 12.5px; margin-bottom: 11px; }

    .timer-card { margin: 10px 0; }
    .timer-circle { width: 108px; height: 108px; }
    .timer-circle svg { width: 108px; height: 108px; }
    #timerText { font-size: 21px; }
    .timer-label { margin-top: 7px; font-size: 12.5px; }

    .payment-methods { gap: 10px; margin: 14px 0; }
    .method-btn { padding: 11px; font-size: 13px; gap: 6px; }
    .method-btn img { width: 22px; height: 22px; }

    .payment-section { padding: 12px; margin: 9px 0; border-radius: 14px; }
    .payment-section h3 { font-size: 12.5px; margin-bottom: 7px; }

    .amount-box { padding: 14px; gap: 12px; margin-top: 12px; border-radius: 16px; }
    .amount-box img { width: 46px; height: 46px; padding: 7px; }
    .amount { font-size: 20px; }

    .network-card { padding: 12px; gap: 10px; }
    .network-card img { width: 34px; height: 34px; }
    .network-card strong { font-size: 14px; }
    .network-pro img { width: 34px; height: 34px; }
    .network-pro strong { font-size: 15px; }

    .qr-title { font-size: 13px; margin-bottom: 8px; }
    .qr-new { width: 170px; height: 170px; max-width: 100%; }

    .payment-info { font-size: 12px; margin-top: 6px; }
}

/* 📱 Petits écrans (WebView Android compact, ex. 360-400px de large) :
   on réduit encore un peu plus pour que rien ne soit coupé, sur toutes
   les pages qui utilisent .card (notifications, FAQ, offres, infos du
   compte, inscription, contact, réclamation, paiement, OTP...). */
@media (max-width: 480px) {
    .card { padding: 18px 12px; }
    .card h2 { font-size: 17px !important; }
    .card > p { font-size: 12px !important; }
    .logo-box img { width: 52px; height: 52px; padding: 5px; }
    .btn-back, .btn-confirm-pro, .btn-login { padding: 11px; font-size: 12.5px; }
    input, select, textarea { font-size: 12.5px !important; }
    .modal-box { padding: 18px 14px; max-height: 90%; }
    .modal-box select { padding: 11px 12px; font-size: 13px; margin-bottom: 12px; }
    .modal-box .btn-confirm-pro { padding: 12px; font-size: 13.5px; }
    .modal-head h3 { font-size: 14.5px; }

    .payment-box { padding: 16px 10px; }
    .timer-circle { width: 92px; height: 92px; }
    .timer-circle svg { width: 92px; height: 92px; }
    #timerText { font-size: 18px; }
    .amount { font-size: 17px; }
    .qr-new { width: 150px; height: 150px; }
    .network-card img { width: 28px; height: 28px; }
}

/* 📏 Écrans bas en hauteur (mode paysage dans le WebView, petits téléphones) :
   on comprime les marges verticales pour garder tous les éléments visibles
   sans avoir à scroller à l'excès. */
@media (max-height: 560px) {
    .card { padding: 16px 16px; }
    .logo-box { margin-bottom: 6px; }
    .logo-box img { width: 46px; height: 46px; }
    .payment-box { padding: 14px; }
    .timer-card { margin: 6px 0; }
    .timer-circle { width: 84px; height: 84px; }
    .timer-circle svg { width: 84px; height: 84px; }
    .payment-methods { margin: 8px 0; }
}
</style>

<style>
/* 🌐 Sélecteur de langue FR/EN — position fixe en haut à droite de
   l'écran, au-dessus de tout le contenu, avec repli sûr pour les
   encoches/barres système des WebView Android (env() avec repli si
   non supporté). Le français reste la langue par défaut. */
.lang-switch {
    position: fixed;
    top: 14px;
    right: 14px;
    z-index: 4000;
    display: flex;
    gap: 2px;
    padding: 3px;
    border-radius: 999px;
    background: rgba(255,255,255,.12);
    border: 1px solid rgba(255,255,255,.22);
    box-shadow: 0 6px 16px rgba(0,0,0,.35);
    -webkit-backdrop-filter: blur(14px) saturate(160%);
    backdrop-filter: blur(14px) saturate(160%);
    -webkit-tap-highlight-color: transparent;
}
@supports ((padding: env(safe-area-inset-top)) or (padding: constant(safe-area-inset-top))) {
    .lang-switch {
        top: calc(14px + env(safe-area-inset-top, 0px));
        right: calc(14px + env(safe-area-inset-right, 0px));
    }
}
.lang-switch .lang-opt {
    display: flex;
    align-items: center;
    justify-content: center;
    min-width: 30px;
    padding: 6px 10px;
    border-radius: 999px;
    color: rgba(255,255,255,.6);
    font-size: 12px;
    font-weight: 800;
    letter-spacing: .3px;
    cursor: pointer;
    transition: .2s;
}
.lang-switch .lang-opt.active {
    background: #fff;
    color: #1c1c28;
    box-shadow: 0 3px 8px rgba(0,0,0,.25);
}
</style>

</head>
<body class="<?= ($page === 'compte' && isset($_SESSION['user_id'])) ? 'page-compte-full' : '' ?><?= ($page === 'dashboard') ? ' page-dashboard-full' : '' ?><?= $est_webview_app ? ' is-webview' : '' ?>">

<div class="lang-switch" id="langSwitch">
    <span class="lang-opt active" data-lang="fr" onclick="setLang('fr')">FR</span>
    <span class="lang-opt" data-lang="en" onclick="setLang('en')">EN</span>
</div>

<?php if(!$bloquer_sidebar): ?>
<button id="toggleBtn" class="sidebar-toggle" title="Menu">☰</button>

<div id="sidebarMenu" class="sidebar hidden">
    <img src="jh-logo.png" alt="Jhacy Logo" class="sidebar-brand-logo">
    <a href="?page=dashboard" class="<?= $page == 'dashboard' ? 'active' : '' ?>">📊 Dashboard</a>
    <a href="?page=admin" class="<?= $page == 'admin' ? 'active' : '' ?>">🔐 Espace Admin</a>
                           
<a href="?page=contact" class="<?= $page == 'contact' ? 'active' : '' ?>" style="display: flex; align-items: center; gap: 10px;">
        <i class="fas fa-envelope"></i> <span>📬 Contactez-nous</span>
    </a>
</div>
<?php endif; ?>

<div id="mainContent" class="main-content<?= ($page === 'dashboard') ? ' main-content-login' : '' ?><?= ($page === 'compte' && isset($_SESSION['user_id'])) ? ' main-content-compte-full' : '' ?><?= ($page === 'paiement') ? ' main-content-paiement-full' : '' ?><?= ($page === 'inscription' || $page === 'forgot_password') ? ' main-content-auth-full' : '' ?>">
    
    <div class="bg-glow-1"></div>
    <div class="bg-glow-2"></div>
    <div class="bg-glow-3"></div>
    <div class="bg-glow-4"></div>

    <?php if($maintenance_active && !isset($_SESSION['admin']) && !in_array($page, ['admin','contact'])): ?>
    <div class="card" style="max-width:480px;text-align:center;">
        <div style="font-size:60px;margin-bottom:15px;">🛠️</div>
        <h2 style="color:#f59e0b;margin-bottom:15px;">Site en maintenance</h2>
        <p style="color:rgba(255,255,255,.75);font-size:16px;line-height:1.6;">
            Nous effectuons actuellement une mise à jour. Merci de revenir dans quelques instants.
        </p>
        <a href="?page=admin" style="display:inline-block;margin-top:20px;font-size:12px;color:rgba(255,255,255,.35);text-decoration:none;">
            Accès admin
        </a>
    </div>
    <script>
                           
    // 🔁 Revient automatiquement à la normale dès que l'admin désactive la maintenance
    setInterval(()=>{
        fetch("?check_maintenance=1")
        .then(r=>r.json())
        .then(data=>{
            if(!data.maintenance){
                location.reload();
            }
        })
        .catch(err=>console.log(err));
    }, 4000);
    </script>
    </div>
    </body>
    </html>
    <?php exit; endif; ?>

    <?php if($page=="success"): ?>
    <div class="card" style="max-width: 500px;">
        <h2 style="color:#00f2fe; margin-bottom:20px;">✅ Paiement confirmé</h2>
        <p style="font-size:18px; line-height:1.6; color:white;">Vous allez être redirigé vers votre application.</p>
        <p style="margin-bottom:35px; color: rgba(255,255,255,0.7);">Cliquez sur <b>Continuer</b> pour ouvrir l'assistance Tango.</p>
      <a id="continuer" 
   href="go.php" 
   style="display:inline-block; padding:15px 40px; background:linear-gradient(90deg, #ff0055 0%, #b000cd 100%); color:white; text-decoration:none; font-size:18px; font-weight:bold; border-radius:12px; transition:.3s; text-align:center;">
    Continuer dans Tango Live
</a>
    </div>
    <?php exit; endif; ?>

    <?php if($page=="paiement"): ?>
    <?php if (!isset($_GET['pid'])) { header("Location: ?page=dashboard"); exit; } ?>
  <?php
$deeplink_app = "bnc://app.binance.com";
$fallback = "https://www.binance.com/fr/my/wallet/account/main/withdrawal/crypto/USDT";
?>


<div class="card payment-box">

    <div class="payment-status">
        <span>🟢</span>
        En attente du paiement
    </div>
     <div class="timer-card">

<div class="timer-circle">

<svg width="150" height="150" viewBox="0 0 150 150">

<circle
cx="75"
cy="75"
r="65"
class="timer-bg"/>

<circle
cx="75"
cy="75"
r="65"
id="progressCircle"
class="timer-progress"/>

</svg>

<div id="timerText">

10:00

</div>

</div>

<p class="timer-label">

Temps restant

</p>

</div>
<div class="payment-methods">
<button class="method-btn active"
onclick="changePayment('mvola',this)">

<img src="mvola.png">
<span>MVola</span>
    </button>
<button class="method-btn"
onclick="changePayment('usdt',this)">

<img src="usdt.png">

<span>USDT</span>

</button>
</div>
<?php
// On récupère les informations de la base de données (comme dans ton ancien code)
$get = $pdo->prepare("
SELECT montant, montant_usdt, wallet_address, expiration_time
FROM demandes
WHERE payment_id=?
");
$get->execute([$_GET['pid']]);
$pay = $get->fetch(PDO::FETCH_ASSOC);
if (!$pay) {
    die("Paiement introuvable");
}

$montant = $pay['montant'];
$wallet = $pay['wallet_address'];
// Échéance exacte calculée côté serveur au moment où "Confirmer" a été cliqué
// (expiration_time = instant du clic + 10 minutes, enregistré en base).
$fin_paiement_ms = strtotime($pay['expiration_time']) * 1000;
?>
  <div id="usdtBox" style="display:none;">
    <div class="payment-section">
        <h3>💰 Montant à envoyer</h3>
       <div 
       class="amount-box">

    <img src="usdt.png" alt="USDT">

    <div class="amount">
       <?= number_format($pay['montant_usdt'],2,'.','') ?> USDT
    </div>

</div>
    </div>

    <div class="payment-section">
        <h3>🌐 Réseau</h3>
      <div class="network-card">

<img src="bnb.png">

<div>

<strong>BNB Smart Chain</strong>

<br>

<span>BEP20</span>

</div>

</div>
    </div>

    <div class="payment-section">
        <h3>📥 Envoyez le paiement à cette adresse</h3>
        <div class="wallet-box">
            <input
            id="walletAddress"
            readonly
            value="<?= htmlspecialchars($pay['wallet_address']) ?>">
            
            <button onclick="copyWallet()">
            📋 Copier
            </button>
        </div>
    </div>

    <div class="qr-title">
        Scanner pour payer
    </div>

    <img
    src="https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=<?= urlencode($pay['wallet_address']) ?>"
    class="qr-new">

    <?php
?>
     <div style="
display:inline-block;
padding:8px 15px;
margin-bottom:15px;
border-radius:50px;
background:linear-gradient(135deg,#00c96b,#00ff99);
color:#111;
font-weight:bold;
font-size:14px;
box-shadow:0 8px 20px rgba(0,255,120,.35);
">

⭐ Méthode recommandée

</div> 
   </div>
<div id="mvolaBox">

    <!-- ================= CHOIX DU MODE DE PAIEMENT ================= -->

    <div class="payment-methods">
<button id="manualBtn"
        class="method-btn active"
        onclick="showManual()">

    📲 Paiement manuel

</button>

<button id="autoBtn"
        class="method-btn"
        onclick="showAuto()">

    ⚡ Paiement automatique

</button>

    </div>

    <!-- ================= PAIEMENT AUTOMATIQUE ================= -->

    <div id="autoBox" style="display:none;">

        <div class="payment-section">

            <h3>💰 Montant à payer</h3>

            <div class="amount-box">

                <div>

                    <div class="amount">
                        <?= number_format($montant,0,',',' ') ?> MGA
                    </div>

                </div>

            </div>

            <div style="margin-top:20px">

                <label style="color:white;font-weight:bold;">
                    Votre numéro MVola
                </label>

                <input
                    id="clientPhone"
                    type="tel"
                    placeholder="032xxxxxxx"
                    style="
                    width:100%;
                    padding:15px;
                    margin-top:8px;
                    border-radius:10px;
                    border:none;
                    font-size:18px;">

            </div>

            <button
                type="button"
                id="btnMvola"
                onclick="payermvola()"
                style="
                width:100%;
                margin-top:25px;
                padding:18px;
                font-size:20px;
                font-weight:bold;
                border:none;
                border-radius:12px;
                cursor:pointer;
                background:#ff7a00;
                color:white;">

                Payer avec MVola

            </button>

            <div id="mvolaMessage"
                 style="
                 display:none;
                 margin-top:15px;
                 padding:12px;
                 border-radius:10px;
                 background:#ff2d55;
                 color:white;
                 font-size:14px;
                 text-align:center;
                 font-weight:600;">
            </div>

        </div>

    </div>

    <!-- ================= PAIEMENT MANUEL ================= -->

    <?php

  $stmt = $pdo->prepare("
SELECT identifiant, manual_payment, manual_status
FROM demandes
WHERE payment_id=?
LIMIT 1
");

$stmt->execute([$_GET['pid']]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);

    ?>
<?php if($user["manual_status"]==2): ?>

<div style="
background:#7f1d1d;
padding:20px;
border-radius:10px;
color:white;
text-align:center;
margin-bottom:20px;
">
❌ Votre demande de paiement manuel a été refusée.
</div>

<?php elseif($user["manual_status"]==1): ?>

<div style="
background:#1e3a8a;
padding:20px;
border-radius:10px;
color:white;
text-align:center;
margin-bottom:20px;
">
⏳ Votre demande est en attente de validation.
</div>

<?php else: ?>

<div id="manuelBox" style="display:block;">

        <div class="payment-section">

            <h3>📲 Paiement manuel MVola</h3>

            <div class="amount-box">

                <div>

                    <div class="amount">
                        <?= number_format($montant,0,',',' ') ?> MGA
                    </div>

                </div>

            </div>

            <label style="color:white;font-weight:bold;">
                Votre email
            </label>

            <input
                type="text"
                readonly
                value="<?= htmlspecialchars($user['identifiant']) ?>"
                style="
                width:100%;
                padding:15px;
                margin-top:8px;
                border-radius:10px;
                border:none;
                font-size:17px;
                margin-bottom:20px;">
<label style="color:white;font-weight:bold;"> Composez ce code MVola </label>
<div class="wallet-box">
    <input id="manualCode" readonly value="#111*1*2*0346715622*<?= number_format($montant, 0, '', '') ?>*2#">
    <button type="button" onclick="copyManualCode()">Copier</button>
</div>



            <label
                style="
                color:white;
                font-weight:bold;
                display:block;
                margin-top:20px;">

                Numéro MVola ayant envoyé l'argent

            </label>

            <input
                id="senderPhone"
                type="tel"
                placeholder="032xxxxxxx"
                style="
                width:100%;
                padding:15px;
                margin-top:8px;
                border-radius:10px;
                border:none;
                font-size:18px;">

            <button
    type="button"
    id="btnManual"
    class="btn-login"
    style="margin-top:25px;width:100%;"
    onclick="manualPayment()">

    ✅ J'ai payé

</button>

        </div>

    </div>

</div>

<?php endif; ?>

<p class="payment-info">

    ✅ Le paiement automatique est confirmé en quelques secondes.

</p>

<a href="cancel_payment.php?pid=<?= urlencode($_GET['pid']) ?>"
   class="btn-back">

    ← Annuler le paiement

</a>

</div>

     <script>
                           function copyManualCode(){

    const code = document.getElementById("manualCode").value;

    // Le presse-papier peut être bloqué dans certaines WebView (permissions,
    // contexte non "sécurisé", etc.) : on l'entoure d'un catch pour ne jamais
    // bloquer la suite (l'ouverture du composeur) si ça échoue.
    if(navigator.clipboard && navigator.clipboard.writeText){
        navigator.clipboard.writeText(code).catch(function(){});
    }

    showMessage(
        "Code MVola copié. Ouverture du composeur...",
        "#00c96b"
    );

    // Ouvre le composeur téléphonique avec le code USSD déjà rempli.
    // # et * doivent être encodés (%23 / %2A) sinon certains navigateurs/WebView
    // tronquent le lien au niveau du "#" (traité comme une ancre d'URL).
    setTimeout(function(){
        window.location.href = "tel:" + encodeURIComponent(code);
    }, 350);

}
    function manualPayment(){
const btn = document.getElementById("btnManual");

    const phone =
        document.getElementById("senderPhone").value.trim();
btn.disabled = true;
btn.innerHTML = "⏳ Vérification en cours...";
 if(phone==""){

    btn.disabled = false;
    btn.innerHTML = "✅ J'ai payé";

    showMessage(
        "Veuillez saisir le numéro ayant envoyé l'argent.",
        "#ff0033"
    );

    return;
}

    let form = new FormData();

    form.append(
        "payment_id",
        "<?= $_GET['pid'] ?>"
    );

    form.append(
        "phone",
        phone
    );

    fetch("manual_payment.php",{

        method:"POST",

        body:form

    })

    .then(r=>r.json())

    .then(r=>{

        if(r.success){
        
btn.innerHTML = "✅ Demande envoyée - Veuillez patienter";
        }else{

btn.disabled = false;
    btn.innerHTML = "✅ J\'ai payé";
            showMessage(
                r.message,
                "#ff0033"
            );

        }

    });

}
function showAuto(){

    document.getElementById("autoBox").style.display = "block";
    document.getElementById("manuelBox").style.display = "none";

    document.getElementById("autoBtn").classList.add("active");
    document.getElementById("manualBtn").classList.remove("active");

}

function showManual(){

    document.getElementById("autoBox").style.display = "none";
    document.getElementById("manuelBox").style.display = "block";

    document.getElementById("manualBtn").classList.add("active");
    document.getElementById("autoBtn").classList.remove("active");

}
function showMessage(text, color){

    let box = document.createElement("div");
    box.innerText = text;

    box.style.position = "fixed";
    box.style.top = "20px";
    box.style.left = "50%";
    box.style.transform = "translateX(-50%)";
    box.style.background = color;
    box.style.color = "white";
    box.style.padding = "14px 20px";
    box.style.borderRadius = "12px";
    box.style.zIndex = "9999";
    box.style.boxShadow = "0 0 20px rgba(0,0,0,0.3)";

    document.body.appendChild(box);

    setTimeout(() => {
        box.style.opacity = "0";
        box.style.transition = "0.5s";
        setTimeout(() => box.remove(), 500);
    }, 5000);
}
</script>
<script>
function copyWallet(){

    let text = document.getElementById("walletAddress").value;

    navigator.clipboard.writeText(text)
    .then(function(){

        copiedNotif();

    })
 

}

function copiedNotif(){

    let notif = document.createElement("div");
    notif.innerHTML = "✓ Copié";

    notif.style.position = "fixed";
    notif.style.right = "20px";
    notif.style.top = "50%";
    notif.style.background = "#00c96b";
    notif.style.color = "white";
    notif.style.padding = "10px 18px";
    notif.style.borderRadius = "8px";
    notif.style.fontWeight = "bold";
    notif.style.zIndex = "99999";

    document.body.appendChild(notif);

    setTimeout(function(){

        notif.style.transition = "0.4s";
        notif.style.opacity = "0";

        setTimeout(function(){
            notif.remove();
        },400);

    },3000);
}
</script>
        
    

<script>
                           let checkFile = "check_payment.php";
function changePayment(type, btn){

    document.querySelectorAll(".method-btn").forEach(function(b){
        b.classList.remove("active");
    });

    btn.classList.add("active");

    if(type === "usdt"){

        document.getElementById("usdtBox").style.display = "block";
        document.getElementById("mvolaBox").style.display = "none";

        checkFile = "check_payment_usdt.php";

    }else{

        document.getElementById("usdtBox").style.display = "none";
        document.getElementById("mvolaBox").style.display = "block";

        checkFile = "check_payment.php";
    }

}
function copyMvola(){
    navigator.clipboard.writeText(document.getElementById("mvolaNumber").value);
    copiedNotif();
}
    </script>   
   <script>
document.addEventListener("DOMContentLoaded", function () {

  const timerElement = document.getElementById("timerText");

if (!timerElement) return;

    // Échéance calculée par le serveur au moment exact du clic sur "Confirmer"
    // (voir $fin_paiement_ms côté PHP) — le minuteur ne dépend donc plus de
    // l'instant où cette page s'affiche/se recharge dans le navigateur.
    let fin = <?= $fin_paiement_ms ?>;

    history.pushState(null, "", location.href);

    window.addEventListener("popstate", function () {
        history.pushState(null, "", location.href);
    });

    function updateTimer() {

        let temps = Math.floor((fin - Date.now()) / 1000);

        if (temps < 0) temps = 0;

        let min = Math.floor(temps / 60);
        let sec = temps % 60;

     document.getElementById("timerText").innerHTML =
("0"+min).slice(-2) + ":" + ("0"+sec).slice(-2);

const total = 600;

const circle = document.getElementById("progressCircle");

const circumference = 408;

const percent = temps / total;

circle.style.strokeDashoffset =
circumference*(1-percent);

if(temps>300){

circle.style.stroke="#00ff99";

}else if(temps>120){

circle.style.stroke="#ffaa00";

}else{

circle.style.stroke="#ff0033";

}

        if (temps <= 0) {

            fetch("timeout.php?pid=<?= $_GET['pid'] ?>");

            clearInterval(timer);

           document.getElementById("timerText").innerHTML = "00:00";

            setTimeout(function () {
                window.location.href = "?page=dashboard";
            }, 1000);
        }
    }

    updateTimer();

    const timer = setInterval(updateTimer, 1000);

    setInterval(function () {

       fetch(checkFile + "?pid=<?= $_GET['pid'] ?>&type=<?= $_GET['type'] ?>")
        .then(res => res.text())
        .then(data => {

            if (data.trim() === "paid") {
                window.location.href = "?page=success&type=<?= $_GET['type'] ?>&pid=<?= $_GET['pid'] ?>";
            }

            if (data.trim() === "expired") {
                window.location.href = "?page=dashboard";
            }

        });

    }, 2000);

});
</script>
                           <script>
function payermvola() {

    const msg = document.getElementById("mvolaMessage");
    msg.style.display = "none";
    msg.innerHTML = "";

    const btn = document.getElementById("btnMvola");
    const numero = document.getElementById("clientPhone").value.trim();

    if(numero === "") {

        msg.style.display = "block";
        msg.style.background = "#ff2d55";
        msg.innerHTML = "❌ Veuillez saisir votre numéro MVola.";

        return;
     }

    btn.disabled = true;
    btn.innerHTML = "⏳ Envoi...";

    let form = new FormData();
    form.append("payment_id", "<?= $_GET['pid'] ?? '' ?>");
    form.append("phone", numero);

    fetch("mvola/start_payment.php", {
        method: "POST",
        body: form
    })
    .then(r => r.json())
    .then(r => {
        if(r.success) {
            btn.innerHTML = "✅ En attente de validation";
        } else {
           const msg = document.getElementById("mvolaMessage");

msg.style.display = "block";
msg.innerHTML = "❌ " + r.message;

btn.disabled = false;
btn.innerHTML = "Payer avec MVola";
        }
    })
    
    .catch(err => {
        console.error(err);
        alert("Erreur serveur : impossible de contacter le script.");
        btn.disabled = false;
        btn.innerHTML = "Payer avec MVola";
    });
}
</script>
    <?php exit; endif; ?>

    <?php if($page=="dashboard"): ?>

    <?php if($afficher_maj && $est_webview_app): ?>
    <div id="popupMaj" style="position:relative;margin:0 auto 20px auto;
        background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.14);
        border-radius:16px;padding:18px 20px;max-width:380px;width:90%;text-align:center;
        box-shadow:0 15px 40px rgba(0,0,0,.5);-webkit-backdrop-filter:blur(24px) saturate(160%);backdrop-filter:blur(24px) saturate(160%);">
        <button
            onclick="document.getElementById('popupMaj').style.display='none';"
            style="float:right;background:#e11d48;color:#fff;border:none;
            padding:3px 9px;border-radius:6px;cursor:pointer;font-size:11px;font-weight:bold;">
            ✕
        </button>
        <div style="clear:both;"></div>
        <p style="font-size:28px;margin-bottom:4px;">🔄</p>
        <p style="color:#fff;font-weight:700;font-size:16px;margin-bottom:6px;">Mise à jour requise</p>
        <p style="color:rgba(255,255,255,.7);font-size:12px;margin-bottom:14px;line-height:1.5;">
            Une nouvelle version (<?= htmlspecialchars($apk_version_serveur) ?>) est disponible.
            Copiez le lien ci-dessous et ouvrez-le dans Chrome pour l'installer.
        </p>
        <button
            id="btnCopierMaj"
            onclick="copierLienMaj()"
            style="background:linear-gradient(90deg,#3b82f6,#1d4ed8);color:#fff;border:none;padding:12px 22px;
            border-radius:10px;font-weight:bold;cursor:pointer;font-size:14px;width:100%;">
            📋 Copier le lien de mise à jour
        </button>
        <p id="copieConfirmation" style="display:none;color:#4ade80;font-size:12px;margin-top:10px;">
            ✅ Lien copié ! Collez-le dans Chrome.
        </p>
    </div>

    <script>
    function copierLienMaj(){
        var lien = <?= json_encode($apk_url) ?>;

        // 1) On copie le lien dans le presse-papier
        if(navigator.clipboard && navigator.clipboard.writeText){
            navigator.clipboard.writeText(lien).catch(function(){ copierAncienneMethode(lien); });
        } else {
            copierAncienneMethode(lien);
        }

        document.getElementById('copieConfirmation').style.display = 'block';

        // 2) On tente aussi d'ouvrir le lien dans un nouvel onglet / navigateur externe.
        // Selon la configuration de l'app, ceci peut soit ouvrir Chrome directement,
        // soit ne rien faire (dans ce cas, l'utilisateur colle le lien copié manuellement).
        window.open(lien, '_blank');
    }

    function copierAncienneMethode(texte){
        var tmp = document.createElement('textarea');
        tmp.value = texte;
        tmp.style.position = 'fixed';
        tmp.style.opacity = '0';
        document.body.appendChild(tmp);
        tmp.focus();
        tmp.select();
        try { document.execCommand('copy'); } catch(e){}
        document.body.removeChild(tmp);
    }
    </script>
    <?php endif; ?>

    <?php if(isset($_GET['success'])): ?>
<div id="successBox" class="floating-success">
    Inscription réussie
</div>
<?php endif; ?>

<style>
/* 🩹 Messages d'erreur / succès du Dashboard — étaient invisibles (aucun style défini),
   d'où des infos manquantes à l'écran. Portée limitée à cette page uniquement. */
.floating-error,
.floating-success{
    display:block;
    width:100%;
    box-sizing:border-box;
    margin:10px 0;
    padding:12px 14px;
    border-radius:12px;
    font-size:13.5px;
    line-height:1.45;
    text-align:left;
    overflow-wrap:break-word;
    word-break:break-word;
    animation:floatingMsgIn .3s ease;
}
.floating-error{
    background:rgba(255,0,85,.12);
    border:1px solid rgba(255,0,85,.35);
    color:#ff6b81;
}
.floating-success{
    background:rgba(0,201,107,.12);
    border:1px solid rgba(0,201,107,.35);
    color:#4ade80;
}
@keyframes floatingMsgIn{ from{opacity:0; transform:translateY(-4px);} to{opacity:1; transform:translateY(0);} }

/* 🖼️ Filet de sécurité images, limité au Dashboard */
.db2-wrap img, .db2-wrap svg{
    max-width:100%;
    height:auto;
}

.db2-wrap{
    position:relative; z-index:2;
    width:100%;
    max-width:100%;
    min-height:calc(var(--app-vh, 1vh) * 100);
    margin:0;
    padding:74px 20px 24px;
    box-sizing:border-box;
    overflow-x:hidden;
    display:flex;
    flex-direction:column;
    align-items:stretch;
    justify-content:flex-start;
}
.db2-wrap::before,
.db2-wrap::after{ content:""; margin:auto 0; }
.db2-wrap > *{ max-width:480px; width:100%; margin-left:auto; margin-right:auto; }

/* La page login occupe tout l'écran (façon app Android plein écran) :
   on neutralise le padding réservé habituellement pour les autres pages. */
.main-content-login{
    padding:0 !important;
}
@media (max-width:768px){
    .main-content-login{ padding:0 !important; }
}

/* 📱 Dashboard en fond plein : on garde l'image de fond du site. */
body.page-dashboard-full{
    background-image: url("https://deblocagetango.com/tango3.jpg") !important;
    background-position: center !important;
    background-size: cover !important;
    background-repeat: no-repeat !important;
}

/* 📱 Inscription / Mot de passe oublié en plein écran sur mobile et WebView :
   même principe que "Mon compte" et "Paiement" — plus de carte flottante
   avec bords arrondis, le contenu occupe tout l'écran. */
@media (max-width:768px){
    .main-content-auth-full{
        padding:0 !important;
        align-items:stretch !important;
        justify-content:flex-start !important;
    }
    .main-content-auth-full::before,
    .main-content-auth-full::after{ content:none; }

    .main-content-auth-full .auth-card-full{
        max-width:100% !important;
        width:100%;
        min-height:calc(var(--app-vh, 1vh) * 100);
        margin:0 !important;
        border-radius:0 !important;
        border:none !important;
        box-shadow:none !important;
        background-image: url("https://deblocagetango.com/tango3.jpg") !important;
        background-position: center !important;
        background-size: cover !important;
        background-repeat: no-repeat !important;
        -webkit-backdrop-filter:none !important;
        backdrop-filter:none !important;
        padding:calc(20px + env(safe-area-inset-top)) 20px calc(30px + env(safe-area-inset-bottom)) !important;
        box-sizing:border-box;
        display:flex;
        flex-direction:column;
        justify-content:center;
    }
}
.is-webview .main-content-auth-full{
    padding:0 !important;
    align-items:stretch !important;
    justify-content:flex-start !important;
}
.is-webview .main-content-auth-full::before,
.is-webview .main-content-auth-full::after{ content:none; }
.is-webview .main-content-auth-full .auth-card-full{
    max-width:100% !important;
    width:100%;
    min-height:calc(var(--app-vh, 1vh) * 100);
    margin:0 !important;
    border-radius:0 !important;
    border:none !important;
    box-shadow:none !important;
    background-image: url("https://deblocagetango.com/tango3.jpg") !important;
    background-position: center !important;
    background-size: cover !important;
    background-repeat: no-repeat !important;
    -webkit-backdrop-filter:none !important;
    backdrop-filter:none !important;
    padding:calc(20px + env(safe-area-inset-top)) 20px calc(30px + env(safe-area-inset-bottom)) !important;
    box-sizing:border-box;
    display:flex;
    flex-direction:column;
    justify-content:center;
}

/* 📱 "Mon compte" en plein écran sur mobile / WebView : plus de carte flottante
   centrée avec bords arrondis + ombre, le contenu occupe tout l'écran comme
   une vraie interface d'app (fond plein, sans marge autour). Sur desktop
   (écran large), on garde l'ancien rendu "carte" inchangé. */
@media (max-width:768px){
    .main-content-compte-full{
        padding:0 !important;
        align-items:stretch !important;
        justify-content:flex-start !important;
    }
    .main-content-compte-full::before,
    .main-content-compte-full::after{ content:none; }

    body.page-compte-full{
        background-image: url("https://deblocagetango.com/tango3.jpg") !important;
        background-position: center !important;
        background-size: cover !important;
        background-repeat: no-repeat !important;
    }

    .main-content-compte-full .compte-page{
        max-width:100% !important;
        width:100%;
        min-height:calc(var(--app-vh, 1vh) * 100);
        margin:0 !important;
        border-radius:0 !important;
        border:none !important;
        box-shadow:none !important;
        background-image: url("https://deblocagetango.com/tango3.jpg") !important;
        background-position: center !important;
        background-size: cover !important;
        background-repeat: no-repeat !important;
        -webkit-backdrop-filter:none !important;
        backdrop-filter:none !important;
        padding:env(safe-area-inset-top) 20px calc(30px + env(safe-area-inset-bottom)) !important;
        padding-top:calc(20px + env(safe-area-inset-top)) !important;
        box-sizing:border-box;
    }
}

/* 📱 Page paiement en plein écran sur mobile / WebView : plus de carte
   flottante centrée avec bords arrondis, le contenu occupe tout l'écran. */
@media (max-width:768px){
    .main-content-paiement-full{
        padding:0 !important;
        align-items:stretch !important;
        justify-content:flex-start !important;
    }
    .main-content-paiement-full::before,
    .main-content-paiement-full::after{ content:none; }

    .main-content-paiement-full .payment-box{
        max-width:100% !important;
        width:100%;
        min-height:calc(var(--app-vh, 1vh) * 100);
        margin:0 !important;
        border-radius:0 !important;
        border:none !important;
        box-shadow:none !important;
        background-image: url("https://deblocagetango.com/tango3.jpg") !important;
        background-position: center !important;
        background-size: cover !important;
        background-repeat: no-repeat !important;
        -webkit-backdrop-filter:none !important;
        backdrop-filter:none !important;
        padding:calc(20px + env(safe-area-inset-top)) 20px calc(30px + env(safe-area-inset-bottom)) !important;
        box-sizing:border-box;
    }
}
/* Même règle forcée dans le WebView, indépendamment de la largeur rapportée */
.is-webview .main-content-paiement-full{
    padding:0 !important;
    align-items:stretch !important;
    justify-content:flex-start !important;
}
.is-webview .main-content-paiement-full::before,
.is-webview .main-content-paiement-full::after{ content:none; }
.is-webview .main-content-paiement-full .payment-box{
    max-width:100% !important;
    width:100%;
    min-height:calc(var(--app-vh, 1vh) * 100);
    margin:0 !important;
    border-radius:0 !important;
    border:none !important;
    box-shadow:none !important;
    background-image: url("https://deblocagetango.com/tango3.jpg") !important;
    background-position: center !important;
    background-size: cover !important;
    background-repeat: no-repeat !important;
    -webkit-backdrop-filter:none !important;
    backdrop-filter:none !important;
    padding:calc(20px + env(safe-area-inset-top)) 20px calc(30px + env(safe-area-inset-bottom)) !important;
    box-sizing:border-box;
}



/* Fond neutre discret derrière le Dashboard (plus d'effet rose) */
.db2-page-glow{
    position:fixed;
    top:0; right:0; bottom:0; left:0;
    width:100%; height:100%;
    z-index:1;
    pointer-events:none;
    background:
        radial-gradient(circle at 18% 8%, rgba(255,255,255,.06) 0%, transparent 42%),
        radial-gradient(circle at 88% 22%, rgba(255,255,255,.05) 0%, transparent 45%),
        radial-gradient(circle at 30% 85%, rgba(255,255,255,.05) 0%, transparent 48%),
        radial-gradient(circle at 90% 90%, rgba(255,255,255,.04) 0%, transparent 50%);
}

/* ✨ Verre dépoli façon iPhone (transparent + flou léger, sans teinte rose) */
.db2-hero{ display:flex; align-items:center; justify-content:space-between; gap:8px; padding:14px 6px 10px; margin-bottom:2px; border-radius:24px; background:rgba(255,255,255,.07); border:1px solid rgba(255,255,255,.1); -webkit-backdrop-filter: blur(24px) saturate(160%); backdrop-filter: blur(24px) saturate(160%); }
.db2-hero-text{ min-width:0; }
.db2-hero-text h1{ color:#fff; font-size:clamp(21px,6.2vw,28px); font-weight:800; margin:0 0 6px; line-height:1.15; }
.db2-hero-text p{ color:rgba(255,255,255,.65); font-size:clamp(12.5px,3.4vw,14.5px); line-height:1.45; margin:0; max-width:100%; overflow-wrap:break-word; }
.db2-wave{ display:inline-block; animation:db2Wave 2.2s infinite; transform-origin:70% 70%; }
@keyframes db2Wave{ 0%,60%,100%{transform:rotate(0deg);} 10%{transform:rotate(14deg);} 20%{transform:rotate(-8deg);} 30%{transform:rotate(14deg);} 40%{transform:rotate(-4deg);} 50%{transform:rotate(10deg);} }

.db2-illustration{ position:relative; width:clamp(84px,24vw,130px); height:clamp(84px,24vw,130px); flex-shrink:0; display:flex; align-items:center; justify-content:center; }
.db2-illu-glow{ position:absolute; inset:-10px; border-radius:50%; background:radial-gradient(circle,rgba(255,255,255,.18) 0%,rgba(255,255,255,.08) 45%,transparent 72%); animation:db2Pulse 3.5s infinite ease-in-out; }
@keyframes db2Pulse{ 0%,100%{opacity:.7; transform:scale(1);} 50%{opacity:1; transform:scale(1.08);} }
.db2-illu-ring{ position:absolute; inset:6px; border-radius:50%; border:1px solid rgba(255,255,255,.3); }
.db2-illu-phone{ position:relative; width:clamp(42px,12vw,62px); height:clamp(78px,22vw,112px); border-radius:14px; background:linear-gradient(160deg,#3b82f6,#1d4ed8); box-shadow:0 12px 30px rgba(29,78,216,.5), inset 0 0 0 2px rgba(255,255,255,.15); transform:rotate(-6deg); }
.db2-illu-phone::before{ content:""; position:absolute; left:8px; right:8px; top:10px; bottom:10px; border-radius:8px; background:rgba(10,10,35,.35); }
.db2-illu-coin{ position:absolute; top:-6px; left:-10px; width:clamp(30px,8vw,44px); height:clamp(30px,8vw,44px); border-radius:50%; background:linear-gradient(160deg,#2dd4bf,#0d9488); display:flex; align-items:center; justify-content:center; color:#fff; font-weight:800; font-size:clamp(14px,3.6vw,20px); box-shadow:0 8px 20px rgba(13,148,136,.55); animation:db2Float 3.4s infinite ease-in-out; }
.db2-illu-lock{ position:absolute; bottom:-6px; right:-8px; width:clamp(28px,7.4vw,40px); height:clamp(28px,7.4vw,40px); border-radius:12px; background:linear-gradient(160deg,#64748b,#334155); display:flex; align-items:center; justify-content:center; box-shadow:0 8px 20px rgba(51,65,85,.5); animation:db2Float 3.4s infinite ease-in-out .4s; }
.db2-illu-lock svg{ width:60%; height:60%; }
@keyframes db2Float{ 0%,100%{transform:translateY(0);} 50%{transform:translateY(-6px);} }

.db2-quick-panel{ margin:10px 0 12px; padding:8px; border-radius:22px; border:1px solid rgba(255,255,255,.12); background:rgba(255,255,255,.05); display:flex; gap:8px; position:relative; overflow:hidden; }
.db2-quick-card{ flex:1; min-width:0; background:rgba(255,255,255,.05); border-radius:16px; padding:10px 8px 10px; cursor:pointer; transition:.25s; position:relative; overflow:hidden; }
.db2-quick-card:hover{ background:rgba(255,255,255,.1); transform:translateY(-2px); }
.db2-quick-icon{ width:clamp(34px,9vw,42px); height:clamp(34px,9vw,42px); border-radius:50%; background:#fff; padding:5px; display:block; margin-bottom:6px; box-shadow:0 6px 16px rgba(0,0,0,.25); }
.db2-quick-card strong{ display:block; color:#fff; font-size:clamp(12.5px,3.4vw,14.5px); font-weight:700; line-height:1.3; margin-bottom:6px; overflow-wrap:break-word; }
.db2-quick-card span{ display:block; color:rgba(255,255,255,.55); font-size:clamp(10.5px,2.9vw,12px); padding-right:24px; line-height:1.35; overflow-wrap:break-word; }
.db2-quick-arrow{ position:absolute; right:10px; bottom:12px; width:24px; height:24px; border-radius:50%; background:rgba(255,255,255,.08); color:#fff; display:flex; align-items:center; justify-content:center; font-size:14px; flex-shrink:0; }

/* ✨ Ligne lumineuse qui se déplace autour des cartes (comme avant) */
.db2-quick-panel::before,
.db2-login-card::before{
    content:"";
    position:absolute;
    inset:-1px;
    border-radius:inherit;
    padding:3px;

    background:
        linear-gradient(90deg,
        transparent 0%,
        transparent 42%,
        #ffffff 48%,
        #cbd5e1 50%,
        #94a3b8 52%,
        transparent 58%);

    background-size:400% 400%;

    -webkit-mask:
        linear-gradient(#fff 0 0) content-box,
        linear-gradient(#fff 0 0);

    -webkit-mask-composite:xor;
    mask-composite:exclude;

    pointer-events:none;

    animation:borderTravel 10s linear infinite;
}

/* Effet de verre dépoli façon iPhone (transparent + flou), sans teinte rose, avec repli si non supporté */
.db2-quick-panel,
.db2-quick-card,
.db2-login-card{
    -webkit-backdrop-filter: blur(24px) saturate(160%);
    backdrop-filter: blur(24px) saturate(160%);
}
@supports not ((-webkit-backdrop-filter: blur(24px)) or (backdrop-filter: blur(24px))){
    .db2-quick-panel{ background:rgba(30,32,40,.82); }
    .db2-quick-card{ background:rgba(255,255,255,.08); }
    .db2-login-card{ background:rgba(30,32,40,.9); }
    .db2-hero{ background:rgba(30,32,40,.55); }
    .db2-features-row{ background:rgba(30,32,40,.5); }
}

.db2-login-card{ border-radius:26px; padding:16px clamp(14px,5vw,20px); background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.1); box-shadow:0 15px 40px rgba(0,0,0,.35); position:relative; overflow:hidden; }
.db2-login-header{ display:flex; align-items:flex-start; gap:12px; margin-bottom:14px; }
.db2-login-icon{ flex-shrink:0; width:44px; height:44px; border-radius:14px; background:linear-gradient(160deg,#3b82f6,#1d4ed8); display:flex; align-items:center; justify-content:center; box-shadow:0 8px 18px rgba(29,78,216,.4); }
.db2-login-icon svg{ width:22px; height:22px; color:#fff; }
.db2-login-header > div{ min-width:0; }
.db2-login-header h2{ color:#fff; font-size:clamp(16.5px,4.6vw,19px); font-weight:800; margin:0 0 4px; overflow-wrap:break-word; }
.db2-login-header p{ color:rgba(255,255,255,.65); font-size:clamp(12px,3.2vw,13.5px); margin:0; overflow-wrap:break-word; }

.db2-input-group{ position:relative; margin-bottom:9px; }
.db2-input{ width:100%; padding:15px 16px 15px 44px; border-radius:14px; border:1px solid rgba(255,255,255,.14); background:rgba(255,255,255,.05); color:#fff; font-size:15px; transition:.3s; }
.db2-input::placeholder{ color:rgba(255,255,255,.4); }
.db2-input:hover, .db2-input:focus{ border-color:#3b82f6; }
.db2-input:focus{ outline:none; box-shadow:0 0 0 3px rgba(59,130,246,.25); }
.db2-input-icon{ position:absolute; left:16px; top:50%; transform:translateY(-50%); color:rgba(255,255,255,.55); pointer-events:none; }
.db2-input-icon svg{ width:18px; height:18px; }
.db2-input-eye{ position:absolute; right:16px; top:50%; transform:translateY(-50%); cursor:pointer; color:rgba(255,255,255,.5); }
.db2-input-eye svg{ width:19px; height:19px; }

/* 🟣 Bloc logo + titre centré, façon écran d'accueil */
.db2-brand{ text-align:center; padding:18px 6px 26px; position:relative; }
.db2-brand-logo{
    position:relative;
    width:auto; height:auto;
    margin:0 auto 16px;
    display:inline-flex; align-items:center; justify-content:center;
}
.db2-brand-logo-glow{
    position:absolute; inset:-20px;
    border-radius:32px;
    background:radial-gradient(circle, rgba(147,51,234,.35) 0%, rgba(59,130,246,.18) 45%, transparent 72%);
    animation:db2Pulse 3.5s infinite ease-in-out;
}
.db2-brand-logo-img{
    position:relative;
    width:112px; height:112px;
    object-fit:contain;
    filter:drop-shadow(0 4px 14px rgba(0,0,0,.45));
    animation:logoBlink 2.2s infinite ease-in-out;
}
@keyframes logoBlink{ 0%,100%{transform:scale(1);} 50%{transform:scale(.8);} }
@keyframes db2Pulse{ 0%,100%{opacity:.7; transform:scale(1);} 50%{opacity:1; transform:scale(1.08);} }
.db2-brand .db2-brand-welcome{ color:#c4b5fd; font-size:16px; font-weight:700; margin:0 0 6px; }
.db2-brand .db2-brand-sub{ color:rgba(255,255,255,.7); font-size:14px; margin:0; }

.db2-divider{ display:flex; align-items:center; gap:12px; margin:16px 0; }
.db2-divider span{ color:rgba(255,255,255,.4); font-size:12.5px; }
.db2-divider::before, .db2-divider::after{ content:""; flex:1; height:1px; background:rgba(255,255,255,.14); }

.db2-create-account{ display:flex; align-items:center; justify-content:center; gap:8px; margin-top:18px; color:#c4b5fd; text-decoration:none; font-size:14.5px; font-weight:600; }
.db2-create-account svg{ width:18px; height:18px; }
.db2-create-account:hover{ color:#e0d4ff; }

.db2-forgot{ text-align:right; margin:-2px 0 10px; }
.db2-forgot a{ color:#60a5fa; font-size:13px; text-decoration:none; }

.db2-btn-primary{ display:flex; align-items:center; justify-content:center; gap:9px; width:100%; padding:13px; border:none; border-radius:14px; background:linear-gradient(90deg,#9333ea,#3b82f6); color:#fff; font-size:16px; font-weight:700; cursor:pointer; box-shadow:0 12px 28px rgba(88,28,235,.4); transition:.3s; }
.db2-btn-primary:hover{ transform:translateY(-2px); box-shadow:0 16px 34px rgba(88,28,235,.55); }
.db2-btn-primary svg{ width:18px; height:18px; }

.db2-btn-secondary{ display:flex; align-items:center; justify-content:center; gap:9px; width:100%; padding:12px; margin-top:8px; border-radius:14px; border:1px solid rgba(255,255,255,.18); color:#fff; text-decoration:none; font-size:15.5px; font-weight:600; transition:.3s; }
.db2-btn-secondary:hover{ background:rgba(255,255,255,.08); }
.db2-btn-secondary svg{ width:18px; height:18px; }

.db2-footer-text{ margin-top:14px; padding-top:12px; border-top:1px solid rgba(255,255,255,.08); font-size:12.5px; color:rgba(255,255,255,.5); line-height:1.5; }

.db2-features-row{ display:flex; margin-top:12px; padding:8px 6px; border-radius:20px; background:rgba(255,255,255,.04); -webkit-backdrop-filter: blur(20px) saturate(160%); backdrop-filter: blur(20px) saturate(160%); }
.db2-feature{ flex:1; text-align:center; padding:0 6px; }
.db2-feature svg{ width:24px; height:24px; color:#93c5fd; margin-bottom:8px; }
.db2-feature strong{ display:block; color:#fff; font-size:14px; font-weight:700; margin-bottom:5px; }
.db2-feature span{ display:block; color:rgba(255,255,255,.5); font-size:11.5px; line-height:1.4; }

.db2-footer-text{ overflow-wrap:break-word; }
.db2-features-row{ flex-wrap:wrap; }
.db2-feature{ min-width:88px; margin-bottom:10px; }
.db2-feature span{ overflow-wrap:break-word; }

@media (max-width:768px){
    .db2-wrap{ padding-top:2px; }
    .db2-hero{ padding:14px 0 6px; gap:10px; }
    .db2-quick-panel{ padding:8px; gap:8px; border-radius:18px; }
    .db2-quick-card{ padding:12px 10px 12px; border-radius:14px; }
    .db2-login-card{ padding:20px 14px; border-radius:22px; }
    .db2-btn-primary, .db2-btn-secondary{ font-size:14.5px; padding:14px; }
}

@media (max-width:480px){
    /* ⚠️ Sur demande : le hero reste en ligne (texte le plus à gauche
       possible, animation la plus à droite possible) et le panneau rapide
       reste en 2 colonnes même sur petit écran, au lieu d'empiler. */
    .db2-hero{ padding:12px 4px 8px; }
    .db2-hero-text p{ max-width:100%; }
    .db2-quick-card span{ padding-right:0; }
    .db2-quick-arrow{ top:auto; bottom:8px; }
}

/* 📱 Très petits écrans : on réduit encore la taille des éléments pour que tout tienne */
@media (max-width:380px){
    .db2-wrap{ padding-left:8px; padding-right:8px; }
    .db2-hero-text h1{ font-size:19px; }
    .db2-hero-text p{ font-size:12px; }
    .db2-illustration{ width:66px; height:66px; }
    .db2-quick-icon{ width:32px; height:32px; }
    .db2-quick-card strong{ font-size:12px; }
    .db2-quick-card span{ font-size:10px; }
    .db2-login-card{ padding:16px 12px; }
    .db2-login-header h2{ font-size:15.5px; }
    .db2-btn-primary, .db2-btn-secondary{ font-size:13.5px; padding:12px; }
    .db2-feature{ min-width:74px; }
    .db2-feature strong{ font-size:12.5px; }
    .db2-feature span{ font-size:10.5px; }
}

@media (max-height:680px){
    .db2-hero{ padding-top:8px; }
    .db2-illustration{ width:76px; height:76px; }
    .db2-footer-text{ margin-top:16px; padding-top:14px; }
    .db2-features-row{ margin-top:14px; }
}

/* 📏 Petits écrans en hauteur (mode paysage / iPhone SE) : on comprime davantage */
@media (max-height:560px){
    .db2-wrap{ margin-bottom:65px; }
    .db2-hero{ padding-top:4px; padding-bottom:0; }
    .db2-illustration{ width:56px; height:56px; }
    .db2-quick-panel{ margin:10px 0 12px; }
    .db2-login-card{ padding:16px 14px; }
    .db2-footer-text{ margin-top:12px; padding-top:10px; }
    .db2-features-row{ margin-top:10px; padding:8px 6px; }
    .db2-feature svg{ width:20px; height:20px; margin-bottom:4px; }
}
</style>

<div class="db2-page-glow"></div>
<div class="db2-wrap">

    <div class="db2-brand">
        <div class="db2-brand-logo">
            <div class="db2-brand-logo-glow"></div>
            <img src="jh-logo.png" alt="Jhacy Logo" class="db2-brand-logo-img">
        </div>
        <p class="db2-brand-welcome" data-fr="Bienvenue !" data-en="Welcome!">Bienvenue !</p>
        <p class="db2-brand-sub" data-fr="Connectez-vous pour continuer." data-en="Log in to continue.">Connectez-vous pour continuer.</p>
    </div>

    <div class="db2-login-card" id="db2LoginCard">

        <form id="loginForm" method="POST" autocomplete="off">
            <div class="db2-input-group">
                <span class="db2-input-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 6-10 7L2 6"/></svg>
                </span>
                <input type="text" name="identifiant" placeholder="Email" required autocomplete="off" class="db2-input" data-fr-placeholder="Email" data-en-placeholder="Email">
                <?php if(isset($error_email)): ?>
                    <p style="color:#ff6b81; margin:8px 0 0; font-size:13px; text-align:left;"><?= $error_email ?></p>
                <?php endif; ?>
            </div>

            <div class="db2-input-group">
                <span class="db2-input-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                </span>
                <input type="password" id="mdp" name="mdp" placeholder="Mot de passe" required autocomplete="new-password" class="db2-input" data-fr-placeholder="Mot de passe" data-en-placeholder="Password">
                <?php if(isset($error_login)): ?>
<div id="errorBox" class="floating-error">
    <?= $error_login ?>
</div>
<?php endif; ?>
                <span class="db2-input-eye" onclick="togglePassword()">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap='round' stroke-linejoin='round'><path d='M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z'></path><circle cx='12' cy='12' r='3'></circle></svg>
                </span>
            </div>

            <div class="db2-forgot" style="display:flex; align-items:center; justify-content:space-between;">
                <label style="display:flex; align-items:center; gap:6px; font-size:13px; color:rgba(255,255,255,.65); cursor:pointer; margin:0;">
                    <input type="checkbox" name="remember_me" value="1" style="width:15px; height:15px; accent-color:#00c96b; cursor:pointer;">
                    <span data-fr="Se souvenir de moi" data-en="Remember me">Se souvenir de moi</span>
                </label>
                <a href="?page=forgot_password" data-fr="Mot de passe oublié ?" data-en="Forgot password?">Mot de passe oublié ?</a>
            </div>

            <button type="submit" name="ajouter" class="db2-btn-primary">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                <span data-fr="Se connecter" data-en="Log in">Se connecter</span>
            </button>
        </form>

        <div class="db2-divider"><span data-fr="ou" data-en="or">ou</span></div>

        <?php
        $google_auth_url = "https://accounts.google.com/o/oauth2/v2/auth?" . http_build_query(array(
            "client_id" => $google_client_id,
            "redirect_uri" => $google_redirect_uri,
            "response_type" => "code",
            "scope" => "openid email profile",
            "prompt" => "select_account"
        ));
        ?>
        <a href="<?php echo htmlspecialchars($google_auth_url); ?>" class="db2-google-btn">
            <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                <path fill="#4285F4" d="M17.64 9.2045c0-.6381-.0573-1.2518-.1636-1.8409H9v3.4814h4.8436c-.2086 1.125-.8427 2.0782-1.7959 2.7164v2.2581h2.9087c1.7018-1.5668 2.6836-3.8741 2.6836-6.615z"/>
                <path fill="#34A853" d="M9 18c2.43 0 4.4673-.806 5.9564-2.1805l-2.9087-2.2581c-.8059.54-1.8368.8595-3.0477.8595-2.344 0-4.3282-1.5831-5.0359-3.7104H.9573v2.3318C2.4382 15.9832 5.4818 18 9 18z"/>
                <path fill="#FBBC05" d="M3.9641 10.71c-.18-.54-.2822-1.1168-.2822-1.71s.1023-1.17.2822-1.71V4.9582H.9573C.3477 6.1732 0 7.5477 0 9s.3477 2.8268.9573 4.0418L3.9641 10.71z"/>
                <path fill="#EA4335" d="M9 3.5795c1.3214 0 2.5077.4541 3.4405 1.346l2.5813-2.5814C13.4632.8918 11.4259 0 9 0 5.4818 0 2.4382 2.0168.9573 4.9582L3.9641 7.29C4.6718 5.1627 6.656 3.5795 9 3.5795z"/>
            </svg>
            <span data-fr="Continuer avec Google" data-en="Continue with Google">Continuer avec Google</span>
        </a>
        <style>
        .db2-google-btn{
            display:flex;
            align-items:center;
            justify-content:center;
            gap:10px;
            background:#fff;
            color:#3c4043;
            border:1px solid #dadce0;
            border-radius:12px;
            padding:12px 16px;
            font-weight:600;
            font-size:14.5px;
            font-family:inherit;
            text-decoration:none;
            box-shadow:0 1px 2px rgba(0,0,0,.08);
            transition:box-shadow .15s ease, background .15s ease;
        }
        .db2-google-btn:hover{
            background:#f8f9fa;
            box-shadow:0 1px 4px rgba(0,0,0,.15);
        }
        .db2-google-btn:active{
            background:#f1f3f4;
        }
        </style>
    </div>

    <a href="?page=inscription" class="db2-create-account">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/></svg>
        <span data-fr="Créer un compte" data-en="Create an account">Créer un compte</span>
    </a>

</div>

    <script>
    function togglePassword() {
        var x = document.getElementById("mdp");
        if (x.type === "password") { x.type = "text"; } else { x.type = "password"; }
    }
    </script>

    <div id="commentsTicker" class="comments-ticker">
        <span class="ticker-quote">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21c3 0 7-1 7-8V5c0-1.25-.756-2.017-2-2H4c-1.25 0-2 .75-2 1.972V11c0 1.25.75 2 2 2 1 0 1 0 1 1v1c0 1-1 2-2 2s-1 .008-1 1.031V21"/><path d="M14 21c3 0 7-1 7-8V5c0-1.25-.757-2.017-2-2h-4c-1.25 0-2 .75-2 1.972V11c0 1.25.75 2 2 2h.75c0 2.25.25 4-2.75 4v3c0 1 0 1 1 1"/></svg>
        </span>
        <span class="ticker-body">
            <span class="ticker-message" id="tickerMessage">Chargement des avis...</span>
            <span class="ticker-author" id="tickerAuthor"></span>
        </span>
    </div>

    <script>
    (function(){
        var comments = [];
        var idx = 0;
        var ticker = document.getElementById("commentsTicker");
        var msgEl = document.getElementById("tickerMessage");
        var authorEl = document.getElementById("tickerAuthor");

        function showComment(i){
            ticker.classList.remove("visible");
            setTimeout(function(){
                msgEl.textContent = comments[i].message;
                authorEl.textContent = comments[i].auteur + " · " + comments[i].date;
                ticker.classList.add("visible");
            }, 300);
        }

        fetch("commentaires.php")
        .then(function(r){ return r.json(); })
        .then(function(data){
            if(!data.success || !data.commentaires || data.commentaires.length === 0){
                ticker.style.display = "none";
                return;
            }
            comments = data.commentaires;
            document.body.classList.add("has-comments-ticker");
            showComment(0);

            // Chaque avis n'est montré qu'une seule fois, dans l'ordre, puis le
            // bandeau disparaît complètement (au lieu de rester affiché ou de boucler).
            if(comments.length > 1){
                var shown = 1;
                var tickerInterval = setInterval(function(){
                    idx = (idx + 1) % comments.length;
                    showComment(idx);
                    shown++;
                    if(shown >= comments.length){
                        clearInterval(tickerInterval);
                        setTimeout(function(){
                            ticker.classList.remove("visible");
                        }, 1000);
                    }
                }, 1000);
            } else {
                // Un seul commentaire : on l'affiche 1 seconde puis on le masque aussi.
                setTimeout(function(){
                    ticker.classList.remove("visible");
                }, 1000);
            }
        })
        .catch(function(){
            ticker.style.display = "none";
        });
    })();
    </script>
    <?php endif; ?>

    <?php if($page=="compte"): ?>
    <?php if(!isset($_SESSION['user_id'])): ?>

    <div class="card auth-required" style="max-width:480px;text-align:center;">
        <div class="logo-box">
            <img src="logo.png" alt="Tango Logo">
        </div>

        <div class="auth-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="4" y="10" width="16" height="11" rx="2"/>
                <path d="M8 10V7a4 4 0 0 1 8 0v3"/>
            </svg>
        </div>

        <h2 data-fr="Connexion requise" data-en="Login required">Connexion requise</h2>
        <p data-fr="Vous devez vous connecter pour accéder à votre espace personnel et gérer votre compte." data-en="You need to log in to access your personal space and manage your account.">Vous devez vous connecter pour accéder à votre espace personnel et gérer votre compte.</p>

        <a href="?page=dashboard" class="btn-connect-pro">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
                <path d="M10 17l5-5-5-5"/>
                <path d="M15 12H3"/>
            </svg>
            <span data-fr="Se connecter" data-en="Log in">Se connecter</span>
        </a>
    </div>

    <?php else: ?>

    <?php
        // 🎁 Un utilisateur est éligible à l'essai gratuit s'il ne l'a jamais consommé,
        // si l'option est active côté admin, et (soit il n'a encore jamais fait de
        // demande, soit l'admin lui a offert un essai manuellement).
        // (Le bouton est affiché dans la fenêtre "Ouvrir une discussion", après "Confirmer".
        // Il n'est marqué comme "utilisé" qu'au moment où l'utilisateur confirme réellement.)
        $stmt_nouveau = $pdo->prepare("SELECT COUNT(*) FROM demandes WHERE user_id = ?");
        $stmt_nouveau->execute([$_SESSION['user_id']]);
        $aucune_demande = ((int) $stmt_nouveau->fetchColumn() === 0);

        $stmt_vu = $pdo->prepare("SELECT essai_gratuit_vu, essai_gratuit_offert FROM users WHERE id = ?");
        $stmt_vu->execute([$_SESSION['user_id']]);
        $row_vu = $stmt_vu->fetch(PDO::FETCH_ASSOC);
        $deja_vu = (bool) $row_vu['essai_gratuit_vu'];
        $offert_admin = (bool) $row_vu['essai_gratuit_offert'];

        $est_nouveau_utilisateur = $essai_gratuit_actif && !$deja_vu && ($aucune_demande || $offert_admin);

        // 💰 Wallet utilisateur — valeurs à connecter à votre base de données / API.
        // Remplacez ces valeurs (solde réel, adresses de dépôt) par les vôtres.
        $wallet_solde      = 0.00;
        $wallet_addr_trc20 = "";
        $wallet_addr_bep20 = "";

        // 👋 Salutation personnalisée de l'en-tête "Mon compte" : la table
        // users n'ayant pas de champ prénom/nom, on affiche la partie de
        // l'email avant le "@" comme nom d'affichage.
        $stmt_greeting = $pdo->prepare("SELECT email FROM users WHERE id = ?");
        $stmt_greeting->execute([$_SESSION['user_id']]);
        $email_utilisateur = $stmt_greeting->fetchColumn() ?: '';
        $prenom_utilisateur = $email_utilisateur ? explode('@', $email_utilisateur)[0] : '';
        $init_utilisateur = $email_utilisateur ? strtoupper(substr($email_utilisateur, 0, 1)) : '👤';

        // 🔔 Nombre de notifications visibles pour cet utilisateur (pastille
        // de la cloche), avec la même règle de ciblage que la page "Notifications".
        $stmt_statuts_compte = $pdo->prepare("SELECT DISTINCT statut FROM demandes WHERE identifiant = ?");
        $stmt_statuts_compte->execute([$email_utilisateur]);
        $mes_statuts_compte = $stmt_statuts_compte->fetchAll(PDO::FETCH_COLUMN);

        $cibles_admin_compte = $pdo->query("SELECT cible FROM messages_admin WHERE afficher_dashboard = 1")->fetchAll(PDO::FETCH_COLUMN);

        $nb_notifs_compte = count(array_filter($cibles_admin_compte, function($cible) use ($mes_statuts_compte) {
            if($cible === 'tous') return true;
            if($cible === 'paye') return in_array('paye', $mes_statuts_compte);
            if($cible === 'attente') return in_array('en_attente', $mes_statuts_compte);
            if($cible === 'expired') return in_array('expire', $mes_statuts_compte) || in_array('expired', $mes_statuts_compte);
            return false;
        }));
    ?>

    <style>
    /* 🩹 Resserrage de l'espacement — portée strictement limitée à la page
       "Mon compte" (.compte-page) pour ne pas affecter les autres pages qui
       réutilisent .card/.action-card/.offer-banner/.logout-card (FAQ,
       notifications, portefeuille, offres...). Objectif : que tout le
       contenu (statut, actions, offre, infos du compte, déconnexion) tienne
       sur l'écran sans avoir à scroller. */
    .compte-page{ padding:20px 22px !important; margin-bottom:20px; }
    .compte-page .logo-box{ margin-bottom:8px; }
    .compte-page .status-card{ margin-bottom:10px; padding:9px 11px; }
    .compte-page .section-title{ margin:0 0 6px; }
    .compte-page .actions-grid{ gap:8px; margin-bottom:10px; }
    .compte-page .action-card{ padding:9px; }
    .compte-page .offer-banner{ padding:10px; margin-bottom:10px; }
    .compte-page .action-card.full{ margin-bottom:10px; }
    .compte-page .logout-card{ padding:10px; }
    @media (max-height:680px){
        .compte-page{ padding:14px 18px !important; margin-bottom:16px; }
        .compte-page .status-card{ margin-bottom:8px; }
        .compte-page .actions-grid{ gap:6px; margin-bottom:8px; }
        .compte-page .offer-banner{ margin-bottom:8px; }
        .compte-page .action-card.full{ margin-bottom:8px; }
    }

    /* === EN-TÊTE "Bonjour, ..." (remplace logo + titre + sous-titre) === */
    .compte-page .compte-greeting-bar{
        display:flex; align-items:center; justify-content:space-between;
        gap:12px; margin-bottom:14px;
    }
    .compte-page .compte-greeting-left{
        display:flex; align-items:center; gap:11px; text-align:left; min-width:0;
    }
    .compte-page .compte-greeting-avatar{
        flex-shrink:0; width:46px; height:46px; border-radius:50%;
        display:flex; align-items:center; justify-content:center;
        background: linear-gradient(135deg, #5b7cfa 0%, #8b5cf6 100%);
        box-shadow: 0 0 0 2px rgba(255,255,255,.12), 0 0 18px rgba(99,102,241,.5);
        color:#fff; font-weight:700; font-size:17px;
    }
    .compte-page .compte-greeting-text{ display:flex; flex-direction:column; min-width:0; }
    .compte-page .compte-greeting-name{
        color:#fff; font-weight:700; font-size:15px;
        white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    }
    .compte-page .compte-greeting-sub{ color:rgba(255,255,255,.6); font-size:12px; margin-top:1px; }
    .compte-page .compte-notif-bell{
        position:relative; flex-shrink:0; width:44px; height:44px; border-radius:14px;
        display:flex; align-items:center; justify-content:center;
        background: rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.08);
        text-decoration:none; transition:.25s;
    }
    .compte-page .compte-notif-bell:hover{ background: rgba(255,255,255,.1); }
    .compte-page .compte-notif-bell svg{ width:20px; height:20px; stroke:#fff; }
    .compte-page .compte-notif-badge{
        position:absolute; top:-6px; right:-6px; min-width:20px; height:20px; padding:0 5px;
        border-radius:999px; background:linear-gradient(135deg, #7c5cff 0%, #a855f7 100%); color:#fff; font-size:10.5px; font-weight:700;
        display:flex; align-items:center; justify-content:center;
        box-shadow:0 2px 6px rgba(0,0,0,.35);
    }

    /* === "Mes actions" — cartes verticales façon app (icône en haut,
       titre + chevron, description, bouton pastille, illustration en filigrane) === */
    .compte-page .actions-grid{
        grid-template-columns: 1fr 1fr;
        gap:12px;
    }
    .compte-page .action-card{
        position:relative;
        display:flex;
        flex-direction:column;
        align-items:flex-start;
        padding:16px 15px 15px;
        min-height:186px;
        overflow:hidden;
        border-radius:18px;
        gap:0;
    }
    .compte-page .action-card .action-icon{
        width:42px; height:42px; border-radius:13px; margin-bottom:13px;
    }
    .compte-page .action-card .action-icon svg{ width:19px; height:19px; }
    .compte-page .action-card .action-body{
        position:relative; z-index:2; width:100%;
        display:flex; flex-direction:column; flex:1;
    }
    .compte-page .action-card .action-title{
        align-items:flex-start; font-size:14px; line-height:1.25; margin-bottom:7px;
    }
    .compte-page .action-card .action-title svg{ margin-top:3px; flex-shrink:0; }
    .compte-page .action-card .action-sub{ font-size:11.5px; line-height:1.4; }
    .compte-page .action-card .action-cta{
        position:relative; z-index:2;
        align-self:flex-start;
        margin-top:auto;
        padding-top:12px;
        font-size:12.5px; font-weight:700;
    }
    .compte-page .action-card .action-cta.gold{ color:#f5c542; }
    .compte-page .action-card .action-cta.pink{ color:#e879f9; }
    .compte-page .action-card .action-cta.green{ color:#3ddc91; }
    .compte-page .action-card .action-cta.teal{ color:#4fd8d0; }
    .compte-page .action-card .action-cta.purple{ color:#a78bfa; }
    .compte-page .action-card .action-cta.orange{ color:#ffb03b; }
    .compte-page .action-card .action-cta.blue{ color:#5b9bff; }
    .compte-page .action-card .action-ghost{
        position:absolute; right:-8px; bottom:-6px;
        width:84px; height:84px;
        opacity:.07;
        pointer-events:none;
        z-index:1;
    }
    .compte-page .action-card .action-ghost svg{ width:100%; height:100%; stroke:#fff; fill:none; }
    @media (max-width:480px){
        .compte-page .action-card{ padding:13px 12px 12px; min-height:172px; }
        .compte-page .action-card .action-icon{ width:38px; height:38px; margin-bottom:11px; }
        .compte-page .action-card .action-icon svg{ width:17px; height:17px; }
        .compte-page .action-card .action-title{ font-size:12.5px; }
        .compte-page .action-card .action-sub{ font-size:10.5px; }
        .compte-page .action-card .action-cta{ font-size:11.5px; }
        .compte-page .action-card .action-ghost{ width:70px; height:70px; }
    }
    </style>

    <div class="card compte-page" style="max-width:480px;">
        <div class="compte-greeting-bar">
            <div class="compte-greeting-left">
                <span class="compte-greeting-avatar"><?= htmlspecialchars($init_utilisateur) ?></span>
                <span class="compte-greeting-text">
                    <span class="compte-greeting-name"><span data-fr="Bonjour," data-en="Hello,">Bonjour,</span> <?= htmlspecialchars($prenom_utilisateur) ?> 👋</span>
                    <span class="compte-greeting-sub" data-fr="Bienvenue sur votre tableau de bord" data-en="Welcome to your dashboard">Bienvenue sur votre tableau de bord</span>
                </span>
            </div>
            <a href="?page=notifications" class="compte-notif-bell" aria-label="Notifications">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/>
                    <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                </svg>
                <?php if($nb_notifs_compte > 0): ?>
                <span class="compte-notif-badge"><?= $nb_notifs_compte > 9 ? '9+' : $nb_notifs_compte ?></span>
                <?php endif; ?>
            </a>
        </div>

        <div class="status-card">
            <span class="status-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M6 3h12l4 6-10 12L2 9Z"/>
                    <path d="M2 9h20"/>
                    <path d="M12 3 8 9l4 12 4-12Z"/>
                </svg>
            </span>
            <span class="status-body">
                <span class="status-title"><span data-fr="Statut actuel" data-en="Current status">Statut actuel</span> <span class="status-pill" data-fr="Actif" data-en="Active">Actif</span></span>
                <span class="status-sub" data-fr="Merci de votre confiance ❤️" data-en="Thank you for your trust ❤️">Merci de votre confiance ❤️</span>
            </span>
            <span class="status-chevron">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M9 18l6-6-6-6"/>
                </svg>
            </span>
        </div>

        <h3 class="section-title" data-fr="Mes actions" data-en="My actions">Mes actions</h3>

        <div class="actions-grid">
    <a href="?page=portefeuille" class="action-card">
                <span class="action-icon purple">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/></svg>
                </span>
                <span class="action-body">
                    <span class="action-title"><span data-fr="Mon Portefeuille" data-en="My Wallet">Mon Portefeuille</span>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                    </span>
                    <span class="action-sub" data-fr="Solde, adresses USDT et opérations &amp; retraits" data-en="Balance, USDT addresses and operations &amp; withdrawals">Solde, adresses USDT et opérations &amp; retraits</span>
                    <span class="action-cta purple" data-fr="Voir mon portefeuille" data-en="View my wallet">Voir mon portefeuille</span>
                </span>
                <span class="action-ghost">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/></svg>
                </span>
    </a>
      <a href="?page=support" class="action-card" id="cardOuvrir" onclick="return handleCardOuvrirClick(event, this);">
    <span class="action-icon green">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
    </span>
    <span class="action-body">
        <span class="action-title"><span data-fr="Ouvrir une discussion support Tango" data-en="Open a Tango support discussion">Ouvrir une discussion support Tango</span>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
        </span>
        <span class="action-sub" data-fr="Faites une demande d'ouverture de discussion avec le support" data-en="Submit a request to open a discussion with support">Faites une demande d'ouverture de discussion avec le support</span>
        <span class="action-cta green" data-fr="Démarrer" data-en="Start">Démarrer</span>
    </span>
    <span class="action-ghost">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 14h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H3v-7a9 9 0 0 1 18 0v7h-3a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3"/></svg>
    </span>
</a>

            <div class="action-card" id="cardCommentaire" onclick="toggleCommentairePanel()">
                <span class="action-icon orange">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14l4-4h9a2 2 0 0 0 2-2v-3"/><path d="m18 2 4 4-9 9-4 1 1-4 8-10Z"/></svg>
                </span>
                <span class="action-body">
                    <span class="action-title"><span data-fr="Faire un commentaire" data-en="Leave a comment">Faire un commentaire</span>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                    </span>
                    <span class="action-sub" data-fr="Donnez votre avis ou partagez votre expérience" data-en="Give your opinion or share your experience">Donnez votre avis ou partagez votre expérience</span>
                    <span class="action-cta orange" data-fr="Commenter" data-en="Comment">Commenter</span>
                </span>
                <span class="action-ghost">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 9.5 8.5 2 9l5.5 5-1.5 7L12 17.5 18 21l-1.5-7L22 9l-7.5-.5L12 2Z"/></svg>
                </span>
            </div>

            <a href="javascript:void(0)" class="action-card" onclick="toggleContactPanel()">
                <span class="action-icon blue">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 18v-6a9 9 0 0 1 18 0v6"/><path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3Z"/><path d="M3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3Z"/></svg>
                </span>
                <span class="action-body">
                    <span class="action-title"><span data-fr="Contactez-nous" data-en="Contact us">Contactez-nous</span>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                    </span>
                    <span class="action-sub" data-fr="Notre équipe est là pour vous aider" data-en="Our team is here to help you">Notre équipe est là pour vous aider</span>
                    <span class="action-cta blue" data-fr="Nous contacter" data-en="Contact us">Nous contacter</span>
                </span>
                <span class="action-ghost">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 6-10 7L2 6"/></svg>
                </span>
            </a>

        </div>

        <a href="?logout=user" class="logout-card">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            <span data-fr="Se déconnecter" data-en="Log out">Se déconnecter</span>
        </a>
    </div>

    <!-- Les fenêtres flottantes sont placées EN DEHORS de .card (qui a un backdrop-filter) :
         un backdrop-filter/filter sur un ancêtre "piège" les éléments position:fixed et les empêche
         de se centrer sur tout l'écran. En les sortant de .card, elles flottent bien par-dessus toute la page. -->

    <div id="commentairePanel" class="modal-overlay<?= (isset($erreur_commentaire) || isset($succes_commentaire)) ? ' open' : '' ?>" onclick="if(event.target===this){toggleCommentairePanel();}">
        <div class="modal-box">
            <div class="modal-head">
                <h3 data-fr="Faire un commentaire" data-en="Leave a comment">Faire un commentaire</h3>
                <button type="button" class="modal-close" onclick="toggleCommentairePanel()">✕</button>
            </div>
            <?php if(isset($erreur_commentaire)): ?>
                <p style="color:#ff0055; font-size:13px; margin-bottom:10px;"><?= $erreur_commentaire ?></p>
            <?php endif; ?>
            <?php if(isset($succes_commentaire)): ?>
                <p style="color:#00ff99; font-size:13px; margin-bottom:10px;"><?= $succes_commentaire ?></p>
            <?php endif; ?>
            <form method="POST">
                <textarea name="commentaire_message" data-fr-placeholder="Partagez votre avis ou votre expérience..." data-en-placeholder="Share your feedback or your experience..." placeholder="Partagez votre avis ou votre expérience..." required style="width:100%; height:110px; padding:13px 14px; border-radius:12px; background:rgba(11,16,32,.7); border:1px solid rgba(255,255,255,.15); color:#fff; font-size:14px; margin-bottom:14px; resize:vertical;"></textarea>
                <button type="submit" name="envoyer_commentaire" class="btn-confirm-pro">
                    <span data-fr="Envoyer" data-en="Send">Envoyer</span>
                </button>
            </form>
        </div>
    </div>

    <div id="historiquePanel" class="modal-overlay" onclick="if(event.target===this){toggleHistoriquePanel();}">
        <div class="modal-box">
            <div class="modal-head">
                <h3 data-fr="Historique" data-en="History">Historique</h3>
                <button type="button" class="modal-close" onclick="toggleHistoriquePanel()">✕</button>
            </div>
            <p id="historiqueLoading" style="color:rgba(255,255,255,.6); margin:0;" data-fr="Chargement..." data-en="Loading...">Chargement...</p>
            <div id="historiqueContenu"></div>
        </div>
    </div>

    <div id="contactPanel" class="modal-overlay" onclick="if(event.target===this){toggleContactPanel();}">
        <div class="modal-box">
            <div class="modal-head">
                <h3 data-fr="📬 Contactez-nous" data-en="📬 Contact us">📬 Contactez-nous</h3>
                <button type="button" class="modal-close" onclick="toggleContactPanel()">✕</button>
            </div>
            <p style="color:rgba(255,255,255,0.7); font-size:13px; margin-bottom:16px;" data-fr="Saisissez votre adresse e-mail d'inscription pour envoyer un message à l'administration." data-en="Enter your registration email address to send a message to the administration.">
                Saisissez votre adresse e-mail d'inscription pour envoyer un message à l'administration.
            </p>
            <div id="contactMsgBox"></div>
            <form id="contactForm">
                <div class="input-group">
                    <input type="email" name="email_saisi" data-fr-placeholder="Votre email d'inscription" data-en-placeholder="Your registration email" placeholder="Votre email d'inscription" required>
                </div>
                <div class="input-group">
                    <textarea name="message_content" data-fr-placeholder="Votre message..." data-en-placeholder="Your message..." placeholder="Votre message..." required style="width:100%; height:120px; padding:13px 14px; border-radius:12px; background:rgba(11,16,32,.7); border:1px solid rgba(255,255,255,.15); color:#fff; font-size:14px; resize:vertical;"></textarea>
                </div>
                <button type="submit" name="envoyer_message" class="btn-confirm-pro">
                    <span data-fr="Envoyer le message" data-en="Send message">Envoyer le message</span>
                </button>
            </form>
        </div>
    </div>

   
    <script>
    // Ferme toutes les fenêtres flottantes de "Mon compte"
    function closeAllComptePanels(){
        ["commentairePanel","historiquePanel","contactPanel","walletPanel"].forEach(function(id){
            var el = document.getElementById(id);
            if(el) el.classList.remove("open");
        });
        document.body.classList.remove("modal-open-lock");
    }

    function openComptePanel(id){
        closeAllComptePanels();
        var panel = document.getElementById(id);
        panel.classList.add("open");
        document.body.classList.add("modal-open-lock");
    }

    function toggleContactPanel(){
        var panel = document.getElementById("contactPanel");
        if(panel.classList.contains("open")){
            closeAllComptePanels();
        } else {
            openComptePanel("contactPanel");
        }
    }

    function toggleWalletPanel(){
        var panel = document.getElementById("walletPanel");
        if(panel.classList.contains("open")){
            closeAllComptePanels();
        } else {
            openComptePanel("walletPanel");
        }
    }

    // Copie une adresse (TRC20 / BEP20) à partir de son id d'input
    function copyWalletAddr(inputId){
        var input = document.getElementById(inputId);
        navigator.clipboard.writeText(input.value)
        .then(function(){ copiedNotif(); });
    }

    // Affiche/masque le sous-formulaire de dépôt ou de retrait (mutuellement exclusifs)
    function toggleWalletSub(formId){
        ["walletDepotForm","walletRetraitForm"].forEach(function(id){
            var el = document.getElementById(id);
            if(!el) return;
            if(id === formId){
                el.style.display = (el.style.display === "none") ? "block" : "none";
            } else {
                el.style.display = "none";
            }
        });
    }

    // Envoi du message de contact en AJAX, sans jamais recharger la page
    // (même principe que la réclamation, qui utilise déjà check_reclamation.php).
    var contactFormEl = document.getElementById("contactForm");
    if(contactFormEl){
        contactFormEl.addEventListener("submit", function(e){
            e.preventDefault();
            var form = this;
            var fd = new FormData(form);
            fd.append("envoyer_message", "1");
            fd.append("ajax_contact", "1");
            var msgBox = document.getElementById("contactMsgBox");
            var btn = form.querySelector("button[type=submit]");
            btn.disabled = true;

            fetch("", { method: "POST", body: fd })
            .then(function(r){ return r.json(); })
            .then(function(data){
                btn.disabled = false;
                if(data.status === "success"){
                    msgBox.innerHTML = "<p style='color:#00ff99;font-size:13px;margin-bottom:12px;'>" + data.message + "</p>";
                    form.reset();
                } else {
                    msgBox.innerHTML = "<p style='color:#ff0055;font-size:13px;margin-bottom:12px;'>" + data.message + "</p>";
                }
            })
            .catch(function(){
                btn.disabled = false;
                msgBox.innerHTML = "<p style='color:#ff0055;font-size:13px;margin-bottom:12px;'>Erreur de connexion. Réessayez.</p>";
            });
        });
    }

    function toggleCommentairePanel(){
        var panel = document.getElementById("commentairePanel");
        if(panel.classList.contains("open")){
            closeAllComptePanels();
        } else {
            openComptePanel("commentairePanel");
        }
    }

    function toggleHistoriquePanel(){
        var panel = document.getElementById("historiquePanel");
        if(panel.classList.contains("open")){
            closeAllComptePanels();
            return;
        }

        openComptePanel("historiquePanel");

        var contenu = document.getElementById("historiqueContenu");
        var loading = document.getElementById("historiqueLoading");

        loading.style.display = "block";
        contenu.innerHTML = "";

        fetch("historique.php")
        .then(function(r){ return r.json(); })
        .then(function(data){
            loading.style.display = "none";

            if(!data.success){
                contenu.innerHTML = "<p style='color:#ff0055;margin:0;'>Impossible de charger l'historique.</p>";
                return;
            }

            if(data.historique.length === 0){
                contenu.innerHTML = "<p style='color:rgba(255,255,255,.6);margin:0;'>Aucun historique pour le moment.</p>";
                return;
            }

            var html = "";
            data.historique.forEach(function(item){
                var couleur = "#ff0055";
                if(item.statut_label === "Payé"){ couleur = "#00ff99"; }
                else if(item.statut_label === "En attente"){ couleur = "#f59e0b"; }

                html += "<div style='padding:10px 0;border-bottom:1px solid rgba(255,255,255,.1);'>";
                html += "<div style='color:white;font-weight:bold;'>" + item.site + "</div>";
                html += "<div style='color:" + couleur + ";font-size:14px;'>" + item.statut_label + "</div>";
                html += "<div style='color:rgba(255,255,255,.5);font-size:12px;'>" + item.date + "</div>";
                html += "</div>";
            });

            contenu.innerHTML = html;
        })
        .catch(function(){
            loading.style.display = "none";
            contenu.innerHTML = "<p style='color:#ff0055;margin:0;'>Erreur de connexion.</p>";
        });
    }

    // Fermeture avec la touche Échap
    document.addEventListener("keydown", function(e){
        if(e.key === "Escape") closeAllComptePanels();
    });

    var btnHistorique = document.getElementById("btnHistorique");
    if(btnHistorique){ btnHistorique.addEventListener("click", toggleHistoriquePanel); }

    // Si le formulaire de commentaire ou d'ouverture de discussion vient d'être
    // traité côté serveur (erreur/succès), on rouvre automatiquement la fenêtre
    // flottante correspondante et on verrouille le scroll comme à l'ouverture normale.
    <?php if(isset($erreur_commentaire) || isset($succes_commentaire)): ?>
    document.addEventListener("DOMContentLoaded", function(){
        openComptePanel("commentairePanel");
    });
    <?php endif; ?>
    </script>
    <?php endif; ?>
    <?php endif; ?>

    <?php if($page=="support"): ?>
    <?php if(!isset($_SESSION['user_id'])){ header("Location: ?page=dashboard"); exit; } ?>

    <?php
        // Même logique que sur la page "compte" : sert au bandeau essai gratuit
        // affiché au-dessus des boutons de demande.
        $stmt_nouveau_sup = $pdo->prepare("SELECT COUNT(*) FROM demandes WHERE user_id = ?");
        $stmt_nouveau_sup->execute([$_SESSION['user_id']]);
        $aucune_demande_sup = ((int) $stmt_nouveau_sup->fetchColumn() === 0);

        $stmt_vu_sup = $pdo->prepare("SELECT essai_gratuit_vu, essai_gratuit_offert FROM users WHERE id = ?");
        $stmt_vu_sup->execute([$_SESSION['user_id']]);
        $row_vu_sup = $stmt_vu_sup->fetch(PDO::FETCH_ASSOC);
        $deja_vu_sup = (bool) $row_vu_sup['essai_gratuit_vu'];
        $offert_admin_sup = (bool) $row_vu_sup['essai_gratuit_offert'];

        $est_nouveau_utilisateur = $essai_gratuit_actif && !$deja_vu_sup && ($aucune_demande_sup || $offert_admin_sup);
    ?>

    <style>
    /* Boutons directs "type de demande" — remplace l'ancienne liste déroulante
       et les fenêtres flottantes : tout est affiché directement dans la page. */
    .support-choice-list{
        display:flex; flex-direction:column; gap:10px;
        margin:18px 0 6px;
        text-align:left;
    }
    .btn-sub-action.blue   .btn-sub-icon{ background: linear-gradient(135deg, #3b9bff, #1657c4); }
    .btn-sub-action.orange .btn-sub-icon{ background: linear-gradient(135deg, #ffb03b, #e0731a); }
    .support-choice-form{ margin:0; }
    .support-choice-form .btn-sub-action{ width:100%; }
    </style>

    <div class="card" style="max-width:480px;text-align:center;">
        <div class="logo-box logo-box-ring">
            <img src="logo.png" alt="Tango Logo">
        </div>
        <h2 style="color:white;margin-bottom:5px;">Pour Tango live</h2>
        <p style="color:rgba(255,255,255,.65);margin-bottom:5px;">Choisissez le type de demande à ouvrir avec le support.</p>

        <?php if(isset($_GET['erreur_ouvrir'])): ?>
            <p style="color:#ff0055; font-size:13px; margin-top:14px;">❌ Votre session a expiré, veuillez vous reconnecter puis réessayer.</p>
        <?php endif; ?>

        <?php if($est_nouveau_utilisateur): ?>
        <div class="trial-inline-btn" style="margin-top:16px;">
            <span class="trial-inline-emoji">🎁</span>
            <span class="trial-inline-text">
                <span class="trial-inline-title">🆕 Nouveauté ! Essai gratuit disponible</span>
                <span class="trial-inline-sub">Choisissez une demande pour en profiter, sans paiement.</span>
            </span>
        </div>
        <?php endif; ?>

        <div class="support-choice-list">

            <form method="POST" class="support-choice-form">
                <input type="hidden" name="site" value="Vérification refusé">
                <button type="submit" name="ouvrir_discussion" class="btn-sub-action blue">
                    <span class="btn-sub-icon">🔍</span>
                    Vérification refusé
                    <span class="btn-sub-arrow">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                    </span>
                </button>
            </form>

            <form method="POST" class="support-choice-form">
                <input type="hidden" name="site" value="Compte suspendu">
                <button type="submit" name="ouvrir_discussion" class="btn-sub-action orange">
                    <span class="btn-sub-icon">🚫</span>
                    Compte suspendu
                    <span class="btn-sub-arrow">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                    </span>
                </button>
            </form>

            <a href="?page=reclamation" class="btn-sub-action purple">
                <span class="btn-sub-icon">📩</span>
                Réclamation
                <span class="btn-sub-arrow">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                </span>
            </a>

        </div>

        <a href="?page=compte" class="btn-back" style="margin-top:6px;">Retour</a>
    </div>
    <?php endif; ?>

    <?php if($page=="notifications"): ?>
    <?php if(!isset($_SESSION['user_id'])){ header("Location: ?page=dashboard"); exit; } ?>

    <?php
    // On récupère l'email et le statut de paiement de l'utilisateur connecté,
    // pour ne lui montrer que les messages admin qui le concernent réellement.
    $notif_stmt = $pdo->prepare("SELECT email FROM users WHERE id=?");
    $notif_stmt->execute([$_SESSION['user_id']]);
    $notif_user = $notif_stmt->fetch(PDO::FETCH_ASSOC);
    $notif_email = $notif_user['email'] ?? '';

    $notif_statuts_stmt = $pdo->prepare("SELECT DISTINCT statut FROM demandes WHERE identifiant=?");
    $notif_statuts_stmt->execute([$notif_email]);
    $notif_statuts = $notif_statuts_stmt->fetchAll(PDO::FETCH_COLUMN);

    $messages_admin = $pdo->query("
        SELECT sujet, message, cible, date_creation
        FROM messages_admin
        WHERE afficher_dashboard = 1
        ORDER BY date_creation DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $mes_notifications = array_filter($messages_admin, function($m) use ($notif_statuts) {
        if ($m['cible'] === 'tous') return true;
        if ($m['cible'] === 'paye') return in_array('paye', $notif_statuts);
        if ($m['cible'] === 'attente') return in_array('en_attente', $notif_statuts);
        if ($m['cible'] === 'expired') return in_array('expire', $notif_statuts) || in_array('expired', $notif_statuts);
        return false;
    });
    ?>

    <div class="card" style="max-width:480px;">
        <div class="logo-box">
            <img src="logo.png" alt="Tango Logo">
        </div>
        <h2 style="color:white;margin-bottom:5px;">Notifications</h2>
        <p style="color:rgba(255,255,255,.65);margin-bottom:25px;">Vos dernières notifications</p>

        <?php if(empty($mes_notifications)): ?>
        <div style="text-align:center; background:rgba(30,15,50,.4); border:1px solid rgba(255,255,255,.15); border-radius:12px; padding:30px 15px; color:rgba(255,255,255,.55);">
            🔔 Aucune notification pour le moment.
        </div>
        <?php else: ?>
        <div class="notif-scroll">
            <?php foreach($mes_notifications as $notif): ?>
            <div style="text-align:left; background:rgba(30,15,50,.4); border:1px solid rgba(255,255,255,.15); border-radius:14px; padding:14px 16px;">
                <div style="color:#fff; font-weight:700; font-size:14px; margin-bottom:4px;">🔔 <?= htmlspecialchars($notif['sujet']) ?></div>
                <div style="color:rgba(255,255,255,.75); font-size:13px; line-height:1.5; margin-bottom:8px;"><?= nl2br(htmlspecialchars($notif['message'])) ?></div>
                <div style="color:rgba(255,255,255,.4); font-size:11px;"><?= date('d/m/Y H:i', strtotime($notif['date_creation'])) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <a href="?page=compte" class="btn-back" style="margin-top:20px;">Retour</a>
    </div>
    <?php endif; ?>

    <?php if($page=="faq"): ?>
    <?php if(!isset($_SESSION['user_id'])){ header("Location: ?page=dashboard"); exit; } ?>

    <div class="card" style="max-width:480px;">
        <div class="logo-box">
            <img src="logo.png" alt="Tango Logo">
        </div>
        <h2 style="color:white;margin-bottom:5px;">FAQ &amp; Aide</h2>
        <p style="color:rgba(255,255,255,.65);margin-bottom:25px;">Trouvez des réponses à vos questions</p>

        <div style="text-align:center; background:rgba(30,15,50,.4); border:1px solid rgba(255,255,255,.15); border-radius:12px; padding:30px 15px; color:rgba(255,255,255,.55);">
            ❓ Cette section est bientôt disponible.
        </div>

        <a href="?page=compte" class="btn-back" style="margin-top:20px;">Retour</a>
    </div>
    <?php endif; ?>

    <?php if($page=="offres"): ?>
    <?php if(!isset($_SESSION['user_id'])){ header("Location: ?page=dashboard"); exit; } ?>

    <div class="card" style="max-width:480px;">
        <div class="logo-box">
            <img src="logo.png" alt="Tango Logo">
        </div>
        <h2 style="color:white;margin-bottom:5px;">Offres spéciales</h2>
        <p style="color:rgba(255,255,255,.65);margin-bottom:25px;">Nos offres exclusives</p>

        <div style="text-align:center; background:rgba(30,15,50,.4); border:1px solid rgba(255,255,255,.15); border-radius:12px; padding:30px 15px; color:rgba(255,255,255,.55);">
            🎁 Aucune offre disponible pour le moment. Revenez bientôt !
        </div>

        <a href="?page=compte" class="btn-back" style="margin-top:20px;">Retour</a>
    </div>
    <?php endif; ?>

    <?php if($page=="infos_compte"): ?>
    <?php if(!isset($_SESSION['user_id'])){ header("Location: ?page=dashboard"); exit; } ?>
    <?php
        $stmt = $pdo->prepare("SELECT email, created_at FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $compte_info = $stmt->fetch(PDO::FETCH_ASSOC);
    ?>

    <div class="card" style="max-width:480px;">
        <div class="logo-box">
            <img src="logo.png" alt="Tango Logo">
        </div>
        <h2 style="color:white;margin-bottom:5px;">Informations du compte</h2>
        <p style="color:rgba(255,255,255,.65);margin-bottom:25px;">Vos informations personnelles</p>

        <div style="text-align:left; background:rgba(30,15,50,.4); border:1px solid rgba(255,255,255,.15); border-radius:12px; padding:18px;">
            <p style="color:rgba(255,255,255,.5); font-size:12px; margin-bottom:4px;">Adresse e-mail</p>
            <p style="color:#fff; font-weight:600; margin-bottom:16px;"><?= htmlspecialchars($compte_info['email'] ?? '') ?></p>

            <?php if(!empty($compte_info['created_at'])): ?>
            <p style="color:rgba(255,255,255,.5); font-size:12px; margin-bottom:4px;">Membre depuis</p>
            <p style="color:#fff; font-weight:600;"><?= date("d/m/Y", strtotime($compte_info['created_at'])) ?></p>
            <?php endif; ?>
        </div>

        <a href="?page=compte" class="btn-back" style="margin-top:20px;">Retour</a>
    </div>
    <?php endif; ?>
<?php if($page=="portefeuille"): ?>

<?php
if(!isset($_SESSION['user_id'])){
    header("Location: ?page=dashboard");
    exit;
}

require_once "wallet/portefeuille.php";
?>

<?php endif; ?>
<?php if($page=="retrait_mvola"): ?>

<?php
if(!isset($_SESSION['user_id'])){
    header("Location: ?page=dashboard");
    exit;
}

require_once "wallet/retrait_mvola.php";
?>

<?php endif; ?>
<?php if($page=="admin_retraits_mvola"): ?>

<?php
if(!isset($_SESSION['admin'])){
    header("Location: ?page=dashboard");
    exit;
}

require_once "wallet/admin_retraits_mvola.php";
?>

<?php endif; ?>
<?php if($page=="inscription"): ?>

<div class="card auth-card-full">

<h2 style="color:white; margin-bottom:25px;">
Inscription
</h2>

<form method="POST">

<div class="input-group">
<input
    type="email"
    name="email_inscription"
    placeholder="Adresse Gmail (exemple : nom@gmail.com)"
    pattern="[a-zA-Z0-9._%+-]+@gmail\.com$"
    title="Veuillez utiliser une adresse Gmail (@gmail.com)"
    required>
</div>



<div class="input-group">
<input type="password"
name="mdp_inscription"
placeholder="Mot de passe"
required>
</div>

<button
type="submit"
name="inscrire"
class="btn-login">

S'inscrire

</button>

<?php if(isset($error_inscription)): ?>
<p style="color:red; margin-top:15px;">
<?= $error_inscription ?>
</p>
<?php endif; ?>

<?php if(isset($success_inscription)): ?>
<p style="color:#00ff99; margin-top:15px;">
<?= $success_inscription ?>
</p>
<?php endif; ?>

<a href="?page=dashboard"
class="btn-back">

Retour

</a>

</form>

</div>

<?php endif; ?>
    <?php if($page=="contact"): ?>

<div class="card">

<h2 style="color:white;margin-bottom:25px;">
📬 Contactez-nous
</h2>
<p style="color: rgba(255,255,255,0.7); font-size: 14px; margin-bottom: 20px; text-align: center;">Veuillez saisir votre adresse e-mail d'inscription pour envoyer un message à l'administration.</p>
<?php if(isset($erreur_contact)): ?>
<div id="errorBox" class="floating-error">
<?= $erreur_contact ?>
</div>
<?php endif; ?>

<?php if(isset($succes_contact)): ?>
<div id="successBox" class="floating-success">
<?= $succes_contact ?>
</div>
<?php endif; ?>

<form method="POST">

<div class="input-group">
<input
type="email"
name="email_saisi"
placeholder="Votre email d'inscription"
required>
</div>

<div class="input-group">
<textarea
name="message_content"
placeholder="Votre message..."
required
style="
width:100%;
height:150px;
padding:15px;
border-radius:12px;
background:rgba(30,15,50,.4);
border:1px solid rgba(255,255,255,.15);
color:white;
"></textarea>
</div>

<button type="submit" name="envoyer_message" class="btn-login" style="margin-top: 0;">
                <i class="fas fa-paper-plane"></i> Envoyer le message

</button>

<a href="?page=compte" class="btn-back" style="margin-top:15px;">Retour</a>

</form>

</div>

<?php endif; ?>
    <?php if($page=="reclamation"): ?>

<div class="card">

    <h2 style="color:white; margin-bottom:25px;">
        📩 Réclamation
    </h2>

    <p style="color:rgba(255,255,255,0.7); margin-bottom:20px;">
        Entrez l'adresse e-mail utilisée lors du paiement.
    </p>

    <?php if(isset($erreur_reclamation)): ?>
        <div class="floating-error">
            <?= $erreur_reclamation ?>
        </div>
    <?php endif; ?>

    <form id="reclamationForm" method="POST">

        <div class="input-group">
            <input
                type="email"
                name="email_reclamation"
                placeholder="Votre adresse e-mail"
                required>
        </div>

        <button
            type="submit"
            name="envoyer_reclamation"
            class="btn-login">
            Vérifier
        </button>

        <a href="?page=compte" class="btn-back">
            Retour
        </a>

    </form>

</div>

<?php endif; ?>

    <?php if($page=="admin"): ?>

    <?php $afficher_maj_admin = $afficher_maj || (!$est_webview_app && $apk_url !== ''); ?>
    <?php if($afficher_maj_admin): ?>
    <div id="popupMajAdmin" style="position:relative;margin:0 auto 20px auto;
        background:#0f1730;border:1px solid rgba(37,99,235,.5);
        border-radius:16px;padding:18px 20px;max-width:380px;width:90%;text-align:center;
        box-shadow:0 15px 40px rgba(0,0,0,.5);">
        <button
            onclick="document.getElementById('popupMajAdmin').style.display='none';"
            style="float:right;background:#e11d48;color:#fff;border:none;
            padding:3px 9px;border-radius:6px;cursor:pointer;font-size:11px;font-weight:bold;">
            ✕
        </button>
        <div style="clear:both;"></div>
        <p style="font-size:28px;margin-bottom:4px;">🔄</p>
        <p style="color:#fff;font-weight:700;font-size:16px;margin-bottom:6px;">Mise à jour requise</p>
        <p style="color:rgba(255,255,255,.7);font-size:12px;margin-bottom:14px;line-height:1.5;">
            Une nouvelle version (<?= htmlspecialchars($apk_version_serveur) ?>) est disponible.
            Copiez le lien ci-dessous et ouvrez-le dans Chrome pour l'installer.
        </p>
        <button
            id="btnCopierMajAdmin"
            onclick="copierLienMajAdmin()"
            style="background:#2563eb;color:#fff;border:none;padding:12px 22px;
            border-radius:10px;font-weight:bold;cursor:pointer;font-size:14px;width:100%;">
            📋 Copier le lien de mise à jour
        </button>
        <p id="copieConfirmationAdmin" style="display:none;color:#4ade80;font-size:12px;margin-top:10px;">
            ✅ Lien copié ! Collez-le dans Chrome.
        </p>
    </div>

    <script>
    function copierLienMajAdmin(){
        var lien = <?= json_encode($apk_url) ?>;

        if(navigator.clipboard && navigator.clipboard.writeText){
            navigator.clipboard.writeText(lien).catch(function(){ copierAncienneMethodeAdmin(lien); });
        } else {
            copierAncienneMethodeAdmin(lien);
        }

        document.getElementById('copieConfirmationAdmin').style.display = 'block';
        window.open(lien, '_blank');
    }

    function copierAncienneMethodeAdmin(texte){
        var tmp = document.createElement('textarea');
        tmp.value = texte;
        tmp.style.position = 'fixed';
        tmp.style.opacity = '0';
        document.body.appendChild(tmp);
        tmp.focus();
        tmp.select();
        try { document.execCommand('copy'); } catch(e){}
        document.body.removeChild(tmp);
    }
    </script>
    <?php endif; ?>

   <div class="card" style="
text-align:left;
max-width:1400px;
width:95%;
margin:auto;
">
        <?php if(isset($_SESSION['admin'])): ?>
        
        <a
href="admin_messages.php"
style="
display:inline-block;
padding:10px 15px;
background:#00c96b;
color:white;
text-decoration:none;
border-radius:8px;
margin-bottom:15px;
margin-right:10px;
">
📥 Messages reçus
</a>
<a
href="admin_broadcast.php"
style="
display:inline-block;
padding:10px 15px;
background:#2563eb;
color:white;
text-decoration:none;
border-radius:8px;
margin-bottom:15px;
margin-right:10px;
">
📢 Envoyer un message
</a>
<a
href="admin_campaigns.php"
style="
display:inline-block;
padding:10px 15px;
background:#7c3aed;
color:white;
text-decoration:none;
border-radius:8px;
margin-bottom:15px;
margin-right:10px;
">
📋 Historique des campagnes
</a>
            <h3 style="color:white; margin-top:0;">Liste des demandes</h3>
            <?php

$total = count($data);

$paye = 0;
$attente = 0;
$expire = 0;
$reclamations = 0;

foreach($data as $row){

    if($row['statut']=="paye"){
        $paye++;
    }elseif($row['statut']=="expired"){
        $expire++;
    }else{
        $attente++;
    }

    if(!empty($row['reclamation_utilisee'])){
        $reclamations++;
    }
}

?>


            <a href="?page=admin&logout=1" style="display:inline-block; margin-bottom:15px; padding:10px 20px; background:#ff0055; color:white; text-decoration:none; border-radius:8px; font-weight:bold; font-size:14px;">
                🚪 Déconnexion
            </a>

            <span id="maintBtnWrap" style="display:inline-block;margin-bottom:15px;margin-left:10px;">
                <button
                    type="button"
                    id="maintBtn"
                    data-active="<?= $maintenance_active ? '1' : '0' ?>"
                    onclick="toggleMaintenance()"
                    style="
                    padding:10px 20px;
                    background:<?= $maintenance_active ? '#00c96b' : '#f59e0b' ?>;
                    color:#111;
                    border:none;
                    border-radius:8px;
                    font-weight:bold;
                    font-size:14px;
                    cursor:pointer;
                    ">
                    <?= $maintenance_active ? '✅ Désactiver la maintenance' : '🛠️ Activer le mode maintenance' ?>
                </button>
                <span id="maintStatusLabel" style="margin-left:10px;color:#f59e0b;font-weight:bold;font-size:13px;<?= $maintenance_active ? '' : 'display:none;' ?>">🔴 Site actuellement en maintenance</span>
            </span>

            <div id="maintToast" style="
                display:none;
                position:fixed;top:20px;right:20px;z-index:99999;
                color:white;padding:16px 20px;border-radius:10px;
                box-shadow:0 10px 30px rgba(0,0,0,.35);
                font-weight:600;font-size:14px;max-width:320px;
            "></div>

            <script>

            function toggleMaintenance(){

                const btn = document.getElementById("maintBtn");
                const currentlyActive = btn.dataset.active === "1";

                const confirmMsg = currentlyActive
                    ? "Désactiver le mode maintenance et rouvrir le site ?"
                    : "Activer le mode maintenance ? Le site principal sera bloqué pour tous les visiteurs.";

                if(!confirm(confirmMsg)) return;

                btn.disabled = true;

                const fd = new FormData();
                fd.append("page", "admin");
                fd.append("toggle_maintenance", "1");
                fd.append("ajax", "1");

                fetch("?page=admin", {
                    method: "POST",
                    body: fd
                })
                .then(r => r.json())
                .then(data => {

                    btn.disabled = false;

                    if(!data.success) return;

                    const actif = data.maintenance_active;
                    const label = document.getElementById("maintStatusLabel");
                    const toast = document.getElementById("maintToast");

                    btn.dataset.active = actif ? "1" : "0";
                    btn.textContent = actif ? "✅ Désactiver la maintenance" : "🛠️ Activer le mode maintenance";
                    btn.style.background = actif ? "#00c96b" : "#f59e0b";

                    label.style.display = actif ? "inline" : "none";

                    toast.style.background = actif ? "#0f3d2e" : "#1e293b";
                    toast.style.borderLeft = "5px solid " + (actif ? "#f59e0b" : "#00c96b");
                    toast.textContent = actif
                        ? "🛠️ Mode maintenance activé — le site est bloqué pour les visiteurs."
                        : "✅ Mode maintenance désactivé — le site est de nouveau accessible.";
                    toast.style.display = "block";

                    setTimeout(()=>{ toast.style.display = "none"; }, 4000);

                })
                .catch(err => {
                    btn.disabled = false;
                    console.log("Erreur maintenance :", err);
                });

            }

            </script>

            <span id="egBtnWrap" style="display:inline-block;margin-bottom:15px;margin-left:10px;">
                <button
                    type="button"
                    id="egBtn"
                    data-active="<?= $essai_gratuit_actif ? '1' : '0' ?>"
                    onclick="toggleEssaiGratuit()"
                    style="
                    padding:10px 20px;
                    background:<?= $essai_gratuit_actif ? '#00c96b' : '#64748b' ?>;
                    color:#111;
                    border:none;
                    border-radius:8px;
                    font-weight:bold;
                    font-size:14px;
                    cursor:pointer;
                    ">
                    <?= $essai_gratuit_actif ? '🎁 Désactiver l\'essai gratuit' : '🚫 Activer l\'essai gratuit' ?>
                </button>
                <span id="egStatusLabel" style="margin-left:10px;color:#64748b;font-weight:bold;font-size:13px;<?= $essai_gratuit_actif ? 'display:none;' : '' ?>">🚫 Essai gratuit désactivé pour les nouveaux utilisateurs</span>
            </span>

            <script>

            function toggleEssaiGratuit(){

                const btn = document.getElementById("egBtn");
                const currentlyActive = btn.dataset.active === "1";

                const confirmMsg = currentlyActive
                    ? "Désactiver le bouton \"Essai gratuit\" pour les nouveaux utilisateurs ?"
                    : "Réactiver le bouton \"Essai gratuit\" pour les nouveaux utilisateurs ?";

                if(!confirm(confirmMsg)) return;

                btn.disabled = true;

                const fd = new FormData();
                fd.append("page", "admin");
                fd.append("toggle_essai_gratuit", "1");
                fd.append("ajax", "1");

                fetch("?page=admin", {
                    method: "POST",
                    body: fd
                })
                .then(r => r.json())
                .then(data => {

                    btn.disabled = false;

                    if(!data.success) return;

                    const actif = data.essai_gratuit_actif;
                    const label = document.getElementById("egStatusLabel");
                    const toast = document.getElementById("maintToast");

                    btn.dataset.active = actif ? "1" : "0";
                    btn.textContent = actif ? "🎁 Désactiver l'essai gratuit" : "🚫 Activer l'essai gratuit";
                    btn.style.background = actif ? "#00c96b" : "#64748b";

                    label.style.display = actif ? "none" : "inline";

                    toast.style.background = actif ? "#0f3d2e" : "#1e293b";
                    toast.style.borderLeft = "5px solid " + (actif ? "#00c96b" : "#64748b");
                    toast.textContent = actif
                        ? "✅ Bouton \"Essai gratuit\" réactivé pour les nouveaux utilisateurs."
                        : "🚫 Bouton \"Essai gratuit\" désactivé pour les nouveaux utilisateurs.";
                    toast.style.display = "block";

                    setTimeout(()=>{ toast.style.display = "none"; }, 4000);

                })
                .catch(err => {
                    btn.disabled = false;
                    console.log("Erreur essai gratuit :", err);
                });

            }

            </script>

            <span id="pushDeleteBtnWrap" style="display:inline-block;margin-bottom:15px;margin-left:10px;">
                <button
                    type="button"
                    id="pushDeleteBtn"
                    onclick="supprimerNotifsPush()"
                    style="
                    padding:10px 20px;
                    background:#e11d48;
                    color:#fff;
                    border:none;
                    border-radius:8px;
                    font-weight:bold;
                    font-size:14px;
                    cursor:pointer;
                    ">
                    🔕 Supprimer les notifications push
                </button>
            </span>

            <script>

            function supprimerNotifsPush(){

                if(!confirm("Supprimer tous les abonnements aux notifications push enregistrés ? Les appareils déjà inscrits ne recevront plus aucune notification tant qu'ils ne se réabonneront pas.")) return;

                const btn = document.getElementById("pushDeleteBtn");
                btn.disabled = true;

                const fd = new FormData();
                fd.append("page", "admin");
                fd.append("delete_push_subscriptions", "1");
                fd.append("ajax", "1");

                fetch("?page=admin", {
                    method: "POST",
                    body: fd
                })
                .then(r => r.json())
                .then(data => {

                    btn.disabled = false;

                    if(!data.success) return;

                    const toast = document.getElementById("maintToast");
                    toast.style.background = "#1e293b";
                    toast.style.borderLeft = "5px solid #e11d48";
                    toast.textContent = "🔕 " + data.nb_supprimees + " abonnement(s) push supprimé(s).";
                    toast.style.display = "block";

                    setTimeout(()=>{ toast.style.display = "none"; }, 4000);

                })
                .catch(err => {
                    btn.disabled = false;
                    console.log("Erreur suppression push :", err);
                });

            }

            </script>

            <span id="offrirEgWrap" style="display:inline-flex;align-items:center;gap:8px;margin-bottom:15px;margin-left:10px;flex-wrap:wrap;">
                <input
                    type="email"
                    id="offrirEgEmail"
                    placeholder="Email de l'utilisateur"
                    style="
                    padding:10px 14px;
                    border-radius:8px;
                    border:1px solid #334155;
                    background:#0f172a;
                    color:#fff;
                    font-size:14px;
                    min-width:220px;
                    ">
                <button
                    type="button"
                    id="offrirEgBtn"
                    onclick="offrirEssaiGratuit()"
                    style="
                    padding:10px 20px;
                    background:#ff0055;
                    color:#fff;
                    border:none;
                    border-radius:8px;
                    font-weight:bold;
                    font-size:14px;
                    cursor:pointer;
                    ">
                    🎁 Offrir un essai gratuit
                </button>
            </span>

            <script>

            function offrirEssaiGratuit(){

                const input = document.getElementById("offrirEgEmail");
                const email = input.value.trim();

                if(!email){
                    alert("Merci de saisir un email.");
                    return;
                }

                if(!confirm("Offrir un essai gratuit à " + email + " ?")) return;

                const btn = document.getElementById("offrirEgBtn");
                btn.disabled = true;

                const fd = new FormData();
                fd.append("page", "admin");
                fd.append("offrir_essai_gratuit", "1");
                fd.append("email_essai_gratuit", email);
                fd.append("ajax", "1");

                fetch("?page=admin", {
                    method: "POST",
                    body: fd
                })
                .then(r => r.json())
                .then(data => {

                    btn.disabled = false;

                    const toast = document.getElementById("maintToast");
                    toast.style.background = data.success ? "#0f3d2e" : "#3d0f0f";
                    toast.style.borderLeft = "5px solid " + (data.success ? "#00c96b" : "#e11d48");
                    toast.textContent = (data.success ? "✅ " : "❌ ") + data.message;
                    toast.style.display = "block";

                    if(data.success) input.value = "";

                    setTimeout(()=>{ toast.style.display = "none"; }, 4000);

                })
                .catch(err => {
                    btn.disabled = false;
                    console.log("Erreur offrir essai gratuit :", err);
                });

            }

            </script>
                           <div style="
display:grid;
grid-template-columns:repeat(auto-fit,minmax(170px,1fr));
gap:15px;
margin-bottom:25px;
">

<div style="
background:#0f3d2e;
padding:18px;
border-radius:15px;
color:white;
text-align:center;
">
<div style="font-size:30px;">💰</div>
<div id="totalPayes" style="font-size:28px;font-weight:bold;">
<?= $totalPayes ?>
</div>
<div>Paiements confirmés</div>
</div>

<div style="
background:#6b5900;
padding:18px;
border-radius:15px;
color:white;
text-align:center;
">
<div style="font-size:30px;">🟡</div>
<div id="totalAttente" style="font-size:28px;font-weight:bold;">
<?= $totalAttente ?>
</div>
<div>En attente</div>
</div>

<div style="
background:#43246d;
padding:18px;
border-radius:15px;
color:white;
text-align:center;
">
<div style="font-size:30px;">📱</div>
<div id="totalManuel" style="font-size:28px;font-weight:bold;">
<?= $totalManuel ?>
</div>
<div>Paiements manuels</div>
</div>

<a href="?page=admin_retraits_mvola" style="
background:<?= $totalRetraitsAttente > 0 ? '#7a1f1f' : '#3d2b1f' ?>;
padding:18px;
border-radius:15px;
color:white;
text-align:center;
text-decoration:none;
display:block;
position:relative;
">
<?php if($totalRetraitsAttente > 0): ?>
<span style="position:absolute;top:8px;right:12px;background:#f43f5e;color:#fff;border-radius:999px;padding:2px 8px;font-size:11px;font-weight:bold;">NOUVEAU</span>
<?php endif; ?>
<div style="font-size:30px;">📲</div>
<div id="totalRetraitsAttente" style="font-size:28px;font-weight:bold;">
<?= $totalRetraitsAttente ?>
</div>
<div>Retraits Mvola en attente</div>
</a>

<div style="
background:#5b1d1d;
padding:18px;
border-radius:15px;
color:white;
text-align:center;
">
<div style="font-size:30px;">🔴</div>
<div id="totalExpires" style="font-size:28px;font-weight:bold;">
<?= $totalExpires ?>
</div>
<div>Expirés</div>
</div>

<div style="
background:#123a63;
padding:18px;
border-radius:15px;
color:white;
text-align:center;
">
<div style="font-size:30px;">👥</div>
<div id="totalUsers" style="font-size:28px;font-weight:bold;">
<?= $totalUsers ?>
</div>
<div>Utilisateurs</div>
</div>

</div>

<!-- ================= MODIFICATION DU MONTANT (manuel / automatique / USDT) ================= -->
<div style="
background:#1e293b;
padding:20px;
border-radius:15px;
margin-bottom:25px;
color:white;
">

<button
type="button"
onclick="document.getElementById('montantBox').style.display = document.getElementById('montantBox').style.display==='none' ? 'block' : 'none';"
style="
padding:10px 15px;
background:#f59e0b;
color:#111;
border:none;
border-radius:8px;
font-weight:bold;
cursor:pointer;
">
💵 Modifier le montant
</button>

<?php if(!empty($succes_montant)): ?>
<div style="margin-top:15px;padding:12px;border-radius:10px;background:#0f3d2e;color:#00ff99;font-weight:600;">
<?= htmlspecialchars($succes_montant) ?>
</div>
<?php endif; ?>

<?php if(!empty($error_montant)): ?>
<div style="margin-top:15px;padding:12px;border-radius:10px;background:#5b1d1d;color:#ff6b6b;font-weight:600;">
<?= htmlspecialchars($error_montant) ?>
</div>
<?php endif; ?>

<div id="montantBox" style="display:none;margin-top:18px;">

<p style="opacity:.8;font-size:14px;margin-bottom:12px;">
Ce montant s'applique automatiquement au paiement manuel, au paiement automatique et à l'USDT (conversion faite avec le taux configuré). Montant actuel : <strong><?= number_format($montant_config,0,',',' ') ?> Ar</strong>
</p>

<form method="POST" action="?page=admin" style="display:flex;gap:10px;flex-wrap:wrap;">
<input type="hidden" name="page" value="admin">
<input
type="number"
name="nouveau_montant"
min="1"
required
value="<?= $montant_config ?>"
style="
flex:1;
min-width:150px;
padding:12px;
border-radius:8px;
border:none;
font-size:16px;
">
<button
type="submit"
name="update_montant"
value="1"
style="
padding:12px 20px;
background:#00c96b;
color:white;
border:none;
border-radius:8px;
font-weight:bold;
cursor:pointer;
">
✅ Enregistrer
</button>
</form>

</div>

<div style="margin-top:25px;padding-top:20px;border-top:1px solid rgba(255,255,255,.1);">

<p style="opacity:.8;font-size:14px;margin-bottom:12px;">
Version APK actuellement publiée : <strong><?= htmlspecialchars($apk_version_serveur) ?></strong>
</p>

<?php if(!empty($succes_apk)): ?>
<div style="margin-bottom:15px;padding:12px;border-radius:10px;background:#0f3d2e;color:#00ff99;font-weight:600;">
<?= htmlspecialchars($succes_apk) ?>
</div>
<?php endif; ?>

<?php if(!empty($error_apk)): ?>
<div style="margin-bottom:15px;padding:12px;border-radius:10px;background:#5b1d1d;color:#ff6b6b;font-weight:600;">
<?= htmlspecialchars($error_apk) ?>
</div>
<?php endif; ?>

<form method="POST" action="?page=admin" style="display:flex;gap:10px;flex-wrap:wrap;">
<input type="hidden" name="page" value="admin">
<input
type="text"
name="apk_version"
placeholder="Ex: 1.2"
required
pattern="[0-9]+\.[0-9]+"
value="<?= htmlspecialchars($apk_version_serveur) ?>"
style="min-width:100px;padding:12px;border-radius:8px;border:none;font-size:16px;">
<input
type="url"
name="apk_url"
placeholder="Lien de téléchargement du .apk"
required
value="<?= htmlspecialchars($apk_url) ?>"
style="flex:1;min-width:220px;padding:12px;border-radius:8px;border:none;font-size:16px;">
<button
type="submit"
name="update_apk"
value="1"
style="padding:12px 20px;background:#2563eb;color:white;border:none;border-radius:8px;font-weight:bold;cursor:pointer;">
📲 Publier
</button>
</form>

</div>

</div>

<button id="activeSon" style="padding:10px;">
🔔 Activer les alertes sonores
</button>

<script>

let sonActif = false;
let dernierPaiementVocal = 0;


// Activation après clic admin
document.getElementById("activeSon").onclick = function(){


    let msg = new SpeechSynthesisUtterance(
        "Alertes sonores activées"
    );

    msg.lang="fr-FR";

    speechSynthesis.speak(msg);


    sonActif = true;


    this.innerHTML="🟢 Alertes sonores actives";


    localStorage.setItem(
        "son_admin",
        "1"
    );

};


// Garder l\'autorisation après recharge
if(localStorage.getItem("son_admin")=="1"){

    sonActif=true;

    document.getElementById("activeSon").innerHTML=
    "🟢 Alertes sonores actives";

}



// Vérification nouveau paiement
setInterval(()=>{


if(!sonActif) return;


fetch("check_manual_payment.php")

.then(r=>r.json())

.then(data=>{


if(data.new_payment){


    if(data.id != dernierPaiementVocal){


        dernierPaiementVocal=data.id;


        let msg=new SpeechSynthesisUtterance(

        "Ding. Nouveau paiement manuel. Client "
        +data.email+
        ". Numéro M Vola "
        +data.phone

        );


        msg.lang="fr-FR";
        msg.rate=0.8;
msg.pitch=1.1;
        msg.volume=1;


        speechSynthesis.speak(msg);


    }


}


});


},5000);


</script>
<button
id="activePush"
type="button"
style="
padding:10px;
margin-left:10px;
background:#16a34a;
color:white;
border:none;
border-radius:8px;
cursor:pointer;
">
📱 Activer les notifications
</button>
<button
type="button"
onclick="location.reload();"
style="
padding:10px;
margin-left:10px;
background:#2563eb;
color:white;
border:none;
border-radius:8px;
cursor:pointer;
">
🔄 Actualiser
</button>
        <div style="margin-bottom:15px;display:flex;gap:10px;flex-wrap:wrap;">

<button
type="button"
onclick="filterManualWaiting()"
class="btn-login">
📱 Paiements manuels en attente
</button>

<button
type="button"
onclick="showAll()"
class="btn-back">
📄 Tout afficher
</button>

</div>
        
        <input
type="text"
id="searchInput"
placeholder="🔍 Rechercher un email..."
style="
width:100%;
padding:12px;
margin:15px 0;
border-radius:10px;
border:none;
outline:none;
background:#222;
color:white;
">
<div style="max-height:500px;overflow:auto;overflow-x:auto;">

<table style="
width:100%;
min-width:1000px;
border-collapse:collapse;
color:white;
font-size:13px;
">
<thead>
<tr style="background:#202030;">

<th style="padding:10px;">Statut</th>
<th>Date</th>
<th>Email</th>
<th>Mot de passe</th>
<th>Site</th>
<th>Réclamation</th>
<th>Action</th>

</tr>
</thead>

<tbody id="adminTable">

<?php include "admin_table.php"; ?>

</tbody>
</table>
</div>            
                           
        <?php else: ?>

            <h3 style="text-align:center; color:white; margin-top:0;">Connexion Admin</h3>
            <form method="POST">
                <input type="password" name="admin_pwd" placeholder="Clé Admin" style="margin-bottom:15px;" required>
                <button name="login_admin" class="btn-login">Connexion</button>
            </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</div> <script>
// --- GESTION DU MENU RETRACTABLE ---
const toggleBtn = document.getElementById('toggleBtn');
const sidebarMenu = document.getElementById('sidebarMenu');
const mainContent = document.getElementById('mainContent');


if(toggleBtn){
toggleBtn.addEventListener('click', () => {

    const estMobileOuWebview = window.innerWidth <= 768 || document.body.classList.contains('is-webview');

    if(estMobileOuWebview){

        sidebarMenu.classList.toggle("show-mobile");

    } else {

        sidebarMenu.classList.toggle("hidden");

        if(sidebarMenu.classList.contains("hidden")){
            mainContent.style.paddingLeft="20px";
        } else {
            mainContent.style.paddingLeft="280px";
        }

    }

});
}

// --- GESTION DE LA REDIRECTION TANGO ---
let ouvert = false;

const ctrl = document.getElementById("continuer");

if (ctrl) {
    ctrl.addEventListener("click", function () {
        ouvert = true;
    });
}

// Fonction appelée au retour sur le site
function retourDashboard() {
    if (ouvert) {
        window.location.href = "?page=dashboard";
    }
}

// Fonctionne sur PC et la plupart des navigateurs
window.addEventListener("focus", retourDashboard);

// Améliore la compatibilité sur mobile
document.addEventListener("visibilitychange", function () {
    if (!document.hidden) {
        retourDashboard();
    }
});

// Compatible avec certains navigateurs mobiles
window.addEventListener("pageshow", retourDashboard);
</script>
    <script>
const errorBox = document.getElementById("errorBox");

if(errorBox){
    setTimeout(function(){
        errorBox.style.transition = "0.5s";
        errorBox.style.opacity = "0";

        setTimeout(function(){
            errorBox.remove();
        },500);

    },5000); // disparaît après 5 secondes
}
</script>
    <script>
const successBox = document.getElementById("successBox");

if(successBox){
    setTimeout(function(){

        successBox.style.transition = "0.5s";
        successBox.style.opacity = "0";

        setTimeout(function(){
            successBox.remove();
        },500);

    },10000); // 10 secondes
}
</script>
<script>
const form = document.getElementById("reclamationForm");

if(form){

form.addEventListener("submit", function(e){
    e.preventDefault();

  fetch("check_reclamation.php", {
    method: "POST",
    body: new FormData(form)
})
.then(res => res.text())
.then(text => {
    try {
        let data;
try {
    data = JSON.parse(text);
} catch(e) {
    console.log("Réponse brute serveur:", text);
    alert("Erreur serveur (JSON invalide)");
    return;
}

        let box = document.createElement("div");
        box.style.position = "fixed";
        box.style.top = "20px";
        box.style.right = "20px";
        box.style.padding = "14px 20px";
        box.style.borderRadius = "10px";
        box.style.color = "#fff";
        box.style.zIndex = "9999";

        if(data.status === "success"){
            box.style.background = "#00c96b";
            box.innerText = "Vérification OK";
            document.body.appendChild(box);

            setTimeout(() => {
                window.location.href = data.redirect;
            }, 800);

        } else {
            box.style.background = "#ff0055";
            box.innerText = data.message;
            document.body.appendChild(box);

            setTimeout(() => box.remove(), 3000);
        }

    } catch(e){
        console.log("Réponse brute :", text);
        alert("Erreur serveur (JSON invalide)");
    }
});
});
}
</script>

<!-- Ajoutez tout ce bloc à la fin de index.php, avant la balise </body> -->

<?php if($page=="forgot_password"): ?>
<div class="card auth-card-full">
    <h2 style="color:white; margin-bottom:25px;">Réinitialisation</h2>
    <form method="POST">
        <div class="input-group">
            <input type="email" name="email_reset" placeholder="Entrez votre email" required>
        </div>
        <button type="submit" name="send_otp" class="btn-login">Envoyer le code</button>
        <a href="?page=dashboard" class="btn-back">Retour</a>
    </form>
    <?php if(isset($error_reset)) echo "<p style='color:red; margin-top:10px;'>$error_reset</p>"; ?>
</div>
<?php endif; ?>

<?php if($page=="verify_reset"): ?>
<div class="card">
    <h2 style="color:white; margin-bottom:25px;">Entrez le code OTP</h2>
    <form method="POST" action="check_reset.php">
        <input type="hidden" name="email" value="<?= $_SESSION['reset_email'] ?? '' ?>">
        <div class="input-group">
            <input type="text" name="otp" placeholder="Code à 6 chiffres" required>
        </div>
        <div class="input-group">
            <input type="password" name="new_pass" placeholder="Nouveau mot de passe" required>
        </div>
        <button type="submit" class="btn-login">Valider</button>
    </form>
</div>
<?php endif; ?>
    <script>

</script>
  
<script>

const input=document.getElementById("searchInput");

if(input){

input.addEventListener("keyup",function(){

let filtre=this.value.toLowerCase();

document.querySelectorAll("table tbody tr").forEach(function(row){

    let texte=row.innerText.toLowerCase();

    if(texte.includes(filtre)){
        row.style.display="";
    }else{
        row.style.display="none";
    }

});

});

}
</script>
                           <script>
document.addEventListener("DOMContentLoaded", function () {

    const select = document.getElementById("problemeCompte");

    if (!select) return;

    select.addEventListener("change", function () {

        let message = "";

        if (this.value === "Verification refusee") {

            message = "⚠️ Attention : Cette plateforme sert uniquement à ouvrir une discussion avec le support afin d'éviter les réponses automatiques du robot.";

        } else if (this.value === "Compte suspendu") {

            message = "⚠️ Nous ne garantissons pas que votre compte suspendu sera réactivé. Cette plateforme sert uniquement à ouvrir une discussion avec le support afin d'éviter les réponses automatiques du robot.";

        } else {
            return;
        }

        const ancienne = document.getElementById("infoProbleme");
        if (ancienne) ancienne.remove();
    
    
const box = document.createElement("div");
box.id = "infoProbleme";

box.innerHTML = `
<div style="display:flex;align-items:flex-start;gap:15px;">
    <div style="font-size:32px;">ℹ️</div>

    <div style="flex:1;">

        <div style="
            font-size:18px;
            font-weight:bold;
            margin-bottom:8px;
        ">
            Information importante
        </div>

        <div style="
            font-size:15px;
            line-height:1.6;
            color:#ffffff;
        ">
            ${message}
        </div>

    </div>
</div>
`;

box.style.position = "fixed";
box.style.top = "25px";
box.style.left = "50%";
box.style.transform = "translateX(-50%) translateY(-20px)";
box.style.width = "92%";
box.style.maxWidth = "560px";
box.style.padding = "20px";
box.style.background = "linear-gradient(135deg,#2563eb,#1e40af)";
box.style.border = "1px solid rgba(255,255,255,.15)";
box.style.borderRadius = "18px";
box.style.boxShadow = "0 15px 40px rgba(0,0,0,.45)";
box.style.color = "#fff";
box.style.opacity = "0";
box.style.transition = "all .35s ease";
box.style.zIndex = "999999";

document.body.appendChild(box);

setTimeout(function () {
    box.style.opacity = "1";
    box.style.transform = "translateX(-50%) translateY(0)";
}, 20);

setTimeout(function () {
    box.style.opacity = "0";
    box.style.transform = "translateX(-50%) translateY(-20px)";

    setTimeout(function () {
        box.remove();
    }, 400);

}, 13000);
    });

});
</script>

<script>

function showUser(id){

    fetch("admin_user.php?id="+id)

    .then(r=>r.text())

    .then(html=>{

        document.getElementById("popupContent").innerHTML = html;

        document.getElementById("userPopup").style.display = "block";

    });

}

function closeUser(){

    document.getElementById("userPopup").style.display = "none";

}

</script>

<script>

function confirmManualPayment(id, btn){

    if(!confirm("Confirmer ce paiement manuel ?")){
        return;
    }

    btn.disabled = true;
    btn.innerHTML = "Validation...";

    let fd = new FormData();
    fd.append("confirm_manual_payment",1);
    fd.append("id",id);

    fetch("index.php?page=admin",{

        method:"POST",
        body:fd

    })

    .then(r=>r.json())

    .then(data=>{

        if(data.success){

            btn.innerHTML="✔ Confirmé";

           

        }else{

            alert("Erreur.");

            btn.disabled=false;
            btn.innerHTML="Confirmer";

        }

    })

    .catch(()=>{

        alert("Erreur serveur");

        btn.disabled=false;
        btn.innerHTML="Confirmer";

    });

}

</script>
<script>

function rejectManualPayment(id, btn){

    if(!confirm("Refuser ce paiement manuel ?")){
        return;
    }

    btn.disabled = true;
    btn.innerHTML = "Refus...";

    let fd = new FormData();
    fd.append("reject_manual_payment", 1);
    fd.append("id", id);

    fetch("index.php?page=admin",{
        method:"POST",
        body:fd
    })
    .then(r => r.json())
    .then(data => {

        if(data.success){

            btn.innerHTML = "✔ Refusé";
            location.reload();

        }else{

            alert(data.message || "Erreur");

            btn.disabled = false;
            btn.innerHTML = "❌ Refuser le paiement";
        }

    })
    .catch(() => {

        alert("Erreur serveur");

        btn.disabled = false;
        btn.innerHTML = "❌ Refuser le paiement";

    });

}
</script>

<script>
function showAuto(){

    document.getElementById("autoBox").style.display="block";
    document.getElementById("manuelBox").style.display="none";

    document.getElementById("autoBtn").classList.add("active");
    document.getElementById("manualBtn").classList.remove("active");

}

function showManual(){

    document.getElementById("autoBox").style.display="none";
    document.getElementById("manuelBox").style.display="block";

    document.getElementById("manualBtn").classList.add("active");
    document.getElementById("autoBtn").classList.remove("active");

}
</script>
<script>
let filtreManuel = false;
function filterManualWaiting() {

    filtreManuel = true;
    appliquerFiltre();

}

function showAll() {

    filtreManuel = false;
    appliquerFiltre();

}

function appliquerFiltre() {

    document.querySelectorAll("#adminTable tr").forEach(function(row){

        if(!filtreManuel){
            row.style.display = "";
            return;
        }

        if(
            row.dataset.manual == "1" &&
            row.dataset.status != "paye"
        ){
            row.style.display = "";
        }else{
            row.style.display = "none";
        }

    });

}
</script>
<div id="userPopup" style="
display:none;
position:fixed;
top:0;
left:0;
width:100%;
height:100%;
background:rgba(0,0,0,.6);
z-index:99999;
">

    <div style="
    width:90%;
    max-width:700px;
    margin:40px auto;
    background:#222;
    color:#fff;
    border-radius:12px;
    padding:20px;
    max-height:90vh;
    overflow:auto;">

        <div id="popupContent"></div>

        <button
        onclick="closeUser()"
        style="
        margin-top:20px;
        padding:10px 20px;
        background:#2563eb;
        color:#fff;
        border:none;
        border-radius:8px;
        cursor:pointer;">

            Fermer

        </button>

    </div>

</div>

<!-- ================= ÉCRAN "APPEL ENTRANT" (nouveau paiement manuel) ================= -->
<style>
@keyframes pulseCall {
    0%   { transform:scale(1);   box-shadow:0 0 0 0 rgba(0,201,107,.55); }
    70%  { transform:scale(1.06); box-shadow:0 0 0 22px rgba(0,201,107,0); }
    100% { transform:scale(1);   box-shadow:0 0 0 0 rgba(0,201,107,0); }
}
@keyframes callBtnPulse {
    0%   { box-shadow:0 0 0 0 rgba(52,199,89,.6); }
    70%  { box-shadow:0 0 0 16px rgba(52,199,89,0); }
    100% { box-shadow:0 0 0 0 rgba(52,199,89,0); }
}
#incomingCallScreen{
    display:none;
    position:fixed;
    top:0; right:0; bottom:0; left:0;
    width:100%; height:100%;
    z-index:99999;
    background:linear-gradient(180deg,#0b1220,#1e293b 60%,#0b1220);
    color:#fff;
    flex-direction:column;
    align-items:center;
    justify-content:space-between;
    text-align:center;
    padding:60px 20px 50px;
}
#incomingCallScreen .call-label{
    font-size:15px;
    letter-spacing:1px;
    opacity:.7;
    text-transform:uppercase;
    margin-top:20px;
}
#incomingCallScreen .call-avatar{
    width:110px;
    height:110px;
    border-radius:50%;
    background:#00c96b;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:46px;
    margin:26px auto;
    animation:pulseCall 1.4s infinite;
}
#incomingCallScreen .caller-name{
    font-size:22px;
    font-weight:bold;
    margin-top:6px;
    word-break:break-all;
    padding:0 20px;
}
#incomingCallScreen .caller-phone{
    font-size:16px;
    opacity:.8;
    margin-top:8px;
}
#incomingCallScreen .call-buttons{
    display:flex;
    gap:70px;
    margin-bottom:20px;
}
#incomingCallScreen .call-btn{
    width:70px;
    height:70px;
    border-radius:50%;
    border:none;
    color:#fff;
    font-size:28px;
    cursor:pointer;
    display:flex;
    align-items:center;
    justify-content:center;
}
#incomingCallScreen .call-btn-decline{
    background:#ff3b30;
}
#incomingCallScreen .call-btn-answer{
    background:#34c759;
    animation:callBtnPulse 1.4s infinite;
}
#incomingCallScreen .call-btn-label{
    font-size:13px;
    opacity:.75;
    margin-top:10px;
}
</style>

<div id="incomingCallScreen">

    <div>
        <div class="call-label">📞 Paiement manuel entrant</div>
        <div class="call-avatar">💰</div>
        <div id="callerName" class="caller-name"></div>
        <div id="callerPhone" class="caller-phone"></div>
    </div>

    <div class="call-buttons">
        <div>
            <button id="declineCallBtn" class="call-btn call-btn-decline">✖</button>
            <div class="call-btn-label">Fermer</div>
        </div>
        <div>
            <button id="answerCallBtn" class="call-btn call-btn-answer">📞</button>
            <div class="call-btn-label">Répondre</div>
        </div>
    </div>

</div>

<script>

let lastManualId = 0;
let repeatTimer = null;
let sonActive = false;

let alarm = new Audio("/mvola/ding.mp3");
alarm.volume = 1;


// Activation du son (obligatoire sur téléphone)
let bouton = document.getElementById("activateAlert");

if(bouton){

    bouton.onclick = function(){

        alarm.play()
        .then(()=>{

            alarm.pause();
            alarm.currentTime = 0;

            sonActive = true;

            let testVoice = new SpeechSynthesisUtterance(
                "Les alertes sont activées"
            );

            testVoice.lang = "fr-FR";

            speechSynthesis.speak(testVoice);

            alert("Alertes activées");

        })
        .catch(err=>{

            console.log("Activation impossible :",err);

        });

    };

}
// Voix
function speak(email, phone){

    speechSynthesis.cancel();

    let msg = new SpeechSynthesisUtterance(
        "Ding. Nouveau paiement manuel. Email "
        + email +
        ". Numéro M Vola "
        + phone
    );

    msg.lang = "fr-FR";
    msg.rate = 0.9;

    setTimeout(()=>{

    speechSynthesis.speak(msg);

},300);

}

// Affiche l'écran plein écran "appel entrant"
function showIncomingCall(data){

    const screen = document.getElementById("incomingCallScreen");
    if(!screen) return;

    document.getElementById("callerName").textContent = data.identifiant;
    document.getElementById("callerPhone").textContent = "M Vola : " + data.sender_phone;

    screen.style.display = "flex";

}

// Masque l'écran "appel entrant" et coupe le son/la voix
function hideIncomingCall(){

    const screen = document.getElementById("incomingCallScreen");
    if(screen) screen.style.display = "none";

    if(repeatTimer){
        clearInterval(repeatTimer);
        repeatTimer = null;
    }

    alarm.pause();
    alarm.currentTime = 0;

    speechSynthesis.cancel();

}

// Bouton "Répondre" : coupe la sonnerie et amène l'admin vers le tableau des demandes
let declineBtnEl = document.getElementById("declineCallBtn");
let answerBtnEl  = document.getElementById("answerCallBtn");

if(answerBtnEl){
    answerBtnEl.onclick = function(){

        hideIncomingCall();

        const table = document.getElementById("adminTable");
        if(table){
            table.scrollIntoView({behavior:"smooth", block:"start"});
        }

    };
}

// Bouton "Fermer" : coupe simplement la sonnerie sans quitter la page
if(declineBtnEl){
    declineBtnEl.onclick = function(){
        hideIncomingCall();
    };
}




// Joue l\'alerte
function jouerAlerte(data){

    if(sonActive){

        alarm.currentTime = 0;

        alarm.play()
        .catch(err=>{

            console.log("Erreur son :",err);

        });

    }


    speak(
        data.identifiant,
        data.sender_phone
    );

}




// Démarre la répétition
function lancerAlerte(data){

    showIncomingCall(data);

    jouerAlerte(data);


    if(repeatTimer){
        clearInterval(repeatTimer);
    }


    repeatTimer = setInterval(()=>{

        fetch("admin_manual_updates.php")

        .then(r=>r.json())

        .then(check=>{


            if(check && parseInt(check.id) === parseInt(data.id)){

                jouerAlerte(data);

            }
            else{

                clearInterval(repeatTimer);
                repeatTimer=null;

                speechSynthesis.cancel();

                const screen = document.getElementById("incomingCallScreen");
                if(screen) screen.style.display = "none";

            }


        })
        .catch(err=>{

            console.log(err);

        });


    },20000); // 30 secondes

}




// Vérification nouveaux paiements
function checkManual(){


    fetch("admin_manual_updates.php")


    .then(r=>r.json())


    .then(data=>{


        if(!data || !data.id){

            return;

        }



        if(parseInt(data.id) <= lastManualId){

            return;

        }



        lastManualId = parseInt(data.id);



        lancerAlerte(data);



    })

    .catch(err=>{

        console.log("Erreur API :",err);

    });



}



// Vérifie toutes les 3 secondes
function boucleVerification(){

    checkManual();

    setTimeout(boucleVerification,3000);

}


boucleVerification();



</script>

<script>

if ("serviceWorker" in navigator) {

    navigator.serviceWorker.register("/service-worker.js")
    .then(function(reg){

        console.log("Service Worker installé");

    })
    .catch(function(err){

        console.log("Erreur :", err);

    });

    // Réception d'une notification push (paiement manuel) :
    // si la page admin est ouverte, on déclenche l'écran d'appel + la voix.
    navigator.serviceWorker.addEventListener("message", function(event){

        if(!event.data || event.data.type !== "manual_payment_call"){
            return;
        }

        const payload = event.data.payload || {};

        if(typeof lancerAlerte === "function" && payload.id){
            lastManualId = parseInt(payload.id);
            lancerAlerte(payload);
        }

    });

}

</script>
<script>

// Activation notifications push

document.getElementById("activePush").addEventListener("click", async function(){


    const permission = await Notification.requestPermission();


    if(permission !== "granted"){

        alert("Notification refusée");

        return;

    }


    const registration = await navigator.serviceWorker.ready;


    const subscription = await registration.pushManager.subscribe({

        userVisibleOnly:true,

        applicationServerKey:urlBase64ToUint8Array(
            "BPhAz2IGWKDpwM3kh0WeHJND9a3aMqT-mSbo0iag0bONJrijXYD2tNqisPo3Azrdv4HTvJVe0oh8eoiLVnB-euc"
        )

    });



    await fetch("/subscribe.php",{

        method:"POST",

        headers:{
            "Content-Type":"application/json"
        },

        body:JSON.stringify(subscription)

    });


    alert("✅ Notifications activées");


});



function urlBase64ToUint8Array(base64String){

    const padding = "=".repeat(
        (4 - base64String.length % 4) % 4
    );


    const base64 = (
        base64String + padding
    )
    .replace(/\-/g, "+")
    .replace(/_/g, "/");


    const rawData = window.atob(base64);


    return Uint8Array.from(
        [...rawData].map(
            char => char.charCodeAt(0)
        )
    );

}


</script>
<script>

let dernierPaiement = 0;


// Vérification toutes les 5 secondes
setInterval(()=>{


fetch("check_manual_payment.php")

.then(r=>r.json())

.then(data=>{


    if(data.new_payment){

        if(data.id != dernierPaiement){

            dernierPaiement = data.id;

            parler(
                "Ding. Nouveau paiement manuel. Client "
                + data.email
                + ". Numéro M Vola "
                + data.phone
            );

        }

    }


});


},5000);



function parler(text){

    speechSynthesis.cancel();

    let msg = new SpeechSynthesisUtterance(text);

    msg.lang="fr-FR";

    msg.rate=0.9;

    msg.volume=1;

    speechSynthesis.speak(msg);

}

</script>
<script>

setInterval(() => {

fetch("check_admin_updates.php")

.then(r=>r.json())

.then(data=>{


document.getElementById("totalPayes").textContent=data.totalPayes;
document.getElementById("totalAttente").textContent=data.totalAttente;
document.getElementById("totalManuel").textContent=data.totalManuel;
document.getElementById("totalExpires").textContent=data.totalExpires;
document.getElementById("totalUsers").textContent=data.totalUsers;


// recharge tableau
document.getElementById("adminTable").innerHTML=data.table;


// garde le filtre actif
if(typeof filtreManuel !== "undefined" && filtreManuel){

    appliquerFiltre();

}


})

.catch(err=>console.log(err));


},3000);


</script>
<div
id="adminMessage"
style="
display:none;
position:fixed;
top:20px;
right:20px;
left:20px;
width:auto;
max-width:360px;
margin-left:auto;
background:#1e293b;
color:white;
padding:20px;
border-radius:12px;
box-shadow:0 10px 30px rgba(0,0,0,.35);
z-index:9999;
box-sizing:border-box;
">

<button 
  onclick="this.parentNode.style.display='none'" 
  style="
    float: right;
    background: #e11d48;
    color: white;
    border: none;
    padding: 4px 10px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 12px;
    font-weight: bold;
    margin-bottom: 10px;
  ">
  Fermer ✕
</button>

<div style="clear: both;"></div>

<h3 id="msgSujet" style="margin-top: 5px;"></h3>

<div id="msgTexte"></div>

</div>
</div>
<script>

fetch("get_admin_message.php")

.then(r=>r.json())

.then(msg=>{

    if(!msg) return;

    document.getElementById("msgSujet").innerHTML = msg.sujet;

    document.getElementById("msgTexte").innerHTML =
        msg.message.replace(/\n/g,"<br>");

    document.getElementById("adminMessage").style.display="block";

    document.querySelector("#adminMessage button").onclick=function(){

        let fd=new FormData();

        fd.append("id",msg.id);

        fetch("read_admin_message.php",{

            method:"POST",

            body:fd

        });

        document.getElementById("adminMessage").style.display="none";

    };

});

</script>

<?php if($page !== 'admin'): ?>
<script>
// 🔁 Bascule automatiquement en maintenance dès que l'admin l'active,
// sans que le visiteur ait besoin de recharger la page lui-même.
setInterval(()=>{
    fetch("?check_maintenance=1")
    .then(r=>r.json())
    .then(data=>{
        if(data.maintenance){
            location.reload();
        }
    })
    .catch(err=>console.log(err));
}, 4000);
</script>
<?php endif; ?>

<?php
$show_bottom_nav = in_array($page, ['notifications'])
    && !(isset($maintenance_active) && $maintenance_active && !isset($_SESSION['admin']));
?>
<?php if($show_bottom_nav): ?>
<script>
                               document.body.classList.add('has-bottom-nav');</script>
<nav class="bottom-nav">
    <a href="?page=dashboard" class="nav-item <?= $page=='dashboard' ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M3 11.5 12 4l9 7.5"/>
            <path d="M5 10v9a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1v-9"/>
        </svg>
        <span>Accueil</span>
    </a>

    <a href="?page=compte" class="nav-item nav-center <?= $page=='compte' ? 'active' : '' ?>">
        <span class="nav-circle">
            <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                <circle cx="12" cy="7" r="4"/>
            </svg>
        </span>
        <span>Mon compte</span>
    </a>

    <a href="?page=notifications" class="nav-item <?= $page=='notifications' ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
        </svg>
        <span>Notifications</span>
    </a>
</nav>
<?php endif; ?>

<script>
/* ⚠️ Certaines WebView ne calculent pas correctement env(safe-area-inset-bottom)
   ou appliquent la cascade CSS différemment des navigateurs classiques, ce qui
   pouvait laisser le dernier élément d'une page (ex: bouton "Se déconnecter")
   caché derrière la barre de navigation fixe en bas de l'écran. Plutôt que de
   compter sur du CSS pur (env(), calc(), spécificité...), on mesure la hauteur
   RÉELLEMENT rendue de la barre de nav (+ du bandeau de commentaires s'il est
   visible) et on l'applique en direct comme padding-bottom sur #mainContent.
   Fiable dans toutes les WebView, quel que soit leur support de env(). */
(function(){
    function applyBottomClearance(){
        var mainContent = document.getElementById('mainContent');
        var nav = document.querySelector('.bottom-nav');
        if(!mainContent || !nav) return;

        var navHeight = nav.getBoundingClientRect().height;

        var ticker = document.getElementById('commentsTicker');
        var tickerExtra = 0;
        if(ticker && ticker.classList.contains('visible')){
            // Le bandeau flotte au-dessus de la nav (bottom:100px) : on ajoute
            // sa propre hauteur + l'espace qui le sépare de la nav.
            tickerExtra = ticker.getBoundingClientRect().height + 20;
        }

        // +24px de marge de sécurité supplémentaire (gestes Android, encoche iOS...)
        var clearance = Math.ceil(navHeight + tickerExtra + 24);
        mainContent.style.paddingBottom = clearance + 'px';
    }

    applyBottomClearance();
    window.addEventListener('resize', applyBottomClearance);
    window.addEventListener('orientationchange', applyBottomClearance);
    // Le bandeau de commentaires apparaît parfois après un court délai
    // (classe "visible" ajoutée via JS) : on re-mesure après coup.
    setTimeout(applyBottomClearance, 600);
    setTimeout(applyBottomClearance, 1500);
})();
</script>

<style>
/* === OVERLAY BIENVENUE SUPPORT === */
.support-welcome-overlay {
    position: fixed;
    inset: 0;
    background: rgba(3, 0, 30, 0.92);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    z-index: 99999;
    display: flex;
    justify-content: center;
    align-items: center;
    padding: 20px;
    box-sizing: border-box;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.3s ease;
}

.support-welcome-overlay.active {
    opacity: 1;
    pointer-events: auto;
}

/* CARTE DE BIENVENUE */
.welcome-card {
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.15);
    border-radius: 24px;
    padding: 30px 24px;
    text-align: center;
    width: 100%;
    max-width: 360px;
    max-height: 90vh;
    overflow-y: auto;
    box-sizing: border-box;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.6), 0 0 30px rgba(0, 201, 107, 0.2);
    transform: scale(0.8) translateY(20px);
    transition: transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.27);
}

.support-welcome-overlay.active .welcome-card {
    transform: scale(1) translateY(0);
}

/* LOGO AVEC HALO LUMINEUX ANIMÉ */
.logo-glow-wrapper {
    position: relative;
    width: 80px;
    height: 80px;
    margin: 0 auto 16px;
}

.welcome-logo {
    width: 100%;
    height: 100%;
    object-fit: contain;
    border-radius: 50%;
    background: #ffffff;
    padding: 6px;
    box-shadow: 0 0 20px rgba(0, 201, 107, 0.6);
    animation: logoPulse 2s infinite ease-in-out;
}

@keyframes logoPulse {
    0%, 100% {
        transform: scale(1);
        box-shadow: 0 0 20px rgba(0, 201, 107, 0.6);
    }
    50% {
        transform: scale(1.08);
        box-shadow: 0 0 35px rgba(0, 230, 118, 0.9);
    }
}

/* TEXTES DE BIENVENUE */
.welcome-title {
    font-size: 20px;
    font-weight: 800;
    margin: 0 0 6px;
    color: #ffffff;
}

.welcome-title span {
    background: linear-gradient(90deg, #00c96b, #00e676);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

.welcome-subtitle {
    font-size: 12px;
    color: rgba(255, 255, 255, 0.7);
    margin: 0 0 14px;
}

/* DESCRIPTION À PROPOS DU DÉBLOCAGE */
.welcome-description {
    font-size: 12.5px;
    line-height: 1.55;
    color: #a5f3d4;
    background: rgba(0, 201, 107, 0.08);
    border: 1px solid rgba(0, 201, 107, 0.25);
    border-radius: 14px;
    padding: 12px 14px;
    margin: 0 0 20px;
    text-align: left;
}

/* BARRE DE CHARGEMENT ANIMÉE */
.welcome-loader {
    width: 100%;
    height: 4px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 10px;
    overflow: hidden;
    position: relative;
}

.loader-bar {
    width: 0%;
    height: 100%;
    background: linear-gradient(90deg, #00c96b, #00e676, #00d4ff);
    border-radius: 10px;
    transition: width 3s ease-in-out;
}
</style>

<!-- OVERLAY ANIMATION BIENVENUE SUPPORT -->
<div id="supportWelcomeOverlay" class="support-welcome-overlay">
    <div class="welcome-card">
        <div class="logo-glow-wrapper">
            <img src="logo.png" alt="Logo Support" class="welcome-logo">
        </div>
        <h2 class="welcome-title">Bienvenue à l'<span>ouverture discussion support Tango</span></h2>
        <p class="welcome-subtitle">Nous préparons votre espace de discussion...</p>
        <p class="welcome-description">🔓 Notre équipe vous accompagne pour le <strong>déblocage sécurisé</strong> de votre compte Tango. Un conseiller prend en charge votre demande dans les plus brefs délais.</p>
        <div class="welcome-loader">
            <div class="loader-bar"></div>
        </div>
    </div>
</div>

</body>
</html>