<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : superviseur/mes_vehicules.php
// Rôle    : Consultation des véhicules assignés au superviseur connecté
//           (lecture seule — la gestion CRUD reste réservée à l'admin)
// ============================================================

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireSuperviseur();

$idSup = $_SESSION['user_id'];

// ====== RECHERCHE ======
$search = clean($_GET['q'] ?? '');

$sql = "
    SELECT v.*, t.vitesse, t.carburant, t.temperature_moteur, t.horodatage AS derniere_maj
    FROM vehicules v
    LEFT JOIN (
        SELECT t1.* FROM telemetrie t1
        INNER JOIN (
            SELECT id_vehicule, MAX(horodatage) AS max_horo
            FROM telemetrie GROUP BY id_vehicule
        ) t2 ON t1.id_vehicule = t2.id_vehicule AND t1.horodatage = t2.max_horo
    ) t ON v.id_vehicule = t.id_vehicule
    WHERE v.id_superviseur = ?
";
$params = [$idSup];

if ($search) {
    $sql .= " AND (v.immatriculation LIKE ? OR v.marque LIKE ? OR v.modele LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
$sql .= " ORDER BY v.id_vehicule DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$vehicules = $stmt->fetchAll();

$pageTitle = 'Mes véhicules';
require_once '../includes/header.php';
?>

<div class="dash-header-row">
    <div>
        <h1>Mes véhicules</h1>
        <p class="subtitle"><?= count($vehicules) ?> véhicule(s) sous votre supervision</p>
    </div>
</div>

<div class="panel fleet-table-wrap">
    <div class="panel-header">
        <h3>Liste de mes véhicules</h3>
        <form method="GET" style="display:flex;gap:8px;">
            <input type="text" name="q" class="search-input" placeholder="Rechercher..." value="<?= htmlspecialchars($search) ?>">
            <button class="btn-dash btn-dash-outline" type="submit">Rechercher</button>
        </form>
    </div>
    <table>
        <thead>
            <tr>
                <th>Immatriculation</th>
                <th>Marque / Modèle</th>
                <th>Type</th>
                <th>Vitesse</th>
                <th>Carburant</th>
                <th>Température</th>
                <th>Statut</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($vehicules)): ?>
                <tr><td colspan="7" style="text-align:center;padding:30px;color:#999;">Aucun véhicule ne vous est assigné pour le moment.</td></tr>
            <?php endif; ?>
            <?php foreach ($vehicules as $v): ?>
                <?php
                    $seuilVitesse = $v['type'] === 'minier' ? 80 : 120;
                    $isAlerteVitesse = $v['vitesse'] !== null && $v['vitesse'] > $seuilVitesse;
                    $isAlerteTemp = $v['temperature_moteur'] !== null && $v['temperature_moteur'] > 95;
                    $isCarburantBas = $v['carburant'] !== null && $v['carburant'] < 10;
                ?>
                <tr>
                    <td><a href="fiche_vehicule.php?id=<?= $v['id_vehicule'] ?>" style="color:#0F6E56;font-weight:600;"><?= htmlspecialchars($v['immatriculation']) ?></a></td>
                    <td><?= htmlspecialchars($v['marque'] . ' ' . $v['modele']) ?> <span style="color:#999;font-size:11px;">(<?= $v['annee'] ?>)</span></td>
                    <td><?= ucfirst($v['type']) ?></td>
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
                    <td style="color: <?= $isAlerteTemp ? '#E24B4A' : '#1A1A1A' ?>">
                        <?= $v['temperature_moteur'] !== null ? round($v['temperature_moteur']) . '°C' : '—' ?>
                    </td>
                    <td>
                        <span class="badge <?= $v['statut'] === 'actif' ? 'badge-success' : 'badge-warning' ?>">
                            <?= ucfirst($v['statut']) ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once '../includes/footer.php'; ?>