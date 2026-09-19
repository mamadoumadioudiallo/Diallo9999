<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : admin/chargement.php
// Rôle    : Page dédiée au suivi du chargement des camions miniers
//           (charge nette, tare, surcharges, historique)
// ============================================================

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAdmin();

$date       = $_GET['date'] ?? date('Y-m-d');
$filtreSup      = $_GET['sup']      ?? '';
$filtreVehicule = $_GET['vehicule'] ?? '';
$filtreVehicule = $_GET['vehicule'] ?? '';

// ====== ÉTAT ACTUEL DES CAMIONS MINIERS (filtré) ======
$etatConditions = ["v.statut = 'actif'", "v.type = 'minier'"];
$etatParams = [];
if ($filtreSup)      { $etatConditions[] = "v.id_superviseur = ?"; $etatParams[] = $filtreSup; }
if ($filtreVehicule) { $etatConditions[] = "v.id_vehicule = ?";    $etatParams[] = $filtreVehicule; }
$etatWhere = 'WHERE ' . implode(' AND ', $etatConditions);

$stmt = $pdo->prepare("
    SELECT v.id_vehicule, v.immatriculation, v.marque, v.modele,
           v.poids_tare_kg, v.poids_max_kg,
           u.nom AS sup_nom, u.prenom AS sup_prenom,
           t.poids_charge_kg, t.moteur_etat, t.horodatage
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
    ORDER BY t.poids_charge_kg DESC
");
$stmt->execute($etatParams);
$camionsActuels = $stmt->fetchAll();

// KPI temps réel
$chargeNetteTotale = 0;
$nbChargés = 0;
$nbSurcharge = 0;
$nbVides = 0;
foreach ($camionsActuels as $c) {
    $charge = (int) $c['poids_charge_kg'];
    $chargeNetteTotale += $charge;
    if ($charge > 0) {
        $nbChargés++;
        if ($c['poids_max_kg'] && $charge > $c['poids_max_kg']) $nbSurcharge++;
    } else {
        $nbVides++;
    }
}
$chargeNetteTonnes = round($chargeNetteTotale / 1000, 1);

// ====== HISTORIQUE PAR VÉHICULE (jour sélectionné) ======
$conditions = ["v.type = 'minier'", "DATE(t.horodatage) = ?"];
$params = [$date];
if ($filtreSup) { $conditions[] = "v.id_superviseur = ?"; $params[] = $filtreSup; }
if ($filtreVehicule) { $conditions[] = "v.id_vehicule = ?"; $params[] = $filtreVehicule; }
$where = 'WHERE ' . implode(' AND ', $conditions);

$stmt = $pdo->prepare("
    SELECT v.id_vehicule, v.immatriculation, v.marque, v.modele,
           v.poids_tare_kg, v.poids_max_kg,
           u.nom AS sup_nom, u.prenom AS sup_prenom,
           MAX(t.poids_charge_kg) AS charge_max_jour,
           AVG(CASE WHEN t.poids_charge_kg > 0 THEN t.poids_charge_kg END) AS charge_moy_jour,
           COUNT(CASE WHEN t.poids_charge_kg > 0 THEN 1 END) AS nb_releves_charges,
           COUNT(CASE WHEN t.poids_charge_kg > v.poids_max_kg AND v.poids_max_kg IS NOT NULL THEN 1 END) AS nb_surcharges
    FROM vehicules v
    JOIN telemetrie t ON v.id_vehicule = t.id_vehicule
    LEFT JOIN utilisateurs u ON v.id_superviseur = u.id_user
    $where
    GROUP BY v.id_vehicule, v.immatriculation, v.marque, v.modele,
             v.poids_tare_kg, v.poids_max_kg, u.nom, u.prenom
    ORDER BY charge_max_jour DESC
");
$stmt->execute($params);
$historiqueChargement = $stmt->fetchAll();

// ====== ÉVOLUTION CHARGE NETTE — 7 JOURS (filtrée) ======
$evolConditions = ["v.type = 'minier'", "v.statut = 'actif'", "t.horodatage >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)"];
$evolParams = [];
if ($filtreSup)      { $evolConditions[] = "v.id_superviseur = ?"; $evolParams[] = $filtreSup; }
if ($filtreVehicule) { $evolConditions[] = "v.id_vehicule = ?";    $evolParams[] = $filtreVehicule; }
$evolWhere = 'WHERE ' . implode(' AND ', $evolConditions);

$stmt = $pdo->prepare("
    SELECT DATE(t.horodatage) AS jour,
           SUM(t.poids_charge_kg) / 1000 AS tonnage_total,
           AVG(CASE WHEN t.poids_charge_kg > 0 THEN t.poids_charge_kg END) / 1000 AS charge_moy
    FROM telemetrie t
    JOIN vehicules v ON t.id_vehicule = v.id_vehicule
    $evolWhere
    GROUP BY DATE(t.horodatage)
    ORDER BY jour ASC
");
$stmt->execute($evolParams);
$evolutionCharge = $stmt->fetchAll();

// ====== ALERTES SURCHARGE ======
$stmtAlertes = $pdo->query("
    SELECT a.*, v.immatriculation FROM alertes a
    JOIN vehicules v ON a.id_vehicule = v.id_vehicule
    WHERE a.type_alerte = 'surcharge' AND a.statut != 'resolue'
    ORDER BY a.horodatage DESC LIMIT 20
");
$alertesSurcharge = $stmtAlertes->fetchAll();

// ====== LISTE SUPERVISEURS & VÉHICULES MINIERS ======
$superviseurs = $pdo->query("SELECT id_user, nom, prenom FROM utilisateurs WHERE role = 'superviseur' AND statut = 'actif'")->fetchAll();

if ($filtreSup) {
    $stmtV = $pdo->prepare("SELECT id_vehicule, immatriculation FROM vehicules WHERE type = 'minier' AND statut = 'actif' AND id_superviseur = ? ORDER BY immatriculation");
    $stmtV->execute([$filtreSup]);
} else {
    $stmtV = $pdo->query("SELECT id_vehicule, immatriculation FROM vehicules WHERE type = 'minier' AND statut = 'actif' ORDER BY immatriculation");
}
$vehiculesMiniers = $stmtV->fetchAll();

$pageTitle = 'Suivi du chargement';
require_once '../includes/header.php';
?>

<div class="dash-header-row">
    <div>
        <h1>&#9878; Suivi du chargement</h1>
        <p class="subtitle">Charge nette des camions miniers — temps réel et historique</p>
    </div>
    <div class="dash-actions">
        <a href="dashboard.php" class="btn-dash btn-dash-outline">&#8592; Retour au dashboard</a>
    </div>
</div>

<!-- KPI temps réel -->
<div class="kpi-grid" style="margin-bottom:16px;">
    <div class="kpi-card">
        <div class="kpi-info">
            <p class="label">Tonnage total actuel</p>
            <p class="value" style="color:#0F6E56;"><?= number_format($chargeNetteTonnes, 1, ',', ' ') ?> t</p>
            <p class="trend">En ce moment — tous camions</p>
        </div>
        <div class="kpi-icon">&#9878;</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-info">
            <p class="label">Camions chargés</p>
            <p class="value"><?= $nbChargés ?></p>
            <p class="trend">En transit en ce moment</p>
        </div>
        <div class="kpi-icon">&#128666;</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-info">
            <p class="label">Camions vides</p>
            <p class="value"><?= $nbVides ?></p>
            <p class="trend">En attente de chargement</p>
        </div>
        <div class="kpi-icon">&#128667;</div>
    </div>
    <div class="kpi-card <?= $nbSurcharge > 0 ? 'danger' : '' ?>">
        <div class="kpi-info">
            <p class="label">Surcharges détectées</p>
            <p class="value"><?= $nbSurcharge ?></p>
            <p class="trend"><?= $nbSurcharge > 0 ? 'Intervention requise !' : 'Aucune surcharge' ?></p>
        </div>
        <div class="kpi-icon">&#9888;</div>
    </div>
</div>

<!-- Filtres -->
<div class="panel" style="padding:16px 20px;margin-bottom:16px;">
    <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
        <div class="form-group" style="margin:0;flex:1;min-width:140px;">
            <label style="font-size:12px;">Date (historique)</label>
            <input type="date" name="date" value="<?= htmlspecialchars($date) ?>" max="<?= date('Y-m-d') ?>">
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
                <?php foreach ($vehiculesMiniers as $v): ?>
                    <option value="<?= $v['id_vehicule'] ?>" <?= $filtreVehicule == $v['id_vehicule'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($v['immatriculation']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn-dash btn-dash-primary" style="height:38px;">Filtrer</button>
        <a href="chargement.php" class="btn-dash btn-dash-outline" style="height:38px;line-height:22px;">Réinitialiser</a>
    </form>
</div>

<!-- Tableau état actuel temps réel -->
<div class="panel fleet-table-wrap" style="margin-bottom:16px;">
    <div class="panel-header">
        <h3>&#128666; État du chargement en temps réel
            <?php if ($filtreSup || $filtreVehicule): ?>
                <span style="font-size:12px;color:#5C6B68;font-weight:normal;">(<?php
                    $parts = [];
                    if ($filtreSup) foreach ($superviseurs as $s) { if ($s['id_user'] == $filtreSup) $parts[] = htmlspecialchars($s['prenom'] . ' ' . $s['nom']); }
                    if ($filtreVehicule) foreach ($vehiculesMiniers as $v) { if ($v['id_vehicule'] == $filtreVehicule) $parts[] = htmlspecialchars($v['immatriculation']); }
                    echo implode(' — ', $parts);
                ?>)</span>
            <?php endif; ?>
        </h3>
        <span id="chargeBadge" style="font-size:12px;color:#fff;background:#999;padding:3px 10px;border-radius:12px;">● Connexion...</span>
    </div>
    <table>
        <thead>
            <tr>
                <th>Véhicule</th>
                <th>Superviseur</th>
                <th>Moteur</th>
                <th>Tare</th>
                <th>Charge nette</th>
                <th>Charge max</th>
                <th>Jauge</th>
                <th>Statut</th>
                <th>Relevé</th>
            </tr>
        </thead>
        <tbody id="tableauChargement">
            <?php foreach ($camionsActuels as $c): ?>
                <?php
                    $charge = (int) $c['poids_charge_kg'];
                    $chargeMax = $c['poids_max_kg'];
                    $surcharge = $chargeMax && $charge > $chargeMax;
                    $pct = $chargeMax && $chargeMax > 0 ? min(110, round($charge / $chargeMax * 100)) : 0;
                    $couleur = $surcharge ? '#E24B4A' : ($pct > 85 ? '#BA7517' : '#1D9E75');
                    $moteurOn = $c['moteur_etat'] === 'on';
                ?>
                <tr data-charge-id="<?= $c['id_vehicule'] ?>" style="background:<?= $surcharge ? '#FFF8F8' : '' ?>;">
                    <td>
                        <a href="fiche_vehicule.php?id=<?= $c['id_vehicule'] ?>" style="color:#0F6E56;font-weight:600;">
                            <?= htmlspecialchars($c['immatriculation']) ?>
                        </a>
                        <br><span style="font-size:11px;color:#999;"><?= htmlspecialchars($c['marque'] . ' ' . $c['modele']) ?></span>
                    </td>
                    <td style="font-size:12px;">
                        <?= $c['sup_nom'] ? htmlspecialchars($c['sup_prenom'] . ' ' . $c['sup_nom']) : '<span style="color:#999;">—</span>' ?>
                    </td>
                    <td>
                        <span class="badge <?= $moteurOn ? 'badge-success' : 'badge-muted' ?> col-moteur">
                            <?= $moteurOn ? '&#128268; ON' : '&#9211; OFF' ?>
                        </span>
                    </td>
                    <td style="font-size:12px;color:#5C6B68;">
                        <?= $c['poids_tare_kg'] ? number_format($c['poids_tare_kg'], 0, ',', ' ') . ' kg' : '<span style="color:#ccc;">—</span>' ?>
                    </td>
                    <td>
                        <strong class="col-charge" style="color:<?= $couleur ?>;">
                            <?= $charge > 0 ? number_format($charge, 0, ',', ' ') . ' kg' : '—' ?>
                        </strong>
                    </td>
                    <td style="font-size:12px;color:#5C6B68;">
                        <?= $chargeMax ? number_format($chargeMax, 0, ',', ' ') . ' kg' : '—' ?>
                    </td>
                    <td style="min-width:100px;">
                        <div class="col-jauge-wrap" style="background:#E9ECEC;border-radius:4px;height:8px;">
                            <div class="col-jauge-fill" style="background:<?= $couleur ?>;width:<?= $pct ?>%;height:8px;border-radius:4px;"></div>
                        </div>
                        <span class="col-jauge-pct" style="font-size:10px;color:#5C6B68;"><?= $pct ?> %</span>
                    </td>
                    <td>
                        <?php if ($surcharge): ?>
                            <span class="badge badge-danger col-statut">&#9888; Surcharge</span>
                        <?php elseif ($charge > 0): ?>
                            <span class="badge badge-success col-statut">&#128666; Chargé</span>
                        <?php else: ?>
                            <span class="badge badge-muted col-statut">Vide</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px;"><?= date('H:i', strtotime($c['horodatage'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($camionsActuels)): ?>
                <tr><td colspan="9" style="text-align:center;padding:30px;color:#999;">Aucun camion minier actif.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Tableau historique -->
<div class="panel fleet-table-wrap" style="margin-bottom:16px;">
    <div class="panel-header">
        <h3>&#128203; Historique chargement — <?= date('d/m/Y', strtotime($date)) ?></h3>
    </div>
    <table>
        <thead>
            <tr>
                <th>Véhicule</th>
                <th>Superviseur</th>
                <th>Tare</th>
                <th>Charge max du jour</th>
                <th>Charge moy. du jour</th>
                <th>Charge max autorisée</th>
                <th>Surcharges</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($historiqueChargement)): ?>
                <tr><td colspan="7" style="text-align:center;padding:30px;color:#999;">Aucune donnée pour cette date.</td></tr>
            <?php endif; ?>
            <?php foreach ($historiqueChargement as $h): ?>
                <?php $surcharge = $h['poids_max_kg'] && $h['charge_max_jour'] > $h['poids_max_kg']; ?>
                <tr style="background:<?= $h['nb_surcharges'] > 0 ? '#FFF8F8' : '' ?>;">
                    <td>
                        <a href="fiche_vehicule.php?id=<?= $h['id_vehicule'] ?>" style="color:#0F6E56;font-weight:600;">
                            <?= htmlspecialchars($h['immatriculation']) ?>
                        </a>
                        <br><span style="font-size:11px;color:#999;"><?= htmlspecialchars($h['marque'] . ' ' . $h['modele']) ?></span>
                    </td>
                    <td style="font-size:12px;">
                        <?= $h['sup_nom'] ? htmlspecialchars($h['sup_prenom'] . ' ' . $h['sup_nom']) : '<span style="color:#999;">—</span>' ?>
                    </td>
                    <td style="font-size:12px;color:#5C6B68;">
                        <?= $h['poids_tare_kg'] ? number_format($h['poids_tare_kg'], 0, ',', ' ') . ' kg' : '—' ?>
                    </td>
                    <td style="color:<?= $surcharge ? '#E24B4A' : '#1A1A1A' ?>;font-weight:600;">
                        <?= $h['charge_max_jour'] ? number_format($h['charge_max_jour'], 0, ',', ' ') . ' kg' : '—' ?>
                        <?= $surcharge ? '<span style="font-size:11px;"> &#9888; SURCHARGE</span>' : '' ?>
                    </td>
                    <td><?= $h['charge_moy_jour'] ? number_format($h['charge_moy_jour'], 0, ',', ' ') . ' kg' : '—' ?></td>
                    <td style="font-size:12px;color:#5C6B68;">
                        <?= $h['poids_max_kg'] ? number_format($h['poids_max_kg'], 0, ',', ' ') . ' kg' : '—' ?>
                    </td>
                    <td>
                        <?php if ($h['nb_surcharges'] > 0): ?>
                            <span class="badge badge-danger"><?= $h['nb_surcharges'] ?> surcharge(s)</span>
                        <?php else: ?>
                            <span class="badge badge-success">Aucune</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Alertes surcharge actives -->
<?php if (!empty($alertesSurcharge)): ?>
<div class="panel fleet-table-wrap" style="margin-bottom:16px;">
    <div class="panel-header">
        <h3>&#9888; Alertes surcharge non résolues</h3>
        <span class="badge badge-danger"><?= count($alertesSurcharge) ?></span>
    </div>
    <table>
        <thead>
            <tr><th>Véhicule</th><th>Poids mesuré</th><th>Date / Heure</th><th>Statut</th></tr>
        </thead>
        <tbody>
            <?php foreach ($alertesSurcharge as $a): ?>
                <?php $sb = ['non_traitee'=>'badge-danger','en_cours'=>'badge-warning']; ?>
                <tr>
                    <td><?= htmlspecialchars($a['immatriculation']) ?></td>
                    <td><?= number_format($a['valeur_declenchante'], 0, ',', ' ') ?> kg</td>
                    <td style="font-size:12px;"><?= date('d/m/Y H:i', strtotime($a['horodatage'])) ?></td>
                    <td><span class="badge <?= $sb[$a['statut']] ?>"><?= $a['statut'] === 'non_traitee' ? 'Non traitée' : 'En cours' ?></span></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- Graphique évolution 7 jours -->
<div class="panel" style="padding:20px;">
    <div class="panel-header" style="margin-bottom:12px;">
        <h3>&#128202; Évolution du tonnage — 7 derniers jours
            <?php if ($filtreSup || $filtreVehicule): ?>
                <span style="font-size:12px;color:#5C6B68;font-weight:normal;">(<?php
                    $parts = [];
                    if ($filtreSup) foreach ($superviseurs as $s) { if ($s['id_user'] == $filtreSup) $parts[] = htmlspecialchars($s['prenom'] . ' ' . $s['nom']); }
                    if ($filtreVehicule) foreach ($vehiculesMiniers as $v) { if ($v['id_vehicule'] == $filtreVehicule) $parts[] = htmlspecialchars($v['immatriculation']); }
                    echo implode(' — ', $parts);
                ?>)</span>
            <?php endif; ?>
        </h3>
    </div>
    <canvas id="chargeChart" height="90"></canvas>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// Graphique évolution
var evolData = <?= json_encode($evolutionCharge) ?>;
var labels = evolData.map(function(e) {
    var d = new Date(e.jour + 'T00:00:00');
    return d.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' });
});

new Chart(document.getElementById('chargeChart'), {
    type: 'bar',
    data: {
        labels: labels,
        datasets: [
            {
                label: 'Tonnage total (t)',
                data: evolData.map(function(e) { return parseFloat(e.tonnage_total) || 0; }),
                backgroundColor: '#0F6E56',
                borderRadius: 4,
                yAxisID: 'y'
            },
            {
                label: 'Charge moyenne (t)',
                data: evolData.map(function(e) { return parseFloat(e.charge_moy) || 0; }),
                type: 'line',
                borderColor: '#BA7517',
                backgroundColor: 'transparent',
                tension: 0.3,
                pointRadius: 4,
                yAxisID: 'y'
            }
        ]
    },
    options: {
        responsive: true,
        interaction: { mode: 'index', intersect: false },
        scales: { y: { beginAtZero: true, title: { display: true, text: 'tonnes' } } }
    }
});

// Polling temps réel — tableau de chargement toutes les 5s
var chargeBadge = document.getElementById('chargeBadge');

function rafraichirChargement() {
    fetch('../api/telemetrie.php')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (chargeBadge) {
                chargeBadge.style.background = '#1D9E75';
                chargeBadge.textContent = '● Temps réel — ' + data.timestamp;
            }

            data.vehicules.forEach(function(v) {
                var ligne = document.querySelector('tr[data-charge-id="' + v.id_vehicule + '"]');
                if (!ligne) return;

                var charge = parseInt(v.poids_charge_kg) || 0;

                // Moteur
                var colMoteur = ligne.querySelector('.col-moteur');
                if (colMoteur) {
                    var on = v.moteur_etat === 'on';
                    colMoteur.className = 'badge ' + (on ? 'badge-success' : 'badge-muted') + ' col-moteur';
                    colMoteur.innerHTML = on ? '&#128268; ON' : '&#9211; OFF';
                }

                // Charge nette
                var colCharge = ligne.querySelector('.col-charge');
                if (colCharge) {
                    colCharge.textContent = charge > 0 ? charge.toLocaleString('fr-FR') + ' kg' : '—';
                }

                // Jauge
                var jaugeWrap = ligne.querySelector('.col-jauge-wrap');
                var jaugeFill = ligne.querySelector('.col-jauge-fill');
                var jaugePct  = ligne.querySelector('.col-jauge-pct');
                if (jaugeFill && jaugePct) {
                    jaugePct.textContent = v.poids_max_kg ? Math.min(110, Math.round(charge / v.poids_max_kg * 100)) + ' %' : '—';
                    var pct = v.poids_max_kg ? Math.min(110, Math.round(charge / v.poids_max_kg * 100)) : 0;
                    jaugeFill.style.width = pct + '%';
                    var couleur = (v.poids_max_kg && charge > v.poids_max_kg) ? '#E24B4A' : (pct > 85 ? '#BA7517' : '#1D9E75');
                    jaugeFill.style.background = couleur;
                    colCharge.style.color = couleur;
                }

                // Statut
                var colStatut = ligne.querySelector('.col-statut');
                if (colStatut) {
                    var surcharge = v.poids_max_kg && charge > v.poids_max_kg;
                    if (surcharge) {
                        colStatut.className = 'badge badge-danger col-statut';
                        colStatut.innerHTML = '&#9888; Surcharge';
                        ligne.style.background = '#FFF8F8';
                    } else if (charge > 0) {
                        colStatut.className = 'badge badge-success col-statut';
                        colStatut.innerHTML = '&#128666; Chargé';
                        ligne.style.background = '';
                    } else {
                        colStatut.className = 'badge badge-muted col-statut';
                        colStatut.textContent = 'Vide';
                        ligne.style.background = '';
                    }
                }
            });
        })
        .catch(function() {
            if (chargeBadge) {
                chargeBadge.style.background = '#BA7517';
                chargeBadge.textContent = '● Reconnexion...';
            }
        });
}

rafraichirChargement();
setInterval(rafraichirChargement, 5000);
</script>

<script>
function mettreAJourVehicules() {
    var idSup = document.getElementById('selectSup').value;
    var sel   = document.getElementById('selectVehicule');
    sel.innerHTML = '<option value="">Tous</option>';
    if (!idSup) return;
    fetch('../api/vehicules_par_sup.php?sup=' + idSup + '&type=minier')
        .then(function(r) { return r.json(); })
        .then(function(liste) {
            liste.forEach(function(v) {
                var o = document.createElement('option');
                o.value = v.id_vehicule;
                o.textContent = v.immatriculation;
                sel.appendChild(o);
            });
        });
}
</script>

<?php require_once '../includes/footer.php'; ?>