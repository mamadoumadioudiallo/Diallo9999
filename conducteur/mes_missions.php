<?php
// ============================================================
// FleetIoT — Simandou 2040
// Fichier : conducteur/mes_missions.php
// Rôle    : Missions assignées au conducteur connecté
//           Notification des nouvelles missions
// ============================================================

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireConducteur();
$idConducteur = $_SESSION['user_id'];

// ====== MARQUER MISSION VUE ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'voir') {
    $idMission = (int) ($_POST['id_mission'] ?? 0);
    // Vérifier que la mission appartient bien à ce conducteur
    $check = $pdo->prepare("SELECT COUNT(*) FROM missions WHERE id_mission = ? AND id_conducteur = ?");
    $check->execute([$idMission, $idConducteur]);
    if ($check->fetchColumn() > 0) {
        $pdo->prepare("UPDATE missions SET vue_conducteur = 1 WHERE id_mission = ?")->execute([$idMission]);
    }
}

// ====== MISSIONS DU CONDUCTEUR ======
$filtreStatut = $_GET['statut'] ?? 'toutes';

$conditions = ["m.id_conducteur = ?"];
$params = [$idConducteur];
if ($filtreStatut !== 'toutes') { $conditions[] = "m.statut = ?"; $params[] = $filtreStatut; }
$where = 'WHERE ' . implode(' AND ', $conditions);

$stmt = $pdo->prepare("
    SELECT m.*,
           v.immatriculation, v.marque, v.modele, v.type AS v_type,
           t.latitude AS gps_lat, t.longitude AS gps_lng,
           t.vitesse AS gps_vitesse
    FROM missions m
    JOIN vehicules v ON m.id_vehicule = v.id_vehicule
    LEFT JOIN (
        SELECT id_vehicule, latitude, longitude, vitesse
        FROM telemetrie
        WHERE (id_vehicule, horodatage) IN (
            SELECT id_vehicule, MAX(horodatage)
            FROM telemetrie GROUP BY id_vehicule
        )
    ) t ON v.id_vehicule = t.id_vehicule
    $where
    ORDER BY
        CASE m.statut WHEN 'en_cours' THEN 0 WHEN 'planifiee' THEN 1 WHEN 'terminee' THEN 2 ELSE 3 END,
        m.date_debut DESC
");
$stmt->execute($params);
$missions = $stmt->fetchAll();

// Nouvelles missions non vues
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM missions
    WHERE id_conducteur = ? AND (vue_conducteur = 0 OR vue_conducteur IS NULL)
    AND statut IN ('planifiee','en_cours')
");
$stmt->execute([$idConducteur]);
$nbNouvelles = (int) $stmt->fetchColumn();

// KPI
$stmt = $pdo->prepare("SELECT statut, COUNT(*) AS nb FROM missions WHERE id_conducteur = ? GROUP BY statut");
$stmt->execute([$idConducteur]);
$kpiStatuts = [];
foreach ($stmt->fetchAll() as $row) { $kpiStatuts[$row['statut']] = (int) $row['nb']; }

$pageTitle = 'Mes missions';
require_once '../includes/header.php';

// Fonction de géocodage des villes — déclarée une seule fois
function trouverVilleCoords($lieu, $villes) {
    $lieuNorm = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $lieu));
    foreach ($villes as $nom => $coords) {
        if (strpos($lieuNorm, $nom) !== false) return $coords;
    }
    return null;
}
?>

<div class="dash-header-row">
    <div>
        <h1>&#128203; Mes missions</h1>
        <p class="subtitle">Missions qui vous sont assignées</p>
    </div>
</div>

<!-- Notification nouvelles missions -->
<?php if ($nbNouvelles > 0): ?>
<div style="background:#E1F5EE;border-left:4px solid #1D9E75;border-radius:8px;padding:14px 18px;margin-bottom:16px;display:flex;align-items:center;gap:12px;">
    <span style="font-size:24px;">&#128276;</span>
    <div>
        <p style="font-weight:700;color:#085041;font-size:14px;">
            <?= $nbNouvelles ?> nouvelle(s) mission(s) vous ont été assignées !
        </p>
        <p style="font-size:12px;color:#5C6B68;">Consultez les détails ci-dessous.</p>
    </div>
</div>
<?php endif; ?>

