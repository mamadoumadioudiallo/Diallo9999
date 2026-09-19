<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : admin/carburant.php
// Rôle    : Page dédiée au suivi du carburant par véhicule
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
        COUNT(DISTINCT v.id_vehicule) AS nb_vehicules,
        COALESCE(AVG(derniers.carburant), 0) AS carburant_moy,
        COALESCE(MIN(derniers.carburant), 0) AS carburant_min,
        COUNT(CASE WHEN derniers.carburant < 10 THEN 1 END) AS nb_critique
    FROM vehicules v
    JOIN (
        SELECT t1.id_vehicule, t1.carburant
        FROM telemetrie t1
        INNER JOIN (
            SELECT id_vehicule, MAX(horodatage) AS max_horo
            FROM telemetrie WHERE DATE(horodatage) = ?
            GROUP BY id_vehicule
        ) t2 ON t1.id_vehicule = t2.id_vehicule AND t1.horodatage = t2.max_horo
    ) derniers ON v.id_vehicule = derniers.id_vehicule
    WHERE v.statut = 'actif'
");
$stmt->execute([$date]);
$kpiJour = $stmt->fetch();

// ====== DÉTAIL CARBURANT PAR VÉHICULE ======
$conditions = ["DATE(t.horodatage) = ?"];
$params = [$date];

if ($filtreType)     { $conditions[] = "v.type = ?";             $params[] = $filtreType; }
if ($filtreSup)      { $conditions[] = "v.id_superviseur = ?";   $params[] = $filtreSup; }
if ($filtreVehicule) { $conditions[] = "v.id_vehicule = ?";      $params[] = $filtreVehicule; }
$where = 'WHERE ' . implode(' AND ', $conditions);

