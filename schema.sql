-- ============================================================================
-- Schéma de base de données de l'application Wallet
-- ----------------------------------------------------------------------------
-- Regroupe toutes les tables utilisées par index.php et le module wallet/.
-- Le code applicatif crée également ces tables à la volée (CREATE TABLE IF NOT
-- EXISTS) ; ce script sert de référence et permet une installation propre.
-- ============================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Utilisateurs
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) UNIQUE,
    password TEXT,
    verify_code VARCHAR(255),
    code_expire DATETIME,
    verified TINYINT(1) DEFAULT 0,
    attempts INT DEFAULT 0,
    google_id VARCHAR(255) NULL,
    essai_gratuit_vu TINYINT(1) DEFAULT 0,
    essai_gratuit_offert TINYINT(1) DEFAULT 0,
    created_at DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Portefeuilles utilisateurs
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_wallets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    identifiant VARCHAR(255),
    solde DECIMAL(30,8) NOT NULL DEFAULT 0,
    statut VARCHAR(50) NOT NULL DEFAULT 'actif',
    wallet_trc20 VARCHAR(191) NULL,
    wallet_bep20 VARCHAR(191) NULL,
    created_at DATETIME,
    KEY idx_user_id (user_id),
    KEY idx_statut (statut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Adresses BEP20 (jusqu'à 10 par utilisateur)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_wallet_bep20 (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    slot TINYINT UNSIGNED NOT NULL,
    adresse VARCHAR(191) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY user_slot (user_id, slot),
    KEY idx_user_id (user_id),
    KEY idx_adresse (adresse)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Transactions du portefeuille (historique interne)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS wallet_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    wallet_id INT NOT NULL,
    type VARCHAR(50),
    montant DECIMAL(30,8),
    network VARCHAR(20),
    reference VARCHAR(191),
    created_at DATETIME,
    KEY idx_wallet_id (wallet_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Dépôts crypto détectés (Plisio / blockchain)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS wallet_deposits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    txid VARCHAR(191) NOT NULL,
    network VARCHAR(20) NOT NULL,
    montant DECIMAL(30,8) NOT NULL,
    statut VARCHAR(50) DEFAULT 'confirme',
    ipn_type VARCHAR(50) NULL,
    token VARCHAR(100) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_txid (txid),
    KEY idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Factures Plisio
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS wallet_invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(100) NOT NULL,
    user_id INT NOT NULL,
    montant DECIMAL(30,8) NOT NULL,
    statut VARCHAR(50) DEFAULT 'en_attente',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_order_number (order_number),
    KEY idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Demandes de retrait Mvola
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS retraits_mvola (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    montant_usdt DECIMAL(30,8) NOT NULL,
    frais_usdt DECIMAL(30,8) NOT NULL DEFAULT 0,
    montant_usdt_net DECIMAL(30,8) NOT NULL DEFAULT 0,
    taux_applique DECIMAL(18,4) NOT NULL DEFAULT 0,
    montant_mga DECIMAL(30,2) NOT NULL DEFAULT 0,
    numero_mvola VARCHAR(20) NOT NULL,
    nom_mvola VARCHAR(100) NOT NULL,
    network VARCHAR(20) NOT NULL DEFAULT 'TRC20',
    statut VARCHAR(50) NOT NULL DEFAULT 'en_attente',
    plisio_withdraw_id VARCHAR(191) NULL,
    confirmed_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user_id (user_id),
    KEY idx_statut (statut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Demandes de déblocage / réservations de wallet
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS demandes (
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
    wallet_address VARCHAR(255),
    txid VARCHAR(255),
    created_at DATETIME,
    KEY idx_user_id (user_id),
    KEY idx_payment_id (payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Wallets de paiement (pool d'adresses réutilisables)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS wallets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    address VARCHAR(255) NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'free',
    reserved_payment_id VARCHAR(100) NULL,
    reserved_at DATETIME NULL,
    last_used_at DATETIME NULL,
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Paramètres applicatifs (maintenance, montant, version APK...)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value VARCHAR(255)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Taux de change USDT -> MGA (cache Binance P2P)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS taux_change (
    id INT AUTO_INCREMENT PRIMARY KEY,
    paire VARCHAR(20) NOT NULL DEFAULT 'USDT_MGA',
    taux_binance DECIMAL(18,4) NOT NULL,
    marge DECIMAL(18,4) NOT NULL DEFAULT 200,
    taux_client DECIMAL(18,4) NOT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS taux_historique (
    id INT AUTO_INCREMENT PRIMARY KEY,
    taux_client DECIMAL(18,4) NOT NULL,
    enregistre_le DATETIME NOT NULL,
    KEY idx_enregistre_le (enregistre_le)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Suivi des crons de détection de dépôts
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cron_deposits_state (
    id TINYINT PRIMARY KEY,
    dernier_user_id INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bep20_scan_state (
    adresse VARCHAR(64) PRIMARY KEY,
    dernier_bloc_traite BIGINT UNSIGNED NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wallet_deposits_diag_vus (
    txid VARCHAR(191) PRIMARY KEY,
    user_id INT NOT NULL,
    network VARCHAR(20) NOT NULL,
    montant DECIMAL(30,8) NOT NULL,
    vu_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Messagerie / support
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS commentaires (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    email VARCHAR(255),
    message TEXT,
    visible TINYINT(1) DEFAULT 1,
    created_at DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS messages_contact (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    email VARCHAR(255),
    message TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS messages_admin (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    message TEXT,
    lu TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Notifications push
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS push_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    endpoint_hash CHAR(64) UNIQUE,
    endpoint TEXT,
    public_key TEXT,
    auth_token TEXT,
    created_at DATETIME,
    updated_at DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Données initiales
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('maintenance', '0');
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('montant', '25000');
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('apk_version', '1.2');
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('apk_url', '');
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('essai_gratuit_actif', '1');
INSERT IGNORE INTO cron_deposits_state (id, dernier_user_id) VALUES (1, 0);

-- Quelques wallets libres pour le pool de paiement (adresses d'exemple).
INSERT IGNORE INTO wallets (id, address, status, last_used_at) VALUES
    (1, 'TJwEQxE5P7QV4wVnSBFQR9YU2E11UdToJy', 'free', NOW()),
    (2, '0x1051f25d00c316acc58dffd89078e23fdbe64de6', 'free', NOW());
