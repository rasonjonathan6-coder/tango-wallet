<?php

/**
 * Libération des wallets de paiement réservés.
 *
 * Lorsqu'une demande de paiement est annulée ou expirée, le wallet réservé
 * (table `wallets`) doit repasser en statut 'free' pour être réutilisable.
 */

function releaseWallet($pdo, $payment_id)
{
    if (empty($payment_id)) {
        return false;
    }

    $stmt = $pdo->prepare("
        UPDATE wallets
        SET status = 'free',
            reserved_payment_id = NULL,
            reserved_at = NULL
        WHERE reserved_payment_id = ?
    ");

    $stmt->execute([$payment_id]);

    return $stmt->rowCount() > 0;
}
