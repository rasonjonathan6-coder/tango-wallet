<?php

// Point d'entrée unique pour la vérification des dépôts (à appeler via cron).
// check_bep20.php et check_trc20.php étaient vides et ne faisaient rien :
// toute la logique est maintenant dans check_wallet_deposits.php (BEP20 + TRC20).

require_once "check_wallet_deposits.php";
