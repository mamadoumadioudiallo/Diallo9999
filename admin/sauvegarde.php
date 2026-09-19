<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : admin/sauvegarde.php
// Rôle    : Sauvegarde manuelle (téléchargement immédiat) +
//           liste des sauvegardes automatiques déjà réalisées
// ============================================================

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/backup.php';

requireAdmin();

// ====== TÉLÉCHARGEMENT IMMÉDIAT D'UNE SAUVEGARDE ======
if (isset($_GET['telecharger'])) {
    $sql = genererSauvegardeSql($pdo, DB_NAME);
    $nomFichier = 'fleetiot_sauvegarde_' . date('Y-m-d_H-i-s') . '.sql';

    enregistrerAudit($pdo, 'sauvegarde_manuelle', $nomFichier);

    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nomFichier . '"');
    header('Content-Length: ' . strlen($sql));
    echo $sql;
    exit();
}

// ====== LISTE DES SAUVEGARDES AUTOMATIQUES EXISTANTES ======
$dossierBackup = '../backups/';
$fichiers = glob($dossierBackup . 'backup_*.sql');
usort($fichiers, function ($a, $b) { return filemtime($b) <=> filemtime($a); });

$pageTitle = 'Sauvegardes';
require_once '../includes/header.php';
?>

<div class="dash-header-row">
    <div>
        <h1>Sauvegardes de la base de données</h1>
        <p class="subtitle">Protection contre la perte de données (suppression accidentelle, panne disque...)</p>
    </div>
    <div class="dash-actions">
        <a href="?telecharger=1" class="btn-dash btn-dash-primary">&#128190; Télécharger une sauvegarde maintenant</a>
    </div>
</div>

<div class="panel" style="padding:16px 20px;margin-bottom:16px;background:#F5F7F6;border:none;">
    <p style="font-size:13px;color:#5C6B68;">
        &#9888; Les sauvegardes automatiques quotidiennes nécessitent une tâche planifiée côté serveur
         et les instructions de configuration fournies.
        Seules les 14 sauvegardes automatiques les plus récentes sont conservées.
    </p>
</div>

<div class="panel fleet-table-wrap">
    <div class="panel-header">
        <h3>Sauvegardes automatiques existantes</h3>
    </div>
    <table>
        <thead>
            <tr>
                <th>Nom du fichier</th>
                <th>Date de création</th>
                <th>Taille</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($fichiers)): ?>
                <tr><td colspan="3" style="text-align:center;padding:30px;color:#999;">Aucune sauvegarde automatique pour le moment. Configure la tâche planifiée pour en générer.</td></tr>
            <?php endif; ?>
            <?php foreach ($fichiers as $f): ?>
                <tr>
                    <td><?= htmlspecialchars(basename($f)) ?></td>
                    <td><?= date('d/m/Y H:i:s', filemtime($f)) ?></td>
                    <td><?= number_format(filesize($f) / 1024, 1) ?> Ko</td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once '../includes/footer.php'; ?>