<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireSuperviseur();
$idSup = $_SESSION['user_id'];

$date           = $_GET['date']     ?? date('Y-m-d');
$filtreVehicule = $_GET['vehicule'] ?? '';

// KPI du jour
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT t.id_vehicule) AS nb_vehicules,
           COALESCE(SUM(sous.km_jour), 0) AS km_total,
           COALESCE(AVG(sous.vitesse_moy), 0) AS vitesse_moy,
           COALESCE(MAX(sous.vitesse_max), 0) AS vitesse_max
    FROM (
        SELECT t.id_vehicule,
               AVG(t.vitesse) AS vitesse_moy, MAX(t.vitesse) AS vitesse_max,
               MAX(t.kilometrage) - MIN(t.kilometrage) AS km_jour
        FROM telemetrie t
        JOIN vehicules v ON t.id_vehicule = v.id_vehicule
        WHERE DATE(t.horodatage) = ? AND v.id_superviseur = ?
        GROUP BY t.id_vehicule HAVING km_jour > 0
    ) sous
    JOIN telemetrie t ON t.id_vehicule = sous.id_vehicule
");
$stmt->execute([$date, $idSup]);
$kpiJour = $stmt->fetch();

// Détail par véhicule
$conditions = ["DATE(t.horodatage) = ?", "v.id_superviseur = ?"];
$params = [$date, $idSup];
if ($filtreVehicule) { $conditions[] = "v.id_vehicule = ?"; $params[] = $filtreVehicule; }
$where = 'WHERE ' . implode(' AND ', $conditions);

