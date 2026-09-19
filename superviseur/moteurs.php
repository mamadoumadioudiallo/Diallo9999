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
$etatCond = ["v.statut = 'actif'", "v.id_superviseur = ?"];
$etatParams = [$idSup];
if ($filtreVehicule) { $etatCond[] = "v.id_vehicule = ?"; $etatParams[] = $filtreVehicule; }

$stmt = $pdo->prepare("
    SELECT v.id_vehicule, v.immatriculation, v.type, v.marque, v.modele,
           t.moteur_etat, t.vitesse, t.temperature_moteur, t.horodatage
    FROM vehicules v
    INNER JOIN (SELECT t1.* FROM telemetrie t1 INNER JOIN (SELECT id_vehicule, MAX(horodatage) AS max_horo FROM telemetrie GROUP BY id_vehicule) t2 ON t1.id_vehicule = t2.id_vehicule AND t1.horodatage = t2.max_horo) t ON v.id_vehicule = t.id_vehicule
    WHERE " . implode(' AND ', $etatCond) . " ORDER BY t.moteur_etat DESC
");
$stmt->execute($etatParams);
$etatActuel = $stmt->fetchAll();

$nbOn  = count(array_filter($etatActuel, fn($v) => $v['moteur_etat'] === 'on'));
$nbOff = count(array_filter($etatActuel, fn($v) => $v['moteur_etat'] === 'off'));

// Historique
$conditions = ["DATE(t.horodatage) = ?", "v.id_superviseur = ?"];
$params = [$date, $idSup];
if ($filtreVehicule) { $conditions[] = "v.id_vehicule = ?"; $params[] = $filtreVehicule; }
$where = 'WHERE ' . implode(' AND ', $conditions);

$stmt = $pdo->prepare("
    SELECT v.id_vehicule, v.immatriculation, v.type, v.marque, v.modele,
           COUNT(CASE WHEN t.moteur_etat = 'on' THEN 1 END) AS releves_on,
           COUNT(CASE WHEN t.moteur_etat = 'off' THEN 1 END) AS releves_off,
           COUNT(*) AS total_releves,
           MIN(t.horodatage) AS premier_releve, MAX(t.horodatage) AS dernier_releve
    FROM vehicules v JOIN telemetrie t ON v.id_vehicule = t.id_vehicule
    $where
    GROUP BY v.id_vehicule, v.immatriculation, v.type, v.marque, v.modele
    ORDER BY releves_on DESC
");
$stmt->execute($params);
$heuresMoteur = $stmt->fetchAll();

// Évolution 7 jours
$evolCond = ["t.horodatage >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)", "v.id_superviseur = ?"];
$evolParams = [$idSup];
if ($filtreVehicule) { $evolCond[] = "v.id_vehicule = ?"; $evolParams[] = $filtreVehicule; }

$stmt = $pdo->prepare("
    SELECT DATE(t.horodatage) AS jour,
           COUNT(CASE WHEN t.moteur_etat = 'on' THEN 1 END) AS nb_on,
           COUNT(CASE WHEN t.moteur_etat = 'off' THEN 1 END) AS nb_off
    FROM telemetrie t JOIN vehicules v ON t.id_vehicule = v.id_vehicule
    WHERE " . implode(' AND ', $evolCond) . "
    GROUP BY DATE(t.horodatage) ORDER BY jour ASC
");
$stmt->execute($evolParams);
$evolution = $stmt->fetchAll();

$vehiculesListe = $pdo->prepare("SELECT id_vehicule, immatriculation FROM vehicules WHERE statut = 'actif' AND id_superviseur = ? ORDER BY immatriculation");
$vehiculesListe->execute([$idSup]);
$vehiculesListe = $vehiculesListe->fetchAll();

$pageTitle = 'État des moteurs';
require_once '../includes/header.php';
?>

<div class="dash-header-row">
    <div><h1>&#128268; État des moteurs</h1><p class="subtitle">Mes véhicules — temps réel</p></div>
    <div class="dash-actions"><a href="dashboard.php" class="btn-dash btn-dash-outline">&#8592; Retour</a></div>
</div>

<div class="kpi-grid" style="margin-bottom:16px;">
    <div class="kpi-card"><div class="kpi-info"><p class="label">Moteurs allumés</p><p class="value" style="color:#1D9E75;"><?= $nbOn ?></p><p class="trend">En ce moment</p></div><div class="kpi-icon">&#128268;</div></div>
    <div class="kpi-card"><div class="kpi-info"><p class="label">Moteurs éteints</p><p class="value" style="color:#5C6B68;"><?= $nbOff ?></p><p class="trend">En ce moment</p></div><div class="kpi-icon">&#9211;</div></div>
    <div class="kpi-card"><div class="kpi-info"><p class="label">Total mes véhicules</p><p class="value"><?= count($etatActuel) ?></p><p class="trend">Sous ma supervision</p></div><div class="kpi-icon">&#128666;</div></div>
    <div class="kpi-card"><div class="kpi-info"><p class="label">Taux d'activité</p><p class="value"><?= count($etatActuel) > 0 ? round($nbOn / count($etatActuel) * 100) : 0 ?> %</p><p class="trend">Moteurs ON / Total</p></div><div class="kpi-icon">&#128200;</div></div>
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
        <a href="moteurs.php" class="btn-dash btn-dash-outline" style="height:38px;line-height:22px;">Réinitialiser</a>
    </form>
</div>

<div class="panel fleet-table-wrap" style="margin-bottom:16px;">
    <div class="panel-header"><h3>&#128268; État actuel (temps réel)</h3></div>
    <table>
        <thead><tr><th>Véhicule</th><th>Type</th><th>Moteur</th><th>Vitesse</th><th>Température</th><th>Relevé</th></tr></thead>
        <tbody id="tableauMoteurs">
            <?php foreach ($etatActuel as $v): ?>
                <?php $on = $v['moteur_etat'] === 'on'; ?>
                <tr data-vehicule-id="<?= $v['id_vehicule'] ?>">
                    <td><a href="fiche_vehicule.php?id=<?= $v['id_vehicule'] ?>" style="color:#0F6E56;font-weight:600;"><?= htmlspecialchars($v['immatriculation']) ?></a><br><span style="font-size:11px;color:#999;"><?= htmlspecialchars($v['marque'] . ' ' . $v['modele']) ?></span></td>
                    <td><?= ucfirst($v['type']) ?></td>
                    <td><span class="badge <?= $on ? 'badge-success' : 'badge-muted' ?> moteur-badge"><?= $on ? '&#128268; ON' : '&#9211; OFF' ?></span></td>
                    <td class="col-vitesse"><?= round($v['vitesse']) ?> km/h</td>
                    <td class="col-temp" style="color:<?= $v['temperature_moteur'] > 95 ? '#E24B4A' : '#1A1A1A' ?>;"><?= round($v['temperature_moteur']) ?> °C</td>
                    <td style="font-size:12px;"><?= date('H:i:s', strtotime($v['horodatage'])) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="panel fleet-table-wrap" style="margin-bottom:16px;">
    <div class="panel-header"><h3>&#128203; Activité moteur — <?= date('d/m/Y', strtotime($date)) ?></h3></div>
    <table>
        <thead><tr><th>Véhicule</th><th>Type</th><th>Relevés ON</th><th>Relevés OFF</th><th>Taux activité</th><th>Jauge</th></tr></thead>
        <tbody>
            <?php if (empty($heuresMoteur)): ?><tr><td colspan="6" style="text-align:center;padding:30px;color:#999;">Aucune donnée.</td></tr><?php endif; ?>
            <?php foreach ($heuresMoteur as $v): ?>
                <?php $taux = $v['total_releves'] > 0 ? round($v['releves_on'] / $v['total_releves'] * 100) : 0; $c = $taux > 70 ? '#1D9E75' : ($taux > 30 ? '#BA7517' : '#E24B4A'); ?>
                <tr>
                    <td><a href="fiche_vehicule.php?id=<?= $v['id_vehicule'] ?>" style="color:#0F6E56;font-weight:600;"><?= htmlspecialchars($v['immatriculation']) ?></a></td>
                    <td><?= ucfirst($v['type']) ?></td>
                    <td style="color:#1D9E75;font-weight:600;"><?= $v['releves_on'] ?></td>
                    <td style="color:#5C6B68;"><?= $v['releves_off'] ?></td>
                    <td style="font-weight:600;color:<?= $c ?>;"><?= $taux ?> %</td>
                    <td style="min-width:100px;"><div style="background:#E9ECEC;border-radius:4px;height:8px;"><div style="background:<?= $c ?>;width:<?= $taux ?>%;height:8px;border-radius:4px;"></div></div></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="panel" style="padding:20px;">
    <div class="panel-header" style="margin-bottom:12px;"><h3>&#128202; Activité moteurs — 7 derniers jours<?php
        if ($filtreVehicule) {
            foreach ($vehiculesListe as $v) { if ($v['id_vehicule'] == $filtreVehicule) echo ' (' . htmlspecialchars($v['immatriculation']) . ')'; }
        }
    ?></h3></div>
    <canvas id="moteursChart" height="80"></canvas>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
var evolution = <?= json_encode($evolution) ?>;
new Chart(document.getElementById('moteursChart'), {
    type: 'bar',
    data: {
        labels: evolution.map(function(e) { var d = new Date(e.jour + 'T00:00:00'); return d.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' }); }),
        datasets: [
            { label: 'Relevés ON', data: evolution.map(function(e) { return parseInt(e.nb_on) || 0; }), backgroundColor: '#1D9E75', borderRadius: 3 },
            { label: 'Relevés OFF', data: evolution.map(function(e) { return parseInt(e.nb_off) || 0; }), backgroundColor: '#E9ECEC', borderRadius: 3 }
        ]
    },
    options: { responsive: true, scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true } } }
});

// Polling 5s
(function() {
    function rafraichir() {
        fetch('../api/telemetrie.php')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                data.vehicules.forEach(function(v) {
                    var ligne = document.querySelector('tr[data-vehicule-id="' + v.id_vehicule + '"]');
                    if (!ligne) return;
                    var badge = ligne.querySelector('.moteur-badge');
                    var on = v.moteur_etat === 'on';
                    if (badge) { badge.className = 'badge ' + (on ? 'badge-success' : 'badge-muted') + ' moteur-badge'; badge.innerHTML = on ? '&#128268; ON' : '&#9211; OFF'; }
                    var cv = ligne.querySelector('.col-vitesse'); if (cv) cv.textContent = Math.round(v.vitesse) + ' km/h';
                    var ct = ligne.querySelector('.col-temp'); if (ct) { ct.textContent = Math.round(v.temperature_moteur) + ' °C'; ct.style.color = v.temperature_moteur > 95 ? '#E24B4A' : '#1A1A1A'; }
                });
            });
    }
    rafraichir();
    setInterval(rafraichir, 5000);
})();
</script>

<?php require_once '../includes/footer.php'; ?>
