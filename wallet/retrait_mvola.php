<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if(!isset($_SESSION['user_id'])){
    header("Location:?page=dashboard");
    exit;
}

require_once "taux.php";

// BEP20 uniquement (TRC20 retiré : frais Plisio trop élevés pour le client).
// BEP20 : facturé à 2% (coût réel Plisio 1,6% + marge 0,4% intégrée dans le taux, pas de plancher)
const FRAIS_RETRAIT_TAUX_BEP20 = 0.02; // 2% facturé (coût réel Plisio : 1,6%)
const FRAIS_RETRAIT_MAX_USDT  = 20.0;
const POURCENTAGE_PERSO_BEP20 = 0.005; // 0,5% marge perso BEP20

const MONTANT_RETRAIT_MIN_USDT = 10.0; // Montant minimum de retrait

function calculerFraisRetrait(float $montantUsdt, string $network = 'BEP20'): float {
    $frais = $montantUsdt * FRAIS_RETRAIT_TAUX_BEP20;
    $frais = min($frais, FRAIS_RETRAIT_MAX_USDT);
    return round($frais, 2);
}

function calculerMargePerso(float $montantUsdt, string $network = 'BEP20'): float {
    return round($montantUsdt * POURCENTAGE_PERSO_BEP20, 2);
}

// Solde net utilisable pour un réseau donné, à partir d'un montant brut donné.
function soldeNetPourReseau(float $montantBrut, string $network): float {
    return max(0, $montantBrut * (1 - FRAIS_RETRAIT_TAUX_BEP20 - POURCENTAGE_PERSO_BEP20));
}

// Solde AFFICHÉ / plafond de retrait autorisé = solde réel moins 2,5% de
// frais forfaitaires, identique au calcul du mail de dépôt (webhook_plisio.php).
function soldeAfficheDepuisBrut(float $montantBrut): float {
    return $montantBrut * (1 - 0.025);
}

// Grille officielle Mvola : frais de transfert vers un abonné Mvola, par palier de montant (en Ariary)
const FRAIS_TRANSFERT_MVOLA = [
    [100,        1000,      70],
    [1001,       5000,      70],
    [5001,       10000,     150],
    [10001,      25000,     250],
    [25001,      50000,     500],
    [50001,      100000,    1000],
    [100001,     250000,    1900],
    [250001,     500000,    1900],
    [500001,     1000000,   3200],
    [1000001,    2000000,   3800],
    [2000001,    3000000,   5000],
    [3000001,    4000000,   6300],
    [4000001,    5000000,   7500],
    [5000001,    6000000,   9400],
    [6000001,    7000000,   10700],
    [7000001,    8000000,   12500],
    [8000001,    9000000,   14400],
    [9000001,    10000000,  15700],
    [10000001,   11000000,  17500],
    [11000001,   12000000,  18800],
    [12000001,   13000000,  20000],
    [13000001,   14000000,  21300],
    [14000001,   15000000,  23200],
    [15000001,   16000000,  25000],
    [16000001,   17000000,  26300],
    [17000001,   18000000,  28200],
    [18000001,   19000000,  30000],
    [19000001,   20000000,  31300],
];

function calculerFraisMvola(float $montantMga): float {
    if ($montantMga < 100) {
        return 0.0;
    }
    foreach (FRAIS_TRANSFERT_MVOLA as $palier) {
        if ($montantMga >= $palier[0] && $montantMga <= $palier[1]) {
            return (float)$palier[2];
        }
    }
    $dernierPalier = end(FRAIS_TRANSFERT_MVOLA);
    return (float)$dernierPalier[2];
}

$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT * FROM user_wallets WHERE user_id=? LIMIT 1");
$stmt->execute([$user_id]);
$wallet = $stmt->fetch(PDO::FETCH_ASSOC);

$erreur = null;
$succes = null;

