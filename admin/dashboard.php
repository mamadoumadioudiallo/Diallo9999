<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : admin/dashboard.php
// Rôle    : Tableau de bord administrateur
// ============================================================

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAdmin();

// ====== CALCUL DES KPI ======

// Véhicules actifs
$stmt = $pdo->query("SELECT COUNT(*) FROM vehicules WHERE statut = 'actif'");
$vehiculesActifs = $stmt->fetchColumn();

// Alertes non traitées
$stmt = $pdo->query("SELECT COUNT(*) FROM alertes WHERE statut = 'non_traitee'");
$alertesNonTraitees = $stmt->fetchColumn();

// Kilométrage du jour (somme des distances simulées aujourd'hui — approximation)
$stmt = $pdo->query("
    SELECT COALESCE(SUM(kilometrage),0) FROM telemetrie
    WHERE DATE(horodatage) = CURDATE()
");
$kmJour = $stmt->fetchColumn();

// Consommation du jour (approximation simple : nb relevés * conso moyenne)
$stmt = $pdo->query("SELECT COUNT(*) FROM telemetrie WHERE DATE(horodatage) = CURDATE()");
$nbRelevesJour = $stmt->fetchColumn();
$consoJour = round($nbRelevesJour * 0.5, 1); // estimation simplifiée

// ====== MISSIONS EN COURS ======
$stmt = $pdo->query("SELECT COUNT(*) FROM missions WHERE statut = 'en_cours'");
$missionsEnCours = (int) $stmt->fetchColumn();

// ====== CHARGE NETTE TOTALE (camions miniers actifs) ======
$stmt = $pdo->query("
    SELECT COALESCE(SUM(t.poids_charge_kg), 0)
    FROM vehicules v
    INNER JOIN (
        SELECT t1.* FROM telemetrie t1
        INNER JOIN (
            SELECT id_vehicule, MAX(horodatage) AS max_horo
            FROM telemetrie GROUP BY id_vehicule
        ) t2 ON t1.id_vehicule = t2.id_vehicule AND t1.horodatage = t2.max_horo
    ) t ON v.id_vehicule = t.id_vehicule
    WHERE v.statut = 'actif' AND v.type = 'minier' AND t.poids_charge_kg > 0
");
$chargeNetteTotaleKg = (int) $stmt->fetchColumn();
$chargeNetteTotaleTonnes = round($chargeNetteTotaleKg / 1000, 1);

// Libellés lisibles pour chaque type d'alerte (utilisé dans la liste d'alertes ci-dessous)
$libellesAlertes = [
    'vitesse_excessive'    => 'Vitesse excessive',
    'temperature_critique' => 'Température critique',
    'carburant_bas'        => 'Carburant bas',
    'hors_zone'            => 'Hors zone',
    'surcharge'            => 'Surcharge',
    'tpms_pression'        => 'Pression pneu (TPMS)',
    'tpms_temperature'     => 'Température pneu (TPMS)',
    'moteur_anomalie'      => 'Anomalie moteur',
];

// ====== KPI MOTEUR : nombre de véhicules actuellement allumés ======
$stmt = $pdo->query("
    SELECT COUNT(*) FROM vehicules v
    INNER JOIN (
        SELECT t1.* FROM telemetrie t1
        INNER JOIN (
            SELECT id_vehicule, MAX(horodatage) AS max_horo FROM telemetrie GROUP BY id_vehicule
        ) t2 ON t1.id_vehicule = t2.id_vehicule AND t1.horodatage = t2.max_horo
    ) t ON v.id_vehicule = t.id_vehicule
    WHERE v.statut = 'actif' AND t.moteur_etat = 'on'
");
$vehiculesMoteurAllume = (int) $stmt->fetchColumn();

// ====== KPI TPMS : nombre de véhicules avec alerte pneu active ======
$stmt = $pdo->query("
    SELECT COUNT(*) FROM vehicules v
    INNER JOIN (
        SELECT t1.* FROM telemetrie t1
        INNER JOIN (
            SELECT id_vehicule, MAX(horodatage) AS max_horo FROM telemetrie GROUP BY id_vehicule
        ) t2 ON t1.id_vehicule = t2.id_vehicule AND t1.horodatage = t2.max_horo
    ) t ON v.id_vehicule = t.id_vehicule
    WHERE v.statut = 'actif' AND t.tpms_alerte = 1
");
$vehiculesTpmsAlerte = (int) $stmt->fetchColumn();

// ====== VÉHICULES ACTIFS POUR LA CARTE (avec conducteur en mission) ======
$stmt = $pdo->query("
    SELECT v.id_vehicule, v.immatriculation, v.type, v.statut,
           t.latitude, t.longitude, t.vitesse, t.carburant, t.temperature_moteur,
           t.moteur_etat, t.poids_charge_kg, t.tpms_alerte,
           c.nom AS c_nom, c.prenom AS c_prenom
    FROM vehicules v
    LEFT JOIN (
        SELECT t1.* FROM telemetrie t1
        INNER JOIN (
            SELECT id_vehicule, MAX(horodatage) AS max_horo
            FROM telemetrie GROUP BY id_vehicule
        ) t2 ON t1.id_vehicule = t2.id_vehicule AND t1.horodatage = t2.max_horo
    ) t ON v.id_vehicule = t.id_vehicule
    LEFT JOIN missions m ON m.id_vehicule = v.id_vehicule AND m.statut = 'en_cours'
    LEFT JOIN conducteurs c ON m.id_conducteur = c.id_conducteur
    WHERE v.statut = 'actif'
");
$vehicules = $stmt->fetchAll();

// ====== ALERTES ACTIVES ======
$stmt = $pdo->query("
    SELECT a.*, v.immatriculation
    FROM alertes a
    JOIN vehicules v ON a.id_vehicule = v.id_vehicule
    WHERE a.statut != 'resolue'
    ORDER BY a.horodatage DESC
    LIMIT 10
");
$alertes = $stmt->fetchAll();

$csrfToken = generateCsrfToken();
$pageTitle = 'Tableau de bord';
require_once '../includes/header.php';
?>

<div class="dash-header-row">
    <div>
        <h1>Tableau de bord</h1>
        <p class="subtitle">Mise à jour toutes les 5 secondes — <?= date('d/m/Y, H:i') ?></p>
    </div>
    <div class="dash-actions">
        <a href="../simulation/run_simulation.php" class="btn-dash btn-dash-primary">&#9654; Simulateur</a>
        <a href="../rapports/export_csv.php?type=conso" class="btn-dash btn-dash-outline">Exporter rapport</a>
    </div>
</div>

<!-- ====== KPI ====== -->
<div class="kpi-grid">
    <div class="kpi-card" onclick="document.getElementById('etatFlotte').scrollIntoView({behavior:'smooth'})" style="cursor:pointer;" title="Voir l'état de la flotte">
        <div class="kpi-info">
            <p class="label">Véhicules actifs</p>
            <p class="value"><?= $vehiculesActifs ?></p>
            <p class="trend">Flotte Simandou 2040 </p>
        </div>
        <div class="kpi-icon">&#128666;</div>
    </div>

    <div class="kpi-card <?= $alertesNonTraitees > 0 ? 'danger' : '' ?>" id="kpiAlertesCard"
         onclick="window.location.href='alertes.php'" style="cursor:pointer;" title="Voir toutes les alertes">
        <div class="kpi-info">
            <p class="label">Alertes non traitées</p>
            <p class="value" id="kpiAlertesValue"><?= $alertesNonTraitees ?></p>
            <p class="trend" id="kpiAlertesTrend"><?= $alertesNonTraitees > 0 ? 'Intervention requise →' : 'Tout est calme' ?></p>
        </div>
        <div class="kpi-icon">&#128276;</div>
    </div>

    <div class="kpi-card" onclick="window.location.href='kilometrage.php'" style="cursor:pointer;" title="Voir le détail du kilométrage">
        <div class="kpi-info">
            <p class="label">Kilométrage du jour</p>
            <p class="value"><?= number_format($kmJour, 0, ',', ' ') ?> km</p>
            <p class="trend">Depuis minuit </p>
        </div>
        <div class="kpi-icon">&#128739;</div>
    </div>

    <div class="kpi-card warning" onclick="window.location.href='carburant.php'" style="cursor:pointer;" title="Voir le détail du carburant">
        <div class="kpi-info">
            <p class="label">Consommation du jour</p>
            <p class="value"><?= number_format($consoJour, 1, ',', ' ') ?> L</p>
            <p class="trend">Estimation simulée </p>
        </div>
        <div class="kpi-icon">&#128167;</div>
    </div>

    <div class="kpi-card" onclick="window.location.href='moteurs.php'" style="cursor:pointer;" title="Voir l'état des moteurs">
        <div class="kpi-info">
            <p class="label">Moteurs allumés</p>
            <p class="value"><?= $vehiculesMoteurAllume ?> / <?= $vehiculesActifs ?></p>
            <p class="trend">En temps réel </p>
        </div>
        <div class="kpi-icon">&#128268;</div>
    </div>

    <div class="kpi-card <?= $vehiculesTpmsAlerte > 0 ? 'danger' : '' ?>" onclick="window.location.href='tpms.php'" style="cursor:pointer;" title="Voir le détail des pneus">
        <div class="kpi-info">
            <p class="label">Alertes pneus (TPMS)</p>
            <p class="value"><?= $vehiculesTpmsAlerte ?></p>
            <p class="trend"><?= $vehiculesTpmsAlerte > 0 ? 'Vérification requise →' : 'Tout est normal' ?></p>
        </div>
        <div class="kpi-icon">&#127919;</div>
    </div>

    <div class="kpi-card <?= $missionsEnCours > 0 ? '' : 'warning' ?>" onclick="window.location.href='missions.php?statut=en_cours'" style="cursor:pointer;" title="Voir les missions en cours">
        <div class="kpi-info">
            <p class="label">Missions en cours</p>
            <p class="value"><?= $missionsEnCours ?></p>
            <p class="trend"><?= $missionsEnCours > 0 ? $missionsEnCours . ' mission(s) active(s) ' : 'Aucune mission active' ?></p>
        </div>
        <div class="kpi-icon">&#128203;</div>
    </div>

    <div class="kpi-card" onclick="window.location.href='chargement.php'" style="cursor:pointer;" title="Voir le détail du chargement">
        <div class="kpi-info">
            <p class="label">Charge nette (miniers)</p>
            <p class="value"><?= $chargeNetteTotaleTonnes > 0 ? number_format($chargeNetteTotaleTonnes, 1, ',', ' ') . ' t' : '—' ?></p>
            <p class="trend">Tonnage transporté </p>
        </div>
        <div class="kpi-icon">&#9878;</div>
    </div>
</div>

<!-- ====== CARTE + ALERTES ====== -->
<div class="map-alerts-grid">

    <div class="panel">
        <div class="panel-header">
            <h3>&#128205; Carte temps réel</h3>
            <div class="panel-badges">
                <span class="badge" id="connexionBadge" style="background:#999;color:#fff;">&#9679; Connexion...</span>
                <span class="badge badge-success">&#9679; <?= $vehiculesActifs ?> actifs</span>
                <span class="badge badge-danger" id="mapAlertesBadge">&#9679; <?= $alertesNonTraitees ?> alertes</span>
            </div>
        </div>
        <div id="map"></div>
        <div style="padding:8px 16px;border-top:1px solid var(--color-border);display:flex;gap:16px;font-size:11px;color:#5C6B68;">
            <span>&#128666; Minier (benne)</span>
            <span>&#128667; Routier</span>
            <span style="color:#1D9E75;">&#9679; Normal</span>
            <span style="color:#E24B4A;">&#9679; Alerte</span>
            <span style="color:#BA7517;">&#9679; Carburant bas</span>
        </div>
    </div>

    <div class="panel">
        <div class="panel-header">
            <h3>&#128276; Alertes actives</h3>
            <span class="badge badge-danger" id="alertsCountBadge"><?= count($alertes) ?></span>
        </div>
        <div class="alerts-list" id="alertsList">
            <?php if (empty($alertes)): ?>
                <p class="alert-empty">Aucune alerte active pour le moment.</p>
            <?php else: ?>
                <?php foreach ($alertes as $a): ?>
                    <?php $isWarning = in_array($a['type_alerte'], ['carburant_bas', 'tpms_pression', 'tpms_temperature'], true); ?>
                    <div class="alert-item <?= $isWarning ? 'warning' : '' ?>">
                        <div class="alert-item-top">
                            <span><?= htmlspecialchars($libellesAlertes[$a['type_alerte']] ?? ucfirst(str_replace('_', ' ', $a['type_alerte']))) ?></span>
                            <span><?= date('H:i', strtotime($a['horodatage'])) ?></span>
                        </div>
                        <p class="detail"><?= htmlspecialchars($a['immatriculation']) ?> — <?= formaterValeurAlerte($a['type_alerte'], $a['valeur_declenchante']) ?></p>
                        <?php if ($a['statut'] === 'non_traitee'): ?>
                            <span class="alert-take" data-id="<?= $a['id_alerte'] ?>" data-action="take">Prendre en charge</span>
                        <?php else: ?>
                            <span class="alert-take alert-resolve" data-id="<?= $a['id_alerte'] ?>" data-action="resolve">Résoudre</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- ====== GRAPHIQUES D'ÉVOLUTION (7 derniers jours) ====== -->
<div class="map-alerts-grid">
    <div class="panel">
        <div class="panel-header">
            <h3>&#128202; Kilométrage &amp; alertes — 7 derniers jours</h3>
        </div>
        <div style="padding:16px;">
            <canvas id="evolutionChart" height="100"></canvas>
        </div>
    </div>

    <div class="panel">
        <div class="panel-header">
            <h3>&#128202; Répartition des alertes</h3>
        </div>
        <div style="padding:16px;">
            <canvas id="repartitionChart" height="100"></canvas>
        </div>
    </div>
</div>

<!-- ====== TABLEAU FLOTTE ====== -->
<div class="panel fleet-table-wrap" id="etatFlotte">
    <div class="panel-header">
        <h3>&#128203; État de la flotte — Simandou 2040</h3>
        <input type="text" class="search-input" id="fleetSearch" placeholder="Rechercher un véhicule...">
    </div>
    <table id="fleetTable">
        <thead>
            <tr>
                <th>Immatriculation</th>
                <th>Type</th>
                <th>Moteur</th>
                <th>Vitesse</th>
                <th>Carburant</th>
                <th>Température</th>
                <th>Statut</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($vehicules as $v): ?>
                <?php
                    $seuilVitesse = $v['type'] === 'minier' ? 80 : 120;
                    $isAlerteVitesse = $v['vitesse'] > $seuilVitesse;
                    $isAlerteTemp = $v['temperature_moteur'] > 95;
                    $isCarburantBas = $v['carburant'] < 10;
                    $isTpmsAlerte = !empty($v['tpms_alerte']);
                    $moteurOn = ($v['moteur_etat'] ?? 'off') === 'on';
                    $statutLabel = 'Normal'; $statutClass = 'badge-success';
                    if ($isAlerteVitesse || $isAlerteTemp || $isTpmsAlerte) { $statutLabel = 'Alerte'; $statutClass = 'badge-danger'; }
                    elseif ($isCarburantBas) { $statutLabel = 'Carburant bas'; $statutClass = 'badge-warning'; }
                ?>
                <tr class="fleet-row" data-vehicle-id="<?= $v['id_vehicule'] ?>" style="cursor:pointer;">
                    <td><?= htmlspecialchars($v['immatriculation']) ?></td>
                    <td><?= ucfirst($v['type']) ?></td>
                    <td>
                        <span class="badge <?= $moteurOn ? 'badge-success' : 'badge-muted' ?>" title="<?= $moteurOn ? 'Moteur allumé' : 'Moteur éteint' ?>">
                            <?= $moteurOn ? '&#128268; ON' : '&#9211; OFF' ?>
                        </span>
                    </td>
                    <td style="color: <?= $isAlerteVitesse ? '#E24B4A' : '#1A1A1A' ?>">
                        <?= $v['vitesse'] !== null ? round($v['vitesse']) . ' km/h' : '—' ?>
                    </td>
                    <td>
                        <div class="fuel-bar">
                            <div class="fuel-bar-track">
                                <div class="fuel-bar-fill <?= $isCarburantBas ? 'low' : '' ?>" style="width:<?= $v['carburant'] ?? 0 ?>%"></div>
                            </div>
                            <span><?= $v['carburant'] !== null ? round($v['carburant']) . '%' : '—' ?></span>
                        </div>
                    </td>
                    <td><?= $v['temperature_moteur'] !== null ? round($v['temperature_moteur']) . '°C' : '—' ?></td>
                    <td><span class="badge <?= $statutClass ?>"><?= $statutLabel ?></span></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
// ====== CARTE LEAFLET — centrée sur la Guinée / Simandou ======
var map = L.map('map').setView([9.9, -10.5], 7);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap contributors'
}).addTo(map);

var vehiclesData = <?= json_encode($vehicules) ?>;
var markers = {};

// ====== ANTI-SUPERPOSITION : décale légèrement les véhicules trop proches ======
function applyAntiOverlap(vehicles) {
    var threshold = 0.0015; // ~150 m — distance minimale considérée comme "superposée"
    var positions = [];

    vehicles.forEach(function (v) {
        if (!v.latitude || !v.longitude) return;

        var lat = parseFloat(v.latitude);
        var lng = parseFloat(v.longitude);
        var collision = true;
        var attempts = 0;

        // Tant qu'un véhicule déjà placé est trop proche, on décale en spirale légère
        while (collision && attempts < 8) {
            collision = positions.some(function (p) {
                return Math.abs(p.lat - lat) < threshold && Math.abs(p.lng - lng) < threshold;
            });

            if (collision) {
                var angle = attempts * 60 * (Math.PI / 180);
                lat = parseFloat(v.latitude) + Math.cos(angle) * threshold * 1.2;
                lng = parseFloat(v.longitude) + Math.sin(angle) * threshold * 1.2;
                attempts++;
            }
        }

        positions.push({ lat: lat, lng: lng });
        v._displayLat = lat;
        v._displayLng = lng;
    });

    return vehicles;
}

vehiclesData = applyAntiOverlap(vehiclesData);

vehiclesData.forEach(function (v) {
    if (!v.latitude || !v.longitude) return;

    var color = '#1D9E75';
    if (v.vitesse > (v.type === 'minier' ? 80 : 120) || v.temperature_moteur > 95) color = '#E24B4A';
    else if (v.carburant < 10) color = '#BA7517';

    // Icône SVG différenciée selon le type de véhicule
    var iconSvg = '';
    if (v.type === 'minier') {
        // Camion-benne minier (forme robuste, benne arrière surélevée)
        iconSvg =
            '<svg width="20" height="20" viewBox="0 0 24 24" fill="white">' +
            '<path d="M2 17h1.5a2.5 2.5 0 0 0 4.9 0H15a2.5 2.5 0 0 0 4.9 0H22v-3l-2-4h-3V7a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v3H2v7z"/>' +
            '<circle cx="6" cy="18" r="1.6" fill="' + color + '"/>' +
            '<circle cx="17" cy="18" r="1.6" fill="' + color + '"/>' +
            '</svg>';
    } else {
        // Camion routier (cabine + remorque classique)
        iconSvg =
            '<svg width="20" height="20" viewBox="0 0 24 24" fill="white">' +
            '<path d="M3 16V8a1 1 0 011-1h9l5 4v5a1 1 0 01-1 1H4a1 1 0 01-1-1z"/>' +
            '<circle cx="7" cy="17.5" r="1.6" fill="' + color + '"/>' +
            '<circle cx="17" cy="17.5" r="1.6" fill="' + color + '"/>' +
            '</svg>';
    }

    var icon = L.divIcon({
        className: 'vehicle-marker',
        html: '<div style="background:' + color + ';width:30px;height:30px;border-radius:50%;border:2px solid white;display:flex;align-items:center;justify-content:center;box-shadow:0 1px 4px rgba(0,0,0,0.3);">' + iconSvg + '</div>',
        iconSize: [30, 30],
        iconAnchor: [15, 15]
    });

    var marker = L.marker([v._displayLat, v._displayLng], { icon: icon }).addTo(map);
    var conducteurInfo = v.c_nom ? ('<br>Conducteur: ' + v.c_prenom + ' ' + v.c_nom) : '<br><span style="color:#999;">Aucun conducteur en mission</span>';
    marker.bindPopup(
        '<b>' + v.immatriculation + '</b> <span style="color:#5C6B68;font-size:11px;">(' + (v.type === 'minier' ? 'Minier' : 'Routier') + ')</span>' +
        conducteurInfo + '<br>' +
        'Vitesse: ' + Math.round(v.vitesse) + ' km/h<br>' +
        'Carburant: ' + Math.round(v.carburant) + '%<br>' +
        'Temp: ' + Math.round(v.temperature_moteur) + '°C'
    );
    markers[v.id_vehicule] = marker;
});

// ====== RECHERCHE TABLEAU ======
document.getElementById('fleetSearch').addEventListener('input', function () {
    var query = this.value.toLowerCase();
    document.querySelectorAll('#fleetTable tbody tr').forEach(function (row) {
        row.style.display = row.textContent.toLowerCase().includes(query) ? '' : 'none';
    });
});

// ====== ZOOM SUR VÉHICULE AU CLIC SUR LA LIGNE ======
document.querySelectorAll('.fleet-row').forEach(function (row) {
    row.addEventListener('click', function () {
        var vehicleId = this.getAttribute('data-vehicle-id');
        var marker = markers[vehicleId];

        if (!marker) {
            alert('Ce véhicule n\'a pas encore de position GPS connue.');
            return;
        }

        // Zoom fluide sur le véhicule
        map.flyTo(marker.getLatLng(), 13, { duration: 0.8 });

        // Ouvre automatiquement le popup après le zoom
        setTimeout(function () {
            marker.openPopup();
        }, 850);

        // Met en évidence brièvement la ligne sélectionnée
        document.querySelectorAll('.fleet-row').forEach(function (r) {
            r.style.background = '';
        });
        row.style.background = '#E1F5EE';
    });
});

// ====== MISE À JOUR TEMPS RÉEL (polling toutes les 5s) ======
// Simple fetch HTTP — compatible avec tout hébergement, aucun process persistant,
// aucun impact sur les performances du serveur.

function mettreAJourCarte(vehicules) {
    var data = applyAntiOverlap(vehicules);
    data.forEach(function (v) {
        if (markers[v.id_vehicule] && v.latitude && v.longitude) {
            markers[v.id_vehicule].setLatLng([v._displayLat, v._displayLng]);
        }
    });
}

function mettreAJourBadgeAlertes(nbAlertes) {
    var mapBadge = document.getElementById('mapAlertesBadge');
    if (mapBadge) mapBadge.innerHTML = '&#9679; ' + nbAlertes + ' alertes';
    var kpiValue = document.getElementById('kpiAlertesValue');
    if (kpiValue) kpiValue.textContent = nbAlertes;
    var kpiCard = document.getElementById('kpiAlertesCard');
    var kpiTrend = document.getElementById('kpiAlertesTrend');
    if (kpiCard && kpiTrend) {
        if (nbAlertes > 0) {
            kpiCard.classList.add('danger');
            kpiTrend.textContent = 'Intervention requise';
        } else {
            kpiCard.classList.remove('danger');
            kpiTrend.textContent = 'Tout est calme';
        }
    }
}

// Met à jour la colonne "Moteur", la vitesse, le carburant, la température
// et le statut de chaque ligne du tableau "État de la flotte", sans recharger la page.
function mettreAJourTableauFlotte(vehicules) {
    vehicules.forEach(function (v) {
        var ligne = document.querySelector('.fleet-row[data-vehicle-id="' + v.id_vehicule + '"]');
        if (!ligne) return;

        var cellules = ligne.querySelectorAll('td');
        // Ordre des colonnes : 0 Immat, 1 Type, 2 Moteur, 3 Vitesse, 4 Carburant, 5 Température, 6 Statut

        var moteurOn = v.moteur_etat === 'on';
        var cellMoteur = cellules[2];
        if (cellMoteur) {
            var badgeMoteur = cellMoteur.querySelector('.badge');
            if (badgeMoteur) {
                badgeMoteur.className = 'badge ' + (moteurOn ? 'badge-success' : 'badge-muted');
                badgeMoteur.title = moteurOn ? 'Moteur allumé' : 'Moteur éteint';
                badgeMoteur.innerHTML = moteurOn ? '&#128268; ON' : '&#9211; OFF';
            }
        }

        var seuilVitesse = v.type === 'minier' ? 80 : 120;
        var alerteVitesse = v.vitesse > seuilVitesse;
        var cellVitesse = cellules[3];
        if (cellVitesse) {
            cellVitesse.style.color = alerteVitesse ? '#E24B4A' : '#1A1A1A';
            cellVitesse.textContent = (v.vitesse !== null) ? Math.round(v.vitesse) + ' km/h' : '—';
        }

        var carburantBas = v.carburant < 10;
        var cellCarburant = cellules[4];
        if (cellCarburant) {
            var fill = cellCarburant.querySelector('.fuel-bar-fill');
            var label = cellCarburant.querySelector('.fuel-bar span');
            if (fill) {
                fill.style.width = (v.carburant || 0) + '%';
                fill.classList.toggle('low', carburantBas);
            }
            if (label) label.textContent = (v.carburant !== null) ? Math.round(v.carburant) + '%' : '—';
        }

        var alerteTemp = v.temperature_moteur > 95;
        var cellTemp = cellules[5];
        if (cellTemp) {
            cellTemp.textContent = (v.temperature_moteur !== null) ? Math.round(v.temperature_moteur) + '°C' : '—';
        }

        var tpmsAlerte = !!v.tpms_alerte;
        var cellStatut = cellules[6];
        if (cellStatut) {
            var badgeStatut = cellStatut.querySelector('.badge');
            if (badgeStatut) {
                var label = 'Normal', cls = 'badge-success';
                if (alerteVitesse || alerteTemp || tpmsAlerte) {
                    label = 'Alerte'; cls = 'badge-danger';
                } else if (carburantBas) {
                    label = 'Carburant bas'; cls = 'badge-warning';
                }
                badgeStatut.className = 'badge ' + cls;
                badgeStatut.textContent = label;
            }
        }
    });
}

// Met à jour les KPI "Moteurs allumés" et "Alertes pneus (TPMS)" en temps réel
function mettreAJourKpiMoteurTpms(vehicules) {
    var nbMoteursOn = vehicules.filter(function (v) { return v.moteur_etat === 'on'; }).length;
    var nbTpmsAlertes = vehicules.filter(function (v) { return !!v.tpms_alerte; }).length;

    var kpiCards = document.querySelectorAll('.kpi-card');
    kpiCards.forEach(function (card) {
        var label = card.querySelector('.label');
        if (!label) return;

        if (label.textContent.indexOf('Moteurs allumés') !== -1) {
            var val = card.querySelector('.value');
            if (val) val.textContent = nbMoteursOn + ' / ' + vehicules.length;
        }

        if (label.textContent.indexOf('Alertes pneus') !== -1) {
            var val2 = card.querySelector('.value');
            var trend2 = card.querySelector('.trend');
            if (val2) val2.textContent = nbTpmsAlertes;
            if (trend2) trend2.textContent = nbTpmsAlertes > 0 ? 'Vérification requise' : 'Tout est normal';
            card.classList.toggle('danger', nbTpmsAlertes > 0);
        }
    });
}

(function () {
    var badge = document.getElementById('connexionBadge');
    if (badge) {
        badge.style.background = '#1D9E75';
        badge.innerHTML = '&#9679; Temps réel (5s)';
    }

    function rafraichir() {
        fetch('../api/telemetrie.php')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                mettreAJourCarte(data.vehicules);
                mettreAJourBadgeAlertes(data.nb_alertes);
                mettreAJourTableauFlotte(data.vehicules);
                mettreAJourKpiMoteurTpms(data.vehicules);
                var subtitle = document.querySelector('.subtitle');
                if (subtitle) subtitle.textContent = 'Dernière mise à jour : ' + data.timestamp;
            })
            .catch(function () {
                if (badge) {
                    badge.style.background = '#BA7517';
                    badge.innerHTML = '&#9679; Reconnexion...';
                }
            });
    }

    // Premier appel immédiat puis toutes les 5 secondes
    rafraichir();
    setInterval(rafraichir, 5000);
})();

// ====== PRISE EN CHARGE / RÉSOLUTION D'UNE ALERTE (AJAX, sans rechargement) ======
var csrfToken = '<?= $csrfToken ?>';

document.querySelectorAll('.alert-take').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var idAlerte = this.getAttribute('data-id');
        var action = this.getAttribute('data-action'); // 'take' ou 'resolve'
        var alertItem = this.closest('.alert-item');
        var originalText = this.textContent;
        var etaitNonTraitee = (action === 'take');

        this.textContent = 'En cours...';
        this.style.pointerEvents = 'none';

        fetch('../api/alertes.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=' + encodeURIComponent(action) + '&id_alerte=' + encodeURIComponent(idAlerte) + '&csrf_token=' + encodeURIComponent(csrfToken)
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.success) {
                    if (action === 'take') {
                        // Prise en charge : le bouton devient "Résoudre", la carte reste affichée
                        btn.textContent = 'Résoudre';
                        btn.setAttribute('data-action', 'resolve');
                        btn.classList.add('alert-resolve');
                        btn.style.pointerEvents = 'auto';
                    } else {
                        // Résolution : la carte disparaît de la liste
                        alertItem.style.opacity = '0';
                        setTimeout(function () {
                            alertItem.remove();

                            var alertsList = document.getElementById('alertsList');
                            if (!alertsList.querySelector('.alert-item')) {
                                alertsList.innerHTML = '<p class="alert-empty">Aucune alerte active pour le moment.</p>';
                            }
                        }, 200);

                        var countBadge = document.getElementById('alertsCountBadge');
                        var mapBadge = document.getElementById('mapAlertesBadge');
                        if (countBadge) {
                            var nouveauCount = Math.max(0, parseInt(countBadge.textContent, 10) - 1);
                            countBadge.textContent = nouveauCount;
                            if (mapBadge) {
                                mapBadge.innerHTML = '&#9679; ' + nouveauCount + ' alertes actives';
                            }
                        }
                    }

                    // Le KPI "Alertes non traitées" ne diminue que lors d'une prise en charge
                    // (une résolution agit sur une alerte déjà en_cours, donc déjà décomptée)
                    if (etaitNonTraitee) {
                        var kpiValue = document.getElementById('kpiAlertesValue');
                        var kpiTrend = document.getElementById('kpiAlertesTrend');
                        var kpiCard = document.getElementById('kpiAlertesCard');
                        var mapBadge2 = document.getElementById('mapAlertesBadge');

                        var nouveauTotal = Math.max(0, parseInt(kpiValue.textContent, 10) - 1);
                        kpiValue.textContent = nouveauTotal;
                        if (mapBadge2) {
                            mapBadge2.innerHTML = '&#9679; ' + nouveauTotal + ' alertes';
                        }

                        if (nouveauTotal === 0) {
                            kpiTrend.textContent = 'Tout est calme';
                            kpiCard.classList.remove('danger');
                        }
                    }
                } else {
                    alert(data.error || 'Erreur lors du traitement de l\'alerte.');
                    btn.textContent = originalText;
                    btn.style.pointerEvents = 'auto';
                }
            })
            .catch(function () {
                alert('Erreur réseau, veuillez réessayer.');
                btn.textContent = originalText;
                btn.style.pointerEvents = 'auto';
            });
    });
});
</script>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="../assets/js/charts.js"></script>

<?php require_once '../includes/footer.php'; ?>