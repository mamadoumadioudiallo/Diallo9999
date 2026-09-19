<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : admin/moteurs.php
// Rôle    : Page dédiée au suivi de l'état des moteurs (ON/OFF)
//           heures de fonctionnement, anomalies
// ============================================================

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAdmin();

// ====== FILTRES ======
$date       = $_GET['date'] ?? date('Y-m-d');
$filtreType = $_GET['type'] ?? '';
$filtreSup      = $_GET['sup']      ?? '';
$filtreVehicule = $_GET['vehicule'] ?? '';

// ====== ÉTAT ACTUEL DES MOTEURS (avec filtres type/sup/vehicule) ======
$etatConditions = ["v.statut = 'actif'"];
$etatParams = [];
if ($filtreType)     { $etatConditions[] = "v.type = ?";           $etatParams[] = $filtreType; }
if ($filtreSup)      { $etatConditions[] = "v.id_superviseur = ?"; $etatParams[] = $filtreSup; }
if ($filtreVehicule) { $etatConditions[] = "v.id_vehicule = ?";    $etatParams[] = $filtreVehicule; }
$etatWhere = 'WHERE ' . implode(' AND ', $etatConditions);

$stmt = $pdo->prepare("
    SELECT
        v.id_vehicule, v.immatriculation, v.type, v.marque, v.modele,
        u.nom AS sup_nom, u.prenom AS sup_prenom,
        t.moteur_etat, t.vitesse, t.temperature_moteur, t.horodatage
    FROM vehicules v
    INNER JOIN (
        SELECT t1.* FROM telemetrie t1
        INNER JOIN (
            SELECT id_vehicule, MAX(horodatage) AS max_horo
            FROM telemetrie GROUP BY id_vehicule
        ) t2 ON t1.id_vehicule = t2.id_vehicule AND t1.horodatage = t2.max_horo
    ) t ON v.id_vehicule = t.id_vehicule
    LEFT JOIN utilisateurs u ON v.id_superviseur = u.id_user
    $etatWhere
    ORDER BY t.moteur_etat DESC, v.immatriculation ASC
");
$stmt->execute($etatParams);
$etatActuel = $stmt->fetchAll();

$nbOn  = count(array_filter($etatActuel, fn($v) => $v['moteur_etat'] === 'on'));
$nbOff = count(array_filter($etatActuel, fn($v) => $v['moteur_etat'] === 'off'));

// ====== HEURES MOTEUR PAR VÉHICULE (jour sélectionné) ======
$conditions = ["DATE(horodatage) = ?"];
$params = [$date];

if ($filtreType) { $conditions[] = "v.type = ?"; $params[] = $filtreType; }
if ($filtreSup)  { $conditions[] = "v.id_superviseur = ?"; $params[] = $filtreSup; }
if ($filtreVehicule) { $conditions[] = "v.id_vehicule = ?"; $params[] = $filtreVehicule; }
$where = 'WHERE ' . implode(' AND ', $conditions);

$stmt = $pdo->prepare("
    SELECT
        v.id_vehicule, v.immatriculation, v.type, v.marque, v.modele,
        u.nom AS sup_nom, u.prenom AS sup_prenom,
        COUNT(CASE WHEN t.moteur_etat = 'on'  THEN 1 END) AS releves_on,
        COUNT(CASE WHEN t.moteur_etat = 'off' THEN 1 END) AS releves_off,
        COUNT(*) AS total_releves,
        MIN(t.horodatage) AS premier_releve,
        MAX(t.horodatage) AS dernier_releve
    FROM vehicules v
    JOIN telemetrie t ON v.id_vehicule = t.id_vehicule
    LEFT JOIN utilisateurs u ON v.id_superviseur = u.id_user
    $where
    GROUP BY v.id_vehicule, v.immatriculation, v.type, v.marque, v.modele, u.nom, u.prenom
    ORDER BY releves_on DESC
");
$stmt->execute($params);
$heuresMoteur = $stmt->fetchAll();

// ====== ÉVOLUTION MOTEURS ON — 7 DERNIERS JOURS (filtrée) ======
$evolConditions = ["t.horodatage >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)"];
$evolParams = [];
if ($filtreType)     { $evolConditions[] = "v.type = ?";           $evolParams[] = $filtreType; }
if ($filtreSup)      { $evolConditions[] = "v.id_superviseur = ?"; $evolParams[] = $filtreSup; }
if ($filtreVehicule) { $evolConditions[] = "v.id_vehicule = ?";    $evolParams[] = $filtreVehicule; }
$evolWhere = 'WHERE ' . implode(' AND ', $evolConditions);

$stmt = $pdo->prepare("
    SELECT
        DATE(t.horodatage) AS jour,
        COUNT(CASE WHEN t.moteur_etat = 'on' THEN 1 END) AS nb_on,
        COUNT(CASE WHEN t.moteur_etat = 'off' THEN 1 END) AS nb_off
    FROM telemetrie t
    JOIN vehicules v ON t.id_vehicule = v.id_vehicule
    $evolWhere
    GROUP BY DATE(t.horodatage)
    ORDER BY jour ASC
");
$stmt->execute($evolParams);
$evolution = $stmt->fetchAll();

// ====== LISTE SUPERVISEURS ======
$superviseurs = $pdo->query("
    SELECT id_user, nom, prenom FROM utilisateurs
    WHERE role = 'superviseur' AND statut = 'actif'
")->fetchAll();

if ($filtreSup) {
    $stmtV = $pdo->prepare("SELECT id_vehicule, immatriculation, type FROM vehicules WHERE statut = 'actif' AND id_superviseur = ? ORDER BY immatriculation");
    $stmtV->execute([$filtreSup]);
} else {
    $stmtV = $pdo->query("SELECT id_vehicule, immatriculation, type FROM vehicules WHERE statut = 'actif' ORDER BY immatriculation");
}
$vehiculesListe = $stmtV->fetchAll();

$pageTitle = 'État des moteurs';
require_once '../includes/header.php';
?>

<div class="dash-header-row">
    <div>
        <h1>&#128268; État des moteurs</h1>
        <p class="subtitle">Suivi temps réel et historique des moteurs — <?= date('d/m/Y') ?></p>
    </div>
    <div class="dash-actions">
        <a href="dashboard.php" class="btn-dash btn-dash-outline">&#8592; Retour au dashboard</a>
    </div>
</div>

<!-- KPI temps réel -->
<div class="kpi-grid" style="margin-bottom:16px;">
    <div class="kpi-card">
        <div class="kpi-info">
            <p class="label">Moteurs allumés</p>
            <p class="value" style="color:#1D9E75;"><?= $nbOn ?></p>
            <p class="trend">En ce moment</p>
        </div>
        <div class="kpi-icon">&#128268;</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-info">
            <p class="label">Moteurs éteints</p>
            <p class="value" style="color:#5C6B68;"><?= $nbOff ?></p>
            <p class="trend">En ce moment</p>
        </div>
        <div class="kpi-icon">&#9211;</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-info">
            <p class="label">Total véhicules actifs</p>
            <p class="value"><?= count($etatActuel) ?></p>
            <p class="trend">Dans la flotte</p>
        </div>
        <div class="kpi-icon">&#128666;</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-info">
            <p class="label">Taux d'activité</p>
            <p class="value"><?= count($etatActuel) > 0 ? round($nbOn / count($etatActuel) * 100) : 0 ?> %</p>
            <p class="trend">Moteurs ON / Total</p>
        </div>
        <div class="kpi-icon">&#128200;</div>
    </div>
</div>

<!-- Filtres -->
<div class="panel" style="padding:16px 20px;margin-bottom:16px;">
    <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
        <div class="form-group" style="margin:0;flex:1;min-width:140px;">
            <label style="font-size:12px;">Date (historique)</label>
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
        <a href="moteurs.php" class="btn-dash btn-dash-outline" style="height:38px;line-height:22px;">Réinitialiser</a>
    </form>
</div>

<!-- État actuel en temps réel -->
<div class="panel fleet-table-wrap" style="margin-bottom:16px;">
    <div class="panel-header">
        <h3>&#128268; État actuel des moteurs (temps réel)</h3>
        <span style="font-size:12px;color:#5C6B68;">Mis à jour toutes les 5 secondes</span>
    </div>
    <table>
        <thead>
            <tr>
                <th>Véhicule</th>
                <th>Type</th>
                <th>Superviseur</th>
                <th>Moteur</th>
                <th>Vitesse</th>
                <th>Température</th>
                <th>Dernier relevé</th>
            </tr>
        </thead>
        <tbody id="tableauMoteurs">
            <?php foreach ($etatActuel as $v): ?>
                <?php $on = $v['moteur_etat'] === 'on'; ?>
                <tr data-vehicule-id="<?= $v['id_vehicule'] ?>">
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
                        <span class="badge <?= $on ? 'badge-success' : 'badge-muted' ?> moteur-badge">
                            <?= $on ? '&#128268; ON' : '&#9211; OFF' ?>
                        </span>
                    </td>
                    <td class="col-vitesse"><?= round($v['vitesse']) ?> km/h</td>
                    <td class="col-temp" style="color:<?= $v['temperature_moteur'] > 95 ? '#E24B4A' : '#1A1A1A' ?>;">
                        <?= round($v['temperature_moteur']) ?> °C
                    </td>
                    <td style="font-size:12px;"><?= date('H:i:s', strtotime($v['horodatage'])) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Tableau heures moteur par véhicule -->
<div class="panel fleet-table-wrap" style="margin-bottom:16px;">
    <div class="panel-header">
        <h3>&#128203; Activité moteur par véhicule — <?= date('d/m/Y', strtotime($date)) ?></h3>
    </div>
    <table>
        <thead>
            <tr>
                <th>Véhicule</th>
                <th>Type</th>
                <th>Superviseur</th>
                <th>Relevés ON</th>
                <th>Relevés OFF</th>
                <th>Taux activité</th>
                <th>Jauge</th>
                <th>Premier relevé</th>
                <th>Dernier relevé</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($heuresMoteur)): ?>
                <tr><td colspan="9" style="text-align:center;padding:30px;color:#999;">Aucune donnée pour cette date.</td></tr>
            <?php endif; ?>
            <?php foreach ($heuresMoteur as $v): ?>
                <?php
                    $tauxActivite = $v['total_releves'] > 0
                        ? round($v['releves_on'] / $v['total_releves'] * 100)
                        : 0;
                    $couleur = $tauxActivite > 70 ? '#1D9E75' : ($tauxActivite > 30 ? '#BA7517' : '#E24B4A');
                ?>
                <tr>
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
                    <td style="color:#1D9E75;font-weight:600;"><?= $v['releves_on'] ?></td>
                    <td style="color:#5C6B68;"><?= $v['releves_off'] ?></td>
                    <td style="font-weight:600;color:<?= $couleur ?>;"><?= $tauxActivite ?> %</td>
                    <td style="min-width:100px;">
                        <div style="background:#E9ECEC;border-radius:4px;height:8px;width:100%;">
                            <div style="background:<?= $couleur ?>;width:<?= $tauxActivite ?>%;height:8px;border-radius:4px;"></div>
                        </div>
                    </td>
                    <td style="font-size:12px;"><?= date('H:i', strtotime($v['premier_releve'])) ?></td>
                    <td style="font-size:12px;"><?= date('H:i', strtotime($v['dernier_releve'])) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Graphique évolution 7 jours -->
<div class="panel" style="padding:20px;">
    <div class="panel-header" style="margin-bottom:12px;">
        <h3>&#128202; Activité moteurs — 7 derniers jours
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
    <canvas id="moteursChart" height="80"></canvas>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// Graphique évolution
var evolutionData = <?= json_encode($evolution) ?>;
var labels = evolutionData.map(function(e) {
    var d = new Date(e.jour + 'T00:00:00');
    return d.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' });
});

new Chart(document.getElementById('moteursChart'), {
    type: 'bar',
    data: {
        labels: labels,
        datasets: [
            {
                label: 'Relevés moteur ON',
                data: evolutionData.map(function(e) { return parseInt(e.nb_on) || 0; }),
                backgroundColor: '#1D9E75',
                borderRadius: 3
            },
            {
                label: 'Relevés moteur OFF',
                data: evolutionData.map(function(e) { return parseInt(e.nb_off) || 0; }),
                backgroundColor: '#E9ECEC',
                borderRadius: 3
            }
        ]
    },
    options: {
        responsive: true,
        interaction: { mode: 'index', intersect: false },
        scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true } }
    }
});

// Polling temps réel — met à jour le tableau des moteurs toutes les 5s
(function () {
    function rafraichir() {
        fetch('../api/telemetrie.php')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                data.vehicules.forEach(function(v) {
                    var ligne = document.querySelector('tr[data-vehicule-id="' + v.id_vehicule + '"]');
                    if (!ligne) return;

                    var badge = ligne.querySelector('.moteur-badge');
                    var on = v.moteur_etat === 'on';
                    if (badge) {
                        badge.className = 'badge ' + (on ? 'badge-success' : 'badge-muted') + ' moteur-badge';
                        badge.innerHTML = on ? '&#128268; ON' : '&#9211; OFF';
                    }

                    var colVitesse = ligne.querySelector('.col-vitesse');
                    if (colVitesse) colVitesse.textContent = Math.round(v.vitesse) + ' km/h';

                    var colTemp = ligne.querySelector('.col-temp');
                    if (colTemp) {
                        colTemp.textContent = Math.round(v.temperature_moteur) + ' °C';
                        colTemp.style.color = v.temperature_moteur > 95 ? '#E24B4A' : '#1A1A1A';
                    }
                });
            })
            .catch(function() {});
    }
    rafraichir();
    setInterval(rafraichir, 5000);
})();
</script>

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

<?php require_once '../includes/footer.php'; ?>