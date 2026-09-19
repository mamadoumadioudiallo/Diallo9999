<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : admin/kilometrage.php
// Rôle    : Page dédiée au kilométrage journalier par véhicule
// ============================================================

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAdmin();

// ====== FILTRES ======
$date           = $_GET['date']     ?? date('Y-m-d');
$filtreType     = $_GET['type']     ?? '';
$filtreSup      = $_GET['sup']      ?? '';
$filtreVehicule = $_GET['vehicule'] ?? '';

// ====== KPI GLOBAUX DU JOUR ======
$stmt = $pdo->prepare("
    SELECT
        COUNT(DISTINCT t.id_vehicule) AS nb_vehicules,
        COALESCE(SUM(t.distance_km), 0) AS km_total,
        COALESCE(AVG(t.vitesse_moy), 0) AS vitesse_moy,
        COALESCE(MAX(t.vitesse_max), 0) AS vitesse_max
    FROM (
        SELECT
            id_vehicule,
            AVG(vitesse) AS vitesse_moy,
            MAX(vitesse) AS vitesse_max,
            MAX(kilometrage) - MIN(kilometrage) AS distance_km
        FROM telemetrie
        WHERE DATE(horodatage) = ?
        GROUP BY id_vehicule
        HAVING distance_km > 0
    ) t
");
$stmt->execute([$date]);
$kpiJour = $stmt->fetch();

// ====== KILOMÉTRAGE PAR VÉHICULE ======
$conditions = ["DATE(t.horodatage) = ?"];
$params = [$date];

if ($filtreType)     { $conditions[] = "v.type = ?";           $params[] = $filtreType; }
if ($filtreSup)      { $conditions[] = "v.id_superviseur = ?"; $params[] = $filtreSup; }
if ($filtreVehicule) { $conditions[] = "v.id_vehicule = ?";    $params[] = $filtreVehicule; }

$where = 'WHERE ' . implode(' AND ', $conditions);

$stmt = $pdo->prepare("
    SELECT
        v.id_vehicule, v.immatriculation, v.type, v.marque, v.modele,
        u.nom AS sup_nom, u.prenom AS sup_prenom,
        MAX(t.vitesse) AS vitesse_max,
        AVG(t.vitesse) AS vitesse_moy,
        MAX(t.kilometrage) - MIN(t.kilometrage) AS km_jour,
        MIN(t.horodatage) AS premier_releve,
        MAX(t.horodatage) AS dernier_releve,
        COUNT(*) AS nb_releves
    FROM vehicules v
    JOIN telemetrie t ON v.id_vehicule = t.id_vehicule
    LEFT JOIN utilisateurs u ON v.id_superviseur = u.id_user
    $where
    GROUP BY v.id_vehicule, v.immatriculation, v.type, v.marque, v.modele, u.nom, u.prenom
    HAVING km_jour > 0
    ORDER BY km_jour DESC
");
$stmt->execute($params);
$vehiculesKm = $stmt->fetchAll();

// ====== ÉVOLUTION 7 DERNIERS JOURS (filtrée selon type et superviseur) ======
$evolConditions = ["t.horodatage >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)"];
$evolParams = [];

if ($filtreType) {
    $evolConditions[] = "v.type = ?";
    $evolParams[] = $filtreType;
}
if ($filtreSup) {
    $evolConditions[] = "v.id_superviseur = ?";
    $evolParams[] = $filtreSup;
}
if ($filtreVehicule) {
    $evolConditions[] = "v.id_vehicule = ?";
    $evolParams[] = $filtreVehicule;
}
$evolWhere = 'WHERE ' . implode(' AND ', $evolConditions);

$stmt = $pdo->prepare("
    SELECT DATE(sous.horodatage) AS jour, SUM(sous.km_jour) AS km_total
    FROM (
        SELECT t.id_vehicule, DATE(t.horodatage) AS horodatage,
               MAX(t.kilometrage) - MIN(t.kilometrage) AS km_jour
        FROM telemetrie t
        JOIN vehicules v ON t.id_vehicule = v.id_vehicule
        $evolWhere
        GROUP BY t.id_vehicule, DATE(t.horodatage)
    ) sous
    GROUP BY DATE(sous.horodatage)
    ORDER BY jour ASC
");
$stmt->execute($evolParams);
$evolution = $stmt->fetchAll();

// ====== LISTE SUPERVISEURS (pour filtre) ======
$superviseurs = $pdo->query("SELECT id_user, nom, prenom FROM utilisateurs WHERE role = 'superviseur' AND statut = 'actif'")->fetchAll();

// Liste véhicules filtrée par superviseur si sélectionné
if ($filtreSup) {
    $stmtV = $pdo->prepare("SELECT id_vehicule, immatriculation, type FROM vehicules WHERE statut = 'actif' AND id_superviseur = ? ORDER BY immatriculation");
    $stmtV->execute([$filtreSup]);
} else {
    $stmtV = $pdo->query("SELECT id_vehicule, immatriculation, type FROM vehicules WHERE statut = 'actif' ORDER BY immatriculation");
}
$vehiculesListe = $stmtV->fetchAll();

$pageTitle = 'Kilométrage du jour';
require_once '../includes/header.php';
?>

<div class="dash-header-row">
    <div>
        <h1>&#128739; Kilométrage journalier</h1>
        <p class="subtitle">Détail des distances parcourues par véhicule</p>
    </div>
    <div class="dash-actions">
        <a href="dashboard.php" class="btn-dash btn-dash-outline">&#8592; Retour au dashboard</a>
    </div>
</div>

<!-- KPI -->
<div class="kpi-grid" style="margin-bottom:16px;">
    <div class="kpi-card">
        <div class="kpi-info">
            <p class="label">Kilométrage total</p>
            <p class="value"><?= number_format($kpiJour['km_total'] ?? 0, 0, ',', ' ') ?> km</p>
            <p class="trend">Toute la flotte — <?= date('d/m/Y', strtotime($date)) ?></p>
        </div>
        <div class="kpi-icon">&#128739;</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-info">
            <p class="label">Véhicules en mouvement</p>
            <p class="value"><?= $kpiJour['nb_vehicules'] ?? 0 ?></p>
            <p class="trend">Ont roulé aujourd'hui</p>
        </div>
        <div class="kpi-icon">&#128666;</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-info">
            <p class="label">Vitesse moyenne</p>
            <p class="value"><?= round($kpiJour['vitesse_moy'] ?? 0) ?> km/h</p>
            <p class="trend">Moyenne flotte</p>
        </div>
        <div class="kpi-icon">&#128225;</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-info">
            <p class="label">Vitesse max enregistrée</p>
            <p class="value"><?= round($kpiJour['vitesse_max'] ?? 0) ?> km/h</p>
            <p class="trend">Pic du jour</p>
        </div>
        <div class="kpi-icon">&#9889;</div>
    </div>
</div>

<!-- Filtres -->
<div class="panel" style="padding:16px 20px;margin-bottom:16px;">
    <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
        <div class="form-group" style="margin:0;flex:1;min-width:140px;">
            <label style="font-size:12px;">Date</label>
            <input type="date" name="date" value="<?= htmlspecialchars($date) ?>" max="<?= date('Y-m-d') ?>">
        </div>
        <div class="form-group" style="margin:0;flex:1;min-width:140px;">
            <label style="font-size:12px;">Type</label>
            <select name="type" id="selectType" onchange="mettreAJourVehicules()">
                <option value="">Tous</option>
                <option value="minier"  <?= $filtreType === 'minier'  ? 'selected' : '' ?>>Minier</option>
                <option value="routier" <?= $filtreType === 'routier' ? 'selected' : '' ?>>Routier</option>
            </select>
        </div>
        <div class="form-group" style="margin:0;flex:1;min-width:160px;">
            <label style="font-size:12px;">Superviseur</label>
            <select name="sup" id="selectSup" onchange="mettreAJourVehicules()">
                <option value="">Tous</option>
                <?php foreach ($superviseurs as $s): ?>
                    <option value="<?= $s['id_user'] ?>" <?= $filtreSup == $s['id_user'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($s['prenom'] . ' ' . $s['nom']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0;flex:1;min-width:160px;">
            <label style="font-size:12px;">Véhicule</label>
            <select name="vehicule" id="selectVehicule">
                <option value="">Tous</option>
                <?php foreach ($vehiculesListe as $v): ?>
                    <option value="<?= $v['id_vehicule'] ?>" <?= $filtreVehicule == $v['id_vehicule'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($v['immatriculation']) ?> (<?= ucfirst($v['type']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn-dash btn-dash-primary" style="height:38px;">Filtrer</button>
        <a href="kilometrage.php" class="btn-dash btn-dash-outline" style="height:38px;line-height:22px;">Réinitialiser</a>
    </form>
</div>

<script>
function mettreAJourVehicules() {
    var idSup = document.getElementById('selectSup').value;
    var sel   = document.getElementById('selectVehicule');
    sel.innerHTML = '<option value="">Tous</option>';
    if (!idSup) return;
    fetch('../api/vehicules_par_sup.php?sup=' + idSup + '&type=' + document.getElementById('selectType').value)
        .then(function(r) { return r.json(); })
        .then(function(liste) {
            liste.forEach(function(v) {
                var o = document.createElement('option');
                o.value = v.id_vehicule;
                o.textContent = v.immatriculation + ' (' + v.type.charAt(0).toUpperCase() + v.type.slice(1) + ')';
                sel.appendChild(o);
            });
        });
}
</script>

<!-- Tableau kilométrage par véhicule -->
<div class="panel fleet-table-wrap">
    <div class="panel-header">
        <h3>&#128203; Détail par véhicule — <?= date('d/m/Y', strtotime($date)) ?></h3>
    </div>
    <table>
        <thead>
            <tr>
                <th>Véhicule</th>
                <th>Type</th>
                <th>Superviseur</th>
                <th>Km du jour</th>
                <th>Vitesse moy.</th>
                <th>Vitesse max</th>
                <th>Premier relevé</th>
                <th>Dernier relevé</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($vehiculesKm)): ?>
                <tr><td colspan="8" style="text-align:center;padding:30px;color:#999;">Aucun kilométrage enregistré pour cette date.</td></tr>
            <?php endif; ?>
            <?php foreach ($vehiculesKm as $v): ?>
                <tr>
                    <td>
                        <a href="fiche_vehicule.php?id=<?= $v['id_vehicule'] ?>" style="color:#0F6E56;font-weight:600;">
                            <?= htmlspecialchars($v['immatriculation']) ?>
                        </a>
                        <br><span style="font-size:11px;color:#999;"><?= htmlspecialchars($v['marque'] . ' ' . $v['modele']) ?></span>
                    </td>
                    <td><?= ucfirst($v['type']) ?></td>
                    <td style="font-size:12px;"><?= $v['sup_nom'] ? htmlspecialchars($v['sup_prenom'] . ' ' . $v['sup_nom']) : '<span style="color:#999;">—</span>' ?></td>
                    <td><strong><?= number_format($v['km_jour'], 1, ',', ' ') ?> km</strong></td>
                    <td><?= round($v['vitesse_moy']) ?> km/h</td>
                    <td style="color:<?= $v['vitesse_max'] > 120 ? '#E24B4A' : '#1A1A1A' ?>;">
                        <?= round($v['vitesse_max']) ?> km/h
                    </td>
                    <td style="font-size:12px;"><?= date('H:i', strtotime($v['premier_releve'])) ?></td>
                    <td style="font-size:12px;"><?= date('H:i', strtotime($v['dernier_releve'])) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Graphique évolution 7 jours -->
<div class="panel" style="margin-top:16px;padding:20px;">
    <div class="panel-header" style="margin-bottom:12px;">
        <h3>&#128202; Évolution kilométrique — 7 derniers jours
            <?php if ($filtreType || $filtreSup): ?>
                <span style="font-size:12px;color:#5C6B68;font-weight:normal;">
                    (<?= $filtreType ? ucfirst($filtreType) : '' ?>
                    <?= $filtreType && $filtreSup ? ' — ' : '' ?>
                    <?php if ($filtreSup):
                        foreach ($superviseurs as $s) {
                            if ($s['id_user'] == $filtreSup) echo htmlspecialchars($s['prenom'] . ' ' . $s['nom']);
                        }
                    endif; ?>)
                </span>
            <?php endif; ?>
        </h3>
    </div>
    <canvas id="kmEvolutionChart" height="80"></canvas>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
var evolutionData = <?= json_encode($evolution) ?>;
var labels = evolutionData.map(function(e) {
    var d = new Date(e.jour + 'T00:00:00');
    return d.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' });
});
var valeurs = evolutionData.map(function(e) { return parseFloat(e.km_total) || 0; });

new Chart(document.getElementById('kmEvolutionChart'), {
    type: 'bar',
    data: {
        labels: labels,
        datasets: [{
            label: 'Kilométrage total (km)',
            data: valeurs,
            backgroundColor: '#1D9E75',
            borderRadius: 4
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, title: { display: true, text: 'km' } } }
    }
});
</script>

<?php require_once '../includes/footer.php'; ?>
