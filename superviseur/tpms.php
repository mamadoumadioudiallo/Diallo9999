<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireSuperviseur();
$idSup = $_SESSION['user_id'];

$filtreVehicule = $_GET['vehicule'] ?? '';

$configStmt = $pdo->query("SELECT parametre, valeur FROM configurations WHERE parametre IN ('seuil_pression_tpms_min','seuil_pression_tpms_max','seuil_temperature_pneu')");
$config = [];
foreach ($configStmt->fetchAll() as $row) { $config[$row['parametre']] = (float) $row['valeur']; }
$seuilPMin = $config['seuil_pression_tpms_min'] ?? 6.5;
$seuilPMax = $config['seuil_pression_tpms_max'] ?? 9.0;
$seuilTMax = $config['seuil_temperature_pneu']  ?? 70;

$conditions = ["v.statut = 'actif'", "t.tpms_pression_json IS NOT NULL", "v.id_superviseur = ?"];
$params = [$idSup];
if ($filtreVehicule) { $conditions[] = "v.id_vehicule = ?"; $params[] = $filtreVehicule; }
$where = 'WHERE ' . implode(' AND ', $conditions);

$stmt = $pdo->prepare("
    SELECT v.id_vehicule, v.immatriculation, v.type, v.marque, v.modele, v.nb_roues,
           t.tpms_pression_json, t.tpms_alerte, t.horodatage
    FROM vehicules v
    INNER JOIN (SELECT t1.* FROM telemetrie t1 INNER JOIN (SELECT id_vehicule, MAX(horodatage) AS max_horo FROM telemetrie GROUP BY id_vehicule) t2 ON t1.id_vehicule = t2.id_vehicule AND t1.horodatage = t2.max_horo) t ON v.id_vehicule = t.id_vehicule
    $where ORDER BY t.tpms_alerte DESC, v.immatriculation ASC
");
$stmt->execute($params);
$vehiculesTpms = $stmt->fetchAll();

$nbAlertePression = 0; $nbAlerteTemp = 0; $nbNormaux = 0;
foreach ($vehiculesTpms as $v) {
    if (!empty($v['tpms_alerte'])) {
        $roues = json_decode($v['tpms_pression_json'], true) ?? [];
        foreach ($roues as $r) { if ($r['pression'] < $seuilPMin || $r['pression'] > $seuilPMax) { $nbAlertePression++; break; } }
        foreach ($roues as $r) { if ($r['temperature'] > $seuilTMax) { $nbAlerteTemp++; break; } }
    } else { $nbNormaux++; }
}

// Alertes non résolues
$alertesCond = ["a.type_alerte IN ('tpms_pression', 'tpms_temperature')", "a.statut != 'resolue'", "v.id_superviseur = ?"];
$alertesParams = [$idSup];
if ($filtreVehicule) { $alertesCond[] = "a.id_vehicule = ?"; $alertesParams[] = $filtreVehicule; }
$stmtA = $pdo->prepare("SELECT a.*, v.immatriculation, v.id_vehicule FROM alertes a JOIN vehicules v ON a.id_vehicule = v.id_vehicule WHERE " . implode(' AND ', $alertesCond) . " ORDER BY a.horodatage DESC LIMIT 50");
$stmtA->execute($alertesParams);
$alertesTpms = $stmtA->fetchAll();

$vehiculesListe = $pdo->prepare("SELECT id_vehicule, immatriculation FROM vehicules WHERE statut = 'actif' AND id_superviseur = ? ORDER BY immatriculation");
$vehiculesListe->execute([$idSup]);
$vehiculesListe = $vehiculesListe->fetchAll();

$pageTitle = 'TPMS — Pneus';
require_once '../includes/header.php';
?>

<div class="dash-header-row">
    <div><h1>&#127919; Suivi TPMS — Pression des pneus</h1><p class="subtitle">Mes véhicules — temps réel</p></div>
    <div class="dash-actions"><a href="dashboard.php" class="btn-dash btn-dash-outline">&#8592; Retour</a></div>
</div>

<div class="kpi-grid" style="margin-bottom:16px;">
    <div class="kpi-card <?= ($nbAlertePression + $nbAlerteTemp) > 0 ? 'danger' : '' ?>"><div class="kpi-info"><p class="label">Véhicules en alerte</p><p class="value"><?= $nbAlertePression + $nbAlerteTemp ?></p><p class="trend"><?= ($nbAlertePression + $nbAlerteTemp) > 0 ? 'Vérification requise' : 'Tout est normal' ?></p></div><div class="kpi-icon">&#9888;</div></div>
    <div class="kpi-card <?= $nbAlertePression > 0 ? 'danger' : '' ?>"><div class="kpi-info"><p class="label">Alertes pression</p><p class="value"><?= $nbAlertePression ?></p><p class="trend">Seuil : <?= $seuilPMin ?> — <?= $seuilPMax ?> bar</p></div><div class="kpi-icon">&#128246;</div></div>
    <div class="kpi-card <?= $nbAlerteTemp > 0 ? 'danger' : '' ?>"><div class="kpi-info"><p class="label">Alertes température</p><p class="value"><?= $nbAlerteTemp ?></p><p class="trend">Seuil : <?= $seuilTMax ?> °C</p></div><div class="kpi-icon">&#127777;</div></div>
    <div class="kpi-card"><div class="kpi-info"><p class="label">Véhicules normaux</p><p class="value" style="color:#1D9E75;"><?= $nbNormaux ?></p><p class="trend">Pression dans les normes</p></div><div class="kpi-icon">&#9989;</div></div>
</div>

<div class="panel" style="padding:16px 20px;margin-bottom:16px;">
    <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
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
        <a href="tpms.php" class="btn-dash btn-dash-outline" style="height:38px;line-height:22px;">Réinitialiser</a>
    </form>
</div>

<div class="panel fleet-table-wrap" style="margin-bottom:16px;">
    <div class="panel-header">
        <h3>&#127919; État des pneus par véhicule (temps réel)</h3>
        <span id="tpmsBadge" style="font-size:12px;color:#fff;background:#999;padding:3px 10px;border-radius:12px;">● Connexion...</span>
    </div>
    <table>
        <thead><tr><th>Véhicule</th><th>Type</th><th>Statut</th><th>Détail par roue</th><th>Dernier relevé</th></tr></thead>
        <tbody>
            <?php foreach ($vehiculesTpms as $v): ?>
                <?php $roues = !empty($v['tpms_pression_json']) ? json_decode($v['tpms_pression_json'], true) : []; $alerte = !empty($v['tpms_alerte']); ?>
                <tr data-tpms-id="<?= $v['id_vehicule'] ?>" style="background:<?= $alerte ? '#FFF8F8' : '' ?>;">
                    <td><a href="fiche_vehicule.php?id=<?= $v['id_vehicule'] ?>" style="color:#0F6E56;font-weight:600;"><?= htmlspecialchars($v['immatriculation']) ?></a><br><span style="font-size:11px;color:#999;"><?= htmlspecialchars($v['marque'] . ' ' . $v['modele']) ?></span></td>
                    <td><?= ucfirst($v['type']) ?></td>
                    <td><span class="badge <?= $alerte ? 'badge-danger' : 'badge-success' ?> tpms-statut-badge"><?= $alerte ? '&#9888; Anomalie' : '&#9989; Normal' ?></span></td>
                    <td class="tpms-roues">
                        <?php if (!empty($roues)): ?>
                            <div style="display:flex;flex-wrap:wrap;gap:6px;">
                                <?php foreach ($roues as $roue): ?>
                                    <?php $p = (float)$roue['pression']; $t = (float)($roue['temperature']??0); $ok = $p>=$seuilPMin && $p<=$seuilPMax && $t<=$seuilTMax; $c = $ok?'#1D9E75':'#E24B4A'; ?>
                                    <div style="background:#F5F7F6;border:1px solid <?= $c ?>;border-radius:6px;padding:4px 8px;text-align:center;min-width:52px;">
                                        <p style="font-size:10px;color:#999;margin-bottom:1px;">R<?= $roue['roue'] ?></p>
                                        <p style="font-size:12px;font-weight:700;color:<?= $c ?>;margin-bottom:1px;"><?= $p ?> b</p>
                                        <p style="font-size:10px;color:#5C6B68;"><?= $t ?>°C</p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?><span style="color:#999;font-size:12px;">—</span><?php endif; ?>
                    </td>
                    <td style="font-size:12px;"><?= date('H:i', strtotime($v['horodatage'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($vehiculesTpms)): ?><tr><td colspan="5" style="text-align:center;padding:30px;color:#999;">Aucune donnée TPMS.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<?php if (!empty($alertesTpms)): ?>
<div class="panel fleet-table-wrap">
    <div class="panel-header"><h3>&#128276; Alertes TPMS non résolues</h3><span class="badge badge-danger"><?= count($alertesTpms) ?></span></div>
    <table>
        <thead><tr><th>Véhicule</th><th>Type alerte</th><th>Valeur</th><th>Date / Heure</th><th>Statut</th></tr></thead>
        <tbody>
            <?php foreach ($alertesTpms as $a): ?>
                <?php $sb = ['non_traitee'=>'badge-danger','en_cours'=>'badge-warning']; ?>
                <tr>
                    <td><a href="fiche_vehicule.php?id=<?= $a['id_vehicule'] ?>" style="color:#0F6E56;font-weight:600;"><?= htmlspecialchars($a['immatriculation']) ?></a></td>
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

<script>
var badge = document.getElementById('tpmsBadge');
(function() {
    function rafraichir() {
        fetch('../api/tpms.php')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (badge) { badge.style.background = '#1D9E75'; badge.textContent = '● Temps réel — ' + data.timestamp; }
                data.vehicules.forEach(function(v) {
                    var ligne = document.querySelector('tr[data-tpms-id="' + v.id_vehicule + '"]');
                    if (!ligne) return;
                    var b = ligne.querySelector('.tpms-statut-badge');
                    if (b) { b.className = 'badge ' + (v.tpms_alerte ? 'badge-danger' : 'badge-success') + ' tpms-statut-badge'; b.innerHTML = v.tpms_alerte ? '&#9888; Anomalie' : '&#9989; Normal'; }
                    ligne.style.background = v.tpms_alerte ? '#FFF8F8' : '';
                    var cr = ligne.querySelector('.tpms-roues');
                    if (cr && v.roues.length > 0) {
                        cr.innerHTML = '<div style="display:flex;flex-wrap:wrap;gap:6px;">' + v.roues.map(function(r) {
                            var c = r.ok ? '#1D9E75' : '#E24B4A';
                            return '<div style="background:#F5F7F6;border:1px solid '+c+';border-radius:6px;padding:4px 8px;text-align:center;min-width:52px;display:inline-block;margin:2px;"><p style="font-size:10px;color:#999;margin-bottom:1px;">R'+r.roue+'</p><p style="font-size:12px;font-weight:700;color:'+c+';margin-bottom:1px;">'+r.pression+' b</p><p style="font-size:10px;color:#5C6B68;">'+r.temperature+'°C</p></div>';
                        }).join('') + '</div>';
                    }
                });
            }).catch(function() { if (badge) { badge.style.background = '#BA7517'; badge.textContent = '● Reconnexion...'; } });
    }
    rafraichir();
    setInterval(rafraichir, 5000);
})();
</script>

<?php require_once '../includes/footer.php'; ?>
