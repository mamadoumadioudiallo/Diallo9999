<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : admin/tpms.php
// Rôle    : Page dédiée au suivi TPMS (pression & température pneus)
// ============================================================

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAdmin();

$filtreType     = $_GET['type']     ?? '';
$filtreSup      = $_GET['sup']      ?? '';
$filtreVehicule = $_GET['vehicule'] ?? '';

// ====== DERNIÈRE TÉLÉMÉTRIE AVEC TPMS PAR VÉHICULE ======
$conditions = ["v.statut = 'actif'", "t.tpms_pression_json IS NOT NULL"];
$params = [];
if ($filtreType)     { $conditions[] = "v.type = ?";           $params[] = $filtreType; }
if ($filtreSup)      { $conditions[] = "v.id_superviseur = ?"; $params[] = $filtreSup; }
if ($filtreVehicule) { $conditions[] = "v.id_vehicule = ?";    $params[] = $filtreVehicule; }
$where = 'WHERE ' . implode(' AND ', $conditions);

$stmt = $pdo->prepare("
    SELECT v.id_vehicule, v.immatriculation, v.type, v.marque, v.modele, v.nb_roues,
           u.nom AS sup_nom, u.prenom AS sup_prenom,
           t.tpms_pression_json, t.tpms_alerte, t.horodatage
    FROM vehicules v
    INNER JOIN (
        SELECT t1.* FROM telemetrie t1
        INNER JOIN (
            SELECT id_vehicule, MAX(horodatage) AS max_horo
            FROM telemetrie GROUP BY id_vehicule
        ) t2 ON t1.id_vehicule = t2.id_vehicule AND t1.horodatage = t2.max_horo
    ) t ON v.id_vehicule = t.id_vehicule
    LEFT JOIN utilisateurs u ON v.id_superviseur = u.id_user
    $where
    ORDER BY t.tpms_alerte DESC, v.immatriculation ASC
");
$stmt->execute($params);
$vehiculesTpms = $stmt->fetchAll();

// Seuils depuis config
$configStmt = $pdo->query("SELECT parametre, valeur FROM configurations WHERE parametre IN ('seuil_pression_tpms_min','seuil_pression_tpms_max','seuil_temperature_pneu')");
$config = [];
foreach ($configStmt->fetchAll() as $row) {
    $config[$row['parametre']] = (float) $row['valeur'];
}
$seuilPMin = $config['seuil_pression_tpms_min'] ?? 6.5;
$seuilPMax = $config['seuil_pression_tpms_max'] ?? 9.0;
$seuilTMax = $config['seuil_temperature_pneu']  ?? 70;

// KPI globaux
$nbAlertePression   = 0;
$nbAlerteTemp       = 0;
$nbVehiculesNormaux = 0;
foreach ($vehiculesTpms as $v) {
    if (!empty($v['tpms_alerte'])) {
        $roues = json_decode($v['tpms_pression_json'], true) ?? [];
        foreach ($roues as $r) {
            if ($r['pression'] < $seuilPMin || $r['pression'] > $seuilPMax) { $nbAlertePression++; break; }
        }
        foreach ($roues as $r) {
            if ($r['temperature'] > $seuilTMax) { $nbAlerteTemp++; break; }
        }
    } else {
        $nbVehiculesNormaux++;
    }
}

// Alertes TPMS en base (filtrées)
$alerteCondParts = [
    "a.type_alerte IN ('tpms_pression', 'tpms_temperature')",
    "a.statut != 'resolue'"
];
$alerteParamsTpms = [];
if ($filtreType)     { $alerteCondParts[] = "v.type = ?";           $alerteParamsTpms[] = $filtreType; }
if ($filtreSup)      { $alerteCondParts[] = "v.id_superviseur = ?"; $alerteParamsTpms[] = $filtreSup; }
if ($filtreVehicule) { $alerteCondParts[] = "a.id_vehicule = ?";    $alerteParamsTpms[] = $filtreVehicule; }

$stmtAlertes = $pdo->prepare("
    SELECT a.*, v.immatriculation, v.id_vehicule FROM alertes a
    JOIN vehicules v ON a.id_vehicule = v.id_vehicule
    WHERE " . implode(' AND ', $alerteCondParts) . "
    ORDER BY a.horodatage DESC LIMIT 50
");
$stmtAlertes->execute($alerteParamsTpms);
$alertesTpms = $stmtAlertes->fetchAll();

$superviseurs = $pdo->query("SELECT id_user, nom, prenom FROM utilisateurs WHERE role = 'superviseur' AND statut = 'actif'")->fetchAll();

if ($filtreSup) {
    $stmtV = $pdo->prepare("SELECT id_vehicule, immatriculation, type FROM vehicules WHERE statut = 'actif' AND id_superviseur = ? ORDER BY immatriculation");
    $stmtV->execute([$filtreSup]);
} else {
    $stmtV = $pdo->query("SELECT id_vehicule, immatriculation, type FROM vehicules WHERE statut = 'actif' ORDER BY immatriculation");
}
$vehiculesListe = $stmtV->fetchAll();

$pageTitle = 'Suivi TPMS — Pneus';
require_once '../includes/header.php';
?>

<div class="dash-header-row">
    <div>
        <h1>&#127919; Suivi TPMS — Pression des pneus</h1>
        <p class="subtitle">État en temps réel des pneus de la flotte</p>
    </div>
    <div class="dash-actions">
        <a href="dashboard.php" class="btn-dash btn-dash-outline">&#8592; Retour au dashboard</a>
    </div>
</div>

<!-- KPI -->
<div class="kpi-grid" style="margin-bottom:16px;">
    <div class="kpi-card <?= ($nbAlertePression + $nbAlerteTemp) > 0 ? 'danger' : '' ?>">
        <div class="kpi-info">
            <p class="label">Véhicules en alerte</p>
            <p class="value"><?= $nbAlertePression + $nbAlerteTemp ?></p>
            <p class="trend"><?= ($nbAlertePression + $nbAlerteTemp) > 0 ? 'Vérification requise' : 'Tout est normal' ?></p>
        </div>
        <div class="kpi-icon">&#9888;</div>
    </div>
    <div class="kpi-card <?= $nbAlertePression > 0 ? 'danger' : '' ?>">
        <div class="kpi-info">
            <p class="label">Alertes pression</p>
            <p class="value"><?= $nbAlertePression ?></p>
            <p class="trend">Seuil : <?= $seuilPMin ?> — <?= $seuilPMax ?> bar</p>
        </div>
        <div class="kpi-icon">&#128246;</div>
    </div>
    <div class="kpi-card <?= $nbAlerteTemp > 0 ? 'danger' : '' ?>">
        <div class="kpi-info">
            <p class="label">Alertes température</p>
            <p class="value"><?= $nbAlerteTemp ?></p>
            <p class="trend">Seuil : <?= $seuilTMax ?> °C</p>
        </div>
        <div class="kpi-icon">&#127777;</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-info">
            <p class="label">Véhicules normaux</p>
            <p class="value" style="color:#1D9E75;"><?= $nbVehiculesNormaux ?></p>
            <p class="trend">Pression dans les normes</p>
        </div>
        <div class="kpi-icon">&#9989;</div>
    </div>
</div>

<!-- Filtres -->
<div class="panel" style="padding:16px 20px;margin-bottom:16px;">
    <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
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
        <a href="tpms.php" class="btn-dash btn-dash-outline" style="height:38px;line-height:22px;">Réinitialiser</a>
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

<!-- Tableau TPMS par véhicule -->
<div class="panel fleet-table-wrap" style="margin-bottom:16px;">
    <div class="panel-header">
        <h3>&#127919; État des pneus par véhicule (temps réel)</h3>
        <span id="tpmsBadgeConnexion" style="font-size:12px;color:#fff;background:#999;padding:3px 10px;border-radius:12px;">● Connexion...</span>
    </div>
    <table>
        <thead>
            <tr>
                <th>Véhicule</th>
                <th>Type</th>
                <th>Superviseur</th>
                <th>Statut global</th>
                <th>Détail par roue</th>
                <th>Dernier relevé</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($vehiculesTpms)): ?>
                <tr><td colspan="6" style="text-align:center;padding:30px;color:#999;">Aucune donnée TPMS disponible.</td></tr>
            <?php endif; ?>
            <?php foreach ($vehiculesTpms as $v): ?>
                <?php
                    $roues = !empty($v['tpms_pression_json']) ? json_decode($v['tpms_pression_json'], true) : [];
                    $alerteGlobale = !empty($v['tpms_alerte']);
                ?>
                <tr data-tpms-id="<?= $v['id_vehicule'] ?>" style="background:<?= $alerteGlobale ? '#FFF8F8' : '' ?>;">
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
                        <span class="badge <?= $alerteGlobale ? 'badge-danger' : 'badge-success' ?> tpms-statut-badge">
                            <?= $alerteGlobale ? '&#9888; Anomalie' : '&#9989; Normal' ?>
                        </span>
                    </td>
                    <td class="tpms-roues">
                        <?php if (!empty($roues)): ?>
                            <div style="display:flex;flex-wrap:wrap;gap:6px;">
                                <?php foreach ($roues as $roue): ?>
                                    <?php
                                        $p = (float) $roue['pression'];
                                        $t = (float) ($roue['temperature'] ?? 0);
                                        $ok = $p >= $seuilPMin && $p <= $seuilPMax && $t <= $seuilTMax;
                                        $c  = $ok ? '#1D9E75' : '#E24B4A';
                                    ?>
                                    <div style="background:#F5F7F6;border:1px solid <?= $c ?>;border-radius:6px;padding:4px 8px;text-align:center;min-width:52px;">
                                        <p style="font-size:10px;color:#999;margin-bottom:1px;">R<?= $roue['roue'] ?></p>
                                        <p style="font-size:12px;font-weight:700;color:<?= $c ?>;margin-bottom:1px;"><?= $p ?> b</p>
                                        <p style="font-size:10px;color:#5C6B68;"><?= $t ?>°C</p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <span style="color:#999;font-size:12px;">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px;"><?= date('H:i', strtotime($v['horodatage'])) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Alertes TPMS non résolues -->
<?php if (!empty($alertesTpms)): ?>
<div class="panel fleet-table-wrap">
    <div class="panel-header">
        <h3>&#128276; Alertes TPMS non résolues
            <?php if ($filtreType || $filtreSup || $filtreVehicule): ?>
                <span style="font-size:12px;color:#5C6B68;font-weight:normal;">(<?php
                    $parts = [];
                    if ($filtreType) $parts[] = ucfirst($filtreType);
                    if ($filtreSup) foreach ($superviseurs as $s) { if ($s['id_user'] == $filtreSup) $parts[] = htmlspecialchars($s['prenom'] . ' ' . $s['nom']); }
                    if ($filtreVehicule) foreach ($vehiculesListe as $v) { if ($v['id_vehicule'] == $filtreVehicule) $parts[] = htmlspecialchars($v['immatriculation']); }
                    echo implode(' — ', $parts);
                ?>)</span>
            <?php endif; ?>
        </h3>
        <span class="badge badge-danger"><?= count($alertesTpms) ?></span>
    </div>
    <table>
        <thead>
            <tr>
                <th>Véhicule</th>
                <th>Type alerte</th>
                <th>Valeur</th>
                <th>Date / Heure</th>
                <th>Statut</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($alertesTpms as $a): ?>
                <?php $sb = ['non_traitee'=>'badge-danger','en_cours'=>'badge-warning']; ?>
                <tr>
                    <td>
                        <a href="fiche_vehicule.php?id=<?= $a['id_vehicule'] ?>" style="color:#0F6E56;font-weight:600;">
                            <?= htmlspecialchars($a['immatriculation']) ?>
                        </a>
                    </td>
                    <td><?= $a['type_alerte'] === 'tpms_pression' ? 'Pression pneu' : 'Température pneu' ?></td>
                    <td><?= formaterValeurAlerte($a['type_alerte'], $a['valeur_declenchante']) ?></td>
                    <td style="font-size:12px;"><?= date('d/m/Y H:i', strtotime($a['horodatage'])) ?></td>
                    <td><span class="badge <?= $sb[$a['statut']] ?>"><?= $a['statut'] === 'non_traitee' ? 'Non traitée' : 'En cours' ?></span></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- ====== GRAPHIQUES ====== -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px;margin-bottom:16px;">

    <!-- Graphique 1 : Répartition alertes pression vs température (donut) -->
    <div class="panel" style="padding:20px;">
        <div class="panel-header" style="margin-bottom:12px;">
            <h3>&#128202; Répartition des alertes TPMS
                <?php if ($filtreType || $filtreSup || $filtreVehicule): ?>
                    <span style="font-size:12px;color:#5C6B68;font-weight:normal;">(<?php
                        $parts = [];
                        if ($filtreType) $parts[] = ucfirst($filtreType);
                        if ($filtreSup) foreach ($superviseurs as $s) { if ($s['id_user'] == $filtreSup) $parts[] = htmlspecialchars($s['prenom'] . ' ' . $s['nom']); }
                        if ($filtreVehicule) foreach ($vehiculesListe as $v) { if ($v['id_vehicule'] == $filtreVehicule) $parts[] = htmlspecialchars($v['immatriculation']); }
                        echo implode(' — ', $parts);
                    ?>)</span>
                <?php endif; ?>
            </h3>
        </div>
        <div style="max-width:260px;margin:0 auto;">
            <canvas id="tpmsDonutChart"></canvas>
        </div>
    </div>

    <!-- Graphique 2 : Évolution nb véhicules en alerte TPMS (7 jours) -->
    <div class="panel" style="padding:20px;">
        <div class="panel-header" style="margin-bottom:12px;">
            <h3>&#128202; Véhicules en alerte TPMS — 7 jours
                <?php if ($filtreType || $filtreSup || $filtreVehicule): ?>
                    <span style="font-size:12px;color:#5C6B68;font-weight:normal;">(<?php
                        $parts = [];
                        if ($filtreType) $parts[] = ucfirst($filtreType);
                        if ($filtreSup) foreach ($superviseurs as $s) { if ($s['id_user'] == $filtreSup) $parts[] = htmlspecialchars($s['prenom'] . ' ' . $s['nom']); }
                        if ($filtreVehicule) foreach ($vehiculesListe as $v) { if ($v['id_vehicule'] == $filtreVehicule) $parts[] = htmlspecialchars($v['immatriculation']); }
                        echo implode(' — ', $parts);
                    ?>)</span>
                <?php endif; ?>
            </h3>
        </div>
        <div style="max-height:260px;">
            <canvas id="tpmsEvolutionChart"></canvas>
        </div>
    </div>

</div>

<!-- Graphique 3 : Évolution pression moyenne par roue (7 jours) -->
<div class="panel" style="padding:20px;margin-bottom:16px;">
    <div class="panel-header" style="margin-bottom:12px;">
        <h3>&#128202; Évolution pression moyenne — 7 derniers jours
            <?php if ($filtreType || $filtreSup || $filtreVehicule): ?>
                <span style="font-size:12px;color:#5C6B68;font-weight:normal;">(<?php
                    $parts = [];
                    if ($filtreType) $parts[] = ucfirst($filtreType);
                    if ($filtreSup) foreach ($superviseurs as $s) { if ($s['id_user'] == $filtreSup) $parts[] = htmlspecialchars($s['prenom'] . ' ' . $s['nom']); }
                    if ($filtreVehicule) foreach ($vehiculesListe as $v) { if ($v['id_vehicule'] == $filtreVehicule) $parts[] = htmlspecialchars($v['immatriculation']); }
                    echo implode(' — ', $parts);
                ?>)</span>
            <?php endif; ?>
        </h3>
        <span style="font-size:11px;color:#5C6B68;">Rouge = min (<?= $seuilPMin ?> bar) / Orange = max (<?= $seuilPMax ?> bar)</span>
    </div>
    <canvas id="tpmsPressionChart" height="100"></canvas>
</div>

<?php
// ====== DONNÉES GRAPHIQUES (filtrées selon type/sup/vehicule) ======

// Conditions communes pour les alertes
$alerteConditions = [
    "a.type_alerte IN ('tpms_pression', 'tpms_temperature')",
    "a.horodatage >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
];
$alerteParams = [];
if ($filtreType)     { $alerteConditions[] = "v.type = ?";           $alerteParams[] = $filtreType; }
if ($filtreSup)      { $alerteConditions[] = "v.id_superviseur = ?"; $alerteParams[] = $filtreSup; }
if ($filtreVehicule) { $alerteConditions[] = "a.id_vehicule = ?";    $alerteParams[] = $filtreVehicule; }
$alerteJoin  = ($filtreType || $filtreSup) ? "JOIN vehicules v ON a.id_vehicule = v.id_vehicule" : "";
$alerteWhere = 'WHERE ' . implode(' AND ', $alerteConditions);

// Conditions communes pour télémétrie
$teleConditions = [
    "t.tpms_pression_json IS NOT NULL",
    "t.horodatage >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)"
];
$teleParams = [];
if ($filtreType)     { $teleConditions[] = "v.type = ?";           $teleParams[] = $filtreType; }
if ($filtreSup)      { $teleConditions[] = "v.id_superviseur = ?"; $teleParams[] = $filtreSup; }
if ($filtreVehicule) { $teleConditions[] = "t.id_vehicule = ?";    $teleParams[] = $filtreVehicule; }
$teleJoin  = ($filtreType || $filtreSup || $filtreVehicule) ? "JOIN vehicules v ON t.id_vehicule = v.id_vehicule" : "";
$teleWhere = 'WHERE ' . implode(' AND ', $teleConditions);

// Donut : nb alertes pression vs température (30 derniers jours, filtré)
$stmtDonut = $pdo->prepare("
    SELECT a.type_alerte, COUNT(*) AS nb
    FROM alertes a $alerteJoin
    $alerteWhere
    GROUP BY a.type_alerte
");
$stmtDonut->execute($alerteParams);
$donutData = [];
foreach ($stmtDonut->fetchAll() as $row) {
    $donutData[$row['type_alerte']] = (int) $row['nb'];
}

// Évolution nb alertes TPMS par jour (7 jours, filtré)
$evolConditions = array_filter($alerteConditions, fn($c) => strpos($c, 'INTERVAL 30') === false);
$evolConditions[] = "a.horodatage >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)";
$evolWhere = 'WHERE ' . implode(' AND ', $evolConditions);

$stmtEvol = $pdo->prepare("
    SELECT DATE(a.horodatage) AS jour, COUNT(DISTINCT a.id_vehicule) AS nb
    FROM alertes a $alerteJoin
    $evolWhere
    GROUP BY DATE(a.horodatage)
    ORDER BY jour ASC
");
$stmtEvol->execute($alerteParams);
$evolData = $stmtEvol->fetchAll();

// Évolution par véhicule (pour filtre graphique 2)
$stmtEvolVehicule = $pdo->prepare("
    SELECT DATE(a.horodatage) AS jour, a.id_vehicule, COUNT(*) AS nb
    FROM alertes a $alerteJoin
    $evolWhere
    GROUP BY DATE(a.horodatage), a.id_vehicule
    ORDER BY jour ASC
");
$stmtEvolVehicule->execute($alerteParams);
$evolParVehicule = [];
foreach ($stmtEvolVehicule->fetchAll() as $row) {
    $evolParVehicule[$row['id_vehicule']][$row['jour']] = (int) $row['nb'];
}

// Évolution pression moyenne (7 jours, filtré)
$stmtPression = $pdo->prepare("
    SELECT DATE(t.horodatage) AS jour,
           AVG(JSON_EXTRACT(t.tpms_pression_json, '\$[*].pression')) AS pression_moy
    FROM telemetrie t $teleJoin
    $teleWhere
    GROUP BY DATE(t.horodatage)
    ORDER BY jour ASC
");
$stmtPression->execute($teleParams);
$pressionData = $stmtPression->fetchAll();

// Évolution pression par véhicule (pour filtre graphique 3)
$stmtPressionVehicule = $pdo->prepare("
    SELECT DATE(t.horodatage) AS jour, t.id_vehicule,
           AVG(JSON_EXTRACT(t.tpms_pression_json, '\$[*].pression')) AS pression_moy
    FROM telemetrie t $teleJoin
    $teleWhere
    GROUP BY DATE(t.horodatage), t.id_vehicule
    ORDER BY jour ASC
");
$stmtPressionVehicule->execute($teleParams);
$pressionParVehicule = [];
foreach ($stmtPressionVehicule->fetchAll() as $row) {
    $pressionParVehicule[$row['id_vehicule']][$row['jour']] = round((float) $row['pression_moy'], 2);
}

// Labels des 7 derniers jours
$joursLabels = [];
for ($i = 6; $i >= 0; $i--) {
    $joursLabels[] = date('Y-m-d', strtotime("-$i days"));
}
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
var seuilPMin = <?= $seuilPMin ?>;
var seuilPMax = <?= $seuilPMax ?>;

// Donut
var donutData = <?= json_encode($donutData) ?>;
var nbPression    = donutData['tpms_pression']    || 0;
var nbTemperature = donutData['tpms_temperature'] || 0;

if (nbPression + nbTemperature === 0) {
    document.getElementById('tpmsDonutChart').parentElement.innerHTML +=
        '<p style="text-align:center;color:#5C6B68;padding:20px 0;">Aucune alerte TPMS sur 30 jours.</p>';
} else {
    new Chart(document.getElementById('tpmsDonutChart'), {
        type: 'doughnut',
        data: {
            labels: ['Pression pneu', 'Température pneu'],
            datasets: [{
                data: [nbPression, nbTemperature],
                backgroundColor: ['#1D9E75', '#E08A1E'],
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            cutout: '60%',
            plugins: {
                legend: { position: 'bottom', align: 'start', labels: { boxWidth: 12, font: { size: 11 } } }
            }
        }
    });
}

// Évolution véhicules en alerte (statique - données historiques)
var evolData = <?= json_encode($evolData) ?>;
var evolLabels = evolData.map(function(e) {
    var d = new Date(e.jour + 'T00:00:00');
    return d.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' });
});

// Données par véhicule pour filtrage
var joursLabels = <?= json_encode($joursLabels) ?>;
var evolParVehicule = <?= json_encode($evolParVehicule) ?>;
var pressionParVehicule = <?= json_encode($pressionParVehicule) ?>;

// Labels formatés pour les 7 jours
var labelsFormates = joursLabels.map(function(j) {
    var d = new Date(j + 'T00:00:00');
    return d.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' });
});

var evolChart = new Chart(document.getElementById('tpmsEvolutionChart'), {
    type: 'bar',
    data: {
        labels: evolLabels,
        datasets: [{
            label: 'Alertes TPMS',
            data: evolData.map(function(e) { return parseInt(e.nb) || 0; }),
            backgroundColor: '#E24B4A',
            borderRadius: 4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
    }
});

// ====== COURBE PRESSION — temps réel via polling ======
var pressionData = <?= json_encode($pressionData) ?>;
var pressionLabels = pressionData.map(function(e) {
    var d = new Date(e.jour + 'T00:00:00');
    return d.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' });
});
var pressionValeurs = pressionData.map(function(e) { return parseFloat(e.pression_moy) || 0; });

// On ajoute un point "maintenant" qui sera mis à jour en temps réel
pressionLabels.push('Maintenant');
pressionValeurs.push(0);

var courbeChart = new Chart(document.getElementById('tpmsPressionChart'), {
    type: 'line',
    data: {
        labels: pressionLabels,
        datasets: [
            {
                label: 'Pression moyenne (bar)',
                data: pressionValeurs,
                borderColor: '#0F6E56',
                backgroundColor: 'rgba(15,110,86,0.1)',
                tension: 0.3,
                fill: true,
                pointRadius: 4,
                pointBackgroundColor: '#0F6E56'
            },
            {
                label: 'Seuil min (' + seuilPMin + ' bar)',
                data: pressionLabels.map(function() { return seuilPMin; }),
                borderColor: '#E24B4A',
                borderDash: [6, 3],
                pointRadius: 0,
                tension: 0
            },
            {
                label: 'Seuil max (' + seuilPMax + ' bar)',
                data: pressionLabels.map(function() { return seuilPMax; }),
                borderColor: '#BA7517',
                borderDash: [6, 3],
                pointRadius: 0,
                tension: 0
            }
        ]
    },
    options: {
        responsive: true,
        interaction: { mode: 'index', intersect: false },
        scales: { y: { beginAtZero: false, title: { display: true, text: 'bar' } } },
        plugins: { legend: { position: 'bottom', align: 'start', labels: { boxWidth: 12, font: { size: 11 } } } }
    }
});

// ====== POLLING TOUTES LES 5 SECONDES ======
var badgeConnexion = document.getElementById('tpmsBadgeConnexion');

function mettreAJourTpms() {
    fetch('../api/tpms.php')
        .then(function(r) { return r.json(); })
        .then(function(data) {

            // --- Mise à jour du badge connexion ---
            if (badgeConnexion) {
                badgeConnexion.style.background = '#1D9E75';
                badgeConnexion.textContent = '● Temps réel — ' + data.timestamp;
            }

            // --- Mise à jour du tableau des pneus ---
            data.vehicules.forEach(function(v) {
                var ligne = document.querySelector('tr[data-tpms-id="' + v.id_vehicule + '"]');
                if (!ligne) return;

                // Statut global
                var badgeStatut = ligne.querySelector('.tpms-statut-badge');
                if (badgeStatut) {
                    badgeStatut.className = 'badge ' + (v.tpms_alerte ? 'badge-danger' : 'badge-success') + ' tpms-statut-badge';
                    badgeStatut.innerHTML = v.tpms_alerte ? '&#9888; Anomalie' : '&#9989; Normal';
                }
                ligne.style.background = v.tpms_alerte ? '#FFF8F8' : '';

                // Détail roues
                var cellRoues = ligne.querySelector('.tpms-roues');
                if (cellRoues && v.roues.length > 0) {
                    cellRoues.innerHTML = v.roues.map(function(r) {
                        var c = r.ok ? '#1D9E75' : '#E24B4A';
                        return '<div style="background:#F5F7F6;border:1px solid ' + c + ';border-radius:6px;padding:4px 8px;text-align:center;min-width:52px;display:inline-block;margin:2px;">' +
                            '<p style="font-size:10px;color:#999;margin-bottom:1px;">R' + r.roue + '</p>' +
                            '<p style="font-size:12px;font-weight:700;color:' + c + ';margin-bottom:1px;">' + r.pression + ' b</p>' +
                            '<p style="font-size:10px;color:#5C6B68;">' + r.temperature + '°C</p>' +
                        '</div>';
                    }).join('');
                }
            });

            // --- Mise à jour KPI nb alertes ---
            var kpiAlerteEl = document.getElementById('kpiTpmsAlertes');
            if (kpiAlerteEl) kpiAlerteEl.textContent = data.nb_alertes;

            // --- Mise à jour du point "Maintenant" sur la courbe ---
            var lastIdx = courbeChart.data.datasets[0].data.length - 1;
            courbeChart.data.datasets[0].data[lastIdx] = data.pression_moy;
            // Mettre à jour aussi les lignes seuil
            courbeChart.data.datasets[1].data[lastIdx] = data.seuil_pmin;
            courbeChart.data.datasets[2].data[lastIdx] = data.seuil_pmax;
            courbeChart.update('none'); // 'none' = pas d'animation pour fluidité
        })
        .catch(function() {
            if (badgeConnexion) {
                badgeConnexion.style.background = '#BA7517';
                badgeConnexion.textContent = '● Reconnexion...';
            }
        });
}

// Premier appel immédiat puis toutes les 5 secondes
mettreAJourTpms();
setInterval(mettreAJourTpms, 5000);
</script>

<?php require_once '../includes/footer.php'; ?>