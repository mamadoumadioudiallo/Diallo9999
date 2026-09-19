<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : rapports/rapport_alertes.php
// Rôle    : Rapport des alertes déclenchées sur une période donnée
// ============================================================

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireSuperviseur();

$idSup = $_SESSION['user_id'];
$estAdmin = isAdmin();

// ====== PÉRIODE (par défaut : les 7 derniers jours) ======
$dateDebut = $_GET['date_debut'] ?? date('Y-m-d', strtotime('-7 days'));
$dateFin = $_GET['date_fin'] ?? date('Y-m-d');
$filtreType = $_GET['type_alerte'] ?? '';

// ====== LISTE DES ALERTES SUR LA PÉRIODE ======
$sql = "
    SELECT a.*, v.immatriculation, v.type AS type_vehicule,
           u.nom AS traitant_nom, u.prenom AS traitant_prenom, u.role AS traitant_role
    FROM alertes a
    JOIN vehicules v ON a.id_vehicule = v.id_vehicule
    LEFT JOIN utilisateurs u ON a.id_traitant = u.id_user
    WHERE a.horodatage BETWEEN ? AND ?
";
$params = [$dateDebut . ' 00:00:00', $dateFin . ' 23:59:59'];

if (!$estAdmin) {
    $sql .= " AND v.id_superviseur = ?";
    $params[] = $idSup;
}
if ($filtreType) {
    $sql .= " AND a.type_alerte = ?";
    $params[] = $filtreType;
}
$sql .= " ORDER BY a.horodatage DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$alertes = $stmt->fetchAll();

// ====== RÉPARTITION PAR TYPE ======
$repartition = ['vitesse_excessive' => 0, 'temperature_critique' => 0, 'carburant_bas' => 0, 'hors_zone' => 0];
$nbResolues = 0;
foreach ($alertes as $a) {
    if (isset($repartition[$a['type_alerte']])) {
        $repartition[$a['type_alerte']]++;
    }
    if ($a['statut'] === 'resolue') {
        $nbResolues++;
    }
}

$pageTitle = 'Rapport des alertes';
require_once '../includes/header.php';
?>

<div class="dash-header-row">
    <div>
        <h1>Rapport des alertes</h1>
        <p class="subtitle">Du <?= date('d/m/Y', strtotime($dateDebut)) ?> au <?= date('d/m/Y', strtotime($dateFin)) ?></p>
    </div>
    <div class="dash-actions">
        <a href="export_csv.php?type=alertes&date_debut=<?= $dateDebut ?>&date_fin=<?= $dateFin ?>&type_alerte=<?= $filtreType ?>" class="btn-dash btn-dash-outline">Exporter en CSV</a>
        <a href="export_pdf.php?type=alertes&date_debut=<?= $dateDebut ?>&date_fin=<?= $dateFin ?>&type_alerte=<?= $filtreType ?>" class="btn-dash btn-dash-primary">Exporter en PDF</a>
    </div>
</div>

<!-- ====== NAVIGATION ENTRE RAPPORTS ====== -->
<div class="panel" style="padding:0;margin-bottom:14px;display:flex;">
    <a href="rapport_conso.php" class="btn-dash btn-dash-outline" style="border-radius:0;flex:1;text-align:center;border:none;">Consommation</a>
    <a href="rapport_km.php" class="btn-dash btn-dash-outline" style="border-radius:0;flex:1;text-align:center;border:none;">Kilométrage</a>
    <a href="rapport_alertes.php" class="btn-dash btn-dash-primary" style="border-radius:0;flex:1;text-align:center;">Alertes</a>
</div>

<!-- ====== FILTRES ====== -->
<div class="panel" style="padding:16px 20px;margin-bottom:14px;">
    <form method="GET" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap;">
        <div class="form-group" style="margin:0;">
            <label>Du</label>
            <input type="date" name="date_debut" value="<?= htmlspecialchars($dateDebut) ?>">
        </div>
        <div class="form-group" style="margin:0;">
            <label>Au</label>
            <input type="date" name="date_fin" value="<?= htmlspecialchars($dateFin) ?>">
        </div>
        <div class="form-group" style="margin:0;">
            <label>Type d'alerte</label>
            <select name="type_alerte" style="width:200px;">
                <option value="">Tous les types</option>
                <option value="vitesse_excessive" <?= $filtreType === 'vitesse_excessive' ? 'selected' : '' ?>>Vitesse excessive</option>
                <option value="temperature_critique" <?= $filtreType === 'temperature_critique' ? 'selected' : '' ?>>Température critique</option>
                <option value="carburant_bas" <?= $filtreType === 'carburant_bas' ? 'selected' : '' ?>>Carburant bas</option>
                <option value="hors_zone" <?= $filtreType === 'hors_zone' ? 'selected' : '' ?>>Hors corridor</option>
                            </select>
                        </div>
                        <button type="submit" class="btn-dash btn-dash-primary">Filtrer</button>
                    </form>
        </div>

