<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: ?page=dashboard");
    exit;
}

require_once "config.php";
require_once "plisio_api.php";
require_once "wallet_functions.php";
require_once "taux.php";

$user_id = $_SESSION['user_id'];

// Chercher portefeuille
$stmt = $pdo->prepare("
    SELECT *
    FROM user_wallets
    WHERE user_id = ?
    LIMIT 1
");
$stmt->execute([$user_id]);
$wallet = $stmt->fetch(PDO::FETCH_ASSOC);

// Créer portefeuille si absent
if (!$wallet) {
    $stmt = $pdo->prepare("
        SELECT email
        FROM users
        WHERE id = ?
    ");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        INSERT INTO user_wallets (user_id, identifiant, solde, statut)
        VALUES (?, ?, 0, 'actif')
    ");
    $stmt->execute([$user_id, $user['email'] ?? '']);

    // Recharger
    $stmt = $pdo->prepare("
        SELECT *
        FROM user_wallets
        WHERE user_id = ?
    ");
    $stmt->execute([$user_id]);
    $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
}

// TRC20 supprimé : uniquement des adresses BEP20 (frais Plisio TRC20 trop élevés).

// Adresses BEP20 : la 1re est créée à l'inscription ; les suivantes sont
// ajoutées progressivement (1 par ouverture du portefeuille) jusqu'à 10,
// pour ne pas bloquer la page sur plusieurs appels Plisio.
$bep20Addresses = completerAdressesDepotProgressive($pdo, $user_id, 10, 1);

// Recharge finale des données du portefeuille
$stmt = $pdo->prepare("
    SELECT *
    FROM user_wallets
    WHERE user_id = ?
");
$stmt->execute([$user_id]);
$wallet = $stmt->fetch(PDO::FETCH_ASSOC);

// Taux appliqué : identique à retrait_mvola.php (taux_client contient déjà la marge de 200 Ar)
$taux = getTauxActuel($pdo);
$tauxAffiche = $taux ? (float)$taux['taux_client'] : 0;

// Le solde AFFICHÉ au client est le solde réel moins 2,5% de frais
// forfaitaires, identique au calcul du mail de confirmation de dépôt
// (webhook_plisio.php : $amount * 0.975). Le solde brut en base
// (user_wallets.solde) n'est PAS modifié : seul l'affichage et le plafond
// de retrait autorisé tiennent compte de cette retenue.
// Arrondi vers le BAS (floor), pas au plus proche : sinon l'affichage peut
// dépasser le plafond réel utilisé par retrait_mvola.php.
function soldeAfficheDepuisBrut(float $montantBrut): float {
    return $montantBrut * (1 - 0.025);
}

$soldeBrut = (float)($wallet['solde'] ?? 0);
$soldeNet  = floor(soldeAfficheDepuisBrut($soldeBrut) * 100) / 100;

$solde_usdt = number_format($soldeNet, 2, '.', ' ');
$solde_ar   = $tauxAffiche > 0
    ? number_format($soldeNet * $tauxAffiche, 0, ',', ' ')
    : '—';

// Nom/avatar affichés dans l'en-tête (pas de champ "prénom" en base : on
// dérive un nom d'affichage à partir de l'email de l'utilisateur).
$stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$userEmail = $stmt->fetchColumn() ?: '';
$userName    = $userEmail ? ucfirst(strtok($userEmail, '@')) : 'Utilisateur';
$userInitial = strtoupper(substr($userName, 0, 1) ?: 'U');

// Carte "Cours USDT" : cours actuel affiché tel quel (taux client).
$coursCarte = $tauxAffiche > 0
    ? number_format($tauxAffiche, 0, ',', ' ')
    : '—';
?>
<!DOCTYPE html>
<!-- VERSION-FIX-OVERLAY-2026-08-01-v5 (webview: seules les adresses sous "Retrait Tango Live" scrollent, en-tête/titre sticky) -->
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mon Portefeuille - Tango</title>
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#03001e">
    <link rel="apple-touch-icon" href="/icon-192.png">

    <script>
    // 📱 Détection WebView (app Android/iOS embarquée) : pose la classe
    // "webview-mode" sur <html> AVANT le premier rendu, pour que le CSS
    // puisse afficher une navigation "plein écran + bouton Retour" pour la
    // sélection d'adresse UNIQUEMENT dans l'app, sans rien changer dans un
    // navigateur classique (où l'accordéon actuel reste tel quel).
    (function () {
        var ua = navigator.userAgent || '';

        // Marqueur standard ajouté automatiquement par Android quand la
        // page tourne dans un android.webkit.WebView (absent de Chrome
        // pour Android classique).
        var estWebviewAndroid = /; ?wv\)/i.test(ua);

        // Sur iOS, une WKWebView embarquée dans une app native n'a
        // généralement PAS "Safari" dans son user-agent (contrairement à
        // Safari lui-même et à Chrome/Firefox iOS qui gardent "Safari").
        var estIOS = /iPhone|iPad|iPod/i.test(ua);
        var estWebviewIOS = estIOS && !/Safari/i.test(ua);

        // 🎯 Marqueur réel de ton app (déjà utilisé côté PHP dans index.php
        // pour détecter la WebView : strpos($ua, 'MonAppAndroid')) — c'est
        // la détection la plus fiable puisqu'elle vient directement de
        // l'user-agent que ton APK envoie, peu importe la version d'Android
        // ou le moteur WebView utilisé.
        var estWebviewCustom = /MonAppAndroid/i.test(ua);

        if (estWebviewAndroid || estWebviewIOS || estWebviewCustom) {
            document.documentElement.classList.add('webview-mode');
        }
    })();
    </script>

    <script>
    // 🌐 Sélecteur de langue FR (par défaut) / EN. Même logique que sur index.php :
    // traduit les éléments marqués data-fr/data-en (et data-fr-placeholder/
    // data-en-placeholder pour les champs), préférence mémorisée en localStorage.
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
    /* === DESIGN SYSTEM & FOND PORTEFEUILLE === */
    * { box-sizing: border-box; }

    html { height: 100%; }

    body { margin: 0; padding: 0; font-family: Arial, Helvetica, sans-serif; min-height: 100vh; display: flex; flex-direction: column; overflow-x: hidden; position: relative; background-image: url('https://deblocagetango.com/tango3.jpg'); background-color: #03001e; background-position: center; background-size: cover; background-repeat: no-repeat; color: #ffffff; } @media (max-width: 768px) { body { background-attachment: scroll; } }

    /* === ANIMATION DE BIENVENUE (SPLASH SCREEN) === */
    #splash-screen {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        width: 100%;
        height: 100%;
        background: #03001e;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        z-index: 9999;
        transition: opacity 0.8s ease, visibility 0.8s ease;
        -webkit-transform: translate3d(0, 0, 0);
        transform: translate3d(0, 0, 0);
        will-change: opacity;
    }

    #splash-screen.hidden {
        opacity: 0;
        visibility: hidden;
    }

    .splash-logo-container {
        position: relative;
        display: flex;
        justify-content: center;
        align-items: center;
    }

    .splash-logo {
        width: 110px;
        height: 110px;
        border-radius: 50%;
        background: #ffffff;
        padding: 8px;
        box-shadow: 0 0 35px rgba(255, 0, 128, 0.6), 0 0 70px rgba(0, 201, 107, 0.4);
        animation: pulseLogo 1.8s infinite ease-in-out;
        object-fit: contain;
    }

    .splash-text {
        margin-top: 25px;
        font-size: 20px;
        font-weight: 800;
        letter-spacing: 1px;
        background: linear-gradient(90deg, #ff2ea6, #00c96b, #00d4ff);
        background-size: 200% auto;
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        animation: textShimmer 2s linear infinite;
    }

    .splash-loader {
        width: 40px;
        height: 4px;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 4px;
        margin-top: 15px;
        overflow: hidden;
        position: relative;
    }

    .splash-loader::after {
        content: '';
        position: absolute;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, #ff2ea6, #00c96b);
        animation: loadingBar 1.5s infinite ease-in-out;
    }

    @keyframes pulseLogo {
        0% { transform: scale(0.92); box-shadow: 0 0 25px rgba(255, 0, 128, 0.4); }
        50% { transform: scale(1.08); box-shadow: 0 0 50px rgba(255, 0, 128, 0.8), 0 0 80px rgba(0, 201, 107, 0.6); }
        100% { transform: scale(0.92); box-shadow: 0 0 25px rgba(255, 0, 128, 0.4); }
    }

    @keyframes textShimmer {
        0% { background-position: 0% center; }
        100% { background-position: 200% center; }
    }

    @keyframes loadingBar {
        0% { left: -100%; }
        50% { left: 0%; }
        100% { left: 100%; }
    }

    .main-container {
        flex: 1;
        display: flex;
        justify-content: center;
        align-items: center;
        padding: 30px 20px 110px 20px;
        width: 100%;
    }

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

    /* === CARTE PRINCIPALE EN VERRE === */
    .card {
        background: rgba(255, 255, 255, 0.03);
        backdrop-filter: blur(25px);
        -webkit-backdrop-filter: blur(25px);
        border: 1px solid rgba(255, 255, 255, 0.12);
        border-radius: 30px;
        width: 100%;
        max-width: 560px;
        padding: 35px 28px;
        position: relative;
        overflow: hidden;
        box-shadow: 0 12px 35px rgba(0,0,0,.35), 0 0 30px rgba(255,0,120,.15);
        transition: transform .35s, box-shadow .35s;
        animation: zoomIn .6s ease;
    }

    .card::before {
        content: "";
        position: absolute;
        inset: -1px;
        border-radius: 30px;
        padding: 4px;
        filter: blur(1px);
        background: linear-gradient(90deg, transparent 0%, transparent 42%, #ffffff 48%, #ff2ea6 50%, #00d4ff 52%, transparent 58%);
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
        top: 20px;
        left: 20px;
        width: 120px;
        height: 120px;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(255,255,255,.95) 0%, rgba(255,0,150,.55) 35%, rgba(0,212,255,.25) 60%, transparent 75%);
        filter: blur(18px);
        opacity: .8;
        pointer-events: none;
        z-index: 1;
        animation: orbFloat 12s ease-in-out infinite;
    }

    .card > * { position: relative; z-index: 2; }

    @keyframes orbFloat {
        0%{transform:translate(30px,30px);} 25%{transform:translate(320px,50px);}
        50%{transform:translate(280px,350px);} 75%{transform:translate(40px,300px);} 100%{transform:translate(30px,30px);}
    }

    .wallet-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 25px;
    }
    .wallet-title {
        font-size: 28px;
        font-weight: 800;
        margin: 0;
        letter-spacing: -0.5px;
    }
    .wallet-title span {
        background: linear-gradient(to right, #ff71cd, #ff007f);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
    }
    .wallet-logo {
        width: 60px;
        height: 60px;
        border-radius: 50%;
        background: #ffffff;
        padding: 5px;
        box-shadow: 0 0 20px rgba(255, 0, 120, 0.45);
        object-fit: contain;
    }

    .amount-box {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 22px;
        margin-bottom: 20px;
        border-radius: 22px;
        background: linear-gradient(135deg, rgba(255,255,255,.06), rgba(255,255,255,.02));
        border: 1px solid rgba(255,255,255,.08);
        box-shadow: 0 10px 35px rgba(0,0,0,.25);
        text-align: center;
    }
    .amount-label {
        font-size: 12px;
        color: rgba(255, 255, 255, 0.6);
        text-transform: uppercase;
        letter-spacing: 1px;
        margin-bottom: 6px;
    }
    .amount {
        font-size: 36px;
        font-weight: 900;
        background: linear-gradient(180deg, #fff6c4 0%, #ffd84d 35%, #ffb300 70%, #ff8c00 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        letter-spacing: 1px;
        filter: drop-shadow(0 0 8px rgba(255,190,0,.4));
    }
    .amount-sub {
        font-size: 15px;
        color: #ff71cd;
        font-weight: 700;
        margin-top: 4px;
    }

    .wallet-stats {
        display: flex;
        gap: 12px;
        margin-bottom: 25px;
    }
    .wallet-stat {
        flex: 1;
        background: rgba(255, 255, 255, 0.04);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 14px;
        padding: 10px 14px;
        text-align: center;
    }
    .wallet-stat small {
        display: block;
        font-size: 11px;
        color: rgba(255, 255, 255, 0.5);
        text-transform: uppercase;
    }
    .wallet-stat strong {
        font-size: 14px;
        color: #00e676;
    }

    .wallet-section-title {
        font-size: 15px;
        font-weight: 700;
        color: rgba(255, 255, 255, 0.9);
        margin: 0 0 12px 2px;
    }

    .wallet-network-cards {
        display: flex;
        flex-direction: column;
        gap: 12px;
        margin-bottom: 20px;
    }

    .wallet-network-card {
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: rgba(255, 255, 255, 0.04);
        border: 1px solid rgba(255, 255, 255, 0.12);
        padding: 14px 18px;
        border-radius: 18px;
        cursor: pointer;
        transition: 0.3s ease;
    }

    .wallet-network-card:hover, .wallet-network-card.wallet-btn-network-active {
        background: rgba(255, 46, 166, 0.12);
        border-color: #ff2ea6;
        box-shadow: 0 8px 25px rgba(255, 0, 130, 0.25);
    }

    /* === ACCORDÉON 100% CSS (radios cachés) : aucune dépendance au JS pour
       afficher/masquer les adresses. Robuste sur toutes les WebView, y
       compris celles qui repeignent mal un display:none->block piloté par
       script. === */
    .wallet-network-radio {
        position: absolute;
        opacity: 0;
        width: 0;
        height: 0;
        pointer-events: none;
    }
    .wallet-address-section {
        display: none;
    }
    #radioBep20:checked ~ #section-bep20 {
        display: block;
    }
    #radioBep20:checked ~ .wallet-network-cards #btnToggleBep20 {
        background: rgba(255, 46, 166, 0.12);
        border-color: #ff2ea6;
        box-shadow: 0 8px 25px rgba(255, 0, 130, 0.25);
    }
    #radioBep20:checked ~ .wallet-network-cards #btnToggleBep20 .wallet-network-arrow {
        transform: rotate(180deg);
        color: #ff2ea6;
    }

    /* --- Bouton "Retour au choix du réseau" : invisible par défaut (le
       navigateur classique garde l'accordéon actuel, cartes + adresse
       ensemble) et affiché uniquement en mode WebView (voir plus bas). --- */
    .wallet-retour-reseau {
        display: none;
        align-items: center;
        gap: 8px;
        width: fit-content;
        background: rgba(255, 255, 255, 0.06);
        border: 1px solid rgba(255, 255, 255, 0.12);
        color: rgba(255, 255, 255, 0.85);
        font-family: inherit;
        font-size: 13px;
        font-weight: 700;
        padding: 9px 16px;
        border-radius: 999px;
        margin-bottom: 16px;
        cursor: pointer;
        transition: 0.25s ease;
    }
    .wallet-retour-reseau:hover {
        background: rgba(255, 46, 166, 0.15);
        border-color: #ff2ea6;
        color: #fff;
    }
    .wallet-retour-reseau svg {
        width: 15px;
        height: 15px;
        stroke: currentColor;
        flex-shrink: 0;
    }

    /* === MODE WEBVIEW : navigation en 2 écrans (choix du réseau -> adresse
       plein écran + Retour), au lieu de l'accordéon "cartes + adresse
       ensemble" utilisé en navigateur classique. Piloté uniquement par la
       classe "webview-mode" posée en JS très tôt dans le <head>. === */
    html.webview-mode #radioBep20:checked ~ .wallet-network-cards {
        display: none;
    }
    html.webview-mode .wallet-address-section .wallet-retour-reseau {
        display: flex;
    }
    html.webview-mode .wallet-retour-dashboard {
        display: flex;
    }
    html.webview-mode .wallet-address-section {
        animation: glisserDepuisDroite 0.25s ease;
    }
    @keyframes glisserDepuisDroite {
        from { opacity: 0; transform: translateX(24px); }
        to   { opacity: 1; transform: translateX(0); }
    }

    /* --- Correctif "contenu coupé en bas" en WebView : le scroll interne
       imbriqué (.historique-corps en overflow-y:auto DANS une box à
       max-height, elle-même DANS un overlay backdrop-filter) ne défile pas
       fiablement sur certaines WebView Android/iOS. On bascule donc, en
       mode WebView UNIQUEMENT, sur un scroll NATIF de la page entière :
       la box occupe tout l'écran, sans limite de hauteur, et c'est le
       document lui-même qui défile — beaucoup plus robuste. Comportement
       "bottom sheet" du navigateur classique totalement inchangé. --- */
    html.webview-mode .historique-overlay.actif {
        display: block;
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
    }
    html.webview-mode .historique-box {
        width: 100%;
        max-width: 100%;
        max-height: none;
        min-height: 100%;
        min-height: 100dvh;
        border-radius: 0;
    }
    html.webview-mode .historique-corps {
        overflow: visible;
        flex: none;
        min-height: 0;
        padding-bottom: 40px;
    }

    /* --- Sur la box "Dépôt USDT" (adresses Tango Live BEP20), en
       mode WebView : le scroll natif de la page entière (fix ci-dessus)
       reste actif — c'est ce qui est fiable sur WebView Android/iOS.
       Par contre l'en-tête et, sous "📍 Retrait Tango Live", le bouton
       "Retour" + le titre restent collés en haut de l'écran (position:
       sticky) : visuellement, seules les cartes d'adresses défilent
       en dessous, pas toute la page. --- */
    html.webview-mode #overlayDepot .historique-header {
        position: sticky;
        top: 0;
        z-index: 4;
        background: #161c2b;
    }
    html.webview-mode #overlayDepot .wallet-address-sticky-top {
        position: sticky;
        top: 54px;
        z-index: 3;
        background: #161c2b;
        padding-top: 6px;
        padding-bottom: 2px;
    }
    html.webview-mode #overlayDepot .wallet-retour-reseau {
        margin-bottom: 10px;
    }
    html.webview-mode #overlayDepot .wallet-mini-title {
        margin-bottom: 15px;
    }

    .wallet-network-left {
        display: flex;
        align-items: center;
        gap: 14px;
    }

    .wallet-network-icon {
        width: 42px;
        height: 42px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        font-weight: bold;
    }
    .wallet-network-icon.bep20 { background: linear-gradient(135deg, #f3ba2f, #f08c00); color: #fff; }

    .wallet-network-left h3 {
        margin: 0;
        font-size: 15px;
        font-weight: 700;
    }

    .wallet-network-left p {
        margin: 3px 0 0 0;
        font-size: 12px;
        color: rgba(255, 255, 255, 0.6);
    }

    .wallet-network-arrow {
        font-size: 12px;
        color: rgba(255, 255, 255, 0.5);
        transition: transform 0.3s;
    }

    .wallet-btn-network-active .wallet-network-arrow {
        transform: rotate(180deg);
        color: #ff2ea6;
    }

    /* FIX CSS WEBVIEW APK : Forcer un affichage et un calque GPU propre */
    .wallet-address-section {
        background: rgba(0, 0, 0, 0.25);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 20px;
        padding: 20px;
        margin-bottom: 20px;
        animation: fadeIn .4s ease;
        -webkit-transform: translate3d(0, 0, 0) !important;
        transform: translate3d(0, 0, 0) !important;
        -webkit-backface-visibility: hidden;
        backface-visibility: hidden;
        will-change: transform, opacity;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(-8px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .wallet-mini-title {
        font-size: 13px;
        color: #ff71cd;
        margin: 0 0 15px 0;
        font-weight: 600;
    }

    .wallet-history-list {
        display: flex;
        flex-direction: column;
        gap: 10px;
        margin-bottom: 20px;
    }

    .wallet-history-item {
        background: rgba(255, 255, 255, 0.03);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 14px;
        padding: 12px 14px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
    }

    .wallet-history-left {
        display: flex;
        flex-direction: column;
        gap: 3px;
    }

    .wallet-history-amount {
        font-size: 14px;
        font-weight: 700;
        color: #fff;
    }

    .wallet-history-sub {
        font-size: 11px;
        color: rgba(255, 255, 255, 0.5);
    }

    .wallet-history-status {
        font-size: 11px;
        font-weight: 700;
        padding: 4px 10px;
        border-radius: 20px;
        white-space: nowrap;
    }

    .wallet-history-status.en_attente {
        background: rgba(255, 183, 3, 0.15);
        color: #ffb703;
    }

    .wallet-history-status.confirme {
        background: rgba(72, 187, 120, 0.15);
        color: #48bb78;
    }

    .wallet-history-status.rejete {
        background: rgba(245, 101, 101, 0.15);
        color: #f56565;
    }

    .wallet-history-empty {
        font-size: 13px;
        color: rgba(255, 255, 255, 0.5);
        text-align: center;
        padding: 15px 0;
    }

    .wallet-address-card {
        background: rgba(255, 255, 255, 0.03);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 16px;
        padding: 16px;
        margin-bottom: 12px;
    }

    .wallet-address-top {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 12px;
    }

    .wallet-address-top h3 {
        margin: 0;
        font-size: 14px;
    }

    .wallet-address-top span {
        font-size: 11px;
        color: rgba(255, 255, 255, 0.5);
    }

    .wallet-qr {
        display: flex;
        justify-content: center;
        margin: 15px 0;
    }

    .wallet-qr img {
        width: 150px;
        height: 150px;
        border-radius: 14px;
        background: #fff;
        padding: 8px;
        box-shadow: 0 5px 20px rgba(0,0,0,0.5);
    }

    .wallet-address-box {
        margin-bottom: 12px;
    }

    .wallet-input {
        width: 100%;
        background: rgba(15, 8, 30, 0.6);
        border: 1px solid rgba(255, 255, 255, 0.15);
        color: #00e676;
        border-radius: 12px;
        padding: 12px 14px;
        font-size: 13px;
        font-family: monospace;
        outline: none;
        text-align: center;
    }

    .wallet-copy-button {
        width: 100%;
        background: linear-gradient(90deg, #ff0055 0%, #b000cd 100%);
        border: none;
        color: #fff;
        padding: 12px;
        border-radius: 12px;
        cursor: pointer;
        font-size: 14px;
        font-weight: bold;
        transition: 0.3s;
        box-shadow: 0 6px 18px rgba(255, 0, 130, 0.3);
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }

    .wallet-copy-button:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 25px rgba(255, 0, 130, 0.5);
    }

    .wallet-actions {
        display: flex;
        flex-direction: column;
        gap: 12px;
        margin-top: 25px;
    }

    .wallet-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        padding: 15px;
        border-radius: 14px;
        font-size: 15px;
        font-weight: bold;
        text-decoration: none;
        transition: 0.3s;
        text-align: center;
    }

    button.wallet-btn {
        width: 100%;
        font-family: inherit;
        cursor: pointer;
    }

    .wallet-btn-mvola {
        background: linear-gradient(90deg, #00c96b 0%, #008f4c 100%);
        color: white;
        box-shadow: 0 8px 22px rgba(0, 201, 107, 0.35);
    }

    .wallet-btn-mvola:hover {
        transform: translateY(-2px);
        box-shadow: 0 12px 28px rgba(0, 201, 107, 0.5);
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
        border: 1px solid rgba(255,255,255,0.1); border-bottom: none; border-radius: 20px 20px 0 0;
        display: flex; justify-content: space-around; align-items: flex-end; padding: 8px 8px 10px;
        z-index: 500; box-shadow: 0 -10px 40px rgba(0,0,0,.55);
    }
    .bottom-nav .nav-item {
        display: flex; flex-direction: column; align-items: center; gap: 3px; text-decoration: none;
        color: rgba(255,255,255,.55); font-size: 10.5px; font-weight: 600; flex: 1; transition: .25s;
    }
    .bottom-nav .nav-item svg { width: 19px; height: 19px; stroke: rgba(255,255,255,.55); }
    .bottom-nav .nav-item.active { color: #ff2ea6; text-shadow: 0 0 10px rgba(255,46,166,.8); }
    .bottom-nav .nav-item.active svg { stroke: #ff2ea6; }

    @keyframes zoomIn { 0%{ opacity:0; transform:translateY(18px) scale(.98); } 100%{ opacity:1; transform:translateY(0) scale(1); } }

    @media (max-width: 768px) {
        .card { padding: 25px 18px; }
        .wallet-title { font-size: 22px; }
        .amount { font-size: 30px; }
    }

    /* === BOX FLOTTANTE HISTORIQUE (dépôts + retraits) === */
    .historique-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        width: 100%;
        height: 100%;
        background: rgba(3, 0, 30, 0.72);
        backdrop-filter: blur(18px);
        -webkit-backdrop-filter: blur(18px);
        z-index: 9998;
        align-items: flex-end;
        justify-content: center;
        -webkit-transform: translateZ(0);
        transform: translateZ(0);
    }

    .historique-overlay.actif {
        display: flex;
    }

    .historique-box {
        width: 100%;
        max-width: 480px;
        max-height: 82vh;
        max-height: 82dvh;
        background: #161c2b;
        border-radius: 20px 20px 0 0;
        display: flex;
        flex-direction: column;
        box-shadow: 0 -10px 40px rgba(0,0,0,0.5);
        padding-bottom: env(safe-area-inset-bottom, 0px);
        animation: glisserVersHaut 0.25s ease;
        -webkit-transform: translateZ(0);
        transform: translateZ(0);
        -webkit-backface-visibility: hidden;
        backface-visibility: hidden;
    }

    @media (min-width: 560px) {
        .historique-overlay { align-items: center; }
        .historique-box { border-radius: 20px; max-height: 78vh; }
    }

    @keyframes glisserVersHaut {
        from { transform: translateY(30px); opacity: 0; }
        to   { transform: translateY(0); opacity: 1; }
    }

    .historique-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 16px 18px 10px;
        flex-shrink: 0;
    }

    .historique-header h3 {
        margin: 0;
        font-size: 17px;
    }

    .historique-close {
        background: rgba(255,255,255,0.08);
        border: none;
        color: #fff;
        width: 32px;
        height: 32px;
        border-radius: 50%;
        font-size: 16px;
        cursor: pointer;
    }

    .historique-filtres {
        display: flex;
        gap: 8px;
        padding: 0 18px 12px;
        flex-shrink: 0;
    }

    .historique-filtre-btn {
        background: rgba(255,255,255,0.06);
        border: 1px solid rgba(255,255,255,0.1);
        color: rgba(255,255,255,0.7);
        padding: 6px 14px;
        border-radius: 20px;
        font-size: 12.5px;
        cursor: pointer;
    }

    .historique-filtre-btn.active {
        background: linear-gradient(90deg, #ff2ea6, #00c96b);
        border-color: transparent;
        color: #fff;
        font-weight: bold;
    }

    /* FIX CSS WEBVIEW APK : Ajustement de flex-basis et min-height */
    .historique-corps {
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
        overscroll-behavior: contain;
        padding: 4px 18px 18px;
        flex: 1 1 auto;
        min-height: 200px;
        width: 100%;
    }

    .historique-chargement,
    .historique-vide,
    .historique-erreur {
        text-align: center;
        color: rgba(255,255,255,0.55);
        padding: 30px 0;
        font-size: 13.5px;
    }

    .historique-ligne {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 0;
        border-bottom: 1px solid rgba(255,255,255,0.06);
    }

    .historique-icone {
        width: 38px;
        height: 38px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 17px;
        flex-shrink: 0;
    }

    .historique-icone.depot { background: rgba(0,201,107,0.15); }
    .historique-icone.retrait { background: rgba(99,179,237,0.15); }

    .historique-details {
        flex: 1;
        min-width: 0;
    }

    .historique-titre-ligne {
        display: flex;
        justify-content: space-between;
        gap: 8px;
        font-size: 14px;
        font-weight: bold;
    }

    .historique-sous-ligne {
        font-size: 11.5px;
        color: rgba(255,255,255,0.5);
        margin-top: 2px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .historique-statut {
        font-size: 10.5px;
        font-weight: bold;
        padding: 2px 8px;
        border-radius: 10px;
        white-space: nowrap;
    }

    .historique-statut.confirme { background: rgba(72,187,120,0.15); color: #9ae6b4; }
    .historique-statut.en_attente { background: rgba(255,183,3,0.15); color: #ffd166; }
    .historique-statut.rejete { background: rgba(245,101,101,0.15); color: #feb2b2; }

    /* === TOPBAR TYPE "TABLEAU DE BORD" === */
    .topbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 22px;
    }
    .topbar-left { display: flex; align-items: center; gap: 14px; }
    .topbar-menu {
        width: 40px; height: 40px; border-radius: 12px;
        background: rgba(255,255,255,0.06);
        border: 1px solid rgba(255,255,255,0.1);
        display: flex; align-items: center; justify-content: center;
        flex-shrink: 0;
    }
    .topbar-menu svg { width: 18px; height: 18px; stroke: #fff; }
    .topbar-hello { font-size: 16px; font-weight: 800; margin: 0; }
    .topbar-sub { font-size: 11.5px; color: rgba(255,255,255,0.55); margin: 2px 0 0; }
    .topbar-right { display: flex; align-items: center; gap: 12px; }
    .topbar-bell {
        position: relative; width: 40px; height: 40px; border-radius: 50%;
        background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1);
        display: flex; align-items: center; justify-content: center;
    }
    .topbar-bell svg { width: 18px; height: 18px; stroke: #fff; }
    .topbar-avatar {
        width: 40px; height: 40px; border-radius: 50%;
        background: linear-gradient(135deg, #ff2ea6, #00c96b);
        display: flex; align-items: center; justify-content: center;
        font-weight: 800; font-size: 15px; color: #fff;
        border: 2px solid rgba(255,255,255,0.25);
        flex-shrink: 0;
    }

    /* === CARTE SOLDE (dashboard) === */
    .balance-card {
        background: linear-gradient(135deg, rgba(0,120,80,.35), rgba(0,40,60,.55));
        border: 1px solid rgba(0,230,150,0.25);
        border-radius: 24px;
        padding: 22px;
        margin-bottom: 22px;
        box-shadow: 0 12px 30px rgba(0,0,0,.3);
    }
    .balance-top-row {
        display: flex; align-items: center; justify-content: space-between;
        margin-bottom: 4px;
    }
    .balance-label {
        font-size: 12px; letter-spacing: .5px; color: rgba(255,255,255,0.65);
        text-transform: uppercase;
        display: flex; align-items: center; gap: 6px;
    }
    .balance-label b { color: #00e676; }
    .balance-eye {
        background: none; border: none; cursor: pointer; padding: 4px;
        display: flex; align-items: center; justify-content: center;
    }
    .balance-eye svg { width: 18px; height: 18px; stroke: rgba(255,255,255,0.75); }
    .balance-amount {
        font-size: 30px; font-weight: 900; color: #fff; margin: 6px 0 2px;
        letter-spacing: -0.5px;
    }
    .balance-sub { font-size: 13.5px; color: rgba(255,255,255,0.6); margin-bottom: 14px; }
    .balance-bottom-row {
        display: flex; align-items: center; justify-content: space-between;
        gap: 10px;
    }
    .balance-network-badge {
        display: inline-flex; align-items: center; gap: 6px;
        background: rgba(0,230,150,0.12);
        border: 1px solid rgba(0,230,150,0.3);
        color: #6fffc4;
        font-size: 11.5px; font-weight: 700;
        padding: 6px 12px; border-radius: 20px;
    }
    .balance-network-badge svg { width: 12px; height: 12px; stroke: #6fffc4; }
    .balance-chart { width: 90px; height: 30px; opacity: .9; }

    /* === GRILLE ACTIONS RAPIDES === */
    .actions-title {
        font-size: 12px; letter-spacing: 1px; text-transform: uppercase;
        color: rgba(255,255,255,0.55); font-weight: 700;
        margin: 4px 0 12px 2px;
    }
    .actions-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 10px;
        margin-bottom: 26px;
    }
    .action-btn {
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        gap: 8px; padding: 14px 6px; border-radius: 18px;
        background: linear-gradient(135deg, rgba(0,120,80,.35), rgba(0,40,60,.55));
        border: 1px solid rgba(0,230,150,0.25);
        box-shadow: 0 12px 30px rgba(0,0,0,.3);
        color: #fff; text-decoration: none; cursor: pointer;
        font-family: inherit; transition: .25s;
    }
    .action-btn:hover { transform: translateY(-2px); background: linear-gradient(135deg, rgba(0,120,80,.45), rgba(0,40,60,.65)); }
    .action-btn span { font-size: 11px; font-weight: 700; text-align: center; line-height: 1.2; }
    .action-icon {
        width: 42px; height: 42px; border-radius: 14px;
        display: flex; align-items: center; justify-content: center;
    }
    .action-icon svg { width: 19px; height: 19px; }
    .action-icon.depot   { background: rgba(0,230,150,0.15); }
    .action-icon.depot svg   { stroke: #00e676; }
    .action-icon.retrait { background: rgba(140,110,255,0.18); }
    .action-icon.retrait svg { stroke: #b39dff; }
    .action-icon.transfert { background: rgba(0,180,255,0.18); }
    .action-icon.transfert svg { stroke: #4fc3ff; }
    .action-icon.historique { background: rgba(255,183,3,0.18); }
    .action-icon.historique svg { stroke: #ffb703; }

    @media (max-width: 380px) {
        .action-btn span { font-size: 10px; }
    }

    /* === CARTE COURS USDT === */
    .cours-card {
        background: linear-gradient(135deg, rgba(0,120,80,.35), rgba(0,40,60,.55));
        border: 1px solid rgba(0,230,150,0.25);
        box-shadow: 0 12px 30px rgba(0,0,0,.3);
        border-radius: 20px;
        padding: 18px 18px 12px;
        margin-bottom: 18px;
    }
    .cours-header {
        display: flex; align-items: center; justify-content: space-between;
        margin-bottom: 10px;
    }
    .cours-title { font-size: 15px; font-weight: 800; color: #fff; }
    .cours-refresh {
        background: none; border: none; cursor: pointer; color: rgba(255,255,255,0.5);
        display: flex; align-items: center; justify-content: center; padding: 4px;
    }
    .cours-refresh svg { width: 15px; height: 15px; stroke: currentColor; }
    .cours-valeur-row { display: flex; align-items: baseline; gap: 10px; margin-bottom: 10px; flex-wrap: wrap; }
    .cours-valeur { font-size: 24px; font-weight: 900; color: #00e676; }
    .cours-badge {
        font-size: 11.5px; font-weight: 800; padding: 4px 9px; border-radius: 20px;
        display: inline-flex; align-items: center; gap: 4px;
    }
    .cours-badge.hausse { background: rgba(0,230,150,0.15); color: #6fffc4; }
    .cours-badge.baisse { background: rgba(245,101,101,0.15); color: #feb2b2; }
    .cours-badge.neutre { background: rgba(255,255,255,0.08); color: rgba(255,255,255,0.6); }
    .cours-chart-grand { width: 100%; height: 90px; display: block; }
    .cours-axe {
        display: flex; justify-content: space-between;
        font-size: 10px; color: rgba(255,255,255,0.4); margin-top: 4px;
    }

    /* Sélecteur de période */
    .cours-periode-row { display: flex; gap: 6px; margin-bottom: 10px; }
    .cours-periode-btn {
        background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1);
        color: rgba(255,255,255,0.55); font-size: 11px; font-weight: 700;
        padding: 4px 10px; border-radius: 20px; cursor: pointer;
        transition: background .15s, color .15s, border-color .15s;
    }
    .cours-periode-btn.active {
        background: rgba(0,230,150,0.18); border-color: rgba(0,230,150,0.4); color: #6fffc4;
    }

    /* Grille + repères min/max */
    .cours-chart-wrap { position: relative; display: flex; }
    .cours-chart-labels {
        position: absolute; left: 0; top: 0; bottom: 0; width: 34px;
        display: flex; flex-direction: column; justify-content: space-between;
        font-size: 9px; color: rgba(255,255,255,0.35); font-weight: 600;
        pointer-events: none; z-index: 2;
    }
    .cours-chart-grand { margin-left: 34px; width: calc(100% - 34px); }
    .cours-grid-line { stroke: rgba(255,255,255,0.08); stroke-width: 1; stroke-dasharray: 3 3; }

    /* Tracé animé */
    #courbeTauxGrandLigne { transition: stroke-dashoffset 1s ease-out; }

    /* Point interactif + tooltip */
    #coursPointHover { pointer-events: none; }
    .cours-tooltip {
        position: absolute; top: 0; left: 0;
        background: rgba(10,20,18,0.95); border: 1px solid rgba(0,230,150,0.3);
        color: #fff; font-size: 11px; font-weight: 700; white-space: nowrap;
        padding: 5px 9px; border-radius: 8px; pointer-events: none;
        transform: translate(-50%, -115%); z-index: 3;
        box-shadow: 0 6px 16px rgba(0,0,0,.35);
    }
    .cours-tooltip .cours-tooltip-date {
        display: block; font-size: 9.5px; font-weight: 600; color: rgba(255,255,255,0.55); margin-top: 1px;
    }

    /* === CARTE TRANSACTIONS RÉCENTES === */
    .transactions-card {
        background: rgba(255,255,255,0.03);
        border: 1px solid rgba(255,255,255,0.08);
        border-radius: 20px;
        padding: 18px;
        margin-bottom: 22px;
    }
    .transactions-header {
        display: flex; align-items: center; justify-content: space-between;
        margin-bottom: 6px;
    }
    .transactions-title { font-size: 15px; font-weight: 800; color: #fff; }
    .transactions-voir-tout {
        background: none; border: none; cursor: pointer;
        font-size: 12.5px; font-weight: 700; color: #6fb8ff;
        display: flex; align-items: center; gap: 2px; padding: 4px 0;
        font-family: inherit;
    }

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

<!-- ÉCRAN DE BIENVENUE ANIMÉ (SPLASH SCREEN) -->
<div id="splash-screen">
    <div class="splash-logo-container">
        <img src="logousdtmvola.png" alt="Bienvenue" class="splash-logo">
    </div>
    <div class="splash-text">USDT Mvola</div>
    <div class="splash-loader"></div>
</div>

<div class="main-container">
    <div class="card">
        
        <!-- Barre du haut façon tableau de bord -->
        <div class="topbar">
            <div class="topbar-left">
                <div>
                    <p class="topbar-hello"><span data-fr="Bonjour," data-en="Hello,">Bonjour,</span> <?= htmlspecialchars($userName); ?> 👋</p>
                    <p class="topbar-sub" data-fr="Bienvenue sur votre portefeuille" data-en="Welcome to your wallet">Bienvenue sur votre portefeuille</p>
                </div>
            </div>
            <div class="topbar-right">
                <div class="topbar-bell">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 10-12 0c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 01-3.46 0"></path></svg>
                </div>
                <div class="topbar-avatar"><?= htmlspecialchars($userInitial); ?></div>
            </div>
        </div>

        <!-- Carte Solde -->
        <div class="balance-card">
            <div class="balance-top-row">
                <span class="balance-label"><span data-fr="Solde" data-en="Balance">Solde</span> <b data-fr="total" data-en="total">total</b></span>
                <button type="button" class="balance-eye" onclick="toggleVisibiliteSolde(this)" id="btnOeilSolde" aria-label="Afficher/masquer le solde">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                </button>
            </div>
            <div class="balance-amount"><span id="soldeUsdtDisplay" data-valeur="<?= $solde_usdt; ?> USDT"><?= $solde_usdt; ?> USDT</span></div>
            <div class="balance-sub" id="soldeArDisplay" data-valeur="≈ <?= $solde_ar; ?> Ar">≈ <?= $solde_ar; ?> Ar</div>
            <div class="balance-bottom-row">
                <span class="balance-network-badge">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    <span data-fr="Réseau BEP20" data-en="BEP20 Network">Réseau BEP20</span>
                </span>
                <div style="text-align:right;">
                    <svg class="balance-chart" id="courbeTauxSvg" viewBox="0 0 90 30" preserveAspectRatio="none">
                        <polyline id="courbeTauxLigne" points="" fill="none" stroke="#00e676" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></polyline>
                    </svg>
                    <div style="font-size:9.5px; color:rgba(255,255,255,0.45); margin-top:2px;">USDT/Ar</div>
                </div>
            </div>
        </div>

        <!-- Actions rapides -->
        <div class="actions-title" data-fr="Actions rapides" data-en="Quick actions">Actions rapides</div>
        <div class="actions-grid" style="grid-template-columns: repeat(3, 1fr);">
            <button type="button" class="action-btn" onclick="ouvrirDepot()">
                <span class="action-icon depot">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="19" x2="12" y2="5"></line><polyline points="5 12 12 5 19 12"></polyline></svg>
                </span>
                <span data-fr="Dépôt USDT" data-en="USDT Deposit">Dépôt USDT</span>
            </button>
            <a href="?page=retrait_mvola" class="action-btn">
                <span class="action-icon retrait">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 1l4 4-4 4"></path><path d="M3 11V9a4 4 0 014-4h14"></path><path d="M7 23l-4-4 4-4"></path><path d="M21 13v2a4 4 0 01-4 4H3"></path></svg>
                </span>
                <span data-fr="Retrait Mvola" data-en="Mvola Withdrawal">Retrait Mvola</span>
            </a>
            <button type="button" class="action-btn" onclick="ouvrirHistorique()">
                <span class="action-icon historique">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                </span>
                <span data-fr="Historique" data-en="History">Historique</span>
            </button>
        </div>

        <!-- Carte Cours USDT -->
        <div class="cours-card">
            <div class="cours-header">
                <span class="cours-title" data-fr="Cours USDT" data-en="USDT Rate">Cours USDT</span>
                <button type="button" class="cours-refresh" onclick="chargerCourbeTaux()" aria-label="Rafraîchir">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"></polyline><polyline points="1 20 1 14 7 14"></polyline><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"></path></svg>
                </button>
            </div>
            <div class="cours-valeur-row">
                <span class="cours-valeur" id="coursValeurGrande"><?= $coursCarte; ?> Ar</span>
                <span class="cours-badge neutre" id="coursBadgeVariation">—</span>
            </div>
            <div class="cours-periode-row" id="coursPeriodeRow">
                <button type="button" class="cours-periode-btn" data-heures="24" onclick="chargerCourbeTaux(24)">24h</button>
                <button type="button" class="cours-periode-btn active" data-heures="48" onclick="chargerCourbeTaux(48)">48h</button>
                <button type="button" class="cours-periode-btn" data-heures="168" onclick="chargerCourbeTaux(168)">7j</button>
            </div>
            <div class="cours-chart-wrap">
                <div class="cours-chart-labels" id="coursChartLabels"></div>
                <svg class="cours-chart-grand" id="courbeTauxGrandSvg" viewBox="0 0 300 90" preserveAspectRatio="none">
                    <defs>
                        <linearGradient id="coursGradientFill" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="#00e676" stop-opacity="0.35"></stop>
                            <stop offset="100%" stop-color="#00e676" stop-opacity="0"></stop>
                        </linearGradient>
                    </defs>
                    <g id="coursGridLines"></g>
                    <polygon id="courbeTauxGrandZone" points="" fill="url(#coursGradientFill)" stroke="none"></polygon>
                    <polyline id="courbeTauxGrandLigne" points="" fill="none" stroke="#00e676" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"></polyline>
                    <circle id="coursPointHover" r="3.5" fill="#00e676" stroke="#03150f" stroke-width="1.5" style="display:none;"></circle>
                    <rect id="coursHitZone" x="0" y="0" width="300" height="90" fill="transparent"></rect>
                </svg>
                <div class="cours-tooltip" id="coursTooltip" style="display:none;"></div>
            </div>
            <div class="cours-axe" id="coursAxeLabels"></div>
        </div>

        <!-- Carte Transactions récentes -->
        <div class="transactions-card">
            <div class="transactions-header">
                <span class="transactions-title" data-fr="Transactions récentes" data-en="Recent transactions">Transactions récentes</span>
                <button type="button" class="transactions-voir-tout" onclick="ouvrirHistorique()" data-fr="Voir tout ›" data-en="See all ›">Voir tout ›</button>
            </div>
            <div id="listeTransactionsRecentes">
                <div class="historique-chargement" data-fr="Chargement..." data-en="Loading...">Chargement...</div>
            </div>
        </div>

        <div class="wallet-actions">
            <a href="?page=compte" class="wallet-btn wallet-btn-secondary" data-fr="Retour au compte" data-en="Back to account">Retour au compte</a>
        </div>

    </div>
</div>

<!-- ================= BOX FLOTTANTE : DÉPÔT USDT (adresses) ================= -->
<div id="overlayDepot" class="historique-overlay" onclick="if(event.target===this) fermerDepot()">
    <div class="historique-box">
        <div class="historique-header">
            <h3 data-fr="💰 Dépôt USDT" data-en="💰 USDT Deposit">💰 Dépôt USDT</h3>
            <button type="button" class="historique-close" onclick="fermerDepot()" aria-label="Fermer">✕</button>
        </div>

        <div class="historique-corps">
            <button type="button" class="wallet-retour-reseau wallet-retour-dashboard" onclick="fermerDepot()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                <span data-fr="Retour" data-en="Back">Retour</span>
            </button>

            <p class="wallet-section-title" style="margin-top:0;" data-fr="Choisissez un réseau" data-en="Choose a network">Choisissez un réseau</p>

            <!-- Sélection des réseaux (Accordéon 100% CSS, sans JS : fiable même
                 sur les WebView Android/iOS où le display:none->block piloté
                 par script ne se repeint pas toujours correctement). -->
            <input type="radio" name="depotReseau" id="radioNone" class="wallet-network-radio" checked>
            <input type="radio" name="depotReseau" id="radioBep20" class="wallet-network-radio">

            <div class="wallet-network-cards">

                <!-- Option BEP20 -->
                <label class="wallet-network-card" for="radioBep20" id="btnToggleBep20">
                    <div class="wallet-network-left">
                        <div class="wallet-network-icon bep20">₿</div>
                        <div>
                            <h3 data-fr="USDT BEP20" data-en="USDT BEP20">USDT BEP20</h3>
                            <p data-fr="Retrait Tango Live" data-en="Tango Live withdrawal">Retrait Tango Live</p>
                        </div>
                    </div>
                    <div class="wallet-network-arrow">▼</div>
                </label>

            </div>

            <!-- SECTION DEPLIABLE : BEP20 -->
            <div id="section-bep20" class="wallet-address-section">
                <div class="wallet-address-sticky-top">
                    <label for="radioNone" class="wallet-retour-reseau">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                        <span data-fr="Retour au choix du réseau" data-en="Back to network choice">Retour au choix du réseau</span>
                    </label>
                    <p class="wallet-mini-title" data-fr="📍 Retrait Tango Live (Binance Smart Chain)" data-en="📍 Tango Live withdrawal (Binance Smart Chain)">📍 Retrait Tango Live (Binance Smart Chain)</p>
                </div>

                <?php foreach ($bep20Addresses as $bep20): ?>
                    <div class="wallet-address-card">
                        <div class="wallet-address-top">
                            <div class="wallet-network-icon bep20" style="width:30px; height:30px; font-size:14px;">₿</div>
                            <div>
                                <h3>USDT BEP20 #<?= (int)$bep20['slot']; ?></h3>
                                <span data-fr="BNB Smart Chain (BEP20)" data-en="BNB Smart Chain (BEP20)">BNB Smart Chain (BEP20)</span>
                            </div>
                        </div>

                        <?php if (!empty($bep20['adresse'])): ?>
                            <div class="wallet-qr">
                                <img src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=<?= urlencode($bep20['adresse']); ?>" alt="QR Code BEP20 Slot <?= (int)$bep20['slot']; ?>">
                            </div>
                        <?php endif; ?>

                        <div class="wallet-address-box">
                            <input readonly id="bep20_<?= (int)$bep20['slot']; ?>" class="wallet-input" value="<?= htmlspecialchars($bep20['adresse'] ?? ''); ?>" data-fr-placeholder="Adresse en cours de génération..." data-en-placeholder="Address being generated..." placeholder="Adresse en cours de génération...">
                        </div>

                        <button type="button" class="wallet-copy-button" onclick="copierAdresse('bep20_<?= (int)$bep20['slot']; ?>', this)">
                            📋 <span data-fr="Copier Slot #<?= (int)$bep20['slot']; ?>" data-en="Copy Slot #<?= (int)$bep20['slot']; ?>">Copier Slot #<?= (int)$bep20['slot']; ?></span>
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- ================= BOX FLOTTANTE : HISTORIQUE (dépôts + retraits) ================= -->
<div id="overlayHistorique" class="historique-overlay" onclick="if(event.target===this) fermerHistorique()">
    <div class="historique-box">
        <div class="historique-header">
            <h3 data-fr="📜 Historique" data-en="📜 History">📜 Historique</h3>
            <button type="button" class="historique-close" onclick="fermerHistorique()" aria-label="Fermer">✕</button>
        </div>

        <div class="historique-filtres">
            <button type="button" class="historique-filtre-btn active" data-filtre="tout" onclick="filtrerHistorique('tout', this)" data-fr="Tout" data-en="All">Tout</button>
            <button type="button" class="historique-filtre-btn" data-filtre="depot" onclick="filtrerHistorique('depot', this)" data-fr="Dépôts" data-en="Deposits">Dépôts</button>
            <button type="button" class="historique-filtre-btn" data-filtre="retrait" onclick="filtrerHistorique('retrait', this)" data-fr="Retraits" data-en="Withdrawals">Retraits</button>
        </div>

        <div class="historique-corps" id="corpsHistorique">
            <div class="historique-chargement" data-fr="Chargement..." data-en="Loading...">Chargement...</div>
        </div>
    </div>
</div>

<script>
// Gestion de l'animation de bienvenue
(function(){
    const splash = document.getElementById('splash-screen');

    function ajusterTailleSplash(){
        if(!splash) return;
        splash.style.width = window.innerWidth + 'px';
        splash.style.height = window.innerHeight + 'px';
    }

    ajusterTailleSplash();
    window.addEventListener('resize', ajusterTailleSplash);
    window.addEventListener('orientationchange', ajusterTailleSplash);

    function demarrerCompteAReboursSplash(){
        ajusterTailleSplash();
        setTimeout(() => {
            if (splash) {
                splash.classList.add('hidden');
            }
        }, 3000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', demarrerCompteAReboursSplash);
    } else {
        demarrerCompteAReboursSplash();
    }

    window.addEventListener('load', () => {
        if (splash && !splash.classList.contains('hidden')) {
            splash.classList.add('hidden');
        }
    });
})();

// L'ouverture/fermeture de la section BEP20 est désormais gérée en
// CSS pur (voir #radioBep20 + sélecteurs :checked dans le
// <style>), donc plus aucun JS n'est nécessaire ici — ce qui élimine
// définitivement les soucis de repaint WebView sur cet accordéon.

// === Mise à jour automatique du solde (détection dépôt en temps réel) ===
let dernierSolde = <?= (float)($wallet['solde'] ?? 0); ?>;

async function rafraichirSolde(){
    try{
        const res = await fetch("wallet/get_solde.php", { cache: "no-store" });
        if(!res.ok) return;
        const data = await res.json();
        if(data.error) return;

        const nouveauSolde = parseFloat(data.solde_usdt);
        if(isNaN(nouveauSolde)) return;

        if(nouveauSolde > dernierSolde + 0.0001){
            afficherNotifDepot(nouveauSolde - dernierSolde);
        }
        dernierSolde = nouveauSolde;

        const elUsdt = document.getElementById("soldeUsdtDisplay");
        const elAr   = document.getElementById("soldeArDisplay");

        const texteUsdt = nouveauSolde.toLocaleString('fr-FR', {minimumFractionDigits:2, maximumFractionDigits:2}) + " USDT";
        const texteAr   = "≈ " + (data.solde_ar !== null && data.solde_ar !== undefined
            ? Math.round(data.solde_ar).toLocaleString('fr-FR')
            : "—") + " Ar";

        if(elUsdt){
            elUsdt.setAttribute('data-valeur', texteUsdt);
            if(!soldeMasque) elUsdt.innerText = texteUsdt;
        }
        if(elAr){
            elAr.setAttribute('data-valeur', texteAr);
            if(!soldeMasque) elAr.innerText = texteAr;
        }
    } catch(e){
        console.error("Erreur rafraîchissement solde :", e);
    }
}

// === Masquer/afficher le solde (bouton œil) ===
let soldeMasque = false;

function toggleVisibiliteSolde(btnElement){
    soldeMasque = !soldeMasque;

    const elUsdt = document.getElementById("soldeUsdtDisplay");
    const elAr   = document.getElementById("soldeArDisplay");

    if(soldeMasque){
        if(elUsdt) elUsdt.innerText = "•••••• USDT";
        if(elAr)   elAr.innerText   = "≈ •••••• Ar";
        if(btnElement) btnElement.innerHTML = `
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.94 10.94 0 0112 20c-7 0-11-8-11-8a20.6 20.6 0 015.06-5.94M9.9 4.24A10.5 10.5 0 0112 4c7 0 11 8 11 8a20.5 20.5 0 01-2.16 3.19M14.12 14.12a3 3 0 11-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>
        `;
    } else {
        if(elUsdt) elUsdt.innerText = elUsdt.getAttribute('data-valeur') || elUsdt.innerText;
        if(elAr)   elAr.innerText   = elAr.getAttribute('data-valeur') || elAr.innerText;
        if(btnElement) btnElement.innerHTML = `
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"></path><circle cx="12" cy="12" r="3"></circle></svg>
        `;
    }
}

// === Box flottante Dépôt USDT (adresses) ===
let depotOuverteViaHash = false;

let courbeHeuresActuelles = 48;
let courbeDernierPoints = [];   // [{taux_client, date, x, y}] en coordonnées SVG (300x90)
const COURBE_LARGEUR = 300, COURBE_HAUTEUR = 90, COURBE_MARGE = 6;

async function chargerCourbeTaux(heures){
    if(heures){
        courbeHeuresActuelles = heures;
        document.querySelectorAll('.cours-periode-btn').forEach(btn => {
            btn.classList.toggle('active', Number(btn.dataset.heures) === heures);
        });
    }

    const ligne = document.getElementById('courbeTauxLigne');
    const ligneGrande = document.getElementById('courbeTauxGrandLigne');
    const zoneGrande = document.getElementById('courbeTauxGrandZone');
    const badge = document.getElementById('coursBadgeVariation');
    const axe = document.getElementById('coursAxeLabels');
    const labels = document.getElementById('coursChartLabels');
    const grille = document.getElementById('coursGridLines');

    try{
        const res = await fetch('wallet/get_taux_historique.php?heures=' + courbeHeuresActuelles, { cache: 'no-store' });
        if(!res.ok) return;
        const data = await res.json();
        if(data.error || !Array.isArray(data.points) || data.points.length < 2){
            const svg = document.getElementById('courbeTauxSvg');
            const svgGrand = document.getElementById('courbeTauxGrandSvg');
            if(svg) svg.style.visibility = 'hidden';
            if(svgGrand) svgGrand.style.visibility = 'hidden';
            if(badge){ badge.textContent = 'Historique en cours'; badge.className = 'cours-badge neutre'; }
            if(axe) axe.innerHTML = '';
            if(labels) labels.innerHTML = '';
            if(grille) grille.innerHTML = '';
            courbeDernierPoints = [];
            return;
        }

        const valeurs = data.points.map(p => Number(p.taux_client)).filter(v => !isNaN(v));
        const min = Math.min(...valeurs);
        const max = Math.max(...valeurs);
        const ecart = (max - min) || 1;
        const n = valeurs.length;

        const tracer = (largeur, hauteur, marge) => valeurs.map((v, i) => {
            const x = (i / (n - 1)) * largeur;
            const y = hauteur - marge - ((v - min) / ecart) * (hauteur - marge * 2);
            return { x: Number(x.toFixed(1)), y: Number(y.toFixed(1)) };
        });

        const ptsPetit = tracer(90, 30, 2);
        const ptsGrand = tracer(COURBE_LARGEUR, COURBE_HAUTEUR, COURBE_MARGE);

        const versChaine = pts => pts.map(p => p.x + ',' + p.y).join(' ');

        if(ligne) ligne.setAttribute('points', versChaine(ptsPetit));
        if(ligneGrande) ligneGrande.setAttribute('points', versChaine(ptsGrand));

        // Zone dégradée sous la courbe : mêmes points + fermeture en bas
        if(zoneGrande){
            const bas = COURBE_HAUTEUR - 1;
            const zonePoints = versChaine(ptsGrand) + ` ${ptsGrand[ptsGrand.length - 1].x},${bas} ${ptsGrand[0].x},${bas}`;
            zoneGrande.setAttribute('points', zonePoints);
        }

        // Grille + labels min/mid/max
        if(grille){
            const milieu = (min + max) / 2;
            const yMax = COURBE_HAUTEUR - COURBE_MARGE - ((max - min) / ecart) * (COURBE_HAUTEUR - COURBE_MARGE * 2);
            const yMid = COURBE_HAUTEUR - COURBE_MARGE - ((milieu - min) / ecart) * (COURBE_HAUTEUR - COURBE_MARGE * 2);
            const yMin = COURBE_HAUTEUR - COURBE_MARGE - ((min - min) / ecart) * (COURBE_HAUTEUR - COURBE_MARGE * 2);
            grille.innerHTML = [yMax, yMid, yMin].map(y =>
                `<line class="cours-grid-line" x1="0" y1="${y.toFixed(1)}" x2="${COURBE_LARGEUR}" y2="${y.toFixed(1)}"></line>`
            ).join('');
            if(labels){
                const fmtVal = v => Math.round(v).toLocaleString('fr-FR');
                labels.innerHTML = `<span>${fmtVal(max)}</span><span>${fmtVal(milieu)}</span><span>${fmtVal(min)}</span>`;
            }
        }

        // Mémoriser les points (coords SVG + valeur + date) pour le tooltip interactif
        courbeDernierPoints = ptsGrand.map((p, i) => ({
            x: p.x, y: p.y,
            taux_client: valeurs[i],
            date: data.points[i].date
        }));

        // Animation : la courbe se dessine progressivement au chargement
        if(ligneGrande){
            requestAnimationFrame(() => {
                const longueur = ligneGrande.getTotalLength();
                ligneGrande.style.transition = 'none';
                ligneGrande.style.strokeDasharray = longueur;
                ligneGrande.style.strokeDashoffset = longueur;
                ligneGrande.getBoundingClientRect(); // force reflow
                requestAnimationFrame(() => {
                    ligneGrande.style.transition = 'stroke-dashoffset 1s ease-out';
                    ligneGrande.style.strokeDashoffset = '0';
                });
            });
        }

        const premier = valeurs[0];
        const dernier = valeurs[n - 1];
        if(badge && premier > 0){
            const variation = ((dernier - premier) / premier) * 100;
            const signe = variation > 0 ? '▲' : (variation < 0 ? '▼' : '—');
            badge.textContent = signe + ' ' + Math.abs(variation).toFixed(2) + '%';
            badge.className = 'cours-badge ' + (variation > 0 ? 'hausse' : (variation < 0 ? 'baisse' : 'neutre'));
        }

        if(axe){
            const fmt = (iso) => {
                const d = new Date(iso.replace(' ', 'T'));
                if(isNaN(d.getTime())) return '';
                return d.toLocaleDateString('fr-FR', {day:'2-digit', month:'2-digit'}) + ' ' + d.toLocaleTimeString('fr-FR', {hour:'2-digit', minute:'2-digit'});
            };
            axe.innerHTML = `<span>${fmt(data.points[0].date)}</span><span>${fmt(data.points[n - 1].date)}</span>`;
        }
    } catch(e){
        console.error('Erreur chargement courbe taux :', e);
    }
}
chargerCourbeTaux();

// === Point interactif + tooltip au survol/tap sur la courbe ===
(function initCoursTooltip(){
    const svg = document.getElementById('courbeTauxGrandSvg');
    const hitZone = document.getElementById('coursHitZone');
    const point = document.getElementById('coursPointHover');
    const tooltip = document.getElementById('coursTooltip');
    if(!svg || !hitZone || !point || !tooltip) return;

    const fmtDate = (iso) => {
        const d = new Date(iso.replace(' ', 'T'));
        if(isNaN(d.getTime())) return '';
        return d.toLocaleDateString('fr-FR', {day:'2-digit', month:'2-digit'}) + ' à ' + d.toLocaleTimeString('fr-FR', {hour:'2-digit', minute:'2-digit'});
    };

    function positionner(clientX){
        if(!courbeDernierPoints.length) return;
        const rect = svg.getBoundingClientRect();
        const xSvg = ((clientX - rect.left) / rect.width) * COURBE_LARGEUR;

        // Trouver le point le plus proche en x
        let plusProche = courbeDernierPoints[0];
        let ecartMin = Math.abs(plusProche.x - xSvg);
        for(const p of courbeDernierPoints){
            const ecart = Math.abs(p.x - xSvg);
            if(ecart < ecartMin){ ecartMin = ecart; plusProche = p; }
        }

        point.setAttribute('cx', plusProche.x);
        point.setAttribute('cy', plusProche.y);
        point.style.display = 'block';

        const xPixel = (plusProche.x / COURBE_LARGEUR) * rect.width;
        const yPixel = (plusProche.y / COURBE_HAUTEUR) * rect.height;
        tooltip.style.left = xPixel + 'px';
        tooltip.style.top = yPixel + 'px';
        tooltip.innerHTML = Math.round(plusProche.taux_client).toLocaleString('fr-FR') + ' Ar'
            + '<span class="cours-tooltip-date">' + fmtDate(plusProche.date) + '</span>';
        tooltip.style.display = 'block';
    }

    function cacher(){
        point.style.display = 'none';
        tooltip.style.display = 'none';
    }

    hitZone.addEventListener('mousemove', e => positionner(e.clientX));
    hitZone.addEventListener('mouseleave', cacher);
    hitZone.addEventListener('touchstart', e => { positionner(e.touches[0].clientX); }, { passive: true });
    hitZone.addEventListener('touchmove', e => { positionner(e.touches[0].clientX); }, { passive: true });
    hitZone.addEventListener('touchend', cacher);
})();

async function actualiserValeurCours(){
    const el = document.getElementById('coursValeurGrande');
    if(!el) return;

    try{
        const res = await fetch('wallet/get_taux.php', { cache: 'no-store' });
        if(!res.ok) return;
        const data = await res.json();
        if(data.error || data.taux_client === undefined) return;

        el.textContent = Number(data.taux_client).toLocaleString('fr-FR', {maximumFractionDigits: 0}) + ' Ar';
    } catch(e){
        console.error('Erreur rafraîchissement cours :', e);
    }
}
setInterval(actualiserValeurCours, 15000);
setInterval(chargerCourbeTaux, 45000);

async function chargerTransactionsRecentes(){
    const conteneur = document.getElementById('listeTransactionsRecentes');
    if(!conteneur) return;

    try{
        const res = await fetch('wallet/historique.php', { cache: 'no-store' });
        const data = await res.json();

        if(data.error || !Array.isArray(data.historique)){
            conteneur.innerHTML = '<div class="historique-erreur">Impossible de charger les transactions.</div>';
            return;
        }

        const items = data.historique.slice(0, 3);
        if(items.length === 0){
            conteneur.innerHTML = '<div class="historique-vide">Aucune opération pour le moment.</div>';
            return;
        }

        conteneur.innerHTML = items.map(ligneHistorique).join('');
    } catch(e){
        conteneur.innerHTML = '<div class="historique-erreur">Connexion indisponible.</div>';
    }
}
chargerTransactionsRecentes();

function ouvrirDepot(){
    const overlay = document.getElementById('overlayDepot');
    if(!overlay) return;

    overlay.classList.add('actif');
    document.body.style.overflow = 'hidden';

    const boiteDepot = overlay.querySelector('.historique-box');
    if(boiteDepot) void boiteDepot.offsetHeight;

    // 🩹 Sur certaines WebView, history.pushState()/history.back() ne se
    // comportent pas comme dans un vrai navigateur : au lieu de changer
    // juste l'état interne (popstate), ça déclenche un VRAI rechargement
    // de la page — ce qui réinitialise tout, y compris le réseau
    // BEP20 déjà choisi (d'où le "ça revient tout le temps au choix du
    // réseau"). On désactive donc ce hook uniquement en mode WebView : le
    // bouton ✕ / "Retour" suffit largement à fermer la fenêtre dans l'app.
    const estWebview = document.documentElement.classList.contains('webview-mode');
    if(!estWebview && !depotOuverteViaHash){
        history.pushState({ depotOuverte: true }, '');
        depotOuverteViaHash = true;
    }
}

function fermerDepot(){
    const overlay = document.getElementById('overlayDepot');
    if(!overlay) return;

    overlay.classList.remove('actif');
    document.body.style.overflow = '';

    if(depotOuverteViaHash){
        depotOuverteViaHash = false;
        if(history.state && history.state.depotOuverte){
            history.back();
        }
    }
}

// --- Mémorisation du réseau choisi dans "Dépôt USDT" ---
// (un seul réseau désormais : BEP20 ; le filet de sécurité reste en place
// pour le bouton "Retour" du mode WebView)
(function(){
    const CLE_RESEAU_DEPOT = 'depotReseauChoisi';

    function restaurerReseauDepot(){
        try{
            const choix = sessionStorage.getItem(CLE_RESEAU_DEPOT);
            if(choix === 'bep20'){
                const radio = document.getElementById('radioBep20');
                if(radio) radio.checked = true;
            }
        } catch(e) {}
    }

    function ecouterChoixReseauDepot(){
        ['radioBep20', 'radioNone'].forEach(function(id){
            const radio = document.getElementById(id);
            if(!radio) return;
            radio.addEventListener('change', function(){
                if(!this.checked) return;
                try{
                    if(id === 'radioNone'){
                        sessionStorage.removeItem(CLE_RESEAU_DEPOT);
                    } else {
                        sessionStorage.setItem(CLE_RESEAU_DEPOT, 'bep20');
                    }
                } catch(e) {}
            });
        });
    }

    if(document.readyState === 'loading'){
        document.addEventListener('DOMContentLoaded', function(){
            restaurerReseauDepot();
            ecouterChoixReseauDepot();
        });
    } else {
        restaurerReseauDepot();
        ecouterChoixReseauDepot();
    }
})();

function afficherNotifDepot(montant){
    const notif = document.createElement("div");
    notif.textContent = "💰 Dépôt reçu : +" + montant.toFixed(2) + " USDT";
    notif.style.cssText = "position:fixed; top:20px; left:50%; transform:translateX(-50%); background:linear-gradient(90deg,#00c96b,#008f4c); color:#fff; padding:12px 22px; border-radius:14px; font-weight:bold; z-index:10000; box-shadow:0 8px 22px rgba(0,201,107,0.4); animation:zoomIn .3s ease; max-width:90%; text-align:center;";
    document.body.appendChild(notif);
    setTimeout(() => {
        notif.style.transition = "opacity .5s ease";
        notif.style.opacity = "0";
        setTimeout(() => notif.remove(), 500);
    }, 4000);
}

setInterval(rafraichirSolde, 5000);

function copierAdresse(id, btnElement) {
    let input = document.getElementById(id);
    if (!input || !input.value) return;

    input.select();
    input.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(input.value);

    const originalHTML = btnElement.innerHTML;
    btnElement.innerHTML = `
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#00e676" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="20 6 9 17 4 12"></polyline>
        </svg>
        <span style="color:#00e676;">Adresse copiée !</span>
    `;
    btnElement.style.background = "rgba(0, 230, 118, 0.15)";
    btnElement.style.border = "1px solid #00e676";

    setTimeout(() => {
        btnElement.innerHTML = originalHTML;
        btnElement.style.background = "";
        btnElement.style.border = "";
    }, 2000);
}

// === Box flottante Historique (dépôts + retraits) ===
let historiqueChargee = null;
let intervalHistorique = null;
let filtreHistoriqueActif = 'tout';
let historiqueOuverteViaHash = false;

function echapperHtml(txt){
    const div = document.createElement('div');
    div.innerText = (txt === null || txt === undefined) ? '' : String(txt);
    return div.innerHTML;
}

function formatDateHistorique(brut){
    if(!brut) return '';
    const d = new Date(String(brut).replace(' ', 'T'));
    if(isNaN(d.getTime())) return echapperHtml(brut);
    return d.toLocaleDateString('fr-FR') + ' ' + d.toLocaleTimeString('fr-FR', {hour:'2-digit', minute:'2-digit'});
}

function libelleStatut(statut){
    if(statut === 'confirme') return 'Confirmé';
    if(statut === 'rejete') return 'Rejeté';
    return 'En attente';
}

function ligneHistorique(item){
    const estDepot = item.type === 'depot';
    const icone = estDepot ? '⬇️' : '⬆️';
    const titre = estDepot
        ? ('+' + Number(item.montant_usdt).toLocaleString('fr-FR', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' USDT')
        : ('-' + Number(item.montant_usdt).toLocaleString('fr-FR', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' USDT');

    let sousLigne = echapperHtml(item.network || '');
    if(estDepot && item.reference){
        sousLigne += ' · ' + echapperHtml(String(item.reference).slice(0, 14)) + '…';
    }
    if(!estDepot && item.numero_mvola){
        sousLigne += ' · ' + echapperHtml(item.numero_mvola) + ' · ' + Math.round(item.montant_mga || 0).toLocaleString('fr-FR') + ' Ar';
    }
    const dateAffichee = formatDateHistorique(item.date);
    if(dateAffichee) sousLigne += ' · ' + dateAffichee;

    return `
        <div class="historique-ligne" data-type="${item.type}">
            <div class="historique-icone ${item.type}">${icone}</div>
            <div class="historique-details">
                <div class="historique-titre-ligne">
                    <span>${estDepot ? 'Dépôt reçu' : 'Retrait Mvola'}</span>
                    <span class="historique-statut ${echapperHtml(item.statut)}">${libelleStatut(item.statut)}</span>
                </div>
                <div class="historique-sous-ligne">${titre} · ${sousLigne}</div>
            </div>
        </div>
    `;
}

function rendreHistorique(){
    const corps = document.getElementById('corpsHistorique');
    if(!corps || !historiqueChargee) return;

    const liste = filtreHistoriqueActif === 'tout'
        ? historiqueChargee
        : historiqueChargee.filter(item => item.type === filtreHistoriqueActif);

    if(liste.length === 0){
        corps.innerHTML = '<div class="historique-vide">Aucune opération pour le moment.</div>';
        return;
    }

    corps.innerHTML = liste.map(ligneHistorique).join('');
}

function filtrerHistorique(filtre, btnElement){
    filtreHistoriqueActif = filtre;
    document.querySelectorAll('.historique-filtre-btn').forEach(b => b.classList.remove('active'));
    if(btnElement) btnElement.classList.add('active');
    rendreHistorique();
}

async function chargerHistorique(){
    const corps = document.getElementById('corpsHistorique');
    if(corps) corps.innerHTML = '<div class="historique-chargement">Chargement...</div>';

    try{
        const res = await fetch('wallet/historique.php', { cache: 'no-store' });
        const data = await res.json();

        if(data.error){
            if(corps) corps.innerHTML = '<div class="historique-erreur">Impossible de charger l\'historique.</div>';
            return;
        }

        historiqueChargee = data.historique || [];
        rendreHistorique();
    } catch(e){
        if(corps) corps.innerHTML = '<div class="historique-erreur">Connexion indisponible. Réessaie.</div>';
    }
}

function ouvrirHistorique(){
    const overlay = document.getElementById('overlayHistorique');
    if(!overlay) return;

    overlay.classList.add('actif');
    document.body.style.overflow = 'hidden';

    const boiteHistorique = overlay.querySelector('.historique-box');
    if(boiteHistorique) void boiteHistorique.offsetHeight;

    if(!document.documentElement.classList.contains('webview-mode') && !historiqueOuverteViaHash){
        history.pushState({ historiqueOuverte: true }, '');
        historiqueOuverteViaHash = true;
    }

    chargerHistorique();

    if(intervalHistorique) clearInterval(intervalHistorique);
    intervalHistorique = setInterval(chargerHistorique, 6000);
}

function fermerHistorique(){
    if(intervalHistorique){
        clearInterval(intervalHistorique);
        intervalHistorique = null;
    }

    const overlay = document.getElementById('overlayHistorique');
    if(!overlay) return;

    overlay.classList.remove('actif');
    document.body.style.overflow = '';

    if(historiqueOuverteViaHash){
        historiqueOuverteViaHash = false;
        if(history.state && history.state.historiqueOuverte){
            history.back();
        }
    }
}

window.addEventListener('popstate', function(){
    const overlay = document.getElementById('overlayHistorique');
    if(overlay && overlay.classList.contains('actif')){
        overlay.classList.remove('actif');
        document.body.style.overflow = '';
        if(intervalHistorique){
            clearInterval(intervalHistorique);
            intervalHistorique = null;
        }
        historiqueOuverteViaHash = false;
    }

    const overlayDepot = document.getElementById('overlayDepot');
    if(overlayDepot && overlayDepot.classList.contains('actif')){
        overlayDepot.classList.remove('actif');
        document.body.style.overflow = '';
        depotOuverteViaHash = false;
    }
});
</script>

</body>
</html>