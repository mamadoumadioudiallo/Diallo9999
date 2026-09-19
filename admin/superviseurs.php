<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : admin/superviseurs.php
// Rôle    : Gestion CRUD des comptes superviseurs (utilisateurs)
// ============================================================

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAdmin();

$message = '';
$messageType = '';

// ====== CRÉATION D'UN SUPERVISEUR ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = 'Session expirée, veuillez réessayer.';
        $messageType = 'error';
    } else {
        $nom = clean($_POST['nom']);
        $prenom = clean($_POST['prenom']);
        $email = clean($_POST['email']);
        $telephone = clean($_POST['telephone'] ?? '');
        $password = $_POST['password'] ?? '';

        if (strlen($password) < 6) {
            $message = 'Le mot de passe doit contenir au moins 6 caractères.';
            $messageType = 'error';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);

            try {
                $stmt = $pdo->prepare("
                    INSERT INTO utilisateurs (nom, prenom, email, telephone, mot_de_passe, role, statut)
                    VALUES (?, ?, ?, ?, ?, 'superviseur', 'actif')
                ");
                $stmt->execute([$nom, $prenom, $email, $telephone, $hash]);
                $message = 'Superviseur ' . $prenom . ' ' . $nom . ' créé avec succès.';
                $messageType = 'success';
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $message = 'Cet email est déjà utilisé par un autre compte.';
                } else {
                    $message = 'Erreur lors de la création du compte.';
                }
                $messageType = 'error';
            }
        }
    }
}