$stmt = $pdo->prepare("
    SELECT
        v.id_vehicule, v.immatriculation, v.type, v.marque, v.modele,
        v.capacite_carburant,
        u.nom AS sup_nom, u.prenom AS sup_prenom,
        MAX(t.carburant) AS carburant_debut,
        MIN(t.carburant) AS carburant_fin,
        MAX(t.carburant) - MIN(t.carburant) AS conso_estimee,
        MIN(t.horodatage) AS premier_releve,
        MAX(t.horodatage) AS dernier_releve
    FROM vehicules v
    JOIN telemetrie t ON v.id_vehicule = t.id_vehicule
    LEFT JOIN utilisateurs u ON v.id_superviseur = u.id_user
    $where
    GROUP BY v.id_vehicule, v.immatriculation, v.type, v.marque, v.modele,
             v.capacite_carburant, u.nom, u.prenom
    ORDER BY conso_estimee DESC
");
$stmt->execute($params);
$vehiculesCarburant = $stmt->fetchAll();

// ====== ÉVOLUTION CARBURANT MOYEN 7 JOURS (filtrée) ======
$evolConditions = ["t.horodatage >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)"];
$evolParams = [];

if ($filtreType)     { $evolConditions[] = "v.type = ?";           $evolParams[] = $filtreType; }
if ($filtreSup)      { $evolConditions[] = "v.id_superviseur = ?"; $evolParams[] = $filtreSup; }
if ($filtreVehicule) { $evolConditions[] = "v.id_vehicule = ?";    $evolParams[] = $filtreVehicule; }
$evolWhere = implode(' AND ', $evolConditions);

$stmt = $pdo->prepare("
    SELECT DATE(t.horodatage) AS jour, AVG(t.carburant) AS carburant_moy
    FROM telemetrie t
    JOIN vehicules v ON t.id_vehicule = v.id_vehicule
    WHERE $evolWhere
    GROUP BY DATE(t.horodatage)
    ORDER BY jour ASC
");
$stmt->execute($evolParams);
$evolution = $stmt->fetchAll();

// ====== ALERTES CARBURANT BAS AUJOURD'HUI ======
$stmt = $pdo->prepare("
    SELECT a.*, v.immatriculation
    FROM alertes a
    JOIN vehicules v ON a.id_vehicule = v.id_vehicule
    WHERE a.type_alerte = 'carburant_bas'
    AND DATE(a.horodatage) = ?
    ORDER BY a.horodatage DESC
    LIMIT 20
");
$stmt->execute([$date]);
$alertesCarburant = $stmt->fetchAll();

// ====== LISTE SUPERVISEURS ======
$superviseurs = $pdo->query("
    SELECT id_user, nom, prenom FROM utilisateurs
    WHERE role = 'superviseur' AND statut = 'actif'
")->fetchAll();

// Liste véhicules filtrée par superviseur si sélectionné
if ($filtreSup) {
    $stmtV = $pdo->prepare("SELECT id_vehicule, immatriculation, type FROM vehicules WHERE statut = 'actif' AND id_superviseur = ? ORDER BY immatriculation");
    $stmtV->execute([$filtreSup]);
} else {
    $stmtV = $pdo->query("SELECT id_vehicule, immatriculation, type FROM vehicules WHERE statut = 'actif' ORDER BY immatriculation");
}
$vehiculesListe = $stmtV->fetchAll();

$pageTitle = 'Suivi carburant';
require_once '../includes/header.php';
?>

<div class="dash-header-row">
    <div>
        <h1>&#128167; Suivi du carburant</h1>
        <p class="subtitle">Niveaux et consommation par véhicule</p>
    </div>
    <div class="dash-actions">
        <a href="dashboard.php" class="btn-dash btn-dash-outline">&#8592; Retour au dashboard</a>
    </div>
</div>

<!-- KPI -->
<div class="kpi-grid" style="margin-bottom:16px;">
    <div class="kpi-card">
        <div class="kpi-info">
            <p class="label">Niveau moyen flotte</p>
            <p class="value"><?= round($kpiJour['carburant_moy'] ?? 0) ?> %</p>
            <p class="trend">Tous véhicules actifs</p>
        </div>
        <div class="kpi-icon">&#128167;</div>
    </div>
    <div class="kpi-card <?= ($kpiJour['carburant_min'] ?? 100) < 10 ? 'danger' : 'warning' ?>">
        <div class="kpi-info">
            <p class="label">Niveau minimum</p>
            <p class="value"><?= round($kpiJour['carburant_min'] ?? 0) ?> %</p>
            <p class="trend">Véhicule le plus bas</p>
        </div>
        <div class="kpi-icon">&#9888;</div>
    </div>
    <div class="kpi-card <?= ($kpiJour['nb_critique'] ?? 0) > 0 ? 'danger' : '' ?>">
        <div class="kpi-info">
            <p class="label">Véhicules critiques</p>
            <p class="value"><?= $kpiJour['nb_critique'] ?? 0 ?></p>
            <p class="trend">Niveau &lt; 10%</p>
        </div>
        <div class="kpi-icon">&#128308;</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-info">
            <p class="label">Alertes carburant</p>
            <p class="value"><?= count($alertesCarburant) ?></p>
            <p class="trend">Aujourd'hui</p>
        </div>
        <div class="kpi-icon">&#128276;</div>
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
        <a href="carburant.php" class="btn-dash btn-dash-outline" style="height:38px;line-height:22px;">Réinitialiser</a>
    </form>
</div>

<script>
// Recharge la liste des véhicules selon le superviseur sélectionné
function mettreAJourVehicules() {
    var idSup = document.getElementById('selectSup').value;
    var selectVehicule = document.getElementById('selectVehicule');

    // Réinitialiser la liste
    selectVehicule.innerHTML = '<option value="">Tous</option>';

    if (!idSup) return; // Pas de sup sélectionné → on garde "Tous"

    // Appel API pour récupérer les véhicules de ce superviseur
    fetch('../api/vehicules_par_sup.php?sup=' + idSup + '&type=' + document.getElementById('selectType').value)
        .then(function(r) { return r.json(); })
        .then(function(vehicules) {
            vehicules.forEach(function(v) {
                var opt = document.createElement('option');
                opt.value = v.id_vehicule;
                opt.textContent = v.immatriculation + ' (' + v.type.charAt(0).toUpperCase() + v.type.slice(1) + ')';
                selectVehicule.appendChild(opt);
            });
        })
        .catch(function() {});
}
</script>
    </form>
</div>

<!-- Tableau carburant par véhicule -->
<div class="panel fleet-table-wrap" style="margin-bottom:16px;">
    <div class="panel-header">
        <h3>&#128203; Niveaux par véhicule — <?= date('d/m/Y', strtotime($date)) ?></h3>
    </div>
    <table>
        <thead>
            <tr>
                <th>Véhicule</th>
                <th>Type</th>
                <th>Superviseur</th>
                <th>Niveau actuel</th>
                <th>Jauge</th>
                <th>Conso. estimée</th>
                <th>Capacité réservoir</th>
                <th>Dernier relevé</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($vehiculesCarburant)): ?>
                <tr><td colspan="8" style="text-align:center;padding:30px;color:#999;">Aucune donnée pour cette date.</td></tr>
            <?php endif; ?>
            <?php foreach ($vehiculesCarburant as $v): ?>
                <?php
                    $niveauActuel = (float) $v['carburant_fin'];
                    $critique = $niveauActuel < 10;
                    $warning  = $niveauActuel < 25 && !$critique;
                    $couleurBarre = $critique ? '#E24B4A' : ($warning ? '#BA7517' : '#1D9E75');
                ?>
                <tr style="background:<?= $critique ? '#FFF8F8' : '' ?>;">
                    <td>
                        <a href="fiche_vehicule.php?id=<?= $v['id_vehicule'] ?>" style="color:#0F6E56;font-weight:600;">
                            <?= htmlspecialchars($v['immatriculation']) ?>
                        </a>
                        <br><span style="font-size:11px;color:#999;"><?= htmlspecialchars($v['marque'] . ' ' . $v['modele']) ?></span>
                    </td>
                    <td><?= ucfirst($v['type']) ?></td>
                    <td style="font-size:12px;">
                        <?= $v['sup_nom'] ? htmlspecialchars($v['sup_prenom'] . ' ' . $v['sup_nom']) : '<span style="color:#999;">—</span>' ?>
                    </td>
                    <td>
                        <strong style="color:<?= $couleurBarre ?>;">
                            <?= round($niveauActuel) ?> %
                        </strong>
                        <?php if ($critique): ?>
                            <span style="font-size:11px;color:#E24B4A;"> &#9888; CRITIQUE</span>
                        <?php endif; ?>
                    </td>
                    <td style="min-width:100px;">
                        <div style="background:#E9ECEC;border-radius:4px;height:8px;width:100%;">
                            <div style="background:<?= $couleurBarre ?>;width:<?= min(100, round($niveauActuel)) ?>%;height:8px;border-radius:4px;"></div>
                        </div>
                    </td>
                    <td style="color:<?= $v['conso_estimee'] > 30 ? '#E24B4A' : '#1A1A1A' ?>;">
                        <?= round($v['conso_estimee'], 1) ?> %
                    </td>
                    <td><?= $v['capacite_carburant'] ?> L</td>
                    <td style="font-size:12px;"><?= date('H:i', strtotime($v['dernier_releve'])) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Alertes carburant bas du jour -->
<?php if (!empty($alertesCarburant)): ?>
<div class="panel fleet-table-wrap" style="margin-bottom:16px;">
    <div class="panel-header">
        <h3>&#128276; Alertes carburant bas — <?= date('d/m/Y', strtotime($date)) ?></h3>
    </div>
    <table>
        <thead>
            <tr><th>Véhicule</th><th>Niveau déclenchant</th><th>Heure</th><th>Statut</th></tr>
        </thead>
        <tbody>
            <?php foreach ($alertesCarburant as $a): ?>
                <?php $sb = ['non_traitee'=>'badge-danger','en_cours'=>'badge-warning','resolue'=>'badge-success']; ?>
                <tr>
                    <td><?= htmlspecialchars($a['immatriculation']) ?></td>
                    <td><?= round($a['valeur_declenchante'], 1) ?> %</td>
                    <td><?= date('H:i', strtotime($a['horodatage'])) ?></td>
                    <td><span class="badge <?= $sb[$a['statut']] ?>"><?= ucfirst(str_replace('_', ' ', $a['statut'])) ?></span></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- Graphique évolution 7 jours -->
<div class="panel" style="padding:20px;">
    <div class="panel-header" style="margin-bottom:12px;">
        <h3>&#128202; Niveau moyen carburant — 7 derniers jours
            <?php if ($filtreType || $filtreSup || $filtreVehicule): ?>
                <span style="font-size:12px;color:#5C6B68;font-weight:normal;">
                    (<?php
                        $parts = [];
                        if ($filtreType) $parts[] = ucfirst($filtreType);
                        if ($filtreSup) {
                            foreach ($superviseurs as $s) {
                                if ($s['id_user'] == $filtreSup) $parts[] = htmlspecialchars($s['prenom'] . ' ' . $s['nom']);
                            }
                        }
                        if ($filtreVehicule) {
                            foreach ($vehiculesListe as $v) {
                                if ($v['id_vehicule'] == $filtreVehicule) $parts[] = htmlspecialchars($v['immatriculation']);
                            }
                        }
                        echo implode(' — ', $parts);
                    ?>)
                </span>
            <?php endif; ?>
        </h3>
    </div>
    <canvas id="carburantChart" height="80"></canvas>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
var evolutionData = <?= json_encode($evolution) ?>;
var labels = evolutionData.map(function(e) {
    var d = new Date(e.jour + 'T00:00:00');
    return d.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' });
});
var valeurs = evolutionData.map(function(e) { return parseFloat(e.carburant_moy) || 0; });

new Chart(document.getElementById('carburantChart'), {
    type: 'line',
    data: {
        labels: labels,
        datasets: [{
            label: 'Niveau moyen carburant (%)',
            data: valeurs,
            borderColor: '#BA7517',
            backgroundColor: 'rgba(186,117,23,0.1)',
            tension: 0.3,
            fill: true,
            pointRadius: 4
        }, {
            label: 'Seuil critique (10%)',
            data: labels.map(function() { return 10; }),
            borderColor: '#E24B4A',
            borderDash: [6, 3],
            pointRadius: 0,
            tension: 0
        }]
    },
    options: {
        responsive: true,
        interaction: { mode: 'index', intersect: false },
        scales: {
            y: { beginAtZero: true, max: 100, title: { display: true, text: '%' } }
        }
    }
});
</script>

<?php require_once '../includes/footer.php'; ?>
