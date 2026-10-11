<?php

/**
 * Admin : gestion des adresses de depot BEP20 des clients.
 *
 * Page deleguee (index.php), accessible via ?page=admin_wallets, reservee a
 * l'admin. Permet de :
 *  - generer les 10 adresses BEP20 d'un client via Plisio ;
 *  - saisir/modifier manuellement une adresse BEP20 (colonne d'affichage) ;
 *  - tout generer en masse pour les clients qui n'ont encore rien.
 *
 * TRC20 a ete retire de l'application (frais Plisio trop eleves).
 */

if (!isset($_SESSION['admin'])) {
    header("Location:?page=dashboard");
    exit;
}

require_once "config.php";
require_once "plisio_api.php";
require_once "wallet_functions.php";

$message = null;
$erreur  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $uid = (int) ($_POST['user_id'] ?? 0);

    if ($uid <= 0) {
        $erreur = "Client invalide.";
    }

    if (!$erreur) {

        $existe = $pdo->prepare("SELECT id FROM user_wallets WHERE user_id=? LIMIT 1");
        $existe->execute([$uid]);
        if (!$existe->fetchColumn()) {
            $pdo->prepare("INSERT INTO user_wallets (user_id, solde, statut, created_at) VALUES (?,0,'actif',NOW())")
                ->execute([$uid]);
        }

        if (isset($_POST['gen_bep20'])) {

            $adresses = ensureUserBep20Addresses($pdo, $uid, 10);
            $message = "Adresses BEP20 generees (total : " . count($adresses) . ").";

        } elseif (isset($_POST['set_manual'])) {

            $bep20 = trim($_POST['bep20'] ?? '');
            $pdo->prepare("UPDATE user_wallets SET wallet_bep20=? WHERE user_id=?")
                ->execute([$bep20 ?: null, $uid]);
            $message = "Adresse BEP20 enregistree manuellement.";

        } elseif (isset($_POST['gen_all'])) {

            $usersManquants = $pdo->query("
                SELECT u.id FROM users u
                WHERE NOT EXISTS (
                    SELECT 1 FROM user_wallet_bep20 b WHERE b.user_id = u.id
                )
            ")->fetchAll(PDO::FETCH_COLUMN);

            $ok = 0;
            foreach ($usersManquants as $idUser) {

                $existe = $pdo->prepare("SELECT id FROM user_wallets WHERE user_id=? LIMIT 1");
                $existe->execute([$idUser]);
                if (!$existe->fetchColumn()) {
                    $pdo->prepare("INSERT INTO user_wallets (user_id, solde, statut, created_at) VALUES (?,0,'actif',NOW())")
                        ->execute([$idUser]);
                }

                $avant = count(getUserBep20Addresses($pdo, (int) $idUser));
                ensureUserBep20Addresses($pdo, (int) $idUser, 10);
                $apres = count(getUserBep20Addresses($pdo, (int) $idUser));
                if ($apres > $avant) {
                    $ok++;
                }
            }
            $message = "Generation en masse : $ok client(s) servi(s) sur " . count($usersManquants) . " sans adresse BEP20.";
        }
    }
}

$utilisateurs = $pdo->query("
    SELECT u.id, u.email, w.solde, w.wallet_bep20,
           (SELECT COUNT(*) FROM user_wallet_bep20 b WHERE b.user_id = u.id) AS bep20_count
    FROM users u
    LEFT JOIN user_wallets w ON w.user_id = u.id
    ORDER BY u.id ASC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Adresses de dépôt — Admin</title>
<link rel="stylesheet" href="admin_style.css?v=<?= @filemtime($_SERVER['DOCUMENT_ROOT'].'/admin_style.css') ?: time() ?>">
<link rel="stylesheet" href="wallet/wallet.css">
<style>
.wrap{max-width:1000px;margin:0 auto;padding:16px}
table{width:100%;border-collapse:collapse;font-size:13px;margin-top:8px}
th,td{border:1px solid #ddd;padding:8px;text-align:left;vertical-align:top;word-break:break-all}
th{background:#f5f5f5}
.actions{display:flex;flex-direction:column;gap:6px;min-width:190px}
.actions form{margin:0}
.msg{background:#e7f7e7;border:1px solid #9ad19a;padding:10px;border-radius:6px;margin:10px 0}
.err{background:#fdecea;border:1px solid #e0a3a3;padding:10px;border-radius:6px;margin:10px 0}
code{background:#f0f0f0;padding:2px 4px;border-radius:3px}
</style>
</head>
<body>
<div class="wrap">
  <h2>🔑 Adresses de dépôt BEP20 des clients</h2>
  <p><a href="?page=admin">← Retour à l'espace admin</a></p>

  <?php if ($message): ?><div class="msg">✅ <?= htmlspecialchars($message) ?></div><?php endif; ?>
  <?php if ($erreur): ?><div class="err">❌ <?= htmlspecialchars($erreur) ?></div><?php endif; ?>

  <form method="POST" style="margin:12px 0">
    <button type="submit" name="gen_all" class="btn"
      onclick="return confirm('Générer 10 adresses BEP20 pour tous les clients qui n\u2019en ont pas ?')">
      Générer pour tous les clients sans adresse BEP20
    </button>
  </form>

  <table>
    <tr>
      <th>ID</th><th>Client</th><th>Solde</th><th>Adresses BEP20</th><th>Actions</th>
    </tr>
    <?php foreach ($utilisateurs as $u): ?>
    <tr>
      <td><?= (int) $u['id'] ?></td>
      <td><?= htmlspecialchars($u['email'] ?? '(sans email)') ?></td>
      <td><?= htmlspecialchars((string) $u['solde']) ?></td>
      <td>
        <?= (int) $u['bep20_count'] ?> adresse(s)
        <?php if (!empty($u['wallet_bep20'])): ?><br><code><?= htmlspecialchars($u['wallet_bep20']) ?></code><?php endif; ?>
      </td>
      <td class="actions">
        <form method="POST">
          <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
          <button type="submit" name="gen_bep20" class="btn secondary">Générer 10 BEP20</button>
        </form>
        <form method="POST">
          <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
          <input type="text" name="bep20" placeholder="BEP20 manuel" value="<?= htmlspecialchars($u['wallet_bep20'] ?? '') ?>" style="width:100%">
          <button type="submit" name="set_manual" class="btn secondary">Enregistrer</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>
</body>
</html>
