<?php

/**
 * Chiffrement symétrique des identifiants tiers (mot de passe de compte
 * stocké dans la table `demandes`). Utilise OpenSSL en AES-256-GCM.
 *
 * La clé provient de $cle_cryptage (config.php). Le format produit est
 * "v1:base64(iv):base64(tag):base64(ciphertext)".
 */

function crypter($texte, $cle)
{
    $iv  = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($texte, 'aes-256-gcm', hash('sha256', $cle, true), OPENSSL_RAW_DATA, $iv, $tag);

    if ($cipher === false) {
        throw new RuntimeException('Échec du chiffrement.');
    }

    return 'v1:' . base64_encode($iv) . ':' . base64_encode($tag) . ':' . base64_encode($cipher);
}

function decrypter($valeur, $cle)
{
    $parts = explode(':', (string) $valeur);
    if (count($parts) !== 4 || $parts[0] !== 'v1') {
        return false;
    }

    $iv         = base64_decode($parts[1], true);
    $tag        = base64_decode($parts[2], true);
    $cipher     = base64_decode($parts[3], true);

    if ($iv === false || $tag === false || $cipher === false) {
        return false;
    }

    $clair = openssl_decrypt($cipher, 'aes-256-gcm', hash('sha256', $cle, true), OPENSSL_RAW_DATA, $iv, $tag);

    return $clair === false ? false : $clair;
}
