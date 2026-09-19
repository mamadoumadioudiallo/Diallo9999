<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireSuperviseur();
$idSup = $_SESSION['user_id'];

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = 'Session expirée.';
        $messageType = 'error';
    } else {
        $idAlerte = (int) ($_POST['id_alerte'] ?? 0);
        // Vérifier que l'alerte appartient bien à un véhicule du superviseur
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM alertes a JOIN vehicules v ON a.id_vehicule = v.id_vehicule WHERE a.id_alerte = ? AND v.id_superviseur = ?");
        $checkStmt->execute([$idAlerte, $idSup]);
        if ($checkStmt->fetchColumn() > 0) {
            if ($_POST['action'] === 'en_cours') {
                $pdo->prepare("UPDATE alertes SET statut = 'en_cours', id_traitant = ? WHERE id_alerte = ?")->execute([$_SESSION['user_id'], $idAlerte]);
                $message = 'Alerte prise en charge.'; $messageType = 'success';
            } elseif ($_POST['action'] === 'resoudre') {
                $pdo->prepare("UPDATE alertes SET statut = 'resolue', id_traitant = ?, date_traitement = NOW() WHERE id_alerte = ?")->execute([$_SESSION['user_id'], $idAlerte]);
                $message = 'Alerte résolue.'; $messageType = 'success';
            }
        }
    }
}

$filtreStatut   = $_GET['statut']   ?? 'non_traitee';
$filtreType     = $_GET['type']     ?? '';
$filtreVehicule = $_GET['vehicule'] ?? '';

$libellesAlertes = [
    'vitesse_excessive' => 'Vitesse excessive', 'temperature_critique' => 'Température critique',
    'carburant_bas' => 'Carburant bas', 'hors_zone' => 'Hors zone', 'surcharge' => 'Surcharge',
    'tpms_pression' => 'Pression pneu (TPMS)', 'tpms_temperature' => 'Température pneu (TPMS)', 'moteur_anomalie' => 'Anomalie moteur',
];

$conditions = ["v.id_superviseur = ?"];
$params = [$idSup];
if ($filtreStatut && $filtreStatut !== 'toutes') { $conditions[] = "a.statut = ?"; $params[] = $filtreStatut; }
if ($filtreType)     { $conditions[] = "a.type_alerte = ?"; $params[] = $filtreType; }
if ($filtreVehicule) { $conditions[] = "v.id_vehicule = ?";  $params[] = $filtreVehicule; }
$where = 'WHERE ' . implode(' AND ', $conditions);

