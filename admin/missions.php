<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : admin/missions.php
// Rôle    : Gestion des missions (lien conducteur <-> véhicule)
// ============================================================

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAdmin();

$message = '';
$messageType = '';

// ====== CRÉATION D'UNE MISSION ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = 'Session expirée, veuillez réessayer.';
        $messageType = 'error';
    } else {
        $idVehicule = (int) $_POST['id_vehicule'];
        $idConducteur = (int) $_POST['id_conducteur'];
        $lieuDepart = clean($_POST['lieu_depart']);
        $lieuDestination = clean($_POST['lieu_destination']);
        $dateDebut = $_POST['date_debut'];

        // Vérifier que ce véhicule n'a pas déjà une mission "en_cours"
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM missions WHERE id_vehicule = ? AND statut = 'en_cours'");
        $checkStmt->execute([$idVehicule]);

        if ($checkStmt->fetchColumn() > 0) {
            $message = 'Ce véhicule a déjà une mission en cours. Terminez-la avant d\'en créer une nouvelle.';
            $messageType = 'error';
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO missions (id_vehicule, id_conducteur, lieu_depart, lieu_destination, date_debut, statut, vue_conducteur)
                VALUES (?, ?, ?, ?, ?, 'planifiee', 0)
            ");
            $stmt->execute([$idVehicule, $idConducteur, $lieuDepart, $lieuDestination, $dateDebut]);
            $message = 'Mission créée et notifiée au conducteur.';
            $messageType = 'success';
        }
    }
}

// ====== CHANGEMENT DE STATUT ======
if (isset($_GET['start']) && is_numeric($_GET['start'])) {
    $stmt = $pdo->prepare("UPDATE missions SET statut = 'en_cours' WHERE id_mission = ?");
    $stmt->execute([$_GET['start']]);
    $message = 'Mission démarrée.';
    $messageType = 'success';
}
if (isset($_GET['finish']) && is_numeric($_GET['finish'])) {
    $stmt = $pdo->prepare("UPDATE missions SET statut = 'terminee' WHERE id_mission = ?");
    $stmt->execute([$_GET['finish']]);
    $message = 'Mission terminée.';
    $messageType = 'success';
}
if (isset($_GET['cancel']) && is_numeric($_GET['cancel'])) {
    $stmt = $pdo->prepare("UPDATE missions SET statut = 'annulee' WHERE id_mission = ?");
    $stmt->execute([$_GET['cancel']]);
    $message = 'Mission annulée.';
    $messageType = 'success';
}

// ====== DONNÉES POUR LES SELECTS ======
$vehicules = $pdo->query("SELECT id_vehicule, immatriculation, type FROM vehicules WHERE statut = 'actif' ORDER BY immatriculation")->fetchAll();
$conducteurs = $pdo->query("SELECT id_conducteur, nom, prenom FROM conducteurs WHERE statut = 'actif' ORDER BY nom")->fetchAll();

// ====== LISTE DES MISSIONS ======
$filtreStatut = $_GET['statut'] ?? '';
$sql = "
    SELECT m.*, v.immatriculation, v.type AS type_vehicule, c.nom AS c_nom, c.prenom AS c_prenom
    FROM missions m
    JOIN vehicules v ON m.id_vehicule = v.id_vehicule
    JOIN conducteurs c ON m.id_conducteur = c.id_conducteur
";
if ($filtreStatut) {
    $sql .= " WHERE m.statut = " . $pdo->quote($filtreStatut);
}
$sql .= " ORDER BY m.date_debut DESC";

$missions = $pdo->query($sql)->fetchAll();

$csrfToken = generateCsrfToken();
$pageTitle = 'Gestion des missions';
require_once '../includes/header.php';
?>

<div class="dash-header-row">
    <div>
        <h1>Gestion des missions</h1>
        <p class="subtitle"><?= count($missions) ?> mission(s) — assigne un conducteur à un véhicule</p>
    </div>
    <div class="dash-actions">
        <button class="btn-dash btn-dash-primary" onclick="document.getElementById('modalAdd').style.display='flex'"
                <?= (empty($vehicules) || empty($conducteurs)) ? 'disabled title="Ajoutez d\'abord un véhicule et un conducteur"' : '' ?>>
            + Créer une mission
        </button>
    </div>
</div>

<?php if ($message): ?>
    <div class="panel" style="padding:12px 16px;margin-bottom:14px;background:<?= $messageType === 'success' ? '#E1F5EE' : '#FCEBEB' ?>;color:<?= $messageType === 'success' ? '#085041' : '#A32D2D' ?>;border:none;">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<?php if (empty($vehicules) || empty($conducteurs)): ?>
    <div class="panel" style="padding:14px 16px;margin-bottom:14px;background:#FAEEDA;color:#633806;border:none;">
        &#9888; Pour créer une mission, il faut au moins un véhicule actif et un conducteur actif enregistrés.
    </div>
<?php endif; ?>

