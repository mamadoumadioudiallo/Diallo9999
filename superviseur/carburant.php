<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireSuperviseur();
$idSup = $_SESSION['user_id'];

$date           = $_GET['date']     ?? date('Y-m-d');
$filtreVehicule = $_GET['vehicule'] ?? '';

// KPI
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT v.id_vehicule) AS nb_vehicules,
           COALESCE(AVG(d.carburant), 0) AS carburant_moy,
           COALESCE(MIN(d.carburant), 0) AS carburant_min,
           COUNT(CASE WHEN d.carburant < 10 THEN 1 END) AS nb_critique
    FROM vehicules v
    JOIN (
        SELECT t1.id_vehicule, t1.carburant FROM telemetrie t1
        INNER JOIN (SELECT id_vehicule, MAX(horodatage) AS max_horo FROM telemetrie WHERE DATE(horodatage) = ? GROUP BY id_vehicule) t2
        ON t1.id_vehicule = t2.id_vehicule AND t1.horodatage = t2.max_horo
    ) d ON v.id_vehicule = d.id_vehicule
    WHERE v.statut = 'actif' AND v.id_superviseur = ?
");
$stmt->execute([$date, $idSup]);
$kpiJour = $stmt->fetch();

// Détail
$conditions = ["DATE(t.horodatage) = ?", "v.id_superviseur = ?"];
$params = [$date, $idSup];
if ($filtreVehicule) { $conditions[] = "v.id_vehicule = ?"; $params[] = $filtreVehicule; }
$where = 'WHERE ' . implode(' AND ', $conditions);

