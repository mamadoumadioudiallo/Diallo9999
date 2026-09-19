<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : conducteur/dashboard.php
// Rôle    : Page d'accueil de l'espace personnel du conducteur
//           (pointage, missions, formations Simandou Academy)
// ============================================================

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireConducteur();

$idConducteur = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT * FROM conducteurs WHERE id_conducteur = ?");
$stmt->execute([$idConducteur]);
$conducteur = $stmt->fetch();

// ====== MISSIONS À VENIR / EN COURS ======
$stmt = $pdo->prepare("
    SELECT m.*, v.immatriculation
    FROM missions m
    JOIN vehicules v ON m.id_vehicule = v.id_vehicule
    WHERE m.id_conducteur = ? AND m.statut IN ('planifiee', 'en_cours')
    ORDER BY m.date_debut ASC
    LIMIT 5
");
$stmt->execute([$idConducteur]);
$missionsAVenir = $stmt->fetchAll();

// Nouvelles missions non vues
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM missions
    WHERE id_conducteur = ? AND (vue_conducteur = 0 OR vue_conducteur IS NULL)
    AND statut IN ('planifiee','en_cours')
");
$stmt->execute([$idConducteur]);
$nbNouvellesMissions = (int) $stmt->fetchColumn();

$pageTitle = 'Mon espace';
require_once '../includes/header.php';
?>

<div class="dash-header-row">
    <div>
        <h1>Bonjour <?= htmlspecialchars($conducteur['prenom']) ?></h1>
        <p class="subtitle">Bienvenue sur votre espace personnel FleetIoT — Simandou 2040</p>
    </div>
</div>

<?php if ($nbNouvellesMissions > 0): ?>
<div style="background:#E1F5EE;border-left:4px solid #1D9E75;border-radius:8px;padding:14px 18px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
    <div style="display:flex;align-items:center;gap:10px;">
        <span style="font-size:24px;">&#128276;</span>
        <div>
            <p style="font-weight:700;color:#085041;font-size:14px;"><?= $nbNouvellesMissions ?> nouvelle(s) mission(s) vous ont été assignées !</p>
            <p style="font-size:12px;color:#5C6B68;">Cliquez pour consulter les détails.</p>
        </div>
    </div>
    <a href="mes_missions.php" class="btn-dash btn-dash-primary" style="white-space:nowrap;">Voir mes missions →</a>
</div>
<?php endif; ?>

<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-info">
            <p class="label">Missions à venir</p>
            <p class="value"><?= count($missionsAVenir) ?></p>
        </div>
        <div class="kpi-icon">&#128203;</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-info">
            <p class="label">Statut du permis</p>
            <p class="value"><?= date('d/m/Y', strtotime($conducteur['permis_expiration'])) ?></p>
        </div>
        <div class="kpi-icon">&#128196;</div>
    </div>
</div>

<div class="panel fleet-table-wrap">
    <div class="panel-header">
        <h3>&#128203; Mes prochaines missions</h3>
    </div>
    <table>
        <thead>
            <tr>
                <th>Véhicule</th>
                <th>Départ</th>
                <th>Destination</th>
                <th>Date</th>
                <th>Statut</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($missionsAVenir)): ?>
                <tr><td colspan="5" style="text-align:center;padding:30px;color:#999;">Aucune mission à venir pour le moment.</td></tr>
            <?php endif; ?>
            <?php foreach ($missionsAVenir as $m): ?>
                <?php
                    $statutLabel = ['planifiee' => 'Planifiée', 'en_cours' => 'En cours'];
                    $statutBadge = ['planifiee' => 'badge-warning', 'en_cours' => 'badge-success'];
                ?>
                <tr>
                    <td><?= htmlspecialchars($m['immatriculation']) ?></td>
                    <td><?= htmlspecialchars($m['lieu_depart']) ?></td>
                    <td><?= htmlspecialchars($m['lieu_destination']) ?></td>
                    <td><?= date('d/m/Y H:i', strtotime($m['date_debut'])) ?></td>
                    <td><span class="badge <?= $statutBadge[$m['statut']] ?>"><?= $statutLabel[$m['statut']] ?></span></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once '../includes/footer.php'; ?>