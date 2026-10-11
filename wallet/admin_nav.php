<?php
/**
 * Barre de navigation de l'espace admin (module wallet/).
 * Attend la variable $admin_nav_active pour surligner l'onglet courant.
 */
$admin_nav_active = $admin_nav_active ?? '';
?>
<nav class="admin-nav">
    <a href="../?page=admin" class="<?= $admin_nav_active === 'admin' ? 'active' : '' ?>">🏠 Tableau de bord</a>
    <a href="../?page=admin_retraits_mvola" class="<?= $admin_nav_active === 'retraits' ? 'active' : '' ?>">📲 Retraits Mvola</a>
    <a href="../?page=admin&logout=1" class="<?= $admin_nav_active === 'logout' ? 'active' : '' ?>">🚪 Quitter l'admin</a>
</nav>