// Détection automatique du réseau d'origine à partir du dernier dépôt de l'utilisateur
$stmtDepot = $pdo->prepare("
    SELECT network
    FROM wallet_deposits
    WHERE user_id=?
    ORDER BY id DESC
    LIMIT 1
");
$stmtDepot->execute([$user_id]);
$reseauDetecte = (string)$stmtDepot->fetchColumn();
// BEP20 uniquement.
$reseauParDefaut = 'BEP20';

if($_SERVER['REQUEST_METHOD'] === 'POST'){

    $montantUsdt = isset($_POST['montant_usdt']) ? (float)$_POST['montant_usdt'] : 0;
    $numeroMvola = trim($_POST['numero_mvola'] ?? '');
    $nomMvola    = trim($_POST['nom_mvola'] ?? '');
    $network     = 'BEP20';

    if($montantUsdt <= 0){
        $erreur = "Montant invalide.";
    } elseif($montantUsdt < MONTANT_RETRAIT_MIN_USDT){
        $erreur = "Le montant minimum de retrait est de ".number_format(MONTANT_RETRAIT_MIN_USDT,2)." USDT.";
    } elseif($montantUsdt > soldeAfficheDepuisBrut((float)$wallet['solde'])){
        $erreur = "Solde insuffisant.";
    } elseif($nomMvola === ''){
        $erreur = "Merci d'indiquer le nom du titulaire du compte Mvola.";
    } elseif(!preg_match('/^(034|038)[0-9]{7}$/', $numeroMvola)){
        $erreur = "Merci de vérifier votre numéro Mvola (il doit commencer par 034 ou 038 et contenir 10 chiffres au total).";
    } else {

        $taux = getTauxActuel($pdo);

        if(!$taux){
            $erreur = "Taux de change indisponible pour le moment, réessaie dans quelques minutes.";
        } else {

            $tauxClient = (float)$taux['taux_client'];
            $fraisUsdt = calculerFraisRetrait($montantUsdt, $network);
            $montantNetUsdt = round($montantUsdt - $fraisUsdt, 2);

            // Marge personnelle prélevée en plus (0,5% sur les deux réseaux), calculée sur le montant BRUT demandé
            $fraisPersoUsdt = calculerMargePerso($montantUsdt, $network);
            $montantConvertiUsdt = round($montantNetUsdt - $fraisPersoUsdt, 2);

            if($montantNetUsdt <= 0){
                $erreur = "Le montant est trop faible pour couvrir les frais de retrait (".number_format($fraisUsdt,2)." USDT).";
            } else {

                $montantMgaBrut = round($montantConvertiUsdt * $tauxClient, 2);
                $fraisMvolaMga  = calculerFraisMvola($montantMgaBrut);
                $montantMga     = round($montantMgaBrut - $fraisMvolaMga, 2);

                $pdo->beginTransaction();

                try{

                    $check = $pdo->prepare("SELECT solde FROM user_wallets WHERE user_id=? FOR UPDATE");
                    $check->execute([$user_id]);
                    $soldeActuel = (float)$check->fetchColumn();

                    if($montantUsdt > soldeAfficheDepuisBrut($soldeActuel)){
                        throw new Exception("Solde insuffisant.");
                    }

                    $pdo->prepare("
                        UPDATE user_wallets SET solde = solde - ? WHERE user_id=?
                    ")->execute([$montantUsdt, $user_id]);

                    $pdo->prepare("
                        INSERT INTO retraits_mvola
                        (user_id, montant_usdt, frais_usdt, montant_usdt_net, taux_applique, montant_mga, numero_mvola, nom_mvola, network)
                        VALUES (?,?,?,?,?,?,?,?,?)
                    ")->execute([
                        $user_id, $montantUsdt, $fraisUsdt, $montantNetUsdt, $tauxClient, $montantMga, $numeroMvola, $nomMvola, $network
                    ]);

                    $pdo->commit();

                    $fraisTotalUsdt = round($fraisUsdt + $fraisPersoUsdt, 2);
                    $succes = "Demande envoyée ✅ Frais appliqués : ".number_format($fraisTotalUsdt,2)." USDT + ".number_format($fraisMvolaMga,0,',',' ')." Ar (transfert Mvola). Tu recevras ".number_format($montantMga,0,',',' ')." MGA sur le ".$numeroMvola." (".$nomMvola.") sous peu.";

                    $wallet['solde'] = $soldeActuel - $montantUsdt;

                    if(!function_exists('sendMail')){
                        require_once __DIR__ . "/../mail.php";
                    }

                    $stmtEmailDemandeur = $pdo->prepare("SELECT email FROM users WHERE id=?");
                    $stmtEmailDemandeur->execute([$user_id]);
                    $emailDemandeur = $stmtEmailDemandeur->fetchColumn() ?: ("utilisateur #".$user_id);

                    $messageAdmin = "
Nouvelle demande de retrait Mvola

Client        : {$emailDemandeur}
Montant brut  : {$montantUsdt} USDT
Frais retrait : {$fraisUsdt} USDT (".($network === 'BEP20' ? '1,6%' : '0,5%, plancher 1 USDT').")
Marge perso   : {$fraisPersoUsdt} USDT (0,5%)
Frais Mvola   : ".number_format($fraisMvolaMga,0,',',' ')." Ar
Montant net   : {$montantNetUsdt} USDT
Réseau        : {$network}
A payer       : ".number_format($montantMga,0,',',' ')." MGA
Numéro Mvola  : {$numeroMvola}
Nom Mvola     : {$nomMvola}

Merci de la traiter depuis l'espace admin.
";

                    sendMail(
                        "customerservicefor032@gmail.com",
                        "📲 Nouvelle demande de retrait Mvola",
                        $messageAdmin
                    );

                    if(!function_exists('sendPush') && file_exists(__DIR__ . "/../send_push.php")){
                        require_once __DIR__ . "/../send_push.php";
                    }

                    if(function_exists('sendPush')){
                        try{
                            sendPush(
                                "📲 Nouveau retrait Mvola",
                                $emailDemandeur." (".$nomMvola.") demande ".number_format($montantMga,0,',',' ')." MGA au ".$numeroMvola,
                                ["type" => "retrait_mvola"]
                            );
                        } catch(Throwable $e){
                            error_log("Erreur push retrait Mvola : ".$e->getMessage());
                        }
                    }

                } catch(Exception $e){
                    $pdo->rollBack();
                    $erreur = $e->getMessage();
                }

            }

        }

    }

}

// Solde AFFICHÉ = solde réel moins les frais BEP20 (2% Plisio + 0,5% marge
// perso). Le solde brut en base n'est pas modifié : seuls l'affichage et le
// plafond de retrait en tiennent compte (cf. validations ci-dessus). Arrondi
// vers le BAS (floor) et non au plus proche : sinon l'affichage peut montrer
// un montant légèrement supérieur au plafond réel utilisé par le contrôle de
// solde ci-dessus, ce qui déclenche à tort "Solde insuffisant" quand le
// client retape/reclique ce montant affiché.
$solde_usdt = number_format(floor(soldeAfficheDepuisBrut((float)($wallet['solde'] ?? 0)) * 100) / 100, 2, '.', ' ');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Retrait Mvola - Tango</title>
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#00c96b">
    <link rel="apple-touch-icon" href="/icon-192.png">

    <script>
    // 🌐 Sélecteur de langue FR (par défaut) / EN — même logique que sur index.php.
    function setLang(lang){
        try { localStorage.setItem('siteLang', lang); } catch(e) {}
        try { document.cookie = 'siteLang=' + lang + '; path=/; max-age=31536000'; } catch(e) {}
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
    function getStoredLang(){
        // 🩹 Cookie en secours si localStorage est limité/cloisonné entre pages (WebView).
        try {
            var l = localStorage.getItem('siteLang');
            if (l) return l;
        } catch(e) {}
        try {
            var m = document.cookie.match(/(?:^|;\s*)siteLang=([^;]+)/);
            if (m) return decodeURIComponent(m[1]);
        } catch(e) {}
        return 'fr';
    }
    document.addEventListener('DOMContentLoaded', function(){
        setLang(getStoredLang());
    });
    </script>

    <style>
    * { box-sizing: border-box; }

    body {
        margin: 0;
        padding: 0;
        font-family: Arial, Helvetica, sans-serif;
        min-height: 100vh;
        display: flex;
        flex-direction: column;
        overflow-x: hidden;
        position: relative;
        background-image: url('https://deblocagetango.com/tango3.jpg');
        background-color: #03001e;
        background-position: center;
        background-size: cover;
        background-repeat: no-repeat;
        color: #ffffff;
    }

    @media (max-width: 768px) {
        body { background-attachment: scroll; }
    }

    .main-container {
        flex: 1;
        display: flex;
        justify-content: center;
        align-items: flex-start;
        padding: 12px 10px 90px 10px;
        width: 100%;
    }

    /* Plein écran sur mobile et webview : la box occupe tout l'écran,
       sans marge ni coin arrondi, pour un rendu type application native. */
    @media (max-width: 768px) {
        .main-container {
            padding: 0 0 90px 0;
            align-items: stretch;
        }
        .card {
            max-width: 100%;
            width: 100%;
            min-height: 100vh;
            min-height: 100dvh;
            border-radius: 0;
            box-shadow: none;
        }
    }

    .card {
        background: rgba(255, 255, 255, 0.03);
        backdrop-filter: blur(25px);
        -webkit-backdrop-filter: blur(25px);
        border: 1px solid rgba(255, 255, 255, 0.12);
        border-radius: 20px;
        width: 100%;
        max-width: 500px;
        padding: 18px 14px;
        position: relative;
        overflow: hidden;
        box-shadow: 0 8px 25px rgba(0,0,0,.35), 0 0 20px rgba(0, 201, 107, 0.12);
        animation: zoomIn .4s ease;
    }

    .card::before {
        content: "";
        position: absolute;
        inset: -1px;
        border-radius: 20px;
        padding: 3px;
        filter: blur(1px);
        background: linear-gradient(90deg, transparent 0%, transparent 42%, #ffffff 48%, #00c96b 50%, #00d4ff 52%, transparent 58%);
        background-size: 400% 400%;
        -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
        -webkit-mask-composite: xor;
        mask-composite: exclude;
        pointer-events: none;
        animation: borderTravel 10s linear infinite;
    }

    @keyframes borderTravel {
        0% { background-position:0% 0%; } 25% { background-position:100% 0%; }
        50% { background-position:100% 100%; } 75% { background-position:0% 100%; } 100% { background-position:0% 0%; }
    }

    .card::after {
        content: "";
        position: absolute;
        top: 10px;
        left: 10px;
        width: 90px;
        height: 90px;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(255,255,255,.95) 0%, rgba(0,201,107,.55) 35%, rgba(0,212,255,.25) 60%, transparent 75%);
        filter: blur(15px);
        opacity: .6;
        pointer-events: none;
        z-index: 1;
        animation: orbFloat 12s ease-in-out infinite;
    }

    .card > * { position: relative; z-index: 2; }

    @keyframes orbFloat {
        0%{transform:translate(20px,20px);} 25%{transform:translate(200px,30px);}
        50%{transform:translate(180px,220px);} 75%{transform:translate(30px,180px);} 100%{transform:translate(20px,20px);}
    }

    .wallet-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 14px;
    }
    .wallet-title {
        font-size: 18px;
        font-weight: 800;
        margin: 0;
        letter-spacing: -0.3px;
    }
    .wallet-title span {
        background: linear-gradient(to right, #00c96b, #00e676);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
    }
    .wallet-logo {
        width: 38px;
        height: 38px;
        border-radius: 50%;
        background: #ffffff;
        padding: 3px;
        box-shadow: 0 0 12px rgba(0, 201, 107, 0.45);
        object-fit: contain;
    }

    .amount-box {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 10px;
        margin-bottom: 14px;
        border-radius: 14px;
        background: linear-gradient(135deg, rgba(255,255,255,.06), rgba(255,255,255,.02));
        border: 1px solid rgba(255,255,255,.08);
        box-shadow: 0 4px 15px rgba(0,0,0,.2);
        text-align: center;
    }
    .amount-label {
        font-size: 10px;
        color: rgba(255, 255, 255, 0.6);
        text-transform: uppercase;
        letter-spacing: 0.8px;
        margin-bottom: 2px;
    }
    .amount {
        font-size: 22px;
        font-weight: 900;
        background: linear-gradient(180deg, #fff6c4 0%, #ffd84d 35%, #ffb300 70%, #ff8c00 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        letter-spacing: 0.5px;
        filter: drop-shadow(0 0 6px rgba(255,190,0,.3));
    }

    .retrait-succes {
        background: rgba(0, 230, 118, 0.12);
        border: 1px solid #00e676;
        color: #00e676;
        padding: 10px;
        border-radius: 12px;
        font-size: 12px;
        line-height: 1.4;
        margin-bottom: 12px;
        text-align: center;
    }

    .retrait-erreur {
        background: rgba(255, 46, 100, 0.12);
        border: 1px solid #ff2ea6;
        color: #ff71cd;
        padding: 10px;
        border-radius: 12px;
        font-size: 12px;
        margin-bottom: 12px;
        text-align: center;
    }

    .retrait-form {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .form-group {
        display: flex;
        flex-direction: column;
        gap: 3px;
    }

    .retrait-form label {
        font-size: 11.5px;
        font-weight: 700;
        color: rgba(255, 255, 255, 0.85);
        margin-left: 2px;
    }

    .retrait-form input, .retrait-form select {
        width: 100%;
        background: rgba(15, 8, 30, 0.6);
        border: 1px solid rgba(255, 255, 255, 0.15);
        color: #ffffff;
        border-radius: 10px;
        padding: 9px 11px;
        font-size: 12.5px;
        outline: none;
        transition: all 0.25s ease;
    }

    .retrait-form select option {
        background: #0d0822;
        color: #ffffff;
    }

    .retrait-form input:focus, .retrait-form select:focus {
        border-color: #00c96b;
        box-shadow: 0 0 10px rgba(0, 201, 107, 0.3);
        background: rgba(20, 10, 40, 0.8);
    }

    /* Style de surbrillance d'erreur sur les inputs */
    .retrait-form input.input-erreur {
        border-color: #ff2ea6 !important;
        box-shadow: 0 0 10px rgba(255, 46, 166, 0.4) !important;
    }

    .retrait-conversion {
        background: rgba(0, 0, 0, 0.3);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 12px;
        padding: 10px;
        margin-top: 4px;
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .retrait-conversion-row {
        display: flex;
        justify-content: space-between;
        font-size: 11px;
        color: rgba(255, 255, 255, 0.65);
    }

    .retrait-conversion-row span:last-child {
        font-weight: 600;
        color: #ffffff;
    }

    .retrait-conversion-total {
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-top: 1px dashed rgba(255, 255, 255, 0.15);
        padding-top: 8px;
        margin-top: 2px;
        font-size: 12px;
        font-weight: bold;
    }

    .retrait-conversion-total span:first-child {
        color: #ffffff;
    }

    .retrait-conversion-total span:last-child {
        font-size: 15px;
        color: #00e676;
        text-shadow: 0 0 8px rgba(0, 230, 118, 0.4);
    }

    .wallet-actions {
        display: flex;
        flex-direction: column;
        gap: 8px;
        margin-top: 14px;
    }

    .wallet-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        padding: 10px;
        border-radius: 10px;
        font-size: 12.5px;
        font-weight: bold;
        text-decoration: none;
        transition: 0.25s;
        text-align: center;
        border: none;
        cursor: pointer;
    }

    .wallet-btn-mvola {
        background: linear-gradient(90deg, #00c96b 0%, #008f4c 100%);
        color: white;
        box-shadow: 0 4px 14px rgba(0, 201, 107, 0.35);
    }

    .wallet-btn-mvola:hover {
        transform: translateY(-1px);
        box-shadow: 0 6px 18px rgba(0, 201, 107, 0.45);
    }

    .wallet-btn-secondary {
        background: rgba(255, 255, 255, 0.08);
        border: 1px solid rgba(255, 255, 255, 0.15);
        color: white;
    }

    .wallet-btn-secondary:hover {
        background: rgba(255, 255, 255, 0.15);
    }

    .bottom-nav {
        position: fixed; left: 50%; bottom: 0; transform: translateX(-50%); width: 100%; max-width: 480px;
        background: linear-gradient(180deg, rgba(18,12,40,.98) 0%, rgba(8,6,20,.99) 100%);
        border: 1px solid rgba(255,255,255,0.1); border-bottom: none; border-radius: 16px 16px 0 0;
        display: flex; justify-content: space-around; align-items: flex-end; padding: 6px 6px 8px;
        z-index: 500; box-shadow: 0 -10px 30px rgba(0,0,0,.55);
    }
    .bottom-nav .nav-item {
        display: flex; flex-direction: column; align-items: center; gap: 2px; text-decoration: none;
        color: rgba(255,255,255,.55); font-size: 9.5px; font-weight: 600; flex: 1; transition: .25s;
    }
    .bottom-nav .nav-item svg { width: 17px; height: 17px; stroke: rgba(255,255,255,.55); }
    .bottom-nav .nav-item.active { color: #00c96b; text-shadow: 0 0 8px rgba(0,201,107,.8); }
    .bottom-nav .nav-item.active svg { stroke: #00c96b; }

    @keyframes zoomIn { 0%{ opacity:0; transform:translateY(10px) scale(.98); } 100%{ opacity:1; transform:translateY(0) scale(1); } }

    /* 🌐 Sélecteur de langue FR/EN — position fixe en haut à droite */
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
<body>

<div class="lang-switch" id="langSwitch">
    <span class="lang-opt active" data-lang="fr" onclick="setLang('fr')">FR</span>
    <span class="lang-opt" data-lang="en" onclick="setLang('en')">EN</span>
</div>


<div class="main-container">
    <div class="card">

        <div class="wallet-header">
            <h2 class="wallet-title"><span data-fr="Retrait vers" data-en="Withdraw to">Retrait vers</span> <span>Mvola</span></h2>
            <img src="logousdtmvola.png" alt="USDT Mvola" class="wallet-logo">
        </div>

        <div class="amount-box">
            <span class="amount-label" data-fr="Solde Disponible" data-en="Available Balance">Solde Disponible</span>
            <span class="amount" id="soldeDisponibleAmount"><?= $solde_usdt; ?> USDT</span>
        </div>

        <!-- Bloc d'erreur JS/PHP dynamique -->
        <div id="jsErreurBox" class="retrait-erreur" style="display: <?= $erreur ? 'block' : 'none' ?>;">
            <?= htmlspecialchars($erreur ?? '') ?>
        </div>

        <?php if($succes): ?>
            <div class="retrait-succes"><?= htmlspecialchars($succes) ?></div>
        <?php else: ?>

            <form method="POST" class="retrait-form" id="formRetrait" novalidate>

                <div class="form-group">
                    <label for="montant_usdt" data-fr="Montant à retirer (USDT)" data-en="Amount to withdraw (USDT)">Montant à retirer (USDT)</label>
                    <div style="display:flex; gap:8px; align-items:stretch;">
                        <input type="number" step="0.01" min="10" name="montant_usdt"
                               id="montant_usdt" data-fr-placeholder="Ex : 20" data-en-placeholder="E.g.: 20" placeholder="Ex : 20" required autocomplete="off" style="flex:1;">
                        <button type="button" id="btnTousMontant"
                                style="flex:0 0 auto; padding:0 16px; border:none; border-radius:8px; background:#00c96b; color:#03001e; font-weight:bold; cursor:pointer;" data-fr="Tous" data-en="All">
                            Tous
                        </button>
                    </div>
                    <small style="display:block; color:rgba(255,255,255,0.5); font-size:10px; margin-top:2px;" data-fr="Montant minimum : 10 USDT." data-en="Minimum amount: 10 USDT.">Montant minimum : 10 USDT.</small>
                </div>

                <div class="form-group">
                    <label for="network" data-fr="Réseau d'origine" data-en="Source network">Réseau d'origine</label>
                    <input type="hidden" name="network" id="network" value="BEP20">
                    <div class="wallet-input" style="display:flex; align-items:center;" data-fr="BEP20 (Binance Smart Chain)" data-en="BEP20 (Binance Smart Chain)">BEP20 (Binance Smart Chain)</div>
                </div>

                <div class="form-group">
                    <label for="nom_mvola" data-fr="Nom sur le compte Mvola" data-en="Name on the Mvola account">Nom sur le compte Mvola</label>
                    <input type="text" name="nom_mvola" id="nom_mvola" data-fr-placeholder="Ex : RAKOTO Jean" data-en-placeholder="E.g.: RAKOTO Jean" placeholder="Ex : RAKOTO Jean" maxlength="100" required autocomplete="off">
                </div>

                <div class="form-group">
                    <label for="numero_mvola" data-fr="Numéro Mvola" data-en="Mvola Number">Numéro Mvola</label>
                    <input type="tel" name="numero_mvola" id="numero_mvola" data-fr-placeholder="034xxxxxxx ou 038xxxxxxx" data-en-placeholder="034xxxxxxx or 038xxxxxxx" placeholder="034xxxxxxx ou 038xxxxxxx" maxlength="10" required>
                    <small id="numeroMvolaHint" style="display:block; color:rgba(255,255,255,0.5); font-size:10px; margin-top:2px;" data-fr="Doit commencer par 034 ou 038 (10 chiffres)." data-en="Must start with 034 or 038 (10 digits).">Doit commencer par 034 ou 038 (10 chiffres).</small>
                </div>

                <div class="retrait-conversion">
                    <div class="retrait-conversion-row">
                        <span data-fr="Cours USDT / Mvola" data-en="USDT / Mvola rate">Cours USDT / Mvola</span>
                        <span id="tauxAffiche">Chargement...</span>
                    </div>
                    <div class="retrait-conversion-row">
                        <span data-fr="Frais (retrait + transfert Mvola)" data-en="Fees (withdrawal + Mvola transfer)">Frais (retrait + transfert Mvola)</span>
                        <span id="fraisAffiche">0.00 USDT + 0 Ar</span>
                    </div>
                    <div class="retrait-conversion-row">
                        <span data-fr="Montant net converti" data-en="Net converted amount">Montant net converti</span>
                        <span id="montantNetAffiche">0.00 USDT</span>
                    </div>
                    <div class="retrait-conversion-total">
                        <span data-fr="Tu reçois" data-en="You receive">Tu reçois</span>
                        <span id="montantMga">0 Ar</span>
                    </div>
                </div>

                <button type="submit" class="wallet-btn wallet-btn-mvola" style="width:100%; margin-top:6px;" data-fr="Confirmer le retrait" data-en="Confirm withdrawal">
                    Confirmer le retrait
                </button>

            </form>

        <?php endif; ?>

        <div class="wallet-actions">
            <a href="?page=portefeuille" class="wallet-btn wallet-btn-secondary" data-fr="Retour au portefeuille" data-en="Back to wallet">Retour au portefeuille</a>
        </div>

    </div>
</div>

<div class="bottom-nav">
    <a href="?page=dashboard" class="nav-item">
        <svg fill="none" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
        <span data-fr="Accueil" data-en="Home">Accueil</span>
    </a>
    <a href="portefeuille.php" class="nav-item active">
        <svg fill="none" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a2 2 0 002-2V7a2 2 0 00-2-2H6a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
        <span data-fr="Wallet" data-en="Wallet">Wallet</span>
    </a>
    <a href="?page=compte" class="nav-item">
        <svg fill="none" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
        <span data-fr="Compte" data-en="Account">Compte</span>
    </a>
</div>

<script>
let tauxClient = null;

async function chargerTaux(){
    try{
        const res = await fetch("wallet/get_taux.php");
        const data = await res.json();
        if(data.taux_client){
            tauxClient = data.taux_client;
            document.getElementById("tauxAffiche").innerText =
                Number(tauxClient).toLocaleString('fr-FR') + " Mga";
            recalculer();
        } else {
            document.getElementById("tauxAffiche").innerText = "Indisponible";
        }
    } catch(e){
        document.getElementById("tauxAffiche").innerText = "Indisponible";
    }
}

const FRAIS_TAUX_BEP20 = 0.02;  // 2% facturé (coût réel Plisio : 1,6%)
const FRAIS_MAX = 20.0;
const MONTANT_MIN = 10.0; // Montant minimum de retrait
const POURCENTAGE_PERSO_BEP20 = 0.005; // 0,5% marge perso

function soldeNetPourReseau(montantBrut, network){
    return Math.max(0, montantBrut * (1 - FRAIS_TAUX_BEP20 - POURCENTAGE_PERSO_BEP20));
}

// Solde affiché = solde réel moins 2,5% de frais forfaitaires, identique au
// calcul du mail de dépôt. Arrondi vers le bas, identique à $solde_usdt côté
// PHP ci-dessus, pour que le bouton "Tous" ne pré-remplisse jamais un
// montant supérieur au plafond.
function soldeAfficheDepuisBrut(montantBrut){
    return montantBrut * (1 - 0.025);
}
let SOLDE_MAX = Math.floor(soldeAfficheDepuisBrut(<?= (float)($wallet['solde'] ?? 0); ?>) * 100) / 100;

// === Mise à jour automatique du solde et du plafond (sans recharger la page) ===
// Reste synchronisé avec portefeuille.php : si un dépôt arrive, ou si une autre
// demande de retrait est envoyée depuis un autre onglet, le solde affiché ici
// et le plafond utilisé par le bouton "Tous" / la validation suivent en direct.
async function rafraichirSoldeRetrait(){
    try{
        const res = await fetch("wallet/get_solde.php", { cache: "no-store" });
        if(!res.ok) return;
        const data = await res.json();
        if(data.error) return;

        const nouveauSolde = parseFloat(data.solde_usdt);
        if(isNaN(nouveauSolde)) return;

        SOLDE_MAX = nouveauSolde;

        const elMontant = document.getElementById("soldeDisponibleAmount");
        if(elMontant){
            elMontant.innerText = nouveauSolde.toLocaleString('fr-FR', {minimumFractionDigits:2, maximumFractionDigits:2}) + " USDT";
        }
    } catch(e){
        console.error("Erreur rafraîchissement solde :", e);
    }
}

setInterval(rafraichirSoldeRetrait, 5000);

function calculerFrais(montant, network){
    let frais = montant * FRAIS_TAUX_BEP20;
    frais = Math.min(frais, FRAIS_MAX);
    return Math.round(frais * 100) / 100;
}

function calculerMargePerso(montant, network){
    return Math.round(montant * POURCENTAGE_PERSO_BEP20 * 100) / 100;
}

// Grille officielle Mvola : frais de transfert vers un abonné Mvola, par palier de montant (en Ariary)
const FRAIS_MVOLA_PALIERS = [
    [100, 1000, 70],
    [1001, 5000, 70],
    [5001, 10000, 150],
    [10001, 25000, 250],
    [25001, 50000, 500],
    [50001, 100000, 1000],
    [100001, 250000, 1900],
    [250001, 500000, 1900],
    [500001, 1000000, 3200],
    [1000001, 2000000, 3800],
    [2000001, 3000000, 5000],
    [3000001, 4000000, 6300],
    [4000001, 5000000, 7500],
    [5000001, 6000000, 9400],
    [6000001, 7000000, 10700],
    [7000001, 8000000, 12500],
    [8000001, 9000000, 14400],
    [9000001, 10000000, 15700],
    [10000001, 11000000, 17500],
    [11000001, 12000000, 18800],
    [12000001, 13000000, 20000],
    [13000001, 14000000, 21300],
    [14000001, 15000000, 23200],
    [15000001, 16000000, 25000],
    [16000001, 17000000, 26300],
    [17000001, 18000000, 28200],
    [18000001, 19000000, 30000],
    [19000001, 20000000, 31300],
];

function calculerFraisMvola(montantMga){
    if(montantMga < 100) return 0;
    for(let i = 0; i < FRAIS_MVOLA_PALIERS.length; i++){
        const palier = FRAIS_MVOLA_PALIERS[i];
        if(montantMga >= palier[0] && montantMga <= palier[1]) return palier[2];
    }
    return FRAIS_MVOLA_PALIERS[FRAIS_MVOLA_PALIERS.length - 1][2];
}

function recalculer(){
    const input = document.getElementById("montant_usdt");
    const networkSelect = document.getElementById("network");
    if(!input || tauxClient === null) return;
    const montant = parseFloat(input.value) || 0;
    const network = networkSelect ? networkSelect.value : 'BEP20';
    const frais = montant > 0 ? calculerFrais(montant, network) : 0;
    const montantNet = Math.max(montant - frais, 0);
    const fraisPerso = montant > 0 ? calculerMargePerso(montant, network) : 0;
    const montantConverti = Math.max(montantNet - fraisPerso, 0);
    const mgaBrut = montantConverti * tauxClient;
    const fraisMvola = mgaBrut > 0 ? calculerFraisMvola(mgaBrut) : 0;
    const mgaNet = Math.max(mgaBrut - fraisMvola, 0);

    document.getElementById("fraisAffiche").innerText =
        (frais + fraisPerso).toFixed(2) + " USDT + " + Math.round(fraisMvola).toLocaleString('fr-FR') + " Ar";
    document.getElementById("montantNetAffiche").innerText = montantConverti.toFixed(2) + " USDT";
    document.getElementById("montantMga").innerText =
        Math.round(mgaNet).toLocaleString('fr-FR') + " Ar";
}

function afficherErreur(msg){
    const box = document.getElementById("jsErreurBox");
    if(box){
        box.innerText = msg;
        box.style.display = "block";
    }
}

function masquerErreur(){
    const box = document.getElementById("jsErreurBox");
    if(box){
        box.style.display = "none";
        box.innerText = "";
    }
}

function reinitialiserErreursVisuelles(){
    document.querySelectorAll(".retrait-form input").forEach(el => el.classList.remove("input-erreur"));
}

document.addEventListener("DOMContentLoaded", function(){
    chargerTaux();

    const inputMontant = document.getElementById("montant_usdt");
    if(inputMontant){
        inputMontant.addEventListener("input", function(){
            recalculer();
            this.classList.remove("input-erreur");
        });
    }

    const selectNetwork = document.getElementById("network");
    if(selectNetwork){
        selectNetwork.addEventListener("change", function(){
            recalculer();
        });
    }

    const inputNom = document.getElementById("nom_mvola");
    if(inputNom){
        inputNom.addEventListener("input", function(){
            this.classList.remove("input-erreur");
        });
    }

    const inputNumero = document.getElementById("numero_mvola");
    if(inputNumero){
        inputNumero.addEventListener("input", function(){
            this.classList.remove("input-erreur");
        });
    }

    const btnTous = document.getElementById("btnTousMontant");
    if(btnTous && inputMontant){
        btnTous.addEventListener("click", function(){
            inputMontant.value = SOLDE_MAX.toFixed(2);
            inputMontant.classList.remove("input-erreur");
            masquerErreur();
            recalculer();
        });
    }

    const form = document.getElementById("formRetrait");
    if(form){
        form.addEventListener("submit", function(e){
            reinitialiserErreursVisuelles();

            const montantVal = parseFloat(inputMontant ? inputMontant.value : 0) || 0;
            const nomVal = inputNom ? inputNom.value.trim() : "";
            const numeroVal = inputNumero ? inputNumero.value.trim() : "";
            const soldeMax = SOLDE_MAX;

            // Détection directe au clic sans réfléchir
            if(montantVal <= 0){
                e.preventDefault();
                inputMontant.classList.add("input-erreur");
                inputMontant.focus();
                afficherErreur("⚠️ Veuille saisir un montant valide à retirer.");
                return;
            }

            if(montantVal < MONTANT_MIN){
                e.preventDefault();
                inputMontant.classList.add("input-erreur");
                inputMontant.focus();
                afficherErreur("⚠️ Le montant minimum de retrait est de " + MONTANT_MIN.toFixed(2) + " USDT.");
                return;
            }

            if(montantVal > soldeMax){
                e.preventDefault();
                inputMontant.classList.add("input-erreur");
                inputMontant.focus();
                afficherErreur("⚠️ Solde insuffisant ! Votre solde est de " + soldeMax.toFixed(2) + " USDT.");
                return;
            }

            if(nomVal === ""){
                e.preventDefault();
                inputNom.classList.add("input-erreur");
                inputNom.focus();
                afficherErreur("⚠️ Veuille indiquer le nom du titulaire Mvola.");
                return;
            }

            if(!/^(034|038)[0-9]{7}$/.test(numeroVal)){
                e.preventDefault();
                inputNumero.classList.add("input-erreur");
                inputNumero.focus();
                afficherErreur("⚠️ Numéro Mvola invalide. Il doit commencer par 034 ou 038 et contenir 10 chiffres.");
                return;
            }

            masquerErreur();
        });
    }
});
</script>

</body>
</html>