$stmt = $pdo->prepare("
    SELECT a.*, v.immatriculation, v.type AS v_type, v.id_vehicule,
           u.nom AS traitant_nom, u.prenom AS traitant_prenom
    FROM alertes a
    JOIN vehicules v ON a.id_vehicule = v.id_vehicule
    LEFT JOIN utilisateurs u ON a.id_traitant = u.id_user
    $where
    ORDER BY a.horodatage DESC LIMIT 200
");
$stmt->execute($params);
$alertes = $stmt->fetchAll();

$kpiStmt = $pdo->prepare("SELECT a.statut, COUNT(*) AS nb FROM alertes a JOIN vehicules v ON a.id_vehicule = v.id_vehicule WHERE v.id_superviseur = ? GROUP BY a.statut");
$kpiStmt->execute([$idSup]);
$kpiAlertes = [];
foreach ($kpiStmt->fetchAll() as $row) { $kpiAlertes[$row['statut']] = (int) $row['nb']; }

$vehiculesListe = $pdo->prepare("SELECT id_vehicule, immatriculation FROM vehicules WHERE statut = 'actif' AND id_superviseur = ? ORDER BY immatriculation");
$vehiculesListe->execute([$idSup]);
$vehiculesListe = $vehiculesListe->fetchAll();

$csrfToken = generateCsrfToken();
$pageTitle = 'Mes alertes';
require_once '../includes/header.php';
?>

<div class="dash-header-row">
    <div><h1>&#128276; Mes alertes</h1><p class="subtitle"><?= count($alertes) ?> alerte(s) affichée(s)</p></div>
    <div class="dash-actions"><a href="dashboard.php" class="btn-dash btn-dash-outline">&#8592; Retour au dashboard</a></div>
</div>

<?php if ($message): ?>
    <div class="panel" style="padding:12px 16px;margin-bottom:14px;background:<?= $messageType === 'success' ? '#E1F5EE' : '#FCEBEB' ?>;color:<?= $messageType === 'success' ? '#085041' : '#A32D2D' ?>;border:none;"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<div class="kpi-grid" style="margin-bottom:16px;">
    <div class="kpi-card danger"><div class="kpi-info"><p class="label">Non traitées</p><p class="value"><?= $kpiAlertes['non_traitee'] ?? 0 ?></p><p class="trend">Intervention requise</p></div><div class="kpi-icon">&#128308;</div></div>
    <div class="kpi-card warning"><div class="kpi-info"><p class="label">En cours</p><p class="value"><?= $kpiAlertes['en_cours'] ?? 0 ?></p><p class="trend">Traitement en cours</p></div><div class="kpi-icon">&#128992;</div></div>
    <div class="kpi-card"><div class="kpi-info"><p class="label">Résolues</p><p class="value"><?= $kpiAlertes['resolue'] ?? 0 ?></p><p class="trend">Total historique</p></div><div class="kpi-icon">&#9989;</div></div>
    <div class="kpi-card"><div class="kpi-info"><p class="label">Total alertes</p><p class="value"><?= array_sum($kpiAlertes) ?></p><p class="trend">Tous statuts confondus</p></div><div class="kpi-icon">&#128276;</div></div>
</div>

<div class="panel" style="padding:16px 20px;margin-bottom:16px;">
    <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
        <div class="form-group" style="margin:0;flex:1;min-width:140px;">
            <label style="font-size:12px;">Statut</label>
            <select name="statut">
                <option value="non_traitee" <?= $filtreStatut === 'non_traitee' ? 'selected' : '' ?>>Non traitées</option>
                <option value="en_cours"    <?= $filtreStatut === 'en_cours'    ? 'selected' : '' ?>>En cours</option>
                <option value="resolue"     <?= $filtreStatut === 'resolue'     ? 'selected' : '' ?>>Résolues</option>
                <option value="toutes"      <?= $filtreStatut === 'toutes'      ? 'selected' : '' ?>>Toutes</option>
            </select>
        </div>
        <div class="form-group" style="margin:0;flex:1;min-width:160px;">
            <label style="font-size:12px;">Type d'alerte</label>
            <select name="type">
                <option value="">Tous les types</option>
                <?php foreach ($libellesAlertes as $key => $libelle): ?>
                    <option value="<?= $key ?>" <?= $filtreType === $key ? 'selected' : '' ?>><?= $libelle ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0;flex:1;min-width:140px;">
            <label style="font-size:12px;">Véhicule</label>
            <select name="vehicule">
                <option value="">Tous</option>
                <?php foreach ($vehiculesListe as $v): ?>
                    <option value="<?= $v['id_vehicule'] ?>" <?= $filtreVehicule == $v['id_vehicule'] ? 'selected' : '' ?>><?= htmlspecialchars($v['immatriculation']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn-dash btn-dash-primary" style="height:38px;">Filtrer</button>
        <a href="alertes.php" class="btn-dash btn-dash-outline" style="height:38px;line-height:22px;">Réinitialiser</a>
    </form>
</div>

<div class="panel fleet-table-wrap">
    <div class="panel-header"><h3>&#128276; Liste des alertes</h3></div>
    <table>
        <thead><tr><th>Véhicule</th><th>Type</th><th>Valeur</th><th>Date / Heure</th><th>Statut</th><th>Traité par</th><th>Actions</th></tr></thead>
        <tbody>
            <?php if (empty($alertes)): ?>
                <tr><td colspan="7" style="text-align:center;padding:30px;color:#999;">Aucune alerte pour ces critères.</td></tr>
            <?php endif; ?>
            <?php foreach ($alertes as $a): ?>
                <?php $sb = ['non_traitee' => 'badge-danger', 'en_cours' => 'badge-warning', 'resolue' => 'badge-success']; $sl = ['non_traitee' => 'Non traitée', 'en_cours' => 'En cours', 'resolue' => 'Résolue']; ?>
                <tr>
                    <td>
                        <a href="fiche_vehicule.php?id=<?= $a['id_vehicule'] ?>" style="color:#0F6E56;font-weight:600;"><?= htmlspecialchars($a['immatriculation']) ?></a>
                        <br><span style="font-size:11px;color:#999;"><?= ucfirst($a['v_type']) ?></span>
                    </td>
                    <td><?= htmlspecialchars($libellesAlertes[$a['type_alerte']] ?? ucfirst(str_replace('_', ' ', $a['type_alerte']))) ?></td>
                    <td><?= htmlspecialchars(formaterValeurAlerte($a['type_alerte'], $a['valeur_declenchante'])) ?></td>
                    <td style="font-size:12px;"><?= date('d/m/Y H:i', strtotime($a['horodatage'])) ?></td>
                    <td><span class="badge <?= $sb[$a['statut']] ?>"><?= $sl[$a['statut']] ?></span></td>
                    <td style="font-size:12px;"><?= $a['traitant_nom'] ? htmlspecialchars($a['traitant_prenom'] . ' ' . $a['traitant_nom']) : '<span style="color:#999;">—</span>' ?></td>
                    <td>
                        <?php if ($a['statut'] === 'non_traitee'): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="id_alerte" value="<?= $a['id_alerte'] ?>">
                                <input type="hidden" name="action" value="en_cours">
                                <button type="submit" style="background:none;border:none;color:#BA7517;font-size:12px;cursor:pointer;">Prendre en charge</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($a['statut'] !== 'resolue'): ?>
                            <form method="POST" style="display:inline;margin-left:8px;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="id_alerte" value="<?= $a['id_alerte'] ?>">
                                <input type="hidden" name="action" value="resoudre">
                                <button type="submit" style="background:none;border:none;color:#1D9E75;font-size:12px;cursor:pointer;">Résoudre</button>
                            </form>
                        <?php else: ?><span style="color:#999;font-size:12px;">—</span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once '../includes/footer.php'; ?>
