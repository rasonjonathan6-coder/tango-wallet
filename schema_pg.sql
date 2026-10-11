-- ============================================================================
-- Schéma de base de données de l'application Wallet
-- ----------------------------------------------------------------------------
-- Regroupe toutes les tables utilisées par index.php et le module wallet/.
-- Le code applicatif crée également ces tables à la volée (CREATE TABLE IF NOT
-- EXISTS) ; ce script sert de référence et permet une installation propre.
-- ============================================================================



-- ---------------------------------------------------------------------------
-- Utilisateurs
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    email VARCHAR(255) UNIQUE,
    password TEXT,
    verify_code VARCHAR(255),
    code_expire TIMESTAMP,
    verified SMALLINT DEFAULT 0,
    attempts INT DEFAULT 0,
    google_id VARCHAR(255) NULL,
    essai_gratuit_vu SMALLINT DEFAULT 0,
    essai_gratuit_offert SMALLINT DEFAULT 0,
    created_at TIMESTAMP
);

-- ---------------------------------------------------------------------------
-- Portefeuilles utilisateurs
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_wallets (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL,
    identifiant VARCHAR(255),
    solde DECIMAL(30,8) NOT NULL DEFAULT 0,
    statut VARCHAR(50) NOT NULL DEFAULT 'actif',
    wallet_trc20 VARCHAR(191) NULL,
    wallet_bep20 VARCHAR(191) NULL,
    created_at TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_user_id ON user_wallets (user_id);
CREATE INDEX IF NOT EXISTS idx_statut ON user_wallets (statut);

-- ---------------------------------------------------------------------------
-- Adresses BEP20 (jusqu'à 10 par utilisateur)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_wallet_bep20 (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL,
    slot SMALLINT  NOT NULL,
    adresse VARCHAR(191) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
ALTER TABLE user_wallet_bep20 ADD CONSTRAINT user_slot UNIQUE (user_id, slot);
CREATE INDEX IF NOT EXISTS idx_user_id ON user_wallet_bep20 (user_id);
CREATE INDEX IF NOT EXISTS idx_adresse ON user_wallet_bep20 (adresse);

-- ---------------------------------------------------------------------------
-- Transactions du portefeuille (historique interne)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS wallet_transactions (
    id SERIAL PRIMARY KEY,
    wallet_id INT NOT NULL,
    type VARCHAR(50),
    montant DECIMAL(30,8),
    network VARCHAR(20),
    reference VARCHAR(191),
    created_at TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_wallet_id ON wallet_transactions (wallet_id);

-- ---------------------------------------------------------------------------
-- Dépôts crypto détectés (Plisio / blockchain)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS wallet_deposits (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL,
    txid VARCHAR(191) NOT NULL,
    network VARCHAR(20) NOT NULL,
    montant DECIMAL(30,8) NOT NULL,
    statut VARCHAR(50) DEFAULT 'confirme',
    ipn_type VARCHAR(50) NULL,
    token VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
ALTER TABLE wallet_deposits ADD CONSTRAINT uniq_txid UNIQUE (txid);
CREATE INDEX IF NOT EXISTS idx_user_id ON wallet_deposits (user_id);

-- ---------------------------------------------------------------------------
-- Factures Plisio
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS wallet_invoices (
    id SERIAL PRIMARY KEY,
    order_number VARCHAR(100) NOT NULL,
    user_id INT NOT NULL,
    montant DECIMAL(30,8) NOT NULL,
    statut VARCHAR(50) DEFAULT 'en_attente',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
ALTER TABLE wallet_invoices ADD CONSTRAINT uniq_order_number UNIQUE (order_number);
CREATE INDEX IF NOT EXISTS idx_user_id ON wallet_invoices (user_id);

-- ---------------------------------------------------------------------------
-- Demandes de retrait Mvola
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS retraits_mvola (
    id SERIAL PRIMARY KEY,
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
    confirmed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_user_id ON retraits_mvola (user_id);
CREATE INDEX IF NOT EXISTS idx_statut ON retraits_mvola (statut);

-- ---------------------------------------------------------------------------
-- Demandes de déblocage / réservations de wallet
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS demandes (
    id SERIAL PRIMARY KEY,
    user_id INT,
    site VARCHAR(255),
    identifiant VARCHAR(255),
    mot_de_passe_crypte TEXT,
    payment_id VARCHAR(100),
    statut VARCHAR(50) DEFAULT 'en_attente',
    expiration_time TIMESTAMP,
    montant DECIMAL(10,2),
    montant_usdt DECIMAL(10,2),
    wallet_address VARCHAR(255),
    txid VARCHAR(255),
    created_at TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_user_id ON demandes (user_id);
CREATE INDEX IF NOT EXISTS idx_payment_id ON demandes (payment_id);

-- ---------------------------------------------------------------------------
-- Wallets de paiement (pool d'adresses réutilisables)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS wallets (
    id SERIAL PRIMARY KEY,
    address VARCHAR(255) NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'free',
    reserved_payment_id VARCHAR(100) NULL,
    reserved_at TIMESTAMP NULL,
    last_used_at TIMESTAMP NULL
);
CREATE INDEX IF NOT EXISTS idx_status ON wallets (status);

-- ---------------------------------------------------------------------------
-- Paramètres applicatifs (maintenance, montant, version APK...)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value VARCHAR(255)
);

-- ---------------------------------------------------------------------------
-- Taux de change USDT -> MGA (cache Binance P2P)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS taux_change (
    id SERIAL PRIMARY KEY,
    paire VARCHAR(20) NOT NULL DEFAULT 'USDT_MGA',
    taux_binance DECIMAL(18,4) NOT NULL,
    marge DECIMAL(18,4) NOT NULL DEFAULT 200,
    taux_client DECIMAL(18,4) NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS taux_historique (
    id SERIAL PRIMARY KEY,
    taux_client DECIMAL(18,4) NOT NULL,
    enregistre_le TIMESTAMP NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_enregistre_le ON taux_historique (enregistre_le);

-- ---------------------------------------------------------------------------
-- Suivi des crons de détection de dépôts
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cron_deposits_state (
    id SMALLINT PRIMARY KEY,
    dernier_user_id INT NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS bep20_scan_state (
    adresse VARCHAR(64) PRIMARY KEY,
    dernier_bloc_traite BIGINT  NOT NULL,
    updated_at TIMESTAMP NOT NULL
);

CREATE TABLE IF NOT EXISTS wallet_deposits_diag_vus (
    txid VARCHAR(191) PRIMARY KEY,
    user_id INT NOT NULL,
    network VARCHAR(20) NOT NULL,
    montant DECIMAL(30,8) NOT NULL,
    vu_at TIMESTAMP NOT NULL
);

-- ---------------------------------------------------------------------------
-- Messagerie / support
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS commentaires (
    id SERIAL PRIMARY KEY,
    user_id INT,
    email VARCHAR(255),
    message TEXT,
    visible SMALLINT DEFAULT 1,
    created_at TIMESTAMP
);

CREATE TABLE IF NOT EXISTS messages_contact (
    id SERIAL PRIMARY KEY,
    user_id INT,
    email VARCHAR(255),
    message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS messages_admin (
    id SERIAL PRIMARY KEY,
    user_id INT,
    message TEXT,
    lu SMALLINT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ---------------------------------------------------------------------------
-- Notifications push
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS push_subscriptions (
    id SERIAL PRIMARY KEY,
    endpoint_hash CHAR(64) UNIQUE,
    endpoint TEXT,
    public_key TEXT,
    auth_token TEXT,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);

-- ---------------------------------------------------------------------------
-- Données initiales
-- ---------------------------------------------------------------------------
INSERT INTO settings (setting_key, setting_value) VALUES ('maintenance', '0') ON CONFLICT DO NOTHING;
INSERT INTO settings (setting_key, setting_value) VALUES ('montant', '25000') ON CONFLICT DO NOTHING;
INSERT INTO settings (setting_key, setting_value) VALUES ('apk_version', '1.2') ON CONFLICT DO NOTHING;
INSERT INTO settings (setting_key, setting_value) VALUES ('apk_url', '') ON CONFLICT DO NOTHING;
INSERT INTO settings (setting_key, setting_value) VALUES ('essai_gratuit_actif', '1') ON CONFLICT DO NOTHING;
INSERT INTO cron_deposits_state (id, dernier_user_id) VALUES (1, 0) ON CONFLICT DO NOTHING;

-- Quelques wallets libres pour le pool de paiement (adresses d'exemple).
INSERT INTO wallets (id, address, status, last_used_at) VALUES
    (1, 'TJwEQxE5P7QV4wVnSBFQR9YU2E11UdToJy', 'free', NOW()),
    (2, '0x1051f25d00c316acc58dffd89078e23fdbe64de6', 'free', NOW()) ON CONFLICT DO NOTHING;
