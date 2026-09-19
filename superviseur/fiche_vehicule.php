<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : superviseur/fiche_vehicule.php
// Rôle    : Fiche véhicule en lecture seule pour le superviseur
//           Infos opérationnelles uniquement — pas d'infos
//           confidentielles (investisseur, contrat, clé API)
// ============================================================

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireSuperviseur();
$idSup = $_SESSION['user_id'];

$idVehicule = (int) ($_GET['id'] ?? 0);

// Vérifier que ce véhicule appartient bien à ce superviseur
$stmt = $pdo->prepare("
    SELECT v.*, u.nom AS sup_nom, u.prenom AS sup_prenom
    FROM vehicules v
    LEFT JOIN utilisateurs u ON v.id_superviseur = u.id_user
    WHERE v.id_vehicule = ? AND v.id_superviseur = ?
");
$stmt->execute([$idVehicule, $idSup]);
$vehicule = $stmt->fetch();

if (!$vehicule) {
    header('Location: mes_vehicules.php');
    exit();
}

// ====== STATISTIQUES GLOBALES ======
$stmt = $pdo->prepare("
    SELECT MIN(kilometrage) AS km_min, MAX(kilometrage) AS km_max,
           AVG(vitesse) AS vitesse_moy, AVG(temperature_moteur) AS temp_moy,
           COUNT(*) AS nb_releves
    FROM telemetrie WHERE id_vehicule = ?
");
$stmt->execute([$idVehicule]);
$statsGlobales = $stmt->fetch();
$kmTotal = ($statsGlobales['km_min'] !== null) ? $statsGlobales['km_max'] - $statsGlobales['km_min'] : 0;

$stmt = $pdo->prepare("SELECT COUNT(*) FROM missions WHERE id_vehicule = ?");
$stmt->execute([$idVehicule]);
$nbMissions = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM alertes WHERE id_vehicule = ? AND statut != 'resolue'");
$stmt->execute([$idVehicule]);
$nbAlertesActives = $stmt->fetchColumn();

// ====== DERNIÈRE TÉLÉMÉTRIE ======
$stmt = $pdo->prepare("SELECT * FROM telemetrie WHERE id_vehicule = ? ORDER BY horodatage DESC LIMIT 1");
$stmt->execute([$idVehicule]);
$dernierReleve = $stmt->fetch();

// ====== TPMS ======
$tpmsRoues = [];
$seuilPMin = 6.5; $seuilPMax = 9.0; $seuilTMax = 70;
if ($dernierReleve && $dernierReleve['tpms_pression_json']) {
    $tpmsRoues = json_decode($dernierReleve['tpms_pression_json'], true) ?? [];
    $cfg = $pdo->query("SELECT parametre, valeur FROM configurations WHERE parametre IN ('seuil_pression_tpms_min','seuil_pression_tpms_max','seuil_temperature_pneu')")->fetchAll();
    foreach ($cfg as $c) { if ($c['parametre'] === 'seuil_pression_tpms_min') $seuilPMin = (float)$c['valeur']; if ($c['parametre'] === 'seuil_pression_tpms_max') $seuilPMax = (float)$c['valeur']; if ($c['parametre'] === 'seuil_temperature_pneu') $seuilTMax = (float)$c['valeur']; }
}

// ====== STATS POIDS (minier) ======
$statsPoidsCharge = null;
if ($vehicule['type'] === 'minier') {
    $stmt = $pdo->prepare("SELECT AVG(poids_charge_kg) AS poids_moy, MAX(poids_charge_kg) AS poids_max, COUNT(CASE WHEN poids_charge_kg > 0 THEN 1 END) AS nb_chargements FROM telemetrie WHERE id_vehicule = ? AND poids_charge_kg IS NOT NULL AND horodatage >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $stmt->execute([$idVehicule]);
    $statsPoidsCharge = $stmt->fetch();
}

// ====== HISTORIQUE MISSIONS ======
$stmt = $pdo->prepare("
    SELECT m.*, c.nom AS c_nom, c.prenom AS c_prenom
    FROM missions m JOIN conducteurs c ON m.id_conducteur = c.id_conducteur
    WHERE m.id_vehicule = ? ORDER BY m.date_debut DESC LIMIT 20
");
$stmt->execute([$idVehicule]);
$missions = $stmt->fetchAll();

// ====== ALERTES ACTIVES ======
$libellesAlertes = ['vitesse_excessive'=>'Vitesse excessive','temperature_critique'=>'Température critique','carburant_bas'=>'Carburant bas','hors_zone'=>'Hors zone','surcharge'=>'Surcharge','tpms_pression'=>'Pression pneu (TPMS)','tpms_temperature'=>'Température pneu (TPMS)','moteur_anomalie'=>'Anomalie moteur'];
$stmt = $pdo->prepare("SELECT * FROM alertes WHERE id_vehicule = ? AND statut != 'resolue' ORDER BY horodatage DESC LIMIT 20");
$stmt->execute([$idVehicule]);
$alertes = $stmt->fetchAll();

// ====== TÉLÉMÉTRIE 24H ======
$stmt = $pdo->prepare("SELECT horodatage, vitesse, carburant, temperature_moteur, poids_charge_kg, moteur_etat FROM telemetrie WHERE id_vehicule = ? AND horodatage >= DATE_SUB(NOW(), INTERVAL 24 HOUR) ORDER BY horodatage ASC");
$stmt->execute([$idVehicule]);
$telemetrieRecente = $stmt->fetchAll();

$pageTitle = 'Fiche — ' . $vehicule['immatriculation'];
require_once '../includes/header.php';
?>

<div class="dash-header-row">
    <div>
        <h1>&#128666; <?= htmlspecialchars($vehicule['immatriculation']) ?></h1>
        <p class="subtitle"><?= htmlspecialchars($vehicule['marque'] . ' ' . $vehicule['modele']) ?> (<?= $vehicule['annee'] ?>) — <?= ucfirst($vehicule['type']) ?></p>
    </div>
    <div class="dash-actions">
        <a href="mes_vehicules.php" class="btn-dash btn-dash-outline">&#8592; Mes véhicules</a>
    </div>
</div>

<!-- Layout 2 colonnes -->
<div style="display:grid;grid-template-columns:1fr 2fr;gap:16px;margin-bottom:16px;">

    <!-- Colonne gauche : photo + état moteur + TPMS -->
    <div style="display:flex;flex-direction:column;gap:16px;">

        <!-- Photo + infos de base -->
        <div class="panel" style="padding:20px;text-align:center;">
            <?php if (!empty($vehicule['photo'])): ?>
                <img src="../<?= htmlspecialchars($vehicule['photo']) ?>" alt="Photo" style="width:100%;max-height:180px;object-fit:cover;border-radius:8px;margin-bottom:12px;">
            <?php else: ?>
                <div style="background:#F0F4F2;border-radius:8px;height:140px;display:flex;align-items:center;justify-content:center;margin-bottom:12px;">
                    <svg width="64" height="64" viewBox="0 0 64 64" fill="none"><rect x="4" y="20" width="56" height="28" rx="6" fill="#C8D8D2"/><rect x="12" y="12" width="32" height="18" rx="4" fill="#A0B8B0"/><circle cx="16" cy="50" r="6" fill="#5C6B68"/><circle cx="48" cy="50" r="6" fill="#5C6B68"/><rect x="28" y="30" width="8" height="6" rx="1" fill="#7AAAA0"/></svg>
                </div>
            <?php endif; ?>
            <p style="font-size:13px;color:#5C6B68;margin-bottom:4px;"><?= htmlspecialchars($vehicule['marque'] . ' ' . $vehicule['modele']) ?></p>
            <p style="font-size:12px;color:#999;"><?= $vehicule['annee'] ?> — <?= ucfirst($vehicule['type']) ?></p>
            <p style="font-size:12px;color:#999;margin-top:4px;">Capacité : <?= $vehicule['capacite_carburant'] ?> L</p>
            <?php if ($vehicule['type'] === 'minier' && $vehicule['nb_roues']): ?>
                <p style="font-size:12px;color:#999;"><?= $vehicule['nb_roues'] ?> roues</p>
            <?php endif; ?>
        </div>

        <!-- État moteur temps réel -->
        <div class="panel" style="padding:20px;">
            <h3 style="margin-bottom:12px;">&#128268; État moteur</h3>
            <?php if ($dernierReleve): ?>
                <?php $moteurOn = $dernierReleve['moteur_etat'] === 'on'; ?>
                <div style="text-align:center;margin-bottom:16px;">
                    <span class="badge <?= $moteurOn ? 'badge-success' : 'badge-muted' ?>" style="font-size:16px;padding:10px 20px;">
                        <?= $moteurOn ? '&#128268; Moteur ON' : '&#9211; Moteur OFF' ?>
                    </span>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:13px;">
                    <div style="background:#F5F7F6;border-radius:6px;padding:10px;text-align:center;">
                        <p style="color:#5C6B68;font-size:11px;margin-bottom:4px;">Vitesse</p>
                        <p style="font-weight:700;color:<?= $dernierReleve['vitesse'] > 80 ? '#E24B4A' : '#1A1A1A' ?>;"><?= round($dernierReleve['vitesse']) ?> km/h</p>
                    </div>
                    <div style="background:#F5F7F6;border-radius:6px;padding:10px;text-align:center;">
                        <p style="color:#5C6B68;font-size:11px;margin-bottom:4px;">Température</p>
                        <p style="font-weight:700;color:<?= $dernierReleve['temperature_moteur'] > 95 ? '#E24B4A' : '#1A1A1A' ?>;"><?= round($dernierReleve['temperature_moteur']) ?> °C</p>
                    </div>
                    <div style="background:#F5F7F6;border-radius:6px;padding:10px;text-align:center;">
                        <p style="color:#5C6B68;font-size:11px;margin-bottom:4px;">Carburant</p>
                        <p style="font-weight:700;color:<?= $dernierReleve['carburant'] < 10 ? '#E24B4A' : '#1A1A1A' ?>;"><?= round($dernierReleve['carburant']) ?> %</p>
                    </div>
                    <div style="background:#F5F7F6;border-radius:6px;padding:10px;text-align:center;">
                        <p style="color:#5C6B68;font-size:11px;margin-bottom:4px;">Relevé</p>
                        <p style="font-weight:700;font-size:11px;"><?= date('H:i', strtotime($dernierReleve['horodatage'])) ?></p>
                    </div>
                </div>
            <?php else: ?>
                <p style="color:#999;text-align:center;">Aucun relevé disponible</p>
            <?php endif; ?>
        </div>

        <!-- TPMS — miniers uniquement -->
        <?php if ($vehicule['type'] === 'minier' && !empty($tpmsRoues)): ?>
        <div class="panel" style="padding:20px;">
            <h3 style="margin-bottom:12px;">&#127919; Pression des pneus (TPMS)</h3>
            <div style="display:flex;flex-wrap:wrap;gap:8px;justify-content:center;">
                <?php foreach ($tpmsRoues as $roue): ?>
                    <?php $p=(float)$roue['pression']; $t=(float)($roue['temperature']??0); $ok=$p>=$seuilPMin&&$p<=$seuilPMax&&$t<=$seuilTMax; $c=$ok?'#1D9E75':'#E24B4A'; ?>
                    <div style="background:#F5F7F6;border:2px solid <?= $c ?>;border-radius:8px;padding:8px 12px;text-align:center;min-width:60px;">
                        <p style="font-size:11px;color:#999;margin-bottom:2px;">R<?= $roue['roue'] ?></p>
                        <p style="font-size:14px;font-weight:700;color:<?= $c ?>;margin-bottom:2px;"><?= $p ?> b</p>
                        <p style="font-size:11px;color:#5C6B68;"><?= $t ?>°C</p>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ($dernierReleve['tpms_alerte']): ?>
                <p style="color:#E24B4A;font-size:12px;margin-top:10px;text-align:center;">&#9888; Anomalie détectée sur un ou plusieurs pneus</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div>

    <!-- Colonne droite : KPI + chargement minier -->
    <div style="display:flex;flex-direction:column;gap:16px;">

        <!-- KPI vue d'ensemble -->
        <div class="panel" style="padding:20px;">
            <h3 style="margin-bottom:16px;">&#128200; Vue d'ensemble</h3>
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;">
                <div style="background:#F5F7F6;border-radius:8px;padding:14px;text-align:center;">
                    <p style="font-size:11px;color:#5C6B68;margin-bottom:4px;">Km total</p>
                    <p style="font-size:20px;font-weight:700;"><?= number_format($kmTotal, 0, ',', ' ') ?></p>
                    <p style="font-size:10px;color:#999;">km</p>
                </div>
                <div style="background:#F5F7F6;border-radius:8px;padding:14px;text-align:center;">
                    <p style="font-size:11px;color:#5C6B68;margin-bottom:4px;">Vitesse moy.</p>
                    <p style="font-size:20px;font-weight:700;"><?= round($statsGlobales['vitesse_moy'] ?? 0) ?></p>
                    <p style="font-size:10px;color:#999;">km/h</p>
                </div>
                <div style="background:#F5F7F6;border-radius:8px;padding:14px;text-align:center;">
                    <p style="font-size:11px;color:#5C6B68;margin-bottom:4px;">Temp. moy.</p>
                    <p style="font-size:20px;font-weight:700;color:<?= ($statsGlobales['temp_moy']??0) > 95 ? '#E24B4A' : '#1A1A1A' ?>;"><?= round($statsGlobales['temp_moy'] ?? 0) ?></p>
                    <p style="font-size:10px;color:#999;">°C</p>
                </div>
                <div style="background:#F5F7F6;border-radius:8px;padding:14px;text-align:center;">
                    <p style="font-size:11px;color:#5C6B68;margin-bottom:4px;">Missions</p>
                    <p style="font-size:20px;font-weight:700;"><?= $nbMissions ?></p>
                    <p style="font-size:10px;color:#999;">total</p>
                </div>
                <div style="background:#F5F7F6;border-radius:8px;padding:14px;text-align:center;">
                    <p style="font-size:11px;color:#5C6B68;margin-bottom:4px;">Alertes actives</p>
                    <p style="font-size:20px;font-weight:700;color:<?= $nbAlertesActives > 0 ? '#E24B4A' : '#1D9E75' ?>;"><?= $nbAlertesActives ?></p>
                    <p style="font-size:10px;color:#999;">non résolues</p>
                </div>
                <div style="background:#F5F7F6;border-radius:8px;padding:14px;text-align:center;">
                    <p style="font-size:11px;color:#5C6B68;margin-bottom:4px;">Relevés</p>
                    <p style="font-size:20px;font-weight:700;"><?= number_format($statsGlobales['nb_releves'] ?? 0, 0, ',', ' ') ?></p>
                    <p style="font-size:10px;color:#999;">IoT</p>
                </div>
            </div>
        </div>

        <!-- Section chargement minier -->
        <?php if ($vehicule['type'] === 'minier' && $dernierReleve): ?>
        <div class="panel" style="padding:20px;">
            <h3 style="margin-bottom:16px;">&#9878; Chargement minier</h3>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:16px;">
                <div style="background:#F5F7F6;border-radius:8px;padding:14px;text-align:center;">
                    <p style="font-size:11px;color:#5C6B68;margin-bottom:4px;">Tare (poids à vide)</p>
                    <p style="font-size:18px;font-weight:700;"><?= $vehicule['poids_tare_kg'] ? number_format($vehicule['poids_tare_kg'], 0, ',', ' ') . ' kg' : '—' ?></p>
                </div>
                <div style="background:#F5F7F6;border-radius:8px;padding:14px;text-align:center;">
                    <p style="font-size:11px;color:#5C6B68;margin-bottom:4px;">Charge nette actuelle</p>
                    <?php $chargeNette = $dernierReleve['poids_charge_kg']; ?>
                    <p style="font-size:18px;font-weight:700;color:<?= $chargeNette > 0 ? '#0F6E56' : '#5C6B68' ?>;"><?= $chargeNette > 0 ? number_format($chargeNette, 0, ',', ' ') . ' kg' : '—' ?></p>
                </div>
                <div style="background:#F5F7F6;border-radius:8px;padding:14px;text-align:center;">
                    <p style="font-size:11px;color:#5C6B68;margin-bottom:4px;">Charge max autorisée</p>
                    <p style="font-size:18px;font-weight:700;"><?= $vehicule['poids_max_kg'] ? number_format($vehicule['poids_max_kg'], 0, ',', ' ') . ' kg' : '—' ?></p>
                </div>
            </div>
            <?php if ($vehicule['poids_max_kg'] && $chargeNette > 0): ?>
                <?php $pct = min(110, round($chargeNette / $vehicule['poids_max_kg'] * 100)); $couleur = $pct > 100 ? '#E24B4A' : ($pct > 85 ? '#BA7517' : '#1D9E75'); ?>
                <div style="background:#E9ECEC;border-radius:6px;height:12px;margin-bottom:6px;">
                    <div style="background:<?= $couleur ?>;width:<?= $pct ?>%;height:12px;border-radius:6px;"></div>
                </div>
                <p style="font-size:12px;color:<?= $couleur ?>;font-weight:600;"><?= $pct ?>% de la capacité<?= $pct > 100 ? ' ⚠️ SURCHARGE' : '' ?></p>
            <?php endif; ?>
            <?php if ($statsPoidsCharge): ?>
                <div style="margin-top:12px;padding-top:12px;border-top:1px solid #E9ECEC;display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;font-size:12px;text-align:center;">
                    <div><p style="color:#5C6B68;">Charge moy. (30j)</p><p style="font-weight:600;"><?= round($statsPoidsCharge['poids_moy'] ?? 0) ?> kg</p></div>
                    <div><p style="color:#5C6B68;">Charge max (30j)</p><p style="font-weight:600;"><?= round($statsPoidsCharge['poids_max'] ?? 0) ?> kg</p></div>
                    <div><p style="color:#5C6B68;">Chargements (30j)</p><p style="font-weight:600;"><?= $statsPoidsCharge['nb_chargements'] ?? 0 ?></p></div>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Alertes actives -->
        <?php if (!empty($alertes)): ?>
        <div class="panel" style="padding:20px;">
            <h3 style="margin-bottom:12px;">&#128276; Alertes actives
                <span class="badge badge-danger" style="margin-left:8px;font-size:12px;"><?= count($alertes) ?></span>
            </h3>
            <div style="display:flex;flex-direction:column;gap:8px;">
                <?php foreach ($alertes as $a): ?>
                    <?php
                        $isWarning = in_array($a['type_alerte'], ['carburant_bas','tpms_pression','tpms_temperature']);
                        $bg = $isWarning ? '#FFFBF0' : '#FFF8F8';
                        $border = $isWarning ? '#BA7517' : '#E24B4A';
                        $icon = [
                            'vitesse_excessive'    => '&#128225;',
                            'temperature_critique' => '&#127777;',
                            'carburant_bas'        => '&#128167;',
                            'hors_zone'            => '&#128205;',
                            'surcharge'            => '&#9878;',
                            'tpms_pression'        => '&#127919;',
                            'tpms_temperature'     => '&#127777;',
                            'moteur_anomalie'      => '&#128268;',
                        ][$a['type_alerte']] ?? '&#9888;';
                    ?>
                    <div style="background:<?= $bg ?>;border-left:3px solid <?= $border ?>;border-radius:6px;padding:10px 14px;display:flex;align-items:center;justify-content:space-between;gap:12px;">
                        <div style="display:flex;align-items:center;gap:10px;">
                            <span style="font-size:18px;"><?= $icon ?></span>
                            <div>
                                <p style="font-weight:600;font-size:13px;margin-bottom:2px;"><?= htmlspecialchars($libellesAlertes[$a['type_alerte']] ?? $a['type_alerte']) ?></p>
                                <p style="font-size:12px;color:#5C6B68;"><?= date('d/m/Y H:i', strtotime($a['horodatage'])) ?></p>
                            </div>
                        </div>
                        <div style="text-align:right;">
                            <p style="font-weight:700;font-size:14px;color:<?= $border ?>;"><?= htmlspecialchars(formaterValeurAlerte($a['type_alerte'], $a['valeur_declenchante'])) ?></p>
                            <span class="badge <?= $a['statut'] === 'non_traitee' ? 'badge-danger' : 'badge-warning' ?>" style="font-size:11px;"><?= $a['statut'] === 'non_traitee' ? 'Non traitée' : 'En cours' ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<!-- Graphiques télémétrie 24h -->
<?php if (!empty($telemetrieRecente)): ?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
    <div class="panel" style="padding:20px;">
        <div class="panel-header" style="margin-bottom:12px;"><h3>&#128200; Vitesse — 24 dernières heures</h3></div>
        <canvas id="chartVitesse" height="120"></canvas>
    </div>
    <div class="panel" style="padding:20px;">
        <div class="panel-header" style="margin-bottom:12px;"><h3>&#128167; Carburant — 24 dernières heures</h3></div>
        <canvas id="chartCarburant" height="120"></canvas>
    </div>
</div>
<?php endif; ?>

<!-- Historique missions -->
<?php if (!empty($missions)): ?>
<div class="panel fleet-table-wrap" style="margin-bottom:16px;">
    <div class="panel-header"><h3>&#128203; Historique des missions</h3></div>
    <table>
        <thead><tr><th>Conducteur</th><th>Départ</th><th>Destination</th><th>Date début</th><th>Date fin</th><th>Statut</th></tr></thead>
        <tbody>
            <?php foreach ($missions as $m): ?>
                <?php $sb=['planifiee'=>'badge-muted','en_cours'=>'badge-warning','terminee'=>'badge-success','annulee'=>'badge-danger']; ?>
                <tr>
                    <td><?= htmlspecialchars($m['c_prenom'] . ' ' . $m['c_nom']) ?></td>
                    <td><?= htmlspecialchars($m['lieu_depart'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($m['lieu_destination'] ?? '—') ?></td>
                    <td style="font-size:12px;"><?= date('d/m/Y H:i', strtotime($m['date_debut'])) ?></td>
                    <td style="font-size:12px;"><?= !empty($m['date_fin']) ? date('d/m/Y H:i', strtotime($m['date_fin'])) : '—' ?></td>
                    <td><span class="badge <?= $sb[$m['statut']] ?>"><?= ucfirst($m['statut']) ?></span></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php if (!empty($telemetrieRecente)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
var tele = <?= json_encode($telemetrieRecente) ?>;
var labels = tele.map(function(t) { var d = new Date(t.horodatage); return d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }); });

new Chart(document.getElementById('chartVitesse'), {
    type: 'line',
    data: {
        labels: labels,
        datasets: [{ label: 'Vitesse (km/h)', data: tele.map(function(t) { return t.vitesse; }), borderColor: '#0F6E56', backgroundColor: 'rgba(15,110,86,0.1)', tension: 0.3, fill: true, pointRadius: 0 }]
    },
    options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
});

new Chart(document.getElementById('chartCarburant'), {
    type: 'line',
    data: {
        labels: labels,
        datasets: [{ label: 'Carburant (%)', data: tele.map(function(t) { return t.carburant; }), borderColor: '#BA7517', backgroundColor: 'rgba(186,117,23,0.1)', tension: 0.3, fill: true, pointRadius: 0 }]
    },
    options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, max: 100 } } }
});
</script>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
