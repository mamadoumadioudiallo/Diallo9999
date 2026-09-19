<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : rapports/rapport_km.php
// Rôle    : Rapport de kilométrage parcouru par véhicule
//           sur une période donnée
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

// ====== VÉHICULES CONCERNÉS ======
$sqlVeh = "SELECT id_vehicule, immatriculation, marque, modele, type FROM vehicules";
$paramsVeh = [];
if (!$estAdmin) {
    $sqlVeh .= " WHERE id_superviseur = ?";
    $paramsVeh[] = $idSup;
}
$sqlVeh .= " ORDER BY immatriculation";
$stmt = $pdo->prepare($sqlVeh);
$stmt->execute($paramsVeh);
$vehicules = $stmt->fetchAll();

// ====== CALCUL KILOMÉTRAGE PAR VÉHICULE SUR LA PÉRIODE ======
$rapport = [];
$totalKm = 0;

foreach ($vehicules as $v) {
    $stmt = $pdo->prepare("
        SELECT MIN(kilometrage) AS km_min, MAX(kilometrage) AS km_max, COUNT(*) AS nb_releves
        FROM telemetrie
        WHERE id_vehicule = ? AND horodatage BETWEEN ? AND ?
    ");
    $stmt->execute([$v['id_vehicule'], $dateDebut . ' 00:00:00', $dateFin . ' 23:59:59']);
    $res = $stmt->fetch();

    $distance = ($res['km_min'] !== null) ? max(0, $res['km_max'] - $res['km_min']) : 0;
    $nbJours = max(1, (strtotime($dateFin) - strtotime($dateDebut)) / 86400 + 1);

    $rapport[] = [
        'immatriculation' => $v['immatriculation'],
        'marque_modele'   => $v['marque'] . ' ' . $v['modele'],
        'type'            => $v['type'],
        'distance'        => $distance,
        'moyenne_jour'    => $distance / $nbJours,
        'nb_releves'      => (int) $res['nb_releves'],
    ];

    $totalKm += $distance;
}

// Tri par distance décroissante (les plus actifs en premier)
usort($rapport, fn($a, $b) => $b['distance'] <=> $a['distance']);

$pageTitle = 'Rapport kilométrique';
require_once '../includes/header.php';
?>

<div class="dash-header-row">
    <div>
        <h1>Rapport kilométrique</h1>
        <p class="subtitle">Distance parcourue — du <?= date('d/m/Y', strtotime($dateDebut)) ?> au <?= date('d/m/Y', strtotime($dateFin)) ?></p>
    </div>
    <div class="dash-actions">
        <a href="export_csv.php?type=km&date_debut=<?= $dateDebut ?>&date_fin=<?= $dateFin ?>" class="btn-dash btn-dash-outline">Exporter en CSV</a>
        <a href="export_pdf.php?type=km&date_debut=<?= $dateDebut ?>&date_fin=<?= $dateFin ?>" class="btn-dash btn-dash-primary">Exporter en PDF</a>
    </div>
</div>

<!-- ====== NAVIGATION ENTRE RAPPORTS ====== -->
<div class="panel" style="padding:0;margin-bottom:14px;display:flex;">
    <a href="rapport_conso.php" class="btn-dash btn-dash-outline" style="border-radius:0;flex:1;text-align:center;border:none;">Consommation</a>
    <a href="rapport_km.php" class="btn-dash btn-dash-primary" style="border-radius:0;flex:1;text-align:center;">Kilométrage</a>
    <a href="rapport_alertes.php" class="btn-dash btn-dash-outline" style="border-radius:0;flex:1;text-align:center;border:none;">Alertes</a>
</div>

<!-- ====== FILTRE DE PÉRIODE ====== -->
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
        <button type="submit" class="btn-dash btn-dash-primary">Filtrer</button>
    </form>
</div>

<!-- ====== KPI RÉSUMÉ ====== -->
<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-info">
            <p class="label">Distance totale parcourue</p>
            <p class="value"><?= number_format($totalKm, 0, ',', ' ') ?> km</p>
            <p class="trend">Toutes flottes confondues</p>
        </div>
        <div class="kpi-icon">&#128739;</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-info">
            <p class="label">Véhicules suivis</p>
            <p class="value"><?= count($rapport) ?></p>
            <p class="trend">Sur la période sélectionnée</p>
        </div>
        <div class="kpi-icon">&#128666;</div>
    </div>
</div>

<div class="panel fleet-table-wrap">
    <div class="panel-header">
        <h3>Détail par véhicule</h3>
    </div>
    <table>
        <thead>
            <tr>
                <th>Immatriculation</th>
                <th>Marque / Modèle</th>
                <th>Type</th>
                <th>Distance parcourue</th>
                <th>Moyenne / jour</th>
                <th>Relevés télémétrie</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rapport)): ?>
                <tr><td colspan="6" style="text-align:center;padding:30px;color:#999;">Aucune donnée de télémétrie pour cette période.</td></tr>
            <?php endif; ?>
            <?php foreach ($rapport as $r): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($r['immatriculation']) ?></strong></td>
                    <td><?= htmlspecialchars($r['marque_modele']) ?></td>
                    <td><?= ucfirst($r['type']) ?></td>
                    <td><?= number_format($r['distance'], 0, ',', ' ') ?> km</td>
                    <td><?= number_format($r['moyenne_jour'], 0, ',', ' ') ?> km/j</td>
                    <td><?= $r['nb_releves'] ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once '../includes/footer.php'; ?>