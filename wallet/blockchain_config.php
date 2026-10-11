<?php

// TRC20 (TRON) retiré de l'application : frais Plisio trop élevés.
// Réseau unique désormais : BEP20 (BNB Smart Chain).
// (TRON_API_KEY volontairement supprimée — plus aucun code ne l'utilise.)

// API BSC (BEP20) - HISTORIQUE : BscScan V1 -> Etherscan V2 (chainid=56).
// Etherscan a ensuite retiré l'accès gratuit à BSC (juillet 2026), rendant
// cette clé inutilisable en plan gratuit. Conservée ici pour référence /
// si un plan payant Etherscan est repris un jour.
define(
    "BSC_API_KEY",
    getenv('BSC_API_KEY') ?: ''
);

// API BSC (BEP20) - ACTUEL : BSCTrace via MegaNode (nodereal.io), gratuit.
// check_wallet_deposits.php utilise désormais cette clé (JSON-RPC,
// méthode nr_getAssetTransfers) pour détecter les dépôts BEP20.
define(
    "MEGANODE_API_KEY",
    getenv('MEGANODE_API_KEY') ?: ''
);

// Adresse email qui reçoit une alerte automatique quand check_wallet_deposits.php
// rencontre trop d'erreurs lors d'une exécution (ex: tous les RPC BEP20 en
// panne, service indisponible...).
define(
    "ADMIN_ALERT_EMAIL",
    "customerservicefor032@gmail.com"
);

// API Ankr (RPC BSC de secours) - nécessaire depuis qu'Ankr exige une clé
// pour son endpoint public (changement de politique, constaté 26/07/2026).
define(
    "ANKR_API_KEY",
    getenv('ANKR_API_KEY') ?: ''
);

// Liste de RPC BSC utilisés en cascade (failover) pour les appels JSON-RPC
// standards (ex: eth_blockNumber). Si le 1er échoue ou que son quota est
// atteint, on passe automatiquement au suivant.
// NB : nr_getAssetTransfers (détection des dépôts BEP20) reste une méthode
// propriétaire NodeReal, non supportée par les RPC de secours ci-dessous :
// elle ne peut donc pas basculer dessus (voir check_wallet_deposits.php,
// qui utilise eth_getLogs comme repli standard dans ce cas).
define(
    "BSC_RPC_ENDPOINTS",
    [
        "https://bsc-mainnet.nodereal.io/v1/".MEGANODE_API_KEY, // 1. NodeReal (principal)
        "https://rpc.ankr.com/bsc/".ANKR_API_KEY,                // 2. Ankr, authentifié (secours)
        "https://bsc-rpc.publicnode.com",                        // 3. publicnode.com (secours)
        "https://bsc-dataseed.binance.org",                      // 4. RPC public BNB Chain (dernier recours)
    ]
);


// Adresse Binance de l'admin, où envoyer manuellement l'USDT
// lors du traitement d'un retrait Mvola (voir admin_retraits_mvola.php).
// BEP20 uniquement (TRC20 retiré).
define(
    "ADMIN_ADRESSE_BINANCE_BEP20",
    "0x1051f25d00c316acc58dffd89078e23fdbe64de6"
);

?>