<!-- ====== KPI RÉSUMÉ ====== -->
<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-info">
            <p class="label">Total alertes</p>
            <p class="value"><?= count($alertes) ?></p>
            <p class="trend">Sur la période sélectionnée</p>
        </div>
        <div class="kpi-icon">&#128276;</div>
    </div>
    <div class="kpi-card danger">
        <div class="kpi-info">
            <p class="label">Vitesse excessive</p>
            <p class="value"><?= $repartition['vitesse_excessive'] ?></p>
            <p class="trend">Dépassements de seuil</p>
        </div>
        <div class="kpi-icon">&#128680;</div>
    </div>
    <div class="kpi-card warning">
        <div class="kpi-info">
            <p class="label">Température critique</p>
            <p class="value"><?= $repartition['temperature_critique'] ?></p>
            <p class="trend">Surchauffes moteur</p>
        </div>
        <div class="kpi-icon">&#127777;</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-info">
            <p class="label">Carburant bas</p>
            <p class="value"><?= $repartition['carburant_bas'] ?></p>
            <p class="trend"><?= count($alertes) > 0 ? round(($nbResolues / count($alertes)) * 100) . '% résolues' : '—' ?></p>
        </div>
        <div class="kpi-icon">&#128167;</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-info">
            <p class="label">Hors corridor minier</p>
            <p class="value"><?= $repartition['hors_zone'] ?></p>
            <p class="trend">Sorties de zone détectées</p>
        </div>
        <div class="kpi-icon">&#128205;</div>
    </div>
</div>

<div class="panel fleet-table-wrap">
    <div class="panel-header">
        <h3>Détail des alertes</h3>
    </div>
    <table>
        <thead>
            <tr>
                <th>Véhicule</th>
                <th>Type d'alerte</th>
                <th>Valeur déclenchante</th>
                <th>Seuil configuré</th>
                <th>Date</th>
                <th>Statut</th>
                <th>Traité par</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($alertes)): ?>
                <tr><td colspan="7" style="text-align:center;padding:30px;color:#999;">Aucune alerte sur cette période.</td></tr>
            <?php endif; ?>
            <?php foreach ($alertes as $a): ?>
                <?php
                    $statutBadge = [
                        'non_traitee' => 'badge-danger',
                        'en_cours'    => 'badge-warning',
                        'resolue'     => 'badge-success',
                    ];
                    $statutLabel = [
                        'non_traitee' => 'Non traitée',
                        'en_cours'    => 'En cours',
                        'resolue'     => 'Résolue',
                    ];
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($a['immatriculation']) ?></strong> <span style="color:#999;font-size:11px;">(<?= ucfirst($a['type_vehicule']) ?>)</span></td>
                    <td><?= htmlspecialchars(str_replace('_', ' ', ucfirst($a['type_alerte']))) ?></td>
                    <td><?= htmlspecialchars($a['valeur_declenchante']) ?></td>
                    <td><?= htmlspecialchars($a['seuil_configure']) ?></td>
                    <td><?= date('d/m/Y H:i', strtotime($a['horodatage'])) ?></td>
                    <td><span class="badge <?= $statutBadge[$a['statut']] ?>"><?= $statutLabel[$a['statut']] ?></span></td>
                    <td>
                        <?php if ($a['traitant_nom']): ?>
                            <?= htmlspecialchars($a['traitant_prenom'] . ' ' . $a['traitant_nom']) ?>
                            <span style="color:#999;font-size:11px;">(<?= $a['traitant_role'] === 'admin' ? 'Admin' : 'Superviseur' ?>)</span>
                        <?php else: ?>
                            <span style="color:#999;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once '../includes/footer.php'; ?>