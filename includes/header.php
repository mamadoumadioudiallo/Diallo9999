<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : includes/header.php
// Rôle    : En-tête commun de la zone privée (admin + superviseur + conducteur)
// Attendu : variable $pageTitle definie avant l'inclusion (optionnel)
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$role = $_SESSION['user_role'] ?? '';
$prenom = $_SESSION['user_prenom'] ?? '';
$userPhoto = $_SESSION['user_photo'] ?? null;
$isAdminArea = ($role === 'admin');

// Préfixe relatif vers la racine du site (depuis admin/, superviseur/ ou conducteur/)
$base = '../';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? htmlspecialchars($pageTitle) . ' — FleetIoT' : 'FleetIoT' ?></title>
    <link rel="stylesheet" href="<?= $base ?>assets/css/style.css">
    <link rel="stylesheet" href="<?= $base ?>assets/css/dashboard.css">
    <link rel="stylesheet" href="<?= $base ?>assets/css/responsive.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
</head>
<body class="dashboard-body">

    <!-- ============ NAVBAR PRIVÉE ============ -->
    <nav class="dash-navbar">
        <div class="dash-navbar-left">
            <a href="<?= $base ?>index.php" class="dash-logo">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M3 16V8a1 1 0 011-1h10l4 4v5a1 1 0 01-1 1H4a1 1 0 01-1-1z"/>
                    <circle cx="7" cy="17" r="1.5"/>
                    <circle cx="16" cy="17" r="1.5"/>
                </svg>
                FleetIoT
            </a>
            <span class="dash-role-badge"><?= $isAdminArea ? 'Admin' : ($role === 'conducteur' ? 'Conducteur' : 'Superviseur') ?></span>
        </div>

        <ul class="dash-navbar-menu" id="dashNavMenu">
            <?php if ($isAdminArea): ?>
                <li><a href="<?= $base ?>admin/dashboard.php">Dashboard</a></li>
                <li><a href="<?= $base ?>admin/vehicules.php">Véhicules</a></li>
                <li><a href="<?= $base ?>admin/conducteurs.php">Conducteurs</a></li>
                <li><a href="<?= $base ?>admin/missions.php">Missions</a></li>
                <li><a href="<?= $base ?>admin/superviseurs.php">Superviseurs</a></li>
                <li><a href="<?= $base ?>rapports/rapport_conso.php">Rapports</a></li>
                <li><a href="<?= $base ?>rapports/rapport_mensuel.php">Rapport officiel</a></li>
                <li><a href="<?= $base ?>admin/journal_audit.php">Audit</a></li>
                <li><a href="<?= $base ?>admin/formations.php">Formations</a></li>
                <li><a href="<?= $base ?>admin/pointage.php">Pointages</a></li>
                <li><a href="<?= $base ?>admin/sauvegarde.php">Sauvegardes</a></li>
                <li><a href="<?= $base ?>admin/configuration.php">Configuration</a></li>

            <?php elseif ($role === 'conducteur'): ?>
                <li><a href="<?= $base ?>conducteur/dashboard.php">Mon espace</a></li>
                <li><a href="<?= $base ?>conducteur/pointage.php">Pointage</a></li>
                <li><a href="<?= $base ?>conducteur/mes_missions.php">Mes missions</a></li>
                <li><a href="<?= $base ?>conducteur/formations.php">Formations</a></li>

            <?php else: ?>
                <li><a href="<?= $base ?>superviseur/dashboard.php">Dashboard</a></li>
                <li><a href="<?= $base ?>superviseur/mes_vehicules.php">Mes véhicules</a></li>
                <li><a href="<?= $base ?>superviseur/mes_conducteurs.php">Mes conducteurs</a></li>
                <li><a href="<?= $base ?>superviseur/formations.php">Formations</a></li>
                <li><a href="<?= $base ?>admin/pointage.php">Pointages</a></li>
                <li><a href="<?= $base ?>superviseur/mes_missions.php">Mes missions</a></li>
                <li><a href="<?= $base ?>superviseur/mes_alertes.php">Mes alertes</a></li>
                <li><a href="<?= $base ?>rapports/rapport_conso.php">Rapports</a></li>
            <?php endif; ?>
        </ul>

        <div class="dash-navbar-right">
            <div class="dash-user-dropdown-wrapper">
                <button class="dash-user" id="dashUserToggle" type="button">
                    <?php if (!empty($userPhoto)): ?>
                        <img src="<?= $base . htmlspecialchars($userPhoto) ?>"
                             alt="Photo de profil"
                             style="width:32px;height:32px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,0.4);">
                    <?php else: ?>
                        <div class="dash-avatar"><?= strtoupper(substr($prenom, 0, 2)) ?></div>
                    <?php endif; ?>
                    <span><?= htmlspecialchars($prenom) ?></span>
                </button>
                <div class="dash-user-dropdown" id="dashUserDropdown">
                    <a href="<?= $base ?><?= $isAdminArea ? 'admin/' : ($role === 'conducteur' ? 'conducteur/' : 'superviseur/') ?>profil.php">
                        &#128100; Mon profil
                    </a>
                    <a href="<?= $base ?>deconnexion.php">
                        &#128682; Déconnexion
                    </a>
                </div>
            </div>
            <button class="dash-burger" id="dashBurger" aria-label="Menu" aria-expanded="false">
                <span></span><span></span><span></span>
            </button>
        </div>
    </nav>

    <main class="dash-main">