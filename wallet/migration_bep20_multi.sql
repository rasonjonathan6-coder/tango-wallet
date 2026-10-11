-- Migration : passage à 10 adresses BEP20 par utilisateur
-- À exécuter une seule fois sur la base de données.

CREATE TABLE IF NOT EXISTS user_wallet_bep20 (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    slot TINYINT UNSIGNED NOT NULL,
    adresse VARCHAR(191) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY user_slot (user_id, slot),
    KEY idx_user_id (user_id),
    KEY idx_adresse (adresse)
);

-- Note : la colonne wallet_bep20 de la table user_wallets n'est plus utilisée
-- pour les nouveaux dépôts BEP20 (remplacée par la table ci-dessus),
-- mais elle peut être conservée pour l'historique / rétrocompatibilité.