$stmt = $pdo->prepare("
    SELECT v.id_vehicule, v.immatriculation, v.type, v.marque, v.modele,
           MAX(t.vitesse) AS vitesse_max, AVG(t.vitesse) AS vitesse_moy,
           MAX(t.kilometrage) - MIN(t.kilometrage) AS km_jour,
           MIN(t.horodatage) AS premier_releve, MAX(t.horodatage) AS dernier_releve
    FROM vehicules v
    JOIN telemetrie t ON v.id_vehicule = t.id_vehicule
    $where
    GROUP BY v.id_vehicule, v.immatriculation, v.type, v.marque, v.modele
    HAVING km_jour > 0 ORDER BY km_jour DESC
");
$stmt->execute($params);
$vehiculesKm = $stmt->fetchAll();

// Évolution 7 jours
$evolCond = ["t.horodatage >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)", "v.id_superviseur = ?"];
$evolParams = [$idSup];
if ($filtreVehicule) { $evolCond[] = "v.id_vehicule = ?"; $evolParams[] = $filtreVehicule; }

$stmt = $pdo->prepare("
    SELECT DATE(sous.horodatage) AS jour, SUM(sous.km_jour) AS km_total
    FROM (
        SELECT t.id_vehicule, DATE(t.horodatage) AS horodatage,
               MAX(t.kilometrage) - MIN(t.kilometrage) AS km_jour
        FROM telemetrie t JOIN vehicules v ON t.id_vehicule = v.id_vehicule
        WHERE " . implode(' AND ', $evolCond) . "
        GROUP BY t.id_vehicule, DATE(t.horodatage)
    ) sous GROUP BY DATE(sous.horodatage) ORDER BY jour ASC
");
$stmt->execute($evolParams);
$evolution = $stmt->fetchAll();

$vehiculesListe = $pdo->prepare("SELECT id_vehicule, immatriculation FROM vehicules WHERE statut = 'actif' AND id_superviseur = ? ORDER BY immatriculation");
$vehiculesListe->execute([$idSup]);
$vehiculesListe = $vehiculesListe->fetchAll();

$pageTitle = 'Kilométrage';
require_once '../includes/header.php';
?>

<div class="dash-header-row">
    <div><h1>&#128739; Kilométrage journalier</h1><p class="subtitle">Mes véhicules — <?= date('d/m/Y', strtotime($date)) ?></p></div>
    <div class="dash-actions"><a href="dashboard.php" class="btn-dash btn-dash-outline">&#8592; Retour</a></div>
</div>

<div class="kpi-grid" style="margin-bottom:16px;">
    <div class="kpi-card"><div class="kpi-info"><p class="label">Kilométrage total</p><p class="value"><?= number_format($kpiJour['km_total'] ?? 0, 0, ',', ' ') ?> km</p><p class="trend"><?= date('d/m/Y', strtotime($date)) ?></p></div><div class="kpi-icon">&#128739;</div></div>
    <div class="kpi-card"><div class="kpi-info"><p class="label">Véhicules en mouvement</p><p class="value"><?= $kpiJour['nb_vehicules'] ?? 0 ?></p><p class="trend">Ont roulé aujourd'hui</p></div><div class="kpi-icon">&#128666;</div></div>
    <div class="kpi-card"><div class="kpi-info"><p class="label">Vitesse moyenne</p><p class="value"><?= round($kpiJour['vitesse_moy'] ?? 0) ?> km/h</p><p class="trend">Moyenne flotte</p></div><div class="kpi-icon">&#128225;</div></div>
    <div class="kpi-card"><div class="kpi-info"><p class="label">Vitesse max</p><p class="value"><?= round($kpiJour['vitesse_max'] ?? 0) ?> km/h</p><p class="trend">Pic du jour</p></div><div class="kpi-icon">&#9889;</div></div>
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
        <a href="kilometrage.php" class="btn-dash btn-dash-outline" style="height:38px;line-height:22px;">Réinitialiser</a>
    </form>
</div>

<div class="panel fleet-table-wrap" style="margin-bottom:16px;">
    <div class="panel-header"><h3>&#128203; Détail par véhicule — <?= date('d/m/Y', strtotime($date)) ?></h3></div>
    <table>
        <thead><tr><th>Véhicule</th><th>Type</th><th>Km du jour</th><th>Vitesse moy.</th><th>Vitesse max</th><th>Premier relevé</th><th>Dernier relevé</th></tr></thead>
        <tbody>
            <?php if (empty($vehiculesKm)): ?><tr><td colspan="7" style="text-align:center;padding:30px;color:#999;">Aucun kilométrage pour cette date.</td></tr><?php endif; ?>
            <?php foreach ($vehiculesKm as $v): ?>
                <tr>
                    <td><a href="fiche_vehicule.php?id=<?= $v['id_vehicule'] ?>" style="color:#0F6E56;font-weight:600;"><?= htmlspecialchars($v['immatriculation']) ?></a><br><span style="font-size:11px;color:#999;"><?= htmlspecialchars($v['marque'] . ' ' . $v['modele']) ?></span></td>
                    <td><?= ucfirst($v['type']) ?></td>
                    <td><strong><?= number_format($v['km_jour'], 1, ',', ' ') ?> km</strong></td>
                    <td><?= round($v['vitesse_moy']) ?> km/h</td>
                    <td style="color:<?= $v['vitesse_max'] > 120 ? '#E24B4A' : '#1A1A1A' ?>;"><?= round($v['vitesse_max']) ?> km/h</td>
                    <td style="font-size:12px;"><?= date('H:i', strtotime($v['premier_releve'])) ?></td>
                    <td style="font-size:12px;"><?= date('H:i', strtotime($v['dernier_releve'])) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="panel" style="padding:20px;">
    <div class="panel-header" style="margin-bottom:12px;"><h3>&#128202; Évolution kilométrique — 7 derniers jours<?= $filtreVehicule ? ' (' . htmlspecialchars($vehiculesListe[array_search($filtreVehicule, array_column($vehiculesListe, 'id_vehicule'))]['immatriculation'] ?? '') . ')' : '' ?></h3></div>
    <canvas id="kmChart" height="80"></canvas>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
var evolution = <?= json_encode($evolution) ?>;
new Chart(document.getElementById('kmChart'), {
    type: 'bar',
    data: {
        labels: evolution.map(function(e) { var d = new Date(e.jour + 'T00:00:00'); return d.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' }); }),
        datasets: [{ label: 'Kilométrage (km)', data: evolution.map(function(e) { return parseFloat(e.km_total) || 0; }), backgroundColor: '#1D9E75', borderRadius: 4 }]
    },
    options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
});
</script>

<?php require_once '../includes/footer.php'; ?>
