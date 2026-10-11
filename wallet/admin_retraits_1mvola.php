<?php

/**
 * Vue admin simple pour confirmer manuellement les retraits Mvola (Version Mobile-First).
 */

if(!isset($_SESSION['admin'])){
    header("Location:?page=dashboard");
    exit;
}

require_once "blockchain_config.php";
require_once "plisio_config.php";
require_once "plisio_api.php";
require_once "wallet_functions.php";

$erreurRetrait = null;
$succesRetrait = null;
$ussdAppelAuto = null;

// Requête envoyée en arrière-plan par le JS (confirmer/refuser/rafraîchir la liste) : on répondra en JSON, sans recharger la page.
$isAjax = ($_SERVER['REQUEST_METHOD'] === 'POST') && isset($_POST['ajax']) && $_POST['ajax'] === '1';

if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmer_id'])){

    $idConfirmation = (int)$_POST['confirmer_id'];

    // On verrouille la ligne pour éviter un double-clic qui déclencherait deux envois
    $pdo->beginTransaction();

    $stmtLock = $pdo->prepare("
        SELECT r.*, u.email
        FROM retraits_mvola r
        LEFT JOIN users u ON u.id = r.user_id
        WHERE r.id=? AND r.statut='en_attente'
        FOR UPDATE
    ");
    $stmtLock->execute([$idConfirmation]);
    $infoRetrait = $stmtLock->fetch(PDO::FETCH_ASSOC);

    if(!$infoRetrait){
        $pdo->rollBack();
        $erreurRetrait = "Cette demande n'existe plus ou a déjà été traitée.";
    } else {

        // Réseau -> devise Plisio + adresse admin correspondante
        $network = $infoRetrait['network'] === 'BEP20' ? 'BEP20' : 'TRC20';
        $currencePlisio = $network === 'BEP20' ? 'USDT_BSC' : 'USDT_TRX';
        $adresseDestination = $network === 'BEP20' ? ADMIN_ADRESSE_BINANCE_BEP20 : ADMIN_ADRESSE_BINANCE_TRC20;

        // Montant envoyé = montant brut demandé par le client (frais gardés séparément côté MGA)
        $montantAEnvoyer = (float)$infoRetrait['montant_usdt'];

        // AVANT : appel direct à plisioWithdraw(), qui pouvait échouer avec
        // "Insufficient funds on balance" dès que le solde Plisio réel (net de
        // la commission Plisio prélevée à la réception) était inférieur au
        // montant brut demandé par le client.
        // MAINTENANT : on passe par envoyerPlisioSiSoldeSuffisant(), qui
        // vérifie D'ABORD le solde réellement disponible via getPlisioBalance()
        // avant de tenter le withdraw.
        $resultatWithdraw = envoyerPlisioSiSoldeSuffisant($currencePlisio, $adresseDestination, $montantAEnvoyer);

        $withdrawOk = isset($resultatWithdraw['status']) && $resultatWithdraw['status'] === 'success';

        if(!$withdrawOk){

            $messageErreur = $resultatWithdraw['data']['message'] ?? 'Erreur inconnue de l\'API Plisio.';

            error_log("Plisio withdraw échec pour retrait_mvola id=".$idConfirmation." : ".json_encode($resultatWithdraw));

            if($messageErreur === 'solde_insuffisant_plisio'){

                // Cas normal/temporaire : rien n'a été tenté côté API (vérifié
                // AVANT l'appel withdraw), donc aucun risque de double-envoi.
                $pdo->rollBack();
                $detail = $resultatWithdraw['data']['detail'] ?? '';
                $erreurRetrait = "⏳ Solde Plisio momentanément insuffisant pour ce retrait (".$detail."). La demande reste en attente, rien n'a été débité — réessaie dans quelques minutes.";

            } elseif($messageErreur === 'timeout_ambigu_plisio'){

                // CORRECTIF DOUBLE-ENVOI : on NE SAIT PAS si Plisio a envoyé
                // l'argent ou non (timeout = pas de réponse reçue, mais la
                // requête a pu être traitée côté Plisio). On NE remet PAS le
                // retrait en "en_attente" (ça autoriserait un ré-envoi réel
                // en un clic) : on le marque "a_verifier" pour bloquer tout
                // nouveau clic tant qu'un humain n'a pas vérifié manuellement
                // sur le dashboard Plisio (historique des retraits) si
                // l'envoi a réellement eu lieu.
                $pdo->prepare("
                    UPDATE retraits_mvola
                    SET statut='a_verifier'
                    WHERE id=? AND statut='en_attente'
                ")->execute([$idConfirmation]);

                $pdo->commit();

                $detail = $resultatWithdraw['data']['detail'] ?? '';
                $erreurRetrait = "⚠️ Réponse Plisio non reçue à temps (".$detail."). IMPOSSIBLE de savoir si l'envoi a eu lieu ou non. Ce retrait est bloqué en statut \"à vérifier\" — va vérifier manuellement sur le dashboard Plisio (historique des retraits) AVANT de faire quoi que ce soit. Ne reclique surtout pas sur Confirmer.";

            } else {

                // Erreur réseau franche avant tout envoi (ex: DNS, connexion
                // refusée) : ici on peut affirmer que rien n'est parti,
                // donc sans risque de repasser en "en_attente".
                $pdo->rollBack();
                $erreurRetrait = "❌ Échec de l'envoi automatique des USDT via Plisio : ".$messageErreur." — la demande reste en attente, rien n'a été débité côté Plisio.";

            }

        } else {

            $plisioTxnId = $resultatWithdraw['data']['txn_id'] ?? ($resultatWithdraw['data']['id'] ?? null);

            $stmtConfirm = $pdo->prepare("
                UPDATE retraits_mvola
                SET statut='confirme', confirmed_at=NOW(), plisio_withdraw_id=?
                WHERE id=? AND statut='en_attente'
            ");
            $stmtConfirm->execute([$plisioTxnId, $idConfirmation]);

            $pdo->commit();

            $succesRetrait = "✅ USDT envoyés automatiquement via Plisio (".$montantAEnvoyer." ".$currencePlisio.") vers ".$adresseDestination;

            // Code USSD Mvola à composer pour envoyer les MGA au client (ouvre l'appel automatiquement)
            $ussdAppelAuto = "#111*1*2*".$infoRetrait['numero_mvola']."*".round((float)$infoRetrait['montant_mga'])."*2#";

            if(!function_exists('sendMail')){
                require_once __DIR__ . "/../mail.php";
            }

            if(!empty($infoRetrait['email'])){

                $messageClient = "
Bonjour,

Ton retrait de ".number_format($infoRetrait['montant_usdt'],2)." USDT a été confirmé.
Tu as reçu ".number_format($infoRetrait['montant_mga'],0,',',' ')." MGA sur le numéro Mvola ".$infoRetrait['numero_mvola'].".

Merci de ta confiance.
";

                sendMail(
                    $infoRetrait['email'],
                    "✅ Retrait Mvola confirmé",
                    $messageClient
                );

            }

            if(!function_exists('sendPush') && file_exists(__DIR__ . "/../send_push.php")){
                require_once __DIR__ . "/../send_push.php";
            }

            if(function_exists('sendPush')){
                try{
                    sendPush(
                        "✅ Retrait Mvola confirmé",
                        "Ton retrait de ".number_format($infoRetrait['montant_usdt'],2)." USDT a été confirmé. Tu as reçu ".number_format($infoRetrait['montant_mga'],0,',',' ')." MGA sur le ".$infoRetrait['numero_mvola'].".",
                        ["type" => "retrait_mvola_confirme", "user_id" => $infoRetrait['user_id']]
                    );
                } catch(Throwable $e){
                    error_log("Erreur push confirmation retrait Mvola : ".$e->getMessage());
                }
            }

        }

    }

}


// ---- Refus d'une demande de retrait : remboursement auto du solde + email ----
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['refuser_id'])){

    $idRefus = (int)$_POST['refuser_id'];

    $pdo->beginTransaction();

    $stmtLockRefus = $pdo->prepare("
        SELECT r.*, u.email
        FROM retraits_mvola r
        LEFT JOIN users u ON u.id = r.user_id
        WHERE r.id=? AND r.statut='en_attente'
        FOR UPDATE
    ");
    $stmtLockRefus->execute([$idRefus]);
    $infoRefus = $stmtLockRefus->fetch(PDO::FETCH_ASSOC);

    if(!$infoRefus){
        $pdo->rollBack();
        $erreurRetrait = "Cette demande n'existe plus ou a déjà été traitée.";
    } else {

        $montantARembourser = (float)$infoRefus['montant_usdt'];

        // Le solde avait été débité au moment de la demande (retrait_mvola.php) :
        // on le recrédite intégralement puisque le retrait n'a finalement pas lieu.
        $pdo->prepare("
            UPDATE user_wallets SET solde = solde + ? WHERE user_id=?
        ")->execute([$montantARembourser, $infoRefus['user_id']]);

        $pdo->prepare("
            UPDATE retraits_mvola
            SET statut='rejete', confirmed_at=NOW()
            WHERE id=? AND statut='en_attente'
        ")->execute([$idRefus]);

        $pdo->commit();

        $succesRetrait = "✅ Demande refusée : ".number_format($montantARembourser,2)." USDT remboursés sur le portefeuille du client.";

        if(!function_exists('sendMail')){
            require_once __DIR__ . "/../mail.php";
        }

        if(!empty($infoRefus['email'])){

            $messageRefus = "
Bonjour,

Ta demande de retrait de ".number_format($infoRefus['montant_usdt'],2)." USDT (".number_format($infoRefus['montant_mga'],0,',',' ')." MGA vers le numéro Mvola ".$infoRefus['numero_mvola'].") a été refusée.

Le montant de ".number_format($montantARembourser,2)." USDT a été automatiquement recrédité sur ton portefeuille.

Pour toute question, contacte le support.
";

            sendMail(
                $infoRefus['email'],
                "❌ Retrait Mvola refusé — solde remboursé",
                $messageRefus
            );

        }

    }

}

// ---- Réponse en arrière-plan (AJAX) : renvoie l'état à jour sans recharger la page ----
if($isAjax){

    $stmtAjax = $pdo->query("
        SELECT r.*, u.email
        FROM retraits_mvola r
        LEFT JOIN users u ON u.id = r.user_id
        ORDER BY r.statut='en_attente' DESC, r.created_at DESC
        LIMIT 100
    ");
    $retraitsAjax = $stmtAjax->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success'  => $erreurRetrait === null,
        'message'  => $erreurRetrait ?? $succesRetrait,
        'ussd'     => $ussdAppelAuto,
        'retraits' => array_map(function($r){
            return [
                'id'           => (int)$r['id'],
                'nom_mvola'    => $r['nom_mvola'],
                'email'        => $r['email'],
                'user_id'      => $r['user_id'],
                'montant_usdt' => (float)$r['montant_usdt'],
                'network'      => $r['network'],
                'montant_mga'  => (float)$r['montant_mga'],
                'numero_mvola' => $r['numero_mvola'],
                'statut'       => $r['statut'],
                'created_at'   => $r['created_at'],
                'adresse'      => $r['network'] === 'BEP20' ? ADMIN_ADRESSE_BINANCE_BEP20 : ADMIN_ADRESSE_BINANCE_TRC20,
            ];
        }, $retraitsAjax),
    ]);
    exit;

}

$stmt = $pdo->query("
    SELECT r.*, u.email
    FROM retraits_mvola r
    LEFT JOIN users u ON u.id = r.user_id
    ORDER BY r.statut='en_attente' DESC, r.created_at DESC
    LIMIT 100
");

$retraits = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<link rel="stylesheet" href="wallet/wallet.css">
<style>
.wallet-card {
    max-width: 1100px;
    margin: 20px auto;
    padding: 20px;
    border-radius: 18px;
    background: #1d2433;
    color: #fff;
}

.wallet-title {
    font-size: 24px;
    margin-bottom: 20px;
}

/* --- AFFICHAGE DESKTOP (Tableau) --- */
.desktop-view {
    display: block;
    overflow-x: auto;
}

.admin-table {
    width: 100%;
    border-collapse: collapse;
    color: var(--wallet-text, #fff);
    font-size: 14px;
}

.admin-table th,
.admin-table td {
    padding: 10px;
    text-align: left;
    border-bottom: 1px solid var(--wallet-border, #2d3748);
}

/* --- AFFICHAGE MOBILE (Cartes empilées) --- */
.mobile-view {
    display: none;
}

.withdrawal-card-item {
    background: #141a23;
    border: 1px solid var(--wallet-border, #2d3748);
    border-radius: 12px;
    padding: 15px;
    margin-bottom: 15px;
}

.withdrawal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid rgba(255,255,255,0.08);
    padding-bottom: 10px;
    margin-bottom: 10px;
}

.withdrawal-body {
    font-size: 13px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.withdrawal-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.withdrawal-row span.label {
    color: #a0aec0;
}

.wallet-btn-copy {
    padding: 6px 10px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    background: #3182ce;
    color: white;
    font-size: 12px;
}

.btn-confirm {
    background: #38a169;
    width: 100%;
    margin-top: 10px;
    padding: 10px;
    font-weight: bold;
}

/* --- RESPONSIVE MEDIA QUERY --- */
@media (max-width: 768px) {
    .wallet-card {
        margin: 10px;
        padding: 12px;
        border-radius: 12px;
    }

    .wallet-title {
        font-size: 18px;
        text-align: center;
    }

    .desktop-view {
        display: none; /* Cache le tableau sur mobile */
    }

    .mobile-view {
        display: block; /* Affiche les cartes sur mobile */
    }
}
</style>

<div class="wallet-card">

<div style="margin-bottom:15px;">
    <a href="?page=dashboard" style="display:inline-flex; align-items:center; gap:6px; color:#fff; background:#2d3748; padding:8px 14px; border-radius:8px; text-decoration:none; font-size:14px;">⬅️ Retour</a>
</div>

<h2 class="wallet-title">📲 Demandes de retrait Mvola</h2>

<?php if($erreurRetrait): ?>
    <div style="background:rgba(245,101,101,0.15); border:1px solid #f56565; color:#feb2b2; padding:12px; border-radius:10px; margin-bottom:15px; font-size:14px;">
        <?= htmlspecialchars($erreurRetrait) ?>
    </div>
<?php endif; ?>

<?php if($succesRetrait): ?>
    <div style="background:rgba(72,187,120,0.15); border:1px solid #48bb78; color:#9ae6b4; padding:12px; border-radius:10px; margin-bottom:15px; font-size:14px;">
        <?= htmlspecialchars($succesRetrait) ?>
    </div>
<?php endif; ?>

<div id="bandeauAjax" style="display:none; padding:12px; border-radius:10px; margin-bottom:15px; font-size:14px;"></div>

<?php if($ussdAppelAuto): ?>
    <div style="background:rgba(99,179,237,0.15); border:1px solid #63b3ed; color:#bee3f8; padding:12px; border-radius:10px; margin-bottom:15px; font-size:14px;">
        📞 Envoi Mvola en cours — l'appel se lance automatiquement.
        Si rien ne se passe, composez manuellement :
        <a href="tel:<?= rawurlencode($ussdAppelAuto) ?>" style="color:#fff; font-weight:bold; text-decoration:underline;"><?= htmlspecialchars($ussdAppelAuto) ?></a>
    </div>
    <script>
        // Lance automatiquement l'appel USSD Mvola pour envoyer les MGA au client
        window.location.href = "tel:<?= rawurlencode($ussdAppelAuto) ?>";
    </script>
<?php endif; ?>

<!-- ================= VUE DESKTOP (TABLEAU) ================= -->
<div class="desktop-view">
    <table class="admin-table">
        <thead>
            <tr>
                <th>Client</th>
                <th>USDT</th>
                <th>Réseau</th>
                <th>Envoyer vers (Binance)</th>
                <th>MGA à payer</th>
                <th>Numéro Mvola</th>
                <th>Statut</th>
                <th>Date</th>
                <th></th>
            </tr>
        </thead>
        <tbody id="corpsTableauDesktop">
        <?php foreach($retraits as $r):
            $adresseDest = $r['network'] === 'BEP20' ? ADMIN_ADRESSE_BINANCE_BEP20 : ADMIN_ADRESSE_BINANCE_TRC20;
        ?>
            <tr>
                <td>
                    <strong><?= htmlspecialchars($r['nom_mvola'] ?? '—') ?></strong><br>
                    <small style="color:#a0aec0;"><?= htmlspecialchars($r['email'] ?? $r['user_id']) ?></small>
                </td>
                <td><?= number_format($r['montant_usdt'],2) ?></td>
                <td><?= htmlspecialchars($r['network']) ?></td>
                <td>
                    <span id="adr_desk_<?= $r['id'] ?>" style="font-family:monospace;font-size:11px;"><?= htmlspecialchars($adresseDest) ?></span>
                    <button type="button" class="wallet-btn-copy" onclick="navigator.clipboard.writeText(document.getElementById('adr_desk_<?= $r['id'] ?>').innerText)">📋</button>
                </td>
                <td><?= number_format($r['montant_mga'],0,',',' ') ?> Ar</td>
                <td><?= htmlspecialchars($r['numero_mvola']) ?></td>
                <td>
                    <?php if($r['statut']==='en_attente'): ?>
                        <span style="color:#ffb703;">En attente</span>
                    <?php elseif($r['statut']==='confirme'): ?>
                        <span style="color:#48bb78;">Confirmé</span>
                    <?php else: ?>
                        <span style="color:#f56565;">Rejeté</span>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($r['created_at']) ?></td>
                <td>
                    <?php if($r['statut']==='en_attente'): ?>
                    <form method="POST" class="form-action-retrait" data-action="confirmer" data-id="<?= $r['id'] ?>" style="display:inline;">
                        <input type="hidden" name="confirmer_id" value="<?= $r['id'] ?>">
                        <button type="submit" class="wallet-btn-copy" style="background:#38a169;">✅ Confirmer</button>
                    </form>
                    <form method="POST" class="form-action-retrait" data-action="refuser" data-id="<?= $r['id'] ?>" data-montant="<?= number_format($r['montant_usdt'],2) ?>" style="display:inline;">
                        <input type="hidden" name="refuser_id" value="<?= $r['id'] ?>">
                        <button type="submit" class="wallet-btn-copy" style="background:#e53e3e;">❌ Refuser</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- ================= VUE MOBILE (CARTES) ================= -->
<div class="mobile-view" id="corpsMobile">
    <?php foreach($retraits as $r):
        $adresseDest = $r['network'] === 'BEP20' ? ADMIN_ADRESSE_BINANCE_BEP20 : ADMIN_ADRESSE_BINANCE_TRC20;
    ?>
        <div class="withdrawal-card-item">
            <div class="withdrawal-header">
                <div>
                    <strong><?= htmlspecialchars($r['nom_mvola'] ?? ($r['email'] ?? $r['user_id'])) ?></strong><br>
                    <small style="color: #a0aec0; font-size: 11px;"><?= htmlspecialchars($r['email'] ?? '') ?> · <?= htmlspecialchars($r['created_at']) ?></small>
                </div>
                <div>
                    <?php if($r['statut']==='en_attente'): ?>
                        <span style="color:#ffb703; font-weight:bold; font-size:12px;">En attente</span>
                    <?php elseif($r['statut']==='confirme'): ?>
                        <span style="color:#48bb78; font-weight:bold; font-size:12px;">Confirmé</span>
                    <?php else: ?>
                        <span style="color:#f56565; font-weight:bold; font-size:12px;">Rejeté</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="withdrawal-body">
                <div class="withdrawal-row">
                    <span class="label">Montant USDT :</span>
                    <strong><?= number_format($r['montant_usdt'],2) ?> (<?= htmlspecialchars($r['network']) ?>)</strong>
                </div>
                <div class="withdrawal-row">
                    <span class="label">À payer Mvola :</span>
                    <strong style="color: #48bb78; font-size:15px;"><?= number_format($r['montant_mga'],0,',',' ') ?> Ar</strong>
                </div>
                <div class="withdrawal-row">
                    <span class="label">Numéro Mvola :</span>
                    <a href="tel:<?= htmlspecialchars($r['numero_mvola']) ?>" style="color:#63b3ed; text-decoration:none; font-weight:bold;"><?= htmlspecialchars($r['numero_mvola']) ?></a>
                </div>
                <div style="background: rgba(0,0,0,0.2); padding: 8px; border-radius: 6px; margin-top: 5px;">
                    <span class="label" style="display:block; margin-bottom: 3px; font-size:11px;">Adresse Binance (<?= htmlspecialchars($r['network']) ?>) :</span>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span id="adr_mob_<?= $r['id'] ?>" style="font-family:monospace; font-size:10px; word-break: break-all; margin-right: 5px;"><?= htmlspecialchars($adresseDest) ?></span>
                        <button type="button" class="wallet-btn-copy" onclick="navigator.clipboard.writeText(document.getElementById('adr_mob_<?= $r['id'] ?>').innerText)">📋 Copier</button>
                    </div>
                </div>
            </div>

            <?php if($r['statut']==='en_attente'): ?>
            <form method="POST" class="form-action-retrait" data-action="confirmer" data-id="<?= $r['id'] ?>">
                <input type="hidden" name="confirmer_id" value="<?= $r['id'] ?>">
                <button type="submit" class="wallet-btn-copy btn-confirm">✅ Confirmer le retrait</button>
            </form>
            <form method="POST" class="form-action-retrait" data-action="refuser" data-id="<?= $r['id'] ?>" data-montant="<?= number_format($r['montant_usdt'],2) ?>">
                <input type="hidden" name="refuser_id" value="<?= $r['id'] ?>">
                <button type="submit" class="wallet-btn-copy btn-confirm" style="background:#e53e3e; margin-top:8px;">❌ Refuser le retrait</button>
            </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<?php if(empty($retraits)): ?>
<p id="messageVide" style="color:var(--wallet-text-dim,#a0aec0);text-align:center;padding:20px 0;">Aucune demande pour le moment.</p>
<?php else: ?>
<p id="messageVide" style="display:none; color:var(--wallet-text-dim,#a0aec0);text-align:center;padding:20px 0;">Aucune demande pour le moment.</p>
<?php endif; ?>

</div>

<script>
(function(){

    function echapper(txt){
        const div = document.createElement('div');
        div.innerText = (txt === null || txt === undefined) ? '' : String(txt);
        return div.innerHTML;
    }

    function formatUsdt(montant){
        return Number(montant).toLocaleString('fr-FR', {minimumFractionDigits:2, maximumFractionDigits:2});
    }

    function formatAr(montant){
        return Math.round(Number(montant)).toLocaleString('fr-FR') + ' Ar';
    }

    function badgeStatut(statut){
        if(statut === 'en_attente') return '<span style="color:#ffb703;">En attente</span>';
        if(statut === 'confirme')   return '<span style="color:#48bb78;">Confirmé</span>';
        if(statut === 'a_verifier') return '<span style="color:#ed8936;">⚠️ À vérifier manuellement</span>';
        return '<span style="color:#f56565;">Rejeté</span>';
    }

    function badgeStatutMobile(statut){
        if(statut === 'en_attente') return '<span style="color:#ffb703; font-weight:bold; font-size:12px;">En attente</span>';
        if(statut === 'confirme')   return '<span style="color:#48bb78; font-weight:bold; font-size:12px;">Confirmé</span>';
        if(statut === 'a_verifier') return '<span style="color:#ed8936; font-weight:bold; font-size:12px;">⚠️ À vérifier manuellement</span>';
        return '<span style="color:#f56565; font-weight:bold; font-size:12px;">Rejeté</span>';
    }

    function boutonsAction(r, mobile){
        if(r.statut !== 'en_attente') return '';
        const classeBtn = mobile ? 'wallet-btn-copy btn-confirm' : 'wallet-btn-copy';
        return `
            <form method="POST" class="form-action-retrait" data-action="confirmer" data-id="${r.id}" ${mobile ? '' : 'style="display:inline;"'}>
                <input type="hidden" name="confirmer_id" value="${r.id}">
                <button type="submit" class="${classeBtn}" style="background:#38a169;">✅ Confirmer${mobile ? ' le retrait' : ''}</button>
            </form>
            <form method="POST" class="form-action-retrait" data-action="refuser" data-id="${r.id}" data-montant="${formatUsdt(r.montant_usdt)}" ${mobile ? '' : 'style="display:inline;"'}>
                <input type="hidden" name="refuser_id" value="${r.id}">
                <button type="submit" class="${classeBtn}" style="background:#e53e3e;${mobile ? ' margin-top:8px;' : ''}">❌ Refuser${mobile ? ' le retrait' : ''}</button>
            </form>
        `;
    }

    function ligneDesktop(r){
        return `
            <tr>
                <td>
                    <strong>${echapper(r.nom_mvola || '—')}</strong><br>
                    <small style="color:#a0aec0;">${echapper(r.email || r.user_id)}</small>
                </td>
                <td>${formatUsdt(r.montant_usdt)}</td>
                <td>${echapper(r.network)}</td>
                <td>
                    <span id="adr_desk_${r.id}" style="font-family:monospace;font-size:11px;">${echapper(r.adresse)}</span>
                    <button type="button" class="wallet-btn-copy" onclick="navigator.clipboard.writeText(document.getElementById('adr_desk_${r.id}').innerText)">📋</button>
                </td>
                <td>${formatAr(r.montant_mga)}</td>
                <td>${echapper(r.numero_mvola)}</td>
                <td>${badgeStatut(r.statut)}</td>
                <td>${echapper(r.created_at)}</td>
                <td>${boutonsAction(r, false)}</td>
            </tr>
        `;
    }

    function carteMobile(r){
        return `
            <div class="withdrawal-card-item">
                <div class="withdrawal-header">
                    <div>
                        <strong>${echapper(r.nom_mvola || r.email || r.user_id)}</strong><br>
                        <small style="color: #a0aec0; font-size: 11px;">${echapper(r.email || '')} · ${echapper(r.created_at)}</small>
                    </div>
                    <div>${badgeStatutMobile(r.statut)}</div>
                </div>
                <div class="withdrawal-body">
                    <div class="withdrawal-row">
                        <span class="label">Montant USDT :</span>
                        <strong>${formatUsdt(r.montant_usdt)} (${echapper(r.network)})</strong>
                    </div>
                    <div class="withdrawal-row">
                        <span class="label">À payer Mvola :</span>
                        <strong style="color: #48bb78; font-size:15px;">${formatAr(r.montant_mga)}</strong>
                    </div>
                    <div class="withdrawal-row">
                        <span class="label">Numéro Mvola :</span>
                        <a href="tel:${echapper(r.numero_mvola)}" style="color:#63b3ed; text-decoration:none; font-weight:bold;">${echapper(r.numero_mvola)}</a>
                    </div>
                    <div style="background: rgba(0,0,0,0.2); padding: 8px; border-radius: 6px; margin-top: 5px;">
                        <span class="label" style="display:block; margin-bottom: 3px; font-size:11px;">Adresse Binance (${echapper(r.network)}) :</span>
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span id="adr_mob_${r.id}" style="font-family:monospace; font-size:10px; word-break: break-all; margin-right: 5px;">${echapper(r.adresse)}</span>
                            <button type="button" class="wallet-btn-copy" onclick="navigator.clipboard.writeText(document.getElementById('adr_mob_${r.id}').innerText)">📋 Copier</button>
                        </div>
                    </div>
                </div>
                ${boutonsAction(r, true)}
            </div>
        `;
    }

    function actualiserListe(retraits){
        const corpsDesktop = document.getElementById('corpsTableauDesktop');
        const corpsMobile  = document.getElementById('corpsMobile');
        const messageVide  = document.getElementById('messageVide');

        if(corpsDesktop) corpsDesktop.innerHTML = retraits.map(ligneDesktop).join('');
        if(corpsMobile)  corpsMobile.innerHTML  = retraits.map(carteMobile).join('');
        if(messageVide)  messageVide.style.display = retraits.length === 0 ? 'block' : 'none';
    }

    function afficherBandeau(message, ok){
        const bandeau = document.getElementById('bandeauAjax');
        if(!bandeau || !message) return;
        bandeau.style.display = 'block';
        bandeau.style.background = ok ? 'rgba(72,187,120,0.15)' : 'rgba(245,101,101,0.15)';
        bandeau.style.border = '1px solid ' + (ok ? '#48bb78' : '#f56565');
        bandeau.style.color = ok ? '#9ae6b4' : '#feb2b2';
        bandeau.innerText = message;
    }

    function envoyerRequeteAjax(champs){
        const corps = new URLSearchParams(Object.assign({ajax: '1'}, champs));
        return fetch(window.location.href, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
            body: corps.toString()
        }).then(res => res.json());
    }

    function traiterAction(form){
        const action = form.getAttribute('data-action');
        const id = form.getAttribute('data-id');

        if(action === 'refuser'){
            const montant = form.getAttribute('data-montant') || '';
            if(!confirm('Refuser ce retrait et rembourser ' + montant + ' USDT au client ?')) return;
        }

        const boutons = form.querySelectorAll('button');
        const boutonClique = document.activeElement && form.contains(document.activeElement) ? document.activeElement : boutons[0];
        const texteOriginal = boutonClique ? boutonClique.innerHTML : null;

        // Retour visuel IMMEDIAT et explicite, pas juste "disabled" (invisible
        // à l'oeil si aucun style spécifique n'existe pour :disabled).
        boutons.forEach(b => { b.disabled = true; b.style.opacity = '0.6'; b.style.cursor = 'wait'; });
        if(boutonClique) boutonClique.innerHTML = '⏳ Envoi en cours... ne pas recliquer';

        envoyerRequeteAjax({ [action === 'confirmer' ? 'confirmer_id' : 'refuser_id']: id }).then(function(data){
            afficherBandeau(data.message, data.success);
            actualiserListe(data.retraits || []);
            if(data.success && data.ussd){
                window.location.href = 'tel:' + encodeURIComponent(data.ussd);
            }
            // Si succès ou si le serveur a répondu (même en échec), la liste
            // est réactualisée depuis la BDD -> le bouton disparaît tout seul
            // si le statut n'est plus "en_attente" (confirmé / à vérifier).
        }).catch(function(){
            // IMPORTANT : on ne sait PAS si la demande a été traitée côté
            // serveur (la requête a pu aboutir sur Plisio même si la réponse
            // ne nous est jamais revenue). On NE réactive PAS le bouton
            // automatiquement pour empêcher un second clic qui enverrait
            // l'argent une deuxième fois pour de vrai. On invite à vérifier
            // le statut réel avant toute nouvelle action.
            afficherBandeau("❌ Erreur réseau : la réponse n'est pas arrivée, mais la demande a peut-être quand même été traitée côté serveur. NE RECLIQUE PAS. Actualise la page pour voir le vrai statut avant d'agir.", false);
            if(boutonClique) boutonClique.innerHTML = '⚠️ Statut inconnu - actualise la page';
        });
    }

    document.addEventListener('submit', function(e){
        if(e.target.matches('.form-action-retrait')){
            e.preventDefault();
            traiterAction(e.target);
        }
    });

    // Rafraîchissement automatique périodique de la liste, sans recharger la page
    setInterval(function(){
        envoyerRequeteAjax({}).then(function(data){
            actualiserListe(data.retraits || []);
        }).catch(function(){ /* on retentera au prochain cycle */ });
    }, 15000);

})();
</script>