<div class="panel fleet-table-wrap">
    <div class="panel-header">
        <h3>Liste des missions</h3>
        <form method="GET" style="display:flex;gap:8px;">
            <select name="statut" class="search-input" onchange="this.form.submit()" style="width:160px;">
                <option value="">Tous les statuts</option>
                <option value="planifiee" <?= $filtreStatut === 'planifiee' ? 'selected' : '' ?>>Planifiée</option>
                <option value="en_cours" <?= $filtreStatut === 'en_cours' ? 'selected' : '' ?>>En cours</option>
                <option value="terminee" <?= $filtreStatut === 'terminee' ? 'selected' : '' ?>>Terminée</option>
                <option value="annulee" <?= $filtreStatut === 'annulee' ? 'selected' : '' ?>>Annulée</option>
            </select>
        </form>
    </div>
    <table>
        <thead>
            <tr>
                <th>Véhicule</th>
                <th>Conducteur</th>
                <th>Départ</th>
                <th>Destination</th>
                <th>Date début</th>
                <th>Statut</th>
                <th>Lu conducteur</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($missions)): ?>
                <tr><td colspan="7" style="text-align:center;padding:30px;color:#999;">Aucune mission enregistrée.</td></tr>
            <?php endif; ?>
            <?php foreach ($missions as $m): ?>
                <?php
                    $statutBadge = [
                        'planifiee' => 'badge-warning',
                        'en_cours' => 'badge-success',
                        'terminee' => 'badge-success',
                        'annulee' => 'badge-danger',
                    ];
                    $statutLabel = [
                        'planifiee' => 'Planifiée',
                        'en_cours' => 'En cours',
                        'terminee' => 'Terminée',
                        'annulee' => 'Annulée',
                    ];
                ?>
                <tr>
                    <td>
                        <a href="fiche_vehicule.php?id=<?= $m['id_vehicule'] ?>" style="color:#0F6E56;font-weight:600;">
                            <?= htmlspecialchars($m['immatriculation']) ?>
                        </a>
                        <span style="color:#999;font-size:11px;">(<?= ucfirst($m['type_vehicule']) ?>)</span>
                    </td>
                    <td><?= htmlspecialchars($m['c_prenom'] . ' ' . $m['c_nom']) ?></td>
                    <td><?= htmlspecialchars($m['lieu_depart']) ?></td>
                    <td><?= htmlspecialchars($m['lieu_destination']) ?></td>
                    <td><?= date('d/m/Y H:i', strtotime($m['date_debut'])) ?></td>
                    <td><span class="badge <?= $statutBadge[$m['statut']] ?>"><?= $statutLabel[$m['statut']] ?></span></td>
                    <td style="text-align:center;">
                        <?php if ($m['statut'] === 'annulee'): ?>
                            <span style="color:#ccc;font-size:12px;">—</span>
                        <?php elseif (!empty($m['vue_conducteur'])): ?>
                            <span style="color:#1D9E75;font-size:13px;">&#9989; Lu</span>
                        <?php else: ?>
                            <span style="color:#BA7517;font-size:12px;">&#128336; En attente</span>
                        <?php endif; ?>
                    </td>
                    <td style="display:flex;gap:10px;">
                        <?php if ($m['statut'] === 'planifiee'): ?>
                            <a href="?start=<?= $m['id_mission'] ?>" style="color:#1D9E75;font-size:12px;">Démarrer</a>
                            <a href="?cancel=<?= $m['id_mission'] ?>" style="color:#E24B4A;font-size:12px;" onclick="return confirm('Annuler cette mission ?')">Annuler</a>
                        <?php elseif ($m['statut'] === 'en_cours'): ?>
                            <a href="?finish=<?= $m['id_mission'] ?>" style="color:#0F6E56;font-size:12px;">Terminer</a>
                        <?php else: ?>
                            <span style="color:#999;font-size:12px;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- ====== MODAL CRÉATION MISSION ====== -->
<div id="modalAdd" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:2000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;padding:28px;width:100%;max-width:480px;max-height:90vh;overflow-y:auto;">
        <h3 style="margin-bottom:18px;font-size:17px;">Créer une mission</h3>
        <form method="POST">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

            <div class="form-group">
                <label>Véhicule *</label>
                <select name="id_vehicule" required>
                    <option value="">— Choisir un véhicule —</option>
                    <?php foreach ($vehicules as $v): ?>
                        <option value="<?= $v['id_vehicule'] ?>"><?= htmlspecialchars($v['immatriculation']) ?> (<?= ucfirst($v['type']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Conducteur *</label>
                <select name="id_conducteur" required>
                    <option value="">— Choisir un conducteur —</option>
                    <?php foreach ($conducteurs as $c): ?>
                        <option value="<?= $c['id_conducteur'] ?>"><?= htmlspecialchars($c['prenom'] . ' ' . $c['nom']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="contact-grid">
                <div class="form-group">
                    <label>Lieu de départ *</label>
                    <input type="text" name="lieu_depart" required placeholder="Zone minière Simandou">
                </div>
                <div class="form-group">
                    <label>Destination *</label>
                    <input type="text" name="lieu_destination" required placeholder="Port de Moribaya">
                </div>
            </div>

            <div class="form-group">
                <label>Date et heure de début *</label>
                <input type="datetime-local" name="date_debut" required value="<?= date('Y-m-d\TH:i') ?>">
            </div>

            <div style="display:flex;gap:10px;margin-top:20px;">
                <button type="button" class="btn-dash btn-dash-outline" style="flex:1;" onclick="document.getElementById('modalAdd').style.display='none'">Annuler</button>
                <button type="submit" class="btn-dash btn-dash-primary" style="flex:1;">Créer</button>
            </div>
        </form>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>