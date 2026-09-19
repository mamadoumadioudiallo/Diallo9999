<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : rapports/rapport_conso.php
// Rôle    : Rapport de consommation de carburant par véhicule
//           sur une période donnée (estimation basée sur la télémétrie)
// ============================================================

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireSuperviseur();

$idSup = $_SESSION['user_id'];
$estAdmin = isAdmin();

// ====== PÉRIODE (par défaut : les 7 derniers jours) ======
$dateDebut = $_GET['date_debut'] ?? date('Y-m-d', strtotime('-7 days'));
$dateFin = $_GET['date_fin'] ?? date('Y-m-d');

// ====== COEFFICIENTS DE CONSOMMATION (mêmes valeurs que le simulateur) ======
$coefConso = ['minier' => 0.35, 'routier' => 0.12]; // % de carburant consommé par km

// ====== VÉHICULES CONCERNÉS ======
$sqlVeh = "SELECT id_vehicule, immatriculation, marque, modele, type, capacite_carburant FROM vehicules";
$paramsVeh = [];
if (!$estAdmin) {
    $sqlVeh .= " WHERE id_superviseur = ?";
    $paramsVeh[] = $idSup;
}
$sqlVeh .= " ORDER BY immatriculation";
$stmt = $pdo->prepare($sqlVeh);
$stmt->execute($paramsVeh);
$vehicules = $stmt->fetchAll();

// ====== CALCUL DISTANCE PARCOURUE PAR VÉHICULE SUR LA PÉRIODE ======
$rapport = [];
$totalKm = 0;
$totalLitres = 0;

foreach ($vehicules as $v) {
    $stmt = $pdo->prepare("
        SELECT MIN(kilometrage) AS km_min, MAX(kilometrage) AS km_max
        FROM telemetrie
        WHERE id_vehicule = ? AND horodatage BETWEEN ? AND ?
    ");
    $stmt->execute([$v['id_vehicule'], $dateDebut . ' 00:00:00', $dateFin . ' 23:59:59']);
    $res = $stmt->fetch();

    $distance = ($res['km_min'] !== null) ? max(0, $res['km_max'] - $res['km_min']) : 0;
    $coef = $coefConso[$v['type']] ?? 0.15;
    $litresEstimes = $distance * ($coef / 100) * $v['capacite_carburant'];
    $consoMoyenne = $distance > 0 ? ($litresEstimes / $distance) * 100 : 0;

    $rapport[] = [
        'immatriculation' => $v['immatriculation'],
        'marque_modele'   => $v['marque'] . ' ' . $v['modele'],
        'type'            => $v['type'],
        'distance'        => $distance,
        'litres'          => $litresEstimes,
        'conso_moyenne'   => $consoMoyenne,
    ];

    $totalKm += $distance;
    $totalLitres += $litresEstimes;
}

// Tri par consommation décroissante (les plus gourmands en premier)
usort($rapport, fn($a, $b) => $b['litres'] <=> $a['litres']);

$pageTitle = 'Rapport de consommation';
require_once '../includes/header.php';
?>

<div class="dash-header-row">
    <div>
        <h1>Rapport de consommation</h1>
        <p class="subtitle">Estimation basée sur la télémétrie — du <?= date('d/m/Y', strtotime($dateDebut)) ?> au <?= date('d/m/Y', strtotime($dateFin)) ?></p>
    </div>
    <div class="dash-actions">
        <a href="export_csv.php?type=km&date_debut=<?= $dateDebut ?>&date_fin=<?= $dateFin ?>" class="btn-dash btn-dash-outline">Exporter en CSV</a>
        <a href="export_pdf.php?type=km&date_debut=<?= $dateDebut ?>&date_fin=<?= $dateFin ?>" class="btn-dash btn-dash-primary">Exporter en PDF</a>
    </div>
</div>

<!-- ====== NAVIGATION ENTRE RAPPORTS ====== -->
<div class="panel" style="padding:0;margin-bottom:14px;display:flex;">
    <a href="rapport_conso.php" class="btn-dash btn-dash-primary" style="border-radius:0;flex:1;text-align:center;">Consommation</a>
    <a href="rapport_km.php" class="btn-dash btn-dash-outline" style="border-radius:0;flex:1;text-align:center;border:none;">Kilométrage</a>
    <a href="rapport_alertes.php" class="btn-dash btn-dash-outline" style="border-radius:0;flex:1;text-align:center;border:none;">Alertes</a>
</div>

<!-- ====== FILTRE DE PÉRIODE ====== -->
<div class="panel" style="padding:16px 20px;margin-bottom:14px;">
    <form method="GET" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap;">
        <div class="form-group" style="margin:0;">
            <label>Du</label>
            <input type="date" name="date_debut" value="<?= htmlspecialchars($dateDebut) ?>">
        </div>
        <div class="form-group" style="margin:0;">
            <label>Au</label>
            <input type="date" name="date_fin" value="<?= htmlspecialchars($dateFin) ?>">
        </div>
        <button type="submit" class="btn-dash btn-dash-primary">Filtrer</button>
    </form>
</div>

<!-- ====== KPI RÉSUMÉ ====== -->
<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-info">
            <p class="label">Distance totale</p>
            <p class="value"><?= number_format($totalKm, 0, ',', ' ') ?> km</p>
            <p class="trend">Sur la période sélectionnée</p>
        </div>
        <div class="kpi-icon">&#128739;</div>
    </div>
    <div class="kpi-card warning">
        <div class="kpi-info">
            <p class="label">Carburant consommé (estimé)</p>
            <p class="value"><?= number_format($totalLitres, 1, ',', ' ') ?> L</p>
            <p class="trend">Toutes flottes confondues</p>
        </div>
        <div class="kpi-icon">&#128167;</div>
    </div>
</div>

<div class="panel fleet-table-wrap">
    <div class="panel-header">
        <h3>Détail par véhicule</h3>
    </div>
    <table>
        <thead>
            <tr>
                <th>Immatriculation</th>
                <th>Marque / Modèle</th>
                <th>Type</th>
                <th>Distance parcourue</th>
                <th>Carburant consommé (estimé)</th>
                <th>Conso. moyenne</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rapport)): ?>
                <tr><td colspan="6" style="text-align:center;padding:30px;color:#999;">Aucune donnée de télémétrie pour cette période.</td></tr>
            <?php endif; ?>
            <?php foreach ($rapport as $r): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($r['immatriculation']) ?></strong></td>
                    <td><?= htmlspecialchars($r['marque_modele']) ?></td>
                    <td><?= ucfirst($r['type']) ?></td>
                    <td><?= number_format($r['distance'], 0, ',', ' ') ?> km</td>
                    <td><?= number_format($r['litres'], 1, ',', ' ') ?> L</td>
                    <td><?= number_format($r['conso_moyenne'], 1, ',', ' ') ?> L/100km</td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once '../includes/footer.php'; ?>