<!-- KPI -->
<div class="kpi-grid" style="margin-bottom:16px;">
    <div class="kpi-card <?= ($kpiStatuts['en_cours'] ?? 0) > 0 ? '' : '' ?>">
        <div class="kpi-info"><p class="label">En cours</p><p class="value" style="color:#0F6E56;"><?= $kpiStatuts['en_cours'] ?? 0 ?></p><p class="trend">Mission active</p></div>
        <div class="kpi-icon">&#9654;</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-info"><p class="label">Planifiées</p><p class="value"><?= $kpiStatuts['planifiee'] ?? 0 ?></p><p class="trend">À venir</p></div>
        <div class="kpi-icon">&#128197;</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-info"><p class="label">Terminées</p><p class="value"><?= $kpiStatuts['terminee'] ?? 0 ?></p><p class="trend">Complétées</p></div>
        <div class="kpi-icon">&#9989;</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-info"><p class="label">Total</p><p class="value"><?= array_sum($kpiStatuts) ?></p><p class="trend">Toutes missions</p></div>
        <div class="kpi-icon">&#128203;</div>
    </div>
</div>

<!-- Filtres -->
<div class="panel" style="padding:14px 20px;margin-bottom:16px;">
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <label style="font-size:12px;color:#5C6B68;">Filtrer :</label>
        <?php
        $filtres = ['toutes'=>'Toutes','en_cours'=>'En cours','planifiee'=>'Planifiées','terminee'=>'Terminées','annulee'=>'Annulées'];
        foreach ($filtres as $val => $label):
        ?>
            <a href="?statut=<?= $val ?>" class="btn-dash <?= $filtreStatut===$val?'btn-dash-primary':'btn-dash-outline' ?>" style="font-size:12px;padding:5px 12px;">
                <?= $label ?>
            </a>
        <?php endforeach; ?>
    </form>
</div>