// ====== MODIFICATION D'UN SUPERVISEUR ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {

    if (verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $id = (int) $_POST['id_user'];
        $nom = clean($_POST['nom']);
        $prenom = clean($_POST['prenom']);
        $telephone = clean($_POST['telephone'] ?? '');
        $newPassword = $_POST['password'] ?? '';

        if (!empty($newPassword)) {
            if (strlen($newPassword) < 6) {
                $message = 'Le mot de passe doit contenir au moins 6 caractères.';
                $messageType = 'error';
            } else {
                $hash = password_hash($newPassword, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("
                    UPDATE utilisateurs SET nom = ?, prenom = ?, telephone = ?, mot_de_passe = ?
                    WHERE id_user = ? AND role = 'superviseur'
                ");
                $stmt->execute([$nom, $prenom, $telephone, $hash, $id]);
                $message = 'Superviseur mis à jour avec succès (mot de passe changé).';
                $messageType = 'success';
            }
        } else {
            $stmt = $pdo->prepare("
                UPDATE utilisateurs SET nom = ?, prenom = ?, telephone = ?
                WHERE id_user = ? AND role = 'superviseur'
            ");
            $stmt->execute([$nom, $prenom, $telephone, $id]);
            $message = 'Superviseur mis à jour avec succès.';
            $messageType = 'success';
        }
    }
}

// ====== DÉSACTIVATION / RÉACTIVATION ======
if (isset($_GET['deactivate']) && is_numeric($_GET['deactivate'])) {
    $stmt = $pdo->prepare("UPDATE utilisateurs SET statut = 'inactif' WHERE id_user = ? AND role = 'superviseur'");
    $stmt->execute([$_GET['deactivate']]);
    $message = 'Superviseur désactivé.';
    $messageType = 'success';
}
if (isset($_GET['activate']) && is_numeric($_GET['activate'])) {
    $stmt = $pdo->prepare("UPDATE utilisateurs SET statut = 'actif' WHERE id_user = ? AND role = 'superviseur'");
    $stmt->execute([$_GET['activate']]);
    $message = 'Superviseur réactivé.';
    $messageType = 'success';
}

// ====== SUPPRESSION ======
if (isset($_GET['supprimer']) && is_numeric($_GET['supprimer'])) {
    $idSup = (int) $_GET['supprimer'];

    // Vérification : impossible de supprimer si des véhicules sont assignés
    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM vehicules WHERE id_superviseur = ? AND statut = 'actif'");
    $stmtCheck->execute([$idSup]);
    $nbVehicules = (int) $stmtCheck->fetchColumn();

    if ($nbVehicules > 0) {
        $message = 'Impossible de supprimer ce superviseur : ' . $nbVehicules . ' véhicule(s) lui sont encore assignés.';
        $messageType = 'error';
    } else {
        // Désassigner les véhicules inactifs éventuels
        $pdo->prepare("UPDATE vehicules SET id_superviseur = NULL WHERE id_superviseur = ?")->execute([$idSup]);
        // Supprimer le compte
        $pdo->prepare("DELETE FROM utilisateurs WHERE id_user = ? AND role = 'superviseur'")->execute([$idSup]);
        enregistrerAudit($pdo, 'suppression_superviseur', 'Superviseur ID ' . $idSup);
        $message = 'Superviseur supprimé définitivement.';
        $messageType = 'success';
    }
}

// ====== LISTE DES SUPERVISEURS (avec nb véhicules assignés) ======
$search = clean($_GET['q'] ?? '');
$sql = "
    SELECT u.*, COUNT(v.id_vehicule) AS nb_vehicules
    FROM utilisateurs u
    LEFT JOIN vehicules v ON v.id_superviseur = u.id_user
    WHERE u.role = 'superviseur'
";
if ($search) {
    $sql .= " AND (u.nom LIKE ? OR u.prenom LIKE ? OR u.email LIKE ?)";
}
$sql .= " GROUP BY u.id_user ORDER BY u.id_user DESC";

$stmt = $pdo->prepare($sql);
if ($search) {
    $like = "%$search%";
    $stmt->execute([$like, $like, $like]);
} else {
    $stmt->execute();
}
$superviseurs = $stmt->fetchAll();

$csrfToken = generateCsrfToken();
$pageTitle = 'Gestion des superviseurs';
require_once '../includes/header.php';
?>

<div class="dash-header-row">
    <div>
        <h1>Gestion des superviseurs</h1>
        <p class="subtitle"><?= count($superviseurs) ?> superviseur(s) enregistré(s)</p>
    </div>
    <div class="dash-actions">
        <button class="btn-dash btn-dash-primary" onclick="document.getElementById('modalAdd').style.display='flex'">
            + Créer un superviseur
        </button>
    </div>
</div>

<?php if ($message): ?>
    <div class="panel" style="padding:12px 16px;margin-bottom:14px;background:<?= $messageType === 'success' ? '#E1F5EE' : '#FCEBEB' ?>;color:<?= $messageType === 'success' ? '#085041' : '#A32D2D' ?>;border:none;">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<div class="panel fleet-table-wrap">
    <div class="panel-header">
        <h3>Liste des superviseurs</h3>
        <form method="GET" style="display:flex;gap:8px;">
            <input type="text" name="q" class="search-input" placeholder="Rechercher..." value="<?= htmlspecialchars($search) ?>">
            <button class="btn-dash btn-dash-outline" type="submit">Rechercher</button>
        </form>
    </div>
    <table>
        <thead>
            <tr>
                <th>Nom complet</th>
                <th>Email</th>
                <th>Téléphone</th>
                <th>Véhicules assignés</th>
                <th>Statut</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($superviseurs)): ?>
                <tr><td colspan="6" style="text-align:center;padding:30px;color:#999;">Aucun superviseur enregistré. Cliquez sur "Créer un superviseur" pour commencer.</td></tr>
            <?php endif; ?>
            <?php foreach ($superviseurs as $s): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($s['prenom'] . ' ' . $s['nom']) ?></strong></td>
                    <td><?= htmlspecialchars($s['email']) ?></td>
                    <td><?= htmlspecialchars($s['telephone'] ?: '—') ?></td>
                    <td><span class="badge badge-success"><?= (int) $s['nb_vehicules'] ?></span></td>
                    <td>
                        <span class="badge <?= $s['statut'] === 'actif' ? 'badge-success' : 'badge-warning' ?>">
                            <?= ucfirst($s['statut']) ?>
                        </span>
                    </td>
                    <td style="display:flex;gap:0;justify-content:space-between;width:100%;min-width:220px;">
                        <a href="fiche_superviseur.php?id=<?= $s['id_user'] ?>" style="color:#7C5CBF;font-size:12px;">Fiche</a>
                        <a href="#" style="color:#0F6E56;font-size:12px;"
                           onclick="openEditModal(<?= htmlspecialchars(json_encode($s)) ?>); return false;">Modifier</a>
                        <?php if ($s['statut'] === 'actif'): ?>
                            <a href="?deactivate=<?= $s['id_user'] ?>" style="color:#E24B4A;font-size:12px;" onclick="return confirm('Désactiver ce superviseur ?')">Désactiver</a>
                        <?php else: ?>
                            <a href="?activate=<?= $s['id_user'] ?>" style="color:#1D9E75;font-size:12px;">Réactiver</a>
                        <?php endif; ?>
                        <?php if ($s['nb_vehicules'] == 0): ?>
                            <a href="?supprimer=<?= $s['id_user'] ?>" style="color:#999;font-size:12px;"
                               onclick="return confirm('Supprimer définitivement ce superviseur ? Cette action est irréversible.')">Supprimer</a>
                        <?php else: ?>
                            <span style="color:#ccc;font-size:12px;cursor:not-allowed;" title="Impossible : <?= $s['nb_vehicules'] ?> véhicule(s) assigné(s)">Supprimer</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- ====== MODAL CRÉATION SUPERVISEUR ====== -->
<div id="modalAdd" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:2000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;padding:28px;width:100%;max-width:480px;max-height:90vh;overflow-y:auto;">
        <h3 style="margin-bottom:18px;font-size:17px;">Créer un superviseur</h3>
        <form method="POST">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

            <div class="contact-grid">
                <div class="form-group">
                    <label>Nom *</label>
                    <input type="text" name="nom" required placeholder="Diallo">
                </div>
                <div class="form-group">
                    <label>Prénom *</label>
                    <input type="text" name="prenom" required placeholder="Aïssatou">
                </div>
            </div>

            <div class="form-group">
                <label>Email *</label>
                <input type="email" name="email" required placeholder="aissatou.diallo@fleetiot.gn">
            </div>

            <div class="form-group">
                <label>Téléphone</label>
                <input type="text" name="telephone" placeholder="+224 6XX XX XX XX">
            </div>

            <div class="form-group">
                <label>Mot de passe *</label>
                <input type="password" name="password" required minlength="6" placeholder="6 caractères minimum">
            </div>

            <div style="display:flex;gap:10px;margin-top:20px;">
                <button type="button" class="btn-dash btn-dash-outline" style="flex:1;" onclick="document.getElementById('modalAdd').style.display='none'">Annuler</button>
                <button type="submit" class="btn-dash btn-dash-primary" style="flex:1;">Créer</button>
            </div>
        </form>
    </div>
</div>

<!-- ====== MODAL MODIFICATION SUPERVISEUR ====== -->
<div id="modalEdit" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:2000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;padding:28px;width:100%;max-width:480px;max-height:90vh;overflow-y:auto;">
        <h3 style="margin-bottom:18px;font-size:17px;">Modifier le superviseur</h3>
        <form method="POST">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id_user" id="edit_id">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

            <div class="contact-grid">
                <div class="form-group">
                    <label>Nom *</label>
                    <input type="text" name="nom" id="edit_nom" required>
                </div>
                <div class="form-group">
                    <label>Prénom *</label>
                    <input type="text" name="prenom" id="edit_prenom" required>
                </div>
            </div>

            <div class="form-group">
                <label>Email (non modifiable)</label>
                <input type="text" id="edit_email" disabled style="background:#f5f5f5;">
            </div>

            <div class="form-group">
                <label>Téléphone</label>
                <input type="text" name="telephone" id="edit_telephone">
            </div>

            <div class="form-group">
                <label>Nouveau mot de passe</label>
                <input type="password" name="password" minlength="6" placeholder="Laisser vide pour ne pas changer">
            </div>

            <div style="display:flex;gap:10px;margin-top:20px;">
                <button type="button" class="btn-dash btn-dash-outline" style="flex:1;" onclick="document.getElementById('modalEdit').style.display='none'">Annuler</button>
                <button type="submit" class="btn-dash btn-dash-primary" style="flex:1;">Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditModal(superviseur) {
    document.getElementById('edit_id').value = superviseur.id_user;
    document.getElementById('edit_nom').value = superviseur.nom;
    document.getElementById('edit_prenom').value = superviseur.prenom;
    document.getElementById('edit_email').value = superviseur.email;
    document.getElementById('edit_telephone').value = superviseur.telephone || '';
    document.getElementById('modalEdit').style.display = 'flex';
}
</script>

<?php require_once '../includes/footer.php'; ?>