<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : superviseur/mes_alertes.php
// Rôle    : Gestion des alertes des véhicules assignés au superviseur
// ============================================================

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireSuperviseur();

$idSup = $_SESSION['user_id'];
$message = '';
$messageType = '';

// ====== PRISE EN CHARGE D'UNE ALERTE ======
if (isset($_GET['take']) && is_numeric($_GET['take'])) {
    $stmt = $pdo->prepare("
        UPDATE alertes a
        JOIN vehicules v ON a.id_vehicule = v.id_vehicule
        SET a.statut = 'en_cours', a.id_traitant = ?
        WHERE a.id_alerte = ? AND v.id_superviseur = ?
    ");
    $stmt->execute([$idSup, $_GET['take'], $idSup]);
    $message = 'Alerte prise en charge.';
    $messageType = 'success';
}

// ====== RÉSOLUTION D'UNE ALERTE ======
if (isset($_GET['resolve']) && is_numeric($_GET['resolve'])) {
    $stmt = $pdo->prepare("
        UPDATE alertes a
        JOIN vehicules v ON a.id_vehicule = v.id_vehicule
        SET a.statut = 'resolue', a.id_traitant = ?
        WHERE a.id_alerte = ? AND v.id_superviseur = ?
    ");
    $stmt->execute([$idSup, $_GET['resolve'], $idSup]);
    $message = 'Alerte marquée comme résolue.';
    $messageType = 'success';
}

// ====== LISTE DES ALERTES (filtrée par statut) ======
$filtreStatut = $_GET['statut'] ?? '';
$sql = "
    SELECT a.*, v.immatriculation, v.type AS type_vehicule,
           u.nom AS traitant_nom, u.prenom AS traitant_prenom, u.role AS traitant_role
    FROM alertes a
    JOIN vehicules v ON a.id_vehicule = v.id_vehicule
    LEFT JOIN utilisateurs u ON a.id_traitant = u.id_user
    WHERE v.id_superviseur = ?
";
$params = [$idSup];

if ($filtreStatut) {
    $sql .= " AND a.statut = ?";
    $params[] = $filtreStatut;
}
$sql .= " ORDER BY a.horodatage DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$alertes = $stmt->fetchAll();

$pageTitle = 'Mes alertes';
require_once '../includes/header.php';
?>

<div class="dash-header-row">
    <div>
        <h1>Mes alertes</h1>
        <p class="subtitle"><?= count($alertes) ?> alerte(s) sur vos véhicules</p>
    </div>
</div>

<?php if ($message): ?>
    <div class="panel" style="padding:12px 16px;margin-bottom:14px;background:<?= $messageType === 'success' ? '#E1F5EE' : '#FCEBEB' ?>;color:<?= $messageType === 'success' ? '#085041' : '#A32D2D' ?>;border:none;">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<div class="panel fleet-table-wrap">
    <div class="panel-header">
        <h3>Liste des alertes</h3>
        <form method="GET" style="display:flex;gap:8px;">
            <select name="statut" class="search-input" onchange="this.form.submit()" style="width:180px;">
                <option value="">Toutes les alertes</option>
                <option value="non_traitee" <?= $filtreStatut === 'non_traitee' ? 'selected' : '' ?>>Non traitées</option>
                <option value="en_cours" <?= $filtreStatut === 'en_cours' ? 'selected' : '' ?>>En cours</option>
                <option value="resolue" <?= $filtreStatut === 'resolue' ? 'selected' : '' ?>>Résolues</option>
            </select>
        </form>
    </div>
    <table>
        <thead>
            <tr>
                <th>Véhicule</th>
                <th>Type d'alerte</th>
                <th>Valeur déclenchante</th>
                <th>Date</th>
                <th>Statut</th>
                <th>Actions</th>
                <th>Traité par</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($alertes)): ?>
                <tr><td colspan="7" style="text-align:center;padding:30px;color:#999;">Aucune alerte pour vos véhicules.</td></tr>
            <?php endif; ?>
            <?php foreach ($alertes as $a): ?>
                <?php
                    $statutBadge = [
                        'non_traitee' => 'badge-danger',
                        'en_cours'    => 'badge-warning',
                        'resolue'     => 'badge-success',
                    ];
                    $statutLabel = [
                        'non_traitee' => 'Non traitée',
                        'en_cours'    => 'En cours',
                        'resolue'     => 'Résolue',
                    ];
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($a['immatriculation']) ?></strong> <span style="color:#999;font-size:11px;">(<?= ucfirst($a['type_vehicule']) ?>)</span></td>
                    <td><?= htmlspecialchars(str_replace('_', ' ', ucfirst($a['type_alerte']))) ?></td>
                    <td><?= htmlspecialchars($a['valeur_declenchante']) ?></td>
                    <td><?= date('d/m/Y H:i', strtotime($a['horodatage'])) ?></td>
                    <td><span class="badge <?= $statutBadge[$a['statut']] ?>"><?= $statutLabel[$a['statut']] ?></span></td>
                    <td style="display:flex;gap:10px;">
                        <?php if ($a['statut'] === 'non_traitee'): ?>
                            <a href="?take=<?= $a['id_alerte'] ?>" style="color:#0F6E56;font-size:12px;">Prendre en charge</a>
                        <?php elseif ($a['statut'] === 'en_cours'): ?>
                            <a href="?resolve=<?= $a['id_alerte'] ?>" style="color:#1D9E75;font-size:12px;">Résoudre</a>
                        <?php else: ?>
                            <span style="color:#999;font-size:12px;">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($a['traitant_nom']): ?>
                            <?= htmlspecialchars($a['traitant_prenom'] . ' ' . $a['traitant_nom']) ?>
                            <span style="color:#999;font-size:11px;">(<?= $a['traitant_role'] === 'admin' ? 'Admin' : 'Vous' ?>)</span>
                        <?php else: ?>
                            <span style="color:#999;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once '../includes/footer.php'; ?>