<!-- Liste missions -->
<div style="display:flex;flex-direction:column;gap:12px;">
    <?php if (empty($missions)): ?>
        <div class="panel" style="padding:40px;text-align:center;color:#999;">
            <p style="font-size:24px;margin-bottom:8px;">&#128203;</p>
            <p>Aucune mission pour ce filtre.</p>
        </div>
    <?php endif; ?>

    <?php foreach ($missions as $m):
        $statutColors = [
            'planifiee' => ['#BA7517','#FFFBF0','Planifiée','&#128197;'],
            'en_cours'  => ['#0F6E56','#E8F4F1','En cours','&#9654;'],
            'terminee'  => ['#5C6B68','#F5F7F6','Terminée','&#9989;'],
            'annulee'   => ['#E24B4A','#FFF8F8','Annulée','&#10060;'],
        ];
        $sc = $statutColors[$m['statut']] ?? ['#999','#F5F7F6','—',''];
        $estNouvelle = !$m['vue_conducteur'] && in_array($m['statut'], ['planifiee','en_cours']);
    ?>
        <div class="panel" style="padding:0;overflow:hidden;border:<?= $estNouvelle?'2px solid #1D9E75':'1px solid #E9ECEC' ?>;">
            <!-- En-tête mission -->
            <div style="background:<?= $sc[1] ?>;padding:12px 18px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                <div style="display:flex;align-items:center;gap:10px;">
                    <span style="font-size:20px;"><?= $sc[3] ?></span>
                    <div>
                        <span style="font-weight:700;color:<?= $sc[0] ?>;font-size:14px;"><?= $sc[2] ?></span>
                        <?php if ($estNouvelle): ?>
                            <span style="background:#1D9E75;color:#fff;font-size:10px;padding:2px 8px;border-radius:10px;margin-left:8px;font-weight:600;">NOUVEAU</span>
                        <?php endif; ?>
                    </div>
                </div>
                <span style="font-size:12px;color:#5C6B68;">Mission #<?= $m['id_mission'] ?> — <?= date('d/m/Y', strtotime($m['date_debut'])) ?></span>
            </div>

            <div style="padding:18px;">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:14px;">
                    <!-- Trajet -->
                    <div>
                        <p style="font-size:11px;color:#5C6B68;margin-bottom:10px;font-weight:600;text-transform:uppercase;">Trajet</p>
                        <?php
                            $villes = [
                                'conakry'     => [9.6412,  -13.5784],
                                'coyah'       => [9.7150,  -13.3750],
                                'kindia'      => [10.0558, -12.8661],
                                'mamou'       => [10.3667, -12.0833],
                                'faranah'     => [10.0333, -10.7500],
                                'kissidougou' => [9.1844,  -10.1167],
                                'nzerekore'   => [7.7564,  -8.8178],
                                'lola'        => [7.8167,  -8.5333],
                                'beyla'       => [8.6833,  -8.6500],
                                'morybaya'    => [9.5000,  -10.5000],
                                'port'        => [9.5092,  -13.7122],
                                'kamsar'      => [10.6500, -14.6167],
                                'boke'        => [10.9333, -14.2833],
                                'fria'        => [10.3667, -13.5500],
                            ];

                            $coordsDepart = trouverVilleCoords($m['lieu_depart'] ?? '', $villes);
                            $coordsDest   = trouverVilleCoords($m['lieu_destination'] ?? '', $villes);
                            $gpsLat = (float) ($m['gps_lat'] ?? 0);
                            $gpsLng = (float) ($m['gps_lng'] ?? 0);

                            $progression = 50;
                            if ($coordsDepart && $coordsDest && $gpsLat && $gpsLng) {
                                $distTotale = sqrt(pow($coordsDest[0]-$coordsDepart[0],2)+pow($coordsDest[1]-$coordsDepart[1],2));
                                $distParcourue = sqrt(pow($gpsLat-$coordsDepart[0],2)+pow($gpsLng-$coordsDepart[1],2));
                                if ($distTotale > 0) {
                                    $progression = min(95, max(5, round($distParcourue/$distTotale*100)));
                                }
                            }
                        ?>
                        <div style="position:relative;padding:10px 0 24px 0;">
                            <!-- Ligne de trajet -->
                            <div style="position:relative;height:4px;background:#E9ECEC;border-radius:4px;margin:10px 0;">
                                <!-- Progression -->
                                <div id="barre_<?= $m['id_mission'] ?>" style="position:absolute;left:0;top:0;height:4px;width:<?= $progression ?>%;background:<?= $m['statut']==='en_cours'?'#0F6E56':'#E9ECEC' ?>;border-radius:4px;transition:width 0.5s;"></div>
                                <!-- Point départ -->
                                <div style="position:absolute;left:-5px;top:-5px;width:14px;height:14px;background:#1D9E75;border-radius:50%;border:2px solid #fff;box-shadow:0 0 0 2px #1D9E75;"></div>
                                <!-- Camion en mouvement -->
                                <?php if ($m['statut'] === 'en_cours'): ?>
                                <div id="camion_<?= $m['id_mission'] ?>" style="position:absolute;left:calc(<?= $progression ?>% - 12px);top:-14px;font-size:20px;transition:left 0.5s;" title="Position GPS actuelle">🚛</div>
                                <?php endif; ?>
                                <!-- Point destination -->
                                <div style="position:absolute;right:-5px;top:-5px;width:14px;height:14px;background:#E24B4A;border-radius:50%;border:2px solid #fff;box-shadow:0 0 0 2px #E24B4A;"></div>
                            </div>
                            <!-- Labels -->
                            <div style="display:flex;justify-content:space-between;margin-top:6px;">
                                <div>
                                    <p style="font-size:12px;font-weight:600;"><?= htmlspecialchars($m['lieu_depart'] ?? '—') ?></p>
                                    <p style="font-size:10px;color:#999;">Départ</p>
                                </div>
                                <?php if ($m['statut'] === 'en_cours' && $gpsLat): ?>
                                <div style="text-align:center;">
                                    <p id="pct_<?= $m['id_mission'] ?>" style="font-size:11px;font-weight:700;color:#0F6E56;"><?= $progression ?> %</p>
                                    <p id="vit_<?= $m['id_mission'] ?>" style="font-size:10px;color:#999;"><?= round($m['gps_vitesse'] ?? 0) ?> km/h</p>
                                </div>
                                <?php endif; ?>
                                <div style="text-align:right;">
                                    <p style="font-size:12px;font-weight:600;"><?= htmlspecialchars($m['lieu_destination'] ?? '—') ?></p>
                                    <p style="font-size:10px;color:#999;">Destination</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Véhicule -->
                    <div>
                        <p style="font-size:11px;color:#5C6B68;margin-bottom:6px;font-weight:600;text-transform:uppercase;">Véhicule assigné</p>
                        <div style="background:#F5F7F6;border-radius:8px;padding:10px 14px;">
                            <p style="font-weight:700;color:#0F6E56;font-size:14px;"><?= htmlspecialchars($m['immatriculation']) ?></p>
                            <p style="font-size:12px;color:#5C6B68;"><?= htmlspecialchars($m['marque'] . ' ' . $m['modele']) ?> — <?= ucfirst($m['v_type']) ?></p>
                        </div>
                    </div>
                </div>

                <!-- Dates -->
                <div style="display:flex;gap:16px;font-size:12px;color:#5C6B68;margin-bottom:14px;flex-wrap:wrap;">
                    <span>&#128197; Début : <strong><?= date('d/m/Y H:i', strtotime($m['date_debut'])) ?></strong></span>
                    <?php if (!empty($m['date_fin'])): ?>
                        <span>&#9989; Fin : <strong><?= date('d/m/Y H:i', strtotime($m['date_fin'])) ?></strong></span>
                    <?php endif; ?>
                </div>

                <!-- Marquer comme vue -->
                <?php if ($estNouvelle): ?>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action" value="voir">
                        <input type="hidden" name="id_mission" value="<?= $m['id_mission'] ?>">
                        <button type="submit" style="background:none;border:none;color:#1D9E75;font-size:12px;cursor:pointer;padding:0;text-decoration:underline;">
                            ✓ Marquer comme lu
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php if (in_array('en_cours', array_column($missions, 'statut'))): ?>
<script>
<?php
$villesJS = [
    'conakry'     => [9.6412,  -13.5784],
    'coyah'       => [9.7150,  -13.3750],
    'kindia'      => [10.0558, -12.8661],
    'mamou'       => [10.3667, -12.0833],
    'faranah'     => [10.0333, -10.7500],
    'kissidougou' => [9.1844,  -10.1167],
    'nzerekore'   => [7.7564,  -8.8178],
    'lola'        => [7.8167,  -8.5333],
    'beyla'       => [8.6833,  -8.6500],
    'morybaya'    => [9.5000,  -10.5000],
    'port'        => [9.5092,  -13.7122],
    'kamsar'      => [10.6500, -14.6167],
    'boke'        => [10.9333, -14.2833],
    'fria'        => [10.3667, -13.5500],
];
foreach ($missions as $m):
    if ($m['statut'] !== 'en_cours' || !$m['gps_lat']) continue;
    $cd = trouverVilleCoords($m['lieu_depart'] ?? '', $villesJS);
    $cf = trouverVilleCoords($m['lieu_destination'] ?? '', $villesJS);
