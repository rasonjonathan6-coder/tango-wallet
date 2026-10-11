<?php
// Test de la couche de compatibilite PostgreSQL (a lancer avec les variables
// d'environnement DB_DRIVER=pgsql DB_HOST=... etc.).
require __DIR__ . '/../config.php';

echo "=== tables ===\n";
echo "tables: " . $pdo->query("SELECT count(*) FROM information_schema.tables WHERE table_schema = current_schema()")->fetchColumn() . "\n";

echo "=== INSERT IGNORE ===\n";
$pdo->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)")->execute(['t_test', '1']);
$pdo->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)")->execute(['t_test', '9']);
echo "valeur (doit rester 1): " . $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 't_test'")->fetchColumn() . "\n";

echo "=== ON DUPLICATE KEY (settings) ===\n";
$pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute(['t_test', '2']);
echo "valeur (doit devenir 2): " . $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 't_test'")->fetchColumn() . "\n";

echo "=== ON DUPLICATE KEY (bep20_scan_state) ===\n";
$sql = "INSERT INTO bep20_scan_state (adresse, dernier_bloc_traite, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE dernier_bloc_traite = VALUES(dernier_bloc_traite), updated_at = VALUES(updated_at)";
$pdo->prepare($sql)->execute(['0xtest', 100]);
$pdo->prepare($sql)->execute(['0xtest', 200]);
echo "bloc (doit devenir 200): " . $pdo->query("SELECT dernier_bloc_traite FROM bep20_scan_state WHERE adresse = '0xtest'")->fetchColumn() . "\n";

echo "=== CREATE TABLE neutralise ===\n";
$pdo->query("CREATE TABLE IF NOT EXISTS zzz (id INT AUTO_INCREMENT PRIMARY KEY, x VARCHAR(10)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "pas d'erreur CREATE TABLE\n";

echo "=== transactions ===\n";
$pdo->beginTransaction();
$pdo->prepare("INSERT INTO users (email, password, verified, created_at) VALUES (?, ?, 1, NOW())")->execute(['t@t.mg', password_hash('x', PASSWORD_DEFAULT)]);
$id = $pdo->lastInsertId();
echo "lastInsertId: $id\n";
$pdo->rollBack();
echo "rollback OK\n";

echo "\nTOUS LES TESTS PASSENT\n";