$stmt = $pdo->prepare("
    SELECT v.id_vehicule, v.immatriculation, v.type, v.marque, v.modele, v.capacite_carburant,
           MAX(t.carburant) AS carburant_debut, MIN(t.carburant) AS carburant_fin,
           MAX(t.carburant) - MIN(t.carburant) AS conso_estimee,
           MAX(t.horodatage) AS dernier_releve
    FROM vehicules v JOIN telemetrie t ON v.id_vehicule = t.id_vehicule
    $where
    GROUP BY v.id_vehicule, v.immatriculation, v.type, v.marque, v.modele, v.capacite_carburant
    ORDER BY conso_estimee DESC
");
$stmt->execute($params);
$vehiculesCarburant = $stmt->fetchAll();

// Évolution 7 jours
$evolCond = ["t.horodatage >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)", "v.id_superviseur = ?"];
$evolParams = [$idSup];
if ($filtreVehicule) { $evolCond[] = "v.id_vehicule = ?"; $evolParams[] = $filtreVehicule; }

$stmt = $pdo->prepare("
    SELECT DATE(t.horodatage) AS jour, AVG(t.carburant) AS carburant_moy
    FROM telemetrie t JOIN vehicules v ON t.id_vehicule = v.id_vehicule
    WHERE " . implode(' AND ', $evolCond) . "
    GROUP BY DATE(t.horodatage) ORDER BY jour ASC
");
$stmt->execute($evolParams);
$evolution = $stmt->fetchAll();

// Alertes carburant bas
$stmt = $pdo->prepare("
    SELECT a.*, v.immatriculation FROM alertes a
    JOIN vehicules v ON a.id_vehicule = v.id_vehicule
    WHERE a.type_alerte = 'carburant_bas' AND DATE(a.horodatage) = ? AND v.id_superviseur = ?
    ORDER BY a.horodatage DESC LIMIT 20
");
$stmt->execute([$date, $idSup]);
$alertesCarburant = $stmt->fetchAll();

$vehiculesListe = $pdo->prepare("SELECT id_vehicule, immatriculation FROM vehicules WHERE statut = 'actif' AND id_superviseur = ? ORDER BY immatriculation");
$vehiculesListe->execute([$idSup]);
$vehiculesListe = $vehiculesListe->fetchAll();

$pageTitle = 'Carburant';
require_once '../includes/header.php';
?>

<div class="dash-header-row">
    <div><h1>&#128167; Suivi du carburant</h1><p class="subtitle">Mes véhicules</p></div>
    <div class="dash-actions"><a href="dashboard.php" class="btn-dash btn-dash-outline">&#8592; Retour</a></div>
</div>

<div class="kpi-grid" style="margin-bottom:16px;">
    <div class="kpi-card"><div class="kpi-info"><p class="label">Niveau moyen</p><p class="value"><?= round($kpiJour['carburant_moy'] ?? 0) ?> %</p><p class="trend">Tous mes véhicules</p></div><div class="kpi-icon">&#128167;</div></div>
    <div class="kpi-card <?= ($kpiJour['carburant_min'] ?? 100) < 10 ? 'danger' : 'warning' ?>"><div class="kpi-info"><p class="label">Niveau minimum</p><p class="value"><?= round($kpiJour['carburant_min'] ?? 0) ?> %</p><p class="trend">Véhicule le plus bas</p></div><div class="kpi-icon">&#9888;</div></div>
    <div class="kpi-card <?= ($kpiJour['nb_critique'] ?? 0) > 0 ? 'danger' : '' ?>"><div class="kpi-info"><p class="label">Véhicules critiques</p><p class="value"><?= $kpiJour['nb_critique'] ?? 0 ?></p><p class="trend">Niveau &lt; 10%</p></div><div class="kpi-icon">&#128308;</div></div>
    <div class="kpi-card"><div class="kpi-info"><p class="label">Alertes carburant</p><p class="value"><?= count($alertesCarburant) ?></p><p class="trend">Aujourd'hui</p></div><div class="kpi-icon">&#128276;</div></div>
</div>

<div class="panel" style="padding:16px 20px;margin-bottom:16px;">
    <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
        <div class="form-group" style="margin:0;flex:1;min-width:140px;"><label style="font-size:12px;">Date</label><input type="date" name="date" value="<?= htmlspecialchars($date) ?>" max="<?= date('Y-m-d') ?>"></div>
        <div class="form-group" style="margin:0;flex:1;min-width:160px;">
            <label style="font-size:12px;">Véhicule</label>
            <select name="vehicule">
                <option value="">Tous mes véhicules</option>
                <?php foreach ($vehiculesListe as $v): ?>
                    <option value="<?= $v['id_vehicule'] ?>" <?= $filtreVehicule == $v['id_vehicule'] ? 'selected' : '' ?>><?= htmlspecialchars($v['immatriculation']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn-dash btn-dash-primary" style="height:38px;">Filtrer</button>
        <a href="carburant.php" class="btn-dash btn-dash-outline" style="height:38px;line-height:22px;">Réinitialiser</a>
    </form>
</div>

<div class="panel fleet-table-wrap" style="margin-bottom:16px;">
    <div class="panel-header"><h3>&#128203; Niveaux par véhicule — <?= date('d/m/Y', strtotime($date)) ?></h3></div>
    <table>
        <thead><tr><th>Véhicule</th><th>Type</th><th>Niveau actuel</th><th>Jauge</th><th>Conso. estimée</th><th>Capacité</th><th>Dernier relevé</th></tr></thead>
        <tbody>
            <?php if (empty($vehiculesCarburant)): ?><tr><td colspan="7" style="text-align:center;padding:30px;color:#999;">Aucune donnée.</td></tr><?php endif; ?>
            <?php foreach ($vehiculesCarburant as $v): ?>
                <?php $niveau = (float) $v['carburant_fin']; $critique = $niveau < 10; $warning = $niveau < 25 && !$critique; $c = $critique ? '#E24B4A' : ($warning ? '#BA7517' : '#1D9E75'); ?>
                <tr style="background:<?= $critique ? '#FFF8F8' : '' ?>;">
                    <td><a href="fiche_vehicule.php?id=<?= $v['id_vehicule'] ?>" style="color:#0F6E56;font-weight:600;"><?= htmlspecialchars($v['immatriculation']) ?></a><br><span style="font-size:11px;color:#999;"><?= htmlspecialchars($v['marque'] . ' ' . $v['modele']) ?></span></td>
                    <td><?= ucfirst($v['type']) ?></td>
                    <td><strong style="color:<?= $c ?>;"><?= round($niveau) ?> %<?= $critique ? ' &#9888;' : '' ?></strong></td>
                    <td style="min-width:100px;"><div style="background:#E9ECEC;border-radius:4px;height:8px;"><div style="background:<?= $c ?>;width:<?= min(100, round($niveau)) ?>%;height:8px;border-radius:4px;"></div></div></td>
                    <td><?= round($v['conso_estimee'], 1) ?> %</td>
                    <td><?= $v['capacite_carburant'] ?> L</td>
                    <td style="font-size:12px;"><?= date('H:i', strtotime($v['dernier_releve'])) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="panel" style="padding:20px;">
    <div class="panel-header" style="margin-bottom:12px;"><h3>&#128202; Niveau moyen carburant — 7 derniers jours<?php
        if ($filtreVehicule) {
            foreach ($vehiculesListe as $v) { if ($v['id_vehicule'] == $filtreVehicule) echo ' (' . htmlspecialchars($v['immatriculation']) . ')'; }
        }
    ?></h3></div>
    <canvas id="carburantChart" height="80"></canvas>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
var evolution = <?= json_encode($evolution) ?>;
new Chart(document.getElementById('carburantChart'), {
    type: 'line',
    data: {
        labels: evolution.map(function(e) { var d = new Date(e.jour + 'T00:00:00'); return d.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' }); }),
        datasets: [
            { label: 'Niveau moyen (%)', data: evolution.map(function(e) { return parseFloat(e.carburant_moy) || 0; }), borderColor: '#BA7517', backgroundColor: 'rgba(186,117,23,0.1)', tension: 0.3, fill: true },
            { label: 'Seuil critique (10%)', data: evolution.map(function() { return 10; }), borderColor: '#E24B4A', borderDash: [6,3], pointRadius: 0 }
        ]
    },
    options: { responsive: true, scales: { y: { beginAtZero: true, max: 100 } } }
});
</script>

<?php require_once '../includes/footer.php'; ?>