?>
(function() {
    var idVehicule   = <?= $m['id_vehicule'] ?>;
    var idMission    = <?= $m['id_mission'] ?>;
    var coordsDepart = <?= $cd ? json_encode($cd) : 'null' ?>;
    var coordsDest   = <?= $cf ? json_encode($cf) : 'null' ?>;

    function calculerProgression(lat, lng) {
        if (!coordsDepart || !coordsDest || !lat || !lng) return null;
        var dT = Math.sqrt(Math.pow(coordsDest[0]-coordsDepart[0],2)+Math.pow(coordsDest[1]-coordsDepart[1],2));
        var dP = Math.sqrt(Math.pow(lat-coordsDepart[0],2)+Math.pow(lng-coordsDepart[1],2));
        if (dT === 0) return 50;
        return Math.min(95, Math.max(5, Math.round(dP/dT*100)));
    }

    setInterval(function() {
        fetch('../api/telemetrie.php?vehicule=' + idVehicule)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                var v = Array.isArray(data)
                    ? data.find(function(x) { return x.id_vehicule == idVehicule; })
                    : data;
                if (!v || !v.latitude) return;

                var pct    = calculerProgression(parseFloat(v.latitude), parseFloat(v.longitude));
                if (pct === null) return;

                var barre  = document.getElementById('barre_'  + idMission);
                var camion = document.getElementById('camion_' + idMission);
                var pctEl  = document.getElementById('pct_'    + idMission);
                var vitEl  = document.getElementById('vit_'    + idMission);

                if (barre)  barre.style.width  = pct + '%';
                if (camion) camion.style.left   = 'calc(' + pct + '% - 12px)';
                if (pctEl)  pctEl.textContent   = pct + ' %';
                if (vitEl)  vitEl.textContent   = Math.round(v.vitesse || 0) + ' km/h';
            })
            .catch(function() {});
    }, 5000); // toutes les 5 secondes
})();
<?php endforeach; ?>
</script>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>