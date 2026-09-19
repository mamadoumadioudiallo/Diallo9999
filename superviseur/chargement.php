<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireSuperviseur();
$idSup = $_SESSION['user_id'];

$date           = $_GET['date']     ?? date('Y-m-d');
$filtreVehicule = $_GET['vehicule'] ?? '';

// État actuel filtré
$etatCond = ["v.statut = 'actif'", "v.type = 'minier'", "v.id_superviseur = ?"];
$etatParams = [$idSup];
if ($filtreVehicule) { $etatCond[] = "v.id_vehicule = ?"; $etatParams[] = $filtreVehicule; }

$stmt = $pdo->prepare("
    SELECT v.id_vehicule, v.immatriculation, v.marque, v.modele,
           v.poids_tare_kg, v.poids_max_kg,
           t.poids_charge_kg, t.moteur_etat, t.horodatage
    FROM vehicules v
    INNER JOIN (
        SELECT t1.* FROM telemetrie t1
        INNER JOIN (SELECT id_vehicule, MAX(horodatage) AS max_horo FROM telemetrie GROUP BY id_vehicule) t2
        ON t1.id_vehicule = t2.id_vehicule AND t1.horodatage = t2.max_horo
    ) t ON v.id_vehicule = t.id_vehicule
    WHERE " . implode(' AND ', $etatCond) . "
    ORDER BY t.poids_charge_kg DESC
");
$stmt->execute($etatParams);
$camionsActuels = $stmt->fetchAll();

// KPI
$chargeNetteTotale = 0; $nbCharges = 0; $nbSurcharge = 0; $nbVides = 0;
foreach ($camionsActuels as $c) {
    $charge = (int) $c['poids_charge_kg'];
    $chargeNetteTotale += $charge;
    if ($charge > 0) { $nbCharges++; if ($c['poids_max_kg'] && $charge > $c['poids_max_kg']) $nbSurcharge++; }
    else { $nbVides++; }
}
$chargeNetteTonnes = round($chargeNetteTotale / 1000, 1);

// Historique
$conditions = ["v.type = 'minier'", "DATE(t.horodatage) = ?", "v.id_superviseur = ?"];
$params = [$date, $idSup];
if ($filtreVehicule) { $conditions[] = "v.id_vehicule = ?"; $params[] = $filtreVehicule; }
$where = 'WHERE ' . implode(' AND ', $conditions);

$stmt = $pdo->prepare("
    SELECT v.id_vehicule, v.immatriculation, v.marque, v.modele,
           v.poids_tare_kg, v.poids_max_kg,
           MAX(t.poids_charge_kg) AS charge_max_jour,
           AVG(CASE WHEN t.poids_charge_kg > 0 THEN t.poids_charge_kg END) AS charge_moy_jour,
           COUNT(CASE WHEN t.poids_charge_kg > v.poids_max_kg AND v.poids_max_kg IS NOT NULL THEN 1 END) AS nb_surcharges
    FROM vehicules v JOIN telemetrie t ON v.id_vehicule = t.id_vehicule
    $where
    GROUP BY v.id_vehicule, v.immatriculation, v.marque, v.modele, v.poids_tare_kg, v.poids_max_kg
    ORDER BY charge_max_jour DESC
");
$stmt->execute($params);
$historique = $stmt->fetchAll();

// Évolution 7 jours
$evolCond = ["v.type = 'minier'", "v.statut = 'actif'", "v.id_superviseur = ?", "t.horodatage >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)"];
$evolParams = [$idSup];
if ($filtreVehicule) { $evolCond[] = "v.id_vehicule = ?"; $evolParams[] = $filtreVehicule; }

$stmt = $pdo->prepare("
    SELECT DATE(t.horodatage) AS jour,
           SUM(t.poids_charge_kg) / 1000 AS tonnage_total,
           AVG(CASE WHEN t.poids_charge_kg > 0 THEN t.poids_charge_kg END) / 1000 AS charge_moy
    FROM telemetrie t JOIN vehicules v ON t.id_vehicule = v.id_vehicule
    WHERE " . implode(' AND ', $evolCond) . "
    GROUP BY DATE(t.horodatage) ORDER BY jour ASC
");
$stmt->execute($evolParams);
$evolution = $stmt->fetchAll();

// Alertes surcharge
$stmt = $pdo->prepare("
    SELECT a.*, v.immatriculation FROM alertes a
    JOIN vehicules v ON a.id_vehicule = v.id_vehicule
    WHERE a.type_alerte = 'surcharge' AND a.statut != 'resolue' AND v.id_superviseur = ?
    ORDER BY a.horodatage DESC LIMIT 20
");
$stmt->execute([$idSup]);
$alertesSurcharge = $stmt->fetchAll();

$vehiculesListe = $pdo->prepare("SELECT id_vehicule, immatriculation FROM vehicules WHERE statut = 'actif' AND type = 'minier' AND id_superviseur = ? ORDER BY immatriculation");
$vehiculesListe->execute([$idSup]);
$vehiculesListe = $vehiculesListe->fetchAll();

$pageTitle = 'Chargement';
require_once '../includes/header.php';
?>

<div class="dash-header-row">
    <div><h1>&#9878; Suivi du chargement</h1><p class="subtitle">Mes camions miniers — temps réel</p></div>
    <div class="dash-actions"><a href="dashboard.php" class="btn-dash btn-dash-outline">&#8592; Retour</a></div>
</div>

<div class="kpi-grid" style="margin-bottom:16px;">
    <div class="kpi-card"><div class="kpi-info"><p class="label">Tonnage total actuel</p><p class="value" style="color:#0F6E56;"><?= number_format($chargeNetteTonnes, 1, ',', ' ') ?> t</p><p class="trend">En ce moment</p></div><div class="kpi-icon">&#9878;</div></div>
    <div class="kpi-card"><div class="kpi-info"><p class="label">Camions chargés</p><p class="value"><?= $nbCharges ?></p><p class="trend">En transit</p></div><div class="kpi-icon">&#128666;</div></div>
    <div class="kpi-card"><div class="kpi-info"><p class="label">Camions vides</p><p class="value"><?= $nbVides ?></p><p class="trend">En attente</p></div><div class="kpi-icon">&#128667;</div></div>
    <div class="kpi-card <?= $nbSurcharge > 0 ? 'danger' : '' ?>"><div class="kpi-info"><p class="label">Surcharges détectées</p><p class="value"><?= $nbSurcharge ?></p><p class="trend"><?= $nbSurcharge > 0 ? 'Intervention requise !' : 'Aucune surcharge' ?></p></div><div class="kpi-icon">&#9888;</div></div>
</div>

<div class="panel" style="padding:16px 20px;margin-bottom:16px;">
    <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
        <div class="form-group" style="margin:0;flex:1;min-width:140px;"><label style="font-size:12px;">Date</label><input type="date" name="date" value="<?= htmlspecialchars($date) ?>" max="<?= date('Y-m-d') ?>"></div>
        <div class="form-group" style="margin:0;flex:1;min-width:160px;">
            <label style="font-size:12px;">Véhicule</label>
            <select name="vehicule">
                <option value="">Tous mes camions miniers</option>
                <?php foreach ($vehiculesListe as $v): ?>
                    <option value="<?= $v['id_vehicule'] ?>" <?= $filtreVehicule == $v['id_vehicule'] ? 'selected' : '' ?>><?= htmlspecialchars($v['immatriculation']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn-dash btn-dash-primary" style="height:38px;">Filtrer</button>
        <a href="chargement.php" class="btn-dash btn-dash-outline" style="height:38px;line-height:22px;">Réinitialiser</a>
    </form>
</div>

<div class="panel fleet-table-wrap" style="margin-bottom:16px;">
    <div class="panel-header">
        <h3>&#128666; État du chargement en temps réel</h3>
        <span id="chargeBadge" style="font-size:12px;color:#fff;background:#999;padding:3px 10px;border-radius:12px;">● Connexion...</span>
    </div>
    <table>
        <thead><tr><th>Véhicule</th><th>Moteur</th><th>Tare</th><th>Charge nette</th><th>Charge max</th><th>Jauge</th><th>Statut</th><th>Relevé</th></tr></thead>
        <tbody id="tableauChargement">
            <?php foreach ($camionsActuels as $c): ?>
                <?php
                    $charge = (int) $c['poids_charge_kg'];
                    $chargeMax = $c['poids_max_kg'];
                    $surcharge = $chargeMax && $charge > $chargeMax;
                    $pct = $chargeMax ? min(110, round($charge / $chargeMax * 100)) : 0;
                    $couleur = $surcharge ? '#E24B4A' : ($pct > 85 ? '#BA7517' : '#1D9E75');
                    $moteurOn = $c['moteur_etat'] === 'on';
                ?>
                <tr data-charge-id="<?= $c['id_vehicule'] ?>" style="background:<?= $surcharge ? '#FFF8F8' : '' ?>;">
                    <td><a href="fiche_vehicule.php?id=<?= $c['id_vehicule'] ?>" style="color:#0F6E56;font-weight:600;"><?= htmlspecialchars($c['immatriculation']) ?></a><br><span style="font-size:11px;color:#999;"><?= htmlspecialchars($c['marque'] . ' ' . $c['modele']) ?></span></td>
                    <td><span class="badge <?= $moteurOn ? 'badge-success' : 'badge-muted' ?> col-moteur"><?= $moteurOn ? '&#128268; ON' : '&#9211; OFF' ?></span></td>
                    <td style="font-size:12px;color:#5C6B68;"><?= $c['poids_tare_kg'] ? number_format($c['poids_tare_kg'], 0, ',', ' ') . ' kg' : '—' ?></td>
                    <td><strong class="col-charge" style="color:<?= $couleur ?>;"><?= $charge > 0 ? number_format($charge, 0, ',', ' ') . ' kg' : '—' ?></strong></td>
                    <td style="font-size:12px;color:#5C6B68;"><?= $chargeMax ? number_format($chargeMax, 0, ',', ' ') . ' kg' : '—' ?></td>
                    <td style="min-width:100px;">
                        <div class="col-jauge-wrap" style="background:#E9ECEC;border-radius:4px;height:8px;"><div class="col-jauge-fill" style="background:<?= $couleur ?>;width:<?= $pct ?>%;height:8px;border-radius:4px;"></div></div>
                        <span class="col-jauge-pct" style="font-size:10px;color:#5C6B68;"><?= $pct ?> %</span>
                    </td>
                    <td>
                        <?php if ($surcharge): ?><span class="badge badge-danger col-statut">&#9888; Surcharge</span>
                        <?php elseif ($charge > 0): ?><span class="badge badge-success col-statut">&#128666; Chargé</span>
                        <?php else: ?><span class="badge badge-muted col-statut">Vide</span><?php endif; ?>
                    </td>
                    <td style="font-size:12px;"><?= date('H:i', strtotime($c['horodatage'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($camionsActuels)): ?><tr><td colspan="8" style="text-align:center;padding:30px;color:#999;">Aucun camion minier assigné.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<div class="panel fleet-table-wrap" style="margin-bottom:16px;">
    <div class="panel-header"><h3>&#128203; Historique chargement — <?= date('d/m/Y', strtotime($date)) ?></h3></div>
    <table>
        <thead><tr><th>Véhicule</th><th>Tare</th><th>Charge max du jour</th><th>Charge moy.</th><th>Charge max autorisée</th><th>Surcharges</th></tr></thead>
        <tbody>
            <?php if (empty($historique)): ?><tr><td colspan="6" style="text-align:center;padding:30px;color:#999;">Aucune donnée.</td></tr><?php endif; ?>
            <?php foreach ($historique as $h): ?>
                <?php $surcharge = $h['poids_max_kg'] && $h['charge_max_jour'] > $h['poids_max_kg']; ?>
                <tr>
                    <td><a href="fiche_vehicule.php?id=<?= $h['id_vehicule'] ?>" style="color:#0F6E56;font-weight:600;"><?= htmlspecialchars($h['immatriculation']) ?></a></td>
                    <td style="font-size:12px;color:#5C6B68;"><?= $h['poids_tare_kg'] ? number_format($h['poids_tare_kg'], 0, ',', ' ') . ' kg' : '—' ?></td>
                    <td style="color:<?= $surcharge ? '#E24B4A' : '#1A1A1A' ?>;font-weight:600;"><?= $h['charge_max_jour'] ? number_format($h['charge_max_jour'], 0, ',', ' ') . ' kg' : '—' ?><?= $surcharge ? ' &#9888;' : '' ?></td>
                    <td><?= $h['charge_moy_jour'] ? number_format($h['charge_moy_jour'], 0, ',', ' ') . ' kg' : '—' ?></td>
                    <td style="font-size:12px;color:#5C6B68;"><?= $h['poids_max_kg'] ? number_format($h['poids_max_kg'], 0, ',', ' ') . ' kg' : '—' ?></td>
                    <td><?= $h['nb_surcharges'] > 0 ? '<span class="badge badge-danger">' . $h['nb_surcharges'] . ' surcharge(s)</span>' : '<span class="badge badge-success">Aucune</span>' ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if (!empty($alertesSurcharge)): ?>
<div class="panel fleet-table-wrap" style="margin-bottom:16px;">
    <div class="panel-header"><h3>&#9888; Alertes surcharge non résolues</h3><span class="badge badge-danger"><?= count($alertesSurcharge) ?></span></div>
    <table>
        <thead><tr><th>Véhicule</th><th>Poids mesuré</th><th>Date / Heure</th><th>Statut</th></tr></thead>
        <tbody>
            <?php foreach ($alertesSurcharge as $a): ?>
                <tr><td><?= htmlspecialchars($a['immatriculation']) ?></td><td><?= number_format($a['valeur_declenchante'], 0, ',', ' ') ?> kg</td><td style="font-size:12px;"><?= date('d/m/Y H:i', strtotime($a['horodatage'])) ?></td><td><span class="badge <?= $a['statut'] === 'non_traitee' ? 'badge-danger' : 'badge-warning' ?>"><?= $a['statut'] === 'non_traitee' ? 'Non traitée' : 'En cours' ?></span></td></tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="panel" style="padding:20px;">
    <div class="panel-header" style="margin-bottom:12px;"><h3>&#128202; Évolution du tonnage — 7 derniers jours<?php
        if ($filtreVehicule) {
            foreach ($vehiculesListe as $v) { if ($v['id_vehicule'] == $filtreVehicule) echo ' (' . htmlspecialchars($v['immatriculation']) . ')'; }
        }
    ?></h3></div>
    <canvas id="chargeChart" height="90"></canvas>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
var evolution = <?= json_encode($evolution) ?>;
new Chart(document.getElementById('chargeChart'), {
    type: 'bar',
    data: {
        labels: evolution.map(function(e) { var d = new Date(e.jour + 'T00:00:00'); return d.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' }); }),
        datasets: [
            { label: 'Tonnage total (t)', data: evolution.map(function(e) { return parseFloat(e.tonnage_total) || 0; }), backgroundColor: '#0F6E56', borderRadius: 4 },
            { label: 'Charge moyenne (t)', data: evolution.map(function(e) { return parseFloat(e.charge_moy) || 0; }), type: 'line', borderColor: '#BA7517', backgroundColor: 'transparent', tension: 0.3, pointRadius: 4 }
        ]
    },
    options: { responsive: true, interaction: { mode: 'index', intersect: false }, scales: { y: { beginAtZero: true, title: { display: true, text: 'tonnes' } } } }
});

// Polling 5s
var badge = document.getElementById('chargeBadge');
(function() {
    function rafraichir() {
        fetch('../api/telemetrie.php')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (badge) { badge.style.background = '#1D9E75'; badge.textContent = '● Temps réel — ' + data.timestamp; }
                data.vehicules.forEach(function(v) {
                    var ligne = document.querySelector('tr[data-charge-id="' + v.id_vehicule + '"]');
                    if (!ligne) return;
                    var charge = parseInt(v.poids_charge_kg) || 0;
                    var cm = ligne.querySelector('.col-moteur');
                    if (cm) { var on = v.moteur_etat === 'on'; cm.className = 'badge ' + (on ? 'badge-success' : 'badge-muted') + ' col-moteur'; cm.innerHTML = on ? '&#128268; ON' : '&#9211; OFF'; }
                    var cc = ligne.querySelector('.col-charge');
                    if (cc) cc.textContent = charge > 0 ? charge.toLocaleString('fr-FR') + ' kg' : '—';
                    var jf = ligne.querySelector('.col-jauge-fill');
                    if (jf && v.poids_max_kg) { var pct = Math.min(110, Math.round(charge / v.poids_max_kg * 100)); jf.style.width = pct + '%'; }
                    var cs = ligne.querySelector('.col-statut');
                    if (cs) {
                        var surcharge = v.poids_max_kg && charge > v.poids_max_kg;
                        cs.className = 'badge ' + (surcharge ? 'badge-danger' : (charge > 0 ? 'badge-success' : 'badge-muted')) + ' col-statut';
                        cs.innerHTML = surcharge ? '&#9888; Surcharge' : (charge > 0 ? '&#128666; Chargé' : 'Vide');
                    }
                });
            }).catch(function() { if (badge) { badge.style.background = '#BA7517'; badge.textContent = '● Reconnexion...'; } });
    }
    rafraichir();
    setInterval(rafraichir, 5000);
})();
</script>

<?php require_once '../includes/footer.php'; ?>
