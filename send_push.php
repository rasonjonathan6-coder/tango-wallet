<?php

/**
 * Notifications push (Web Push) — implémentation minimale.
 *
 * L'enregistrement des abonnements se fait via subscribe.php ; l'envoi réel
 * nécessite une paire de clés VAPID (variables VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY)
 * et la bibliothèque web-push. En l'absence de configuration, la fonction
 * journalise simplement l'intention d'envoi sans échouer, afin de ne jamais
 * bloquer le parcours utilisateur.
 */

function sendPush($user_id, $title, $body, $url = '/')
{
    $publicKey  = getenv('VAPID_PUBLIC_KEY') ?: '';
    $privateKey = getenv('VAPID_PRIVATE_KEY') ?: '';

    if ($publicKey === '' || $privateKey === '') {
        error_log("sendPush (non configuré) user_id=$user_id title=$title");
        return false;
    }

    // Envoi réel à implémenter avec une bibliothèque web-push (minishlink/web-push).
    error_log("sendPush user_id=$user_id title=$title body=$body url=$url");
    return true;
}
